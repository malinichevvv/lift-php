<?php

declare(strict_types=1);

namespace Lift\Crypto;

use RuntimeException;

/**
 * HMAC-based data signing and verification.
 *
 * Use for signing URL tokens, API keys, cookies, or any data that must be
 * verified as untampered without encryption.
 *
 * All comparisons use `hash_equals()` to prevent timing attacks.
 *
 * ```php
 * $signer = new Signer($_ENV['APP_SECRET']);
 *
 * // Sign a payload
 * $token = $signer->signToken(['user_id' => 42, 'exp' => time() + 3600]);
 *
 * // Later, verify and decode
 * try {
 *     $payload = $signer->verifyToken($token);
 *     echo $payload['user_id']; // 42
 * } catch (\RuntimeException $e) {
 *     // invalid or tampered token
 * }
 * ```
 */
final class Signer
{
    /**
     * @param string $secret A strong secret key (min 32 bytes recommended).
     * @param string $algo   HMAC algorithm — see `hash_hmac_algos()` for options.
     */
    public function __construct(
        private readonly string $secret,
        private readonly string $algo = 'sha256',
    ) {
        if ($secret === '') {
            throw new \InvalidArgumentException('Signer secret must not be empty.');
        }
        if (!in_array($algo, hash_hmac_algos(), true)) {
            throw new \InvalidArgumentException("Unsupported HMAC algorithm: {$algo}");
        }
    }

    /**
     * Compute an HMAC signature for arbitrary data.
     *
     * @return string Lowercase hexadecimal HMAC signature.
     */
    public function sign(string $data): string
    {
        return hash_hmac($this->algo, $data, $this->secret);
    }

    /**
     * Verify that a signature matches the data.
     *
     * Uses a constant-time comparison to prevent timing attacks.
     */
    public function verify(string $data, string $signature): bool
    {
        return hash_equals($this->sign($data), $signature);
    }

    /**
     * Create a self-contained signed token: `base64url(payload).hmac`.
     *
     * The payload is JSON-encoded and base64url-encoded. The signature covers
     * the encoded payload, so the full token is safe to include in URLs.
     *
     * @param array<string, mixed> $payload
     */
    public function signToken(array $payload): string
    {
        $encoded = $this->base64url(json_encode($payload, JSON_THROW_ON_ERROR));
        return $encoded . '.' . $this->sign($encoded);
    }

    /**
     * Decode and verify a token created by {@see signToken()}.
     *
     * @return array<string, mixed> The decoded payload.
     * @throws RuntimeException If the signature is invalid or the payload cannot be decoded.
     */
    public function verifyToken(string $token): array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            throw new RuntimeException('Invalid signed token format.');
        }

        [$encoded, $signature] = $parts;

        if (!$this->verify($encoded, $signature)) {
            throw new RuntimeException('Signed token signature is invalid — possible tampering.');
        }

        $payload = json_decode($this->base64urlDecode($encoded), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Signed token payload is not a JSON object.');
        }

        return $payload;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64urlDecode(string $data): string
    {
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + 4 - strlen($data) % 4, '=');
        $decoded = base64_decode($padded, strict: true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid base64url data in token.');
        }
        return $decoded;
    }
}
