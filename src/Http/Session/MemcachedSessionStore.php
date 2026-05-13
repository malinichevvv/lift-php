<?php

declare(strict_types=1);

namespace Lift\Http\Session;

/**
 * Memcached-backed session store.
 *
 * Accepts an already configured `\Memcached` instance to avoid forcing global
 * connection configuration into Lift. The class only type-checks at runtime, so
 * projects that do not install ext-memcached can still use the framework.
 */
class MemcachedSessionStore implements SessionStoreInterface
{
    /**
     * @param object $memcached An already-configured `\Memcached` instance.
     * @param string $prefix    Prefix prepended to every Memcached key.
     * @throws \InvalidArgumentException When `$memcached` is not a `\Memcached` instance.
     */
    public function __construct(
        private readonly object $memcached,
        private readonly string $prefix = 'lift:session:',
    ) {
        if (!$this->memcached instanceof \Memcached) {
            throw new \InvalidArgumentException('MemcachedSessionStore expects an instance of ext-memcached \Memcached');
        }
    }

    /**
     * Fetch the session payload from Memcached.
     *
     * A cache miss returns `false` from `get()`, which is normalised to `null`.
     */
    public function read(string $id): ?string
    {
        $payload = $this->memcached->get($this->key($id));
        return $payload === false ? null : (string) $payload;
    }

    /** Store the session payload with a Memcached-level TTL for auto-expiry. */
    public function write(string $id, string $payload, int $ttl): void
    {
        $this->memcached->set($this->key($id), $payload, $ttl);
    }

    /** Delete the session item from Memcached. */
    public function destroy(string $id): void
    {
        $this->memcached->delete($this->key($id));
    }

    /** No-op — Memcached expires items natively via TTL. */
    public function gc(int $maxLifetime): void
    {
    }

    private function key(string $id): string
    {
        return $this->prefix . $id;
    }
}
