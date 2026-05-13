---
layout: page
title: DI Container
nav_order: 5
---

# DI Container

Lift ships with a PSR-11 compliant container that supports autowiring, singletons, and interface-to-implementation bindings.

## Binding

### `bind()` — fresh instance every call

```php
$app->bind(Mailer::class, fn() => new Mailer(host: 'smtp.example.com'));

// Or bind interface → concrete class (class is autowired)
$app->bind(UserRepositoryInterface::class, MySQLUserRepository::class);
```

### `singleton()` — resolve once, reuse

```php
$app->singleton(Database::class, fn() => new Database($_ENV['DB_DSN']));

// No factory? Autowired and cached
$app->singleton(UserRepository::class);
```

### `instance()` — pre-built object

```php
$app->instance(Config::class, new Config(['debug' => true, 'env' => 'prod']));
```

## Autowiring

Any class with type-hinted constructor parameters is resolved automatically.

```php
class OrderService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly Mailer $mailer,
    ) {}
}

// Lift resolves OrderRepository and Mailer automatically
$service = $app->make(OrderService::class);
```

## Injection in route handlers

Type-hint any container-registered type in a closure or controller method:

```php
$app->get('/orders', function (Request $req, OrderService $svc) {
    return $svc->all();
});
```

The `Request` object is always available without registration.

## Direct resolution

```php
$repo = $app->make(UserRepository::class);

// With explicit overrides
$svc = $app->make(ReportService::class, ['month' => 5]);
```

## `call()` — inject any callable

```php
$container = $app->container();

$result = $container->call(fn(Database $db) => $db->query('SELECT 1'));
$result = $container->call([SomeClass::class, 'method'], ['extra' => 'arg']);
```

## `has()` — check registration

```php
$app->container()->has(UserRepository::class); // true if bound or autowirable
```

## PSR-11 compliance

The container implements `Psr\Container\ContainerInterface` and throws the correct PSR-11 exceptions:

- `Lift\Exception\ContainerNotFoundException` (implements `NotFoundExceptionInterface`)
- `Lift\Exception\ContainerException` (implements `ContainerExceptionInterface`)

It can be passed to any PSR-11-compatible library.
