<?php

async\register(static fn() => new \FiberInterop\Phasync\Adapter(), 'fiber-interop/phasync');

// The phasync extension makes curl_exec() cooperative when loaded; without it there is no native driver.
if (\extension_loaded('phasync') && \interface_exists(\async\curl\Driver::class)) {
    \async\curl\Registry::$candidates['fiber-interop/phasync'] = static fn() => new \FiberInterop\Phasync\CurlDriver();
}
