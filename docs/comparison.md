---
layout: page
title: Framework Comparison
nav_order: 38
---

# Framework Comparison

## Direct competitors

Lift targets the same space as Flight PHP, Slim 4, and Lumen — lightweight frameworks for building APIs and microservices without the overhead of a full-stack solution.

---

## Feature matrix

| Feature | **Lift** | Flight | Slim 4 | Lumen | Silex¹ | Mezzio |
|---------|:--------:|:------:|:------:|:-----:|:------:|:------:|
| PHP version | 8.1+ | 7.0+ | 7.4+ | 8.1+ | 7.0+ | 8.1+ |
| PSR-7 HTTP | ✓ | ✗² | ✓ | ✓ | ✓ | ✓ |
| PSR-11 Container | ✓ | ✗ | ✓ | ✓ | ✓ | ✓ |
| PSR-15 Middleware | ✓ | ✗ | ✓ | ✗ | ✗ | ✓ |
| Autowiring DI | ✓ | ✗ | ✗³ | ✓ | ✗ | ✗ |
| PHP attribute routing | ✓ | ✗ | ✗ | ✗ | ✗ | ✓⁴ |
| Static route O(1) lookup | ✓ | ✗ | ✓⁵ | ✗ | ✗ | ✓⁵ |
| Named routes + URL generation | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Route groups | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| JWT built-in | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| AES-256-GCM encryption | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Password hashing (Argon2id) | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| HMAC signing | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| UUID v4/v7 + ULID | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Queue system | ✓ | ✗ | ✗ | ✓ | ✗ | ✗ |
| Redis client | ✓ | ✗ | ✗ | ✓ | ✗ | ✗ |
| JSON-RPC 2.0 | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| CORS middleware | ✓ | ✗ | ✓⁶ | ✓⁶ | ✗ | ✓⁶ |
| Rate limiting middleware | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| CSRF protection | ✓ | ✗ | ✗ | ✓⁷ | ✗ | ✗ |
| Security headers middleware | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Zero non-PSR runtime deps | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ |

> ¹ Silex is EOL (2019). Listed for historical context only.  
> ² Flight uses its own HTTP objects, not PSR-7.  
> ³ Slim 4 ships `SlimContainerResolver` but doesn't autowire; requires explicit bindings.  
> ⁴ Mezzio supports attributes via `mezzio/mezzio-attributerouter` package.  
> ⁵ Via FastRoute (separate `nikic/fast-route` dependency).  
> ⁶ Via `tuupola/cors-middleware` or similar package (separate dependency).  
> ⁷ Via Laravel session helpers (Lumen only).

---

## Performance

Numbers are from internal benchmarks (`benchmarks/micro.php`) and community-published results. All measured at 100 concurrent connections, PHP 8.3, OPcache enabled.

### Internal micro-benchmark (Lift, PHP 8.3)

| Operation | ops/s | µs/op |
|-----------|------:|------:|
| Static route dispatch | ~534 000 | 1.9 |
| Dynamic route dispatch | ~364 000 | 2.7 |
| Container singleton | ~23 500 000 | 0.04 |
| Container autowire | ~2 000 000 | 0.5 |
| JWT encode (HS256) | ~628 000 | 1.6 |
| JWT decode + verify | ~460 000 | 2.2 |
| AES-256-GCM encrypt | ~626 000 | 1.6 |
| UUID v7 | ~1 068 000 | 0.9 |
| `Response::json()` | ~822 000 | 1.2 |

### HTTP throughput comparison (req/s, wrk, 100c/4t, 10s)

| Framework | Static route | Dynamic route | Notes |
|-----------|------------:|---------------:|-------|
| **Lift** | ~20 000 – 50 000 | ~15 000 – 40 000 | Static O(1), reflection cache |
| Flight | ~18 000 – 45 000 | ~14 000 – 35 000 | Minimal, no PSR overhead |
| Slim 4 | ~12 000 – 25 000 | ~10 000 – 20 000 | PSR-7, FastRoute |
| Lumen | ~8 000 – 18 000 | ~7 000 – 14 000 | Laravel IOC, service providers |
| Laravel | ~3 000 – 7 000 | ~2 500 – 6 000 | Full framework |

> Ranges reflect variability across hardware (local Mac vs CI vs production VPS). Run `benchmarks/micro.php` in your environment for your numbers.

---

## Why not Slim 4?

Slim 4 is excellent but requires `nikic/fast-route` for competitive routing performance and `php-di/php-di` (or similar) for autowiring — adding dependencies. Lift ships both out of the box with zero extra packages, and adds JWT, crypto, UUID, queues, and JSON-RPC that Slim leaves to third-party libraries.

## Why not Lumen?

Lumen uses the full Laravel IoC container and service-provider bootstrap, which adds ~5–10ms of cold-start overhead. If you're already on Laravel, Lumen is the natural fit. If you're starting fresh or optimising for microservices, Lift starts in under 1ms.

## Why not Flight?

Flight is fast and genuinely zero-dependency, but it predates PSR standards and cannot use the modern ecosystem of PSR-7/15 middleware. Lift gives you the same minimal footprint with full PSR compliance, modern PHP 8.1+ idioms, and a complete crypto/security toolkit.

---

## Running your own benchmark

```bash
# Install wrk
brew install wrk   # macOS
sudo apt install wrk  # Ubuntu

# Start the Lift app
php -S localhost:8080 benchmarks/apps/lift_app.php

# Static route
wrk -t4 -c100 -d10s http://localhost:8080/ping

# Dynamic route
wrk -t4 -c100 -d10s http://localhost:8080/users/42
```

See [`benchmarks/README.md`](https://github.com/malinichevvv/lift-web/tree/main/benchmarks) for the full guide.
