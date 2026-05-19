---
layout: home
title: Lift — The lifting PHP micro-framework
nav_order: 1
---

# Lift

**The lifting PHP micro-framework — small enough to read in an evening, complete enough to ship a production API by morning.**

Lift is a modern PHP 8.1+ micro-framework that ships *only* what a real web service actually needs: a router, a DI container with autowiring, PSR-15 middleware, an HTTP layer, a query builder + migrations, queues, JWT, encryption, and a JSON-RPC server. It has **zero non-PSR runtime dependencies**, ~10 000 lines of source, and benchmarks faster than every other PHP micro-framework except raw PHP itself.

If you've ever felt that Laravel/Symfony are too much, and that Slim/Flight are too little — Lift is the framework you actually want.

## Five-second demo

```php
<?php
require 'vendor/autoload.php';

use Lift\App;
use Lift\Http\Request;
use Lift\Http\Response;

$app = new App();

$app->get('/', fn() => Response::json(['message' => 'Hello, World!']));

$app->get('/users/{id}', fn(Request $req) => Response::json([
    'id' => (int) $req->param('id'),
]));

$app->run();
```

That's a complete, runnable JSON API. No config files, no service providers, no DI graph to declare up front.

## Why does Lift exist?

PHP has three kinds of frameworks today:

| Tier | Examples | Trade-off |
|---|---|---|
| **Full-stack** | Laravel, Symfony | Powerful but heavy — 50+ MB vendor, dozens of magic methods, opinionated structure. Overkill for an API. |
| **Minimal** | Slim, Flight, Leaf | Light, but you write the queue/JWT/crypto/migrations/validation yourself. |
| **Lifting** | **Lift** | Everything a real service needs, nothing it doesn't. |

Lift is **opinionated about what to include** (queues, JWT, crypto, migrations, validation, debug toolbar) and **unopinionated about how you use it** (no service providers, no required directory layout, no facades, no global state).

## What's in the box

The full feature list — every item below has its own doc page:

| Module | What it gives you |
|---|---|
| [Routing](routing) | Static O(1) + dynamic regex routes, named routes, groups, attribute routing |
| [Container](container) | PSR-11 DI with full autowiring, singletons, factories |
| [Middleware](middleware) | PSR-15 pipeline. Built-in CORS, rate-limit, CSRF, security headers |
| [Request](request) / [Response](response) | PSR-7 HTTP objects with ergonomic helpers and immutability done right |
| [Views](views) | Plain PHP templates with layouts, sections, partials |
| [Sessions](sessions) | File, database, Redis, Memcached drivers behind one interface |
| [Validation](validation) | 40+ built-in rules, custom validators, auto-422 |
| [Database](database) | Query builder, migrations, models, soft deletes, pagination, multi-connection |
| [Queues](queues) | Sync, Redis, AMQP, DB drivers + worker with graceful shutdown |
| [JWT](jwt) | HS256/RS256 encode/decode with claims helpers and a ready middleware |
| [Crypto](crypto) | AES-256-GCM, HMAC-SHA256, Argon2 password hashing |
| [Cache](cache) | PSR-16 cache with array and Redis backends |
| [Events](events) | PSR-14 dispatcher |
| [Logging](logging) | PSR-3 logger with file, stdout, rotating, syslog handlers |
| [JSON-RPC 2.0](json-rpc) | Spec-compliant server bound to any route |
| [Console](console) | CLI runner, generators (`make:controller`, …), queue worker |
| [HTTP client](http-client) | Retry-aware curl wrapper |
| [Debug toolbar](debug) | In-page request/SQL/timing inspector for dev |
| [Testing](testing) | `TestCase` + `TestResponse` with fluent assertions |
| [OpenAPI](openapi) | Generate OpenAPI 3.0 spec from `#[Route]` + `#[Param]` attributes |

## Performance

Lift's HTTP throughput on the same host, same PHP build, same handler:

| Framework  | `/ping` (req/s) | `/json` (req/s) | `/users/{id}` (req/s) |
|------------|----------------:|----------------:|----------------------:|
| raw PHP    | 4,838           | 4,362           | 4,426                 |
| **Lift**   | **2,913**       | **2,553**       | **2,323**             |
| Flight     | 2,521           | 1,720           | 1,660                 |
| Leaf       | 2,379           | 1,826           | 1,776                 |
| Slim 4     | 1,718           | 1,427           | 1,429                 |
| Lumen      | 1,210           |   926           |   777                 |

Reproducible — see [Benchmarks](comparison).

## Learning path

If you're new, read the docs in this order — each one builds on the previous:

1. **[Installation](installation)** — get a working `composer.json`.
2. **[Quick Start](quickstart)** — Hello World → REST API → controllers.
3. **[Routing](routing)** — verbs, params, groups, named routes, attribute routing.
4. **[Request](request) / [Response](response)** — read input, write output.
5. **[DI Container](container)** — autowiring, bindings, singletons.
6. **[Middleware](middleware)** — auth, logging, CORS.
7. **[Validation](validation)** — 40+ rules, error formatting.
8. **[Database](database)** — query builder, migrations, models.

Everything after that is *additive* — read what you need.

## Design principles

1. **PSRs all the way down.** PSR-3, PSR-7, PSR-11, PSR-14, PSR-15, PSR-16. Anything you write against Lift's interfaces works with any other PSR-compatible package.
2. **No globals, no magic statics.** Every service is resolved through the container; every Request/Response is an object you can pass around in tests.
3. **Fail loudly.** Misuse throws a `ContainerException` / `HttpException` with a precise message — no silent fallbacks.
4. **Zero runtime dependencies outside the PSR interfaces.** Less code = less attack surface, less to break on `composer update`, less to audit.
5. **Beginner-readable source.** ~10 000 lines, no `__call`/`__get` indirection, no facades. You can `git clone` Lift and read the whole thing in a weekend.

## License

MIT. Built and maintained by the community.

[Get started →](installation)
