---
layout: page
title: Security middleware
nav_order: 24
---

# Security middleware

Lift ships four production-grade security middlewares you can drop into any app:

| Middleware                  | Class                              | Solves                                          |
|-----------------------------|------------------------------------|-------------------------------------------------|
| **CORS**                    | `Lift\Middleware\CorsMiddleware`   | Cross-origin browser requests                   |
| **CSRF**                    | `Lift\Middleware\CsrfMiddleware`   | Cross-site request forgery (cookie-based auth)  |
| **Rate limiting**           | `Lift\Middleware\RateLimitMiddleware` | Abuse, brute-force, runaway clients          |
| **Security headers**        | `Lift\Middleware\SecurityHeadersMiddleware` | HSTS, CSP, X-Frame-Options, …          |

For **token-based auth (Bearer JWT)** see [JWT](jwt). For password hashing and encryption see [Crypto](crypto). For Lift's typed HTTP exceptions (401/403/429) see [Error handling](errors).

## Mental model

These are **PSR-15 middleware**. You register them once with `$app->use(...)`, and they wrap every request. Each addresses one specific attack vector — pick the ones you actually need (most APIs need CORS + rate-limit + security headers; session-cookie apps add CSRF).

---

## CORS

CORS is the browser's gate-keeper for cross-origin XHR/fetch. Without it, a page at `app.com` cannot read responses from `api.com` — period. The middleware:

1. Replies to `OPTIONS` **preflight** requests with the correct `Access-Control-*` headers.
2. Adds `Access-Control-Allow-Origin` to every real response.

### Quick start

```php
use Lift\Middleware\CorsMiddleware;

$app->use(new CorsMiddleware());                          // wildcard, no credentials
$app->use(new CorsMiddleware(origins: 'https://app.example.com'));
$app->use(new CorsMiddleware(origins: ['https://a.com', 'https://b.com']));
```

### Full configuration

```php
$app->use(new CorsMiddleware(
    origins:       ['https://app.example.com', 'https://admin.example.com'],
    methods:       ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    headers:       ['Content-Type', 'Authorization', 'X-Requested-With'],
    exposeHeaders: ['X-Total-Count', 'X-RateLimit-Remaining'],
    credentials:   true,         // allow cookies / Authorization on cross-origin
    maxAge:        7200,         // browser may cache the preflight for 2 hours
));
```

| Argument           | Default                                 | Notes                                         |
|--------------------|-----------------------------------------|-----------------------------------------------|
| `origins`          | `'*'`                                   | String, list of strings, or `'*'`             |
| `methods`          | GET/POST/PUT/PATCH/DELETE/OPTIONS       | Listed in `Allow-Methods`                     |
| `headers`          | Content-Type/Authorization/Accept/X-Requested-With | Listed in `Allow-Headers`          |
| `exposeHeaders`    | `[]`                                    | Listed in `Expose-Headers`                    |
| `credentials`      | `false`                                 | Set `true` for cookie/auth cross-origin       |
| `maxAge`           | `86400`                                 | Seconds to cache preflight on the browser     |

### Wildcard sub-domains

```php
$app->use(new CorsMiddleware(origins: '*.example.com'));
// Allows https://api.example.com, https://admin.example.com, but NOT https://example.com.
```

The wildcard matches **one** sub-domain level. List the apex separately if you need it too.

### Credentials caveat

When `credentials: true`, the browser **refuses** wildcard origins. The middleware reflects the request's `Origin` back if it matches the allow-list, and adds `Vary: Origin` so caches differentiate responses per origin.

> **Since 1.2.1:** combining `origins: '*'` with `credentials: true` throws an `InvalidArgumentException` at construction time. Reflecting an arbitrary origin alongside `Access-Control-Allow-Credentials: true` would let any site issue credentialed cross-origin requests. Always pass an explicit allow-list when credentials are enabled.

### Order matters — register CORS first

```php
$app->use(new CorsMiddleware(origins: 'https://app.com'));   // 1st
$app->use(new RateLimitMiddleware(/* … */));                 // 2nd
$app->use(new AuthMiddleware(/* … */));                      // 3rd
```

Preflight requests don't carry auth headers — if your auth middleware runs first it'll 401 them and the browser will refuse the real request. Always put CORS at the very top.

---

## CSRF

CSRF is only an issue when **the browser automatically sends credentials** (cookies, HTTP Basic) on cross-site requests. For pure JSON APIs that authenticate via `Authorization: Bearer ...`, CSRF is **not** a concern — skip this middleware.

Lift's CSRF uses the **Double-Submit Cookie** pattern: a random token is set as a cookie AND must be echoed back on mutating requests via a header or form field.

### Setup

```php
use Lift\Middleware\CsrfMiddleware;

$app->use(new CsrfMiddleware(
    secret:     $_ENV['APP_SECRET'],     // strong random secret — same across servers
    secure:     true,                    // Secure flag (require HTTPS)
    sameSite:   'Lax',                   // 'Strict' | 'Lax' | 'None'
    cookiePath: '/',
));
```

The middleware sets a `csrf_token` cookie on every response and exposes the same token via `$req->getAttribute('csrf_token')` so templates can embed it.

### How clients submit the token

Two ways — pick whichever fits the client. The middleware checks both.

#### A) Header (preferred for AJAX/SPAs)

```js
fetch('/api/posts', {
    method:  'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCookie('csrf_token'),
    },
    body:    JSON.stringify(post),
});
```

#### B) Hidden form field (traditional HTML forms)

```php
<form method="POST" action="/posts">
    <input type="hidden" name="_csrf_token" value="<?= $view->e($csrfToken) ?>">
    …
</form>
```

In a view: `$csrfToken = $req->getAttribute('csrf_token');` — share it via `$app->views()->share('csrf_token', …)` from a small bootstrap middleware.

### Safe methods

`GET`, `HEAD`, `OPTIONS`, `TRACE` are always allowed — they should be **side-effect-free**. If your app makes destructive changes on a GET, that's the bug, not CSRF protection.

### What happens on mismatch

403 JSON:

```json
{ "error": "CSRF token mismatch" }
```

### When to skip CSRF

- Pure JSON API + Bearer token auth.
- Webhook endpoints (the caller isn't a browser; the signature header is the auth).
- Static-token API keys.

For mixed apps: register CSRF globally and exclude API routes with a [route group](routing#route-groups) — apply CSRF as a group middleware, not a global one.

---

## Rate limiting

Token-bucket / fixed-window rate limit backed by [Cache](cache). The counter is just a Redis `INCR` per client per window — works across processes and servers.

### Quick start

```php
use Lift\Middleware\RateLimitMiddleware;
use Lift\Cache\RedisCache;
use Lift\Redis\RedisClient;

$app->use(new RateLimitMiddleware(
    store:         new RedisCache(new RedisClient()),
    maxRequests:   100,
    windowSeconds: 60,
));
```

This allows **100 requests per minute per IP**, returns 429 when exceeded.

### Headers it adds

Every response (allowed or 429) includes:

```
RateLimit-Limit:     100
RateLimit-Remaining: 73
RateLimit-Reset:     1715692800       (Unix timestamp when the window resets)
```

And on 429:

```
Retry-After: 60
```

### Choosing the rate-limit key

Default is `REMOTE_ADDR`. Customise with a closure:

```php
new RateLimitMiddleware(
    store:        $cache,
    maxRequests:  1000,
    windowSeconds: 3600,
    keyResolver:  function (Request $req): string {
        $user = $req->getAttribute('user');
        return $user !== null
            ? "user:{$user->id}"                          // per authenticated user
            : 'ip:' . ($req->getServerParams()['REMOTE_ADDR'] ?? 'anon');
    },
);
```

Other useful keys:

- **API key:** `"key:" . $req->getHeaderLine('X-API-Key')`
- **Endpoint-scoped:** `"ep:{$req->getUri()->getPath()}:{$ip}"` — let each endpoint have its own budget.

### Tiered limits

Apply different middleware on different route groups:

```php
$loose = new RateLimitMiddleware($cache, maxRequests: 60,  windowSeconds: 60, prefix: 'rl:loose:');
$tight = new RateLimitMiddleware($cache, maxRequests: 10,  windowSeconds: 60, prefix: 'rl:tight:');

$app->group('/api', fn($g) => /* … */)->middleware($loose);
$app->group('/api/auth', fn($g) => /* login, register, password-reset */)->middleware($tight);
```

Distinct `prefix:` keeps counters separate.

### Development without Redis

Use `ArrayCache` (per-worker only — useless behind multiple workers, but fine for `php -S`):

```php
new RateLimitMiddleware(new \Lift\Cache\ArrayCache(), maxRequests: 60, windowSeconds: 60);
```

### Behind a reverse proxy

`REMOTE_ADDR` is the proxy's IP, not the client's. Trust `X-Forwarded-For` only if you control the proxy:

```php
keyResolver: function (Request $req): string {
    $fwd = $req->getHeaderLine('X-Forwarded-For');
    $ip  = $fwd !== '' ? trim(explode(',', $fwd)[0]) : ($req->getServerParams()['REMOTE_ADDR'] ?? 'anon');
    return "ip:{$ip}";
},
```

Otherwise an attacker can spoof the header and bypass the limit.

---

## Security headers

A one-line hardening pass. Defaults are sane and conservative.

```php
use Lift\Middleware\SecurityHeadersMiddleware;

$app->use(new SecurityHeadersMiddleware());
```

That alone adds:

```
X-Content-Type-Options: nosniff
X-XSS-Protection: 1; mode=block
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Content-Security-Policy: default-src 'self'
Strict-Transport-Security: max-age=31536000; includeSubDomains
Permissions-Policy: camera=(), microphone=(), geolocation=()
```

### Tuning for real apps

The default CSP (`default-src 'self'`) blocks third-party scripts/styles/fonts. Most apps need it relaxed:

```php
$app->use(new SecurityHeadersMiddleware(
    csp:          "default-src 'self'; "
                . "script-src 'self' https://cdn.jsdelivr.net; "
                . "style-src  'self' 'unsafe-inline' https://fonts.googleapis.com; "
                . "font-src   'self' https://fonts.gstatic.com; "
                . "img-src    'self' data: https:;",
    hsts:         'max-age=31536000; includeSubDomains; preload',
    frameOptions: 'SAMEORIGIN',
    permissions:  'camera=(), microphone=(), geolocation=(self)',
));
```

| Argument        | Default                                              | Purpose                                              |
|-----------------|------------------------------------------------------|------------------------------------------------------|
| `csp`           | `default-src 'self'`                                 | Content-Security-Policy. `null` to disable.          |
| `hsts`          | `max-age=31536000; includeSubDomains`                | HSTS. **Set `null` on HTTP-only dev environments.**  |
| `frameOptions`  | `DENY`                                               | `DENY` or `SAMEORIGIN`. Click-jacking guard.         |
| `referrer`      | `strict-origin-when-cross-origin`                    | Standard PII-safe default.                           |
| `permissions`   | `camera=(), microphone=(), geolocation=()`           | Disables sensor APIs by default. `null` to skip.     |
| `noSniff`       | `true`                                               | Sends `X-Content-Type-Options: nosniff`.             |
| `xssProtect`    | `true`                                               | Legacy IE/Edge XSS auditor — harmless to leave on.   |

### HSTS warning

`Strict-Transport-Security` tells browsers *"only ever talk to me over HTTPS"*, **persistently**. If you enable it on a non-HTTPS site, browsers will refuse to load it until the header expires (potentially a year later). Always set `hsts: null` in dev:

```php
$app->use(new SecurityHeadersMiddleware(
    hsts: $app->environment() === 'production' ? 'max-age=31536000; includeSubDomains' : null,
));
```

---

## Composing a hardened stack

Typical production order (top is outermost):

```php
$app->use(new SecurityHeadersMiddleware(/* … */));       // adds headers to every response
$app->use(new CorsMiddleware(origins: [...]));           // handles preflight first
$app->use(new RateLimitMiddleware($cache, /* … */));     // rejects before doing real work
// $app->use(new CsrfMiddleware(...));                   // only for cookie-auth apps
$app->use(new RequestIdMiddleware());                    // your own; assigns X-Request-Id
$app->use(new LoggingMiddleware($log));
// — your routes —
```

Auth and validation middlewares attach **per-route or per-group**, not globally, so unauthenticated routes (`/health`, `/login`) stay reachable.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Browser CORS error even though you set `origins: '*'` | You also set `credentials: true` | Pick: wildcard origin OR credentials; the spec forbids both. |
| 403 "CSRF token mismatch" on every form POST | Cookie set on `Secure` but tested over HTTP | Drop `secure: true` for dev; use HTTPS in prod. |
| Rate limit doesn't apply across servers | Using `ArrayCache` in production | Switch to `RedisCache` for shared state. |
| Site stuck unreachable after HSTS slip | Enabled HSTS on HTTP | Disable HSTS server-side, then wait for `max-age` to expire on each browser. |
| CSP blocks inline `<script>` | Default-src includes `'self'` only | Add `'unsafe-inline'` (bad) or use script nonces / hashes (better). |
| Preflight returns 405 | Auth middleware is **before** CORS and rejects OPTIONS | Move CORS to the top of `$app->use(...)` order. |

## Cheat sheet

```php
// Pick what you need; order = outermost first
$app->use(new SecurityHeadersMiddleware());
$app->use(new CorsMiddleware(origins: ['https://app.com']));
$app->use(new RateLimitMiddleware($cache, 100, 60));
$app->use(new CsrfMiddleware($_ENV['APP_SECRET']));   // session-cookie apps only
```

[JWT →](jwt)
