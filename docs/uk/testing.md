---
layout: page
title: Тестування
nav_order: 13
---

# Тестування

Lift був спроєктований із прицілом на тестовність із першого дня. Два проєктні рішення роблять тести тривіальними:

1. **`App::handle($request): Response` чистий** — за запитом він повертає відповідь, жодного разу не торкаючись `$_SERVER`, буферів виводу чи заголовків PHP.
2. **Усе впроваджується через конструктор** — ви можете підмінити будь-який сервіс фейком, прив’язавши його до запуску запиту.

На додачу Lift постачає крихітний базовий клас PHPUnit — `Lift\Testing\TestCase` — із плавним API тверджень. Ви писатимете інтеграційні тести цілих HTTP-маршрутів у 5 рядків.

## Налаштування

У `composer.json`:

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

## Ваш перший feature-тест

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

`createApp()` викликається з `setUp()` і зберігається в `$this->app`. Перевизначте його один раз на клас тесту.

## HTTP-помічники

```php
$this->get   ('/users');
$this->post  ('/users', ['name' => 'Alice']);
$this->put   ('/users/1', ['name' => 'Bobby']);
$this->patch ('/users/1', ['name' => 'Carol']);
$this->delete('/users/1');

// Завжди-JSON варіанти:
$this->getJson ('/users');                 // надсилає Accept: application/json, стверджує 200
$this->postJson('/users', ['name' => 'A']);

// Власні заголовки:
$this->get('/users', ['Authorization' => 'Bearer ' . $token]);
$this->post('/orders', ['sku' => 'ABC'], ['X-Idempotency-Key' => 'k1']);
```

Масиви тіла кодуються в JSON автоматично. Щоб надіслати щось інше, побудуйте запит вручну:

```php
$req = new \Lift\Http\Request('POST', new \Lift\Http\Uri('/upload'),
    headers: ['Content-Type' => 'multipart/form-data'],
    body: \Lift\Http\Stream::fromString($rawMultipart),
);
$response = $this->app->handle($req);
```

## API тверджень

Кожен помічник повертає `TestResponse`, усі методи якого зчіплюються:

```php
$this->post('/api/users', ['name' => 'Alice', 'email' => 'a@b.c'])
     ->assertCreated()
     ->assertContentType('application/json')
     ->assertHeader('Location', '/api/users/1')
     ->assertJson(['name' => 'Alice'])
     ->assertJsonHas('id')
     ->assertJsonPath('email', 'a@b.c');
```

### Твердження статусу

| Метод                      | Що перевіряє                         |
|----------------------------|--------------------------------------|
| `assertStatus(int $code)`  | Точний код стану                     |
| `assertOk()`               | 200                                  |
| `assertCreated()`          | 201                                  |
| `assertNoContent()`        | 204                                  |
| `assertRedirect($url?)`    | 3xx, опційно з `Location`            |
| `assertUnauthorized()`     | 401                                  |
| `assertForbidden()`        | 403                                  |
| `assertNotFound()`         | 404                                  |
| `assertUnprocessable()`    | 422                                  |

### Твердження заголовків

| Метод                                         | Що перевіряє                                |
|-----------------------------------------------|---------------------------------------------|
| `assertHeader(string $name, ?string $value)`  | Заголовок існує (і дорівнює значенню, якщо задано) |
| `assertContentType(string $type)`             | `Content-Type` містить заданий media-тип    |

### Твердження тіла

| Метод                                                    | Що перевіряє                                                     |
|----------------------------------------------------------|------------------------------------------------------------------|
| `assertSee(string $text)`                                | Сире тіло **містить** рядок                                      |
| `assertDontSee(string $text)`                            | Сире тіло **не містить** рядок                                   |
| `assertJson(array $expected, bool $exact = false)`       | JSON-тіло збігається з очікуваними парами (частково за замовчуванням) |
| `assertJsonHas(string $key)`                             | JSON-тіло має ключ із крапковою нотацією (`'user.email'`)        |
| `assertJsonPath(string $path, mixed $expected)`          | Шлях із крапковою нотацією дорівнює очікуваному значенню          |
| `assertJsonCount(int $count, ?string $key = null)`       | Тіло (або `$key`) — масив із рівно `$count` елементів            |

### Сирі аксесори (аварійні виходи)

```php
$response = $this->get('/x');

$response->status();        // int
$response->body();          // string
$response->json();          // array (викидає виняток, якщо не JSON)
$response->header('X-Foo'); // ?string (перше значення)
$response->getResponse();   // нижчележний Lift\Http\Response
```

## Підміна сервісів фейками

Увесь DI-контейнер у вас під рукою всередині `createApp()`:

```php
protected function createApp(): App
{
    $app = new App();

    // Замінити справжній mailer на фейк у пам’яті
    $this->mailer = new InMemoryMailer();
    $app->instance(Mailer::class, $this->mailer);

    // Заглушити клієнт стороннього API
    $app->instance(GithubClient::class, new FakeGithubClient([
        'octocat' => ['name' => 'The Cat'],
    ]));

    require __DIR__ . '/../../routes/web.php';   // ваша звичайна реєстрація маршрутів
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

`$app->instance(...)` поміщає *уже побудований* об’єкт у контейнер; більше нічого не змінюється. Обробник розв’язує ваш фейк автоматично.

## Тести бази даних із SQLite

Справжня база даних без справжньої бази даних:

```php
protected function createApp(): App
{
    $app = new App();

    $app->singleton(Connection::class, fn() => Connection::fromConfig([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));

    // Побудувати схему один раз на тест:
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

Для більших схем запускайте свої міграції проти БД у пам’яті:

```php
(new \Lift\Database\Migrator($db, __DIR__ . '/../../database/migrations'))->migrate();
```

Кожен тест отримує свіжий `:memory:` SQLite — ідеально ізольований, блискавично швидкий.

## Сесії та автентифікація в тестах

Використовуйте сховище в пам’яті й засійте сесію до диспетчеризації:

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
    // «Залогінити користувача», записавши user_id напряму
    $this->session->set('user_id', 42);
    $this->getJson('/dashboard')->assertOk();
}
```

Для маршрутів, захищених JWT, викарбуйте токен напряму:

```php
$token = $this->app->make(\Lift\Jwt\Jwt::class)->encode(['sub' => 42]);
$this->get('/me', ['Authorization' => "Bearer $token"])->assertOk();
```

## Чисті юніт-тести

Для класів без HTTP-контексту — сервісів, валідаторів, кодувальників — звичайний `TestCase` PHPUnit (`PHPUnit\Framework\TestCase`) — правильна база. Жодної участі Lift узагалі:

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

## Огляд запитів у тестах middleware

Middleware — це звичайний клас — створіть його, передайте йому запит і фейковий обробник:

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

## Поради для швидких і коректних тестів

- **Скидайте стан у `setUp()`**, не в методах тесту. Інакше тести заважають один одному під час запуску окремо.
- **Використовуйте `setUp()` лише для речей, прив’язаних до `$this`** — для налаштування рівня застосунку віддавайте перевагу `createApp()`.
- **Уникайте мережі.** Заглушайте HTTP-клієнти, платіжні SDK тощо, прив’язуючи фейки.
- **Не розділяйте стан між тестами.** Жодних статичних синглтонів, жодних глобалей. Кожен тест перебудовує застосунок.
- **Тестуйте HTTP-контракт** (код стану, форма тіла, заголовки), а не внутрішні класи — контракт — це те, що бачать ваші користувачі.
- **Швидкість:** ~5 000 HTTP-рівневих тестів за хвилину досяжні на типовому ноутбуці, бо `App::handle()` не робить вводу-виводу.

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| Тести проходять окремо, але падають під час групування | Розділюваний глобальний стан (статичний кеш, env-змінна) | Скидайте в `setUp()` або робіть тести самодостатніми. |
| `Response body is not valid JSON` | Ендпоінт повернув HTML / порожнечу (наприклад, 422 валідації з власним шаблоном) | Використовуйте `$response->body()` або спершу перевірте `assertContentType('application/json')`. |
| Заголовки не встановлені в тесті | Ви викликали `withHeader` і відкинули результат | Завжди присвоюйте назад. (Та сама пастка, що в [Response](response).) |
| Автентифікація працює в браузері, але не в тесті | Браузер автоматично несе cookie / CSRF-токен; тест ні | Засійте сесію/JWT у `setUp()` або спершу надішліть запит входу. |
| `Cannot resolve parameter $cfg` під час завантаження | Тестовий застосунок пропустив прив’язку, яка є в `public/index.php` | Перенесіть прив’язку в `bootstrap.php`, який ви викликаєте з обох. |

## Шпаргалка

```php
final class FooTest extends \Lift\Testing\TestCase
{
    protected function createApp(): App
    {
        $app = new App();
        $app->instance(Mailer::class, $this->mailer = new InMemoryMailer());
        // …реєстрація маршрутів…
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
