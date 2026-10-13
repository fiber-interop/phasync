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

    public function testScopeActivatedOnSwitchAndOnReturnToMain(): void
    {
        $root = $_GET;
        $seen = new \ArrayObject(); // an object: arrow fns capture by value
        \async\run(function () use ($seen) {
            $task = fn(string $name, float $delay) => (new \Scope())->run(fn() => \async\go(function () use ($name, $delay, $seen) {
                $_GET = ['req' => $name];
                for ($i = 0; $i < 2; $i++) {
                    \async\sleep($delay);
                    $seen[$name] = [...($seen[$name] ?? []), $_GET['req'] ?? null];
                }
            }));
            \async\await_all([$task('a', 0.01), $task('b', 0.015)]);
        });
        $this->assertSame(['a' => ['a', 'a'], 'b' => ['b', 'b']], $seen->getArrayCopy());
        $this->assertSame($root, $_GET, 'back in {main}, the root superglobals are live again');
    }

    public function testRawCoroutineWithScopeContextIsActivatedBySwitch(): void
    {
        $seen = [];
        \async\run(function () use (&$seen) {
            $cs = [];
            foreach (['x' => 0.01, 'y' => 0.015] as $name => $delay) {
                $cs[] = \phasync::go(function () use ($name, $delay, &$seen) {
                    (new \Scope())->run(function () use ($name, $delay, &$seen) {
                        $_GET = ['req' => $name];
                        \phasync::sleep($delay);
                        \phasync::sleep($delay);
                        $seen[$name] = $_GET['req'] ?? null;
                    });
                }, [], new \FiberInterop\Phasync\ScopeContext());
            }
            foreach ($cs as $c) {
                \phasync::await($c);
            }
        });
        $this->assertSame(['x' => 'x', 'y' => 'y'], $seen);
    }

    public function testNoSwapWhenScopeUnchanged(): void
    {
        $ctx = new \FiberInterop\Phasync\ScopeContext();
        (new \Scope())->run(function () use ($ctx) {
            $_GET = ['k' => 1];
            $ref = &$_GET; // a swap would rebind the superglobal's storage; the reference stays bound without one
            $ctx->resume();
            $ctx->resume();
            $ref['k'] = 2;
            $this->assertSame(['k' => 2], $_GET);
            $this->assertFalse(\FiberInterop\Phasync\ScopeContext::$hooking);
        });
    }
}
