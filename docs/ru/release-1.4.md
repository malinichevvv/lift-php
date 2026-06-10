# Lift 1.4: заметки к релизу

Lift 1.4 сфокусирован на production-безопасности и рабочих улучшениях, которые не превращают микрофреймворк в тяжёлый full-stack.

## Безопасность и корректность

- `SessionMiddleware` теперь создаёт свежий request-scoped `Session` для каждого запроса. Это исключает утечку mutable session state между клиентами в RoadRunner, Swoole и FrankenPHP worker mode.
- `RedisCache::expire()` позволяет `RateLimitMiddleware` хранить Redis rate-limit counters как raw integers после `INCRBY` и отдельно ставить TTL на первом запросе.
- Dynamic route patterns компилируются при регистрации маршрута или сборке route cache. Некорректные имена параметров и сломанные regex constraints падают до production traffic.
- `Jwt::decode()` валидирует JWT header и отклоняет токены, где `alg` не совпадает с настроенным verifier.
- `Request::fromGlobals()` имеет настраиваемый лимит JSON body. По умолчанию 1 MiB; oversized JSON payload выбрасывает `PayloadTooLargeException` со статусом 413.
- Session cookies получили настройки `path`, `domain`, `sameSite`, `secure`, `httpOnly`, `partitioned`.

## Валидация и фильтры

`Request::filter()` добавляет лёгкий pipeline трансформации input перед validation:

```php
$data = $request->filter([
    'email' => 'trim|lowercase',
    'price' => 'numeric_string_to_float',
])->validate([
    'email' => 'required|email',
    'price' => 'required|numeric|min:0.01',
]);
```

Filters трансформируют входные данные; они не заменяют escaping HTML на выходе и binding SQL-параметров.

Новые validation rules: `alpha_dash`, `slug`, `domain`, `port`, `timezone`, `language_code`, `country_code`, `currency_code`, `latitude`, `longitude`, `hex_color`, `base64`, `strong_password`, `file`, `mimes`, `max_file_size`.

Nested wildcard validation теперь поддерживает пути вроде `items.*.name` и `items.*.price`.

## Lifecycle и bootstrap

Lifecycle hooks удобны для tracing, metrics, audit logging и request IDs:

```php
$app->on('request.received', fn (Request $request) => null);
$app->on('route.matched', fn (Route $route, Request $request) => null);
$app->on('response.sending', fn (Response $response, Request $request) => null);
```

Config-driven bootstrap помогает структурировать большие приложения без service-provider слоя:

```php
$app->bootstrap([
    ConfigureMiddleware::class,
    ConfigureDatabase::class,
    ConfigureRoutes::class,
]);
```

Каждый step может быть callable, invokable или иметь метод `bootstrap(App $app): void`.

## Route cache CLI

В bundled CLI добавлены:

```bash
lift route:cache --bootstrap=bootstrap/app.php --path=storage/framework/routes.cache.php
lift route:clear --path=storage/framework/routes.cache.php
lift route:list --json
```

`routes:list` остаётся доступной для backward compatibility.

## Production debug

Debug pages должны быть отключены в production. Для публичных ошибок используйте явный production renderer:

```php
$app->debug(['enabled' => false]);
$app->onError(fn (Throwable $e, Request $request) => Response::json([
    'error' => 'Internal Server Error',
], 500));
```
