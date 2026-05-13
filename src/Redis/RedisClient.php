<?php

declare(strict_types=1);

namespace Lift\Redis;

use RuntimeException;

/**
 * Thin wrapper around the PHP {@see \Redis} extension.
 *
 * Requires {@see ext-redis} (phpredis). For a pure-PHP alternative, implement
 * {@see RedisClientInterface} with Predis or any other client.
 *
 * ```php
 * $redis = new RedisClient('127.0.0.1', 6379);
 * $app->instance(RedisClientInterface::class, $redis);
 * ```
 */
final class RedisClient implements RedisClientInterface
{
    private \Redis $redis;

    /**
     * @param string   $host    Redis host (default: 127.0.0.1)
     * @param int      $port    Redis port (default: 6379)
     * @param float    $timeout Connection timeout in seconds (default: 0.0 = no timeout)
     * @param string   $prefix  Optional key prefix applied to every key.
     * @param int      $db      Logical database index (0-15).
     * @param string   $auth    Password for AUTH command (empty = no auth).
     *
     * @throws RuntimeException If ext-redis is not loaded or connection fails.
     */
    public function __construct(
        private readonly string $host    = '127.0.0.1',
        private readonly int    $port    = 6379,
        private readonly float  $timeout = 0.0,
        private readonly string $prefix  = '',
        private readonly int    $db      = 0,
        private readonly string $auth    = '',
    ) {
        if (!extension_loaded('redis')) {
            throw new RuntimeException('The "redis" PHP extension is required to use RedisClient. Install phpredis or use RedisClientInterface with another client.');
        }

        $this->redis = new \Redis();
        $this->connect();
    }

    private function connect(): void
    {
        $connected = $this->timeout > 0.0
            ? $this->redis->connect($this->host, $this->port, $this->timeout)
            : $this->redis->connect($this->host, $this->port);

        if (!$connected) {
            throw new RuntimeException("Failed to connect to Redis at {$this->host}:{$this->port}");
        }

        if ($this->auth !== '') {
            $this->redis->auth($this->auth);
        }

        if ($this->db !== 0) {
            $this->redis->select($this->db);
        }

        if ($this->prefix !== '') {
            $this->redis->setOption(\Redis::OPT_PREFIX, $this->prefix);
        }
    }

    public function get(string $key): string|false
    {
        $result = $this->redis->get($key);
        return $result === false ? false : (string) $result;
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        return $ttl > 0
            ? (bool) $this->redis->setEx($key, $ttl, $value)
            : (bool) $this->redis->set($key, $value);
    }

    public function del(string ...$keys): int
    {
        return (int) $this->redis->del(...$keys);
    }

    public function exists(string $key): int
    {
        return (int) $this->redis->exists($key);
    }

    public function expire(string $key, int $ttl): bool
    {
        return (bool) $this->redis->expire($key, $ttl);
    }

    public function ttl(string $key): int
    {
        return (int) $this->redis->ttl($key);
    }

    public function incr(string $key): int
    {
        return (int) $this->redis->incr($key);
    }

    public function incrBy(string $key, int $by): int
    {
        return (int) $this->redis->incrBy($key, $by);
    }

    public function lPush(string $key, string ...$values): int
    {
        return (int) $this->redis->lPush($key, ...$values);
    }

    public function rPop(string $key): string|false
    {
        $result = $this->redis->rPop($key);
        return $result === false ? false : (string) $result;
    }

    public function lLen(string $key): int
    {
        return (int) $this->redis->lLen($key);
    }

    public function zAdd(string $key, float $score, string $member): int
    {
        return (int) $this->redis->zAdd($key, $score, $member);
    }

    public function zRangeByScore(string $key, string $min, string $max): array
    {
        return $this->redis->zRangeByScore($key, $min, $max) ?: [];
    }

    public function zRem(string $key, string ...$members): int
    {
        return (int) $this->redis->zRem($key, ...$members);
    }

    public function ping(): bool
    {
        return $this->redis->ping() === '+PONG' || $this->redis->ping() === true;
    }

    public function select(int $db): bool
    {
        return (bool) $this->redis->select($db);
    }

    /**
     * Expose the raw {@see \Redis} instance for advanced operations not covered
     * by the interface.
     */
    public function raw(): \Redis
    {
        return $this->redis;
    }
}
