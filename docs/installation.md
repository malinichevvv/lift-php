---
layout: page
title: Installation
nav_order: 2
---

# Installation

This page gets you from "nothing installed" to a running Lift app you can `curl`.

## 1. Check prerequisites

You need **PHP 8.1 or newer** and **Composer**.

```bash
php -v        # must be ≥ 8.1
composer -V   # any recent version
```

If `php -v` shows 7.x, install PHP 8.1+ first:

- **Ubuntu/Debian:** `sudo apt install php8.3-cli php8.3-curl php8.3-mbstring`
- **macOS (Homebrew):** `brew install php`
- **Windows:** download from [windows.php.net](https://windows.php.net/download/)

Recommended PHP extensions (none are strictly required, but you'll want them):

| Extension | Needed for |
|---|---|
| `ext-curl` | HTTP client |
| `ext-mbstring` | UTF-8 string handling, JWT signing |
| `ext-pdo` + `ext-pdo_mysql` / `ext-pdo_pgsql` / `ext-pdo_sqlite` | Database |
| `ext-redis` | `RedisCache`, `RedisQueue`, `RedisSessionStore` |
| `ext-pcntl` | Graceful queue worker shutdown |
| `ext-opcache` | Production performance (huge speedup) |

## 2. Create the project

```bash
mkdir my-app
cd my-app
composer init --name="me/my-app" --type=project --no-interaction
composer require lift-php/lift
```

That's the only dependency. Lift pulls in the small PSR interface packages it needs (`psr/http-message`, `psr/container`, etc.) automatically.

## 3. Lay out the files

Lift doesn't enforce any structure, but this is the smallest layout that's also production-ready:

```
my-app/
├── composer.json
├── composer.lock
├── vendor/                  (created by composer)
├── public/
│   └── index.php            ← single entry point, the only PHP file the web server serves
├── src/                     ← your classes (controllers, services, …)
├── views/                   ← optional, if you use templates
├── storage/                 ← runtime files (logs, cache, sessions)
└── .env                     ← optional, environment-specific config
```

Create `public/index.php`:

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

## 4. Verify it works

```bash
php -S 127.0.0.1:8000 -t public
```

In another terminal:

```bash
curl http://127.0.0.1:8000/
# {"status":"ok","time":"2025-...","php":"8.3.6"}
```

If you see JSON — Lift is installed and running. 🎉

## 5. Configure your real web server

The PHP built-in server is fine for development. For production you'll want PHP-FPM behind **Nginx** or **Apache**.

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

    # Static assets: serve directly, with long cache
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

Make sure `AllowOverride All` is set for that directory in your vhost.

### Caddy (zero-config alternative)

```caddyfile
example.com {
    root * /var/www/my-app/public
    php_fastcgi unix//run/php/php8.3-fpm.sock
    file_server
    encode gzip
}
```

## 6. Enable OPcache in production

This single change roughly **doubles** request throughput. Add to `php.ini` (or a drop-in file in `/etc/php/8.3/fpm/conf.d/`):

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0     ; production only — flush manually on deploy
opcache.save_comments=1           ; required for #[Route] attribute routing
opcache.jit=tracing
opcache.jit_buffer_size=64M
```

After enabling, restart PHP-FPM (`sudo systemctl restart php8.3-fpm`).

## 7. (Optional) Environment file

For 12-factor config, drop a `.env` next to your project root:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_KEY=base64:GENERATE_ME_LATER
DB_DSN="mysql:host=127.0.0.1;dbname=myapp;charset=utf8mb4"
DB_USER=root
DB_PASS=secret
```

Load it in `public/index.php` **before** instantiating the app:

```php
$app = new App();
$app->loadEnv(__DIR__ . '/../.env');
```

Then anywhere in your code:

```php
use Lift\Config\Env;

$debug = Env::bool('APP_DEBUG', false);
$dsn   = Env::string('DB_DSN');
```

## 8. (Optional) Use the `lift` CLI

The `lift` binary is installed by Composer to `vendor/bin/lift`. It scaffolds boilerplate and runs queue workers / migrations:

```bash
vendor/bin/lift make:controller UserController
vendor/bin/lift make:request    StoreUserRequest
vendor/bin/lift make:model      User
vendor/bin/lift queue:work
vendor/bin/lift migrate
```

Full list: [Console (CLI)](console).

## Common installation gotchas

- ❌ **`Class "Lift\App" not found`** → you forgot `require '.../vendor/autoload.php'` at the top of `index.php`.
- ❌ **`SyntaxError: unexpected token "fn"`** → your PHP is older than 7.4 (Lift requires 8.1+). Run `php -v` to confirm.
- ❌ **404 for every route except `/`** → your web server isn't rewriting URLs to `index.php`. Re-check the Nginx/Apache config above.
- ❌ **`composer require lift-php/lift` says "Could not find package"** → if you're trying a not-yet-released version, install from path or VCS:
  ```bash
  composer config repositories.lift path /path/to/lift
  composer require lift-php/lift:@dev
  ```

## Next steps

You now have a running app. Time to make it do something.

[Quick Start →](quickstart)
