<?php

declare(strict_types=1);

use Lift\Crypto\Algorithm;
use Lift\Crypto\Encrypter;
use Lift\Crypto\Hasher;
use Lift\Crypto\Signer;
use PHPUnit\Framework\TestCase;

class CryptoTest extends TestCase
{
    // ---------------------------------------------------------------
    // Algorithm enum
    // ---------------------------------------------------------------

    public function testAlgorithmEnumValuesMatchPhpConstants(): void
    {
        $this->assertSame(PASSWORD_ARGON2ID, Algorithm::Argon2id->value);
        $this->assertSame(PASSWORD_ARGON2I,  Algorithm::Argon2i->value);
        $this->assertSame(PASSWORD_BCRYPT,   Algorithm::Bcrypt->value);
    }

    public function testAlgorithmEnumFromValue(): void
    {
        $this->assertSame(Algorithm::Argon2id, Algorithm::from('argon2id'));
        $this->assertSame(Algorithm::Argon2i,  Algorithm::from('argon2i'));
        $this->assertSame(Algorithm::Bcrypt,   Algorithm::from('2y'));
    }

    public function testAllAlgorithmsAreListed(): void
    {
        $cases = Algorithm::cases();
        $this->assertCount(3, $cases);
        $names = array_map(fn($c) => $c->name, $cases);
        $this->assertContains('Argon2id', $names);
        $this->assertContains('Argon2i', $names);
        $this->assertContains('Bcrypt', $names);
    }

    // ---------------------------------------------------------------
    // Hasher — Argon2id (default)
    // ---------------------------------------------------------------

    public function testHasherArgon2idProducesVerifiableHash(): void
    {
        $hasher = new Hasher();
        $hash   = $hasher->hash('secret');

        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertTrue($hasher->verify('secret', $hash));
        $this->assertFalse($hasher->verify('wrong', $hash));
    }

    public function testHasherArgon2idDifferentHashEachTime(): void
    {
        $hasher = new Hasher();
        $hash1  = $hasher->hash('password');
        $hash2  = $hasher->hash('password');

        // Salt is random — two hashes of the same password must differ
        $this->assertNotSame($hash1, $hash2);
        $this->assertTrue($hasher->verify('password', $hash1));
        $this->assertTrue($hasher->verify('password', $hash2));
    }

    public function testHasherArgon2idNeedsRehashReturnsFalseForFreshHash(): void
    {
        $hasher = new Hasher();
        $hash   = $hasher->hash('pw');
        $this->assertFalse($hasher->needsRehash($hash));
    }

    public function testHasherArgon2idNeedsRehashReturnsTrueForDifferentAlgo(): void
    {
        $bcryptHasher = new Hasher(Algorithm::Bcrypt);
        $hash         = $bcryptHasher->hash('pw');

        $argon2idHasher = new Hasher(Algorithm::Argon2id);
        $this->assertTrue($argon2idHasher->needsRehash($hash));
    }

    public function testHasherArgon2idVerifyReturnsFalseForEmptyHash(): void
    {
        $hasher = new Hasher();
        $this->assertFalse($hasher->verify('any', ''));
    }

    // ---------------------------------------------------------------
    // Hasher — Argon2i
    // ---------------------------------------------------------------

    public function testHasherArgon2iProducesVerifiableHash(): void
    {
        $hasher = new Hasher(Algorithm::Argon2i);
        $hash   = $hasher->hash('secret');

        $this->assertStringStartsWith('$argon2i$', $hash);
        $this->assertTrue($hasher->verify('secret', $hash));
        $this->assertFalse($hasher->verify('wrong', $hash));
    }

    public function testHasherArgon2iWithCustomOptions(): void
    {
        $hasher = new Hasher(Algorithm::Argon2i, [
            'memory_cost' => 1024,
            'time_cost'   => 2,
            'threads'     => 1,
        ]);
        $hash = $hasher->hash('test');
        $this->assertTrue($hasher->verify('test', $hash));
    }

    // ---------------------------------------------------------------
    // Hasher — Bcrypt
    // ---------------------------------------------------------------

    public function testHasherBcryptProducesVerifiableHash(): void
    {
        $hasher = new Hasher(Algorithm::Bcrypt);
        $hash   = $hasher->hash('secret');

        $this->assertStringStartsWith('$2y$', $hash);
        $this->assertTrue($hasher->verify('secret', $hash));
        $this->assertFalse($hasher->verify('wrong', $hash));
    }

    public function testHasherBcryptWithCustomCostFactor(): void
    {
        $hasher = new Hasher(Algorithm::Bcrypt, ['cost' => 4]); // minimum, fast for tests
        $hash   = $hasher->hash('pw');
        $this->assertTrue($hasher->verify('pw', $hash));
    }

    public function testHasherBcryptNeedsRehashOnCostChange(): void
    {
        $low  = new Hasher(Algorithm::Bcrypt, ['cost' => 4]);
        $high = new Hasher(Algorithm::Bcrypt, ['cost' => 5]);
        $hash = $low->hash('pw');

        $this->assertTrue($high->needsRehash($hash));
        $this->assertFalse($low->needsRehash($hash));
    }

    public function testHasherBcryptVerifyConstantTime(): void
    {
        $hasher = new Hasher(Algorithm::Bcrypt, ['cost' => 4]);
        $hash   = $hasher->hash('pw');

        // Both should return bool — this is a smoke test, not a timing test
        $this->assertIsBool($hasher->verify('pw', $hash));
        $this->assertIsBool($hasher->verify('wrong', $hash));
    }

    // ---------------------------------------------------------------
    // Encrypter
    // ---------------------------------------------------------------

    public function testEncrypterGeneratesValidKey(): void
    {
        $key = Encrypter::generateKey();
        $this->assertSame(32, strlen($key));
    }

    public function testEncrypterGeneratesUniqueKeys(): void
    {
        $this->assertNotSame(Encrypter::generateKey(), Encrypter::generateKey());
    }

    public function testEncrypterRejectsShortKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Encrypter(str_repeat('a', 16));
    }

    public function testEncrypterRejectsLongKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Encrypter(str_repeat('a', 64));
    }

    public function testEncrypterRoundTrip(): void
    {
        $enc  = new Encrypter(Encrypter::generateKey());
        $plain = 'Hello, AES-256-GCM!';

        $ciphertext = $enc->encrypt($plain);
        $this->assertNotSame($plain, $ciphertext);
        $this->assertSame($plain, $enc->decrypt($ciphertext));
    }

    public function testEncrypterEmptyStringRoundTrip(): void
    {
        $enc = new Encrypter(Encrypter::generateKey());
        $this->assertSame('', $enc->decrypt($enc->encrypt('')));
    }

    public function testEncrypterLargePayloadRoundTrip(): void
    {
        $enc   = new Encrypter(Encrypter::generateKey());
        $plain = str_repeat('X', 1_000_000);
        $this->assertSame($plain, $enc->decrypt($enc->encrypt($plain)));
    }

    public function testEncrypterProducesUniqueOutputEachTime(): void
    {
        $enc = new Encrypter(Encrypter::generateKey());
        $c1  = $enc->encrypt('same');
        $c2  = $enc->encrypt('same');
        // Fresh IV per call → different ciphertexts
        $this->assertNotSame($c1, $c2);
    }

    public function testEncrypterOutputIsBase64(): void
    {
        $enc = new Encrypter(Encrypter::generateKey());
        $ct  = $enc->encrypt('test');
        $this->assertNotFalse(base64_decode($ct, strict: true));
    }

    public function testEncrypterTamperedTagFails(): void
    {
        $enc = new Encrypter(Encrypter::generateKey());
        $ct  = $enc->encrypt('data');
        $raw = base64_decode($ct);
        // Flip a byte in the GCM tag (bytes 12-27)
        $tampered = substr($raw, 0, 12) . chr(ord($raw[12]) ^ 0x01) . substr($raw, 13);

        $this->expectException(RuntimeException::class);
        $enc->decrypt(base64_encode($tampered));
    }

    public function testEncrypterWrongKeyFails(): void
    {
        $enc1 = new Encrypter(Encrypter::generateKey());
        $enc2 = new Encrypter(Encrypter::generateKey());
        $ct   = $enc1->encrypt('secret');

        $this->expectException(RuntimeException::class);
        $enc2->decrypt($ct);
    }

    public function testEncrypterUnicodePayload(): void
    {
        $enc   = new Encrypter(Encrypter::generateKey());
        $plain = '日本語テスト — Привет мир — مرحبا بالعالم';
        $this->assertSame($plain, $enc->decrypt($enc->encrypt($plain)));
    }

    public function testEncrypterJsonPayloadRoundTrip(): void
    {
        $enc     = new Encrypter(Encrypter::generateKey());
        $payload = json_encode(['user_id' => 42, 'roles' => ['admin', 'editor'], 'exp' => time() + 3600]);
        $decoded = json_decode($enc->decrypt($enc->encrypt($payload)), true);
        $this->assertSame(42, $decoded['user_id']);
    }

    // ---------------------------------------------------------------
    // Signer
    // ---------------------------------------------------------------

    public function testSignerRejectsEmptySecret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Signer('');
    }

    public function testSignerRejectsUnknownAlgorithm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Signer('secret', 'md5_not_hmac');
    }

    public function testSignerDefaultAlgorithmIsSha256(): void
    {
        $signer = new Signer('secret');
        $sig    = $signer->sign('data');
        // SHA256 hex output is always 64 characters
        $this->assertSame(64, strlen($sig));
    }

    public function testSignerSignProducesHexString(): void
    {
        $signer = new Signer('secret');
        $sig    = $signer->sign('data');
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $sig);
    }

    public function testSignerVerifyReturnsTrueForValidSignature(): void
    {
        $signer = new Signer('my-secret');
        $sig    = $signer->sign('payload');
        $this->assertTrue($signer->verify('payload', $sig));
    }

    public function testSignerVerifyReturnsFalseForWrongData(): void
    {
        $signer = new Signer('my-secret');
        $sig    = $signer->sign('payload');
        $this->assertFalse($signer->verify('different-payload', $sig));
    }

    public function testSignerVerifyReturnsFalseForWrongSignature(): void
    {
        $signer = new Signer('my-secret');
        $this->assertFalse($signer->verify('payload', str_repeat('a', 64)));
    }

    public function testSignerVerifyReturnsFalseForWrongSecret(): void
    {
        $signer1 = new Signer('secret-1');
        $signer2 = new Signer('secret-2');
        $sig = $signer1->sign('data');
        $this->assertFalse($signer2->verify('data', $sig));
    }

    public function testSignerSignTokenRoundTrip(): void
    {
        $signer  = new Signer('token-secret');
        $payload = ['user_id' => 99, 'role' => 'admin'];
        $token   = $signer->signToken($payload);

        $decoded = $signer->verifyToken($token);
        $this->assertSame(99, $decoded['user_id']);
        $this->assertSame('admin', $decoded['role']);
    }

    public function testSignerTokenIsUrlSafe(): void
    {
        $signer = new Signer('secret');
        $token  = $signer->signToken(['key' => 'value+with/special=chars']);
        $this->assertStringNotContainsString('+', $token);
        $this->assertStringNotContainsString('/', $token);
        $this->assertStringNotContainsString('=', $token);
    }

    public function testSignerTamperedTokenFails(): void
    {
        $signer = new Signer('secret');
        $token  = $signer->signToken(['role' => 'user']);
        $parts  = explode('.', $token, 2);
        $evil   = rtrim(strtr(base64_encode(json_encode(['role' => 'admin'])), '+/', '-_'), '=');
        $forged = $evil . '.' . $parts[1];

        $this->expectException(RuntimeException::class);
        $signer->verifyToken($forged);
    }

    public function testSignerInvalidTokenFormatFails(): void
    {
        $signer = new Signer('secret');
        $this->expectException(RuntimeException::class);
        $signer->verifyToken('no-dot-in-here');
    }

    public function testSignerSha384Algorithm(): void
    {
        $signer = new Signer('secret', 'sha384');
        $sig    = $signer->sign('data');
        // SHA384 hex output is always 96 characters
        $this->assertSame(96, strlen($sig));
        $this->assertTrue($signer->verify('data', $sig));
    }

    public function testSignerSha512Algorithm(): void
    {
        $signer = new Signer('secret', 'sha512');
        $sig    = $signer->sign('data');
        // SHA512 hex output is always 128 characters
        $this->assertSame(128, strlen($sig));
        $this->assertTrue($signer->verify('data', $sig));
    }

    public function testSignerDifferentAlgorithmsProduceDifferentSignatures(): void
    {
        $s256 = new Signer('secret', 'sha256');
        $s512 = new Signer('secret', 'sha512');
        $this->assertNotSame($s256->sign('data'), $s512->sign('data'));
    }

    public function testSignerEmptyPayloadRoundTrip(): void
    {
        $signer = new Signer('secret');
        $this->assertSame([], $signer->verifyToken($signer->signToken([])));
    }
}
