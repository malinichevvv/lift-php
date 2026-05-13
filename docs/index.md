---
layout: home
title: Lift — The lifting PHP micro-framework
nav_order: 1
---

# Lift

**The lifting PHP micro-framework.**

Lift is a fast, minimal PHP 8.1+ micro-framework that gives you everything you need to build production APIs — a router, DI container, PSR-15 middleware, application skeletons, debug tooling, JWT, encryption, queues, and JSON-RPC — without the overhead of a full-stack framework.

## Install

```bash
composer require lift-php/lift
```

## Taste

```php
use Lift\App;
use Lift\Http\Request;
use Lift\Http\Response;
use Lift\Jwt\Claims;
use Lift\Jwt\Jwt;
use Lift\Jwt\JwtMiddleware;

$jwt = new Jwt(secret: $_ENV['JWT_SECRET']);
$app = new App();

$app->use(new JwtMiddleware($jwt, except: ['/login']));

$app->post('/login', function (Request $req) use ($jwt) {
    // validate credentials…
    $token = $jwt->encode(Claims::make()->subject('user_42')->expiresIn(3600)->toArray());
    return Response::json(['token' => $token]);
});

$app->get('/me', function (Request $req) {
    $claims = $req->getAttribute('jwt');
    return Response::json(['id' => $claims['sub']]);
});

$app->run();
```

## Why Lift?

| | Lift | Flight | Slim 4 | Lumen |
|---|:---:|:---:|:---:|:---:|
| PHP 8.1+ | ✓ | ✓ | ✓ | ✓ |
| PSR-7/11/15 | ✓ | ✗ | ✓ | ✓ |
| Autowiring DI | ✓ | ✗ | ✗ | ✓ |
| PHP attribute routing | ✓ | ✗ | ✗ | ✗ |
| JWT built-in | ✓ | ✗ | ✗ | ✗ |
| Crypto (AES/Argon2) | ✓ | ✗ | ✗ | ✗ |
| UUID v7 / ULID | ✓ | ✗ | ✗ | ✗ |
| Queue system | ✓ | ✗ | ✗ | ✓ |
| JSON-RPC 2.0 | ✓ | ✗ | ✗ | ✗ |
| Zero non-PSR deps | ✓ | ✓ | ✗ | ✗ |

## Performance highlights

Measured on PHP 8.3 with OPcache — see the [full comparison](comparison) for methodology.

- **Static route dispatch** ~534 000 req/s (O(1) hash lookup)
- **JWT encode/verify** ~460 000 ops/s
- **AES-256-GCM encrypt** ~626 000 ops/s
- **Memory footprint** ~2 MB peak at boot

[Get started →](installation)
