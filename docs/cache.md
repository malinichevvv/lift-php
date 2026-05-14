---
layout: page
title: Cache
nav_order: 20
---

# Cache

`Lift\Cache\CacheInterface` is a tiny key/value store contract with two production drivers (`ArrayCache`, `RedisCache`) and a PSR-16 adapter for third-party libraries.

> Mental model: "remember this value for N seconds, and give it back to me when I ask". Anything you can `serialize()` can be cached. Nothing more, nothing less.

## When to cache

- Expensive computations whose result rarely changes.
- Database query results that don't need to be fresh on every request.
- Rate-limit counters (atomic increment via `increment()`).
- Aggregated metrics ("count of active users", refreshed every minute).
- Rendered HTML fragments (see [Views § renderCached](views#cached-rendering)).

**Don't** cache: per-request data (use [Request attributes](request#middleware-attributes)), per-user state that has consistency requirements, anything you can't afford to lose during a Redis restart.

## The interface

Every driver implements:

```php
interface CacheInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value, int $ttl = 0): bool;   // 0 = no expiry
    public function delete(string ...$keys): bool;
    public function has(string $key): bool;
    public function increment(string $key, int $by = 1): int;
    public function remember(string $key, int $ttl, callable $factory): mixed;
    public function flush(): bool;
}
```

## Setup

### `ArrayCache` — for tests & single-request scopes

```php
use Lift\Cache\ArrayCache;
use Lift\Cache\CacheInterface;

$app->singleton(CacheInterface::class, fn() => new ArrayCache());
```

Lives in PHP memory only. Lost when the request ends (under PHP-FPM). Useful when:

- You're writing tests and don't want a real Redis.
- The cache only needs to exist for one request (de-dup repeated lookups in a single handler).

### `RedisCache` — for production

```php
use Lift\Cache\CacheInterface;
use Lift\Cache\RedisCache;
use Lift\Redis\RedisClient;

$app->singleton(CacheInterface::class, function () {
    $redis = new RedisClient(
        host: $_ENV['REDIS_HOST']   ?? '127.0.0.1',
        port: (int) ($_ENV['REDIS_PORT'] ?? 6379),
        auth: $_ENV['REDIS_PASSWORD'] ?? '',
    );
    return new RedisCache(
        $redis,
        prefix: 'myapp:cache:',
        secret: $_ENV['CACHE_HMAC_SECRET'] ?? '',   // recommended — see § Security
    );
});
```

Now anything in your code can `make(CacheInterface::class)` (or constructor-inject) and use the cache.

## Reading and writing

```php
$cache = $app->make(CacheInterface::class);

// Store a value for 5 minutes
$cache->set('user:42', $user, 300);

// Read it back
$user = $cache->get('user:42');                   // null when missing
$user = $cache->get('user:42', $defaultValue);    // explicit default

// Existence check (doesn't return the value)
if ($cache->has('user:42')) { ... }

// Delete
$cache->delete('user:42');
$cache->delete('user:42', 'user:43', 'user:44');  // batch

// Wipe everything
$cache->flush();
```

TTL semantics:

| `$ttl` | What it means                |
|--------|------------------------------|
| `0` (default) | No expiry — lives until explicitly deleted or evicted. |
| `> 0`         | Live for this many seconds.   |

## `remember()` — the most useful method

The "compute or fetch" pattern in one call:

```php
$users = $cache->remember('users:active', 60, function () use ($db) {
    return $db->table('users')->where('active', 1)->get();
});
```

- On the first call (and after expiry) the closure runs, the result is stored, and returned.
- Subsequent calls within 60 s return the stored value without touching the DB.

Pattern: prefer `remember()` over `if (! $cache->has(...))` + `set()`. One call, race-free for the typical case, half the typing.

## `increment()` — atomic counters

Backed by Redis `INCR` (truly atomic across processes). Returns the new value:

```php
$count = $cache->increment('signups:today');         // +1
$count = $cache->increment('downloads:abc', 3);      // +3
```

Use cases: rate limits, view counters, A/B test buckets, queue lengths. Don't try to do `$n = $cache->get('x'); $cache->set('x', $n + 1)` — that's racey.

> The Redis driver stores counters as **plain integers**, not serialized. Don't `get()` a counter expecting a complex value; use `increment()` and `get()` (which returns an `int`-castable string) consistently.

## PSR-16 — when a third-party lib demands it

Some libraries (especially HTTP clients, JWT libs) accept a `Psr\SimpleCache\CacheInterface`. Wrap your Lift cache:

```php
use Lift\Cache\Psr16Adapter;

$psr16 = new Psr16Adapter($app->make(CacheInterface::class));

$someLibrary->setCache($psr16);   // happy
```

`Psr16Adapter` supports `DateInterval` TTLs and `getMultiple()` / `setMultiple()` / `deleteMultiple()`.

## Security: HMAC envelope (Redis)

`RedisCache` accepts an optional `secret` parameter. **Use it in production.**

```php
new RedisCache($redis, secret: $_ENV['CACHE_HMAC_SECRET']);
```

Why: the driver uses `unserialize()` internally, and a write to Redis from anywhere (compromised neighbour, misconfigured `MONITOR` user, …) could inject a malicious payload that achieves RCE via PHP object injection on the next `get()`.

With a `secret`, every value is wrapped in `{"v":1,"mac":"<hmac>","data":"<serialized>"}`. The MAC is checked before `unserialize()` — tampered payloads return `null` instead of running anything.

Rotation: when the secret changes, all existing entries appear as cache misses (`null`) and are repopulated naturally.

## Real-world patterns

### Cache the expensive query

```php
class UserRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly CacheInterface $cache,
    ) {}

    public function topActive(): array
    {
        return $this->cache->remember('users:top:active', 60, function () {
            return $this->db->table('users')
                ->where('active', 1)
                ->orderByDesc('login_count')
                ->limit(10)
                ->get();
        });
    }
}
```

### Cache invalidation on write

```php
public function updateUser(int $id, array $data): void
{
    $this->db->table('users')->where('id', $id)->update($data);
    $this->cache->delete("user:{$id}", 'users:top:active');
}
```

### Rate limit by IP

```php
final class IpRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $maxPerMinute = 60,
    ) {}

    public function process($req, $next): ResponseInterface
    {
        $ip  = $req->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        $key = "rl:{$ip}:" . date('Y-m-d-H-i');

        $hits = $this->cache->increment($key);
        $this->cache->set($key, $hits, 70);   // refresh TTL each hit

        if ($hits > $this->maxPerMinute) {
            throw new \Lift\Exception\TooManyRequestsException("Slow down", retryAfter: 60);
        }

        return $next->handle($req);
    }
}
```

Lift ships a more featureful `RateLimitMiddleware` — see [Security](security#rate-limiting). The snippet above is the principle.

### Cache HTML fragment

See [Views — cached rendering](views#cached-rendering).

## Designing cache keys

- **Namespace by domain.** `user:42`, `product:7`, `feed:home:42` — separated by `:`.
- **Include the version of the data layout** so a deploy doesn't serve old shapes:
  ```php
  "user:v3:42"
  ```
- **Avoid user input in raw form** — hash it: `'page:' . md5($url)`. Otherwise an attacker can use the cache to fingerprint your routes / steal others' cache entries.
- **Don't put PII in keys** — Redis logs the key on every `KEYS` / `MONITOR`. Use IDs.

## Custom drivers

Implement `CacheInterface`. Three rules:

1. `get()` returns the *exact* value that was passed to `set()` (handle serialisation).
2. `increment()` is atomic across processes (or document that it isn't).
3. Respect `$ttl` in seconds; `0` means no expiry.

```php
final class FileCache implements CacheInterface
{
    public function __construct(private readonly string $dir) { … }
    public function get(string $key, mixed $default = null): mixed { … }
    public function set(string $key, mixed $value, int $ttl = 0): bool { … }
    // …
}
```

A Memcached driver in ~40 lines is left as an exercise — wrap `ext-memcached`.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Cache always empty under PHP-FPM | Using `ArrayCache` in production | Switch to `RedisCache`. |
| `get()` returns old data after deploy | Shape changed; old cache still alive | Bump cache-key version (`user:v2:…`). |
| `unserialize()` warning + 500 | Stored an object whose class no longer exists, or got a tampered payload | Use `secret` + invalidate the key. |
| `increment()` returns 0 on Redis miss | `incr` creates the key with 1, so first call returns **1** not 0 | That's correct — read carefully. |
| Two requests both run the factory in `remember()` | The "thundering herd" — first miss races | For very expensive ops, take a Redis lock around the work; or pre-warm. |
| Memory grows under `ArrayCache` | TTL is honoured only on `get`/`has` — no background eviction | Restart the worker; or use Redis. |

## Cheat sheet

```php
$cache->set('k', $v, 60);
$cache->get('k', $defaultValue);
$cache->has('k');
$cache->delete('k', 'k2');
$cache->flush();

$cache->remember('users:active', 60, fn() => $db->table('users')->where(...)->get());

$cache->increment('rl:1.2.3.4');                // +1
$cache->increment('rl:1.2.3.4', 5);             // +5

// PSR-16 adapter
$psr16 = new Psr16Adapter($cache);
$lib->setCache($psr16);
```

[Filesystem →](filesystem)
