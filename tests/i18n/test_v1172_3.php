<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * v1.172.3 — reminder thresholds and the Spanish dictionary. Runs WITHOUT a database:  php tests/i18n/test_v1172_3.php
 * R1 ReminderService::thresholds() (default, override, cleaning, legacy key ignored) · R2 label · R3 code/config guards
 * D1 es.php parses and has no key twice inside the v1.172.3 block · D2 {placeholders} identical in key and value
 * D3 every literal t('…') in app/views, app/src, index.php, bootstrap has a Spanish entry · D4 no empty values
 * D5 the reminder sentence is translated with its {days} placeholder.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$ROOT = dirname(__DIR__, 2);
spl_autoload_register(function ($c) use ($ROOT) {
    if (str_starts_with($c, 'FlxCoreHC\\')) { $f = $ROOT . '/app/src/' . str_replace('\\', '/', substr($c, 10)) . '.php'; if (is_file($f)) require $f; }
});
// config() and t() normally come from app/bootstrap.php (which needs a config file and a database): use the same bodies here.
if (!function_exists('config')) { function config(string $key, mixed $default = null): mixed { return $GLOBALS['flxcorehc_config'][$key] ?? $default; } }
if (!function_exists('t')) { function t(string $en, array $vars = []): string { return $en; } }
$fail = false;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };
$with = function (array $cfg): array { $GLOBALS['flxcorehc_config'] = $cfg; return FlxCoreHC\ReminderService::thresholds(); };

// R1
$check($with([]) === [60, 30, 14, 7], 'R1 no setting → the default 60/30/14/7');
$check($with(['reminder_thresholds' => [180, 90, 30]]) === [180, 90, 30], 'R1 reminder_thresholds [180,90,30] is used');
$check($with(['reminder_thresholds' => [7, 30, 30, 14]]) === [30, 14, 7], 'R1 unsorted list with a duplicate → unique, largest first');
$check($with(['reminder_thresholds' => ['45', 10]]) === [45, 10], 'R1 digit strings are accepted');
$check($with(['reminder_thresholds' => ['x', -1, 0, 400, 1.5, null, true, 20]]) === [20], 'R1 garbage (text, negatives, zero, >365, float, null, bool) is dropped; valid values stay');
$check($with(['reminder_thresholds' => ['x', 0]]) === [60, 30, 14, 7], 'R1 nothing usable → default');
$check($with(['reminder_thresholds' => []]) === [60, 30, 14, 7], 'R1 empty list → default');
$check($with(['reminder_thresholds' => 'abc']) === [60, 30, 14, 7], 'R1 not a list → default');
$check($with(['reminder_thresholds' => range(1, 12)]) === [12, 11, 10, 9, 8, 7, 6, 5], 'R1 at most 8 values, the largest ones');
$check($with(['reminder_days' => [180, 90, 30]]) === [60, 30, 14, 7], 'R1 the old unused reminder_days key is ignored (an earlier installer wrote it)');
// R2
$GLOBALS['flxcorehc_config'] = [];
$check(FlxCoreHC\ReminderService::thresholdsLabel() === '60, 30, 14, and 7', 'R2 label "60, 30, 14, and 7"');
$GLOBALS['flxcorehc_config'] = ['reminder_thresholds' => [30]];
$check(FlxCoreHC\ReminderService::thresholdsLabel() === '30', 'R2 label for one value');
$GLOBALS['flxcorehc_config'] = ['reminder_thresholds' => [30, 7]];
$check(FlxCoreHC\ReminderService::thresholdsLabel() === '30 and 7', 'R2 label for two values');
$GLOBALS['flxcorehc_config'] = [];
// R3
$rs = file_get_contents($ROOT . '/app/src/ReminderService.php');
$check(substr_count($rs, 'self::THRESHOLDS') === 2 && substr_count($rs, 'self::thresholds()') >= 4, 'R3 scans use thresholds() (the constant is only the default)');
$check(!str_contains(file_get_contents($ROOT . '/app/config.sample.php'), "'reminder_days'") && str_contains(file_get_contents($ROOT . '/app/config.sample.php'), 'reminder_thresholds'), 'R3 config.sample documents reminder_thresholds, not reminder_days');
$inst = file_get_contents($ROOT . '/install.php');
$check(str_contains($inst, "'reminder_thresholds' => [60, 30, 14, 7]") && !str_contains($inst, "'reminder_days'"), 'R3 installer writes reminder_thresholds 60/30/14/7');
$check(!str_contains(file_get_contents($ROOT . '/app/views/compliance/index.php'), 'at 60, 30, 14, and 7 days'), 'R3 the Compliance screen does not hard-code the days');

// D: parse es.php with the tokenizer (keys can span lines and hold quotes)
$lit = fn(string $s): string => str_replace(["\\\\", "\\'"], ["\\", "'"], substr($s, 1, -1));
$tok = token_get_all(file_get_contents($ROOT . '/app/lang/es.php')); $n = count($tok);
$pairs = []; $blockStart = null;
for ($i = 0; $i < $n; $i++) {
    if (is_array($tok[$i]) && $tok[$i][0] === T_COMMENT && str_contains($tok[$i][1], 'v1.172.3') && $blockStart === null) $blockStart = $tok[$i][2];
    if (is_array($tok[$i]) && $tok[$i][0] === T_CONSTANT_ENCAPSED_STRING) {
        $j = $i + 1; while ($j < $n && is_array($tok[$j]) && $tok[$j][0] === T_WHITESPACE) $j++;
        if ($j < $n && is_array($tok[$j]) && $tok[$j][0] === T_DOUBLE_ARROW) {
            $j++; while ($j < $n && is_array($tok[$j]) && $tok[$j][0] === T_WHITESPACE) $j++;
            if (is_array($tok[$j]) && $tok[$j][0] === T_CONSTANT_ENCAPSED_STRING) $pairs[] = [$lit($tok[$i][1]), $lit($tok[$j][1]), $tok[$i][2]];
        }
    }
}
$es = require $ROOT . '/app/lang/es.php';
$check(is_array($es) && count($pairs) > 2000 && $blockStart !== null, 'D1 es.php parses (' . count($pairs) . ' entries) and has the v1.172.3 block');
$seen = []; $dupInBlock = [];
foreach ($pairs as [$k, $v, $line]) { if (isset($seen[$k]) && $line >= $blockStart) $dupInBlock[] = $k; $seen[$k] = true; }
$check($dupInBlock === [], 'D1 no key of the v1.172.3 block appears twice in the file' . ($dupInBlock ? ' — e.g. ' . substr($dupInBlock[0], 0, 50) : ''));
$badPh = []; $empty = [];
foreach ($es as $k => $v) {
    $a = []; $b = [];
    preg_match_all('/\{\w+\}/', (string) $k, $a); preg_match_all('/\{\w+\}/', (string) $v, $b);
    sort($a[0]); sort($b[0]);
    if ($a[0] !== $b[0]) $badPh[] = $k;
    if (trim((string) $v) === '') $empty[] = $k;
}
$check($badPh === [], 'D2 every {placeholder} of an English key is kept in its Spanish value' . ($badPh ? ' — e.g. ' . substr($badPh[0], 0, 50) : ''));
$check($empty === [], 'D4 no empty Spanish value');
// D3 literal t('…') calls
$files = array_merge(glob($ROOT . '/app/views/*.php'), glob($ROOT . '/app/views/*/*.php'), glob($ROOT . '/app/views/*/*/*.php'), glob($ROOT . '/app/src/*.php'), glob($ROOT . '/app/src/*/*.php'), [$ROOT . '/index.php', $ROOT . '/app/bootstrap.php']);
$missing = [];
foreach ($files as $f) {
    if (preg_match_all("/(?<![A-Za-z_>:])t\(\s*'((?:[^'\\\\]|\\\\.)*)'/s", (string) file_get_contents($f), $m)) {
        foreach ($m[1] as $x) { $x = str_replace("\\'", "'", $x); if (!isset($es[$x])) $missing[$x] = basename($f); }
    }
}
$check($missing === [], 'D3 every literal t(\'…\') has a Spanish entry (' . count($missing) . ' missing)' . ($missing ? ' — e.g. ' . substr((string) array_key_first($missing), 0, 60) . ' in ' . reset($missing) : ''));
// D5
$k5 = 'Providers are emailed automatically at {days} days before a credential expires. Configure delivery under';
$check(isset($es[$k5]) && str_contains($es[$k5], '{days}'), 'D5 the reminder sentence is translated and keeps {days}');
$check(($es['and'] ?? '') === 'y', 'D5 "and" → "y" (used in the thresholds list)');
exit($fail ? 1 : 0);
