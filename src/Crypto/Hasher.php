<?php

declare(strict_types=1);

namespace Lift\Crypto;

/**
 * Secure password hashing using PHP's native `password_hash()` API.
 *
 * Defaults to Argon2id — the OWASP-recommended algorithm that is resistant
 * to both GPU brute-force and side-channel attacks.
 *
 * ```php
 * $hasher = new Hasher();
 *
 * $hash = $hasher->hash('secret123');           // store in DB
 * $ok   = $hasher->verify('secret123', $hash);  // true
 *
 * // On login, rehash if the algorithm or cost factor changed
 * if ($ok && $hasher->needsRehash($hash)) {
 *     $newHash = $hasher->hash('secret123');
 *     // persist $newHash
 * }
 * ```
 *
 * The class is intentionally stateless; register it as a singleton in the
 * DI container:
 * ```php
 * $app->singleton(Hasher::class);
 * ```
 */
final class Hasher
{
    /**
     * @param Algorithm $algorithm  Hashing algorithm (default: Argon2id).
     * @param array<string,mixed> $options  Algorithm-specific options passed to
     *                                      `password_hash()`. E.g.
     *                                      `['memory_cost' => 65536, 'time_cost' => 4]`
     *                                      for Argon2.
     */
    public function __construct(
        private readonly Algorithm $algorithm = Algorithm::Argon2id,
        private readonly array $options = [],
    ) {}

    /**
     * Hash a plaintext password.
     *
     * Never store the plaintext; store only the returned hash string.
     *
     * @throws \RuntimeException If hashing fails (should never happen with valid input).
     */
    public function hash(string $password): string
    {
        $hash = password_hash($password, $this->algorithm->value, $this->options);
        if ($hash === false) {
            throw new \RuntimeException('Password hashing failed');
        }
        return $hash;
    }

    /**
     * Verify a plaintext password against a stored hash.
     *
     * Uses a timing-safe comparison internally (via `password_verify`).
     */
    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Check whether an existing hash should be rehashed.
     *
     * Returns true when:
     * - The hash was created with a different algorithm than the current one.
     * - The cost factor (memory_cost / time_cost / cost) has changed.
     *
     * Call this after a successful {@see verify()} and rehash if true.
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm->value, $this->options);
    }
}
