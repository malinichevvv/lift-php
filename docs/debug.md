---
layout: page
title: Debug Toolbar
nav_order: 17
---

# Debug Toolbar

Lift includes a request-scoped debug toolbar for local development. It renders as a minimal bar at the bottom of any HTML page and expands into a full panel with tabs for request/response details, SQL queries, log entries, performance metrics, and errors.

---

## Enabling debug mode

```php
$app = new App();
$app->debug(['enabled' => true]);
```

By convention, read the flag from the environment:

```php
$app->debug(['enabled' => ($_ENV['APP_DEBUG'] ?? '0') === '1']);
```

Debug mode is **off by default**. Nothing is installed unless you call `debug()`.

---

## All configuration options

Pass an array to `$app->debug()`. Every key is optional; the defaults are shown below.

```php
$app->debug([
    // Master switch — must be true for anything else to work.
    'enabled' => false,

    // Inject the HTML toolbar into HTML responses.
    'toolbar' => true,

    // Only inject into responses whose Content-Type contains text/html.
    'only_html' => true,

    // Register a set_error_handler() to capture PHP warnings / notices.
    'track_php_errors' => true,

    // Render pretty exception pages instead of plain 500 responses.
    'exception_pages' => true,

    // Mask these HTTP header names in the toolbar (shown as ***).
    'hide' => [
        'headers' => ['Authorization', 'Cookie', 'Set-Cookie'],
        'params'  => ['password', 'password_confirmation', 'token', 'secret', 'api_key'],
    ],
]);
```

### Option reference

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | bool | `false` | Master on/off switch. |
| `toolbar` | bool | `true` | Render the HTML debug bar. Requires `enabled`. |
| `only_html` | bool | `true` | Skip injection when `Content-Type` is not `text/html`. |
| `track_php_errors` | bool | `true` | Capture PHP warnings, notices, and deprecations. Previous error handlers are called after Lift records the error. |
| `exception_pages` | bool | `true` | Render detailed exception pages with stack traces. |
| `hide.headers` | string[] | `['Authorization','Cookie','Set-Cookie']` | Request/response headers whose values are replaced with `***`. Case-insensitive. |
| `hide.params` | string[] | `['password','password_confirmation','token','secret','api_key']` | Query and route parameters whose values are replaced with `***`. Case-insensitive. |

---

## Toolbar injection rules

The toolbar is injected only when **all** of the following hold:

- `enabled` is `true`
- `toolbar` is `true`
- The response has a `</body>` tag to inject before
- Response status is not `204` or `304`
- The request method is not `HEAD`
- The request does **not** carry the header `X-Debug-Toolbar: off`
- When `only_html` is `true` — `Content-Type` header contains `text/html`

JSON, API, redirect, and no-content responses are always left untouched.

---

## Toolbar panels

The toolbar mini-bar shows: **method · URI · status · duration · memory · SQL count · log count · error count**. Click to expand the full panel.

### Request tab

Shows method, URI, query parameters, route parameters, and request headers. Sensitive values are masked per `hide`.

### Response tab

Shows status code and response headers. Sensitive headers are masked.

### SQL tab

Lists every SQL query executed during the request, with:
- The SQL statement
- Bound parameter values
- Execution time in milliseconds

Slow queries are highlighted: **orange** for > 50 ms, **red** for > 200 ms.

> The SQL tab is empty unless you wire a query listener at bootstrap. See [Wiring the SQL tab](#wiring-the-sql-tab) below.

### Logs tab

Lists all PSR-3 log entries recorded during the request, grouped by level with color coding (`debug` grey, `info` blue, `warning` yellow, `error`/`critical`/`alert`/`emergency` red).

> The Logs tab is empty unless you add `DebugLogHandler` to your logger. See [Wiring the Logs tab](#wiring-the-logs-tab) below.

### Performance tab

Shows total request duration in milliseconds and peak PHP memory usage in MB.

### Errors tab

Appears when PHP errors or uncaught exceptions were recorded. Shows the error class, message, file, and line number.

---

## Wiring the SQL tab

The debug collector is completely decoupled from the database layer. Wire them with `Connection::onQuery()` at bootstrap:

```php
use Lift\Database\Connection;
use Lift\Debug\DebugCollector;

$db        = new Connection($dsn);
$collector = $app->container()->make(DebugCollector::class);

// Every query — including those from the QueryBuilder and raw methods — will
// now be recorded with its SQL, bindings, and execution time.
$db->onQuery([$collector, 'recordQuery']);
```

After wiring, the SQL tab will show all queries for each request.

### Multiple connections

You can wire any number of connections:

```php
$db->onQuery([$collector, 'recordQuery']);
$readDb->onQuery([$collector, 'recordQuery']);
```

---

## Wiring the Logs tab

Add `DebugLogHandler` to your logger at bootstrap:

```php
use Lift\Debug\DebugCollector;
use Lift\Debug\DebugLogHandler;
use Lift\Log\Logger;
use Psr\Log\LogLevel;

$collector = $app->container()->make(DebugCollector::class);

$logger = new Logger([
    new FileHandler('/var/log/app.log'),                // your production handler
    new DebugLogHandler($collector),                    // debug toolbar handler
]);
```

`DebugLogHandler` accepts an optional minimum log level (default `debug`):

```php
// Only capture warnings and above in the toolbar
new DebugLogHandler($collector, LogLevel::WARNING)
```

### `DebugLogHandler` options

| Constructor parameter | Type | Default | Description |
|-----------------------|------|---------|-------------|
| `$collector` | `DebugCollector` | required | The request-scoped collector instance. |
| `$minLevel` | `string` (PSR-3 level) | `LogLevel::DEBUG` | Log entries below this level are ignored. |

---

## Full bootstrap example

This is what a production-ready bootstrap looks like with all debug integrations active:

```php
<?php
require __DIR__ . '/../vendor/autoload.php';

use Lift\App;
use Lift\Database\Connection;
use Lift\Debug\DebugCollector;
use Lift\Debug\DebugLogHandler;
use Lift\Log\Logger;
use Lift\Log\Handler\FileHandler;
use Lift\Config\Env;

$app = new App();
$app->loadEnv(__DIR__ . '/../.env');

// ① Enable debug mode from environment
$app->debug([
    'enabled'           => Env::bool('APP_DEBUG', false),
    'toolbar'           => true,
    'track_php_errors'  => true,
    'exception_pages'   => true,
    'hide' => [
        'headers' => ['Authorization', 'Cookie', 'Set-Cookie'],
        'params'  => ['password', 'token', 'secret'],
    ],
]);

// ② Wire database → collector (only when debug is on)
$db = new Connection($dsn);
$app->instance(Connection::class, $db);

if (Env::bool('APP_DEBUG', false)) {
    $collector = $app->container()->make(DebugCollector::class);
    $db->onQuery([$collector, 'recordQuery']);
}

// ③ Wire logger → collector
$logger = new Logger([
    new FileHandler('/var/log/app.log'),
    ...(Env::bool('APP_DEBUG', false)
        ? [new DebugLogHandler($app->container()->make(DebugCollector::class))]
        : []),
]);
$app->instance(Logger::class, $logger);
```

---

## Disabling the toolbar per-request

Send `X-Debug-Toolbar: off` in the request to suppress toolbar injection for that request only. Useful for AJAX calls inside an HTML page:

```js
fetch('/api/data', { headers: { 'X-Debug-Toolbar': 'off' } })
```

---

## Custom exception renderers

Register handlers by exception class or base type. They take priority over the default exception pages:

```php
use Lift\Exception\NotFoundException;

$app->debug(['enabled' => true]);

$app->onException(NotFoundException::class, function (NotFoundException $e, Request $request) {
    return Response::html('<h1>Page not found</h1>', 404);
});
```

You can also use the error handler directly:

```php
$app->debugErrorHandler()->render(
    \DomainException::class,
    fn (\DomainException $e) => Response::json(['error' => $e->getMessage()], 409)
);
```

### Fallback handler

The `onError()` API becomes the catch-all after exception-specific renderers:

```php
$app->onError(function (Throwable $e, Request $request) {
    return Response::json(['error' => 'Something went wrong'], 500);
});
```

Built-in fallbacks when no handler is registered:

| Exception | Default response |
|-----------|-----------------|
| `ValidationException` | `422` JSON with validation errors |
| `HttpException` | JSON with the exception's status code |
| Everything else (debug on) | HTML exception page |
| Everything else (debug off) | `500` JSON |

---

## PHP error tracking

When `track_php_errors` is `true` Lift installs a `set_error_handler()` that captures warnings, notices, and deprecations into the collector. Any previously registered PHP error handler is still called after Lift records the error.

Restore the previous handler manually if needed (e.g. in tests):

```php
$app->debugErrorHandler()->restorePhpHandlers();
```

---

## Security notes

- **Never enable debug mode in production.** Exception pages expose stack traces, environment values, and SQL queries.
- **Always mask secrets** — add passwords, tokens, API keys, and cookies to `hide.headers` / `hide.params`.
- **Prefer environment-based enabling** so debug mode is only active on developer machines.

```dotenv
# .env (local only)
APP_DEBUG=true
```
