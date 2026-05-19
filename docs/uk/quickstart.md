---
layout: page
title: Швидкий старт
nav_order: 3
---

# Швидкий старт

До кінця цієї сторінки ви побудуєте невеликий JSON-API з кількома маршрутами, валідацією параметрів, впровадженням залежностей і класом-контролером — використовуючи лише те, що входить у поставку Lift.

Почнемо буквально з однорядкового коду й поступово виростимо його у щось, упізнаване за реальним сервісом.

## Етап 0 — Hello, World

Якщо ви пройшли [Встановлення](installation), `public/index.php` уже виглядає так:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Lift\App;
use Lift\Http\Response;

$app = new App();

$app->get('/', fn() => Response::json(['message' => 'Hello, World!']));

$app->run();
```

Запустіть:

```bash
php -S 127.0.0.1:8000 -t public
curl http://127.0.0.1:8000/
# {"message":"Hello, World!"}
```

Три речі, на які варто звернути увагу:

1. `new App()` — жодної фабрики, жодного білдера. Застосунок — це просто об’єкт.
2. `$app->get('/', $handler)` — `$handler` може бути **будь-чим викликаним**: замиканням, `[Class::class, 'method']` або іменем invokable-класу.
3. `Response::json([...])` — одне з кількох фабричних скорочень. Усі вони описані в [Response](response).

> Якщо ваш обробник повертає `array`, Lift автоматично загортає його у `Response::json(...)`. Тож `fn() => ['ok' => true]` теж працює — див. [автоматичне перетворення відповіді](response#auto-conversion). У решті посібника ми будемо явними й використовуватимемо `Response::json(...)`.

## Етап 1 — Кілька маршрутів

```php
$app->get('/',          fn() => Response::json(['message' => 'Hello, World!']));
$app->get('/health',    fn() => Response::json(['ok' => true]));
$app->get('/version',   fn() => Response::json(['version' => '1.0.0']));

// POST / PUT / PATCH / DELETE працюють так само
$app->post('/echo', function (\Lift\Http\Request $req) {
    return Response::json(['you_sent' => $req->json()]);
});
```

Швидка перевірка:

```bash
curl -X POST -H 'Content-Type: application/json' \
     -d '{"foo":"bar"}' \
     http://127.0.0.1:8000/echo
# {"you_sent":{"foo":"bar"}}
```

Зверніть увагу на параметр замикання `\Lift\Http\Request $req`. Lift **автоматично впроваджує** поточний запит у будь-який обробник, який його запитує. Передавати його вручну не потрібно.

## Етап 2 — Параметри маршруту

Усе, що всередині `{...}`, стає параметром, який можна прочитати через `$req->param(...)`:

```php
$app->get('/users/{id}', function (\Lift\Http\Request $req) {
    return Response::json([
        'id' => $req->param('id'),
    ]);
});
```

```bash
curl http://127.0.0.1:8000/users/42
# {"id":"42"}
```

Зауважте, що `id` приходить як **рядок** — саме це дає вам HTTP. Приводьте тип самі:

```php
$id = (int) $req->param('id');
```

Параметр також можна обмежити regex-шаблоном. Двокрапка відділяє ім’я від шаблону:

```php
$app->get('/posts/{id:\d+}',       $handler);   // лише цифри
$app->get('/articles/{slug:[a-z0-9-]+}', $handler); // малі літери + дефіси
```

Якщо URL не відповідає шаблону, Lift повертає 404 — обробник не викликається.

## Етап 3 — Крихітний REST API у пам’яті

Побудуємо щось близьке до справжнього CRUD-ендпоінта, використовуючи як «базу даних» звичайний масив PHP:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Lift\App;
use Lift\Http\Request;
use Lift\Http\Response;

$app = new App();

/** @var array<int, array{id:int, name:string}> $users */
$users = [
    1 => ['id' => 1, 'name' => 'Alice'],
    2 => ['id' => 2, 'name' => 'Bob'],
];
$nextId = 3;

// Список
$app->get('/users', fn() => Response::json(array_values($users)));

// Перегляд
$app->get('/users/{id:\d+}', function (Request $req) use (&$users) {
    $id = (int) $req->param('id');
    if (!isset($users[$id])) {
        return Response::json(['error' => 'User not found'], 404);
    }
    return Response::json($users[$id]);
});

// Створення
$app->post('/users', function (Request $req) use (&$users, &$nextId) {
    $name = $req->json()['name'] ?? null;
    if (!is_string($name) || $name === '') {
        return Response::json(['error' => 'name is required'], 422);
    }
    $id = $nextId++;
    $users[$id] = ['id' => $id, 'name' => $name];
    return Response::json($users[$id], 201);
});

// Оновлення
$app->put('/users/{id:\d+}', function (Request $req) use (&$users) {
    $id = (int) $req->param('id');
    if (!isset($users[$id])) {
        return Response::json(['error' => 'User not found'], 404);
    }
    $users[$id]['name'] = $req->json()['name'] ?? $users[$id]['name'];
    return Response::json($users[$id]);
});

// Видалення
$app->delete('/users/{id:\d+}', function (Request $req) use (&$users) {
    unset($users[(int) $req->param('id')]);
    return Response::noContent(); // 204
});

$app->run();
```

Спробуйте:

```bash
curl    http://127.0.0.1:8000/users
curl    http://127.0.0.1:8000/users/1
curl -X POST   -H 'Content-Type: application/json' -d '{"name":"Carol"}' http://127.0.0.1:8000/users
curl -X PUT    -H 'Content-Type: application/json' -d '{"name":"Bobby"}' http://127.0.0.1:8000/users/2
curl -X DELETE http://127.0.0.1:8000/users/1
```

Що ви могли пропустити:

- `$req->json()` повертає декодоване тіло JSON у вигляді асоціативного масиву. Завжди.
- `Response::json($data, 201)` дозволяє задати власний код стану.
- `Response::noContent()` — це скорочення для HTTP 204.
- `Response::json(['error' => ...], 422)` — загальноприйнята форма для помилок валідації.

## Етап 4 — Валідація, простий спосіб

Писати вручну перевірки `if (!$name)` швидко набридає. У Lift є валідатор. Найкоротший спосіб його застосувати — `$req->validate([...])`:

```php
use Lift\Validation\ValidationException;

$app->post('/users', function (Request $req) use (&$users, &$nextId) {
    try {
        $data = $req->validate([
            'name'  => 'required|string|min:2|max:255',
            'email' => 'required|email',
        ]);
    } catch (ValidationException $e) {
        return Response::json(['errors' => $e->errors()], 422);
    }

    $id = $nextId++;
    $users[$id] = ['id' => $id, ...$data];
    return Response::json($users[$id], 201);
});
```

`try/catch` насправді не обов’язковий: якщо `ValidationException` залишає обробник, типовий обробник помилок Lift перетворює його на 422 з тією самою формою `errors`. Повний перелік правил міститься у [Валідації](validation).

## Етап 5 — Контролери та впровадження залежностей

Замикання в `index.php` годяться для 10 маршрутів. Далі вам знадобляться класи. Контейнер Lift створить їх і впровадить їхні залежності автоматично.

```
my-app/
├── public/index.php
└── src/
    ├── UserRepository.php
    └── UserController.php
```

`src/UserRepository.php`:

```php
<?php
namespace App;

final class UserRepository
{
    /** @var array<int, array{id:int, name:string}> */
    private array $users = [
        1 => ['id' => 1, 'name' => 'Alice'],
        2 => ['id' => 2, 'name' => 'Bob'],
    ];
    private int $nextId = 3;

    public function all(): array          { return array_values($this->users); }
    public function find(int $id): ?array { return $this->users[$id] ?? null; }

    public function create(array $data): array
    {
        $id = $this->nextId++;
        return $this->users[$id] = ['id' => $id, ...$data];
    }
}
```

`src/UserController.php`:

```php
<?php
namespace App;

use Lift\Http\Request;
use Lift\Http\Response;

final class UserController
{
    // 👇 Lift автоматично зв’язує це через контейнер — ви ніколи не створюєте UserController самі.
    public function __construct(private readonly UserRepository $users) {}

    public function index(): array
    {
        return $this->users->all();
    }

    public function show(Request $req): Response
    {
        $user = $this->users->find((int) $req->param('id'));
        return $user
            ? Response::json($user)
            : Response::json(['error' => 'Not found'], 404);
    }

    public function store(Request $req): Response
    {
        $data = $req->validate([
            'name'  => 'required|string|min:2',
            'email' => 'required|email',
        ]);
        return Response::json($this->users->create($data), 201);
    }
}
```

Повідомте Composer про простір імен — додайте це до `composer.json` і виконайте `composer dump-autoload`:

```json
"autoload": {
    "psr-4": { "App\\": "src/" }
}
```

Нарешті, під’єднайте маршрути у `public/index.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Lift\App;
use App\UserController;
use App\UserRepository;

$app = new App();

// Кешуємо репозиторій на час життя запиту
$app->singleton(UserRepository::class);

$app->get   ('/users',          [UserController::class, 'index']);
$app->get   ('/users/{id:\d+}', [UserController::class, 'show']);
$app->post  ('/users',          [UserController::class, 'store']);

$app->run();
```

Ось і все. Зверніть увагу, що:

- Ви ніколи явно не створюєте `UserController` чи `UserRepository` — це робить контейнер через [автозв’язування](container#autowiring).
- `$app->singleton(UserRepository::class)` каже контейнеру «створи один і повторно використовуй». Без цього рядка ви отримували б новий репозиторій на кожен виклик (і втрачали б дані в пам’яті).
- `[UserController::class, 'index']` — це стандартний для PHP callable вигляду `[$class, $method]`. Lift розв’язує клас через контейнер, а потім викликає метод.

## Етап 6 — Групування маршрутів

Більшість API версіонують свої ендпоінти під `/api/v1/...`. Групи беруть префікс на себе:

```php
$app->group('/api/v1', function ($group) {
    $group->get   ('/users',          [UserController::class, 'index']);
    $group->get   ('/users/{id:\d+}', [UserController::class, 'show']);
    $group->post  ('/users',          [UserController::class, 'store']);
});
```

Групи також приймають middleware, префікси для іменованих маршрутів і можуть бути вкладеними. Див. [Маршрутизація](routing#route-groups).

## Етап 7 — Додавання middleware

Припустімо, ви хочете, щоб кожна відповідь несла заголовок `X-Request-Id`. Middleware — правильне для цього місце.

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface
    {
        $id = $req->getHeaderLine('X-Request-Id') ?: bin2hex(random_bytes(8));
        return $next->handle($req->withAttribute('request_id', $id))
                    ->withHeader('X-Request-Id', $id);
    }
}

$app->use(RequestIdMiddleware::class);  // глобально — виконується на кожному запиті
```

У маршрутів також може бути **помаршрутний** або **погруповий** middleware. Див. [Middleware](middleware).

## Етап 8 — Під’єднання справжнього сховища

Замініть масив у пам’яті на SQLite (або MySQL/Postgres) менш ніж за 20 рядків:

```php
use Lift\Database\Connection;

$app->singleton(Connection::class, fn() => Connection::fromConfig([
    'driver'   => 'sqlite',
    'database' => __DIR__ . '/../database.sqlite',
]));

// У вашому репозиторії:
public function __construct(private readonly Connection $db) {}

public function all(): array
{
    return $this->db->table('users')->orderBy('id')->get();
}
```

Повний огляд, включно з міграціями: [База даних](database).

## Куди рухатися далі

Тепер ви знаєте достатньо, щоб побудувати справжній CRUD-сервіс. Природні наступні кроки:

| Якщо ви хочете… | Читайте |
|---|---|
| Зрозуміти кожну можливість маршрутизації | [Маршрутизація](routing) |
| Читати ввід із форм, JSON, файлів | [Request](request) |
| Надсилати HTML, ставити cookie, робити редирект | [Response](response) |
| Під’єднувати сервіси без глобалей | [DI-контейнер](container) |
| Додати автентифікацію, CORS, обмеження частоти | [Middleware](middleware), [Безпека](security) |
| Правильно валідувати ввід | [Валідація](validation) |
| Працювати з базою даних | [База даних](database) |
| Обробляти фонові задачі | [Черги](queues) |
| Писати тести | [Тестування](testing) |
| Розгорнути у продакшені | [Встановлення §6](installation#6-enable-opcache-in-production) |

[Маршрутизація →](routing)
