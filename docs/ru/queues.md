---
layout: page
title: Очереди
nav_order: 27
---

# Очереди

**Очередь** позволяет поместить медленную работу в буфер и дать отдельному воркер-процессу выполнить её позже — сохраняя HTTP-ответы быстрыми. Lift поставляет четыре драйвера (sync, в памяти array, Redis, база данных), базовый класс `AbstractJob`, повторные попытки, задержки, отслеживание проваленных задач и CLI-`Worker` с корректным завершением.

> Ментальная модель: **задача** — это сериализуемый PHP-объект с методом `handle()`. **Очередь** — это список задач, ожидающих выполнения. **Воркер** — это долгоживущий процесс, который извлекает задачи и вызывает `handle()`. Сбой, повтор, провал, повтор сначала.

## Когда ставить в очередь

| Операция                             | Sync или очередь? |
|--------------------------------------|-------------------|
| Отправка транзакционного письма      | **Очередь** — SMTP может занять секунды |
| Генерация PDF-отчёта                 | **Очередь** — держите запрос < 100 мс |
| Push-уведомление тысячам             | **Очередь** — рассылка веером в воркере |
| Общение с ненадёжным сторонним API   | **Очередь** — повторные попытки бесплатно |
| Чтение пользователя из БД для рендера страницы | Sync — пользователь ждёт |
| Обновление несвязанного кэша после записи | Очередь — некритичный путь |

Правило: всё, что строго не должно завершиться до возврата HTTP-ответа, должно ставиться в очередь.

## Тур за 60 секунд

```php
use Lift\Queue\AbstractJob;

// 1. Определить задачу
final class SendWelcomeEmail extends AbstractJob
{
    public function __construct(private readonly string $email) {}

    public function handle(): void
    {
        // …собственно отправить письмо…
    }
}

// 2. Поместить из обработчика
$app->post('/signup', function (Request $req) use ($app) {
    $email = $req->validate(['email' => 'required|email'])['email'];

    $app->dispatch(new SendWelcomeEmail($email));

    return Response::noContent();
});

// 3. Запустить воркер (отдельный CLI-процесс)
//   vendor/bin/lift queue:work
```

Это весь жизненный цикл. Обработчик возвращается за миллисекунды; воркер делает медленную работу в фоне.

## Обзор драйверов

| Драйвер        | Класс                     | Где живут задачи             | Использовать, когда                         |
|----------------|---------------------------|------------------------------|---------------------------------------------|
| **Sync**       | `SyncQueue`               | Нигде — выполняется сразу    | По умолчанию. Разработка/тесты. Простые приложения. |
| **Array**      | `ArrayQueue`              | Память PHP                   | Пакетирование в рамках запроса. Тесты.      |
| **Redis**      | `RedisQueue`              | Списки + sorted sets Redis   | Продакшен по умолчанию. Дёшево, быстро, распределённо. |
| **Database**   | `DatabaseQueue`           | SQL-таблица, которую вы контролируете | Когда у вас уже есть Postgres/MySQL и не хочется Redis. Идёт с таблицей проваленных задач бесплатно. |

Все четыре реализуют `QueueInterface` — код приложения не меняется при смене драйверов.

### Выбор драйвера

Логика загрузки:

```php
use Lift\Queue\QueueInterface;
use Lift\Queue\SyncQueue;
use Lift\Queue\RedisQueue;

$app->singleton(QueueInterface::class, function () use ($app) {
    return match ($_ENV['QUEUE_DRIVER'] ?? 'sync') {
        'redis' => new RedisQueue($app->make(\Lift\Redis\RedisClientInterface::class)),
        'db'    => new \Lift\Queue\DatabaseQueue($app->make(\Lift\Database\Connection::class)),
        'array' => new \Lift\Queue\ArrayQueue(),
        default => new SyncQueue(),
    };
});

// Необязательное сокращение:
$app->setQueue($app->make(QueueInterface::class));
```

Теперь `$app->queue()` / `$app->dispatch(...)` используют настроенный драйвер.

## Определение задач

Наследуйте `AbstractJob`, переопределите `handle()`. У всего остального есть разумное значение по умолчанию.

```php
final class ProcessReport extends AbstractJob
{
    // Переопределения на класс — необязательны
    protected string $queue = 'reports';   // имя очереди; по умолчанию 'default'
    protected int    $delay = 0;            // задержка (с) перед тем, как стать доступной
    protected int    $tries = 5;            // макс. попыток до failed()

    public function __construct(
        private readonly int $reportId,
    ) {}

    public function handle(): void
    {
        // …делать работу; выбросить исключение при провале для автоматического повтора…
    }

    // Переопределите, чтобы оповестить кого-то после провала всех повторов
    public function failed(\Throwable $e): void
    {
        error_log("Report {$this->reportId} permanently failed: " . $e->getMessage());
    }
}
```

> Задачи **сериализуются** при помещении (кроме `SyncQueue`). Все свойства конструктора должны быть сериализуемыми — никаких ресурсов, никаких замыканий, никаких PDO-дескрипторов. Передавайте идентификаторы и ищите богатые объекты внутри `handle()`.

### Плохо vs хорошо

```php
// ❌ НЕПРАВИЛЬНО — $user, $logger не сериализуемы
new EmailJob($user, $logger);

// ✅ ПРАВИЛЬНО — только идентификаторы в конструкторе; искать внутри handle()
final class EmailJob extends AbstractJob
{
    public function __construct(private readonly int $userId) {}

    public function handle(): void
    {
        $user   = User::find($this->userId);
        $logger = $this->container()->get(LoggerInterface::class);
        // …
    }
}
```

### Внедрение сервисов в `handle()`

Lift не внедряет автоматически в `handle()`. Самые чистые варианты:

1. **Статический поиск** через шаг загрузки `RegisterContainerStatically`. Многословно.
2. **Получение из `App` через хук `setUp()`**. Связывает задачи с App.
3. **Создание базового класса**, который предоставляет помощник `container()` подклассам.

Наименее болезненный паттерн — вариант 3 плюс подкласс Worker, внедряющий контейнер во время `process()`. Фреймворк намеренно держит JobInterface минимальным — вы выбираете связывание.

## Отправка задач

```php
$app->dispatch($job);                     // через QueueInterface — использует настроенный драйвер
// ИЛИ
$queue = $app->queue();
$queue->push($job);                       // то же самое
$queue->later(60, $job);                  // доступна через 60 секунд от текущего момента
```

Осмотр / очистка:

```php
$queue->size('default');                  // количество ожидающих
$queue->size('reports');                  // на очередь
$queue->clear('default');                 // стереть одну очередь
```

`push()` уважает `$job->getDelay()` — передача задачи, у которой `getDelay()` равно 60, эквивалентна `later(60, $job)`.

## Запуск воркера

Долгоживущий процесс, который зацикливается, извлекает задачи, выполняет их, спит, когда пусто:

```bash
vendor/bin/lift queue:work
vendor/bin/lift queue:work --queue=reports --sleep=2 --max-jobs=100
```

Программный эквивалент:

```php
use Lift\Queue\Worker;

$worker = new Worker($app->queue(), $app->logger());
$worker->run(queue: 'default', sleep: 1, maxJobs: 0);
```

| Аргумент `run()` | Значение                                                   |
|------------------|------------------------------------------------------------|
| `queue`          | Имя для опроса. По умолчанию `'default'`.                  |
| `sleep`          | Секунды ожидания между пустыми опросами.                   |
| `maxJobs`        | Остановиться после N задач (`0` = без ограничения). Используйте для гигиены памяти. |

### Корректное завершение

Воркер устанавливает обработчики `SIGTERM` / `SIGINT` (требует `ext-pcntl`). Когда вы делаете `kill <pid>` или нажимаете Ctrl-C:

1. Он завершает **текущую** задачу.
2. Чисто выходит с количеством обработанных задач.

Запускайте несколько воркеров под **systemd**, **supervisord** или Kubernetes Deployment, чтобы они автоматически перезапускались при выходе. Распространённый паттерн — `--max-jobs=1000`, чтобы каждый воркер перерабатывал свою память после каждых 1000 задач.

Пример systemd-юнита:

```ini
[Unit]
Description=Lift queue worker
After=network.target redis.service

[Service]
ExecStart=/usr/bin/php /var/www/myapp/vendor/bin/lift queue:work --max-jobs=1000
Restart=always
RestartSec=1
User=www-data
KillSignal=SIGTERM
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
```

## Повторные попытки и окончательный провал

По умолчанию `$tries = 3`. Если `handle()` выбрасывает исключение:

1. Воркер логирует `Job attempt failed` и пробует снова немедленно.
2. После `getTries()` всего попыток он вызывает `$job->failed($exception)`.
3. С `DatabaseQueue` проваленная строка также помечается персистентно (см. ниже).

Чтобы перебросить исключение «сдаться сейчас», пропускающее повторы, пометьте его сигнальным значением и проверьте в `handle()`:

```php
public function handle(): void
{
    try {
        $this->doWork();
    } catch (PermanentlyBrokenException $e) {
        $this->tries = 1;       // нестандартно — Lift уважает только getTries()
        throw $e;
    }
}
```

(Фреймворк не поставляет `PermanentFailureException` — держите задачи простыми или вызывайте `failed()` напрямую + return.)

## Отложенные задачи

```php
$queue->later(300, new SendReminderEmail($userId));   // через 5 минут от текущего момента
```

Поведение зависит от драйвера:

- **Sync** — задержка игнорируется, выполняется сразу.
- **Array / Redis / Database** — хранятся отдельно; воркер видит их только после того, как пройдёт их временная метка `ready-at`.

`RedisQueue` использует sorted set с оценкой по ready-at, и `pop()` атомарно мигрирует подошедшие задачи в основной список. `DatabaseQueue` использует столбец `available_at` с `SELECT … FOR UPDATE SKIP LOCKED`.

## Несколько очередей

«Очередь» — это просто имя. Используйте их для приоритизации:

```php
final class CriticalPayment extends AbstractJob { protected string $queue = 'high'; }
final class CleanupOrphans extends AbstractJob { protected string $queue = 'low'; }

// Запустите воркер на очередь, масштабируйте их независимо:
vendor/bin/lift queue:work --queue=high   # 4 таких
vendor/bin/lift queue:work --queue=low    # 1 такой
```

Вы не получаете строгий приоритет «осушить high перед low» «из коробки»; вместо этого поднимайте больше воркеров на загруженной очереди.

## Проваленные задачи (DatabaseQueue)

Только `DatabaseQueue` персистентно хранит проваленные задачи. Он предоставляет небольшой API управления:

```php
$queue = $app->queue();   // предполагается, что это DatabaseQueue

$queue->failedCount('default');         // int
$queue->listFailed('default');          // массив строк (новейшие первыми)

// Перепоставить одну строку по ID
$queue->retry($rowId);

// Перепоставить каждую проваленную задачу
$queue->retryAll('default');

// Окончательно удалить все проваленные строки
$queue->clearFailed('default');
```

Проваленная строка хранит `payload`, `attempts`, `error`, `failed_at` — достаточно, чтобы отладить без повторного запуска.

### Восстановление после сбоя

Если воркер-процесс убит посреди задачи (OOM, `kill -9`, потеря питания), строка остаётся `reserved_at = <some-time>` навсегда. `DatabaseQueue::pop()` вызывает `pruneReserved()` на каждом опросе — любая строка, зарезервированная дольше `$reservedTimeout` секунд (по умолчанию 60), освобождается для повтора. Настройте таймаут, если ваши задачи законно занимают дольше.

### Добавление столбцов приложения

Наследуйте `HasDatabaseExtra`, если хотите сохранять идентификаторы арендаторов, корреляционные идентификаторы и т. п. в таблицу задач:

```php
use Lift\Queue\AbstractJob;
use Lift\Queue\HasDatabaseExtra;

final class TenantJob extends AbstractJob implements HasDatabaseExtra
{
    public function __construct(public readonly string $tenantId, public readonly int $id) {}

    public function getDatabaseExtra(): array
    {
        return ['tenant_id' => $this->tenantId];
    }

    public function handle(): void { /* … */ }
}

// Определите дополнительный столбец при конструировании очереди:
new DatabaseQueue(
    $db,
    extraColumns: fn($t) => $t->string('tenant_id', 36)->nullable()->index(),
);
```

Теперь вы можете делать `SELECT … WHERE tenant_id = '…'` прямо к таблице очереди — удобно для операционных дашбордов.

## Безопасность: подписанные полезные нагрузки

`RedisQueue`, `DatabaseQueue` и `AmqpQueue` сериализуют задачи через `serialize()`. Любой с доступом на запись к вашему ключу Redis, строке БД или AMQP-обменнику мог бы сконструировать полезную нагрузку, запускающую инъекцию PHP-объекта через `unserialize`. Все три драйвера принимают необязательный `$secret`:

```php
new RedisQueue($redis, secret: $_ENV['QUEUE_SECRET']);
new DatabaseQueue($db,   secret: $_ENV['QUEUE_SECRET']);
new AmqpQueue($channel,  secret: $_ENV['QUEUE_SECRET']);
```

Когда секрет непуст, каждая полезная нагрузка подписывается HMAC-SHA256. Используйте один и тот же секрет на каждом воркере.

> **Начиная с 1.2.1:** когда секрет настроен, полезная нагрузка, прибывшая **без** подписанного конверта, отклоняется сразу — она никогда не передаётся в `unserialize()`. Более ранние версии молча принимали неподписанные полезные нагрузки даже при заданном секрете, что позволяло атакующему полностью обойти проверку HMAC. Настройка `$secret` настоятельно рекомендуется для любой не-`sync` очереди.

## Тестирование

Три варианта, от самого дешёвого к самому реалистичному.

### 1. `SyncQueue` (по умолчанию в тестах)

```php
$app->setQueue(new SyncQueue());

$app->dispatch(new SendWelcomeEmail($email));      // выполняется сразу
self::assertSame(1, $mailerSpy->count);             // можно проверять побочные эффекты
```

По умолчанию — если задача выбрасывает исключение, ваш тест выбрасывает. Воркер не нужен.

### 2. `ArrayQueue` + ручной `pop()`

```php
$queue = new ArrayQueue();
$app->setQueue($queue);

$response = $this->post('/signup', ['email' => 'a@b.c'])->assertCreated();

self::assertSame(1, $queue->size());
self::assertInstanceOf(SendWelcomeEmail::class, $queue->pop());
```

Позволяет проверить «задача была поставлена в очередь», не запуская её фактически.

### 3. Настоящий драйвер в CI

Для e2e-тестов направьте `QUEUE_DRIVER=redis` на тестовый Redis (или используйте SQLite `:memory:` + `DatabaseQueue`). Запустите воркер в отдельном процессе и используйте `Worker::process()` напрямую для синхронных утверждений.

## Операционные советы

- **Утечки памяти накапливаются.** PHP не возвращает память между запросами — используйте `--max-jobs=1000` и дайте systemd перезапускать воркер.
- **Один воркер, одна очередь.** Если смешать `--queue=default,reports`, пропускная способность загруженной очереди голодит тихую. Поднимайте выделенные воркеры.
- **Мониторьте `size()`** — настройте оповещения, когда глубина очереди непрерывно растёт.
- **Идемпотентность.** Воркеры могут выполнить задачу более одного раза (сбой до подтверждения, двойная отправка). Используйте ключи идемпотентности: `if (Db::exists("emails_sent.{$jobId}")) return;` в начале `handle()`.
- **Не ставьте в очередь внутри транзакции**, которую вы ещё не зафиксировали — воркер может попытаться найти строки, которых ещё нет. Помещайте **после** возврата `$db->transaction(...)`.

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| `Job processed` в логах, но ничего не произошло | Использовался `SyncQueue`, и `handle()` был молчалив | Перейдите на настоящий драйвер. |
| `Class not found` при старте воркера после деплоя | Воркер выполняет старый код | Перезапускайте воркер при каждом деплое. |
| Предупреждение `unserialize` | Класс задачи был переименован / удалён | Осушите старую очередь перед деплоем переименований; или прочитайте столбец `payload` вручную и перепоставьте. |
| Одна задача выполняется N раз | Нет идемпотентности; воркер упал после `handle()`, но до подтверждения | Добавьте ключ идемпотентности (`INSERT IGNORE INTO processed_jobs`). |
| Воркер ест ОЗУ | Долгоживущий процесс накапливает состояние | `--max-jobs=N`; цикл перезапуска через systemd. |
| `pcntl_signal not available` | PHP скомпилирован без pcntl | Установите `php-pcntl` или примите некорректные kill (задачи могут быть переобработаны). |
| Отложенные задачи никогда не выполняются | Воркеры опрашивают *неверное* имя очереди | `--queue=default` совпадает с `getQueue()`; проверьте написание. |

## Шпаргалка

```php
// Определить
final class EmailJob extends AbstractJob {
    protected int $tries = 5;
    public function __construct(private int $userId) {}
    public function handle(): void { /* … */ }
    public function failed(\Throwable $e): void { /* оповестить */ }
}

// Драйвер
$app->setQueue(new RedisQueue($redis, secret: $_ENV['QUEUE_SECRET']));

// Поместить
$app->dispatch(new EmailJob(42));
$app->queue()->later(60, new EmailJob(42));

// Воркер (CLI)
vendor/bin/lift queue:work --queue=default --sleep=1 --max-jobs=1000

// Операции с проваленными задачами (только DatabaseQueue)
$queue->failedCount();
$queue->retry($rowId);
$queue->retryAll();
$queue->clearFailed();
```

[События →](events)
