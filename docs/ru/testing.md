---
layout: page
title: Тестирование
nav_order: 13
---

# Тестирование

Lift был спроектирован с прицелом на тестируемость с первого дня. Два проектных решения делают тесты тривиальными:

1. **`App::handle($request): Response` чист** — по запросу он возвращает ответ, ни разу не трогая `$_SERVER`, буферы вывода или заголовки PHP.
2. **Всё внедряется через конструктор** — вы можете подменить любой сервис фейком, привязав его до запуска запроса.

Вдобавок Lift поставляет крошечный базовый класс PHPUnit — `Lift\Testing\TestCase` — с текучим API утверждений. Вы будете писать интеграционные тесты целых HTTP-маршрутов в 5 строк.

## Настройка

В `composer.json`:

```json
"require-dev": {
    "phpunit/phpunit": "^11.0"
},
"autoload-dev": {
    "psr-4": { "Tests\\": "tests/" }
}
```

`phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" colors="true">
    <testsuites>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

Запуск:

```bash
vendor/bin/phpunit
```

## Ваш первый feature-тест

```php
<?php

namespace Tests\Feature;

use Lift\App;
use Lift\Http\Response;
use Lift\Testing\TestCase;

final class HelloTest extends TestCase
{
    protected function createApp(): App
    {
        $app = new App();
        $app->get('/hello/{name}', fn($req) => Response::json([
            'message' => 'Hello, ' . $req->param('name'),
        ]));
        return $app;
    }

    public function testItGreets(): void
    {
        $this->get('/hello/Alice')
             ->assertOk()
             ->assertJson(['message' => 'Hello, Alice']);
    }
}
```

`createApp()` вызывается из `setUp()` и сохраняется в `$this->app`. Переопределите его один раз на класс теста.

## HTTP-помощники

```php
$this->get   ('/users');
$this->post  ('/users', ['name' => 'Alice']);
$this->put   ('/users/1', ['name' => 'Bobby']);
$this->patch ('/users/1', ['name' => 'Carol']);
$this->delete('/users/1');

// Всегда-JSON варианты:
$this->getJson ('/users');                 // отправляет Accept: application/json, утверждает 200
$this->postJson('/users', ['name' => 'A']);

// Собственные заголовки:
$this->get('/users', ['Authorization' => 'Bearer ' . $token]);
$this->post('/orders', ['sku' => 'ABC'], ['X-Idempotency-Key' => 'k1']);
```

Массивы тела кодируются в JSON автоматически. Чтобы отправить что-то иное, постройте запрос вручную:

```php
$req = new \Lift\Http\Request('POST', new \Lift\Http\Uri('/upload'),
    headers: ['Content-Type' => 'multipart/form-data'],
    body: \Lift\Http\Stream::fromString($rawMultipart),
);
$response = $this->app->handle($req);
```

## API утверждений

Каждый помощник возвращает `TestResponse`, все методы которого сцепляются:

```php
$this->post('/api/users', ['name' => 'Alice', 'email' => 'a@b.c'])
     ->assertCreated()
     ->assertContentType('application/json')
     ->assertHeader('Location', '/api/users/1')
     ->assertJson(['name' => 'Alice'])
     ->assertJsonHas('id')
     ->assertJsonPath('email', 'a@b.c');
```

### Утверждения статуса

| Метод                      | Что проверяет                        |
|----------------------------|--------------------------------------|
| `assertStatus(int $code)`  | Точный код состояния                 |
| `assertOk()`               | 200                                  |
| `assertCreated()`          | 201                                  |
| `assertNoContent()`        | 204                                  |
| `assertRedirect($url?)`    | 3xx, опционально с `Location`        |
| `assertUnauthorized()`     | 401                                  |
| `assertForbidden()`        | 403                                  |
| `assertNotFound()`         | 404                                  |
| `assertUnprocessable()`    | 422                                  |

### Утверждения заголовков

| Метод                                         | Что проверяет                               |
|-----------------------------------------------|---------------------------------------------|
| `assertHeader(string $name, ?string $value)`  | Заголовок существует (и равен значению, если задано) |
| `assertContentType(string $type)`             | `Content-Type` содержит заданный media-тип  |

### Утверждения тела

| Метод                                                    | Что проверяет                                                    |
|----------------------------------------------------------|------------------------------------------------------------------|
| `assertSee(string $text)`                                | Сырое тело **содержит** строку                                   |
| `assertDontSee(string $text)`                            | Сырое тело **не содержит** строку                                |
| `assertJson(array $expected, bool $exact = false)`       | JSON-тело совпадает с ожидаемыми парами (частично по умолчанию)  |
| `assertJsonHas(string $key)`                             | JSON-тело имеет ключ с точечной нотацией (`'user.email'`)        |
| `assertJsonPath(string $path, mixed $expected)`          | Путь с точечной нотацией равен ожидаемому значению               |
| `assertJsonCount(int $count, ?string $key = null)`       | Тело (или `$key`) — массив из ровно `$count` элементов           |

### Сырые аксессоры (аварийные выходы)

```php
$response = $this->get('/x');

$response->status();        // int
$response->body();          // string
$response->json();          // array (выбрасывает исключение, если не JSON)
$response->header('X-Foo'); // ?string (первое значение)
$response->getResponse();   // нижележащий Lift\Http\Response
```

## Подмена сервисов фейками

Весь DI-контейнер у вас под рукой внутри `createApp()`:

```php
protected function createApp(): App
{
    $app = new App();

    // Заменить настоящий mailer на фейк в памяти
    $this->mailer = new InMemoryMailer();
    $app->instance(Mailer::class, $this->mailer);

    // Заглушить клиент стороннего API
    $app->instance(GithubClient::class, new FakeGithubClient([
        'octocat' => ['name' => 'The Cat'],
    ]));

    require __DIR__ . '/../../routes/web.php';   // ваша обычная регистрация маршрутов
    return $app;
}

public function testSignupSendsEmail(): void
{
    $this->postJson('/signup', ['email' => 'a@b.c', 'password' => 'secret'])
         ->assertCreated();

    self::assertCount(1, $this->mailer->sent);
    self::assertSame('a@b.c', $this->mailer->sent[0]->to);
}
```

`$app->instance(...)` помещает *уже построенный* объект в контейнер; больше ничего не меняется. Обработчик разрешает ваш фейк автоматически.

## Тесты базы данных с SQLite

Настоящая база данных без настоящей базы данных:

```php
protected function createApp(): App
{
    $app = new App();

    $app->singleton(Connection::class, fn() => Connection::fromConfig([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));

    // Построить схему один раз на тест:
    $db = $app->make(Connection::class);
    $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

    require __DIR__ . '/../../routes/web.php';
    return $app;
}

public function testCreateUser(): void
{
    $this->postJson('/users', ['name' => 'Alice'])
         ->assertCreated()
         ->assertJson(['id' => 1, 'name' => 'Alice']);
}
```

Для бо́льших схем запускайте свои миграции против БД в памяти:

```php
(new \Lift\Database\Migrator($db, __DIR__ . '/../../database/migrations'))->migrate();
```

Каждый тест получает свежий `:memory:` SQLite — идеально изолированный, молниеносно быстрый.

## Сессии и аутентификация в тестах

Используйте хранилище в памяти и засейте сессию до диспетчеризации:

```php
use Lift\Http\Session\ArraySessionStore;
use Lift\Http\Session\Session;
use Lift\Http\Session\SessionMiddleware;

protected function createApp(): App
{
    $app = new App();
    $this->session = new Session(new ArraySessionStore());
    $app->use(new SessionMiddleware($this->session));
    require __DIR__ . '/../../routes/web.php';
    return $app;
}

public function testProtectedRoute(): void
{
    // «Залогинить пользователя», записав user_id напрямую
    $this->session->set('user_id', 42);
    $this->getJson('/dashboard')->assertOk();
}
```

Для маршрутов, защищённых JWT, отчеканьте токен напрямую:

```php
$token = $this->app->make(\Lift\Jwt\Jwt::class)->encode(['sub' => 42]);
$this->get('/me', ['Authorization' => "Bearer $token"])->assertOk();
```

## Чистые юнит-тесты

Для классов без HTTP-контекста — сервисов, валидаторов, кодировщиков — обычный `TestCase` PHPUnit (`PHPUnit\Framework\TestCase`) — правильная база. Никакого участия Lift вообще:

```php
final class PriceCalculatorTest extends \PHPUnit\Framework\TestCase
{
    public function testApplyDiscount(): void
    {
        $calc = new PriceCalculator();
        self::assertSame(80.0, $calc->apply(100.0, discount: 20));
    }
}
```

## Осмотр запросов в тестах middleware

Middleware — это обычный класс — создайте его, передайте ему запрос и фейковый обработчик:

```php
public function testAuthMiddlewareRejectsMissingToken(): void
{
    $mw  = new AuthMiddleware(new Jwt(secret: 'k'));
    $req = new Request('GET', new Uri('/x'));
    $next = new class implements RequestHandlerInterface {
        public function handle(ServerRequestInterface $r): ResponseInterface { return new Response(200); }
    };

    $response = $mw->process($req, $next);
    self::assertSame(401, $response->getStatusCode());
}
```

## Советы для быстрых и корректных тестов

- **Сбрасывайте состояние в `setUp()`**, не в методах теста. Иначе тесты мешают друг другу при запуске по отдельности.
- **Используйте `setUp()` только для вещей, привязанных к `$this`** — для настройки уровня приложения предпочитайте `createApp()`.
- **Избегайте сети.** Заглушайте HTTP-клиенты, платёжные SDK и т. д., привязывая фейки.
- **Не разделяйте состояние между тестами.** Никаких статических синглтонов, никаких глобалей. Каждый тест перестраивает приложение.
- **Тестируйте HTTP-контракт** (код состояния, форма тела, заголовки), а не внутренние классы — контракт — это то, что видят ваши пользователи.
- **Скорость:** ~5 000 HTTP-уровневых тестов в минуту достижимы на типичном ноутбуке, потому что `App::handle()` не делает ввода-вывода.

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| Тесты проходят по отдельности, но падают при группировке | Разделяемое глобальное состояние (статический кэш, env-переменная) | Сбрасывайте в `setUp()` или делайте тесты самодостаточными. |
| `Response body is not valid JSON` | Эндпоинт вернул HTML / пустоту (например, 422 валидации с собственным шаблоном) | Используйте `$response->body()` или сначала проверьте `assertContentType('application/json')`. |
| Заголовки не установлены в тесте | Вы вызвали `withHeader` и отбросили результат | Всегда присваивайте обратно. (Та же ловушка, что в [Response](response).) |
| Аутентификация работает в браузере, но не в тесте | Браузер автоматически несёт cookie / CSRF-токен; тест нет | Засейте сессию/JWT в `setUp()` или сначала отправьте запрос входа. |
| `Cannot resolve parameter $cfg` при загрузке | Тестовое приложение пропустило привязку, которая есть в `public/index.php` | Перенесите привязку в `bootstrap.php`, который вы вызываете из обоих. |

## Шпаргалка

```php
final class FooTest extends \Lift\Testing\TestCase
{
    protected function createApp(): App
    {
        $app = new App();
        $app->instance(Mailer::class, $this->mailer = new InMemoryMailer());
        // …регистрация маршрутов…
        return $app;
    }

    public function test_it_works(): void
    {
        $this->postJson('/users', ['name' => 'Alice'])
             ->assertCreated()
             ->assertJson(['name' => 'Alice'])
             ->assertJsonHas('id')
             ->assertHeader('Location');
    }
}
```

[Server-Sent Events →](sse)
