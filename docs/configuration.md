# Configuration Reference

Lift is configured through environment variables (loaded from `.env`) and/or an
in-process array passed to `$app->config([...])`.

**Loading order (highest precedence first):**
1. Real environment variables already set in the shell / server
2. Values loaded from `.env` via `$app->loadEnv(path)`
3. Values set in PHP config arrays via `$app->config([...])`

---

## Bootstrap

```php
$app = new App();
$app->loadEnv(__DIR__ . '/../.env');   // loads .env into $_ENV / putenv()
$app->config([                         // optional array overrides
    'app.name' => 'My App',
    'app.env'  => 'production',
]);
```

Read values anywhere via `Env::string()` / `Env::int()` / `Env::bool()`:

```php
use Lift\Config\Env;

$dsn  = Env::string('DB_URL');
$port = Env::int('PORT', 8080);   // default 8080
$flag = Env::bool('FEATURE_X');   // null when absent
```

---

## Application

| Variable       | Type     | Default       | Description |
|----------------|----------|---------------|-------------|
| `APP_NAME`     | string   | `Lift App`    | Human-readable application name. |
| `APP_ENV`      | string   | `production`  | Runtime environment: `local`, `testing`, `staging`, `production`. |
| `APP_DEBUG`    | bool     | `false`       | Enable debug mode (shows full stack traces, injects toolbar). |
| `APP_KEY`      | string   | —             | 32-byte encryption key used by `Encrypter`. **Required in production.** |
| `APP_URL`      | string   | `http://localhost` | Canonical application URL (used for link generation). |

```dotenv
APP_NAME="My Blog"
APP_ENV=local
APP_DEBUG=true
APP_KEY=base64:...32-byte-random-key...
APP_URL=https://myblog.example.com
```

```php
$app->debug(['enabled' => Env::bool('APP_DEBUG', false)]);
$app->loadEnv(__DIR__ . '/../.env');
echo $app->environment(); // → 'local'
```

---

## Database

| Variable        | Type   | Default    | Description |
|-----------------|--------|------------|-------------|
| `DB_CONNECTION` | string | `sqlite`   | Driver: `mysql`, `pgsql`, `sqlite`. |
| `DB_HOST`       | string | `127.0.0.1`| Database server host. |
| `DB_PORT`       | int    | `3306`     | Database server port (default changes per driver: PgSQL = `5432`). |
| `DB_DATABASE`   | string | —          | Database name, or relative path for SQLite (e.g. `storage/db.sqlite`). |
| `DB_USERNAME`   | string | —          | Authentication username. |
| `DB_PASSWORD`   | string | —          | Authentication password. |
| `DB_CHARSET`    | string | `utf8mb4`  | Connection charset (MySQL/MariaDB only). |

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=myapp
DB_USERNAME=root
DB_PASSWORD=secret
```

```php
$dsn = match (Env::string('DB_CONNECTION', 'sqlite')) {
    'mysql'  => sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
                    Env::string('DB_HOST', '127.0.0.1'),
                    Env::string('DB_PORT', '3306'),
                    Env::string('DB_DATABASE'),
                    Env::string('DB_CHARSET', 'utf8mb4')),
    'pgsql'  => sprintf('pgsql:host=%s;port=%s;dbname=%s',
                    Env::string('DB_HOST', '127.0.0.1'),
                    Env::string('DB_PORT', '5432'),
                    Env::string('DB_DATABASE')),
    default  => 'sqlite:' . Env::string('DB_DATABASE', 'storage/database.sqlite'),
};
$db = new Connection($dsn, Env::string('DB_USERNAME'), Env::string('DB_PASSWORD'));
$app->instance(Connection::class, $db);
```

---

## Session

| Variable          | Type   | Default         | Description |
|-------------------|--------|-----------------|-------------|
| `SESSION_DRIVER`  | string | `file`          | Store driver: `file`, `array`, `database`, `redis`, `memcached`. |
| `SESSION_PATH`    | string | `storage/sessions` | Directory for file-based sessions. |
| `SESSION_LIFETIME`| int    | `7200`          | Session TTL in seconds. |
| `SESSION_COOKIE`  | string | `lift_session`  | Cookie name sent to the browser. |
| `SESSION_TABLE`   | string | `sessions`      | Table name for the database store. |

```dotenv
SESSION_DRIVER=file
SESSION_PATH=storage/sessions
SESSION_LIFETIME=7200
SESSION_COOKIE=myapp_session
```

```php
$store = match (Env::string('SESSION_DRIVER', 'file')) {
    'database'  => new DatabaseSessionStore($db, Env::string('SESSION_TABLE', 'sessions')),
    'redis'     => new RedisSessionStore($redis),
    'memcached' => new MemcachedSessionStore($memcached),
    'array'     => new ArraySessionStore(),
    default     => new FileSessionStore(Env::string('SESSION_PATH', 'storage/sessions')),
};
$session = new Session(
    $store,
    lifetime:   Env::int('SESSION_LIFETIME', 7200),
    cookieName: Env::string('SESSION_COOKIE', 'lift_session'),
);
$app->use(new SessionMiddleware($session));
```

---

## Cache

| Variable       | Type   | Default | Description |
|----------------|--------|---------|-------------|
| `CACHE_DRIVER` | string | `array` | Driver: `array`, `redis`. |
| `CACHE_PREFIX` | string | `lift_` | Prefix prepended to every cache key. |
| `CACHE_TTL`    | int    | `3600`  | Default TTL in seconds when none is specified. |

```dotenv
CACHE_DRIVER=redis
CACHE_PREFIX=myapp_
```

```php
$cache = match (Env::string('CACHE_DRIVER', 'array')) {
    'redis' => new RedisCache($redis, Env::string('CACHE_PREFIX', 'lift_')),
    default => new ArrayCache(),
};
$app->instance(CacheInterface::class, $cache);
$app->instance(\Psr\SimpleCache\CacheInterface::class, new Psr16Adapter($cache));
```

---

## Queue

| Variable         | Type   | Default   | Description |
|------------------|--------|-----------|-------------|
| `QUEUE_DRIVER`   | string | `sync`    | Driver: `sync`, `array`, `redis`. |
| `QUEUE_DEFAULT`  | string | `default` | Default queue name. |
| `QUEUE_RETRY_AFTER` | int | `90`      | Seconds before a job is considered failed and retried. |

```dotenv
QUEUE_DRIVER=redis
QUEUE_DEFAULT=default
```

```php
$queue = match (Env::string('QUEUE_DRIVER', 'sync')) {
    'redis' => new RedisQueue($redis, Env::string('QUEUE_DEFAULT', 'default')),
    'array' => new ArrayQueue(),
    default => new SyncQueue(),
};
$app->setQueue($queue);
```

---

## Redis

Used by Redis-backed cache, queue, and session drivers.

| Variable         | Type   | Default     | Description |
|------------------|--------|-------------|-------------|
| `REDIS_HOST`     | string | `127.0.0.1` | Redis server host. |
| `REDIS_PORT`     | int    | `6379`      | Redis server port. |
| `REDIS_PASSWORD` | string | —           | Redis `AUTH` password (omit for no auth). |
| `REDIS_DB`       | int    | `0`         | Redis database index. |
| `REDIS_PREFIX`   | string | `lift:`     | Key prefix for all Lift Redis keys. |

```dotenv
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=secret
REDIS_DB=0
```

---

## Logging

| Variable      | Type   | Default              | Description |
|---------------|--------|----------------------|-------------|
| `LOG_CHANNEL` | string | `stdout`             | Channel: `file`, `stdout`, `null`. |
| `LOG_LEVEL`   | string | `debug`              | Minimum level: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`. |
| `LOG_PATH`    | string | `storage/logs/app.log` | File path when `LOG_CHANNEL=file`. |
| `LOG_FORMAT`  | string | `line`               | Formatter: `line`, `json`. |

```dotenv
LOG_CHANNEL=file
LOG_LEVEL=warning
LOG_PATH=storage/logs/app.log
LOG_FORMAT=json
```

```php
$formatter = Env::string('LOG_FORMAT', 'line') === 'json'
    ? new JsonFormatter()
    : new LineFormatter();

$handler = match (Env::string('LOG_CHANNEL', 'stdout')) {
    'file'   => new FileHandler(Env::string('LOG_PATH', 'storage/logs/app.log'),
                                Env::string('LOG_LEVEL', 'debug'), $formatter),
    'null'   => new NullHandler(),
    default  => new StdoutHandler(Env::string('LOG_LEVEL', 'debug'), $formatter),
};
$logger = new Logger([$handler]);
$app->instance(Logger::class, $logger);
```

---

## Views

| Variable          | Type   | Default    | Description |
|-------------------|--------|------------|-------------|
| `VIEWS_PATH`      | string | `views/`   | Path to the views root directory. |
| `VIEWS_EXTENSION` | string | `php`      | File extension for view files (without dot). |
| `ASSET_BASE`      | string | `/assets`  | URL prefix prepended to relative asset paths via `$view->asset()`. |

```dotenv
VIEWS_PATH=resources/views
VIEWS_EXTENSION=php
ASSET_BASE=/static
```

```php
$app->views(
    path:      Env::string('VIEWS_PATH', __DIR__ . '/../views'),
    extension: Env::string('VIEWS_EXTENSION', 'php'),
    assetBase: Env::string('ASSET_BASE', '/assets'),
);
```

---

## Debug Toolbar

Enabled only when `APP_DEBUG=true`. Never enable in production.

| Variable                   | Type   | Default        | Description |
|----------------------------|--------|----------------|-------------|
| `DEBUG_TOOLBAR`            | bool   | `true`         | Show the HTML toolbar on responses. |
| `DEBUG_TOOLBAR_POSITION`   | string | `bottom-right` | Position: `bottom-right`, `bottom-left`. |
| `DEBUG_TRACK_PHP_ERRORS`   | bool   | `true`         | Capture PHP notices/warnings. |
| `DEBUG_RENDER_EXCEPTIONS`  | bool   | `true`         | Render full exception pages instead of generic 500 errors. |

```php
$app->debug([
    'enabled'           => Env::bool('APP_DEBUG', false),
    'toolbar'           => Env::bool('DEBUG_TOOLBAR', true),
    'position'          => Env::string('DEBUG_TOOLBAR_POSITION', 'bottom-right'),
    'track_php_errors'  => Env::bool('DEBUG_TRACK_PHP_ERRORS', true),
    'render_exceptions' => Env::bool('DEBUG_RENDER_EXCEPTIONS', true),
]);
```

---

## JWT

| Variable        | Type   | Default | Description |
|-----------------|--------|---------|-------------|
| `JWT_SECRET`    | string | —       | Signing secret for HS256/HS384/HS512. **Required.** |
| `JWT_ALGORITHM` | string | `HS256` | Algorithm: `HS256`, `HS384`, `HS512`, `RS256` (requires key files). |
| `JWT_TTL`       | int    | `3600`  | Token time-to-live in seconds. |
| `JWT_ISSUER`    | string | —       | Optional `iss` claim value. |
| `JWT_AUDIENCE`  | string | —       | Optional `aud` claim value. |

```dotenv
JWT_SECRET=your-256-bit-secret
JWT_ALGORITHM=HS256
JWT_TTL=3600
```

```php
$jwt = new Jwt(
    secret:    Env::string('JWT_SECRET') ?? throw new \RuntimeException('JWT_SECRET is required'),
    algorithm: JwtAlgorithm::from(Env::string('JWT_ALGORITHM', 'HS256')),
    ttl:       Env::int('JWT_TTL', 3600),
);
$app->use(new JwtMiddleware($jwt));
```

---

## CORS

| Variable                | Type   | Default  | Description |
|-------------------------|--------|----------|-------------|
| `CORS_ALLOWED_ORIGINS`  | string | `*`      | Comma-separated allowed origins, or `*` for all. |
| `CORS_ALLOWED_METHODS`  | string | `GET,POST,PUT,PATCH,DELETE,OPTIONS` | Comma-separated HTTP methods. |
| `CORS_ALLOWED_HEADERS`  | string | `Content-Type,Authorization` | Comma-separated request headers. |
| `CORS_EXPOSED_HEADERS`  | string | —        | Comma-separated response headers exposed to the browser. |
| `CORS_MAX_AGE`          | int    | `0`      | Seconds to cache the preflight response. |
| `CORS_ALLOW_CREDENTIALS`| bool   | `false`  | Allow cookies / auth in cross-origin requests. |

```dotenv
CORS_ALLOWED_ORIGINS=https://app.example.com,https://admin.example.com
CORS_ALLOW_CREDENTIALS=true
CORS_MAX_AGE=86400
```

```php
$app->use(new CorsMiddleware(
    allowedOrigins:    explode(',', Env::string('CORS_ALLOWED_ORIGINS', '*')),
    allowedMethods:    explode(',', Env::string('CORS_ALLOWED_METHODS', 'GET,POST,PUT,PATCH,DELETE,OPTIONS')),
    allowedHeaders:    explode(',', Env::string('CORS_ALLOWED_HEADERS', 'Content-Type,Authorization')),
    allowCredentials:  Env::bool('CORS_ALLOW_CREDENTIALS', false),
    maxAge:            Env::int('CORS_MAX_AGE', 0),
));
```

---

## Storage / Filesystem

| Variable          | Type   | Default          | Description |
|-------------------|--------|------------------|-------------|
| `STORAGE_DRIVER`  | string | `local`          | Disk driver: `local` (additional adapters can be registered). |
| `STORAGE_PATH`    | string | `storage/app`    | Root directory for the default local disk. |
| `STORAGE_URL`     | string | —                | Public URL prefix for files on the default disk. |

```dotenv
STORAGE_DRIVER=local
STORAGE_PATH=storage/app
STORAGE_URL=https://cdn.example.com/files
```

```php
$storage = new Storage();
$storage->addDisk('local', new LocalFilesystem(
    root:      Env::string('STORAGE_PATH', __DIR__ . '/../storage/app'),
    publicUrl: Env::string('STORAGE_URL'),
));
$storage->setDefault('local');
Storage::setInstance($storage);
$app->instance(Storage::class, $storage);
```

---

## Rate Limiting

| Variable               | Type | Default | Description |
|------------------------|------|---------|-------------|
| `RATE_LIMIT_MAX`       | int  | `60`    | Maximum requests per window. |
| `RATE_LIMIT_WINDOW`    | int  | `60`    | Window size in seconds. |

```php
$app->use(new RateLimitMiddleware(
    maxRequests: Env::int('RATE_LIMIT_MAX', 60),
    windowSecs:  Env::int('RATE_LIMIT_WINDOW', 60),
));
```

---

## Security Headers

`SecurityHeadersMiddleware` ships with safe defaults. No environment variables
are required — configure it directly in code:

```php
$app->use(new SecurityHeadersMiddleware(
    contentSecurityPolicy: "default-src 'self'",
    frameOptions:          'DENY',
    xssProtection:         '1; mode=block',
    noSniff:               true,
    referrerPolicy:        'strict-origin-when-cross-origin',
    hsts:                  'max-age=31536000; includeSubDomains',
));
```

---

## CSRF

| Variable           | Type   | Default | Description |
|--------------------|--------|---------|-------------|
| `CSRF_TOKEN_NAME`  | string | `_token` | Form field and session key name for the CSRF token. |
| `CSRF_HEADER_NAME` | string | `X-CSRF-Token` | HTTP header name accepted as an alternative to the form field. |
| `CSRF_COOKIE_NAME` | string | `csrf_token` | Cookie name containing the token for JS-driven SPAs. |

```php
$app->use(new CsrfMiddleware(
    session:     $session,
    tokenName:   Env::string('CSRF_TOKEN_NAME', '_token'),
    headerName:  Env::string('CSRF_HEADER_NAME', 'X-CSRF-Token'),
));
```

---

## HTTP Client

Configure defaults for outgoing requests:

```php
$client = HttpClient::new()
    ->timeout(Env::int('HTTP_CLIENT_TIMEOUT', 30))
    ->withHeaders([
        'User-Agent' => Env::string('APP_NAME', 'Lift App') . '/1.0',
    ]);
$app->instance(HttpClient::class, $client);
```

---

## Full `.env` example

```dotenv
# Application
APP_NAME="My Lift App"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:ZmRzZmRzZmRzZmRzZmRzZmRzZmRzZmRzZmRzZmQ=
APP_URL=https://myapp.example.com

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=myapp
DB_USERNAME=myapp_user
DB_PASSWORD=secret

# Session
SESSION_DRIVER=file
SESSION_PATH=storage/sessions
SESSION_LIFETIME=7200

# Cache
CACHE_DRIVER=redis
CACHE_PREFIX=myapp_

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Queue
QUEUE_DRIVER=redis

# Logging
LOG_CHANNEL=file
LOG_LEVEL=warning
LOG_PATH=storage/logs/app.log

# JWT
JWT_SECRET=your-very-long-random-secret-key-here
JWT_TTL=3600

# CORS
CORS_ALLOWED_ORIGINS=https://myapp.example.com
```
