---
layout: page
title: Redis
nav_order: 22
---

# Redis

`Lift\Redis\RedisClient` is a thin wrapper around the `phpredis` extension. It implements `Lift\Redis\RedisClientInterface` — the contract used by `RedisCache`, `RedisQueue`, `RedisSessionStore`, and the rate-limit middleware.

> Mental model: a "minimum useful Redis API". Strings, counters, lists, sorted sets, TTLs. No pub/sub, no streams, no cluster — for those, grab the raw `\Redis` instance with `$client->raw()`.

## Why a separate interface?

You can use Lift's cache, queue, and sessions with any Redis-shaped backend — `phpredis`, `Predis`, in-memory mock for tests — as long as it implements `RedisClientInterface`. Your application code is decoupled from which client is actually in the box.

## Setup

### `phpredis` extension required

The default driver uses the C extension because it's ~3× faster than userland clients:

```bash
sudo apt install php8.3-redis      # Debian/Ubuntu
brew install php-redis             # macOS via brew tap
```

Then in PHP:

```php
use Lift\Redis\RedisClient;
use Lift\Redis\RedisClientInterface;

$app->singleton(RedisClientInterface::class, fn() => new RedisClient(
    host:    $_ENV['REDIS_HOST']     ?? '127.0.0.1',
    port:    (int) ($_ENV['REDIS_PORT'] ?? 6379),
    timeout: 1.5,
    prefix:  'myapp:',              // applied to every key automatically
    db:      0,
    auth:    $_ENV['REDIS_PASSWORD'] ?? '',
));
```

The constructor connects immediately and throws `RuntimeException` if Redis is unreachable.

### Container wiring

Bind to the **interface**, not the concrete class — so tests can swap a mock:

```php
$app->singleton(RedisClientInterface::class, fn() => new RedisClient(...));
```

Then services type-hint the interface:

```php
class FeedRepository
{
    public function __construct(private readonly RedisClientInterface $redis) {}
}
```

## The interface

```php
// Strings / generic
$redis->get($key);                  // string|false
$redis->set($key, $value, $ttl = 0);
$redis->del(...$keys);              // int (count deleted)
$redis->exists($key);               // int (1 or 0)
$redis->expire($key, $ttl);
$redis->ttl($key);                  // int  ( -1 no expiry, -2 missing )

// Counters
$redis->incr($key);                 // int (atomic +1)
$redis->incrBy($key, $by);          // int (atomic +N)

// Lists  — used by RedisQueue
$redis->lPush($key, ...$values);    // int (new length)
$redis->rPop($key);                 // string|false
$redis->lLen($key);                 // int

// Sorted sets — used by delayed queues
$redis->zAdd($key, $score, $member);
$redis->zRangeByScore($key, $min, $max);
$redis->zRem($key, ...$members);

// Connection
$redis->ping();                     // bool
$redis->select($db);                // switch logical DB
```

That's the whole API.

## Usage examples

### Plain key/value

```php
$redis->set('feature:darkmode', '1', 3600);
$enabled = $redis->get('feature:darkmode') === '1';
$redis->del('feature:darkmode');
```

Values are always strings on the wire. For complex data, serialise yourself (or use [Cache](cache) which does it for you).

### Counters

```php
$views = $redis->incr("post:42:views");          // atomically +1
$redis->expire("post:42:views", 86400);          // expire after a day
```

`incr` returns the new value. Use this for view counters, rate limits, anything that needs to count concurrently without races.

### Queues (lists)

```php
// Producer
$redis->lPush('jobs', json_encode(['type' => 'send_email', 'to' => 'a@b.c']));

// Worker
while (true) {
    $job = $redis->rPop('jobs');
    if ($job === false) { sleep(1); continue; }
    handle(json_decode($job, true));
}
```

For real queue features (retries, backoff, multiple drivers), use [Queues](queues) — it builds on this primitive.

### Delayed jobs (sorted sets)

```php
// Schedule a job for time T
$redis->zAdd('jobs:delayed', $runAt = time() + 60, json_encode($payload));

// Worker reaper — every second
foreach ($redis->zRangeByScore('jobs:delayed', '-inf', (string) time()) as $job) {
    $redis->lPush('jobs', $job);
    $redis->zRem('jobs:delayed', $job);
}
```

The Redis queue driver does this for you.

### Distributed lock (poor man's)

For real production locks, use a library (e.g. `redlock-php`). For "good enough" guards:

```php
$ok = $redis->set("lock:export", '1', 60);   // NX is NOT implemented in the interface
if ($ok) {
    try { runExport(); } finally { $redis->del("lock:export"); }
}
```

> Lift's interface lacks `SET … NX` — drop to `$redis->raw()->set($k, '1', ['NX', 'EX' => 60])` for true mutual-exclusion semantics.

## Escape hatch — `raw()`

`RedisClient::raw()` returns the underlying `\Redis` instance for operations not in the interface:

```php
$pipeline = $redis->raw()->pipeline();
$pipeline->set('a', '1');
$pipeline->incrBy('b', 5);
$results = $pipeline->exec();

// Pub/Sub
$redis->raw()->subscribe(['channel1'], function ($redis, $channel, $message) { … });

// MGET
$values = $redis->raw()->mGet(['k1', 'k2', 'k3']);
```

Use `raw()` sparingly — anything you rely on it for can't be mocked in tests without faking `\Redis` itself.

## Testing without a real Redis

Implement `RedisClientInterface` with an in-memory backend:

```php
final class FakeRedis implements RedisClientInterface
{
    private array $data    = [];
    private array $expires = [];
    private array $lists   = [];

    public function get(string $key): string|false { return $this->data[$key] ?? false; }
    public function set(string $key, string $value, int $ttl = 0): bool
    {
        $this->data[$key] = $value;
        if ($ttl > 0) $this->expires[$key] = time() + $ttl;
        return true;
    }
    public function del(string ...$keys): int
    {
        $n = 0;
        foreach ($keys as $k) {
            if (isset($this->data[$k])) { unset($this->data[$k]); $n++; }
        }
        return $n;
    }
    // …implement the rest…
}

// In your TestCase:
$app->instance(RedisClientInterface::class, new FakeRedis());
```

Building a complete fake is a few hours of work — but it lets your test suite run without `docker run -p 6379:6379 redis`.

## High-availability notes

- **Sentinel / Cluster** isn't built into `RedisClient`. Use `\Redis` directly or use the Predis client behind your own `RedisClientInterface` implementation.
- **Connection pooling**: a singleton `RedisClient` is one TCP connection. Under PHP-FPM, that's one connection per worker — fine for most loads. Under RoadRunner/Swoole, the connection is reused across requests, so make sure your queries are short.
- **Timeouts** matter a lot. Set `timeout: 1.5` (or less) in production — a stalled Redis shouldn't take your whole API with it.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `RuntimeException: extension "redis" is required` | `phpredis` not installed | Install the extension (see Setup). |
| Keys appear `myapp:user:42` not `user:42` | You set a `prefix` in the constructor | Either expected behaviour — or drop the prefix. |
| `ttl()` returns `-1` when you set a TTL | `set()` with `$ttl=0` skips TTL; previous `EXPIRE` was overwritten | Pass `$ttl > 0` to `set()`, or call `expire()` after. |
| Counter starts at `1` not `0` on first hit | `incr` creates missing keys with `0` then adds `1` → returns `1` | That's correct — read off-by-one carefully. |
| Subscribe blocks the entire app | Pub/sub is synchronous | Run it in a separate process, never inside a request handler. |
| `auth()` fails after restart | Redis upgraded to 6+ ACLs; password-only auth deprecated | Pass `username:password` style, or update your Redis config. |

## Cheat sheet

```php
$redis = new RedisClient(
    host: '127.0.0.1', port: 6379, timeout: 1.5,
    prefix: 'myapp:', db: 0, auth: $_ENV['REDIS_PASSWORD'],
);

$redis->set('k', 'v', 60);
$redis->get('k');                       // 'v'|false
$redis->del('k', 'k2');                 // int
$redis->incr('counter');                // +1, returns new value
$redis->incrBy('counter', 10);
$redis->expire('k', 30);
$redis->ttl('k');

$redis->lPush('q', 'a', 'b');
$redis->rPop('q');
$redis->lLen('q');

$redis->zAdd('z', 1.5, 'm');
$redis->zRangeByScore('z', '-inf', '+inf');
$redis->zRem('z', 'm');

$redis->ping();
$redis->raw()->…;                       // anything else
```

[Collections →](collections)
