<?php

declare(strict_types=1);

namespace Lift\Console\Commands;

use Lift\Console\Command;
use Lift\Console\Input;
use Lift\Console\Output;
use Lift\Database\Connection;
use Lift\Database\Migrator;

/** Rolls back all migrations and re-runs them from scratch. */
final class MigrateFreshCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $path = 'database/migrations',
    ) {}

    public function getName(): string        { return 'migrate:fresh'; }
    public function getDescription(): string { return 'Drop all tables and re-run all migrations'; }

    public function execute(Input $input, Output $output): int
    {
        $migrator = new Migrator($this->connection, str_starts_with($this->path, '/') ? $this->path : getcwd() . '/' . trim($this->path, '/'));
        $result   = $migrator->fresh();

        foreach ($result['reset'] as $name) {
            $output->warn("Rolled back: {$name}");
        }
        foreach ($result['migrated'] as $name) {
            $output->success("Migrated:    {$name}");
        }

        if ($result['reset'] === [] && $result['migrated'] === []) {
            $output->info('No migrations found.');
        } else {
            $output->success('Database refreshed.');
        }

        return 0;
    }
}
