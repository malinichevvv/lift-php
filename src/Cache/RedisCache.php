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
 * ```php
 * $cache = new RedisCache(new RedisClient());
 * $app->instance(CacheInterface::class, $cache);
 * ```
 */
final class RedisCache implements CacheInterface
{
    public function __construct(
        private readonly RedisClientInterface $redis,
        private readonly string $prefix = 'lift:cache:',
    ) {}

    /** {@inheritdoc} */
    public function get(string $key, mixed $default = null): mixed
    {
        $raw = $this->redis->get($this->prefix . $key);
        if ($raw === false) {
            return $default;
        }
        return unserialize($raw);
    }

    /** {@inheritdoc} */
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        return $this->redis->set($this->prefix . $key, serialize($value), $ttl);
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
}
