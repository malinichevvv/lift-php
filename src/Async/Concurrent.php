<?php

declare(strict_types=1);

namespace Lift\Async;

use Fiber;

/**
 * Cooperative concurrency using PHP 8.1 Fibers.
 *
 * All tasks run within the **same thread** — concurrency is achieved by
 * voluntarily suspending (via `Concurrent::suspend()`) at I/O wait points.
 * This is useful for parallelising multiple blocking operations that spend
 * most of their time waiting (e.g. HTTP calls with curl_multi, sleep
 * simulations, sequential DB reads).
 *
 * ```php
 * [$a, $b] = Concurrent::all([
 *     fn() => fetchUser(1),
 *     fn() => fetchUser(2),
 * ]);
 *
 * // Inside a task, voluntarily yield control:
 * Concurrent::suspend();
 * ```
 */
final class Concurrent
{
    /**
     * Run multiple callables concurrently and return their results in order.
     *
     * Tasks are started as Fibers and round-robined until all complete.
     * A task MAY call {@see suspend()} to yield control to the next task.
     *
     * @template T
     * @param  array<callable(): T> $tasks
     * @return array<int, T>        Results in the same order as $tasks.
     * @throws \Throwable           Re-throws the first exception from any task.
     */
    public static function all(array $tasks): array
    {
        /** @var Fiber[] $fibers */
        $fibers  = [];
        $results = [];

        foreach ($tasks as $i => $task) {
            $fibers[$i] = new Fiber(static function () use ($task, $i, &$results): void {
                $results[$i] = $task();
            });
        }

        // Start all fibers
        foreach ($fibers as $fiber) {
            $fiber->start();
        }

        // Round-robin resume until all are done
        $running = true;
        while ($running) {
            $running = false;
            foreach ($fibers as $fiber) {
                if (!$fiber->isTerminated()) {
                    $running = true;
                    if ($fiber->isSuspended()) {
                        $fiber->resume();
                    }
                }
                if ($fiber->isTerminated() && $fiber->getReturn() === null) {
                    // Check for thrown exceptions
                    // (PHP re-throws inside fiber->start/resume, so this path
                    // is reached only on clean termination)
                }
            }
        }

        ksort($results);
        return $results;
    }

    /**
     * Voluntarily suspend the current fiber, yielding control to others.
     *
     * Must be called from within a fiber (i.e. inside a task passed to
     * {@see all()}). A no-op when called outside of a fiber.
     */
    public static function suspend(): void
    {
        if (Fiber::getCurrent() !== null) {
            Fiber::suspend();
        }
    }

    /**
     * Run tasks sequentially and return results — useful as a drop-in
     * replacement for `all()` in environments that prohibit fibers.
     *
     * @template T
     * @param  array<callable(): T> $tasks
     * @return array<int, T>
     */
    public static function sequential(array $tasks): array
    {
        $results = [];
        foreach ($tasks as $i => $task) {
            $results[$i] = $task();
        }
        return $results;
    }

    /**
     * Run a single callable inside a Fiber and return its result.
     *
     * @template T
     * @param  callable(): T $task
     * @return T
     */
    public static function run(callable $task): mixed
    {
        $result = null;
        $fiber  = new Fiber(static function () use ($task, &$result): void {
            $result = $task();
        });

        $fiber->start();
        while (!$fiber->isTerminated()) {
            if ($fiber->isSuspended()) {
                $fiber->resume();
            }
        }

        return $result;
    }
}
