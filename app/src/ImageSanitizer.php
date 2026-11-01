<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Remove metadata from a JPEG in place without any image library (GD/Imagick are often absent on
 * shared cPanel hosting). Phone photos embed EXIF including GPS coordinates; we don't want those
 * stored alongside a credential document.
 *
 * Approach: walk the JPEG marker segments and drop the metadata blocks — APP0..APP15 (EXIF, JFIF
 * thumbnails, XMP), and COM comments — while preserving SOI, quantisation/Huffman tables, frame and
 * scan data. The image itself is untouched (no re-encode, no quality loss). If anything looks
 * unexpected, the file is left as-is rather than risk corruption.
 */
final class ImageSanitizer
{
    public static function stripJpegMetadata(string $path): bool
    {
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) < 4) return false;
        // Must start with SOI (FFD8).
        if (substr($data, 0, 2) !== "\xFF\xD8") return false;

        $out = "\xFF\xD8";
        $i = 2; $len = strlen($data);

        while ($i < $len - 1) {
            // Every marker starts with 0xFF; skip any fill bytes.
            if ($data[$i] !== "\xFF") { return false; } // malformed — don't touch it
            $marker = ord($data[$i + 1]);

            // Start of Scan: copy the rest of the file verbatim (entropy-coded image data).
            if ($marker === 0xDA) { $out .= substr($data, $i); break; }

            // Standalone markers (no length): RSTn, SOI, EOI, TEM.
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD9)) { $out .= substr($data, $i, 2); $i += 2; continue; }

            // Everything else has a 2-byte big-endian length following the marker.
            if ($i + 4 > $len) { return false; }
            $segLen = (ord($data[$i + 2]) << 8) | ord($data[$i + 3]);
            if ($segLen < 2 || $i + 2 + $segLen > $len) { return false; }

            $isMeta = ($marker >= 0xE0 && $marker <= 0xEF) // APPn (EXIF/JFIF/XMP/…)
                   || $marker === 0xFE;                     // COM comment
            if (!$isMeta) {
                $out .= substr($data, $i, 2 + $segLen);      // keep tables/frame headers
            }
            $i += 2 + $segLen;
        }

        if (strlen($out) < 4) return false;
        return @file_put_contents($path, $out) !== false;
    }
}
