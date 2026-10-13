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

## Limitations

- Only tasks started through `async\go()` are counted when the outermost run decides it is idle; a bare
  `phasync::go()` coroutine is still waited for by `phasync::run()`, as phasync specifies.
- phasync allows one reader and one writer per stream; the adapter serialises extra waiters on the same
  stream and direction (all of them wake when the stream is ready), but only among adapter calls.
- Streams must be non-blocking for `readable`/`writable` to wait.
- Requires PHP ^8.2 (phasync's floor).

## Tests

```
vendor/bin/phpunit
vendor/bin/fiber-interop-conformance tests/bootstrap.php
```
