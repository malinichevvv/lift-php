---
layout: page
title: Конфигурация
nav_order: 36
---

# Справочник по конфигурации

Lift настраивается через переменные окружения (загружаемые из `.env`) и/или
внутрипроцессный массив, передаваемый в `$app->config([...])`.

**Порядок загрузки (от высшего приоритета к низшему):**
1. Реальные переменные окружения, уже установленные в оболочке / на сервере
2. Значения, загруженные из `.env` через `$app->loadEnv(path)`
3. Значения, заданные в PHP-массивах конфигурации через `$app->config([...])`

---

## Загрузка

```php
$app = new App();
$app->loadEnv(__DIR__ . '/../.env');   // загружает .env в $_ENV / putenv()
$app->config([                         // необязательные переопределения массивом
    'app.name' => 'My App',
    'app.env'  => 'production',
]);
```

Читайте значения где угодно через `Env::string()` / `Env::int()` / `Env::bool()`:

```php
use Lift\Config\Env;

$dsn  = Env::string('DB_URL');
$port = Env::int('PORT', 8080);   // по умолчанию 8080
$flag = Env::bool('FEATURE_X');   // null, если отсутствует
```

---

## Приложение

| Переменная     | Тип      | По умолчанию  | Описание |
|----------------|----------|---------------|----------|
| `APP_NAME`     | string   | `Lift App`    | Человекочитаемое имя приложения. |
| `APP_ENV`      | string   | `production`  | Рабочее окружение: `local`, `testing`, `staging`, `production`. |
| `APP_DEBUG`    | bool     | `false`       | Включить режим отладки (показывает полные трассировки стека, внедряет панель). |
| `APP_KEY`      | string   | —             | 32-байтовый ключ шифрования, используемый `Encrypter`. **Обязателен в продакшене.** |
| `APP_URL`      | string   | `http://localhost` | Каноничный URL приложения (используется для генерации ссылок). |

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

### Массовая загрузка каталога конфигурации

`$app->configure(string $directory)` читает каждый PHP- и YAML-файл в папке и регистрирует каждый под ключом, равным имени файла (без расширения). Это повторяет классическое соглашение Laravel:

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
$app->configure(__DIR__ . '/../config');   // загрузить каждый файл в config/
```

Пример `config/database.php`:

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

Чтение значения где угодно:

```php
$driver = $app->configuration()->get('database.driver', 'sqlite');
```

`configure()` выполняет рекурсивное слияние, поэтому многократный вызов (например, загрузка базовой конфигурации, а затем наложение переопределений под конкретное окружение) безопасен:

```php
$app->configure(__DIR__ . '/../config');
$app->configure(__DIR__ . "/../config/{$app->environment()}");  // накладывает local/ или testing/
```

### Методы-сокращения App

`App` предоставляет удобные аксессоры для наиболее часто внедряемых сервисов:

```php
$app->router();           // Lift\Routing\Router      — кэш маршрутов, генерация URL
$app->db();               // Lift\Database\Connection  — требует предварительной привязки singleton
$app->logger();           // Lift\Log\Logger           — требует предварительной привязки singleton
$app->events();           // Lift\Events\EventDispatcher — требует предварительной привязки singleton
$app->configuration();    // Lift\Config\Config        — живой репозиторий конфигурации (все загруженные значения)

// Отправить ответ вручную (полезно в CLI-обвязках, бенчмарках)
$response = $app->handle($request);
$app->send($response);
```

`db()`, `logger()` и `events()` делегируют в `$app->container()->make(ClassName::class)`. Они выбрасывают `ContainerException`, если вызвать их до привязки сервиса.

---

## База данных

| Переменная      | Тип    | По умолчанию | Описание |
|-----------------|--------|--------------|----------|
| `DB_CONNECTION` | string | `sqlite`     | Драйвер: `mysql`, `pgsql`, `sqlite`. |
| `DB_HOST`       | string | `127.0.0.1`  | Хост сервера базы данных. |
| `DB_PORT`       | int    | `3306`       | Порт сервера базы данных (по умолчанию меняется в зависимости от драйвера: PgSQL = `5432`). |
| `DB_DATABASE`   | string | —            | Имя базы данных или относительный путь для SQLite (например, `storage/db.sqlite`). |
| `DB_USERNAME`   | string | —            | Имя пользователя для аутентификации. |
| `DB_PASSWORD`   | string | —            | Пароль для аутентификации. |
| `DB_CHARSET`    | string | `utf8mb4`    | Кодировка соединения (только MySQL/MariaDB). |

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

## Сессия

| Переменная        | Тип    | По умолчанию       | Описание |
|-------------------|--------|--------------------|----------|
| `SESSION_DRIVER`  | string | `file`             | Драйвер хранилища: `file`, `array`, `database`, `redis`, `memcached`. |
| `SESSION_PATH`    | string | `storage/sessions` | Каталог для файловых сессий. |
| `SESSION_LIFETIME`| int    | `7200`             | TTL сессии в секундах. |
| `SESSION_COOKIE`  | string | `lift_session`     | Имя cookie, отправляемого браузеру. |
| `SESSION_TABLE`   | string | `sessions`         | Имя таблицы для хранилища в базе данных. |

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

## Кэш

| Переменная     | Тип    | По умолчанию | Описание |
|----------------|--------|--------------|----------|
| `CACHE_DRIVER` | string | `array`      | Драйвер: `array`, `redis`. |
| `CACHE_PREFIX` | string | `lift_`      | Префикс, добавляемый к каждому ключу кэша. |
| `CACHE_TTL`    | int    | `3600`       | TTL по умолчанию в секундах, когда не указан. |

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

## Очередь

| Переменная            | Тип    | По умолчанию | Описание |
|-----------------------|--------|--------------|----------|
| `QUEUE_DRIVER`        | string | `sync`       | Драйвер: `sync`, `array`, `redis`, `amqp`. |
| `QUEUE_DEFAULT`       | string | `default`    | Имя очереди по умолчанию. |
| `QUEUE_RETRY_AFTER`   | int    | `90`         | Секунды до того, как задача считается проваленной и повторяется. |

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

### Переменные окружения RabbitMQ

Используются, когда `QUEUE_DRIVER=amqp`. Требуют `composer require php-amqplib/php-amqplib "^3.0"`.

| Переменная          | Тип    | По умолчанию | Описание |
|---------------------|--------|--------------|----------|
| `RABBITMQ_HOST`     | string | `localhost`  | Хост сервера RabbitMQ. |
| `RABBITMQ_PORT`     | int    | `5672`       | Порт AMQP. |
| `RABBITMQ_USER`     | string | `guest`      | Имя пользователя AMQP. |
| `RABBITMQ_PASSWORD` | string | `guest`      | Пароль AMQP. |
| `RABBITMQ_VHOST`    | string | `/`          | Виртуальный хост. |

```dotenv
QUEUE_DRIVER=amqp
RABBITMQ_HOST=rabbitmq.internal
RABBITMQ_USER=myapp
RABBITMQ_PASSWORD=secret
RABBITMQ_VHOST=/myapp
```

---

## Redis

Используется кэшем, очередью и драйверами сессий на базе Redis.

| Переменная       | Тип    | По умолчанию | Описание |
|------------------|--------|--------------|----------|
| `REDIS_HOST`     | string | `127.0.0.1`  | Хост сервера Redis. |
| `REDIS_PORT`     | int    | `6379`       | Порт сервера Redis. |
| `REDIS_PASSWORD` | string | —            | Пароль `AUTH` для Redis (опустите, если аутентификация не нужна). |
| `REDIS_DB`       | int    | `0`          | Индекс базы данных Redis. |
| `REDIS_PREFIX`   | string | `lift:`      | Префикс ключей для всех ключей Lift в Redis. |

```dotenv
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=secret
REDIS_DB=0
```

---

## Логирование

| Переменная    | Тип    | По умолчанию           | Описание |
|---------------|--------|------------------------|----------|
| `LOG_CHANNEL` | string | `stdout`               | Канал: `file`, `stdout`, `null`. |
| `LOG_LEVEL`   | string | `debug`                | Минимальный уровень: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`. |
| `LOG_PATH`    | string | `storage/logs/app.log` | Путь к файлу, когда `LOG_CHANNEL=file`. |
| `LOG_FORMAT`  | string | `line`                 | Форматтер: `line`, `json`. |

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

## Шаблоны

| Переменная        | Тип    | По умолчанию | Описание |
|-------------------|--------|--------------|----------|
| `VIEWS_PATH`      | string | `views/`     | Путь к корневому каталогу шаблонов. |
| `VIEWS_EXTENSION` | string | `php`        | Расширение файлов шаблонов (без точки). |
| `ASSET_BASE`      | string | `/assets`    | Префикс URL, добавляемый к относительным путям ресурсов через `$view->asset()`. |

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

## Отладочная панель

Включена только когда `APP_DEBUG=true`. Никогда не включайте в продакшене.

| Переменная                 | Тип    | По умолчанию   | Описание |
|----------------------------|--------|----------------|----------|
| `DEBUG_TOOLBAR`            | bool   | `true`         | Показывать HTML-панель на ответах. |
| `DEBUG_TOOLBAR_POSITION`   | string | `bottom-right` | Положение: `bottom-right`, `bottom-left`. |
| `DEBUG_TRACK_PHP_ERRORS`   | bool   | `true`         | Захватывать PHP-notice/warning. |
| `DEBUG_RENDER_EXCEPTIONS`  | bool   | `true`         | Рендерить полные страницы исключений вместо общих ошибок 500. |

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

| Переменная      | Тип    | По умолчанию | Описание |
|-----------------|--------|--------------|----------|
| `JWT_SECRET`    | string | —            | Секрет подписи для HS256/HS384/HS512. **Обязателен.** |
| `JWT_ALGORITHM` | string | `HS256`      | Алгоритм: `HS256`, `HS384`, `HS512`, `RS256` (требует файлы ключей). |
| `JWT_TTL`       | int    | `3600`       | Время жизни токена в секундах. |
| `JWT_ISSUER`    | string | —            | Необязательное значение claim `iss`. |
| `JWT_AUDIENCE`  | string | —            | Необязательное значение claim `aud`. |

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

| Переменная              | Тип    | По умолчанию | Описание |
|-------------------------|--------|--------------|----------|
| `CORS_ALLOWED_ORIGINS`  | string | `*`          | Разрешённые источники через запятую или `*` для всех. |
| `CORS_ALLOWED_METHODS`  | string | `GET,POST,PUT,PATCH,DELETE,OPTIONS` | HTTP-методы через запятую. |
| `CORS_ALLOWED_HEADERS`  | string | `Content-Type,Authorization` | Заголовки запроса через запятую. |
| `CORS_EXPOSED_HEADERS`  | string | —            | Заголовки ответа, доступные браузеру, через запятую. |
| `CORS_MAX_AGE`          | int    | `0`          | Секунды кэширования preflight-ответа. |
| `CORS_ALLOW_CREDENTIALS`| bool   | `false`      | Разрешить cookie / аутентификацию в кросс-доменных запросах. |

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

## Хранилище / Файловая система

| Переменная        | Тип    | По умолчанию  | Описание |
|-------------------|--------|---------------|----------|
| `STORAGE_DRIVER`  | string | `local`       | Драйвер диска: `local` (можно регистрировать дополнительные адаптеры). |
| `STORAGE_PATH`    | string | `storage/app` | Корневой каталог для локального диска по умолчанию. |
| `STORAGE_URL`     | string | —             | Публичный префикс URL для файлов на диске по умолчанию. |

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

## Ограничение частоты запросов

| Переменная             | Тип  | По умолчанию | Описание |
|------------------------|------|--------------|----------|
| `RATE_LIMIT_MAX`       | int  | `60`         | Максимум запросов за окно. |
| `RATE_LIMIT_WINDOW`    | int  | `60`         | Размер окна в секундах. |

```php
$app->use(new RateLimitMiddleware(
    maxRequests: Env::int('RATE_LIMIT_MAX', 60),
    windowSecs:  Env::int('RATE_LIMIT_WINDOW', 60),
));
```

---

## Заголовки безопасности

`SecurityHeadersMiddleware` поставляется с безопасными значениями по умолчанию. Переменные окружения не требуются — настраивайте его напрямую в коде:

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

| Переменная         | Тип    | По умолчанию   | Описание |
|--------------------|--------|----------------|----------|
| `CSRF_TOKEN_NAME`  | string | `_token`       | Имя поля формы и ключа сессии для CSRF-токена. |
| `CSRF_HEADER_NAME` | string | `X-CSRF-Token` | Имя HTTP-заголовка, принимаемого как альтернатива полю формы. |
| `CSRF_COOKIE_NAME` | string | `csrf_token`   | Имя cookie, содержащего токен для SPA на JS. |

```php
$app->use(new CsrfMiddleware(
    session:     $session,
    tokenName:   Env::string('CSRF_TOKEN_NAME', '_token'),
    headerName:  Env::string('CSRF_HEADER_NAME', 'X-CSRF-Token'),
));
```

---

## HTTP-клиент

Настройте значения по умолчанию для исходящих запросов:

```php
$client = HttpClient::new()
    ->timeout(Env::int('HTTP_CLIENT_TIMEOUT', 30))
    ->withHeaders([
        'User-Agent' => Env::string('APP_NAME', 'Lift App') . '/1.0',
    ]);
$app->instance(HttpClient::class, $client);
```

---

## Полный пример `.env`

```dotenv
# Приложение
APP_NAME="My Lift App"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:ZmRzZmRzZmRzZmRzZmRzZmRzZmRzZmRzZmRzZmQ=
APP_URL=https://myapp.example.com

# База данных
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=myapp
DB_USERNAME=myapp_user
DB_PASSWORD=secret

# Сессия
SESSION_DRIVER=file
SESSION_PATH=storage/sessions
SESSION_LIFETIME=7200

# Кэш
CACHE_DRIVER=redis
CACHE_PREFIX=myapp_

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Очередь
QUEUE_DRIVER=redis

# Логирование
LOG_CHANNEL=file
LOG_LEVEL=warning
LOG_PATH=storage/logs/app.log

# JWT
JWT_SECRET=your-very-long-random-secret-key-here
JWT_TTL=3600

# CORS
CORS_ALLOWED_ORIGINS=https://myapp.example.com
```
