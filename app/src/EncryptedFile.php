<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Encryption at rest for uploaded documents (max 10 MB, so whole-file AEAD is fine).
 *
 * On disk: "FLXENC1\n" + Crypto blob (sodium / AES-256-GCM, AAD bound to the file's basename).
 * Reads are backward compatible: a file without the header is a legacy plaintext file and is
 * served as-is until database/encrypt_existing_files.php converts it.
 * Writes FAIL CLOSED: if encryption is unavailable in production, nothing is stored.
 */
final class EncryptedFile
{
    public const MAGIC = "FLXENC1\n";

    private static function aad(string $path): string
    {
        return 'file|' . basename($path);
    }

    public static function isEncrypted(string $path): bool
    {
        $h = @fopen($path, 'rb');
        if (!$h) return false;
        $m = fread($h, strlen(self::MAGIC));
        fclose($h);
        return $m === self::MAGIC;
    }

    /** Encrypt $bytes and write to $dest (0640). Returns false (and leaves nothing behind) on failure. */
    public static function writeBytes(string $dest, string $bytes): bool
    {
        try {
            $blob = Crypto::encrypt($bytes, self::aad($dest));
            if ($blob === null) return false;
            if (@file_put_contents($dest, self::MAGIC . $blob, LOCK_EX) === false) { @unlink($dest); return false; }
            @chmod($dest, 0640);
            return true;
        } catch (\Throwable $e) {
            @unlink($dest);
            return false;
        }
    }

    /** Move an uploaded/temp file into place encrypted; the temp file is removed. */
    public static function store(string $tmp, string $dest): bool
    {
        $bytes = @file_get_contents($tmp);
        if ($bytes === false || $bytes === '') return false;
        if (!self::writeBytes($dest, $bytes)) return false;
        @unlink($tmp);
        return true;
    }

    /** Plaintext bytes (decrypting when needed), or null if unreadable / cannot be decrypted. */
    public static function read(string $path): ?string
    {
        $raw = @file_get_contents($path);
        if ($raw === false) return null;
        if (strncmp($raw, self::MAGIC, strlen(self::MAGIC)) !== 0) return $raw; // legacy plaintext
        return Crypto::decrypt(substr($raw, strlen(self::MAGIC)), self::aad($path));
    }

    /** Echo the plaintext with a correct Content-Length. Returns false (404-worthy) if unreadable. */
    public static function send(string $path): bool
    {
        $b = self::read($path);
        if ($b === null) return false;
        if (!headers_sent()) header('Content-Length: ' . strlen($b));
        echo $b;
        return true;
    }

    /** Convert a legacy plaintext file in place. true = converted, false = already encrypted / failed. */
    public static function encryptInPlace(string $path): bool
    {
        if (!is_file($path) || self::isEncrypted($path)) return false;
        $b = @file_get_contents($path);
        if ($b === false || $b === '') return false;
        // AAD is bound to the FINAL basename; write to a temp name, verify, then swap atomically.
        $tmp = $path . '.enc.tmp';
        try { $blob = Crypto::encrypt($b, self::aad($path)); } catch (\Throwable $e) { return false; }
        if ($blob === null || @file_put_contents($tmp, self::MAGIC . $blob, LOCK_EX) === false) { @unlink($tmp); return false; }
        if (Crypto::decrypt($blob, self::aad($path)) !== $b) { @unlink($tmp); return false; }
        @chmod($tmp, 0640);
        return @rename($tmp, $path);
    }
}
