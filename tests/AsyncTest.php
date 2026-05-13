<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\Async\Concurrent;
use PHPUnit\Framework\TestCase;

class AsyncTest extends TestCase
{
    // -----------------------------------------------------------------
    // Concurrent::all()
    // -----------------------------------------------------------------

    public function testAllRunsAllTasks(): void
    {
        $results = Concurrent::all([
            fn() => 1,
            fn() => 2,
            fn() => 3,
        ]);

        self::assertSame([1, 2, 3], $results);
    }

    public function testAllPreservesOrder(): void
    {
        // Even when tasks suspend, results must be in submission order.
        $results = Concurrent::all([
            function () { Concurrent::suspend(); return 'a'; },
            function () { return 'b'; },
            function () { Concurrent::suspend(); return 'c'; },
        ]);

        self::assertSame(['a', 'b', 'c'], $results);
    }

    public function testAllEmptyTasksReturnsEmpty(): void
    {
        self::assertSame([], Concurrent::all([]));
    }

    public function testAllWithSingleTask(): void
    {
        $results = Concurrent::all([fn() => 42]);
        self::assertSame([42], $results);
    }

    public function testAllWithStrings(): void
    {
        $results = Concurrent::all([
            fn() => 'hello',
            fn() => 'world',
        ]);
        self::assertSame(['hello', 'world'], $results);
    }

    // -----------------------------------------------------------------
    // Concurrent::sequential()
    // -----------------------------------------------------------------

    public function testSequentialRunsInOrder(): void
    {
        $log = [];
        Concurrent::sequential([
            function () use (&$log) { $log[] = 1; return 1; },
            function () use (&$log) { $log[] = 2; return 2; },
            function () use (&$log) { $log[] = 3; return 3; },
        ]);

        self::assertSame([1, 2, 3], $log);
    }

    public function testSequentialReturnsResults(): void
    {
        $results = Concurrent::sequential([
            fn() => 'x',
            fn() => 'y',
        ]);
        self::assertSame(['x', 'y'], $results);
    }

    // -----------------------------------------------------------------
    // Concurrent::run()
    // -----------------------------------------------------------------

    public function testRunReturnsSingleResult(): void
    {
        $result = Concurrent::run(fn() => 'single');
        self::assertSame('single', $result);
    }

    public function testRunWithSuspend(): void
    {
        $result = Concurrent::run(function () {
            Concurrent::suspend();
            return 'after suspend';
        });
        self::assertSame('after suspend', $result);
    }

    // -----------------------------------------------------------------
    // Concurrent::suspend() outside fiber (no-op)
    // -----------------------------------------------------------------

    public function testSuspendOutsideFiberIsNoop(): void
    {
        // Should not throw
        Concurrent::suspend();
        $this->addToAssertionCount(1);
    }

    // -----------------------------------------------------------------
    // Concurrent accumulates multiple values
    // -----------------------------------------------------------------

    public function testAllAccumulatesData(): void
    {
        $data = [10, 20, 30, 40, 50];

        $results = Concurrent::all(array_map(
            fn(int $n) => fn() => $n * 2,
            $data,
        ));

        self::assertSame([20, 40, 60, 80, 100], $results);
    }
}
