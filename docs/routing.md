---
layout: page
title: Routing
nav_order: 4
---

# Routing

The router maps an incoming HTTP request (method + path) to a *handler* (function/method to run). This page covers **everything** the router can do — verbs, parameters, named routes, groups, middleware, attribute routing, and the production cache.

> Mental model: you tell the router *"when a GET hits `/users/42`, call this function"*. The router translates that to a fast lookup table at boot, then matches each request against it.

## The five basic verbs

```php
$app->get   ('/path', $handler);
$app->post  ('/path', $handler);
$app->put   ('/path', $handler);
$app->patch ('/path', $handler);
$app->delete('/path', $handler);
```

Less common ones:

```php
$app->any('/path', $handler);                        // GET POST PUT PATCH DELETE OPTIONS HEAD
$app->map(['GET', 'HEAD'], '/path', $handler);       // a specific set
```

Every `$app->get(...)` returns a `Route` object that you can fluent-chain on (`->name()`, `->middleware()`). More on that below.

## Handler types

A handler is **anything callable**. Lift accepts five flavours and treats them all the same:

```php
// 1. Closure
$app->get('/ping', fn() => 'pong');

// 2. Closure with type-hinted dependencies (autowired by the container)
$app->get('/orders', function (Request $req, OrderService $svc) {
    return $svc->all();
});

// 3. [Class::class, 'method'] — class resolved through the DI container
$app->get('/users', [UserController::class, 'index']);

// 4. Invokable class (has __invoke)
$app->get('/healthcheck', HealthcheckAction::class);

// 5. Plain function name as a string
$app->get('/old-school', 'my_handler_function');
```

For 2 and 3, Lift looks at the parameter types and pulls instances from the [container](container) automatically. You never have to "register" your controllers; they're autowired.

### What the handler can return

| Return value           | What you get back               |
|------------------------|---------------------------------|
| `Response` object      | Passed through unchanged        |
| `array` or `object`    | `Response::json(...)`           |
| `string`               | `Response::html(...)`           |
| `null`                 | `Response::noContent()` (204)   |
| anything else (scalar) | `Response::text((string) $v)`   |

So `fn() => ['ok' => true]` and `fn() => Response::json(['ok' => true])` are equivalent. Use whichever reads better.

## Route parameters

Anything inside `{...}` is captured and made available via `$req->param(...)`:

```php
$app->get('/users/{id}', function (Request $req) {
    $id = $req->param('id');     // always a string
    return ['id' => $id];
});
```

Get all params at once:

```php
$all = $req->params();           // ['id' => '42']
```

### Regex constraints

By default a `{param}` matches `[^/]+` — any character except `/`. To require a specific pattern, append `:regex`:

```php
$app->get('/posts/{id:\d+}',             $handler);  // digits only
$app->get('/files/{name:[a-z0-9-]+}',    $handler);  // slug
$app->get('/cards/{code:[A-Z]{3}-\d{4}}', $handler); // "ABC-1234"
```

A path that *doesn't* match the regex falls through to the next route (or 404 if nothing matches) — your handler is never called with a bad value.

> **WRONG**: `(?P<name>...)` style PCRE named groups — Lift uses its own placeholder syntax, not raw PCRE.
> **RIGHT**: `{name}` or `{name:pattern}`.

### Optional segments

Lift does **not** support optional path segments inside one route (`/users[/{id}]` style). Register two routes instead:

```php
$app->get('/users',       fn() => 'list');
$app->get('/users/{id}',  fn($req) => 'show ' . $req->param('id'));
```

## Named routes & URL generation

Give a route a name with `->name(...)`, then build URLs by name with `$app->url(...)`:

```php
$app->get('/users/{id}',        $h)->name('users.show');
$app->get('/articles/{slug}',   $h)->name('articles.show');

$app->url('users.show',    ['id'   => 42]);          // /users/42
$app->url('articles.show', ['slug' => 'hello']);     // /articles/hello
```

Naming convention is up to you — `users.show`, `users:show`, `users-show` all work. The convention used across the docs and the generators is `resource.action` (`users.index`, `users.show`, `users.store`, `users.update`, `users.destroy`).

If the name doesn't exist, `$app->url(...)` throws `RuntimeException`.

## Route groups

A group shares a path prefix (and optionally middleware) across many routes.

```php
$app->group('/api/v1', function ($group) {
    $group->get   ('/users',          [UserController::class, 'index']);
    $group->get   ('/users/{id:\d+}', [UserController::class, 'show']);
    $group->post  ('/users',          [UserController::class, 'store']);
    $group->put   ('/users/{id:\d+}', [UserController::class, 'update']);
    $group->delete('/users/{id:\d+}', [UserController::class, 'destroy']);
});
```

### Nested groups

```php
$app->group('/api', function ($api) {
    $api->group('/v1', function ($v1) {
        $v1->get('/ping', fn() => ['pong' => 'v1']);
    });

    $api->group('/v2', function ($v2) {
        $v2->get('/ping', fn() => ['pong' => 'v2']);
    });
});
```

### Group middleware

Middleware applied to a group runs for every route inside it (and inherits into nested groups):

```php
$app->group('/admin', function ($g) {
    $g->get('/users',    [AdminController::class, 'users']);
    $g->get('/settings', [AdminController::class, 'settings']);
})->middleware(AuthMiddleware::class, RequireAdminMiddleware::class);
```

> Order matters. Middleware listed first runs *outermost* — i.e. it sees the request before later middleware, and sees the response after later middleware.

## Per-route middleware

Attach middleware to a single route by chaining `->middleware(...)`:

```php
$app->post('/users', [UserController::class, 'store'])
    ->middleware(AuthMiddleware::class, RateLimitMiddleware::class);
```

Order of execution per request:

```
Global middleware (in order of $app->use())
  → Group middleware (outer to inner)
    → Route middleware (in declaration order)
      → Handler
    ← Route middleware
  ← Group middleware
← Global middleware
```

Each layer can short-circuit by returning a `Response` instead of calling `$handler->handle($request)`.

## 404 and 405

| Situation                                      | Exception thrown                                  | Default response |
|------------------------------------------------|---------------------------------------------------|------------------|
| URL doesn't match any route                    | `Lift\Exception\NotFoundException`                | 404 JSON         |
| URL matches a route but with the wrong method  | `Lift\Exception\MethodNotAllowedException`        | 405 JSON         |

You can customise the response with `$app->onError(...)`:

```php
use Lift\Exception\NotFoundException;
use Lift\Exception\MethodNotAllowedException;

$app->onError(function (\Throwable $e, Request $req) {
    if ($e instanceof NotFoundException) {
        return Response::html('<h1>404 — page not found</h1>', 404);
    }
    if ($e instanceof MethodNotAllowedException) {
        return Response::json(['error' => 'method not allowed'], 405);
    }
    return Response::json(['error' => 'server error'], 500);
});
```

Or hook a specific exception type:

```php
$app->onException(NotFoundException::class, fn() => Response::html('Not here.', 404));
```

Full exception list → [Error handling](errors).

## Attribute routing

Instead of declaring routes imperatively, you can attach `#[Get]`, `#[Post]`, etc. attributes to controller methods. The router will scan the classes you ask it to load.

```php
use Lift\Attribute\Get;
use Lift\Attribute\Post;
use Lift\Attribute\Middleware;
use Lift\Attribute\Group;

#[Group('/api/v1/users')]
#[Middleware(AuthMiddleware::class)]
final class UserController
{
    public function __construct(private readonly UserRepository $users) {}

    #[Get('/')]
    public function index(): array
    {
        return $this->users->all();
    }

    #[Get('/{id:\d+}')]
    public function show(Request $req): Response { /* ... */ }

    #[Post('/')]
    #[Middleware(RateLimitMiddleware::class)]
    public function store(Request $req): Response { /* ... */ }
}

// in public/index.php
$app->loadControllers(UserController::class, OrderController::class, ...);
```

Full reference: [Attribute routing](attribute-routing).

## Production: route caching

Once you have lots of routes (50+), the registration step itself is non-trivial. The router can compile your routes to a flat PHP file that OPcache will load instantly:

```php
$cache = __DIR__ . '/../storage/routes.cache.php';

$router = $app->router();   // shortcut for $app->container()->get(Router::class)

if (!$router->loadCache($cache)) {
    // First request after deploy — register normally and write the cache
    require __DIR__ . '/../routes/web.php';
    $router->writeCache($cache);
}
```

> ⚠️ **Closure handlers are silently skipped** when writing the cache. Use `[Class::class, 'method']` or invokable classes for any route you want cacheable. Closures still work, they just defeat caching.

Clear the cache on deploy (`rm storage/routes.cache.php`) and the first request will rebuild it.

## App conveniences

`$app` exposes two helpers that are useful when you need the router or need to emit a response manually:

```php
// Access the Router directly (useful for route cache, URL generation outside a request)
$app->router()->writeCache(storage_path('routes.cache.php'));
$app->router()->url('users.show', ['id' => 42]);

// Dispatch + emit — the normal case
$app->run();

// Dispatch without emitting (testing, CLI harnesses)
$response = $app->handle($request);
// … inspect $response …
$app->send($response);   // emit when ready
```

## Performance notes

- **Static routes are O(1).** A route with no `{param}` lives in a hash map keyed by path + method.
- **Dynamic routes are O(n).** A linear scan with PCRE per request. Even 100 dynamic routes resolve in a few microseconds.
- **Reflection is cached.** The router reflects each handler **once per process** and reuses the metadata across requests (huge speedup under OPcache + persistent SAPIs).
- **Named-route map is lazy.** Built once on the first `url()` call, never rebuilt unless a new route is added.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Routes register but return 404 | Web server doesn't rewrite to `index.php` | Re-check Nginx/Apache config in [Installation](installation#5-configure-your-real-web-server). |
| `{id}` arrives as `'42'` not `42` | Route params are always strings | Cast: `(int) $req->param('id')`. |
| Middleware doesn't run | Forgot `$app->use(...)` or chained `->middleware(...)` after `->name(...)` (works, but easy to forget) | Add it. Order independent of `name()`. |
| `Cannot resolve parameter $foo of type [App\X]` | Container can't find a binding *and* the class isn't autowirable | Either `$app->bind(X::class, ...)` or make the class concrete with autowirable constructor. |
| `$app->url('foo')` throws | Route `foo` was never registered with `->name('foo')` | Register it, or fix the typo. |

## Cheat sheet

```php
// Verbs
$app->get|post|put|patch|delete($path, $handler);
$app->any($path, $handler);
$app->map(['GET','POST'], $path, $handler);

// Parameters
'/users/{id}'              // unrestricted
'/users/{id:\d+}'          // regex-constrained

// Naming + URL gen
$app->get($p, $h)->name('foo');
$app->url('foo', ['id'=>1]);

// Groups
$app->group('/api', fn($g) => /* ... */)->middleware(M1::class);

// Per-route middleware
$app->get($p, $h)->middleware(M1::class, M2::class);

// Attribute routing
$app->loadControllers(UserController::class);

// Production cache
$router->loadCache($file) || ($router->writeCache($file));
```

[Request →](request)
