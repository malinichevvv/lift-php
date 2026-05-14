---
layout: page
title: Middleware
nav_order: 6
---

# Middleware

Middleware is a piece of code that runs **before** and/or **after** your route handler — perfect for authentication, logging, CORS, rate limiting, request mutation, response compression, and anything else that's cross-cutting.

Lift implements the **PSR-15** middleware interface, which means:

- Any third-party PSR-15 middleware works out of the box.
- Middleware you write for Lift works in Slim, Mezzio, ReactPHP, etc.

> Mental model: middlewares wrap the handler like onion layers. The request flows *down* to the handler, the response flows *up* through the same layers in reverse.

## A middleware in 12 lines

```php
use Lift\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface
    {
        $id = $req->getHeaderLine('X-Request-Id') ?: bin2hex(random_bytes(8));

        // ↓ pass control to the next layer
        $response = $next->handle($req->withAttribute('request_id', $id));

        // ↑ inspect/modify the response on the way back
        return $response->withHeader('X-Request-Id', $id);
    }
}
```

That's the entire contract. One method, four lines of "real" code (the rest is the type-safe `use` block).

## Attaching middleware

### Global — runs on every request

```php
$app->use(CorsMiddleware::class);             // class name (autowired through the container)
$app->use(new RateLimitMiddleware(60));       // pre-built instance
$app->use(RequestIdMiddleware::class);
```

You can pass a class name (Lift will resolve it via the [container](container) the first time it's needed) or an instance you built yourself. Both work; pre-built instances avoid reflection on hot paths.

### Per-route

Chain `->middleware(...)` on the route:

```php
$app->get('/secret', $handler)
    ->middleware(AuthMiddleware::class);

$app->post('/users', [UserController::class, 'store'])
    ->middleware(AuthMiddleware::class, RateLimitMiddleware::class);
```

### Per-group

Apply to a whole group at once:

```php
$app->group('/admin', function ($g) {
    $g->get('/users',    [AdminController::class, 'users']);
    $g->get('/settings', [AdminController::class, 'settings']);
})->middleware(AuthMiddleware::class, RequireAdminMiddleware::class);
```

Nested groups inherit the outer middleware *and* can add their own.

## Execution order — the onion model

```
$app->use(A);                              // outermost
$app->use(B);
$app->group('/api', fn($g) => $g
    ->get('/x', $h)
    ->middleware(C));                      // innermost

// Request lifecycle for GET /api/x:
//   A → B → C → handler
//   A ← B ← C ← response
```

Each middleware decides whether to delegate (`$next->handle($req)`) or short-circuit by returning a `Response` directly. A short-circuit means later middleware never runs — perfect for auth guards:

```php
public function process($req, $next): ResponseInterface
{
    if (! $this->validate($req->getHeaderLine('Authorization'))) {
        return Response::json(['error' => 'Unauthorized'], 401);
        // ↑ no $next->handle(…) call — pipeline stops here
    }
    return $next->handle($req);
}
```

## Constructor injection

Middleware classes go through the container, which means **they can have dependencies**:

```php
final class LogMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Psr\Log\LoggerInterface $log,
        private readonly Clock $clock,
    ) {}

    public function process($req, $next): ResponseInterface { /* ... */ }
}

$app->use(LogMiddleware::class);   // Logger and Clock autowired
```

If you pass the class name (not an instance), Lift resolves it through the container exactly once and caches the result for subsequent requests in the same process.

## Modifying request → passing data to the handler

The standard pattern: attach values to the request via PSR-7 attributes.

```php
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly UserRepository $users, private readonly Jwt $jwt) {}

    public function process($req, $next): ResponseInterface
    {
        $token = trim((string) preg_replace('/^Bearer\s+/i', '', $req->getHeaderLine('Authorization')));

        try {
            $claims = $this->jwt->decode($token);
        } catch (\Throwable) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $user = $this->users->find((int) $claims['sub']);
        if ($user === null) {
            return Response::json(['error' => 'User gone'], 401);
        }

        // ↓ attach for the handler
        return $next->handle($req->withAttribute('user', $user));
    }
}

// Read it in the handler:
$app->get('/me', fn(Request $req) => Response::json($req->getAttribute('user')))
    ->middleware(AuthMiddleware::class);
```

## Modifying response

Same idea, on the way back out:

```php
public function process($req, $next): ResponseInterface
{
    $start    = hrtime(true);
    $response = $next->handle($req);
    $ms       = (hrtime(true) - $start) / 1e6;

    return $response
        ->withHeader('Server-Timing', sprintf('total;dur=%.1f', $ms))
        ->withHeader('X-Powered-By', 'Lift');
}
```

## Built-in middleware

Lift ships with a few production-grade middlewares ready to plug in:

| Middleware                         | Solves              | Doc            |
|------------------------------------|---------------------|----------------|
| `Lift\Middleware\CorsMiddleware`   | CORS preflight + headers | [Security](security#cors) |
| `Lift\Middleware\CsrfMiddleware`   | CSRF (double-submit cookie) | [Security](security#csrf) |
| `Lift\Middleware\RateLimitMiddleware` | Token-bucket rate limit  | [Security](security#rate-limiting) |
| `Lift\Middleware\SecurityHeadersMiddleware` | HSTS, X-Frame-Options, etc. | [Security](security#security-headers) |
| `Lift\Jwt\JwtMiddleware`           | Bearer-token auth   | [JWT](jwt#middleware) |
| `Lift\Debug\DebugToolbarMiddleware` | Dev toolbar         | [Debug](debug) |
| `Lift\Http\Session\SessionMiddleware` | Session bootstrap | [Sessions](sessions) |

Most have constructors that accept config. For example:

```php
use Lift\Middleware\CorsMiddleware;

$app->use(new CorsMiddleware(
    allowedOrigins: ['https://app.example.com'],
    allowedMethods: ['GET', 'POST', 'PATCH', 'DELETE'],
    allowedHeaders: ['Content-Type', 'Authorization'],
    allowCredentials: true,
    maxAge: 86400,
));
```

## Examples

### CORS (hand-rolled, when you want maximum control)

```php
final class CorsMiddleware implements MiddlewareInterface
{
    public function process($req, $next): ResponseInterface
    {
        if ($req->getMethod() === 'OPTIONS') {
            return (new Response(204))
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
                ->withHeader('Access-Control-Max-Age', '86400');
        }

        return $next->handle($req)
            ->withHeader('Access-Control-Allow-Origin', '*');
    }
}
```

### Request logging

```php
final class LogMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Psr\Log\LoggerInterface $log) {}

    public function process($req, $next): ResponseInterface
    {
        $t0       = hrtime(true);
        $response = $next->handle($req);
        $ms       = (hrtime(true) - $t0) / 1e6;

        $this->log->info(sprintf(
            '%s %s → %d (%.1f ms)',
            $req->getMethod(),
            $req->getUri()->getPath(),
            $response->getStatusCode(),
            $ms,
        ));

        return $response;
    }
}
```

### Body-size guard

Reject requests with absurdly large bodies before they hit your handler:

```php
final class MaxBodySizeMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly int $limitBytes) {}

    public function process($req, $next): ResponseInterface
    {
        $len = (int) $req->getHeaderLine('Content-Length');
        if ($len > 0 && $len > $this->limitBytes) {
            return Response::json(['error' => 'Payload too large'], 413);
        }
        return $next->handle($req);
    }
}

$app->use(new MaxBodySizeMiddleware(2 * 1024 * 1024));  // 2 MB
```

### Compression (gzip)

```php
final class GzipMiddleware implements MiddlewareInterface
{
    public function process($req, $next): ResponseInterface
    {
        $res = $next->handle($req);
        if (!str_contains($req->getHeaderLine('Accept-Encoding'), 'gzip')) {
            return $res;
        }

        $body = (string) $res->getBody();
        if (strlen($body) < 1024) {
            return $res;  // not worth it
        }

        return $res
            ->withHeader('Content-Encoding', 'gzip')
            ->withHeader('Vary', 'Accept-Encoding')
            ->withBody(\Lift\Http\Stream::fromString(gzencode($body, 6)));
    }
}
```

### Error → JSON

A middleware can catch exceptions thrown by deeper middleware/handlers:

```php
final class JsonErrorMiddleware implements MiddlewareInterface
{
    public function process($req, $next): ResponseInterface
    {
        try {
            return $next->handle($req);
        } catch (\Lift\Exception\HttpException $e) {
            return Response::json(['error' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Server error'], 500);
        }
    }
}
```

> In most cases you don't need this — Lift's built-in error handling already converts `HttpException` subclasses + `ValidationException` to appropriate responses. Use `$app->onError(...)` for app-level handling. See [Error handling](errors).

## Anatomy of `$next->handle($req)`

The `$next` argument is a `RequestHandlerInterface` — a one-method object whose `handle(ServerRequestInterface): ResponseInterface` runs the **rest of the pipeline** starting from the next middleware. The framework builds this lazily so you never construct it yourself.

Calling `$next->handle($req)` more than once is *technically* allowed but almost always a bug (the handler would run twice). Don't.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Middleware never runs | Forgot to `$app->use(...)` or `->middleware(...)` | Register it. |
| Headers set in middleware are missing in response | You called `withHeader(...)` but didn't `return` the result | `return $response->withHeader(...);`. |
| 500 with "no response returned" | Middleware forgot to return | Always `return $next->handle($req)` or your own `Response`. |
| Auth middleware runs *after* CORS preflight fails | CORS middleware is registered after auth | Register CORS **first** (`$app->use(CorsMiddleware::class)` before everything else). |
| Same middleware adds the same header twice | Registered both globally and per-route | Pick one. |
| Closure middleware  | Lift requires `MiddlewareInterface` | Wrap your closure into a class. (Lift deliberately doesn't allow closure middleware to keep the type contract tight.) |

## Cheat sheet

```php
// Define
final class MyMiddleware implements MiddlewareInterface
{
    public function process($req, $next): ResponseInterface { /* ... */ }
}

// Attach
$app->use(MyMiddleware::class);                       // global
$app->use(new MyMiddleware($cfg));                    // global, pre-built
$app->get($p, $h)->middleware(MyMiddleware::class);   // per-route
$app->group($p, fn($g) => /* */)->middleware(MyMiddleware::class); // per-group

// Modify request / response
$req = $req->withAttribute('user', $user);
$res = $next->handle($req)->withHeader('X-Foo', 'bar');

// Short-circuit
return Response::json(['error' => 'denied'], 401);
```

[Security middleware →](security)
