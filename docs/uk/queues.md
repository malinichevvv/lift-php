---
layout: page
title: Черги
nav_order: 27
---

# Черги

**Черга** дозволяє помістити повільну роботу в буфер і дати окремому воркер-процесу виконати її пізніше — зберігаючи HTTP-відповіді швидкими. Lift постачає чотири драйвери (sync, у пам’яті array, Redis, база даних), базовий клас `AbstractJob`, повторні спроби, затримки, відстеження провалених задач і CLI-`Worker` із коректним завершенням.

> Ментальна модель: **задача** — це серіалізовний PHP-об’єкт із методом `handle()`. **Черга** — це список задач, що очікують на виконання. **Воркер** — це довгоживучий процес, який витягує задачі й викликає `handle()`. Збій, повтор, провал, повтор спочатку.

## Коли ставити в чергу

| Операція                             | Sync чи черга? |
|--------------------------------------|----------------|
| Надсилання транзакційного листа      | **Черга** — SMTP може зайняти секунди |
| Генерація PDF-звіту                  | **Черга** — тримайте запит < 100 мс |
| Push-сповіщення тисячам              | **Черга** — розсилка віялом у воркері |
| Спілкування з ненадійним стороннім API | **Черга** — повторні спроби безкоштовно |
| Читання користувача з БД для рендеру сторінки | Sync — користувач чекає |
| Оновлення непов’язаного кешу після запису | Черга — некритичний шлях |

Правило: усе, що строго не має завершитися до повернення HTTP-відповіді, має ставитися в чергу.

## Тур за 60 секунд

```php
use Lift\Queue\AbstractJob;

// 1. Визначити задачу
final class SendWelcomeEmail extends AbstractJob
{
    public function __construct(private readonly string $email) {}

    public function handle(): void
    {
        // …власне надіслати лист…
    }
}

// 2. Помістити з обробника
$app->post('/signup', function (Request $req) use ($app) {
    $email = $req->validate(['email' => 'required|email'])['email'];

    $app->dispatch(new SendWelcomeEmail($email));

    return Response::noContent();
});

// 3. Запустити воркер (окремий CLI-процес)
//   vendor/bin/lift queue:work
```

Це весь життєвий цикл. Обробник повертається за мілісекунди; воркер робить повільну роботу у фоні.

## Огляд драйверів

| Драйвер        | Клас                      | Де живуть задачі             | Використовувати, коли                       |
|----------------|---------------------------|------------------------------|---------------------------------------------|
| **Sync**       | `SyncQueue`               | Ніде — виконується одразу    | За замовчуванням. Розробка/тести. Прості застосунки. |
| **Array**      | `ArrayQueue`              | Пам’ять PHP                  | Пакетування в межах запиту. Тести.          |
| **Redis**      | `RedisQueue`              | Списки + sorted sets Redis   | Продакшен за замовчуванням. Дешево, швидко, розподілено. |
| **Database**   | `DatabaseQueue`           | SQL-таблиця, яку ви контролюєте | Коли у вас уже є Postgres/MySQL і не хочеться Redis. Іде з таблицею провалених задач безкоштовно. |

Усі чотири реалізують `QueueInterface` — код застосунку не змінюється під час зміни драйверів.

### Вибір драйвера

Логіка завантаження:

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

// Необов’язкове скорочення:
$app->setQueue($app->make(QueueInterface::class));
```

Тепер `$app->queue()` / `$app->dispatch(...)` використовують налаштований драйвер.

## Визначення задач

Успадкуйте `AbstractJob`, перевизначте `handle()`. Усе інше має розумне значення за замовчуванням.

```php
final class ProcessReport extends AbstractJob
{
    // Перевизначення на клас — необов’язкові
    protected string $queue = 'reports';   // ім’я черги; за замовчуванням 'default'
    protected int    $delay = 0;            // затримка (с) перед тим, як стати доступною
    protected int    $tries = 5;            // макс. спроб до failed()

    public function __construct(
        private readonly int $reportId,
    ) {}

    public function handle(): void
    {
        // …робити роботу; викинути виняток за провалу для автоматичного повтору…
    }

    // Перевизначте, щоб сповістити когось після провалу всіх повторів
    public function failed(\Throwable $e): void
    {
        error_log("Report {$this->reportId} permanently failed: " . $e->getMessage());
    }
}
```

> Задачі **серіалізуються** під час поміщення (окрім `SyncQueue`). Усі властивості конструктора мають бути серіалізовними — жодних ресурсів, жодних замикань, жодних PDO-дескрипторів. Передавайте ідентифікатори й шукайте багаті об’єкти всередині `handle()`.

### Погано vs добре

```php
// ❌ НЕПРАВИЛЬНО — $user, $logger не серіалізовні
new EmailJob($user, $logger);

// ✅ ПРАВИЛЬНО — лише ідентифікатори в конструкторі; шукати всередині handle()
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

### Впровадження сервісів у `handle()`

Lift не впроваджує автоматично в `handle()`. Найчистіші варіанти:

1. **Статичний пошук** через крок завантаження `RegisterContainerStatically`. Багатослівно.
2. **Отримання з `App` через хук `setUp()`**. Зв’язує задачі з App.
3. **Створення базового класу**, який надає помічник `container()` підкласам.

Найменш болісний патерн — варіант 3 плюс підклас Worker, що впроваджує контейнер під час `process()`. Фреймворк навмисно тримає JobInterface мінімальним — ви обираєте зв’язування.

## Надсилання задач

```php
$app->dispatch($job);                     // через QueueInterface — використовує налаштований драйвер
// АБО
$queue = $app->queue();
$queue->push($job);                       // те саме
$queue->later(60, $job);                  // доступна через 60 секунд від поточного моменту
```

Огляд / очищення:

```php
$queue->size('default');                  // кількість очікуваних
$queue->size('reports');                  // на чергу
$queue->clear('default');                 // стерти одну чергу
```

`push()` поважає `$job->getDelay()` — передання задачі, у якої `getDelay()` дорівнює 60, еквівалентне `later(60, $job)`.

## Запуск воркера

Довгоживучий процес, який зациклюється, витягує задачі, виконує їх, спить, коли порожньо:

```bash
vendor/bin/lift queue:work
vendor/bin/lift queue:work --queue=reports --sleep=2 --max-jobs=100
```

Програмний еквівалент:

```php
use Lift\Queue\Worker;

$worker = new Worker($app->queue(), $app->logger());
$worker->run(queue: 'default', sleep: 1, maxJobs: 0);
```

| Аргумент `run()` | Значення                                                   |
|------------------|------------------------------------------------------------|
| `queue`          | Ім’я для опитування. За замовчуванням `'default'`.         |
| `sleep`          | Секунди очікування між порожніми опитуваннями.             |
| `maxJobs`        | Зупинитися після N задач (`0` = без обмеження). Використовуйте для гігієни пам’яті. |

### Коректне завершення

Воркер встановлює обробники `SIGTERM` / `SIGINT` (потребує `ext-pcntl`). Коли ви робите `kill <pid>` або натискаєте Ctrl-C:

1. Він завершує **поточну** задачу.
2. Чисто виходить із кількістю оброблених задач.

Запускайте кілька воркерів під **systemd**, **supervisord** або Kubernetes Deployment, щоб вони автоматично перезапускалися під час виходу. Поширений патерн — `--max-jobs=1000`, щоб кожен воркер переробляв свою пам’ять після кожних 1000 задач.

Приклад systemd-юніта:

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

## Повторні спроби й остаточний провал

За замовчуванням `$tries = 3`. Якщо `handle()` викидає виняток:

1. Воркер логує `Job attempt failed` і пробує знову негайно.
2. Після `getTries()` усього спроб він викликає `$job->failed($exception)`.
3. З `DatabaseQueue` провалений рядок також позначається персистентно (див. нижче).

Щоб перекинути виняток «здатися зараз», що пропускає повтори, позначте його сигнальним значенням і перевірте у `handle()`:

```php
public function handle(): void
{
    try {
        $this->doWork();
    } catch (PermanentlyBrokenException $e) {
        $this->tries = 1;       // нестандартно — Lift поважає лише getTries()
        throw $e;
    }
}
```

(Фреймворк не постачає `PermanentFailureException` — тримайте задачі простими або викликайте `failed()` напряму + return.)

## Відкладені задачі

```php
$queue->later(300, new SendReminderEmail($userId));   // через 5 хвилин від поточного моменту
```

Поведінка залежить від драйвера:

- **Sync** — затримка ігнорується, виконується одразу.
- **Array / Redis / Database** — зберігаються окремо; воркер бачить їх лише після того, як мине їхня часова мітка `ready-at`.

`RedisQueue` використовує sorted set з оцінкою за ready-at, і `pop()` атомарно мігрує підхожі задачі в основний список. `DatabaseQueue` використовує стовпець `available_at` з `SELECT … FOR UPDATE SKIP LOCKED`.

## Кілька черг

«Черга» — це просто ім’я. Використовуйте їх для пріоритизації:

```php
final class CriticalPayment extends AbstractJob { protected string $queue = 'high'; }
final class CleanupOrphans extends AbstractJob { protected string $queue = 'low'; }

// Запустіть воркер на чергу, масштабуйте їх незалежно:
vendor/bin/lift queue:work --queue=high   # 4 таких
vendor/bin/lift queue:work --queue=low    # 1 такий
```

Ви не отримуєте суворий пріоритет «осушити high перед low» «з коробки»; натомість піднімайте більше воркерів на завантаженій черзі.

## Провалені задачі (DatabaseQueue)

Лише `DatabaseQueue` персистентно зберігає провалені задачі. Він надає невеликий API керування:

```php
$queue = $app->queue();   // припускається, що це DatabaseQueue

$queue->failedCount('default');         // int
$queue->listFailed('default');          // масив рядків (найновіші першими)

// Перепоставити один рядок за ID
$queue->retry($rowId);

// Перепоставити кожну провалену задачу
$queue->retryAll('default');

// Остаточно видалити всі провалені рядки
$queue->clearFailed('default');
```

Провалений рядок зберігає `payload`, `attempts`, `error`, `failed_at` — достатньо, щоб налагодити без повторного запуску.

### Відновлення після збою

Якщо воркер-процес убито посеред задачі (OOM, `kill -9`, втрата живлення), рядок залишається `reserved_at = <some-time>` назавжди. `DatabaseQueue::pop()` викликає `pruneReserved()` на кожному опитуванні — будь-який рядок, зарезервований довше `$reservedTimeout` секунд (за замовчуванням 60), звільняється для повтору. Налаштуйте таймаут, якщо ваші задачі законно займають довше.

### Додавання стовпців застосунку

Успадкуйте `HasDatabaseExtra`, якщо хочете зберігати ідентифікатори орендарів, кореляційні ідентифікатори тощо в таблицю задач:

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

// Визначте додатковий стовпець під час конструювання черги:
new DatabaseQueue(
    $db,
    extraColumns: fn($t) => $t->string('tenant_id', 36)->nullable()->index(),
);
```

Тепер ви можете робити `SELECT … WHERE tenant_id = '…'` прямо до таблиці черги — зручно для операційних дашбордів.

## Безпека: підписані корисні навантаження

`RedisQueue`, `DatabaseQueue` і `AmqpQueue` серіалізують задачі через `serialize()`. Будь-хто з доступом на запис до вашого ключа Redis, рядка БД чи AMQP-обмінника міг би сконструювати корисне навантаження, що запускає ін’єкцію PHP-об’єкта через `unserialize`. Усі три драйвери приймають необов’язковий `$secret`:

```php
new RedisQueue($redis, secret: $_ENV['QUEUE_SECRET']);
new DatabaseQueue($db,   secret: $_ENV['QUEUE_SECRET']);
new AmqpQueue($channel,  secret: $_ENV['QUEUE_SECRET']);
```

Коли секрет непорожній, кожне корисне навантаження підписується HMAC-SHA256. Використовуйте той самий секрет на кожному воркері.

> **Починаючи з 1.2.1:** коли секрет налаштовано, корисне навантаження, що прибуло **без** підписаного конверта, відхиляється одразу — воно ніколи не передається в `unserialize()`. Раніші версії мовчки приймали непідписані корисні навантаження навіть за заданого секрета, що дозволяло зловмиснику повністю обійти перевірку HMAC. Налаштування `$secret` наполегливо рекомендується для будь-якої не-`sync` черги.

## Тестування

Три варіанти, від найдешевшого до найреалістичнішого.

### 1. `SyncQueue` (за замовчуванням у тестах)

```php
$app->setQueue(new SyncQueue());

$app->dispatch(new SendWelcomeEmail($email));      // виконується одразу
self::assertSame(1, $mailerSpy->count);             // можна перевіряти побічні ефекти
```

За замовчуванням — якщо задача викидає виняток, ваш тест викидає. Воркер не потрібен.

### 2. `ArrayQueue` + ручний `pop()`

```php
$queue = new ArrayQueue();
$app->setQueue($queue);

$response = $this->post('/signup', ['email' => 'a@b.c'])->assertCreated();

self::assertSame(1, $queue->size());
self::assertInstanceOf(SendWelcomeEmail::class, $queue->pop());
```

Дозволяє перевірити «задачу було поставлено в чергу», не запускаючи її фактично.

### 3. Справжній драйвер у CI

Для e2e-тестів спрямуйте `QUEUE_DRIVER=redis` на тестовий Redis (або використовуйте SQLite `:memory:` + `DatabaseQueue`). Запустіть воркер в окремому процесі й використовуйте `Worker::process()` напряму для синхронних тверджень.

## Операційні поради

- **Витоки пам’яті накопичуються.** PHP не повертає пам’ять між запитами — використовуйте `--max-jobs=1000` і дайте systemd перезапускати воркер.
- **Один воркер, одна черга.** Якщо змішати `--queue=default,reports`, пропускна здатність завантаженої черги голодить тиху. Піднімайте виділені воркери.
- **Моніторте `size()`** — налаштуйте сповіщення, коли глибина черги безперервно зростає.
- **Ідемпотентність.** Воркери можуть виконати задачу більше одного разу (збій до підтвердження, подвійне надсилання). Використовуйте ключі ідемпотентності: `if (Db::exists("emails_sent.{$jobId}")) return;` на початку `handle()`.
- **Не ставте в чергу всередині транзакції**, яку ви ще не зафіксували — воркер може спробувати знайти рядки, яких ще немає. Поміщайте **після** повернення `$db->transaction(...)`.

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| `Job processed` у логах, але нічого не сталося | Використовувався `SyncQueue`, і `handle()` був мовчазний | Перейдіть на справжній драйвер. |
| `Class not found` під час старту воркера після деплою | Воркер виконує старий код | Перезапускайте воркер під час кожного деплою. |
| Попередження `unserialize` | Клас задачі було перейменовано / видалено | Осушіть стару чергу перед деплоєм перейменувань; або прочитайте стовпець `payload` вручну й перепоставте. |
| Одна задача виконується N разів | Немає ідемпотентності; воркер упав після `handle()`, але до підтвердження | Додайте ключ ідемпотентності (`INSERT IGNORE INTO processed_jobs`). |
| Воркер їсть ОЗП | Довгоживучий процес накопичує стан | `--max-jobs=N`; цикл перезапуску через systemd. |
| `pcntl_signal not available` | PHP скомпільовано без pcntl | Установіть `php-pcntl` або прийміть некоректні kill (задачі можуть бути переоброблені). |
| Відкладені задачі ніколи не виконуються | Воркери опитують *невірне* ім’я черги | `--queue=default` збігається з `getQueue()`; перевірте написання. |

## Шпаргалка

```php
// Визначити
final class EmailJob extends AbstractJob {
    protected int $tries = 5;
    public function __construct(private int $userId) {}
    public function handle(): void { /* … */ }
    public function failed(\Throwable $e): void { /* сповістити */ }
}

// Драйвер
$app->setQueue(new RedisQueue($redis, secret: $_ENV['QUEUE_SECRET']));

// Помістити
$app->dispatch(new EmailJob(42));
$app->queue()->later(60, new EmailJob(42));

// Воркер (CLI)
vendor/bin/lift queue:work --queue=default --sleep=1 --max-jobs=1000

// Операції з проваленими задачами (лише DatabaseQueue)
$queue->failedCount();
$queue->retry($rowId);
$queue->retryAll();
$queue->clearFailed();
```

[Події →](events)
