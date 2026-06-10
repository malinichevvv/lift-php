# Lift 1.4: нотатки до релізу

Lift 1.4 зосереджений на production-безпеці та корисних робочих покращеннях, які не перетворюють мікрофреймворк на важкий full-stack.

## Безпека та коректність

- `SessionMiddleware` тепер створює свіжий request-scoped `Session` для кожного запиту. Це запобігає витоку mutable session state між клієнтами у RoadRunner, Swoole та FrankenPHP worker mode.
- `RedisCache::expire()` дозволяє `RateLimitMiddleware` зберігати Redis rate-limit counters як raw integers після `INCRBY` і окремо ставити TTL на першому запиті.
- Dynamic route patterns компілюються під час реєстрації маршруту або збірки route cache. Некоректні імена параметрів і зламані regex constraints падають до production traffic.
- `Jwt::decode()` валідує JWT header і відхиляє токени, де `alg` не збігається з налаштованим verifier.
- `Request::fromGlobals()` має налаштовуваний ліміт JSON body. Типове значення - 1 MiB; oversized JSON payload кидає `PayloadTooLargeException` зі статусом 413.
- Session cookies отримали налаштування `path`, `domain`, `sameSite`, `secure`, `httpOnly`, `partitioned`.

## Валідація та фільтри

`Request::filter()` додає легкий pipeline трансформації input перед validation:

```php
$data = $request->filter([
    'email' => 'trim|lowercase',
    'price' => 'numeric_string_to_float',
])->validate([
    'email' => 'required|email',
    'price' => 'required|numeric|min:0.01',
]);
```

Filters трансформують вхідні дані; вони не замінюють escaping HTML на виході та binding SQL-параметрів.

Нові validation rules: `alpha_dash`, `slug`, `domain`, `port`, `timezone`, `language_code`, `country_code`, `currency_code`, `latitude`, `longitude`, `hex_color`, `base64`, `strong_password`, `file`, `mimes`, `max_file_size`.

Nested wildcard validation тепер підтримує шляхи на кшталт `items.*.name` і `items.*.price`.

## Lifecycle та bootstrap

Lifecycle hooks корисні для tracing, metrics, audit logging і request IDs:

```php
$app->on('request.received', fn (Request $request) => null);
$app->on('route.matched', fn (Route $route, Request $request) => null);
$app->on('response.sending', fn (Response $response, Request $request) => null);
```

Config-driven bootstrap допомагає структурувати більші застосунки без service-provider шару:

```php
$app->bootstrap([
    ConfigureMiddleware::class,
    ConfigureDatabase::class,
    ConfigureRoutes::class,
]);
```

Кожен step може бути callable, invokable або мати метод `bootstrap(App $app): void`.

## Route cache CLI

У bundled CLI додано:

```bash
lift route:cache --bootstrap=bootstrap/app.php --path=storage/framework/routes.cache.php
lift route:clear --path=storage/framework/routes.cache.php
lift route:list --json
```

`routes:list` залишається доступною для backward compatibility.

## Production debug

Debug pages мають бути вимкнені у production. Для публічних помилок використовуйте явний production renderer:

```php
$app->debug(['enabled' => false]);
$app->onError(fn (Throwable $e, Request $request) => Response::json([
    'error' => 'Internal Server Error',
], 500));
```
