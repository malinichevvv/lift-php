---
layout: page
title: Quick Start
nav_order: 3
---

# Quick Start

By the end of this page you will have built a small JSON API with several routes, parameter validation, dependency injection, and a controller class — using nothing but what ships with Lift.

We'll start with a literal one-liner and slowly grow it into something you'd recognise from a real service.

## Stage 0 — Hello, World

If you finished [Installation](installation), `public/index.php` already looks like this:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Lift\App;
use Lift\Http\Response;

$app = new App();

$app->get('/', fn() => Response::json(['message' => 'Hello, World!']));

$app->run();
```

Run it:

```bash
php -S 127.0.0.1:8000 -t public
curl http://127.0.0.1:8000/
# {"message":"Hello, World!"}
```

Three things to notice:

1. `new App()` — no factory, no builder. The app is just an object.
2. `$app->get('/', $handler)` — `$handler` can be **anything callable**: a closure, `[Class::class, 'method']`, or an invokable class name.
3. `Response::json([...])` is one of several factory shortcuts. We'll cover all of them in [Response](response).

> If your handler returns an `array`, Lift auto-wraps it in `Response::json(...)`. So `fn() => ['ok' => true]` works too — see [auto-response conversion](response#auto-conversion). For the rest of the tutorial we'll be explicit and use `Response::json(...)`.

## Stage 1 — Multiple routes

```php
$app->get('/',          fn() => Response::json(['message' => 'Hello, World!']));
$app->get('/health',    fn() => Response::json(['ok' => true]));
$app->get('/version',   fn() => Response::json(['version' => '1.0.0']));

// POST / PUT / PATCH / DELETE all work the same way
$app->post('/echo', function (\Lift\Http\Request $req) {
    return Response::json(['you_sent' => $req->json()]);
});
```

Quick test:

```bash
curl -X POST -H 'Content-Type: application/json' \
     -d '{"foo":"bar"}' \
     http://127.0.0.1:8000/echo
# {"you_sent":{"foo":"bar"}}
```

Notice the closure parameter `\Lift\Http\Request $req`. Lift **automatically injects** the current request into any handler that asks for one. You don't have to pass it manually.

## Stage 2 — Route parameters

Anything inside `{...}` becomes a parameter you can read with `$req->param(...)`:

```php
$app->get('/users/{id}', function (\Lift\Http\Request $req) {
    return Response::json([
        'id' => $req->param('id'),
    ]);
});
```

```bash
curl http://127.0.0.1:8000/users/42
# {"id":"42"}
```

Note that `id` arrives as a **string** — that's what HTTP gives you. Cast it yourself:

```php
$id = (int) $req->param('id');
```

You can also constrain a parameter with a regex pattern. The colon separates the name from the pattern:

```php
$app->get('/posts/{id:\d+}',       $handler);   // digits only
$app->get('/articles/{slug:[a-z0-9-]+}', $handler); // lowercase + dashes
```

If the URL doesn't match the pattern, Lift returns 404 — the handler is never called.

## Stage 3 — A tiny in-memory REST API

Let's build something close to a real CRUD endpoint, using just a PHP array as the "database":

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Lift\App;
use Lift\Http\Request;
use Lift\Http\Response;

$app = new App();

/** @var array<int, array{id:int, name:string}> $users */
$users = [
    1 => ['id' => 1, 'name' => 'Alice'],
    2 => ['id' => 2, 'name' => 'Bob'],
];
$nextId = 3;

// List
$app->get('/users', fn() => Response::json(array_values($users)));

// Show
$app->get('/users/{id:\d+}', function (Request $req) use (&$users) {
    $id = (int) $req->param('id');
    if (!isset($users[$id])) {
        return Response::json(['error' => 'User not found'], 404);
    }
    return Response::json($users[$id]);
});

// Create
$app->post('/users', function (Request $req) use (&$users, &$nextId) {
    $name = $req->json()['name'] ?? null;
    if (!is_string($name) || $name === '') {
        return Response::json(['error' => 'name is required'], 422);
    }
    $id = $nextId++;
    $users[$id] = ['id' => $id, 'name' => $name];
    return Response::json($users[$id], 201);
});

// Update
$app->put('/users/{id:\d+}', function (Request $req) use (&$users) {
    $id = (int) $req->param('id');
    if (!isset($users[$id])) {
        return Response::json(['error' => 'User not found'], 404);
    }
    $users[$id]['name'] = $req->json()['name'] ?? $users[$id]['name'];
    return Response::json($users[$id]);
});

// Delete
$app->delete('/users/{id:\d+}', function (Request $req) use (&$users) {
    unset($users[(int) $req->param('id')]);
    return Response::noContent(); // 204
});

$app->run();
```

Try it:

```bash
curl    http://127.0.0.1:8000/users
curl    http://127.0.0.1:8000/users/1
curl -X POST   -H 'Content-Type: application/json' -d '{"name":"Carol"}' http://127.0.0.1:8000/users
curl -X PUT    -H 'Content-Type: application/json' -d '{"name":"Bobby"}' http://127.0.0.1:8000/users/2
curl -X DELETE http://127.0.0.1:8000/users/1
```

Things you may have missed:

- `$req->json()` returns the decoded JSON body as an associative array. Always.
- `Response::json($data, 201)` lets you set a custom status code.
- `Response::noContent()` is a shortcut for HTTP 204.
- `Response::json(['error' => ...], 422)` is the conventional shape for validation errors.

## Stage 4 — Validation, the easy way

Hand-rolling `if (!$name)` checks gets old fast. Lift has a validator. The shortest way to use it is `$req->validate([...])`:

```php
use Lift\Validation\ValidationException;

$app->post('/users', function (Request $req) use (&$users, &$nextId) {
    try {
        $data = $req->validate([
            'name'  => 'required|string|min:2|max:255',
            'email' => 'required|email',
        ]);
    } catch (ValidationException $e) {
        return Response::json(['errors' => $e->errors()], 422);
    }

    $id = $nextId++;
    $users[$id] = ['id' => $id, ...$data];
    return Response::json($users[$id], 201);
});
```

You don't actually need the `try/catch`: if a `ValidationException` escapes a handler, Lift's default error handler converts it to a 422 with the same `errors` shape. The full rule list lives in [Validation](validation).

## Stage 5 — Controllers and dependency injection

Closures in `index.php` are fine for 10 routes. Past that, you'll want classes. Lift's container will instantiate them and inject their dependencies automatically.

```
my-app/
├── public/index.php
└── src/
    ├── UserRepository.php
    └── UserController.php
```

`src/UserRepository.php`:

```php
<?php
namespace App;

final class UserRepository
{
    /** @var array<int, array{id:int, name:string}> */
    private array $users = [
        1 => ['id' => 1, 'name' => 'Alice'],
        2 => ['id' => 2, 'name' => 'Bob'],
    ];
    private int $nextId = 3;

    public function all(): array          { return array_values($this->users); }
    public function find(int $id): ?array { return $this->users[$id] ?? null; }

    public function create(array $data): array
    {
        $id = $this->nextId++;
        return $this->users[$id] = ['id' => $id, ...$data];
    }
}
```

`src/UserController.php`:

```php
<?php
namespace App;

use Lift\Http\Request;
use Lift\Http\Response;

final class UserController
{
    // 👇 Lift autowires this via the container — you never construct UserController yourself.
    public function __construct(private readonly UserRepository $users) {}

    public function index(): array
    {
        return $this->users->all();
    }

    public function show(Request $req): Response
    {
        $user = $this->users->find((int) $req->param('id'));
        return $user
            ? Response::json($user)
            : Response::json(['error' => 'Not found'], 404);
    }

    public function store(Request $req): Response
    {
        $data = $req->validate([
            'name'  => 'required|string|min:2',
            'email' => 'required|email',
        ]);
        return Response::json($this->users->create($data), 201);
    }
}
```

Tell Composer about the namespace — add this to `composer.json` and run `composer dump-autoload`:

```json
"autoload": {
    "psr-4": { "App\\": "src/" }
}
```

Finally, wire the routes in `public/index.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Lift\App;
use App\UserController;
use App\UserRepository;

$app = new App();

// Cache the repository for the lifetime of the request
$app->singleton(UserRepository::class);

$app->get   ('/users',          [UserController::class, 'index']);
$app->get   ('/users/{id:\d+}', [UserController::class, 'show']);
$app->post  ('/users',          [UserController::class, 'store']);

$app->run();
```

That's it. Notice that:

- You never explicitly construct `UserController` or `UserRepository` — the container does it via [autowiring](container#autowiring).
- `$app->singleton(UserRepository::class)` tells the container "make one, reuse it". Without that line, you'd get a fresh repo per call (and lose your in-memory data).
- `[UserController::class, 'index']` is PHP's standard `[$class, $method]` callable. Lift resolves the class through the container, then calls the method.

## Stage 6 — Grouping routes

Most APIs version their endpoints under `/api/v1/...`. Groups handle the prefix for you:

```php
$app->group('/api/v1', function ($group) {
    $group->get   ('/users',          [UserController::class, 'index']);
    $group->get   ('/users/{id:\d+}', [UserController::class, 'show']);
    $group->post  ('/users',          [UserController::class, 'store']);
});
```

Groups also accept middleware, named-route prefixes, and can be nested. See [Routing](routing#route-groups).

## Stage 7 — Adding middleware

Say you want every response to carry a `X-Request-Id` header. Middleware is the right place.

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface
    {
        $id = $req->getHeaderLine('X-Request-Id') ?: bin2hex(random_bytes(8));
        return $next->handle($req->withAttribute('request_id', $id))
                    ->withHeader('X-Request-Id', $id);
    }
}

$app->use(RequestIdMiddleware::class);  // global — runs on every request
```

Routes can also have **per-route** or **per-group** middleware. See [Middleware](middleware).

## Stage 8 — Wiring real persistence

Swap the in-memory array for SQLite (or MySQL/Postgres) in under 20 lines:

```php
use Lift\Database\Connection;

$app->singleton(Connection::class, fn() => Connection::fromConfig([
    'driver'   => 'sqlite',
    'database' => __DIR__ . '/../database.sqlite',
]));

// In your repository:
public function __construct(private readonly Connection $db) {}

public function all(): array
{
    return $this->db->table('users')->orderBy('id')->get();
}
```

Full walkthrough including migrations: [Database](database).

## Where to go next

You now know enough to build a real CRUD service. The natural next steps:

| If you want to… | Read |
|---|---|
| Understand every routing feature | [Routing](routing) |
| Read input from forms, JSON, files | [Request](request) |
| Send HTML, set cookies, redirect | [Response](response) |
| Wire services without globals | [DI Container](container) |
| Add auth, CORS, rate limiting | [Middleware](middleware), [Security](security) |
| Validate input properly | [Validation](validation) |
| Talk to a database | [Database](database) |
| Process background jobs | [Queues](queues) |
| Write tests | [Testing](testing) |
| Deploy to production | [Installation §6](installation#6-enable-opcache-in-production) |

[Routing →](routing)
