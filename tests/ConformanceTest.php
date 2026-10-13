<?php

namespace FiberInterop\Phasync\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConformanceTest extends TestCase
{
    /** Known phasync limitation, see README: a wait from {main} returns only after every coroutine has ended. */
    private const KNOWN_FAILURES = ['04-timeout-leaves-task-running'];

    public static function examples(): iterable
    {
        foreach (glob(__DIR__ . '/../vendor/fiber-interop/api/examples/*.php') as $f) {
            yield basename($f, '.php') => [basename($f, '.php')];
        }
    }

    #[DataProvider('examples')]
    public function testExample(string $name): void
    {
        $bin = __DIR__ . '/../vendor/fiber-interop/api/bin/fiber-interop-conformance';
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' ' . escapeshellarg(__DIR__ . '/bootstrap.php') . ' ' . escapeshellarg($name) . ' 2>&1', $out, $code);
        if (in_array($name, self::KNOWN_FAILURES, true)) {
            $this->markTestIncomplete('Known phasync limitation: ' . $name);
        }
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
