<?php

declare(strict_types=1);

namespace Lift\Http\Session;

use Lift\Database\Connection;

/**
 * Database-backed session store using a simple `id / payload / last_activity` table.
 *
 * Compatible with MySQL, PostgreSQL, and SQLite — SQLite uses an upsert
 * (`INSERT … ON CONFLICT … DO UPDATE`) while other drivers fall back to a
 * delete-then-insert strategy.
 *
 * The expected table schema (created by `Migrator::createSessionsTable()`):
 * ```sql
 * CREATE TABLE sessions (
 *     id VARCHAR(40) PRIMARY KEY,
 *     payload TEXT NOT NULL,
 *     last_activity INTEGER NOT NULL
 * );
 * ```
 */
class DatabaseSessionStore implements SessionStoreInterface
{
    /**
     * @param Connection $connection Active database connection.
     * @param string     $table      Table name that holds session rows.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table = 'sessions',
    ) {
        // The table name is interpolated into SQL (PDO cannot bind identifiers),
        // so reject anything that is not a plain identifier — defence in depth
        // for setups that derive the name from dynamic configuration.
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new \InvalidArgumentException("Invalid session table name: {$table}");
        }
    }

    /**
     * Select the payload column for the given session ID.
     *
     * Returns `null` when the row does not exist (expiry is not checked here —
     * the {@see gc()} method removes stale rows).
     */
    public function read(string $id): ?string
    {
        $row = $this->connection->selectOne("SELECT payload FROM {$this->table} WHERE id = ?", [$id]);
        return $row === null ? null : (string) $row['payload'];
    }

    /**
     * Upsert the session row.
     *
     * SQLite uses a native `INSERT … ON CONFLICT … DO UPDATE` statement.
     * Other drivers delete the existing row first and then insert a new one.
     */
    public function write(string $id, string $payload, int $ttl): void
    {
        $now = time();
        if ($this->connection->getDriverName() === 'sqlite') {
            $this->connection->execute(
                "INSERT INTO {$this->table} (id, payload, last_activity) VALUES (?, ?, ?) ON CONFLICT(id) DO UPDATE SET payload = excluded.payload, last_activity = excluded.last_activity",
                [$id, $payload, $now],
            );
            return;
        }

        $this->connection->execute("DELETE FROM {$this->table} WHERE id = ?", [$id]);
        $this->connection->execute("INSERT INTO {$this->table} (id, payload, last_activity) VALUES (?, ?, ?)", [$id, $payload, $now]);
    }

    /** Delete the session row identified by `$id`. */
    public function destroy(string $id): void
    {
        $this->connection->execute("DELETE FROM {$this->table} WHERE id = ?", [$id]);
    }

    /** Remove all rows whose `last_activity` timestamp is older than `$maxLifetime` seconds. */
    public function gc(int $maxLifetime): void
    {
        $this->connection->execute("DELETE FROM {$this->table} WHERE last_activity < ?", [time() - $maxLifetime]);
    }
}
