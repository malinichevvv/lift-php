<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\Database\Connection;
use Lift\Queue\AbstractJob;
use Lift\Queue\ArrayQueue;
use Lift\Queue\DatabaseJobEnvelope;
use Lift\Queue\DatabaseQueue;
use Lift\Queue\HasDatabaseExtra;
use Lift\Queue\SyncQueue;
use Lift\Queue\Worker;
use PHPUnit\Framework\TestCase;

class QueueTest extends TestCase
{
    // -----------------------------------------------------------------
    // SyncQueue
    // -----------------------------------------------------------------

    public function testSyncQueueExecutesImmediately(): void
    {
        $queue = new SyncQueue();
        $job   = new RecordingJob();
        $queue->push($job);
        self::assertTrue($job->handled);
    }

    public function testSyncQueueCallsFailedOnException(): void
    {
        $queue = new SyncQueue();
        $job   = new ExplodingJob();
        try {
            $queue->push($job);
        } catch (\RuntimeException) {}
        self::assertTrue($job->failedCalled);
    }

    public function testSyncQueueAlwaysReturnsZeroSize(): void
    {
        self::assertSame(0, (new SyncQueue())->size());
    }

    // -----------------------------------------------------------------
    // ArrayQueue
    // -----------------------------------------------------------------

    public function testArrayQueuePushAndPop(): void
    {
        $queue = new ArrayQueue();
        $job   = new RecordingJob();
        $queue->push($job);

        self::assertSame(1, $queue->size());
        $popped = $queue->pop();
        self::assertSame($job, $popped);
        self::assertSame(0, $queue->size());
    }

    public function testArrayQueuePopEmptyReturnsNull(): void
    {
        self::assertNull((new ArrayQueue())->pop());
    }

    public function testArrayQueueDelayedJobNotReturnedBeforeDelay(): void
    {
        $queue = new ArrayQueue();
        $job   = new DelayedJob(delay: 9999);
        $queue->push($job);
        self::assertNull($queue->pop());
        self::assertSame(0, $queue->size());
    }

    public function testArrayQueueClear(): void
    {
        $queue = new ArrayQueue();
        $queue->push(new RecordingJob());
        $queue->push(new RecordingJob());
        $queue->clear();
        self::assertSame(0, $queue->size());
    }

    public function testArrayQueueNamedQueues(): void
    {
        $queue = new ArrayQueue();
        $job1  = new RecordingJob(queue: 'emails');
        $job2  = new RecordingJob(queue: 'default');
        $queue->push($job1);
        $queue->push($job2);

        self::assertSame(1, $queue->size('emails'));
        self::assertSame(1, $queue->size('default'));
        self::assertSame($job1, $queue->pop('emails'));
    }

    // -----------------------------------------------------------------
    // Worker
    // -----------------------------------------------------------------

    public function testWorkerProcessesJob(): void
    {
        $queue  = new ArrayQueue();
        $job    = new RecordingJob();
        $queue->push($job);

        $worker = new Worker($queue);
        $worker->run(maxJobs: 1);

        self::assertTrue($job->handled);
    }

    public function testWorkerRetriesOnFailure(): void
    {
        $queue  = new ArrayQueue();
        $job    = new FlakyJob(failTimes: 2);
        $queue->push($job);

        $worker = new Worker($queue);
        $worker->process($job);

        self::assertSame(3, $job->attempts); // 2 failures + 1 success
    }

    public function testWorkerCallsFailedAfterMaxRetries(): void
    {
        $queue  = new ArrayQueue();
        $job    = new ExplodingJob();
        $worker = new Worker($queue);
        $worker->process($job);
        self::assertTrue($job->failedCalled);
    }

    // -----------------------------------------------------------------
    // DatabaseQueue
    // -----------------------------------------------------------------

    private function makeDb(): Connection
    {
        return new Connection('sqlite::memory:');
    }

    public function testDatabaseQueueAutoCreatesTable(): void
    {
        $db    = $this->makeDb();
        $queue = new DatabaseQueue($db);
        $queue->push(new RecordingJob());

        $count = (int) $db->value("SELECT COUNT(*) FROM jobs");
        self::assertSame(1, $count);
    }

    public function testDatabaseQueueCustomTableName(): void
    {
        $db    = $this->makeDb();
        $queue = new DatabaseQueue($db, table: 'my_queue');
        $queue->push(new RecordingJob());

        $count = (int) $db->value("SELECT COUNT(*) FROM my_queue");
        self::assertSame(1, $count);
    }

    public function testDatabaseQueuePushAndPop(): void
    {
        $db    = $this->makeDb();
        $queue = new DatabaseQueue($db);
        $queue->push(new RecordingJob());

        self::assertSame(1, $queue->size());

        $envelope = $queue->pop();
        self::assertInstanceOf(DatabaseJobEnvelope::class, $envelope);

        // Still in DB (reserved, not yet acknowledged)
        self::assertSame(0, $queue->size()); // not available anymore
    }

    public function testDatabaseQueuePopEmptyReturnsNull(): void
    {
        $queue = new DatabaseQueue($this->makeDb());
        self::assertNull($queue->pop());
    }

    public function testDatabaseQueueDelayedJobNotReturnedBeforeDelay(): void
    {
        $db    = $this->makeDb();
        $queue = new DatabaseQueue($db);
        $queue->push(new DelayedJob(delay: 9999));

        self::assertSame(0, $queue->size());
        self::assertNull($queue->pop());
    }

    public function testDatabaseQueueDelayedJobViaPush(): void
    {
        $db    = $this->makeDb();
        $queue = new DatabaseQueue($db);
        $queue->later(9999, new RecordingJob());

        self::assertNull($queue->pop());
    }

    public function testDatabaseQueueClear(): void
    {
        $db    = $this->makeDb();
        $queue = new DatabaseQueue($db);
        $queue->push(new RecordingJob());
        $queue->push(new RecordingJob());
        $queue->clear();

        self::assertSame(0, $queue->size());
    }

    public function testDatabaseQueueNamedQueues(): void
    {
        $db    = $this->makeDb();
        $queue = new DatabaseQueue($db);
        $queue->push(new RecordingJob(queue: 'emails'));
        $queue->push(new RecordingJob(queue: 'default'));

        self::assertSame(1, $queue->size('emails'));
        self::assertSame(1, $queue->size('default'));

        $envelope = $queue->pop('emails');
        self::assertNotNull($envelope);
        self::assertSame('emails', $envelope->getQueue());
    }

    public function testDatabaseQueueJobDeletedOnSuccess(): void
    {
        $db    = $this->makeDb();
        $queue = new DatabaseQueue($db);
        $queue->push(new RecordingJob());

        $envelope = $queue->pop();
        self::assertNotNull($envelope);
        $envelope->handle(); // succeeds → row deleted

        self::assertSame(0, (int) $db->value("SELECT COUNT(*) FROM jobs"));
    }

    public function testDatabaseQueueJobMarkedFailedAfterMaxRetries(): void
    {
        $db     = $this->makeDb();
        $queue  = new DatabaseQueue($db);
        $job    = new ExplodingJob();
        $queue->push($job);

        $envelope = $queue->pop();
        self::assertNotNull($envelope);

        $worker = new Worker($queue);
        $worker->process($envelope); // all retries exhausted → failed() called

        self::assertSame(1, $queue->failedCount());
        self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM jobs WHERE failed_at IS NOT NULL"));
    }

    public function testDatabaseQueueListFailed(): void
    {
        $db    = $this->makeDb();
        $queue = new DatabaseQueue($db);
        $queue->push(new ExplodingJob());

        $envelope = $queue->pop();
        self::assertNotNull($envelope);
        (new Worker($queue))->process($envelope);

        $failed = $queue->listFailed();
        self::assertCount(1, $failed);
        self::assertNotNull($failed[0]['failed_at']);
        self::assertNotEmpty($failed[0]['error']);
    }

    public function testDatabaseQueueRetryResetsRow(): void
    {
        $db    = $this->makeDb();
        $queue = new DatabaseQueue($db);
        $queue->push(new ExplodingJob());

        $envelope = $queue->pop();
        self::assertNotNull($envelope);
        (new Worker($queue))->process($envelope);

        self::assertSame(1, $queue->failedCount());

        $row = $queue->listFailed()[0];
        $queue->retry((int) $row['id']);

        self::assertSame(0, $queue->failedCount());
        self::assertSame(1, $queue->size()); // back in pending
    }

    public function testDatabaseQueueRetryAll(): void
    {
        $db    = $this->makeDb();
        $queue = new DatabaseQueue($db);

        foreach (range(1, 3) as $_) {
            $envelope = null;
            $queue->push(new ExplodingJob());
            $envelope = $queue->pop();
            self::assertNotNull($envelope);
            (new Worker($queue))->process($envelope);
        }

        self::assertSame(3, $queue->failedCount());

        $count = $queue->retryAll();
        self::assertSame(3, $count);
        self::assertSame(0, $queue->failedCount());
        self::assertSame(3, $queue->size());
    }

    public function testDatabaseQueueClearFailed(): void
    {
        $db    = $this->makeDb();
        $queue = new DatabaseQueue($db);
        $queue->push(new ExplodingJob());

        $envelope = $queue->pop();
        self::assertNotNull($envelope);
        (new Worker($queue))->process($envelope);

        $queue->clearFailed();
        self::assertSame(0, $queue->failedCount());
        self::assertSame(0, (int) $db->value("SELECT COUNT(*) FROM jobs"));
    }

    public function testDatabaseQueuePruneReservedReleasesStuckJobs(): void
    {
        $db    = $this->makeDb();
        $queue = new DatabaseQueue($db, reservedTimeout: 60);
        $queue->push(new RecordingJob());

        // Pop (reserves the row)
        $envelope = $queue->pop();
        self::assertNotNull($envelope);
        self::assertSame(0, $queue->size()); // reserved, not visible

        // Simulate a crash by backdating reserved_at
        /** @var DatabaseJobEnvelope $envelope */
        $db->execute("UPDATE jobs SET reserved_at = ? WHERE id = ?", [
            time() - 120,
            $envelope->getRowId(),
        ]);

        // pruneReserved should release it
        $released = $queue->pruneReserved(60);
        self::assertSame(1, $released);
        self::assertSame(1, $queue->size()); // available again
    }

    public function testDatabaseQueueRelease(): void
    {
        $db    = $this->makeDb();
        $queue = new DatabaseQueue($db);
        $queue->push(new RecordingJob());

        /** @var DatabaseJobEnvelope $envelope */
        $envelope = $queue->pop();
        self::assertNotNull($envelope);
        self::assertSame(0, $queue->size());

        $queue->release($envelope->getRowId());
        self::assertSame(1, $queue->size());
    }

    public function testDatabaseQueueExtraColumns(): void
    {
        $db    = $this->makeDb();
        $queue = new DatabaseQueue(
            $db,
            extraColumns: static function (\Lift\Database\Schema\Blueprint $t): void {
                $t->string('tenant_id', 36)->nullable();
            },
        );

        $job = new TenantJob('acme-123');
        $queue->push($job);

        $row = $db->selectOne("SELECT tenant_id FROM jobs LIMIT 1");
        self::assertNotNull($row);
        self::assertSame('acme-123', $row['tenant_id']);
    }

    public function testDatabaseQueueWorkerProcessesJobEndToEnd(): void
    {
        $db    = $this->makeDb();
        $queue = new DatabaseQueue($db);
        $queue->push(new RecordingJob());

        (new Worker($queue))->run(maxJobs: 1);

        // Successful processing deletes the row
        self::assertSame(0, (int) $db->value("SELECT COUNT(*) FROM jobs"));
    }

    public function testDatabaseQueueAttemptsIncrementedOnPop(): void
    {
        $db    = $this->makeDb();
        $queue = new DatabaseQueue($db);
        $queue->push(new RecordingJob());

        $queue->pop(); // reserves row, increments attempts

        $row = $db->selectOne("SELECT attempts FROM jobs LIMIT 1");
        self::assertSame(1, (int) $row['attempts']);
    }
}

// ---- Fixtures --------------------------------------------------------

class RecordingJob extends AbstractJob
{
    public bool $handled = false;

    public function __construct(string $queue = 'default')
    {
        $this->queue = $queue;
    }

    public function handle(): void { $this->handled = true; }
}

class ExplodingJob extends AbstractJob
{
    public bool $failedCalled = false;
    protected int $tries = 1;

    public function handle(): void { throw new \RuntimeException('boom'); }
    public function failed(\Throwable $e): void { $this->failedCalled = true; }
}

class DelayedJob extends AbstractJob
{
    public function __construct(int $delay)
    {
        $this->delay = $delay;
    }

    public function handle(): void {}
}

class FlakyJob extends AbstractJob
{
    public int $attempts = 0;

    public function __construct(private int $failTimes)
    {
        $this->tries = 3;
    }

    public function handle(): void
    {
        $this->attempts++;
        if ($this->attempts <= $this->failTimes) {
            throw new \RuntimeException('not yet');
        }
    }
}

class TenantJob extends AbstractJob implements HasDatabaseExtra
{
    public function __construct(private readonly string $tenantId) {}

    public function handle(): void {}

    public function getDatabaseExtra(): array
    {
        return ['tenant_id' => $this->tenantId];
    }
}
