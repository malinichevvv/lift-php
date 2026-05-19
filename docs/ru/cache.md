---
layout: page
title: Кэш
nav_order: 20
---

# Кэш

`Lift\Cache\CacheInterface` — это крошечный контракт хранилища ключ/значение с двумя продакшен-драйверами (`ArrayCache`, `RedisCache`) и адаптером PSR-16 для сторонних библиотек.

> Ментальная модель: «запомни это значение на N секунд и верни мне его, когда я попрошу». Кэшировать можно всё, что поддаётся `serialize()`. Ни больше, ни меньше.

## Когда кэшировать

- Дорогие вычисления, результат которых редко меняется.
- Результаты запросов к базе данных, которым не нужна свежесть на каждом запросе.
- Счётчики ограничения частоты (атомарный инкремент через `increment()`).
- Агрегированные метрики («количество активных пользователей», обновляемое раз в минуту).
- Отрендеренные фрагменты HTML (см. [Шаблоны § renderCached](views#cached-rendering)).

**Не** кэшируйте: данные уровня запроса (используйте [атрибуты Request](request#middleware-attributes)), пользовательское состояние с требованиями к согласованности, всё, что нельзя позволить себе потерять при перезапуске Redis.

## Интерфейс

Каждый драйвер реализует:

```php
interface CacheInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value, int $ttl = 0): bool;   // 0 = без срока действия
    public function delete(string ...$keys): bool;
    public function has(string $key): bool;
    public function increment(string $key, int $by = 1): int;
    public function remember(string $key, int $ttl, callable $factory): mixed;
    public function flush(): bool;
}
```

## Настройка

### `ArrayCache` — для тестов и областей одного запроса

```php
use Lift\Cache\ArrayCache;
use Lift\Cache\CacheInterface;

$app->singleton(CacheInterface::class, fn() => new ArrayCache());
```

Живёт только в памяти PHP. Теряется по окончании запроса (под PHP-FPM). Полезен, когда:

- Вы пишете тесты и не хотите настоящий Redis.
- Кэш нужен только на один запрос (дедупликация повторяющихся обращений в одном обработчике).

### `RedisCache` — для продакшена

```php
use Lift\Cache\CacheInterface;
use Lift\Cache\RedisCache;
use Lift\Redis\RedisClient;

$app->singleton(CacheInterface::class, function () {
    $redis = new RedisClient(
        host: $_ENV['REDIS_HOST']   ?? '127.0.0.1',
        port: (int) ($_ENV['REDIS_PORT'] ?? 6379),
        auth: $_ENV['REDIS_PASSWORD'] ?? '',
    );
    return new RedisCache(
        $redis,
        prefix: 'myapp:cache:',
        secret: $_ENV['CACHE_HMAC_SECRET'] ?? '',   // рекомендуется — см. § Безопасность
    );
});
```

Теперь любое место в вашем коде может вызвать `make(CacheInterface::class)` (или внедрить через конструктор) и использовать кэш.

## Чтение и запись

```php
$cache = $app->make(CacheInterface::class);

// Сохранить значение на 5 минут
$cache->set('user:42', $user, 300);

// Прочитать обратно
$user = $cache->get('user:42');                   // null, если отсутствует
$user = $cache->get('user:42', $defaultValue);    // явное значение по умолчанию

// Проверка существования (не возвращает значение)
if ($cache->has('user:42')) { ... }

// Удалить
$cache->delete('user:42');
$cache->delete('user:42', 'user:43', 'user:44');  // пакетно

// Стереть всё
$cache->flush();
```

Семантика TTL:

| `$ttl` | Что означает                 |
|--------|------------------------------|
| `0` (по умолчанию) | Без срока действия — живёт до явного удаления или вытеснения. |
| `> 0`         | Жить указанное число секунд.  |

## `remember()` — самый полезный метод

Паттерн «вычислить или получить» в одном вызове:

```php
$users = $cache->remember('users:active', 60, function () use ($db) {
    return $db->table('users')->where('active', 1)->get();
});
```

- При первом вызове (и после истечения срока) замыкание выполняется, результат сохраняется и возвращается.
- Последующие вызовы в течение 60 с возвращают сохранённое значение, не обращаясь к БД.

Паттерн: предпочитайте `remember()` вместо `if (! $cache->has(...))` + `set()`. Один вызов, без гонок для типичного случая, вдвое меньше печатать.

## `increment()` — атомарные счётчики

Опирается на Redis `INCR` (по-настоящему атомарно между процессами). Возвращает новое значение:

```php
$count = $cache->increment('signups:today');         // +1
$count = $cache->increment('downloads:abc', 3);      // +3
```

Сценарии: ограничения частоты, счётчики просмотров, корзины A/B-тестов, длины очередей. Не пытайтесь делать `$n = $cache->get('x'); $cache->set('x', $n + 1)` — это создаёт гонку.

> Драйвер Redis хранит счётчики как **обычные целые числа**, не сериализованные. Не делайте `get()` счётчика, ожидая сложное значение; используйте `increment()` и `get()` (который возвращает приводимую к `int` строку) последовательно.

## PSR-16 — когда сторонняя библиотека этого требует

Некоторые библиотеки (особенно HTTP-клиенты, JWT-библиотеки) принимают `Psr\SimpleCache\CacheInterface`. Оберните ваш кэш Lift:

```php
use Lift\Cache\Psr16Adapter;

$psr16 = new Psr16Adapter($app->make(CacheInterface::class));

$someLibrary->setCache($psr16);   // довольна
```

`Psr16Adapter` поддерживает TTL в виде `DateInterval` и `getMultiple()` / `setMultiple()` / `deleteMultiple()`.

## Безопасность: HMAC-конверт (Redis)

`RedisCache` принимает необязательный параметр `secret`. **Используйте его в продакшене.**

```php
new RedisCache($redis, secret: $_ENV['CACHE_HMAC_SECRET']);
```

Почему: драйвер внутренне использует `unserialize()`, и запись в Redis откуда угодно (скомпрометированный сосед, неверно настроенный пользователь `MONITOR`, …) могла бы внедрить вредоносную полезную нагрузку, добивающуюся RCE через инъекцию PHP-объекта при следующем `get()`.

С `secret` каждое значение оборачивается в `{"v":1,"mac":"<hmac>","data":"<serialized>"}`. MAC проверяется до `unserialize()` — подделанные полезные нагрузки возвращают `null` вместо запуска чего-либо.

Ротация: когда секрет меняется, все существующие записи выглядят как промахи кэша (`null`) и естественным образом перезаполняются.

## Реальные паттерны

### Кэширование дорогого запроса

```php
class UserRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly CacheInterface $cache,
    ) {}

    public function topActive(): array
    {
        return $this->cache->remember('users:top:active', 60, function () {
            return $this->db->table('users')
                ->where('active', 1)
                ->orderByDesc('login_count')
                ->limit(10)
                ->get();
        });
    }
}
```

### Инвалидация кэша при записи

```php
public function updateUser(int $id, array $data): void
{
    $this->db->table('users')->where('id', $id)->update($data);
    $this->cache->delete("user:{$id}", 'users:top:active');
}
```

### Ограничение частоты по IP

```php
final class IpRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $maxPerMinute = 60,
    ) {}

    public function process($req, $next): ResponseInterface
    {
        $ip  = $req->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        $key = "rl:{$ip}:" . date('Y-m-d-H-i');

        $hits = $this->cache->increment($key);
        $this->cache->set($key, $hits, 70);   // обновлять TTL при каждом обращении

        if ($hits > $this->maxPerMinute) {
            throw new \Lift\Exception\TooManyRequestsException("Slow down", retryAfter: 60);
        }

        return $next->handle($req);
    }
}
```

Lift поставляет более функциональный `RateLimitMiddleware` — см. [Безопасность](security#rate-limiting). Сниппет выше — это принцип.

### Кэширование фрагмента HTML

См. [Шаблоны — кэшированный рендеринг](views#cached-rendering).

## Проектирование ключей кэша

- **Разделяйте по доменам пространствами имён.** `user:42`, `product:7`, `feed:home:42` — разделено `:`.
- **Включайте версию схемы данных**, чтобы деплой не отдавал старые формы:
  ```php
  "user:v3:42"
  ```
- **Избегайте пользовательского ввода в сыром виде** — хешируйте его: `'page:' . md5($url)`. Иначе атакующий может использовать кэш для снятия отпечатков ваших маршрутов / кражи чужих записей кэша.
- **Не помещайте персональные данные в ключи** — Redis логирует ключ при каждом `KEYS` / `MONITOR`. Используйте идентификаторы.

## Собственные драйверы

Реализуйте `CacheInterface`. Три правила:

1. `get()` возвращает *точно то* значение, что было передано в `set()` (обрабатывайте сериализацию).
2. `increment()` атомарен между процессами (или задокументируйте, что нет).
3. Уважайте `$ttl` в секундах; `0` означает отсутствие срока действия.

```php
final class FileCache implements CacheInterface
{
    public function __construct(private readonly string $dir) { … }
    public function get(string $key, mixed $default = null): mixed { … }
    public function set(string $key, mixed $value, int $ttl = 0): bool { … }
    // …
}
```

Драйвер Memcached в ~40 строк оставлен как упражнение — оберните `ext-memcached`.

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| Кэш всегда пуст под PHP-FPM | Используете `ArrayCache` в продакшене | Перейдите на `RedisCache`. |
| `get()` возвращает старые данные после деплоя | Схема изменилась; старый кэш всё ещё жив | Поднимите версию ключа кэша (`user:v2:…`). |
| Предупреждение `unserialize()` + 500 | Сохранили объект, чей класс больше не существует, или получили подделанную полезную нагрузку | Используйте `secret` + инвалидируйте ключ. |
| `increment()` возвращает 0 при промахе Redis | `incr` создаёт ключ со значением 1, поэтому первый вызов возвращает **1**, а не 0 | Это правильно — читайте внимательно. |
| Два запроса оба выполняют фабрику в `remember()` | «Громовое стадо» — первый промах создаёт гонку | Для очень дорогих операций возьмите блокировку Redis вокруг работы; или прогревайте заранее. |
| Память растёт при `ArrayCache` | TTL соблюдается только при `get`/`has` — без фонового вытеснения | Перезапустите воркер; или используйте Redis. |

## Шпаргалка

```php
$cache->set('k', $v, 60);
$cache->get('k', $defaultValue);
$cache->has('k');
$cache->delete('k', 'k2');
$cache->flush();

$cache->remember('users:active', 60, fn() => $db->table('users')->where(...)->get());

$cache->increment('rl:1.2.3.4');                // +1
$cache->increment('rl:1.2.3.4', 5);             // +5

// Адаптер PSR-16
$psr16 = new Psr16Adapter($cache);
$lib->setCache($psr16);
```

[Файловая система →](filesystem)
