---
layout: page
title: HTTP client
nav_order: 15
---

# HTTP client

`Lift\Http\HttpClient` is a small, immutable, fluent client for making **outgoing** HTTP requests. It uses cURL when available, falls back to PHP's stream wrappers when not, and gives you JSON helpers, retries, timeouts, basic auth, and bearer tokens out of the box.

> Mental model: every `with*()` returns a *new* client, so you can configure a base client once (timeouts, auth, base headers) and re-use it across calls. The verbs (`->get()`, `->post()`, etc.) actually fire the request and return a `HttpClientResponse`.

## Five-second demo

```php
use Lift\Http\HttpClient;

$client = HttpClient::new()->timeout(10);

$response = $client->get('https://api.example.com/users/1');

if ($response->ok()) {
    $user = $response->json();
}
```

`HttpClient::new()` is the factory. `$response` is a `HttpClientResponse`.

## Configuring a client

All configurators return a **clone** — they don't mutate the original:

```php
$base = HttpClient::new()
    ->timeout(10)
    ->retry(3)                              // retry 5xx up to 3 attempts
    ->asJson()                              // Content-Type and Accept = application/json
    ->withHeaders(['User-Agent' => 'MyApp/1.0'])
    ->withToken('Bearer', $jwt);            // Authorization: Bearer <token>
```

| Method                            | Effect                                          |
|-----------------------------------|-------------------------------------------------|
| `timeout(int $seconds)`           | Hard timeout (connection + read). Default 30 s. |
| `retry(int $times)`               | Retry on 5xx — total attempts incl. first.      |
| `asJson()`                        | Sets `Content-Type` & `Accept` to JSON.         |
| `withHeaders(array $headers)`     | Merge headers into the base set.                |
| `withToken('Bearer', $token)`     | `Authorization: Bearer <token>`.                |
| `withBasicAuth($user, $pass)`     | HTTP Basic auth.                                |
| `withoutVerifying()`              | Skip TLS certificate verification (DEV ONLY).   |
| `withoutRedirecting()`            | Don't follow `Location:` redirects.             |

> The class is immutable — `$base->timeout(5)` doesn't change `$base`. Re-assign or chain:
> `$client = $base->timeout(5);`

## Sending requests

Verb-shaped methods do exactly what you'd guess:

```php
$client->get('https://api.example.com/users');
$client->get('https://api.example.com/users', query: ['page' => 2]); // /users?page=2

$client->post  ('https://api.example.com/users', ['name' => 'Alice']);   // JSON-encodes the body
$client->put   ('https://api.example.com/users/1', ['name' => 'Bobby']);
$client->patch ('https://api.example.com/users/1', ['name' => 'Carol']);
$client->delete('https://api.example.com/users/1');
$client->head  ('https://api.example.com/users/1');
```

Body arguments:

| Type          | Sent as                                              |
|---------------|------------------------------------------------------|
| `array`/`object` | `application/json` (auto-encoded)                |
| `string`      | Raw bytes (you set the `Content-Type` yourself)      |
| `null`        | No body                                              |

Custom per-call headers (merged on top of the client's base headers):

```php
$client->post($url, $payload, headers: ['X-Idempotency-Key' => $key]);
```

## Reading the response

```php
$response = $client->get($url);

$response->status();              // 200
$response->body();                // raw response body string
$response->json();                // decoded array (throws RuntimeException on non-JSON)
$response->header('X-Foo');       // first value, case-insensitive
$response->headerValues('X-Foo'); // all values
$response->headers();             // full map

$response->ok();          // 2xx
$response->failed();      // 4xx or 5xx
$response->clientError(); // 4xx
$response->serverError(); // 5xx

$response->throw();       // throw RuntimeException on 4xx/5xx (chainable)
```

`throw()` + `json()` is a tidy idiom:

```php
$user = $client->get($url)->throw()->json();
```

## Retries

```php
$client = HttpClient::new()->retry(4);   // up to 4 total attempts on 5xx
```

Behaviour:

- Triggers on `serverError()` (status ≥ 500).
- Waits `100 ms` between attempts.
- Does **not** retry on 4xx — those are client errors, retrying won't help.
- Does **not** retry on connection errors that throw `RuntimeException` (cURL errors, DNS, etc.). For those, wrap in your own try/catch.

For exponential backoff, jitter, or circuit breaking, layer your own loop:

```php
$attempt = 0;
$max = 5;
while (true) {
    try {
        $response = $client->get($url);
        if ($response->ok()) break;
    } catch (\Throwable $e) {
        if (++$attempt >= $max) throw $e;
        usleep(min(60_000_000, 100_000 * (2 ** $attempt)) + random_int(0, 50_000));
    }
}
```

## Authentication

```php
// Bearer JWT / API key
$client->withToken('Bearer', $jwt);

// Basic
$client->withBasicAuth('user', 'pass');

// Custom scheme
$client->withHeaders(['Authorization' => 'Signature keyId=...,algorithm=hs2019,signature=...']);
```

## SSL / TLS

```php
// Production — leave verification ON (the default)
$client = HttpClient::new();

// Self-signed dev cert — disable verification ONLY locally
$dev = HttpClient::new()->withoutVerifying();
```

Never deploy `withoutVerifying()` to production — it disables certificate verification.

## Practical recipes

### A JSON API client

```php
$github = HttpClient::new()
    ->timeout(15)
    ->retry(3)
    ->asJson()
    ->withToken('Bearer', $_ENV['GITHUB_TOKEN'])
    ->withHeaders(['Accept' => 'application/vnd.github+json']);

$repo = $github->get('https://api.github.com/repos/lift-php/lift')->throw()->json();
$prs  = $github->get('https://api.github.com/repos/lift-php/lift/pulls', ['state' => 'open'])->json();
```

### Webhook delivery with idempotency

```php
$response = HttpClient::new()
    ->timeout(8)
    ->retry(3)
    ->asJson()
    ->post('https://merchant.example.com/webhooks/orders', $payload, [
        'X-Idempotency-Key' => $idempotencyKey,
    ]);

if ($response->failed()) {
    $queue->push(new RetryWebhook($payload, $idempotencyKey, $response->status()));
}
```

### Upload a file (multipart)

The client doesn't ship a multipart builder — it's rarely needed for service-to-service work. For one-offs:

```php
$boundary = bin2hex(random_bytes(16));
$body  = "--{$boundary}\r\n";
$body .= "Content-Disposition: form-data; name=\"file\"; filename=\"a.txt\"\r\n";
$body .= "Content-Type: text/plain\r\n\r\n";
$body .= file_get_contents('a.txt') . "\r\n";
$body .= "--{$boundary}--\r\n";

$client->post($url, $body, [
    'Content-Type' => "multipart/form-data; boundary={$boundary}",
]);
```

### Injecting via the DI container

Register a configured client once and inject it everywhere:

```php
use Lift\Http\HttpClient;

$app->singleton(HttpClient::class, fn() => HttpClient::new()
    ->timeout(10)
    ->retry(2)
    ->asJson());

// Now in any controller / service:
class GithubService
{
    public function __construct(private readonly HttpClient $http) {}
    public function repo(string $name): array
    {
        return $this->http
            ->withToken('Bearer', $_ENV['GITHUB_TOKEN'])
            ->get("https://api.github.com/repos/{$name}")
            ->throw()
            ->json();
    }
}
```

Notice: `withToken(...)` returns a clone — the singleton in the container is untouched, so reusing it from many services is safe.

## Testing code that uses the client

The class is `final` (no easy subclassing). The recommended pattern is to depend on an interface you control, with a tiny adapter:

```php
interface HttpFetcher {
    public function get(string $url): array;
}

final class LiftHttpFetcher implements HttpFetcher
{
    public function __construct(private readonly HttpClient $http) {}
    public function get(string $url): array
    {
        return $this->http->get($url)->throw()->json();
    }
}

// In tests:
$app->instance(HttpFetcher::class, new class implements HttpFetcher {
    public function get(string $url): array { return ['stubbed' => true]; }
});
```

Alternatively, point a real `HttpClient` at a local test server (`php -S 127.0.0.1:9999 ...`) for integration tests.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `cURL error [60]: SSL certificate problem` | Self-signed / outdated CA bundle | Fix CA bundle, or use `withoutVerifying()` (dev only). |
| Hangs forever | No timeout configured, server slow | `->timeout(N)` — never trust a remote to be timely. |
| `Response body is not valid JSON` | Endpoint returned HTML or empty | Don't call `->json()` — check `->ok()` first, or use `->body()`. |
| Retries don't fire | Status was 4xx, not 5xx; or `retry(0)` | `retry()` only handles 5xx. Roll your own loop for 4xx. |
| Bearer token leaks into wrong service | Sharing one configured client across hosts | Build per-host clones (`$github = $base->withToken(...);`). |
| `withTimeout()` "didn't work" | Configurator returns a clone you discarded | `$client = $client->timeout(5);`. |

## Cheat sheet

```php
$client = HttpClient::new()
    ->timeout(10)
    ->retry(3)
    ->asJson()
    ->withToken('Bearer', $token);

$users = $client->get($url, query: ['page' => 2])->throw()->json();
$client->post($url, ['name' => 'Alice'])->throw();
$client->delete($url);

// Response
$response->status() / ok() / failed() / clientError() / serverError();
$response->body() / json() / header($n) / headerValues($n);
$response->throw();
```

[Form requests →](form-requests)
