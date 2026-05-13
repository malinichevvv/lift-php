<?php

declare(strict_types=1);

namespace Lift\Http\Session;

/** In-memory session store for tests and short-lived CLI/dev processes. */
class ArraySessionStore implements SessionStoreInterface
{
    /** @var array<string, array{payload: string, expires: int}> */
    private array $items = [];

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

    public function write(string $id, string $payload, int $ttl): void
    {
        $this->items[$id] = ['payload' => $payload, 'expires' => time() + $ttl];
    }

    public function destroy(string $id): void
    {
        unset($this->items[$id]);
    }

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
