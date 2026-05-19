---
layout: page
title: Установка
nav_order: 2
---

# Установка

Эта страница проведёт вас от «ничего не установлено» до работающего приложения Lift, которое можно опросить через `curl`.

## 1. Проверьте предварительные требования

Вам нужны **PHP 8.1 или новее** и **Composer**.

```bash
php -v        # должно быть ≥ 8.1
composer -V   # любая свежая версия
```

Если `php -v` показывает 7.x, сначала установите PHP 8.1+:

- **Ubuntu/Debian:** `sudo apt install php8.3-cli php8.3-curl php8.3-mbstring`
- **macOS (Homebrew):** `brew install php`
- **Windows:** скачайте с [windows.php.net](https://windows.php.net/download/)

Рекомендуемые расширения PHP (ни одно не является строго обязательным, но они вам понадобятся):

| Расширение | Нужно для |
|---|---|
| `ext-curl` | HTTP-клиента |
| `ext-mbstring` | Работы со строками UTF-8, подписи JWT |
| `ext-pdo` + `ext-pdo_mysql` / `ext-pdo_pgsql` / `ext-pdo_sqlite` | Базы данных |
| `ext-redis` | `RedisCache`, `RedisQueue`, `RedisSessionStore` |
| `ext-pcntl` | Корректного завершения воркера очередей |
| `ext-opcache` | Производительности в продакшене (огромное ускорение) |

## 2. Создайте проект

```bash
mkdir my-app
cd my-app
composer init --name="me/my-app" --type=project --no-interaction
composer require malinichevvv/lift-php
```

Это единственная зависимость. Lift автоматически подтягивает небольшие пакеты PSR-интерфейсов, которые ему нужны (`psr/http-message`, `psr/container` и т. д.).

## 3. Разложите файлы

Lift не навязывает какую-либо структуру, но вот минимальная раскладка, которая при этом готова к продакшену:

```
my-app/
├── composer.json
├── composer.lock
├── vendor/                  (создаётся composer)
├── public/
│   └── index.php            ← единственная точка входа, единственный PHP-файл, который отдаёт веб-сервер
├── src/                     ← ваши классы (контроллеры, сервисы, …)
├── views/                   ← опционально, если используете шаблоны
├── storage/                 ← рантайм-файлы (логи, кэш, сессии)
└── .env                     ← опционально, конфигурация под окружение
```

Создайте `public/index.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Lift\App;
use Lift\Http\Response;

$app = new App();

$app->get('/', fn() => Response::json([
    'status'  => 'ok',
    'time'    => date('c'),
    'php'     => PHP_VERSION,
]));

$app->run();
```

## 4. Проверьте, что всё работает

```bash
php -S 127.0.0.1:8000 -t public
```

В другом терминале:

```bash
curl http://127.0.0.1:8000/
# {"status":"ok","time":"2025-...","php":"8.3.6"}
```

Если вы видите JSON — Lift установлен и работает. 🎉

## 5. Настройте свой реальный веб-сервер

Встроенный сервер PHP подходит для разработки. Для продакшена вам понадобится PHP-FPM за **Nginx** или **Apache**.

### Nginx + PHP-FPM

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/my-app/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Статические ресурсы: отдаём напрямую, с долгим кэшем
    location ~* \.(css|js|svg|png|jpg|jpeg|gif|woff2?)$ {
        expires 30d;
        access_log off;
    }
}
```

### Apache

`/var/www/my-app/public/.htaccess`:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]
</IfModule>
```

Убедитесь, что для этого каталога в вашем vhost установлено `AllowOverride All`.

### Caddy (альтернатива без конфигурации)

```caddyfile
example.com {
    root * /var/www/my-app/public
    php_fastcgi unix//run/php/php8.3-fpm.sock
    file_server
    encode gzip
}
```

## 6. Включите OPcache в продакшене

Это единственное изменение примерно **удваивает** пропускную способность запросов. Добавьте в `php.ini` (или в drop-in файл в `/etc/php/8.3/fpm/conf.d/`):

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0     ; только продакшен — сбрасывайте вручную при деплое
opcache.save_comments=1           ; обязательно для маршрутизации через атрибуты #[Route]
opcache.jit=tracing
opcache.jit_buffer_size=64M
```

После включения перезапустите PHP-FPM (`sudo systemctl restart php8.3-fpm`).

## 7. (Опционально) Файл окружения

Для конфигурации по принципу 12-factor положите `.env` в корень проекта:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_KEY=base64:GENERATE_ME_LATER
DB_DSN="mysql:host=127.0.0.1;dbname=myapp;charset=utf8mb4"
DB_USER=root
DB_PASS=secret
```

Загрузите его в `public/index.php` **до** создания приложения:

```php
$app = new App();
$app->loadEnv(__DIR__ . '/../.env');
```

Затем в любом месте кода:

```php
use Lift\Config\Env;

$debug = Env::bool('APP_DEBUG', false);
$dsn   = Env::string('DB_DSN');
```

## 8. (Опционально) Используйте CLI `lift`

Бинарник `lift` устанавливается Composer в `vendor/bin/lift`. Он генерирует шаблонный код и запускает воркеры очередей / миграции:

```bash
vendor/bin/lift make:controller UserController
vendor/bin/lift make:request    StoreUserRequest
vendor/bin/lift make:model      User
vendor/bin/lift queue:work
vendor/bin/lift migrate
```

Полный список: [Консоль (CLI)](console).

## Частые подводные камни при установке

- ❌ **`Class "Lift\App" not found`** → вы забыли `require '.../vendor/autoload.php'` в начале `index.php`.
- ❌ **`SyntaxError: unexpected token "fn"`** → ваш PHP старше 7.4 (Lift требует 8.1+). Выполните `php -v` для проверки.
- ❌ **404 для каждого маршрута, кроме `/`** → ваш веб-сервер не перенаправляет URL на `index.php`. Перепроверьте конфигурацию Nginx/Apache выше.
- ❌ **`composer require malinichevvv/lift-php` пишет «Could not find package»** → если вы пробуете ещё не выпущенную версию, установите из пути или VCS:
  ```bash
  composer config repositories.lift path /path/to/lift
  composer require malinichevvv/lift-php:@dev
  ```

## Следующие шаги

Теперь у вас есть работающее приложение. Пора заставить его что-то делать.

[Быстрый старт →](quickstart)
