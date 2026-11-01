<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Minimal, dependency-free ZIP reader. The shared host has no ext-zip/ZipArchive, but CMS
 * distributes its landscape files as .zip. This parses the central directory and inflates
 * entries with gzinflate() (deflate, method 8) or reads stored entries (method 0) — enough
 * for the CSV/TXT payloads CMS ships. Not a general-purpose extractor (no encryption, no
 * zip64, no other compression methods).
 */
final class MiniZip
{
    /**
     * Extract a ZIP given as a raw byte string.
     * @return array<string,string> filename => file contents
     */
    public static function extract(string $bytes): array
    {
        $out = [];
        $eocd = strrpos($bytes, "\x50\x4b\x05\x06");         // End of Central Directory signature
        if ($eocd === false) {
            throw new \RuntimeException('Not a ZIP file (no end-of-central-directory record).');
        }
        $total = self::u16($bytes, $eocd + 10);              // total central directory entries
        $cdOffset = self::u32($bytes, $eocd + 16);           // offset of central directory

        $p = $cdOffset;
        for ($i = 0; $i < $total; $i++) {
            if (substr($bytes, $p, 4) !== "\x50\x4b\x01\x02") break;   // central file header sig
            $method   = self::u16($bytes, $p + 10);
            $compSize = self::u32($bytes, $p + 20);
            $nameLen  = self::u16($bytes, $p + 28);
            $extraLen = self::u16($bytes, $p + 30);
            $commLen  = self::u16($bytes, $p + 32);
            $localHdr = self::u32($bytes, $p + 42);
            $name     = substr($bytes, $p + 46, $nameLen);
            $p += 46 + $nameLen + $extraLen + $commLen;

            // Jump to the local header to find where the data actually starts.
            if (substr($bytes, $localHdr, 4) !== "\x50\x4b\x03\x04") continue;
            $lNameLen  = self::u16($bytes, $localHdr + 26);
            $lExtraLen = self::u16($bytes, $localHdr + 28);
            $dataStart = $localHdr + 30 + $lNameLen + $lExtraLen;
            $comp = substr($bytes, $dataStart, $compSize);

            if (str_ends_with($name, '/')) continue;         // directory entry
            if ($method === 0) {                              // stored
                $out[$name] = $comp;
            } elseif ($method === 8) {                        // deflate
                $raw = @gzinflate($comp);
                if ($raw !== false) $out[$name] = $raw;
            }
            // other methods silently skipped
        }
        return $out;
    }

    /** Return the first CSV/TXT-looking entry's contents, or the largest entry. */
    public static function firstTextFile(string $bytes): ?string
    {
        $files = self::extract($bytes);
        if ($files === []) return null;
        foreach ($files as $n => $c) {
            $l = strtolower($n);
            if (str_ends_with($l, '.csv') || str_ends_with($l, '.txt')) return $c;
        }
        // fall back to the biggest entry
        uasort($files, fn($a, $b) => strlen($b) <=> strlen($a));
        return reset($files) ?: null;
    }

    private static function u16(string $b, int $o): int
    {
        return ord($b[$o]) | (ord($b[$o + 1]) << 8);
    }

    private static function u32(string $b, int $o): int
    {
        return ord($b[$o]) | (ord($b[$o + 1]) << 8) | (ord($b[$o + 2]) << 16) | (ord($b[$o + 3]) << 24);
    }
}
