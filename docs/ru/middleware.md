---
layout: page
title: Middleware
nav_order: 6
---

# Middleware

Middleware — это участок кода, который выполняется **до** и/или **после** вашего обработчика маршрута — идеально для аутентификации, логирования, CORS, ограничения частоты, изменения запроса, сжатия ответа и всего прочего сквозного.

Lift реализует интерфейс middleware из **PSR-15**, что означает:

- Любой сторонний PSR-15 middleware работает «из коробки».
- Middleware, написанный вами для Lift, работает в Slim, Mezzio, ReactPHP и т. д.

> Ментальная модель: middleware'ы оборачивают обработчик, как слои луковицы. Запрос течёт *вниз* к обработчику, ответ течёт *вверх* через те же слои в обратном порядке.

## Middleware за 12 строк

```php
use Lift\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface
    {
        $id = $req->getHeaderLine('X-Request-Id') ?: bin2hex(random_bytes(8));

        // ↓ передаём управление следующему слою
        $response = $next->handle($req->withAttribute('request_id', $id));

        // ↑ осматриваем/изменяем ответ на обратном пути
        return $response->withHeader('X-Request-Id', $id);
    }
}
```

Это весь контракт. Один метод, четыре строки «настоящего» кода (остальное — типобезопасный блок `use`).

## Подключение middleware

### Глобально — выполняется на каждом запросе

```php
$app->use(CorsMiddleware::class);             // имя класса (автосвязывается через контейнер)
$app->use(new RateLimitMiddleware(60));       // готовый экземпляр
$app->use(RequestIdMiddleware::class);
```

Можно передать имя класса (Lift разрешит его через [контейнер](container) при первой надобности) или экземпляр, который вы построили сами. Оба варианта работают; готовые экземпляры избегают рефлексии на горячих путях.

### Помаршрутно

Сцепите `->middleware(...)` на маршруте:

```php
$app->get('/secret', $handler)
    ->middleware(AuthMiddleware::class);

$app->post('/users', [UserController::class, 'store'])
    ->middleware(AuthMiddleware::class, RateLimitMiddleware::class);
```

### Погруппово

Примените сразу ко всей группе:

```php
$app->group('/admin', function ($g) {
    $g->get('/users',    [AdminController::class, 'users']);
    $g->get('/settings', [AdminController::class, 'settings']);
})->middleware(AuthMiddleware::class, RequireAdminMiddleware::class);
```

Вложенные группы наследуют внешний middleware *и* могут добавить свой.

## Порядок выполнения — модель луковицы

```
$app->use(A);                              // самый внешний
$app->use(B);
$app->group('/api', fn($g) => $g
    ->get('/x', $h)
    ->middleware(C));                      // самый внутренний

// Жизненный цикл запроса для GET /api/x:
//   A → B → C → обработчик
//   A ← B ← C ← ответ
```

Каждый middleware решает, делегировать ли дальше (`$next->handle($req)`) или прервать цепочку, вернув `Response` напрямую. Прерывание означает, что последующие middleware никогда не выполняются — идеально для стражей аутентификации:

```php
public function process($req, $next): ResponseInterface
{
    if (! $this->validate($req->getHeaderLine('Authorization'))) {
        return Response::json(['error' => 'Unauthorized'], 401);
        // ↑ нет вызова $next->handle(…) — конвейер останавливается здесь
    }
    return $next->handle($req);
}
```

## Внедрение через конструктор

Классы middleware проходят через контейнер, а значит **могут иметь зависимости**:

```php
final class LogMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Psr\Log\LoggerInterface $log,
        private readonly Clock $clock,
    ) {}

    public function process($req, $next): ResponseInterface { /* ... */ }
}

$app->use(LogMiddleware::class);   // Logger и Clock автосвязываются
```

Если вы передаёте имя класса (а не экземпляр), Lift разрешает его через контейнер ровно один раз и кэширует результат для последующих запросов в том же процессе.

## Изменение запроса → передача данных обработчику

Стандартный паттерн: прикрепить значения к запросу через атрибуты PSR-7.

```php
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly UserRepository $users, private readonly Jwt $jwt) {}

    public function process($req, $next): ResponseInterface
    {
        $token = trim((string) preg_replace('/^Bearer\s+/i', '', $req->getHeaderLine('Authorization')));

        try {
            $claims = $this->jwt->decode($token);
        } catch (\Throwable) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $user = $this->users->find((int) $claims['sub']);
        if ($user === null) {
            return Response::json(['error' => 'User gone'], 401);
        }

        // ↓ прикрепляем для обработчика
        return $next->handle($req->withAttribute('user', $user));
    }
}

// Читаем в обработчике:
$app->get('/me', fn(Request $req) => Response::json($req->getAttribute('user')))
    ->middleware(AuthMiddleware::class);
```

## Изменение ответа

Та же идея, на обратном пути наружу:

```php
public function process($req, $next): ResponseInterface
{
    $start    = hrtime(true);
    $response = $next->handle($req);
    $ms       = (hrtime(true) - $start) / 1e6;

    return $response
        ->withHeader('Server-Timing', sprintf('total;dur=%.1f', $ms))
        ->withHeader('X-Powered-By', 'Lift');
}
```

## Встроенные middleware

Lift поставляется с несколькими middleware продакшен-уровня, готовыми к подключению:

| Middleware                          | Решает              | Документация   |
|-------------------------------------|---------------------|----------------|
| `Lift\Middleware\CorsMiddleware`    | CORS preflight + заголовки | [Безопасность](security#cors) |
| `Lift\Middleware\CsrfMiddleware`    | CSRF (double-submit cookie) | [Безопасность](security#csrf) |
| `Lift\Middleware\RateLimitMiddleware` | Ограничение частоты «token-bucket» | [Безопасность](security#rate-limiting) |
| `Lift\Middleware\SecurityHeadersMiddleware` | HSTS, X-Frame-Options и т. д. | [Безопасность](security#security-headers) |
| `Lift\Jwt\JwtMiddleware`            | Аутентификация по Bearer-токену | [JWT](jwt#middleware) |
| `Lift\Debug\DebugToolbarMiddleware` | Панель разработчика | [Отладка](debug) |
| `Lift\Http\Session\SessionMiddleware` | Инициализация сессии | [Сессии](sessions) |

У большинства есть конструкторы, принимающие конфигурацию. Например:

```php
use Lift\Middleware\CorsMiddleware;

$app->use(new CorsMiddleware(
    allowedOrigins: ['https://app.example.com'],
    allowedMethods: ['GET', 'POST', 'PATCH', 'DELETE'],
    allowedHeaders: ['Content-Type', 'Authorization'],
    allowCredentials: true,
    maxAge: 86400,
));
```

## Примеры

### CORS (написанный вручную, когда нужен максимальный контроль)

```php
final class CorsMiddleware implements MiddlewareInterface
{
    public function process($req, $next): ResponseInterface
    {
        if ($req->getMethod() === 'OPTIONS') {
            return (new Response(204))
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
                ->withHeader('Access-Control-Max-Age', '86400');
        }

        return $next->handle($req)
            ->withHeader('Access-Control-Allow-Origin', '*');
    }
}
```

### Логирование запросов

```php
final class LogMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Psr\Log\LoggerInterface $log) {}

    public function process($req, $next): ResponseInterface
    {
        $t0       = hrtime(true);
        $response = $next->handle($req);
        $ms       = (hrtime(true) - $t0) / 1e6;

        $this->log->info(sprintf(
            '%s %s → %d (%.1f ms)',
            $req->getMethod(),
            $req->getUri()->getPath(),
            $response->getStatusCode(),
            $ms,
        ));

        return $response;
    }
}
```

### Страж размера тела

Отклоняйте запросы с абсурдно большими телами до того, как они дойдут до вашего обработчика:

```php
final class MaxBodySizeMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly int $limitBytes) {}

    public function process($req, $next): ResponseInterface
    {
        $len = (int) $req->getHeaderLine('Content-Length');
        if ($len > 0 && $len > $this->limitBytes) {
            return Response::json(['error' => 'Payload too large'], 413);
        }
        return $next->handle($req);
    }
}

$app->use(new MaxBodySizeMiddleware(2 * 1024 * 1024));  // 2 МБ
```

### Сжатие (gzip)

```php
final class GzipMiddleware implements MiddlewareInterface
{
    public function process($req, $next): ResponseInterface
    {
        $res = $next->handle($req);
        if (!str_contains($req->getHeaderLine('Accept-Encoding'), 'gzip')) {
            return $res;
        }

        $body = (string) $res->getBody();
        if (strlen($body) < 1024) {
            return $res;  // не стоит того
        }

        return $res
            ->withHeader('Content-Encoding', 'gzip')
            ->withHeader('Vary', 'Accept-Encoding')
            ->withBody(\Lift\Http\Stream::fromString(gzencode($body, 6)));
    }
}
```

### Ошибка → JSON

Middleware может перехватывать исключения, выброшенные более глубокими middleware/обработчиками:

```php
final class JsonErrorMiddleware implements MiddlewareInterface
{
    public function process($req, $next): ResponseInterface
    {
        try {
            return $next->handle($req);
        } catch (\Lift\Exception\HttpException $e) {
            return Response::json(['error' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Server error'], 500);
        }
    }
}
```

> В большинстве случаев это не нужно — встроенная обработка ошибок Lift уже преобразует подклассы `HttpException` + `ValidationException` в подходящие ответы. Используйте `$app->onError(...)` для обработки на уровне приложения. См. [Обработка ошибок](errors).

## Анатомия `$next->handle($req)`

Аргумент `$next` — это `RequestHandlerInterface`, объект с единственным методом, чей `handle(ServerRequestInterface): ResponseInterface` выполняет **остаток конвейера**, начиная со следующего middleware. Фреймворк строит его лениво, поэтому вы никогда не конструируете его сами.

Вызов `$next->handle($req)` более одного раза *технически* разрешён, но почти всегда является багом (обработчик выполнится дважды). Не делайте так.

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| Middleware никогда не выполняется | Забыли `$app->use(...)` или `->middleware(...)` | Зарегистрируйте его. |
| Заголовки, установленные в middleware, отсутствуют в ответе | Вы вызвали `withHeader(...)`, но не сделали `return` результата | `return $response->withHeader(...);`. |
| 500 с «no response returned» | Middleware забыл сделать return | Всегда `return $next->handle($req)` или ваш собственный `Response`. |
| Auth-middleware выполняется *после* провала CORS preflight | CORS-middleware зарегистрирован после auth | Регистрируйте CORS **первым** (`$app->use(CorsMiddleware::class)` раньше всего остального). |
| Один и тот же middleware добавляет один и тот же заголовок дважды | Зарегистрирован и глобально, и помаршрутно | Выберите что-то одно. |
| Middleware-замыкание | Lift требует `MiddlewareInterface` | Оберните замыкание в класс. (Lift намеренно не позволяет middleware-замыкания, чтобы держать типовой контракт строгим.) |

## Шпаргалка

```php
// Определение
final class MyMiddleware implements MiddlewareInterface
{
    public function process($req, $next): ResponseInterface { /* ... */ }
}

// Подключение
$app->use(MyMiddleware::class);                       // глобально
$app->use(new MyMiddleware($cfg));                    // глобально, готовый экземпляр
$app->get($p, $h)->middleware(MyMiddleware::class);   // помаршрутно
$app->group($p, fn($g) => /* */)->middleware(MyMiddleware::class); // погруппово

// Изменение запроса / ответа
$req = $req->withAttribute('user', $user);
$res = $next->handle($req)->withHeader('X-Foo', 'bar');

// Прерывание цепочки
return Response::json(['error' => 'denied'], 401);
```

[Security-middleware →](security)
