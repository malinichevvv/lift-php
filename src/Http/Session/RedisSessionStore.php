<?php

declare(strict_types=1);

namespace Lift\Http\Session;

use Lift\Redis\RedisClientInterface;

/**
 * Redis-backed session store with native TTL support.
 *
 * Each session is stored as a plain string value at key `{prefix}{id}`.
 * Redis handles expiry natively via the key TTL, so {@see gc()} is a no-op.
 *
 * Requires a {@see RedisClientInterface} implementation — wire up a
 * `\Redis` or `\Predis\Client` adapter in your application bootstrap.
 */
class RedisSessionStore implements SessionStoreInterface
{
    /**
     * @param RedisClientInterface $redis  Connected Redis client.
     * @param string               $prefix Prefix prepended to every session key.
     */
    public function __construct(
        private readonly RedisClientInterface $redis,
        private readonly string $prefix = 'lift:session:',
    ) {}

    /**
     * Fetch the session payload from Redis, returning `null` on a miss.
     *
     * A missing or expired key returns `false` from the client, which this
     * method normalises to `null`.
     */
    public function read(string $id): ?string
    {
        $payload = $this->redis->get($this->key($id));
        return $payload === false ? null : $payload;
    }

    /** Store the session payload with a key-level TTL so Redis auto-expires it. */
    public function write(string $id, string $payload, int $ttl): void
    {
        $this->redis->set($this->key($id), $payload, $ttl);
    }

    /** Delete the session key from Redis. */
    public function destroy(string $id): void
    {
        $this->redis->del($this->key($id));
    }

    /** No-op — Redis expires keys natively via TTL. */
    public function gc(int $maxLifetime): void
    {
    }

    private function key(string $id): string
    {
        return $this->prefix . $id;
    }
}
