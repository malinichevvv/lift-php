# Lift — The lifting PHP micro-framework

[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://php.net)
[![PSR-7](https://img.shields.io/badge/PSR-7-green)](https://www.php-fig.org/psr/psr-7/)
[![PSR-11](https://img.shields.io/badge/PSR-11-green)](https://www.php-fig.org/psr/psr-11/)
[![PSR-15](https://img.shields.io/badge/PSR-15-green)](https://www.php-fig.org/psr/psr-15/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Lift is a fast, minimal PHP micro-framework built for modern PHP 8.1+ code. It ships with a router, a PSR-11 DI container with autowiring, PSR-7 HTTP objects, and a PSR-15 middleware pipeline — everything you need and nothing you don't.

## Why Lift?

| Feature | Lift | Flight |
|---|---|---|
| PHP version | 8.1+ | 7.4+ |
| PSR-7 Request/Response | ✅ | ❌ |
| PSR-11 Container | ✅ | ❌ |
| PSR-15 Middleware | ✅ | ❌ |
| Autowiring DI | ✅ | ❌ |
| Named routes + URL generation | ✅ | ❌ |
| Type-safe handler injection | ✅ | ❌ |
| Zero extra dependencies | ✅ | ✅ |

## Installation

```bash
composer require lift-php/lift
```

## Quick Start

```php
<?php
require 'vendor/autoload.php';

use Lift\App;
use Lift\Http\Request;
use Lift\Http\Response;

$app = new App();

$app->get('/', fn() => Response::json(['message' => 'Hello, World!']));

$app->get('/users/{id}', function (Request $req) {
    return Response::json(['id' => $req->param('id')]);
});

$app->run();
```

That's it. No config files, no service providers, no magic.

---

## Routing

### Basic routes

```php
$app->get('/path',    $handler);
$app->post('/path',   $handler);
$app->put('/path',    $handler);
$app->patch('/path',  $handler);
$app->delete('/path', $handler);
$app->any('/path',    $handler);                       // all HTTP verbs
$app->map(['GET', 'POST'], '/path', $handler);         // specific verbs
```

### Route parameters

```php
$app->get('/users/{id}', function (Request $req) {
    $id = $req->param('id');         // string
    return Response::json(['id' => $id]);
});
```

Custom regex constraint:

```php
$app->get('/posts/{id:\d+}', fn(Request $req) => ['id' => (int) $req->param('id')]);
```

### Named routes & URL generation

```php
$app->get('/users/{id}', fn(Request $req) => [...])->name('users.show');

$url = $app->url('users.show', ['id' => 42]); // /users/42
```

### Route groups

```php
$app->group('/api/v1', function ($group) {
    $group->get('/users',     [UserController::class, 'index']);
    $group->post('/users',    [UserController::class, 'store']);
    $group->get('/users/{id}', [UserController::class, 'show']);
})->middleware(AuthMiddleware::class);
```

### Handler types

Lift accepts any of the following as a route handler:

```php
// Closure
$app->get('/ping', fn() => 'pong');

// [Class, method] — class is resolved from the DI container
$app->get('/users', [UserController::class, 'index']);

// Invokable class
$app->get('/status', StatusAction::class);
```

### Auto-response conversion

If a handler doesn't return a `Response`, Lift converts the return value automatically:

| Return type | Response |
|---|---|
| `array` / `object` | `application/json` |
| `string` | `text/html` |
| `null` | `204 No Content` |
| `Response` | passed through as-is |

---

## DI Container

Lift's container supports full autowiring — just type-hint your dependencies anywhere.

### Binding

```php
// Bind interface → concrete class
$app->bind(UserRepositoryInterface::class, MySQLUserRepository::class);

// Bind with factory
$app->bind(Mailer::class, fn() => new Mailer(host: 'smtp.example.com'));

// Singleton (resolved once, reused)
$app->singleton(Database::class, fn() => new Database($_ENV['DB_DSN']));

// Pre-built instance
$app->instance(Config::class, new Config(['debug' => true]));
```

### Injection in route handlers

Dependencies are resolved automatically by type hint:

```php
$app->get('/users', function (Request $req, UserRepository $repo) {
    return Response::json($repo->all());
});
```

### Constructor injection in controllers

```php
class UserController
{
    public function __construct(private readonly UserRepository $users) {}

    public function index(Request $req): array
    {
        return $this->users->all();
    }
}

$app->get('/users', [UserController::class, 'index']);
```

### Direct resolution

```php
$repo = $app->make(UserRepository::class);

// Or call any callable with injection
$result = $app->container()->call(fn(Database $db) => $db->query('SELECT 1'));
```

---

## Middleware

Middleware implements `Psr\Http\Server\MiddlewareInterface`.

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

        if (!$this->isValid($token)) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        return $handler->handle($request);
    }
}
```

### Attaching middleware

```php
// Global — runs on every request
$app->use(CorsMiddleware::class);
$app->use(new RateLimitMiddleware(60));

// Per route
$app->get('/secret', fn() => 'ok')->middleware(AuthMiddleware::class);

// Per group
$app->group('/admin', fn($g) => $g->get('/stats', fn() => [...]))
    ->middleware(AuthMiddleware::class, LogMiddleware::class);
```

---

## Request

```php
$app->get('/example', function (Request $req) {

    // Route parameters: /users/{id}
    $id = $req->param('id');

    // Query string: ?page=2
    $page = $req->query('page', 1);

    // Form / JSON body
    $name = $req->input('name');

    // Decoded JSON body (full array)
    $data = $req->json();

    // Cookies
    $session = $req->cookie('session');

    // Uploaded file
    $avatar = $req->file('avatar');

    // Helpers
    $req->isJson();          // Content-Type: application/json ?
    $req->wantsJson();       // Accept: application/json ?
    $req->isMethod('POST');  // method check

    // All PSR-7 methods are available
    $req->getHeaderLine('X-Custom');
    $req->getAttribute('user');
});
```

---

## Response

```php
// Factory methods
Response::json(['ok' => true]);
Response::json($data, 201);
Response::html('<h1>Hi</h1>');
Response::text('plain text');
Response::redirect('/login', 302);
Response::noContent();         // 204

// Fluent PSR-7 (returns new instance, immutable)
return (new Response(200))
    ->withHeader('X-Custom', 'value')
    ->withJson(['created' => true])
    ->withStatus(201);
```

---

## Error handling

```php
$app->onError(function (\Throwable $e, Request $req) {
    if ($e instanceof \Lift\Exception\NotFoundException) {
        return Response::json(['error' => 'Not found'], 404);
    }
    return Response::json(['error' => 'Server error'], 500);
});
```

`HttpException` subclasses (`NotFoundException`, `MethodNotAllowedException`) are caught automatically and converted to the correct status code if no custom handler is registered.

---

## Testing

Because `App::handle(Request $request): Response` accepts a request object and never touches `php://input` or `$_SERVER`, testing is effortless:

```php
$app = new App();
$app->get('/ping', fn() => Response::json(['pong' => true]));

$request  = new Request('GET', new Uri('http://localhost/ping'));
$response = $app->handle($request);

assert($response->getStatusCode() === 200);
```

---

## License

MIT © Lift Contributors
