<?php
/**
 * v1.172.11 — "today" is the organization's day, and a timed event is typed in its zone. Runs WITHOUT a database:
 *   php tests/dates/test_v1172_11.php
 * T1 credential_status_for(): expiry = the org's today is NOT expired; expiry = the org's yesterday IS expired
 *    (run in whichever of UTC+14 / UTC-11 has a different calendar day than UTC right now, so a UTC-"today" cannot pass both)
 * T2 local_to_utc(): wall-clock typed in the org zone -> the UTC string stored (DST included)
 * T3 source guards: no CURDATE() left in app/ or index.php; the connection sets @flx_today; agenda / overdue use the org's day
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$ROOT = dirname(__DIR__, 2);
$fail = false;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };
$GLOBALS['__tz'] = 'UTC';
function app_tz(): DateTimeZone { return new DateTimeZone($GLOBALS['__tz']); }
function config(string $k, mixed $d = null): mixed { return $d; }
$boot = file_get_contents($ROOT . '/app/bootstrap.php');
foreach (['today_local', 'today_local_dt', 'local_to_utc'] as $fn) {
    preg_match('/\nfunction ' . $fn . '\(.*?\n}\n/s', $boot, $m);
    $check(($m[0] ?? '') !== '', "helper $fn() exists in bootstrap.php");
    eval($m[0] ?? '');
}
$idx = file_get_contents($ROOT . '/index.php');
preg_match('/\nfunction credential_status_for\(.*?\n}\n/s', $idx, $m);
$check(($m[0] ?? '') !== '', 'credential_status_for() found');
eval($m[0] ?? '');

// ---- T1 ----
$utcDay = gmdate('Y-m-d');
foreach (['Pacific/Kiritimati', 'Pacific/Pago_Pago'] as $z) {
    $GLOBALS['__tz'] = $z; $loc = (new DateTimeImmutable('now', new DateTimeZone($z)))->format('Y-m-d');
    if ($loc === $utcDay) continue;
    $yest = (new DateTimeImmutable($loc))->modify('-1 day')->format('Y-m-d');
    $tom  = (new DateTimeImmutable($loc))->modify('+1 day')->format('Y-m-d');
    $check(today_local() === $loc, "T1 [$z] today_local() is the org's day ($loc), not UTC's ($utcDay)");
    $check(credential_status_for($loc) !== 'expired', "T1 [$z] a credential expiring on the org's TODAY ($loc) is not expired");
    $check(credential_status_for($yest) === 'expired', "T1 [$z] a credential that expired the org's YESTERDAY ($yest) is expired");
    $check(credential_status_for($tom) === 'expiring', "T1 [$z] tomorrow is 'expiring'");
}
$GLOBALS['__tz'] = 'UTC';

// ---- T2 ----
$GLOBALS['__tz'] = 'Pacific/Honolulu';
$check(local_to_utc('2026-10-20T23:30') === '2026-10-21 09:30:00', 'T2 Honolulu 23:30 -> next day 09:30 UTC');
$GLOBALS['__tz'] = 'America/New_York';
$check(local_to_utc('2026-10-20 23:30:00') === '2026-10-21 03:30:00', 'T2 New York (EDT) 23:30 -> 03:30 UTC next day');
$check(local_to_utc('2026-12-20T09:00') === '2026-12-20 14:00:00', 'T2 New York (EST) 09:00 -> 14:00 UTC');
$check(local_to_utc('not a date') === 'not a date', 'T2 unparseable input is returned unchanged (never a fatal)');
$GLOBALS['__tz'] = 'UTC';
$check(local_to_utc('2026-10-20T23:30') === '2026-10-20 23:30:00', 'T2 UTC org is unchanged');

// ---- T3 ----
$bad = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/app', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) if ($f->getExtension() === 'php' && str_contains(file_get_contents($f->getPathname()), 'CURDATE()')) $bad[] = substr($f->getPathname(), strlen($ROOT) + 1);
if (str_contains($idx, 'CURDATE()')) $bad[] = 'index.php';
$check($bad === [], 'T3 no CURDATE() (the database\'s UTC day) left in app/ or index.php' . ($bad ? ': ' . implode(', ', $bad) : ''));
$db = file_get_contents($ROOT . '/app/src/Database.php');
$check(str_contains($db, 'SET @flx_today'), 'T3 the connection sets @flx_today to the org\'s day');
$cal = file_get_contents($ROOT . '/app/src/Repository/CalendarRepository.php');
$check(!str_contains($cal, "new \\DateTimeImmutable('today')") && str_contains($cal, 'today_local_dt()'), 'T3 CalendarRepository uses the org\'s day');
$check(str_contains($cal, 'all_day = 1 AND due_at < ?'), 'T3 overdueCount(): an all-day item is overdue only after its day');
$check(str_contains($cal, "fmt_dt((string)\$e['due_at'], 'Y-m-d')"), 'T3 agenda buckets a timed event by its LOCAL day');
$check(preg_match_all('/local_to_utc(?:_keep)?\(/', $idx) >= 3, 'T3 create, edit and move convert a typed time to UTC');
exit($fail ? 1 : 0);
