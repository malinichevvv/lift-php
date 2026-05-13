<?php

declare(strict_types=1);

namespace Lift\Support;

/**
 * Universally Unique Identifier (UUID) and ULID generator.
 *
 * All generators use `random_bytes()` for the random component — they are
 * cryptographically secure and safe for use as primary keys.
 *
 * | Format | Version | Sortable | Use case                                 |
 * |--------|---------|----------|------------------------------------------|
 * | UUIDv4 | RFC4122 | No       | General purpose, maximum compatibility   |
 * | UUIDv7 | RFC9562 | Yes ↑    | Database PKs, distributed IDs, logs      |
 * | ULID   | ULID    | Yes ↑    | Human-readable, Crockford base32         |
 *
 * @see https://www.rfc-editor.org/rfc/rfc9562 (UUID v7)
 * @see https://github.com/ulid/spec (ULID)
 */
final class Uuid
{
    /** Crockford Base32 alphabet (avoids ambiguous characters I, L, O, U). */
    private const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    // -----------------------------------------------------------------
    // UUID v4 — random
    // -----------------------------------------------------------------

    /**
     * Generate a version-4 (random) UUID.
     *
     * 122 bits of cryptographically random data; 6 bits used for version/variant.
     * No time component — not sortable but maximally opaque.
     *
     * ```php
     * $id = Uuid::v4();  // e.g. "550e8400-e29b-41d4-a716-446655440000"
     * ```
     */
    public static function v4(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version = 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant = RFC 4122

        return self::format($bytes);
    }

    // -----------------------------------------------------------------
    // UUID v7 — time-ordered
    // -----------------------------------------------------------------

    /**
     * Generate a version-7 (Unix timestamp + random) UUID.
     *
     * The first 48 bits are a millisecond-precision Unix timestamp, making
     * UUIDv7 monotonically increasing and B-tree friendly for database indexes.
     * Use this instead of v4 when IDs are used as primary keys.
     *
     * ```php
     * $id = Uuid::v7();  // e.g. "018f8e0d-1c2a-7xxx-xxxx-xxxxxxxxxxxx"
     *                    //       ^^^^^^^^ millisecond timestamp prefix
     * ```
     *
     * @param int|null $ms Unix timestamp in milliseconds. Defaults to current time.
     */
    public static function v7(?int $ms = null): string
    {
        $ms    = $ms ?? (int) (microtime(true) * 1000);
        $bytes = random_bytes(16);

        // Embed 48-bit millisecond timestamp in bytes 0-5
        $bytes[0] = chr(($ms >> 40) & 0xff);
        $bytes[1] = chr(($ms >> 32) & 0xff);
        $bytes[2] = chr(($ms >> 24) & 0xff);
        $bytes[3] = chr(($ms >> 16) & 0xff);
        $bytes[4] = chr(($ms >> 8)  & 0xff);
        $bytes[5] = chr($ms         & 0xff);

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70); // version = 7
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant = RFC 4122

        return self::format($bytes);
    }

    // -----------------------------------------------------------------
    // ULID — Universally Unique Lexicographically Sortable Identifier
    // -----------------------------------------------------------------

    /**
     * Generate a ULID.
     *
     * 26-character Crockford base32 string. Encodes a 48-bit millisecond
     * timestamp (10 chars) and 80 bits of randomness (16 chars).
     *
     * Lexicographically sortable, case-insensitive, URL-safe, and more compact
     * than a UUID string (26 vs 36 characters).
     *
     * ```php
     * $id = Uuid::ulid();  // e.g. "01ARZ3NDEKTSV4RRFFQ69G5FAV"
     * ```
     */
    public static function ulid(?int $ms = null): string
    {
        $ms   = $ms ?? (int) (microtime(true) * 1000);
        $rand = random_bytes(10);

        // Time component: 10 Crockford chars (48 bits, supports until year 10889)
        $ts = '';
        $t  = $ms;
        for ($i = 0; $i < 10; $i++) {
            $ts = self::CROCKFORD[$t % 32] . $ts;
            $t  = intdiv($t, 32);
        }

        // Random component: 16 Crockford chars from 80 bits
        $rnd  = '';
        $n    = 0;
        $bits = 0;
        for ($i = 0; $i < 10; $i++) {
            $n    = ($n << 8) | ord($rand[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $rnd  .= self::CROCKFORD[($n >> $bits) & 0x1f];
            }
        }

        return $ts . $rnd;
    }

    // -----------------------------------------------------------------
    // Validation & conversion
    // -----------------------------------------------------------------

    /**
     * Check whether a string is a valid UUID (v1–v8, case-insensitive).
     */
    public static function isValid(string $uuid): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid,
        );
    }

    /**
     * Check whether a string is a valid ULID.
     */
    public static function isValidUlid(string $ulid): bool
    {
        return (bool) preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $ulid);
    }

    /**
     * Convert a UUID to its 16-byte binary representation.
     *
     * Useful for storing UUIDs efficiently in a BINARY(16) database column.
     */
    public static function toBinary(string $uuid): string
    {
        return pack('H*', str_replace('-', '', $uuid));
    }

    /**
     * Convert a 16-byte binary UUID back to its string representation.
     */
    public static function fromBinary(string $binary): string
    {
        return self::format($binary);
    }

    // -----------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------

    private static function format(string $bytes): string
    {
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20),
        );
    }
}
