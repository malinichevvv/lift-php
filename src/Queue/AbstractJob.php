<?php

declare(strict_types=1);

namespace Lift\Queue;

/**
 * Convenient base class for all jobs.
 *
 * Override any property to customise queue, delay, or retry behaviour:
 *
 * ```php
 * class ProcessReport extends AbstractJob
 * {
 *     protected string $queue = 'reports';
 *     protected int    $tries = 5;
 *     protected int    $delay = 60; // start after 60 s
 *
 *     public function handle(): void { ... }
 * }
 * ```
 */
abstract class AbstractJob implements JobInterface
{
    /** Queue this job is placed on. */
    protected string $queue = 'default';

    /** Seconds before the job becomes available for processing. */
    protected int $delay = 0;

    /** Maximum processing attempts before {@see failed()} is called. */
    protected int $tries = 3;

    /**
     * {@inheritdoc}
     *
     * Override in subclasses to handle final failure gracefully.
     */
    public function failed(\Throwable $e): void {}

    public function getQueue(): string { return $this->queue; }
    public function getDelay(): int    { return $this->delay; }
    public function getTries(): int    { return $this->tries; }
}
