<?php

async\register(static fn() => new \FiberInterop\Phasync\Adapter(), 'fiber-interop/phasync');

// The phasync extension makes curl_exec() cooperative when loaded; without it there is no native driver.
if (\extension_loaded('phasync') && \interface_exists(\async\curl\Driver::class)) {
    \async\curl\Registry::$candidates['fiber-interop/phasync'] = static fn() => new \FiberInterop\Phasync\CurlDriver();
}

// The phasync extension hooks PHP streams, which mysqlnd uses, so mysqli::query() is cooperative.
if (\extension_loaded('phasync') && \extension_loaded('mysqli') && \interface_exists(\async\mysqli\Driver::class)) {
    \async\mysqli\Registry::$candidates['fiber-interop/phasync'] = static fn() => new \FiberInterop\Phasync\MysqliDriver();
}
