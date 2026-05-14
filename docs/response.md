---
layout: page
title: Response
nav_order: 8
---

# Response

`Lift\Http\Response` is an immutable HTTP-response object. It implements `Psr\Http\Message\ResponseInterface` and provides factory methods for the common cases (JSON, HTML, text, redirect, no-content) plus cookie helpers and a fluent builder.

> Mental model: build a `Response`, return it from your handler, Lift sends it to the client. Like `Request`, it is immutable — every `with*()` method returns a **new** instance.

## The shortest possible response

```php
$app->get('/', fn() => Response::json(['hello' => 'world']));
```

That's all. If you don't need to set custom headers or status codes, the factory methods are the cleanest API.

## Factory methods

### `Response::json($data, $status = 200, $flags = ...)`

Sends an array/object as JSON with `Content-Type: application/json; charset=utf-8`.

```php
Response::json(['status' => 'ok']);              // 200 OK
Response::json(['error' => 'Conflict'], 409);    // custom status
Response::json($data, 200, JSON_PRETTY_PRINT);   // custom encode flags
```

The default flags include `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` (you almost always want those). Encoding errors throw `JsonException` — never silently produce broken output.

### `Response::html($content, $status = 200)`

```php
Response::html('<h1>Hello</h1>');
Response::html($view->render('home'), 200);
```

`Content-Type: text/html; charset=utf-8` is set automatically.

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
Response::redirect('/after-post', 303);          // 303 See Other  (POST → GET pattern)
Response::redirect('/short-cache', 307);         // 307 Temporary Redirect (preserves method)
Response::redirect('/forever', 308);             // 308 Permanent Redirect (preserves method)
```

The third `$headers` argument merges additional headers into the redirect response:

```php
// Redirect and clear a cookie in one shot
Response::redirect('/login', 302, ['Clear-Site-Data' => '"cookies"']);

// Redirect with a custom cache control
Response::redirect('/new-home', 301, ['Cache-Control' => 'no-store']);
```

You can also chain `->withHeader(...)` / `->withCookie(...)` on the result:

```php
return Response::redirect('/dashboard')
    ->withCookie('flash', 'Welcome back!');
```

### `Response::noContent()`

```php
return Response::noContent();   // 204, empty body
```

Use this when a DELETE / PUT etc. succeeds but has nothing to return.

## Fluent builder (PSR-7 style)

For everything the factories don't cover, use `with*()` chains. **Each call returns a new instance**:

```php
return (new Response())
    ->withStatus(201)
    ->withHeader('Location', '/users/42')
    ->withHeader('X-Request-Id', $id)
    ->withJson(['id' => 42]);          // sets body + Content-Type, keeps status
    // ->withJson(['id' => 42], 201);  // optional second arg overrides status code
```

A subtle but common bug:

```php
// ❌ WRONG — withHeader returns a new object; this throws it away.
$res = Response::json($data);
$res->withHeader('X-Custom', 'value');
return $res;

// ✅ RIGHT
$res = Response::json($data)->withHeader('X-Custom', 'value');
return $res;
```

## Auto-conversion

If a handler returns something that isn't a `Response`, Lift converts it for you:

| Return value         | What you get back               |
|----------------------|---------------------------------|
| `Response`           | passed through unchanged        |
| `array`, `object`    | `Response::json(...)`           |
| `string`             | `Response::html(...)`           |
| `null`               | `Response::noContent()` (204)   |
| anything else        | `Response::text((string) $v)`   |

So these two handlers are identical:

```php
fn() => ['ok' => true]
fn() => Response::json(['ok' => true])
```

Pick whichever reads better. Tip: explicit `Response::json(...)` shines whenever you also need a status code or a header — those force you to use a `Response` anyway.

## Cookies

Lift's response carries first-class cookie helpers — you don't need PHP's `setcookie()`.

```php
return Response::json($user)
    ->withCookie('remember_token', $token, [
        'max_age'   => 86400 * 30,   // 30 days
        'http_only' => true,         // default true
        'same_site' => 'Lax',        // default 'Lax'
        'secure'    => true,         // send only over HTTPS
        'path'      => '/',          // default '/'
        'domain'    => 'example.com',// optional
    ]);
```

Quickly delete a cookie:

```php
return Response::noContent()->withoutCookie('remember_token');
```

> Read the value on the next request via [`$req->cookie('remember_token')`](request#cookies).

### Cookie option reference

| Key         | Type   | Default | Effect |
|-------------|--------|---------|--------|
| `max_age`   | int    | —       | `Max-Age=N` seconds. Recommended over `expires`. |
| `expires`   | int    | —       | Unix timestamp. Ignored when `max_age` is set.   |
| `path`      | string | `/`     | URL prefix the cookie applies to.                |
| `domain`    | string | —       | Cookie domain (sub-domain control).              |
| `secure`    | bool   | `false` | Adds `Secure` flag (HTTPS only).                 |
| `http_only` | bool   | `true`  | Adds `HttpOnly` flag (no JS access).             |
| `same_site` | string | `Lax`   | `Strict` / `Lax` / `None`.                       |

## Custom status codes

```php
return Response::json(['error' => 'I refuse to brew coffee.'], 418);

// Custom reason phrase
return (new Response())->withStatus(418, "I'm a teapot");
```

Lift knows the standard phrases (`200 OK`, `404 Not Found`, etc.) — you only pass a phrase if you want to override.

## Accessing / mutating the body

```php
$stream  = $res->getBody();              // Psr\Http\Message\StreamInterface
$content = (string) $res->getBody();     // string

// Replace the body
$newRes  = $res->withBody(\Lift\Http\Stream::fromString('hello'));
```

Most code never touches the body directly — the factory methods + `withJson()` cover 99% of cases.

## Setting headers

```php
$res = Response::json($data)
    ->withHeader('Cache-Control', 'public, max-age=3600')
    ->withHeader('X-Total-Count', '42')
    ->withAddedHeader('Set-Cookie', 'a=1')   // append (don't replace)
    ->withAddedHeader('Set-Cookie', 'b=2');
```

`withHeader()` **replaces** any existing value; `withAddedHeader()` **appends** (use this when a header legitimately appears more than once, like `Set-Cookie`).

## Streaming and Server-Sent Events

For long-lived responses (Server-Sent Events, log tailing, etc.) use `SseResponse` — see [Server-Sent Events](sse).

## Sending custom binary / file responses

Lift doesn't ship a `Response::file()` helper (it's a micro-framework, not a CMS), but it's a one-liner:

```php
use Lift\Http\Stream;

$path = '/storage/exports/report.csv';

return (new Response())
    ->withHeader('Content-Type', 'text/csv')
    ->withHeader('Content-Disposition', 'attachment; filename="report.csv"')
    ->withHeader('Content-Length', (string) filesize($path))
    ->withBody(Stream::fromFile($path));
```

(See `Lift\Http\Stream` for factory methods — `fromString`, `fromFile`, `fromInput`, `empty`.)

## Status code cheat sheet

| Code | Use for                                                                |
|------|------------------------------------------------------------------------|
| 200  | OK — anything with a body that didn't create a resource                |
| 201  | Created — POST that created a resource                                 |
| 202  | Accepted — queued for async processing                                 |
| 204  | No Content — successful DELETE / PUT with nothing to return            |
| 301  | Moved Permanently — old URL, forever                                   |
| 302  | Found — temporary redirect (browsers may switch method to GET)         |
| 303  | See Other — POST → GET redirect after form submission                  |
| 307  | Temporary Redirect — like 302 but preserves the HTTP method            |
| 308  | Permanent Redirect — like 301 but preserves the HTTP method            |
| 400  | Bad Request — malformed request                                        |
| 401  | Unauthorized — missing/invalid credentials                             |
| 403  | Forbidden — authenticated but not allowed                              |
| 404  | Not Found                                                              |
| 405  | Method Not Allowed                                                     |
| 409  | Conflict — e.g. duplicate unique constraint                            |
| 422  | Unprocessable Entity — validation failed (Lift's `ValidationException` default) |
| 429  | Too Many Requests — rate-limited                                       |
| 500  | Internal Server Error                                                  |
| 502  | Bad Gateway — upstream failure                                         |
| 503  | Service Unavailable — maintenance / overload                           |

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Headers don't appear | You called `withHeader()` but didn't capture the return | Assign back: `$res = $res->withHeader(...);`. |
| `JSON_THROW_ON_ERROR` blows up | Non-UTF-8 string in payload | Sanitise input; or `Response::json($data, 200, JSON_INVALID_UTF8_IGNORE)`. |
| Browser ignores `Set-Cookie` | Cookie attributes are wrong (`Secure` on HTTP, mismatched domain) | Drop `secure` for local dev, double-check `domain`/`path`. |
| Empty JSON `{}` instead of array `[]` | `json_encode([])` is correct; happens when you pass an empty associative array | Pass a `list<...>` (e.g. `array_values($items)`) when you want `[]`. |
| Status text says nothing | You passed an empty reason phrase | Either pass nothing (Lift fills it in) or supply your own string. |

## Cheat sheet

```php
// Factories
Response::json($data, $status?, $flags?);
Response::html($html, $status?);
Response::text($text, $status?);
Response::redirect($url, $status?);
Response::noContent();

// Fluent
(new Response())
    ->withStatus(201)
    ->withHeader('X-Foo', 'bar')
    ->withAddedHeader('Set-Cookie', '...')
    ->withJson($data);

// Cookies
$res->withCookie($name, $value, [...]);
$res->withoutCookie($name);

// Body
$res->getBody();           // StreamInterface
(string) $res->getBody();
$res->withBody(Stream::fromString($html));
```

[DI Container →](container)
