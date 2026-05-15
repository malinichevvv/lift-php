# Changelog

All notable changes to Lift will be documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.1.3] — 2026-05-16

### Performance
- `StringStream` — new string-backed `StreamInterface` that replaces `fopen('php://temp')` + `fwrite` + `rewind` for every response. All `Response` factory methods (`json()`, `html()`, `text()`) and `Request` empty-body construction now use `StringStream`, eliminating a syscall per request.
- `App::emit()` — fast path for `StringStream` bodies: avoids `isSeekable()` + `rewind()` + `getContents()` indirection.
- `Request::fromGlobals()` — skip `Stream::fromInput()` (file-descriptor open) for `GET`, `HEAD`, `DELETE`, and `OPTIONS` requests, which have no body.
- `Router` — static routes skip the `withRouteParams([])` clone (saves one object allocation). Middleware pipeline is only instantiated when at least one middleware is attached; handlers with no middleware are called directly.
- `Router` — `Closure` route handler reflection is cached by `{file}:{startLine}` instead of being re-created per request. Non-closure callables are cached by function name.
- `Container::resolveParameters()` — early return for zero-parameter lists (common case for no-arg handlers). `class_parents()` / `class_implements()` results are cached per class in a static process-level map, avoiding repeated hierarchy traversal.

## [1.1.2] — 2026-05-16

### Added
- `StreamHandler` — writes log records to any writable PHP stream resource (memory streams, file handles, sockets). Useful for capturing log output in tests via `fopen('php://memory', 'rw')`.
- `Jwt::sign()` / `Jwt::verify()` — aliases for `encode()` / `decode()` for API familiarity.
- `Connection::statement()` — alias for `execute()` for familiarity with other frameworks (e.g. Laravel `DB::statement()`).
- `Collection::paginate(int $page, int $perPage)` — paginate a collection in memory; returns `data`, `total`, `per_page`, `page`, `last_page`, `from`, `to` matching the QueryBuilder paginator shape.
- `ListenerProvider::listen()` — alias for `addListener()` for consistent API across `EventDispatcher` and `ListenerProvider`.
- `Model::__get()` / `__set()` / `__isset()` — property access for model attributes (`$model->name` now works in addition to `$model->get('name')` and `$model['name']`).

### Fixed
- `ArrayCache::set()` with a negative `$ttl` now immediately removes any existing key and returns without storing, per PSR-16 semantics. Previously a negative TTL was silently treated as "never expire".

## [1.1.1] — 2026-05-16

### Fixed
- `StdoutHandler`: `STDOUT` constant is undefined in PHP-FPM context (only available in CLI). Handler now opens `php://stdout` as a fallback, making it usable in web server processes (e.g. Docker + php-fpm).
- `ViewRenderer` / `ViewContext`: `layout()` now accepts an optional second argument `array $data = []`. Extra variables are merged over the current view data before the layout is rendered, allowing views to pass layout-specific variables such as `title` or `canonical` directly at the call site.

## [1.1.0] — 2026-05-16

### Added
- `RotatingFileHandler` — daily log rotation with configurable `$maxFiles` retention; file naming: `app-2026-05-15.log`.
- `Model::$casts` — declarative attribute casting on read/write. Supported types: `int`, `float`, `string`, `bool`, `array`/`json` (array ↔ JSON string), `datetime`/`date`/`timestamp` (string/int ↔ `DateTimeImmutable`).
- `Model::belongsToMany()` — many-to-many relationship via a pivot table. Pivot name defaults to alphabetical snake_case pair (`User ↔ Role` → `role_user`). Accepts explicit `$pivotTable`, `$foreignKey`, `$relatedKey`.
- `Model::keyName()` — public static method returning the primary key column name.
- `QueryBuilder::cursor()` — lazy generator that yields one row at a time; constant memory for large tables.
- `Connection::selectCursor()` — underlying row-streaming method used by `cursor()`.
- `Support\Date` — timezone-aware date utilities: `now()`, `parse()`, `inTimezone()`, `format()`, `add()`, `sub()`, `startOf()`, `endOf()`, `diffForHumans()`, `isToday()`, `isPast()`, `isFuture()`, `isSameDay()`.
- `Support\Number` — number and money formatting: `money()` (locale-aware via `intl`, with fallback), `format()`, `percent()`, `fileSize()`, `abbreviate()`, `ordinal()`.

## [1.0.0] — 2026-05-16

### Added
- Core HTTP: immutable PSR-7 `Request` / `Response`, `Uri`, `Stream`
- Router: named routes, regex constraints, route groups, per-route and group middleware
- Attribute routing: `#[Get]`, `#[Post]`, `#[Group]`, `#[Middleware]`
- DI Container: PSR-11, full constructor/method autowiring, circular dependency detection, static reflection cache
- Middleware: PSR-15 pipeline, global / group / per-route attachment
- Database: query builder, schema builder, migrations, models (active-record), soft deletes, pessimistic row locks, advisory locks
- Validation: 60+ rules, custom messages, `FormRequest`, `$req->validate()`
- Views: template engine with layouts, `@section` / `@yield`, partials, asset helpers, translation
- Sessions: `SessionInterface` with cookie, file, and Redis drivers
- Queue: `SyncQueue`, `DatabaseQueue`, `RedisQueue`, `AmqpQueue`, worker process with PCNTL signals
- Cache: PSR-16, `FileCache`, `RedisCache`, `DatabaseCache`
- Events: PSR-14 `EventDispatcher` with listener priorities and wildcards
- HTTP Client: fluent ext-curl wrapper with retries, auth, and streaming
- Server-Sent Events: `SseResponse` with typed event helpers
- Redis client: thin wrapper for ext-redis
- JWT: HS256/RS256/ES256 sign, verify, decode
- Crypto: HMAC, AES-256-GCM encryption/decryption, bcrypt helpers
- OpenAPI: `#[Operation]`, `#[Schema]`, generator producing spec array
- JSON-RPC 2.0: `JsonRpcServer` router with DI-resolved handlers
- Console: `Application`, `Command`, `Input`, `Output`; scaffolding generators (`make:controller`, `make:model`, `make:test`, …); interactive REPL (`lift repl`)
- Debug toolbar: inline HTML panel with request, SQL, performance, and error tabs; `$app->debug()`
- Async: PHP Fiber-based `Concurrent::all()`, `suspend()`, `sequential()`
- Collections: `Collection` with `map`, `filter`, `reduce`, `pluck`, `groupBy`, `chunk`, pagination, lazy generators
- UUID v4 / v7, ULID
- `ErrorRenderer`: content-negotiating error handler factory (`auto()`, `json()`, `html()`)
- `$app->configure(string $directory)`: bulk-load config files from a directory
- CLI `lift serve`, `lift repl`, `migrate:*`, `queue:work`, `routes`, `key:generate`
