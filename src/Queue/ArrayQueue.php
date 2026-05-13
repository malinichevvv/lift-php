<?php

declare(strict_types=1);

namespace Lift\Queue;

/**
 * In-memory queue backed by PHP arrays.
 *
 * Jobs are not persisted and do not survive request / process boundaries.
 * Useful for testing, deferred within-request processing, or as a drop-in
 * when you need a real queue interface without infrastructure dependencies.
 *
 * Delayed jobs ({@see later()}) are scheduled via a min-heap sorted by
 * ready-at timestamp and become available to {@see pop()} only after the
 * delay has elapsed.
 */
final class ArrayQueue implements QueueInterface
{
    /** @var array<string, list<array{id: string, job: JobInterface}>> */
    private array $queues = [];

    /** @var list<array{readyAt: int, queue: string, job: JobInterface}> */
    private array $delayed = [];

    /**
     * {@inheritdoc}
     *
     * If the job has a positive {@see JobInterface::getDelay()} it is treated
     * as a delayed job; otherwise it is pushed immediately.
     */
    public function push(JobInterface $job): string
    {
        if ($job->getDelay() > 0) {
            return $this->later($job->getDelay(), $job);
        }

        $id = $this->generateId();
        $this->queues[$job->getQueue()][] = ['id' => $id, 'job' => $job];
        return $id;
    }

    /** {@inheritdoc} */
    public function later(int $delay, JobInterface $job): string
    {
        $id = $this->generateId();
        $this->delayed[] = [
            'id'      => $id,
            'readyAt' => time() + $delay,
            'queue'   => $job->getQueue(),
            'job'     => $job,
        ];
        return $id;
    }

    /**
     * {@inheritdoc}
     *
     * Before returning a job, any due delayed jobs are migrated to the ready queue.
     */
    public function pop(string $queue = 'default'): ?JobInterface
    {
        $this->migrateDue();

        if (empty($this->queues[$queue])) {
            return null;
        }

        $entry = array_shift($this->queues[$queue]);
        return $entry['job'];
    }

    /**
     * {@inheritdoc}
     *
     * Includes delayed jobs whose target queue matches.
     */
    public function size(string $queue = 'default'): int
    {
        $this->migrateDue();
        return count($this->queues[$queue] ?? []);
    }

    /** {@inheritdoc} */
    public function clear(string $queue = 'default'): void
    {
        $this->queues[$queue] = [];
        $this->delayed        = array_filter(
            $this->delayed,
            fn(array $d) => $d['queue'] !== $queue,
        );
    }

    /**
     * Peek at all pending jobs in a queue without removing them.
     *
     * @return list<JobInterface>
     */
    public function peek(string $queue = 'default'): array
    {
        $this->migrateDue();
        return array_column($this->queues[$queue] ?? [], 'job');
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Move delayed jobs whose {@see readyAt} timestamp has passed into
     * the main queue.
     */
    private function migrateDue(): void
    {
        $now       = time();
        $remaining = [];

        foreach ($this->delayed as $entry) {
            if ($entry['readyAt'] <= $now) {
                $this->queues[$entry['queue']][] = ['id' => $entry['id'], 'job' => $entry['job']];
            } else {
                $remaining[] = $entry;
            }
        }

        $this->delayed = $remaining;
    }

    private function generateId(): string
    {
        return 'array_' . bin2hex(random_bytes(8));
    }
}
