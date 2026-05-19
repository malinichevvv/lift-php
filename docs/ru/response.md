---
layout: page
title: Response
nav_order: 8
---

# Response

`Lift\Http\Response` — это неизменяемый объект HTTP-ответа. Он реализует `Psr\Http\Message\ResponseInterface` и предоставляет фабричные методы для частых случаев (JSON, HTML, текст, редирект, без содержимого), а также помощники для cookie и текучий билдер.

> Ментальная модель: соберите `Response`, верните его из обработчика — Lift отправит его клиенту. Как и `Request`, он неизменяем — каждый метод `with*()` возвращает **новый** экземпляр.

## Кратчайший возможный ответ

```php
$app->get('/', fn() => Response::json(['hello' => 'world']));
```

Вот и всё. Если вам не нужно задавать собственные заголовки или коды состояния, фабричные методы — самый чистый API.

## Фабричные методы

### `Response::json($data, $status = 200, $flags = ...)`

Отправляет массив/объект как JSON с `Content-Type: application/json; charset=utf-8`.

```php
Response::json(['status' => 'ok']);              // 200 OK
Response::json(['error' => 'Conflict'], 409);    // собственный статус
Response::json($data, 200, JSON_PRETTY_PRINT);   // собственные флаги кодирования
```

Флаги по умолчанию включают `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` (они почти всегда вам нужны). Ошибки кодирования выбрасывают `JsonException` — никогда не дают молча испорченный вывод.

### `Response::html($content, $status = 200)`

```php
Response::html('<h1>Hello</h1>');
Response::html($view->render('home'), 200);
```

`Content-Type: text/html; charset=utf-8` устанавливается автоматически.

### `Response::text($content, $status = 200)`

```php
Response::text('pong');
Response::text("Hello, $name", 200);
```

`Content-Type: text/plain; charset=utf-8`.

### `Response::redirect($url, $status = 302, $headers = [])`

```php
Response::redirect('/login');                    // 302 Found
Response::redirect('/new-home', 301);            // 301 Moved Permanently
Response::redirect('/after-post', 303);          // 303 See Other  (паттерн POST → GET)
Response::redirect('/short-cache', 307);         // 307 Temporary Redirect (сохраняет метод)
Response::redirect('/forever', 308);             // 308 Permanent Redirect (сохраняет метод)
```

Третий аргумент `$headers` подмешивает дополнительные заголовки в ответ-редирект:

```php
// Редирект и очистка cookie за один раз
Response::redirect('/login', 302, ['Clear-Site-Data' => '"cookies"']);

// Редирект с собственным управлением кэшем
Response::redirect('/new-home', 301, ['Cache-Control' => 'no-store']);
```

Можно также сцепить `->withHeader(...)` / `->withCookie(...)` на результате:

```php
return Response::redirect('/dashboard')
    ->withCookie('flash', 'Welcome back!');
```

### `Response::noContent()`

```php
return Response::noContent();   // 204, пустое тело
```

Используйте, когда DELETE / PUT и т. п. успешны, но возвращать нечего.

## Текучий билдер (в стиле PSR-7)

Для всего, что не покрывают фабрики, используйте цепочки `with*()`. **Каждый вызов возвращает новый экземпляр**:

```php
return (new Response())
    ->withStatus(201)
    ->withHeader('Location', '/users/42')
    ->withHeader('X-Request-Id', $id)
    ->withJson(['id' => 42]);          // задаёт тело + Content-Type, сохраняет статус
    // ->withJson(['id' => 42], 201);  // необязательный второй аргумент переопределяет код статуса
```

Тонкий, но частый баг:

```php
// ❌ НЕПРАВИЛЬНО — withHeader возвращает новый объект; этот код его выбрасывает.
$res = Response::json($data);
$res->withHeader('X-Custom', 'value');
return $res;

// ✅ ПРАВИЛЬНО
$res = Response::json($data)->withHeader('X-Custom', 'value');
return $res;
```

## Автоматическое преобразование

Если обработчик возвращает что-то, что не является `Response`, Lift преобразует это за вас:

| Возвращаемое значение | Что вы получаете обратно        |
|-----------------------|---------------------------------|
| `Response`            | передаётся без изменений        |
| `array`, `object`     | `Response::json(...)`           |
| `string`              | `Response::html(...)`           |
| `null`                | `Response::noContent()` (204)   |
| что угодно иное       | `Response::text((string) $v)`   |

Так что эти два обработчика идентичны:

```php
fn() => ['ok' => true]
fn() => Response::json(['ok' => true])
```

Выбирайте то, что читается лучше. Подсказка: явный `Response::json(...)` блистает всякий раз, когда вам также нужен код статуса или заголовок — они всё равно вынуждают использовать `Response`.

## Cookie

Ответ Lift несёт первоклассные помощники для cookie — вам не нужен `setcookie()` PHP.

```php
return Response::json($user)
    ->withCookie('remember_token', $token, [
        'max_age'   => 86400 * 30,   // 30 дней
        'http_only' => true,         // по умолчанию true
        'same_site' => 'Lax',        // по умолчанию 'Lax'
        'secure'    => true,         // отправлять только по HTTPS
        'path'      => '/',          // по умолчанию '/'
        'domain'    => 'example.com',// необязательно
    ]);
```

Быстро удалить cookie:

```php
return Response::noContent()->withoutCookie('remember_token');
```

> Прочитайте значение на следующем запросе через [`$req->cookie('remember_token')`](request#cookies).

### Справочник опций cookie

| Ключ        | Тип    | По умолчанию | Эффект |
|-------------|--------|--------------|--------|
| `max_age`   | int    | —            | `Max-Age=N` секунд. Рекомендуется вместо `expires`. |
| `expires`   | int    | —            | Unix-метка времени. Игнорируется, когда задан `max_age`. |
| `path`      | string | `/`          | Префикс URL, к которому применяется cookie. |
| `domain`    | string | —            | Домен cookie (контроль поддоменов). |
| `secure`    | bool   | `false`      | Добавляет флаг `Secure` (только HTTPS). |
| `http_only` | bool   | `true`       | Добавляет флаг `HttpOnly` (без доступа из JS). |
| `same_site` | string | `Lax`        | `Strict` / `Lax` / `None`. |

## Собственные коды состояния

```php
return Response::json(['error' => 'I refuse to brew coffee.'], 418);

// Собственная фраза причины
return (new Response())->withStatus(418, "I'm a teapot");
```

Lift знает стандартные фразы (`200 OK`, `404 Not Found` и т. д.) — фразу вы передаёте, только если хотите её переопределить.

## Доступ к телу / изменение тела

```php
$stream  = $res->getBody();              // Psr\Http\Message\StreamInterface
$content = (string) $res->getBody();     // строка

// Заменить тело
$newRes  = $res->withBody(\Lift\Http\Stream::fromString('hello'));
```

Большинство кода никогда не трогает тело напрямую — фабричные методы + `withJson()` покрывают 99% случаев.

## Установка заголовков

```php
$res = Response::json($data)
    ->withHeader('Cache-Control', 'public, max-age=3600')
    ->withHeader('X-Total-Count', '42')
    ->withAddedHeader('Set-Cookie', 'a=1')   // добавить (не заменять)
    ->withAddedHeader('Set-Cookie', 'b=2');
```

`withHeader()` **заменяет** любое существующее значение; `withAddedHeader()` **добавляет** (используйте, когда заголовок законно появляется более одного раза, как `Set-Cookie`).

## Стриминг и Server-Sent Events

Для долгоживущих ответов (Server-Sent Events, чтение хвоста логов и т. п.) используйте `SseResponse` — см. [Server-Sent Events](sse).

## Отправка собственных бинарных / файловых ответов

Lift не поставляет помощник `Response::file()` (это микрофреймворк, а не CMS), но это однострочник:

```php
use Lift\Http\Stream;

$path = '/storage/exports/report.csv';

return (new Response())
    ->withHeader('Content-Type', 'text/csv')
    ->withHeader('Content-Disposition', 'attachment; filename="report.csv"')
    ->withHeader('Content-Length', (string) filesize($path))
    ->withBody(Stream::fromFile($path));
```

(См. `Lift\Http\Stream` для фабричных методов — `fromString`, `fromFile`, `fromInput`, `empty`.)

## Шпаргалка по кодам состояния

| Код  | Использовать для                                                       |
|------|------------------------------------------------------------------------|
| 200  | OK — что угодно с телом, что не создало ресурс                         |
| 201  | Created — POST, создавший ресурс                                       |
| 202  | Accepted — поставлено в очередь на асинхронную обработку               |
| 204  | No Content — успешный DELETE / PUT, которому нечего возвращать          |
| 301  | Moved Permanently — старый URL, навсегда                               |
| 302  | Found — временный редирект (браузеры могут сменить метод на GET)       |
| 303  | See Other — редирект POST → GET после отправки формы                   |
| 307  | Temporary Redirect — как 302, но сохраняет HTTP-метод                  |
| 308  | Permanent Redirect — как 301, но сохраняет HTTP-метод                  |
| 400  | Bad Request — некорректный запрос                                      |
| 401  | Unauthorized — отсутствующие/неверные учётные данные                   |
| 403  | Forbidden — аутентифицирован, но не разрешено                          |
| 404  | Not Found                                                              |
| 405  | Method Not Allowed                                                     |
| 409  | Conflict — например, дубликат уникального ограничения                  |
| 422  | Unprocessable Entity — валидация не прошла (по умолчанию для `ValidationException` Lift) |
| 429  | Too Many Requests — превышен лимит частоты                             |
| 500  | Internal Server Error                                                  |
| 502  | Bad Gateway — сбой вышестоящего сервиса                                |
| 503  | Service Unavailable — обслуживание / перегрузка                        |

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| Заголовки не появляются | Вы вызвали `withHeader()`, но не захватили результат | Присвойте обратно: `$res = $res->withHeader(...);`. |
| `JSON_THROW_ON_ERROR` срывается | Не-UTF-8 строка в полезной нагрузке | Очистите ввод; или `Response::json($data, 200, JSON_INVALID_UTF8_IGNORE)`. |
| Браузер игнорирует `Set-Cookie` | Неверные атрибуты cookie (`Secure` по HTTP, несовпадающий домен) | Уберите `secure` для локальной разработки, перепроверьте `domain`/`path`. |
| Пустой JSON `{}` вместо массива `[]` | `json_encode([])` корректно; происходит, когда вы передаёте пустой ассоциативный массив | Передавайте `list<...>` (например, `array_values($items)`), когда хотите `[]`. |
| Текст статуса ничего не говорит | Вы передали пустую фразу причины | Либо не передавайте ничего (Lift подставит), либо укажите свою строку. |

## Шпаргалка

```php
// Фабрики
Response::json($data, $status?, $flags?);
Response::html($html, $status?);
Response::text($text, $status?);
Response::redirect($url, $status?);
Response::noContent();

// Текучий стиль
(new Response())
    ->withStatus(201)
    ->withHeader('X-Foo', 'bar')
    ->withAddedHeader('Set-Cookie', '...')
    ->withJson($data);

// Cookie
$res->withCookie($name, $value, [...]);
$res->withoutCookie($name);

// Тело
$res->getBody();           // StreamInterface
(string) $res->getBody();
$res->withBody(Stream::fromString($html));
```

[DI-контейнер →](container)
