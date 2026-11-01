<?php
/**
 * v1.172.6 — every visible date is MM/DD/YYYY and every time is local (app timezone, 12-hour), never UTC.
 * Runs WITHOUT a database:  php tests/dates/test_v1172_6.php
 * T1 fmt_dt shifts UTC → New York (EDT and EST) · T2 fmt_day never shifts · T3 today_local follows the app timezone
 * T4 fmt_iso_text converts datetimes (shifted) and dates inside free text, leaves invalid dates and ids alone
 * G1 no view prints a " UTC" label or "(UTC)" header · G2 no view formats a stored UTC value with date('M j…') or strtotime(… UTC)
 * G3 no view compares a stored day with PHP's default-timezone date('Y-m-d') · G4 the Google/Microsoft SMTP presets are gone
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$ROOT = dirname(__DIR__, 2);
$fail = false;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };
// Load the REAL helper bodies from bootstrap.php (the file itself needs a config and a database).
$src = file_get_contents($ROOT . '/app/bootstrap.php');
foreach (['fmt_dt', 'fmt_date', 'fmt_time', 'today_local', 'fmt_iso_text', 'fmt_day'] as $fn) {
    $p = strpos($src, 'function ' . $fn . '(');
    if ($p === false) { $check(false, "found $fn in bootstrap"); continue; }
    $o = strpos($src, '{', strpos($src, ')', $p)); $depth = 0; $end = $o;   // brace-match the body (strings here hold no braces)
    for ($k = $o; $k < strlen($src); $k++) { if ($src[$k] === '{') $depth++; elseif ($src[$k] === '}' && --$depth === 0) { $end = $k; break; } }
    eval(substr($src, $p, $end - $p + 1));
}
$GLOBALS['tzname'] = 'America/New_York';
function app_tz(): \DateTimeZone { return new \DateTimeZone($GLOBALS['tzname']); }

$check(fmt_dt('2026-10-14 16:58:00') === '10/14/2026 12:58 PM', 'T1 16:58 UTC in October → 12:58 PM Eastern (EDT)');
$check(fmt_dt('2026-01-15 03:30:00') === '01/14/2026 10:30 PM', 'T1 winter (EST) crosses midnight to the previous day');
$check(fmt_dt('2026-10-14 16:58:00', 'm/d/Y g:i:s A') === '10/14/2026 12:58:00 PM', 'T1 seconds format');
$check(fmt_dt(null) === '' && fmt_dt('') === '', 'T1 empty stays empty');
$check(fmt_day('2026-10-14') === '10/14/2026' && fmt_day('2026-10-14 23:59:00') === '10/14/2026', 'T2 a date-only value is never shifted');
$check(fmt_day('2027-03-13') === '03/13/2027', 'T2 MM/DD/YYYY with leading zeros');
$GLOBALS['tzname'] = 'Pacific/Honolulu';
$check(fmt_dt('2026-10-14 03:00:00', 'm/d/Y') === '10/13/2026', 'T3 follows the configured timezone (Hawaii)');
$check(today_local() === (new DateTimeImmutable('now', new DateTimeZone('Pacific/Honolulu')))->format('Y-m-d'), 'T3 today_local is the day in the app timezone');
$GLOBALS['tzname'] = 'America/New_York';
$check(today_local('+1 day') === (new DateTimeImmutable('tomorrow', new DateTimeZone('America/New_York')))->format('Y-m-d'), 'T3 today_local accepts a modifier');
$t = fmt_iso_text('due_at: 2026-10-14 16:58:00 · expiry: 2027-06-30 · id: 1505f017-1a3e-4f43 · bad: 2026-13-45');
$check(str_contains($t, '10/14/2026 12:58 PM') && str_contains($t, '06/30/2027') && str_contains($t, '1505f017-1a3e-4f43') && str_contains($t, '2026-13-45'),
       'T4 free text: datetime shifted, date reformatted, uuid and impossible date untouched (' . $t . ')');
$check(!preg_match('/\b\d{4}-\d{2}-\d{2}\b/', fmt_iso_text('a 2026-02-03 b 2026-02-04T10:00:00Z')), 'T4 no ISO date survives');

$views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/app/views'));
$bad = ['utc' => [], 'fmt' => [], 'today' => []];
foreach ($views as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    $rel = substr($f->getPathname(), strlen($ROOT) + 1);
    foreach (file($f->getPathname()) as $i => $ln) {
        if (preg_match('/\(UTC\)|\?> UTC\b|\) UTC\b|\bUTC<\/|\) \?> UTC/', $ln) && !str_contains($rel, 'admin/system.php')) $bad['utc'][] = "$rel:" . ($i + 1);
        if (preg_match('/date\(\s*\'[^\']*[MFjDl][^\']*\'/', $ln) && !str_contains($ln, 'mktime')) $bad['fmt'][] = "$rel:" . ($i + 1);
        if (preg_match('/date\(\s*\'Y-m-d\'/', $ln)) $bad['today'][] = "$rel:" . ($i + 1);
    }
}
$check($bad['utc'] === [], 'G1 no " UTC" label / "(UTC)" header in any view' . ($bad['utc'] ? ' — ' . implode(', ', $bad['utc']) : ''));
$check($bad['fmt'] === [], 'G2 no view prints dates with date(\'M j…\')' . ($bad['fmt'] ? ' — ' . implode(', ', $bad['fmt']) : ''));
$check($bad['today'] === [], 'G3 no view uses date(\'Y-m-d\') (PHP default timezone) — use today_local()' . ($bad['today'] ? ' — ' . implode(', ', $bad['today']) : ''));
$tm = file_get_contents($ROOT . '/app/src/TenantMailService.php');
$check(!preg_match("/'(google|microsoft)'\s*=>/", $tm) && !str_contains($tm, 'smtp.gmail.com') && !str_contains($tm, 'smtp.office365.com'), 'G4 Google / Microsoft password-SMTP presets removed');
$src5 = file_get_contents($ROOT . '/app/views/org/sources.php');
$check(substr_count($src5, "fmt_iso_text((string)\$s['regulation'])") === 1 && substr_count($src5, "fmt_iso_text((string)\$s['summary'])") === 1, 'G5 Sources: legal citation and summary text are shown with MM/DD/YYYY dates');
// G6/G7 (added in v1.172.8): services and index.php must not use PHP's default-timezone date('Y-m-d') for stored or compared days
// (file names for downloads are the only allowed use); views must not cut a UTC timestamp with substr() to show it.
$rawToday = []; $rawSub = [];
$scan = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/app/src'));
$files6 = [$ROOT . '/index.php'];
foreach ($scan as $f) if ($f->isFile() && $f->getExtension() === 'php') $files6[] = $f->getPathname();
foreach ($files6 as $f) foreach (file($f) as $i => $ln) {
    if (preg_match("/(?<![a-z_])date\(\s*'Y-m-d'/", $ln) && !preg_match('/Content-Disposition|\$fname|filename/', $ln)) $rawToday[] = substr($f, strlen($ROOT) + 1) . ':' . ($i + 1);
}
foreach ($views as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    foreach (file($f->getPathname()) as $i => $ln) if (preg_match("/substr\(\(string\)\s*\(?\s*\$[A-Za-z_]+\['[a-z_]*_at'\][^)]*,\s*0\s*,\s*(10|16)\)/", $ln)) $rawSub[] = substr($f->getPathname(), strlen($ROOT) + 1) . ':' . ($i + 1);
}
$check($rawToday === [], 'G6 no date(\'Y-m-d\') for stored/compared days in services or index.php — use today_local()' . ($rawToday ? ' — ' . implode(', ', $rawToday) : ''));
$check($rawSub === [], 'G7 no view shows a UTC timestamp cut with substr() — use fmt_dt()' . ($rawSub ? ' — ' . implode(', ', $rawSub) : ''));
exit($fail ? 1 : 0);
