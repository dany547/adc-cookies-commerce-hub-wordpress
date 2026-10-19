<?php
/**
 * Secret sealing at rest (libsodium secretbox).
 *
 * Format: prefix "enc1:" + base64(nonce || ciphertext).
 * Key: hex2bin(hash('sha256', wp_salt('auth'))) — site-specific, 32 bytes.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Encrypt/decrypt small secrets (API tokens) stored in options.
 */
final class ADC_Secrets
{
    /**
     * Ciphertext prefix stored in the database.
     */
    private const PREFIX = 'enc1:';

    /**
     * sodium_crypto_secretbox nonce length in bytes (fixed by the
     * XChaCha20-Poly1305 spec; the SODIUM_CRYPTO_SECRETBOX_NONCE_BYTES
     * constant is not available on every build).
     */
    private const NONCE_BYTES = 24;

    /**
     * Cached raw key bytes.
     *
     * @var string|null|false null = not computed, false = sodium unavailable.
     */
    private static string|false|null $key = null;

    /**
     * Seal (encrypt) a plaintext secret. '' stays ''.
     *
     * @param string $plain Plaintext secret.
     * @return string Sealed value (prefix enc1:).
     */
    public static function seal(string $plain): string
    {
        if ('' === $plain) {
            return '';
        }

        $key = self::key();
        if (null === $key || false === $key) {
            // Degraded mode: sodium unavailable — base64 is obfuscation only, not encryption.
            return self::PREFIX . base64_encode($plain);
        }

        $nonce = random_bytes(self::NONCE_BYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }

    /**
     * Open (decrypt) a sealed value. Values without the prefix (legacy
     * plaintext or constant overrides) are returned unchanged.
     *
     * @param string $sealed Sealed value.
     * @return string Plaintext ('' when it cannot be decrypted).
     */
    public static function open(string $sealed): string
    {
        if ('' === $sealed) {
            return '';
        }

        if (!str_starts_with($sealed, self::PREFIX)) {
            return $sealed; // legacy plaintext / passthrough.
        }

        $raw = base64_decode(substr($sealed, strlen(self::PREFIX)), true);
        if (false === $raw) {
            return '';
        }

        $key = self::key();
        if (null === $key || false === $key) {
            // Degraded mode matching seal() above.
            $plain = base64_decode($raw, true);
            return false === $plain ? '' : $plain;
        }

        if (strlen($raw) <= self::NONCE_BYTES) {
            return '';
        }

        $nonce  = substr($raw, 0, self::NONCE_BYTES);
        $cipher = substr($raw, self::NONCE_BYTES);
        $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $key);

        return false === $plain ? '' : $plain;
    }

    /**
     * 32-byte site-specific key. Returns false when sodium is unavailable.
     *
     * @return string|false
     */
    private static function key(): string|false|null
    {
        if (null !== self::$key) {
            return self::$key;
        }

        if (!function_exists('sodium_crypto_secretbox')) {
            self::$key = false;
            return self::$key;
        }

        self::$key = (string) hex2bin(hash('sha256', wp_salt('auth')));

        return self::$key;
    }
}
