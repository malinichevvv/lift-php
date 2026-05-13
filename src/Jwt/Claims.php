<?php

declare(strict_types=1);

namespace Lift\Jwt;

/**
 * Fluent builder for JWT claim sets.
 *
 * ```php
 * $payload = Claims::make()
 *     ->subject('user_42')
 *     ->issuer('https://api.example.com')
 *     ->audience('https://app.example.com')
 *     ->expiresIn(3600)
 *     ->extra(['role' => 'admin'])
 *     ->toArray();
 *
 * $token = $jwt->encode($payload);
 * ```
 */
final class Claims
{
    /** @var array<string, mixed> */
    private array $claims = [];

    private function __construct() {}

    public static function make(): self
    {
        return new self();
    }

    /** Set the `sub` (subject) claim. */
    public function subject(string $sub): self
    {
        $this->claims['sub'] = $sub;
        return $this;
    }

    /** Set the `iss` (issuer) claim. */
    public function issuer(string $iss): self
    {
        $this->claims['iss'] = $iss;
        return $this;
    }

    /** Set the `aud` (audience) claim — string or array. */
    public function audience(string|array $aud): self
    {
        $this->claims['aud'] = $aud;
        return $this;
    }

    /** Set the `jti` (JWT ID) claim. */
    public function id(string $jti): self
    {
        $this->claims['jti'] = $jti;
        return $this;
    }

    /** Set the `iat` (issued-at) claim. Defaults to now. */
    public function issuedAt(?int $iat = null): self
    {
        $this->claims['iat'] = $iat ?? time();
        return $this;
    }

    /**
     * Set the `exp` (expiration) claim as an absolute Unix timestamp.
     *
     * @see expiresIn() for relative expiry.
     */
    public function expiresAt(int $exp): self
    {
        $this->claims['exp'] = $exp;
        return $this;
    }

    /**
     * Set the `exp` claim relative to now.
     *
     * @param int $seconds Number of seconds from now until the token expires.
     */
    public function expiresIn(int $seconds): self
    {
        $this->claims['exp'] = time() + $seconds;
        return $this;
    }

    /** Set the `nbf` (not-before) claim as an absolute Unix timestamp. */
    public function notBefore(int $nbf): self
    {
        $this->claims['nbf'] = $nbf;
        return $this;
    }

    /**
     * Merge arbitrary additional claims.
     *
     * @param array<string, mixed> $extra
     */
    public function extra(array $extra): self
    {
        $this->claims = array_merge($this->claims, $extra);
        return $this;
    }

    /**
     * Return the assembled claims array, ready for {@see Jwt::encode()}.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->claims;
    }
}
