---
layout: page
title: JWT
nav_order: 11
---

# JWT — JSON Web Tokens

`Lift\Jwt\Jwt` is a zero-dependency JWT implementation (RFC 7519) with support for both symmetric (HMAC) and asymmetric (RSA) algorithms.

---

## Quick start

```php
use Lift\Jwt\Claims;
use Lift\Jwt\Jwt;
use Lift\Jwt\JwtException;

$jwt = new Jwt(secret: $_ENV['JWT_SECRET']);

// Encode
$token = $jwt->encode(
    Claims::make()
        ->subject('user_42')
        ->expiresIn(3600)
        ->extra(['role' => 'admin'])
        ->toArray()
);

// Decode and verify
try {
    $payload = $jwt->decode($token);
    echo $payload['sub'];   // user_42
    echo $payload['role'];  // admin
} catch (JwtException $e) {
    // expired, tampered, wrong key, issuer mismatch, etc.
}
```

---

## Constructor options

```php
$jwt = new Jwt(
    secret:    $_ENV['JWT_SECRET'],      // required for HS* algorithms
    algo:      JwtAlgorithm::HS256,      // default
    leeway:    30,                       // seconds of clock skew tolerance
    issuer:    'https://api.example.com', // validate iss claim if set
    audience:  'https://app.example.com', // validate aud claim if set
    privateKey: null,                    // PEM private key (RS* only)
    publicKey:  null,                    // PEM public key (RS* only)
);
```

---

## Algorithms

### Symmetric (HMAC) — `HS256`, `HS384`, `HS512`

Single shared secret. Fast and simple. Use for monolithic apps or trusted internal services.

```php
$jwt = new Jwt(secret: $_ENV['JWT_SECRET'], algo: JwtAlgorithm::HS256);
```

### Asymmetric (RSA) — `RS256`, `RS384`, `RS512`

Private key signs; public key verifies. Allows multiple services to verify tokens without sharing the signing secret.

```php
$jwt = new Jwt(
    algo:       JwtAlgorithm::RS256,
    privateKey: file_get_contents('/keys/private.pem'),  // for signing
    publicKey:  file_get_contents('/keys/public.pem'),   // for verification
);
```

Generate an RSA key pair:

```bash
openssl genrsa -out private.pem 2048
openssl rsa -in private.pem -pubout -out public.pem
```

---

## Claims builder

`Claims::make()` is a fluent builder that produces a standard-compliant payload array.

```php
$payload = Claims::make()
    ->subject('user_42')                          // sub
    ->issuer('https://api.example.com')            // iss
    ->audience('https://app.example.com')          // aud
    ->expiresIn(3600)                              // exp = now + 3600
    ->notBefore(time() - 5)                        // nbf (with 5s leeway)
    ->issuedAt()                                   // iat = now
    ->id('unique-token-id')                        // jti
    ->extra(['role' => 'admin', 'plan' => 'pro'])  // custom claims
    ->toArray();
```

### Time helpers

| Method | Sets |
|--------|------|
| `expiresIn(int $seconds)` | `exp = time() + $seconds` |
| `expiresAt(int $timestamp)` | `exp = $timestamp` |
| `notBefore(int $timestamp)` | `nbf = $timestamp` |
| `issuedAt(?int $ts = null)` | `iat = $ts ?? time()` |

---

## Standard claims validated by `decode()`

| Claim | Validation |
|-------|------------|
| `exp` | Rejects if `now > exp + leeway` |
| `nbf` | Rejects if `now + leeway < nbf` |
| `iss` | Checked when `$issuer` is set in constructor |
| `aud` | Checked when `$audience` is set; supports string or array |

---

## JWT Authentication Middleware

`JwtMiddleware` extracts and verifies a `Bearer` token from the `Authorization` header and injects the decoded payload as a request attribute.

```php
use Lift\Jwt\JwtMiddleware;

// Global (protects all routes)
$app->use(new JwtMiddleware($jwt));

// Per-group (protects /api/* only)
$app->group('/api', function ($g) use ($jwt) {
    $g->middleware(new JwtMiddleware($jwt));
    $g->get('/me', [UserController::class, 'me']);
});
```

### Accessing claims in a handler

```php
$app->get('/me', function (Request $req) {
    $claims = $req->getAttribute('jwt');
    return Response::json([
        'id'   => $claims['sub'],
        'role' => $claims['role'],
    ]);
});
```

### Excluding paths

```php
new JwtMiddleware($jwt, except: ['/health', '/metrics'])
```

### Custom attribute name

```php
new JwtMiddleware($jwt, attribute: 'token_data')
// $request->getAttribute('token_data')
```

### On failure

Returns `401 Unauthorized` with a JSON body:

```json
{ "error": "Unauthorized", "message": "Token has expired (exp: 1718700000)." }
```

---

## Security notes

- All signature comparisons use `hash_equals()` (constant-time) to prevent timing attacks.
- RSA decoding uses the **public key** via `openssl_verify()` — the private key is never needed to decode.
- The `alg: none` attack is not possible — the algorithm is taken from the constructor, not the token header.
