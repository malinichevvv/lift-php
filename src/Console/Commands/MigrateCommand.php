<?php

declare(strict_types=1);

namespace Lift\Console\Commands;

use Lift\Console\Command;
use Lift\Console\Input;
use Lift\Console\Output;
use Lift\Database\Connection;
use Lift\Database\Migrator;

/** Runs pending database migrations or rolls back the latest batch. */
final class MigrateCommand extends Command
{
    public function __construct(private readonly Connection $connection, private readonly string $path = 'database/migrations') {}

    public function getName(): string
    {
        return 'migrate';
    }

    public function getDescription(): string
    {
        return 'Run pending database migrations';
    }

    public function getHelp(): string
    {
        return 'Usage: lift migrate [--rollback] [--sessions]';
    }

    public function execute(Input $input, Output $output): int
    {
        $migrator = new Migrator($this->connection, getcwd() . '/' . trim($this->path, '/'));

        if ($input->hasOption('sessions')) {
            $migrator->createSessionsTable();
            $output->success('Session table is ready.');
            return 0;
        }

        $items = $input->hasOption('rollback') ? $migrator->rollback() : $migrator->migrate();
        if ($items === []) {
            $output->info('Nothing to migrate.');
            return 0;
        }

        foreach ($items as $item) {
            $output->success($item);
        }

        return 0;
    }
}
