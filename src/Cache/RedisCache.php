<?php

declare(strict_types=1);

namespace Lift\Cache;

use Lift\Redis\RedisClientInterface;

/**
 * Cache implementation backed by Redis via {@see RedisClientInterface}.
 *
 * Values are serialised with {@see serialize()} so any PHP value (objects,
 * arrays, booleans) can be stored safely.
 *
 * When a non-empty `$secret` is provided, every stored value is wrapped in an
 * HMAC-signed envelope. This prevents an attacker who can write to Redis from
 * injecting a crafted PHP serialized payload and triggering object injection /
 * RCE on the next `get()`.
 *
 * ```php
 * $cache = new RedisCache(new RedisClient(), secret: $_ENV['CACHE_SECRET']);
 * $app->instance(CacheInterface::class, $cache);
 * ```
 */
final class RedisCache implements CacheInterface
{
    public function __construct(
        private readonly RedisClientInterface $redis,
        private readonly string $prefix = 'lift:cache:',
        private readonly string $secret = '',
    ) {}

    /** {@inheritdoc} */
    public function get(string $key, mixed $default = null): mixed
    {
        $raw = $this->redis->get($this->prefix . $key);
        if ($raw === false) {
            return $default;
        }
        $payload = $this->unwrap((string) $raw);
        if ($payload === null) {
            return $default;
        }
        return unserialize($payload);
    }

    /** {@inheritdoc} */
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        return $this->redis->set($this->prefix . $key, $this->wrap(serialize($value)), $ttl);
    }

    /** {@inheritdoc} */
    public function delete(string ...$keys): bool
    {
        $prefixed = array_map(fn(string $k) => $this->prefix . $k, $keys);
        return $this->redis->del(...$prefixed) >= 0;
    }

    /** {@inheritdoc} */
    public function has(string $key): bool
    {
        return $this->redis->exists($this->prefix . $key) > 0;
    }

    /**
     * {@inheritdoc}
     *
     * Uses an atomic Redis INCR so it is safe for concurrent access.
     * Note: the counter is stored as a plain integer, not serialised.
     */
    public function increment(string $key, int $by = 1): int
    {
        return $this->redis->incrBy($this->prefix . $key, $by);
    }

    /** {@inheritdoc} */
    public function remember(string $key, int $ttl, callable $factory): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }
        $value = $factory();
        $this->set($key, $value, $ttl);
        return $value;
    }

    /** {@inheritdoc} */
    public function flush(): bool
    {
        // Cannot safely flush only prefixed keys via the interface.
        // Caller should use the raw Redis client for targeted flushes.
        return true;
    }

    // -----------------------------------------------------------------
    // HMAC envelope helpers
    // -----------------------------------------------------------------

    private function wrap(string $data): string
    {
        if ($this->secret === '') {
            return $data;
        }
        $mac = hash_hmac('sha256', $data, $this->secret);
        return json_encode(['v' => 1, 'mac' => $mac, 'data' => $data], JSON_THROW_ON_ERROR);
    }

    /** Returns null when signature verification fails (tampered payload). */
    private function unwrap(string $raw): ?string
    {
        if ($this->secret === '') {
            return $raw;
        }

        $envelope = json_decode($raw, true);
        if (!is_array($envelope) || !isset($envelope['v'], $envelope['mac'], $envelope['data'])) {
            return null;
        }

        $expected = hash_hmac('sha256', (string) $envelope['data'], $this->secret);
        if (!hash_equals($expected, (string) $envelope['mac'])) {
            return null;
        }

        return (string) $envelope['data'];
    }
}
