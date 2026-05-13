<?php

declare(strict_types=1);

namespace Lift\Console\Commands;

use Lift\Console\Command;
use Lift\Console\Input;
use Lift\Console\Output;
use Lift\Queue\Worker;

/**
 * Processes jobs from the queue.
 *
 * Usage:
 *   lift queue:work [--queue=default] [--sleep=1] [--max-jobs=0]
 */
final class WorkerCommand extends Command
{
    public function __construct(private readonly Worker $worker) {}

    public function getName(): string        { return 'queue:work'; }
    public function getDescription(): string { return 'Process jobs from the queue'; }
    public function getHelp(): string
    {
        return <<<'HELP'
        Runs the queue worker in a loop, processing one job at a time.

        Options:
          --queue=<name>        Queue name to poll (default: "default")
          --sleep=<seconds>     Seconds to sleep when the queue is empty (default: 1)
          --max-jobs=<n>        Stop after processing n jobs; 0 = run forever (default: 0)
        HELP;
    }

    public function execute(Input $input, Output $output): int
    {
        $queue   = (string) $input->getOption('queue', 'default');
        $sleep   = (int)    $input->getOption('sleep', '1');
        $maxJobs = (int)    $input->getOption('max-jobs', '0');

        $output->info("Queue worker started on '{$queue}'. Press Ctrl+C to stop.");
        $this->worker->run($queue, $sleep, $maxJobs);
        return 0;
    }
}
