<?php

declare(strict_types=1);

namespace Lift\Http\Session;

/**
 * Storage contract for server-side sessions.
 *
 * Implementations must be able to persist an opaque serialised string keyed by
 * a session ID, honour a TTL, and support eager deletion and garbage collection.
 * The session ID is treated as an opaque string — stores must not interpret it.
 */
interface SessionStoreInterface
{
    /**
     * Read and return the raw serialised session payload for the given ID.
     *
     * Returns `null` when the session does not exist or has expired.
     */
    public function read(string $id): ?string;

    /**
     * Persist the serialised session payload with a time-to-live in seconds.
     *
     * If a session with this ID already exists it must be overwritten.
     */
    public function write(string $id, string $payload, int $ttl): void;

    /**
     * Immediately delete the session identified by `$id`.
     *
     * Must not throw when the ID does not exist.
     */
    public function destroy(string $id): void;

    /**
     * Remove all sessions that were last active more than `$maxLifetime` seconds ago.
     *
     * Called periodically for stores that do not handle expiry natively (e.g. files, DB).
     * Stores with native TTL support (Redis, Memcached) may leave this as a no-op.
     */
    public function gc(int $maxLifetime): void;
}
