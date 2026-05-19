---
layout: page
title: Маршрутизація через атрибути
nav_order: 12
---

# Маршрутизація через атрибути

Декларативна маршрутизація з використанням атрибутів PHP 8 — ті самі маршрути, але **зареєстровані поряд із кодом, який їх обробляє**.

> Ментальна модель: замість переліку маршрутів у центральному файлі ви прикріплюєте `#[Get('/users')]` прямо до методу контролера. Під час завантаження Lift сканує класи, на які ви його вказуєте, і реєструє все за один прохід.

## Найпростіший можливий приклад

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

// У public/index.php
$app->loadControllers(UserController::class);
$app->run();
```

Ось і все. Жодних `$app->get(...)`, жодного центрального файлу маршрутів.

## Атрибути

| Атрибут          | Ціль             | Повторюваний | Призначення                                             |
|------------------|------------------|:------------:|---------------------------------------------------------|
| `#[Get]`         | метод, функція   | ✓            | GET-маршрут                                             |
| `#[Post]`        | метод, функція   | ✓            | POST-маршрут                                            |
| `#[Put]`         | метод, функція   | ✓            | PUT-маршрут                                             |
| `#[Patch]`       | метод, функція   | ✓            | PATCH-маршрут                                           |
| `#[Delete]`      | метод, функція   | ✓            | DELETE-маршрут                                          |
| `#[Route]`       | метод, функція   | ✓            | Будь-який метод (`#[Route('OPTIONS', '/x')]`)           |
| `#[Group]`       | клас             | один раз     | Префікс URL для кожного методу в класі                  |
| `#[Middleware]`  | клас, метод      | ✓            | Прикріпити middleware до класу або методу               |

Усі `#[Get/Post/...]` приймають ті самі аргументи, що й їхні імперативні аналоги:

```php
#[Get('/users/{id:\d+}', name: 'users.show')]
public function show(Request $req): Response { … }
```

Аргумент `name:` під’єднується до `$app->url('users.show', ['id' => 42])` рівно як `->name(...)`.

## Префікс URL на рівні класу — `#[Group]`

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

Клас може нести лише **один** `#[Group]`. Для вкладеності використовуйте кілька контролерів (`UserController`, `AdminUserController`).

## Middleware

Або на рівні класу (застосовується до кожного маршруту в контролері), або на метод (застосовується лише до цього маршруту):

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
    #[Middleware(RateLimitMiddleware::class)]      // додається поверх класових
    public function ban(Request $req) { … }
}
```

Класи middleware розв’язуються через [контейнер](container), тож вони можуть мати залежності конструктора.

Можна також передати масив:

```php
#[Middleware([AuthMiddleware::class, LogMiddleware::class])]
final class X { … }
```

## Кілька маршрутів на одному методі

І `#[Route]`, і атрибути, специфічні для методів, **повторювані** — застосовуйте більше одного разу, щоб зіставити кілька URL (або методів) одному методу:

```php
#[Get('/users/{id:\d+}')]
#[Get('/users/by-uuid/{uuid:[a-z0-9-]+}')]
public function show(Request $req): Response
{
    // Розгалуження за тим, який параметр існує
    return ...;
}

#[Route('GET',  '/widgets')]
#[Route('HEAD', '/widgets')]
public function index(): array { … }
```

## Завантаження контролерів

```php
// Один клас:
$app->loadControllers(UserController::class);

// Багато — ланцюжком або одним викликом:
$app->loadControllers(
    UserController::class,
    OrderController::class,
    AdminController::class,
);
```

Підказка: тримайте список контролерів у конфігураційному файлі й розгорніть його:

```php
$controllers = require __DIR__ . '/../config/controllers.php';
$app->loadControllers(...$controllers);
```

## Взаємодія з імперативними маршрутами

Атрибутні та імперативні маршрути вільно співіснують:

```php
$app->loadControllers(UserController::class);

// Додати швидку перевірку здоров’я імперативно
$app->get('/health', fn() => ['ok' => true]);
```

Порядок не має значення; маршрутизатор розв’язує потрібний на кожен запит.

## OPcache і `save_comments`

Атрибути PHP зберігаються у док-коментарях класу на рівні байт-коду. **OPcache має їх зберігати.** У `php.ini`:

```ini
opcache.save_comments=1
```

Це значення за замовчуванням — але деякі посилені продакшен-образи ставлять `0` заради «меншого байт-коду». Без нього завантажувач не бачить атрибутів і мовчки реєструє нуль маршрутів.

## Продакшен-кеш

Сканування атрибутів використовує рефлексію, що коштує кілька мс на контролер. Для десятків контролерів комбінуйте з [кешем маршрутів](routing#production-route-caching):

```php
$cache = __DIR__ . '/../storage/routes.cache.php';
$router = $app->container()->get(\Lift\Routing\Router::class);

if (!$router->loadCache($cache)) {
    $app->loadControllers(...$controllers);
    $router->writeCache($cache);
}
```

Кеш зберігає розв’язану таблицю маршрутів; наступні запити повністю пропускають і сканування контролерів, і реєстрацію маршрутів.

## Коли *не* використовувати атрибути

- Разові скрипти або API з 3 маршрутів — `$app->get(...)` простіший.
- Коли той самий обробник викликається з кількох фреймворків — тримайте маршрути зовнішніми.
- Коли потрібна умовна реєстрація (`if ($env === 'dev') ...`) — можлива лише імперативно.

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| `$app->loadControllers(X::class)` реєструє нуль маршрутів | `opcache.save_comments=0`, або в класі немає атрибутів `#[Get]/...` | Виправте `php.ini`; переконайтеся, що атрибути існують. |
| Middleware не застосовується | Забули `use Lift\Attribute\Middleware` — PHP імпортував невірний клас `Middleware` | Використовуйте повне FQN `\Lift\Attribute\Middleware` або імпортуйте явно. |
| Два маршрути зареєстровані для одного методу | Ви використали і `#[Get]`, і `#[Route('GET', …)]` для одного URL | Оберіть щось одне. |
| Метод, зареєстрований як маршрут, — `static` | Статичні методи пропускаються завантажувачем | Зробіть його методом екземпляра або викликайте з нестатичного диспетчера. |
| `Cannot resolve parameter $foo` під час завантаження | Конструктору контролера потрібен клас, який контейнер не може знайти | Спершу `$app->bind(...)` залежність. |

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

[Обробка помилок →](errors)
