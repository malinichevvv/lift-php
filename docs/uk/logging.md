---
layout: page
title: Логування
nav_order: 29
---

# Логування

`Lift\Log\Logger` — це логер за **PSR-3** із під’єднуваними обробниками та форматувальниками. Він підтримує вісім стандартних рівнів логування, інтерполяцію плейсхолдерів і стеки незалежних обробників (наприклад, писати JSON у файл *і* кольорові рядки в stdout одночасно).

> Ментальна модель: **логер** отримує повідомлення. **Обробники** вирішують, куди вони йдуть (файл, stdout, syslog, /dev/null). **Форматувальники** вирішують, як вони виглядають (JSON, звичайний рядок тощо). Один логер, багато обробників, у кожного свій форматувальник і мінімальний рівень.

## Коли і скільки логувати

- **Помилки й попередження**: завжди. Інакше ви ніколи не дізнаєтеся, що ваш застосунок зламано.
- **Важливі бізнес-події**: так, через `info()`. («Замовлення №1234 розміщено», «Користувач зареєструвався».)
- **Деталі для налагодження**: `debug()` — вмикайте лише в розробці / для вибраних запитів.
- **Персональні дані**: ніколи. Маскуйте email, редагуйте токени. Логи — місце №1 витоку секретів.

## Приклад за 30 секунд

```php
use Lift\Log\Logger;
use Lift\Log\Handler\FileHandler;
use Lift\Log\Handler\StdoutHandler;
use Lift\Log\Formatter\JsonFormatter;

$logger = new Logger([
    new FileHandler('/var/log/myapp.log', 'debug', new JsonFormatter()),
    new StdoutHandler('warning'),
]);

$logger->info('User logged in', ['user_id' => 42]);
$logger->error('Payment failed', ['order_id' => 123, 'exception' => $e]);
$logger->warning('Hot-cache miss', ['key' => 'user:42']);
```

Один і той самий виклик `info(...)` проходить через **обидва** обробники: файл отримує повний JSON-рядок, stdout не бачить нічого (фільтр рівня), а виклик `warning()` потрапив би в обидва.

## Рівні PSR-3

Стандартні ступені серйозності, від найсерйознішого до найменшого:

| Метод         | Рівень       | Використовувати для                           |
|---------------|--------------|-----------------------------------------------|
| `emergency()` | `emergency`  | Система непридатна до використання            |
| `alert()`     | `alert`      | Дію треба вжити негайно                       |
| `critical()`  | `critical`   | Критичні умови (компонент упав)               |
| `error()`     | `error`      | Помилки, що потребують уваги, але не зупиняють застосунок |
| `warning()`   | `warning`    | Щось підозріле, може стати помилкою           |
| `notice()`    | `notice`     | Нормальні, але значущі події                  |
| `info()`      | `info`       | Рутинні операційні події                      |
| `debug()`     | `debug`      | Детальна інформація лише для налагодження      |

Або загальний `log($level, $message, $context)`.

## Під’єднання в Lift

`App` не реєструє логер автоматично — зареєструйте свій:

```php
use Lift\Log\Logger;
use Lift\Log\Handler\FileHandler;
use Lift\Log\Formatter\JsonFormatter;
use Psr\Log\LoggerInterface;

$app->singleton(Logger::class, fn() => new Logger([
    new FileHandler(__DIR__ . '/../storage/logs/app.log', 'info', new JsonFormatter()),
]));

// Прив’язати інтерфейс PSR-3 до того самого екземпляра — сторонні бібліотеки це приймають
$app->bind(LoggerInterface::class, fn() => $app->make(Logger::class));
```

Потім будь-де — обробник, контролер, middleware — вкажіть тип і впровадьте:

```php
class UserController
{
    public function __construct(private readonly LoggerInterface $log) {}

    public function login(Request $req): Response
    {
        $this->log->info('Login attempt', ['email' => $req->input('email')]);
        // …
    }
}
```

## Інтерполяція плейсхолдерів

PSR-3 підтримує плейсхолдери `{key}`, що підтягуються з масиву контексту:

```php
$log->info('User {user_id} did {action}', [
    'user_id' => 42,
    'action'  => 'login',
]);
// → "User 42 did login"   (+ повний масив контексту все ще збережено)
```

Заміна покриває рядки, числа й будь-який об’єкт із `__toString()`. Інші значення залишаються в контексті, але не підставляються в повідомлення.

## Масив контексту

Другий аргумент — це асоціативний масив довільної форми. Угоди:

- **`exception`** → передайте `Throwable`. Більшість обробників включають трасування стека.
- **`user_id` / `request_id` / `trace_id`** → для кореляції між сервісами.
- **Цілий `Throwable`** як значення:

```php
try {
    $this->processPayment($order);
} catch (\Throwable $e) {
    $this->log->error('Payment processing failed', [
        'order_id'  => $order->id,
        'amount'    => $order->total,
        'exception' => $e,                                    // форматувальник його рендерить
    ]);
    throw $e;
}
```

## Обробники

**Обробник** вирішує, куди записуються рядки, і фільтрує їх за мінімальним рівнем. Вбудовані:

| Обробник               | Записує в                                          |
|------------------------|----------------------------------------------------|
| `FileHandler`          | Один файл (створює каталог, якщо відсутній)        |
| `RotatingFileHandler`  | Файли зі щоденною ротацією; автоматично видаляє старі |
| `StdoutHandler`        | `php://stdout`                                     |
| `NullHandler`          | Нікуди (корисний у тестах)                         |

Кожен обробник приймає мінімальний рівень + (необов’язковий) форматувальник:

```php
new FileHandler('/var/log/app.log', minLevel: 'warning', formatter: new JsonFormatter());
new RotatingFileHandler('/var/log/app.log', minLevel: 'info', maxFiles: 30);
new StdoutHandler(minLevel: 'debug');                  // форматувальник за замовчуванням = LineFormatter
new NullHandler();
```

### Додавання обробника до наявного логера

`withHandler()` повертає клон із додатковим обробником:

```php
$logger = $logger->withHandler(new FileHandler('/tmp/debug.log', 'debug'));
```

Корисно в тестах, коли ви хочете тимчасово захоплювати рядки логів.

## Форматувальники

**Форматувальник** перетворює запис логу на рядок. Вбудовані:

| Форматувальник  | Вивід                                                           |
|-----------------|-----------------------------------------------------------------|
| `LineFormatter` | `[2026-05-14 15:30:00] info: User 42 logged in {"user_id":42}` |
| `JsonFormatter` | `{"ts":"2026-05-14T15:30:00Z","level":"info","message":"…","context":{…}}` |

Обирайте **`JsonFormatter`** для продакшену — це формат, який будь-який інструмент агрегації логів (Loki, ELK, Datadog, CloudWatch) парсить безкоштовно. Обирайте **`LineFormatter`** для людиночитаного виводу в термінал.

### Власний форматувальник

```php
use Lift\Log\Formatter\FormatterInterface;

final class CompactFormatter implements FormatterInterface
{
    public function format(string $level, string $message, array $context): string
    {
        return sprintf("%s %-8s %s\n", date('H:i:s'), strtoupper($level), $message);
    }
}

new StdoutHandler('debug', new CompactFormatter());
```

## Поширені конфігурації

### Продакшен — JSON у файл + stdout

```php
$app->singleton(Logger::class, fn() => new Logger([
    new FileHandler(   __DIR__ . '/../storage/logs/app.log', 'info', new JsonFormatter()),
    new StdoutHandler('warning', new JsonFormatter()),   // контейнер це підхоплює
]));
```

- Файл: кожен `info`+ іде сюди, для ретроспективного налагодження.
- Stdout: `warning`+, щоб він з’являвся в `journalctl` / `docker logs` без переповнення.
- Обидва JSON, тож відправники логів парсять їх однаково.

### Розробка — кольорові рядки в stdout

```php
$app->singleton(Logger::class, fn() => new Logger([
    new StdoutHandler('debug'),    // LineFormatter, усі рівні
]));
```

### Тести — захоплення всього в пам’яті

`Lift\Log\Handler\NullHandler` проковтує все. Для тестів, що стверджують вміст логів, напишіть невеликий обробник у пам’яті:

```php
final class ArrayHandler implements HandlerInterface
{
    public array $records = [];
    public function handle(string $level, string $message, array $context): void
    {
        $this->records[] = compact('level', 'message', 'context');
    }
}

// У вашому TestCase:
$this->app->instance(LoggerInterface::class, new Logger([$this->logHandler = new ArrayHandler()]));

// Твердження
self::assertSame('error', $this->logHandler->records[0]['level']);
```

### Middleware логування на запит

Логуйте кожен HTTP-запит:

```php
final class LogRequestsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $log) {}

    public function process($req, $next): ResponseInterface
    {
        $t0       = hrtime(true);
        $response = $next->handle($req);
        $ms       = round((hrtime(true) - $t0) / 1e6, 1);

        $this->log->info('{method} {path} → {status} ({ms} ms)', [
            'method' => $req->getMethod(),
            'path'   => $req->getUri()->getPath(),
            'status' => $response->getStatusCode(),
            'ms'     => $ms,
        ]);

        return $response;
    }
}

$app->use(LogRequestsMiddleware::class);
```

### Логування неперехоплених винятків

Уже показано в [Обробці помилок](errors), але для повноти:

```php
$app->onError(function (\Throwable $e, Request $req) use ($app) {
    if (!$e instanceof \Lift\Exception\HttpException) {
        $app->logger()->error($e->getMessage(), [
            'method'    => $req->getMethod(),
            'path'      => $req->getUri()->getPath(),
            'exception' => $e,
        ]);
    }
    // … повернути відповідь
});
```

## Ротація логів

### Вбудована: RotatingFileHandler

`RotatingFileHandler` створює новий файл щодня й опційно видаляє старі.

```php
use Lift\Log\Handler\RotatingFileHandler;
use Lift\Log\Formatter\JsonFormatter;

new RotatingFileHandler(
    path:      storage_path('logs/app.log'),   // базовий шлях
    minLevel:  'info',
    formatter: new JsonFormatter(),
    maxFiles:  30,    // зберігати 30 днів; 0 = зберігати вічно
)
```

Файли іменуються вставкою дати перед розширенням:

```
storage/logs/app.log          ← базовий шлях (сам не створюється)
storage/logs/app-2026-05-15.log   ← сьогодні
storage/logs/app-2026-05-14.log   ← учора
…
```

Обробник ліниво відкриває правильний файл під час першого запису кожного дня — безпечно для довгоживучих воркерів і процесів черг. Коли `maxFiles > 0`, файли понад ліміт автоматично видаляються після кожної ротації.

### Зовнішня ротація (альтернатива)

Використовуйте `logrotate` із `copytruncate`, коли віддаєте перевагу ротації на рівні ОС:

```
/var/log/myapp.log {
    daily
    rotate 14
    missingok
    notifempty
    copytruncate
    compress
}
```

## Надсилання логів сторонньому сервісу

Загорніть SDK стороннього сервісу у власний обробник:

```php
use Lift\Log\Handler\HandlerInterface;

final class SentryHandler implements HandlerInterface
{
    public function __construct(private readonly \Sentry\State\HubInterface $sentry) {}

    public function handle(string $level, string $message, array $context): void
    {
        // надсилати лише error+
        if (!in_array($level, ['error', 'critical', 'alert', 'emergency'], true)) {
            return;
        }
        if (isset($context['exception'])) {
            $this->sentry->captureException($context['exception']);
        } else {
            $this->sentry->captureMessage($message);
        }
    }
}

$app->singleton(Logger::class, fn() => new Logger([
    new FileHandler('/var/log/myapp.log', 'info', new JsonFormatter()),
    new SentryHandler(Sentry\SentrySdk::getCurrentHub()),
]));
```

Фреймворк залишається без залежностей; ви під’єднуєте Sentry / Datadog / Loki / тощо через власні обробники.

## Безпека

- **Ніколи не логуйте паролі, токени, JWT, API-ключі** — навіть на рівні debug. Логи архівуються, розшаровуються, витікають.
- **Маскуйте email / персональні дані** перед передаванням у `context`:
  ```php
  $log->info('Signup', ['email_hash' => hash('sha256', $email)]);
  ```
- **Заголовки `Cookie` і `Authorization`**: редагуйте їх у middleware логування запитів. [Панель налагодження](debug) робить це автоматично.

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| Логи нікуди не йдуть | Обробник не налаштовано | За замовчуванням це `[StdoutHandler]`, якщо передано `[]`; перевірте вашу зв’язку. |
| `Permission denied` на файлі логу | Користувач вебсервера не може писати | `chown www-data:www-data storage/logs/` + каталог `0755`. |
| `{user_id}` буквально у виводі | Ключа не було в `$context` (або значення не приводиться до рядка) | Додайте його в масив контексту. |
| Логер проковтує трасування стека | Передано `$e->getMessage()` замість самого `$e` | Передавайте `'exception' => $e`. |
| Надто багатослівно під навантаженням | `info()` у гарячому циклі | Знизьте до `debug` і покладіться на фільтри рівня; або приберіть виклик. |
| Тести забруднюють реальний файл логу | Прив’язали продакшен-логер у тестах | Замініть на `new Logger([new NullHandler()])` у вашому `TestCase`. |

## Шпаргалка

```php
// Побудувати
$log = new Logger([
    new FileHandler('/var/log/app.log', 'info', new JsonFormatter()),
    new StdoutHandler('warning'),
]);

// Використовувати (PSR-3)
$log->emergency / alert / critical / error / warning / notice / info / debug ($msg, $ctx);
$log->log('error', $msg, $ctx);

// Інтерполяція
$log->info('User {id} did {action}', ['id' => 42, 'action' => 'login']);

// Включити throwable
$log->error('Boom', ['exception' => $e]);

// Впровадити (PSR-3)
public function __construct(private readonly LoggerInterface $log) {}
```

[Консоль (CLI) →](console)
