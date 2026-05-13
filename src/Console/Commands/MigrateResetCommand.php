<?php

declare(strict_types=1);

namespace Lift\Console\Commands;

use Lift\Console\Command;
use Lift\Console\Input;
use Lift\Console\Output;
use Lift\Database\Connection;
use Lift\Database\Migrator;

/** Rolls back every migration batch (full reset). */
final class MigrateResetCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $path = 'database/migrations',
    ) {}

    public function getName(): string        { return 'migrate:reset'; }
    public function getDescription(): string { return 'Roll back all database migrations'; }

    public function execute(Input $input, Output $output): int
    {
        $migrator = new Migrator($this->connection, str_starts_with($this->path, '/') ? $this->path : getcwd() . '/' . trim($this->path, '/'));
        $rolled   = $migrator->reset();

        if ($rolled === []) {
            $output->info('Nothing to reset.');
            return 0;
        }

        foreach ($rolled as $name) {
            $output->warn("Rolled back: {$name}");
        }

        $output->success('All migrations rolled back.');
        return 0;
    }
}
