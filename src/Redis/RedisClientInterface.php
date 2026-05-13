<?php

declare(strict_types=1);

namespace Lift\Redis;

/**
 * Minimal Redis client contract covering operations needed by Lift internals
 * (cache, rate limiting, queues).
 *
 * A concrete implementation wrapping the PHP {@see \Redis} extension ships as
 * {@see RedisClient}. Any PSR-compatible adapter (Predis, phpredis-mock, …)
 * may also be used by implementing this interface.
 */
interface RedisClientInterface
{
    // -----------------------------------------------------------------
    // String / generic
    // -----------------------------------------------------------------

    /**
     * Retrieve the value stored at a key.
     *
     * @return string|false The stored string, or false if the key does not exist.
     */
    public function get(string $key): string|false;

    /**
     * Store a string value with optional TTL.
     *
     * @param int $ttl Seconds until expiry. 0 = no expiry.
     */
    public function set(string $key, string $value, int $ttl = 0): bool;

    /**
     * Delete one or more keys.
     *
     * @return int Number of keys actually deleted.
     */
    public function del(string ...$keys): int;

    /**
     * @return int 1 if the key exists, 0 otherwise.
     */
    public function exists(string $key): int;

    /**
     * Set or update the TTL on an existing key.
     */
    public function expire(string $key, int $ttl): bool;

    /**
     * @return int Remaining TTL in seconds; -1 = no expiry; -2 = key missing.
     */
    public function ttl(string $key): int;

    // -----------------------------------------------------------------
    // Counters
    // -----------------------------------------------------------------

    /**
     * Atomically increment a counter key by 1 and return the new value.
     *
     * Creates the key with value 1 if absent.
     */
    public function incr(string $key): int;

    /**
     * Atomically increment a counter key by {@see $by} and return the new value.
     */
    public function incrBy(string $key, int $by): int;

    // -----------------------------------------------------------------
    // Lists (used by queue drivers)
    // -----------------------------------------------------------------

    /**
     * Prepend one or more values to a list (LPUSH).
     *
     * @return int New length of the list.
     */
    public function lPush(string $key, string ...$values): int;

    /**
     * Remove and return the last element of a list (RPOP).
     *
     * @return string|false The element, or false if the list is empty / missing.
     */
    public function rPop(string $key): string|false;

    /**
     * @return int Current length of the list.
     */
    public function lLen(string $key): int;

    // -----------------------------------------------------------------
    // Sorted sets (used by delayed queues)
    // -----------------------------------------------------------------

    /**
     * Add a member with a score to a sorted set.
     *
     * @return int Number of elements added (0 if already existed and was updated).
     */
    public function zAdd(string $key, float $score, string $member): int;

    /**
     * Return members with scores between {@see $min} and {@see $max} (inclusive).
     *
     * @return list<string>
     */
    public function zRangeByScore(string $key, string $min, string $max): array;

    /**
     * Remove one or more members from a sorted set.
     *
     * @return int Number of members removed.
     */
    public function zRem(string $key, string ...$members): int;

    // -----------------------------------------------------------------
    // Connection
    // -----------------------------------------------------------------

    /** @throws \RuntimeException On connection failure. */
    public function ping(): bool;

    /** Switch to a different logical database (0-15). */
    public function select(int $db): bool;
}
