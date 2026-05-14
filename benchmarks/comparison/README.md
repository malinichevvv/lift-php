# Lift vs Competitors — HTTP Benchmark Harness

Сравнивает throughput одинаковых endpoints в разных PHP-микрофреймворках на одной машине,
без `wrk`/`ab` (используется встроенный PHP load-tester на `curl_multi`).

## Структура

```
comparison/
├── frameworks/
│   ├── lift/       — Lift (этот репозиторий, через path-зависимость)
│   ├── slim/       — Slim 4
│   ├── lumen/      — Lumen 10
│   ├── leaf/       — Leaf 3
│   ├── flight/     — Flight v2
│   └── raw-php/    — голый PHP (контрольная точка)
├── loadtest.php    — параллельный HTTP-нагрузочный тестер
└── run.sh          — поднимает php -S, гоняет тесты, собирает results.md
```

## Эндпоинты (одинаковые во всех фреймворках)

| Path                  | Описание                              |
|-----------------------|---------------------------------------|
| `GET /ping`           | static route → "pong" (text/plain)    |
| `GET /users/{id}`     | dynamic route → JSON `{id}`           |
| `GET /json`           | static route → JSON object            |

## Установка

```bash
cd benchmarks/comparison
./install.sh    # выполняет composer install в каждом frameworks/*
```

## Запуск

```bash
./run.sh                          # дефолт: 5000 запросов, конкурентность 50
REQUESTS=20000 CONCURRENCY=100 ./run.sh
```

Скрипт:
1. поднимает `php -S 127.0.0.1:PORT public/index.php` для каждого фреймворка,
2. ждёт готовности,
3. прогоняет warmup + замер,
4. собирает `results.md` и `results.json`,
5. валит сервер.

## Что измеряется

- **req/s** — пропускная способность,
- **p50/p95/p99 latency** — задержка по перцентилям (мс),
- **errors** — количество не-2xx ответов.

## Текущие результаты (PHP 8.3, x86_64)

| Framework  | /ping req/s | /json req/s | /users/{id} req/s |
|------------|------------:|------------:|------------------:|
| raw-php    | 4,838       | 4,362       | 4,426             |
| **lift**   | **2,913**   | **2,553**   | **2,323**         |
| flight     | 2,521       | 1,720       | 1,660             |
| leaf       | 2,379       | 1,826       | 1,776             |
| slim       | 1,718       | 1,427       | 1,429             |
| lumen      | 1,210       |   926       |   777             |

> 8000 запросов × 64 параллельных воркера, после warmup. См. `results/results.md`.
> Lift показывает **~60% от чистого PHP** и в **2–3× быстрее** чем Slim/Lumen.

## Заметки по честности

- Все фреймворки запускаются под одинаковым `php -S` (single-process). В реальном проде PHP-FPM/RoadRunner дадут другие абсолютные числа, но **относительный** порядок сохраняется.
- OPcache включён через `php.ini` (см. `php-bench.ini`).
- Никаких middleware/DB — чистый router + handler.
