---
layout: page
title: Маршрутизація
nav_order: 4
---

# Маршрутизація

Маршрутизатор зіставляє вхідний HTTP-запит (метод + шлях) з *обробником* (функцією/методом для виконання). Ця сторінка охоплює **все**, що може маршрутизатор — методи, параметри, іменовані маршрути, групи, middleware, маршрутизацію через атрибути та продакшен-кеш.

> Ментальна модель: ви кажете маршрутизатору *«коли GET потрапляє на `/users/42`, виклич цю функцію»*. Маршрутизатор перекладає це у швидку таблицю пошуку під час завантаження, а потім зіставляє кожен запит із нею.

## П’ять базових методів

```php
$app->get   ('/path', $handler);
$app->post  ('/path', $handler);
$app->put   ('/path', $handler);
$app->patch ('/path', $handler);
$app->delete('/path', $handler);
```

Менш поширені:

```php
$app->any('/path', $handler);                        // GET POST PUT PATCH DELETE OPTIONS HEAD
$app->map(['GET', 'HEAD'], '/path', $handler);       // конкретний набір
```

Кожен виклик `$app->get(...)` повертає об’єкт `Route`, на якому можна будувати плавний ланцюжок (`->name()`, `->middleware()`). Докладніше про це нижче.

## Типи обробників

Обробник — це **все, що викликається**. Lift приймає п’ять різновидів і поводиться з усіма однаково:

```php
// 1. Замикання
$app->get('/ping', fn() => 'pong');

// 2. Замикання з типізованими залежностями (автозв’язуються контейнером)
$app->get('/orders', function (Request $req, OrderService $svc) {
    return $svc->all();
});

// 3. [Class::class, 'method'] — клас розв’язується через DI-контейнер
$app->get('/users', [UserController::class, 'index']);

// 4. Invokable-клас (має __invoke)
$app->get('/healthcheck', HealthcheckAction::class);

// 5. Звичайне ім’я функції у вигляді рядка
$app->get('/old-school', 'my_handler_function');
```

Для варіантів 2 і 3 Lift дивиться на типи параметрів і автоматично бере екземпляри з [контейнера](container). Вам ніколи не потрібно «реєструвати» контролери; вони автозв’язуються.

### Що може повернути обробник

| Повернене значення     | Що ви отримуєте назад           |
|------------------------|---------------------------------|
| Об’єкт `Response`      | Передається без змін            |
| `array` або `object`   | `Response::json(...)`           |
| `string`               | `Response::html(...)`           |
| `null`                 | `Response::noContent()` (204)   |
| будь-що інше (скаляр)  | `Response::text((string) $v)`   |

Тож `fn() => ['ok' => true]` і `fn() => Response::json(['ok' => true])` еквівалентні. Використовуйте те, що читається краще.

## Параметри маршруту

Усе, що всередині `{...}`, захоплюється й стає доступним через `$req->param(...)`:

```php
$app->get('/users/{id}', function (Request $req) {
    $id = $req->param('id');     // завжди рядок
    return ['id' => $id];
});
```

Отримати всі параметри разом:

```php
$all = $req->params();           // ['id' => '42']
```

### Regex-обмеження

За замовчуванням `{param}` відповідає `[^/]+` — будь-якому символу, окрім `/`. Щоб вимагати конкретний шаблон, додайте `:regex`:

```php
$app->get('/posts/{id:\d+}',             $handler);  // лише цифри
$app->get('/files/{name:[a-z0-9-]+}',    $handler);  // slug
$app->get('/cards/{code:[A-Z]{3}-\d{4}}', $handler); // "ABC-1234"
```

Шлях, який *не* відповідає regex, провалюється до наступного маршруту (або до 404, якщо нічого не підходить) — ваш обробник ніколи не викликається з некоректним значенням.

> **НЕПРАВИЛЬНО**: іменовані групи PCRE у стилі `(?P<name>...)` — Lift використовує власний синтаксис плейсхолдерів, а не сирий PCRE.
> **ПРАВИЛЬНО**: `{name}` або `{name:pattern}`.

### Необов’язкові сегменти

Lift **не** підтримує необов’язкові сегменти шляху всередині одного маршруту (стиль `/users[/{id}]`). Замість цього зареєструйте два маршрути:

```php
$app->get('/users',       fn() => 'list');
$app->get('/users/{id}',  fn($req) => 'show ' . $req->param('id'));
```

## Іменовані маршрути та генерація URL

Дайте маршруту ім’я через `->name(...)`, потім будуйте URL за іменем за допомогою `$app->url(...)`:

```php
$app->get('/users/{id}',        $h)->name('users.show');
$app->get('/articles/{slug}',   $h)->name('articles.show');

$app->url('users.show',    ['id'   => 42]);          // /users/42
$app->url('articles.show', ['slug' => 'hello']);     // /articles/hello
```

Угода про іменування — за вами: `users.show`, `users:show`, `users-show` усі працюють. Угода, що використовується в документації та генераторах, — `resource.action` (`users.index`, `users.show`, `users.store`, `users.update`, `users.destroy`).

Якщо ім’я не існує, `$app->url(...)` викидає `RuntimeException`.

## Групи маршрутів

Група розділяє спільний префікс шляху (та опційно middleware) серед багатьох маршрутів.

```php
$app->group('/api/v1', function ($group) {
    $group->get   ('/users',          [UserController::class, 'index']);
    $group->get   ('/users/{id:\d+}', [UserController::class, 'show']);
    $group->post  ('/users',          [UserController::class, 'store']);
    $group->put   ('/users/{id:\d+}', [UserController::class, 'update']);
    $group->delete('/users/{id:\d+}', [UserController::class, 'destroy']);
});
```

### Вкладені групи

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

### Middleware групи

Middleware, застосований до групи, виконується для кожного маршруту всередині неї (та успадковується у вкладені групи):

```php
$app->group('/admin', function ($g) {
    $g->get('/users',    [AdminController::class, 'users']);
    $g->get('/settings', [AdminController::class, 'settings']);
})->middleware(AuthMiddleware::class, RequireAdminMiddleware::class);
```

> Порядок має значення. Middleware, вказаний першим, виконується *найзовнішнім* — тобто бачить запит раніше за наступні middleware і бачить відповідь після них.

## Помаршрутний middleware

Прикріпіть middleware до одного маршруту через ланцюжок `->middleware(...)`:

```php
$app->post('/users', [UserController::class, 'store'])
    ->middleware(AuthMiddleware::class, RateLimitMiddleware::class);
```

Порядок виконання на запит:

```
Глобальний middleware (у порядку $app->use())
  → Middleware групи (від зовнішнього до внутрішнього)
    → Middleware маршруту (у порядку оголошення)
      → Обробник
    ← Middleware маршруту
  ← Middleware групи
← Глобальний middleware
```

Кожен шар може перервати ланцюжок, повернувши `Response` замість виклику `$handler->handle($request)`.

## 404 і 405

| Ситуація                                            | Викинуте виняток                                  | Відповідь за замовчуванням |
|-----------------------------------------------------|---------------------------------------------------|----------------------------|
| URL не відповідає жодному маршруту                  | `Lift\Exception\NotFoundException`                | 404 JSON                   |
| URL відповідає маршруту, але з невірним методом     | `Lift\Exception\MethodNotAllowedException`        | 405 JSON                   |

Ви можете налаштувати відповідь через `$app->onError(...)`:

```php
use Lift\Exception\NotFoundException;
use Lift\Exception\MethodNotAllowedException;

$app->onError(function (\Throwable $e, Request $req) {
    if ($e instanceof NotFoundException) {
        return Response::html('<h1>404 — сторінку не знайдено</h1>', 404);
    }
    if ($e instanceof MethodNotAllowedException) {
        return Response::json(['error' => 'method not allowed'], 405);
    }
    return Response::json(['error' => 'server error'], 500);
});
```

Або під’єднайте конкретний тип винятку:

```php
$app->onException(NotFoundException::class, fn() => Response::html('Not here.', 404));
```

Повний перелік винятків → [Обробка помилок](errors).

## Маршрутизація через атрибути

Замість імперативного оголошення маршрутів можна прикріплювати атрибути `#[Get]`, `#[Post]` тощо до методів контролерів. Маршрутизатор просканує класи, які ви попросите його завантажити.

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

// у public/index.php
$app->loadControllers(UserController::class, OrderController::class, ...);
```

Повний довідник: [Маршрутизація через атрибути](attribute-routing).

## Продакшен: кешування маршрутів

Коли у вас багато маршрутів (50+), сам крок реєстрації стає нетривіальним. Маршрутизатор може скомпілювати ваші маршрути у плоский PHP-файл, який OPcache завантажить миттєво:

```php
$cache = __DIR__ . '/../storage/routes.cache.php';

$router = $app->router();   // скорочення для $app->container()->get(Router::class)

if (!$router->loadCache($cache)) {
    // Перший запит після деплою — реєструємо звичайним чином і пишемо кеш
    require __DIR__ . '/../routes/web.php';
    $router->writeCache($cache);
}
```

> ⚠️ **Обробники-замикання мовчки пропускаються** під час запису кешу. Використовуйте `[Class::class, 'method']` або invokable-класи для будь-якого маршруту, який має кешуватися. Замикання все ще працюють, вони просто зводять нанівець кешування.

Очищайте кеш під час деплою (`rm storage/routes.cache.php`), і перший запит перебудує його.

## Зручності App

`$app` надає два помічники, корисні, коли вам потрібен маршрутизатор або потрібно вручну надіслати відповідь:

```php
// Прямий доступ до Router (корисно для кешу маршрутів, генерації URL поза запитом)
$app->router()->writeCache(storage_path('routes.cache.php'));
$app->router()->url('users.show', ['id' => 42]);

// Диспетчеризація + надсилання — звичайний випадок
$app->run();

// Диспетчеризація без надсилання (тестування, CLI-обгортки)
$response = $app->handle($request);
// … дослідити $response …
$app->send($response);   // надіслати, коли готово
```

## Нотатки про продуктивність

- **Статичні маршрути — O(1).** Маршрут без `{param}` живе у хеш-карті за ключем шлях + метод.
- **Динамічні маршрути — O(n).** Лінійний перегляд із PCRE на кожен запит. Навіть 100 динамічних маршрутів розв’язуються за кілька мікросекунд.
- **Рефлексія кешується.** Маршрутизатор рефлексує кожен обробник **один раз на процес** і повторно використовує метадані між запитами (величезне пришвидшення під OPcache + персистентними SAPI).
- **Карта іменованих маршрутів лінива.** Будується один раз під час першого виклику `url()`, ніколи не перебудовується, доки не додано новий маршрут.

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| Маршрути реєструються, але повертають 404 | Вебсервер не перенаправляє на `index.php` | Перевірте конфігурацію Nginx/Apache в [Встановленні](installation#5-configure-your-real-web-server). |
| `{id}` приходить як `'42'`, а не `42` | Параметри маршруту завжди рядки | Приведіть тип: `(int) $req->param('id')`. |
| Middleware не виконується | Забули `$app->use(...)` або зчепили `->middleware(...)` після `->name(...)` (працює, але легко забути) | Додайте його. Порядок не залежить від `name()`. |
| `Cannot resolve parameter $foo of type [App\X]` | Контейнер не знаходить прив’язки *і* клас не автозв’язуваний | Або `$app->bind(X::class, ...)`, або зробіть клас конкретним з автозв’язуваним конструктором. |
| `$app->url('foo')` викидає виняток | Маршрут `foo` ніколи не реєструвався з `->name('foo')` | Зареєструйте його або виправте друкарську помилку. |

## Шпаргалка

```php
// Методи
$app->get|post|put|patch|delete($path, $handler);
$app->any($path, $handler);
$app->map(['GET','POST'], $path, $handler);

// Параметри
'/users/{id}'              // без обмежень
'/users/{id:\d+}'          // обмежений regex

// Іменування + генерація URL
$app->get($p, $h)->name('foo');
$app->url('foo', ['id'=>1]);

// Групи
$app->group('/api', fn($g) => /* ... */)->middleware(M1::class);

// Помаршрутний middleware
$app->get($p, $h)->middleware(M1::class, M2::class);

// Маршрутизація через атрибути
$app->loadControllers(UserController::class);

// Продакшен-кеш
$router->loadCache($file) || ($router->writeCache($file));
```

[Request →](request)
