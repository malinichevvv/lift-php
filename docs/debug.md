---
layout: page
title: Debug toolbar
nav_order: 34
---

# Debug toolbar

Lift ships an in-browser debugging toolbar — a docked panel that overlays your HTML pages with timings, request/response data, database queries, log messages, and uncaught-exception pages. **Off by default**; gated by `$app->debug(true)` so it can't accidentally ship to production.

> Mental model: a debug session is just three pieces — a **DebugCollector** that records info during the request, a **DebugToolbarMiddleware** that injects the rendered HTML into responses, and an **ErrorHandler** that converts exceptions into rich HTML pages instead of plain 500s.

## Enabling it

```php
use Lift\Config\Env;

$app->debug([
    'enabled'          => Env::bool('APP_DEBUG', false),     // master switch
    'toolbar'          => true,                              // render the HTML toolbar
    'position'         => 'bottom-right',                    // or 'bottom-left'
    'only_html'        => true,                              // skip JSON/text/binary responses
    'track_php_errors' => true,                              // capture warnings/notices
    'exception_pages'  => true,                              // pretty HTML 500 pages
    'hide' => [
        'headers' => ['Authorization', 'Cookie', 'Set-Cookie'],
        'params'  => ['password', 'token', 'secret'],
    ],
]);
```

Or the short form:

```php
$app->debug(true);                       // enable with defaults
$app->debug(Env::bool('APP_DEBUG', false));
```

**Always** derive `enabled` from an environment variable. Hard-coding `true` will leak source code paths, env vars, and stack traces the next time you deploy.

## What it shows

A small badge appears in the bottom corner of every HTML response. Click it to expand:

- **Request** — method, path, route name, controller, route parameters, headers (with redactions).
- **Response** — status, content type, headers, size, render time.
- **Session** — current keys/values (when a session is active).
- **Queries** — every SQL statement executed during the request, with bindings, duration, and the call site.
- **Logs** — every PSR-3 log line emitted during the request.
- **PHP errors** — captured warnings/notices that didn't escalate to exceptions.
- **Timing** — boot, dispatch, render time breakdown.
- **Memory** — peak / current.

The "hide" list redacts sensitive headers/params so screenshots are safe to share.

## How injection works

`DebugToolbarMiddleware` runs at the very end of the pipeline. After your handler returns, it:

1. Checks `DebugConfig::shouldInject()` (skipping HEAD, 204/304, non-HTML, `X-Debug-Toolbar: off`, …).
2. Renders the toolbar HTML from the collected data.
3. Injects it before the closing `</body>` tag of the response.

To skip the toolbar for one specific request, send the header:

```
X-Debug-Toolbar: off
```

— useful for full-page-screenshot tooling, performance benchmarks, etc.

## Exception pages

When `exception_pages: true` and an uncaught `Throwable` reaches `ErrorHandler::handle()`, Lift renders a detailed HTML page with:

- Exception class + message + status code.
- File / line of the throw, with **source-code preview** (~10 lines).
- Full stack trace, each frame expandable to its own source preview.
- Request inspection panel (same data as the toolbar).
- "Open in editor" links (`vscode://`, `phpstorm://`) on every frame.

Disable per-environment:

```php
'exception_pages' => $app->environment() === 'local',
```

For JSON APIs you usually want both `toolbar: false` and `exception_pages: false` — error responses should stay JSON. The middleware honours these flags independently.

## SQL query log

`$db->onQuery(...)` is what powers the query panel. Lift wires it automatically when debug is enabled and a `Connection` is registered. To attach manually for advanced setups:

```php
$collector = $app->container()->get(\Lift\Debug\DebugCollector::class);
$db->onQuery(fn($sql, $bindings, $ms) => $collector->addQuery($sql, $bindings, $ms));
```

Each row shows:

- SQL with `?` placeholders.
- The actual `$bindings` array.
- Execution time in ms.

## Log capture

`DebugLogHandler` is a tiny PSR-3 handler that forwards every log line into the collector. To enable:

```php
$logger = new \Lift\Log\Logger([
    new \Lift\Log\Handler\FileHandler('storage/logs/app.log'),
    new \Lift\Debug\DebugLogHandler($collector),       // only when debug is on
]);
```

In a typical bootstrap:

```php
$app->singleton(\Psr\Log\LoggerInterface::class, function () use ($app) {
    $handlers = [new FileHandler('storage/logs/app.log', 'info')];

    if ($app->container()->has(\Lift\Debug\DebugCollector::class)) {
        $handlers[] = new \Lift\Debug\DebugLogHandler(
            $app->container()->get(\Lift\Debug\DebugCollector::class),
        );
    }

    return new Logger($handlers);
});
```

## Custom collector entries

Anything in your code can record into the collector:

```php
$collector = $app->container()->get(\Lift\Debug\DebugCollector::class);
$collector->addTiming('ai.completion', 1234.5);
$collector->addContext('feature_flags', $flags);
```

These show up in the toolbar under a generic "App" panel.

## Production safety

A deployment-day checklist:

- ✅ `APP_DEBUG=false` in your prod `.env`.
- ✅ `$app->debug(Env::bool('APP_DEBUG', false))` — **never** `$app->debug(true)`.
- ✅ Sensitive headers/params are in the `hide` list (`Authorization`, `Cookie`, `password`, `token`, `secret` — Lift's defaults already cover these).
- ✅ `OPcache.save_comments = 1` is fine; the toolbar doesn't need it specifically (route attributes do).
- ❌ Don't `$_GET['debug'] = true` your way to enabling it — it leaks to logged-in users.

When debug is **disabled**:

- `DebugCollector` isn't built.
- The middleware short-circuits (no injection).
- Error pages fall through to your `$app->onError(...)` handler (or Lift's default 500 JSON).

Result: zero overhead in production.

## Performance

Enabled, the toolbar adds ~1–3 ms of overhead per request (collection + HTML render). Disabled, it adds **zero** — the middleware isn't even registered, the collector isn't constructed. Don't ship-gate on perf concerns; ship-gate on security.

## Configuration reference

```php
$app->debug([
    'enabled'           => false,     // master switch
    'toolbar'           => true,      // inject the HTML toolbar
    'position'          => 'bottom-right',  // or 'bottom-left'
    'only_html'         => true,      // skip non-HTML responses
    'track_php_errors'  => true,      // capture warnings / notices
    'exception_pages'   => true,      // render rich HTML for uncaught exceptions
    'hide' => [
        'headers' => ['Authorization', 'Cookie', 'Set-Cookie'],
        'params'  => ['password', 'password_confirmation', 'token', 'secret'],
    ],
]);
```

| Key                | Default                                        | Effect                                           |
|--------------------|------------------------------------------------|--------------------------------------------------|
| `enabled`          | `false`                                        | Master switch. Nothing else runs without it.     |
| `toolbar`          | `true`                                         | Inject the HTML panel.                           |
| `position`         | `'bottom-right'`                               | `'bottom-right'` or `'bottom-left'`.             |
| `only_html`        | `true`                                         | Skip JSON/binary responses.                      |
| `track_php_errors` | `true`                                         | Capture `E_NOTICE` / `E_WARNING`.                |
| `exception_pages`  | `true`                                         | Pretty 500 page on uncaught exceptions.          |
| `hide.headers`     | `['Authorization', 'Cookie', 'Set-Cookie']`    | Mask these request/response headers.             |
| `hide.params`      | `['password', 'token', 'secret', …]`           | Mask these query / body / route parameters.      |

## Tips

- **Behind a CDN?** Bypass the cache on debug requests (`Cache-Control: private, no-store`) — otherwise the cached page won't include your toolbar.
- **JSON API in dev?** Set `only_html: false` and the toolbar tries to inject anyway. Most teams keep `only_html: true` and just use the exception-pages feature for API debugging.
- **Slow page render?** Open the toolbar's **Timing** panel — it'll usually narrow it down to a specific middleware / handler / query.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Toolbar doesn't appear on any page | `enabled: false` (often via env) | Set `APP_DEBUG=true` in `.env`. |
| Toolbar doesn't appear on JSON endpoints | `only_html: true` (correct behaviour) | Either flip to false (rarely useful) or use exception pages instead. |
| `X-Debug-Toolbar: off` from a screenshot tool blocks debugging mid-test | Header sent unintentionally | Drop the header in test setup. |
| Sensitive header / param still visible | Not in `hide.headers`/`hide.params` list | Add it explicitly. |
| Toolbar shows zero SQL queries | DB connection wasn't built via the container (or `onQuery` wiring skipped) | Make sure the same `Connection` instance both serves your handlers and is registered with the collector. |
| Exception page leaks `.env` values | `exception_pages: true` in production | Tie it to environment, **never** hardcode `true`. |

## Cheat sheet

```php
// Enable (always env-gated!)
$app->debug([
    'enabled'         => Env::bool('APP_DEBUG', false),
    'toolbar'         => true,
    'exception_pages' => true,
]);

// Off per-request
// curl -H 'X-Debug-Toolbar: off' …

// Custom timing
$collector->addTiming('llm.call', $ms);

// Custom context panel
$collector->addContext('features', $flagsArray);

// Logger that feeds the toolbar
new Logger([
    new FileHandler('storage/logs/app.log'),
    new DebugLogHandler($collector),
]);
```

[Async (Fibers) →](async)
