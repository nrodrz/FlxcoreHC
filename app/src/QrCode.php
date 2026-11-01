<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * v1.172.0 — a small, dependency-free QR Code encoder (ISO/IEC 18004, Model 2).
 *
 * Why it exists: the two-factor enrolment page shows the otpauth:// URI as a QR code. That URI contains the TOTP
 * secret, so it must NEVER be sent to an external QR service or CDN. This class builds the symbol on the server and
 * returns it as inline SVG (no image request at all, compatible with the Content-Security-Policy).
 *
 * Scope: byte mode, error-correction level M, versions 1–20 (up to 666 bytes) — far more than an otpauth URI needs.
 * Correctness is checked in the test suite against matrices produced by an independent reference implementation.
 */
final class QrCode
{
    /** Level M block structure per version: [ec codewords per block, blocks in group 1, data cw per block, blocks in group 2, data cw per block]. */
    private const BLOCKS_M = [
        1 => [10, 1, 16, 0, 0],   2 => [16, 1, 28, 0, 0],   3 => [26, 1, 44, 0, 0],   4 => [18, 2, 32, 0, 0],
        5 => [24, 2, 43, 0, 0],   6 => [16, 4, 27, 0, 0],   7 => [18, 4, 31, 0, 0],   8 => [22, 2, 38, 2, 39],
        9 => [22, 3, 36, 2, 37],  10 => [26, 4, 43, 1, 44], 11 => [30, 1, 50, 4, 51], 12 => [22, 6, 36, 2, 37],
        13 => [22, 8, 37, 1, 38], 14 => [24, 4, 40, 5, 41], 15 => [24, 5, 41, 5, 42], 16 => [28, 7, 45, 3, 46],
        17 => [28, 10, 46, 1, 47], 18 => [26, 9, 43, 4, 44], 19 => [26, 3, 44, 11, 45], 20 => [26, 3, 41, 13, 42],
    ];

    /** Alignment-pattern centre coordinates per version. */
    private const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42],
        9 => [6, 26, 46], 10 => [6, 28, 50], 11 => [6, 30, 54], 12 => [6, 32, 58], 13 => [6, 34, 62], 14 => [6, 26, 46, 66],
        15 => [6, 26, 48, 70], 16 => [6, 26, 50, 74], 17 => [6, 30, 54, 78], 18 => [6, 30, 56, 82], 19 => [6, 30, 58, 86],
        20 => [6, 34, 62, 90],
    ];

    /** Data capacity in bytes (byte mode, level M) for a version. */
    public static function capacity(int $version): int
    {
        [, $g1, $d1, $g2, $d2] = self::BLOCKS_M[$version];
        $dataCw = $g1 * $d1 + $g2 * $d2;
        return $dataCw - ($version >= 10 ? 3 : 2);   // 4-bit mode + 8/16-bit count (+ up to 4 terminator bits fit in the rounding)
    }

    /**
     * Encode a string. Returns ['version' => int, 'mask' => int, 'size' => int, 'modules' => bool[][]] (true = dark),
     * or null when the text does not fit in version 20.
     * $forceMask (0–7) is for tests; production passes null and the lowest-penalty mask is chosen.
     */
    public static function encode(string $text, ?int $forceMask = null): ?array
    {
        $len = strlen($text);
        $version = null;
        for ($v = 1; $v <= 20; $v++) { if ($len <= self::capacity($v)) { $version = $v; break; } }
        if ($version === null) return null;

        $codewords = self::interleave($version, self::dataCodewords($text, $version));
        $size = 17 + 4 * $version;
        [$base, $reserved] = self::functionPatterns($version, $size);
        self::placeData($base, $reserved, $size, $codewords);

        $bestMask = 0; $best = null; $bestPenalty = PHP_INT_MAX;
        foreach (($forceMask === null ? range(0, 7) : [$forceMask]) as $mask) {
            $m = self::applyMask($base, $reserved, $size, $mask);
            self::formatInfo($m, $size, $mask);
            $p = self::penalty($m, $size);
            if ($p < $bestPenalty) { $bestPenalty = $p; $best = $m; $bestMask = $mask; }
        }
        return ['version' => $version, 'mask' => $bestMask, 'size' => $size, 'modules' => $best];
    }

    /**
     * Inline SVG for the text (black on white, with the 4-module quiet zone), or '' when it cannot be encoded.
     * Always black on white regardless of the page theme, so phones can read it.
     */
    public static function svg(string $text, int $pixels = 220, string $label = 'QR code'): string
    {
        $qr = self::encode($text);
        if ($qr === null) return '';
        $n = $qr['size']; $q = 4; $total = $n + 2 * $q; $d = '';
        foreach ($qr['modules'] as $y => $row) {
            $x = 0;
            while ($x < $n) {
                if (!$row[$x]) { $x++; continue; }
                $run = 1; while ($x + $run < $n && $row[$x + $run]) $run++;
                $d .= 'M' . ($x + $q) . ' ' . ($y + $q) . 'h' . $run . 'v1h-' . $run . 'z';
                $x += $run;
            }
        }
        $lbl = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $pixels . '" height="' . $pixels . '" viewBox="0 0 ' . $total . ' ' . $total
            . '" shape-rendering="crispEdges" role="img" aria-label="' . $lbl . '"><rect width="' . $total . '" height="' . $total
            . '" fill="#ffffff"/><path fill="#000000" d="' . $d . '"/></svg>';
    }

    // ---- data + error correction ---------------------------------------------------------------------------------

    /** Mode indicator, character count, payload, terminator and pad codewords for the version. @return int[] */
    private static function dataCodewords(string $text, int $version): array
    {
        [, $g1, $d1, $g2, $d2] = self::BLOCKS_M[$version];
        $capBits = ($g1 * $d1 + $g2 * $d2) * 8;
        $bits = '0100' . str_pad(decbin(strlen($text)), $version >= 10 ? 16 : 8, '0', STR_PAD_LEFT);
        foreach (unpack('C*', $text) ?: [] as $b) $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        $bits .= str_repeat('0', min(4, $capBits - strlen($bits)));
        if (strlen($bits) % 8) $bits .= str_repeat('0', 8 - strlen($bits) % 8);
        $out = [];
        foreach (str_split($bits, 8) as $byte) $out[] = bindec($byte);
        for ($i = 0; count($out) < $capBits / 8; $i++) $out[] = ($i % 2) ? 0x11 : 0xEC;
        return $out;
    }

    /** Split into blocks, add Reed–Solomon codewords, interleave. @return int[] */
    private static function interleave(int $version, array $data): array
    {
        [$ec, $g1, $d1, $g2, $d2] = self::BLOCKS_M[$version];
        $blocks = []; $ecBlocks = []; $pos = 0;
        foreach ([[$g1, $d1], [$g2, $d2]] as [$count, $size]) {
            for ($i = 0; $i < $count; $i++) {
                $blk = array_slice($data, $pos, $size); $pos += $size;
                $blocks[] = $blk; $ecBlocks[] = self::reedSolomon($blk, $ec);
            }
        }
        $out = [];
        $max = max($d1, $d2);
        for ($i = 0; $i < $max; $i++) foreach ($blocks as $b) if (isset($b[$i])) $out[] = $b[$i];
        for ($i = 0; $i < $ec; $i++) foreach ($ecBlocks as $b) $out[] = $b[$i];
        return $out;
    }

    /** Reed–Solomon remainder over GF(256), polynomial 0x11D. @return int[] */
    private static function reedSolomon(array $data, int $ecLen): array
    {
        static $exp = null, $log = null;
        if ($exp === null) {
            $exp = []; $log = array_fill(0, 256, 0); $x = 1;
            for ($i = 0; $i < 255; $i++) { $exp[$i] = $x; $log[$x] = $i; $x <<= 1; if ($x & 0x100) $x ^= 0x11D; }
            for ($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];
        }
        $gen = [1];   // (x - a^0)(x - a^1)…(x - a^(ecLen-1)), highest degree first
        for ($i = 0; $i < $ecLen; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $g) {
                $next[$j] ^= $g;
                if ($g !== 0) $next[$j + 1] ^= $exp[$log[$g] + $i];
            }
            $gen = $next;
        }
        $rem = array_fill(0, $ecLen, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ $rem[0];
            array_shift($rem); $rem[] = 0;
            if ($factor !== 0) {
                for ($j = 0; $j < $ecLen; $j++) {
                    if ($gen[$j + 1] !== 0) $rem[$j] ^= $exp[$log[$gen[$j + 1]] + $log[$factor]];
                }
            }
        }
        return $rem;
    }

    // ---- matrix ---------------------------------------------------------------------------------------------------

    /** Finder, separator, timing, alignment, dark module, version info; format areas reserved. @return array{0:bool[][],1:bool[][]} */
    private static function functionPatterns(int $version, int $size): array
    {
        $m = array_fill(0, $size, array_fill(0, $size, false));
        $r = array_fill(0, $size, array_fill(0, $size, false));
        $set = static function (int $y, int $x, bool $dark) use (&$m, &$r, $size): void {
            if ($y < 0 || $x < 0 || $y >= $size || $x >= $size) return;
            $m[$y][$x] = $dark; $r[$y][$x] = true;
        };
        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$oy, $ox]) {          // finder patterns + separators
            for ($dy = -1; $dy <= 7; $dy++) for ($dx = -1; $dx <= 7; $dx++) {
                $inside = $dy >= 0 && $dy <= 6 && $dx >= 0 && $dx <= 6;
                $dark = $inside && ($dy === 0 || $dy === 6 || $dx === 0 || $dx === 6 || ($dy >= 2 && $dy <= 4 && $dx >= 2 && $dx <= 4));
                $set($oy + $dy, $ox + $dx, $dark);
            }
        }
        for ($i = 8; $i < $size - 8; $i++) { $set(6, $i, $i % 2 === 0); $set($i, 6, $i % 2 === 0); }   // timing
        $centres = self::ALIGN[$version];
        $last = count($centres) - 1;
        foreach ($centres as $iy => $cy) foreach ($centres as $ix => $cx) {             // alignment — all but the three under the finders
            if (($iy === 0 && $ix === 0) || ($iy === 0 && $ix === $last) || ($iy === $last && $ix === 0)) continue;
            for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) {
                $set($cy + $dy, $cx + $dx, max(abs($dy), abs($dx)) !== 1);
            }
        }
        $set($size - 8, 8, true);                                                       // dark module
        for ($i = 0; $i < 9; $i++) { if (!$r[8][$i]) $r[8][$i] = true; if (!$r[$i][8]) $r[$i][8] = true; }   // format areas
        for ($i = 0; $i < 8; $i++) { $r[8][$size - 1 - $i] = true; $r[$size - 1 - $i][8] = true; }
        if ($version >= 7) {                                                            // version information (BCH 18,6)
            $rem = $version;
            for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            $bits = ($version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $dark = (($bits >> $i) & 1) === 1;
                $a = intdiv($i, 3); $b = $i % 3 + $size - 11;
                $set($a, $b, $dark); $set($b, $a, $dark);
            }
        }
        return [$m, $r];
    }

    /** Zig-zag placement of the codeword bits into every module that is not a function pattern. */
    private static function placeData(array &$m, array $reserved, int $size, array $codewords): void
    {
        $bitCount = count($codewords) * 8; $i = 0; $up = true;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right = 5;                                               // skip the vertical timing column
            for ($n = 0; $n < $size; $n++) {
                $y = $up ? $size - 1 - $n : $n;
                foreach ([$right, $right - 1] as $x) {
                    if ($reserved[$y][$x]) continue;
                    $bit = false;
                    if ($i < $bitCount) $bit = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                    $m[$y][$x] = $bit; $i++;
                }
            }
            $up = !$up;
        }
    }

    private static function applyMask(array $m, array $reserved, int $size, int $mask): array
    {
        for ($y = 0; $y < $size; $y++) for ($x = 0; $x < $size; $x++) {
            if ($reserved[$y][$x]) continue;
            $flip = match ($mask) {
                0 => ($y + $x) % 2 === 0,
                1 => $y % 2 === 0,
                2 => $x % 3 === 0,
                3 => ($y + $x) % 3 === 0,
                4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
                5 => ($y * $x) % 2 + ($y * $x) % 3 === 0,
                6 => (($y * $x) % 2 + ($y * $x) % 3) % 2 === 0,
                default => (($y + $x) % 2 + ($y * $x) % 3) % 2 === 0,
            };
            if ($flip) $m[$y][$x] = !$m[$y][$x];
        }
        return $m;
    }

    /** 15-bit format information (level M = 00, mask), BCH(15,5), XOR 0x5412 — written in both locations. */
    private static function formatInfo(array &$m, int $size, int $mask): void
    {
        $data = (0b00 << 3) | $mask; $rem = $data;
        for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn(int $i): bool => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) $m[$i][8] = $bit($i);
        $m[7][8] = $bit(6); $m[8][8] = $bit(7); $m[8][7] = $bit(8);
        for ($i = 9; $i < 15; $i++) $m[8][14 - $i] = $bit($i);
        for ($i = 0; $i < 8; $i++) $m[8][$size - 1 - $i] = $bit($i);
        for ($i = 8; $i < 15; $i++) $m[$size - 15 + $i][8] = $bit($i);
        $m[$size - 8][8] = true;
    }

    /** Standard mask penalty score (rules N1–N4). */
    private static function penalty(array $m, int $size): int
    {
        $score = 0;
        for ($pass = 0; $pass < 2; $pass++) {                                           // N1 runs, N3 finder-like, rows then columns
            for ($a = 0; $a < $size; $a++) {
                $run = 0; $prev = null; $line = '';
                for ($b = 0; $b < $size; $b++) {
                    $v = $pass === 0 ? $m[$a][$b] : $m[$b][$a];
                    $line .= $v ? '1' : '0';
                    if ($v === $prev) { $run++; if ($run === 5) $score += 3; elseif ($run > 5) $score++; }
                    else { $run = 1; $prev = $v; }
                }
                $score += 40 * (substr_count($line, '10111010000') + substr_count($line, '00001011101'));
            }
        }
        $dark = 0;
        for ($y = 0; $y < $size; $y++) for ($x = 0; $x < $size; $x++) {
            if ($m[$y][$x]) $dark++;
            if ($y < $size - 1 && $x < $size - 1 && $m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) $score += 3;   // N2
        }
        $score += 10 * intdiv((int) abs($dark * 100 / ($size * $size) - 50), 5);        // N4
        return $score;
    }
}
