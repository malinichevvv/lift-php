---
layout: page
title: Logging
nav_order: 29
---

# Logging

`Lift\Log\Logger` is a **PSR-3** logger with pluggable handlers and formatters. It supports the eight standard log levels, placeholder interpolation, and stacks of independent handlers (e.g. write JSON to a file *and* coloured lines to stdout simultaneously).

> Mental model: a **logger** receives messages. **Handlers** decide where they go (file, stdout, syslog, /dev/null). **Formatters** decide what they look like (JSON, plain line, etc.). One logger, many handlers, each with its own formatter and minimum level.

## When and how much to log

- **Errors and warnings**: always. Otherwise you'll never know your app is broken.
- **Important business events**: yes, with `info()`. ("Order #1234 placed", "User signed up".)
- **Debugging detail**: `debug()` — turn on only in dev / for selected requests.
- **PII**: never. Mask emails, redact tokens. Logs are the #1 place secrets leak.

## 30-second example

```php
use Lift\Log\Logger;
use Lift\Log\Handler\FileHandler;
use Lift\Log\Handler\StdoutHandler;
use Lift\Log\Formatter\JsonFormatter;

$logger = new Logger([
    new FileHandler('/var/log/myapp.log', 'debug', new JsonFormatter()),
    new StdoutHandler('warning'),
]);

$logger->info('User logged in', ['user_id' => 42]);
$logger->error('Payment failed', ['order_id' => 123, 'exception' => $e]);
$logger->warning('Hot-cache miss', ['key' => 'user:42']);
```

The same `info(...)` call goes through **both** handlers: the file gets a full JSON line, stdout sees nothing (level filter), and a `warning()` call would hit both.

## PSR-3 levels

Standard severities, ordered most → least severe:

| Method        | Level        | Use for                                       |
|---------------|--------------|-----------------------------------------------|
| `emergency()` | `emergency`  | System is unusable                            |
| `alert()`     | `alert`      | Action must be taken immediately              |
| `critical()`  | `critical`   | Critical conditions (component down)          |
| `error()`     | `error`      | Errors that need attention but don't stop the app |
| `warning()`   | `warning`    | Something fishy, may become an error          |
| `notice()`    | `notice`     | Normal but significant events                 |
| `info()`      | `info`       | Routine operational events                    |
| `debug()`     | `debug`      | Detailed debug-only info                      |

Or the generic `log($level, $message, $context)`.

## Wiring into Lift

`App` does not register a logger automatically — register your own:

```php
use Lift\Log\Logger;
use Lift\Log\Handler\FileHandler;
use Lift\Log\Formatter\JsonFormatter;
use Psr\Log\LoggerInterface;

$app->singleton(Logger::class, fn() => new Logger([
    new FileHandler(__DIR__ . '/../storage/logs/app.log', 'info', new JsonFormatter()),
]));

// Bind PSR-3 interface to the same instance — third-party libs accept that
$app->bind(LoggerInterface::class, fn() => $app->make(Logger::class));
```

Then anywhere — handler, controller, middleware — type-hint and inject:

```php
class UserController
{
    public function __construct(private readonly LoggerInterface $log) {}

    public function login(Request $req): Response
    {
        $this->log->info('Login attempt', ['email' => $req->input('email')]);
        // …
    }
}
```

## Placeholder interpolation

PSR-3 supports `{key}` placeholders that pull from the context array:

```php
$log->info('User {user_id} did {action}', [
    'user_id' => 42,
    'action'  => 'login',
]);
// → "User 42 did login"   (+ the full context array still preserved)
```

The replacement covers strings, numerics, and any object with `__toString()`. Other values stay in the context but are not substituted into the message.

## Context array

The second argument is a free-form associative array. Conventions:

- **`exception`** → pass the `Throwable`. Most handlers include the stack trace.
- **`user_id` / `request_id` / `trace_id`** → for correlation across services.
- **Whole `Throwable`** as a value:

```php
try {
    $this->processPayment($order);
} catch (\Throwable $e) {
    $this->log->error('Payment processing failed', [
        'order_id'  => $order->id,
        'amount'    => $order->total,
        'exception' => $e,                                    // formatter renders it
    ]);
    throw $e;
}
```

## Handlers

A **handler** decides where lines are written and gates them by minimum level. Built-ins:

| Handler                | Writes to                                          |
|------------------------|----------------------------------------------------|
| `FileHandler`          | A single file (creates dir if missing)             |
| `RotatingFileHandler`  | Daily-rotated files; prunes old files automatically|
| `StdoutHandler`        | `php://stdout`                                     |
| `NullHandler`          | Nowhere (useful in tests)                          |

Each handler takes a minimum level + (optional) formatter:

```php
new FileHandler('/var/log/app.log', minLevel: 'warning', formatter: new JsonFormatter());
new RotatingFileHandler('/var/log/app.log', minLevel: 'info', maxFiles: 30);
new StdoutHandler(minLevel: 'debug');                  // default formatter = LineFormatter
new NullHandler();
```

### Adding a handler to an existing logger

`withHandler()` returns a clone with the extra handler:

```php
$logger = $logger->withHandler(new FileHandler('/tmp/debug.log', 'debug'));
```

Useful in tests when you want to capture log lines temporarily.

## Formatters

A **formatter** turns a log record into a string. Built-ins:

| Formatter       | Output                                                          |
|-----------------|-----------------------------------------------------------------|
| `LineFormatter` | `[2026-05-14 15:30:00] info: User 42 logged in {"user_id":42}` |
| `JsonFormatter` | `{"ts":"2026-05-14T15:30:00Z","level":"info","message":"…","context":{…}}` |

Pick **`JsonFormatter`** for production — it's the format every log-aggregation tool (Loki, ELK, Datadog, CloudWatch) parses for free. Pick **`LineFormatter`** for human-readable terminal output.

### Custom formatter

```php
use Lift\Log\Formatter\FormatterInterface;

final class CompactFormatter implements FormatterInterface
{
    public function format(string $level, string $message, array $context): string
    {
        return sprintf("%s %-8s %s\n", date('H:i:s'), strtoupper($level), $message);
    }
}

new StdoutHandler('debug', new CompactFormatter());
```

## Common configurations

### Production — JSON to file + stdout

```php
$app->singleton(Logger::class, fn() => new Logger([
    new FileHandler(   __DIR__ . '/../storage/logs/app.log', 'info', new JsonFormatter()),
    new StdoutHandler('warning', new JsonFormatter()),   // container picks this up
]));
```

- File: every `info`+ goes here, for retrospective debugging.
- Stdout: `warning`+ so it shows up in `journalctl` / `docker logs` without flooding.
- Both JSON, so log shippers parse them identically.

### Development — coloured lines to stdout

```php
$app->singleton(Logger::class, fn() => new Logger([
    new StdoutHandler('debug'),    // LineFormatter, all levels
]));
```

### Tests — capture everything in memory

`Lift\Log\Handler\NullHandler` swallows everything. For tests that assert log content, write a small in-memory handler:

```php
final class ArrayHandler implements HandlerInterface
{
    public array $records = [];
    public function handle(string $level, string $message, array $context): void
    {
        $this->records[] = compact('level', 'message', 'context');
    }
}

// In your TestCase:
$this->app->instance(LoggerInterface::class, new Logger([$this->logHandler = new ArrayHandler()]));

// Assert
self::assertSame('error', $this->logHandler->records[0]['level']);
```

### Per-request logging middleware

Log every HTTP request:

```php
final class LogRequestsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $log) {}

    public function process($req, $next): ResponseInterface
    {
        $t0       = hrtime(true);
        $response = $next->handle($req);
        $ms       = round((hrtime(true) - $t0) / 1e6, 1);

        $this->log->info('{method} {path} → {status} ({ms} ms)', [
            'method' => $req->getMethod(),
            'path'   => $req->getUri()->getPath(),
            'status' => $response->getStatusCode(),
            'ms'     => $ms,
        ]);

        return $response;
    }
}

$app->use(LogRequestsMiddleware::class);
```

### Log uncaught exceptions

Already shown in [Error handling](errors), but for completeness:

```php
$app->onError(function (\Throwable $e, Request $req) use ($app) {
    if (!$e instanceof \Lift\Exception\HttpException) {
        $app->logger()->error($e->getMessage(), [
            'method'    => $req->getMethod(),
            'path'      => $req->getUri()->getPath(),
            'exception' => $e,
        ]);
    }
    // … return response
});
```

## Log rotation

### Built-in: RotatingFileHandler

`RotatingFileHandler` creates a new file each day and optionally prunes old ones.

```php
use Lift\Log\Handler\RotatingFileHandler;
use Lift\Log\Formatter\JsonFormatter;

new RotatingFileHandler(
    path:      storage_path('logs/app.log'),   // base path
    minLevel:  'info',
    formatter: new JsonFormatter(),
    maxFiles:  30,    // keep 30 days; 0 = keep forever
)
```

Files are named by inserting the date before the extension:

```
storage/logs/app.log          ← base path (not created itself)
storage/logs/app-2026-05-15.log   ← today
storage/logs/app-2026-05-14.log   ← yesterday
…
```

The handler opens the correct file lazily on the first write of each day — safe for long-running workers and queue processes. When `maxFiles > 0`, files beyond the limit are deleted automatically after each rotation.

### External rotation (alternative)

Use `logrotate` with `copytruncate` when you prefer OS-level rotation:

```
/var/log/myapp.log {
    daily
    rotate 14
    missingok
    notifempty
    copytruncate
    compress
}
```

## Shipping logs to a third party

Wrap the third party's SDK in a custom handler:

```php
use Lift\Log\Handler\HandlerInterface;

final class SentryHandler implements HandlerInterface
{
    public function __construct(private readonly \Sentry\State\HubInterface $sentry) {}

    public function handle(string $level, string $message, array $context): void
    {
        // only ship errors+
        if (!in_array($level, ['error', 'critical', 'alert', 'emergency'], true)) {
            return;
        }
        if (isset($context['exception'])) {
            $this->sentry->captureException($context['exception']);
        } else {
            $this->sentry->captureMessage($message);
        }
    }
}

$app->singleton(Logger::class, fn() => new Logger([
    new FileHandler('/var/log/myapp.log', 'info', new JsonFormatter()),
    new SentryHandler(Sentry\SentrySdk::getCurrentHub()),
]));
```

The framework stays dependency-free; you opt into Sentry / Datadog / Loki / etc. via custom handlers.

## Security

- **Never log passwords, tokens, JWTs, API keys** — even at debug level. Logs get archived, shared, leaked.
- **Mask emails / PII** before passing to `context`:
  ```php
  $log->info('Signup', ['email_hash' => hash('sha256', $email)]);
  ```
- **`Cookie` and `Authorization` headers**: redact them from request-logging middleware. The [Debug toolbar](debug) does this automatically.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Logs go nowhere | No handler configured | Default is `[StdoutHandler]` if `[]` is passed; verify your wiring. |
| `Permission denied` on log file | Web-server user can't write | `chown www-data:www-data storage/logs/` + dir `0755`. |
| `{user_id}` literally in output | The key wasn't in `$context` (or value isn't stringable) | Add it to the context array. |
| Logger swallows the stack trace | Passed `$e->getMessage()` instead of `$e` itself | Pass `'exception' => $e`. |
| Too verbose under load | `info()` in a hot loop | Drop to `debug` and rely on level filters; or remove the call. |
| Tests pollute the real log file | Bound the production logger in tests | Replace with `new Logger([new NullHandler()])` in your `TestCase`. |

## Cheat sheet

```php
// Build
$log = new Logger([
    new FileHandler('/var/log/app.log', 'info', new JsonFormatter()),
    new StdoutHandler('warning'),
]);

// Use (PSR-3)
$log->emergency / alert / critical / error / warning / notice / info / debug ($msg, $ctx);
$log->log('error', $msg, $ctx);

// Interpolation
$log->info('User {id} did {action}', ['id' => 42, 'action' => 'login']);

// Include throwable
$log->error('Boom', ['exception' => $e]);

// Inject (PSR-3)
public function __construct(private readonly LoggerInterface $log) {}
```

[Console (CLI) →](console)
