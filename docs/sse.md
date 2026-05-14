---
layout: page
title: Server-Sent Events
nav_order: 14
---

# Server-Sent Events (SSE)

SSE is a simple "the server pushes, the browser listens" protocol on top of plain HTTP. The browser opens a `text/event-stream` connection, the server keeps it open and writes events as text. No WebSocket, no library — both PHP and the browser (`EventSource`) speak it natively.

Use it for: live notifications, log tailing, progress bars, dashboard counters, AI-style token streaming.

> Mental model: a long-running HTTP response that flushes new "event frames" instead of closing.

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

Browser side:

```js
const es = new EventSource('/stream/clock');
es.addEventListener('clock', (e) => {
    const data = JSON.parse(e.data);
    console.log('tick', data.tick, data.ts);
});
es.onerror = () => es.close();
```

That's it. You'll see five JSON objects logged, one per second, then the connection closes.

## The event frame

An SSE frame is just text:

```
event: clock
id: 42
retry: 3000
data: {"tick":1,"ts":"2025-..."}

```

Build one with the `SseEvent` builder:

```php
SseEvent::create('plain text data')
    ->event('tick')          // event: tick
    ->id('42')               // id: 42        — last-event-id, used for resume
    ->retry(3000)            // retry: 3000ms — reconnection delay hint
    ->encode();              // returns the wire string
```

Or the JSON shortcut:

```php
SseEvent::json(['x' => 1], event: 'state');
```

You pass an `SseEvent` to the emitter (`$emit(...)`), which encodes it, echoes it, and flushes.

## The emitter

`SseEmitter` is just a callable that:

1. Encodes an `SseEvent` to the wire format.
2. `echo`s it.
3. `flush()`es PHP's output buffer.
4. Records the frame (useful in tests — `$emitter->getSent()`).

You don't construct it yourself in production; `SseResponse` passes one in.

## Headers the response sets for you

```
Content-Type: text/event-stream
Cache-Control: no-cache
X-Accel-Buffering: no
```

The last header tells nginx to **not buffer** the response — without it nginx will sit on your events until the buffer fills, defeating the entire point.

## Killing buffering on the PHP side

`SseResponse::stream()` calls `ob_end_flush()` in a loop until no buffers are left. You generally don't need to do anything extra, but **never** leave a global `output_buffering=4096` in `php.ini` for SSE endpoints — flushes will queue up.

Also disable any compression middleware (gzip) for SSE — compressed streams force buffering. Either skip the middleware for `text/event-stream` responses, or detect `Accept: text/event-stream` and bypass.

## Reconnection & last-event-id

`EventSource` reconnects automatically. On reconnect it sends `Last-Event-ID: <previous-id>` — handle it:

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

Hint the browser how long to wait before reconnecting:

```php
$emit(SseEvent::json($payload)->retry(10_000));   // 10 seconds
```

## Periodic heartbeats

If a proxy is between you and the client and the stream is silent for ~60 s, the connection may be dropped. Send a comment line (`:` prefix, ignored by the browser) every 15 s:

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

For long-lived streams, prefer running the endpoint behind a single-process SAPI (RoadRunner / Swoole / FrankenPHP) — PHP-FPM ties up a worker for the entire stream duration.

## Detecting client disconnects

Loop without forever-spawning new events:

```php
new SseResponse(function (SseEmitter $emit) {
    ignore_user_abort(false);

    while (!connection_aborted()) {
        // … emit work …
        usleep(500_000);
    }
});
```

`connection_aborted()` returns 1 once the client closes; PHP also calls your `register_shutdown_function`s on the next echo/flush after disconnect.

## Streaming AI / token responses

A common modern use case — stream tokens from an upstream LLM API to your browser:

```php
$app->get('/stream/chat', function (Request $req) use ($llm) {
    return new SseResponse(function (SseEmitter $emit) use ($req, $llm) {
        foreach ($llm->stream($req->query('prompt')) as $token) {
            $emit(SseEvent::create($token));         // each token as `data:`
            if (connection_aborted()) return;
        }
        $emit(SseEvent::create('[DONE]')->event('done'));
    });
});
```

Frontend:

```js
const es = new EventSource('/stream/chat?prompt=hello');
es.onmessage = (e) => { if (e.data !== '[DONE]') document.body.append(e.data); };
es.addEventListener('done', () => es.close());
```

## Testing

Because `SseEmitter` records every frame, you can swap to a unit test without booting a server:

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

## When *not* to use SSE

- **Bidirectional traffic** (client sends frequent messages back) → use WebSocket.
- **Very high message rates** (hundreds/sec per connection) → binary protocols are leaner.
- **Behind PHP-FPM with limited workers** — each open SSE eats one worker. Use FrankenPHP / RoadRunner / a queue + JS polling.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Browser receives events in chunks of N seconds | nginx buffers `text/event-stream` | Add `proxy_buffering off;` for the SSE location, or rely on `X-Accel-Buffering: no` (set automatically by `SseResponse`). |
| `EventSource` reconnects forever, never receives | Endpoint returned 4xx/5xx | Hit the URL with `curl -v`; check for missing route, auth, etc. |
| Random disconnects on idle streams | Long-running connection killed by a proxy | Add periodic heartbeats (`: ping\n\n`). |
| Frame has `data: {"...` but the JSON is malformed | Multi-line payloads weren't escaped | `SseEvent` does the line-splitting for you — use it instead of writing frames manually. |
| Memory grows during a long stream | You accumulated data in PHP variables across iterations | Don't keep state in the closure; emit and forget. |
| Tests time out | The generator never returns | Cap loop iterations / `if ($emitter->getSent() === N) return;` in tests. |

## Cheat sheet

```php
use Lift\Http\{SseResponse, SseEvent, SseEmitter};

$app->get('/stream', function () {
    return new SseResponse(function (SseEmitter $emit) {
        $emit(SseEvent::json(['hello' => 'world']));
        $emit(SseEvent::create('plain text')->event('tick')->id('1'));
    });
});

// Frontend
const es = new EventSource('/stream');
es.onmessage = (e) => { … };
es.addEventListener('tick', (e) => { … });
```

[HTTP client →](http-client)
