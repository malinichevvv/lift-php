---
layout: page
title: Обробка помилок
nav_order: 9
---

# Обробка помилок

У вебзастосунку «помилки» приходять із трьох місць:

1. **Проблеми HTTP-форми**, які ви піднімаєте навмисно — *«не знайдено»*, *«не авторизовано»*, *«перевищено ліміт»*.
2. **Невдачі валідації** — ввід не відповідає правилам.
3. **Баги / збої інфраструктури** — база даних недоступна, розіменування null тощо.

Lift дає вам єдиний, уніфікований спосіб перетворити всі три на правильні HTTP-відповіді й налаштувати це зіставлення, коли значення за замовчуванням не підходять.

## Загальна картина

Щоразу, коли обробник / middleware викидає виняток, Lift перехоплює його й виконує цей конвеєр:

```
throw  → налагоджувальний обробник (якщо зареєстрований і збігається)
      → onException(SomeClass::class, $h) (якщо зареєстрований для цього класу)
      → onError($h) (якщо зареєстрований — загальний перехоплювач)
      → зіставлення за замовчуванням (HttpException → код статусу; ValidationException → 422)
      → фінальний запасний варіант: 500 Internal Server Error
```

Цей порядок важливий. Специфічні обробники перемагають загальні.

## Викидання HTTP-винятків

Lift постачає ієрархію типізованих винятків під `Lift\Exception\*`. Усі вони успадковують `HttpException`, який несе код статусу:

| Виняток                            | Статус | Коли викидати                                    |
|-----------------------------------:|:------:|--------------------------------------------------|
| `BadRequestException`              | 400    | Запит спотворений / не може бути оброблений       |
| `UnauthorizedException`            | 401    | Потрібна автентифікація, вона відсутня/невірна    |
| `ForbiddenException`               | 403    | Автентифікований, але не дозволено                |
| `NotFoundException`                | 404    | Ресурс не існує                                   |
| `MethodNotAllowedException`        | 405    | Шлях правильний, а метод ні                       |
| `ConflictException`                | 409    | Дублікат / конфлікт стану                         |
| `TooManyRequestsException`         | 429    | Перевищено ліміт частоти (несе необов’язковий `retryAfter`) |
| `HttpException` (базовий клас)     | будь-який | Власний статус, не покритий вище               |

```php
use Lift\Exception\NotFoundException;
use Lift\Exception\ForbiddenException;
use Lift\Exception\TooManyRequestsException;

$app->get('/users/{id:\d+}', function (Request $req) use ($repo) {
    $user = $repo->find((int) $req->param('id'));
    if ($user === null) {
        throw new NotFoundException("User not found");
    }
    return $user;
});

// Потрібен власний статус?
throw new \Lift\Exception\HttpException(418, "I'm a teapot");

// 429 із заголовком Retry-After (типовий обробник читає `retryAfter` і пише заголовок):
throw new TooManyRequestsException("Slow down", retryAfter: 60);
```

За замовчуванням вони перетворюються на JSON-відповіді:

```json
{ "error": "User not found" }
```

…з відповідним кодом статусу. Щоб налаштувати тіло чи тип вмісту, зареєструйте обробник (див. нижче).

## Помилки валідації (422)

`Lift\Validation\ValidationException`, викинутий будь-де — включно з `$req->validate(...)` і `FormRequest` — перехоплюється автоматично й перетворюється на **HTTP 422** з картою помилок:

```json
{
  "errors": {
    "email": ["The email field is required."],
    "age":   ["The age must be at least 13."]
  }
}
```

Вам майже ніколи не потрібно загортати `$req->validate(...)` у try/catch у продакшені — дайте типовому обробнику Lift зробити це.

## Налаштування глобально — `$app->onError(...)`

`onError()` реєструє **загальний перехоплювач**. Він виконується для будь-якого `Throwable`, який ще не був оброблений більш специфічним `onException()`.

```php
$app->onError(function (\Throwable $e, Request $req) use ($app, $logger) {
    // Логувати все, окрім очікуваних HTTP-винятків
    if (!$e instanceof \Lift\Exception\HttpException) {
        $logger->error($e->getMessage(), ['exception' => $e]);
    }

    // Повернути Response залежно від того, чи хоче клієнт JSON або HTML
    $isJson = $req->wantsJson() || str_starts_with($req->getUri()->getPath(), '/api');

    if ($e instanceof \Lift\Validation\ValidationException) {
        return Response::json(['errors' => $e->errors()], 422);
    }
    if ($e instanceof \Lift\Exception\HttpException) {
        return $isJson
            ? Response::json(['error' => $e->getMessage()], $e->getStatusCode())
            : Response::html("<h1>{$e->getStatusCode()}</h1><p>{$e->getMessage()}</p>", $e->getStatusCode());
    }

    return $isJson
        ? Response::json(['error' => 'Server error'], 500)
        : Response::html('<h1>500 — Something went wrong</h1>', 500);
});
```

Обробник отримує виняток і вихідний запит. Він має повернути `Response`.

> Типовий обробник вмикається, лише коли **ви** не зареєстрували свій. Щойно ви викликаєте `$app->onError(...)`, ви берете повну відповідальність — включно з 404, 405, 422 тощо.

## Налаштування за типом — `$app->onException(...)`

`onException(SomeClass::class, $handler)` виконується, лише коли викинутий виняток є екземпляром `SomeClass`. Кілька обробників складаються — перемагає найспецифічніший збіг.

```php
use Lift\Exception\NotFoundException;
use App\Exception\PaymentFailedException;

$app->onException(NotFoundException::class, fn() => Response::html(
    '<h1>404</h1><p>Nothing here, mate.</p>', 404
));

$app->onException(PaymentFailedException::class, function (PaymentFailedException $e) {
    return Response::json([
        'error' => 'payment_failed',
        'reason' => $e->reason,
        'next_step' => '/billing/retry',
    ], 402);
});
```

Вони не замінюють `onError(...)` — вони виконуються **до** нього. Якщо жоден не обробив виняток, фреймворк провалюється до зіставлення за замовчуванням.

## Власні винятки застосунку

Створюйте свої, коли хочете типізований, семантичний виняток, що зіставляється зі статусом:

```php
namespace App\Exception;

use Lift\Exception\HttpException;

final class PaymentFailedException extends HttpException
{
    public function __construct(
        public readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(402, "Payment failed: $reason", $previous);
    }
}

// Будь-де:
throw new PaymentFailedException('card_declined');
```

Типовий обробник перетворить це на `{ "error": "Payment failed: card_declined" }` зі статусом 402.

## У middleware

Middleware може і *викидати*, і *перехоплювати* винятки. Поширений патерн:

```php
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Jwt $jwt) {}

    public function process($req, $next): ResponseInterface
    {
        $token = $req->getHeaderLine('Authorization');
        if (!$this->jwt->verify($token)) {
            throw new \Lift\Exception\UnauthorizedException();
        }
        return $next->handle($req);
    }
}
```

`UnauthorizedException` поширюється вгору до обробки помилок Lift і стає 401. Жодного ручного `Response::json(...)` у middleware.

## Режим налагодження

Коли ввімкнено `$app->debug(true)`, винятки рендеряться як **детальна HTML-сторінка** з трасуванням стека, попереднім переглядом вихідного коду, оглядом запиту та SQL-запитами:

```php
$app->debug([
    'enabled'        => Env::bool('APP_DEBUG', false),
    'show_query_log' => true,
    'log_requests'   => true,
]);
```

**Ніколи не вмикайте режим налагодження у продакшені** — він витікає шляхи до файлів, змінні оточення та вихідний код. Захистіть його змінною оточення.

Докладніше: [Панель налагодження](debug).

## Логування у продакшені

Непередбачені винятки ви хочете логувати + моніторити + отримувати сповіщення. Lift не постачає моніторинг — він дає вам [логер](logging) і дозволяє під’єднати що завгодно (Sentry, Bugsnag, звичайний файл):

```php
$app->onError(function (\Throwable $e, Request $req) use ($logger, $sentry) {
    // Пропустити очікувані винятки HTTP-потоку
    if (!$e instanceof \Lift\Exception\HttpException) {
        $sentry->captureException($e);
        $logger->error($e->getMessage(), [
            'method'    => $req->getMethod(),
            'path'      => $req->getUri()->getPath(),
            'exception' => $e,
        ]);
    }

    // …повернути відповідь як раніше
});
```

## `ErrorRenderer` — обробник помилок із узгодженням контенту

Писати повний колбек `onError()`, який обробляє JSON vs HTML, логує помилки й зіставляє коди статусів, повторюване заняття. `Lift\Debug\ErrorRenderer` — це фабрика, що генерує готові обробники:

```php
use Lift\Debug\ErrorRenderer;

// Автовизначення: JSON, коли клієнт шле/приймає JSON, інакше HTML
$app->onError(ErrorRenderer::auto());

// Завжди JSON (API, мікросервіси)
$app->onError(ErrorRenderer::json());

// Завжди HTML (класичні вебзастосунки)
$app->onError(ErrorRenderer::html());
```

**Показ деталей помилки** (клас винятку, file:line, трасування стека) у розробці:

```php
use Lift\Config\Env;

$app->onError(ErrorRenderer::auto(
    showDetails: Env::bool('APP_DEBUG', false),
));
```

У продакшені (`showDetails: false`) відповідь містить лише повідомлення:

```json
{ "error": "User not found" }
```

З `showDetails: true` тіло JSON також несе:

```json
{
    "error": "User not found",
    "exception": "Lift\\Exception\\NotFoundException",
    "file": "/var/www/src/UserRepository.php",
    "line": 42,
    "trace": [
        "Lift\\Exception\\NotFoundException::__construct (/var/www/src/UserRepository.php:42)",
        "App\\Http\\Controllers\\UserController::show (/var/www/src/Http/Controllers/UserController.php:31)"
    ]
}
```

HTML-відповідь (коли клієнт приймає `text/html`) — це чиста, мінімальна сторінка помилки, що працює без жодних зовнішніх ресурсів:

```php
$app->onError(ErrorRenderer::html(showDetails: true));
// → рендерить: картку 404, клас винятку, file:line, повне трасування
```

**Зіставлення кодів статусу** слідує тим самим правилам, що й типовий обробник:
- `ValidationException` → 422 (включає карту `errors` у режимі JSON)
- Підкласи `HttpException` → їхній `getStatusCode()`
- Усе інше → 500

**Поєднання з логуванням** — `ErrorRenderer` обробляє лише _рендеринг_. Щоб логувати до рендерингу, загорніть його:

```php
$app->onError(function (\Throwable $e, Request $req) use ($logger) {
    if (!$e instanceof \Lift\Exception\HttpException) {
        $logger->error($e->getMessage(), ['exception' => $e]);
    }
    return ErrorRenderer::auto()($e, $req);
});
```

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| 500 із `{"error":"Internal Server Error"}` і без логу | Ви зареєстрували `$app->onError(...)` і забули логувати всередині | Додайте явне логування в обробник. |
| `ValidationException` повертає 500 замість 422 | Ви написали власний `onError(...)` і забули обробити `ValidationException` | Додайте гілку (див. приклад вище). |
| `NotFoundException` зсередини `find()` стає загальним 500 | Ви викидаєте виняток усередині обробника `onError` — повторні викидання спливають недоторканими | Не викидайте з обробника помилок; повертайте Response. |
| Заголовок `Retry-After` відсутній на 429 | Ви викинули загальний `HttpException(429)` замість `TooManyRequestsException` | Використовуйте типізований, передавайте `retryAfter`. |
| Сторінка налагодження витікає у продакшені | `$app->debug(true)` жорстко прописаний | Завжди виводьте з `Env::bool('APP_DEBUG', false)`. |

## Шпаргалка

```php
// Викидати типізовані помилки
throw new NotFoundException();                                  // 404
throw new UnauthorizedException("Bad token");                   // 401
throw new ForbiddenException("Admins only");                    // 403
throw new TooManyRequestsException("Slow down", retryAfter: 60); // 429
throw new HttpException(418, "I'm a teapot");                   // будь-який

// Реєструвати обробники
$app->onException(NotFoundException::class, fn($e, $req) => …);
$app->onError(fn(\Throwable $e, Request $req) => …);

// Готовий обробник з узгодженням контенту
use Lift\Debug\ErrorRenderer;
$app->onError(ErrorRenderer::auto());                       // JSON або HTML на основі Accept
$app->onError(ErrorRenderer::auto(showDetails: true));      // + деталі винятку
$app->onError(ErrorRenderer::json());                       // завжди JSON
$app->onError(ErrorRenderer::html());                       // завжди HTML

// Авто-422 валідації — жодної особливої обробки не потрібно
$data = $req->validate(['email' => 'required|email']);
```

[Тестування →](testing)
