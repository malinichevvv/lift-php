---
layout: page
title: Асинхронність (Fibers)
nav_order: 35
---

# Асинхронність (Fibers)

`Lift\Async\Concurrent` — це крихітний помічник кооперативної конкурентності, побудований на **Fibers PHP 8.1**. Він дозволяє запускати кілька блокувальних викликів вводу-виводу паралельно **в межах одного процесу PHP**, без ext-amphp / ext-react / ext-swoole.

> Ментальна модель: `Fiber` — це функція, яка може призупинити себе (`suspend`) і відновитися пізніше. `Concurrent::all([...])` запускає багато задач як fibers, циклічно перемикає їх, доки всі не завершаться, і збирає результати.

**Важливо:** це **не** справжній паралелізм — PHP усе ще виконує один оператор за раз. Fibers допомагають, коли задачі **витрачають більшу частину часу в очікуванні** вводу-виводу (HTTP-виклики, запити до БД, очікування). Для CPU-навантаженої роботи вони не допомагають; використовуйте пул воркерів черги.

## Коли (і коли ні) використовувати

✅ **Добре пасує**

- Звернутися до 5 сторонніх API й об’єднати їхні результати — послідовно = 5×затримка, конкурентно ≈ 1×затримка.
- Пакетувати безліч викликів `curl_multi` за чистим інтерфейсом.
- Попередньо прогрівати кеші, випускаючи кілька читань одразу.
- Запустити кілька операцій «за можливості» після запису, не блокуючи відповідь.

❌ **Погано пасує**

- CPU-навантажена робота (зміна розміру зображень, парсинг) — fibers не дають більше ядер.
- Усе, що можна перенести в [чергу](queues) — асинхронність-у-запиті складніше осмислити, ніж асинхронність-через-воркер.
- Довгоживучі потоки — використовуйте [Server-Sent Events](sse) або справжній цикл подій.

## Приклад за 30 секунд

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

Якщо кожен виклик займає ~200 мс блокування, послідовна версія займає ~600 мс, а конкурентна — ~200 мс — *якщо* нижчележний клієнт поступається керуванням під час вводу-виводу. Інакше це все ще еквівалентно `Concurrent::sequential(...)`. Див. «Коли fibers справді допомагають» нижче.

## API

### `Concurrent::all(array $tasks): array`

Запускає кожен callable як fiber, циклічно перемикає, доки всі не завершаться, повертає результати в тому самому порядку. Перекидає **перший** виняток від будь-якої задачі.

```php
$results = Concurrent::all([
    'github'  => fn() => fetchGithub(),
    'weather' => fn() => fetchWeather(),
]);
// $results['github'], $results['weather']
```

Ключі зберігаються.

### `Concurrent::suspend(): void`

Усередині callable задачі поступіться керуванням наступному fiber:

```php
Concurrent::all([
    function () {
        $data = openSocketRead();        // умовно-блокувальне
        Concurrent::suspend();           // дати іншим задачам виконатися
        $more = openSocketRead();
        return $data . $more;
    },
    function () { /* … */ },
]);
```

Виклик `suspend()` поза fiber — це no-op — безпечно використовувати всюди.

### `Concurrent::sequential(array $tasks): array`

Ідентична сигнатура `all()`, але виконує задачі одну за одною. Корисно як заміна для оточень, що забороняють fibers (наприклад, PHP < 8.1 чи набори тестів, що не терплять особливостей життєвого циклу fiber):

```php
$tasks = [/* … */];
$results = $useFibers ? Concurrent::all($tasks) : Concurrent::sequential($tasks);
```

### `Concurrent::run(callable $task): mixed`

Загортає один callable у fiber і виконує його до кінця. Здебільшого корисно для стрес-тестування fiber-безпеки функції.

## Коли fibers справді допомагають

Fiber поступається керуванням, лише коли **щось усередині нього викликає `Fiber::suspend()`**. PHP не поступається автоматично під час нативного вводу-виводу. Тож:

| Бібліотека / виклик                       | Авто-поступається? |
|-------------------------------------------|:------------------:|
| `curl`-виклик, звичайний `file_get_contents` | ❌ блокує весь процес |
| `curl_multi_*` із ручним select           | ✅ якщо обернути select у `suspend()` |
| Асинхронні клієнти `ReactPHP` / `amphp`   | ✅ — вони інтегруються з планувальником fiber |
| `sleep()` / `usleep()`                    | ❌ — блокують |
| Явна поступка `Concurrent::suspend()`     | ✅                 |

Коротко: `Concurrent::all([HttpClient::new()->get(...), ...])` **не** пришвидшує, бо cURL блокує весь процес PHP. Використовуйте його з бібліотеками, що інтегруються з fibers PHP, або з `curl_multi_*`, де можна вкраплювати `suspend()` між select.

Для реального пришвидшення з HTTP опустіться до `curl_multi`:

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

`curl_multi_select($mh, 0.1)` — природне місце для `Concurrent::suspend()`, якщо хочете вплести це в більшу групу fibers.

## Реальний патерн — fan-out з таймаутом

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
        return null;            // вийти — ми за дедлайном
    }
    try {
        return $work();
    } catch (\Throwable) {
        return null;            // за можливості: деградувати витончено
    }
}
```

Кожна задача відстежує глобальний дедлайн і витончено виходить — відповідь ніколи не займає довше `~timeout` секунд, навіть якщо один із вищележних сервісів повільний.

## Обробка помилок

`Concurrent::all()` перекидає перший виняток від будь-якої задачі **після** того, як усі fibers завершаться. Якщо потрібна ізоляція помилок на задачу, загорніть кожен callable у власний try/catch:

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

Так виглядає більшість продакшен-коду — виводьте окремі невдачі нагору, ніколи не давайте одній поганій задачі вбити пакет.

## Тестування

Fibers детерміновані, коли всі задачі чисті. Поводьтеся з ними як зі звичайними функціями в тестах:

```php
public function testParallelFetchMergesResults(): void
{
    $service = new ProductPage(/* замокані клієнти */);
    $result  = $service->show('SKU-1');

    self::assertSame('Widget', $result['name']);
    self::assertArrayHasKey('reviews', $result);
}
```

Уникайте тверджень «вони справді виконувалися паралельно» — це деталь реалізації. Стверджуйте *результат*.

## Обмеження

- **Немає циклу подій** — `Concurrent::all` — це цикл активного відновлення. Реальна асинхронність потребує ext-event / ReactPHP / amphp.
- **Глобалі витікають між fibers.** `$_SERVER` PHP, обробники помилок і багато розширень не очікують перемикань fiber. Залишайтеся в чистих callable, не перетинайте межу fiber через сторонній код, що вовтузиться з глобальним станом.
- **Задачі розділяють запит** — усі вони бачать той самий `Request`, `Connection`, контейнер. Жодної ізоляції. Стежте за своїми транзакціями.
- **Скасування** не підтримується. Щойно задача запущена, вона виконується до кінця (або викидає виняток). Використовуйте дедлайни всередині задачі.

Для серйозніших асинхронних навантажень беріть ReactPHP, amphp, FrankenPHP або RoadRunner. `Concurrent` — це відповідь у 50 рядків для простого випадку «розіслати N HTTP-викликів віялом».

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| `Concurrent::all([...])` тієї самої швидкості, що послідовно | Задачі не поступаються в точках очікування вводу-виводу | Використовуйте бібліотеки, що інтегруються з fibers PHP, або `curl_multi_*`. |
| `Fiber::suspend` викликано поза fiber | `suspend()` із не-fiber контексту | Помічник робить це no-op; перевірте, що ви всередині callable задачі. |
| Одна погана задача викинула виняток, а інші були відкинуті | `all()` перекидає першу помилку | Загорніть кожну задачу у try/catch для ізоляції на задачу. |
| Пам’ять зростає лінійно з кількістю fibers | Ви створили N fibers, але ніколи не дали їм завершитися | Не створюйте тисячі fibers на запит — пакетуйте групами. |
| Транзакції переплітаються дивно | Два fiber розділяють одне з’єднання з БД | Не відкривайте транзакцію всередині fiber, якщо тільки не відкриваєте окреме з’єднання на fiber. |
| Записи `$_SESSION` втрачаються | Обробники збереження сесій PHP не fiber-aware | Використовуйте [Сесії](sessions) (на основі драйверів) замість цього. |

## Шпаргалка

```php
use Lift\Async\Concurrent;

// Fan-out
$results = Concurrent::all([
    'a' => fn() => callA(),
    'b' => fn() => callB(),
    'c' => fn() => callC(),
]);

// Поступитися всередині задачі
Concurrent::suspend();

// Послідовний запасний варіант (оточення без fiber)
$results = Concurrent::sequential($tasks);

// Обгортка одного fiber
$value = Concurrent::run(fn() => doSomething());

// Семантика першої помилки
try {
    Concurrent::all($tasks);
} catch (\Throwable $e) {
    // …перша задача, що викинула, спливає сюди
}
```

[UUID та ULID →](uuid)
