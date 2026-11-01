<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);
/**
 * Puts the ownership header (Ownership::fileHeader()) in the first comment of every program file.
 *   php tools/ownership_headers.php --check   list files without it (exit 1 when any); no file is changed
 *   php tools/ownership_headers.php           add it where missing (safe to run again)
 * Placement: PHP right after the opening tag (a view that starts with HTML gets a one-line PHP comment first, so the output
 * does not change); JS and CSS in a block comment; Python and shell with # after the shebang / coding line; SQL with --.
 * Not touched: .htaccess, documents, data files, third-party files (fonts, vendor).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once "$root/app/src/Ownership.php";
$H = FlxCoreHC\Ownership::fileHeader();
$check = in_array('--check', $argv, true);
$skipDirs = ['.git', 'validation', 'releases', 'uploads', 'node_modules', '__pycache__', 'vendor'];
$marker = 'Proprietary — licensed, not sold';

$add = function (string $ext, string $src) use ($H): string {
    switch ($ext) {
        case 'php':
            if (preg_match('/^(<\?php)(?=[\s])/', $src)) return preg_replace('/^<\?php/', '<?php /* ' . $H . ' */', $src, 1);
            if (str_starts_with($src, '#!')) {   // shebang first (CLI scripts): keep it, header goes after the opening tag
                $nl = strpos($src, "\n"); $line = substr($src, 0, $nl + 1); $rest = substr($src, $nl + 1);
                return $line . preg_replace('/^<\?php/', '<?php /* ' . $H . ' */', $rest, 1);
            }
            return '<?php /* ' . $H . " */ ?>\n" . $src;   /* a template that starts with HTML: PHP swallows the newline after the closing tag, so the output is unchanged */
        case 'js': case 'css':
            return '/* ' . $H . " */\n" . $src;
        case 'sql':
            return '-- ' . $H . "\n" . $src;
        case 'py': case 'sh':
            $lines = explode("\n", $src, 3);
            $keep = '';
            if (isset($lines[0]) && str_starts_with($lines[0], '#!')) { $keep .= array_shift($lines) . "\n"; }
            if (isset($lines[0]) && preg_match('/^#.*coding[:=]/', $lines[0])) { $keep .= array_shift($lines) . "\n"; }
            return $keep . '# ' . $H . "\n" . implode("\n", $lines);
    }
    return $src;
};

$missing = []; $changed = 0; $seen = 0;
$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    fn($f) => !in_array($f->getFilename(), $skipDirs, true)));
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $ext = strtolower($f->getExtension());
    if (!in_array($ext, ['php', 'js', 'css', 'sql', 'py', 'sh'], true)) continue;
    $path = $f->getPathname(); $rel = substr($path, strlen($root) + 1);
    if (str_starts_with($rel, 'assets/fonts/')) continue;   // third-party font CSS generated from Google Fonts
    $seen++;
    $src = (string) file_get_contents($path);
    if (str_contains(substr($src, 0, 600), $marker)) continue;
    $missing[] = $rel;
    if ($check) continue;
    $new = $add($ext, $src);
    if ($new !== $src) { file_put_contents($path, $new); $changed++; }
}
if ($check) {
    foreach ($missing as $m) echo "FAIL — no ownership header: $m\n";
    echo $missing ? 'FAIL' : 'PASS', " — ownership header in $seen program files" . ($missing ? ' (' . count($missing) . ' without)' : '') . "\n";
    exit($missing ? 1 : 0);
}
echo "added the header to $changed of $seen program files\n";
