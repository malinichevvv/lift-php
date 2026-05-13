<?php

declare(strict_types=1);

namespace Lift\Http\Session;

/**
 * In-memory session store for tests and short-lived CLI/dev processes.
 *
 * Data is stored in a plain PHP array and is not persisted across requests.
 * This store is ideal for unit tests where real persistence is undesirable.
 */
class ArraySessionStore implements SessionStoreInterface
{
    /** @var array<string, array{payload: string, expires: int}> */
    private array $items = [];

    /**
     * Return the payload for the given ID, or `null` if absent/expired.
     *
     * Expired entries are pruned on read.
     */
    public function read(string $id): ?string
    {
        $item = $this->items[$id] ?? null;
        if ($item === null) {
            return null;
        }
        if ($item['expires'] < time()) {
            unset($this->items[$id]);
            return null;
        }
        return $item['payload'];
    }

    /** Store the payload in the in-memory map with an expiry timestamp. */
    public function write(string $id, string $payload, int $ttl): void
    {
        $this->items[$id] = ['payload' => $payload, 'expires' => time() + $ttl];
    }

    /** Remove the session entry from the in-memory map. */
    public function destroy(string $id): void
    {
        unset($this->items[$id]);
    }

    /** Remove all expired entries from the in-memory map. */
    public function gc(int $maxLifetime): void
    {
        $now = time();
        foreach ($this->items as $id => $item) {
            if ($item['expires'] < $now) {
                unset($this->items[$id]);
            }
        }
    }
}
