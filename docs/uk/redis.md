---
layout: page
title: Redis
nav_order: 22
---

# Redis

`Lift\Redis\RedisClient` — це тонка обгортка над розширенням `phpredis`. Вона реалізує `Lift\Redis\RedisClientInterface` — контракт, що використовується `RedisCache`, `RedisQueue`, `RedisSessionStore` і middleware обмеження частоти.

> Ментальна модель: «мінімально корисний API Redis». Рядки, лічильники, списки, sorted sets, TTL. Жодного pub/sub, жодних streams, жодного кластера — для них беріть сирий екземпляр `\Redis` через `$client->raw()`.

## Навіщо окремий інтерфейс?

Ви можете використовувати кеш, черги та сесії Lift із будь-яким Redis-подібним бекендом — `phpredis`, `Predis`, мок у пам’яті для тестів — поки він реалізує `RedisClientInterface`. Код вашого застосунку розв’язаний від того, який клієнт фактично в коробці.

## Налаштування

### Потрібне розширення `phpredis`

Драйвер за замовчуванням використовує C-розширення, бо воно приблизно в 3 рази швидше за userland-клієнти:

```bash
sudo apt install php8.3-redis      # Debian/Ubuntu
brew install php-redis             # macOS через brew tap
```

Потім у PHP:

```php
use Lift\Redis\RedisClient;
use Lift\Redis\RedisClientInterface;

$app->singleton(RedisClientInterface::class, fn() => new RedisClient(
    host:    $_ENV['REDIS_HOST']     ?? '127.0.0.1',
    port:    (int) ($_ENV['REDIS_PORT'] ?? 6379),
    timeout: 1.5,
    prefix:  'myapp:',              // застосовується до кожного ключа автоматично
    db:      0,
    auth:    $_ENV['REDIS_PASSWORD'] ?? '',
));
```

Конструктор підключається негайно й викидає `RuntimeException`, якщо Redis недосяжний.

### Зв’язування в контейнері

Прив’язуйте до **інтерфейсу**, не до конкретного класу — щоб тести могли підмінити мок:

```php
$app->singleton(RedisClientInterface::class, fn() => new RedisClient(...));
```

Потім сервіси вказують тип інтерфейсу:

```php
class FeedRepository
{
    public function __construct(private readonly RedisClientInterface $redis) {}
}
```

## Інтерфейс

```php
// Рядки / загальне
$redis->get($key);                  // string|false
$redis->set($key, $value, $ttl = 0);
$redis->del(...$keys);              // int (кількість видалених)
$redis->exists($key);               // int (1 або 0)
$redis->expire($key, $ttl);
$redis->ttl($key);                  // int  ( -1 без терміну, -2 відсутній )

// Лічильники
$redis->incr($key);                 // int (атомарно +1)
$redis->incrBy($key, $by);          // int (атомарно +N)

// Списки  — використовуються RedisQueue
$redis->lPush($key, ...$values);    // int (нова довжина)
$redis->rPop($key);                 // string|false
$redis->lLen($key);                 // int

// Sorted sets — використовуються відкладеними чергами
$redis->zAdd($key, $score, $member);
$redis->zRangeByScore($key, $min, $max);
$redis->zRem($key, ...$members);

// З’єднання
$redis->ping();                     // bool
$redis->select($db);                // перемкнути логічну БД
```

Це весь API.

## Приклади використання

### Простий ключ/значення

```php
$redis->set('feature:darkmode', '1', 3600);
$enabled = $redis->get('feature:darkmode') === '1';
$redis->del('feature:darkmode');
```

Значення завжди рядки на проводі. Для складних даних серіалізуйте самі (або використовуйте [Кеш](cache), який робить це за вас).

### Лічильники

```php
$views = $redis->incr("post:42:views");          // атомарно +1
$redis->expire("post:42:views", 86400);          // минути через день
```

`incr` повертає нове значення. Використовуйте для лічильників переглядів, обмежень частоти, усього, що потрібно рахувати конкурентно без гонок.

### Черги (списки)

```php
// Виробник
$redis->lPush('jobs', json_encode(['type' => 'send_email', 'to' => 'a@b.c']));

// Воркер
while (true) {
    $job = $redis->rPop('jobs');
    if ($job === false) { sleep(1); continue; }
    handle(json_decode($job, true));
}
```

Для справжніх можливостей черги (повтори, backoff, кілька драйверів) використовуйте [Черги](queues) — вони будуються на цьому примітиві.

### Відкладені задачі (sorted sets)

```php
// Запланувати задачу на час T
$redis->zAdd('jobs:delayed', $runAt = time() + 60, json_encode($payload));

// Збирач воркера — щосекунди
foreach ($redis->zRangeByScore('jobs:delayed', '-inf', (string) time()) as $job) {
    $redis->lPush('jobs', $job);
    $redis->zRem('jobs:delayed', $job);
}
```

Драйвер черги Redis робить це за вас.

### Розподілене блокування (для бідних)

Для справжніх продакшен-блокувань використовуйте бібліотеку (наприклад, `redlock-php`). Для «достатньо хороших» стражів:

```php
$ok = $redis->set("lock:export", '1', 60);   // NX НЕ реалізовано в інтерфейсі
if ($ok) {
    try { runExport(); } finally { $redis->del("lock:export"); }
}
```

> Інтерфейсу Lift бракує `SET … NX` — опустіться до `$redis->raw()->set($k, '1', ['NX', 'EX' => 60])` для справжньої семантики взаємного виключення.

## Аварійний вихід — `raw()`

`RedisClient::raw()` повертає нижчележний екземпляр `\Redis` для операцій, що не входять в інтерфейс:

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

Використовуйте `raw()` помірно — усе, на що ви на нього покладаєтеся, не можна замокати в тестах без підробки самого `\Redis`.

## Тестування без справжнього Redis

Реалізуйте `RedisClientInterface` з бекендом у пам’яті:

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
    // …реалізуйте решту…
}

// У вашому TestCase:
$app->instance(RedisClientInterface::class, new FakeRedis());
```

Побудова повного фейка — кілька годин роботи — але це дозволяє вашому набору тестів запускатися без `docker run -p 6379:6379 redis`.

## Нотатки про високу доступність

- **Sentinel / Cluster** не вбудовані в `RedisClient`. Використовуйте `\Redis` напряму або використовуйте клієнт Predis за вашою власною реалізацією `RedisClientInterface`.
- **Пул з’єднань**: синглтон `RedisClient` — це одне TCP-з’єднання. Під PHP-FPM це одне з’єднання на воркер — нормально для більшості навантажень. Під RoadRunner/Swoole з’єднання повторно використовується між запитами, тож переконайтеся, що ваші запити короткі.
- **Таймаути** дуже важливі. Установіть `timeout: 1.5` (або менше) у продакшені — застряглий Redis не повинен потягнути за собою весь ваш API.

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| `RuntimeException: extension "redis" is required` | `phpredis` не встановлено | Установіть розширення (див. Налаштування). |
| Ключі виглядають `myapp:user:42`, а не `user:42` | Ви задали `prefix` у конструкторі | Або очікувана поведінка — або приберіть префікс. |
| `ttl()` повертає `-1`, коли ви задали TTL | `set()` з `$ttl=0` пропускає TTL; попередній `EXPIRE` був перезаписаний | Передайте `$ttl > 0` у `set()` або викличте `expire()` після. |
| Лічильник починається з `1`, а не `0` під час першого звернення | `incr` створює відсутні ключі з `0`, потім додає `1` → повертає `1` | Це правильно — читайте зсув на одиницю уважно. |
| Subscribe блокує весь застосунок | Pub/sub синхронний | Запускайте його в окремому процесі, ніколи всередині обробника запиту. |
| `auth()` падає після перезапуску | Redis оновлено до 6+ ACL; автентифікація лише за паролем застаріла | Передавайте у стилі `username:password` або оновіть конфігурацію Redis. |

## Шпаргалка

```php
$redis = new RedisClient(
    host: '127.0.0.1', port: 6379, timeout: 1.5,
    prefix: 'myapp:', db: 0, auth: $_ENV['REDIS_PASSWORD'],
);

$redis->set('k', 'v', 60);
$redis->get('k');                       // 'v'|false
$redis->del('k', 'k2');                 // int
$redis->incr('counter');                // +1, повертає нове значення
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
$redis->raw()->…;                       // будь-що інше
```

[Колекції →](collections)
