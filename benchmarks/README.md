# Lift Benchmarks

## Internal micro-benchmark

Measures key subsystems in isolation — no HTTP server, no network overhead.

```bash
# Default: 10 000 iterations per benchmark
php benchmarks/micro.php

# Custom iterations
php benchmarks/micro.php 50000
```

### Sample results (PHP 8.3, Apple M3 / AMD Ryzen 7)

| Subsystem                        | ops/s        | µs/op |
|----------------------------------|-------------|-------|
| Router: static route (O(1))      | ~530 000    | 1.9   |
| Router: dynamic route (regex)    | ~360 000    | 2.7   |
| Container: singleton make()      | ~23 000 000 | 0.04  |
| Container: autowire make()       | ~2 000 000  | 0.5   |
| JWT encode HS256                 | ~630 000    | 1.6   |
| JWT decode+verify HS256          | ~460 000    | 2.2   |
| AES-256-GCM encrypt              | ~625 000    | 1.6   |
| AES-256-GCM decrypt              | ~950 000    | 1.1   |
| HMAC-SHA256 sign                 | ~1 150 000  | 0.9   |
| UUID v4                          | ~1 080 000  | 0.9   |
| UUID v7                          | ~1 070 000  | 0.9   |
| ULID                             | ~670 000    | 1.5   |
| Response::json() serialize       | ~820 000    | 1.2   |

---

## HTTP load test (wrk / ab)

### Prerequisites

```bash
# wrk (recommended)
sudo apt install wrk   # Debian/Ubuntu
brew install wrk       # macOS

# or Apache Bench
sudo apt install apache2-utils
```

### Start the test server

```bash
php -S localhost:8080 benchmarks/apps/lift_app.php
```

### Static route

```bash
wrk -t4 -c100 -d10s http://localhost:8080/ping
```

### Dynamic route

```bash
wrk -t4 -c100 -d10s http://localhost:8080/users/42
```

### ab equivalent

```bash
ab -n 10000 -c 100 http://localhost:8080/ping
```

---

## Competitor comparison

Numbers are from publicly available benchmarks and representative community runs.
All values are **requests/second** under similar concurrency (100 connections, 4 threads, PHP-FPM + nginx or built-in server).

| Framework  | Static route (req/s) | Dynamic route (req/s) | Notes |
|------------|---------------------:|----------------------:|-------|
| **Lift**   | ~20 000 – 50 000*    | ~15 000 – 40 000*     | Static O(1) fast-path, reflection cache |
| Flight     | ~18 000 – 45 000     | ~14 000 – 35 000      | Single-file, minimal overhead |
| Slim 4     | ~12 000 – 25 000     | ~10 000 – 20 000      | PSR-7 compliant, FastRoute |
| Lumen      | ~8 000 – 18 000      | ~7 000 – 14 000       | Laravel-based, heavier bootstrap |
| Laravel    | ~3 000 – 7 000       | ~2 500 – 6 000        | Full-featured, DI container, service providers |

> \* Lift numbers measured on PHP 8.3 with OPcache enabled.
> External competitors measured with equivalent setup where possible.

### Feature comparison

| Feature                    | Lift | Flight | Slim 4 | Lumen | Laravel |
|----------------------------|:----:|:------:|:------:|:-----:|:-------:|
| PSR-7 compliant            | ✓    | ✗      | ✓      | ✓     | ✓       |
| PSR-11 DI container        | ✓    | ✗      | ✓      | ✓     | ✓       |
| PSR-15 middleware          | ✓    | ✗      | ✓      | ✗     | ✗       |
| PHP attributes routing     | ✓    | ✗      | ✗      | ✗     | ✓       |
| Static route O(1) lookup   | ✓    | ✗      | ✓*     | ✗     | ✗       |
| AES-256-GCM encryption     | ✓    | ✗      | ✗      | ✗     | ✓       |
| JWT built-in               | ✓    | ✗      | ✗      | ✗     | ✗       |
| UUID v4/v7 + ULID          | ✓    | ✗      | ✗      | ✗     | ✓       |
| Queue system               | ✓    | ✗      | ✗      | ✓     | ✓       |
| JSON-RPC 2.0               | ✓    | ✗      | ✗      | ✗     | ✗       |
| Redis client               | ✓    | ✗      | ✗      | ✓     | ✓       |
| Rate limiting middleware    | ✓    | ✗      | ✗      | ✗     | ✓       |
| CORS middleware             | ✓    | ✗      | ✗      | ✗     | ✓       |
| CSRF protection             | ✓    | ✗      | ✗      | ✗     | ✓       |
| Zero non-PSR runtime deps  | ✓    | ✓      | ✗      | ✗     | ✗       |

> \* Slim 4 uses FastRoute which has a static dispatch table but requires an additional package.

---

## OPcache tips

For best performance in production:

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0   ; disable in production
opcache.save_comments=1         ; required for PHP attributes
```
