---
layout: page
title: Middleware безпеки
nav_order: 24
---

# Middleware безпеки

Lift постачає чотири middleware безпеки продакшен-рівня, які можна під’єднати в будь-який застосунок:

| Middleware                  | Клас                               | Розв’язує                                       |
|-----------------------------|------------------------------------|-------------------------------------------------|
| **CORS**                    | `Lift\Middleware\CorsMiddleware`   | Крос-доменні запити з браузера                  |
| **CSRF**                    | `Lift\Middleware\CsrfMiddleware`   | Міжсайтову підробку запитів (cookie-автентифікація) |
| **Обмеження частоти**       | `Lift\Middleware\RateLimitMiddleware` | Зловживання, перебір, некеровані клієнти      |
| **Заголовки безпеки**       | `Lift\Middleware\SecurityHeadersMiddleware` | HSTS, CSP, X-Frame-Options, …          |

Для **токенної автентифікації (Bearer JWT)** див. [JWT](jwt). Для хешування паролів і шифрування див. [Криптографію](crypto). Для типізованих HTTP-винятків Lift (401/403/429) див. [Обробку помилок](errors).

## Ментальна модель

Це **middleware за PSR-15**. Ви реєструєте їх один раз через `$app->use(...)`, і вони загортають кожен запит. Кожен закриває один конкретний вектор атаки — обирайте ті, що вам справді потрібні (більшості API потрібні CORS + обмеження частоти + заголовки безпеки; застосунки із session-cookie додають CSRF).

---

## CORS

CORS — це воротар браузера для крос-доменних XHR/fetch. Без нього сторінка на `app.com` не може прочитати відповіді від `api.com` — крапка. Middleware:

1. Відповідає на **preflight**-запити `OPTIONS` правильними заголовками `Access-Control-*`.
2. Додає `Access-Control-Allow-Origin` до кожної реальної відповіді.

### Швидкий старт

```php
use Lift\Middleware\CorsMiddleware;

$app->use(new CorsMiddleware());                          // wildcard, без облікових даних
$app->use(new CorsMiddleware(origins: 'https://app.example.com'));
$app->use(new CorsMiddleware(origins: ['https://a.com', 'https://b.com']));
```

### Повна конфігурація

```php
$app->use(new CorsMiddleware(
    origins:       ['https://app.example.com', 'https://admin.example.com'],
    methods:       ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    headers:       ['Content-Type', 'Authorization', 'X-Requested-With'],
    exposeHeaders: ['X-Total-Count', 'X-RateLimit-Remaining'],
    credentials:   true,         // дозволити cookie / Authorization за крос-домену
    maxAge:        7200,         // браузер може кешувати preflight 2 години
));
```

| Аргумент           | За замовчуванням                        | Примітки                                      |
|--------------------|-----------------------------------------|-----------------------------------------------|
| `origins`          | `'*'`                                   | Рядок, список рядків або `'*'`                |
| `methods`          | GET/POST/PUT/PATCH/DELETE/OPTIONS       | Перелічені в `Allow-Methods`                  |
| `headers`          | Content-Type/Authorization/Accept/X-Requested-With | Перелічені в `Allow-Headers`       |
| `exposeHeaders`    | `[]`                                    | Перелічені в `Expose-Headers`                 |
| `credentials`      | `false`                                 | Установіть `true` для cookie/auth за крос-домену |
| `maxAge`           | `86400`                                 | Секунди кешування preflight у браузері        |

### Підстановні піддомени

```php
$app->use(new CorsMiddleware(origins: '*.example.com'));
// Дозволяє https://api.example.com, https://admin.example.com, але НЕ https://example.com.
```

Підстановний знак відповідає **одному** рівню піддомену. Перелічіть вершину окремо, якщо вона вам теж потрібна.

### Застереження щодо облікових даних

Коли `credentials: true`, браузер **відмовляється** від підстановних джерел. Middleware відображає `Origin` запиту назад, якщо він збігається зі списком дозволених, і додає `Vary: Origin`, щоб кеші розрізняли відповіді за джерелом.

> **Починаючи з 1.2.1:** поєднання `origins: '*'` з `credentials: true` викидає `InvalidArgumentException` під час конструювання. Відображення довільного джерела поряд із `Access-Control-Allow-Credentials: true` дозволило б будь-якому сайту виконувати крос-доменні запити з обліковими даними. Завжди передавайте явний список дозволених, коли облікові дані ввімкнено.

### Порядок має значення — реєструйте CORS першим

```php
$app->use(new CorsMiddleware(origins: 'https://app.com'));   // 1-й
$app->use(new RateLimitMiddleware(/* … */));                 // 2-й
$app->use(new AuthMiddleware(/* … */));                      // 3-й
```

Preflight-запити не несуть заголовків автентифікації — якщо ваш auth-middleware виконується першим, він поверне їм 401, і браузер відмовиться від реального запиту. Завжди ставте CORS у самий верх.

---

## CSRF

CSRF — проблема лише коли **браузер автоматично надсилає облікові дані** (cookie, HTTP Basic) за міжсайтових запитів. Для чистих JSON API, що автентифікуються через `Authorization: Bearer ...`, CSRF **не** є проблемою — пропустіть цей middleware.

CSRF у Lift використовує патерн **Double-Submit Cookie**: випадковий токен встановлюється як cookie І має бути повернений на мутувальних запитах через заголовок або поле форми.

### Налаштування

```php
use Lift\Middleware\CsrfMiddleware;

$app->use(new CsrfMiddleware(
    secret:     $_ENV['APP_SECRET'],     // надійний випадковий секрет — однаковий на всіх серверах
    secure:     true,                    // прапор Secure (вимагати HTTPS)
    sameSite:   'Lax',                   // 'Strict' | 'Lax' | 'None'
    cookiePath: '/',
));
```

Middleware встановлює cookie `csrf_token` на кожній відповіді й надає той самий токен через `$req->getAttribute('csrf_token')`, тож шаблони можуть його вбудувати.

### Як клієнти надсилають токен

Два способи — обирайте підхожий клієнту. Middleware перевіряє обидва.

#### A) Заголовок (бажано для AJAX/SPA)

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

#### B) Приховане поле форми (традиційні HTML-форми)

```php
<form method="POST" action="/posts">
    <input type="hidden" name="_csrf_token" value="<?= $view->e($csrfToken) ?>">
    …
</form>
```

У шаблоні: `$csrfToken = $req->getAttribute('csrf_token');` — поділіться ним через `$app->views()->share('csrf_token', …)` з невеликого middleware початкового завантаження.

### Безпечні методи

`GET`, `HEAD`, `OPTIONS`, `TRACE` завжди дозволені — вони мають бути **без побічних ефектів**. Якщо ваш застосунок робить руйнівні зміни на GET — це баг, а не захист CSRF.

### Що відбувається за невідповідності

403 JSON:

```json
{ "error": "CSRF token mismatch" }
```

### Коли пропустити CSRF

- Чистий JSON API + автентифікація Bearer-токеном.
- Ендпоінти вебхуків (викликач не браузер; заголовок підпису — це автентифікація).
- API-ключі у вигляді статичних токенів.

Для змішаних застосунків: реєструйте CSRF глобально й виключайте API-маршрути через [групу маршрутів](routing#route-groups) — застосовуйте CSRF як погруповий middleware, а не глобальний.

---

## Обмеження частоти

Обмеження частоти за принципом token-bucket / fixed-window на базі [Кешу](cache). Лічильник — це просто Redis `INCR` на клієнта на вікно — працює між процесами та серверами.

### Швидкий старт

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

Це дозволяє **100 запитів на хвилину на IP**, повертає 429 за перевищення.

### Заголовки, які він додає

Кожна відповідь (дозволена або 429) включає:

```
RateLimit-Limit:     100
RateLimit-Remaining: 73
RateLimit-Reset:     1715692800       (Unix-мітка часу, коли вікно скидається)
```

І за 429:

```
Retry-After: 60
```

### Вибір ключа обмеження частоти

За замовчуванням — `REMOTE_ADDR`. Налаштуйте через замикання:

```php
new RateLimitMiddleware(
    store:        $cache,
    maxRequests:  1000,
    windowSeconds: 3600,
    keyResolver:  function (Request $req): string {
        $user = $req->getAttribute('user');
        return $user !== null
            ? "user:{$user->id}"                          // на автентифікованого користувача
            : 'ip:' . ($req->getServerParams()['REMOTE_ADDR'] ?? 'anon');
    },
);
```

Інші корисні ключі:

- **API-ключ:** `"key:" . $req->getHeaderLine('X-API-Key')`
- **З прив’язкою до ендпоінта:** `"ep:{$req->getUri()->getPath()}:{$ip}"` — хай у кожного ендпоінта буде свій бюджет.

### Багаторівневі ліміти

Застосовуйте різний middleware до різних груп маршрутів:

```php
$loose = new RateLimitMiddleware($cache, maxRequests: 60,  windowSeconds: 60, prefix: 'rl:loose:');
$tight = new RateLimitMiddleware($cache, maxRequests: 10,  windowSeconds: 60, prefix: 'rl:tight:');

$app->group('/api', fn($g) => /* … */)->middleware($loose);
$app->group('/api/auth', fn($g) => /* login, register, password-reset */)->middleware($tight);
```

Окремий `prefix:` тримає лічильники роздільними.

### Розробка без Redis

Використовуйте `ArrayCache` (лише на воркер — марний за кількома воркерами, але годиться для `php -S`):

```php
new RateLimitMiddleware(new \Lift\Cache\ArrayCache(), maxRequests: 60, windowSeconds: 60);
```

### За зворотним проксі

`REMOTE_ADDR` — це IP проксі, не клієнта. Довіряйте `X-Forwarded-For`, лише якщо ви контролюєте проксі:

```php
keyResolver: function (Request $req): string {
    $fwd = $req->getHeaderLine('X-Forwarded-For');
    $ip  = $fwd !== '' ? trim(explode(',', $fwd)[0]) : ($req->getServerParams()['REMOTE_ADDR'] ?? 'anon');
    return "ip:{$ip}";
},
```

Інакше зловмисник може підробити заголовок і обійти ліміт.

---

## Заголовки безпеки

Однорядкове посилення захисту. Значення за замовчуванням розумні й консервативні.

```php
use Lift\Middleware\SecurityHeadersMiddleware;

$app->use(new SecurityHeadersMiddleware());
```

Лише це додає:

```
X-Content-Type-Options: nosniff
X-XSS-Protection: 1; mode=block
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Content-Security-Policy: default-src 'self'
Strict-Transport-Security: max-age=31536000; includeSubDomains
Permissions-Policy: camera=(), microphone=(), geolocation=()
```

### Налаштування під реальні застосунки

CSP за замовчуванням (`default-src 'self'`) блокує сторонні скрипти/стилі/шрифти. Більшості застосунків потрібно його послабити:

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

| Аргумент        | За замовчуванням                                     | Призначення                                          |
|-----------------|------------------------------------------------------|------------------------------------------------------|
| `csp`           | `default-src 'self'`                                 | Content-Security-Policy. `null` для вимкнення.       |
| `hsts`          | `max-age=31536000; includeSubDomains`                | HSTS. **Ставте `null` в HTTP-only dev-оточеннях.**   |
| `frameOptions`  | `DENY`                                               | `DENY` або `SAMEORIGIN`. Захист від click-jacking.   |
| `referrer`      | `strict-origin-when-cross-origin`                    | Стандартне безпечне для персональних даних значення. |
| `permissions`   | `camera=(), microphone=(), geolocation=()`           | Вимикає API сенсорів за замовчуванням. `null` — пропустити. |
| `noSniff`       | `true`                                               | Надсилає `X-Content-Type-Options: nosniff`.          |
| `xssProtect`    | `true`                                               | Застарілий XSS-аудитор IE/Edge — нешкідливий.        |

### Попередження щодо HSTS

`Strict-Transport-Security` каже браузерам *«спілкуйся зі мною лише через HTTPS»*, **постійно**. Якщо ви ввімкнете його на не-HTTPS сайті, браузери відмовляться завантажувати його, доки заголовок не мине (потенційно через рік). Завжди ставте `hsts: null` у розробці:

```php
$app->use(new SecurityHeadersMiddleware(
    hsts: $app->environment() === 'production' ? 'max-age=31536000; includeSubDomains' : null,
));
```

---

## Складання посиленого стека

Типовий продакшен-порядок (зверху — найзовнішній):

```php
$app->use(new SecurityHeadersMiddleware(/* … */));       // додає заголовки до кожної відповіді
$app->use(new CorsMiddleware(origins: [...]));           // обробляє preflight першим
$app->use(new RateLimitMiddleware($cache, /* … */));     // відхиляє до виконання реальної роботи
// $app->use(new CsrfMiddleware(...));                   // лише для застосунків із cookie-автентифікацією
$app->use(new RequestIdMiddleware());                    // ваш власний; призначає X-Request-Id
$app->use(new LoggingMiddleware($log));
// — ваші маршрути —
```

Middleware автентифікації та валідації прикріплюються **помаршрутно або погрупово**, не глобально, щоб неавтентифіковані маршрути (`/health`, `/login`) залишалися досяжними.

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| Помилка CORS у браузері, хоча ви задали `origins: '*'` | Ви також задали `credentials: true` | Оберіть: підстановне джерело АБО облікові дані; специфікація забороняє обидва. |
| 403 «CSRF token mismatch» на кожному POST форми | Cookie встановлена з `Secure`, але тестується по HTTP | Приберіть `secure: true` для розробки; використовуйте HTTPS у продакшені. |
| Обмеження частоти не застосовується між серверами | Використовуєте `ArrayCache` у продакшені | Перейдіть на `RedisCache` для спільного стану. |
| Сайт застряг недосяжним після промаху з HSTS | Увімкнули HSTS по HTTP | Вимкніть HSTS на стороні сервера, потім чекайте завершення `max-age` у кожному браузері. |
| CSP блокує вбудований `<script>` | Default-src включає лише `'self'` | Додайте `'unsafe-inline'` (погано) або використовуйте nonce/хеші скриптів (краще). |
| Preflight повертає 405 | Auth-middleware стоїть **перед** CORS і відхиляє OPTIONS | Перемістіть CORS на початок порядку `$app->use(...)`. |

## Шпаргалка

```php
// Обирайте, що вам потрібно; порядок = найзовнішній першим
$app->use(new SecurityHeadersMiddleware());
$app->use(new CorsMiddleware(origins: ['https://app.com']));
$app->use(new RateLimitMiddleware($cache, 100, 60));
$app->use(new CsrfMiddleware($_ENV['APP_SECRET']));   // лише застосунки із session-cookie
```

[JWT →](jwt)
