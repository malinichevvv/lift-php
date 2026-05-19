---
layout: page
title: Кеш
nav_order: 20
---

# Кеш

`Lift\Cache\CacheInterface` — це крихітний контракт сховища ключ/значення з двома продакшен-драйверами (`ArrayCache`, `RedisCache`) та адаптером PSR-16 для сторонніх бібліотек.

> Ментальна модель: «запам’ятай це значення на N секунд і поверни мені його, коли я попрошу». Кешувати можна все, що піддається `serialize()`. Ні більше, ні менше.

## Коли кешувати

- Дорогі обчислення, результат яких рідко змінюється.
- Результати запитів до бази даних, яким не потрібна свіжість на кожному запиті.
- Лічильники обмеження частоти (атомарний інкремент через `increment()`).
- Агреговані метрики («кількість активних користувачів», оновлювана раз на хвилину).
- Відрендерені фрагменти HTML (див. [Шаблони § renderCached](views#cached-rendering)).

**Не** кешуйте: дані рівня запиту (використовуйте [атрибути Request](request#middleware-attributes)), користувацький стан із вимогами до узгодженості, усе, що не можна дозволити собі втратити під час перезапуску Redis.

## Інтерфейс

Кожен драйвер реалізує:

```php
interface CacheInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value, int $ttl = 0): bool;   // 0 = без терміну дії
    public function delete(string ...$keys): bool;
    public function has(string $key): bool;
    public function increment(string $key, int $by = 1): int;
    public function remember(string $key, int $ttl, callable $factory): mixed;
    public function flush(): bool;
}
```

## Налаштування

### `ArrayCache` — для тестів і областей одного запиту

```php
use Lift\Cache\ArrayCache;
use Lift\Cache\CacheInterface;

$app->singleton(CacheInterface::class, fn() => new ArrayCache());
```

Живе лише в пам’яті PHP. Втрачається після завершення запиту (під PHP-FPM). Корисний, коли:

- Ви пишете тести й не хочете справжній Redis.
- Кеш потрібен лише на один запит (дедуплікація повторюваних звернень в одному обробнику).

### `RedisCache` — для продакшену

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
        secret: $_ENV['CACHE_HMAC_SECRET'] ?? '',   // рекомендується — див. § Безпека
    );
});
```

Тепер будь-яке місце у вашому коді може викликати `make(CacheInterface::class)` (або впровадити через конструктор) і використовувати кеш.

## Читання і запис

```php
$cache = $app->make(CacheInterface::class);

// Зберегти значення на 5 хвилин
$cache->set('user:42', $user, 300);

// Прочитати назад
$user = $cache->get('user:42');                   // null, якщо відсутнє
$user = $cache->get('user:42', $defaultValue);    // явне значення за замовчуванням

// Перевірка існування (не повертає значення)
if ($cache->has('user:42')) { ... }

// Видалити
$cache->delete('user:42');
$cache->delete('user:42', 'user:43', 'user:44');  // пакетно

// Стерти все
$cache->flush();
```

Семантика TTL:

| `$ttl` | Що означає                   |
|--------|------------------------------|
| `0` (за замовчуванням) | Без терміну дії — живе до явного видалення або витіснення. |
| `> 0`         | Жити вказану кількість секунд. |

## `remember()` — найкорисніший метод

Патерн «обчислити або отримати» в одному виклику:

```php
$users = $cache->remember('users:active', 60, function () use ($db) {
    return $db->table('users')->where('active', 1)->get();
});
```

- За першого виклику (і після завершення терміну) замикання виконується, результат зберігається й повертається.
- Наступні виклики протягом 60 с повертають збережене значення, не звертаючись до БД.

Патерн: віддавайте перевагу `remember()` над `if (! $cache->has(...))` + `set()`. Один виклик, без гонок для типового випадку, удвічі менше друкувати.

## `increment()` — атомарні лічильники

Спирається на Redis `INCR` (по-справжньому атомарно між процесами). Повертає нове значення:

```php
$count = $cache->increment('signups:today');         // +1
$count = $cache->increment('downloads:abc', 3);      // +3
```

Сценарії: обмеження частоти, лічильники переглядів, кошики A/B-тестів, довжини черг. Не намагайтеся робити `$n = $cache->get('x'); $cache->set('x', $n + 1)` — це створює гонку.

> Драйвер Redis зберігає лічильники як **звичайні цілі числа**, не серіалізовані. Не робіть `get()` лічильника, очікуючи складне значення; використовуйте `increment()` і `get()` (який повертає приводжуваний до `int` рядок) послідовно.

## PSR-16 — коли стороння бібліотека цього вимагає

Деякі бібліотеки (особливо HTTP-клієнти, JWT-бібліотеки) приймають `Psr\SimpleCache\CacheInterface`. Загорніть ваш кеш Lift:

```php
use Lift\Cache\Psr16Adapter;

$psr16 = new Psr16Adapter($app->make(CacheInterface::class));

$someLibrary->setCache($psr16);   // задоволена
```

`Psr16Adapter` підтримує TTL у вигляді `DateInterval` та `getMultiple()` / `setMultiple()` / `deleteMultiple()`.

## Безпека: HMAC-конверт (Redis)

`RedisCache` приймає необов’язковий параметр `secret`. **Використовуйте його у продакшені.**

```php
new RedisCache($redis, secret: $_ENV['CACHE_HMAC_SECRET']);
```

Чому: драйвер внутрішньо використовує `unserialize()`, і запис у Redis звідки завгодно (скомпрометований сусід, неправильно налаштований користувач `MONITOR`, …) міг би впровадити шкідливе корисне навантаження, що досягає RCE через ін’єкцію PHP-об’єкта під час наступного `get()`.

З `secret` кожне значення загортається у `{"v":1,"mac":"<hmac>","data":"<serialized>"}`. MAC перевіряється до `unserialize()` — підроблені корисні навантаження повертають `null` замість запуску чогось.

Ротація: коли секрет змінюється, усі наявні записи виглядають як промахи кешу (`null`) і природним чином перезаповнюються.

## Реальні патерни

### Кешування дорогого запиту

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

### Інвалідація кешу під час запису

```php
public function updateUser(int $id, array $data): void
{
    $this->db->table('users')->where('id', $id)->update($data);
    $this->cache->delete("user:{$id}", 'users:top:active');
}
```

### Обмеження частоти за IP

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
        $this->cache->set($key, $hits, 70);   // оновлювати TTL за кожного звернення

        if ($hits > $this->maxPerMinute) {
            throw new \Lift\Exception\TooManyRequestsException("Slow down", retryAfter: 60);
        }

        return $next->handle($req);
    }
}
```

Lift постачає більш функціональний `RateLimitMiddleware` — див. [Безпека](security#rate-limiting). Сніпет вище — це принцип.

### Кешування фрагмента HTML

Див. [Шаблони — кешований рендеринг](views#cached-rendering).

## Проєктування ключів кешу

- **Розділяйте за доменами просторами імен.** `user:42`, `product:7`, `feed:home:42` — розділено `:`.
- **Включайте версію схеми даних**, щоб деплой не віддавав старі форми:
  ```php
  "user:v3:42"
  ```
- **Уникайте користувацького вводу в сирому вигляді** — хешуйте його: `'page:' . md5($url)`. Інакше зловмисник може використати кеш для зняття відбитків ваших маршрутів / крадіжки чужих записів кешу.
- **Не розміщуйте персональні дані в ключах** — Redis логує ключ за кожного `KEYS` / `MONITOR`. Використовуйте ідентифікатори.

## Власні драйвери

Реалізуйте `CacheInterface`. Три правила:

1. `get()` повертає *точно те* значення, що було передане в `set()` (обробляйте серіалізацію).
2. `increment()` атомарний між процесами (або задокументуйте, що ні).
3. Поважайте `$ttl` у секундах; `0` означає відсутність терміну дії.

```php
final class FileCache implements CacheInterface
{
    public function __construct(private readonly string $dir) { … }
    public function get(string $key, mixed $default = null): mixed { … }
    public function set(string $key, mixed $value, int $ttl = 0): bool { … }
    // …
}
```

Драйвер Memcached у ~40 рядків залишено як вправу — загорніть `ext-memcached`.

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| Кеш завжди порожній під PHP-FPM | Використовуєте `ArrayCache` у продакшені | Перейдіть на `RedisCache`. |
| `get()` повертає старі дані після деплою | Схема змінилася; старий кеш усе ще живий | Підніміть версію ключа кешу (`user:v2:…`). |
| Попередження `unserialize()` + 500 | Зберегли об’єкт, чий клас більше не існує, або отримали підроблене корисне навантаження | Використовуйте `secret` + інвалідуйте ключ. |
| `increment()` повертає 0 за промаху Redis | `incr` створює ключ зі значенням 1, тому перший виклик повертає **1**, а не 0 | Це правильно — читайте уважно. |
| Два запити обидва виконують фабрику в `remember()` | «Громове стадо» — перший промах створює гонку | Для дуже дорогих операцій візьміть блокування Redis навколо роботи; або прогрівайте заздалегідь. |
| Пам’ять зростає за `ArrayCache` | TTL дотримується лише за `get`/`has` — без фонового витіснення | Перезапустіть воркер; або використовуйте Redis. |

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

[Файлова система →](filesystem)
