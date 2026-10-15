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

`service()` uses `phasync::service()` and returns that coroutine. The outermost `phasync::run()` waits for every
coroutine started inside it, then cancels the services (write them to be restartable, as the api asks).

A Fiber phasync did not create (started inside `run()`) cannot be suspended: suspending it would only return
to whoever started it. A wait there runs as a coroutine while the foreign fiber drives phasync's loop in place
(`EventLoop::tick()`, which puts the interrupted coroutine back when it returns). A nested `async\run()` inside such a fiber works the same way.
Any api call outside `run()` throws `LogicException` (the api itself throws `NotInRunException` first).

## Scope state

`$_GET`, `$_POST`, `$_COOKIE` and `$_FILES` follow the current `\Scope` (`fiber-interop/scope`). phasync does
this itself since 2.0.0-beta9: a coroutine runs in the `Scope` current where it was started, and phasync makes
that scope current (swapping the polyfill's superglobals) whenever the loop switches to a coroutine of another
scope, also for raw `phasync::go()` coroutines and back in `{main}` after `phasync::run()`. The adapter adds
nothing; per-coroutine state of your own belongs in a `WeakMap` keyed by `Scope::getCurrent()`, swapped by a
`phasync::onScopeChange()` hook if needed.

Probes (`php probes/scope-*.php`, each prints `ok` or the mismatch):

- `scope-api.php` passes.
- `scope-loop.php` passes with plain `phasync::go` + `phasync::sleep` and no api.
- `scope-raw-fiber.php` passes through the polyfill's "most recently current" fallback.

## Limitations

- A local `fclose()` of a stream that fibers wait on is detected by phasync itself (`phasync\IOException: Stream closed`, immediately); the adapter turns that into a normal return of `readable()`/`writable()` when the resource is gone (other IOExceptions propagate), and wakes the extra waiters on the same stream through the flag chain.
- phasync allows one reader and one writer per stream; the adapter serialises extra waiters on the same
  stream and direction (all of them wake when the stream is ready), but only among adapter calls.
- Streams must be non-blocking for `readable`/`writable` to wait.
- Requires PHP ^8.2 (phasync's floor) and phasync 2.0.0-beta9 or later (adapter 0.3; 0.2 targets the context API of earlier betas).

## Tests

The api's stress tier (`FIBER_INTEROP_STRESS_ITERATIONS=200 php vendor/fiber-interop/api/bin/fiber-interop-stress tests/bootstrap.php`) passes.

```
vendor/bin/phpunit
vendor/bin/fiber-interop-conformance tests/bootstrap.php
```
