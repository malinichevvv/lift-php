---
layout: page
title: JSON-RPC 2.0
nav_order: 32
---

# JSON-RPC 2.0

`Lift\JsonRpc\JsonRpcServer` — це **відповідний специфікації** сервер [JSON-RPC 2.0](https://www.jsonrpc.org/specification), який можна змонтувати як обробник маршруту. Він підтримує одиночні та пакетні запити, іменовані й позиційні параметри, сповіщення, коди помилок і сканування атрибутів `#[RpcMethod]`.

> Ментальна модель: JSON-RPC — це один URL, який приймає `{"method": "...", "params": {...}}` і повертає `{"result": ...}` або `{"error": {...}}`. Жодної маршрутизації за шляхом — **ім’я методу** визначає, що викликати. Чудово для симетричних інструментів, внутрішнього RPC і коду, який простіше викликати, ніж проєктувати під URL.

## Коли використовувати JSON-RPC

- **Внутрішній трафік мікросервісів**, де дієслова REST не додають цінності.
- **API для інструментів** (плагіни IDE, мовні сервери, клієнти автоматизації).
- **Пакетні мутації** — JSON-RPC підтримує масив викликів в одному HTTP-запиті.
- **Фронтенди, які вже моделюють усе як RPC** (наприклад, `api.users.create(...)` замість `POST /users`).

Коли **не** використовувати його:

- Публічні, звернені до браузера API — REST більш кешований і більш звичний.
- Завантаження файлів / бінарний вміст — JSON-RPC кодує все в JSON.

## Приклад за 30 секунд

```php
use Lift\JsonRpc\JsonRpcServer;

$rpc = new JsonRpcServer($app->container());

$rpc->register('math.add', fn(int $a, int $b): int => $a + $b);
$rpc->register('math.mul', fn(int $a, int $b): int => $a * $b);

$app->post('/rpc', $rpc);   // сервер викликаний
```

Викличте його:

```bash
curl -X POST http://localhost:8000/rpc \
    -H 'Content-Type: application/json' \
    -d '{"jsonrpc":"2.0","method":"math.add","params":{"a":2,"b":3},"id":1}'

# {"jsonrpc":"2.0","result":5,"id":1}
```

Об’єкт `$rpc` **є** обробником маршруту — Lift викликає `$rpc->__invoke($req)` за вас.

## Реєстрація методів

Два стилі. Змішуйте вільно.

### Форма callable

```php
$rpc->register('users.find',  fn(int $id) => $userRepo->find($id));
$rpc->register('users.list',  [UserService::class, 'list']);   // розв’язується контейнером
$rpc->register('users.echo',  $someClosure);
```

### Форма атрибутів (`#[RpcMethod]`)

Згрупуйте пов’язані методи на класі-сервісі:

```php
use Lift\JsonRpc\Attribute\RpcMethod;

final class MathService
{
    public function __construct(private readonly Cache $cache) {}

    #[RpcMethod('math.add')]
    public function add(int $a, int $b): int { return $a + $b; }

    #[RpcMethod('math.mul')]
    public function mul(int $a, int $b): int { return $a * $b; }

    #[RpcMethod]   // ім’я за замовчуванням — "MathService.div"
    public function div(int $a, int $b): float { return $a / $b; }
}

$rpc->registerService(MathService::class);
```

`registerService(...)`:

1. Рефлексує клас.
2. Для кожного публічного методу з `#[RpcMethod]` реєструє `[$instance, 'method']`.
3. Клас будується через [контейнер](container), тому залежності його конструктора автозв’язуються.

Огляньте, що зареєстровано:

```php
$rpc->methods();   // ['math.add', 'math.mul', 'MathService.div']
```

## Угоди про виклики

JSON-RPC підтримує **іменовані** та **позиційні** параметри. Lift обробляє обидва прозоро — ваша PHP-сигнатура залишається тією самою.

```php
$rpc->register('users.find', fn(int $id, bool $includeProfile = false) => …);
```

Іменовані:

```json
{"jsonrpc":"2.0","method":"users.find","params":{"id":42,"includeProfile":true},"id":1}
```

Позиційні:

```json
{"jsonrpc":"2.0","method":"users.find","params":[42, true],"id":1}
```

Якщо обов’язковий параметр відсутній, відповідь — це структурована помилка:

```json
{"jsonrpc":"2.0","error":{"code":-32602,"message":"Missing required parameter: $id"},"id":1}
```

Необов’язкові параметри використовують значення за замовчуванням PHP; невідомі ключі JSON ігноруються.

### Приведення типів

Для вбудованих скалярних типів параметрів (`int`, `float`, `string`, `bool`, `array`) сервер приводить JSON-значення перед викликом. Тож клієнт, що надсилає `{"a":"3"}` у `math.add(int $a, …)`, отримує `int(3)`, а не помилку типу.

Об’єктні типи (`User $u` тощо) передаються без змін — JSON-значення залишається `stdClass` / масивом. Ви можете гідрувати його самі всередині методу.

## Сповіщення

Запит **без** поля `id` — це *сповіщення*: клієнт не хоче відповіді.

```json
{"jsonrpc":"2.0","method":"audit.log","params":{"event":"login","user":42}}
```

Сервер:

1. Викликає метод як зазвичай.
2. Не повертає **тіла відповіді** (HTTP 204).
3. **Проковтує** будь-які помилки — клієнти їх не бачать.

Використовуйте сповіщення для побічних ефектів «надіслав і забув».

## Пакетні запити

JSON-масив пакує кілька викликів в один HTTP-запит:

```json
[
  {"jsonrpc":"2.0","method":"math.add","params":[1,2],"id":1},
  {"jsonrpc":"2.0","method":"math.mul","params":[2,3],"id":2},
  {"jsonrpc":"2.0","method":"notify.something","params":{}}
]
```

Відповідь — це масив відповідей у довільному порядку, з **опущеними** сповіщеннями:

```json
[
  {"jsonrpc":"2.0","result":3,"id":1},
  {"jsonrpc":"2.0","result":6,"id":2}
]
```

Клієнти зіставляють за `id`. Якщо кожен виклик у пакеті — сповіщення, сервер повертає `204 No Content`.

## Коди помилок

Стандарт JSON-RPC резервує кілька кодів:

| Код      | Значення                                 | Коли Lift його повертає             |
|----------|------------------------------------------|-------------------------------------|
| `-32700` | Помилка розбору — некоректний JSON       | Тіло запиту не парситься як JSON    |
| `-32600` | Невірний запит — спотворений конверт RPC | Відсутній `jsonrpc` / `method`      |
| `-32601` | Метод не знайдено                        | Невідоме ім’я методу                |
| `-32602` | Невірні параметри                        | Відсутній обов’язковий PHP-параметр |
| `-32603` | Внутрішня помилка                        | Метод викинув непередбачений виняток |

Власні помилки приходять із винятків, які ви викидаєте всередині методу. Lift загортає їх як `JsonRpcError::fromException($e, $debug)`:

```php
$rpc->register('users.find', function (int $id) use ($repo) {
    $user = $repo->find($id);
    if ($user === null) {
        throw new \InvalidArgumentException("User not found", JsonRpcError::INVALID_PARAMS);
    }
    return $user;
});
```

Використовуйте поле **code** винятку для коду помилки RPC. **Message** — це повідомлення для користувача; якщо ввімкнено `setDebug(true)`, може розкриватися додаткова налагоджувальна інформація — залишайте його **вимкненим у продакшені**.

```php
$rpc->setDebug($app->environment() === 'local');
```

## Реальний приклад

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

Клієнт (JS):

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

## Автентифікація та middleware

Монтуйте RPC-маршрут як будь-який інший — middleware застосовується звичайним чином:

```php
$app->post('/rpc', $rpc)->middleware(JwtMiddleware::class);
```

Автентифікація на метод (наприклад, *«лише адміни можуть викликати `users.delete`»*) живе всередині методу:

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

Lift передасть поточний `Request` у будь-який параметр, типізований як `ServerRequestInterface` або `Request` (без спеціального ввімкнення).

## Тестування

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

Помилки JSON-RPC повертаються з HTTP **200** — це за специфікацією. Помилка в тілі, не в статусі. Не піддавайтеся спокусі відобразити їх на коди 4xx.

## Порівняння з REST

| Аспект                    | REST                        | JSON-RPC                            |
|---------------------------|-----------------------------|-------------------------------------|
| Проєктування URL          | Один URL на ресурс          | Один URL на весь API                |
| Дієслова                  | GET / POST / PUT / DELETE   | Усі POST (ім’я методу в тілі)       |
| Помилки                   | HTTP-коди стану             | `error.code` у тілі, HTTP 200       |
| Кешування                 | Вбудовано через GET + заголовки | Вручну, у клієнті               |
| Пакетування               | Вручну                      | Вбудовано (запит-масив)             |
| Виявлюваність             | Можна «переглядати»         | Потрібна окрема документація / OpenAPI |
| Інструментарій            | Postman / curl /…           | Трохи менш поширений                |

Обидва валідні. Використовуйте той, що краще моделює вашу задачу. Можна також змонтувати обидва — REST для браузерів, RPC на `/rpc` для внутрішніх сервісів.

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| 404 на RPC-запитах | Змонтовано як GET замість POST | `$app->post('/rpc', $rpc)`. Сервер обробляє лише POST. |
| `Method not found` для методу, який ви зареєстрували | Невідповідність написання (`math.Add` vs `math.add`) | Імена методів RPC регістрозалежні. |
| `Missing required parameter: $foo`, хоча я надіслав `foo` | Параметр названо інакше в PHP — рефлексія використовує ім’я PHP | Зіставте ключ JSON з іменем PHP-параметра (або використовуйте позиційні). |
| Сповіщення загадково нічого не роблять | Метод виконався, але його повернення відкинуто | Це правильно — сповіщення ніколи не отримують відповіді. |
| Внутрішній виняток розкриває деталі БД клієнту | Увімкнено `setDebug(true)` | Вимкніть у продакшені. |
| Повертає 422 / 400 — але специфікація каже 200 | Ви перехоплюєте виняток і конвертуєте | Не робіть цього — дайте серверу видати правильний конверт помилки RPC. |

## Шпаргалка

```php
// Побудувати
$rpc = new JsonRpcServer($app->container());
$rpc->register('foo.bar', $callable);
$rpc->registerService(MyService::class);          // сканує #[RpcMethod]
$rpc->setDebug(false);

// Змонтувати
$app->post('/rpc', $rpc);

// Конверт запиту
{
  "jsonrpc": "2.0",
  "method":  "foo.bar",
  "params":  {"name":"Alice"} | [42, true],
  "id":      1                                    // опустити для сповіщення
}

// Конверт відповіді
{ "jsonrpc": "2.0", "result": …, "id": 1 }
{ "jsonrpc": "2.0", "error": {"code": -32601, "message": "…"}, "id": 1 }
```

[OpenAPI →](openapi)
