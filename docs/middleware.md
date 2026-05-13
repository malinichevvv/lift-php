---
layout: page
title: Middleware
nav_order: 6
---

# Middleware

Lift uses the PSR-15 middleware interface, which means any compliant library middleware works out of the box.

## Writing middleware

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $request->getHeaderLine('Authorization');

        if (!$this->validate($token)) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        // Optionally attach data to the request for downstream use
        $request = $request->withAttribute('user', $this->resolve($token));

        return $handler->handle($request);
    }
}
```

## Attaching middleware

### Global middleware

Runs on every request, in the order added:

```php
$app->use(CorsMiddleware::class);
$app->use(new RateLimitMiddleware(60));
$app->use(LoggingMiddleware::class);
```

Pass a class name (resolved from container) or an object instance.

### Route middleware

```php
$app->get('/secret', fn() => 'ok')
    ->middleware(AuthMiddleware::class);

$app->post('/users', [UserController::class, 'store'])
    ->middleware(AuthMiddleware::class, ValidateJsonMiddleware::class);
```

### Group middleware

```php
$app->group('/admin', function ($g) {
    $g->get('/users',    [AdminController::class, 'users']);
    $g->get('/settings', [AdminController::class, 'settings']);
})->middleware(AuthMiddleware::class, AdminRoleMiddleware::class);
```

## Execution order

Middleware is executed in the order it was added (outermost first):

```
GlobalMiddleware1 → GlobalMiddleware2 → RouteMiddleware → Handler
                                                        ↓
GlobalMiddleware1 ← GlobalMiddleware2 ← RouteMiddleware ← Response
```

## Examples

### CORS

```php
class CorsMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            return (new Response(204))
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        }

        return $handler->handle($request)
            ->withHeader('Access-Control-Allow-Origin', '*');
    }
}
```

### Request logging

```php
class LogMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $log) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start    = hrtime(true);
        $response = $handler->handle($request);
        $ms       = (hrtime(true) - $start) / 1e6;

        $this->log->info(sprintf('%s %s → %d (%.1f ms)',
            $request->getMethod(),
            $request->getUri()->getPath(),
            $response->getStatusCode(),
            $ms,
        ));

        return $response;
    }
}
```

### Body parsing (JSON guard)

```php
class JsonBodyMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
            $data    = json_decode((string) $request->getBody(), true) ?? [];
            $request = $request->withParsedBody($data);
        }

        return $handler->handle($request);
    }
}
```
