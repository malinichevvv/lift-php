---
layout: page
title: JSON-RPC 2.0
nav_order: 32
---

# JSON-RPC 2.0

`Lift\JsonRpc\JsonRpcServer` — это **соответствующий спецификации** сервер [JSON-RPC 2.0](https://www.jsonrpc.org/specification), который можно смонтировать как обработчик маршрута. Он поддерживает одиночные и пакетные запросы, именованные и позиционные параметры, уведомления, коды ошибок и сканирование атрибутов `#[RpcMethod]`.

> Ментальная модель: JSON-RPC — это один URL, который принимает `{"method": "...", "params": {...}}` и возвращает `{"result": ...}` или `{"error": {...}}`. Никакой маршрутизации по пути — **имя метода** определяет, что вызвать. Отлично для симметричных инструментов, внутреннего RPC и кода, который проще вызывать, чем проектировать под URL.

## Когда использовать JSON-RPC

- **Внутренний трафик микросервисов**, где глаголы REST не добавляют ценности.
- **API для инструментов** (плагины IDE, языковые серверы, клиенты автоматизации).
- **Пакетные мутации** — JSON-RPC поддерживает массив вызовов в одном HTTP-запросе.
- **Фронтенды, которые уже моделируют всё как RPC** (например, `api.users.create(...)` вместо `POST /users`).

Когда **не** использовать его:

- Публичные, обращённые к браузеру API — REST более кэшируем и более привычен.
- Загрузка файлов / бинарное содержимое — JSON-RPC кодирует всё в JSON.

## Пример за 30 секунд

```php
use Lift\JsonRpc\JsonRpcServer;

$rpc = new JsonRpcServer($app->container());

$rpc->register('math.add', fn(int $a, int $b): int => $a + $b);
$rpc->register('math.mul', fn(int $a, int $b): int => $a * $b);

$app->post('/rpc', $rpc);   // сервер вызываем
```

Вызовите его:

```bash
curl -X POST http://localhost:8000/rpc \
    -H 'Content-Type: application/json' \
    -d '{"jsonrpc":"2.0","method":"math.add","params":{"a":2,"b":3},"id":1}'

# {"jsonrpc":"2.0","result":5,"id":1}
```

Объект `$rpc` **является** обработчиком маршрута — Lift вызывает `$rpc->__invoke($req)` за вас.

## Регистрация методов

Два стиля. Смешивайте свободно.

### Форма callable

```php
$rpc->register('users.find',  fn(int $id) => $userRepo->find($id));
$rpc->register('users.list',  [UserService::class, 'list']);   // разрешается контейнером
$rpc->register('users.echo',  $someClosure);
```

### Форма атрибутов (`#[RpcMethod]`)

Сгруппируйте связанные методы на классе-сервисе:

```php
use Lift\JsonRpc\Attribute\RpcMethod;

final class MathService
{
    public function __construct(private readonly Cache $cache) {}

    #[RpcMethod('math.add')]
    public function add(int $a, int $b): int { return $a + $b; }

    #[RpcMethod('math.mul')]
    public function mul(int $a, int $b): int { return $a * $b; }

    #[RpcMethod]   // имя по умолчанию — "MathService.div"
    public function div(int $a, int $b): float { return $a / $b; }
}

$rpc->registerService(MathService::class);
```

`registerService(...)`:

1. Рефлексирует класс.
2. Для каждого публичного метода с `#[RpcMethod]` регистрирует `[$instance, 'method']`.
3. Класс строится через [контейнер](container), поэтому зависимости его конструктора автосвязываются.

Осмотрите, что зарегистрировано:

```php
$rpc->methods();   // ['math.add', 'math.mul', 'MathService.div']
```

## Соглашения о вызовах

JSON-RPC поддерживает **именованные** и **позиционные** параметры. Lift обрабатывает оба прозрачно — ваша PHP-сигнатура остаётся той же.

```php
$rpc->register('users.find', fn(int $id, bool $includeProfile = false) => …);
```

Именованные:

```json
{"jsonrpc":"2.0","method":"users.find","params":{"id":42,"includeProfile":true},"id":1}
```

Позиционные:

```json
{"jsonrpc":"2.0","method":"users.find","params":[42, true],"id":1}
```

Если обязательный параметр отсутствует, ответ — это структурированная ошибка:

```json
{"jsonrpc":"2.0","error":{"code":-32602,"message":"Missing required parameter: $id"},"id":1}
```

Необязательные параметры используют значение по умолчанию PHP; неизвестные ключи JSON игнорируются.

### Приведение типов

Для встроенных скалярных типов параметров (`int`, `float`, `string`, `bool`, `array`) сервер приводит JSON-значение перед вызовом. Так что клиент, отправляющий `{"a":"3"}` в `math.add(int $a, …)`, получает `int(3)`, а не ошибку типа.

Объектные типы (`User $u` и т. д.) передаются без изменений — JSON-значение остаётся `stdClass` / массивом. Вы можете гидрировать его сами внутри метода.

## Уведомления

Запрос **без** поля `id` — это *уведомление*: клиент не хочет ответа.

```json
{"jsonrpc":"2.0","method":"audit.log","params":{"event":"login","user":42}}
```

Сервер:

1. Вызывает метод как обычно.
2. Не возвращает **тела ответа** (HTTP 204).
3. **Проглатывает** любые ошибки — клиенты их не видят.

Используйте уведомления для побочных эффектов «отправил и забыл».

## Пакетные запросы

JSON-массив упаковывает несколько вызовов в один HTTP-запрос:

```json
[
  {"jsonrpc":"2.0","method":"math.add","params":[1,2],"id":1},
  {"jsonrpc":"2.0","method":"math.mul","params":[2,3],"id":2},
  {"jsonrpc":"2.0","method":"notify.something","params":{}}
]
```

Ответ — это массив ответов в произвольном порядке, с **опущенными** уведомлениями:

```json
[
  {"jsonrpc":"2.0","result":3,"id":1},
  {"jsonrpc":"2.0","result":6,"id":2}
]
```

Клиенты сопоставляют по `id`. Если каждый вызов в пакете — уведомление, сервер возвращает `204 No Content`.

## Коды ошибок

Стандарт JSON-RPC резервирует несколько кодов:

| Код      | Значение                                 | Когда Lift его возвращает           |
|----------|------------------------------------------|-------------------------------------|
| `-32700` | Ошибка разбора — некорректный JSON       | Тело запроса не парсится как JSON   |
| `-32600` | Неверный запрос — искажённый конверт RPC | Отсутствует `jsonrpc` / `method`    |
| `-32601` | Метод не найден                          | Неизвестное имя метода              |
| `-32602` | Неверные параметры                       | Отсутствует обязательный PHP-параметр |
| `-32603` | Внутренняя ошибка                        | Метод выбросил непредвиденное исключение |

Собственные ошибки приходят из исключений, которые вы выбрасываете внутри метода. Lift оборачивает их как `JsonRpcError::fromException($e, $debug)`:

```php
$rpc->register('users.find', function (int $id) use ($repo) {
    $user = $repo->find($id);
    if ($user === null) {
        throw new \InvalidArgumentException("User not found", JsonRpcError::INVALID_PARAMS);
    }
    return $user;
});
```

Используйте поле **code** исключения для кода ошибки RPC. **Message** — это сообщение для пользователя; если включён `setDebug(true)`, может раскрываться дополнительная отладочная информация — оставляйте его **выключенным в продакшене**.

```php
$rpc->setDebug($app->environment() === 'local');
```

## Реальный пример

```php
use Lift\JsonRpc\Attribute\RpcMethod;

#[\Lift\JsonRpc\Attribute\RpcService]
final class TaskService
{
    public function __construct(private readonly TaskRepository $repo) {}

    #[RpcMethod('tasks.list')]
    public function list(?string $status = null): array
    {
        return $this->repo->listByStatus($status);
    }

    #[RpcMethod('tasks.create')]
    public function create(string $title, ?string $description = null): array
    {
        $id = $this->repo->create(['title' => $title, 'description' => $description]);
        return $this->repo->find($id);
    }

    #[RpcMethod('tasks.complete')]
    public function complete(int $id): bool
    {
        return $this->repo->complete($id) > 0;
    }
}

$rpc = new JsonRpcServer($app->container());
$rpc->registerService(TaskService::class);
$app->post('/rpc', $rpc);
```

Клиент (JS):

```js
async function rpc(method, params) {
    const r = await fetch('/rpc', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ jsonrpc: '2.0', method, params, id: 1 }),
    });
    const body = await r.json();
    if (body.error) throw new Error(body.error.message);
    return body.result;
}

await rpc('tasks.create', { title: 'Write docs' });
await rpc('tasks.list',   { status: 'open' });
```

## Аутентификация и middleware

Монтируйте RPC-маршрут как любой другой — middleware применяется обычным образом:

```php
$app->post('/rpc', $rpc)->middleware(JwtMiddleware::class);
```

Аутентификация на метод (например, *«только админы могут вызывать `users.delete`»*) живёт внутри метода:

```php
#[RpcMethod('users.delete')]
public function delete(int $id, ServerRequestInterface $req): bool
{
    $claims = $req->getAttribute('jwt');
    if (($claims['role'] ?? '') !== 'admin') {
        throw new \RuntimeException('Forbidden', \Lift\JsonRpc\JsonRpcError::INVALID_PARAMS);
    }
    return $this->repo->delete($id) > 0;
}
```

Lift передаст текущий `Request` в любой параметр, типизированный как `ServerRequestInterface` или `Request` (без специального включения).

## Тестирование

```php
public function testAddsTwoNumbers(): void
{
    $this->postJson('/rpc', [
        'jsonrpc' => '2.0',
        'method'  => 'math.add',
        'params'  => ['a' => 2, 'b' => 3],
        'id'      => 1,
    ])
    ->assertOk()
    ->assertJson(['jsonrpc' => '2.0', 'result' => 5, 'id' => 1]);
}

public function testMethodNotFoundReturnsError(): void
{
    $this->postJson('/rpc', [
        'jsonrpc' => '2.0',
        'method'  => 'does.not.exist',
        'id'      => 1,
    ])
    ->assertOk()
    ->assertJsonPath('error.code', -32601);
}
```

Ошибки JSON-RPC возвращаются с HTTP **200** — это по спецификации. Ошибка в теле, не в статусе. Не поддавайтесь искушению отобразить их на коды 4xx.

## Сравнение с REST

| Аспект                    | REST                        | JSON-RPC                            |
|---------------------------|-----------------------------|-------------------------------------|
| Проектирование URL        | Один URL на ресурс          | Один URL на весь API                |
| Глаголы                   | GET / POST / PUT / DELETE   | Все POST (имя метода в теле)        |
| Ошибки                    | HTTP-коды состояния         | `error.code` в теле, HTTP 200       |
| Кэширование               | Встроено через GET + заголовки | Вручную, в клиенте              |
| Пакетирование             | Вручную                     | Встроено (запрос-массив)            |
| Обнаруживаемость          | Можно «просматривать»       | Нужна отдельная документация / OpenAPI |
| Инструментарий            | Postman / curl /…           | Чуть менее распространён            |

Оба валидны. Используйте тот, что лучше моделирует вашу задачу. Можно также смонтировать оба — REST для браузеров, RPC на `/rpc` для внутренних сервисов.

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| 404 на RPC-запросах | Смонтировано как GET вместо POST | `$app->post('/rpc', $rpc)`. Сервер обрабатывает только POST. |
| `Method not found` для метода, который вы зарегистрировали | Несовпадение написания (`math.Add` vs `math.add`) | Имена методов RPC регистрозависимы. |
| `Missing required parameter: $foo`, хотя я отправил `foo` | Параметр назван иначе в PHP — рефлексия использует имя PHP | Сопоставьте ключ JSON с именем PHP-параметра (или используйте позиционные). |
| Уведомления загадочно ничего не делают | Метод выполнился, но его возврат отброшен | Это правильно — уведомления никогда не получают ответа. |
| Внутреннее исключение раскрывает детали БД клиенту | Включён `setDebug(true)` | Отключите в продакшене. |
| Возвращает 422 / 400 — но спецификация говорит 200 | Вы перехватываете исключение и конвертируете | Не делайте этого — дайте серверу выдать правильный конверт ошибки RPC. |

## Шпаргалка

```php
// Построить
$rpc = new JsonRpcServer($app->container());
$rpc->register('foo.bar', $callable);
$rpc->registerService(MyService::class);          // сканирует #[RpcMethod]
$rpc->setDebug(false);

// Смонтировать
$app->post('/rpc', $rpc);

// Конверт запроса
{
  "jsonrpc": "2.0",
  "method":  "foo.bar",
  "params":  {"name":"Alice"} | [42, true],
  "id":      1                                    // опустить для уведомления
}

// Конверт ответа
{ "jsonrpc": "2.0", "result": …, "id": 1 }
{ "jsonrpc": "2.0", "error": {"code": -32601, "message": "…"}, "id": 1 }
```

[OpenAPI →](openapi)
