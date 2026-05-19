---
layout: page
title: Server-Sent Events
nav_order: 14
---

# Server-Sent Events (SSE)

SSE — це простий протокол «сервер пушить, браузер слухає» поверх звичайного HTTP. Браузер відкриває з’єднання `text/event-stream`, сервер тримає його відкритим і пише події як текст. Жодного WebSocket, жодної бібліотеки — і PHP, і браузер (`EventSource`) розуміють його нативно.

Використовуйте для: живих сповіщень, читання хвоста логів, індикаторів прогресу, лічильників дашборда, потокової видачі токенів у стилі ШІ.

> Ментальна модель: довгоживуча HTTP-відповідь, яка скидає нові «кадри подій» замість закриття.

## Hello, SSE

```php
use Lift\Http\SseResponse;
use Lift\Http\SseEvent;
use Lift\Http\SseEmitter;

$app->get('/stream/clock', function () {
    return new SseResponse(function (SseEmitter $emit) {
        for ($i = 1; $i <= 5; $i++) {
            $emit(SseEvent::json(['tick' => $i, 'ts' => date('c')], 'clock'));
            sleep(1);
        }
    });
});
```

Сторона браузера:

```js
const es = new EventSource('/stream/clock');
es.addEventListener('clock', (e) => {
    const data = JSON.parse(e.data);
    console.log('tick', data.tick, data.ts);
});
es.onerror = () => es.close();
```

Ось і все. Ви побачите п’ять JSON-об’єктів у лозі, по одному за секунду, потім з’єднання закриється.

## Кадр події

Кадр SSE — це просто текст:

```
event: clock
id: 42
retry: 3000
data: {"tick":1,"ts":"2025-..."}

```

Побудуйте його білдером `SseEvent`:

```php
SseEvent::create('plain text data')
    ->event('tick')          // event: tick
    ->id('42')               // id: 42        — last-event-id, використовується для відновлення
    ->retry(3000)            // retry: 3000ms — підказка затримки перепідключення
    ->encode();              // повертає рядок для проводу
```

Або JSON-скорочення:

```php
SseEvent::json(['x' => 1], event: 'state');
```

Ви передаєте `SseEvent` емітеру (`$emit(...)`), який кодує його, виводить і скидає буфер.

## Емітер

`SseEmitter` — це просто callable, який:

1. Кодує `SseEvent` у провідний формат.
2. Робить `echo`.
3. Скидає буфер виводу PHP через `flush()`.
4. Записує кадр (корисно в тестах — `$emitter->getSent()`).

У продакшені ви не конструюєте його самі; `SseResponse` його передає.

## Заголовки, які відповідь задає за вас

```
Content-Type: text/event-stream
Cache-Control: no-cache
X-Accel-Buffering: no
```

Останній заголовок каже nginx **не буферизувати** відповідь — без нього nginx сидітиме на ваших подіях, доки буфер не заповниться, зводячи нанівець увесь сенс.

## Вимкнення буферизації на стороні PHP

`SseResponse::stream()` викликає `ob_end_flush()` у циклі, доки не залишиться буферів. Зазвичай вам не потрібно робити нічого додаткового, але **ніколи** не залишайте глобальний `output_buffering=4096` у `php.ini` для SSE-ендпоінтів — скиди ставитимуться в чергу.

Також вимкніть будь-який middleware стиснення (gzip) для SSE — стиснені потоки змушують буферизацію. Або пропускайте middleware для відповідей `text/event-stream`, або визначайте `Accept: text/event-stream` і обходьте його.

## Перепідключення і last-event-id

`EventSource` перепідключається автоматично. Під час перепідключення він шле `Last-Event-ID: <previous-id>` — обробіть його:

```php
$app->get('/stream/feed', function (Request $req) {
    $lastId = $req->getHeaderLine('Last-Event-ID');
    $startFrom = $lastId !== '' ? (int) $lastId : 0;

    return new SseResponse(function (SseEmitter $emit) use ($startFrom) {
        foreach (Repository::since($startFrom) as $event) {
            $emit(SseEvent::json($event->payload, 'feed')->id((string) $event->id));
        }
    });
});
```

Підкажіть браузеру, скільки чекати перед перепідключенням:

```php
$emit(SseEvent::json($payload)->retry(10_000));   // 10 секунд
```

## Періодичні heartbeat

Якщо між вами та клієнтом стоїть проксі, а потік мовчить ~60 с, з’єднання може бути розірване. Надсилайте рядок-коментар (префікс `:`, ігнорується браузером) кожні 15 с:

```php
new SseResponse(function (SseEmitter $emit) {
    $last = time();
    while (true) {
        if ($event = pollNewEvent()) {
            $emit(SseEvent::json($event));
            $last = time();
        }
        if (time() - $last >= 15) {
            echo ": ping\n\n"; flush();
            $last = time();
        }
        usleep(200_000);
    }
});
```

Для довгоживучих потоків віддавайте перевагу запуску ендпоінта за однопроцесним SAPI (RoadRunner / Swoole / FrankenPHP) — PHP-FPM займає воркер на всю тривалість потоку.

## Виявлення відключень клієнта

Зациклюйтеся, не породжуючи події вічно:

```php
new SseResponse(function (SseEmitter $emit) {
    ignore_user_abort(false);

    while (!connection_aborted()) {
        // … емітувати роботу …
        usleep(500_000);
    }
});
```

`connection_aborted()` повертає 1, щойно клієнт закривається; PHP також викликає ваші `register_shutdown_function` під час наступного echo/flush після відключення.

## Потокова видача ШІ / токенів

Поширений сучасний сценарій — стримінг токенів із вищележного LLM API у ваш браузер:

```php
$app->get('/stream/chat', function (Request $req) use ($llm) {
    return new SseResponse(function (SseEmitter $emit) use ($req, $llm) {
        foreach ($llm->stream($req->query('prompt')) as $token) {
            $emit(SseEvent::create($token));         // кожен токен як `data:`
            if (connection_aborted()) return;
        }
        $emit(SseEvent::create('[DONE]')->event('done'));
    });
});
```

Фронтенд:

```js
const es = new EventSource('/stream/chat?prompt=hello');
es.onmessage = (e) => { if (e.data !== '[DONE]') document.body.append(e.data); };
es.addEventListener('done', () => es.close());
```

## Тестування

Оскільки `SseEmitter` записує кожен кадр, ви можете перейти на юніт-тест без запуску сервера:

```php
public function testStreamEmitsThree(): void
{
    $emitter = new SseEmitter();
    $generator = function (SseEmitter $emit) {
        $emit(SseEvent::json(['n' => 1]));
        $emit(SseEvent::json(['n' => 2]));
        $emit(SseEvent::json(['n' => 3]));
    };

    ob_start(); $generator($emitter); ob_end_clean();

    self::assertCount(3, $emitter->getSent());
    self::assertStringContainsString('"n":2', $emitter->getSent()[1]);
}
```

## Коли *не* використовувати SSE

- **Двонапрямлений трафік** (клієнт часто шле повідомлення назад) → використовуйте WebSocket.
- **Дуже висока частота повідомлень** (сотні/с на з’єднання) → бінарні протоколи економніші.
- **За PHP-FPM з обмеженими воркерами** — кожен відкритий SSE з’їдає один воркер. Використовуйте FrankenPHP / RoadRunner / чергу + JS-полінг.

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| Браузер отримує події чанками по N секунд | nginx буферизує `text/event-stream` | Додайте `proxy_buffering off;` для SSE-локації або покладіться на `X-Accel-Buffering: no` (задається `SseResponse` автоматично). |
| `EventSource` перепідключається вічно, нічого не отримує | Ендпоінт повернув 4xx/5xx | Зверніться до URL через `curl -v`; перевірте відсутність маршруту, автентифікацію тощо. |
| Випадкові розриви на простоюваних потоках | Довгоживуче з’єднання вбито проксі | Додайте періодичні heartbeat (`: ping\n\n`). |
| У кадрі є `data: {"...`, але JSON некоректний | Багаторядкові корисні навантаження не екрановані | `SseEvent` робить розбиття за рядками за вас — використовуйте його замість ручного написання кадрів. |
| Пам’ять зростає під час довгого потоку | Ви накопичували дані у PHP-змінних між ітераціями | Не зберігайте стан у замиканні; емітуйте і забувайте. |
| Тести зависають за таймаутом | Генератор ніколи не повертається | Обмежте ітерації циклу / `if ($emitter->getSent() === N) return;` у тестах. |

## Шпаргалка

```php
use Lift\Http\{SseResponse, SseEvent, SseEmitter};

$app->get('/stream', function () {
    return new SseResponse(function (SseEmitter $emit) {
        $emit(SseEvent::json(['hello' => 'world']));
        $emit(SseEvent::create('plain text')->event('tick')->id('1'));
    });
});

// Фронтенд
const es = new EventSource('/stream');
es.onmessage = (e) => { … };
es.addEventListener('tick', (e) => { … });
```

[HTTP-клієнт →](http-client)
