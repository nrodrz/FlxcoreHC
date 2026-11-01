<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Minimal dependency-free PDF writer (US Letter, Helvetica core fonts).
 * Built for shared hosting with no Composer: text, lines, filled rects,
 * word-wrap, multi-page. Coordinates are top-left based in points.
 */
final class MiniPdf
{
    public const W = 612.0;   // 8.5in
    public const H = 792.0;   // 11in

    /** @var string[] finished page content streams */
    private array $pages = [];
    private string $cur = '';
    private bool $open = false;

    public function page(): void
    {
        if ($this->open) { $this->pages[] = $this->cur; }
        $this->cur = '';
        $this->open = true;
    }

    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, array $rgb = [0.13, 0.16, 0.22]): void
    {
        $f = $bold ? 'F2' : 'F1';
        $this->cur .= sprintf("BT /%s %.2f Tf %.3f %.3f %.3f rg %.2f %.2f Td (%s) Tj ET\n",
            $f, $size, $rgb[0], $rgb[1], $rgb[2], $x, self::H - $y, $this->esc($s));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $w = 0.6, array $rgb = [0.78, 0.81, 0.86]): void
    {
        $this->cur .= sprintf("%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S\n",
            $rgb[0], $rgb[1], $rgb[2], $w, $x1, self::H - $y1, $x2, self::H - $y2);
    }

    public function rect(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->cur .= sprintf("%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f\n",
            $rgb[0], $rgb[1], $rgb[2], $x, self::H - $y - $h, $w, $h);
    }

    /** Approximate string width in points (Helvetica ~0.52em average). */
    public static function width(string $s, float $size): float
    {
        return strlen($s) * $size * 0.52;
    }

    /** Wrapped text within $maxW. Returns the y position AFTER the last line. */
    public function wrap(float $x, float $y, float $maxW, string $s, float $size = 10, bool $bold = false, array $rgb = [0.13, 0.16, 0.22], float $leading = 1.35): float
    {
        $words = preg_split('/\s+/', trim($s)) ?: [];
        $line = '';
        foreach ($words as $wd) {
            $try = $line === '' ? $wd : "$line $wd";
            if (self::width($try, $size) > $maxW && $line !== '') {
                $this->text($x, $y, $line, $size, $bold, $rgb);
                $y += $size * $leading;
                $line = $wd;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') { $this->text($x, $y, $line, $size, $bold, $rgb); $y += $size * $leading; }
        return $y;
    }

    private function esc(string $s): string
    {
        $t = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);
        if ($t === false) { $t = preg_replace('/[^\x20-\x7E]/', '?', $s) ?? $s; }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $t);
    }

    public function output(): string
    {
        if ($this->open) { $this->pages[] = $this->cur; $this->open = false; }
        $n = count($this->pages) ?: 1;
        if ($this->pages === []) { $this->pages = ['']; }

        // Object plan: 1 Catalog, 2 Pages, 3 F1, 4 F2, then per page: page obj + stream obj.
        $objs = [];
        $kids = [];
        for ($i = 0; $i < $n; $i++) { $kids[] = (5 + $i * 2) . ' 0 R'; }
        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objs[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count $n >>";
        $objs[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objs[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";
        foreach ($this->pages as $i => $stream) {
            $pid = 5 + $i * 2; $sid = $pid + 1;
            $objs[$pid] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . self::W . " " . self::H . "]"
                . " /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $sid 0 R >>";
            $objs[$sid] = "STREAM:" . $stream;
        }

        $out = "%PDF-1.4\n";
        $offsets = [];
        ksort($objs);
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($out);
            if (str_starts_with($body, 'STREAM:')) {
                $st = substr($body, 7);
                $out .= "$id 0 obj\n<< /Length " . strlen($st) . " >>\nstream\n$st\nendstream\nendobj\n";
            } else {
                $out .= "$id 0 obj\n$body\nendobj\n";
            }
        }
        $xref = strlen($out);
        $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $max; $id++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }
        $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
        return $out;
    }
}
