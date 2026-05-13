<?php

declare(strict_types=1);

namespace Lift\Http\Session;

use Lift\Database\Connection;

/** Database-backed session store using a simple `id/payload/last_activity` table. */
class DatabaseSessionStore implements SessionStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table = 'sessions',
    ) {}

    public function read(string $id): ?string
    {
        $row = $this->connection->selectOne("SELECT payload FROM {$this->table} WHERE id = ?", [$id]);
        return $row === null ? null : (string) $row['payload'];
    }

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

    public function destroy(string $id): void
    {
        $this->connection->execute("DELETE FROM {$this->table} WHERE id = ?", [$id]);
    }

    public function gc(int $maxLifetime): void
    {
        $this->connection->execute("DELETE FROM {$this->table} WHERE last_activity < ?", [time() - $maxLifetime]);
    }
}
