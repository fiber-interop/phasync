# fiber-interop/phasync

Runs the [`fiber-interop/api`](../api) functions (`async\go`, `await`, `await_all`, `await_any`, `run`,
`sleep`, `readable`, `writable`) on the [phasync](https://github.com/phasync/phasync) event loop.

```
composer require fiber-interop/phasync
```

Installing it is enough: `src/register.php` calls `async\register()`; the adapter is picked up on first use.
With several adapters installed, call `async\setup(new FiberInterop\Phasync\Adapter())`.

## Mapping

| api | phasync |
|---|---|
| `go()` | `phasync::go()`; the returned `Fiber` is the awaitable |
| `service()` | `phasync::service()`; the coroutine is returned |
| `await(Fiber)` | `phasync::await($fiber, $timeout)` |
| `await(thenable)` | `then()` callbacks raise a flag (`raiseFlag` / `awaitFlag`), first settle wins, a helper coroutine implements the timeout and is cancelled afterwards |
| `sleep` / `readable` / `writable` | `phasync::sleep` / `readable` / `writable` |
| `Timeout` | phasync's `TimeoutException` is translated to `async\Timeout` (phasync's exception is `getPrevious()`) |
| `CancelledException` | phasync's `CancelledException` is translated to `FiberInterop\Phasync\Cancelled` (implements `async\CancelledException`) |

phasync's `TimeoutException` and `CancelledException` are wrapped only until phasync implements the api interfaces itself; then the translation goes away.

A task that itself fails with a phasync `TimeoutException` is rethrown unchanged, as is every other exception.

The api guarantees every call happens inside `async\run()`, whose first steps from `{main}` are `go()` and
`await()` of that task. So there is exactly one `phasync::run()`, the outermost: `go()` from `{main}` returns a
queued `Task`, and the `await()` of it wraps the `phasync::run()` that starts it. Everything else runs in
phasync coroutines, and a task's exception reaches `async\run()` as phasync delivers it.

`service()` uses `phasync::service()` and returns that coroutine. phasync's own `run()` waits for services;
the api's does not, so once every task has finished the outermost run cancels the services (write them to be
restartable, as the api asks).

A Fiber phasync did not create (started inside `run()`) cannot be suspended: suspending it would only return
to whoever started it. A wait there runs as a coroutine while the foreign fiber drives phasync's loop in place
(`EventLoop::tick()`), after which the interrupted coroutine's "current fiber" is restored by reflection
because `tick()` is written for the root loop. A nested `async\run()` inside such a fiber works the same way.
Any api call outside `run()` throws `LogicException` (the api itself throws `NotInRunException` first).

## Scope state

`$_GET`, `$_POST`, `$_COOKIE` and `$_FILES` follow the current `\Scope` (polyfill, see `fiber-interop/scope`):
the adapter calls `fiber_interop\scope\activate()` wherever phasync hands control to user code.

| Where | How |
|---|---|
| every switch to an api task (`async\go()`), also to a raw `phasync::go()` coroutine inside it that has its own context | `FiberInterop\Phasync\ScopeContext` implements `phasync\Context\SwitchAwareInterface`; phasync calls `resume()` from the loop before a coroutine of that context runs after another context ran. The adapter gives every api task, and the outermost `phasync::run()`, its own `ScopeContext` |
| after any adapter wait returns (`await`, `sleep`, `readable`, `writable`, thenables) | `activate()` in a `finally` (fallback; also covers the in-place loop of foreign fibers) |
| outermost `run()` back in `{main}` | `activate()` after `phasync::run()` returns |

phasync's only switch hook is per context, not global: it fires when the next coroutine's context differs from
the one that ran last (and only if the next context is switch-aware). So:

- Coroutines that share one context (every plain `phasync::go()` inherits its creator's) are NOT activated on
  switches among themselves. Give them their own: `phasync::go($fn, [], new ScopeContext())`. A coroutine in a
  context that is not switch-aware gets no hook at all; the previous one stays live until the next adapter call.
- Raw code that starts `phasync::run()` itself from `{main}` must call `ScopeContext::activate()` after it returns.
- While `resume()` runs (in the loop, outside any coroutine) the adapter's execution key provider tells `\Scope`
  which coroutine is about to run.

Probes (`php probes/scope-*.php`, each prints `ok` or the mismatch):

- `scope-api.php` passes: api tasks have their own `ScopeContext`, and waits activate on return.
- `scope-loop.php` passes written with `phasync::go` + `phasync::sleep` and no api, but only because each
  coroutine is created with `new ScopeContext()` and `activate()` is called after `phasync::run()`. With plain
  `phasync::go()` (`--plain`) it fails: interleaved coroutines of one context see each other's `$_GET`.
- `scope-raw-fiber.php` passes through the polyfill's "most recently current" fallback.

## Limitations

- A local `fclose()` of a stream that fibers wait on is detected by phasync itself (`phasync\IOException: Stream closed`, immediately); the adapter turns that into a normal return of `readable()`/`writable()` when the resource is gone (other IOExceptions propagate), and wakes the extra waiters on the same stream through the flag chain.
- Only tasks started through `async\go()` are counted when the outermost run decides it is idle; a bare
  `phasync::go()` coroutine is still waited for by `phasync::run()`, as phasync specifies.
- phasync allows one reader and one writer per stream; the adapter serialises extra waiters on the same
  stream and direction (all of them wake when the stream is ready), but only among adapter calls.
- Streams must be non-blocking for `readable`/`writable` to wait.
- Requires PHP ^8.2 (phasync's floor).

## Tests

The api's stress tier (`FIBER_INTEROP_STRESS_ITERATIONS=200 php vendor/fiber-interop/api/bin/fiber-interop-stress tests/bootstrap.php`) passes.

```
vendor/bin/phpunit
vendor/bin/fiber-interop-conformance tests/bootstrap.php
```
