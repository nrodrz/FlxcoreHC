<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * v1.171.3 — optional passphrase sealing of a database dump (same primitives as KeyBackup: PBKDF2-HMAC-SHA256 + AES-256-GCM).
 * File layout: "FLXDUMP1\n" | iterations(4, BE) | salt(16) | iv(12) | tag(16) | ciphertext. Standalone (no bootstrap).
 * Wrong passphrase or any tampering fails authentication — never yields junk.
 */
final class DumpSeal
{
    public const MAGIC = "FLXDUMP1\n";
    public const ITERATIONS = 200000;

    public static function seal(string $data, string $passphrase): string
    {
        $salt = random_bytes(16); $iv = random_bytes(12);
        $key = hash_pbkdf2('sha256', $passphrase, $salt, self::ITERATIONS, 32, true);
        $hdr = self::MAGIC . pack('N', self::ITERATIONS);
        $tag = '';
        $ct = openssl_encrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'flxcorehc-dump-v1', 16);
        if ($ct === false) throw new \RuntimeException('AES-256-GCM unavailable.');
        return $hdr . $salt . $iv . $tag . $ct;
    }

    public static function open(string $blob, string $passphrase): ?string
    {
        $m = strlen(self::MAGIC);
        if (strlen($blob) < $m + 4 + 16 + 12 + 16 || substr($blob, 0, $m) !== self::MAGIC) return null;
        $iter = unpack('N', substr($blob, $m, 4))[1];
        if ($iter < 100000) return null;   // refuse a downgraded work factor
        $salt = substr($blob, $m + 4, 16); $iv = substr($blob, $m + 20, 12); $tag = substr($blob, $m + 32, 16); $ct = substr($blob, $m + 48);
        $key = hash_pbkdf2('sha256', $passphrase, $salt, $iter, 32, true);
        $p = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'flxcorehc-dump-v1');
        return $p === false ? null : $p;
    }
}
