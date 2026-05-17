---
layout: page
title: Queues
nav_order: 27
---

# Queues

A **queue** lets you push slow work onto a buffer and let a separate worker process do it later — keeping HTTP responses fast. Lift ships four drivers (sync, in-memory array, Redis, database), a base `AbstractJob` class, retries, delays, failed-job tracking, and a CLI `Worker` with graceful shutdown.

> Mental model: a **job** is a serializable PHP object with a `handle()` method. A **queue** is a list of jobs waiting to run. A **worker** is a long-running process that pops jobs and calls `handle()`. Crash, retry, fail, repeat.

## When to queue

| Operation                            | Sync or queue? |
|--------------------------------------|----------------|
| Send transactional email             | **Queue** — SMTP can take seconds |
| Generate a PDF report                | **Queue** — keep request < 100 ms |
| Push notification to thousands       | **Queue** — fan out in the worker |
| Talk to a flaky third-party API      | **Queue** — get retries for free  |
| Read user from DB to render a page   | Sync — the user is waiting |
| Update an unrelated cache after a write | Queue — non-critical path |

The rule: anything that doesn't strictly need to finish before you return the HTTP response should be queued.

## A 60-second tour

```php
use Lift\Queue\AbstractJob;

// 1. Define a job
final class SendWelcomeEmail extends AbstractJob
{
    public function __construct(private readonly string $email) {}

    public function handle(): void
    {
        // …actually send the email…
    }
}

// 2. Push from a handler
$app->post('/signup', function (Request $req) use ($app) {
    $email = $req->validate(['email' => 'required|email'])['email'];

    $app->dispatch(new SendWelcomeEmail($email));

    return Response::noContent();
});

// 3. Run a worker (separate CLI process)
//   vendor/bin/lift queue:work
```

That's the entire lifecycle. The handler returns in milliseconds; the worker does the slow work in the background.

## Driver overview

| Driver         | Class                     | Where jobs live              | Use when                                    |
|----------------|---------------------------|------------------------------|---------------------------------------------|
| **Sync**       | `SyncQueue`               | Nowhere — runs immediately   | Default. Dev/testing. Simple apps.          |
| **Array**      | `ArrayQueue`              | PHP memory                   | In-request batching. Tests.                 |
| **Redis**      | `RedisQueue`              | Redis lists + sorted sets    | Production default. Cheap, fast, distributed. |
| **Database**   | `DatabaseQueue`           | SQL table you control        | When you already run Postgres/MySQL and don't want Redis. Comes with failed-job table for free. |

All four implement `QueueInterface` — the application code doesn't change when you swap drivers.

### Picking a driver

Boot logic:

```php
use Lift\Queue\QueueInterface;
use Lift\Queue\SyncQueue;
use Lift\Queue\RedisQueue;

$app->singleton(QueueInterface::class, function () use ($app) {
    return match ($_ENV['QUEUE_DRIVER'] ?? 'sync') {
        'redis' => new RedisQueue($app->make(\Lift\Redis\RedisClientInterface::class)),
        'db'    => new \Lift\Queue\DatabaseQueue($app->make(\Lift\Database\Connection::class)),
        'array' => new \Lift\Queue\ArrayQueue(),
        default => new SyncQueue(),
    };
});

// Optional shortcut:
$app->setQueue($app->make(QueueInterface::class));
```

Now `$app->queue()` / `$app->dispatch(...)` use the configured driver.

## Defining jobs

Extend `AbstractJob`, override `handle()`. Everything else has a sane default.

```php
final class ProcessReport extends AbstractJob
{
    // Per-class overrides — optional
    protected string $queue = 'reports';   // queue name; default 'default'
    protected int    $delay = 0;            // delay (s) before becoming available
    protected int    $tries = 5;            // max attempts before failed()

    public function __construct(
        private readonly int $reportId,
    ) {}

    public function handle(): void
    {
        // …do the work; throw on failure for automatic retry…
    }

    // Override to alert someone after all retries fail
    public function failed(\Throwable $e): void
    {
        error_log("Report {$this->reportId} permanently failed: " . $e->getMessage());
    }
}
```

> Jobs are **serialized** when pushed (except `SyncQueue`). All constructor properties must be serializable — no resources, no closures, no PDO handles. Pass IDs and look up the rich objects inside `handle()`.

### Bad vs good

```php
// ❌ WRONG — $user, $logger are not serializable
new EmailJob($user, $logger);

// ✅ RIGHT — only IDs in the constructor; look up inside handle()
final class EmailJob extends AbstractJob
{
    public function __construct(private readonly int $userId) {}

    public function handle(): void
    {
        $user   = User::find($this->userId);
        $logger = $this->container()->get(LoggerInterface::class);
        // …
    }
}
```

### Injecting services into `handle()`

Lift doesn't auto-inject into `handle()`. The cleanest options:

1. **Static lookup** through a `RegisterContainerStatically` boot step. Verbose.
2. **Pull from `App` via a `setUp()` hook**. Couples jobs to App.
3. **Make a base class** that exposes a `container()` helper to subclasses.

The least painful pattern is option 3 plus a Worker subclass that injects the container during `process()`. The framework keeps the JobInterface minimal on purpose — you choose the wiring.

## Dispatching jobs

```php
$app->dispatch($job);                     // through QueueInterface — uses the configured driver
// OR
$queue = $app->queue();
$queue->push($job);                       // same thing
$queue->later(60, $job);                  // available 60 seconds from now
```

Inspect / clear:

```php
$queue->size('default');                  // pending count
$queue->size('reports');                  // per-queue
$queue->clear('default');                 // wipe one queue
```

`push()` honours `$job->getDelay()` — passing a job whose `getDelay()` is 60 is the same as `later(60, $job)`.

## Running a worker

Long-running process that loops, pops jobs, executes them, sleeps when empty:

```bash
vendor/bin/lift queue:work
vendor/bin/lift queue:work --queue=reports --sleep=2 --max-jobs=100
```

Programmatic equivalent:

```php
use Lift\Queue\Worker;

$worker = new Worker($app->queue(), $app->logger());
$worker->run(queue: 'default', sleep: 1, maxJobs: 0);
```

| `run()` arg | Meaning                                                    |
|-------------|------------------------------------------------------------|
| `queue`     | Name to poll. Default `'default'`.                         |
| `sleep`     | Seconds to wait between empty polls.                       |
| `maxJobs`   | Stop after N jobs (`0` = unlimited). Use for memory hygiene. |

### Graceful shutdown

The worker installs `SIGTERM` / `SIGINT` handlers (requires `ext-pcntl`). When you `kill <pid>` or hit Ctrl-C:

1. It finishes the **current** job.
2. Exits cleanly with the count of jobs processed.

Run multiple workers under **systemd**, **supervisord**, or a Kubernetes Deployment so they're auto-restarted on exit. A common pattern is `--max-jobs=1000` so each worker recycles its memory after every 1000 jobs.

Sample systemd unit:

```ini
[Unit]
Description=Lift queue worker
After=network.target redis.service

[Service]
ExecStart=/usr/bin/php /var/www/myapp/vendor/bin/lift queue:work --max-jobs=1000
Restart=always
RestartSec=1
User=www-data
KillSignal=SIGTERM
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
```

## Retries and final failure

By default `$tries = 3`. If `handle()` throws:

1. Worker logs `Job attempt failed` and tries again immediately.
2. After `getTries()` total attempts, it calls `$job->failed($exception)`.
3. With `DatabaseQueue`, the failed row is also marked persistently (see below).

To re-throw a "give up now" exception that skips retries, mark it with a sentinel and check in `handle()`:

```php
public function handle(): void
{
    try {
        $this->doWork();
    } catch (PermanentlyBrokenException $e) {
        $this->tries = 1;       // not standard — Lift respects only getTries()
        throw $e;
    }
}
```

(The framework doesn't ship a `PermanentFailureException` — keep your jobs simple, or call `failed()` directly + return.)

## Delayed jobs

```php
$queue->later(300, new SendReminderEmail($userId));   // 5 minutes from now
```

Behaviour depends on the driver:

- **Sync** — delay ignored, runs immediately.
- **Array / Redis / Database** — stored separately; the worker only sees them once their `ready-at` timestamp has passed.

`RedisQueue` uses a sorted set scored by ready-at and `pop()` migrates due jobs to the main list atomically. `DatabaseQueue` uses an `available_at` column with `SELECT … FOR UPDATE SKIP LOCKED`.

## Multiple queues

A "queue" is just a name. Use them to prioritise:

```php
final class CriticalPayment extends AbstractJob { protected string $queue = 'high'; }
final class CleanupOrphans extends AbstractJob { protected string $queue = 'low'; }

// Run a worker per queue, scale them independently:
vendor/bin/lift queue:work --queue=high   # 4 of these
vendor/bin/lift queue:work --queue=low    # 1 of these
```

You don't get strict priority "drain high before low" out of the box; spin up more workers on the busy queue instead.

## Failed jobs (DatabaseQueue)

Only `DatabaseQueue` persists failed jobs. It exposes a small management API:

```php
$queue = $app->queue();   // assumed to be DatabaseQueue

$queue->failedCount('default');         // int
$queue->listFailed('default');          // array of rows (newest first)

// Re-queue one row by ID
$queue->retry($rowId);

// Re-queue every failed job
$queue->retryAll('default');

// Permanently remove all failed rows
$queue->clearFailed('default');
```

A failed row keeps `payload`, `attempts`, `error`, `failed_at` — enough to debug without re-running.

### Crash recovery

If a worker process is killed mid-job (OOM, `kill -9`, power-loss), the row stays `reserved_at = <some-time>` forever. `DatabaseQueue::pop()` calls `pruneReserved()` on every poll — any row reserved longer than `$reservedTimeout` seconds (default 60) is released for retry. Tune the timeout if your jobs legitimately take longer than that.

### Adding application columns

Subclass `HasDatabaseExtra` if you want to persist tenant IDs, correlation IDs, etc. into the jobs table:

```php
use Lift\Queue\AbstractJob;
use Lift\Queue\HasDatabaseExtra;

final class TenantJob extends AbstractJob implements HasDatabaseExtra
{
    public function __construct(public readonly string $tenantId, public readonly int $id) {}

    public function getDatabaseExtra(): array
    {
        return ['tenant_id' => $this->tenantId];
    }

    public function handle(): void { /* … */ }
}

// Define the extra column when constructing the queue:
new DatabaseQueue(
    $db,
    extraColumns: fn($t) => $t->string('tenant_id', 36)->nullable()->index(),
);
```

Now you can `SELECT … WHERE tenant_id = '…'` directly against the queue table — handy for ops dashboards.

## Security: signed payloads

`RedisQueue`, `DatabaseQueue`, and `AmqpQueue` serialise jobs with `serialize()`. Anyone with write access to your Redis key, DB row, or AMQP exchange could craft a payload that triggers PHP object injection via `unserialize`. All three drivers accept an optional `$secret`:

```php
new RedisQueue($redis, secret: $_ENV['QUEUE_SECRET']);
new DatabaseQueue($db,   secret: $_ENV['QUEUE_SECRET']);
new AmqpQueue($channel,  secret: $_ENV['QUEUE_SECRET']);
```

When the secret is non-empty, every payload is HMAC-SHA256-signed. Use the same secret on every worker.

> **Since 1.2.1:** when a secret is configured, a payload that arrives **without** the signed envelope is rejected outright — it is never passed to `unserialize()`. Earlier versions silently accepted unsigned payloads even when a secret was set, which let an attacker bypass the HMAC check entirely. Configuring a `$secret` is strongly recommended for any non-`sync` queue.

## Testing

Three options, from cheapest to most realistic.

### 1. `SyncQueue` (default in tests)

```php
$app->setQueue(new SyncQueue());

$app->dispatch(new SendWelcomeEmail($email));      // runs immediately
self::assertSame(1, $mailerSpy->count);             // can assert side effects
```

The default — if a job throws, your test throws. No worker needed.

### 2. `ArrayQueue` + manual `pop()`

```php
$queue = new ArrayQueue();
$app->setQueue($queue);

$response = $this->post('/signup', ['email' => 'a@b.c'])->assertCreated();

self::assertSame(1, $queue->size());
self::assertInstanceOf(SendWelcomeEmail::class, $queue->pop());
```

Lets you assert "a job was queued" without actually running it.

### 3. Real driver in CI

For e2e tests, point `QUEUE_DRIVER=redis` at a test Redis (or use `:memory:` SQLite + `DatabaseQueue`). Run a worker in a separate process and use `Worker::process()` directly for synchronous assertions.

## Operational tips

- **Memory leaks accumulate.** PHP doesn't reclaim memory across requests — use `--max-jobs=1000` and let systemd restart the worker.
- **One worker, one queue.** If you mix `--queue=default,reports`, throughput on the busy queue starves the quiet one. Spin up dedicated workers.
- **Monitor `size()`** — drop alerts when a queue's depth grows continuously.
- **Idempotency.** Workers may execute a job more than once (crash before ack, double dispatch). Use idempotency keys: `if (Db::exists("emails_sent.{$jobId}")) return;` at the top of `handle()`.
- **Don't queue inside a transaction** that you haven't committed yet — the worker may try to look up rows that don't exist yet. Push **after** `$db->transaction(...)` returns.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `Job processed` in logs but nothing happened | `SyncQueue` was used and `handle()` was silent | Switch to a real driver. |
| `Class not found` on worker startup after deploy | Worker is running old code | Restart the worker on every deploy. |
| `unserialize` warning | Job class was renamed / removed | Drain old queue before deploying renames; or read the `payload` column manually and re-push. |
| Same job runs N times | No idempotency; worker crashed after `handle()` but before ack | Add an idempotency key (`INSERT IGNORE INTO processed_jobs`). |
| Worker eats RAM | Long-lived process accumulates state | `--max-jobs=N`; restart loop via systemd. |
| `pcntl_signal not available` | Compiled PHP without pcntl | Install `php-pcntl` or accept ungraceful kills (jobs may be reprocessed). |
| Delayed jobs never run | Workers polling the *wrong* queue name | `--queue=default` matches `getQueue()`; check spelling. |

## Cheat sheet

```php
// Define
final class EmailJob extends AbstractJob {
    protected int $tries = 5;
    public function __construct(private int $userId) {}
    public function handle(): void { /* … */ }
    public function failed(\Throwable $e): void { /* alert */ }
}

// Driver
$app->setQueue(new RedisQueue($redis, secret: $_ENV['QUEUE_SECRET']));

// Push
$app->dispatch(new EmailJob(42));
$app->queue()->later(60, new EmailJob(42));

// Worker (CLI)
vendor/bin/lift queue:work --queue=default --sleep=1 --max-jobs=1000

// Failed-job ops (DatabaseQueue only)
$queue->failedCount();
$queue->retry($rowId);
$queue->retryAll();
$queue->clearFailed();
```

[Events →](events)
