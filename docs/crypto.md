---
layout: page
title: Cryptography
nav_order: 26
---

# Cryptography

Three small, focused classes that cover 99 % of what a web app needs to do with cryptography — without pulling in a 5 MB Sodium-or-libsodium-or-Defuse dependency stack:

| Class            | Solves                                                  | Algorithm     |
|------------------|---------------------------------------------------------|---------------|
| `Encrypter`      | Encrypt and authenticate data at rest                   | AES-256-GCM   |
| `Hasher`         | Hash passwords (one-way, slow-by-design)                | Argon2id (default) / Argon2i / bcrypt |
| `Signer`         | Sign data so you can verify it later                    | HMAC (default SHA-256) |

All three are **stateless** and safe to register as singletons. All comparisons use timing-safe primitives (`hash_equals`, `password_verify`, GCM tag verification).

> Mental model: pick by intent. *"I want this readable later"* → `Encrypter`. *"I want to prove it's mine but not hide it"* → `Signer`. *"Compare a password without ever recovering it"* → `Hasher`.

## When to use what

| Need                                          | Use         |
|-----------------------------------------------|-------------|
| Store secrets at rest (API tokens, PII)       | `Encrypter` |
| Sign URL parameters, cookies, opaque tickets  | `Signer`    |
| Hash user passwords                           | `Hasher`    |
| Stateless API auth tokens with claims         | [JWT](jwt)  |

If you're tempted to write `md5($password)`, stop, breathe, use `Hasher`.

---

## Encrypter — AES-256-GCM

Authenticated symmetric encryption. AES-256-GCM provides **both** confidentiality (nobody can read it) and integrity (nobody can tamper with it). Decryption with the wrong key or a flipped bit throws — never silently corrupts.

Wire format (then base64-encoded):

```
[12-byte IV][16-byte GCM tag][variable ciphertext]
```

### Quick start

```php
use Lift\Crypto\Encrypter;

// One-time: generate a key and store base64-encoded
$key = base64_encode(Encrypter::generateKey());     // put in APP_KEY env var

// At boot:
$encrypter = new Encrypter(base64_decode($_ENV['APP_KEY']));

// Encrypt anything
$ciphertext = $encrypter->encrypt('hunter2');
$plaintext  = $encrypter->decrypt($ciphertext);     // 'hunter2'
```

### Generate the key

```bash
php -r "require 'vendor/autoload.php'; echo base64_encode(\Lift\Crypto\Encrypter::generateKey()) . PHP_EOL;"
# d6vK2tBh+RDxYTPbAv1mZ+iD1mPj5L0eR2RhYZmDcNk=
```

Put the result in `.env` as `APP_KEY=…`. Never commit. Never log.

### Properties of the output

- **Different ciphertext every time** even for the same plaintext (random IV per call). Don't use it as a deduplication key.
- **Authenticated.** Tampering anywhere → `RuntimeException: Decryption failed: authentication tag mismatch`.
- **Base64-encoded**, so safe in URLs / cookies / JSON / database columns.
- **About 28 bytes of overhead** vs the raw plaintext (IV + tag + base64 padding).

### Real-world patterns

#### Encrypted column

```php
$encrypter = $app->make(Encrypter::class);

$db->table('users')->insert([
    'email'         => $email,
    'oauth_token'   => $encrypter->encrypt($accessToken),    // stored encrypted
]);

$row = $db->table('users')->where('id', $id)->first();
$accessToken = $encrypter->decrypt($row['oauth_token']);
```

#### Encrypted cookie

```php
return Response::json($data)
    ->withCookie('session_extra', $encrypter->encrypt(json_encode($payload)), [
        'http_only' => true,
        'secure'    => true,
        'same_site' => 'Lax',
    ]);
```

#### One-time link with encrypted payload

```php
// Generate
$url = '/reset?t=' . urlencode($encrypter->encrypt(json_encode([
    'user_id' => 42,
    'exp'     => time() + 900,
])));

// Verify
try {
    $payload = json_decode($encrypter->decrypt($req->query('t')), true);
    if ($payload['exp'] < time()) throw new \RuntimeException('expired');
} catch (\Throwable) {
    return Response::json(['error' => 'Invalid link'], 400);
}
```

For tokens that should be **readable** to the client (e.g. they need to see the user-id), use a [Signer](#signer--hmac) instead — encryption is overkill.

### Key rotation

If `APP_KEY` ever leaks, rotate:

1. Generate a new key.
2. Keep the **old** key available as `APP_KEY_PREVIOUS`.
3. On `decrypt()` failure with the new key, try the previous one. If that succeeds, re-encrypt with the new key and save.

```php
try {
    $plaintext = $newEncrypter->decrypt($ciphertext);
} catch (\RuntimeException) {
    $plaintext  = $oldEncrypter->decrypt($ciphertext);          // grace
    $ciphertext = $newEncrypter->encrypt($plaintext);           // persist new
}
```

Two weeks later, delete the old key.

### Limits

- Plaintext length: practically unbounded (we never load more than once into memory; the limit is whatever PHP allows).
- Don't try to encrypt **streams** with this class — it's one-shot. For large files, encrypt chunks individually.

---

## Hasher — password hashing

Wraps PHP's native `password_hash()` / `password_verify()`. **Argon2id** is the default — OWASP-recommended, resistant to both GPU brute-force and side-channel attacks.

### Quick start

```php
use Lift\Crypto\Hasher;

$hasher = new Hasher();           // Argon2id, default options

// Hash on signup
$hash = $hasher->hash('hunter2');
$db->table('users')->insert(['email' => $email, 'password_hash' => $hash]);

// Verify on login
$user = $db->table('users')->where('email', $email)->first();
if ($user === null || !$hasher->verify($plainPassword, $user['password_hash'])) {
    return Response::json(['error' => 'Invalid credentials'], 401);
}
```

### Why Argon2id

| Algorithm  | Memory-hard | GPU-resistant | OWASP-rec'd |
|------------|:-----------:|:-------------:|:-----------:|
| MD5 / SHA-* | ❌          | ❌            | NEVER       |
| bcrypt     | partial     | ⚠️ (modern GPUs help) | OK |
| Argon2i    | ✅          | ✅            | ✅          |
| **Argon2id** | ✅        | ✅            | ✅ default  |

Argon2id mixes Argon2i (side-channel resistant) and Argon2d (GPU resistant) — the best of both. Use it unless you have a hard constraint to support legacy.

### Tuning costs

Argon2 parameters control how much CPU/RAM hashing costs (and therefore how slow it is to crack). Defaults are good; for high-security apps, bump them:

```php
$hasher = new Hasher(
    algorithm: Algorithm::Argon2id,
    options:   [
        'memory_cost' => 65_536,   // KiB  — 64 MiB
        'time_cost'   => 4,        // iterations
        'threads'     => 2,
    ],
);
```

Aim for hashing time ≈ 100–300 ms on your production hardware. Benchmark:

```php
$start = hrtime(true);
$hasher->hash('test');
$ms = (hrtime(true) - $start) / 1e6;
echo "hash took {$ms} ms\n";
```

### bcrypt for legacy compatibility

```php
$hasher = new Hasher(algorithm: Algorithm::Bcrypt, options: ['cost' => 12]);
```

Notes:

- bcrypt silently truncates input to **72 bytes** — multi-byte passwords can lose entropy. Argon2 doesn't.
- Cost factor 12 is the minimum modern guideline; 14+ for security-sensitive apps.

### Re-hashing on login

When you upgrade Argon2 cost factors (or switch from bcrypt to Argon2id), users on the old hash should be upgraded transparently:

```php
if ($hasher->verify($plainPassword, $row['password_hash'])) {
    if ($hasher->needsRehash($row['password_hash'])) {
        $newHash = $hasher->hash($plainPassword);
        $db->table('users')->where('id', $row['id'])->update(['password_hash' => $newHash]);
    }
    // … log them in …
}
```

`needsRehash()` returns true when the stored hash uses a weaker algorithm or smaller cost than the current `Hasher` config.

---

## Signer — HMAC

`Signer` doesn't hide data — it proves that data came from you. Use cases:

- Signed URL parameters (`/files?id=42&exp=…&sig=…`)
- Stateless one-time tokens (password reset, email confirm)
- Webhook signatures (`X-Signature: sha256=…`)
- Cookies you want the client to read but not modify

### Quick start

```php
use Lift\Crypto\Signer;

$signer = new Signer($_ENV['APP_SECRET']);          // any non-empty secret
$signer = new Signer($_ENV['APP_SECRET'], 'sha512'); // any algo from hash_hmac_algos()
```

### Raw sign / verify

```php
$payload = $userId . '|' . $exp;

$sig = $signer->sign($payload);                 // 64-char hex
$ok  = $signer->verify($payload, $sig);         // bool, timing-safe

if (!$ok) { /* tampered */ }
```

### Self-contained tokens

The high-level helper packs a payload + signature into one URL-safe string:

```php
$token = $signer->signToken([
    'user_id' => 42,
    'action'  => 'reset_password',
    'exp'     => time() + 900,
]);
// → 'eyJ1c2VyX2lkIjo0Mn0.b6f3c0a9…'

try {
    $payload = $signer->verifyToken($token);     // returns the original array
    if ($payload['exp'] < time()) throw new \RuntimeException('expired');
} catch (\RuntimeException $e) {
    // bad signature, malformed, or expired
}
```

`signToken()` / `verifyToken()` differ from JWT in two ways:

- No header — the algorithm is fixed by the `Signer` instance (no `alg` confusion attacks).
- Payload is base64url-encoded JSON. **Not encrypted** — anyone can read it.

For interop with other systems that expect *standard* JWTs, use [JWT](jwt) instead.

### Webhook verification

A common pattern: you accept webhooks signed with HMAC-SHA256 of the raw body.

```php
$app->post('/webhook', function (Request $req) use ($signer) {
    $raw    = (string) $req->getBody();
    $sig    = $req->getHeaderLine('X-Signature');

    if (!$signer->verify($raw, $sig)) {
        throw new \Lift\Exception\UnauthorizedException("Bad signature");
    }

    $event = json_decode($raw, true);
    // … handle …

    return Response::noContent();
});
```

> Always sign and verify the **raw bytes**, not the parsed body. Reparsing changes whitespace, key order, etc. — and the signature won't match.

### Signed download links

```php
$expires = time() + 60;
$signature = $signer->sign("download:$fileId:$expires");
$url = "/download?id={$fileId}&exp={$expires}&sig={$signature}";

// Handler
$app->get('/download', function (Request $req) use ($signer, $fs) {
    $id  = (int) $req->query('id');
    $exp = (int) $req->query('exp');
    $sig = $req->query('sig', '');

    if ($exp < time() || !$signer->verify("download:{$id}:{$exp}", $sig)) {
        return Response::json(['error' => 'Link expired or invalid'], 403);
    }
    // … stream the file …
});
```

The user can copy the URL; without the secret they can't forge a new one.

---

## Registering everything in the container

```php
use Lift\Crypto\Encrypter;
use Lift\Crypto\Hasher;
use Lift\Crypto\Signer;

$app->singleton(Encrypter::class, fn() => new Encrypter(base64_decode($_ENV['APP_KEY'])));
$app->singleton(Hasher::class);                                       // autowired with defaults
$app->singleton(Signer::class,    fn() => new Signer($_ENV['APP_SECRET']));
```

Then anywhere — handler, controller, service — type-hint the class:

```php
class TokenService
{
    public function __construct(
        private readonly Encrypter $cipher,
        private readonly Signer    $signer,
    ) {}
}
```

## Security checklist

- ✅ Keys live in env vars / secrets manager, **never** in git.
- ✅ `APP_KEY` (Encrypter) is 32 raw bytes (= 44-char base64).
- ✅ `APP_SECRET` (Signer / CSRF) is ≥ 32 random bytes.
- ✅ Use Argon2id for passwords. Re-hash on login when `needsRehash()` is true.
- ✅ Sign the **raw bytes** for webhook verification; never the parsed JSON.
- ✅ Always check expiry alongside signature (`exp` claim in token, `?exp=…` in URL).
- ❌ Don't roll your own crypto algorithm — these classes already wrap the right primitives.
- ❌ Don't `md5/sha1` passwords. Ever. (Yes, even with a "salt".)
- ❌ Don't `==` compare hashes/signatures. Use `hash_equals()` (which `Signer` does internally).
- ❌ Don't reuse one key for multiple purposes (encrypt vs sign vs cookies) — use distinct env vars.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `InvalidArgumentException: Encryption key must be exactly 32 bytes` | You passed the base64 string, not the decoded bytes | `new Encrypter(base64_decode($_ENV['APP_KEY']))`. |
| `Decryption failed: authentication tag mismatch` | Wrong key, or someone flipped a bit (or you base64-encoded it twice) | Re-check the key; never edit ciphertext manually. |
| `password_verify()` returns false for the right password | bcrypt truncated the password to 72 bytes during hash | Switch to Argon2id and re-hash everyone on next login. |
| Webhook signature fails | You verified against `json_decode($body)`, not the raw bytes | Use `(string) $req->getBody()` BEFORE any parsing. |
| `Hasher::hash()` takes 2 seconds | Argon2 costs are very high | Lower `memory_cost` / `time_cost`; aim for ~200 ms. |
| Signature URLs leak the user_id | The payload is base64-encoded JSON, not encrypted | Use `Encrypter` if you need to hide the contents. |

## Cheat sheet

```php
// Encrypter
$e = new Encrypter(base64_decode($_ENV['APP_KEY']));
$ct = $e->encrypt('secret');
$pt = $e->decrypt($ct);

// Hasher
$h = new Hasher();                              // Argon2id by default
$hash = $h->hash($password);
$h->verify($password, $hash);                   // bool
$h->needsRehash($hash);                         // bool, after upgrade

// Signer
$s = new Signer($_ENV['APP_SECRET']);
$sig = $s->sign($data);                         // hex
$s->verify($data, $sig);                        // bool, timing-safe
$tok = $s->signToken(['user_id' => 1, 'exp' => time() + 60]);
$payload = $s->verifyToken($tok);
```

[Queues →](queues)
