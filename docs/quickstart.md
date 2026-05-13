---
layout: page
title: Quick Start
nav_order: 3
---

# Quick Start

## Hello, World

```php
<?php
require 'vendor/autoload.php';

use Lift\App;
use Lift\Http\Response;

$app = new App();

$app->get('/', fn() => Response::json(['message' => 'Hello, World!']));

$app->run();
```

## REST API example

```php
<?php
require 'vendor/autoload.php';

use Lift\App;
use Lift\Http\Request;
use Lift\Http\Response;

$app = new App();

// In-memory "database" for demonstration
$users = [
    1 => ['id' => 1, 'name' => 'Alice'],
    2 => ['id' => 2, 'name' => 'Bob'],
];

// GET /users
$app->get('/users', fn() => array_values($users));

// GET /users/1
$app->get('/users/{id:\d+}', function (Request $req) use ($users) {
    $id = (int) $req->param('id');
    if (!isset($users[$id])) {
        return Response::json(['error' => 'User not found'], 404);
    }
    return $users[$id];
});

// POST /users
$app->post('/users', function (Request $req) use (&$users) {
    $data = $req->json();
    $id   = max(array_keys($users)) + 1;
    $users[$id] = ['id' => $id, 'name' => $data['name'] ?? 'Unknown'];
    return Response::json($users[$id], 201);
});

// DELETE /users/1
$app->delete('/users/{id:\d+}', function (Request $req) use (&$users) {
    $id = (int) $req->param('id');
    unset($users[$id]);
    return Response::noContent();
});

$app->run();
```

## MVC-style with controllers and DI

```php
// src/UserRepository.php
class UserRepository
{
    public function findAll(): array { /* ... */ }
    public function find(int $id): ?array { /* ... */ }
}

// src/UserController.php
class UserController
{
    public function __construct(private readonly UserRepository $users) {}

    public function index(): array
    {
        return $this->users->findAll();
    }

    public function show(Request $req): Response
    {
        $user = $this->users->find((int) $req->param('id'));
        return $user
            ? Response::json($user)
            : Response::json(['error' => 'Not found'], 404);
    }
}

// public/index.php
$app = new App();

$app->singleton(UserRepository::class);  // autowired, cached

$app->group('/users', function ($g) {
    $g->get('/',    [UserController::class, 'index']);
    $g->get('/{id}', [UserController::class, 'show']);
});

$app->run();
```

[Routing →](routing)
