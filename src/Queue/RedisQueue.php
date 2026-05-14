<?php

declare(strict_types=1);

namespace Lift\Queue;

use Lift\Redis\RedisClientInterface;

/**
 * Queue driver backed by Redis.
 *
 * Ready jobs are stored in Redis lists (LPUSH / RPOP).
 * Delayed jobs are stored in a sorted set keyed by their ready-at timestamp.
 *
 * The {@see pop()} method atomically migrates due delayed jobs before
 * returning the next ready job.
 *
 * Redis key layout:
 * - `{prefix}:queue:{name}`         — list of serialised ready jobs
 * - `{prefix}:queue:{name}:delayed` — sorted set (score = ready-at UNIX timestamp)
 *
 * ```php
 * $queue = new RedisQueue(new RedisClient());
 * $app->instance(QueueInterface::class, $queue);
 *
 * // In a CLI worker:
 * $worker = new Worker($app->queue());
 * $worker->run(); // polls indefinitely
 * ```
 */
final class RedisQueue implements QueueInterface
{
    use SerializesJobs;

    /**
     * @param RedisClientInterface $redis
     * @param string $prefix  Redis key prefix.
     * @param string $secret  When non-empty, queue payloads are HMAC-signed (SHA-256).
     *                        Use the same secret on all workers. Prevents RCE via
     *                        deserialization of tampered payloads in a compromised Redis.
     */
    public function __construct(
        private readonly RedisClientInterface $redis,
        private readonly string $prefix = 'lift',
        private readonly string $secret = '',
    ) {}

    /**
     * {@inheritdoc}
     *
     * @throws \RuntimeException If the job cannot be serialised.
     */
    public function push(JobInterface $job): string
    {
        if ($job->getDelay() > 0) {
            return $this->later($job->getDelay(), $job);
        }

        $id      = $this->generateJobId('redis');
        $payload = $this->serialiseJob($job, $id);
        $this->redis->lPush($this->key($job->getQueue()), $payload);
        return $id;
    }

    /** {@inheritdoc} */
    public function later(int $delay, JobInterface $job): string
    {
        $id       = $this->generateJobId('redis');
        $payload  = $this->serialiseJob($job, $id);
        $readyAt  = time() + $delay;
        $this->redis->zAdd($this->delayedKey($job->getQueue()), (float) $readyAt, $payload);
        return $id;
    }

    /**
     * {@inheritdoc}
     *
     * Migrates any due delayed jobs to the ready list first.
     */
    public function pop(string $queue = 'default'): ?JobInterface
    {
        $this->migrateDue($queue);

        $raw = $this->redis->rPop($this->key($queue));
        if ($raw === false) {
            return null;
        }

        return $this->deserialiseJob($raw);
    }

    /** {@inheritdoc} */
    public function size(string $queue = 'default'): int
    {
        $this->migrateDue($queue);
        return $this->redis->lLen($this->key($queue));
    }

    /** {@inheritdoc} */
    public function clear(string $queue = 'default'): void
    {
        $this->redis->del($this->key($queue), $this->delayedKey($queue));
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function migrateDue(string $queue): void
    {
        $now      = (string) time();
        $payloads = $this->redis->zRangeByScore($this->delayedKey($queue), '-inf', $now);

        if ($payloads === []) {
            return;
        }

        $this->redis->zRem($this->delayedKey($queue), ...$payloads);
        foreach ($payloads as $payload) {
            $this->redis->lPush($this->key($queue), $payload);
        }
    }

    private function key(string $queue): string
    {
        return "{$this->prefix}:queue:{$queue}";
    }

    private function delayedKey(string $queue): string
    {
        return "{$this->prefix}:queue:{$queue}:delayed";
    }

}
