<?php

declare(strict_types=1);

namespace Lift\Console\Commands;

use Lift\Console\Command;
use Lift\Console\Input;
use Lift\Console\Output;
use Lift\Database\Connection;
use Lift\Database\Migrator;

/** Displays the run status of every known migration file. */
final class MigrateStatusCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $path = 'database/migrations',
    ) {}

    public function getName(): string        { return 'migrate:status'; }
    public function getDescription(): string { return 'Show the status of each migration'; }

    public function execute(Input $input, Output $output): int
    {
        $migrator = new Migrator($this->connection, str_starts_with($this->path, '/') ? $this->path : getcwd() . '/' . trim($this->path, '/'));
        $status   = $migrator->status();

        if ($status === []) {
            $output->info('No migrations found.');
            return 0;
        }

        $rows = [];
        foreach ($status as $item) {
            $rows[] = [
                'Ran'       => $item['ran'] ? 'Yes' : 'No',
                'Batch'     => $item['batch'] !== null ? (string) $item['batch'] : '',
                'Migration' => $item['migration'] . (!empty($item['missing']) ? ' (file missing)' : ''),
            ];
        }

        $output->table(['Ran', 'Batch', 'Migration'], $rows);
        return 0;
    }
}
