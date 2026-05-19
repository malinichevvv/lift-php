---
layout: page
title: Конфігурація
nav_order: 36
---

# Довідник з конфігурації

Lift налаштовується через змінні оточення (завантажувані з `.env`) та/або
внутрішньопроцесний масив, що передається у `$app->config([...])`.

**Порядок завантаження (від найвищого пріоритету до найнижчого):**
1. Реальні змінні оточення, уже встановлені в оболонці / на сервері
2. Значення, завантажені з `.env` через `$app->loadEnv(path)`
3. Значення, задані в PHP-масивах конфігурації через `$app->config([...])`

---

## Завантаження

```php
$app = new App();
$app->loadEnv(__DIR__ . '/../.env');   // завантажує .env у $_ENV / putenv()
$app->config([                         // необов’язкові перевизначення масивом
    'app.name' => 'My App',
    'app.env'  => 'production',
]);
```

Читайте значення будь-де через `Env::string()` / `Env::int()` / `Env::bool()`:

```php
use Lift\Config\Env;

$dsn  = Env::string('DB_URL');
$port = Env::int('PORT', 8080);   // за замовчуванням 8080
$flag = Env::bool('FEATURE_X');   // null, якщо відсутнє
```

---

## Застосунок

| Змінна         | Тип      | За замовчуванням | Опис |
|----------------|----------|------------------|------|
| `APP_NAME`     | string   | `Lift App`       | Людиночитане ім’я застосунку. |
| `APP_ENV`      | string   | `production`     | Робоче оточення: `local`, `testing`, `staging`, `production`. |
| `APP_DEBUG`    | bool     | `false`          | Увімкнути режим налагодження (показує повні трасування стека, впроваджує панель). |
| `APP_KEY`      | string   | —                | 32-байтовий ключ шифрування, що використовується `Encrypter`. **Обов’язковий у продакшені.** |
| `APP_URL`      | string   | `http://localhost` | Канонічний URL застосунку (використовується для генерації посилань). |

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

### Масове завантаження каталогу конфігурації

`$app->configure(string $directory)` читає кожен PHP- і YAML-файл у папці та реєструє кожен під ключем, рівним імені файлу (без розширення). Це повторює класичну угоду Laravel:

```
config/
  app.php       → configuration()->get('app.name')
  database.php  → configuration()->get('database.host')
  cache.php     → configuration()->get('cache.driver')
```

```php
// bootstrap/app.php
$app = new App();
$app->loadEnv(__DIR__ . '/../.env');
$app->configure(__DIR__ . '/../config');   // завантажити кожен файл у config/
```

Приклад `config/database.php`:

```php
<?php
use Lift\Config\Env;

return [
    'driver'   => Env::string('DB_CONNECTION', 'sqlite'),
    'host'     => Env::string('DB_HOST', '127.0.0.1'),
    'port'     => Env::int('DB_PORT', 3306),
    'database' => Env::string('DB_DATABASE', 'storage/db.sqlite'),
    'username' => Env::string('DB_USERNAME', ''),
    'password' => Env::string('DB_PASSWORD', ''),
];
```

Читання значення будь-де:

```php
$driver = $app->configuration()->get('database.driver', 'sqlite');
```

`configure()` виконує рекурсивне злиття, тому багаторазовий виклик (наприклад, завантаження базової конфігурації, а потім накладання перевизначень під конкретне оточення) безпечний:

```php
$app->configure(__DIR__ . '/../config');
$app->configure(__DIR__ . "/../config/{$app->environment()}");  // накладає local/ або testing/
```

### Методи-скорочення App

`App` надає зручні аксесори для найчастіше впроваджуваних сервісів:

```php
$app->router();           // Lift\Routing\Router      — кеш маршрутів, генерація URL
$app->db();               // Lift\Database\Connection  — потребує попередньої прив’язки singleton
$app->logger();           // Lift\Log\Logger           — потребує попередньої прив’язки singleton
$app->events();           // Lift\Events\EventDispatcher — потребує попередньої прив’язки singleton
$app->configuration();    // Lift\Config\Config        — живий репозиторій конфігурації (усі завантажені значення)

// Надіслати відповідь вручну (корисно в CLI-обгортках, бенчмарках)
$response = $app->handle($request);
$app->send($response);
```

`db()`, `logger()` і `events()` делегують у `$app->container()->make(ClassName::class)`. Вони викидають `ContainerException`, якщо викликати їх до прив’язки сервісу.

---

## База даних

| Змінна          | Тип    | За замовчуванням | Опис |
|-----------------|--------|------------------|------|
| `DB_CONNECTION` | string | `sqlite`         | Драйвер: `mysql`, `pgsql`, `sqlite`. |
| `DB_HOST`       | string | `127.0.0.1`      | Хост сервера бази даних. |
| `DB_PORT`       | int    | `3306`           | Порт сервера бази даних (за замовчуванням змінюється залежно від драйвера: PgSQL = `5432`). |
| `DB_DATABASE`   | string | —                | Ім’я бази даних або відносний шлях для SQLite (наприклад, `storage/db.sqlite`). |
| `DB_USERNAME`   | string | —                | Ім’я користувача для автентифікації. |
| `DB_PASSWORD`   | string | —                | Пароль для автентифікації. |
| `DB_CHARSET`    | string | `utf8mb4`        | Кодування з’єднання (лише MySQL/MariaDB). |

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

## Сесія

| Змінна            | Тип    | За замовчуванням   | Опис |
|-------------------|--------|--------------------|------|
| `SESSION_DRIVER`  | string | `file`             | Драйвер сховища: `file`, `array`, `database`, `redis`, `memcached`. |
| `SESSION_PATH`    | string | `storage/sessions` | Каталог для файлових сесій. |
| `SESSION_LIFETIME`| int    | `7200`             | TTL сесії в секундах. |
| `SESSION_COOKIE`  | string | `lift_session`     | Ім’я cookie, що надсилається браузеру. |
| `SESSION_TABLE`   | string | `sessions`         | Ім’я таблиці для сховища в базі даних. |

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

## Кеш

| Змінна         | Тип    | За замовчуванням | Опис |
|----------------|--------|------------------|------|
| `CACHE_DRIVER` | string | `array`          | Драйвер: `array`, `redis`. |
| `CACHE_PREFIX` | string | `lift_`          | Префікс, що додається до кожного ключа кешу. |
| `CACHE_TTL`    | int    | `3600`           | TTL за замовчуванням у секундах, коли не вказано. |

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

## Черга

| Змінна                | Тип    | За замовчуванням | Опис |
|-----------------------|--------|------------------|------|
| `QUEUE_DRIVER`        | string | `sync`           | Драйвер: `sync`, `array`, `redis`, `amqp`. |
| `QUEUE_DEFAULT`       | string | `default`        | Ім’я черги за замовчуванням. |
| `QUEUE_RETRY_AFTER`   | int    | `90`             | Секунди до того, як задача вважається проваленою і повторюється. |

```dotenv
QUEUE_DRIVER=redis
QUEUE_DEFAULT=default
```

```php
$queue = match (Env::string('QUEUE_DRIVER', 'sync')) {
    'redis' => new RedisQueue($redis),
    'amqp'  => new AmqpQueue([
                   'host'     => Env::string('RABBITMQ_HOST', 'localhost'),
                   'port'     => Env::int('RABBITMQ_PORT', 5672),
                   'user'     => Env::string('RABBITMQ_USER', 'guest'),
                   'password' => Env::string('RABBITMQ_PASSWORD', 'guest'),
                   'vhost'    => Env::string('RABBITMQ_VHOST', '/'),
               ]),
    'array' => new ArrayQueue(),
    default => new SyncQueue(),
};
$app->setQueue($queue);
```

### Змінні оточення RabbitMQ

Використовуються, коли `QUEUE_DRIVER=amqp`. Потребують `composer require php-amqplib/php-amqplib "^3.0"`.

| Змінна              | Тип    | За замовчуванням | Опис |
|---------------------|--------|------------------|------|
| `RABBITMQ_HOST`     | string | `localhost`      | Хост сервера RabbitMQ. |
| `RABBITMQ_PORT`     | int    | `5672`           | Порт AMQP. |
| `RABBITMQ_USER`     | string | `guest`          | Ім’я користувача AMQP. |
| `RABBITMQ_PASSWORD` | string | `guest`          | Пароль AMQP. |
| `RABBITMQ_VHOST`    | string | `/`              | Віртуальний хост. |

```dotenv
QUEUE_DRIVER=amqp
RABBITMQ_HOST=rabbitmq.internal
RABBITMQ_USER=myapp
RABBITMQ_PASSWORD=secret
RABBITMQ_VHOST=/myapp
```

---

## Redis

Використовується кешем, чергою та драйверами сесій на базі Redis.

| Змінна           | Тип    | За замовчуванням | Опис |
|------------------|--------|------------------|------|
| `REDIS_HOST`     | string | `127.0.0.1`      | Хост сервера Redis. |
| `REDIS_PORT`     | int    | `6379`           | Порт сервера Redis. |
| `REDIS_PASSWORD` | string | —                | Пароль `AUTH` для Redis (опустіть, якщо автентифікація не потрібна). |
| `REDIS_DB`       | int    | `0`              | Індекс бази даних Redis. |
| `REDIS_PREFIX`   | string | `lift:`          | Префікс ключів для всіх ключів Lift у Redis. |

```dotenv
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=secret
REDIS_DB=0
```

---

## Логування

| Змінна        | Тип    | За замовчуванням       | Опис |
|---------------|--------|------------------------|------|
| `LOG_CHANNEL` | string | `stdout`               | Канал: `file`, `stdout`, `null`. |
| `LOG_LEVEL`   | string | `debug`                | Мінімальний рівень: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`. |
| `LOG_PATH`    | string | `storage/logs/app.log` | Шлях до файлу, коли `LOG_CHANNEL=file`. |
| `LOG_FORMAT`  | string | `line`                 | Форматувальник: `line`, `json`. |

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

## Шаблони

| Змінна            | Тип    | За замовчуванням | Опис |
|-------------------|--------|------------------|------|
| `VIEWS_PATH`      | string | `views/`         | Шлях до кореневого каталогу шаблонів. |
| `VIEWS_EXTENSION` | string | `php`            | Розширення файлів шаблонів (без крапки). |
| `ASSET_BASE`      | string | `/assets`        | Префікс URL, що додається до відносних шляхів ресурсів через `$view->asset()`. |

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

## Панель налагодження

Увімкнена лише коли `APP_DEBUG=true`. Ніколи не вмикайте у продакшені.

| Змінна                     | Тип    | За замовчуванням | Опис |
|----------------------------|--------|------------------|------|
| `DEBUG_TOOLBAR`            | bool   | `true`           | Показувати HTML-панель на відповідях. |
| `DEBUG_TOOLBAR_POSITION`   | string | `bottom-right`   | Положення: `bottom-right`, `bottom-left`. |
| `DEBUG_TRACK_PHP_ERRORS`   | bool   | `true`           | Захоплювати PHP-notice/warning. |
| `DEBUG_RENDER_EXCEPTIONS`  | bool   | `true`           | Рендерити повні сторінки винятків замість загальних помилок 500. |

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

| Змінна          | Тип    | За замовчуванням | Опис |
|-----------------|--------|------------------|------|
| `JWT_SECRET`    | string | —                | Секрет підпису для HS256/HS384/HS512. **Обов’язковий.** |
| `JWT_ALGORITHM` | string | `HS256`          | Алгоритм: `HS256`, `HS384`, `HS512`, `RS256` (потребує файли ключів). |
| `JWT_TTL`       | int    | `3600`           | Час життя токена в секундах. |
| `JWT_ISSUER`    | string | —                | Необов’язкове значення claim `iss`. |
| `JWT_AUDIENCE`  | string | —                | Необов’язкове значення claim `aud`. |

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

| Змінна                  | Тип    | За замовчуванням | Опис |
|-------------------------|--------|------------------|------|
| `CORS_ALLOWED_ORIGINS`  | string | `*`              | Дозволені джерела через кому або `*` для всіх. |
| `CORS_ALLOWED_METHODS`  | string | `GET,POST,PUT,PATCH,DELETE,OPTIONS` | HTTP-методи через кому. |
| `CORS_ALLOWED_HEADERS`  | string | `Content-Type,Authorization` | Заголовки запиту через кому. |
| `CORS_EXPOSED_HEADERS`  | string | —                | Заголовки відповіді, доступні браузеру, через кому. |
| `CORS_MAX_AGE`          | int    | `0`              | Секунди кешування preflight-відповіді. |
| `CORS_ALLOW_CREDENTIALS`| bool   | `false`          | Дозволити cookie / автентифікацію у крос-доменних запитах. |

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

## Сховище / Файлова система

| Змінна            | Тип    | За замовчуванням | Опис |
|-------------------|--------|------------------|------|
| `STORAGE_DRIVER`  | string | `local`          | Драйвер диска: `local` (можна реєструвати додаткові адаптери). |
| `STORAGE_PATH`    | string | `storage/app`    | Кореневий каталог для локального диска за замовчуванням. |
| `STORAGE_URL`     | string | —                | Публічний префікс URL для файлів на диску за замовчуванням. |

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

## Обмеження частоти запитів

| Змінна                 | Тип  | За замовчуванням | Опис |
|------------------------|------|------------------|------|
| `RATE_LIMIT_MAX`       | int  | `60`             | Максимум запитів за вікно. |
| `RATE_LIMIT_WINDOW`    | int  | `60`             | Розмір вікна в секундах. |

```php
$app->use(new RateLimitMiddleware(
    maxRequests: Env::int('RATE_LIMIT_MAX', 60),
    windowSecs:  Env::int('RATE_LIMIT_WINDOW', 60),
));
```

---

## Заголовки безпеки

`SecurityHeadersMiddleware` постачається з безпечними значеннями за замовчуванням. Змінні оточення не потрібні — налаштовуйте його напряму в коді:

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

| Змінна             | Тип    | За замовчуванням | Опис |
|--------------------|--------|------------------|------|
| `CSRF_TOKEN_NAME`  | string | `_token`         | Ім’я поля форми та ключа сесії для CSRF-токена. |
| `CSRF_HEADER_NAME` | string | `X-CSRF-Token`   | Ім’я HTTP-заголовка, що приймається як альтернатива полю форми. |
| `CSRF_COOKIE_NAME` | string | `csrf_token`     | Ім’я cookie, що містить токен для SPA на JS. |

```php
$app->use(new CsrfMiddleware(
    session:     $session,
    tokenName:   Env::string('CSRF_TOKEN_NAME', '_token'),
    headerName:  Env::string('CSRF_HEADER_NAME', 'X-CSRF-Token'),
));
```

---

## HTTP-клієнт

Налаштуйте значення за замовчуванням для вихідних запитів:

```php
$client = HttpClient::new()
    ->timeout(Env::int('HTTP_CLIENT_TIMEOUT', 30))
    ->withHeaders([
        'User-Agent' => Env::string('APP_NAME', 'Lift App') . '/1.0',
    ]);
$app->instance(HttpClient::class, $client);
```

---

## Повний приклад `.env`

```dotenv
# Застосунок
APP_NAME="My Lift App"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:ZmRzZmRzZmRzZmRzZmRzZmRzZmRzZmRzZmRzZmQ=
APP_URL=https://myapp.example.com

# База даних
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=myapp
DB_USERNAME=myapp_user
DB_PASSWORD=secret

# Сесія
SESSION_DRIVER=file
SESSION_PATH=storage/sessions
SESSION_LIFETIME=7200

# Кеш
CACHE_DRIVER=redis
CACHE_PREFIX=myapp_

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Черга
QUEUE_DRIVER=redis

# Логування
LOG_CHANNEL=file
LOG_LEVEL=warning
LOG_PATH=storage/logs/app.log

# JWT
JWT_SECRET=your-very-long-random-secret-key-here
JWT_TTL=3600

# CORS
CORS_ALLOWED_ORIGINS=https://myapp.example.com
```
