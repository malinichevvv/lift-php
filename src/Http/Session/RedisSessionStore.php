<?php

declare(strict_types=1);

namespace Lift\Http\Session;

use Lift\Redis\RedisClientInterface;

/** Redis-backed session store with native TTL support. */
class RedisSessionStore implements SessionStoreInterface
{
    public function __construct(
        private readonly RedisClientInterface $redis,
        private readonly string $prefix = 'lift:session:',
    ) {}

    public function read(string $id): ?string
    {
        $payload = $this->redis->get($this->key($id));
        return $payload === false ? null : $payload;
    }

    public function write(string $id, string $payload, int $ttl): void
    {
        $this->redis->set($this->key($id), $payload, $ttl);
    }

    public function destroy(string $id): void
    {
        $this->redis->del($this->key($id));
    }

    public function gc(int $maxLifetime): void
    {
    }

    private function key(string $id): string
    {
        return $this->prefix . $id;
    }
}
