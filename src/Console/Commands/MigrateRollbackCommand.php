<?php

declare(strict_types=1);

namespace Lift\Console\Commands;

use Lift\Console\Command;
use Lift\Console\Input;
use Lift\Console\Output;
use Lift\Database\Connection;
use Lift\Database\Migrator;

/** Rolls back the last N migration batches. */
final class MigrateRollbackCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $path = 'database/migrations',
    ) {}

    public function getName(): string        { return 'migrate:rollback'; }
    public function getDescription(): string { return 'Roll back the last database migration batch'; }
    public function getHelp(): string        { return 'Usage: lift migrate:rollback [--steps=1]'; }

    public function execute(Input $input, Output $output): int
    {
        $steps    = max(1, (int) $input->getOption('steps', '1'));
        $migrator = new Migrator($this->connection, str_starts_with($this->path, '/') ? $this->path : getcwd() . '/' . trim($this->path, '/'));
        $rolled   = $migrator->rollback($steps);

        if ($rolled === []) {
            $output->info('Nothing to roll back.');
            return 0;
        }

        foreach ($rolled as $name) {
            $output->warn("Rolled back: {$name}");
        }

        return 0;
    }
}
