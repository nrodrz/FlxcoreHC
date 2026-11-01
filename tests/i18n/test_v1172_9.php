<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * v1.172.9 — Spanish gaps and wording. Runs WITHOUT a database:  php tests/i18n/test_v1172_9.php
 * N1 notification texts are re-rendered per language (14 templates), English unchanged
 * C1 auto-generated calendar texts are translated for display; the user's own events never are
 * T1 translateHtml: long paragraphs translate on exact match (also when wrapped over source lines), and long
 *    unknown paragraphs are never written to the "missing strings" log (they may be a user's note)
 * W1 the document-request hint/help no longer claim nothing is e-mailed
 * V1 the New-event modal is built from the configured event types
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$ROOT = dirname(__DIR__, 2);
spl_autoload_register(function ($c) use ($ROOT) {
    if (str_starts_with($c, 'FlxCoreHC\\')) { $f = $ROOT . '/app/src/' . str_replace('\\', '/', substr($c, 10)) . '.php'; if (is_file($f)) require $f; }
});
if (!function_exists('config')) { function config(string $key, mixed $default = null): mixed { return $default; } }
if (!function_exists('t')) { function t(string $en, array $vars = []): string { return \FlxCoreHC\I18n::t($en, $vars); } }
if (!function_exists('fmt_day')) { function fmt_day(?string $d): string { return (string) $d; } }
define('FLXCOREHC_APP', $ROOT . '/app');
$_SESSION = [];
$fail = false;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };
$setLoc = function (string $l) { $_SESSION['locale'] = $l; $r = new ReflectionProperty(FlxCoreHC\I18n::class, 'active'); $r->setAccessible(true); $r->setValue(null, $l); };

$samples = [   // exactly what NotificationService::sweep writes (see the drift guard below)
    ['Malpractice Policy has expired — Luis Colon',                         ['Luis Colon']],
    ['DEA Registration expires in 27 day(s) — Maria Santos',                ['27', 'Maria Santos']],
    ['Expired 21 day(s) ago. Renew and upload the new document.',           ['21']],
    ['Due 11/02/2026. Start the renewal now.',                              ['11/02/2026']],
    ['Was due 09/22/2026.',                                                 ['09/22/2026']],
    ['Overdue: Annual credentialing review',                                ['Annual credentialing review']],
    ['Re-verify CLIA-Certificate.pdf (CLIA Certificate)',                   ['CLIA-Certificate.pdf']],
    ['Verification is due to be repeated by 2026-12-01.',                   ['2026-12-01']],
    ['Exclusion screening flag — OIG_LEIE',                                 ['OIG_LEIE']],
    ['Result "potential_match" on 2026-10-01. Review before any billing.',  ['potential_match', '2026-10-01']],
    ['Ana Rivera was assigned to you',                                      ['Ana Rivera']],
    ['Note: please review first',                                           ['please review first']],
    ['Credential import finished: 12 imported, 3 skipped',                  ['12', '3']],
    ['Credential import finished: 12 imported',                             ['12']],
];
$setLoc('en');
$same = true; foreach ($samples as [$s]) { if (FlxCoreHC\NotificationService::localize($s) !== $s) { $same = false; echo "   en changed: $s\n"; } }
$check($same, 'N1 English: all 14 notification templates come back unchanged');
$setLoc('es');
$allEs = true; foreach ($samples as [$s, $vals]) {
    $o = FlxCoreHC\NotificationService::localize($s);
    $ok = $o !== $s && !str_contains($o, '{') && array_reduce($vals, fn($c, $v) => $c && str_contains($o, $v), true);
    if (!$ok) { $allEs = false; echo "   es not translated / value lost: $s  =>  $o\n"; }
}
$check($allEs, 'N1 Spanish: each template is translated, keeps its values and leaves no {placeholder}');
$check(FlxCoreHC\NotificationService::localize('Something we never wrote') === 'Something we never wrote', 'N1 unknown text passes through unchanged');
$src = (string) file_get_contents($ROOT . '/app/src/NotificationService.php');
$drift = true; foreach (['has expired — {$who}', 'day(s) — {$who}', 'Start the renewal now.', "'Was due '", 'Overdue: {$e', 'Re-verify {$d', 'Verification is due to be repeated by', 'Exclusion screening flag — ', 'Review before any billing.'] as $frag) { if (!str_contains($src, $frag)) { $drift = false; echo "   drift: '$frag' no longer in the sweep\n"; } }
$check($drift, 'N1 drift guard: the sweep still writes the texts the localizer expects');

// C1
$auto = ['source' => 'credential', 'title' => 'Malpractice Policy expires — Maria Santos', 'detail' => 'Credential renewal deadline.'];
$setLoc('en');  $en = FlxCoreHC\Repository\CalendarRepository::localizeAuto($auto);
$check($en['title'] === $auto['title'] && $en['detail'] === $auto['detail'], 'C1 English: auto event unchanged');
$setLoc('es');
$es = FlxCoreHC\Repository\CalendarRepository::localizeAuto($auto);
$check(str_contains($es['title'], 'vence') && str_contains($es['title'], 'Maria Santos') && $es['detail'] !== $auto['detail'], 'C1 Spanish: auto deadline title and detail translated');
foreach (['Recredentialing — MCS — Ana Rivera' => 'Recredencialización', 'Re-verify CLIA Certificate — Ana Rivera' => 'Re-verificar', 'OCS / SICRO attestation — Sonia Diaz' => 'Atestación', 'Medicaid PEP revalidation — Rosa Mendez' => 'Revalidación'] as $title => $word) {
    $o = FlxCoreHC\Repository\CalendarRepository::localizeAuto(['source' => 'payer', 'title' => $title, 'detail' => null])['title'];
    $check(str_contains($o, $word) && str_contains($o, ' — '), "C1 Spanish: \"$title\" → \"$o\"");
}
$mine = ['source' => 'manual', 'title' => 'Passport expires — renew', 'detail' => 'Credential renewal deadline.'];
$check(FlxCoreHC\Repository\CalendarRepository::localizeAuto($mine) === $mine, 'C1 a manual event is never rewritten, even if it looks like an auto title');

// T1
$setLoc('es');
$es = require $ROOT . '/app/lang/es.php';
$long = ''; foreach (array_keys($es) as $k) { if (mb_strlen($k) > 300 && !str_contains($k, '{') && !str_contains($k, '<') && !str_contains($k, '&') && !str_contains($k, '"') && !str_contains($k, "'")) { $long = $k; break; } }
$check($long !== '' && mb_strlen($long) > 200, 'T1 the dictionary has a >200-character entry to test with');
$wrapped = '<p>' . wordwrap($long, 70, "\n            ") . '</p>';
$out = FlxCoreHC\I18n::translateHtml($wrapped);
$check($long !== '' && !str_contains($out, substr($long, 0, 40)) && str_contains($out, substr($es[$long], 0, 30)), 'T1 a long paragraph wrapped over source lines is translated');
$miss = new ReflectionProperty(FlxCoreHC\I18n::class, 'missing'); $miss->setAccessible(true); $miss->setValue(null, []);
$note = str_repeat('Patient called about the renewal and asked for the packet to be sent again next week. ', 4);
FlxCoreHC\I18n::translateHtml('<p>' . $note . '</p>');
$check($miss->getValue() === [], 'T1 a long unknown English paragraph is NOT written to the missing-strings log');
FlxCoreHC\I18n::translateHtml('<p>Short unknown interface text here</p>');
$check(count($miss->getValue()) === 1, 'T1 a short unknown UI string still IS logged (so the editor can translate it)');

// W1
$all = ''; foreach (['/app/views/holders/show.php', '/app/help/en.php', '/app/help/es.php'] as $f) $all .= file_get_contents($ROOT . $f);
$check(!preg_match('/nothing is e-?mailed|nada se env[ií]a por correo/i', $all), 'W1 no screen or help text says nothing is e-mailed');
$check(str_contains($all, 'never carries documents') || str_contains($all, 'nunca lleva documentos'), 'W1 the hint says what is e-mailed (a link only)');

// V1
$view = (string) file_get_contents($ROOT . '/app/views/calendar/index.php');
$check(!str_contains($view, '<option value="task">') && str_contains($view, "foreach (\$types ?? [])") === false && str_contains($view, "foreach ((\$types ?? []) as \$t)"), 'V1 the New-event type select is generated from the configured types');

echo $fail ? "\nFAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
