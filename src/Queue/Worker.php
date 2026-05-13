<?php

declare(strict_types=1);

namespace Lift\Queue;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * CLI worker that continuously polls a queue and executes jobs.
 *
 * Typical usage in a CLI entry-point:
 *
 * ```php
 * // bin/worker.php
 * require 'vendor/autoload.php';
 *
 * $app    = require 'bootstrap.php';
 * $worker = new Worker($app->queue(), $app->make(LoggerInterface::class));
 * $worker->run(queue: 'default', sleep: 1, maxJobs: 0);
 * ```
 *
 * Signals: send SIGTERM or SIGINT to gracefully finish the current job and stop.
 */
final class Worker
{
    private bool $shouldStop = false;

    public function __construct(
        private readonly QueueInterface $queue,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->registerSignalHandlers();
    }

    /**
     * Poll and execute jobs until stopped.
     *
     * @param string $queue    Queue name to poll (default: "default").
     * @param int    $sleep    Seconds to sleep when the queue is empty.
     * @param int    $maxJobs  Stop after processing this many jobs. 0 = unlimited.
     */
    public function run(string $queue = 'default', int $sleep = 1, int $maxJobs = 0): void
    {
        $processed = 0;

        while (!$this->shouldStop) {
            $job = $this->queue->pop($queue);

            if ($job === null) {
                sleep($sleep);
                continue;
            }

            $this->process($job);
            $processed++;

            if ($maxJobs > 0 && $processed >= $maxJobs) {
                break;
            }
        }

        $this->logger->info("Worker stopped after processing {$processed} job(s).");
    }

    /**
     * Process a single job with retry logic.
     *
     * Calls {@see JobInterface::handle()}. On failure, retries up to
     * {@see JobInterface::getTries()} times, then calls {@see JobInterface::failed()}.
     */
    public function process(JobInterface $job): void
    {
        $attempts = 0;
        $tries    = max(1, $job->getTries());

        while ($attempts < $tries) {
            $attempts++;
            try {
                $job->handle();
                $this->logger->info('Job processed', ['class' => $job::class, 'attempt' => $attempts]);
                return;
            } catch (\Throwable $e) {
                $this->logger->warning('Job attempt failed', [
                    'class'   => $job::class,
                    'attempt' => $attempts,
                    'error'   => $e->getMessage(),
                ]);

                if ($attempts >= $tries) {
                    $this->logger->error('Job failed permanently', ['class' => $job::class]);
                    $job->failed($e);
                    return;
                }
            }
        }
    }

    /**
     * Request the worker to stop after the current job finishes.
     */
    public function stop(): void
    {
        $this->shouldStop = true;
    }

    private function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        $stop = function (): void { $this->stop(); };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }
}
