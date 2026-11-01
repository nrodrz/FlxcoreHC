<?php
/**
 * v1.172.12 — blind-spot guards found by the independent v1.172.11 run. Runs WITHOUT a database:  php tests/dates/test_v1172_12.php
 * G1 no database-side "today" in UTC (CURDATE()) anywhere that runs: app/, cron/, tools/, index.php, install.php (tests/docs/migrations excluded)
 * G2 every cron / CLI entry point that touches dates connects through Database::pdo() (which sets @flx_today)
 * G3 the calendar window query converts the LOCAL day bounds to UTC for timed events (month-end evenings)
 * G4 the agenda uses the same overdue rule as the banner for timed events
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$ROOT = dirname(__DIR__, 2);
$fail = false;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };
$scan = [];
foreach (['app', 'cron', 'tools'] as $d) {
    if (!is_dir("$ROOT/$d")) continue;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$ROOT/$d", FilesystemIterator::SKIP_DOTS)) as $f) {
        if (in_array($f->getExtension(), ['php', 'sh'], true)) $scan[] = $f->getPathname();
    }
}
foreach (['index.php', 'install.php'] as $f) if (is_file("$ROOT/$f")) $scan[] = "$ROOT/$f";
$bad = [];
foreach ($scan as $f) if (preg_match('/\bCURDATE\s*\(|\bCURRENT_DATE\b/i', file_get_contents($f))) $bad[] = substr($f, strlen($ROOT) + 1);
$check($bad === [], 'G1 no CURDATE()/CURRENT_DATE in ' . count($scan) . ' runnable files' . ($bad ? ': ' . implode(', ', $bad) : ''));
$cronOk = true;
foreach (glob("$ROOT/cron/*.php") as $f) {
    $src = file_get_contents($f);
    if (!str_contains($src, 'bootstrap.php')) { $cronOk = false; echo "   no bootstrap: " . basename($f) . "\n"; }
}
$check($cronOk, 'G2 every cron script loads bootstrap.php (so Database::pdo() sets @flx_today)');
$sweep = file_get_contents("$ROOT/cron/sweep_expiring.php");
$check(substr_count($sweep, '@flx_today') >= 4, 'G2 the nightly expiry sweep compares against @flx_today');
$cal = file_get_contents("$ROOT/app/src/Repository/CalendarRepository.php");
$check(str_contains($cal, 'private static function dayWindow') && substr_count($cal, 'self::dayWindow(') >= 3, 'G3 filtered(), between() and dayCounts() use the day-window helper');
$check(str_contains($cal, "local_to_utc(\$from . ' 00:00:00')") && str_contains($cal, "local_to_utc(\$next . ' 00:00:00')"), 'G3 timed items are compared against UTC bounds of the local day');
$check(!str_contains($cal, 'due_at < DATE_ADD(?, INTERVAL 1 DAY)') && !str_contains($cal, 'GROUP BY DATE(due_at)'), 'G3 no raw UTC-vs-local day comparison is left');
$check(str_contains($cal, '$ad ? $d < $td : (string)$e[\'due_at\'] < $nowUtc'), 'G4 agenda: all-day overdue after its day, timed at its instant');
$es = file_get_contents("$ROOT/app/lang/es.php");
foreach (['Too many sign-in attempts. Try again in 15 minutes.', 'Too many attempts. Try again in 15 minutes.', 'Too many codes tried. Please sign in again.'] as $m) {
    $check(str_contains($es, "'" . $m . "' =>"), "G5 Spanish for the lock-out message: $m");
}
exit($fail ? 1 : 0);
