<?php

declare(strict_types=1);

namespace Lift\Crypto;

use RuntimeException;
use InvalidArgumentException;

/**
 * Authenticated symmetric encryption using AES-256-GCM.
 *
 * AES-256-GCM provides both confidentiality and integrity (AEAD). Any
 * tampering with the ciphertext or the authentication tag causes decryption
 * to fail with a {@see RuntimeException} — no silent data corruption.
 *
 * Wire format (base64-encoded):
 * ```
 * [12-byte IV][16-byte GCM tag][variable-length ciphertext]
 * ```
 *
 * ```php
 * $key       = Encrypter::generateKey();          // keep in .env, never commit
 * $encrypter = new Encrypter(base64_decode($key));
 *
 * $token   = $encrypter->encrypt(json_encode($payload));
 * $payload = json_decode($encrypter->decrypt($token), true);
 * ```
 *
 * @see https://www.php.net/openssl_encrypt
 */
final class Encrypter
{
    private const CIPHER  = 'aes-256-gcm';
    private const IV_LEN  = 12;   // 96-bit IV recommended for GCM
    private const TAG_LEN = 16;   // 128-bit authentication tag

    /**
     * @param string $key Raw 32-byte (256-bit) key. Use {@see generateKey()} to create one.
     * @throws InvalidArgumentException If the key length is not exactly 32 bytes.
     */
    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== 32) {
            throw new InvalidArgumentException(
                'Encryption key must be exactly 32 bytes (256 bits). '
                . 'Use Encrypter::generateKey() to generate a valid key.'
            );
        }

        if (!extension_loaded('openssl')) {
            throw new RuntimeException('The openssl PHP extension is required for Encrypter.');
        }
    }

    /**
     * Generate a cryptographically secure 32-byte encryption key.
     *
     * Store the base64-encoded form in an environment variable:
     * ```php
     * $rawKey    = Encrypter::generateKey();
     * $envValue  = base64_encode($rawKey);  // store this in APP_KEY
     *
     * // Later, in bootstrap:
     * $encrypter = new Encrypter(base64_decode($_ENV['APP_KEY']));
     * ```
     *
     * @return string 32 raw bytes (store base64-encoded).
     */
    public static function generateKey(): string
    {
        return random_bytes(32);
    }

    /**
     * Encrypt a plaintext string.
     *
     * A fresh random 96-bit IV is generated for every call — encrypting the
     * same plaintext twice produces different ciphertexts (IND-CPA secure).
     *
     * @return string Base64-encoded payload (safe for storage/transport).
     * @throws RuntimeException On OpenSSL failure.
     */
    public function encrypt(string $plaintext): string
    {
        $iv  = random_bytes(self::IV_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a payload produced by {@see encrypt()}.
     *
     * @throws RuntimeException If the payload is malformed or the authentication tag fails
     *                          (i.e. the ciphertext was tampered with or the wrong key is used).
     */
    public function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, strict: true);

        if ($raw === false || strlen($raw) < self::IV_LEN + self::TAG_LEN) {
            throw new RuntimeException('Decryption failed: invalid payload format.');
        }

        $iv         = substr($raw, 0, self::IV_LEN);
        $tag        = substr($raw, self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($raw, self::IV_LEN + self::TAG_LEN);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plaintext === false) {
            throw new RuntimeException(
                'Decryption failed: authentication tag mismatch — payload may have been tampered with.'
            );
        }

        return $plaintext;
    }
}
