<p align="center">
  <strong>Lift</strong> — a fast, minimal PHP micro-framework
</p>

<p align="center">
  <a href="https://packagist.org/packages/malinichevvv/lift-php"><img src="https://img.shields.io/packagist/v/malinichevvv/lift-php.svg" alt="Latest Version"></a>
  <a href="https://packagist.org/packages/malinichevvv/lift-php"><img src="https://img.shields.io/packagist/dt/malinichevvv/lift-php.svg" alt="Total Downloads"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.1%2B-blue" alt="PHP 8.1+"></a>
  <a href="https://www.php-fig.org/"><img src="https://img.shields.io/badge/PSR-7%20%E2%80%A2%2011%20%E2%80%A2%2015-green" alt="PSR-7 · 11 · 15"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-MIT-yellow.svg" alt="MIT License"></a>
</p>

<p align="center">
  <a href="https://getlift.dev"><strong>Documentation → getlift.dev</strong></a>
</p>

---

Lift is a PHP 8.1+ micro-framework built around PSR standards. It gives you a router, an autowiring DI container, PSR-7 request/response objects, and a PSR-15 middleware pipeline — everything you actually need, nothing you don't.

## Installation

```bash
composer require malinichevvv/lift-php
```

## Quick start

```php
<?php
require 'vendor/autoload.php';

use Lift\App;
use Lift\Http\Request;
use Lift\Http\Response;

$app = new App();

$app->get('/', fn() => ['message' => 'Hello, World!']);

$app->get('/users/{id:\d+}', function (Request $req) use ($repo) {
    $user = $repo->find((int) $req->param('id'));
    return $user ?? throw new \Lift\Exception\NotFoundException();
});

$app->post('/users', function (Request $req) use ($repo) {
    $data = $req->validate([
        'name'  => 'required|string|max:255',
        'email' => 'required|email',
    ]);
    return Response::json($repo->create($data), 201);
});

$app->run();
```

No config files. No service providers. No magic — just PHP.

## Starter project

Prefer a ready-made project layout over a single file? Scaffold one with Composer:

```bash
composer create-project malinichevvv/lift-skeleton myapp
cd myapp && composer serve
```

[**lift-skeleton**](https://github.com/malinichevvv/lift-skeleton) is a small URL-shortener microservice — a working example of attribute routing, request validation, migrations, a cache layer, per-route middleware, a custom console command, and feature tests.

## What's included

| Feature | |
|---|---|
| Router | Named routes, regex constraints, groups, attribute routing |
| DI Container | PSR-11, full autowiring, circular dependency detection |
| HTTP | Immutable PSR-7 Request/Response, cookies, file uploads |
| Middleware | PSR-15 pipeline, global / group / per-route |
| Database | Query builder, migrations, models, transactions, pessimistic locks |
| Validation | 60+ rules, custom messages, `FormRequest` |
| Console | CLI framework, scaffolding generators, interactive REPL |
| Views | Template engine with layouts, partials, and asset helpers |
| Queue | Sync, Database, Redis, AMQP drivers + worker |
| Cache | PSR-16, File, Redis, Database backends |
| Events | PSR-14 dispatcher |
| HTTP Client | Fluent wrapper for ext-curl |
| Auth/Crypto | JWT, HMAC, Bcrypt, AES-256-GCM |
| OpenAPI | Attribute-driven spec generation |
| SSE | Server-Sent Events response |
| Testing | Built-in `TestCase` with HTTP helpers |
| Debug | Dev toolbar with SQL, request, performance, and error tabs |

## Routing

```php
$app->get('/path',    $handler);
$app->post('/path',   $handler);
$app->put('/path',    $handler);
$app->patch('/path',  $handler);
$app->delete('/path', $handler);
$app->any('/path',    $handler);
$app->map(['GET', 'POST'], '/path', $handler);
```

Any callable works as a handler — closure, `[Class::class, 'method']`, invokable class, or function name. Dependencies are autowired from the container automatically.

```php
// Regex-constrained parameter
$app->get('/posts/{id:\d+}', fn(Request $req) => ['id' => (int) $req->param('id')]);

// Named route + URL generation
$app->get('/users/{id}', $h)->name('users.show');
$app->url('users.show', ['id' => 42]); // → /users/42

// Group with shared prefix + middleware
$app->group('/api/v1', function ($g) {
    $g->get('/users',      [UserController::class, 'index']);
    $g->post('/users',     [UserController::class, 'store']);
    $g->get('/users/{id}', [UserController::class, 'show']);
})->middleware(AuthMiddleware::class);
```

## DI Container

```php
// Bind interface → concrete class (autowired)
$app->bind(UserRepositoryInterface::class, MySQLUserRepository::class);

// Singleton factory
$app->singleton(Connection::class, fn() => Connection::fromEnv());

// Pre-built instance
$app->instance(Config::class, $config);

// Inject anywhere via type-hint — Lift resolves it automatically
$app->get('/users', function (Request $req, UserRepository $repo) {
    return $repo->all();
});
```

## Error handling

```php
use Lift\Debug\ErrorRenderer;

// Content-negotiating handler — JSON for API clients, HTML for browsers
$app->onError(ErrorRenderer::auto());

// Or custom
$app->onException(\Lift\Exception\NotFoundException::class, fn() =>
    Response::json(['error' => 'Not found'], 404)
);
```

## Testing

`App::handle(Request): Response` never touches `$_SERVER` or `php://input`, so tests need no HTTP server:

```php
use Lift\Testing\TestCase;

final class UserApiTest extends TestCase
{
    public function testCreateUser(): void
    {
        $res = $this->post('/users', ['name' => 'Alice', 'email' => 'alice@example.com']);

        $this->assertStatus(201, $res);
        $this->assertJsonContains(['name' => 'Alice'], $res);
    }
}
```

## Requirements

- PHP 8.1+
- Composer

Optional extensions unlock specific features (`ext-redis`, `ext-pcntl`, `ext-curl`, `ext-pdo`). The core framework works without any of them.

## Documentation

Full docs with examples for every feature: **[getlift.dev](https://getlift.dev)**

## License

MIT © [Vladyslav Malinichiev](https://github.com/malinichevvv)
