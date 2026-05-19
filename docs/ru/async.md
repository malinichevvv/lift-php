---
layout: page
title: Асинхронность (Fibers)
nav_order: 35
---

# Асинхронность (Fibers)

`Lift\Async\Concurrent` — это крошечный помощник кооперативной конкурентности, построенный на **Fibers PHP 8.1**. Он позволяет запускать несколько блокирующих вызовов ввода-вывода параллельно **в рамках одного процесса PHP**, без ext-amphp / ext-react / ext-swoole.

> Ментальная модель: `Fiber` — это функция, которая может приостановить себя (`suspend`) и возобновиться позже. `Concurrent::all([...])` запускает много задач как fibers, циклически переключает их, пока все не завершатся, и собирает результаты.

**Важно:** это **не** настоящий параллелизм — PHP всё ещё выполняет один оператор за раз. Fibers помогают, когда задачи **тратят бо́льшую часть времени в ожидании** ввода-вывода (HTTP-вызовы, запросы к БД, ожидания). Для CPU-нагруженной работы они не помогают; используйте пул воркеров очереди.

## Когда (и когда не) использовать

✅ **Хорошо подходит**

- Обратиться к 5 сторонним API и объединить их результаты — последовательно = 5×задержка, конкурентно ≈ 1×задержка.
- Пакетировать множество вызовов `curl_multi` за чистым интерфейсом.
- Предварительно прогревать кэши, выпуская несколько чтений сразу.
- Запустить несколько операций «по возможности» после записи, не блокируя ответ.

❌ **Плохо подходит**

- CPU-нагруженная работа (изменение размера изображений, парсинг) — fibers не дают больше ядер.
- Всё, что можно перенести в [очередь](queues) — асинхронность-в-запросе сложнее осмыслить, чем асинхронность-через-воркер.
- Долгоживущие потоки — используйте [Server-Sent Events](sse) или настоящий цикл событий.

## Пример за 30 секунд

```php
use Lift\Async\Concurrent;
use Lift\Http\HttpClient;

[$github, $weather, $stocks] = Concurrent::all([
    fn() => HttpClient::new()->get('https://api.github.com/repos/malinichevvv/lift-php')->json(),
    fn() => HttpClient::new()->get('https://api.weather.gov/...')->json(),
    fn() => HttpClient::new()->get('https://api.iexcloud.io/...')->json(),
]);

return Response::json([
    'github'  => $github,
    'weather' => $weather,
    'stocks'  => $stocks,
]);
```

Если каждый вызов занимает ~200 мс блокировки, последовательная версия занимает ~600 мс, а конкурентная — ~200 мс — *если* нижележащий клиент уступает управление во время ввода-вывода. Иначе это всё ещё эквивалентно `Concurrent::sequential(...)`. См. «Когда fibers действительно помогают» ниже.

## API

### `Concurrent::all(array $tasks): array`

Запускает каждый callable как fiber, циклически переключает, пока все не завершатся, возвращает результаты в том же порядке. Перебрасывает **первое** исключение от любой задачи.

```php
$results = Concurrent::all([
    'github'  => fn() => fetchGithub(),
    'weather' => fn() => fetchWeather(),
]);
// $results['github'], $results['weather']
```

Ключи сохраняются.

### `Concurrent::suspend(): void`

Внутри callable задачи уступите управление следующему fiber:

```php
Concurrent::all([
    function () {
        $data = openSocketRead();        // условно-блокирующее
        Concurrent::suspend();           // дать другим задачам выполниться
        $more = openSocketRead();
        return $data . $more;
    },
    function () { /* … */ },
]);
```

Вызов `suspend()` вне fiber — это no-op — безопасно использовать везде.

### `Concurrent::sequential(array $tasks): array`

Идентичная сигнатура `all()`, но выполняет задачи одну за другой. Полезно как замена для окружений, запрещающих fibers (например, PHP < 8.1 или наборы тестов, не терпящие особенностей жизненного цикла fiber):

```php
$tasks = [/* … */];
$results = $useFibers ? Concurrent::all($tasks) : Concurrent::sequential($tasks);
```

### `Concurrent::run(callable $task): mixed`

Оборачивает один callable в fiber и выполняет его до конца. В основном полезно для стресс-тестирования fiber-безопасности функции.

## Когда fibers действительно помогают

Fiber уступает управление, только когда **что-то внутри него вызывает `Fiber::suspend()`**. PHP не уступает автоматически во время нативного ввода-вывода. Так что:

| Библиотека / вызов                        | Авто-уступает? |
|-------------------------------------------|:--------------:|
| `curl`-вызов, обычный `file_get_contents` | ❌ блокирует весь процесс |
| `curl_multi_*` с ручным select            | ✅ если обернуть select в `suspend()` |
| Асинхронные клиенты `ReactPHP` / `amphp`  | ✅ — они интегрируются с планировщиком fiber |
| `sleep()` / `usleep()`                    | ❌ — блокируют |
| Явная уступка `Concurrent::suspend()`     | ✅             |

Короче: `Concurrent::all([HttpClient::new()->get(...), ...])` **не** ускоряет, потому что cURL блокирует весь процесс PHP. Используйте его с библиотеками, интегрирующимися с fibers PHP, или с `curl_multi_*`, где можно вкраплять `suspend()` между select.

Для реального ускорения с HTTP опуститесь до `curl_multi`:

```php
function parallelGet(array $urls): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $i => $url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
    } while ($running > 0);
    $out = [];
    foreach ($handles as $i => $ch) {
        $out[$i] = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

$pages = parallelGet([
    'https://api.example.com/a',
    'https://api.example.com/b',
    'https://api.example.com/c',
]);
```

`curl_multi_select($mh, 0.1)` — естественное место для `Concurrent::suspend()`, если хотите вплести это в бо́льшую группу fibers.

## Реальный паттерн — fan-out с таймаутом

```php
use Lift\Async\Concurrent;

$timeout = 2.0;
$start   = microtime(true);

$results = Concurrent::all([
    'inventory' => fn() => $this->safe(fn() => $this->inventory->lookup($sku), $timeout, $start),
    'pricing'   => fn() => $this->safe(fn() => $this->pricing->fetch($sku),   $timeout, $start),
    'reviews'   => fn() => $this->safe(fn() => $this->reviews->latest($sku),  $timeout, $start),
]);

return Response::json($results);

private function safe(callable $work, float $timeout, float $start): mixed
{
    if (microtime(true) - $start > $timeout) {
        return null;            // выйти — мы за дедлайном
    }
    try {
        return $work();
    } catch (\Throwable) {
        return null;            // по возможности: деградировать изящно
    }
}
```

Каждая задача отслеживает глобальный дедлайн и изящно выходит — ответ никогда не занимает дольше `~timeout` секунд, даже если один из вышестоящих сервисов медленный.

## Обработка ошибок

`Concurrent::all()` перебрасывает первое исключение от любой задачи **после** того, как все fibers завершатся. Если нужна изоляция ошибок на задачу, оберните каждый callable в собственный try/catch:

```php
$results = Concurrent::all([
    fn() => safelyCall(fn() => $a->fetch()),
    fn() => safelyCall(fn() => $b->fetch()),
]);

function safelyCall(callable $work): array
{
    try   { return ['ok' => true,  'value' => $work()]; }
    catch (\Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
}
```

Так выглядит большинство продакшен-кода — выводите отдельные неудачи наверх, никогда не давайте одной плохой задаче убить пакет.

## Тестирование

Fibers детерминированы, когда все задачи чисты. Обращайтесь с ними как с обычными функциями в тестах:

```php
public function testParallelFetchMergesResults(): void
{
    $service = new ProductPage(/* замоканные клиенты */);
    $result  = $service->show('SKU-1');

    self::assertSame('Widget', $result['name']);
    self::assertArrayHasKey('reviews', $result);
}
```

Избегайте утверждений «они действительно выполнялись параллельно» — это деталь реализации. Утверждайте *результат*.

## Ограничения

- **Нет цикла событий** — `Concurrent::all` — это цикл активного возобновления. Реальная асинхронность нуждается в ext-event / ReactPHP / amphp.
- **Глобали утекают между fibers.** `$_SERVER` PHP, обработчики ошибок и многие расширения не ожидают переключений fiber. Оставайтесь в чистых callable, не пересекайте границу fiber через сторонний код, который возится с глобальным состоянием.
- **Задачи разделяют запрос** — все они видят один и тот же `Request`, `Connection`, контейнер. Никакой изоляции. Следите за своими транзакциями.
- **Отмена** не поддерживается. Раз задача запущена, она выполняется до конца (или выбрасывает исключение). Используйте дедлайны внутри задачи.

Для более серьёзных асинхронных нагрузок берите ReactPHP, amphp, FrankenPHP или RoadRunner. `Concurrent` — это ответ в 50 строк для простого случая «разослать N HTTP-вызовов веером».

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| `Concurrent::all([...])` той же скорости, что последовательно | Задачи не уступают в точках ожидания ввода-вывода | Используйте библиотеки, интегрирующиеся с fibers PHP, или `curl_multi_*`. |
| `Fiber::suspend` вызван вне fiber | `suspend()` из не-fiber контекста | Помощник делает это no-op; проверьте, что вы внутри callable задачи. |
| Одна плохая задача выбросила исключение, а другие были отброшены | `all()` перебрасывает первую ошибку | Оберните каждую задачу в try/catch для изоляции на задачу. |
| Память растёт линейно с числом fibers | Вы создали N fibers, но никогда не дали им завершиться | Не создавайте тысячи fibers на запрос — пакетируйте группами. |
| Транзакции переплетаются странно | Два fiber разделяют одно соединение с БД | Не открывайте транзакцию внутри fiber, если только не открываете отдельное соединение на fiber. |
| Записи `$_SESSION` теряются | Обработчики сохранения сессий PHP не fiber-aware | Используйте [Сессии](sessions) (на основе драйверов) вместо этого. |

## Шпаргалка

```php
use Lift\Async\Concurrent;

// Fan-out
$results = Concurrent::all([
    'a' => fn() => callA(),
    'b' => fn() => callB(),
    'c' => fn() => callC(),
]);

// Уступить внутри задачи
Concurrent::suspend();

// Последовательный запасной вариант (окружения без fiber)
$results = Concurrent::sequential($tasks);

// Обёртка одного fiber
$value = Concurrent::run(fn() => doSomething());

// Семантика первой ошибки
try {
    Concurrent::all($tasks);
} catch (\Throwable $e) {
    // …первая выбросившая задача всплывает сюда
}
```

[UUID и ULID →](uuid)
