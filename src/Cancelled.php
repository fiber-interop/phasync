<?php

namespace FiberInterop\Phasync;

/** Thrown in place of phasync's CancelledException, so callers can catch async\CancelledException. */
final class Cancelled extends \RuntimeException implements \async\CancelledException
{
}
