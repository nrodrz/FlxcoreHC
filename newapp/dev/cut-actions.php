<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
// dev/cut-actions.php (local tool, never shipped; 0001-A004): removes the top-level `if ($action === 'name' …) { … }` blocks
// of a route file, with the comment lines right above each one. PHP's own tokenizer finds the braces, so strings and
// comments that hold braces are no problem.
//   php dev/cut-actions.php api/routes/m365hub.php vtiger-provision-users,systems-for
if ($argc < 3) { fwrite(STDERR, "usage: php dev/cut-actions.php <file> <action,action,…>\n"); exit(2); }
$file = $argv[1]; $want = array_flip(array_filter(explode(',', $argv[2])));
$src = file_get_contents($file); $toks = token_get_all($src);
// byte offset of every token
$off = []; $p = 0; foreach ($toks as $i => $t) { $off[$i] = $p; $p += strlen(is_array($t) ? $t[1] : $t); }
$txt = fn($t) => is_array($t) ? $t[1] : $t;
$cuts = []; $depth = 0; $found = [];
for ($i = 0, $n = count($toks); $i < $n; $i++) {
    $t = $toks[$i]; $s = $txt($t);
    if (!is_array($t) && $s === '{') { $depth++; continue; }
    if (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) { $depth++; continue; }
    if (!is_array($t) && $s === '}') { $depth--; continue; }
    if ($depth !== 0 || !is_array($t) || $t[0] !== T_IF) continue;
    // the condition: from the "(" after if to its matching ")"
    $j = $i + 1; while ($j < $n && $txt($toks[$j]) !== '(') $j++;
    $pd = 0; $k = $j; for (; $k < $n; $k++) { $x = $txt($toks[$k]); if ($x === '(') $pd++; elseif ($x === ')') { $pd--; if ($pd === 0) break; } }
    $cond = ''; for ($q = $j; $q <= $k; $q++) $cond .= $txt($toks[$q]);
    if (!preg_match_all("/\\\$action\s*===\s*'([a-z0-9_-]+)'/", $cond, $m)) continue;
    $names = array_unique($m[1]); $hit = array_values(array_filter($names, fn($a) => isset($want[$a])));
    if (!$hit) continue;
    if (count($hit) !== count($names)) { fwrite(STDERR, "skipped (shares its block with actions to keep): " . implode(',', $names) . "\n"); continue; }
    // the block: the "{" after the condition and its matching "}"
    $b = $k + 1; while ($b < $n && $txt($toks[$b]) !== '{') $b++;
    $bd = 0; $e = $b; for (; $e < $n; $e++) { $x = $toks[$e]; $xs = $txt($x);
        if ($xs === '{' || (is_array($x) && in_array($x[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) $bd++;
        elseif ($xs === '}') { $bd--; if ($bd === 0) break; } }
    $start = $off[$i]; $end = $off[$e] + 1;
    // whole lines: from the start of the if's line (and the comment lines above it) to the end of the closing line
    $ls = strrpos(substr($src, 0, $start), "\n"); $ls = $ls === false ? 0 : $ls + 1;
    while ($ls > 0) { $pl = strrpos(substr($src, 0, $ls - 1), "\n"); $pl = $pl === false ? 0 : $pl + 1; $line = trim(substr($src, $pl, $ls - $pl));
        if ($line !== '' && (str_starts_with($line, '//') || str_starts_with($line, '/*') || str_starts_with($line, '*'))) $ls = $pl; else break; }
    $le = strpos($src, "\n", $end); $rest = $le === false ? substr($src, $end) : substr($src, $end, $le - $end);
    if (trim($rest) === '') $end = $le === false ? strlen($src) : $le + 1;
    $cuts[] = [$ls, $end]; foreach ($hit as $h) $found[$h] = true;
    $i = $e; // continue after the block
}
foreach (array_reverse($cuts) as [$a, $z]) $src = substr($src, 0, $a) . substr($src, $z);
file_put_contents($file, $src);
echo 'removed ' . count($cuts) . ' block(s): ' . implode(', ', array_keys($found)) . "\n";
$miss = array_diff(array_keys($want), array_keys($found)); if ($miss) echo 'not found: ' . implode(', ', $miss) . "\n";
