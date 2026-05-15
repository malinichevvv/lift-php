<?php

declare(strict_types=1);

namespace Lift\Jwt;

use RuntimeException;
use InvalidArgumentException;

/**
 * Lightweight JSON Web Token (JWT) implementation.
 *
 * Supports symmetric (HS256/HS384/HS512) and asymmetric (RS256/RS384/RS512)
 * signing algorithms with zero external dependencies — pure PHP + ext-openssl.
 *
 * ```php
 * $jwt = new Jwt(secret: $_ENV['JWT_SECRET']);
 *
 * // Create a token
 * $token = $jwt->encode([
 *     'sub' => 'user_123',
 *     'role' => 'admin',
 *     'exp' => time() + 3600,
 * ]);
 *
 * // Verify and decode
 * try {
 *     $payload = $jwt->decode($token);
 *     echo $payload['sub']; // user_123
 * } catch (JwtException $e) {
 *     // expired, tampered, wrong key, etc.
 * }
 * ```
 *
 * RS256 (asymmetric) example — preferred for distributed systems:
 * ```php
 * $jwt = new Jwt(
 *     algo: JwtAlgorithm::RS256,
 *     privateKey: file_get_contents('/keys/private.pem'),
 *     publicKey:  file_get_contents('/keys/public.pem'),
 * );
 * ```
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7519
 */
final class Jwt
{
    /** @var array<string, array{type: 'hmac'|'rsa', hash: string}> */
    private const ALGORITHMS = [
        'HS256' => ['type' => 'hmac', 'hash' => 'sha256'],
        'HS384' => ['type' => 'hmac', 'hash' => 'sha384'],
        'HS512' => ['type' => 'hmac', 'hash' => 'sha512'],
        'RS256' => ['type' => 'rsa',  'hash' => 'sha256'],
        'RS384' => ['type' => 'rsa',  'hash' => 'sha384'],
        'RS512' => ['type' => 'rsa',  'hash' => 'sha512'],
    ];

    /**
     * @param string                 $secret     HMAC secret (required for HS*).
     * @param JwtAlgorithm           $algo       Signing algorithm.
     * @param int                    $leeway     Seconds of clock skew tolerance for `exp`/`nbf`.
     * @param string|null            $privateKey PEM-encoded private key (required for RS*).
     * @param string|null            $publicKey  PEM-encoded public key (required for RS*).
     * @param string|null            $issuer     Expected `iss` claim value; null = skip check.
     * @param string|null            $audience   Expected `aud` claim value; null = skip check.
     */
    public function __construct(
        private readonly string $secret = '',
        private readonly JwtAlgorithm $algo = JwtAlgorithm::HS256,
        private readonly int $leeway = 0,
        private readonly ?string $privateKey = null,
        private readonly ?string $publicKey  = null,
        private readonly ?string $issuer     = null,
        private readonly ?string $audience   = null,
    ) {
        $algoMeta = self::ALGORITHMS[$algo->value];
        if ($algoMeta['type'] === 'hmac' && $secret === '') {
            throw new InvalidArgumentException('A secret is required for HMAC algorithms (HS256/HS384/HS512).');
        }
        if ($algoMeta['type'] === 'rsa' && ($privateKey === null && $publicKey === null)) {
            throw new InvalidArgumentException('A private or public key is required for RSA algorithms (RS256/RS384/RS512).');
        }
    }

    // -----------------------------------------------------------------
    // Encoding
    // -----------------------------------------------------------------

    /**
     * Encode a payload as a signed JWT string.
     *
     * Standard claims (`iat`, `exp`, `nbf`) should be in Unix timestamp format.
     * Use {@see Claims} for a fluent builder:
     * ```php
     * $token = $jwt->encode(
     *     Claims::make()->subject('user_42')->expiresIn(3600)->extra(['role' => 'admin'])->toArray()
     * );
     * ```
     *
     * @param  array<string, mixed> $payload
     * @throws RuntimeException On signing failure.
     */
    public function encode(array $payload): string
    {
        $header  = $this->b64(['typ' => 'JWT', 'alg' => $this->algo->value]);
        $body    = $this->b64($payload);
        $message = "{$header}.{$body}";

        $sig = $this->makeSignature($message);

        return "{$message}.{$sig}";
    }

    // -----------------------------------------------------------------
    // Decoding
    // -----------------------------------------------------------------

    /**
     * Verify a JWT string and return its payload.
     *
     * Validates:
     * - Signature integrity
     * - `exp` (expiration) with optional `leeway`
     * - `nbf` (not before) with optional `leeway`
     * - `iss` (issuer) if configured
     * - `aud` (audience) if configured
     *
     * @return array<string, mixed> Decoded payload.
     * @throws JwtException On any validation failure.
     */
    public function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new JwtException('Malformed token: expected 3 segments.');
        }

        [$headerB64, $bodyB64, $sigB64] = $parts;

        // 1. Verify signature
        $message = "{$headerB64}.{$bodyB64}";
        $valid = $this->algo->isHmac()
            ? hash_equals($this->makeSignature($message), $sigB64)
            : $this->verifyRsa($message, $sigB64);

        if (!$valid) {
            throw new JwtException('Token signature is invalid.');
        }

        // 2. Decode payload (header already verified via algorithm match in signature)
        $payload = $this->decode64($bodyB64);

        if (!is_array($payload)) {
            throw new JwtException('Token payload is not a JSON object.');
        }

        // 3. Validate standard claims
        $now = time();

        if (isset($payload['exp']) && ($now - $this->leeway) > (int) $payload['exp']) {
            throw new JwtException('Token has expired (exp: ' . $payload['exp'] . ').');
        }

        if (isset($payload['nbf']) && ($now + $this->leeway) < (int) $payload['nbf']) {
            throw new JwtException('Token is not yet valid (nbf: ' . $payload['nbf'] . ').');
        }

        if ($this->issuer !== null && ($payload['iss'] ?? null) !== $this->issuer) {
            throw new JwtException("Token issuer mismatch: expected '{$this->issuer}'.");
        }

        if ($this->audience !== null) {
            $aud = $payload['aud'] ?? null;
            $audList = is_array($aud) ? $aud : [$aud];
            if (!in_array($this->audience, $audList, true)) {
                throw new JwtException("Token audience mismatch: expected '{$this->audience}'.");
            }
        }

        return $payload;
    }

    /**
     * Alias for {@see encode()} — create a signed JWT string.
     *
     * @param  array<string, mixed> $payload
     */
    public function sign(array $payload): string
    {
        return $this->encode($payload);
    }

    /**
     * Alias for {@see decode()} — verify a JWT string and return its payload.
     *
     * @return array<string, mixed>
     * @throws JwtException On any validation failure.
     */
    public function verify(string $token): array
    {
        return $this->decode($token);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * @throws RuntimeException
     */
    private function makeSignature(string $message): string
    {
        $meta = self::ALGORITHMS[$this->algo->value];

        if ($meta['type'] === 'hmac') {
            return $this->b64url(hash_hmac($meta['hash'], $message, $this->secret, true));
        }

        // RSA
        $key = openssl_pkey_get_private($this->privateKey ?? '');
        if ($key === false) {
            throw new RuntimeException('Invalid RSA private key: ' . openssl_error_string());
        }

        $sig = '';
        if (!openssl_sign($message, $sig, $key, 'SHA' . substr($meta['hash'], 3))) {
            throw new RuntimeException('RSA signing failed: ' . openssl_error_string());
        }

        return $this->b64url($sig);
    }

    private function verifyRsa(string $message, string $sigB64): bool
    {
        $padded  = str_pad(strtr($sigB64, '-_', '+/'), (int) ceil(strlen($sigB64) / 4) * 4, '=');
        $sig = base64_decode($padded, strict: true);
        if ($sig === false) {
            return false;
        }
        $meta = self::ALGORITHMS[$this->algo->value];

        $key = openssl_pkey_get_public($this->publicKey ?? '');
        if ($key === false) {
            throw new RuntimeException('Invalid RSA public key: ' . openssl_error_string());
        }

        return openssl_verify($message, $sig, $key, 'SHA' . substr($meta['hash'], 3)) === 1;
    }

    /** JSON-encode and base64url-encode a value for use in a JWT segment. */
    private function b64(mixed $data): string
    {
        return $this->b64url(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** Decode a base64url segment and JSON-decode it. */
    private function decode64(string $b64): mixed
    {
        $padded  = str_pad(strtr($b64, '-_', '+/'), (int) ceil(strlen($b64) / 4) * 4, '=');
        $decoded = base64_decode($padded, strict: true);
        if ($decoded === false) {
            throw new JwtException('Invalid base64url segment in token.');
        }
        return json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
    }
}
