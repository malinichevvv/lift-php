<?php

declare(strict_types=1);

namespace Lift\Queue;

/**
 * Synchronous queue driver — executes jobs immediately in the current process.
 *
 * This is the default driver when no other queue is configured.
 * Useful in development, testing, and simple deployments where async processing
 * is not needed.
 *
 * Note: {@see later()} ignores the delay and executes immediately.
 */
final class SyncQueue implements QueueInterface
{
    /**
     * {@inheritdoc}
     *
     * The job is executed immediately. If {@see JobInterface::handle()} throws,
     * {@see JobInterface::failed()} is called and the exception is re-thrown.
     *
     * @throws \Throwable If the job fails and has no custom failure handler.
     */
    public function push(JobInterface $job): string
    {
        $id = $this->generateId();
        try {
            $job->handle();
        } catch (\Throwable $e) {
            $job->failed($e);
            throw $e;
        }
        return $id;
    }

    /**
     * {@inheritdoc}
     *
     * Delay is ignored; the job is executed immediately.
     */
    public function later(int $delay, JobInterface $job): string
    {
        return $this->push($job);
    }

    /**
     * {@inheritdoc}
     *
     * Always returns null — SyncQueue has no pending jobs.
     */
    public function pop(string $queue = 'default'): ?JobInterface
    {
        return null;
    }

    /** {@inheritdoc} */
    public function size(string $queue = 'default'): int
    {
        return 0;
    }

    /** {@inheritdoc} */
    public function clear(string $queue = 'default'): void {}

    private function generateId(): string
    {
        return 'sync_' . bin2hex(random_bytes(8));
    }
}
