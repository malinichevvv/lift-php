# Lift 1.4 Release Notes

Lift 1.4 focuses on production safety and workflow features that stay small enough for a micro-framework.

## Security And Correctness

- `SessionMiddleware` now creates a fresh request-scoped `Session` for every request. This prevents mutable session state from leaking between clients in RoadRunner, Swoole, and FrankenPHP worker mode.
- `RedisCache::expire()` lets `RateLimitMiddleware` keep Redis rate-limit counters as raw integers after `INCRBY`, while still setting a TTL on the first hit.
- Dynamic route patterns are compiled when routes are registered or route cache is built. Invalid parameter names or broken regex constraints fail before production traffic reaches them.
- `Jwt::decode()` now validates the JWT header and rejects tokens whose `alg` header does not match the configured verifier.
- `Request::fromGlobals()` enforces a configurable JSON body limit. The default is 1 MiB and oversized JSON payloads throw `PayloadTooLargeException` with HTTP status 413.
- Session cookies now support configurable `path`, `domain`, `sameSite`, `secure`, `httpOnly`, and `partitioned` options.

## Validation And Filtering

`Request::filter()` adds a lightweight input filtering pipeline before validation:

```php
$data = $request->filter([
    'email' => 'trim|lowercase',
    'price' => 'numeric_string_to_float',
])->validate([
    'email' => 'required|email',
    'price' => 'required|numeric|min:0.01',
]);
```

Filters transform input; they do not replace output escaping or SQL parameter binding.

New validation rules include `alpha_dash`, `slug`, `domain`, `port`, `timezone`, `language_code`, `country_code`, `currency_code`, `latitude`, `longitude`, `hex_color`, `base64`, `strong_password`, `file`, `mimes`, and `max_file_size`.

Nested wildcard validation now supports paths like `items.*.name` and `items.*.price`.

## Lifecycle And Bootstrap

Use lightweight lifecycle hooks for tracing, metrics, audit logging, and request IDs:

```php
$app->on('request.received', fn (Request $request) => null);
$app->on('route.matched', fn (Route $route, Request $request) => null);
$app->on('response.sending', fn (Response $response, Request $request) => null);
```

Use config-driven bootstrap steps to structure larger apps without service-provider machinery:

```php
$app->bootstrap([
    ConfigureMiddleware::class,
    ConfigureDatabase::class,
    ConfigureRoutes::class,
]);
```

Each step may be callable, invokable, or expose `bootstrap(App $app): void`.

## Route Cache CLI

The bundled CLI now includes:

```bash
lift route:cache --bootstrap=bootstrap/app.php --path=storage/framework/routes.cache.php
lift route:clear --path=storage/framework/routes.cache.php
lift route:list --json
```

`routes:list` remains available for backward compatibility.

## Production Debug Guidance

Keep debug pages disabled in production. Use explicit production renderers for public error responses:

```php
$app->debug(['enabled' => false]);
$app->onError(fn (Throwable $e, Request $request) => Response::json([
    'error' => 'Internal Server Error',
], 500));
```
