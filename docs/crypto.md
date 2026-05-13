---
layout: page
title: Cryptography
nav_order: 10
---

# Cryptography

Lift ships three cryptographic primitives in `Lift\Crypto`, all implemented on top of PHP's built-in functions and `ext-openssl`. No third-party libraries required.

---

## Password Hashing — `Hasher`

`Lift\Crypto\Hasher` wraps PHP's `password_hash()` API with sane defaults and a `needsRehash()` upgrade path.

**Default algorithm: Argon2id** — OWASP-recommended, memory-hard, resistant to GPU brute-force and side-channel attacks.

```php
use Lift\Crypto\Hasher;

$hasher = new Hasher();

// Hash a password
$hash = $hasher->hash('user-password');

// Verify on login
if ($hasher->verify($request->input('password'), $storedHash)) {
    // authenticated
}

// Rehash if algorithm or cost changed (call after successful verify)
if ($hasher->needsRehash($storedHash)) {
    $newHash = $hasher->hash($request->input('password'));
    // persist $newHash
}
```

### Algorithms

```php
use Lift\Crypto\Algorithm;

$hasher = new Hasher(Algorithm::Argon2id);  // default — new applications
$hasher = new Hasher(Algorithm::Argon2i);   // side-channel resistant variant
$hasher = new Hasher(Algorithm::Bcrypt);    // legacy / compatibility only
```

### Custom cost options

```php
// Argon2id with increased memory / time cost for high-value accounts
$hasher = new Hasher(Algorithm::Argon2id, [
    'memory_cost' => 65536,   // 64 MB
    'time_cost'   => 4,
    'threads'     => 2,
]);

// bcrypt with explicit cost factor
$hasher = new Hasher(Algorithm::Bcrypt, ['cost' => 12]);
```

### DI container registration

```php
$app->singleton(Hasher::class);
// or with custom options:
$app->singleton(Hasher::class, fn() => new Hasher(Algorithm::Argon2id, ['memory_cost' => 65536]));
```

---

## Authenticated Encryption — `Encrypter`

`Lift\Crypto\Encrypter` provides **AES-256-GCM** authenticated encryption (AEAD). Any tampering with the ciphertext or authentication tag causes decryption to fail with an exception — no silent data corruption.

```php
use Lift\Crypto\Encrypter;

// Generate and store a key (do this once, persist in APP_KEY env var)
$rawKey   = Encrypter::generateKey();           // 32 random bytes
$envValue = base64_encode($rawKey);             // store this in .env

// In bootstrap
$encrypter = new Encrypter(base64_decode($_ENV['APP_KEY']));

// Encrypt
$token = $encrypter->encrypt(json_encode(['user_id' => 42, 'exp' => time() + 3600]));

// Decrypt
try {
    $payload = json_decode($encrypter->decrypt($token), true);
} catch (RuntimeException $e) {
    // tampered, wrong key, or malformed payload
}
```

### Wire format

Every encrypted payload is base64-encoded and structured as:

```
[12-byte IV] [16-byte GCM tag] [variable-length ciphertext]
```

A fresh random IV is generated on every `encrypt()` call, so encrypting the same plaintext twice produces different outputs (IND-CPA secure).

### Use cases

- Encrypted cookies / session tokens
- Sensitive fields in the database
- Encrypted API tokens passed to clients
- Secure URL parameters

---

## HMAC Signing — `Signer`

`Lift\Crypto\Signer` computes and verifies HMAC signatures. Use it when you need **integrity** but not **confidentiality** — the payload is visible but cannot be tampered with.

```php
use Lift\Crypto\Signer;

$signer = new Signer($_ENV['APP_SECRET']);

// Sign a string
$signature = $signer->sign($data);          // hex string
$valid     = $signer->verify($data, $sig);  // constant-time comparison

// Self-contained signed token (base64url payload + HMAC)
$token   = $signer->signToken(['user_id' => 99, 'exp' => time() + 3600]);
$payload = $signer->verifyToken($token);    // throws RuntimeException if invalid
```

### Algorithms

Default is `sha256`. Any `hash_hmac_algos()` value is accepted:

```php
new Signer($_ENV['SECRET'], 'sha512');
new Signer($_ENV['SECRET'], 'sha384');
```

### Comparison: Signer vs JWT vs Encrypter

| | `Signer` | `Jwt` | `Encrypter` |
|---|----------|-------|-------------|
| Payload visible? | Yes | Yes | No |
| Tamper-proof? | Yes | Yes | Yes |
| Standard format? | No | RFC 7519 | No |
| Asymmetric keys? | No | Yes (RS*) | No |
| Use for... | URL tokens, API keys, cookies | Auth tokens | Sensitive data |

---

## Generating a key

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

Store the output in `.env` as `APP_KEY`. Never commit secrets to source control.
