<?php
// Runtime-specific: the scenario with phasync primitives only (phasync::go + phasync::sleep, no async\ functions).
// Each coroutine gets its own FiberInterop\Phasync\ScopeContext: phasync calls context hooks only when
// the context changes, so coroutines sharing one context are not covered.
// Pass --plain to use plain phasync::go (one shared context): expected to fail, see README.
require __DIR__ . '/../vendor/autoload.php';

use FiberInterop\Phasync\ScopeContext;

async\setup(new FiberInterop\Phasync\Adapter());
$plain = in_array('--plain', $argv, true);
$root = $_GET;
$bad = [];
phasync::run(function () use (&$bad, $plain) {
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
        }, [], $plain ? null : new ScopeContext());
    }
    foreach ($coroutines as $c) {
        phasync::await($c);
    }
}, [], $plain ? null : new ScopeContext());
ScopeContext::activate(); // raw phasync::run() returning to {main}: nothing in phasync can do this for us
if ($_GET !== $root) {
    $bad[] = 'root $_GET not restored: ' . json_encode($_GET);
}
echo $bad ? implode("\n", $bad) . "\n" : "ok\n";
exit($bad ? 1 : 0);
