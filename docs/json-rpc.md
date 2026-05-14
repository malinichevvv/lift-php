---
layout: page
title: JSON-RPC 2.0
nav_order: 32
---

# JSON-RPC 2.0

`Lift\JsonRpc\JsonRpcServer` is a **spec-compliant** [JSON-RPC 2.0](https://www.jsonrpc.org/specification) server you can mount as a route handler. It supports single and batch requests, named and positional params, notifications, error codes, and `#[RpcMethod]` attribute scanning.

> Mental model: JSON-RPC is one URL that accepts `{"method": "...", "params": {...}}` and returns `{"result": ...}` or `{"error": {...}}`. No path-based routing — the **method name** identifies what to call. Great for symmetric tools, internal RPC, and code that's easier to call than to URL-design.

## When to use JSON-RPC

- **Internal microservice traffic** where REST's verbs add no value.
- **Tooling APIs** (IDE plugins, language servers, automation clients).
- **Batched mutations** — JSON-RPC supports an array of calls in a single HTTP request.
- **Front-ends that already model things as RPCs** (e.g. `api.users.create(...)` instead of `POST /users`).

When **not** to use it:

- Public, browser-facing APIs — REST is more cacheable and more familiar.
- File uploads / binary content — JSON-RPC encodes everything in JSON.

## 30-second example

```php
use Lift\JsonRpc\JsonRpcServer;

$rpc = new JsonRpcServer($app->container());

$rpc->register('math.add', fn(int $a, int $b): int => $a + $b);
$rpc->register('math.mul', fn(int $a, int $b): int => $a * $b);

$app->post('/rpc', $rpc);   // the server is invokable
```

Call it:

```bash
curl -X POST http://localhost:8000/rpc \
    -H 'Content-Type: application/json' \
    -d '{"jsonrpc":"2.0","method":"math.add","params":{"a":2,"b":3},"id":1}'

# {"jsonrpc":"2.0","result":5,"id":1}
```

The `$rpc` object **is** the route handler — Lift calls `$rpc->__invoke($req)` for you.

## Registering methods

Two styles. Mix freely.

### Callable form

```php
$rpc->register('users.find',  fn(int $id) => $userRepo->find($id));
$rpc->register('users.list',  [UserService::class, 'list']);   // container-resolved
$rpc->register('users.echo',  $someClosure);
```

### Attribute form (`#[RpcMethod]`)

Group related methods on a service class:

```php
use Lift\JsonRpc\Attribute\RpcMethod;

final class MathService
{
    public function __construct(private readonly Cache $cache) {}

    #[RpcMethod('math.add')]
    public function add(int $a, int $b): int { return $a + $b; }

    #[RpcMethod('math.mul')]
    public function mul(int $a, int $b): int { return $a * $b; }

    #[RpcMethod]   // name defaults to "MathService.div"
    public function div(int $a, int $b): float { return $a / $b; }
}

$rpc->registerService(MathService::class);
```

`registerService(...)`:

1. Reflects the class.
2. For every public method with `#[RpcMethod]`, registers `[$instance, 'method']`.
3. The class is built through the [container](container), so its constructor deps are autowired.

Inspect what's registered:

```php
$rpc->methods();   // ['math.add', 'math.mul', 'MathService.div']
```

## Calling conventions

JSON-RPC supports **named** and **positional** params. Lift handles both transparently — your PHP signature stays the same.

```php
$rpc->register('users.find', fn(int $id, bool $includeProfile = false) => …);
```

Named:

```json
{"jsonrpc":"2.0","method":"users.find","params":{"id":42,"includeProfile":true},"id":1}
```

Positional:

```json
{"jsonrpc":"2.0","method":"users.find","params":[42, true],"id":1}
```

If a required parameter is missing, the response is a structured error:

```json
{"jsonrpc":"2.0","error":{"code":-32602,"message":"Missing required parameter: $id"},"id":1}
```

Optional parameters use PHP's default; unknown JSON keys are ignored.

### Type coercion

For built-in scalar parameter types (`int`, `float`, `string`, `bool`, `array`), the server casts the JSON value before calling. So a client that sends `{"a":"3"}` to `math.add(int $a, …)` gets `int(3)`, not a type error.

Object types (`User $u`, etc.) are passed through unchanged — the JSON value remains a `stdClass` / `array`. You can hydrate it yourself inside the method.

## Notifications

A request **without** an `id` field is a *notification*: the client doesn't want a response.

```json
{"jsonrpc":"2.0","method":"audit.log","params":{"event":"login","user":42}}
```

The server:

1. Invokes the method like normal.
2. Returns **no response body** (HTTP 204).
3. **Swallows** any errors — clients don't see them.

Use notifications for fire-and-forget side effects.

## Batch requests

A JSON array packs several calls in one HTTP request:

```json
[
  {"jsonrpc":"2.0","method":"math.add","params":[1,2],"id":1},
  {"jsonrpc":"2.0","method":"math.mul","params":[2,3],"id":2},
  {"jsonrpc":"2.0","method":"notify.something","params":{}}
]
```

Response is an array of responses, in arbitrary order, with notifications **omitted**:

```json
[
  {"jsonrpc":"2.0","result":3,"id":1},
  {"jsonrpc":"2.0","result":6,"id":2}
]
```

Clients match by `id`. If every call in a batch is a notification, the server returns `204 No Content`.

## Error codes

Standard JSON-RPC reserves a few codes:

| Code     | Meaning                                  | When Lift returns it                |
|----------|------------------------------------------|-------------------------------------|
| `-32700` | Parse error — invalid JSON               | Request body isn't parseable JSON   |
| `-32600` | Invalid request — malformed RPC envelope | Missing `jsonrpc` / `method`        |
| `-32601` | Method not found                         | Unknown method name                 |
| `-32602` | Invalid params                           | Missing required PHP parameter      |
| `-32603` | Internal error                           | Method threw an unexpected exception |

Custom errors come from exceptions you throw inside the method. Lift wraps them as `JsonRpcError::fromException($e, $debug)`:

```php
$rpc->register('users.find', function (int $id) use ($repo) {
    $user = $repo->find($id);
    if ($user === null) {
        throw new \InvalidArgumentException("User not found", JsonRpcError::INVALID_PARAMS);
    }
    return $user;
});
```

Use the exception's **code** field for the RPC error code. The **message** is the user-facing message; if `setDebug(true)` is on, additional debug info may be exposed — leave it **off in production**.

```php
$rpc->setDebug($app->environment() === 'local');
```

## A real example

```php
use Lift\JsonRpc\Attribute\RpcMethod;

#[\Lift\JsonRpc\Attribute\RpcService]
final class TaskService
{
    public function __construct(private readonly TaskRepository $repo) {}

    #[RpcMethod('tasks.list')]
    public function list(?string $status = null): array
    {
        return $this->repo->listByStatus($status);
    }

    #[RpcMethod('tasks.create')]
    public function create(string $title, ?string $description = null): array
    {
        $id = $this->repo->create(['title' => $title, 'description' => $description]);
        return $this->repo->find($id);
    }

    #[RpcMethod('tasks.complete')]
    public function complete(int $id): bool
    {
        return $this->repo->complete($id) > 0;
    }
}

$rpc = new JsonRpcServer($app->container());
$rpc->registerService(TaskService::class);
$app->post('/rpc', $rpc);
```

Client (JS):

```js
async function rpc(method, params) {
    const r = await fetch('/rpc', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ jsonrpc: '2.0', method, params, id: 1 }),
    });
    const body = await r.json();
    if (body.error) throw new Error(body.error.message);
    return body.result;
}

await rpc('tasks.create', { title: 'Write docs' });
await rpc('tasks.list',   { status: 'open' });
```

## Auth and middleware

Mount the RPC route just like any other — middleware applies normally:

```php
$app->post('/rpc', $rpc)->middleware(JwtMiddleware::class);
```

Per-method auth (e.g. *"only admins can call `users.delete`"*) lives inside the method:

```php
#[RpcMethod('users.delete')]
public function delete(int $id, ServerRequestInterface $req): bool
{
    $claims = $req->getAttribute('jwt');
    if (($claims['role'] ?? '') !== 'admin') {
        throw new \RuntimeException('Forbidden', \Lift\JsonRpc\JsonRpcError::INVALID_PARAMS);
    }
    return $this->repo->delete($id) > 0;
}
```

Lift will pass the current `Request` into any parameter typed as `ServerRequestInterface` or `Request` (no special opt-in).

## Testing

```php
public function testAddsTwoNumbers(): void
{
    $this->postJson('/rpc', [
        'jsonrpc' => '2.0',
        'method'  => 'math.add',
        'params'  => ['a' => 2, 'b' => 3],
        'id'      => 1,
    ])
    ->assertOk()
    ->assertJson(['jsonrpc' => '2.0', 'result' => 5, 'id' => 1]);
}

public function testMethodNotFoundReturnsError(): void
{
    $this->postJson('/rpc', [
        'jsonrpc' => '2.0',
        'method'  => 'does.not.exist',
        'id'      => 1,
    ])
    ->assertOk()
    ->assertJsonPath('error.code', -32601);
}
```

JSON-RPC errors come back with HTTP **200** — that's by spec. The error is in the body, not the status. Don't be tempted to map them to 4xx codes.

## Comparison with REST

| Concern                   | REST                        | JSON-RPC                            |
|---------------------------|-----------------------------|-------------------------------------|
| URL design                | One URL per resource        | One URL for the whole API           |
| Verbs                     | GET / POST / PUT / DELETE   | All POST (method name in body)      |
| Errors                    | HTTP status codes           | `error.code` in body, HTTP 200      |
| Caching                   | Built-in via GET + headers  | Manual, in the client               |
| Batching                  | Manual                      | Built-in (array request)            |
| Discoverability           | Browseable                  | Needs separate docs / OpenAPI       |
| Tooling                   | Postman / curl /…           | Slightly less common                |

Both are valid. Use whichever models your problem better. You can also mount both — REST for browsers, RPC at `/rpc` for internal services.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| 404 on RPC requests | Mounted as GET instead of POST | `$app->post('/rpc', $rpc)`. The server only handles POST. |
| `Method not found` for a method you registered | Spelling mismatch (`math.Add` vs `math.add`) | RPC method names are case-sensitive. |
| `Missing required parameter: $foo` even though I sent `foo` | Param is named differently in PHP — reflection uses the PHP name | Match the JSON key to the PHP parameter name (or use positional). |
| Notifications mysteriously do nothing | The method ran but its return was discarded | That's correct — notifications never get a response. |
| Internal exception leaks DB details to client | `setDebug(true)` is on | Disable in production. |
| Returns 422 / 400 — but spec says 200 | You're catching the exception and converting | Don't — let the server emit the proper RPC error envelope. |

## Cheat sheet

```php
// Build
$rpc = new JsonRpcServer($app->container());
$rpc->register('foo.bar', $callable);
$rpc->registerService(MyService::class);          // scans #[RpcMethod]
$rpc->setDebug(false);

// Mount
$app->post('/rpc', $rpc);

// Request envelope
{
  "jsonrpc": "2.0",
  "method":  "foo.bar",
  "params":  {"name":"Alice"} | [42, true],
  "id":      1                                    // omit for notification
}

// Response envelope
{ "jsonrpc": "2.0", "result": …, "id": 1 }
{ "jsonrpc": "2.0", "error": {"code": -32601, "message": "…"}, "id": 1 }
```

[OpenAPI →](openapi)
