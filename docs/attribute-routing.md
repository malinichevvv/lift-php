---
layout: page
title: Attribute routing
nav_order: 12
---

# Attribute routing

Declarative routing using PHP 8 attributes — the same routes, but **registered next to the code that handles them**.

> Mental model: instead of listing routes in a central file, you attach `#[Get('/users')]` directly to the controller method. At boot, Lift scans the classes you point it at and registers everything in one pass.

## Quickest possible example

```php
use Lift\Attribute\Get;
use Lift\Attribute\Post;
use Lift\Http\Request;
use Lift\Http\Response;

final class UserController
{
    public function __construct(private readonly UserRepository $repo) {}

    #[Get('/users')]
    public function index(): array
    {
        return $this->repo->all();
    }

    #[Get('/users/{id:\d+}')]
    public function show(Request $req): Response
    {
        return Response::json($this->repo->find((int) $req->param('id')));
    }

    #[Post('/users')]
    public function store(Request $req): Response
    {
        $data = $req->validate(['name' => 'required|string']);
        return Response::json($this->repo->create($data), 201);
    }
}

// In public/index.php
$app->loadControllers(UserController::class);
$app->run();
```

That's it. No `$app->get(...)`, no central routes file.

## The attributes

| Attribute        | Target           | Repeatable | Purpose                                                 |
|------------------|------------------|:----------:|---------------------------------------------------------|
| `#[Get]`         | method, function | ✓          | GET route                                               |
| `#[Post]`        | method, function | ✓          | POST route                                              |
| `#[Put]`         | method, function | ✓          | PUT route                                               |
| `#[Patch]`       | method, function | ✓          | PATCH route                                             |
| `#[Delete]`      | method, function | ✓          | DELETE route                                            |
| `#[Route]`       | method, function | ✓          | Any verb (`#[Route('OPTIONS', '/x')]`)                  |
| `#[Group]`       | class            | once       | URL prefix for every method in the class                |
| `#[Middleware]`  | class, method    | ✓          | Attach middleware to a class or method                  |

All `#[Get/Post/...]` take the same arguments as their imperative counterparts:

```php
#[Get('/users/{id:\d+}', name: 'users.show')]
public function show(Request $req): Response { … }
```

The `name:` argument plugs into `$app->url('users.show', ['id' => 42])` exactly like `->name(...)`.

## Class-level URL prefix — `#[Group]`

```php
#[Group('/api/v1/users')]
final class UserController
{
    #[Get('/')]          // → GET /api/v1/users/
    public function index() { … }

    #[Get('/{id:\d+}')]  // → GET /api/v1/users/{id}
    public function show(Request $req) { … }

    #[Post('/')]         // → POST /api/v1/users/
    public function store(Request $req) { … }
}
```

A class may carry only **one** `#[Group]`. For nesting, use multiple controllers (`UserController`, `AdminUserController`).

## Middleware

Either at the class level (applies to every route in the controller) or per method (applies to that route only):

```php
use Lift\Attribute\Middleware;

#[Group('/admin')]
#[Middleware(AuthMiddleware::class)]
#[Middleware(RequireAdminMiddleware::class)]
final class AdminController
{
    #[Get('/dashboard')]
    public function dashboard() { … }

    #[Post('/users/{id:\d+}/ban')]
    #[Middleware(RateLimitMiddleware::class)]      // adds on top of class-level ones
    public function ban(Request $req) { … }
}
```

Middleware classes are resolved through the [container](container), so they can have constructor dependencies.

You can also pass an array:

```php
#[Middleware([AuthMiddleware::class, LogMiddleware::class])]
final class X { … }
```

## Several routes on one method

Both `#[Route]` and the verb-specific attributes are **repeatable** — apply more than once to map several URLs (or verbs) onto the same method:

```php
#[Get('/users/{id:\d+}')]
#[Get('/users/by-uuid/{uuid:[a-z0-9-]+}')]
public function show(Request $req): Response
{
    // Branch on which param exists
    return ...;
}

#[Route('GET',  '/widgets')]
#[Route('HEAD', '/widgets')]
public function index(): array { … }
```

## Loading controllers

```php
// One class:
$app->loadControllers(UserController::class);

// Many — chain or one call:
$app->loadControllers(
    UserController::class,
    OrderController::class,
    AdminController::class,
);
```

Tip: keep a list of controllers in a config file and splat it:

```php
$controllers = require __DIR__ . '/../config/controllers.php';
$app->loadControllers(...$controllers);
```

## Interplay with imperative routes

Attribute and imperative routes coexist freely:

```php
$app->loadControllers(UserController::class);

// Add a quick health check imperatively
$app->get('/health', fn() => ['ok' => true]);
```

Order doesn't matter; the router resolves the right one per request.

## OPcache & `save_comments`

PHP attributes are stored in class doc-comments at the bytecode level. **OPcache must keep them.** In `php.ini`:

```ini
opcache.save_comments=1
```

This is the default — but some hardened production images set `0` for "smaller bytecode". Without it, the loader sees no attributes and silently registers zero routes.

## Production cache

Attribute scanning uses reflection, which costs a few ms per controller. For dozens of controllers, combine with the [route cache](routing#production-route-caching):

```php
$cache = __DIR__ . '/../storage/routes.cache.php';
$router = $app->container()->get(\Lift\Routing\Router::class);

if (!$router->loadCache($cache)) {
    $app->loadControllers(...$controllers);
    $router->writeCache($cache);
}
```

The cache stores the resolved route table; subsequent requests skip both the controller scan and the route registration entirely.

## When *not* to use attributes

- One-off scripts or 3-route APIs — `$app->get(...)` is plainer.
- When the same handler is called from multiple frameworks — keep routes external.
- When you need conditional registration (`if ($env === 'dev') ...`) — only possible imperatively.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `$app->loadControllers(X::class)` registers zero routes | `opcache.save_comments=0`, or class has no `#[Get]/...` attributes | Fix `php.ini`; ensure attributes exist. |
| Middleware not applied | Forgot to `use Lift\Attribute\Middleware` — PHP imported the wrong `Middleware` class | Use full FQN `\Lift\Attribute\Middleware` or import explicitly. |
| Two routes registered for one method | You used both `#[Get]` and `#[Route('GET', …)]` for the same URL | Pick one. |
| Method registered as a route is `static` | Static methods are skipped by the loader | Make it instance method, or call from a non-static dispatcher. |
| `Cannot resolve parameter $foo` at boot | Controller's constructor needs a class the container can't find | `$app->bind(...)` the dependency first. |

## Cheat sheet

```php
use Lift\Attribute\{Get, Post, Put, Patch, Delete, Route, Group, Middleware};

#[Group('/api/v1')]
#[Middleware(AuthMiddleware::class)]
final class WidgetController
{
    #[Get('/widgets', name: 'widgets.index')]
    public function index() { … }

    #[Get('/widgets/{id:\d+}', name: 'widgets.show')]
    public function show(Request $req) { … }

    #[Post('/widgets')]
    #[Middleware(RateLimitMiddleware::class)]
    public function store(Request $req) { … }

    #[Delete('/widgets/{id:\d+}')]
    public function destroy(Request $req) { … }
}

$app->loadControllers(WidgetController::class);
```

[Error handling →](errors)
