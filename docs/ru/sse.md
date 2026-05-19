---
layout: page
title: Server-Sent Events
nav_order: 14
---

# Server-Sent Events (SSE)

SSE — это простой протокол «сервер пушит, браузер слушает» поверх обычного HTTP. Браузер открывает соединение `text/event-stream`, сервер держит его открытым и пишет события как текст. Никакого WebSocket, никакой библиотеки — и PHP, и браузер (`EventSource`) понимают его нативно.

Используйте для: живых уведомлений, чтения хвоста логов, индикаторов прогресса, счётчиков дашборда, потоковой выдачи токенов в стиле ИИ.

> Ментальная модель: долгоживущий HTTP-ответ, который сбрасывает новые «кадры событий» вместо закрытия.

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

Вот и всё. Вы увидите пять JSON-объектов в логе, по одному в секунду, затем соединение закроется.

## Кадр события

Кадр SSE — это просто текст:

```
event: clock
id: 42
retry: 3000
data: {"tick":1,"ts":"2025-..."}

```

Постройте его билдером `SseEvent`:

```php
SseEvent::create('plain text data')
    ->event('tick')          // event: tick
    ->id('42')               // id: 42        — last-event-id, используется для возобновления
    ->retry(3000)            // retry: 3000ms — подсказка задержки переподключения
    ->encode();              // возвращает строку для провода
```

Или JSON-сокращение:

```php
SseEvent::json(['x' => 1], event: 'state');
```

Вы передаёте `SseEvent` эмиттеру (`$emit(...)`), который кодирует его, выводит и сбрасывает буфер.

## Эмиттер

`SseEmitter` — это просто callable, который:

1. Кодирует `SseEvent` в проводной формат.
2. Делает `echo`.
3. Сбрасывает буфер вывода PHP через `flush()`.
4. Записывает кадр (полезно в тестах — `$emitter->getSent()`).

В продакшене вы не конструируете его сами; `SseResponse` его передаёт.

## Заголовки, которые ответ задаёт за вас

```
Content-Type: text/event-stream
Cache-Control: no-cache
X-Accel-Buffering: no
```

Последний заголовок говорит nginx **не буферизировать** ответ — без него nginx будет сидеть на ваших событиях, пока буфер не заполнится, сводя на нет весь смысл.

## Отключение буферизации на стороне PHP

`SseResponse::stream()` вызывает `ob_end_flush()` в цикле, пока не останется буферов. Обычно вам не нужно делать ничего дополнительного, но **никогда** не оставляйте глобальный `output_buffering=4096` в `php.ini` для SSE-эндпоинтов — сбросы будут ставиться в очередь.

Также отключите любой middleware сжатия (gzip) для SSE — сжатые потоки вынуждают буферизацию. Либо пропускайте middleware для ответов `text/event-stream`, либо определяйте `Accept: text/event-stream` и обходите его.

## Переподключение и last-event-id

`EventSource` переподключается автоматически. При переподключении он шлёт `Last-Event-ID: <previous-id>` — обработайте его:

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

Подскажите браузеру, сколько ждать перед переподключением:

```php
$emit(SseEvent::json($payload)->retry(10_000));   // 10 секунд
```

## Периодические heartbeat

Если между вами и клиентом стоит прокси, а поток молчит ~60 с, соединение может быть разорвано. Отправляйте строку-комментарий (префикс `:`, игнорируется браузером) каждые 15 с:

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

Для долгоживущих потоков предпочитайте запуск эндпоинта за однопроцессным SAPI (RoadRunner / Swoole / FrankenPHP) — PHP-FPM занимает воркер на всю длительность потока.

## Обнаружение отключений клиента

Зацикливайтесь, не порождая события вечно:

```php
new SseResponse(function (SseEmitter $emit) {
    ignore_user_abort(false);

    while (!connection_aborted()) {
        // … эмитировать работу …
        usleep(500_000);
    }
});
```

`connection_aborted()` возвращает 1, как только клиент закрывается; PHP также вызывает ваши `register_shutdown_function` при следующем echo/flush после отключения.

## Потоковая выдача ИИ / токенов

Распространённый современный сценарий — стриминг токенов из вышестоящего LLM API в ваш браузер:

```php
$app->get('/stream/chat', function (Request $req) use ($llm) {
    return new SseResponse(function (SseEmitter $emit) use ($req, $llm) {
        foreach ($llm->stream($req->query('prompt')) as $token) {
            $emit(SseEvent::create($token));         // каждый токен как `data:`
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

## Тестирование

Поскольку `SseEmitter` записывает каждый кадр, вы можете перейти на юнит-тест без запуска сервера:

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

## Когда *не* использовать SSE

- **Двунаправленный трафик** (клиент часто шлёт сообщения обратно) → используйте WebSocket.
- **Очень высокая частота сообщений** (сотни/с на соединение) → бинарные протоколы экономнее.
- **За PHP-FPM с ограниченными воркерами** — каждый открытый SSE съедает один воркер. Используйте FrankenPHP / RoadRunner / очередь + JS-поллинг.

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| Браузер получает события чанками по N секунд | nginx буферизирует `text/event-stream` | Добавьте `proxy_buffering off;` для SSE-локации или положитесь на `X-Accel-Buffering: no` (задаётся `SseResponse` автоматически). |
| `EventSource` переподключается вечно, ничего не получает | Эндпоинт вернул 4xx/5xx | Обратитесь к URL через `curl -v`; проверьте отсутствие маршрута, аутентификацию и т. д. |
| Случайные разрывы на простаивающих потоках | Долгоживущее соединение убито прокси | Добавьте периодические heartbeat (`: ping\n\n`). |
| В кадре есть `data: {"...`, но JSON некорректен | Многострочные полезные нагрузки не экранированы | `SseEvent` делает разбиение по строкам за вас — используйте его вместо ручного написания кадров. |
| Память растёт во время длинного потока | Вы накапливали данные в PHP-переменных между итерациями | Не храните состояние в замыкании; эмитируйте и забывайте. |
| Тесты зависают по таймауту | Генератор никогда не возвращается | Ограничьте итерации цикла / `if ($emitter->getSent() === N) return;` в тестах. |

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

[HTTP-клиент →](http-client)
