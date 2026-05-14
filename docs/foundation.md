---
layout: page
title: Application Foundation
nav_order: 39
---

# Application Foundation

> This page is a **navigation hub**. Its original content has been split into focused pages — each topic now lives in the best place for it.

---

## Controllers & routing

- **[Routing](routing)** — registering routes, groups, named routes, middleware, return-value normalisation.
- **[Attribute Routing](attribute-routing)** — `#[Get]`, `#[Post]`, `#[Group]`, `#[Middleware]` on controller methods.
- **[Handler types](routing#handler-types)** — closures, `[Class, 'method']`, invokable classes.

## HTTP

- **[Request](request)** — reading input, params, headers, cookies, file uploads.
- **[Response](response)** — JSON, HTML, redirects, streaming, cookies.
- **[Form Requests](form-requests)** — typed, validated request objects.
- **[JSON Resources](json-resources)** — shaping API output.
- **[Server-Sent Events](sse)** — streaming server push.
- **[HTTP Client](http-client)** — making outbound HTTP requests.

## Views & sessions

- **[Views](views)** — PHP templates, layouts, sections, partials, asset URLs, view caching.
- **[Sessions](sessions)** — file, Redis, database, and array drivers; flash messages.

## Error handling

- **[Errors](errors)** — exception hierarchy, `onError`, `onException`, HTTP exceptions.
- **[Debug Toolbar](debug)** — toolbar, exception pages, SQL/log wiring for local development.

## Data

- **[Database](database)** — Connection, QueryBuilder, Schema, Migrator, Model, SoftDeletes, pessimistic locks, advisory locks.
- **[Validation](validation)** — all rules, custom rules, `FormRequest`, `ValidationException`.
- **[Collections](collections)** — `Collection` helper with full method reference.
- **[Cache](cache)** — `ArrayCache`, `RedisCache` with HMAC signing, PSR-16 adapter.
- **[Filesystem](filesystem)** — `LocalFilesystem`, `Storage` facade, upload patterns.
- **[Redis](redis)** — `RedisClient`, raw protocol client, testing fakes.

## Security

- **[Security Middleware](security)** — CORS, CSRF, Rate Limiting, Security Headers.
- **[JWT](jwt)** — sign/verify, middleware, refresh-token pattern.
- **[Cryptography](crypto)** — AES-256-GCM encryption, Argon2id hashing, HMAC signing.

## Services

- **[Queues](queues)** — jobs, drivers, workers, retries, failed-job tracking.
- **[Events](events)** — PSR-14 dispatcher, listeners, model lifecycle events.
- **[Logging](logging)** — handlers, formatters, PSR-3 integration.
- **[Console](console)** — CLI commands, generators, the worker command.
- **[Localization](localization)** — translations, pluralization, locale switching.
- **[JSON-RPC 2.0](json-rpc)** — batch requests, method routing, error codes.
- **[OpenAPI](openapi)** — generating specs from attributes and doc-blocks.
- **[Async (Fibers)](async)** — cooperative concurrency with PHP 8.1 fibers.

## Reference

- **[Configuration](configuration)** — environment variables, `.env`, config arrays.
- **[UUID & ULID](uuid)** — v4, v7, ULID, binary encoding.
- **[DI Container](container)** — bindings, singletons, auto-wiring, contextual binding.
- **[Testing](testing)** — `TestCase`, `TestResponse`, request helpers.
- **[Framework Comparison](comparison)** — Lift vs Slim vs Lumen vs Laravel Micro.
