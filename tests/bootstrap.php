<?php
// Conformance bootstrap: loads the autoloader (src/register.php registers the adapter) and selects it.
require_once __DIR__ . '/../vendor/autoload.php';
if (!class_exists('Scope')) {
    $stub = __DIR__ . '/../vendor/fiber-interop/api/tests/Support/ScopeStub.php';
    if (is_file($stub)) {
        require_once $stub;
    }
}
fiber\setup(new FiberInterop\Phasync\Adapter());
