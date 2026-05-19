# Адаптеры рантаймов

Lift поставляет три необязательных адаптера для долгоживущих рантаймов PHP. Все они держат экземпляр `$app` живым между запросами, так что стоимость загрузки оплачивается только один раз — соединения с БД остаются прогретыми, кэш рефлексии горячий, а синглтоны переиспользуются.

| Рантайм | Класс | Транспорт |
|---|---|---|
| [RoadRunner](#roadrunner) | `Lift\Runtime\RoadRunnerWorker` | Go-процесс, PSR-7 через IPC |
| [Swoole / OpenSwoole](#swoole--openswoole) | `Lift\Runtime\SwooleServer` | Расширение PHP, асинхронный ввод-вывод |
| [FrankenPHP](#frankenphp) | `Lift\Runtime\FrankenPhpWorker` | Встроенный Caddy, заполняет суперглобали |

---

## Персистентное состояние — применимо ко всем рантаймам

Поскольку один и тот же процесс PHP обрабатывает много запросов, объекты-синглтоны, зарегистрированные через `$app->singleton()`, **живут весь срок жизни воркера** — это сделано намеренно:

- Соединения с базой данных сохраняются → поведение пула соединений, без накладных расходов на переподключение.
- Логгеры, кэши, HTTP-клиенты → прогреты и переиспользуются.

**Состояние уровня запроса** (например, аутентифицированный пользователь) никогда не должно храниться в синглтоне. Кладите его в **атрибуты запроса**:

```php
// middleware
$user    = Auth::check($request);
$request = $request->withAttribute('user', $user);

// обработчик
$user = $request->getAttribute('user');
```

---

## RoadRunner

RoadRunner — это сервер PHP-приложений на основе Go. Воркеры — это долгоживущие процессы PHP, которые общаются с родительским Go-процессом через IPC.

### Требования

```bash
composer require spiral/roadrunner-http nyholm/psr7
./vendor/bin/rr get-binary           # скачивает бинарник rr
```

### Настройка

**`worker.php`** (корень проекта):

```php
<?php
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';

(new \Lift\Runtime\RoadRunnerWorker($app))->serve();
```

**`.rr.yaml`**:

```yaml
version: "3"

server:
  command: "php worker.php"

http:
  address: "0.0.0.0:8080"
  pool:
    num_workers: 4
    max_jobs: 1000          # перезапускать воркер после N запросов (защита от утечек памяти)
```

**Запуск:**

```bash
./rr serve
```

### Фабрика PSR-17

`RoadRunnerWorker::serve()` автоматически определяет фабрику PSR-17 из установленных пакетов (Nyholm → Guzzle → Laminas в таком порядке). Передайте свою, чтобы переопределить:

```php
(new RoadRunnerWorker($app))->serve(new \Nyholm\Psr7\Factory\Psr17Factory());
```

### Как это работает

Каждая итерация цикла:

1. `PSR7Worker::waitRequest()` блокируется, пока RoadRunner не доставит следующий HTTP-запрос как PSR-7 `ServerRequestInterface`.
2. `Request::fromPsr7()` преобразует его в Lift `Request`.
3. `$app->handle($request)` выполняет конвейер middleware + маршрутизатора.
4. `PSR7Worker::respond()` отправляет Lift `Response` (который уже реализует `ResponseInterface`) обратно в RoadRunner.
5. RoadRunner проксирует его клиенту.

---

## Swoole / OpenSwoole

Swoole — это расширение PHP, добавляющее асинхронный, событийно-управляемый HTTP-сервер прямо в PHP. Внешний бинарник не требуется.

### Требования

```bash
pecl install swoole
# или
pecl install openswoole
```

Включите в `php.ini`:

```ini
extension=swoole
```

### Настройка

**`server.php`** (корень проекта):

```php
<?php
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';

(new \Lift\Runtime\SwooleServer($app))->start();
```

**Запуск:**

```bash
php server.php
```

### Конфигурация

Передайте массив настроек Swoole вторым аргументом:

```php
new \Lift\Runtime\SwooleServer($app, [
    'host'            => '0.0.0.0',
    'port'            => 9501,
    'worker_num'      => swoole_cpu_num() * 2,
    'max_request'     => 1000,       // перезапускать воркер после N запросов
    'daemonize'       => false,
    'log_file'        => '/var/log/swoole.log',
]);
```

Полный список настроек: [документация Swoole](https://openswoole.com/docs/modules/swoole-server/configuration).

### Корутины

Если вы включаете корутины (например, `\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL)`), каждый обработчик запроса выполняется в собственной корутине. Стандартный `PDO` и блокирующий ввод-вывод **не** являются корутинно-осведомлёнными. Варианты:

- Используйте `Swoole\Database\PDOPool` для корутинно-безопасного доступа к базе данных.
- Или держите корутины отключёнными (по умолчанию) и полагайтесь на несколько воркеров для конкурентности.

### Как это работает

Колбэк `on('request', ...)` срабатывает синхронно для каждого запроса:

1. `SwooleServer` преобразует `\Swoole\Http\Request` → Lift `Request` (метод, URI, заголовки, cookie, тело).
2. `$app->handle($request)` выполняет конвейер.
3. Статус, заголовки и тело записываются обратно в `\Swoole\Http\Response`.

---

## FrankenPHP

FrankenPHP — это сервер PHP-приложений, встроенный в Caddy. В режиме воркера он заполняет суперглобали (`$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`) заново для каждого запроса — ровно как PHP-FPM. Это значит, что `Request::fromGlobals()` работает без изменений.

### Требования

Скачайте бинарник FrankenPHP (он включает PHP + Caddy — отдельная установка не нужна):

```bash
curl -L https://github.com/dunglas/frankenphp/releases/latest/download/frankenphp-linux-x86_64 \
     -o frankenphp && chmod +x frankenphp
```

### Настройка

**`worker.php`** (корень проекта):

```php
<?php
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';

(new \Lift\Runtime\FrankenPhpWorker($app))->serve();
```

**`Caddyfile`**:

```caddyfile
{
    frankenphp
    admin off
    auto_https off
}

:8080 {
    root * public

    # Маршрутизировать каждый запрос через worker.php.
    # В режиме воркера Caddy использует этот путь для идентификации пула воркеров;
    # уже запущенный воркер обрабатывает фактическую логику запроса.
    rewrite * /worker.php

    php_server {
        worker worker.php 4     # количество воркеров; опустите, чтобы использовать число CPU
    }
}
```

**Запуск:**

```bash
./frankenphp run --config Caddyfile
```

### Как это работает

`FrankenPhpWorker::serve()` циклится на `frankenphp_handle_request()`:

1. FrankenPHP заполняет суперглобали и вызывает колбэк.
2. `Request::fromGlobals()` строит свежий Lift `Request`.
3. `$app->handle($request)` выполняет конвейер.
4. Ответ отправляется через `http_response_code()`, `header()` и `echo`.
5. FrankenPHP завершает HTTP-цикл, и цикл продолжается.

### Миграция с php-fpm

Если ваш существующий `public/index.php` вызывает `$app->run()`, оберните это в режим воркера FrankenPHP:

```php
// worker.php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';

(new \Lift\Runtime\FrankenPhpWorker($app))->serve();
```

Ваш `public/index.php` может оставаться неизменным для традиционных развёртываний FPM. Для режима воркера FrankenPHP нужна только точка входа `worker.php`.
