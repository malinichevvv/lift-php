<?php

declare(strict_types=1);

namespace Lift\Queue;

/**
 * Contract for all dispatchable background jobs.
 *
 * Implement this interface (or extend {@see AbstractJob}) to create a job:
 *
 * ```php
 * class SendWelcomeEmail extends AbstractJob
 * {
 *     public function __construct(private readonly string $email) {}
 *
 *     public function handle(): void
 *     {
 *         // send email…
 *     }
 * }
 *
 * $app->queue()->push(new SendWelcomeEmail('user@example.com'));
 * ```
 */
interface JobInterface
{
    /**
     * Execute the job.
     *
     * Any exception thrown here is caught by the worker; the job is either
     * retried (if tries remain) or marked as failed.
     */
    public function handle(): void;

    /**
     * Called when all retry attempts have been exhausted.
     *
     * Override in your job class to clean up, send alerts, etc.
     */
    public function failed(\Throwable $e): void;

    /**
     * Queue name this job should be placed on (default: "default").
     */
    public function getQueue(): string;

    /**
     * Delay in seconds before the job becomes available.
     * 0 = process immediately.
     */
    public function getDelay(): int;

    /**
     * Maximum number of attempts before the job is marked as failed.
     */
    public function getTries(): int;
}
