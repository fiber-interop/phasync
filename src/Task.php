<?php

namespace FiberInterop\Phasync;

/** A go() issued outside any phasync coroutine; it is started by the next wait. @internal */
final class Task
{
    public ?\Fiber $fiber = null;

    public function __construct(public readonly \Closure $f)
    {
    }
}
