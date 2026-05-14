---
layout: page
title: DI Container
nav_order: 5
---

# DI Container

The container is the brain of a Lift app. It knows how to **construct your services** so you never have to write `new` for anything that has dependencies. It also lets you swap implementations in tests without touching production code.

> Mental model: a container is a Map<class-name, factory>. You ask `get(MyService::class)`, it figures out what `MyService` needs, builds those first, then builds `MyService` and hands it to you. If you didn't tell it how, it uses **autowiring** — reflection on the class's constructor.

## The simplest possible usage

```php
class Mailer
{
    public function __construct(private readonly string $host = 'smtp.example.com') {}
}

class WelcomeService
{
    public function __construct(private readonly Mailer $mailer) {}
}

// Just ask for it — the container builds the dependency tree for you.
$svc = $app->make(WelcomeService::class);
//   ↑ Lift sees WelcomeService needs a Mailer.
//     Mailer needs no other classes, only a string with a default.
//     Lift constructs Mailer, then WelcomeService(mailer), and returns it.
```

You don't have to register either class. Both are *concrete* and have constructors the container can satisfy → **autowiring** handles them.

## When do you need to register a class?

Three situations:

| Situation                                              | What to do                              |
|--------------------------------------------------------|-----------------------------------------|
| Interface → concrete class binding                     | `$app->bind(I::class, Concrete::class)` |
| Constructor needs config values (DSN, secret, etc.)    | `$app->bind(X::class, fn() => new X(...))` |
| The instance is expensive — only build once per request | `$app->singleton(X::class, ...)`        |
| You already have a built instance                      | `$app->instance(X::class, $obj)`        |

## `bind()` — factory called every time

```php
// Interface → class
$app->bind(LoggerInterface::class, FileLogger::class);

// Factory closure (with arguments)
$app->bind(Mailer::class, fn() => new Mailer(
    host: $_ENV['MAIL_HOST'],
    port: (int) $_ENV['MAIL_PORT'],
));

// Factory that uses the container itself
$app->bind(UserRepository::class, function (Container $c) {
    return new UserRepository($c->get(Database::class));
});
```

Each `$app->make(Mailer::class)` runs the factory again, giving you a fresh instance.

## `singleton()` — resolve once, reuse

```php
$app->singleton(Database::class, fn() => new Database($_ENV['DB_DSN']));

// Autowired singleton (no factory) — Lift still caches it
$app->singleton(UserRepository::class);
```

`$app->make(Database::class)` returns the same instance every call until the request ends.

> A singleton in Lift is **per-process** when running in a long-lived SAPI (RoadRunner, Swoole, ReactPHP), and **per-request** under PHP-FPM. Don't store request-scoped state inside a singleton.

## `instance()` — already-built object

```php
$config = new Config(['debug' => true]);
$app->instance(Config::class, $config);

$app->make(Config::class) === $config;   // true, always
```

Useful for: configs assembled at boot, mocks in tests, third-party objects you constructed outside Lift.

## Autowiring — the magic in detail

For every constructor parameter the container:

1. Checks if an **explicit override** matches by name (`$app->make(X::class, ['port' => 8080])`).
2. Looks at the parameter's **type hint**. If it's a non-built-in class/interface:
   - Is it bound in the container? Use that.
   - Otherwise, is the class concrete and instantiable? Recursively autowire it.
3. If type is **nullable**, fall back to `null`.
4. If the parameter is **optional** (has a default), use the default.
5. Otherwise throw `ContainerException` with the precise parameter and class.

Concrete example:

```php
class OrderService
{
    public function __construct(
        private readonly OrderRepository $orders,    // autowired (or bound)
        private readonly Mailer $mailer,             // autowired (or bound)
        private readonly int $maxItems = 100,        // primitive with default → 100
    ) {}
}

// Just works. No registration needed unless OrderRepository is an interface.
$svc = $app->make(OrderService::class);
```

Override one specific parameter at the call site:

```php
$svc = $app->make(OrderService::class, ['maxItems' => 50]);
```

> **Primitive parameters without a default** are fatal — there's no way for the container to guess `string $dsn`. Either bind a factory or supply an override.

## Injection in route handlers

Type-hint anything the container can resolve, alongside `Request`:

```php
$app->get('/orders', function (Request $req, OrderService $svc) {
    return $svc->all();
});
```

The `Request` is **always** available — Lift injects the current request object even if it's not "registered".

Works in controller methods too:

```php
class OrderController
{
    public function __construct(private readonly OrderService $svc) {}

    public function index(Request $req): array
    {
        return $this->svc->all();
    }
}

$app->get('/orders', [OrderController::class, 'index']);
```

Both the controller class **and** the method's parameters are autowired.

## `make()` — direct resolution

```php
$repo = $app->make(UserRepository::class);

// With named overrides
$svc  = $app->make(ReportService::class, ['month' => 5]);
```

`make()` is the lowest-level API; under the hood `$app->get(...)`, `[Class::class, 'method']` handlers, and middleware resolution all go through it.

## `call()` — invoke any callable with injection

Sometimes you have an existing callable and just want the container to fill in its parameters:

```php
$container = $app->container();

// Closure
$result = $container->call(fn(Database $db) => $db->query('SELECT 1'));

// [Class, 'method']
$result = $container->call([ReportGenerator::class, 'monthly'], ['month' => 5]);

// Already-built instance
$result = $container->call([$generator, 'monthly'], ['month' => 5]);
```

## `has()` — check if something is resolvable

```php
$c = $app->container();
$c->has(LoggerInterface::class);    // true if bound
$c->has(NotRegistered::class);      // true if class exists & is autowirable; false otherwise
```

Useful in libraries that want to optionally use a service if the user provided one.

## PSR-11 compliance

`Container` implements `Psr\Container\ContainerInterface`. It can be handed to any PSR-11-aware library:

```php
$psr11 = $app->container();      // Psr\Container\ContainerInterface
$svc   = $psr11->get(MyThing::class);
```

It throws the proper PSR-11 exception types:

- `Lift\Exception\ContainerNotFoundException` (`Psr\Container\NotFoundExceptionInterface`)
- `Lift\Exception\ContainerException` (`Psr\Container\ContainerExceptionInterface`)

## Circular dependencies

If `A` depends on `B` and `B` depends on `A`, the container detects it and throws:

```
Lift\Exception\ContainerException:
  Circular dependency detected while resolving [App\A]
```

There's no auto-resolution (you can't break a cycle without choosing a side). The fix is architectural — break the cycle by extracting a third class, or by using a setter rather than a constructor.

## Replacing services in tests

```php
$app = new App();

// Real bindings:
$app->singleton(Mailer::class, fn() => new SmtpMailer($_ENV['MAIL_DSN']));

// In a test setup:
$app->instance(Mailer::class, new InMemoryMailer());

$response = $app->handle($request);
```

`instance()` and `bind()` overwrite each other silently — the *last* registration wins.

## Performance notes

- **Reflection is cached** — each class is reflected exactly once per process (the cache is `static`). Under OPcache + a persistent SAPI you pay the reflection cost once at boot and never again.
- **Singletons** save the constructor work on every subsequent resolution.
- The container does no annotation parsing, no proxy generation, no compilation. Everything is plain runtime PHP. Trade-off: slightly slower than a compiled container like Symfony's, but **zero build step**.

Want even faster start-up? Eager-fire the singletons you know will be hit:

```php
$app->container()->get(Database::class);
$app->container()->get(Logger::class);
```

(They're now built once at boot, not on the critical path of the first request that needs them.)

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `Cannot resolve parameter $foo of type [App\X]` | `App\X` is an interface with no binding | `$app->bind(X::class, ConcreteX::class)`. |
| `Cannot resolve untyped required parameter $dsn` | Constructor takes a `string` with no default | Bind a factory: `$app->bind(X::class, fn() => new X(dsn: ...))`. |
| Singleton sees old state | You stored mutable state in it (bad practice under FPM) | Move per-request state into the Request's attributes. |
| Test mock isn't used | Registered via `bind()` *after* something already resolved it (e.g. a global middleware in `$app->use()`) | Use `instance()` before any resolution, or `singleton()` (registered → resolved fresh). |

## Cheat sheet

```php
// Register
$app->bind     ($abstract, $concrete|$factory);     // fresh every call
$app->singleton($abstract, $concrete|$factory|null); // resolve once
$app->instance ($abstract, $object);                 // pre-built

// Resolve
$x = $app->make ($abstract, $overrides = []);
$x = $app->container()->get($abstract);             // PSR-11
$ok = $app->container()->has($abstract);

// Invoke callable with injection
$app->container()->call($callable, $overrides = []);

// Most usage: just type-hint and let Lift figure it out
$app->get('/x', function (Request $req, MyService $svc) { /* … */ });
```

[Middleware →](middleware)
