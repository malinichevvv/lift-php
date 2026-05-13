<?php

declare(strict_types=1);

namespace Lift\Database;

/** Lightweight migration runner with batch rollback support. */
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
        $ran = array_flip($this->ran());
        $batch = $this->nextBatch();
        $migrated = [];

        foreach ($this->files() as $file) {
            $name = basename($file, '.php');
            if (isset($ran[$name])) {
                continue;
            }

            $migration = $this->load($file);
            $migration->up();
            $this->db->execute("INSERT INTO {$this->table} (migration, batch) VALUES (?, ?)", [$name, $batch]);
            $migrated[] = $name;
        }

        return $migrated;
    }

    /** Roll back the latest migration batch and return rolled back names. */
    public function rollback(): array
    {
        $this->ensureRepository();
        $batch = $this->currentBatch();
        if ($batch === 0) {
            return [];
        }

        $rows = $this->db->select("SELECT migration FROM {$this->table} WHERE batch = ? ORDER BY migration DESC", [$batch]);
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
        return array_map(static fn(array $row): string => (string) $row['migration'], $this->db->select("SELECT migration FROM {$this->table} ORDER BY migration ASC"));
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
        $db = $this->db;
        $migration = require $file;
        if (!$migration instanceof Migration) {
            throw new \RuntimeException("Migration [{$file}] must return an instance of " . Migration::class);
        }
        return $migration;
    }
}
