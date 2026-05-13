---
layout: page
title: Routing
nav_order: 4
---

# Routing

## HTTP verbs

```php
$app->get('/path',    $handler);
$app->post('/path',   $handler);
$app->put('/path',    $handler);
$app->patch('/path',  $handler);
$app->delete('/path', $handler);
$app->any('/path',    $handler);                        // GET POST PUT PATCH DELETE OPTIONS HEAD
$app->map(['GET', 'POST'], '/path', $handler);          // specific set of verbs
```

## Route parameters

Parameters are defined with `{name}` and available via `$req->param('name')`.

```php
$app->get('/users/{id}', function (Request $req) {
    return ['id' => $req->param('id')];
});
```

### Custom regex constraint

Append `:pattern` inside the braces:

```php
$app->get('/posts/{id:\d+}',       $handler);  // digits only
$app->get('/files/{name:[a-z-]+}', $handler);  // lowercase + dash
```

Requests that don't match the constraint will fall through to a 404 instead of reaching the handler.

## Named routes & URL generation

```php
$app->get('/users/{id}', [UserController::class, 'show'])->name('users.show');
$app->get('/articles/{slug}', $handler)->name('articles.show');

// Generate URLs
$url = $app->url('users.show', ['id' => 42]);         // /users/42
$url = $app->url('articles.show', ['slug' => 'hello']); // /articles/hello
```

## Route groups

Groups let you share a prefix and/or middleware across multiple routes.

```php
$app->group('/api/v1', function ($group) {
    $group->get('/users',      [UserController::class, 'index']);
    $group->post('/users',     [UserController::class, 'store']);
    $group->get('/users/{id}', [UserController::class, 'show']);
    $group->put('/users/{id}', [UserController::class, 'update']);
    $group->delete('/users/{id}', [UserController::class, 'destroy']);
});
```

### Nesting groups

```php
$app->group('/api', function ($api) {
    $api->group('/v1', function ($v1) {
        $v1->get('/ping', fn() => ['pong' => true]);
    });
    $api->group('/v2', function ($v2) {
        $v2->get('/ping', fn() => ['pong' => 'v2']);
    });
});
```

### Group middleware

```php
$app->group('/admin', function ($g) {
    $g->get('/users',    [AdminController::class, 'users']);
    $g->get('/settings', [AdminController::class, 'settings']);
})->middleware(AuthMiddleware::class, AdminMiddleware::class);
```

## Handler types

### Closure

```php
$app->get('/ping', fn() => 'pong');
$app->get('/me',   function (Request $req) { return [...]; });
```

### Controller method

The controller class is resolved from the DI container (constructor autowired):

```php
$app->get('/users', [UserController::class, 'index']);
```

### Invokable class

A class with `__invoke` is resolved from the container and called:

```php
class GetUserAction
{
    public function __construct(private readonly UserRepository $users) {}
    public function __invoke(Request $req): array { return $this->users->find($req->param('id')); }
}

$app->get('/users/{id}', GetUserAction::class);
```

## 404 / 405 handling

Unmatched routes throw `Lift\Exception\NotFoundException` (404).  
Matched path but wrong method throws `Lift\Exception\MethodNotAllowedException` (405).

Both extend `Lift\Exception\HttpException` and are caught automatically unless you register a custom error handler.

```php
$app->onError(function (\Throwable $e, Request $req) {
    if ($e instanceof \Lift\Exception\NotFoundException) {
        return Response::html('<h1>404 Not Found</h1>', 404);
    }
    return Response::json(['error' => 'Server error'], 500);
});
```
