---
layout: page
title: Обработка ошибок
nav_order: 9
---

# Обработка ошибок

В веб-приложении «ошибки» приходят из трёх мест:

1. **Проблемы HTTP-формы**, которые вы поднимаете намеренно — *«не найдено»*, *«не авторизован»*, *«превышен лимит»*.
2. **Неудачи валидации** — ввод не соответствует правилам.
3. **Баги / сбои инфраструктуры** — база данных недоступна, разыменование null и т. д.

Lift даёт вам единый, унифицированный способ превратить все три в правильные HTTP-ответы и настроить это сопоставление, когда значения по умолчанию не подходят.

## Общая картина

Каждый раз, когда обработчик / middleware выбрасывает исключение, Lift перехватывает его и выполняет этот конвейер:

```
throw  → отладочный обработчик (если зарегистрирован и совпадает)
      → onException(SomeClass::class, $h) (если зарегистрирован для этого класса)
      → onError($h) (если зарегистрирован — общий перехватчик)
      → сопоставление по умолчанию (HttpException → код статуса; ValidationException → 422)
      → финальный запасной вариант: 500 Internal Server Error
```

Этот порядок важен. Специфичные обработчики побеждают общие.

## Выброс HTTP-исключений

Lift поставляет иерархию типизированных исключений под `Lift\Exception\*`. Все они наследуют `HttpException`, который несёт код статуса:

| Исключение                         | Статус | Когда выбрасывать                                |
|-----------------------------------:|:------:|--------------------------------------------------|
| `BadRequestException`              | 400    | Запрос искажён / не может быть обработан          |
| `UnauthorizedException`            | 401    | Требуется аутентификация, она отсутствует/неверна |
| `ForbiddenException`               | 403    | Аутентифицирован, но не разрешено                 |
| `NotFoundException`                | 404    | Ресурс не существует                              |
| `MethodNotAllowedException`        | 405    | Путь правильный, а метод нет                      |
| `ConflictException`                | 409    | Дубликат / конфликт состояния                     |
| `TooManyRequestsException`         | 429    | Превышен лимит частоты (несёт необязательный `retryAfter`) |
| `HttpException` (базовый класс)    | любой  | Собственный статус, не покрытый выше              |

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

// Нужен собственный статус?
throw new \Lift\Exception\HttpException(418, "I'm a teapot");

// 429 с заголовком Retry-After (обработчик по умолчанию читает `retryAfter` и пишет заголовок):
throw new TooManyRequestsException("Slow down", retryAfter: 60);
```

По умолчанию они превращаются в JSON-ответы:

```json
{ "error": "User not found" }
```

…с соответствующим кодом статуса. Чтобы настроить тело или тип содержимого, зарегистрируйте обработчик (см. ниже).

## Ошибки валидации (422)

`Lift\Validation\ValidationException`, выброшенное где угодно — включая `$req->validate(...)` и `FormRequest` — перехватывается автоматически и преобразуется в **HTTP 422** с картой ошибок:

```json
{
  "errors": {
    "email": ["The email field is required."],
    "age":   ["The age must be at least 13."]
  }
}
```

Вам почти никогда не нужно оборачивать `$req->validate(...)` в try/catch в продакшене — дайте обработчику Lift по умолчанию сделать это.

## Настройка глобально — `$app->onError(...)`

`onError()` регистрирует **общий перехватчик**. Он выполняется для любого `Throwable`, который ещё не был обработан более специфичным `onException()`.

```php
$app->onError(function (\Throwable $e, Request $req) use ($app, $logger) {
    // Логировать всё, кроме ожидаемых HTTP-исключений
    if (!$e instanceof \Lift\Exception\HttpException) {
        $logger->error($e->getMessage(), ['exception' => $e]);
    }

    // Вернуть Response в зависимости от того, хочет ли клиент JSON или HTML
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

Обработчик получает исключение и исходный запрос. Он должен вернуть `Response`.

> Обработчик по умолчанию включается, только когда **вы** не зарегистрировали свой. Как только вы вызываете `$app->onError(...)`, вы берёте полную ответственность — включая 404, 405, 422 и т. д.

## Настройка по типу — `$app->onException(...)`

`onException(SomeClass::class, $handler)` выполняется, только когда выброшенное исключение является экземпляром `SomeClass`. Несколько обработчиков складываются — побеждает наиболее специфичное совпадение.

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

Они не заменяют `onError(...)` — они выполняются **до** него. Если ни один не обработал исключение, фреймворк проваливается к сопоставлению по умолчанию.

## Собственные исключения приложения

Создавайте свои, когда хотите типизированное, семантичное исключение, которое сопоставляется со статусом:

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

// Где угодно:
throw new PaymentFailedException('card_declined');
```

Обработчик по умолчанию превратит это в `{ "error": "Payment failed: card_declined" }` со статусом 402.

## В middleware

Middleware может и *выбрасывать*, и *перехватывать* исключения. Распространённый паттерн:

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

`UnauthorizedException` распространяется вверх к обработке ошибок Lift и становится 401. Никакого ручного `Response::json(...)` в middleware.

## Режим отладки

Когда включён `$app->debug(true)`, исключения рендерятся как **детальная HTML-страница** с трассировкой стека, предпросмотром исходного кода, осмотром запроса и SQL-запросами:

```php
$app->debug([
    'enabled'        => Env::bool('APP_DEBUG', false),
    'show_query_log' => true,
    'log_requests'   => true,
]);
```

**Никогда не включайте режим отладки в продакшене** — он утекает пути к файлам, переменные окружения и исходный код. Защитите его переменной окружения.

Подробнее: [Отладочная панель](debug).

## Логирование в продакшене

Непредвиденные исключения вы хотите логировать + мониторить + получать оповещения. Lift не поставляет мониторинг — он даёт вам [логгер](logging) и позволяет подключить что угодно (Sentry, Bugsnag, обычный файл):

```php
$app->onError(function (\Throwable $e, Request $req) use ($logger, $sentry) {
    // Пропустить ожидаемые исключения HTTP-потока
    if (!$e instanceof \Lift\Exception\HttpException) {
        $sentry->captureException($e);
        $logger->error($e->getMessage(), [
            'method'    => $req->getMethod(),
            'path'      => $req->getUri()->getPath(),
            'exception' => $e,
        ]);
    }

    // …вернуть ответ как раньше
});
```

## `ErrorRenderer` — обработчик ошибок с согласованием контента

Писать полный колбэк `onError()`, который обрабатывает JSON vs HTML, логирует ошибки и сопоставляет коды статусов, повторяющееся занятие. `Lift\Debug\ErrorRenderer` — это фабрика, генерирующая готовые обработчики:

```php
use Lift\Debug\ErrorRenderer;

// Автоопределение: JSON, когда клиент шлёт/принимает JSON, иначе HTML
$app->onError(ErrorRenderer::auto());

// Всегда JSON (API, микросервисы)
$app->onError(ErrorRenderer::json());

// Всегда HTML (классические веб-приложения)
$app->onError(ErrorRenderer::html());
```

**Показ деталей ошибки** (класс исключения, file:line, трассировка стека) в разработке:

```php
use Lift\Config\Env;

$app->onError(ErrorRenderer::auto(
    showDetails: Env::bool('APP_DEBUG', false),
));
```

В продакшене (`showDetails: false`) ответ содержит только сообщение:

```json
{ "error": "User not found" }
```

С `showDetails: true` тело JSON также несёт:

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

HTML-ответ (когда клиент принимает `text/html`) — это чистая, минимальная страница ошибки, работающая без каких-либо внешних ресурсов:

```php
$app->onError(ErrorRenderer::html(showDetails: true));
// → рендерит: карточку 404, класс исключения, file:line, полную трассировку
```

**Сопоставление кодов статуса** следует тем же правилам, что и обработчик по умолчанию:
- `ValidationException` → 422 (включает карту `errors` в режиме JSON)
- Подклассы `HttpException` → их `getStatusCode()`
- Всё остальное → 500

**Сочетание с логированием** — `ErrorRenderer` обрабатывает только _рендеринг_. Чтобы логировать до рендеринга, оберните его:

```php
$app->onError(function (\Throwable $e, Request $req) use ($logger) {
    if (!$e instanceof \Lift\Exception\HttpException) {
        $logger->error($e->getMessage(), ['exception' => $e]);
    }
    return ErrorRenderer::auto()($e, $req);
});
```

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| 500 с `{"error":"Internal Server Error"}` и без лога | Вы зарегистрировали `$app->onError(...)` и забыли логировать внутри | Добавьте явное логирование в обработчик. |
| `ValidationException` возвращает 500 вместо 422 | Вы написали собственный `onError(...)` и забыли обработать `ValidationException` | Добавьте ветку (см. пример выше). |
| `NotFoundException` изнутри `find()` становится общим 500 | Вы выбрасываете исключение внутри обработчика `onError` — повторные выбросы всплывают нетронутыми | Не выбрасывайте из обработчика ошибок; возвращайте Response. |
| Заголовок `Retry-After` отсутствует на 429 | Вы выбросили общий `HttpException(429)` вместо `TooManyRequestsException` | Используйте типизированное, передавайте `retryAfter`. |
| Страница отладки утекает в продакшене | `$app->debug(true)` жёстко прописан | Всегда выводите из `Env::bool('APP_DEBUG', false)`. |

## Шпаргалка

```php
// Выбрасывать типизированные ошибки
throw new NotFoundException();                                  // 404
throw new UnauthorizedException("Bad token");                   // 401
throw new ForbiddenException("Admins only");                    // 403
throw new TooManyRequestsException("Slow down", retryAfter: 60); // 429
throw new HttpException(418, "I'm a teapot");                   // любой

// Регистрировать обработчики
$app->onException(NotFoundException::class, fn($e, $req) => …);
$app->onError(fn(\Throwable $e, Request $req) => …);

// Готовый обработчик с согласованием контента
use Lift\Debug\ErrorRenderer;
$app->onError(ErrorRenderer::auto());                       // JSON или HTML на основе Accept
$app->onError(ErrorRenderer::auto(showDetails: true));      // + детали исключения
$app->onError(ErrorRenderer::json());                       // всегда JSON
$app->onError(ErrorRenderer::html());                       // всегда HTML

// Авто-422 валидации — никакой особой обработки не нужно
$data = $req->validate(['email' => 'required|email']);
```

[Тестирование →](testing)
