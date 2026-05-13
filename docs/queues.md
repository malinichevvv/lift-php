---
layout: page
title: Queues
nav_order: 13
---

# Queues

Lift's queue system lets you defer time-consuming work (emails, reports, webhooks) to a background worker. Three drivers are included: `SyncQueue` (immediate, for development), `ArrayQueue` (in-memory), and `RedisQueue` (production).

---

## Defining a job

```php
use Lift\Queue\AbstractJob;

class SendWelcomeEmail extends AbstractJob
{
    protected string $queue = 'emails';   // queue name (default: 'default')
    protected int    $tries = 3;           // max attempts (default: 3)
    protected int    $delay = 0;           // seconds before becoming visible (default: 0)

    public function __construct(
        private readonly string $email,
        private readonly string $name,
    ) {}

    public function handle(): void
    {
        // send email via your mailer
        mailer()->send($this->email, "Welcome, {$this->name}!");
    }

    public function failed(\Throwable $e): void
    {
        // called after all retries are exhausted
        logger()->error("Email failed for {$this->email}: {$e->getMessage()}");
    }
}
```

---

## Dispatching jobs

```php
// Register the queue in the container
$app->setQueue(new RedisQueue($redisClient));

// Dispatch from a route or controller
$app->dispatch(new SendWelcomeEmail($user['email'], $user['name']));

// With delay (process after 5 minutes)
$job = new SendWelcomeEmail($email, $name);
$job->delay = 300;
$app->dispatch($job);
```

---

## Queue drivers

### SyncQueue

Executes `handle()` immediately in the current request. Good for development and testing.

```php
$app->setQueue(new SyncQueue());
```

### ArrayQueue

In-memory queue with delayed job support. Resets when the process ends.

```php
use Lift\Queue\ArrayQueue;

$queue = new ArrayQueue();
$queue->push($job);

// For delayed jobs, migrate due ones first
$queue->migrateDue();
$job = $queue->pop();
```

### RedisQueue

Production-grade driver backed by Redis lists (ready jobs) and sorted sets (delayed jobs).

```php
use Lift\Queue\RedisQueue;
use Lift\Redis\RedisClient;

$redis = new RedisClient(host: 'localhost', password: $_ENV['REDIS_PASSWORD']);
$queue = new RedisQueue($redis);

$app->setQueue($queue);
```

---

## Running a worker

The `Worker` class polls the queue and processes jobs with retry logic.

```bash
# Run indefinitely
php artisan worker:run

# Or use the Worker directly
```

```php
use Lift\Queue\Worker;
use Psr\Log\NullLogger;

$worker = new Worker($app->container(), new NullLogger());
$worker->run($queue, sleep: 1, maxJobs: 0); // maxJobs=0 means forever
```

The worker handles:
- **Retries** — re-queues on failure up to `$job->tries`
- **Graceful shutdown** — catches `SIGTERM` / `SIGINT` (requires `ext-pcntl`)
- **Delayed jobs** — migrates delayed jobs when they become due

---

## DI container integration

```php
use Lift\Queue\RedisQueue;

$app->singleton(RedisQueue::class, fn($c) => new RedisQueue($c->make(RedisClient::class)));
$app->setQueue($app->container()->make(RedisQueue::class));
```

---

## Custom job options

All properties on `AbstractJob` can be set per instance:

```php
class ReportJob extends AbstractJob
{
    protected string $queue = 'reports';
    protected int    $tries = 5;
    protected int    $delay = 60; // 1 minute delay

    public function handle(): void { /* ... */ }
    public function failed(\Throwable $e): void { /* ... */ }
}
```
