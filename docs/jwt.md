---
layout: page
title: JWT
nav_order: 25
---

# JWT — JSON Web Tokens

Lift ships a self-contained JWT implementation: encode/decode, HS256–HS512 (symmetric) and RS256–RS512 (asymmetric), a fluent `Claims` builder, and a ready-to-use Bearer-auth middleware. **Zero external dependencies** — pure PHP plus `ext-openssl` for RSA.

> Mental model: a JWT is `{header}.{payload}.{signature}` — three base64url segments separated by dots. The signature proves the payload wasn't tampered with. If you trust the signature, you trust the payload.

## When to use JWTs

- **Stateless API auth** (mobile apps, SPAs, service-to-service).
- **Short-lived signed tickets** (password-reset links, email confirmation, magic-link login).
- **Inter-service trust** (one of your services signs a token; another verifies it without sharing DB state).

When **not** to use them:

- **Server-rendered session-cookie apps** — use [Sessions](sessions). They handle revocation cleanly.
- **Storing sensitive data** — JWT payloads are base64-encoded, **not encrypted**. Anyone with the token can read them. (For encrypted tokens, see JWE — not implemented by this library.)

## 30-second example

```php
use Lift\Jwt\Jwt;
use Lift\Jwt\Claims;

$jwt = new Jwt(secret: $_ENV['JWT_SECRET']);

// Issue a token
$token = $jwt->encode(
    Claims::make()
        ->subject('user_42')
        ->expiresIn(3600)
        ->extra(['role' => 'admin'])
        ->toArray()
);

// Later, verify and decode
try {
    $payload = $jwt->decode($token);
    // $payload['sub']   === 'user_42'
    // $payload['role']  === 'admin'
} catch (\Lift\Jwt\JwtException $e) {
    // expired, tampered, wrong key, malformed, …
}
```

## Algorithms

| Enum case               | Type      | Use when                                                |
|-------------------------|-----------|---------------------------------------------------------|
| `JwtAlgorithm::HS256`   | HMAC SHA-256 | Single-process apps. Both issuer and verifier share one secret. |
| `JwtAlgorithm::HS384`   | HMAC SHA-384 | Same, larger digest. Rarely needed.                  |
| `JwtAlgorithm::HS512`   | HMAC SHA-512 | Same, larger digest. Rarely needed.                  |
| `JwtAlgorithm::RS256`   | RSA SHA-256  | **Multi-service.** Private key signs; public key verifies. |
| `JwtAlgorithm::RS384`   | RSA SHA-384  | Same.                                                 |
| `JwtAlgorithm::RS512`   | RSA SHA-512  | Same.                                                 |

### Symmetric (HS*) — simple

```php
$jwt = new Jwt(
    secret: $_ENV['JWT_SECRET'],
    algo:   JwtAlgorithm::HS256,    // default
);
```

> The secret must be **at least** 32 random bytes. Generate one once:
> `php -r 'echo base64_encode(random_bytes(64));'`

### Asymmetric (RS*) — for distributed systems

The issuer holds the **private** key. Verifiers only need the **public** key. Compromise the public key — nothing happens; an attacker still can't forge tokens.

```php
// Issuer
$issuer = new Jwt(
    algo:       JwtAlgorithm::RS256,
    privateKey: file_get_contents('/keys/private.pem'),
);
$token = $issuer->encode($payload);

// Verifier (only needs the public key)
$verifier = new Jwt(
    algo:      JwtAlgorithm::RS256,
    publicKey: file_get_contents('/keys/public.pem'),
);
$payload = $verifier->decode($token);
```

Generate a key pair:

```bash
openssl genpkey -algorithm RSA -out private.pem -pkeyopt rsa_keygen_bits:2048
openssl rsa -in private.pem -pubout -out public.pem
```

## The `Claims` builder

Standard JWT claim names are three-letter codes. The fluent builder spells them out:

```php
$payload = Claims::make()
    ->subject('user_42')                              // sub
    ->issuer('https://api.example.com')               // iss
    ->audience('https://app.example.com')             // aud  (or array)
    ->id(Uuid::v7())                                  // jti  — unique token id
    ->issuedAt()                                      // iat  — defaults to now()
    ->expiresIn(3600)                                 // exp  — 1 hour from now
    ->notBefore(time() + 60)                          // nbf  — valid only after this time
    ->extra([
        'role'  => 'admin',
        'email' => 'a@example.com',
    ])
    ->toArray();
```

You can skip the builder entirely and pass a raw array — it's just sugar.

### What Lift validates automatically

When you call `$jwt->decode($token)` it checks:

| Claim   | Behaviour                                                          |
|---------|--------------------------------------------------------------------|
| Signature | Always verified against the configured key/secret.               |
| `exp`   | Token rejected if `now > exp` (with `leeway` tolerance).            |
| `nbf`   | Token rejected if `now < nbf` (with `leeway` tolerance).            |
| `iss`   | Verified only if you configured `issuer:` on the `Jwt` instance.    |
| `aud`   | Verified only if you configured `audience:` on the `Jwt` instance.  |

Anything else (`sub`, custom claims) is **not** validated by the library — you check them in your handler / middleware.

### Issuer / audience enforcement

```php
$jwt = new Jwt(
    secret:   $_ENV['JWT_SECRET'],
    issuer:   'https://auth.example.com',
    audience: 'https://api.example.com',
);

// decode() will reject any token whose `iss` and `aud` don't match
```

This protects you from token replay across services — a token issued *for the billing API* can't be used against the *admin API*.

### Clock skew

If your verifier's clock is a couple of seconds behind the issuer's, freshly-issued tokens may briefly look "not yet valid". Allow a small leeway:

```php
$jwt = new Jwt(secret: $_ENV['JWT_SECRET'], leeway: 30);   // ±30 seconds
```

## The middleware

Drop-in Bearer-token authentication:

```php
use Lift\Jwt\JwtMiddleware;

$jwt = new Jwt(secret: $_ENV['JWT_SECRET']);
$app->use(new JwtMiddleware($jwt));

// In any handler:
$app->get('/me', function (Request $req) {
    $claims = $req->getAttribute('jwt');         // decoded payload
    return ['user_id' => $claims['sub']];
});
```

What it does:

1. Reads `Authorization: Bearer <token>`.
2. Calls `$jwt->decode($token)`.
3. On success: attaches the payload to `$req` as `'jwt'` attribute, calls the next handler.
4. On failure: returns `401 Unauthorized` JSON + `WWW-Authenticate: Bearer` header.

### Skipping public routes

The middleware can ignore exact paths — useful when you mount it globally:

```php
$app->use(new JwtMiddleware(
    jwt:    $jwt,
    except: ['/login', '/register', '/healthz', '/openapi.json'],
));
```

For more flexible skipping (regex paths, public groups), apply per-group instead of globally:

```php
$app->group('/api', function ($g) use ($jwt) {
    $g->get('/me', /* … */);
    $g->get('/orders', /* … */);
})->middleware(new JwtMiddleware($jwt));
```

### Custom attribute name

```php
new JwtMiddleware($jwt, attribute: 'auth');
// later: $req->getAttribute('auth')
```

## End-to-end example: login + protected route

```php
use Lift\App;
use Lift\Crypto\Hasher;
use Lift\Http\Request;
use Lift\Http\Response;
use Lift\Jwt\Claims;
use Lift\Jwt\Jwt;
use Lift\Jwt\JwtMiddleware;

$app = new App();

$jwt    = new Jwt(secret: $_ENV['JWT_SECRET']);
$hasher = new Hasher();

$app->instance(Jwt::class, $jwt);
$app->instance(Hasher::class, $hasher);

// 1. Login — public
$app->post('/login', function (Request $req) use ($jwt, $hasher, $db) {
    $data = $req->validate([
        'email'    => 'required|email',
        'password' => 'required|string',
    ]);

    $user = $db->table('users')->where('email', $data['email'])->first();
    if ($user === null || !$hasher->verify($data['password'], $user['password_hash'])) {
        return Response::json(['error' => 'Invalid credentials'], 401);
    }

    $token = $jwt->encode(
        Claims::make()
            ->subject((string) $user['id'])
            ->expiresIn(3600)
            ->extra(['email' => $user['email'], 'role' => $user['role']])
            ->toArray()
    );

    return Response::json(['token' => $token, 'expires_in' => 3600]);
});

// 2. Protect everything under /api with the middleware
$app->group('/api', function ($g) {
    $g->get('/me', function (Request $req) {
        $claims = $req->getAttribute('jwt');
        return ['id' => $claims['sub'], 'email' => $claims['email']];
    });

    $g->get('/admin/stats', function (Request $req) {
        $claims = $req->getAttribute('jwt');
        if (($claims['role'] ?? '') !== 'admin') {
            throw new \Lift\Exception\ForbiddenException();
        }
        return ['users' => 42, 'orders' => 1337];
    });
})->middleware(new JwtMiddleware($jwt));

$app->run();
```

Client usage:

```bash
# 1. Login
TOKEN=$(curl -s -X POST http://localhost:8000/login \
    -H 'Content-Type: application/json' \
    -d '{"email":"a@b.c","password":"hunter2"}' | jq -r .token)

# 2. Use the token
curl http://localhost:8000/api/me \
    -H "Authorization: Bearer $TOKEN"
```

## Refresh tokens

JWT is stateless — once issued, you can't "revoke" a token before it expires. The standard fix:

- **Access tokens** are short-lived (5–15 min) JWTs.
- **Refresh tokens** are long-lived (days/weeks) opaque IDs stored in your DB. When an access token expires, the client exchanges the refresh token for a new access token. **You can revoke a refresh token** by deleting the row.

Sketch:

```php
$app->post('/refresh', function (Request $req) use ($jwt, $db) {
    $data = $req->validate(['refresh_token' => 'required|string']);

    $row = $db->table('refresh_tokens')
        ->where('token', hash('sha256', $data['refresh_token']))
        ->where('expires_at', '>', date('Y-m-d H:i:s'))
        ->first();

    if ($row === null) {
        throw new \Lift\Exception\UnauthorizedException("Bad refresh token");
    }

    // Optional: rotate — delete the old refresh token, issue a new pair.
    $access = $jwt->encode(Claims::make()->subject((string)$row['user_id'])->expiresIn(900)->toArray());
    return ['access_token' => $access];
});
```

## Storing tokens client-side

**SPA / browser**: put the access token in **memory** (a JS variable, never `localStorage`). Refresh token in an `HttpOnly; Secure; SameSite=Strict` cookie. This combination resists XSS (the access token isn't reachable by malicious scripts) and CSRF (the cookie can't be exfiltrated by other sites).

**Mobile**: platform secure storage (Keychain / Keystore).

**Server-to-server**: in process memory; re-read from a secrets manager on rotation.

## Security checklist

- ✅ HMAC secret is **at least 32 random bytes**, kept in env vars / secrets manager, **never in git**.
- ✅ Short `exp` (≤ 1 hour for access tokens).
- ✅ Always validate `iss` and `aud` when you have multiple services / clients.
- ✅ Always pin `algo` server-side — never let the token's `alg` header decide.
- ✅ Rotate RSA keys at least annually. Support multiple public keys during a rotation window (build a small `KeySelector` if you need this).
- ❌ **Never** trust the payload before `decode()` returns successfully.
- ❌ **Never** put passwords, raw PII, or session cookies inside a JWT — they're plaintext-visible.
- ❌ **Never** use `alg: none` — Lift's enum doesn't even include it, but be aware some libraries do.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `Token has expired` immediately after issuing | Issuer and verifier clocks drift | Set `leeway: 30` on the verifier. |
| 401 on every request after deploy | `JWT_SECRET` changed | Rotate carefully: tokens issued with the old secret die instantly. Use rolling deploys / two-key support. |
| `Missing or malformed Authorization header.` | Header is `Bearer token` (correct) but client forgot the space, or uses `bearer` (lowercase OK) | Lift's check is case-sensitive on `Bearer ` — make sure the client sends exactly that prefix. |
| Token's `role` field updated server-side but the user still sees the old role | JWT is *stateless*; old tokens still work until `exp` | Short `exp` + refresh tokens; or maintain a server-side "session version" the client must echo. |
| RSA verification returns `false` for valid token | Wrong key, or PEM has CRLF line endings | Re-export keys; verify with `openssl rsa -in private.pem -check`. |
| Token is huge | You stuffed lots of claims into it | Keep payloads tiny (sub + a few ids). The token is sent on every request. |

## Cheat sheet

```php
// Configure
$jwt = new Jwt(
    secret:   $_ENV['JWT_SECRET'],
    algo:     JwtAlgorithm::HS256,
    leeway:   30,
    issuer:   'https://auth.example.com',
    audience: 'https://api.example.com',
);

// Issue
$token = $jwt->encode(
    Claims::make()->subject('user_42')->expiresIn(3600)->extra([...])->toArray()
);

// Verify
try { $payload = $jwt->decode($token); }
catch (JwtException $e) { /* 401 */ }

// Middleware
$app->use(new JwtMiddleware($jwt, except: ['/login']));
$claims = $req->getAttribute('jwt');
```

[Crypto →](crypto)
