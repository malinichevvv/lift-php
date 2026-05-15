<?php

declare(strict_types=1);

namespace Lift\Cache;

/**
 * In-memory cache backed by a plain PHP array.
 *
 * Suitable for testing, single-request scopes, or development environments.
 * Data is not shared between processes and does not survive request boundaries.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expires: int}> */
    private array $store = [];

    /** {@inheritdoc} */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }
        return $this->store[$key]['value'];
    }

    /** {@inheritdoc} */
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        if ($ttl < 0) {
            $this->delete($key);
            return true;
        }
        $this->store[$key] = [
            'value'   => $value,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
        ];
        return true;
    }

    /** {@inheritdoc} */
    public function delete(string ...$keys): bool
    {
        foreach ($keys as $key) {
            unset($this->store[$key]);
        }
        return true;
    }

    /** {@inheritdoc} */
    public function has(string $key): bool
    {
        if (!isset($this->store[$key])) {
            return false;
        }
        $expires = $this->store[$key]['expires'];
        if ($expires > 0 && $expires < time()) {
            unset($this->store[$key]);
            return false;
        }
        return true;
    }

    /** {@inheritdoc} */
    public function increment(string $key, int $by = 1): int
    {
        $current = (int) ($this->has($key) ? $this->store[$key]['value'] : 0);
        $new     = $current + $by;
        $expires = $this->store[$key]['expires'] ?? 0;
        $this->store[$key] = ['value' => $new, 'expires' => $expires];
        return $new;
    }

    /** {@inheritdoc} */
    public function remember(string $key, int $ttl, callable $factory): mixed
    {
        if ($this->has($key)) {
            return $this->store[$key]['value'];
        }
        $value = $factory();
        $this->set($key, $value, $ttl);
        return $value;
    }

    /** {@inheritdoc} */
    public function flush(): bool
    {
        $this->store = [];
        return true;
    }
}
