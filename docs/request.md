---
layout: page
title: Request
nav_order: 7
---

# Request

`Lift\Http\Request` is an immutable HTTP-request object. It implements `Psr\Http\Message\ServerRequestInterface` (so it's compatible with every PSR-15 middleware ever written), and adds friendlier shortcuts on top.

> Mental model: a `Request` is a snapshot of one incoming HTTP call. It's *immutable*. Any method that "changes" it (`with*`) returns a **new** object — the original is untouched. This is by design and is the same rule every PSR-7 library follows.

## Getting hold of the request

You almost never construct a Request yourself in production. Just **type-hint it** in your handler and Lift will inject it:

```php
use Lift\Http\Request;

$app->get('/users/{id}', function (Request $req) {
    return ['id' => $req->param('id')];
});
```

Other ways — useful for tests or non-standard entry points:

```php
// From PHP superglobals ($_SERVER, $_GET, $_POST, $_FILES, $_COOKIE, php://input)
$req = Request::fromGlobals();

// Manually (great for tests)
use Lift\Http\Uri;
$req = new Request('GET', new Uri('http://localhost/users/1'));
```

## Reading input

| Source                        | Method                                |
|-------------------------------|---------------------------------------|
| Route parameter `/users/{id}` | `$req->param('id')`                   |
| Query string `?page=2`        | `$req->query('page')`                 |
| Form/JSON body field          | `$req->input('name')`                 |
| Whole JSON body               | `$req->json()` (returns array)        |
| Whole parsed body             | `$req->getParsedBody()` (PSR-7)       |
| Cookie                        | `$req->cookie('session')`             |
| Uploaded file                 | `$req->file('avatar')`                |
| Header value                  | `$req->getHeaderLine('Accept')`       |
| Method (`GET`, `POST`, …)     | `$req->getMethod()`                   |
| Full URI object               | `$req->getUri()`                      |
| Path only                     | `$req->getUri()->getPath()`           |
| Middleware attribute          | `$req->getAttribute('user')`          |
| All server vars               | `$req->getServerParams()` (≈ `$_SERVER`) |

### Route parameters

```php
// Route: /users/{id}
$id  = $req->param('id');            // '42' (always string)
$id  = (int) $req->param('id');      // 42  (cast it yourself)
$id  = $req->param('missing', 0);    // fallback default
$all = $req->params();               // ['id' => '42']

// Lower-level: raw associative array of all matched route params
$raw = $req->getRouteParams();       // ['id' => '42']

// Useful in tests — build a request with specific route params
$req = $req->withRouteParams(['id' => '42', 'slug' => 'hello']);
```

### Query string

```php
// URL: /search?q=lift&page=2
$q    = $req->query('q');             // 'lift'
$page = (int) $req->query('page', 1); // 2
$all  = $req->getQueryParams();       // ['q' => 'lift', 'page' => '2']
```

### Request body

For `POST`, `PUT`, and `PATCH` requests Lift parses the body automatically based on `Content-Type`:

- `application/json` → parsed into an array, available via `$req->json()` **and** `$req->input(...)`.
- Anything else → `$_POST` is used (i.e. `application/x-www-form-urlencoded` and `multipart/form-data`).

```php
// Form POST:  name=Alice&email=alice@example.com
$name = $req->input('name');

// JSON POST:  {"name":"Alice","email":"alice@example.com"}
$name  = $req->input('name');     // works the same
$email = $req->json()['email'];   // direct array access too
```

To read the **raw** body (e.g. webhook signatures that need the unparsed bytes):

```php
$raw = (string) $req->getBody();
```

> If you need the body multiple times or after middleware has read it, rewind the stream: `$req->getBody()->rewind();`.

### Uploaded files

```php
$avatar = $req->file('avatar');   // ?Psr\Http\Message\UploadedFileInterface

if ($avatar !== null && $avatar->getError() === UPLOAD_ERR_OK) {
    $avatar->moveTo(__DIR__ . '/../storage/uploads/' . $avatar->getClientFilename());
}
```

Available info from the file object:

```php
$avatar->getSize();                  // bytes
$avatar->getClientFilename();        // 'me.png'
$avatar->getClientMediaType();       // 'image/png'
$avatar->getError();                 // UPLOAD_ERR_OK etc.
$avatar->getStream();                // PSR-7 stream
```

Multiple files under one field (`<input type="file" name="docs[]" multiple>`):

```php
// $req->getUploadedFiles() returns the normalised tree
foreach ($req->getUploadedFiles()['docs'] ?? [] as $file) {
    /* ... */
}
```

### Cookies

```php
$session = $req->cookie('session');
$all     = $req->getCookieParams();      // ['session' => '...', 'lang' => 'en']
```

To **set** a cookie, see [Response cookies](response#cookies).

### Headers

```php
$accept = $req->getHeaderLine('Accept');       // 'application/json'
$lines  = $req->getHeader('Accept');           // ['application/json'] (list form)
$has    = $req->hasHeader('Authorization');    // bool
$all    = $req->getHeaders();                  // ['Accept' => [...], ...]
```

Header names are case-insensitive (`'Accept'` and `'accept'` both work).

## Helpers / shortcuts

```php
$req->isJson();             // Content-Type contains application/json
$req->wantsJson();          // Accept contains application/json
$req->isMethod('POST');     // method check (case-insensitive)
$req->getMethod();          // 'GET' | 'POST' | …
$req->getUri()->getPath();  // '/users/42'
```

A typical usage of these:

```php
$app->get('/users/{id}', function (Request $req) use ($repo) {
    $user = $repo->find((int) $req->param('id'));

    if ($req->wantsJson()) {
        return Response::json($user);
    }
    return Response::html($view->render('users.show', ['user' => $user]));
});
```

## Validation, the one-liner way

`Request::validate()` merges query params + body + route params, runs them through the [Validator](validation), and either returns the validated data or throws `ValidationException` (which Lift's default error handler turns into HTTP 422):

```php
$app->post('/users', function (Request $req) use ($repo) {
    $data = $req->validate([
        'name'  => 'required|string|min:2|max:255',
        'email' => 'required|email|unique:users,email',
        'age'   => 'integer|min:13',
    ]);

    return Response::json($repo->create($data), 201);
});
```

Custom error messages:

```php
$data = $req->validate(
    ['email' => 'required|email'],
    ['email.required' => 'We need your email to send the link.'],
);
```

Pass a [Translator](localization) for localized messages:

```php
$data = $req->validate($rules, [], $translator);
```

See [Validation](validation) for the full rule list.

## Middleware attributes — passing data downstream

Middleware can attach arbitrary values to the request, and the handler reads them. The conventional carrier for "the authenticated user", "the JWT claims", "the request ID", etc.

```php
// In middleware:
$req = $req->withAttribute('user', $authenticatedUser);
return $handler->handle($req);

// In the handler:
$user = $req->getAttribute('user');           // null if not set
$user = $req->getAttribute('user', $default); // with default
$all  = $req->getAttributes();
```

Attributes are *per-request*, never shared, and disappear when the request finishes. They are **not** an event bus and **not** persistent — for those use [Events](events) or [Sessions](sessions).

## PSR-7 immutability

The single most-common mistake when starting with PSR-7:

```php
// ❌ WRONG — does nothing. with*() returns a NEW object.
$req->withHeader('X-Foo', 'bar');
$req->withAttribute('user', $user);

// ✅ RIGHT — capture the new instance.
$req = $req->withHeader('X-Foo', 'bar');
$req = $req->withAttribute('user', $user);
```

If you call `$req->withFoo(...)` and ignore the return value, **nothing changes** because the underlying object is immutable. This rule applies to every PSR-7 method, not just Lift's.

Fluent chaining works fine because each call returns the new instance:

```php
$req = $req
    ->withHeader('X-Trace', $traceId)
    ->withAttribute('user', $user)
    ->withAttribute('start', microtime(true));
```

## Less-common PSR-7 methods you might need

```php
$req->getProtocolVersion();        // '1.1', '2.0'
$req->getRequestTarget();          // '/users/42?page=1'
$req->withMethod('POST');
$req->withUri($newUri);            // returns clone with new URI
$req->withQueryParams(['x' => 1]); // replace query
$req->withParsedBody($newBody);    // replace body
$req->withoutAttribute('user');
```

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `$req->json()` returns `[]` for a JSON body | Wrong/missing `Content-Type: application/json` header | Make the client send the right header. |
| `$req->input('name')` is `null` but the field is in the URL | `input()` only reads body — use `query('name')` instead | Use the right reader. |
| Calling `withFoo(...)` "doesn't work" | You didn't assign the result back | `$req = $req->withFoo(...)`. |
| `$req->file('avatar')` is `null` | The form is missing `enctype="multipart/form-data"` | Add the enctype. |
| `$req->param('id')` is `'42'` not `42` | All route params are strings | Cast: `(int) $req->param('id')`. |
| Reading body twice yields empty | The PHP `input` stream is not rewindable in some SAPIs | Either cache `$raw = (string) $req->getBody();` once and reuse, or use Lift's `$req->json()` (it re-reads internally). |

## Cheat sheet

```php
// Input
$req->param('id');         // route
$req->query('page', 1);    // query string
$req->input('name');       // body field (form or JSON)
$req->json();              // entire JSON body as array
$req->file('avatar');      // ?UploadedFileInterface
$req->cookie('session');

// Inspect
$req->getMethod();
$req->getUri()->getPath();
$req->getHeaderLine('Authorization');
$req->isJson() / wantsJson() / isMethod('POST');

// Validate
$data = $req->validate(['email' => 'required|email']);

// Middleware → handler
$req = $req->withAttribute('user', $user);
$user = $req->getAttribute('user');

// PSR-7 (always assign result!)
$req = $req->withMethod('POST')->withHeader('X-Foo', 'bar');
```

[Response →](response)
