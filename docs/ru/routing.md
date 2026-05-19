---
layout: page
title: Маршрутизация
nav_order: 4
---

# Маршрутизация

Маршрутизатор сопоставляет входящий HTTP-запрос (метод + путь) с *обработчиком* (функцией/методом для выполнения). Эта страница охватывает **всё**, что может маршрутизатор — методы, параметры, именованные маршруты, группы, middleware, маршрутизацию через атрибуты и продакшен-кэш.

> Ментальная модель: вы говорите маршрутизатору *«когда GET попадает на `/users/42`, вызови эту функцию»*. Маршрутизатор переводит это в быструю таблицу поиска при загрузке, а затем сопоставляет каждый запрос с ней.

## Пять базовых методов

```php
$app->get   ('/path', $handler);
$app->post  ('/path', $handler);
$app->put   ('/path', $handler);
$app->patch ('/path', $handler);
$app->delete('/path', $handler);
```

Менее распространённые:

```php
$app->any('/path', $handler);                        // GET POST PUT PATCH DELETE OPTIONS HEAD
$app->map(['GET', 'HEAD'], '/path', $handler);       // конкретный набор
```

Каждый вызов `$app->get(...)` возвращает объект `Route`, на котором можно строить текучую цепочку (`->name()`, `->middleware()`). Подробнее об этом ниже.

## Типы обработчиков

Обработчик — это **всё, что вызываемо**. Lift принимает пять разновидностей и обращается со всеми одинаково:

```php
// 1. Замыкание
$app->get('/ping', fn() => 'pong');

// 2. Замыкание с типизированными зависимостями (автосвязываются контейнером)
$app->get('/orders', function (Request $req, OrderService $svc) {
    return $svc->all();
});

// 3. [Class::class, 'method'] — класс разрешается через DI-контейнер
$app->get('/users', [UserController::class, 'index']);

// 4. Invokable-класс (имеет __invoke)
$app->get('/healthcheck', HealthcheckAction::class);

// 5. Обычное имя функции в виде строки
$app->get('/old-school', 'my_handler_function');
```

Для вариантов 2 и 3 Lift смотрит на типы параметров и автоматически берёт экземпляры из [контейнера](container). Вам никогда не нужно «регистрировать» контроллеры; они автосвязываются.

### Что может вернуть обработчик

| Возвращаемое значение  | Что вы получаете обратно        |
|------------------------|---------------------------------|
| Объект `Response`      | Передаётся без изменений        |
| `array` или `object`   | `Response::json(...)`           |
| `string`               | `Response::html(...)`           |
| `null`                 | `Response::noContent()` (204)   |
| что угодно иное (скаляр)| `Response::text((string) $v)`  |

Так что `fn() => ['ok' => true]` и `fn() => Response::json(['ok' => true])` эквивалентны. Используйте то, что читается лучше.

## Параметры маршрута

Всё, что внутри `{...}`, захватывается и становится доступным через `$req->param(...)`:

```php
$app->get('/users/{id}', function (Request $req) {
    $id = $req->param('id');     // всегда строка
    return ['id' => $id];
});
```

Получить все параметры разом:

```php
$all = $req->params();           // ['id' => '42']
```

### Regex-ограничения

По умолчанию `{param}` соответствует `[^/]+` — любому символу, кроме `/`. Чтобы потребовать конкретный шаблон, добавьте `:regex`:

```php
$app->get('/posts/{id:\d+}',             $handler);  // только цифры
$app->get('/files/{name:[a-z0-9-]+}',    $handler);  // slug
$app->get('/cards/{code:[A-Z]{3}-\d{4}}', $handler); // "ABC-1234"
```

Путь, который *не* соответствует regex, проваливается к следующему маршруту (или к 404, если ничего не подходит) — ваш обработчик никогда не вызывается с некорректным значением.

> **НЕПРАВИЛЬНО**: именованные группы PCRE в стиле `(?P<name>...)` — Lift использует собственный синтаксис плейсхолдеров, а не сырой PCRE.
> **ПРАВИЛЬНО**: `{name}` или `{name:pattern}`.

### Необязательные сегменты

Lift **не** поддерживает необязательные сегменты пути внутри одного маршрута (стиль `/users[/{id}]`). Вместо этого зарегистрируйте два маршрута:

```php
$app->get('/users',       fn() => 'list');
$app->get('/users/{id}',  fn($req) => 'show ' . $req->param('id'));
```

## Именованные маршруты и генерация URL

Дайте маршруту имя через `->name(...)`, затем стройте URL по имени с помощью `$app->url(...)`:

```php
$app->get('/users/{id}',        $h)->name('users.show');
$app->get('/articles/{slug}',   $h)->name('articles.show');

$app->url('users.show',    ['id'   => 42]);          // /users/42
$app->url('articles.show', ['slug' => 'hello']);     // /articles/hello
```

Соглашение об именовании за вами — `users.show`, `users:show`, `users-show` все работают. Соглашение, используемое в документации и генераторах, — `resource.action` (`users.index`, `users.show`, `users.store`, `users.update`, `users.destroy`).

Если имя не существует, `$app->url(...)` выбрасывает `RuntimeException`.

## Группы маршрутов

Группа разделяет общий префикс пути (и опционально middleware) среди множества маршрутов.

```php
$app->group('/api/v1', function ($group) {
    $group->get   ('/users',          [UserController::class, 'index']);
    $group->get   ('/users/{id:\d+}', [UserController::class, 'show']);
    $group->post  ('/users',          [UserController::class, 'store']);
    $group->put   ('/users/{id:\d+}', [UserController::class, 'update']);
    $group->delete('/users/{id:\d+}', [UserController::class, 'destroy']);
});
```

### Вложенные группы

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

### Middleware группы

Middleware, применённый к группе, выполняется для каждого маршрута внутри неё (и наследуется во вложенные группы):

```php
$app->group('/admin', function ($g) {
    $g->get('/users',    [AdminController::class, 'users']);
    $g->get('/settings', [AdminController::class, 'settings']);
})->middleware(AuthMiddleware::class, RequireAdminMiddleware::class);
```

> Порядок имеет значение. Middleware, указанный первым, выполняется *самым внешним* — то есть видит запрос раньше последующих middleware и видит ответ после них.

## Помаршрутный middleware

Прикрепите middleware к одному маршруту через цепочку `->middleware(...)`:

```php
$app->post('/users', [UserController::class, 'store'])
    ->middleware(AuthMiddleware::class, RateLimitMiddleware::class);
```

Порядок выполнения на запрос:

```
Глобальный middleware (в порядке $app->use())
  → Middleware группы (от внешнего к внутреннему)
    → Middleware маршрута (в порядке объявления)
      → Обработчик
    ← Middleware маршрута
  ← Middleware группы
← Глобальный middleware
```

Каждый слой может прервать цепочку, вернув `Response` вместо вызова `$handler->handle($request)`.

## 404 и 405

| Ситуация                                          | Выбрасываемое исключение                          | Ответ по умолчанию |
|---------------------------------------------------|---------------------------------------------------|--------------------|
| URL не соответствует ни одному маршруту           | `Lift\Exception\NotFoundException`                | 404 JSON           |
| URL соответствует маршруту, но с неверным методом | `Lift\Exception\MethodNotAllowedException`        | 405 JSON           |

Вы можете настроить ответ через `$app->onError(...)`:

```php
use Lift\Exception\NotFoundException;
use Lift\Exception\MethodNotAllowedException;

$app->onError(function (\Throwable $e, Request $req) {
    if ($e instanceof NotFoundException) {
        return Response::html('<h1>404 — страница не найдена</h1>', 404);
    }
    if ($e instanceof MethodNotAllowedException) {
        return Response::json(['error' => 'method not allowed'], 405);
    }
    return Response::json(['error' => 'server error'], 500);
});
```

Или подключите конкретный тип исключения:

```php
$app->onException(NotFoundException::class, fn() => Response::html('Not here.', 404));
```

Полный список исключений → [Обработка ошибок](errors).

## Маршрутизация через атрибуты

Вместо императивного объявления маршрутов можно прикреплять атрибуты `#[Get]`, `#[Post]` и т. д. к методам контроллеров. Маршрутизатор просканирует классы, которые вы попросите его загрузить.

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

// в public/index.php
$app->loadControllers(UserController::class, OrderController::class, ...);
```

Полный справочник: [Маршрутизация через атрибуты](attribute-routing).

## Продакшен: кэширование маршрутов

Когда у вас много маршрутов (50+), сам шаг регистрации становится нетривиальным. Маршрутизатор может скомпилировать ваши маршруты в плоский PHP-файл, который OPcache загрузит мгновенно:

```php
$cache = __DIR__ . '/../storage/routes.cache.php';

$router = $app->router();   // сокращение для $app->container()->get(Router::class)

if (!$router->loadCache($cache)) {
    // Первый запрос после деплоя — регистрируем обычным образом и пишем кэш
    require __DIR__ . '/../routes/web.php';
    $router->writeCache($cache);
}
```

> ⚠️ **Обработчики-замыкания молча пропускаются** при записи кэша. Используйте `[Class::class, 'method']` или invokable-классы для любого маршрута, который должен кэшироваться. Замыкания всё ещё работают, они просто сводят на нет кэширование.

Очищайте кэш при деплое (`rm storage/routes.cache.php`), и первый запрос перестроит его.

## Удобства App

`$app` предоставляет два помощника, полезных, когда вам нужен маршрутизатор или нужно вручную отправить ответ:

```php
// Прямой доступ к Router (полезно для кэша маршрутов, генерации URL вне запроса)
$app->router()->writeCache(storage_path('routes.cache.php'));
$app->router()->url('users.show', ['id' => 42]);

// Диспетчеризация + отправка — обычный случай
$app->run();

// Диспетчеризация без отправки (тестирование, CLI-обвязки)
$response = $app->handle($request);
// … изучить $response …
$app->send($response);   // отправить, когда готово
```

## Заметки о производительности

- **Статические маршруты — O(1).** Маршрут без `{param}` живёт в хеш-карте по ключу путь + метод.
- **Динамические маршруты — O(n).** Линейный просмотр с PCRE на каждый запрос. Даже 100 динамических маршрутов разрешаются за несколько микросекунд.
- **Рефлексия кэшируется.** Маршрутизатор рефлексирует каждый обработчик **один раз на процесс** и переиспользует метаданные между запросами (огромное ускорение под OPcache + персистентными SAPI).
- **Карта именованных маршрутов ленивая.** Строится один раз при первом вызове `url()`, никогда не перестраивается, пока не добавлен новый маршрут.

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| Маршруты регистрируются, но возвращают 404 | Веб-сервер не перенаправляет на `index.php` | Перепроверьте конфигурацию Nginx/Apache в [Установке](installation#5-configure-your-real-web-server). |
| `{id}` приходит как `'42'`, а не `42` | Параметры маршрута всегда строки | Приведите тип: `(int) $req->param('id')`. |
| Middleware не выполняется | Забыли `$app->use(...)` или сцепили `->middleware(...)` после `->name(...)` (работает, но легко забыть) | Добавьте его. Порядок не зависит от `name()`. |
| `Cannot resolve parameter $foo of type [App\X]` | Контейнер не находит привязку *и* класс не автосвязываем | Либо `$app->bind(X::class, ...)`, либо сделайте класс конкретным с автосвязываемым конструктором. |
| `$app->url('foo')` выбрасывает исключение | Маршрут `foo` никогда не регистрировался с `->name('foo')` | Зарегистрируйте его или исправьте опечатку. |

## Шпаргалка

```php
// Методы
$app->get|post|put|patch|delete($path, $handler);
$app->any($path, $handler);
$app->map(['GET','POST'], $path, $handler);

// Параметры
'/users/{id}'              // без ограничений
'/users/{id:\d+}'          // ограничен regex

// Именование + генерация URL
$app->get($p, $h)->name('foo');
$app->url('foo', ['id'=>1]);

// Группы
$app->group('/api', fn($g) => /* ... */)->middleware(M1::class);

// Помаршрутный middleware
$app->get($p, $h)->middleware(M1::class, M2::class);

// Маршрутизация через атрибуты
$app->loadControllers(UserController::class);

// Продакшен-кэш
$router->loadCache($file) || ($router->writeCache($file));
```

[Request →](request)
