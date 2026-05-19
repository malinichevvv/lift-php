---
layout: page
title: Маршрутизация через атрибуты
nav_order: 12
---

# Маршрутизация через атрибуты

Декларативная маршрутизация с использованием атрибутов PHP 8 — те же маршруты, но **зарегистрированные рядом с кодом, который их обрабатывает**.

> Ментальная модель: вместо перечисления маршрутов в центральном файле вы прикрепляете `#[Get('/users')]` прямо к методу контроллера. При загрузке Lift сканирует классы, на которые вы его указываете, и регистрирует всё за один проход.

## Простейший возможный пример

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

// В public/index.php
$app->loadControllers(UserController::class);
$app->run();
```

Вот и всё. Никаких `$app->get(...)`, никакого центрального файла маршрутов.

## Атрибуты

| Атрибут          | Цель             | Повторяемый | Назначение                                              |
|------------------|------------------|:-----------:|---------------------------------------------------------|
| `#[Get]`         | метод, функция   | ✓           | GET-маршрут                                             |
| `#[Post]`        | метод, функция   | ✓           | POST-маршрут                                            |
| `#[Put]`         | метод, функция   | ✓           | PUT-маршрут                                             |
| `#[Patch]`       | метод, функция   | ✓           | PATCH-маршрут                                           |
| `#[Delete]`      | метод, функция   | ✓           | DELETE-маршрут                                          |
| `#[Route]`       | метод, функция   | ✓           | Любой метод (`#[Route('OPTIONS', '/x')]`)               |
| `#[Group]`       | класс            | один раз    | Префикс URL для каждого метода в классе                 |
| `#[Middleware]`  | класс, метод     | ✓           | Прикрепить middleware к классу или методу               |

Все `#[Get/Post/...]` принимают те же аргументы, что и их императивные аналоги:

```php
#[Get('/users/{id:\d+}', name: 'users.show')]
public function show(Request $req): Response { … }
```

Аргумент `name:` подключается к `$app->url('users.show', ['id' => 42])` ровно как `->name(...)`.

## Префикс URL на уровне класса — `#[Group]`

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

Класс может нести только **один** `#[Group]`. Для вложенности используйте несколько контроллеров (`UserController`, `AdminUserController`).

## Middleware

Либо на уровне класса (применяется к каждому маршруту в контроллере), либо на метод (применяется только к этому маршруту):

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
    #[Middleware(RateLimitMiddleware::class)]      // добавляется поверх классовых
    public function ban(Request $req) { … }
}
```

Классы middleware разрешаются через [контейнер](container), так что они могут иметь зависимости конструктора.

Можно также передать массив:

```php
#[Middleware([AuthMiddleware::class, LogMiddleware::class])]
final class X { … }
```

## Несколько маршрутов на одном методе

И `#[Route]`, и атрибуты, специфичные для методов, **повторяемы** — применяйте более одного раза, чтобы сопоставить несколько URL (или методов) одному методу:

```php
#[Get('/users/{id:\d+}')]
#[Get('/users/by-uuid/{uuid:[a-z0-9-]+}')]
public function show(Request $req): Response
{
    // Ветвление по тому, какой параметр существует
    return ...;
}

#[Route('GET',  '/widgets')]
#[Route('HEAD', '/widgets')]
public function index(): array { … }
```

## Загрузка контроллеров

```php
// Один класс:
$app->loadControllers(UserController::class);

// Много — цепочкой или одним вызовом:
$app->loadControllers(
    UserController::class,
    OrderController::class,
    AdminController::class,
);
```

Подсказка: держите список контроллеров в конфигурационном файле и разверните его:

```php
$controllers = require __DIR__ . '/../config/controllers.php';
$app->loadControllers(...$controllers);
```

## Взаимодействие с императивными маршрутами

Атрибутные и императивные маршруты свободно сосуществуют:

```php
$app->loadControllers(UserController::class);

// Добавить быструю проверку здоровья императивно
$app->get('/health', fn() => ['ok' => true]);
```

Порядок не имеет значения; маршрутизатор разрешает нужный на каждый запрос.

## OPcache и `save_comments`

Атрибуты PHP хранятся в док-комментариях класса на уровне байт-кода. **OPcache должен их сохранять.** В `php.ini`:

```ini
opcache.save_comments=1
```

Это значение по умолчанию — но некоторые усиленные продакшен-образы ставят `0` ради «меньшего байт-кода». Без него загрузчик не видит атрибутов и молча регистрирует ноль маршрутов.

## Продакшен-кэш

Сканирование атрибутов использует рефлексию, что стоит несколько мс на контроллер. Для десятков контроллеров комбинируйте с [кэшем маршрутов](routing#production-route-caching):

```php
$cache = __DIR__ . '/../storage/routes.cache.php';
$router = $app->container()->get(\Lift\Routing\Router::class);

if (!$router->loadCache($cache)) {
    $app->loadControllers(...$controllers);
    $router->writeCache($cache);
}
```

Кэш хранит разрешённую таблицу маршрутов; последующие запросы полностью пропускают и сканирование контроллеров, и регистрацию маршрутов.

## Когда *не* использовать атрибуты

- Разовые скрипты или API из 3 маршрутов — `$app->get(...)` проще.
- Когда тот же обработчик вызывается из нескольких фреймворков — держите маршруты внешними.
- Когда нужна условная регистрация (`if ($env === 'dev') ...`) — возможна только императивно.

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| `$app->loadControllers(X::class)` регистрирует ноль маршрутов | `opcache.save_comments=0`, или у класса нет атрибутов `#[Get]/...` | Исправьте `php.ini`; убедитесь, что атрибуты существуют. |
| Middleware не применяется | Забыли `use Lift\Attribute\Middleware` — PHP импортировал неверный класс `Middleware` | Используйте полное FQN `\Lift\Attribute\Middleware` или импортируйте явно. |
| Два маршрута зарегистрированы для одного метода | Вы использовали и `#[Get]`, и `#[Route('GET', …)]` для одного URL | Выберите что-то одно. |
| Метод, зарегистрированный как маршрут, — `static` | Статические методы пропускаются загрузчиком | Сделайте его методом экземпляра или вызывайте из нестатического диспетчера. |
| `Cannot resolve parameter $foo` при загрузке | Конструктору контроллера нужен класс, который контейнер не может найти | Сначала `$app->bind(...)` зависимость. |

## Шпаргалка

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

[Обработка ошибок →](errors)
