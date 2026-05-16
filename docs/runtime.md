# Runtime Adapters

Lift ships three optional adapters for long-running PHP runtimes. All of them keep the `$app` instance alive between requests so bootstrap cost is paid only once — DB connections stay warm, the reflection cache is hot, and singletons are reused.

| Runtime | Class | Transport |
|---|---|---|
| [RoadRunner](#roadrunner) | `Lift\Runtime\RoadRunnerWorker` | Go process, PSR-7 over IPC |
| [Swoole / OpenSwoole](#swoole--openswoole) | `Lift\Runtime\SwooleServer` | PHP extension, async I/O |
| [FrankenPHP](#frankenphp) | `Lift\Runtime\FrankenPhpWorker` | Built-in Caddy, fills superglobals |

---

## Persistent state — applies to all runtimes

Because the same PHP process handles many requests, singleton objects registered with `$app->singleton()` **live for the entire worker lifetime** — this is intentional:

- Database connections persist → connection pool behaviour, no reconnect overhead.
- Loggers, caches, HTTP clients → warm and reused.

**Request-scoped state** (e.g. the authenticated user) must never be stored in a singleton. Put it in **request attributes** instead:

```php
// middleware
$user    = Auth::check($request);
$request = $request->withAttribute('user', $user);

// handler
$user = $request->getAttribute('user');
```

---

## RoadRunner

RoadRunner is a Go-based PHP application server. Workers are long-running PHP processes that communicate with a Go parent via IPC.

### Requirements

```bash
composer require spiral/roadrunner-http nyholm/psr7
./vendor/bin/rr get-binary           # downloads the rr binary
```

### Setup

**`worker.php`** (project root):

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
    max_jobs: 1000          # restart worker after N requests (memory leak protection)
```

**Start:**

```bash
./rr serve
```

### PSR-17 factory

`RoadRunnerWorker::serve()` auto-detects a PSR-17 factory from your installed packages (Nyholm → Guzzle → Laminas in order). Pass your own to override:

```php
(new RoadRunnerWorker($app))->serve(new \Nyholm\Psr7\Factory\Psr17Factory());
```

### How it works

Each loop iteration:

1. `PSR7Worker::waitRequest()` blocks until RoadRunner delivers the next HTTP request as a PSR-7 `ServerRequestInterface`.
2. `Request::fromPsr7()` converts it to a Lift `Request`.
3. `$app->handle($request)` runs the middleware + router pipeline.
4. `PSR7Worker::respond()` sends the Lift `Response` (which already implements `ResponseInterface`) back to RoadRunner.
5. RoadRunner proxies it to the client.

---

## Swoole / OpenSwoole

Swoole is a PHP extension that adds an async, event-driven HTTP server directly into PHP. No external binary required.

### Requirements

```bash
pecl install swoole
# or
pecl install openswoole
```

Enable in `php.ini`:

```ini
extension=swoole
```

### Setup

**`server.php`** (project root):

```php
<?php
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';

(new \Lift\Runtime\SwooleServer($app))->start();
```

**Start:**

```bash
php server.php
```

### Configuration

Pass a Swoole settings array as the second argument:

```php
new \Lift\Runtime\SwooleServer($app, [
    'host'            => '0.0.0.0',
    'port'            => 9501,
    'worker_num'      => swoole_cpu_num() * 2,
    'max_request'     => 1000,       // restart worker after N requests
    'daemonize'       => false,
    'log_file'        => '/var/log/swoole.log',
]);
```

Full list of settings: [Swoole documentation](https://openswoole.com/docs/modules/swoole-server/configuration).

### Coroutines

If you enable coroutines (e.g. `\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL)`), each request handler runs in its own coroutine. Standard `PDO` and blocking I/O are **not** coroutine-aware. Options:

- Use `Swoole\Database\PDOPool` for coroutine-safe database access.
- Or keep coroutines disabled (the default) and rely on multiple workers for concurrency.

### How it works

The `on('request', ...)` callback fires synchronously for each request:

1. `SwooleServer` converts `\Swoole\Http\Request` → Lift `Request` (method, URI, headers, cookies, body).
2. `$app->handle($request)` runs the pipeline.
3. Status, headers, and body are written back to `\Swoole\Http\Response`.

---

## FrankenPHP

FrankenPHP is a PHP app server built into Caddy. In worker mode it fills superglobals (`$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`) fresh for every request — exactly like PHP-FPM. This means `Request::fromGlobals()` works unchanged.

### Requirements

Download the FrankenPHP binary (bundles PHP + Caddy — no separate install needed):

```bash
curl -L https://github.com/dunglas/frankenphp/releases/latest/download/frankenphp-linux-x86_64 \
     -o frankenphp && chmod +x frankenphp
```

### Setup

**`worker.php`** (project root):

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

    # Route every request through worker.php.
    # In worker mode Caddy uses this path to identify the worker pool;
    # the already-running worker handles the actual request logic.
    rewrite * /worker.php

    php_server {
        worker worker.php 4     # worker count; omit to use CPU count
    }
}
```

**Start:**

```bash
./frankenphp run --config Caddyfile
```

### How it works

`FrankenPhpWorker::serve()` loops on `frankenphp_handle_request()`:

1. FrankenPHP fills superglobals and invokes the callback.
2. `Request::fromGlobals()` builds a fresh Lift `Request`.
3. `$app->handle($request)` runs the pipeline.
4. The response is emitted with `http_response_code()`, `header()`, and `echo`.
5. FrankenPHP completes the HTTP cycle and the loop continues.

### Migration from php-fpm

If your existing `public/index.php` calls `$app->run()`, wrap it in FrankenPHP worker mode:

```php
// worker.php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';

(new \Lift\Runtime\FrankenPhpWorker($app))->serve();
```

Your `public/index.php` can remain unchanged for traditional FPM deployments. Only the `worker.php` entry point is needed for FrankenPHP worker mode.
