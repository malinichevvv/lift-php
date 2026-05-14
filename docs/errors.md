---
layout: page
title: Error handling
nav_order: 9
---

# Error handling

In a web app, "errors" come from three places:

1. **HTTP-shaped problems** you raise on purpose — *"not found"*, *"unauthorized"*, *"rate-limited"*.
2. **Validation failures** — input doesn't match the rules.
3. **Bugs / infrastructure failures** — the database is down, a null pointer, etc.

Lift gives you a single, uniform way to turn all three into proper HTTP responses, and to customise that mapping when the defaults aren't right.

## The big picture

Whenever a handler / middleware throws, Lift catches it and runs this pipeline:

```
throw  → debug handler (if registered & matches)
      → onException(SomeClass::class, $h) (if registered for that class)
      → onError($h) (if registered — catch-all)
      → default mapping (HttpException → status code; ValidationException → 422)
      → final fallback: 500 Internal Server Error
```

That order is important. Specific handlers win over generic ones.

## Throwing HTTP exceptions

Lift ships a hierarchy of typed exceptions under `Lift\Exception\*`. They all extend `HttpException`, which carries a status code:

| Exception                          | Status | When to throw                                    |
|-----------------------------------:|:------:|--------------------------------------------------|
| `BadRequestException`              | 400    | Request is malformed / can't be processed        |
| `UnauthorizedException`            | 401    | Auth required and missing/invalid                |
| `ForbiddenException`               | 403    | Authenticated but not allowed                    |
| `NotFoundException`                | 404    | Resource doesn't exist                           |
| `MethodNotAllowedException`        | 405    | Path is right but verb isn't                     |
| `ConflictException`                | 409    | Duplicate / state conflict                       |
| `TooManyRequestsException`         | 429    | Rate limit exceeded (carries optional `retryAfter`) |
| `HttpException` (the base class)   | any    | Custom status not covered above                  |

```php
use Lift\Exception\NotFoundException;
use Lift\Exception\ForbiddenException;
use Lift\Exception\TooManyRequestsException;

$app->get('/users/{id:\d+}', function (Request $req) use ($repo) {
    $user = $repo->find((int) $req->param('id'));
    if ($user === null) {
        throw new NotFoundException("User not found");
    }
    return $user;
});

// Need a custom status?
throw new \Lift\Exception\HttpException(418, "I'm a teapot");

// 429 with Retry-After header (the default handler reads `retryAfter` and writes the header):
throw new TooManyRequestsException("Slow down", retryAfter: 60);
```

By default these turn into JSON responses:

```json
{ "error": "User not found" }
```

…with the matching status code. To customise the body or content-type, register a handler (see below).

## Validation errors (422)

`Lift\Validation\ValidationException` thrown anywhere — including `$req->validate(...)` and `FormRequest` — is caught automatically and converted to **HTTP 422** with the errors map:

```json
{
  "errors": {
    "email": ["The email field is required."],
    "age":   ["The age must be at least 13."]
  }
}
```

You almost never need to wrap `$req->validate(...)` in a try/catch in production — let Lift's default handler do it.

## Customising globally — `$app->onError(...)`

`onError()` registers a **catch-all**. It runs for any `Throwable` that wasn't already handled by a more specific `onException()`.

```php
$app->onError(function (\Throwable $e, Request $req) use ($app, $logger) {
    // Log everything except expected HTTP exceptions
    if (!$e instanceof \Lift\Exception\HttpException) {
        $logger->error($e->getMessage(), ['exception' => $e]);
    }

    // Return a Response based on whether the client wants JSON or HTML
    $isJson = $req->wantsJson() || str_starts_with($req->getUri()->getPath(), '/api');

    if ($e instanceof \Lift\Validation\ValidationException) {
        return Response::json(['errors' => $e->errors()], 422);
    }
    if ($e instanceof \Lift\Exception\HttpException) {
        return $isJson
            ? Response::json(['error' => $e->getMessage()], $e->getStatusCode())
            : Response::html("<h1>{$e->getStatusCode()}</h1><p>{$e->getMessage()}</p>", $e->getStatusCode());
    }

    return $isJson
        ? Response::json(['error' => 'Server error'], 500)
        : Response::html('<h1>500 — Something went wrong</h1>', 500);
});
```

The handler receives the exception and the original request. It must return a `Response`.

> The default handler kicks in only when **you** didn't register one. As soon as you call `$app->onError(...)`, you take full responsibility — including 404, 405, 422, etc.

## Customising per type — `$app->onException(...)`

`onException(SomeClass::class, $handler)` runs only when the thrown exception is an instance of `SomeClass`. Multiple handlers stack — the most specific match wins.

```php
use Lift\Exception\NotFoundException;
use App\Exception\PaymentFailedException;

$app->onException(NotFoundException::class, fn() => Response::html(
    '<h1>404</h1><p>Nothing here, mate.</p>', 404
));

$app->onException(PaymentFailedException::class, function (PaymentFailedException $e) {
    return Response::json([
        'error' => 'payment_failed',
        'reason' => $e->reason,
        'next_step' => '/billing/retry',
    ], 402);
});
```

These don't replace `onError(...)` — they run **before** it. If neither handles the exception, the framework falls through to the default mapping.

## Custom app exceptions

Roll your own when you want a typed, semantic exception that maps to a status:

```php
namespace App\Exception;

use Lift\Exception\HttpException;

final class PaymentFailedException extends HttpException
{
    public function __construct(
        public readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(402, "Payment failed: $reason", $previous);
    }
}

// Anywhere:
throw new PaymentFailedException('card_declined');
```

The default handler will turn this into `{ "error": "Payment failed: card_declined" }` with status 402.

## In middleware

A middleware can both *throw* and *catch* exceptions. A common pattern:

```php
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Jwt $jwt) {}

    public function process($req, $next): ResponseInterface
    {
        $token = $req->getHeaderLine('Authorization');
        if (!$this->jwt->verify($token)) {
            throw new \Lift\Exception\UnauthorizedException();
        }
        return $next->handle($req);
    }
}
```

The `UnauthorizedException` propagates up to Lift's error handling and becomes a 401. No manual `Response::json(...)` in the middleware.

## Debug mode

When `$app->debug(true)` is enabled, exceptions render as a **detailed HTML page** with the stack trace, source-code preview, request inspection, and SQL queries:

```php
$app->debug([
    'enabled'        => Env::bool('APP_DEBUG', false),
    'show_query_log' => true,
    'log_requests'   => true,
]);
```

**Never enable debug mode in production** — it leaks file paths, environment variables, and source code. Gate it behind an environment variable.

Read more: [Debug toolbar](debug).

## Production logging

For unexpected exceptions you want to log + monitor + alert on. Lift doesn't ship monitoring — it gives you a [logger](logging) and lets you wire whatever you want (Sentry, Bugsnag, plain file):

```php
$app->onError(function (\Throwable $e, Request $req) use ($logger, $sentry) {
    // Skip expected HTTP-flow exceptions
    if (!$e instanceof \Lift\Exception\HttpException) {
        $sentry->captureException($e);
        $logger->error($e->getMessage(), [
            'method'    => $req->getMethod(),
            'path'      => $req->getUri()->getPath(),
            'exception' => $e,
        ]);
    }

    // …return the response as before
});
```

## `ErrorRenderer` — content-negotiating error handler

Writing a full `onError()` callback that handles JSON vs HTML, logs errors, and maps status codes is repetitive. `Lift\Debug\ErrorRenderer` is a factory that generates ready-made handlers:

```php
use Lift\Debug\ErrorRenderer;

// Auto-detect: JSON when client sends/accepts JSON, HTML otherwise
$app->onError(ErrorRenderer::auto());

// Always JSON (APIs, microservices)
$app->onError(ErrorRenderer::json());

// Always HTML (classic web apps)
$app->onError(ErrorRenderer::html());
```

**Show error details** (exception class, file:line, stack trace) in dev:

```php
use Lift\Config\Env;

$app->onError(ErrorRenderer::auto(
    showDetails: Env::bool('APP_DEBUG', false),
));
```

In production (`showDetails: false`) the response only contains the message:

```json
{ "error": "User not found" }
```

With `showDetails: true` the JSON body also carries:

```json
{
    "error": "User not found",
    "exception": "Lift\\Exception\\NotFoundException",
    "file": "/var/www/src/UserRepository.php",
    "line": 42,
    "trace": [
        "Lift\\Exception\\NotFoundException::__construct (/var/www/src/UserRepository.php:42)",
        "App\\Http\\Controllers\\UserController::show (/var/www/src/Http/Controllers/UserController.php:31)"
    ]
}
```

The HTML response (when the client accepts `text/html`) is a clean, minimal error page that works without any external assets:

```php
$app->onError(ErrorRenderer::html(showDetails: true));
// → renders: 404 card, exception class, file:line, full trace
```

**Status code mapping** follows the same rules as the default handler:
- `ValidationException` → 422 (includes `errors` map in JSON mode)
- `HttpException` subclasses → their `getStatusCode()`
- Everything else → 500

**Combining with logging** — `ErrorRenderer` handles _rendering_ only. To log before rendering, wrap it:

```php
$app->onError(function (\Throwable $e, Request $req) use ($logger) {
    if (!$e instanceof \Lift\Exception\HttpException) {
        $logger->error($e->getMessage(), ['exception' => $e]);
    }
    return ErrorRenderer::auto()($e, $req);
});
```

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| 500 with `{"error":"Internal Server Error"}` and no log | You registered `$app->onError(...)` and forgot to log inside it | Add explicit logging in the handler. |
| `ValidationException` returns 500 instead of 422 | You wrote a custom `onError(...)` and forgot to handle `ValidationException` | Add the branch (see example above). |
| `NotFoundException` from inside `find()` becomes generic 500 | You're throwing inside an `onError` handler — re-throws bubble up untouched | Don't throw from the error handler; return a Response. |
| `Retry-After` header missing on 429 | You threw a generic `HttpException(429)` instead of `TooManyRequestsException` | Use the typed one, pass `retryAfter`. |
| Debug page leaks in prod | `$app->debug(true)` is hard-coded | Always derive from `Env::bool('APP_DEBUG', false)`. |

## Cheat sheet

```php
// Throw typed errors
throw new NotFoundException();                                  // 404
throw new UnauthorizedException("Bad token");                   // 401
throw new ForbiddenException("Admins only");                    // 403
throw new TooManyRequestsException("Slow down", retryAfter: 60); // 429
throw new HttpException(418, "I'm a teapot");                   // any

// Register handlers
$app->onException(NotFoundException::class, fn($e, $req) => …);
$app->onError(fn(\Throwable $e, Request $req) => …);

// Ready-made content-negotiating handler
use Lift\Debug\ErrorRenderer;
$app->onError(ErrorRenderer::auto());                       // JSON or HTML based on Accept
$app->onError(ErrorRenderer::auto(showDetails: true));      // + exception details
$app->onError(ErrorRenderer::json());                       // always JSON
$app->onError(ErrorRenderer::html());                       // always HTML

// Validation auto-422 — no special handling needed
$data = $req->validate(['email' => 'required|email']);
```

[Testing →](testing)
