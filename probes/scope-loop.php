<?php
// Runtime-specific: the scenario with phasync primitives only (phasync::go + phasync::sleep, no async\ functions).
// phasync makes each coroutine's Scope current (and swaps the polyfill's superglobals) on every switch.
require __DIR__ . '/../vendor/autoload.php';

async\setup(new FiberInterop\Phasync\Adapter());
$root = $_GET;
$bad = [];
phasync::run(function () use (&$bad) {
    $coroutines = [];
    foreach ([['a', 0.01], ['b', 0.015]] as [$name, $delay]) {
        $coroutines[] = phasync::go(function () use ($name, $delay, &$bad) {
            (new Scope())->run(function () use ($name, $delay, &$bad) {
                $_GET = ['req' => $name];
                for ($i = 0; $i < 2; $i++) {
                    phasync::sleep($delay);
                    if (($_GET['req'] ?? null) !== $name) {
                        $bad[] = "$name after sleep $i saw " . json_encode($_GET);
                    }
                }
            });
        });
    }
    foreach ($coroutines as $c) {
        phasync::await($c);
    }
});
if ($_GET !== $root) {
    $bad[] = 'root $_GET not restored: ' . json_encode($_GET);
}
echo $bad ? implode("\n", $bad) . "\n" : "ok\n";
exit($bad ? 1 : 0);
