---
layout: page
title: Request
nav_order: 7
---

# Request

`Lift\Http\Request` implements `Psr\Http\Message\ServerRequestInterface` and adds ergonomic helpers on top.

## Getting the request object

The request is injected automatically into handlers and middleware:

```php
$app->get('/example', function (Request $req) {
    // ...
});
```

You can also create one from PHP superglobals:

```php
$request = Request::fromGlobals();
```

Or construct it manually (useful in tests):

```php
$request = new Request('GET', new Uri('http://localhost/users/1'));
```

## Route parameters

```php
// Route: /users/{id}
$id = $req->param('id');              // '42' (string)
$id = $req->param('missing', 0);      // fallback default
$all = $req->params();                // ['id' => '42']
```

## Query string

```php
// URL: /search?q=lift&page=2
$q    = $req->query('q');             // 'lift'
$page = $req->query('page', 1);       // '2'
```

## Request body

```php
// Form POST
$name = $req->input('name');

// JSON body (decoded)
$data = $req->json();                 // ['name' => 'Alice', ...]

// Single field from JSON
$name = $req->json()['name'] ?? null;
```

## Uploaded files

```php
$avatar = $req->file('avatar');       // Psr\Http\Message\UploadedFileInterface|null

if ($avatar && $avatar->getError() === UPLOAD_ERR_OK) {
    $avatar->moveTo('/storage/avatars/' . $avatar->getClientFilename());
}
```

## Cookies

```php
$session = $req->cookie('session_id');
```

## Headers

```php
$accept = $req->getHeaderLine('Accept');
$type   = $req->getHeaderLine('Content-Type');
$all    = $req->getHeaders();
$has    = $req->hasHeader('Authorization');
```

## Helpers

```php
$req->isJson();            // Content-Type contains application/json
$req->wantsJson();         // Accept contains application/json
$req->isMethod('POST');    // method check (case-insensitive)
$req->getMethod();         // 'GET' | 'POST' | ...
$req->getUri();            // Psr\Http\Message\UriInterface
```

## Middleware attributes

Middleware can attach arbitrary data to a request:

```php
// In middleware
$request = $request->withAttribute('user', $authenticatedUser);

// In handler
$user = $req->getAttribute('user');
```

## PSR-7 immutability

All `with*` methods return a new instance:

```php
$new = $req->withMethod('POST')
           ->withUri($req->getUri()->withPath('/new'))
           ->withHeader('X-Custom', 'value');
```
