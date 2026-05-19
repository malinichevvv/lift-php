---
layout: page
title: Redis
nav_order: 22
---

# Redis

`Lift\Redis\RedisClient` — это тонкая обёртка над расширением `phpredis`. Она реализует `Lift\Redis\RedisClientInterface` — контракт, используемый `RedisCache`, `RedisQueue`, `RedisSessionStore` и middleware ограничения частоты.

> Ментальная модель: «минимально полезный API Redis». Строки, счётчики, списки, sorted sets, TTL. Никакого pub/sub, никаких streams, никакого кластера — для них берите сырой экземпляр `\Redis` через `$client->raw()`.

## Зачем отдельный интерфейс?

Вы можете использовать кэш, очереди и сессии Lift с любым Redis-подобным бэкендом — `phpredis`, `Predis`, мок в памяти для тестов — пока он реализует `RedisClientInterface`. Код вашего приложения развязан от того, какой клиент фактически в коробке.

## Настройка

### Требуется расширение `phpredis`

Драйвер по умолчанию использует C-расширение, потому что оно примерно в 3 раза быстрее, чем userland-клиенты:

```bash
sudo apt install php8.3-redis      # Debian/Ubuntu
brew install php-redis             # macOS через brew tap
```

Затем в PHP:

```php
use Lift\Redis\RedisClient;
use Lift\Redis\RedisClientInterface;

$app->singleton(RedisClientInterface::class, fn() => new RedisClient(
    host:    $_ENV['REDIS_HOST']     ?? '127.0.0.1',
    port:    (int) ($_ENV['REDIS_PORT'] ?? 6379),
    timeout: 1.5,
    prefix:  'myapp:',              // применяется к каждому ключу автоматически
    db:      0,
    auth:    $_ENV['REDIS_PASSWORD'] ?? '',
));
```

Конструктор подключается немедленно и выбрасывает `RuntimeException`, если Redis недостижим.

### Связывание в контейнере

Привязывайте к **интерфейсу**, не к конкретному классу — чтобы тесты могли подменить мок:

```php
$app->singleton(RedisClientInterface::class, fn() => new RedisClient(...));
```

Затем сервисы указывают тип интерфейса:

```php
class FeedRepository
{
    public function __construct(private readonly RedisClientInterface $redis) {}
}
```

## Интерфейс

```php
// Строки / общее
$redis->get($key);                  // string|false
$redis->set($key, $value, $ttl = 0);
$redis->del(...$keys);              // int (количество удалённых)
$redis->exists($key);               // int (1 или 0)
$redis->expire($key, $ttl);
$redis->ttl($key);                  // int  ( -1 без срока, -2 отсутствует )

// Счётчики
$redis->incr($key);                 // int (атомарно +1)
$redis->incrBy($key, $by);          // int (атомарно +N)

// Списки  — используются RedisQueue
$redis->lPush($key, ...$values);    // int (новая длина)
$redis->rPop($key);                 // string|false
$redis->lLen($key);                 // int

// Sorted sets — используются отложенными очередями
$redis->zAdd($key, $score, $member);
$redis->zRangeByScore($key, $min, $max);
$redis->zRem($key, ...$members);

// Соединение
$redis->ping();                     // bool
$redis->select($db);                // переключить логическую БД
```

Это весь API.

## Примеры использования

### Простой ключ/значение

```php
$redis->set('feature:darkmode', '1', 3600);
$enabled = $redis->get('feature:darkmode') === '1';
$redis->del('feature:darkmode');
```

Значения всегда строки на проводе. Для сложных данных сериализуйте сами (или используйте [Кэш](cache), который делает это за вас).

### Счётчики

```php
$views = $redis->incr("post:42:views");          // атомарно +1
$redis->expire("post:42:views", 86400);          // истечь через день
```

`incr` возвращает новое значение. Используйте для счётчиков просмотров, ограничений частоты, всего, что нужно считать конкурентно без гонок.

### Очереди (списки)

```php
// Производитель
$redis->lPush('jobs', json_encode(['type' => 'send_email', 'to' => 'a@b.c']));

// Воркер
while (true) {
    $job = $redis->rPop('jobs');
    if ($job === false) { sleep(1); continue; }
    handle(json_decode($job, true));
}
```

Для настоящих возможностей очереди (повторы, backoff, несколько драйверов) используйте [Очереди](queues) — они строятся на этом примитиве.

### Отложенные задачи (sorted sets)

```php
// Запланировать задачу на время T
$redis->zAdd('jobs:delayed', $runAt = time() + 60, json_encode($payload));

// Сборщик воркера — каждую секунду
foreach ($redis->zRangeByScore('jobs:delayed', '-inf', (string) time()) as $job) {
    $redis->lPush('jobs', $job);
    $redis->zRem('jobs:delayed', $job);
}
```

Драйвер очереди Redis делает это за вас.

### Распределённая блокировка (для бедных)

Для настоящих продакшен-блокировок используйте библиотеку (например, `redlock-php`). Для «достаточно хороших» стражей:

```php
$ok = $redis->set("lock:export", '1', 60);   // NX НЕ реализован в интерфейсе
if ($ok) {
    try { runExport(); } finally { $redis->del("lock:export"); }
}
```

> Интерфейсу Lift не хватает `SET … NX` — опуститесь до `$redis->raw()->set($k, '1', ['NX', 'EX' => 60])` для настоящей семантики взаимного исключения.

## Аварийный выход — `raw()`

`RedisClient::raw()` возвращает нижележащий экземпляр `\Redis` для операций, не входящих в интерфейс:

```php
$pipeline = $redis->raw()->pipeline();
$pipeline->set('a', '1');
$pipeline->incrBy('b', 5);
$results = $pipeline->exec();

// Pub/Sub
$redis->raw()->subscribe(['channel1'], function ($redis, $channel, $message) { … });

// MGET
$values = $redis->raw()->mGet(['k1', 'k2', 'k3']);
```

Используйте `raw()` умеренно — всё, на что вы на него полагаетесь, нельзя замокать в тестах без подделки самого `\Redis`.

## Тестирование без настоящего Redis

Реализуйте `RedisClientInterface` с бэкендом в памяти:

```php
final class FakeRedis implements RedisClientInterface
{
    private array $data    = [];
    private array $expires = [];
    private array $lists   = [];

    public function get(string $key): string|false { return $this->data[$key] ?? false; }
    public function set(string $key, string $value, int $ttl = 0): bool
    {
        $this->data[$key] = $value;
        if ($ttl > 0) $this->expires[$key] = time() + $ttl;
        return true;
    }
    public function del(string ...$keys): int
    {
        $n = 0;
        foreach ($keys as $k) {
            if (isset($this->data[$k])) { unset($this->data[$k]); $n++; }
        }
        return $n;
    }
    // …реализуйте остальное…
}

// В вашем TestCase:
$app->instance(RedisClientInterface::class, new FakeRedis());
```

Построение полного фейка — несколько часов работы — но это позволяет вашему набору тестов запускаться без `docker run -p 6379:6379 redis`.

## Заметки о высокой доступности

- **Sentinel / Cluster** не встроены в `RedisClient`. Используйте `\Redis` напрямую или используйте клиент Predis за вашей собственной реализацией `RedisClientInterface`.
- **Пул соединений**: синглтон `RedisClient` — это одно TCP-соединение. Под PHP-FPM это одно соединение на воркер — нормально для большинства нагрузок. Под RoadRunner/Swoole соединение переиспользуется между запросами, так что убедитесь, что ваши запросы коротки.
- **Таймауты** очень важны. Установите `timeout: 1.5` (или меньше) в продакшене — застрявший Redis не должен утянуть с собой весь ваш API.

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| `RuntimeException: extension "redis" is required` | `phpredis` не установлен | Установите расширение (см. Настройку). |
| Ключи выглядят `myapp:user:42`, а не `user:42` | Вы задали `prefix` в конструкторе | Либо ожидаемое поведение — либо уберите префикс. |
| `ttl()` возвращает `-1`, когда вы задали TTL | `set()` с `$ttl=0` пропускает TTL; предыдущий `EXPIRE` был перезаписан | Передайте `$ttl > 0` в `set()` или вызовите `expire()` после. |
| Счётчик начинается с `1`, а не `0` при первом обращении | `incr` создаёт отсутствующие ключи с `0`, затем добавляет `1` → возвращает `1` | Это правильно — читайте смещение на единицу внимательно. |
| Subscribe блокирует всё приложение | Pub/sub синхронен | Запускайте его в отдельном процессе, никогда внутри обработчика запроса. |
| `auth()` падает после перезапуска | Redis обновлён до 6+ ACL; аутентификация только по паролю устарела | Передавайте в стиле `username:password` или обновите конфигурацию Redis. |

## Шпаргалка

```php
$redis = new RedisClient(
    host: '127.0.0.1', port: 6379, timeout: 1.5,
    prefix: 'myapp:', db: 0, auth: $_ENV['REDIS_PASSWORD'],
);

$redis->set('k', 'v', 60);
$redis->get('k');                       // 'v'|false
$redis->del('k', 'k2');                 // int
$redis->incr('counter');                // +1, возвращает новое значение
$redis->incrBy('counter', 10);
$redis->expire('k', 30);
$redis->ttl('k');

$redis->lPush('q', 'a', 'b');
$redis->rPop('q');
$redis->lLen('q');

$redis->zAdd('z', 1.5, 'm');
$redis->zRangeByScore('z', '-inf', '+inf');
$redis->zRem('z', 'm');

$redis->ping();
$redis->raw()->…;                       // что угодно ещё
```

[Коллекции →](collections)
