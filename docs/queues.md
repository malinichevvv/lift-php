---
layout: page
title: Queues
nav_order: 15
---

# Queues

Lift's queue system lets you defer time-consuming work (emails, reports, webhooks) to a background worker. Five drivers are included: `SyncQueue` (immediate, for development), `ArrayQueue` (in-memory), `DatabaseQueue` (relational DB), `RedisQueue` (production), and `AmqpQueue` (RabbitMQ).

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

### DatabaseQueue

Persists jobs in any PDO-compatible relational database (MySQL, PostgreSQL, SQLite).
The table is created automatically on first use — no manual migration required for
quick start, though `queue:table` can generate a versioned migration file.

```php
use Lift\Queue\DatabaseQueue;
use Lift\Database\Connection;

$db    = Connection::fromConfig([
    'driver'   => 'mysql',
    'host'     => 'localhost',
    'database' => 'app',
    'username' => 'root',
    'password' => $_ENV['DB_PASSWORD'],
]);

$queue = new DatabaseQueue($db);
$app->setQueue($queue);
```

#### Custom table name

```php
$queue = new DatabaseQueue($db, table: 'queue_jobs');
```

#### Extra columns

Add application-specific columns via the `$extraColumns` callback:

```php
use Lift\Database\Schema\Blueprint;

$queue = new DatabaseQueue(
    db: $db,
    table: 'jobs',
    extraColumns: function (Blueprint $t): void {
        $t->string('tenant_id', 36)->nullable()->index();
        $t->string('priority', 10)->default('normal');
    },
);
```

To persist values into those columns, implement `HasDatabaseExtra` on the job:

```php
use Lift\Queue\AbstractJob;
use Lift\Queue\HasDatabaseExtra;

class SendInvoice extends AbstractJob implements HasDatabaseExtra
{
    public function __construct(
        private readonly string $tenantId,
        private readonly int    $invoiceId,
    ) {}

    public function handle(): void
    {
        // send invoice…
    }

    public function getDatabaseExtra(): array
    {
        return ['tenant_id' => $this->tenantId];
    }
}
```

#### Table schema

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT PK | Auto-increment identifier. |
| `queue` | VARCHAR(100) | Queue name (default: `"default"`). |
| `payload` | LONGTEXT | Serialised job. |
| `attempts` | SMALLINT | Times this row has been reserved. |
| `available_at` | BIGINT | Unix timestamp when the job is dispatchable. |
| `reserved_at` | BIGINT NULL | Set when a worker pops the job; NULL when pending. |
| `failed_at` | BIGINT NULL | Set when all retries are exhausted. |
| `error` | TEXT NULL | Exception message of the last failure. |
| `created_at` | BIGINT | Unix timestamp of insertion. |
| *(extra)* | *any* | Application columns via `$extraColumns`. |

#### Generating a migration

Run the console command to create a versioned migration file you can commit:

```bash
php lift queue:table
# or with a custom table name:
php lift queue:table --table=queue_jobs
```

Then apply it:

```bash
php lift migrate
```

#### Failed job management

Jobs that exhaust all retries are marked failed but kept in the table.

```php
// List all failed jobs
$rows = $queue->listFailed();          // array of row arrays
$count = $queue->failedCount();

// Re-queue a single failed job
$queue->retry($rowId);

// Re-queue all failed jobs
$queue->retryAll();

// Permanently delete all failed jobs
$queue->clearFailed();
```

#### Crash recovery

If a worker process crashes while a job is reserved, the row stays reserved
indefinitely. `pop()` automatically releases rows reserved longer than
`$reservedTimeout` seconds (default: 60) so another worker can pick them up.

```php
// Custom timeout (seconds)
$queue = new DatabaseQueue($db, reservedTimeout: 120);

// Manually prune stale reservations
$released = $queue->pruneReserved(timeout: 90);

// Release a specific row back to pending
$queue->release($rowId);
// With a delay (re-queue after 5 minutes)
$queue->release($rowId, delay: 300);
```

#### Concurrent workers

On MySQL and PostgreSQL, `pop()` uses `SELECT … FOR UPDATE SKIP LOCKED` inside a
transaction — two workers polling simultaneously never receive the same job. On
SQLite the transaction alone serializes access.

---

### RedisQueue

Production-grade driver backed by Redis lists (ready jobs) and sorted sets (delayed jobs).

```php
use Lift\Queue\RedisQueue;
use Lift\Redis\RedisClient;

$redis = new RedisClient(host: 'localhost', password: $_ENV['REDIS_PASSWORD']);
$queue = new RedisQueue($redis);

$app->setQueue($queue);
```

### AmqpQueue (RabbitMQ)

RabbitMQ driver using the AMQP protocol. Requires [php-amqplib](https://github.com/php-amqplib/php-amqplib) as a separate dependency:

```bash
composer require php-amqplib/php-amqplib "^3.0"
```

```php
use Lift\Queue\AmqpQueue;

$queue = new AmqpQueue([
    'host'     => $_ENV['RABBITMQ_HOST']     ?? 'localhost',
    'port'     => $_ENV['RABBITMQ_PORT']     ?? 5672,
    'user'     => $_ENV['RABBITMQ_USER']     ?? 'guest',
    'password' => $_ENV['RABBITMQ_PASSWORD'] ?? 'guest',
    'vhost'    => $_ENV['RABBITMQ_VHOST']    ?? '/',
]);

$app->setQueue($queue);
```

#### Configuration options

| Key | Default | Description |
|-----|---------|-------------|
| `host` | `localhost` | RabbitMQ server host. |
| `port` | `5672` | AMQP port. |
| `user` | `guest` | AMQP username. |
| `password` | `guest` | AMQP password. |
| `vhost` | `/` | Virtual host. |
| `exchange` | `''` | Exchange name (empty = default exchange). |
| `prefetch` | `1` | `basic_qos` prefetch count per consumer. |

#### Delayed jobs

Delayed jobs are implemented without requiring the RabbitMQ Delayed Message Exchange plugin. A short-lived TTL queue is declared automatically. When the TTL expires, RabbitMQ delivers the message to the main queue via a dead-letter exchange.

```php
// Dispatch a job to run after 5 minutes
$job = new SendReminderJob($userId);
$job->delay = 300;
$queue->push($job);

// Or use later() explicitly
$queue->later(300, $job);
```

#### Graceful shutdown

`AmqpQueue` closes its channel and connection automatically when it is garbage-collected (`__destruct`). In long-running workers, call `$queue = null` explicitly or let PHP's shutdown routine handle it.

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
