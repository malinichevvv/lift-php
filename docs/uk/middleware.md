---
layout: page
title: Middleware
nav_order: 6
---

# Middleware

Middleware — це ділянка коду, що виконується **до** та/або **після** вашого обробника маршруту — ідеально для автентифікації, логування, CORS, обмеження частоти, зміни запиту, стиснення відповіді та всього іншого наскрізного.

Lift реалізує інтерфейс middleware із **PSR-15**, що означає:

- Будь-який сторонній PSR-15 middleware працює «з коробки».
- Middleware, написаний вами для Lift, працює у Slim, Mezzio, ReactPHP тощо.

> Ментальна модель: middleware'и загортають обробник, як шари цибулини. Запит тече *вниз* до обробника, відповідь тече *вгору* через ті самі шари у зворотному порядку.

## Middleware за 12 рядків

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

        // ↓ передаємо керування наступному шару
        $response = $next->handle($req->withAttribute('request_id', $id));

        // ↑ оглядаємо/змінюємо відповідь на зворотному шляху
        return $response->withHeader('X-Request-Id', $id);
    }
}
```

Це весь контракт. Один метод, чотири рядки «справжнього» коду (решта — типобезпечний блок `use`).

## Під’єднання middleware

### Глобально — виконується на кожному запиті

```php
$app->use(CorsMiddleware::class);             // ім’я класу (автозв’язується через контейнер)
$app->use(new RateLimitMiddleware(60));       // готовий екземпляр
$app->use(RequestIdMiddleware::class);
```

Можна передати ім’я класу (Lift розв’яже його через [контейнер](container) за першої потреби) або екземпляр, який ви побудували самі. Обидва варіанти працюють; готові екземпляри уникають рефлексії на гарячих шляхах.

### Помаршрутно

Зчепіть `->middleware(...)` на маршруті:

```php
$app->get('/secret', $handler)
    ->middleware(AuthMiddleware::class);

$app->post('/users', [UserController::class, 'store'])
    ->middleware(AuthMiddleware::class, RateLimitMiddleware::class);
```

### Погрупово

Застосуйте одразу до всієї групи:

```php
$app->group('/admin', function ($g) {
    $g->get('/users',    [AdminController::class, 'users']);
    $g->get('/settings', [AdminController::class, 'settings']);
})->middleware(AuthMiddleware::class, RequireAdminMiddleware::class);
```

Вкладені групи успадковують зовнішній middleware *і* можуть додати свій.

## Порядок виконання — модель цибулини

```
$app->use(A);                              // найзовнішній
$app->use(B);
$app->group('/api', fn($g) => $g
    ->get('/x', $h)
    ->middleware(C));                      // найвнутрішніший

// Життєвий цикл запиту для GET /api/x:
//   A → B → C → обробник
//   A ← B ← C ← відповідь
```

Кожен middleware вирішує, чи делегувати далі (`$next->handle($req)`), чи перервати ланцюжок, повернувши `Response` напряму. Переривання означає, що наступні middleware ніколи не виконуються — ідеально для стражів автентифікації:

```php
public function process($req, $next): ResponseInterface
{
    if (! $this->validate($req->getHeaderLine('Authorization'))) {
        return Response::json(['error' => 'Unauthorized'], 401);
        // ↑ немає виклику $next->handle(…) — конвеєр зупиняється тут
    }
    return $next->handle($req);
}
```

## Впровадження через конструктор

Класи middleware проходять через контейнер, а отже **можуть мати залежності**:

```php
final class LogMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Psr\Log\LoggerInterface $log,
        private readonly Clock $clock,
    ) {}

    public function process($req, $next): ResponseInterface { /* ... */ }
}

$app->use(LogMiddleware::class);   // Logger і Clock автозв’язуються
```

Якщо ви передаєте ім’я класу (а не екземпляр), Lift розв’язує його через контейнер рівно один раз і кешує результат для наступних запитів у тому самому процесі.

## Зміна запиту → передавання даних обробнику

Стандартний патерн: прикріпити значення до запиту через атрибути PSR-7.

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

        // ↓ прикріплюємо для обробника
        return $next->handle($req->withAttribute('user', $user));
    }
}

// Читаємо в обробнику:
$app->get('/me', fn(Request $req) => Response::json($req->getAttribute('user')))
    ->middleware(AuthMiddleware::class);
```

## Зміна відповіді

Та сама ідея, на зворотному шляху назовні:

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

## Вбудовані middleware

Lift постачається з кількома middleware продакшен-рівня, готовими до під’єднання:

| Middleware                          | Розв’язує           | Документація   |
|-------------------------------------|---------------------|----------------|
| `Lift\Middleware\CorsMiddleware`    | CORS preflight + заголовки | [Безпека](security#cors) |
| `Lift\Middleware\CsrfMiddleware`    | CSRF (double-submit cookie) | [Безпека](security#csrf) |
| `Lift\Middleware\RateLimitMiddleware` | Обмеження частоти «token-bucket» | [Безпека](security#rate-limiting) |
| `Lift\Middleware\SecurityHeadersMiddleware` | HSTS, X-Frame-Options тощо | [Безпека](security#security-headers) |
| `Lift\Jwt\JwtMiddleware`            | Автентифікація за Bearer-токеном | [JWT](jwt#middleware) |
| `Lift\Debug\DebugToolbarMiddleware` | Панель розробника   | [Налагодження](debug) |
| `Lift\Http\Session\SessionMiddleware` | Ініціалізація сесії | [Сесії](sessions) |

Більшість мають конструктори, що приймають конфігурацію. Наприклад:

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

## Приклади

### CORS (написаний вручну, коли потрібен максимальний контроль)

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

### Логування запитів

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

### Страж розміру тіла

Відхиляйте запити з абсурдно великими тілами до того, як вони дійдуть до вашого обробника:

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

### Стиснення (gzip)

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
            return $res;  // не варте того
        }

        return $res
            ->withHeader('Content-Encoding', 'gzip')
            ->withHeader('Vary', 'Accept-Encoding')
            ->withBody(\Lift\Http\Stream::fromString(gzencode($body, 6)));
    }
}
```

### Помилка → JSON

Middleware може перехоплювати винятки, викинуті глибшими middleware/обробниками:

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

> У більшості випадків це не потрібно — вбудована обробка помилок Lift уже перетворює підкласи `HttpException` + `ValidationException` на відповідні відповіді. Використовуйте `$app->onError(...)` для обробки на рівні застосунку. Див. [Обробка помилок](errors).

## Анатомія `$next->handle($req)`

Аргумент `$next` — це `RequestHandlerInterface`, об’єкт з єдиним методом, чий `handle(ServerRequestInterface): ResponseInterface` виконує **решту конвеєра**, починаючи з наступного middleware. Фреймворк будує його ліниво, тому ви ніколи не конструюєте його самі.

Виклик `$next->handle($req)` більше одного разу *технічно* дозволений, але майже завжди є багом (обробник виконається двічі). Не робіть так.

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| Middleware ніколи не виконується | Забули `$app->use(...)` або `->middleware(...)` | Зареєструйте його. |
| Заголовки, встановлені в middleware, відсутні у відповіді | Ви викликали `withHeader(...)`, але не зробили `return` результату | `return $response->withHeader(...);`. |
| 500 з «no response returned» | Middleware забув зробити return | Завжди `return $next->handle($req)` або ваш власний `Response`. |
| Auth-middleware виконується *після* провалу CORS preflight | CORS-middleware зареєстрований після auth | Реєструйте CORS **першим** (`$app->use(CorsMiddleware::class)` раніше за все інше). |
| Той самий middleware додає той самий заголовок двічі | Зареєстрований і глобально, і помаршрутно | Оберіть щось одне. |
| Middleware-замикання | Lift вимагає `MiddlewareInterface` | Загорніть замикання у клас. (Lift навмисно не дозволяє middleware-замикання, щоб тримати типовий контракт суворим.) |

## Шпаргалка

```php
// Визначення
final class MyMiddleware implements MiddlewareInterface
{
    public function process($req, $next): ResponseInterface { /* ... */ }
}

// Під’єднання
$app->use(MyMiddleware::class);                       // глобально
$app->use(new MyMiddleware($cfg));                    // глобально, готовий екземпляр
$app->get($p, $h)->middleware(MyMiddleware::class);   // помаршрутно
$app->group($p, fn($g) => /* */)->middleware(MyMiddleware::class); // погрупово

// Зміна запиту / відповіді
$req = $req->withAttribute('user', $user);
$res = $next->handle($req)->withHeader('X-Foo', 'bar');

// Переривання ланцюжка
return Response::json(['error' => 'denied'], 401);
```

[Security-middleware →](security)
