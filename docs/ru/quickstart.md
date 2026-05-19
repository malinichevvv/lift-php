---
layout: page
title: Быстрый старт
nav_order: 3
---

# Быстрый старт

К концу этой страницы вы построите небольшой JSON-API с несколькими маршрутами, валидацией параметров, внедрением зависимостей и классом-контроллером — используя только то, что входит в поставку Lift.

Начнём с буквально однострочника и постепенно вырастим его во что-то, узнаваемое по реальному сервису.

## Этап 0 — Hello, World

Если вы прошли [Установку](installation), `public/index.php` уже выглядит так:

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

Запустите:

```bash
php -S 127.0.0.1:8000 -t public
curl http://127.0.0.1:8000/
# {"message":"Hello, World!"}
```

Три вещи, на которые стоит обратить внимание:

1. `new App()` — никакой фабрики, никакого билдера. Приложение — это просто объект.
2. `$app->get('/', $handler)` — `$handler` может быть **чем угодно вызываемым**: замыканием, `[Class::class, 'method']` или именем invokable-класса.
3. `Response::json([...])` — один из нескольких фабричных сокращений. Все они описаны в [Response](response).

> Если ваш обработчик возвращает `array`, Lift автоматически оборачивает его в `Response::json(...)`. Так что `fn() => ['ok' => true]` тоже работает — см. [автоматическое преобразование ответа](response#auto-conversion). В остальной части руководства мы будем явными и используем `Response::json(...)`.

## Этап 1 — Несколько маршрутов

```php
$app->get('/',          fn() => Response::json(['message' => 'Hello, World!']));
$app->get('/health',    fn() => Response::json(['ok' => true]));
$app->get('/version',   fn() => Response::json(['version' => '1.0.0']));

// POST / PUT / PATCH / DELETE работают точно так же
$app->post('/echo', function (\Lift\Http\Request $req) {
    return Response::json(['you_sent' => $req->json()]);
});
```

Быстрая проверка:

```bash
curl -X POST -H 'Content-Type: application/json' \
     -d '{"foo":"bar"}' \
     http://127.0.0.1:8000/echo
# {"you_sent":{"foo":"bar"}}
```

Обратите внимание на параметр замыкания `\Lift\Http\Request $req`. Lift **автоматически внедряет** текущий запрос в любой обработчик, который его запрашивает. Передавать его вручную не нужно.

## Этап 2 — Параметры маршрута

Всё, что внутри `{...}`, становится параметром, который можно прочитать через `$req->param(...)`:

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

Заметьте, что `id` приходит как **строка** — именно это даёт вам HTTP. Приводите тип сами:

```php
$id = (int) $req->param('id');
```

Параметр также можно ограничить regex-шаблоном. Двоеточие отделяет имя от шаблона:

```php
$app->get('/posts/{id:\d+}',       $handler);   // только цифры
$app->get('/articles/{slug:[a-z0-9-]+}', $handler); // строчные буквы + дефисы
```

Если URL не соответствует шаблону, Lift возвращает 404 — обработчик не вызывается.

## Этап 3 — Крошечный REST API в памяти

Построим что-то близкое к настоящему CRUD-эндпоинту, используя в качестве «базы данных» обычный массив PHP:

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

// Просмотр
$app->get('/users/{id:\d+}', function (Request $req) use (&$users) {
    $id = (int) $req->param('id');
    if (!isset($users[$id])) {
        return Response::json(['error' => 'User not found'], 404);
    }
    return Response::json($users[$id]);
});

// Создание
$app->post('/users', function (Request $req) use (&$users, &$nextId) {
    $name = $req->json()['name'] ?? null;
    if (!is_string($name) || $name === '') {
        return Response::json(['error' => 'name is required'], 422);
    }
    $id = $nextId++;
    $users[$id] = ['id' => $id, 'name' => $name];
    return Response::json($users[$id], 201);
});

// Обновление
$app->put('/users/{id:\d+}', function (Request $req) use (&$users) {
    $id = (int) $req->param('id');
    if (!isset($users[$id])) {
        return Response::json(['error' => 'User not found'], 404);
    }
    $users[$id]['name'] = $req->json()['name'] ?? $users[$id]['name'];
    return Response::json($users[$id]);
});

// Удаление
$app->delete('/users/{id:\d+}', function (Request $req) use (&$users) {
    unset($users[(int) $req->param('id')]);
    return Response::noContent(); // 204
});

$app->run();
```

Попробуйте:

```bash
curl    http://127.0.0.1:8000/users
curl    http://127.0.0.1:8000/users/1
curl -X POST   -H 'Content-Type: application/json' -d '{"name":"Carol"}' http://127.0.0.1:8000/users
curl -X PUT    -H 'Content-Type: application/json' -d '{"name":"Bobby"}' http://127.0.0.1:8000/users/2
curl -X DELETE http://127.0.0.1:8000/users/1
```

Что вы могли упустить:

- `$req->json()` возвращает декодированное тело JSON в виде ассоциативного массива. Всегда.
- `Response::json($data, 201)` позволяет задать собственный код состояния.
- `Response::noContent()` — это сокращение для HTTP 204.
- `Response::json(['error' => ...], 422)` — общепринятая форма для ошибок валидации.

## Этап 4 — Валидация, простой способ

Писать вручную проверки `if (!$name)` быстро надоедает. В Lift есть валидатор. Самый короткий способ его применить — `$req->validate([...])`:

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

`try/catch` на самом деле не обязателен: если `ValidationException` покидает обработчик, обработчик ошибок Lift по умолчанию преобразует его в 422 с той же формой `errors`. Полный список правил находится в [Валидации](validation).

## Этап 5 — Контроллеры и внедрение зависимостей

Замыкания в `index.php` годятся для 10 маршрутов. Дальше вам понадобятся классы. Контейнер Lift создаст их и внедрит их зависимости автоматически.

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
    // 👇 Lift автоматически связывает это через контейнер — вы никогда не создаёте UserController сами.
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

Сообщите Composer о пространстве имён — добавьте это в `composer.json` и выполните `composer dump-autoload`:

```json
"autoload": {
    "psr-4": { "App\\": "src/" }
}
```

Наконец, подключите маршруты в `public/index.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Lift\App;
use App\UserController;
use App\UserRepository;

$app = new App();

// Кэшируем репозиторий на время жизни запроса
$app->singleton(UserRepository::class);

$app->get   ('/users',          [UserController::class, 'index']);
$app->get   ('/users/{id:\d+}', [UserController::class, 'show']);
$app->post  ('/users',          [UserController::class, 'store']);

$app->run();
```

Вот и всё. Обратите внимание, что:

- Вы никогда явно не создаёте `UserController` или `UserRepository` — это делает контейнер через [автосвязывание](container#autowiring).
- `$app->singleton(UserRepository::class)` говорит контейнеру «создай один и переиспользуй». Без этой строки вы получали бы новый репозиторий на каждый вызов (и теряли бы данные в памяти).
- `[UserController::class, 'index']` — это стандартный для PHP callable вида `[$class, $method]`. Lift разрешает класс через контейнер, а затем вызывает метод.

## Этап 6 — Группировка маршрутов

Большинство API версионируют свои эндпоинты под `/api/v1/...`. Группы берут префикс на себя:

```php
$app->group('/api/v1', function ($group) {
    $group->get   ('/users',          [UserController::class, 'index']);
    $group->get   ('/users/{id:\d+}', [UserController::class, 'show']);
    $group->post  ('/users',          [UserController::class, 'store']);
});
```

Группы также принимают middleware, префиксы для именованных маршрутов и могут быть вложенными. См. [Маршрутизация](routing#route-groups).

## Этап 7 — Добавление middleware

Допустим, вы хотите, чтобы каждый ответ нёс заголовок `X-Request-Id`. Middleware — правильное для этого место.

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

$app->use(RequestIdMiddleware::class);  // глобально — выполняется на каждом запросе
```

У маршрутов также может быть **помаршрутный** или **погрупповой** middleware. См. [Middleware](middleware).

## Этап 8 — Подключение настоящего хранилища

Замените массив в памяти на SQLite (или MySQL/Postgres) менее чем в 20 строк:

```php
use Lift\Database\Connection;

$app->singleton(Connection::class, fn() => Connection::fromConfig([
    'driver'   => 'sqlite',
    'database' => __DIR__ . '/../database.sqlite',
]));

// В вашем репозитории:
public function __construct(private readonly Connection $db) {}

public function all(): array
{
    return $this->db->table('users')->orderBy('id')->get();
}
```

Полное руководство, включая миграции: [База данных](database).

## Куда двигаться дальше

Теперь вы знаете достаточно, чтобы построить настоящий CRUD-сервис. Естественные следующие шаги:

| Если вы хотите… | Читайте |
|---|---|
| Понять каждую возможность маршрутизации | [Маршрутизация](routing) |
| Читать ввод из форм, JSON, файлов | [Request](request) |
| Отправлять HTML, ставить cookie, делать редирект | [Response](response) |
| Подключать сервисы без глобалей | [DI-контейнер](container) |
| Добавить аутентификацию, CORS, ограничение частоты | [Middleware](middleware), [Безопасность](security) |
| Правильно валидировать ввод | [Валидация](validation) |
| Работать с базой данных | [База данных](database) |
| Обрабатывать фоновые задачи | [Очереди](queues) |
| Писать тесты | [Тестирование](testing) |
| Развернуть в продакшене | [Установка §6](installation#6-enable-opcache-in-production) |

[Маршрутизация →](routing)
