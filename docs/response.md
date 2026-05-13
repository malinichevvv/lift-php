---
layout: page
title: Response
nav_order: 8
---

# Response

`Lift\Http\Response` implements `Psr\Http\Message\ResponseInterface` and adds factory methods for common use cases.

## Factory methods

### JSON

```php
Response::json(['status' => 'ok']);
Response::json($data, 201);                // custom status
Response::json($data, 200, JSON_PRETTY_PRINT); // custom flags
```

### HTML

```php
Response::html('<h1>Hello</h1>');
Response::html($view->render('home'), 200);
```

### Plain text

```php
Response::text('hello world');
```

### Redirect

```php
Response::redirect('/login');             // 302
Response::redirect('/new-url', 301);      // 301 Moved Permanently
Response::redirect('/dashboard', 303);    // 303 See Other
```

### No content

```php
Response::noContent();                    // 204
```

## Fluent builder

All `with*` methods return a **new** instance (PSR-7 immutable):

```php
return (new Response())
    ->withStatus(201)
    ->withHeader('Location', '/users/42')
    ->withHeader('X-Request-Id', $id)
    ->withJson(['id' => 42]);
```

## Setting headers

```php
$res = Response::json($data)
    ->withHeader('Cache-Control', 'max-age=3600')
    ->withHeader('X-Powered-By', 'Lift');
```

## Custom status codes

```php
return Response::json(['error' => 'Conflict'], 409);
return (new Response())->withStatus(418, "I'm a teapot");
```

## Accessing body

```php
$stream  = $res->getBody();             // Psr\Http\Message\StreamInterface
$content = (string) $res->getBody();    // string
```

## Auto-conversion

When a route handler returns something other than a `Response`, Lift converts it automatically:

| Return | Converted to |
|---|---|
| `array` / `object` | `Response::json(...)` with `application/json` |
| `string` | `Response::html(...)` with `text/html` |
| `null` | `Response::noContent()` — 204 |
| `Response` | passed through unchanged |
