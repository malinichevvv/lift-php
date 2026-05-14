# Benchmark Results

**Date:** 2026-05-14 12:42:46 UTC  
**PHP:** 8.3.6  
**Host:** vlad / x86_64  
**Tester:** built-in `php -S` + `loadtest.php` (curl_multi)

Each row is the result of N requests with C concurrent workers, after warmup.

## `GET /ping`

| # | Framework | req/s | rel | avg ms | p50 | p95 | p99 | errors |
|---|-----------|------:|----:|-------:|----:|----:|----:|-------:|
| 1 | raw-php | 4,837.5 | 1.00x | 12.93 | 12.76 | 18.79 | 19.54 | 0 |
| 2 | **lift** | 2,913.3 | 0.60x | 21.67 | 20.95 | 33.57 | 38.49 | 0 |
| 3 | flight | 2,520.5 | 0.52x | 25.12 | 25.78 | 33.45 | 41.09 | 0 |
| 4 | leaf | 2,378.5 | 0.49x | 26.61 | 26.46 | 37.46 | 44.86 | 0 |
| 5 | slim | 1,717.5 | 0.36x | 36.96 | 35.59 | 58.48 | 71.60 | 0 |
| 6 | lumen | 1,210.0 | 0.25x | 52.63 | 49.91 | 76.39 | 91.93 | 0 |

## `GET /json`

| # | Framework | req/s | rel | avg ms | p50 | p95 | p99 | errors |
|---|-----------|------:|----:|-------:|----:|----:|----:|-------:|
| 1 | raw-php | 4,361.7 | 1.00x | 14.36 | 14.20 | 19.54 | 22.94 | 0 |
| 2 | **lift** | 2,553.0 | 0.59x | 24.73 | 24.30 | 33.29 | 48.41 | 0 |
| 3 | leaf | 1,826.4 | 0.42x | 34.72 | 34.12 | 51.50 | 58.66 | 0 |
| 4 | flight | 1,720.1 | 0.39x | 36.90 | 35.87 | 53.20 | 74.33 | 0 |
| 5 | slim | 1,426.9 | 0.33x | 44.55 | 43.77 | 57.50 | 61.75 | 0 |
| 6 | lumen | 925.6 | 0.21x | 68.85 | 68.63 | 94.97 | 104.15 | 0 |

## `GET /users/42`

| # | Framework | req/s | rel | avg ms | p50 | p95 | p99 | errors |
|---|-----------|------:|----:|-------:|----:|----:|----:|-------:|
| 1 | raw-php | 4,426.2 | 1.00x | 14.15 | 13.79 | 19.08 | 21.26 | 0 |
| 2 | **lift** | 2,323.3 | 0.52x | 27.19 | 27.18 | 36.82 | 41.98 | 0 |
| 3 | leaf | 1,775.8 | 0.40x | 35.72 | 34.77 | 52.93 | 58.55 | 0 |
| 4 | flight | 1,660.0 | 0.38x | 38.24 | 36.81 | 52.14 | 61.00 | 0 |
| 5 | slim | 1,428.9 | 0.32x | 44.46 | 43.58 | 63.04 | 66.77 | 0 |
| 6 | lumen | 777.2 | 0.18x | 82.03 | 79.44 | 113.35 | 124.22 | 0 |

---

## Methodology

- Single PHP process per framework (`php -S`), OPcache + JIT enabled (`php-bench.ini`).
- Identical handlers across frameworks: static `/ping` (text), static `/json` (5-item JSON), dynamic `/users/{id}` (JSON).
- Warmup excluded from latency stats.
- Numbers are **relative within this run** — absolute throughput depends heavily on host CPU and PHP build.
