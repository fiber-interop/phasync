<?php

namespace FiberInterop\Phasync;

/** Thrown in place of phasync's CancelledException, so callers can catch fiber\CancelledException. */
final class Cancelled extends \RuntimeException implements \fiber\CancelledException
{
}
