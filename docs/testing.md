---
layout: page
title: Testing
nav_order: 13
---

# Testing

Lift was designed for testability from day one. Two design choices make tests trivial:

1. **`App::handle($request): Response` is pure** — given a request, it returns a response, without ever touching `$_SERVER`, output buffers, or PHP headers.
2. **Everything is constructor-injected** — you can swap any service for a fake by binding it before the request fires.

On top of that, Lift ships a tiny PHPUnit base class — `Lift\Testing\TestCase` — with a fluent assertion API. You'll write integration tests for entire HTTP routes in 5 lines.

## Setup

In `composer.json`:

```json
"require-dev": {
    "phpunit/phpunit": "^11.0"
},
"autoload-dev": {
    "psr-4": { "Tests\\": "tests/" }
}
```

`phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" colors="true">
    <testsuites>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

Run with:

```bash
vendor/bin/phpunit
```

## Your first feature test

```php
<?php

namespace Tests\Feature;

use Lift\App;
use Lift\Http\Response;
use Lift\Testing\TestCase;

final class HelloTest extends TestCase
{
    protected function createApp(): App
    {
        $app = new App();
        $app->get('/hello/{name}', fn($req) => Response::json([
            'message' => 'Hello, ' . $req->param('name'),
        ]));
        return $app;
    }

    public function testItGreets(): void
    {
        $this->get('/hello/Alice')
             ->assertOk()
             ->assertJson(['message' => 'Hello, Alice']);
    }
}
```

`createApp()` is called by `setUp()` and stored in `$this->app`. Override it once per test class.

## The HTTP helpers

```php
$this->get   ('/users');
$this->post  ('/users', ['name' => 'Alice']);
$this->put   ('/users/1', ['name' => 'Bobby']);
$this->patch ('/users/1', ['name' => 'Carol']);
$this->delete('/users/1');

// Always JSON variants:
$this->getJson ('/users');                 // sends Accept: application/json, asserts 200
$this->postJson('/users', ['name' => 'A']);

// Custom headers:
$this->get('/users', ['Authorization' => 'Bearer ' . $token]);
$this->post('/orders', ['sku' => 'ABC'], ['X-Idempotency-Key' => 'k1']);
```

Body arrays are JSON-encoded automatically. To send something else, build the request manually:

```php
$req = new \Lift\Http\Request('POST', new \Lift\Http\Uri('/upload'),
    headers: ['Content-Type' => 'multipart/form-data'],
    body: \Lift\Http\Stream::fromString($rawMultipart),
);
$response = $this->app->handle($req);
```

## The assertion API

Every helper returns a `TestResponse` whose methods all chain:

```php
$this->post('/api/users', ['name' => 'Alice', 'email' => 'a@b.c'])
     ->assertCreated()
     ->assertContentType('application/json')
     ->assertHeader('Location', '/api/users/1')
     ->assertJson(['name' => 'Alice'])
     ->assertJsonHas('id')
     ->assertJsonPath('email', 'a@b.c');
```

### Status assertions

| Method                     | What it checks                       |
|----------------------------|--------------------------------------|
| `assertStatus(int $code)`  | Exact status code                    |
| `assertOk()`               | 200                                  |
| `assertCreated()`          | 201                                  |
| `assertNoContent()`        | 204                                  |
| `assertRedirect($url?)`    | 3xx, optionally with the `Location`  |
| `assertUnauthorized()`     | 401                                  |
| `assertForbidden()`        | 403                                  |
| `assertNotFound()`         | 404                                  |
| `assertUnprocessable()`    | 422                                  |

### Header assertions

| Method                                        | What it checks                              |
|-----------------------------------------------|---------------------------------------------|
| `assertHeader(string $name, ?string $value)`  | Header exists (and equals value, if given)  |
| `assertContentType(string $type)`             | `Content-Type` contains the given media type |

### Body assertions

| Method                                                   | What it checks                                                   |
|----------------------------------------------------------|------------------------------------------------------------------|
| `assertSee(string $text)`                                | Raw body **contains** the string                                 |
| `assertDontSee(string $text)`                            | Raw body **does not contain** the string                         |
| `assertJson(array $expected, bool $exact = false)`       | JSON body matches the expected pairs (partial by default)        |
| `assertJsonHas(string $key)`                             | JSON body has the dot-notated key (`'user.email'`)               |
| `assertJsonPath(string $path, mixed $expected)`          | Dot-notated path equals the expected value                       |
| `assertJsonCount(int $count, ?string $key = null)`       | Body (or `$key`) is an array of exactly `$count` items           |

### Raw accessors (escape hatches)

```php
$response = $this->get('/x');

$response->status();        // int
$response->body();          // string
$response->json();          // array (throws if non-JSON)
$response->header('X-Foo'); // ?string (first value)
$response->getResponse();   // underlying Lift\Http\Response
```

## Swapping services for fakes

The whole DI container is at your fingertips inside `createApp()`:

```php
protected function createApp(): App
{
    $app = new App();

    // Replace the real mailer with an in-memory fake
    $this->mailer = new InMemoryMailer();
    $app->instance(Mailer::class, $this->mailer);

    // Stub a third-party API client
    $app->instance(GithubClient::class, new FakeGithubClient([
        'octocat' => ['name' => 'The Cat'],
    ]));

    require __DIR__ . '/../../routes/web.php';   // your normal route registration
    return $app;
}

public function testSignupSendsEmail(): void
{
    $this->postJson('/signup', ['email' => 'a@b.c', 'password' => 'secret'])
         ->assertCreated();

    self::assertCount(1, $this->mailer->sent);
    self::assertSame('a@b.c', $this->mailer->sent[0]->to);
}
```

`$app->instance(...)` puts a *pre-built* object in the container; nothing else changes. The handler resolves your fake automatically.

## Database tests with SQLite

A real database without a real database:

```php
protected function createApp(): App
{
    $app = new App();

    $app->singleton(Connection::class, fn() => Connection::fromConfig([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));

    // Build the schema once per test:
    $db = $app->make(Connection::class);
    $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

    require __DIR__ . '/../../routes/web.php';
    return $app;
}

public function testCreateUser(): void
{
    $this->postJson('/users', ['name' => 'Alice'])
         ->assertCreated()
         ->assertJson(['id' => 1, 'name' => 'Alice']);
}
```

For larger schemas, run your migrations against the in-memory DB:

```php
(new \Lift\Database\Migrator($db, __DIR__ . '/../../database/migrations'))->migrate();
```

Each test gets a fresh `:memory:` SQLite — perfectly isolated, blazingly fast.

## Sessions and authentication in tests

Use the in-memory store and seed the session before dispatching:

```php
use Lift\Http\Session\ArraySessionStore;
use Lift\Http\Session\Session;
use Lift\Http\Session\SessionMiddleware;

protected function createApp(): App
{
    $app = new App();
    $this->session = new Session(new ArraySessionStore());
    $app->use(new SessionMiddleware($this->session));
    require __DIR__ . '/../../routes/web.php';
    return $app;
}

public function testProtectedRoute(): void
{
    // "Log the user in" by writing the user_id directly
    $this->session->set('user_id', 42);
    $this->getJson('/dashboard')->assertOk();
}
```

For JWT-protected routes, mint a token directly:

```php
$token = $this->app->make(\Lift\Jwt\Jwt::class)->encode(['sub' => 42]);
$this->get('/me', ['Authorization' => "Bearer $token"])->assertOk();
```

## Pure unit tests

For classes that have no HTTP context — services, validators, encoders — the regular PHPUnit `TestCase` (`PHPUnit\Framework\TestCase`) is the right base. No Lift involvement at all:

```php
final class PriceCalculatorTest extends \PHPUnit\Framework\TestCase
{
    public function testApplyDiscount(): void
    {
        $calc = new PriceCalculator();
        self::assertSame(80.0, $calc->apply(100.0, discount: 20));
    }
}
```

## Inspecting requests in middleware tests

Middleware is a normal class — instantiate it, hand it a request and a fake handler:

```php
public function testAuthMiddlewareRejectsMissingToken(): void
{
    $mw  = new AuthMiddleware(new Jwt(secret: 'k'));
    $req = new Request('GET', new Uri('/x'));
    $next = new class implements RequestHandlerInterface {
        public function handle(ServerRequestInterface $r): ResponseInterface { return new Response(200); }
    };

    $response = $mw->process($req, $next);
    self::assertSame(401, $response->getStatusCode());
}
```

## Tips for fast & correct tests

- **Reset state in `setUp()`**, not in test methods. Otherwise tests interfere when run individually.
- **Use `setUp()` only for things tied to `$this`** — for application-wide setup, prefer `createApp()`.
- **Avoid the network.** Stub HTTP clients, payment SDKs, etc. by binding fakes.
- **Don't share state between tests.** No static singletons, no globals. Each test rebuilds the app.
- **Test the HTTP contract** (status code, body shape, headers) rather than internal classes — the contract is what your users see.
- **Speed:** ~5 000 HTTP-level tests per minute is achievable on a typical laptop because `App::handle()` does no I/O.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Tests pass alone but fail when grouped | Shared global state (static cache, env var) | Reset in `setUp()`, or make tests self-contained. |
| `Response body is not valid JSON` | Endpoint returned HTML / empty (e.g. validation 422 with custom view) | Use `$response->body()` instead, or check `assertContentType('application/json')` first. |
| Headers not set in test | You called `withHeader` and discarded the return | Always assign back. (Same trap as in [Response](response).) |
| Auth works in browser but not in test | Browser carries cookies / CSRF token automatically; the test doesn't | Seed the session/JWT in `setUp()`, or fire a login request first. |
| `Cannot resolve parameter $cfg` at boot | Test app missed a binding you have in `public/index.php` | Move the binding into a `bootstrap.php` you call from both. |

## Cheat sheet

```php
final class FooTest extends \Lift\Testing\TestCase
{
    protected function createApp(): App
    {
        $app = new App();
        $app->instance(Mailer::class, $this->mailer = new InMemoryMailer());
        // …register routes…
        return $app;
    }

    public function test_it_works(): void
    {
        $this->postJson('/users', ['name' => 'Alice'])
             ->assertCreated()
             ->assertJson(['name' => 'Alice'])
             ->assertJsonHas('id')
             ->assertHeader('Location');
    }
}
```

[Server-Sent Events →](sse)
