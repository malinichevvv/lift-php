<?php

declare(strict_types=1);

namespace Lift\Support;

/**
 * Fluent, immutable-by-default array wrapper.
 *
 * Most methods return a **new** Collection instance; explicitly mutable helpers
 * (`push`, `put`, `forget`, `transform`, `each`) mutate in place and return
 * `$this` for chaining.
 *
 * ```php
 * $active = Collection::make($users)
 *     ->filter(fn($u) => $u['active'])
 *     ->sortBy('name')
 *     ->pluck('email')
 *     ->values();
 * ```
 */
final class Collection implements \Countable, \IteratorAggregate, \JsonSerializable, \ArrayAccess
{
    /** @param array<array-key, mixed> $items */
    public function __construct(private array $items = []) {}

    /** @param array<array-key, mixed> $items */
    public static function make(array $items = []): static
    {
        return new static($items);
    }

    // -----------------------------------------------------------------
    // Transformation — return new instances
    // -----------------------------------------------------------------

    public function map(callable $callback): static
    {
        return new static(array_map($callback, $this->items));
    }

    public function flatMap(callable $callback): static
    {
        $result = [];
        foreach ($this->items as $key => $value) {
            $mapped = $callback($value, $key);
            if (is_array($mapped) || $mapped instanceof self) {
                array_push($result, ...($mapped instanceof self ? $mapped->all() : $mapped));
            } else {
                $result[] = $mapped;
            }
        }
        return new static($result);
    }

    public function filter(?callable $callback = null): static
    {
        return $callback === null
            ? new static(array_values(array_filter($this->items)))
            : new static(array_values(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH)));
    }

    public function reject(callable $callback): static
    {
        return $this->filter(fn($v, $k) => !$callback($v, $k));
    }

    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    // -----------------------------------------------------------------
    // Extraction
    // -----------------------------------------------------------------

    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return $this->items === [] ? $default : reset($this->items);
        }
        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }
        return $default;
    }

    public function last(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return $this->items === [] ? $default : end($this->items);
        }
        $result = $default;
        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                $result = $value;
            }
        }
        return $result;
    }

    public function take(int $limit): static
    {
        return new static($limit < 0
            ? array_slice($this->items, $limit)
            : array_slice($this->items, 0, $limit));
    }

    public function skip(int $count): static
    {
        return new static(array_slice($this->items, $count));
    }

    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length, true));
    }

    public function chunk(int $size): static
    {
        if ($size <= 0) {
            return new static();
        }
        return new static(array_map(
            static fn($chunk) => new static($chunk),
            array_chunk($this->items, $size, true),
        ));
    }

    // -----------------------------------------------------------------
    // Grouping / keying / plucking
    // -----------------------------------------------------------------

    public function pluck(string $key, ?string $indexBy = null): static
    {
        $result = [];
        foreach ($this->items as $item) {
            $val  = is_object($item) ? ($item->$key ?? null)      : ($item[$key]      ?? null);
            $idx  = $indexBy ? (is_object($item) ? ($item->$indexBy ?? null) : ($item[$indexBy] ?? null)) : null;
            if ($idx !== null) {
                $result[$idx] = $val;
            } else {
                $result[] = $val;
            }
        }
        return new static($result);
    }

    public function groupBy(string|callable $key): static
    {
        $result = [];
        foreach ($this->items as $item) {
            $gk = is_callable($key)
                ? $key($item)
                : (is_object($item) ? ($item->$key ?? '') : ($item[$key] ?? ''));
            $result[$gk][] = $item;
        }
        return new static(array_map(static fn($g) => new static($g), $result));
    }

    public function keyBy(string|callable $key): static
    {
        $result = [];
        foreach ($this->items as $item) {
            $k = is_callable($key)
                ? $key($item)
                : (is_object($item) ? ($item->$key ?? '') : ($item[$key] ?? ''));
            $result[$k] = $item;
        }
        return new static($result);
    }

    // -----------------------------------------------------------------
    // Sorting
    // -----------------------------------------------------------------

    public function sortBy(string|callable $key, bool $descending = false): static
    {
        $items = $this->items;
        usort($items, static function ($a, $b) use ($key, $descending): int {
            $av = is_callable($key) ? $key($a) : (is_object($a) ? ($a->$key ?? null) : ($a[$key] ?? null));
            $bv = is_callable($key) ? $key($b) : (is_object($b) ? ($b->$key ?? null) : ($b[$key] ?? null));
            return $descending ? $bv <=> $av : $av <=> $bv;
        });
        return new static($items);
    }

    public function sortByDesc(string|callable $key): static
    {
        return $this->sortBy($key, true);
    }

    public function sort(?callable $callback = null): static
    {
        $items = $this->items;
        $callback ? usort($items, $callback) : sort($items);
        return new static($items);
    }

    public function sortKeys(bool $descending = false): static
    {
        $items = $this->items;
        $descending ? krsort($items) : ksort($items);
        return new static($items);
    }

    public function reverse(): static
    {
        return new static(array_reverse($this->items, true));
    }

    // -----------------------------------------------------------------
    // Set operations
    // -----------------------------------------------------------------

    public function unique(?string $key = null): static
    {
        if ($key === null) {
            return new static(array_values(array_unique($this->items)));
        }
        $seen   = [];
        $result = [];
        foreach ($this->items as $item) {
            $val  = is_object($item) ? ($item->$key ?? null) : ($item[$key] ?? null);
            $hash = serialize($val);
            if (!isset($seen[$hash])) {
                $seen[$hash] = true;
                $result[]    = $item;
            }
        }
        return new static($result);
    }

    public function flatten(int $depth = PHP_INT_MAX): static
    {
        return new static($this->flattenArray($this->items, $depth));
    }

    /** @param array<array-key, mixed>|self $items */
    public function merge(array|self $items): static
    {
        return new static(array_merge($this->items, $items instanceof self ? $items->all() : $items));
    }

    public function diff(array $items): static
    {
        return new static(array_values(array_diff($this->items, $items)));
    }

    public function intersect(array $items): static
    {
        return new static(array_values(array_intersect($this->items, $items)));
    }

    // -----------------------------------------------------------------
    // Keys / values
    // -----------------------------------------------------------------

    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    public function values(): static
    {
        return new static(array_values($this->items));
    }

    public function flip(): static
    {
        return new static(array_flip($this->items));
    }

    // -----------------------------------------------------------------
    // Search / check
    // -----------------------------------------------------------------

    public function contains(mixed $valueOrCallback): bool
    {
        if (is_callable($valueOrCallback)) {
            foreach ($this->items as $key => $value) {
                if ($valueOrCallback($value, $key)) {
                    return true;
                }
            }
            return false;
        }
        return in_array($valueOrCallback, $this->items, true);
    }

    public function has(mixed $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    public function where(string $key, mixed $value): static
    {
        return $this->filter(function ($item) use ($key, $value): bool {
            $v = is_object($item) ? ($item->$key ?? null) : ($item[$key] ?? null);
            return $v === $value;
        });
    }

    // -----------------------------------------------------------------
    // Aggregates
    // -----------------------------------------------------------------

    public function count(): int
    {
        return count($this->items);
    }

    public function sum(string|callable|null $key = null): int|float
    {
        if ($key === null) {
            return array_sum($this->items);
        }
        $total = 0;
        foreach ($this->items as $item) {
            $total += (float) (is_callable($key) ? $key($item) : (is_object($item) ? ($item->$key ?? 0) : ($item[$key] ?? 0)));
        }
        return $total;
    }

    public function avg(string|callable|null $key = null): int|float
    {
        $count = $this->count();
        return $count > 0 ? $this->sum($key) / $count : 0;
    }

    public function min(string|callable|null $key = null): mixed
    {
        if ($key === null) {
            return min($this->items);
        }
        return $this->map(fn($item) => is_callable($key) ? $key($item) : (is_object($item) ? ($item->$key ?? null) : ($item[$key] ?? null)))->min();
    }

    public function max(string|callable|null $key = null): mixed
    {
        if ($key === null) {
            return max($this->items);
        }
        return $this->map(fn($item) => is_callable($key) ? $key($item) : (is_object($item) ? ($item->$key ?? null) : ($item[$key] ?? null)))->max();
    }

    // -----------------------------------------------------------------
    // Info
    // -----------------------------------------------------------------

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    // -----------------------------------------------------------------
    // Access / export
    // -----------------------------------------------------------------

    public function get(mixed $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->items;
    }

    public function toArray(): array
    {
        return array_map(static fn($v) => $v instanceof \JsonSerializable ? $v->jsonSerialize() : $v, $this->items);
    }

    public function toJson(int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): string
    {
        return (string) json_encode($this->jsonSerialize(), $flags);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // -----------------------------------------------------------------
    // Mutable helpers — return $this
    // -----------------------------------------------------------------

    public function push(mixed $value): static
    {
        $this->items[] = $value;
        return $this;
    }

    public function put(mixed $key, mixed $value): static
    {
        $this->items[$key] = $value;
        return $this;
    }

    public function forget(mixed $key): static
    {
        unset($this->items[$key]);
        return $this;
    }

    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $value) {
            if ($callback($value, $key) === false) {
                break;
            }
        }
        return $this;
    }

    public function transform(callable $callback): static
    {
        foreach ($this->items as $key => $value) {
            $this->items[$key] = $callback($value, $key);
        }
        return $this;
    }

    // -----------------------------------------------------------------
    // ArrayAccess
    // -----------------------------------------------------------------

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->items);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    // -----------------------------------------------------------------
    // IteratorAggregate
    // -----------------------------------------------------------------

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    // -----------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------

    private function flattenArray(array $items, int $depth): array
    {
        $result = [];
        foreach ($items as $item) {
            if ($depth > 0 && (is_array($item) || $item instanceof self)) {
                $sub = $item instanceof self ? $item->all() : $item;
                array_push($result, ...$this->flattenArray($sub, $depth - 1));
            } else {
                $result[] = $item;
            }
        }
        return $result;
    }
}
