<?php

namespace FiberInterop\Phasync;

use async\RejectedException;
use async\Timeout;
use phasync\CancelledException as PhasyncCancelled;
use phasync\TimeoutException as PhasyncTimeout;

/**
 * Runs the fiber-interop/api functions on the phasync event loop.
 *
 * A phasync coroutine (the Fiber returned by phasync::go) is the awaitable. phasync's own
 * TimeoutException/CancelledException do not implement the api interfaces, so waits translate
 * them to async\Timeout / Cancelled (phasync's exception is kept as ->getPrevious()).
 */
final class Adapter implements \async\Adapter
{
    /** @var list<Task> tasks created by go() outside any coroutine, not yet started */
    private array $pending = [];

    /** @var array<string, object> flag raised when the polling waiter of a stream/direction is done */
    private array $streamWaiters = [];

    public function go(\Closure $f): object
    {
        if ($this->inCoroutine()) {
            return \phasync::go($f);
        }
        // phasync::go() needs a running coroutine; outside one the task starts with the next wait.
        return $this->pending[] = new Task($f);
    }

    public function canAwait(object $o): bool
    {
        if ($o instanceof Task) {
            return true;
        }
        if ($o instanceof \Fiber) {
            // only phasync coroutines are awaitable
            return (\phasync::isRunning() && \phasync::getLoop()->getContext($o) !== null) || $o->isTerminated();
        }
        return \method_exists($o, 'then');
    }

    public function await(object $o, float $timeout): mixed
    {
        if ($o instanceof Task || $o instanceof \Fiber) {
            return $this->inLoop(function () use ($o, $timeout) {
                $fiber = $o instanceof Task ? $this->start($o) : $o;
                try {
                    return \phasync::await($fiber, $timeout);
                } catch (PhasyncTimeout $e) {
                    if ($fiber->isTerminated()) {
                        throw $e; // the task itself failed with a timeout; rethrow unchanged
                    }
                    throw new Timeout(\sprintf('Timed out after %.3fs', $timeout), 0, $e);
                } catch (PhasyncCancelled $e) {
                    if ($fiber->isTerminated()) {
                        throw new Cancelled($e->getMessage(), 0, $e);
                    }
                    throw $e;
                }
            });
        }
        return $this->awaitThenable($o, $timeout);
    }

    private function awaitThenable(object $o, float $timeout): mixed
    {
        return $this->wrap(function () use ($o, $timeout) {
            $flag = new \stdClass();
            $done = false;
            $ok = false;
            $value = null;
            $settle = static function (bool $success, mixed $v) use (&$done, &$ok, &$value, $flag): void {
                if ($done) {
                    return;
                }
                $done = true;
                $ok = $success;
                $value = $v;
                \phasync::raiseFlag($flag);
            };
            $o->then(
                static fn($v = null) => $settle(true, $v),
                static fn($r = null) => $settle(false, $r instanceof \Throwable ? $r : new RejectedException($r)),
            );
            if (!$done) {
                $timer = null;
                if ($timeout !== \PHP_FLOAT_MAX) {
                    $timer = \phasync::go(static function () use ($timeout, $settle): void {
                        \phasync::sleep($timeout);
                        $settle(false, new Timeout(\sprintf('Timed out after %.3fs', $timeout)));
                    });
                }
                try {
                    while (!$done) {
                        \phasync::awaitFlag($flag);
                    }
                } finally {
                    if ($timer !== null && !$timer->isTerminated()) {
                        \phasync::cancel($timer);
                    }
                }
            }
            if (!$ok) {
                throw $value;
            }
            return $value;
        });
    }

    public function sleep(float $seconds): void
    {
        $this->wrap(static fn() => \phasync::sleep($seconds));
    }

    public function readable(mixed $resource, float $timeout): void
    {
        $this->waitStream('r', $resource, $timeout);
    }

    public function writable(mixed $resource, float $timeout): void
    {
        $this->waitStream('w', $resource, $timeout);
    }

    /**
     * phasync allows one waiter per stream and direction; the api allows many. The first waiter
     * polls, later ones wait for its flag and then poll themselves (returning at once if the
     * stream is still ready).
     */
    private function waitStream(string $mode, mixed $resource, float $timeout): void
    {
        $this->wrap(function () use ($mode, $resource, $timeout) {
            $key = $mode . \get_resource_id($resource);
            $start = \microtime(true);
            while (isset($this->streamWaiters[$key])) {
                $remaining = $timeout === \PHP_FLOAT_MAX ? $timeout : \max(0.0, $timeout - (\microtime(true) - $start));
                \phasync::awaitFlag($this->streamWaiters[$key], $remaining);
            }
            $flag = $this->streamWaiters[$key] = new \stdClass();
            $remaining = $timeout === \PHP_FLOAT_MAX ? $timeout : \max(0.0, $timeout - (\microtime(true) - $start));
            try {
                $mode === 'r' ? \phasync::readable($resource, $remaining) : \phasync::writable($resource, $remaining);
            } finally {
                unset($this->streamWaiters[$key]);
                \phasync::raiseFlag($flag);
            }
        }, $timeout);
    }

    /**
     * Run a wait. Inside a phasync coroutine it suspends that coroutine. Anywhere else ({main} or a
     * fiber phasync does not own) the loop must be driven in place, so the wait runs in a phasync::run().
     */
    private function wrap(\Closure $wait, ?float $timeout = null): mixed
    {
        return $this->inLoop(static function () use ($wait, $timeout) {
            try {
                return $wait();
            } catch (PhasyncTimeout $e) {
                throw new Timeout(\sprintf('Timed out after %.3fs', $timeout ?? 0.0), 0, $e);
            } catch (PhasyncCancelled $e) {
                throw new Cancelled($e->getMessage(), 0, $e);
            }
        });
    }

    private function inLoop(\Closure $wait): mixed
    {
        if ($this->inCoroutine()) {
            return $wait();
        }
        // An exception escaping phasync::run()'s main coroutine would cancel the tasks started here
        // (a timeout must leave them running), so carry it out as a value.
        [$ok, $value] = \phasync::run(function () use ($wait) {
            $this->startPending();
            try {
                return [true, $wait()];
            } catch (\Throwable $e) {
                return [false, $e];
            }
        });
        if (!$ok) {
            throw $value;
        }
        return $value;
    }

    private function inCoroutine(): bool
    {
        $current = \Fiber::getCurrent();
        return $current !== null && \phasync::isRunning() && \phasync::getLoop()->getCurrentFiber() === $current;
    }

    private function start(Task $t): \Fiber
    {
        return $t->fiber ??= \phasync::go($t->f);
    }

    private function startPending(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $t) {
            $this->start($t);
        }
    }
}
