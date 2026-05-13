<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\Queue\AbstractJob;
use Lift\Queue\ArrayQueue;
use Lift\Queue\JobInterface;
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
