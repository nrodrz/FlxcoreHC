<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * v1.172.17 — Spanish screens: frame of the Help Center, the audit package, joined messages, month/weekday names.
 * Runs WITHOUT a database:  php tests/i18n/test_v1172_17.php
 * L1 translateHtml() leaves a <!--flx-notranslate--> region alone and translates the rest of the page
 * L2 the Help Center no longer opts out of translation, and its body is a no-translate region
 * L3 the audit package is translated (it is printed without view())
 * L4 every message rewritten with placeholders has a Spanish entry that keeps all of its placeholders
 * L5 month and weekday names, and the platform/organization time-zone labels, are translatable
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$ROOT = dirname(__DIR__, 2);
$fail = false;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };
$rd = fn(string $f) => (string) file_get_contents("$ROOT/$f");
$es = require "$ROOT/app/lang/es.php";

// L1 — the translator, with the Spanish catalog and no database (a failing DB lookup falls back to the file catalog)
$i18nSrc = $rd('app/src/I18n.php');
$check(str_contains($i18nSrc, '<!--flx-notranslate-->'), 'L1 translateHtml() knows the no-translate region marker');
$check(preg_match('/flx-notranslate-->\.\*\?<!--\/flx-notranslate-->\|<script/', $i18nSrc) === 1, 'L1 the region is split out together with script/style (never touched)');

// L2
$idx = $rd('index.php'); $help = $rd('app/views/help/index.php');
$check(!preg_match("/view\('help\/index'.*skipOutputTranslate/", $idx), 'L2 the Help route no longer skips the translator');
$check(substr_count($help, 'flx-notranslate') === 2 && strpos($help, '<!--flx-notranslate-->') < strpos($help, '<div class="wrap">'), 'L2 the Help body sits inside a no-translate region (topics are per-language files)');
foreach (['Sign out', 'Open menu', 'Toggle theme', 'Dark mode', 'Your profile'] as $k) $check(isset($es[$k]), "L2 Spanish entry for the shell text \"$k\"");

// L3
$check(preg_match('/ob_start\(\);\s*require FLXCOREHC_APP \. \'\/views\/reports\/audit_package\.php\';\s*echo FlxCoreHC\\\\I18n::translateHtml/', $idx) === 1, 'L3 the audit package output goes through translateHtml()');

// L4 placeholders kept
$keys = ['Full access (all {n} permissions)', '{n} of {m} permissions', '{n} of {m} users with 2FA', 'Recommended next steps ({n})', '{n} entries recorded',
    '{n} user(s) have not enrolled in two-factor authentication', 'Generated {when}', '{n} credential types tracked', 'Active ({n})', 'Expiring ({n})', 'Expired ({n})',
    'Choose an icon for {name}', '1 user', '{n} users', '{n} defined', '{seats} seats · {providers} providers', '{n}/{m} done', '{n}% overall', 'Credentials ({n})',
    'Set up your {groups}', '{n} gaps', '1 gap', '{n} missing', '{n} missing, {e} expiring', '{a}/{t} active · score'];
foreach ($keys as $k) {
    preg_match_all('/\{\w+\}/', $k, $a); preg_match_all('/\{\w+\}/', (string) ($es[$k] ?? ''), $b);
    sort($a[0]); sort($b[0]);
    $check(isset($es[$k]) && $es[$k] !== $k && $a[0] === $b[0], "L4 \"$k\" translated, placeholders kept");
}
foreach ($keys as $k) {   // the literal must really be used by the code (a key nobody calls would hide a regression)
    $found = false;
    foreach (['index.php', 'app/views', 'app/src'] as $p) {
        $it = is_dir("$ROOT/$p") ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$ROOT/$p", FilesystemIterator::SKIP_DOTS)) : [new SplFileInfo("$ROOT/$p")];
        foreach ($it as $f) if ($f->getExtension() === 'php' && str_contains((string) file_get_contents($f->getPathname()), "'" . str_replace("'", "\\'", $k) . "'")) { $found = true; break 2; }
    }
    $check($found, "L4 the code calls t() with \"$k\"");
}

// L5
foreach (['January','February','March','April','May','June','July','August','September','October','November','December'] as $m) $check(isset($es[$m]), "L5 month $m");
foreach (['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $m) $check(isset($es[$m]), "L5 short month $m");
foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d) $check(isset($es[$d]), "L5 weekday $d");
$check(str_contains($rd('app/views/calendar/index.php'), "t(\$ms->format('F'))"), 'L5 the calendar title translates the month name');
foreach (array_keys(['Eastern (EST/EDT)' => 1, 'Central' => 1, 'Mountain' => 1, 'Pacific' => 1, 'Hawaii' => 1, 'Atlantic — Puerto Rico (AST)' => 1]) as $z) $check(isset($es[$z]), "L5 time-zone label \"$z\"");
echo $fail ? "I18N 1.172.17: FAIL\n" : "I18N 1.172.17: ALL PASS\n";
exit($fail ? 1 : 0);
