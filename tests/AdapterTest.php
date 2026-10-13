<?php

namespace FiberInterop\Phasync\Tests;

use FiberInterop\Phasync\Adapter;
use FiberInterop\Phasync\Cancelled;
use PHPUnit\Framework\TestCase;

final class AdapterTest extends TestCase
{
    private Adapter $a;

    protected function setUp(): void
    {
        $this->a = new Adapter();
    }

    public function testAwaitTimeoutIsApiTimeout(): void
    {
        $t = $this->a->go(function () { $this->a->sleep(0.2); });
        $this->expectException(\async\TimeoutException::class);
        $this->a->await($t, 0.01);
    }

    public function testReadableTimeoutIsApiTimeout(): void
    {
        [$r, $w] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($r, false);
        $this->expectException(\async\TimeoutException::class);
        \async\run(fn() => $this->a->readable($r, 0.01));
    }

    public function testCancelledIsApiCancelled(): void
    {
        $r = $this->a->await($this->a->go(function () {
            $inner = $this->a->go(function () { $this->a->sleep(1); });
            \phasync::go(function () use ($inner) { \phasync::cancel($inner); });
            try {
                $this->a->await($inner, 5);
            } catch (\async\CancelledException $e) {
                return get_class($e);
            }
            return 'not cancelled';
        }), 5);
        $this->assertSame(Cancelled::class, $r);
    }

    public function testSettledThenableBeforeAwait(): void
    {
        $then = new class {
            public function then(callable $ok, callable $fail): void { $ok(42); $ok(43); }
        };
        \async\run(function () use ($then) {
            $this->assertSame(42, $this->a->await($then, 1.0));
            $this->assertSame(42, $this->a->await($this->a->go(fn() => $this->a->await($then, 1.0)), 1.0));
        });
    }

    public function testRejectedNonThrowable(): void
    {
        $then = new class {
            public function then(callable $ok, callable $fail): void { $fail('nope'); }
        };
        $this->expectException(\async\RejectedException::class);
        \async\run(fn() => $this->a->await($then, 1.0));
    }

    public function testTwoFibersReadableOnOneStream(): void
    {
        [$r, $w] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($r, false);
        $woke = [];
        $tasks = [];
        foreach (['x', 'y'] as $n) {
            $tasks[] = $this->a->go(function () use ($r, $n, &$woke) { $this->a->readable($r, 2.0); $woke[] = $n; });
        }
        $tasks[] = $this->a->go(function () use ($w) { $this->a->sleep(0.01); fwrite($w, 'hi'); });
        foreach ($tasks as $t) {
            $this->a->await($t, 5.0);
        }
        $this->assertSame(['x', 'y'], $woke);
    }
}
