---
layout: page
title: Middleware безопасности
nav_order: 24
---

# Middleware безопасности

Lift поставляет четыре middleware безопасности продакшен-уровня, которые можно подключить в любое приложение:

| Middleware                  | Класс                              | Решает                                          |
|-----------------------------|------------------------------------|-------------------------------------------------|
| **CORS**                    | `Lift\Middleware\CorsMiddleware`   | Кросс-доменные запросы из браузера              |
| **CSRF**                    | `Lift\Middleware\CsrfMiddleware`   | Межсайтовую подделку запросов (cookie-аутентификация) |
| **Ограничение частоты**     | `Lift\Middleware\RateLimitMiddleware` | Злоупотребления, перебор, неуправляемые клиенты |
| **Заголовки безопасности**  | `Lift\Middleware\SecurityHeadersMiddleware` | HSTS, CSP, X-Frame-Options, …          |

Для **токенной аутентификации (Bearer JWT)** см. [JWT](jwt). Для хеширования паролей и шифрования см. [Криптографию](crypto). Для типизированных HTTP-исключений Lift (401/403/429) см. [Обработку ошибок](errors).

## Ментальная модель

Это **middleware по PSR-15**. Вы регистрируете их один раз через `$app->use(...)`, и они оборачивают каждый запрос. Каждый закрывает один конкретный вектор атаки — выбирайте те, что вам действительно нужны (большинству API нужны CORS + ограничение частоты + заголовки безопасности; приложения с session-cookie добавляют CSRF).

---

## CORS

CORS — это привратник браузера для кросс-доменных XHR/fetch. Без него страница на `app.com` не может прочитать ответы от `api.com` — точка. Middleware:

1. Отвечает на **preflight**-запросы `OPTIONS` правильными заголовками `Access-Control-*`.
2. Добавляет `Access-Control-Allow-Origin` к каждому реальному ответу.

### Быстрый старт

```php
use Lift\Middleware\CorsMiddleware;

$app->use(new CorsMiddleware());                          // wildcard, без учётных данных
$app->use(new CorsMiddleware(origins: 'https://app.example.com'));
$app->use(new CorsMiddleware(origins: ['https://a.com', 'https://b.com']));
```

### Полная конфигурация

```php
$app->use(new CorsMiddleware(
    origins:       ['https://app.example.com', 'https://admin.example.com'],
    methods:       ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    headers:       ['Content-Type', 'Authorization', 'X-Requested-With'],
    exposeHeaders: ['X-Total-Count', 'X-RateLimit-Remaining'],
    credentials:   true,         // разрешить cookie / Authorization при кросс-домене
    maxAge:        7200,         // браузер может кэшировать preflight 2 часа
));
```

| Аргумент           | По умолчанию                            | Примечания                                    |
|--------------------|-----------------------------------------|-----------------------------------------------|
| `origins`          | `'*'`                                   | Строка, список строк или `'*'`                |
| `methods`          | GET/POST/PUT/PATCH/DELETE/OPTIONS       | Перечислены в `Allow-Methods`                 |
| `headers`          | Content-Type/Authorization/Accept/X-Requested-With | Перечислены в `Allow-Headers`      |
| `exposeHeaders`    | `[]`                                    | Перечислены в `Expose-Headers`                |
| `credentials`      | `false`                                 | Установите `true` для cookie/auth при кросс-домене |
| `maxAge`           | `86400`                                 | Секунды кэширования preflight в браузере      |

### Подстановочные поддомены

```php
$app->use(new CorsMiddleware(origins: '*.example.com'));
// Разрешает https://api.example.com, https://admin.example.com, но НЕ https://example.com.
```

Подстановочный знак соответствует **одному** уровню поддомена. Перечислите вершину отдельно, если она вам тоже нужна.

### Оговорка про учётные данные

Когда `credentials: true`, браузер **отказывается** от подстановочных источников. Middleware отражает `Origin` запроса обратно, если он совпадает со списком разрешённых, и добавляет `Vary: Origin`, чтобы кэши различали ответы по источнику.

> **Начиная с 1.2.1:** сочетание `origins: '*'` с `credentials: true` выбрасывает `InvalidArgumentException` во время конструирования. Отражение произвольного источника наряду с `Access-Control-Allow-Credentials: true` позволило бы любому сайту выполнять кросс-доменные запросы с учётными данными. Всегда передавайте явный список разрешённых, когда учётные данные включены.

### Порядок имеет значение — регистрируйте CORS первым

```php
$app->use(new CorsMiddleware(origins: 'https://app.com'));   // 1-й
$app->use(new RateLimitMiddleware(/* … */));                 // 2-й
$app->use(new AuthMiddleware(/* … */));                      // 3-й
```

Preflight-запросы не несут заголовков аутентификации — если ваш auth-middleware выполняется первым, он вернёт им 401, и браузер откажется от реального запроса. Всегда ставьте CORS в самый верх.

---

## CSRF

CSRF — проблема только когда **браузер автоматически отправляет учётные данные** (cookie, HTTP Basic) при межсайтовых запросах. Для чистых JSON API, аутентифицирующихся через `Authorization: Bearer ...`, CSRF **не** является проблемой — пропустите этот middleware.

CSRF в Lift использует паттерн **Double-Submit Cookie**: случайный токен устанавливается как cookie И должен быть возвращён на мутирующих запросах через заголовок или поле формы.

### Настройка

```php
use Lift\Middleware\CsrfMiddleware;

$app->use(new CsrfMiddleware(
    secret:     $_ENV['APP_SECRET'],     // надёжный случайный секрет — одинаковый на всех серверах
    secure:     true,                    // флаг Secure (требовать HTTPS)
    sameSite:   'Lax',                   // 'Strict' | 'Lax' | 'None'
    cookiePath: '/',
));
```

Middleware устанавливает cookie `csrf_token` на каждом ответе и предоставляет тот же токен через `$req->getAttribute('csrf_token')`, так что шаблоны могут его встроить.

### Как клиенты отправляют токен

Два способа — выбирайте подходящий клиенту. Middleware проверяет оба.

#### A) Заголовок (предпочтителен для AJAX/SPA)

```js
fetch('/api/posts', {
    method:  'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCookie('csrf_token'),
    },
    body:    JSON.stringify(post),
});
```

#### B) Скрытое поле формы (традиционные HTML-формы)

```php
<form method="POST" action="/posts">
    <input type="hidden" name="_csrf_token" value="<?= $view->e($csrfToken) ?>">
    …
</form>
```

В шаблоне: `$csrfToken = $req->getAttribute('csrf_token');` — поделитесь им через `$app->views()->share('csrf_token', …)` из небольшого middleware начальной загрузки.

### Безопасные методы

`GET`, `HEAD`, `OPTIONS`, `TRACE` всегда разрешены — они должны быть **без побочных эффектов**. Если ваше приложение делает разрушительные изменения на GET — это баг, а не защита CSRF.

### Что происходит при несовпадении

403 JSON:

```json
{ "error": "CSRF token mismatch" }
```

### Когда пропустить CSRF

- Чистый JSON API + аутентификация Bearer-токеном.
- Эндпоинты вебхуков (вызывающий не браузер; заголовок подписи — это аутентификация).
- API-ключи в виде статических токенов.

Для смешанных приложений: регистрируйте CSRF глобально и исключайте API-маршруты через [группу маршрутов](routing#route-groups) — применяйте CSRF как погрупповой middleware, а не глобальный.

---

## Ограничение частоты

Ограничение частоты по принципу token-bucket / fixed-window на базе [Кэша](cache). Счётчик — это просто Redis `INCR` на клиента на окно — работает между процессами и серверами.

### Быстрый старт

```php
use Lift\Middleware\RateLimitMiddleware;
use Lift\Cache\RedisCache;
use Lift\Redis\RedisClient;

$app->use(new RateLimitMiddleware(
    store:         new RedisCache(new RedisClient()),
    maxRequests:   100,
    windowSeconds: 60,
));
```

Это разрешает **100 запросов в минуту на IP**, возвращает 429 при превышении.

### Заголовки, которые он добавляет

Каждый ответ (разрешённый или 429) включает:

```
RateLimit-Limit:     100
RateLimit-Remaining: 73
RateLimit-Reset:     1715692800       (Unix-метка времени, когда окно сбрасывается)
```

И при 429:

```
Retry-After: 60
```

### Выбор ключа ограничения частоты

По умолчанию — `REMOTE_ADDR`. Настройте через замыкание:

```php
new RateLimitMiddleware(
    store:        $cache,
    maxRequests:  1000,
    windowSeconds: 3600,
    keyResolver:  function (Request $req): string {
        $user = $req->getAttribute('user');
        return $user !== null
            ? "user:{$user->id}"                          // на аутентифицированного пользователя
            : 'ip:' . ($req->getServerParams()['REMOTE_ADDR'] ?? 'anon');
    },
);
```

Другие полезные ключи:

- **API-ключ:** `"key:" . $req->getHeaderLine('X-API-Key')`
- **С привязкой к эндпоинту:** `"ep:{$req->getUri()->getPath()}:{$ip}"` — пусть у каждого эндпоинта будет свой бюджет.

### Многоуровневые лимиты

Применяйте разный middleware к разным группам маршрутов:

```php
$loose = new RateLimitMiddleware($cache, maxRequests: 60,  windowSeconds: 60, prefix: 'rl:loose:');
$tight = new RateLimitMiddleware($cache, maxRequests: 10,  windowSeconds: 60, prefix: 'rl:tight:');

$app->group('/api', fn($g) => /* … */)->middleware($loose);
$app->group('/api/auth', fn($g) => /* login, register, password-reset */)->middleware($tight);
```

Отдельный `prefix:` держит счётчики раздельными.

### Разработка без Redis

Используйте `ArrayCache` (только на воркер — бесполезен за несколькими воркерами, но годится для `php -S`):

```php
new RateLimitMiddleware(new \Lift\Cache\ArrayCache(), maxRequests: 60, windowSeconds: 60);
```

### За обратным прокси

`REMOTE_ADDR` — это IP прокси, не клиента. Доверяйте `X-Forwarded-For`, только если вы контролируете прокси:

```php
keyResolver: function (Request $req): string {
    $fwd = $req->getHeaderLine('X-Forwarded-For');
    $ip  = $fwd !== '' ? trim(explode(',', $fwd)[0]) : ($req->getServerParams()['REMOTE_ADDR'] ?? 'anon');
    return "ip:{$ip}";
},
```

Иначе атакующий может подделать заголовок и обойти лимит.

---

## Заголовки безопасности

Однострочное усиление защиты. Значения по умолчанию разумны и консервативны.

```php
use Lift\Middleware\SecurityHeadersMiddleware;

$app->use(new SecurityHeadersMiddleware());
```

Только это добавляет:

```
X-Content-Type-Options: nosniff
X-XSS-Protection: 1; mode=block
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Content-Security-Policy: default-src 'self'
Strict-Transport-Security: max-age=31536000; includeSubDomains
Permissions-Policy: camera=(), microphone=(), geolocation=()
```

### Настройка под реальные приложения

CSP по умолчанию (`default-src 'self'`) блокирует сторонние скрипты/стили/шрифты. Большинству приложений нужно его ослабить:

```php
$app->use(new SecurityHeadersMiddleware(
    csp:          "default-src 'self'; "
                . "script-src 'self' https://cdn.jsdelivr.net; "
                . "style-src  'self' 'unsafe-inline' https://fonts.googleapis.com; "
                . "font-src   'self' https://fonts.gstatic.com; "
                . "img-src    'self' data: https:;",
    hsts:         'max-age=31536000; includeSubDomains; preload',
    frameOptions: 'SAMEORIGIN',
    permissions:  'camera=(), microphone=(), geolocation=(self)',
));
```

| Аргумент        | По умолчанию                                         | Назначение                                           |
|-----------------|------------------------------------------------------|------------------------------------------------------|
| `csp`           | `default-src 'self'`                                 | Content-Security-Policy. `null` для отключения.      |
| `hsts`          | `max-age=31536000; includeSubDomains`                | HSTS. **Ставьте `null` в HTTP-only dev-окружениях.** |
| `frameOptions`  | `DENY`                                               | `DENY` или `SAMEORIGIN`. Защита от click-jacking.    |
| `referrer`      | `strict-origin-when-cross-origin`                    | Стандартное безопасное для персональных данных значение. |
| `permissions`   | `camera=(), microphone=(), geolocation=()`           | Отключает API сенсоров по умолчанию. `null` — пропустить. |
| `noSniff`       | `true`                                               | Отправляет `X-Content-Type-Options: nosniff`.        |
| `xssProtect`    | `true`                                               | Устаревший XSS-аудитор IE/Edge — безвреден.          |

### Предупреждение про HSTS

`Strict-Transport-Security` говорит браузерам *«общайся со мной только по HTTPS»*, **постоянно**. Если вы включите его на не-HTTPS сайте, браузеры откажутся загружать его, пока заголовок не истечёт (потенциально через год). Всегда ставьте `hsts: null` в разработке:

```php
$app->use(new SecurityHeadersMiddleware(
    hsts: $app->environment() === 'production' ? 'max-age=31536000; includeSubDomains' : null,
));
```

---

## Составление усиленного стека

Типичный продакшен-порядок (сверху — самый внешний):

```php
$app->use(new SecurityHeadersMiddleware(/* … */));       // добавляет заголовки к каждому ответу
$app->use(new CorsMiddleware(origins: [...]));           // обрабатывает preflight первым
$app->use(new RateLimitMiddleware($cache, /* … */));     // отклоняет до выполнения реальной работы
// $app->use(new CsrfMiddleware(...));                   // только для приложений с cookie-аутентификацией
$app->use(new RequestIdMiddleware());                    // ваш собственный; назначает X-Request-Id
$app->use(new LoggingMiddleware($log));
// — ваши маршруты —
```

Middleware аутентификации и валидации прикрепляются **помаршрутно или погруппово**, не глобально, чтобы неаутентифицированные маршруты (`/health`, `/login`) оставались достижимыми.

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| Ошибка CORS в браузере, хотя вы задали `origins: '*'` | Вы также задали `credentials: true` | Выберите: подстановочный источник ИЛИ учётные данные; спецификация запрещает оба. |
| 403 «CSRF token mismatch» на каждом POST формы | Cookie установлена с `Secure`, но тестируется по HTTP | Уберите `secure: true` для разработки; используйте HTTPS в продакшене. |
| Ограничение частоты не применяется между серверами | Используете `ArrayCache` в продакшене | Перейдите на `RedisCache` для общего состояния. |
| Сайт застрял недостижимым после промаха с HSTS | Включили HSTS по HTTP | Отключите HSTS на стороне сервера, затем ждите истечения `max-age` в каждом браузере. |
| CSP блокирует встроенный `<script>` | Default-src включает только `'self'` | Добавьте `'unsafe-inline'` (плохо) или используйте nonce/хеши скриптов (лучше). |
| Preflight возвращает 405 | Auth-middleware стоит **перед** CORS и отклоняет OPTIONS | Переместите CORS в начало порядка `$app->use(...)`. |

## Шпаргалка

```php
// Выбирайте, что вам нужно; порядок = самый внешний первым
$app->use(new SecurityHeadersMiddleware());
$app->use(new CorsMiddleware(origins: ['https://app.com']));
$app->use(new RateLimitMiddleware($cache, 100, 60));
$app->use(new CsrfMiddleware($_ENV['APP_SECRET']));   // только приложения с session-cookie
```

[JWT →](jwt)
