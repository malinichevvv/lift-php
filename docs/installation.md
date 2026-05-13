---
layout: page
title: Installation
nav_order: 2
---

# Installation

## Requirements

- PHP **8.1** or higher
- Composer

## Install via Composer

```bash
composer require lift-php/lift
```

That's the only dependency you need. Lift pulls in the PSR interface packages automatically (`psr/http-message`, `psr/container`, `psr/http-server-middleware`).

## Verify

```bash
php -r "require 'vendor/autoload.php'; echo (new Lift\App()) ? 'Lift OK' : '';"
```

## Project structure

There is no required directory structure. A minimal app lives in a single file:

```
my-project/
├── composer.json
├── composer.lock
├── vendor/
└── public/
    └── index.php   ← entry point
```

`public/index.php`:

```php
<?php
require __DIR__ . '/../vendor/autoload.php';

use Lift\App;
use Lift\Http\Response;

$app = new App();

$app->get('/', fn() => Response::json(['status' => 'ok']));

$app->run();
```

## Web server configuration

### Apache

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]
</IfModule>
```

### Nginx

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### Built-in PHP server (development)

```bash
php -S localhost:8080 -t public/
```

[Quick Start →](quickstart)
