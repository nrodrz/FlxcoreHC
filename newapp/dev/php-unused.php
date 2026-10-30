<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
// dev/php-unused.php (local tool, never shipped; 0001-A004): the global functions and classes of the PHP code that nothing
// refers to, and the library files left with nothing in them.
//   php dev/php-unused.php            → list
//   php dev/php-unused.php --remove   → remove the unused definitions (repeats until none is left), empty function_exists
//                                       guards, and list the library files that hold nothing any more
// A name counts as used when it appears anywhere outside its own definition: as code, or inside any string (callbacks,
// dynamic names), except the string of its own function_exists('…') guard. It errs on keeping. Class methods are not
// looked at (a class is used when its name appears).
$root = realpath(__DIR__ . '/..');
$remove = in_array('--remove', $argv, true);
function php_files(string $root): array {
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        fn($f) => !($f->isDir() && in_array($f->getFilename(), ['vendor', 'dev', 'node_modules', '.git', 'docs'], true))));
    foreach ($it as $f) if ($f->isFile() && substr($f->getFilename(), -4) === '.php') $out[] = $f->getPathname();
    sort($out); return $out;
}
function analyse(string $file): array {
    $src = file_get_contents($file); $toks = token_get_all($src);
    $off = []; $p = 0; foreach ($toks as $i => $t) { $off[$i] = $p; $p += strlen(is_array($t) ? $t[1] : $t); }
    $txt = fn($t) => is_array($t) ? $t[1] : $t;
    $defs = []; $refs = []; $n = count($toks);
    $depth = 0; $classDepth = []; // brace depth at which a class body opens
    $next = function (int $i) use ($toks, $n) { for ($j = $i + 1; $j < $n; $j++) { $t = $toks[$j]; if (!is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) return $j; } return $n; };
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i]; $s = $txt($t);
        if ($s === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; continue; }
        if ($s === '}') { $depth--; if ($classDepth && end($classDepth) === $depth) array_pop($classDepth); continue; }
        if (!is_array($t)) continue;
        if (in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
            $j = $next($i); if ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_STRING) {
                $name = $toks[$j][1];
                $b = $j; while ($b < $n && $txt($toks[$b]) !== '{') $b++;
                $e = $b; $d = 0; for (; $e < $n; $e++) { $x = $txt($toks[$e]); if ($x === '{' || (is_array($toks[$e]) && in_array($toks[$e][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) $d++; elseif ($x === '}') { $d--; if ($d === 0) break; } }
                // the modifiers in front (final, abstract, readonly) belong to it
                $st = $i; for ($k = $i - 1; $k >= 0; $k--) { $x = $toks[$k]; if (is_array($x) && $x[0] === T_WHITESPACE) continue; if (is_array($x) && in_array($x[0], [T_FINAL, T_ABSTRACT, defined('T_READONLY') ? T_READONLY : -1], true)) { $st = $k; continue; } break; }
                $defs[] = ['kind' => 'class', 'name' => $name, 'start' => $off[$st], 'end' => $off[$e] + 1, 'tok' => $j];
                $classDepth[] = $depth; // the class body opens at this depth
            }
            continue;
        }
        if ($t[0] === T_FUNCTION && !$classDepth) {
            $j = $next($i);
            if ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_STRING) {   // a named (global) function, not a closure
                $b = $j; while ($b < $n && $txt($toks[$b]) !== '{' && $txt($toks[$b]) !== ';') $b++;
                if ($txt($toks[$b]) === '{') {
                    $e = $b; $d = 0; for (; $e < $n; $e++) { $x = $txt($toks[$e]); if ($x === '{' || (is_array($toks[$e]) && in_array($toks[$e][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) $d++; elseif ($x === '}') { $d--; if ($d === 0) break; } }
                    // the doc comment right above belongs to it
                    $st = $off[$i]; $k = $i - 1; while ($k >= 0 && is_array($toks[$k]) && $toks[$k][0] === T_WHITESPACE) $k--;
                    if ($k >= 0 && is_array($toks[$k]) && in_array($toks[$k][0], [T_DOC_COMMENT, T_COMMENT], true) && substr_count($txt($toks[$k + 1] ?? ''), "\n") <= 1) $st = $off[$k];
                    $defs[] = ['kind' => 'function', 'name' => $toks[$j][1], 'start' => $st, 'end' => $off[$e] + 1, 'tok' => $j];
                }
            }
            continue;
        }
    }
    // references
    $defTok = array_flip(array_column($defs, 'tok'));
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i]; if (!is_array($t)) continue;
        if ($t[0] === T_STRING || $t[0] === T_NAME_QUALIFIED || $t[0] === T_NAME_FULLY_QUALIFIED) {
            if (isset($defTok[$i])) continue;
            if (strtolower($t[1]) === 'function_exists' || strtolower($t[1]) === 'class_exists') {   // skip the guard's own string
                $j = $next($i); if ($j < $n && $txt($toks[$j]) === '(') { $k = $next($j); if ($k < $n && is_array($toks[$k]) && $toks[$k][0] === T_CONSTANT_ENCAPSED_STRING) $i = $k; }
                continue;
            }
            $refs[] = [ltrim($t[1], '\\'), $off[$i]];
        } elseif ($t[0] === T_CONSTANT_ENCAPSED_STRING || $t[0] === T_ENCAPSED_AND_WHITESPACE || $t[0] === T_INLINE_HTML) {
            if (preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $t[1], $m)) foreach ($m[0] as $w) $refs[] = [$w, $off[$i]];
        }
    }
    return ['src' => $src, 'defs' => $defs, 'refs' => $refs];
}
function unused_defs(array $A): array {
    // name → total references, and per (file, def) the references inside the definition itself
    $count = [];
    foreach ($A as $f => $a) foreach ($a['refs'] as [$w, $o]) $count[strtolower($w)] = ($count[strtolower($w)] ?? 0) + 1;
    $out = [];
    foreach ($A as $f => $a) foreach ($a['defs'] as $d) {
        $k = strtolower($d['name']); $inside = 0;
        foreach ($a['refs'] as [$w, $o]) if (strtolower($w) === $k && $o >= $d['start'] && $o < $d['end']) $inside++;
        if (($count[$k] ?? 0) - $inside <= 0) $out[] = ['file' => $f] + $d;
    }
    return $out;
}
$files = php_files($root);
$round = 0; $removedTotal = [];
do {
    $A = []; foreach ($files as $f) if (is_file($f)) $A[$f] = analyse($f);
    $dead = unused_defs($A);
    if (!$remove) {
        foreach ($dead as $d) printf("%-9s %-48s %s\n", $d['kind'], $d['name'], substr($d['file'], strlen($root) + 1));
        echo count($dead) . " unused definitions\n"; break;
    }
    if (!$dead) break;
    $round++; $byFile = [];
    foreach ($dead as $d) $byFile[$d['file']][] = $d;
    foreach ($byFile as $f => $ds) {
        $src = $A[$f]['src']; usort($ds, fn($x, $y) => $y['start'] <=> $x['start']);
        foreach ($ds as $d) {
            $s = $d['start']; $e = $d['end'];
            $ls = strrpos(substr($src, 0, $s), "\n"); $ls = $ls === false ? 0 : $ls + 1; if (trim(substr($src, $ls, $s - $ls)) === '') $s = $ls;
            $le = strpos($src, "\n", $e); if ($le !== false && trim(substr($src, $e, $le - $e)) === '') $e = $le + 1;
            $src = substr($src, 0, $s) . substr($src, $e);
            $removedTotal[] = $d['name'] . ' (' . substr($f, strlen($root) + 1) . ')';
        }
        // guards left empty
        $src = preg_replace("/^[ \t]*if \(!(function|class)_exists\('[A-Za-z0-9_]+'\)\) \{\s*\}[ \t]*\n/m", '', $src);
        file_put_contents($f, $src);
    }
    echo "round $round: removed " . count($dead) . "\n";
} while (true);
if ($remove) {
    echo 'removed ' . count($removedTotal) . " definitions\n"; foreach ($removedTotal as $r) echo "  $r\n";
    // library files that hold nothing any more (only the opening tag, comments, guards and requires)
    foreach ($files as $f) {
        if (strpos($f, '/api/lib/') === false || !is_file($f)) continue;
        $code = ''; foreach (token_get_all(file_get_contents($f)) as $t) { if (is_array($t) && in_array($t[0], [T_OPEN_TAG, T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) continue; $code .= is_array($t) ? $t[1] : $t; }
        $code = preg_replace('/(require|include)(_once)?\s*[^;]+;/', '', $code);
        if (trim($code) === '') echo 'EMPTY ' . substr($f, strlen($root) + 1) . "\n";
    }
}
