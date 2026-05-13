<?php

declare(strict_types=1);

namespace Lift\Cache;

use DateInterval;
use Psr\SimpleCache\CacheInterface as Psr16CacheInterface;

/**
 * Adapts a Lift {@see CacheInterface} to PSR-16 (`Psr\SimpleCache\CacheInterface`).
 *
 * Use this when a third-party library requires a PSR-16 cache:
 *
 * ```php
 * $cache    = new ArrayCache();
 * $psr16    = new Psr16Adapter($cache);
 *
 * $library->setCache($psr16); // accepts PSR-16
 * ```
 */
final class Psr16Adapter implements Psr16CacheInterface
{
    public function __construct(private readonly CacheInterface $inner) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->inner->get($key, $default);
    }

    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool
    {
        return $this->inner->set($key, $value, $this->normalizeTtl($ttl));
    }

    public function delete(string $key): bool
    {
        return $this->inner->delete($key);
    }

    public function clear(): bool
    {
        return $this->inner->flush();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->inner->get($key, $default);
        }
        return $result;
    }

    public function setMultiple(iterable $values, int|DateInterval|null $ttl = null): bool
    {
        $seconds = $this->normalizeTtl($ttl);
        $ok      = true;
        foreach ($values as $key => $value) {
            if (!$this->inner->set($key, $value, $seconds)) {
                $ok = false;
            }
        }
        return $ok;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            if (!$this->inner->delete($key)) {
                $ok = false;
            }
        }
        return $ok;
    }

    public function has(string $key): bool
    {
        return $this->inner->has($key);
    }

    private function normalizeTtl(int|DateInterval|null $ttl): int
    {
        if ($ttl === null) {
            return 0;
        }
        if ($ttl instanceof DateInterval) {
            return max(0, (int) (new \DateTime())->add($ttl)->getTimestamp() - time());
        }
        return max(0, $ttl);
    }
}
