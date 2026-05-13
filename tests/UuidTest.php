<?php

declare(strict_types=1);

use Lift\Support\Uuid;
use PHPUnit\Framework\TestCase;

class UuidTest extends TestCase
{
    // ---------------------------------------------------------------
    // UUID v4
    // ---------------------------------------------------------------

    public function testV4HasCorrectFormat(): void
    {
        $uuid = Uuid::v4();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid,
        );
    }

    public function testV4IsUnique(): void
    {
        $ids = array_map(fn($_) => Uuid::v4(), range(1, 100));
        $this->assertSame(100, count(array_unique($ids)));
    }

    public function testV4IsValidUuid(): void
    {
        $this->assertTrue(Uuid::isValid(Uuid::v4()));
    }

    public function testV4VersionBit(): void
    {
        $uuid = Uuid::v4();
        $this->assertSame('4', $uuid[14]);
    }

    public function testV4VariantBits(): void
    {
        $uuid    = Uuid::v4();
        $variant = strtolower($uuid[19]);
        $this->assertContains($variant, ['8', '9', 'a', 'b']);
    }

    public function testV4HasCorrectLength(): void
    {
        $this->assertSame(36, strlen(Uuid::v4()));
    }

    // ---------------------------------------------------------------
    // UUID v7
    // ---------------------------------------------------------------

    public function testV7HasCorrectFormat(): void
    {
        $uuid = Uuid::v7();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid,
        );
    }

    public function testV7VersionBit(): void
    {
        $uuid = Uuid::v7();
        $this->assertSame('7', $uuid[14]);
    }

    public function testV7VariantBits(): void
    {
        $uuid    = Uuid::v7();
        $variant = strtolower($uuid[19]);
        $this->assertContains($variant, ['8', '9', 'a', 'b']);
    }

    public function testV7IsMonotonicallyIncreasing(): void
    {
        $ids = [];
        for ($i = 0; $i < 20; $i++) {
            $ids[] = Uuid::v7();
            usleep(1000); // 1ms gap
        }
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids, 'UUIDv7 should be lexicographically monotone');
    }

    public function testV7WithExplicitTimestamp(): void
    {
        $ms   = 1_700_000_000_000; // fixed timestamp
        $uuid = Uuid::v7($ms);

        // Extract the 48-bit timestamp from the first 12 hex chars
        $hex  = str_replace('-', '', $uuid);
        $tsHex = substr($hex, 0, 12);
        $decoded = hexdec($tsHex);

        $this->assertSame($ms, $decoded);
    }

    public function testV7IsUnique(): void
    {
        $ids = array_map(fn($_) => Uuid::v7(), range(1, 100));
        $this->assertSame(100, count(array_unique($ids)));
    }

    public function testV7IsValidUuid(): void
    {
        $this->assertTrue(Uuid::isValid(Uuid::v7()));
    }

    // ---------------------------------------------------------------
    // ULID
    // ---------------------------------------------------------------

    public function testUlidHasCorrectLength(): void
    {
        $this->assertSame(26, strlen(Uuid::ulid()));
    }

    public function testUlidUsesOnlyCrockfordAlphabet(): void
    {
        $ulid = Uuid::ulid();
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $ulid);
    }

    public function testUlidIsMonotonicallyIncreasing(): void
    {
        $ids = [];
        for ($i = 0; $i < 20; $i++) {
            $ids[] = Uuid::ulid();
            usleep(1000); // 1ms gap
        }
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids, 'ULIDs should be lexicographically monotone');
    }

    public function testUlidWithExplicitTimestamp(): void
    {
        $ms   = 1_700_000_000_000;
        $ulid = Uuid::ulid($ms);

        // Decode the 10-char time prefix from Crockford base32
        $crockford = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $prefix    = substr($ulid, 0, 10);
        $decoded   = 0;
        for ($i = 0; $i < 10; $i++) {
            $decoded = $decoded * 32 + strpos($crockford, $prefix[$i]);
        }
        $this->assertSame($ms, $decoded);
    }

    public function testUlidIsUnique(): void
    {
        $ids = array_map(fn($_) => Uuid::ulid(), range(1, 100));
        $this->assertSame(100, count(array_unique($ids)));
    }

    public function testUlidIsValidUlid(): void
    {
        $this->assertTrue(Uuid::isValidUlid(Uuid::ulid()));
    }

    // ---------------------------------------------------------------
    // isValid
    // ---------------------------------------------------------------

    public function testIsValidAcceptsV4(): void
    {
        $this->assertTrue(Uuid::isValid('550e8400-e29b-41d4-a716-446655440000'));
    }

    public function testIsValidAcceptsV7(): void
    {
        $this->assertTrue(Uuid::isValid(Uuid::v7()));
    }

    public function testIsValidRejectsEmpty(): void
    {
        $this->assertFalse(Uuid::isValid(''));
    }

    public function testIsValidRejectsMissingDashes(): void
    {
        $this->assertFalse(Uuid::isValid('550e8400e29b41d4a716446655440000'));
    }

    public function testIsValidRejectsTooShort(): void
    {
        $this->assertFalse(Uuid::isValid('550e8400-e29b-41d4-a716-4466554400'));
    }

    public function testIsValidRejectsInvalidVersion(): void
    {
        $this->assertFalse(Uuid::isValid('550e8400-e29b-91d4-a716-446655440000'));
    }

    public function testIsValidCaseInsensitive(): void
    {
        $this->assertTrue(Uuid::isValid('550E8400-E29B-41D4-A716-446655440000'));
    }

    // ---------------------------------------------------------------
    // isValidUlid
    // ---------------------------------------------------------------

    public function testIsValidUlidAcceptsValidUlid(): void
    {
        $this->assertTrue(Uuid::isValidUlid('01ARZ3NDEKTSV4RRFFQ69G5FAV'));
    }

    public function testIsValidUlidRejectsTooShort(): void
    {
        $this->assertFalse(Uuid::isValidUlid('01ARZ3NDEKTSV4RRFFQ69G5FA'));
    }

    public function testIsValidUlidRejectsTooLong(): void
    {
        $this->assertFalse(Uuid::isValidUlid('01ARZ3NDEKTSV4RRFFQ69G5FAVX'));
    }

    public function testIsValidUlidRejectsAmbiguousChars(): void
    {
        // I, L, O, U are excluded from Crockford base32
        $this->assertFalse(Uuid::isValidUlid('ILARZOOOOOOOOOOOOOOOOOOOOO'));
    }

    public function testIsValidUlidCaseInsensitive(): void
    {
        $this->assertTrue(Uuid::isValidUlid(strtolower('01ARZ3NDEKTSV4RRFFQ69G5FAV')));
    }

    // ---------------------------------------------------------------
    // Binary encoding round-trip
    // ---------------------------------------------------------------

    public function testToBinaryProduces16Bytes(): void
    {
        $uuid = Uuid::v4();
        $this->assertSame(16, strlen(Uuid::toBinary($uuid)));
    }

    public function testBinaryRoundTrip(): void
    {
        $uuid   = Uuid::v4();
        $binary = Uuid::toBinary($uuid);
        $this->assertSame(strtolower($uuid), strtolower(Uuid::fromBinary($binary)));
    }

    public function testV7BinaryRoundTrip(): void
    {
        $uuid = Uuid::v7();
        $this->assertSame(strtolower($uuid), strtolower(Uuid::fromBinary(Uuid::toBinary($uuid))));
    }

    public function testBinaryPreservesTimestampPrefix(): void
    {
        $ms     = 1_700_000_000_123;
        $uuid   = Uuid::v7($ms);
        $binary = Uuid::toBinary($uuid);
        $back   = Uuid::fromBinary($binary);

        $hex      = str_replace('-', '', $back);
        $tsHex    = substr($hex, 0, 12);
        $decoded  = hexdec($tsHex);
        $this->assertSame($ms, $decoded);
    }
}
