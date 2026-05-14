<?php

declare(strict_types=1);

namespace Lift\Queue;

/**
 * Contract for queue drivers.
 *
 * Built-in drivers:
 * - {@see SyncQueue}     — executes jobs immediately in the same process
 * - {@see ArrayQueue}    — stores jobs in a PHP array (in-memory, no persistence)
 * - {@see RedisQueue}    — persists jobs in Redis lists / sorted sets
 * - {@see DatabaseQueue} — persists jobs in a relational database table (MySQL, PostgreSQL, SQLite)
 */
interface QueueInterface
{
    /**
     * Push a job onto the queue.
     *
     * If the job has a non-zero {@see JobInterface::getDelay()} the driver may
     * defer it until the delay has elapsed.
     *
     * @return string A unique job ID assigned by the driver.
     */
    public function push(JobInterface $job): string;

    /**
     * Push a job that should not become available for processing until {@see $delay}
     * seconds have passed.
     *
     * @param int $delay Seconds until the job is eligible for processing.
     * @return string Job ID.
     */
    public function later(int $delay, JobInterface $job): string;

    /**
     * Retrieve and remove the next available job from the queue.
     *
     * @param string $queue Queue name (default: "default").
     * @return JobInterface|null Null if the queue is empty.
     */
    public function pop(string $queue = 'default'): ?JobInterface;

    /**
     * Return the number of pending jobs in the queue.
     */
    public function size(string $queue = 'default'): int;

    /**
     * Remove all pending jobs from the queue.
     */
    public function clear(string $queue = 'default'): void;
}
