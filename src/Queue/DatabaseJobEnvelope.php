<?php

declare(strict_types=1);

namespace Lift\Queue;

/**
 * Internal wrapper that ties a deserialized job to its database row.
 *
 * Returned by {@see DatabaseQueue::pop()}. The {@see Worker} processes it
 * exactly like any other job — no special handling needed.
 *
 * On successful `handle()` the row is deleted from the table.
 * On permanent failure `failed()` marks the row with a `failed_at` timestamp
 * and stores the exception message in the `error` column.
 */
final class DatabaseJobEnvelope implements JobInterface
{
    public function __construct(
        private readonly JobInterface $inner,
        private readonly DatabaseQueue $queue,
        private readonly int $rowId,
    ) {}

    /**
     * Execute the inner job and delete the database row on success.
     *
     * Any exception propagates to the {@see Worker} for retry logic.
     * The row is NOT deleted on exception — it remains reserved until either
     * the worker retries successfully or calls {@see failed()}.
     */
    public function handle(): void
    {
        $this->inner->handle();
        $this->queue->acknowledge($this->rowId);
    }

    /**
     * Delegate to the inner job's failed() and mark the row permanently failed.
     */
    public function failed(\Throwable $e): void
    {
        $this->inner->failed($e);
        $this->queue->markFailed($this->rowId, $e);
    }

    public function getQueue(): string { return $this->inner->getQueue(); }
    public function getDelay(): int    { return $this->inner->getDelay(); }
    public function getTries(): int    { return $this->inner->getTries(); }

    /** The database row primary key. */
    public function getRowId(): int { return $this->rowId; }

    /** The original job instance. */
    public function getInner(): JobInterface { return $this->inner; }
}