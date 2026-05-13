---
layout: page
title: Security Middleware
nav_order: 9
---

# Security Middleware

Lift ships four production-ready security middleware classes. All are zero-dependency and use built-in PHP + OpenSSL primitives.

---

## CORS

`Lift\Middleware\CorsMiddleware` handles Cross-Origin Resource Sharing, including automatic `OPTIONS` preflight responses.

```php
use Lift\Middleware\CorsMiddleware;

$app->use(new CorsMiddleware(
    origins:     ['https://app.example.com', 'https://admin.example.com'],
    methods:     ['GET', 'POST', 'PUT', 'DELETE'],
    headers:     ['Content-Type', 'Authorization'],
    credentials: true,
    maxAge:      3600,
));
```

### Options

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `origins` | `string\|array` | `'*'` | Allowed origins. Supports wildcard subdomain: `'*.example.com'` |
| `methods` | `string[]` | common verbs | Allowed HTTP methods |
| `headers` | `string[]` | common headers | Allowed request headers |
| `credentials` | `bool` | `false` | Allow cookies / `Authorization` header in cross-origin requests |
| `maxAge` | `int` | `0` | Preflight cache duration in seconds |

### Wildcard subdomains

```php
new CorsMiddleware(origins: '*.example.com')
// Allows: api.example.com, app.example.com, etc.
```

### Credentials mode

When `credentials: true`, the `Origin` is always echoed (never `*`) as required by the browser security model.

---

## Security Headers

`Lift\Middleware\SecurityHeadersMiddleware` adds a suite of hardening headers to every response.

```php
use Lift\Middleware\SecurityHeadersMiddleware;

$app->use(new SecurityHeadersMiddleware(
    csp:              "default-src 'self'; script-src 'self'",
    hsts:             'max-age=31536000; includeSubDomains',
    frameOptions:     'DENY',
    referrerPolicy:   'strict-origin-when-cross-origin',
    permissionsPolicy: 'geolocation=(), microphone=()',
));
```

Pass `null` for any header to omit it.

### Headers set by default

| Header | Default value |
|--------|--------------|
| `Content-Security-Policy` | `default-src 'self'` |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` |
| `X-Frame-Options` | `SAMEORIGIN` |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `geolocation=(), microphone=(), camera=()` |
| `X-XSS-Protection` | `1; mode=block` |

---

## Rate Limiting

`Lift\Middleware\RateLimitMiddleware` implements a fixed-window counter using any `CacheInterface` store.

```php
use Lift\Cache\ArrayCache;
use Lift\Cache\RedisCache;
use Lift\Middleware\RateLimitMiddleware;

// Development: in-process counter (resets on each request in CLI)
$app->use(new RateLimitMiddleware(new ArrayCache(), maxRequests: 60, windowSeconds: 60));

// Production: shared Redis counter
$app->use(new RateLimitMiddleware(
    store:         new RedisCache($redisClient),
    maxRequests:   100,
    windowSeconds: 60,
    keyResolver:   fn($req) => $req->getAttribute('user_id') ?? $req->getServerParams()['REMOTE_ADDR'],
));
```

### Response headers

Every response gets standard rate-limit headers:

```
RateLimit-Limit:     100
RateLimit-Remaining: 47
RateLimit-Reset:     1718700060
```

On limit exceeded, returns `429 Too Many Requests` with a `Retry-After` header.

### Custom key resolver

By default the key is the client's `REMOTE_ADDR`. Override to key by user, API key, or any attribute:

```php
new RateLimitMiddleware(
    store: $cache,
    maxRequests: 1000,
    windowSeconds: 3600,
    keyResolver: fn($req) => 'user:' . $req->getAttribute('jwt')['sub'],
)
```

---

## CSRF Protection

`Lift\Middleware\CsrfMiddleware` uses the **Double-Submit Cookie** pattern with HMAC-signed tokens, making tokens unforgeable even without server-side session state.

```php
use Lift\Middleware\CsrfMiddleware;

$app->use(new CsrfMiddleware(
    secret:     $_ENV['APP_SECRET'],
    cookieName: 'csrf_token',     // default
    headerName: 'X-CSRF-Token',   // default
    fieldName:  '_csrf_token',    // default for form POST
));
```

### How it works

1. On the first safe request, the middleware generates `random|HMAC(secret, random)` and sets it as a cookie.
2. On mutating requests (POST/PUT/PATCH/DELETE), the token from either the `X-CSRF-Token` header or `_csrf_token` form field is validated against the cookie.
3. Safe methods (GET/HEAD/OPTIONS/TRACE) bypass validation.
4. On success, the decoded token random is available via `$request->getAttribute('csrf_token')`.

### SPA / API usage (header-based)

```javascript
// Fetch the token from the cookie and send it in the header
const token = document.cookie.match(/csrf_token=([^;]+)/)?.[1];

fetch('/api/users', {
    method: 'POST',
    headers: { 'X-CSRF-Token': token, 'Content-Type': 'application/json' },
    body: JSON.stringify({ name: 'Alice' }),
});
```

### Traditional form usage

```html
<form method="POST" action="/submit">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <!-- fields -->
</form>
```

On failure: returns `403 Forbidden` with a JSON error body.
