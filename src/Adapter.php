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
 *
 * Every call happens inside the api's run(). Its first call from {main} is go() (queued as a Task,
 * phasync::go() needs a coroutine) and then await() of it, which wraps the one phasync::run().
 */
final class Adapter implements \async\Adapter
{
    /** @var list<Task> tasks created by go() outside any coroutine, not yet started */
    private array $pending = [];

    /** @var array<string, object> flag raised when the polling waiter of a stream/direction is done */
    private array $streamWaiters = [];

    /** @var int api tasks (go()) still running; the outermost run waits for them before stopping services */
    private int $active = 0;

    /** raised whenever the count of running api tasks drops to zero; never replaced or destroyed while waited on */
    private object $idle;

    /** @var list<\Fiber> */
    private array $services = [];

    public function __construct()
    {
        $this->idle = new \stdClass();
        // phasync's resume() hook runs in the loop: tell \Scope which coroutine is about to run.
        if (\function_exists('fiber_interop\\scope\\set_execution_key_provider')) {
            \fiber_interop\scope\set_execution_key_provider(
                static fn() => ScopeContext::$hooking && \phasync::isRunning() ? \phasync::getLoop()->getCurrentFiber() : null
            );
        }
    }

    public function go(\Closure $f): object
    {
        if (!$this->inCoroutine() && !\phasync::isRunning()) {
            // (a foreign fiber inside a running loop can start coroutines directly)
            // The api's run() starts its main task from {main}, then awaits it: the task starts there.
            return $this->pending[] = new Task($f);
        }
        return \phasync::go($this->counted($f), [], new ScopeContext());
    }

    public function service(\Closure $f): object
    {
        $fiber = null;
        \phasync::service(static function () use ($f, &$fiber) {
            $fiber = \Fiber::getCurrent();
            return $f();
        });
        // phasync::run() waits for services, the api's run() does not: the outermost run stops them.
        return $this->services[] = $fiber;
    }

    private function counted(\Closure $f): \Closure
    {
        return function () use ($f) {
            $this->active++;
            try {
                return $f();
            } finally {
                if (--$this->active === 0) {
                    \phasync::raiseFlag($this->idle);
                }
            }
        };
    }

    /** The outermost run: phasync::run() around the api's main task. */
    private function outermost(Task $t, float $timeout): mixed
    {
        try {
            return \phasync::run(function () use ($t, $timeout) {
                $this->startPending();
                try {
                    return $this->awaitFiber($t->fiber, $timeout);
                } finally {
                    // other tasks finish first; then the services, which run() must not wait for
                    while ($this->active > 0) {
                        \phasync::awaitFlag($this->idle);
                    }
                    foreach ($this->services as $s) {
                        if (!$s->isTerminated()) {
                            \phasync::cancel($s);
                        }
                    }
                    $this->services = [];
                }
            }, [], new ScopeContext());
        } finally {
            ScopeContext::activate(); // back in {main}
        }
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
            if ($o instanceof Task && !$this->inCoroutine() && !\phasync::isRunning()) {
                return $this->outermost($o, $timeout);
            }
            return $this->inLoop(fn() => $this->awaitFiber($o instanceof Task ? $this->start($o) : $o, $timeout));
        }
        return $this->awaitThenable($o, $timeout);
    }

    private function awaitFiber(\Fiber $fiber, float $timeout): mixed
    {
        try {
            try {
                return \phasync::await($fiber, $timeout);
            } finally {
                ScopeContext::activate();
            }
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
     * Run a wait, translating phasync's timeout and cancellation. Inside a phasync coroutine it
     * suspends that coroutine; in a fiber phasync does not own, the loop is driven in place.
     */
    private function wrap(\Closure $wait, ?float $timeout = null): mixed
    {
        return $this->inLoop(static function () use ($wait, $timeout) {
            try {
                try {
                    return $wait();
                } finally {
                    ScopeContext::activate(); // fallback: the wait returned to this coroutine
                }
            } catch (PhasyncTimeout $e) {
                throw new Timeout(\sprintf('Timed out after %.3fs', $timeout ?? 0.0), 0, $e);
            } catch (PhasyncCancelled $e) {
                throw new Cancelled($e->getMessage(), 0, $e);
            }
        });
    }

    /**
     * Run a wait from a fiber phasync does not own. Suspending it would only return control to
     * whoever started it, so the wait runs as a coroutine while this fiber drives the loop in place.
     */
    private function inLoop(\Closure $wait): mixed
    {
        if ($this->inCoroutine()) {
            return $wait();
        }
        if (!\phasync::isRunning()) {
            throw new \LogicException('fiber-interop/phasync: called outside the event loop; use async\\run().');
        }
        $coroutine = \phasync::go(function () use ($wait) {
            $this->startPending();
            return $wait();
        });
        // tick() is written for the root and clears "the current coroutine" when it returns; this
        // wait happens inside a coroutine (the one that started the foreign fiber), so put it back.
        $loop = \phasync::getLoop();
        $props = [new \ReflectionProperty($loop, 'currentFiber'), new \ReflectionProperty($loop, 'currentContext')];
        $saved = [$props[0]->getValue($loop), $props[1]->getValue($loop)];
        try {
            while (!$coroutine->isTerminated()) {
                $loop->tick();
            }
        } finally {
            $props[0]->setValue($loop, $saved[0]);
            $props[1]->setValue($loop, $saved[1]);
            ScopeContext::activate();
        }
        return \phasync::await($coroutine);
    }

    private function inCoroutine(): bool
    {
        $current = \Fiber::getCurrent();
        return $current !== null && \phasync::isRunning() && \phasync::getLoop()->getCurrentFiber() === $current;
    }

    private function start(Task $t): \Fiber
    {
        return $t->fiber ??= \phasync::go($this->counted($t->f), [], new ScopeContext());
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
