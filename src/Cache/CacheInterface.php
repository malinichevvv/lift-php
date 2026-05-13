<?php

declare(strict_types=1);

namespace Lift\Cache;

/**
 * Simple key-value cache contract.
 *
 * All implementations must guarantee that values survive serialisation and
 * deserialisation (e.g. objects stored via {@see set()} must be returned as
 * equal objects by {@see get()}).
 */
interface CacheInterface
{
    /**
     * Retrieve an item from the cache.
     *
     * @template T
     * @param  string  $key
     * @param  T       $default Returned when the key is missing or expired.
     * @return T|mixed
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Store an item in the cache.
     *
     * @param  string $key
     * @param  mixed  $value   Any serialisable value.
     * @param  int    $ttl     Time-to-live in seconds. 0 = no expiry.
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool;

    /**
     * Remove one or more items from the cache.
     *
     * @param string ...$keys
     */
    public function delete(string ...$keys): bool;

    /**
     * Check whether a key exists and has not expired.
     */
    public function has(string $key): bool;

    /**
     * Increment a numeric counter stored at {@see $key}.
     *
     * Creates the key with value 1 if it does not exist.
     *
     * @param  int $by Amount to increment by (must be > 0).
     * @return int New value after increment.
     */
    public function increment(string $key, int $by = 1): int;

    /**
     * Retrieve an item or compute and store it if absent.
     *
     * @template T
     * @param  string   $key
     * @param  int      $ttl     Seconds until expiry. 0 = no expiry.
     * @param  callable $factory Called only when the key is missing; must return the value.
     * @return T|mixed
     */
    public function remember(string $key, int $ttl, callable $factory): mixed;

    /**
     * Remove all items from the cache store.
     */
    public function flush(): bool;
}
