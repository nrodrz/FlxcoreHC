<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Application-layer encryption for PHI/PII at rest.
 *
 * Two interchangeable AEAD backends, chosen automatically at runtime:
 *   - libsodium XChaCha20-Poly1305  (preferred; marker 0x02, legacy 0x01)
 *   - OpenSSL AES-256-GCM           (fallback when ext-sodium is absent; marker 0x03)
 *
 * Every ciphertext is self-describing: a 1-byte marker records which backend
 * wrote it, so a value stays decryptable even if the environment later gains or
 * loses libsodium. New writes use whichever backend is available (sodium first);
 * old blobs are read with the backend that produced them. Both key material and
 * blind-index HMAC use a plain 32-byte key, so nothing here needs libsodium.
 *
 * FAIL CLOSED: in production, if NO AEAD backend is available (neither sodium nor
 * openssl) or keys are missing, encrypt() throws rather than store plaintext.
 */
final class Crypto
{
    private const DEV      = "\x00";  // dev passthrough (not encrypted)
    private const SODIUM_V1 = "\x01"; // legacy sodium, empty AAD
    private const SODIUM    = "\x02"; // sodium AEAD, bound to tenant|table|column
    private const OPENSSL    = "\x03"; // AES-256-GCM, bound to tenant|table|column

    private const KEYLEN = 32;        // both backends use a 256-bit key
    private const SODIUM_NONCE = 24;  // XChaCha20-Poly1305 nonce
    private const GCM_IV = 12;        // AES-GCM IV
    private const GCM_TAG = 16;       // AES-GCM auth tag

    private static ?string $dataKey = null;
    private static ?string $indexKey = null;

    public static function haveSodium(): bool
    {
        return function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt');
    }
    public static function haveOpenssl(): bool
    {
        return function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
    }

    private static function boot(): void
    {
        if (self::$dataKey !== null) {
            return;
        }
        $hexData  = (string) config('crypto_data_key', '');
        $hexIndex = (string) config('crypto_index_key', '');
        self::$dataKey  = ($hexData !== '' && ctype_xdigit($hexData))   ? hex2bin($hexData)  : '';
        self::$indexKey = ($hexIndex !== '' && ctype_xdigit($hexIndex)) ? hex2bin($hexIndex) : '';
    }

    /** True when keys are present AND at least one AEAD backend is available. */
    public static function isConfigured(): bool
    {
        self::boot();
        return strlen(self::$dataKey) === self::KEYLEN
            && strlen(self::$indexKey) === self::KEYLEN
            && (self::haveSodium() || self::haveOpenssl());
    }

    /**
     * Encrypt a plaintext value to a binary blob, or null in → null out.
     * $aad binds the ciphertext to its context (tenant|table|column) so a blob
     * moved to another row/column/tenant will not decrypt.
     */
    public static function encrypt(?string $plain, string $aad = ''): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }
        self::boot();
        if (!self::isConfigured()) {
            if (config('app_env', 'production') !== 'dev') {
                throw new \RuntimeException(
                    'Encryption is not available: keys missing, or neither libsodium nor OpenSSL '
                    . '(AES-256-GCM) is enabled. Refusing to store sensitive data in plaintext. '
                    . 'Enable ext-sodium or ext-openssl and set the crypto keys in app/config.php, '
                    . 'or set app_env=dev for local development.'
                );
            }
            return self::DEV . $plain; // dev passthrough only
        }
        if (self::haveSodium()) {
            $nonce  = random_bytes(self::SODIUM_NONCE);
            $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $aad, $nonce, self::$dataKey);
            return self::SODIUM . $nonce . $cipher;
        }
        // OpenSSL AES-256-GCM fallback
        $iv  = random_bytes(self::GCM_IV);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::$dataKey, OPENSSL_RAW_DATA, $iv, $tag, $aad, self::GCM_TAG);
        if ($cipher === false) {
            throw new \RuntimeException('OpenSSL encryption failed.');
        }
        return self::OPENSSL . $iv . $tag . $cipher;
    }

    /** Decrypt a binary blob back to plaintext, or null in → null out. */
    public static function decrypt(?string $blob, string $aad = ''): ?string
    {
        if ($blob === null || $blob === '') {
            return null;
        }
        self::boot();
        $marker = $blob[0];

        if ($marker === self::DEV) {
            // Dev passthrough is never honoured in production: a forged 0x00-prefixed value must not read as valid data.
            return config('app_env', 'production') === 'dev' ? substr($blob, 1) : null;
        }

        if ($marker === self::SODIUM || $marker === self::SODIUM_V1) {
            if (!self::haveSodium() || strlen(self::$dataKey) !== self::KEYLEN) {
                return null;
            }
            $nonce  = substr($blob, 1, self::SODIUM_NONCE);
            $cipher = substr($blob, 1 + self::SODIUM_NONCE);
            $useAad = $marker === self::SODIUM_V1 ? '' : $aad;
            $plain  = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, $useAad, $nonce, self::$dataKey);
            return $plain === false ? null : $plain;
        }

        if ($marker === self::OPENSSL) {
            if (!self::haveOpenssl() || strlen(self::$dataKey) !== self::KEYLEN) {
                return null;
            }
            $iv     = substr($blob, 1, self::GCM_IV);
            $tag    = substr($blob, 1 + self::GCM_IV, self::GCM_TAG);
            $cipher = substr($blob, 1 + self::GCM_IV + self::GCM_TAG);
            $plain  = openssl_decrypt($cipher, 'aes-256-gcm', self::$dataKey, OPENSSL_RAW_DATA, $iv, $tag, $aad);
            return $plain === false ? null : $plain;
        }

        return null; // unknown marker
    }

    /**
     * Deterministic keyed index for exact-match search on an encrypted value.
     * Same plaintext -> same hash (per key). Backend-independent (HMAC-SHA256).
     */
    public static function blindIndex(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }
        self::boot();
        if (strlen(self::$indexKey) !== self::KEYLEN) {
            return null;
        }
        $norm = strtolower(preg_replace('/\s+/', '', $plain));
        return hash_hmac('sha256', $norm, self::$indexKey);
    }

    /** Generate fresh keys for config (run once at setup). Works without libsodium. */
    public static function generateKeys(): array
    {
        return [
            'crypto_data_key'  => bin2hex(random_bytes(self::KEYLEN)),
            'crypto_index_key' => bin2hex(random_bytes(self::KEYLEN)),
        ];
    }
}
