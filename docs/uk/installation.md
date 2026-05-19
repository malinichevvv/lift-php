---
layout: page
title: Встановлення
nav_order: 2
---

# Встановлення

Ця сторінка проведе вас від «нічого не встановлено» до робочого застосунку Lift, який можна опитати через `curl`.

## 1. Перевірте передумови

Вам потрібні **PHP 8.1 або новіший** і **Composer**.

```bash
php -v        # має бути ≥ 8.1
composer -V   # будь-яка свіжа версія
```

Якщо `php -v` показує 7.x, спершу встановіть PHP 8.1+:

- **Ubuntu/Debian:** `sudo apt install php8.3-cli php8.3-curl php8.3-mbstring`
- **macOS (Homebrew):** `brew install php`
- **Windows:** завантажте з [windows.php.net](https://windows.php.net/download/)

Рекомендовані розширення PHP (жодне не є строго обов’язковим, але вони вам знадобляться):

| Розширення | Потрібне для |
|---|---|
| `ext-curl` | HTTP-клієнта |
| `ext-mbstring` | Роботи з рядками UTF-8, підпису JWT |
| `ext-pdo` + `ext-pdo_mysql` / `ext-pdo_pgsql` / `ext-pdo_sqlite` | Бази даних |
| `ext-redis` | `RedisCache`, `RedisQueue`, `RedisSessionStore` |
| `ext-pcntl` | Коректного завершення воркера черг |
| `ext-opcache` | Продуктивності в продакшені (величезне пришвидшення) |

## 2. Створіть проєкт

```bash
mkdir my-app
cd my-app
composer init --name="me/my-app" --type=project --no-interaction
composer require malinichevvv/lift-php
```

Це єдина залежність. Lift автоматично підтягує невеликі пакети PSR-інтерфейсів, які йому потрібні (`psr/http-message`, `psr/container` тощо).

## 3. Розкладіть файли

Lift не нав’язує жодної структури, але ось мінімальне розкладання, яке водночас готове до продакшену:

```
my-app/
├── composer.json
├── composer.lock
├── vendor/                  (створюється composer)
├── public/
│   └── index.php            ← єдина точка входу, єдиний PHP-файл, який віддає вебсервер
├── src/                     ← ваші класи (контролери, сервіси, …)
├── views/                   ← опційно, якщо використовуєте шаблони
├── storage/                 ← рантайм-файли (логи, кеш, сесії)
└── .env                     ← опційно, конфігурація під оточення
```

Створіть `public/index.php`:

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

## 4. Перевірте, що все працює

```bash
php -S 127.0.0.1:8000 -t public
```

В іншому терміналі:

```bash
curl http://127.0.0.1:8000/
# {"status":"ok","time":"2025-...","php":"8.3.6"}
```

Якщо ви бачите JSON — Lift встановлено і він працює. 🎉

## 5. Налаштуйте свій реальний вебсервер

Вбудований сервер PHP підходить для розробки. Для продакшену вам знадобиться PHP-FPM за **Nginx** або **Apache**.

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

    # Статичні ресурси: віддаємо напряму, з тривалим кешем
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

Переконайтеся, що для цього каталогу у вашому vhost встановлено `AllowOverride All`.

### Caddy (альтернатива без конфігурації)

```caddyfile
example.com {
    root * /var/www/my-app/public
    php_fastcgi unix//run/php/php8.3-fpm.sock
    file_server
    encode gzip
}
```

## 6. Увімкніть OPcache у продакшені

Ця єдина зміна приблизно **подвоює** пропускну здатність запитів. Додайте до `php.ini` (або у drop-in файл у `/etc/php/8.3/fpm/conf.d/`):

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0     ; лише продакшен — скидайте вручну під час деплою
opcache.save_comments=1           ; обов’язково для маршрутизації через атрибути #[Route]
opcache.jit=tracing
opcache.jit_buffer_size=64M
```

Після ввімкнення перезапустіть PHP-FPM (`sudo systemctl restart php8.3-fpm`).

## 7. (Опційно) Файл оточення

Для конфігурації за принципом 12-factor покладіть `.env` у корінь проєкту:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_KEY=base64:GENERATE_ME_LATER
DB_DSN="mysql:host=127.0.0.1;dbname=myapp;charset=utf8mb4"
DB_USER=root
DB_PASS=secret
```

Завантажте його в `public/index.php` **до** створення застосунку:

```php
$app = new App();
$app->loadEnv(__DIR__ . '/../.env');
```

Потім у будь-якому місці коду:

```php
use Lift\Config\Env;

$debug = Env::bool('APP_DEBUG', false);
$dsn   = Env::string('DB_DSN');
```

## 8. (Опційно) Використовуйте CLI `lift`

Бінарник `lift` встановлюється Composer у `vendor/bin/lift`. Він генерує шаблонний код і запускає воркери черг / міграції:

```bash
vendor/bin/lift make:controller UserController
vendor/bin/lift make:request    StoreUserRequest
vendor/bin/lift make:model      User
vendor/bin/lift queue:work
vendor/bin/lift migrate
```

Повний перелік: [Консоль (CLI)](console).

## Часті підводні камені під час встановлення

- ❌ **`Class "Lift\App" not found`** → ви забули `require '.../vendor/autoload.php'` на початку `index.php`.
- ❌ **`SyntaxError: unexpected token "fn"`** → ваш PHP старший за 7.4 (Lift вимагає 8.1+). Виконайте `php -v` для перевірки.
- ❌ **404 для кожного маршруту, окрім `/`** → ваш вебсервер не перенаправляє URL на `index.php`. Перевірте конфігурацію Nginx/Apache вище.
- ❌ **`composer require malinichevvv/lift-php` пише «Could not find package»** → якщо ви пробуєте ще не випущену версію, встановіть зі шляху або VCS:
  ```bash
  composer config repositories.lift path /path/to/lift
  composer require malinichevvv/lift-php:@dev
  ```

## Наступні кроки

Тепер у вас є робочий застосунок. Час змусити його щось робити.

[Швидкий старт →](quickstart)
