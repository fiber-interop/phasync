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
| `await(Fiber)` | `phasync::await($fiber, $timeout)` |
| `await(thenable)` | `then()` callbacks raise a flag (`raiseFlag` / `awaitFlag`), first settle wins, a helper coroutine implements the timeout and is cancelled afterwards |
| `sleep` / `readable` / `writable` | `phasync::sleep` / `readable` / `writable` |
| `Timeout` | phasync's `TimeoutException` is translated to `async\Timeout` (phasync's exception is `getPrevious()`) |
| `CancelledException` | phasync's `CancelledException` is translated to `FiberInterop\Phasync\Cancelled` (implements `async\CancelledException`) |

A task that itself fails with a phasync `TimeoutException` is rethrown unchanged, as is every other exception.

Outside a phasync coroutine (`{main}`, or a Fiber phasync did not create) a wait runs inside `phasync::run()`,
so the loop is driven in place and the foreign Fiber is never suspended. `go()` called there is queued and
started by the next wait, because `phasync::go()` refuses to run outside a coroutine.

## Limitations

- **Waits from `{main}` return only after every coroutine of that wait has ended.** `phasync::run()` drains
  all coroutines before returning, so an `await($task, $timeout)` from `{main}` that times out still throws
  `Timeout`, and leaves the task running, but only once the task has finished. Inside a coroutine (including
  `async\run(fn)`) timeouts behave exactly as specified. Conformance example `04-timeout-leaves-task-running`
  is the one that observes this (output order differs).
- phasync allows one reader and one writer per stream; the adapter serialises extra waiters on the same
  stream and direction (all of them wake when the stream is ready), but only among adapter calls.
- Streams must be non-blocking for `readable`/`writable` to wait.
- Requires PHP ^8.2 (phasync's floor).

## Tests

```
vendor/bin/phpunit
vendor/bin/fiber-interop-conformance tests/bootstrap.php
```
