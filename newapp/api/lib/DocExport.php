<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — formal documents in PDF and Word (BT60).
 *
 * One document model (OpshDoc: a cover, headings, paragraphs, figures, tables, bar charts, notes, sign-off blocks)
 * rendered two ways from the same content:
 *   - opsh_doc_pdf($doc)  → a PDF built here (standard Helvetica fonts, no library needed);
 *   - opsh_doc_docx($doc) → a Word .docx built here (Office Open XML), editable before it is sent.
 * Used by the IT Budget document and the Calendar reports. Nothing here reads the network; only opsh_doc_brand() reads
 * the document branding settings (Settings → Branding → Documents), and the logo only ever from this app's own uploads.
 *
 * Text: PDF uses the Windows-1252 character set of the standard fonts; characters outside it are transliterated.
 */

if (!class_exists('OpshDoc')) {

    final class OpshDoc {
        public array $meta;
        public array $blocks = [];
        public function __construct(array $meta) {
            $this->meta = $meta + ['title' => 'Document', 'subtitle' => '', 'org' => '', 'logo' => null, 'footer' => '', 'date' => date('m/d/Y'),
                                   'accent' => '1D4D7A', 'status' => '', 'watermark' => '', 'cover' => [], 'toc' => true, 'landscape' => false, 'author' => '',
                                   'header' => '', 'eyebrow' => ''];
            $a = strtoupper(ltrim((string)$this->meta['accent'], '#')); $this->meta['accent'] = preg_match('/^[0-9A-F]{6}$/', $a) ? $a : '1D4D7A';
        }
        public function h1(string $t): self { $this->blocks[] = ['h1', $t]; return $this; }
        public function h2(string $t): self { $this->blocks[] = ['h2', $t]; return $this; }
        public function p(string $t, array $o = []): self { if (trim($t) !== '') $this->blocks[] = ['p', $t, $o]; return $this; }
        public function bullets(array $items): self { $items = array_values(array_filter(array_map('strval', $items), fn($x) => trim($x) !== '')); if ($items) $this->blocks[] = ['bullets', $items]; return $this; }
        /** @param array $tiles [[label, value, note], …] */
        public function kpis(array $tiles): self { if ($tiles) $this->blocks[] = ['kpis', array_values($tiles)]; return $this; }
        /**
         * @param array $cols [['label'=>, 'w'=>ratio, 'align'=>'L'|'R'|'C'], …]
         * @param array $rows [['cells'=>[…], 'style'=>null|'total'|'group'|'muted'], …] (a plain list of cells is accepted)
         */
        public function table(array $cols, array $rows, array $o = []): self {
            $rows = array_map(fn($r) => isset($r['cells']) ? $r : ['cells' => $r, 'style' => null], $rows);
            $this->blocks[] = ['table', $cols, $rows, $o]; return $this;
        }
        /** @param array $rows [[label, value(number), shown text], …] */
        public function bars(array $rows, array $o = []): self { if ($rows) $this->blocks[] = ['bars', array_values($rows), $o]; return $this; }
        public function note(string $t, string $tone = 'info'): self { if (trim($t) !== '') $this->blocks[] = ['note', $t, $tone]; return $this; }
        /** @param array $who [[role, name, title], …] */
        public function signatures(array $who): self { if ($who) $this->blocks[] = ['sign', array_values($who)]; return $this; }
        public function pagebreak(): self { $this->blocks[] = ['pagebreak']; return $this; }
        public function headings(): array { $o = []; foreach ($this->blocks as $b) if ($b[0] === 'h1') $o[] = $b[1]; return $o; }
    }

    /** Text helpers shared by both formats. */
    function opsh_doc_money(?float $v, string $cur = 'USD', bool $cents = false): string {
        if ($v === null) return '—';
        $sym = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'CAD' => 'CA$', 'MXN' => 'MX$'][$cur] ?? ($cur . ' ');
        $s = number_format(abs($v), $cents ? 2 : 0);
        return ($v < 0 ? '-' : '') . $sym . $s;
    }
    function opsh_doc_pct(?float $v, int $dec = 1): string { return $v === null ? '—' : number_format($v, $dec) . '%'; }

    /**
     * A logo for documents from a LOCAL file (never a URL): PNG, JPEG, GIF or WEBP. Re-encoded through GD when present
     * (flattened on white, at most 900 px wide) so only clean image data reaches the document. Returns null when the
     * file is not a usable image.
     */
    function opsh_doc_logo_load(string $file): ?array {
        if (!is_file($file) || filesize($file) > 6 * 1024 * 1024) return null;
        $raw = (string)@file_get_contents($file); if ($raw === '') return null;
        $info = @getimagesizefromstring($raw); if (!$info) return null;
        if (function_exists('imagecreatefromstring') && function_exists('imagepng')) {
            $im = @imagecreatefromstring($raw);
            if ($im) {
                $w = imagesx($im); $h = imagesy($im); $nw = $w; $nh = $h;
                if ($w > 900) { $nw = 900; $nh = max(1, (int)round($h * 900 / $w)); }
                $out = imagecreatetruecolor($nw, $nh);
                imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
                imagealphablending($out, true);
                imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagesavealpha($out, false);
                ob_start(); imagepng($out, null, 6); $png = (string)ob_get_clean();
                imagedestroy($im); imagedestroy($out);
                if ($png !== '') return ['data' => $png, 'type' => 'png', 'w' => $nw, 'h' => $nh];
            }
        }
        if ($info[2] === IMAGETYPE_JPEG) return ['data' => $raw, 'type' => 'jpg', 'w' => (int)$info[0], 'h' => (int)$info[1]];
        if ($info[2] === IMAGETYPE_PNG && opsh_pdf_png_parse($raw) !== null) return ['data' => $raw, 'type' => 'png', 'w' => (int)$info[0], 'h' => (int)$info[1]];
        return null;
    }

    /** PNG → [width, height, colors, idat] when the PDF can embed it as is (8-bit grey or RGB, not interlaced, no alpha). */
    function opsh_pdf_png_parse(string $png): ?array {
        if (substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;
        $p = 8; $idat = ''; $w = $h = $bits = $ct = $il = null;
        while ($p + 8 <= strlen($png)) {
            $len = unpack('N', substr($png, $p, 4))[1]; $type = substr($png, $p + 4, 4); $data = substr($png, $p + 8, $len); $p += 12 + $len;
            if ($type === 'IHDR') { $u = unpack('Nw/Nh/Cbits/Cct/Ccm/Cfm/Cil', $data); $w = $u['w']; $h = $u['h']; $bits = $u['bits']; $ct = $u['ct']; $il = $u['il']; }
            elseif ($type === 'IDAT') $idat .= $data;
            elseif ($type === 'IEND') break;
        }
        if (!$w || $bits !== 8 || $il !== 0 || !in_array($ct, [0, 2], true) || $idat === '') return null;
        return ['w' => $w, 'h' => $h, 'colors' => $ct === 2 ? 3 : 1, 'idat' => $idat];
    }

    // ═══════════════════════════════════════════════ PDF ═══════════════════════════════════════════════

    final class OpshPdfWriter {
        private const HELV = [0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,761,556,556,222,556,333,1000,556,556,333,1000,667,333,1000,556,611,556,556,222,222,333,333,350,556,1000,333,1000,500,333,944,556,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500];
        private const HELVB = [0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,761,556,556,278,556,500,1000,556,556,333,1000,667,333,1000,556,611,556,556,278,278,500,500,350,556,1000,333,1000,556,333,944,556,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556];
        private float $W = 612, $H = 792, $ml = 54, $mr = 54, $mt = 76, $mb = 58;
        private array $pages = [];      // content streams
        private int $pg = -1;
        private float $y = 0;
        private array $toc = [];
        private ?int $tocPage = null;
        private bool $hasLogo = false;
        private string $acc;
        private OpshDoc $doc;

        public function __construct(OpshDoc $doc) {
            $this->doc = $doc; $this->acc = $doc->meta['accent'];
            if (!empty($doc->meta['landscape'])) { $this->W = 792; $this->H = 612; }
            $this->hasLogo = is_array($doc->meta['logo']) && !empty($doc->meta['logo']['data']);
        }

        // ── low level ──
        public static function enc(string $s): string {
            $s = strtr($s, ['→' => '->', '←' => '<-', '≥' => '>=', '≤' => '<=', '✓' => 'v', '✕' => 'x', '×' => 'x', '−' => '-', "\u{00A0}" => ' ', "\u{2009}" => ' ', "\u{202F}" => ' ', '★' => '*', '▲' => '+', '▼' => '-', '≈' => '~']);
            $o = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
            if ($o === false || $o === '') $o = function_exists('mb_convert_encoding') ? (string)@mb_convert_encoding($s, 'Windows-1252', 'UTF-8') : preg_replace('/[^\x20-\x7E]/', '?', $s);
            return (string)$o;
        }
        private static function esc(string $b): string { return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $b); }
        private static function rgb(string $hex): string { $hex = ltrim($hex, '#'); return sprintf('%.3F %.3F %.3F', hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255); }
        private function out(string $s): void { $this->pages[$this->pg] .= $s . "\n"; }
        public function wb(string $bytes, float $size, bool $bold = false): float {   // width of encoded bytes
            $t = $bold ? self::HELVB : self::HELV; $w = 0; $n = strlen($bytes);
            for ($i = 0; $i < $n; $i++) $w += $t[ord($bytes[$i])];
            return $w * $size / 1000;
        }
        private function textB(float $x, float $y, string $bytes, float $size, bool $bold, string $color, string $align = 'L', float $boxW = 0): void {
            if ($bytes === '') return;
            if ($align === 'R') $x = $x + $boxW - $this->wb($bytes, $size, $bold);
            elseif ($align === 'C') $x = $x + ($boxW - $this->wb($bytes, $size, $bold)) / 2;
            $this->out(sprintf('BT /%s %.2F Tf %s rg %.2F %.2F Td (%s) Tj ET', $bold ? 'F2' : 'F1', $size, self::rgb($color), $x, $this->H - $y, self::esc($bytes)));
        }
        private function text(float $x, float $y, string $s, float $size, bool $bold = false, string $color = '2E3A44', string $align = 'L', float $boxW = 0): void {
            $this->textB($x, $y, self::enc($s), $size, $bold, $color, $align, $boxW);
        }
        private function rect(float $x, float $y, float $w, float $h, ?string $fill, ?string $stroke = null, float $lw = 0.5): void {
            $s = sprintf('%.2F %.2F %.2F %.2F re', $x, $this->H - $y - $h, $w, $h);
            if ($fill && $stroke) $this->out(sprintf('%s rg %s RG %.2F w %s B', self::rgb($fill), self::rgb($stroke), $lw, $s));
            elseif ($fill) $this->out(sprintf('%s rg %s f', self::rgb($fill), $s));
            elseif ($stroke) $this->out(sprintf('%s RG %.2F w %s S', self::rgb($stroke), $lw, $s));
        }
        private function line(float $x1, float $y1, float $x2, float $y2, string $color, float $lw = 0.5): void {
            $this->out(sprintf('%s RG %.2F w %.2F %.2F m %.2F %.2F l S', self::rgb($color), $lw, $x1, $this->H - $y1, $x2, $this->H - $y2));
        }
        private function logo(float $x, float $y, float $maxW, float $maxH): float {   // returns the width drawn
            if (!$this->hasLogo) return 0;
            $L = $this->doc->meta['logo']; $r = min($maxW / max(1, $L['w']), $maxH / max(1, $L['h'])); $w = $L['w'] * $r; $h = $L['h'] * $r;
            $this->out(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q', $w, $h, $x, $this->H - $y - $h));
            return $w;
        }
        /** Wrap text to lines of encoded bytes. */
        public function wrap(string $s, float $size, bool $bold, float $maxW): array {
            $lines = [];
            foreach (preg_split('/\r?\n/', $s) as $para) {
                $words = preg_split('/ +/', self::enc($para)); $cur = '';
                foreach ($words as $w) {
                    $try = $cur === '' ? $w : $cur . ' ' . $w;
                    if ($this->wb($try, $size, $bold) <= $maxW) { $cur = $try; continue; }
                    if ($cur !== '') $lines[] = $cur;
                    while ($this->wb($w, $size, $bold) > $maxW && strlen($w) > 1) {   // a word longer than the line
                        $k = strlen($w); while ($k > 1 && $this->wb(substr($w, 0, $k), $size, $bold) > $maxW) $k--;
                        $lines[] = substr($w, 0, $k); $w = substr($w, $k);
                    }
                    $cur = $w;
                }
                $lines[] = $cur;
            }
            return $lines;
        }
        private function cw(): float { return $this->W - $this->ml - $this->mr; }
        private function newPage(bool $cover = false): void {
            $this->pages[] = ''; $this->pg = count($this->pages) - 1; $this->y = $this->mt;
            if (!$cover) $this->header();
        }
        private function ensure(float $h): bool { if ($this->y + $h > $this->H - $this->mb) { $this->newPage(); return true; } return false; }
        private function header(): void {
            $m = $this->doc->meta; $top = 30;
            $lw = $this->logo($this->ml, $top - 4, 110, 22);
            $right = trim((string)($m['header'] ?: $m['title']));
            $this->text($this->ml, $top + 12, $lw ? '' : (string)$m['org'], 8.5, true, $this->acc);
            $this->text($this->ml, $top + 12, $right, 8, false, '6B7A88', 'R', $this->cw());
            if ($m['status'] !== '') $this->text($this->ml, $top + 22, strtoupper((string)$m['status']), 6.8, true, 'B5562E', 'R', $this->cw());
            $this->line($this->ml, $top + 28, $this->W - $this->mr, $top + 28, 'C7D2DE', 0.6);
        }

        // ── blocks ──
        private function cover(): void {
            $m = $this->doc->meta; $this->newPage(true);
            $this->rect(0, 0, $this->W, 8, $this->acc);
            $y = 70;
            if ($this->hasLogo) { $this->logo($this->ml, $y, 220, 86); }
            else if ($m['org'] !== '') $this->text($this->ml, $y + 24, (string)$m['org'], 20, true, $this->acc);
            $y = $this->H * 0.36;
            if ($m['eyebrow'] !== '' || $m['org'] !== '') { $this->text($this->ml, $y, strtoupper((string)($m['eyebrow'] ?: $m['org'])), 9, true, '6B7A88'); $y += 16; }
            foreach ($this->wrap((string)$m['title'], 30, true, $this->cw()) as $ln) { $y += 32; $this->textB($this->ml, $y, $ln, 30, true, $this->acc); }
            $y += 12; $this->rect($this->ml, $y, 64, 3.5, $this->acc); $y += 14;
            foreach ($this->wrap((string)$m['subtitle'], 14, false, $this->cw()) as $ln) { $y += 18; $this->textB($this->ml, $y, $ln, 14, false, '4A5A68'); }
            if ($m['status'] !== '') { $y += 22; $st = self::enc(strtoupper((string)$m['status'])); $w = $this->wb($st, 8, true) + 16;
                $this->rect($this->ml, $y, $w, 17, 'FBEFE8', 'E3B9A3', 0.6); $this->textB($this->ml + 8, $y + 12, $st, 8, true, 'B5562E'); $y += 17; }
            // details
            $rows = array_values(array_filter((array)$m['cover'], fn($r) => is_array($r) && trim((string)($r[1] ?? '')) !== ''));
            $y = max($y + 40, $this->H * 0.66); $colW = $this->cw() / 2;
            foreach ($rows as $i => $r) {
                $x = $this->ml + ($i % 2) * $colW; if ($i % 2 === 0 && $i > 0) $y += 34;
                $this->text($x, $y, strtoupper((string)$r[0]), 7, true, '6B7A88');
                $vl = $this->wrap((string)$r[1], 10.5, false, $colW - 14); $this->textB($x, $y + 14, $vl[0] ?? '', 10.5, false, '2E3A44');
            }
            $this->line($this->ml, $this->H - 62, $this->W - $this->mr, $this->H - 62, 'C7D2DE', 0.6);
            $this->text($this->ml, $this->H - 46, (string)$m['footer'], 7.6, false, '6B7A88');
            $this->text($this->ml, $this->H - 46, (string)$m['date'], 7.6, false, '6B7A88', 'R', $this->cw());
        }
        private function h1(string $t): void {
            if ($this->y > $this->mt + 4) $this->y += 16;
            $this->ensure(110);
            $this->toc[] = [$t, $this->pg];
            $lines = $this->wrap($t, 16, true, $this->cw());
            foreach ($lines as $ln) { $this->y += 19; $this->textB($this->ml, $this->y, $ln, 16, true, $this->acc); }
            $this->y += 7; $this->rect($this->ml, $this->y, 40, 2.2, $this->acc); $this->y += 14;
        }
        private function h2(string $t): void {
            $this->y += 8; $this->ensure(90);
            foreach ($this->wrap($t, 11.5, true, $this->cw()) as $ln) { $this->y += 14; $this->textB($this->ml, $this->y, $ln, 11.5, true, '2E3A44'); }
            $this->y += 7;
        }
        private function para(string $t, array $o): void {
            $size = (float)($o['size'] ?? 9.8); $lead = $size * 1.45; $col = (string)($o['color'] ?? '2E3A44'); $bold = !empty($o['bold']);
            foreach ($this->wrap($t, $size, $bold, $this->cw()) as $ln) { $this->ensure($lead); $this->y += $lead; $this->textB($this->ml, $this->y - 3, $ln, $size, $bold, $col); }
            $this->y += 6;
        }
        private function bullets(array $items): void {
            foreach ($items as $it) {
                $lines = $this->wrap($it, 9.8, false, $this->cw() - 16);
                foreach ($lines as $i => $ln) { $this->ensure(14.2); $this->y += 14.2; if ($i === 0) $this->textB($this->ml + 3, $this->y - 3, "\x95", 9.8, true, $this->acc); $this->textB($this->ml + 16, $this->y - 3, $ln, 9.8, false, '2E3A44'); }
                $this->y += 2;
            }
            $this->y += 5;
        }
        private function kpis(array $tiles): void {
            $per = min(4, count($tiles)); $gap = 10; $h = 60;
            foreach (array_chunk($tiles, $per) as $row) {
                $n = count($row); $w = ($this->cw() - $gap * ($per - 1)) / $per;
                $this->ensure($h + 10);
                foreach ($row as $i => $t) {
                    $x = $this->ml + $i * ($w + $gap); $y = $this->y;
                    $this->rect($x, $y, $w, $h, 'F3F6F9'); $this->rect($x, $y, 3, $h, $this->acc);
                    $lab = $this->wrap(strtoupper((string)$t[0]), 6.8, true, $w - 18);
                    $this->textB($x + 11, $y + 14, $lab[0] ?? '', 6.8, true, '6B7A88');
                    $val = self::enc((string)$t[1]); $vs = 15.5; while ($vs > 9 && $this->wb($val, $vs, true) > $w - 18) $vs -= 0.5;
                    $this->textB($x + 11, $y + 35, $val, $vs, true, $this->acc);
                    $sub = $this->wrap((string)($t[2] ?? ''), 7.2, false, $w - 18);
                    if ($sub && $sub[0] !== '') $this->textB($x + 11, $y + 50, $sub[0] . (count($sub) > 1 ? "\x85" : ''), 7.2, false, '6B7A88');
                }
                $this->y += $h + 10;
            }
            $this->y += 4;
        }
        private function table(array $cols, array $rows, array $o): void {
            $fs = (float)($o['size'] ?? 8.3); $lead = $fs * 1.32; $pad = 4.2;
            $tot = array_sum(array_map(fn($c) => (float)($c['w'] ?? 1), $cols)) ?: 1;
            $ws = array_map(fn($c) => $this->cw() * (float)($c['w'] ?? 1) / $tot, $cols);
            $head = function () use ($cols, $ws, $fs, $pad) {
                $hl = []; $hh = 0;
                foreach ($cols as $i => $c) { $hl[$i] = $this->wrap((string)$c['label'], $fs - 0.6, true, $ws[$i] - 2 * $pad); $hh = max($hh, count($hl[$i])); }
                $h = $hh * ($fs * 1.25) + 2 * $pad + 1;
                $this->ensure($h + 18);
                $this->rect($this->ml, $this->y, $this->cw(), $h, $this->acc);
                $x = $this->ml;
                foreach ($cols as $i => $c) { foreach ($hl[$i] as $k => $ln) $this->textB($x + $pad, $this->y + $pad + ($k + 1) * ($fs * 1.25) - 1, $ln, $fs - 0.6, true, 'FFFFFF', $c['align'] ?? 'L', $ws[$i] - 2 * $pad); $x += $ws[$i]; }
                $this->y += $h;
            };
            $head();
            foreach ($rows as $ri => $r) {
                $st = $r['style'] ?? null; $cells = array_values($r['cells']);
                if ($st === 'group') {
                    $h = $lead + 2 * $pad; if ($this->ensure($h + $lead)) $head();
                    $this->rect($this->ml, $this->y, $this->cw(), $h, 'E8EEF4');
                    $this->text($this->ml + $pad, $this->y + $pad + $lead - 2, (string)($cells[0] ?? ''), $fs + 0.2, true, $this->acc);
                    if (isset($cells[1]) && $cells[1] !== '') $this->text($this->ml + $pad, $this->y + $pad + $lead - 2, (string)$cells[1], $fs + 0.2, true, $this->acc, 'R', $this->cw() - 2 * $pad);
                    $this->y += $h; continue;
                }
                $bold = $st === 'total'; $col = $st === 'muted' ? '8A97A3' : '2E3A44';
                $wl = []; $n = 1;
                foreach ($cols as $i => $c) { $wl[$i] = $this->wrap((string)($cells[$i] ?? ''), $fs, $bold, $ws[$i] - 2 * $pad); $n = max($n, count($wl[$i])); }
                $h = $n * $lead + 2 * $pad - 1;
                if ($this->ensure($h)) $head();
                if ($bold) { $this->rect($this->ml, $this->y, $this->cw(), $h, 'EAF0F6'); $this->line($this->ml, $this->y, $this->W - $this->mr, $this->y, $this->acc, 0.9); }
                elseif ($ri % 2 === 1) $this->rect($this->ml, $this->y, $this->cw(), $h, 'F6F8FA');
                $x = $this->ml;
                foreach ($cols as $i => $c) { foreach ($wl[$i] as $k => $ln) $this->textB($x + $pad, $this->y + $pad + ($k + 1) * $lead - 2, $ln, $fs, $bold, $col, $c['align'] ?? 'L', $ws[$i] - 2 * $pad); $x += $ws[$i]; }
                $this->y += $h;
                $this->line($this->ml, $this->y, $this->W - $this->mr, $this->y, 'DDE4EB', 0.4);
            }
            if (!$rows) { $this->ensure(18); $this->y += 14; $this->text($this->ml + 4, $this->y, (string)($o['empty'] ?? 'Nothing to show.'), $fs, false, '8A97A3'); }
            $this->y += 12;
        }
        private function bars(array $rows, array $o): void {
            $max = 0; foreach ($rows as $r) $max = max($max, (float)$r[1]); $max = $max ?: 1;
            $lw = $this->cw() * 0.32; $vw = $this->cw() * 0.17; $bw = $this->cw() - $lw - $vw - 12; $h = 17;
            if (count($rows) * $h <= $this->H - $this->mt - $this->mb - 40) $this->ensure(count($rows) * $h);   // keep a chart on one page
            foreach ($rows as $r) {
                $this->ensure($h);
                $lab = $this->wrap((string)$r[0], 8.3, false, $lw - 6); $this->textB($this->ml, $this->y + 11.5, ($lab[0] ?? '') . (count($lab) > 1 ? "\x85" : ''), 8.3, false, '2E3A44');
                $x = $this->ml + $lw; $this->rect($x, $this->y + 3.5, $bw, 9.5, 'EEF2F6');
                $w = max(0, min(1, (float)$r[1] / $max)) * $bw; if ($w > 0) $this->rect($x, $this->y + 3.5, max(1.5, $w), 9.5, ltrim((string)($r[3] ?? $this->acc), '#'));
                $this->text($x + $bw + 6, $this->y + 11.5, (string)($r[2] ?? ''), 8.3, true, '2E3A44', 'R', $vw);
                $this->y += $h;
            }
            $this->y += 10;
        }
        private function note(string $t, string $tone): void {
            $fill = $tone === 'warn' ? 'FBF5EA' : 'F3F6F9'; $bar = $tone === 'warn' ? 'C08A30' : $this->acc;
            $lines = $this->wrap($t, 9, false, $this->cw() - 26); $h = count($lines) * 13 + 14;
            $this->ensure(min($h, 200));
            $this->rect($this->ml, $this->y, $this->cw(), $h, $fill); $this->rect($this->ml, $this->y, 3, $h, $bar);
            $yy = $this->y + 7; foreach ($lines as $ln) { $yy += 13; if ($yy > $this->H - $this->mb) { $this->newPage(); $yy = $this->y + 13; } $this->textB($this->ml + 14, $yy - 3, $ln, 9, false, '2E3A44'); }
            $this->y = max($this->y + $h, $yy + 7) + 10;
        }
        private function sign(array $who): void {
            $per = min(3, count($who)); $gap = 22; $w = ($this->cw() - $gap * ($per - 1)) / $per;
            foreach (array_chunk($who, $per) as $row) {
                $this->ensure(104); $y0 = $this->y + 46;
                foreach ($row as $i => $s) {
                    $x = $this->ml + $i * ($w + $gap);
                    $this->line($x, $y0, $x + $w, $y0, '2E3A44', 0.7);
                    $this->text($x, $y0 + 13, (string)$s[0], 8.6, true, '2E3A44');
                    $this->text($x, $y0 + 26, trim((string)($s[1] ?? '')) !== '' ? (string)$s[1] : 'Name: ________________________', 8.4, false, '4A5A68');
                    if (trim((string)($s[2] ?? '')) !== '') $this->text($x, $y0 + 38, (string)$s[2], 8, false, '6B7A88');
                    $this->text($x, $y0 + 52, 'Date: ________________', 8.4, false, '4A5A68');
                }
                $this->y = $y0 + 66;
            }
        }
        private function renderToc(): void {
            if ($this->tocPage === null) return;
            $keep = $this->pg; $this->pg = $this->tocPage; $y = $this->mt + 22;
            $this->textB($this->ml, $y, 'Contents', 16, true, $this->acc); $y += 8; $this->rect($this->ml, $y, 40, 2.2, $this->acc); $y += 24;
            foreach ($this->toc as $i => [$t, $p]) {
                $num = (string)($p + 1); $tb = self::enc(($i + 1) . '.  ' . $t);
                $this->textB($this->ml, $y, $tb, 10.5, false, '2E3A44');
                $x1 = $this->ml + $this->wb($tb, 10.5) + 6; $x2 = $this->W - $this->mr - $this->wb($num, 10.5) - 6;
                if ($x2 > $x1) $this->out(sprintf('[1 2.5] 0 d %s RG 0.6 w %.2F %.2F m %.2F %.2F l S [] 0 d', self::rgb('B8C4CF'), $x1, $this->H - $y + 1, $x2, $this->H - $y + 1));
                $this->textB($this->ml, $y, $num, 10.5, false, '2E3A44', 'R', $this->cw());
                $y += 21;
                if ($y > $this->H - $this->mb) break;
            }
            $this->pg = $keep;
        }
        private function footers(): void {
            $m = $this->doc->meta; $n = count($this->pages);
            for ($i = 1; $i < $n; $i++) {
                $this->pg = $i; $y = $this->H - 32;
                $this->line($this->ml, $y - 12, $this->W - $this->mr, $y - 12, 'DDE4EB', 0.5);
                $this->text($this->ml, $y, trim((string)$m['footer']), 7.4, false, '6B7A88');
                $this->text($this->ml, $y, 'Page ' . ($i + 1) . ' of ' . $n, 7.4, false, '6B7A88', 'R', $this->cw());
                if ($m['watermark'] !== '') {   // drawn last, nearly transparent, so it shows over table shading too
                    $wm = self::enc(strtoupper((string)$m['watermark'])); $s = 92; $c = 0.7071;
                    $wmw = $this->wb($wm, $s, true); $cx = $this->W / 2 - $wmw * $c / 2; $cy = $this->H / 2 - $wmw * $c / 2;
                    $this->pages[$i] .= sprintf("q /GS1 gs BT /F2 %d Tf 0.45 0.5 0.55 rg %.4F %.4F %.4F %.4F %.2F %.2F Tm (%s) Tj ET Q\n", $s, $c, $c, -$c, $c, $cx, $cy, self::esc($wm));
                }
            }
        }

        public function render(): string {
            $d = $this->doc;
            $this->cover();
            $hs = $d->headings();
            if (!empty($d->meta['toc']) && count($hs) > 1) { $this->newPage(); $this->tocPage = $this->pg; }
            $this->newPage();
            foreach ($d->blocks as $b) {
                switch ($b[0]) {
                    case 'h1': $this->h1($b[1]); break;
                    case 'h2': $this->h2($b[1]); break;
                    case 'p': $this->para($b[1], $b[2] ?? []); break;
                    case 'bullets': $this->bullets($b[1]); break;
                    case 'kpis': $this->kpis($b[1]); break;
                    case 'table': $this->table($b[1], $b[2], $b[3] ?? []); break;
                    case 'bars': $this->bars($b[1], $b[2] ?? []); break;
                    case 'note': $this->note($b[1], $b[2] ?? 'info'); break;
                    case 'sign': $this->sign($b[1]); break;
                    case 'pagebreak': if ($this->y > $this->mt + 4) $this->newPage(); break;
                }
            }
            $this->renderToc();
            $this->footers();
            return $this->assemble();
        }

        private function assemble(): string {
            $objs = [];   // index → body (1-based ids)
            $add = function (string $body) use (&$objs): int { $objs[] = $body; return count($objs); };
            $catalog = $add(''); $pagesId = $add('');
            $f1 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
            $f2 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
            $img = null;
            if ($this->hasLogo) {
                $L = $this->doc->meta['logo'];
                if ($L['type'] === 'jpg') {
                    $img = $add(sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n", $L['w'], $L['h'], strlen($L['data'])) . $L['data'] . "\nendstream");
                } else {
                    $p = opsh_pdf_png_parse($L['data']);
                    if ($p) $img = $add(sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent 8 /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors %d /BitsPerComponent 8 /Columns %d >> /Length %d >>\nstream\n",
                        $p['w'], $p['h'], $p['colors'] === 3 ? 'DeviceRGB' : 'DeviceGray', $p['colors'], $p['w'], strlen($p['idat'])) . $p['idat'] . "\nendstream");
                }
            }
            $res = '<< /Font << /F1 ' . $f1 . ' 0 R /F2 ' . $f2 . ' 0 R >> /ExtGState << /GS1 << /Type /ExtGState /ca 0.09 /CA 0.09 >> >>' . ($img ? ' /XObject << /Im1 ' . $img . ' 0 R >>' : '') . ' >>';
            $kids = [];
            foreach ($this->pages as $c) {
                if (!$img) $c = preg_replace('/^q [^\n]* \/Im1 Do Q\n/m', '', $c);
                $z = function_exists('gzcompress') ? gzcompress($c, 6) : null;
                $cs = $add($z !== null ? "<< /Length " . strlen($z) . " /Filter /FlateDecode >>\nstream\n" . $z . "\nendstream" : "<< /Length " . strlen($c) . " >>\nstream\n" . $c . "\nendstream");
                $kids[] = $add(sprintf('<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.0F %.0F] /Resources %s /Contents %d 0 R >>', $pagesId, $this->W, $this->H, $res, $cs));
            }
            $objs[$catalog - 1] = '<< /Type /Catalog /Pages ' . $pagesId . ' 0 R >>';
            $objs[$pagesId - 1] = '<< /Type /Pages /Kids [' . implode(' ', array_map(fn($k) => $k . ' 0 R', $kids)) . '] /Count ' . count($kids) . ' >>';
            $m = $this->doc->meta;
            $info = $add('<< /Title (' . self::esc(self::enc((string)$m['title'])) . ') /Author (' . self::esc(self::enc((string)($m['author'] ?: $m['org']))) . ') /Producer (' . self::esc(self::enc((string)($m['producer'] ?? 'OpsHub'))) . ') /CreationDate (D:' . date('YmdHis') . ') >>');
            $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $off = [];
            foreach ($objs as $i => $b) { $off[$i + 1] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n" . $b . "\nendobj\n"; }
            $x = strlen($pdf);
            $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
            for ($i = 1; $i <= count($objs); $i++) $pdf .= sprintf("%010d 00000 n \n", $off[$i]);
            $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root $catalog 0 R /Info $info 0 R >>\nstartxref\n$x\n%%EOF\n";
            return $pdf;
        }
    }

    function opsh_doc_pdf(OpshDoc $doc): string { return (new OpshPdfWriter($doc))->render(); }

    // ═══════════════════════════════════════════════ WORD (.docx) ═══════════════════════════════════════════════

    final class OpshDocxWriter {
        private OpshDoc $doc; private string $acc; private int $cw; private bool $logo; private string $logoExt = 'png';
        private int $pid = 100;
        public function __construct(OpshDoc $doc) {
            $this->doc = $doc; $this->acc = $doc->meta['accent'];
            $this->cw = !empty($doc->meta['landscape']) ? 12960 : 10080;   // content width in twentieths of a point (0.75" margins)
            $this->logo = is_array($doc->meta['logo']) && !empty($doc->meta['logo']['data']);
            if ($this->logo) $this->logoExt = $doc->meta['logo']['type'] === 'jpg' ? 'jpeg' : 'png';
        }
        private static function x(string $s): string {
            $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
            return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        private function run(string $t, array $o = []): string {
            $rp = '';
            if (!empty($o['bold'])) $rp .= '<w:b/>';
            if (!empty($o['caps'])) $rp .= '<w:caps/>';
            if (!empty($o['color']) && preg_match('/^[0-9A-F]{6}$/i', (string)$o['color'])) $rp .= '<w:color w:val="' . $o['color'] . '"/>';   // review WORK-23: hex only
            if (!empty($o['spacing'])) $rp .= '<w:spacing w:val="' . (int)$o['spacing'] . '"/>';
            if (!empty($o['size'])) $rp .= '<w:sz w:val="' . (int)round($o['size'] * 2) . '"/><w:szCs w:val="' . (int)round($o['size'] * 2) . '"/>';
            $parts = explode("\n", $t); $x = '';
            foreach ($parts as $i => $p) { if ($i) $x .= '<w:br/>'; $x .= '<w:t xml:space="preserve">' . self::x($p) . '</w:t>'; }
            return '<w:r>' . ($rp ? '<w:rPr>' . $rp . '</w:rPr>' : '') . $x . '</w:r>';
        }
        private function para(string $inner, array $o = []): string {
            $pp = '';
            if (!empty($o['style']) && preg_match('/^[A-Za-z0-9]{1,40}$/', (string)$o['style'])) $pp .= '<w:pStyle w:val="' . $o['style'] . '"/>';
            if (!empty($o['keep'])) $pp .= '<w:keepNext/>';
            if (!empty($o['pagebreak'])) $pp .= '<w:pageBreakBefore/>';
            if (!empty($o['numId'])) $pp .= '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="' . (int)$o['numId'] . '"/></w:numPr>';
            if (!empty($o['border'])) $pp .= '<w:pBdr><w:bottom w:val="single" w:sz="' . ($o['border_sz'] ?? 6) . '" w:space="1" w:color="' . $o['border'] . '"/></w:pBdr>';
            if (isset($o['before']) || isset($o['after'])) $pp .= '<w:spacing w:before="' . (int)($o['before'] ?? 0) . '" w:after="' . (int)($o['after'] ?? 0) . '"/>';
            if (!empty($o['align']) && in_array($o['align'], ['left', 'center', 'right', 'both', 'start', 'end'], true)) $pp .= '<w:jc w:val="' . $o['align'] . '"/>';
            return '<w:p>' . ($pp ? '<w:pPr>' . $pp . '</w:pPr>' : '') . $inner . '</w:p>';
        }
        private function image(string $rid, float $maxWin, float $maxHin, string $name = 'Logo'): string {
            $L = $this->doc->meta['logo']; $r = min($maxWin / max(1, $L['w']), $maxHin / max(1, $L['h']));
            $cx = (int)round($L['w'] * $r * 914400); $cy = (int)round($L['h'] * $r * 914400); $id = ++$this->pid;
            return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="' . $id . '" name="' . $name . ' ' . $id . '"/>'
                . '<wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>'
                . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
                . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="' . $id . '" name="logo.' . $this->logoExt . '"/><pic:cNvPicPr/></pic:nvPicPr>'
                . '<pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
                . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
        }
        private function cell(string $inner, int $w, array $o = []): string {
            $tc = '<w:tcW w:w="' . $w . '" w:type="dxa"/>';
            if (!empty($o['span'])) $tc .= '<w:gridSpan w:val="' . (int)$o['span'] . '"/>';
            if (!empty($o['borders'])) $tc .= '<w:tcBorders>' . $o['borders'] . '</w:tcBorders>';
            if (!empty($o['fill']) && preg_match('/^[0-9A-F]{6}$/i', (string)$o['fill'])) $tc .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $o['fill'] . '"/>';
            if (!empty($o['mar'])) $tc .= '<w:tcMar>' . $o['mar'] . '</w:tcMar>';
            if (!empty($o['valign']) && in_array($o['valign'], ['top', 'center', 'bottom'], true)) $tc .= '<w:vAlign w:val="' . $o['valign'] . '"/>';
            return '<w:tc><w:tcPr>' . $tc . '</w:tcPr>' . ($inner === '' ? '<w:p/>' : $inner) . '</w:tc>';
        }
        private function tbl(array $widths, string $rows, array $o = []): string {
            $b = $o['borders'] ?? '<w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/>';
            $grid = implode('', array_map(fn($w) => '<w:gridCol w:w="' . $w . '"/>', $widths));
            return '<w:tbl><w:tblPr><w:tblW w:w="' . array_sum($widths) . '" w:type="dxa"/><w:tblBorders>' . $b . '</w:tblBorders><w:tblLayout w:type="fixed"/>'
                . '<w:tblCellMar><w:top w:w="' . ($o['pad'] ?? 60) . '" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:bottom w:w="' . ($o['pad'] ?? 60) . '" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr>'
                . '<w:tblGrid>' . $grid . '</w:tblGrid>' . $rows . '</w:tbl>';
        }
        private static function split(int $total, array $ratios): array {
            $s = array_sum($ratios) ?: 1; $o = []; $acc = 0;
            foreach ($ratios as $i => $r) { $w = $i === count($ratios) - 1 ? $total - $acc : (int)floor($total * $r / $s); $o[] = $w; $acc += $w; }
            return $o;
        }
        private function cover(): string {
            $m = $this->doc->meta; $x = '';
            $x .= $this->para($this->logo ? $this->image('rIdLogo', 2.9, 1.15) : $this->run((string)$m['org'], ['bold' => true, 'size' => 20, 'color' => $this->acc]), ['after' => 0]);
            $x .= $this->para('', ['before' => 2400, 'after' => 0]);
            if ($m['eyebrow'] !== '' || $m['org'] !== '') $x .= $this->para($this->run((string)($m['eyebrow'] ?: $m['org']), ['bold' => true, 'caps' => true, 'size' => 9, 'color' => '6B7A88', 'spacing' => 20]), ['after' => 120]);
            $x .= $this->para($this->run((string)$m['title']), ['style' => 'Title']);
            $x .= $this->para('', ['border' => $this->acc, 'border_sz' => 24, 'after' => 240]);
            if ($m['subtitle'] !== '') $x .= $this->para($this->run((string)$m['subtitle']), ['style' => 'Subtitle']);
            if ($m['status'] !== '') $x .= $this->para($this->run(strtoupper((string)$m['status']), ['bold' => true, 'size' => 8.5, 'color' => 'B5562E', 'spacing' => 20]), ['before' => 160, 'after' => 0]);
            $rows = array_values(array_filter((array)$m['cover'], fn($r) => is_array($r) && trim((string)($r[1] ?? '')) !== ''));
            if ($rows) {
                $x .= $this->para('', ['before' => 1500, 'after' => 0]);
                $half = intdiv($this->cw, 2); $tr = '';
                foreach (array_chunk($rows, 2) as $pair) {
                    $cells = '';
                    foreach ([0, 1] as $i) {
                        $r = $pair[$i] ?? null;
                        $cells .= $this->cell($r ? $this->para($this->run((string)$r[0], ['bold' => true, 'caps' => true, 'size' => 7.5, 'color' => '6B7A88']), ['after' => 20]) . $this->para($this->run((string)$r[1], ['size' => 10.5]), ['after' => 0]) : '', $half);
                    }
                    $tr .= '<w:tr>' . $cells . '</w:tr>';
                }
                $x .= $this->tbl([$half, $half], $tr, ['pad' => 90]);
            }
            $x .= $this->para('', ['before' => 600, 'after' => 0, 'border' => 'C7D2DE']);
            $x .= $this->para($this->run(trim((string)$m['footer'] . '   ·   ' . (string)$m['date'], ' ·'), ['size' => 8, 'color' => '6B7A88']), ['after' => 0]);
            return $x;
        }
        private function toc(): string {
            $hs = $this->doc->headings(); if (empty($this->doc->meta['toc']) || count($hs) < 2) return '';
            $x = $this->para($this->run('Contents'), ['style' => 'Heading1', 'pagebreak' => true]);
            $x .= '<w:p><w:pPr><w:pStyle w:val="TOC1"/></w:pPr><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> TOC \o "1-1" \h \z \u </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r>'
                . $this->run('1.  ' . $hs[0]) . '</w:p>';
            foreach (array_slice($hs, 1) as $i => $h) $x .= $this->para($this->run(($i + 2) . '.  ' . $h), ['style' => 'TOC1']);
            $x .= '<w:p><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>';
            return $x;
        }
        private function kpis(array $tiles): string {
            $x = ''; $per = min(4, count($tiles));
            foreach (array_chunk($tiles, $per) as $row) {
                $ws = self::split($this->cw, array_fill(0, $per, 1)); $cells = '';
                foreach ($ws as $i => $w) {
                    $t = $row[$i] ?? null;
                    $b = '<w:left w:val="single" w:sz="24" w:space="0" w:color="' . ($t ? $this->acc : 'FFFFFF') . '"/><w:right w:val="single" w:sz="24" w:space="0" w:color="FFFFFF"/>';
                    $inner = $t ? $this->para($this->run((string)$t[0], ['bold' => true, 'caps' => true, 'size' => 7, 'color' => '6B7A88']), ['after' => 40])
                        . $this->para($this->run((string)$t[1], ['bold' => true, 'size' => 15, 'color' => $this->acc]), ['after' => 20])
                        . $this->para($this->run((string)($t[2] ?? ''), ['size' => 7.5, 'color' => '6B7A88']), ['after' => 0]) : '';
                    $cells .= $this->cell($inner, $w, ['fill' => $t ? 'F3F6F9' : null, 'borders' => $b]);
                }
                $x .= $this->tbl($ws, '<w:tr><w:trPr><w:cantSplit/></w:trPr>' . $cells . '</w:tr>', ['pad' => 110]) . $this->para('', ['after' => 60]);
            }
            return $x;
        }
        private function table(array $cols, array $rows, array $o): string {
            $ws = self::split($this->cw, array_map(fn($c) => (float)($c['w'] ?? 1), $cols)); $fs = (float)($o['size'] ?? 8.3);
            $al = fn($c) => ['R' => 'right', 'C' => 'center'][$c['align'] ?? 'L'] ?? 'left';
            $line = '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="DDE4EB"/>';
            $tr = '<w:tr><w:trPr><w:tblHeader/><w:cantSplit/></w:trPr>';
            foreach ($cols as $i => $c) $tr .= $this->cell($this->para($this->run((string)$c['label'], ['bold' => true, 'size' => $fs - 0.5, 'color' => 'FFFFFF']), ['after' => 0, 'align' => $al($c)]), $ws[$i], ['fill' => $this->acc]);
            $tr .= '</w:tr>';
            foreach ($rows as $ri => $r) {
                $st = $r['style'] ?? null; $cells = array_values($r['cells']);
                if ($st === 'group') {
                    $inner = $this->para($this->run((string)($cells[0] ?? ''), ['bold' => true, 'size' => $fs + 0.3, 'color' => $this->acc]) . (isset($cells[1]) && $cells[1] !== '' ? '<w:r><w:tab/></w:r>' . $this->run((string)$cells[1], ['bold' => true, 'size' => $fs + 0.3, 'color' => $this->acc]) : ''), ['after' => 0]);
                    $inner = str_replace('<w:pPr><w:spacing', '<w:pPr><w:tabs><w:tab w:val="right" w:pos="' . ($this->cw - 220) . '"/></w:tabs><w:spacing', $inner);
                    $tr .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>' . $this->cell($inner, $this->cw, ['span' => count($cols), 'fill' => 'E8EEF4']) . '</w:tr>'; continue;
                }
                $bold = $st === 'total'; $color = $st === 'muted' ? '8A97A3' : '2E3A44';
                $fill = $bold ? 'EAF0F6' : ($ri % 2 === 1 ? 'F6F8FA' : null);
                $b = $bold ? '<w:top w:val="single" w:sz="8" w:space="0" w:color="' . $this->acc . '"/>' . $line : $line;
                $tr .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>';
                foreach ($cols as $i => $c) $tr .= $this->cell($this->para($this->run((string)($cells[$i] ?? ''), ['bold' => $bold, 'size' => $fs, 'color' => $color]), ['after' => 0, 'align' => $al($c)]), $ws[$i], ['fill' => $fill, 'borders' => $b]);
                $tr .= '</w:tr>';
            }
            $x = $this->tbl($ws, $tr, ['pad' => 55]);
            if (!$rows) $x .= $this->para($this->run((string)($o['empty'] ?? 'Nothing to show.'), ['size' => $fs, 'color' => '8A97A3']));
            return $x . $this->para('', ['after' => 120]);
        }
        private function bars(array $rows): string {
            $max = 0; foreach ($rows as $r) $max = max($max, (float)$r[1]); $max = $max ?: 1;
            $ws = self::split($this->cw, [32, 51, 17]); $track = $ws[1] - 200; $tr = '';
            foreach ($rows as $r) {
                $fw = (int)max(0, min($track, round((float)$r[1] / $max * $track)));
                $color = ltrim((string)($r[3] ?? $this->acc), '#'); if (!preg_match('/^[0-9A-F]{6}$/i', $color)) $color = ltrim((string)$this->acc, '#');
                $bar = $fw > 0 ? $this->tbl($fw >= $track ? [$track] : [$fw, $track - $fw], '<w:tr><w:trPr><w:trHeight w:val="170" w:hRule="exact"/></w:trPr>' . $this->cell('<w:p><w:pPr><w:spacing w:before="0" w:after="0" w:line="120" w:lineRule="exact"/></w:pPr></w:p>', $fw >= $track ? $track : $fw, ['fill' => $color])
                    . ($fw >= $track ? '' : $this->cell('<w:p><w:pPr><w:spacing w:before="0" w:after="0" w:line="120" w:lineRule="exact"/></w:pPr></w:p>', $track - $fw, ['fill' => 'EEF2F6'])) . '</w:tr>', ['pad' => 0])
                    : $this->para($this->run('—', ['size' => 7, 'color' => '8A97A3']), ['after' => 0]);
                $tr .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>' . $this->cell($this->para($this->run((string)$r[0], ['size' => 8.3]), ['after' => 0]), $ws[0], ['valign' => 'center'])
                    . $this->cell($bar . '<w:p><w:pPr><w:spacing w:before="0" w:after="0" w:line="20" w:lineRule="exact"/></w:pPr></w:p>', $ws[1], ['valign' => 'center'])
                    . $this->cell($this->para($this->run((string)($r[2] ?? ''), ['bold' => true, 'size' => 8.3]), ['after' => 0, 'align' => 'right']), $ws[2], ['valign' => 'center']) . '</w:tr>';
            }
            return $this->tbl($ws, $tr, ['pad' => 40]) . $this->para('', ['after' => 120]);
        }
        private function note(string $t, string $tone): string {
            $fill = $tone === 'warn' ? 'FBF5EA' : 'F3F6F9'; $bar = $tone === 'warn' ? 'C08A30' : $this->acc;
            $inner = ''; foreach (preg_split('/\r?\n/', $t) as $ln) $inner .= $this->para($this->run($ln, ['size' => 9]), ['after' => 40]);
            return $this->tbl([$this->cw], '<w:tr>' . $this->cell($inner, $this->cw, ['fill' => $fill, 'borders' => '<w:left w:val="single" w:sz="24" w:space="0" w:color="' . $bar . '"/>', 'mar' => '<w:left w:w="220" w:type="dxa"/>']) . '</w:tr>', ['pad' => 120]) . $this->para('', ['after' => 120]);
        }
        private function sign(array $who): string {
            $x = ''; $per = min(3, count($who));
            foreach (array_chunk($who, $per) as $row) {
                $ws = self::split($this->cw, array_fill(0, $per, 1)); $cells = '';
                foreach ($ws as $i => $w) {
                    $s = $row[$i] ?? null;
                    $inner = $s ? $this->para('', ['before' => 600, 'after' => 0, 'border' => '2E3A44'])
                        . $this->para($this->run((string)$s[0], ['bold' => true, 'size' => 8.6]), ['before' => 60, 'after' => 20])
                        . $this->para($this->run(trim((string)($s[1] ?? '')) !== '' ? (string)$s[1] : 'Name: ________________________', ['size' => 8.4, 'color' => '4A5A68']), ['after' => 20])
                        . (trim((string)($s[2] ?? '')) !== '' ? $this->para($this->run((string)$s[2], ['size' => 8, 'color' => '6B7A88']), ['after' => 20]) : '')
                        . $this->para($this->run('Date: ________________', ['size' => 8.4, 'color' => '4A5A68']), ['before' => 100, 'after' => 0]) : '';
                    $cells .= $this->cell($inner, $w, ['mar' => '<w:right w:w="360" w:type="dxa"/>']);
                }
                $x .= $this->tbl($ws, '<w:tr><w:trPr><w:cantSplit/></w:trPr>' . $cells . '</w:tr>', ['pad' => 60]);
            }
            return $x;
        }
        private function body(): string {
            $x = $this->cover() . $this->toc(); $first = true;
            foreach ($this->doc->blocks as $b) {
                switch ($b[0]) {
                    case 'h1': $x .= $this->para($this->run($b[1]), ['style' => 'Heading1', 'pagebreak' => $first]); $first = false; break;
                    case 'h2': $x .= $this->para($this->run($b[1]), ['style' => 'Heading2']); break;
                    case 'p': $o = $b[2] ?? []; $x .= $this->para($this->run($b[1], ['bold' => !empty($o['bold']), 'color' => $o['color'] ?? null, 'size' => $o['size'] ?? null])); break;
                    case 'bullets': foreach ($b[1] as $it) $x .= $this->para($this->run($it), ['numId' => 1, 'after' => 60]); $x .= $this->para('', ['after' => 60]); break;
                    case 'kpis': $x .= $this->kpis($b[1]); break;
                    case 'table': $x .= $this->table($b[1], $b[2], $b[3] ?? []); break;
                    case 'bars': $x .= $this->bars($b[1]); break;
                    case 'note': $x .= $this->note($b[1], $b[2] ?? 'info'); break;
                    case 'sign': $x .= $this->sign($b[1]); break;
                    case 'pagebreak': $x .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>'; break;
                }
            }
            if ($first) $x .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
            return $x;
        }
        private function sect(): string {
            $land = !empty($this->doc->meta['landscape']);
            return '<w:sectPr><w:headerReference w:type="default" r:id="rIdH1"/><w:headerReference w:type="first" r:id="rIdH2"/><w:footerReference w:type="default" r:id="rIdF1"/><w:footerReference w:type="first" r:id="rIdF2"/>'
                . ($land ? '<w:pgSz w:w="15840" w:h="12240" w:orient="landscape"/>' : '<w:pgSz w:w="12240" w:h="15840"/>')
                . '<w:pgMar w:top="1300" w:right="1080" w:bottom="1150" w:left="1080" w:header="560" w:footer="520" w:gutter="0"/><w:titlePg/></w:sectPr>';
        }
        private const NS = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"';
        private function header(): string {
            $m = $this->doc->meta; $ws = self::split($this->cw, [40, 60]);
            $left = $this->logo ? $this->para($this->image('rIdLogo', 1.45, 0.32), ['after' => 0]) : $this->para($this->run((string)$m['org'], ['bold' => true, 'size' => 8.5, 'color' => $this->acc]), ['after' => 0]);
            $right = $this->para($this->run(trim((string)($m['header'] ?: $m['title'])), ['size' => 8, 'color' => '6B7A88']), ['after' => 0, 'align' => 'right'])
                . ($m['status'] !== '' ? $this->para($this->run(strtoupper((string)$m['status']), ['bold' => true, 'size' => 6.8, 'color' => 'B5562E']), ['after' => 0, 'align' => 'right']) : '');
            $t = $this->tbl($ws, '<w:tr>' . $this->cell($left, $ws[0], ['valign' => 'bottom']) . $this->cell($right, $ws[1], ['valign' => 'bottom']) . '</w:tr>',
                ['borders' => '<w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="single" w:sz="6" w:space="0" w:color="C7D2DE"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/>', 'pad' => 50]);
            return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:hdr ' . self::NS . '>' . $t . '<w:p><w:pPr><w:spacing w:before="0" w:after="0"/></w:pPr></w:p></w:hdr>';
        }
        private function footer(): string {
            $m = $this->doc->meta; $fld = fn($f) => '<w:r><w:rPr><w:sz w:val="15"/><w:color w:val="6B7A88"/></w:rPr><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:rPr><w:sz w:val="15"/><w:color w:val="6B7A88"/></w:rPr><w:instrText xml:space="preserve"> ' . $f . ' </w:instrText></w:r><w:r><w:rPr><w:sz w:val="15"/><w:color w:val="6B7A88"/></w:rPr><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:rPr><w:sz w:val="15"/><w:color w:val="6B7A88"/></w:rPr><w:t>1</w:t></w:r><w:r><w:rPr><w:sz w:val="15"/><w:color w:val="6B7A88"/></w:rPr><w:fldChar w:fldCharType="end"/></w:r>';
            $p = '<w:p><w:pPr><w:pBdr><w:top w:val="single" w:sz="4" w:space="6" w:color="DDE4EB"/></w:pBdr><w:tabs><w:tab w:val="right" w:pos="' . $this->cw . '"/></w:tabs><w:spacing w:before="0" w:after="0"/></w:pPr>'
                . $this->run(trim((string)$m['footer']), ['size' => 7.5, 'color' => '6B7A88']) . '<w:r><w:tab/></w:r>' . $this->run('Page ', ['size' => 7.5, 'color' => '6B7A88']) . $fld('PAGE') . $this->run(' of ', ['size' => 7.5, 'color' => '6B7A88']) . $fld('NUMPAGES') . '</w:p>';
            return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr ' . self::NS . '>' . $p . '</w:ftr>';
        }
        private function styles(): string {
            $a = $this->acc;
            return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:eastAsia="Arial" w:cs="Arial"/><w:color w:val="2E3A44"/><w:sz w:val="20"/><w:szCs w:val="20"/><w:lang w:val="en-US"/></w:rPr></w:rPrDefault>'
                . '<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
                . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
                . '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:spacing w:before="0" w:after="160" w:line="240" w:lineRule="auto"/></w:pPr><w:rPr><w:b/><w:color w:val="' . $a . '"/><w:sz w:val="60"/><w:szCs w:val="60"/></w:rPr></w:style>'
                . '<w:style w:type="paragraph" w:styleId="Subtitle"><w:name w:val="Subtitle"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:spacing w:after="120"/></w:pPr><w:rPr><w:color w:val="4A5A68"/><w:sz w:val="28"/><w:szCs w:val="28"/></w:rPr></w:style>'
                . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:keepNext/><w:keepLines/><w:pBdr><w:bottom w:val="single" w:sz="12" w:space="4" w:color="' . $a . '"/></w:pBdr><w:spacing w:before="420" w:after="200"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:color w:val="' . $a . '"/><w:sz w:val="32"/><w:szCs w:val="32"/></w:rPr></w:style>'
                . '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:keepNext/><w:keepLines/><w:spacing w:before="260" w:after="100"/><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:b/><w:color w:val="2E3A44"/><w:sz w:val="23"/><w:szCs w:val="23"/></w:rPr></w:style>'
                . '<w:style w:type="paragraph" w:styleId="TOC1"><w:name w:val="toc 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr><w:tabs><w:tab w:val="right" w:leader="dot" w:pos="' . $this->cw . '"/></w:tabs><w:spacing w:after="100"/></w:pPr><w:rPr><w:sz w:val="21"/></w:rPr></w:style>'
                . '<w:style w:type="table" w:default="1" w:styleId="TableNormal"><w:name w:val="Normal Table"/><w:tblPr><w:tblInd w:w="0" w:type="dxa"/><w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:left w:w="108" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/><w:right w:w="108" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>'
                . '</w:styles>';
        }
        public function render(): string {
            $m = $this->doc->meta; $ns = self::NS;
            $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $ns . '><w:body>' . $this->body() . $this->sect() . '</w:body></w:document>';
            $empty = fn($t) => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:' . $t . ' ' . $ns . '><w:p/></w:' . $t . '>';
            $logoRel = $this->logo ? '<Relationship Id="rIdLogo" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/logo.' . $this->logoExt . '"/>' : '';
            $R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';
            $files = [
                '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Default Extension="jpeg" ContentType="image/jpeg"/>'
                    . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/><Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
                    . '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/><Override PartName="/word/header2.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/><Override PartName="/word/footer2.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
                    . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>',
                '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $R . 'officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="' . $R . 'extended-properties" Target="docProps/app.xml"/></Relationships>',
                'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . self::x((string)$m['title']) . '</dc:title><dc:creator>' . self::x((string)($m['author'] ?: $m['org'])) . '</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>',
                'docProps/app.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>' . self::x((string)($m['producer'] ?? 'OpsHub')) . '</Application></Properties>',
                'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdS" Type="' . $R . 'styles" Target="styles.xml"/><Relationship Id="rIdN" Type="' . $R . 'numbering" Target="numbering.xml"/><Relationship Id="rIdSet" Type="' . $R . 'settings" Target="settings.xml"/>'
                    . '<Relationship Id="rIdH1" Type="' . $R . 'header" Target="header1.xml"/><Relationship Id="rIdH2" Type="' . $R . 'header" Target="header2.xml"/><Relationship Id="rIdF1" Type="' . $R . 'footer" Target="footer1.xml"/><Relationship Id="rIdF2" Type="' . $R . 'footer" Target="footer2.xml"/>' . $logoRel . '</Relationships>',
                'word/_rels/header1.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $logoRel . '</Relationships>',
                'word/document.xml' => $doc,
                'word/styles.xml' => $this->styles(),
                'word/settings.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:defaultTabStop w:val="720"/><w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat></w:settings>',
                'word/numbering.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:abstractNum w:abstractNumId="0"><w:multiLevelType w:val="hybridMultilevel"/><w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="bullet"/><w:lvlText w:val="•"/><w:lvlJc w:val="left"/><w:pPr><w:ind w:left="360" w:hanging="260"/></w:pPr><w:rPr><w:color w:val="' . $this->acc . '"/></w:rPr></w:lvl></w:abstractNum><w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num></w:numbering>',
                'word/header1.xml' => $this->header(),
                'word/header2.xml' => $empty('hdr'),
                'word/footer1.xml' => $this->footer(),
                'word/footer2.xml' => $empty('ftr'),
            ];
            if ($this->logo) $files['word/media/logo.' . $this->logoExt] = $m['logo']['data'];
            return opsh_doc_zip($files);
        }
    }

    function opsh_doc_docx(OpshDoc $doc): string { return (new OpshDocxWriter($doc))->render(); }

    /**
     * The organization's document branding: name, logo (Settings → Branding → Documents, else the app logo), footer and
     * accent colour. The logo is read only from this app's own upload folder — a URL is never fetched.
     */
    function opsh_doc_brand(PDO $db): array {
        $s = [];
        try { foreach ($db->query("SELECT skey, sval FROM opsh_settings WHERE skey IN ('doc_org_name','doc_logo_url','doc_footer','doc_accent','app_logo_url','app_name','org_name')") as $r) $s[$r['skey']] = (string)$r['sval']; } catch (Throwable $e) {}
        $org = trim($s['doc_org_name'] ?? '') ?: (trim($s['org_name'] ?? '') ?: (function_exists('opsh_app_name') ? opsh_app_name() : (trim($s['app_name'] ?? '') ?: 'OpsHub')));
        $logo = null; $from = 'none';
        foreach (['doc_logo_url' => 'documents', 'app_logo_url' => 'app'] as $k => $src) {
            $f = opsh_doc_upload_file((string)($s[$k] ?? '')); if ($f === null) continue;
            $logo = opsh_doc_logo_load($f); if ($logo) { $from = $src; break; }
        }
        $acc = strtoupper(ltrim(trim($s['doc_accent'] ?? ''), '#'));
        return ['org' => $org, 'logo' => $logo, 'logo_from' => $from, 'footer' => trim($s['doc_footer'] ?? '') !== '' ? trim($s['doc_footer']) : $org . ' · Confidential — for internal use',
                'accent' => preg_match('/^[0-9A-F]{6}$/', $acc) ? $acc : '1D4D7A'];
    }
    /** The local file behind a logo URL this app stored (…/public/uploads/logos/<name>), or null. */
    function opsh_doc_upload_file(string $url): ?string {
        if ($url === '' || !preg_match('~/public/uploads/logos/([a-z]+_[0-9a-f]{12}\.(?:png|jpg|gif|webp))$~', $url, $m) || !defined('UPLOAD_DIR')) return null;
        $f = rtrim(UPLOAD_DIR, '/') . '/logos/' . $m[1];
        return is_file($f) ? $f : null;
    }

    /** A zip in memory: ZipArchive when present, else a small writer of our own (deflate when zlib is there). */
    function opsh_doc_zip(array $files): string {
        if (class_exists('ZipArchive')) {
            $tmp = tempnam(sys_get_temp_dir(), 'opshdoc'); $z = new ZipArchive();
            if ($tmp && $z->open($tmp, ZipArchive::OVERWRITE) === true) {
                foreach ($files as $n => $c) $z->addFromString($n, $c);
                $z->close(); $out = (string)file_get_contents($tmp); @unlink($tmp);
                if ($out !== '') return $out;
            }
        }
        $data = ''; $cd = ''; $n = 0; $t = getdate(); $dt = (($t['year'] - 1980) << 25) | ($t['mon'] << 21) | ($t['mday'] << 16) | ($t['hours'] << 11) | ($t['minutes'] << 5) | (intdiv($t['seconds'], 2));
        foreach ($files as $name => $c) {
            $crc = crc32($c); $len = strlen($c); $def = function_exists('gzdeflate') ? gzdeflate($c, 6) : null; $m = $def !== null ? 8 : 0; $body = $def ?? $c;
            $h = pack('VvvvVVVVvv', 0x04034b50, 20, 0, $m, $dt, $crc, strlen($body), $len, strlen($name), 0) . $name;
            $cd .= pack('VvvvvVVVVvvvvvVV', 0x02014b50, 20, 20, 0, $m, $dt, $crc, strlen($body), $len, strlen($name), 0, 0, 0, 0, 0, strlen($data)) . $name;
            $data .= $h . $body; $n++;
        }
        return $data . $cd . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($cd), strlen($data), 0);
    }
}
