# Changelog

All notable changes to Lift will be documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] — 2026-05-15

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
