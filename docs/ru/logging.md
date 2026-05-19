---
layout: page
title: Логирование
nav_order: 29
---

# Логирование

`Lift\Log\Logger` — это логгер по **PSR-3** с подключаемыми обработчиками и форматтерами. Он поддерживает восемь стандартных уровней логирования, интерполяцию плейсхолдеров и стеки независимых обработчиков (например, писать JSON в файл *и* цветные строки в stdout одновременно).

> Ментальная модель: **логгер** получает сообщения. **Обработчики** решают, куда они идут (файл, stdout, syslog, /dev/null). **Форматтеры** решают, как они выглядят (JSON, обычная строка и т. д.). Один логгер, много обработчиков, у каждого свой форматтер и минимальный уровень.

## Когда и сколько логировать

- **Ошибки и предупреждения**: всегда. Иначе вы никогда не узнаете, что ваше приложение сломано.
- **Важные бизнес-события**: да, через `info()`. («Заказ №1234 размещён», «Пользователь зарегистрировался».)
- **Детали для отладки**: `debug()` — включайте только в разработке / для выбранных запросов.
- **Персональные данные**: никогда. Маскируйте email, редактируйте токены. Логи — место №1 утечки секретов.

## Пример за 30 секунд

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

Один и тот же вызов `info(...)` проходит через **оба** обработчика: файл получает полную JSON-строку, stdout не видит ничего (фильтр уровня), а вызов `warning()` попал бы в оба.

## Уровни PSR-3

Стандартные степени серьёзности, от самой серьёзной к наименее:

| Метод         | Уровень      | Использовать для                              |
|---------------|--------------|-----------------------------------------------|
| `emergency()` | `emergency`  | Система непригодна к использованию            |
| `alert()`     | `alert`      | Действие должно быть предпринято немедленно   |
| `critical()`  | `critical`   | Критические условия (компонент упал)          |
| `error()`     | `error`      | Ошибки, требующие внимания, но не останавливающие приложение |
| `warning()`   | `warning`    | Что-то подозрительное, может стать ошибкой    |
| `notice()`    | `notice`     | Нормальные, но значимые события               |
| `info()`      | `info`       | Рутинные операционные события                 |
| `debug()`     | `debug`      | Подробная информация только для отладки       |

Или общий `log($level, $message, $context)`.

## Подключение в Lift

`App` не регистрирует логгер автоматически — зарегистрируйте свой:

```php
use Lift\Log\Logger;
use Lift\Log\Handler\FileHandler;
use Lift\Log\Formatter\JsonFormatter;
use Psr\Log\LoggerInterface;

$app->singleton(Logger::class, fn() => new Logger([
    new FileHandler(__DIR__ . '/../storage/logs/app.log', 'info', new JsonFormatter()),
]));

// Привязать интерфейс PSR-3 к тому же экземпляру — сторонние библиотеки это принимают
$app->bind(LoggerInterface::class, fn() => $app->make(Logger::class));
```

Затем где угодно — обработчик, контроллер, middleware — укажите тип и внедрите:

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

## Интерполяция плейсхолдеров

PSR-3 поддерживает плейсхолдеры `{key}`, которые подтягиваются из массива контекста:

```php
$log->info('User {user_id} did {action}', [
    'user_id' => 42,
    'action'  => 'login',
]);
// → "User 42 did login"   (+ полный массив контекста всё ещё сохранён)
```

Замена покрывает строки, числа и любой объект с `__toString()`. Другие значения остаются в контексте, но не подставляются в сообщение.

## Массив контекста

Второй аргумент — это ассоциативный массив произвольной формы. Соглашения:

- **`exception`** → передайте `Throwable`. Большинство обработчиков включают трассировку стека.
- **`user_id` / `request_id` / `trace_id`** → для корреляции между сервисами.
- **Целый `Throwable`** как значение:

```php
try {
    $this->processPayment($order);
} catch (\Throwable $e) {
    $this->log->error('Payment processing failed', [
        'order_id'  => $order->id,
        'amount'    => $order->total,
        'exception' => $e,                                    // форматтер его рендерит
    ]);
    throw $e;
}
```

## Обработчики

**Обработчик** решает, куда записываются строки, и фильтрует их по минимальному уровню. Встроенные:

| Обработчик             | Записывает в                                       |
|------------------------|----------------------------------------------------|
| `FileHandler`          | Один файл (создаёт каталог, если отсутствует)      |
| `RotatingFileHandler`  | Файлы с ежедневной ротацией; автоматически удаляет старые |
| `StdoutHandler`        | `php://stdout`                                     |
| `NullHandler`          | Никуда (полезен в тестах)                          |

Каждый обработчик принимает минимальный уровень + (необязательный) форматтер:

```php
new FileHandler('/var/log/app.log', minLevel: 'warning', formatter: new JsonFormatter());
new RotatingFileHandler('/var/log/app.log', minLevel: 'info', maxFiles: 30);
new StdoutHandler(minLevel: 'debug');                  // форматтер по умолчанию = LineFormatter
new NullHandler();
```

### Добавление обработчика к существующему логгеру

`withHandler()` возвращает клон с дополнительным обработчиком:

```php
$logger = $logger->withHandler(new FileHandler('/tmp/debug.log', 'debug'));
```

Полезно в тестах, когда вы хотите временно захватывать строки логов.

## Форматтеры

**Форматтер** превращает запись лога в строку. Встроенные:

| Форматтер       | Вывод                                                           |
|-----------------|-----------------------------------------------------------------|
| `LineFormatter` | `[2026-05-14 15:30:00] info: User 42 logged in {"user_id":42}` |
| `JsonFormatter` | `{"ts":"2026-05-14T15:30:00Z","level":"info","message":"…","context":{…}}` |

Выбирайте **`JsonFormatter`** для продакшена — это формат, который любой инструмент агрегации логов (Loki, ELK, Datadog, CloudWatch) парсит бесплатно. Выбирайте **`LineFormatter`** для человекочитаемого вывода в терминал.

### Собственный форматтер

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

## Распространённые конфигурации

### Продакшен — JSON в файл + stdout

```php
$app->singleton(Logger::class, fn() => new Logger([
    new FileHandler(   __DIR__ . '/../storage/logs/app.log', 'info', new JsonFormatter()),
    new StdoutHandler('warning', new JsonFormatter()),   // контейнер это подхватывает
]));
```

- Файл: каждый `info`+ идёт сюда, для ретроспективной отладки.
- Stdout: `warning`+, чтобы он появлялся в `journalctl` / `docker logs` без переполнения.
- Оба JSON, так что отправщики логов парсят их одинаково.

### Разработка — цветные строки в stdout

```php
$app->singleton(Logger::class, fn() => new Logger([
    new StdoutHandler('debug'),    // LineFormatter, все уровни
]));
```

### Тесты — захват всего в памяти

`Lift\Log\Handler\NullHandler` проглатывает всё. Для тестов, утверждающих содержимое логов, напишите небольшой обработчик в памяти:

```php
final class ArrayHandler implements HandlerInterface
{
    public array $records = [];
    public function handle(string $level, string $message, array $context): void
    {
        $this->records[] = compact('level', 'message', 'context');
    }
}

// В вашем TestCase:
$this->app->instance(LoggerInterface::class, new Logger([$this->logHandler = new ArrayHandler()]));

// Утверждение
self::assertSame('error', $this->logHandler->records[0]['level']);
```

### Middleware логирования на запрос

Логируйте каждый HTTP-запрос:

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

### Логирование неперехваченных исключений

Уже показано в [Обработке ошибок](errors), но для полноты:

```php
$app->onError(function (\Throwable $e, Request $req) use ($app) {
    if (!$e instanceof \Lift\Exception\HttpException) {
        $app->logger()->error($e->getMessage(), [
            'method'    => $req->getMethod(),
            'path'      => $req->getUri()->getPath(),
            'exception' => $e,
        ]);
    }
    // … вернуть ответ
});
```

## Ротация логов

### Встроенная: RotatingFileHandler

`RotatingFileHandler` создаёт новый файл каждый день и опционально удаляет старые.

```php
use Lift\Log\Handler\RotatingFileHandler;
use Lift\Log\Formatter\JsonFormatter;

new RotatingFileHandler(
    path:      storage_path('logs/app.log'),   // базовый путь
    minLevel:  'info',
    formatter: new JsonFormatter(),
    maxFiles:  30,    // хранить 30 дней; 0 = хранить вечно
)
```

Файлы именуются вставкой даты перед расширением:

```
storage/logs/app.log          ← базовый путь (сам не создаётся)
storage/logs/app-2026-05-15.log   ← сегодня
storage/logs/app-2026-05-14.log   ← вчера
…
```

Обработчик лениво открывает правильный файл при первой записи каждого дня — безопасно для долгоживущих воркеров и процессов очередей. Когда `maxFiles > 0`, файлы сверх лимита автоматически удаляются после каждой ротации.

### Внешняя ротация (альтернатива)

Используйте `logrotate` с `copytruncate`, когда предпочитаете ротацию на уровне ОС:

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

## Отправка логов стороннему сервису

Оберните SDK стороннего сервиса в собственный обработчик:

```php
use Lift\Log\Handler\HandlerInterface;

final class SentryHandler implements HandlerInterface
{
    public function __construct(private readonly \Sentry\State\HubInterface $sentry) {}

    public function handle(string $level, string $message, array $context): void
    {
        // отправлять только error+
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

Фреймворк остаётся без зависимостей; вы подключаете Sentry / Datadog / Loki / и т. д. через собственные обработчики.

## Безопасность

- **Никогда не логируйте пароли, токены, JWT, API-ключи** — даже на уровне debug. Логи архивируются, расшариваются, утекают.
- **Маскируйте email / персональные данные** перед передачей в `context`:
  ```php
  $log->info('Signup', ['email_hash' => hash('sha256', $email)]);
  ```
- **Заголовки `Cookie` и `Authorization`**: редактируйте их в middleware логирования запросов. [Отладочная панель](debug) делает это автоматически.

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| Логи никуда не идут | Обработчик не настроен | По умолчанию это `[StdoutHandler]`, если передан `[]`; проверьте вашу связку. |
| `Permission denied` на файле лога | Пользователь веб-сервера не может писать | `chown www-data:www-data storage/logs/` + каталог `0755`. |
| `{user_id}` буквально в выводе | Ключа не было в `$context` (или значение не приводится к строке) | Добавьте его в массив контекста. |
| Логгер проглатывает трассировку стека | Передан `$e->getMessage()` вместо самого `$e` | Передавайте `'exception' => $e`. |
| Слишком многословно под нагрузкой | `info()` в горячем цикле | Снизьте до `debug` и положитесь на фильтры уровня; или уберите вызов. |
| Тесты загрязняют реальный файл лога | Привязали продакшен-логгер в тестах | Замените на `new Logger([new NullHandler()])` в вашем `TestCase`. |

## Шпаргалка

```php
// Построить
$log = new Logger([
    new FileHandler('/var/log/app.log', 'info', new JsonFormatter()),
    new StdoutHandler('warning'),
]);

// Использовать (PSR-3)
$log->emergency / alert / critical / error / warning / notice / info / debug ($msg, $ctx);
$log->log('error', $msg, $ctx);

// Интерполяция
$log->info('User {id} did {action}', ['id' => 42, 'action' => 'login']);

// Включить throwable
$log->error('Boom', ['exception' => $e]);

// Внедрить (PSR-3)
public function __construct(private readonly LoggerInterface $log) {}
```

[Консоль (CLI) →](console)
