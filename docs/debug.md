---
title: Debug and configuration
nav_order: 17
---

# Debug and configuration

Lift includes an optional debug layer for local development. It provides an inline HTML toolbar, request-scoped diagnostics, exception renderers, PHP error tracking, and application configuration loading from arrays, PHP files, and YAML files.

## Enable debug mode

```php
use Lift\App;

$app = new App();

$app->debug();
```

Debug mode is disabled unless you explicitly enable it.

```php
$app->debug([
    'enabled' => ($_ENV['APP_DEBUG'] ?? '0') === '1',
    'toolbar' => true,
    'position' => 'bottom-right',
    'only_html' => true,
    'track_php_errors' => true,
    'exception_pages' => true,
]);
```

## Toolbar behaviour

The toolbar is injected only when all conditions match:

- **Debug is enabled**: `enabled` is `true`.
- **Toolbar is enabled**: `toolbar` is `true`.
- **HTML response**: response `Content-Type` contains `text/html` when `only_html` is enabled.
- **Injectable status**: status is not `204` or `304`.
- **Non-HEAD request**: `HEAD` responses are never modified.
- **Not explicitly disabled**: request header `X-Debug-Toolbar: off` disables injection.

JSON, API, no-content, and redirect-style responses are left untouched.

## Hiding sensitive data

```php
$app->debug([
    'enabled' => true,
    'hide' => [
        'headers' => ['Authorization', 'Cookie', 'Set-Cookie'],
        'params' => ['password', 'password_confirmation', 'token', 'secret'],
    ],
]);
```

Hidden values are shown as `***` in toolbar details.

## Custom exception renderers

Register handlers by exception class or parent type:

```php
use Lift\Exception\NotFoundException;
use Lift\Http\Request;
use Lift\Http\Response;

$app->debug();

$app->onException(NotFoundException::class, function (NotFoundException $e, Request $request) {
    return Response::html('<h1>Page not found</h1>', 404);
});
```

You can also use the error handler directly:

```php
$app->debugErrorHandler()
    ->render(DomainException::class, fn (DomainException $e) => Response::json([
        'error' => $e->getMessage(),
    ], 409));
```

## Fallback error handler

The existing `onError()` API still works. When debug mode is active, it becomes the fallback after exception-specific renderers.

```php
$app->onError(function (Throwable $e, Request $request) {
    return Response::json(['error' => 'Something went wrong'], 500);
});
```

Default Lift behaviour is preserved when no custom renderer or fallback is registered:

- **ValidationException**: returns `422` JSON with validation errors.
- **HttpException**: returns JSON with the exception status code.
- **Other exceptions**: return a debug HTML page for HTML requests in debug mode, otherwise `500` JSON.

## PHP error tracking

When `track_php_errors` is enabled, Lift records warnings/notices in the debug collector. Previous PHP error handlers are preserved and called after Lift records the error.

```php
$app->debug([
    'enabled' => true,
    'track_php_errors' => true,
]);
```

You can restore the previous PHP handler manually:

```php
$app->debugErrorHandler()->restorePhpHandlers();
```

## Configuration from arrays

```php
$app->config([
    'app' => [
        'debug' => true,
        'name' => 'Example',
    ],
]);

$name = $app->configuration()->get('app.name');
```

## Configuration from PHP files

A PHP config file must return an array:

```php
return [
    'debug' => [
        'enabled' => true,
    ],
];
```

Load it with:

```php
$app->config(__DIR__ . '/config/app.php');
$app->debug($app->configuration()->get('debug', []));
```

## Configuration from YAML files

YAML files require the `ext-yaml` PHP extension.

```yaml
debug:
  enabled: true
  toolbar: true
  position: bottom-right
```

```php
$app->config(__DIR__ . '/config/app.yaml');
$app->debug($app->configuration()->get('debug', []));
```

## Security notes

- **Do not enable debug mode in production.**
- **Never expose exception pages to untrusted users.**
- **Always mask secrets** such as tokens, cookies, passwords, and API keys.
- **Prefer environment-based enabling** with `APP_DEBUG=1` only in local development.
