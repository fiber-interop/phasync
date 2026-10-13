<?php

namespace FiberInterop\Phasync;

use phasync\Context\SwitchAwareInterface;

/**
 * A phasync context that activates the current \Scope (and its superglobals) whenever phasync switches to
 * a coroutine of this context from a coroutine of another context.
 *
 * phasync calls resume() from its event loop, before the coroutine runs, so \Scope::getCurrent() would see
 * the loop, not the coroutine; the execution key provider installed by the Adapter answers with the
 * coroutine phasync is about to run while resume() is active.
 *
 * phasync only calls hooks when the context changes, so coroutines sharing one context are not covered
 * among themselves. The Adapter gives every api task its own ScopeContext; raw code can do the same:
 * `phasync::go($fn, [], new ScopeContext())`.
 */
final class ScopeContext implements SwitchAwareInterface
{
    /** True while phasync's resume() hook runs in the loop. */
    public static bool $hooking = false;

    public static function activate(): void
    {
        if (\function_exists('fiber_interop\\scope\\activate')) {
            \fiber_interop\scope\activate();
        }
    }

    public function resume(): void
    {
        self::$hooking = true;
        try {
            self::activate();
        } finally {
            self::$hooking = false;
        }
    }

    public function suspend(): void
    {
    }
}
