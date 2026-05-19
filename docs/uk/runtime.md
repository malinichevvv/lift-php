# Адаптери рантаймів

Lift постачає три необов’язкові адаптери для довгоживучих рантаймів PHP. Усі вони тримають екземпляр `$app` живим між запитами, тож вартість завантаження оплачується лише один раз — з’єднання з БД залишаються прогрітими, кеш рефлексії гарячий, а синглтони повторно використовуються.

| Рантайм | Клас | Транспорт |
|---|---|---|
| [RoadRunner](#roadrunner) | `Lift\Runtime\RoadRunnerWorker` | Go-процес, PSR-7 через IPC |
| [Swoole / OpenSwoole](#swoole--openswoole) | `Lift\Runtime\SwooleServer` | Розширення PHP, асинхронний ввід-вивід |
| [FrankenPHP](#frankenphp) | `Lift\Runtime\FrankenPhpWorker` | Вбудований Caddy, заповнює суперглобалі |

---

## Персистентний стан — застосовно до всіх рантаймів

Оскільки той самий процес PHP обробляє багато запитів, об’єкти-синглтони, зареєстровані через `$app->singleton()`, **живуть увесь термін життя воркера** — це зроблено навмисно:

- З’єднання з базою даних зберігаються → поведінка пулу з’єднань, без накладних витрат на перепідключення.
- Логери, кеші, HTTP-клієнти → прогріті й повторно використовуються.

**Стан рівня запиту** (наприклад, автентифікований користувач) ніколи не має зберігатися в синглтоні. Кладіть його в **атрибути запиту**:

```php
// middleware
$user    = Auth::check($request);
$request = $request->withAttribute('user', $user);

// обробник
$user = $request->getAttribute('user');
```

---

## RoadRunner

RoadRunner — це сервер PHP-застосунків на основі Go. Воркери — це довгоживучі процеси PHP, які спілкуються з батьківським Go-процесом через IPC.

### Вимоги

```bash
composer require spiral/roadrunner-http nyholm/psr7
./vendor/bin/rr get-binary           # завантажує бінарник rr
```

### Налаштування

**`worker.php`** (корінь проєкту):

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
    max_jobs: 1000          # перезапускати воркер після N запитів (захист від витоків пам’яті)
```

**Запуск:**

```bash
./rr serve
```

### Фабрика PSR-17

`RoadRunnerWorker::serve()` автоматично визначає фабрику PSR-17 із встановлених пакетів (Nyholm → Guzzle → Laminas у такому порядку). Передайте свою, щоб перевизначити:

```php
(new RoadRunnerWorker($app))->serve(new \Nyholm\Psr7\Factory\Psr17Factory());
```

### Як це працює

Кожна ітерація циклу:

1. `PSR7Worker::waitRequest()` блокується, доки RoadRunner не доставить наступний HTTP-запит як PSR-7 `ServerRequestInterface`.
2. `Request::fromPsr7()` перетворює його на Lift `Request`.
3. `$app->handle($request)` виконує конвеєр middleware + маршрутизатора.
4. `PSR7Worker::respond()` надсилає Lift `Response` (який уже реалізує `ResponseInterface`) назад до RoadRunner.
5. RoadRunner проксує його клієнту.

---

## Swoole / OpenSwoole

Swoole — це розширення PHP, що додає асинхронний, подієво-керований HTTP-сервер прямо в PHP. Зовнішній бінарник не потрібен.

### Вимоги

```bash
pecl install swoole
# або
pecl install openswoole
```

Увімкніть у `php.ini`:

```ini
extension=swoole
```

### Налаштування

**`server.php`** (корінь проєкту):

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

### Конфігурація

Передайте масив налаштувань Swoole другим аргументом:

```php
new \Lift\Runtime\SwooleServer($app, [
    'host'            => '0.0.0.0',
    'port'            => 9501,
    'worker_num'      => swoole_cpu_num() * 2,
    'max_request'     => 1000,       // перезапускати воркер після N запитів
    'daemonize'       => false,
    'log_file'        => '/var/log/swoole.log',
]);
```

Повний перелік налаштувань: [документація Swoole](https://openswoole.com/docs/modules/swoole-server/configuration).

### Корутини

Якщо ви вмикаєте корутини (наприклад, `\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL)`), кожен обробник запиту виконується у власній корутині. Стандартний `PDO` і блокувальний ввід-вивід **не** є корутинно-обізнаними. Варіанти:

- Використовуйте `Swoole\Database\PDOPool` для корутинно-безпечного доступу до бази даних.
- Або тримайте корутини вимкненими (за замовчуванням) і покладайтеся на кілька воркерів для конкурентності.

### Як це працює

Колбек `on('request', ...)` спрацьовує синхронно для кожного запиту:

1. `SwooleServer` перетворює `\Swoole\Http\Request` → Lift `Request` (метод, URI, заголовки, cookie, тіло).
2. `$app->handle($request)` виконує конвеєр.
3. Статус, заголовки й тіло записуються назад у `\Swoole\Http\Response`.

---

## FrankenPHP

FrankenPHP — це сервер PHP-застосунків, вбудований у Caddy. У режимі воркера він заповнює суперглобалі (`$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`) заново для кожного запиту — рівно як PHP-FPM. Це означає, що `Request::fromGlobals()` працює без змін.

### Вимоги

Завантажте бінарник FrankenPHP (він включає PHP + Caddy — окреме встановлення не потрібне):

```bash
curl -L https://github.com/dunglas/frankenphp/releases/latest/download/frankenphp-linux-x86_64 \
     -o frankenphp && chmod +x frankenphp
```

### Налаштування

**`worker.php`** (корінь проєкту):

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

    # Маршрутизувати кожен запит через worker.php.
    # У режимі воркера Caddy використовує цей шлях для ідентифікації пулу воркерів;
    # уже запущений воркер обробляє фактичну логіку запиту.
    rewrite * /worker.php

    php_server {
        worker worker.php 4     # кількість воркерів; опустіть, щоб використати число CPU
    }
}
```

**Запуск:**

```bash
./frankenphp run --config Caddyfile
```

### Як це працює

`FrankenPhpWorker::serve()` циклиться на `frankenphp_handle_request()`:

1. FrankenPHP заповнює суперглобалі й викликає колбек.
2. `Request::fromGlobals()` будує свіжий Lift `Request`.
3. `$app->handle($request)` виконує конвеєр.
4. Відповідь надсилається через `http_response_code()`, `header()` і `echo`.
5. FrankenPHP завершує HTTP-цикл, і цикл продовжується.

### Міграція з php-fpm

Якщо ваш наявний `public/index.php` викликає `$app->run()`, загорніть це в режим воркера FrankenPHP:

```php
// worker.php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';

(new \Lift\Runtime\FrankenPhpWorker($app))->serve();
```

Ваш `public/index.php` може залишатися незмінним для традиційних розгортань FPM. Для режиму воркера FrankenPHP потрібна лише точка входу `worker.php`.
