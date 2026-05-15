---
layout: page
title: Async (Fibers)
nav_order: 35
---

# Async (Fibers)

`Lift\Async\Concurrent` is a tiny cooperative-concurrency helper built on **PHP 8.1 Fibers**. It lets you run several blocking I/O calls in parallel **within a single PHP process**, without ext-amphp / ext-react / ext-swoole.

> Mental model: a `Fiber` is a function that can pause itself (`suspend`) and resume later. `Concurrent::all([...])` starts many tasks as fibers, round-robins them until they all finish, and collects results.

**Important:** this is **not** real parallelism — PHP still runs one statement at a time. Fibers help when tasks **spend most of their time waiting** for I/O (HTTP calls, DB queries, sleeps). For CPU-bound work they don't help; use a queue worker pool instead.

## When (and when not) to use it

✅ **Good fits**

- Hit 5 third-party APIs and merge their results — sequential = 5×latency, concurrent ≈ 1×latency.
- Batch up many `curl_multi` calls behind a clean interface.
- Pre-warm caches by issuing several reads at once.
- Run a few "best-effort" operations after a write without blocking the response.

❌ **Bad fits**

- CPU-bound work (image resizing, parsing) — fibers don't get more cores.
- Anything you can move to a [queue](queues) — async-in-request is harder to reason about than async-via-worker.
- Long-running streams — use [Server-Sent Events](sse) or a real event loop.

## 30-second example

```php
use Lift\Async\Concurrent;
use Lift\Http\HttpClient;

[$github, $weather, $stocks] = Concurrent::all([
    fn() => HttpClient::new()->get('https://api.github.com/repos/malinichevvv/lift-php')->json(),
    fn() => HttpClient::new()->get('https://api.weather.gov/...')->json(),
    fn() => HttpClient::new()->get('https://api.iexcloud.io/...')->json(),
]);

return Response::json([
    'github'  => $github,
    'weather' => $weather,
    'stocks'  => $stocks,
]);
```

If each call takes ~200 ms blocking, the sequential version takes ~600 ms and the concurrent version takes ~200 ms — *if* the underlying client yields during I/O. Otherwise, this is still equivalent to `Concurrent::sequential(...)`. See "When fibers actually help" below.

## API

### `Concurrent::all(array $tasks): array`

Starts each callable as a fiber, round-robins until all complete, returns results in the same order. Re-throws the **first** exception from any task.

```php
$results = Concurrent::all([
    'github'  => fn() => fetchGithub(),
    'weather' => fn() => fetchWeather(),
]);
// $results['github'], $results['weather']
```

Keys are preserved.

### `Concurrent::suspend(): void`

Inside a task callable, yield control to the next fiber:

```php
Concurrent::all([
    function () {
        $data = openSocketRead();        // blocking-ish
        Concurrent::suspend();           // let other tasks run
        $more = openSocketRead();
        return $data . $more;
    },
    function () { /* … */ },
]);
```

Calling `suspend()` outside a fiber is a no-op — safe to use everywhere.

### `Concurrent::sequential(array $tasks): array`

Identical signature to `all()`, but runs tasks one after another. Useful as a drop-in for environments that prohibit fibers (e.g. PHP < 8.1, or test suites that don't tolerate fiber lifecycle quirks):

```php
$tasks = [/* … */];
$results = $useFibers ? Concurrent::all($tasks) : Concurrent::sequential($tasks);
```

### `Concurrent::run(callable $task): mixed`

Wrap a single callable in a fiber and run it to completion. Mainly useful for stress-testing a function's fiber-safety.

## When fibers actually help

A fiber only yields when **something inside it calls `Fiber::suspend()`**. PHP doesn't auto-yield during native I/O. So:

| Library / call                            | Auto-yields? |
|-------------------------------------------|:------------:|
| `curl_exec`, plain `file_get_contents`    | ❌ blocks the whole process |
| `curl_multi_*` with manual select         | ✅ if you wrap select in `suspend()` |
| `ReactPHP` / `amphp` async clients        | ✅ — they integrate with the fiber scheduler |
| `sleep()` / `usleep()`                    | ❌ — blocks |
| `Concurrent::suspend()` explicit yield    | ✅           |

In short: `Concurrent::all([HttpClient::new()->get(...), ...])` **does not** speed things up because cURL blocks the whole PHP process. Use it with libraries that integrate with PHP fibers, or with `curl_multi_*` where you can sprinkle `suspend()` between selects.

For an actual speedup with HTTP, drop down to `curl_multi`:

```php
function parallelGet(array $urls): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $i => $url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
    } while ($running > 0);
    $out = [];
    foreach ($handles as $i => $ch) {
        $out[$i] = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

$pages = parallelGet([
    'https://api.example.com/a',
    'https://api.example.com/b',
    'https://api.example.com/c',
]);
```

`curl_multi_select($mh, 0.1)` is the natural place to `Concurrent::suspend()` if you want to weave this into a larger fiber group.

## Real-world pattern — fan-out with timeout

```php
use Lift\Async\Concurrent;

$timeout = 2.0;
$start   = microtime(true);

$results = Concurrent::all([
    'inventory' => fn() => $this->safe(fn() => $this->inventory->lookup($sku), $timeout, $start),
    'pricing'   => fn() => $this->safe(fn() => $this->pricing->fetch($sku),   $timeout, $start),
    'reviews'   => fn() => $this->safe(fn() => $this->reviews->latest($sku),  $timeout, $start),
]);

return Response::json($results);

private function safe(callable $work, float $timeout, float $start): mixed
{
    if (microtime(true) - $start > $timeout) {
        return null;            // bail out — we're past the deadline
    }
    try {
        return $work();
    } catch (\Throwable) {
        return null;            // best-effort: degrade gracefully
    }
}
```

Each task tracks the global deadline and bails out gracefully — the response never takes longer than `~timeout` seconds, even if one upstream is slow.

## Error handling

`Concurrent::all()` re-throws the first exception from any task **after** all fibers complete. If you need per-task error isolation, wrap each callable in its own try/catch:

```php
$results = Concurrent::all([
    fn() => safelyCall(fn() => $a->fetch()),
    fn() => safelyCall(fn() => $b->fetch()),
]);

function safelyCall(callable $work): array
{
    try   { return ['ok' => true,  'value' => $work()]; }
    catch (\Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
}
```

This is what most production code looks like — bubble individual failures, never let one bad task kill the batch.

## Testing

Fibers are deterministic when all tasks are pure. Treat them like normal functions in tests:

```php
public function testParallelFetchMergesResults(): void
{
    $service = new ProductPage(/* mocked clients */);
    $result  = $service->show('SKU-1');

    self::assertSame('Widget', $result['name']);
    self::assertArrayHasKey('reviews', $result);
}
```

Avoid asserting "they actually ran in parallel" — that's an implementation detail. Assert the *outcome*.

## Limitations

- **No event loop** — `Concurrent::all` is a busy-resume loop. Real-life async needs ext-event / ReactPHP / amphp.
- **Globals leak across fibers.** PHP's `$_SERVER`, error handlers, and many extensions don't expect fiber switches. Stay within pure callables, don't fiber across third-party code that fiddles with global state.
- **Tasks share the request** — they all see the same `Request`, `Connection`, container. No isolation. Mind your transactions.
- **Cancellation** isn't supported. Once a task is started, it runs to completion (or throws). Use deadlines inside the task.

For more serious async workloads, reach for ReactPHP, amphp, FrankenPHP, or RoadRunner. `Concurrent` is the 50-line answer for the simple "fan out N HTTP calls" case.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `Concurrent::all([...])` is the same speed as sequential | Tasks don't yield at I/O wait points | Use libraries that integrate with PHP fibers, or `curl_multi_*`. |
| `Fiber::suspend` called outside a fiber | `suspend()` from non-fiber context | The helper makes this a no-op; check you're inside a task callable. |
| One bad task throws and others were dropped | `all()` re-throws the first error | Wrap each task in try/catch for per-task isolation. |
| Memory grows linearly with fiber count | You created N fibers but never let them finish | Don't create thousands of fibers per request — batch in groups. |
| Transactions interleave weirdly | Two fibers share one DB connection | Don't open a transaction inside a fiber unless you also open a separate connection per fiber. |
| `$_SESSION` writes lost | PHP session save handlers are not fiber-aware | Use [Sessions](sessions) (driver-backed) instead. |

## Cheat sheet

```php
use Lift\Async\Concurrent;

// Fan-out
$results = Concurrent::all([
    'a' => fn() => callA(),
    'b' => fn() => callB(),
    'c' => fn() => callC(),
]);

// Yield inside a task
Concurrent::suspend();

// Sequential fallback (no-fiber environments)
$results = Concurrent::sequential($tasks);

// Single fiber wrapper
$value = Concurrent::run(fn() => doSomething());

// First-error semantics
try {
    Concurrent::all($tasks);
} catch (\Throwable $e) {
    // …first task to throw bubbles up here
}
```

[UUID & ULID →](uuid)
