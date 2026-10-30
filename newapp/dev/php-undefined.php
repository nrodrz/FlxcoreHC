<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
// dev/php-undefined.php (local tool, never shipped; 0001-A004): every call of a global function that is defined nowhere —
// not in the app's PHP and not in PHP itself. Run it after removing code; it should print nothing.
//   php dev/php-undefined.php
$root = realpath(__DIR__ . '/..');
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    fn($f) => !($f->isDir() && in_array($f->getFilename(), ['vendor', 'dev', 'node_modules', '.git', 'docs'], true))));
foreach ($it as $f) if ($f->isFile() && substr($f->getFilename(), -4) === '.php') $files[] = $f->getPathname();
$defined = []; $calls = [];
$skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
foreach ($files as $file) {
    $t = token_get_all(file_get_contents($file)); $n = count($t);
    $sig = []; foreach ($t as $i => $x) if (!(is_array($x) && in_array($x[0], $skip, true))) $sig[] = $x;
    $m = count($sig);
    for ($i = 0; $i < $m; $i++) {
        $x = $sig[$i]; if (!is_array($x) || $x[0] !== T_STRING) continue;
        $prev = $sig[$i - 1] ?? null; $next = $sig[$i + 1] ?? null;
        $pv = is_array($prev) ? $prev[0] : $prev;
        if ($pv === T_FUNCTION) { $defined[strtolower($x[1])] = true; continue; }
        if ($next !== '(') continue;
        if (in_array($pv, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_NULLSAFE_OBJECT_OPERATOR, T_CONST], true)) continue;
        $calls[strtolower($x[1])][] = substr($file, strlen($root) + 1) . ':' . $x[2];
    }
}
$bad = 0;
foreach ($calls as $fn => $where) {
    if (isset($defined[$fn]) || function_exists($fn) || in_array($fn, ['isset', 'empty', 'list', 'array', 'exit', 'die', 'eval', 'unset', 'echo', 'print', 'fn', 'match', 'declare'], true)) continue;
    $bad++; echo $fn . '  ← ' . implode(', ', array_slice($where, 0, 4)) . (count($where) > 4 ? ' …' : '') . "\n";
}
echo $bad ? "$bad undefined function(s)\n" : '';
