<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * v1.172.14 — daylight-saving edge cases and per-item overdue marking. Runs WITHOUT a database:  php tests/dates/test_v1172_14.php
 * D1 local_time_status(): a time skipped when clocks move forward is 'gap'; a time that happens twice when they move back is 'ambiguous'
 * D2 local_to_utc_keep(): saving an event without changing its time keeps the stored instant (the second 01:30 stays the second)
 * D3 create / edit / drag-move refuse a 'gap' time instead of silently storing a different one
 * D4 the month grid marks overdue on the item (class "ov"), not the whole day
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$ROOT = dirname(__DIR__, 2);
$fail = false;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };
$GLOBALS['__tz'] = 'America/New_York';
function app_tz(): DateTimeZone { return new DateTimeZone($GLOBALS['__tz']); }
function config(string $k, mixed $d = null): mixed { return $d; }
$boot = file_get_contents($ROOT . '/app/bootstrap.php');
foreach (['fmt_dt', 'local_to_utc', 'local_time_status', 'local_to_utc_keep'] as $fn) {
    preg_match('/\nfunction ' . $fn . '\(.*?\n}\n/s', $boot, $m);
    $check(($m[0] ?? '') !== '', "helper $fn() exists in bootstrap.php");
    eval($m[0] ?? '');
}
$st = fn(string $tz, string $t): string => (function () use ($tz, $t) { $GLOBALS['__tz'] = $tz; return local_time_status($t); })();
$check($st('America/New_York', '2026-03-08 02:30:00') === 'gap', 'D1 New York 03/08/2026 02:30 does not exist (spring forward)');
$check($st('America/New_York', '2026-03-08T02:30') === 'gap', 'D1 the datetime-local form (T, no seconds) is understood');
$check($st('America/New_York', '2026-03-08 03:30:00') === 'ok', 'D1 New York 03:30 the same morning is fine');
$check($st('America/New_York', '2026-03-08 01:59:00') === 'ok', 'D1 New York 01:59 just before the jump is fine');
$check($st('America/New_York', '2026-11-01 01:30:00') === 'ambiguous', 'D1 New York 11/01/2026 01:30 happens twice (fall back)');
$check($st('America/New_York', '2026-11-01 00:30:00') === 'ok', 'D1 New York 00:30 that night is unambiguous');
$check($st('America/New_York', '2026-11-01 02:30:00') === 'ok', 'D1 New York 02:30 that night is unambiguous');
$check($st('America/Chicago', '2027-03-14 02:15:00') === 'gap', 'D1 Chicago 03/14/2027 02:15 does not exist');
$check($st('America/Los_Angeles', '2026-11-01 01:30:00') === 'ambiguous', 'D1 Los Angeles 01:30 happens twice');
$check($st('Pacific/Honolulu', '2026-03-08 02:30:00') === 'ok', 'D1 Honolulu has no daylight saving: 02:30 is fine');
$check($st('Pacific/Honolulu', '2026-11-01 01:30:00') === 'ok', 'D1 Honolulu 01:30 is not ambiguous');
$check($st('America/Puerto_Rico', '2026-03-08 02:30:00') === 'ok', 'D1 Puerto Rico has no daylight saving');
$check($st('UTC', '2026-03-08 02:30:00') === 'ok', 'D1 UTC is always fine');
$GLOBALS['__tz'] = 'America/New_York';
$first = local_to_utc('2026-11-01T01:30');
$check($first === '2026-11-01 05:30:00', 'D2 typing 01:30 on the clocks-back night stores the first occurrence (05:30 UTC)');
$check(local_to_utc_keep('2026-11-01T01:30', '2026-11-01 06:30:00') === '2026-11-01 06:30:00', 'D2 re-saving the second 01:30 keeps it (06:30 UTC)');
$check(local_to_utc_keep('2026-11-01T01:30', '2026-11-01 05:30:00') === '2026-11-01 05:30:00', 'D2 re-saving the first 01:30 keeps it (05:30 UTC)');
$check(local_to_utc_keep('2026-11-01T03:00', '2026-11-01 06:30:00') === '2026-11-01 08:00:00', 'D2 changing the time stores the new instant');
$check(local_to_utc_keep('2026-10-20T09:00', null) === '2026-10-20 13:00:00', 'D2 without a stored value it behaves like local_to_utc()');
$idx = file_get_contents($ROOT . '/index.php');
$check(substr_count($idx, "local_time_status(") >= 3, 'D3 index.php checks local_time_status() in create, edit and drag-move');
$check(str_contains($idx, 'local_to_utc_keep($due, (string)$ev[\'due_at\'])'), 'D3 the edit path keeps an untouched time');
$view = file_get_contents($ROOT . '/app/views/calendar/index.php');
$check(!str_contains($view, "(\$hasOver?' overdue':'')"), 'D4 the month cell is not tinted overdue as a whole');
$check(str_contains($view, "\$stat.=' ov'"), 'D4 an overdue item carries the "ov" class');
$check(str_contains(file_get_contents($ROOT . '/assets/app.css'), '.cal-ev.ov{'), 'D4 the stylesheet styles .cal-ev.ov');
echo $fail ? "DATE 1.172.14: FAIL\n" : "DATE 1.172.14: ALL PASS\n";
exit($fail ? 1 : 0);
