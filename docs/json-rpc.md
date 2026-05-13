---
layout: page
title: JSON-RPC 2.0
nav_order: 14
---

# JSON-RPC 2.0

`Lift\JsonRpc\JsonRpcServer` is a full [JSON-RPC 2.0](https://www.jsonrpc.org/specification) server that handles single requests, batch requests, and notifications. Mount it as an invokable handler on any route.

---

## Quick start

```php
use Lift\JsonRpc\JsonRpcServer;

$rpc = new JsonRpcServer();

$rpc->register('math.add', fn(int $a, int $b): int => $a + $b);
$rpc->register('math.multiply', fn(int $a, int $b): int => $a * $b);

$rpc->register('users.get', function (int $id) use ($db): array {
    return $db->find('users', $id) ?? throw new \RuntimeException('Not found', -32000);
});

$app->post('/rpc', $rpc);
```

---

## Registering methods

### Closure

```php
$rpc->register('greet', fn(string $name): string => "Hello, {$name}!");
```

### Service class with `#[RpcMethod]`

Scan a class and register all methods annotated with `#[RpcMethod]`:

```php
use Lift\JsonRpc\Attribute\RpcMethod;

class MathService
{
    #[RpcMethod]
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    #[RpcMethod(name: 'subtract')]
    public function minus(int $a, int $b): int
    {
        return $a - $b;
    }

    #[RpcMethod(name: 'math.hello', description: 'Returns a greeting')]
    public function hello(string $name = 'world'): string
    {
        return "Hello, {$name}!";
    }
}

$rpc->registerService(MathService::class);
// Registers: MathService.add, subtract, math.hello
```

---

## Calling methods

### Single request

```json
POST /rpc
Content-Type: application/json

{"jsonrpc":"2.0","method":"math.add","params":{"a":3,"b":4},"id":1}
```

```json
{"jsonrpc":"2.0","result":7,"id":1}
```

### Positional parameters

```json
{"jsonrpc":"2.0","method":"math.add","params":[3,4],"id":1}
```

### Notification (no response)

```json
{"jsonrpc":"2.0","method":"log.event","params":{"type":"click"}}
```

No response body is returned for notifications.

### Batch request

```json
[
  {"jsonrpc":"2.0","method":"math.add","params":[1,2],"id":1},
  {"jsonrpc":"2.0","method":"math.multiply","params":[3,4],"id":2},
  {"jsonrpc":"2.0","method":"does.not.exist","id":3}
]
```

```json
[
  {"jsonrpc":"2.0","result":3,"id":1},
  {"jsonrpc":"2.0","result":12,"id":2},
  {"jsonrpc":"2.0","error":{"code":-32601,"message":"Method not found"},"id":3}
]
```

---

## Error handling

Throw any exception from a handler to return an error response. The error code defaults to `-32000` (application error).

```php
$rpc->register('users.delete', function (int $id) use ($db) {
    if (!$db->exists('users', $id)) {
        throw new \RuntimeException('User not found', -32001);
    }
    $db->delete('users', $id);
});
```

### Standard JSON-RPC error codes

| Code | Meaning |
|------|---------|
| `-32700` | Parse error |
| `-32600` | Invalid request |
| `-32601` | Method not found |
| `-32602` | Invalid params |
| `-32603` | Internal error |
| `-32000` to `-32099` | Application-defined errors |

---

## Middleware on the RPC endpoint

Apply rate limiting, authentication, or logging to the `/rpc` route like any other:

```php
$app->post('/rpc', $rpc)
    ->middleware(new JwtMiddleware($jwt))
    ->middleware(new RateLimitMiddleware($cache, maxRequests: 100, windowSeconds: 60));
```

---

## App shortcut

`App::rpc()` returns (or creates) the default `JsonRpcServer` singleton and registers it on `POST /rpc`:

```php
$app->rpc()->register('ping', fn() => 'pong');
// Equivalent to:
$app->post('/rpc', $app->rpc());
```
