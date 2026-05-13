<?php

declare(strict_types=1);

namespace Lift\Database;

/** Lightweight migration runner with batch tracking and rollback support. */
final class Migrator
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $path,
        private readonly string $table = 'migrations',
    ) {}

    /** Run all pending migrations and return their names. */
    public function migrate(): array
    {
        $this->ensureRepository();
        $ran      = array_flip($this->ran());
        $batch    = $this->nextBatch();
        $migrated = [];

        foreach ($this->files() as $file) {
            $name = basename($file, '.php');
            if (isset($ran[$name])) {
                continue;
            }
            $this->load($file)->up();
            $this->db->execute("INSERT INTO {$this->table} (migration, batch) VALUES (?, ?)", [$name, $batch]);
            $migrated[] = $name;
        }

        return $migrated;
    }

    /**
     * Roll back the last N migration batches.
     *
     * @return string[] Rolled-back migration names.
     */
    public function rollback(int $steps = 1): array
    {
        $this->ensureRepository();
        $rolled = [];

        for ($i = 0; $i < $steps; $i++) {
            $batch = $this->currentBatch();
            if ($batch === 0) {
                break;
            }
            $rolled = array_merge($rolled, $this->rollbackBatch($batch));
        }

        return $rolled;
    }

    /**
     * Roll back every migration batch (full reset).
     *
     * @return string[] Rolled-back migration names.
     */
    public function reset(): array
    {
        $this->ensureRepository();
        $batch = $this->currentBatch();
        return $batch > 0 ? $this->rollback($batch) : [];
    }

    /**
     * Reset all migrations then re-run them from scratch.
     *
     * @return array{reset: string[], migrated: string[]}
     */
    public function fresh(): array
    {
        return [
            'reset'    => $this->reset(),
            'migrated' => $this->migrate(),
        ];
    }

    /**
     * Return the run status of every known migration file.
     *
     * Each entry: `['migration' => string, 'ran' => bool, 'batch' => int|null]`.
     * Entries whose files have been deleted also appear with `'missing' => true`.
     *
     * @return list<array{migration: string, ran: bool, batch: int|null}>
     */
    public function status(): array
    {
        $this->ensureRepository();

        $ranRows = $this->db->select(
            "SELECT migration, batch FROM {$this->table} ORDER BY migration ASC"
        );
        $ran = [];
        foreach ($ranRows as $row) {
            $ran[(string) $row['migration']] = (int) $row['batch'];
        }

        $result = [];
        foreach ($this->files() as $file) {
            $name     = basename($file, '.php');
            $result[] = [
                'migration' => $name,
                'ran'       => isset($ran[$name]),
                'batch'     => $ran[$name] ?? null,
            ];
        }

        // Include ran migrations whose files have since been deleted.
        foreach ($ran as $name => $batch) {
            $found = false;
            foreach ($result as $item) {
                if ($item['migration'] === $name) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $result[] = ['migration' => $name, 'ran' => true, 'batch' => $batch, 'missing' => true];
            }
        }

        return $result;
    }

    /** Create the sessions table used by DatabaseSessionStore. */
    public function createSessionsTable(string $table = 'sessions'): void
    {
        $this->db->execute("CREATE TABLE IF NOT EXISTS {$table} (id VARCHAR(128) PRIMARY KEY, payload TEXT NOT NULL, last_activity INTEGER NOT NULL)");
    }

    private function ensureRepository(): void
    {
        $this->db->execute("CREATE TABLE IF NOT EXISTS {$this->table} (migration VARCHAR(255) PRIMARY KEY, batch INTEGER NOT NULL)");
    }

    private function ran(): array
    {
        return array_map(
            static fn(array $row): string => (string) $row['migration'],
            $this->db->select("SELECT migration FROM {$this->table} ORDER BY migration ASC"),
        );
    }

    private function currentBatch(): int
    {
        return (int) ($this->db->value("SELECT MAX(batch) FROM {$this->table}") ?? 0);
    }

    private function nextBatch(): int
    {
        return $this->currentBatch() + 1;
    }

    private function files(): array
    {
        $files = glob(rtrim($this->path, '/') . '/*.php') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    private function load(string $file): Migration
    {
        $db        = $this->db;
        $migration = require $file;
        if (!$migration instanceof Migration) {
            throw new \RuntimeException("Migration [{$file}] must return an instance of " . Migration::class);
        }
        return $migration;
    }

    /** @return string[] */
    private function rollbackBatch(int $batch): array
    {
        $rows       = $this->db->select(
            "SELECT migration FROM {$this->table} WHERE batch = ? ORDER BY migration DESC",
            [$batch]
        );
        $rolledBack = [];

        foreach ($rows as $row) {
            $name = (string) $row['migration'];
            $file = $this->path . '/' . $name . '.php';
            if (!is_file($file)) {
                throw new \RuntimeException("Migration file not found: {$file}");
            }
            $this->load($file)->down();
            $this->db->execute("DELETE FROM {$this->table} WHERE migration = ?", [$name]);
            $rolledBack[] = $name;
        }

        return $rolledBack;
    }
}
