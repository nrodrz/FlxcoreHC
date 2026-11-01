<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * v1.171.0 — passphrase-protected backup of the two encryption keys (crypto_data_key, crypto_index_key).
 *
 * Why: if app/config.php is lost, every encrypted PHI/PII column is unrecoverable. The keys are therefore never sent by
 * e-mail and never shown in the clear after install; the operator downloads a file that is useless without a passphrase
 * only they know, and stores it in a vault / password manager.
 *
 * Format (JSON, version 1): PBKDF2-HMAC-SHA256 (≥ 200 000 iterations) derives a 256-bit key from the passphrase; the
 * payload is sealed with AES-256-GCM (authenticated — a wrong passphrase or a tampered file fails, never yields junk).
 * Needs only OpenSSL (always present where the app runs). Standalone: no bootstrap, no database — the installer and the
 * CLI restore tool both load this file directly.
 */
final class KeyBackup
{
    public const ITERATIONS = 200000;
    public const MIN_PASSPHRASE = 12;

    /** A short, non-secret identifier of the key pair, to match a backup file with an installation. */
    public static function fingerprint(string $dataKey, string $indexKey): string
    {
        return substr(hash('sha256', 'flx-key-fp|' . $dataKey . '|' . $indexKey), 0, 12);
    }

    /** Returns an error message when the passphrase is unacceptable, else null. */
    public static function passphraseError(string $p, string $confirm): ?string
    {
        if (mb_strlen($p) < self::MIN_PASSPHRASE) return 'Use a backup passphrase of at least ' . self::MIN_PASSPHRASE . ' characters.';
        if (!hash_equals($p, $confirm)) return 'The two backup passphrases do not match.';
        return null;
    }

    /** @return string JSON document to hand to the operator as a file download */
    public static function encrypt(string $dataKey, string $indexKey, string $passphrase): string
    {
        $salt = random_bytes(16); $iv = random_bytes(12);
        $key  = hash_pbkdf2('sha256', $passphrase, $salt, self::ITERATIONS, 32, true);
        $plain = json_encode(['crypto_data_key' => $dataKey, 'crypto_index_key' => $indexKey], JSON_UNESCAPED_SLASHES);
        $tag = '';
        $ct = openssl_encrypt((string) $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'flxcorehc-keys-v1', 16);
        if ($ct === false) throw new \RuntimeException('Could not create the key backup (OpenSSL AES-256-GCM unavailable).');
        return json_encode([
            'app' => 'FlxCoreHC', 'type' => 'encryption-key-backup', 'v' => 1,
            'created_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'fingerprint' => self::fingerprint($dataKey, $indexKey),
            'kdf' => 'pbkdf2-sha256', 'iterations' => self::ITERATIONS,
            'salt' => base64_encode($salt), 'iv' => base64_encode($iv), 'tag' => base64_encode($tag), 'ct' => base64_encode($ct),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /** @return array{crypto_data_key:string,crypto_index_key:string}|null  null = wrong passphrase / damaged file */
    public static function decrypt(string $json, string $passphrase): ?array
    {
        $d = json_decode($json, true);
        if (!is_array($d) || ($d['type'] ?? '') !== 'encryption-key-backup' || (int) ($d['v'] ?? 0) !== 1) return null;
        $iter = (int) ($d['iterations'] ?? 0);
        if ($iter < 100000) return null;   // refuse a downgraded work factor
        $salt = base64_decode((string) ($d['salt'] ?? ''), true); $iv = base64_decode((string) ($d['iv'] ?? ''), true);
        $tag  = base64_decode((string) ($d['tag'] ?? ''), true);  $ct = base64_decode((string) ($d['ct'] ?? ''), true);
        if ($salt === false || $iv === false || $tag === false || $ct === false) return null;
        $key = hash_pbkdf2('sha256', $passphrase, $salt, $iter, 32, true);
        $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'flxcorehc-keys-v1');
        if ($plain === false) return null;
        $k = json_decode($plain, true);
        return (is_array($k) && !empty($k['crypto_data_key']) && !empty($k['crypto_index_key'])) ? $k : null;
    }
}
