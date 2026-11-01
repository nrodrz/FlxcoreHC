<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * v1.172.10 — findings from the independent v1.172.7 validation. Runs WITHOUT a database:  php tests/i18n/test_v1172_10.php
 * S1 SmtpGuard: a host name that does not resolve is refused with a message, never a TypeError (was HTTP 500)
 * D1 fmt_iso_in_text: raw ISO dates / UTC timestamps inside free text are shown in the reader's format; ids are left alone
 * D2 the calendar view treats an all-day item as a calendar day, not an instant
 * L1 the signed-out pages carry the language switch and the resolver honours ?lang, cookie and Accept-Language
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$ROOT = dirname(__DIR__, 2);
spl_autoload_register(function ($c) use ($ROOT) {
    if (str_starts_with($c, 'FlxCoreHC\\')) { $f = $ROOT . '/app/src/' . str_replace('\\', '/', substr($c, 10)) . '.php'; if (is_file($f)) require $f; }
});
$fail = false;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };

// ---- S1 ----
$resolvers = [
    'resolver returns [false] (what gethostbynamel gives for an unknown name, cast to array)' => fn($h) => [false],
    'resolver returns false' => fn($h) => false,
    'resolver returns []' => fn($h) => [],
];
foreach ($resolvers as $label => $fn) {
    FlxCoreHC\SmtpGuard::$resolver = $fn;
    try { [$ip, $why] = FlxCoreHC\SmtpGuard::resolveSafe('smtp.example.com'); $ok = $ip === null && is_string($why) && str_contains($why, 'could not be resolved'); }
    catch (\Throwable $e) { $ok = false; }
    $check($ok, "S1 unresolvable host is refused with a message — $label");
}
FlxCoreHC\SmtpGuard::$resolver = fn($h) => ['93.184.216.34'];
$r = FlxCoreHC\SmtpGuard::resolveSafe('smtp.example.com');
$check($r[0] === '93.184.216.34' && $r[1] === null, 'S1 a public address still passes');
FlxCoreHC\SmtpGuard::$resolver = fn($h) => ['10.0.0.5'];
$check(FlxCoreHC\SmtpGuard::resolveSafe('smtp.example.com')[0] === null, 'S1 a private address is still refused');
FlxCoreHC\SmtpGuard::$resolver = null;
$check(FlxCoreHC\SmtpGuard::resolveSafe('no-such-host-flx.example.invalid')[1] !== null, 'S1 real resolver path: unknown name does not throw');

// ---- D1 ----
$src = file_get_contents($ROOT . '/app/bootstrap.php');
preg_match('/function fmt_iso_in_text\(.*?\n}\n/s', $src, $m); $fnSrc = $m[0] ?? '';
$tz = 'America/New_York';
function app_tz(): DateTimeZone { return new DateTimeZone('America/New_York'); }
function fmt_dt(?string $utc, string $fmt = 'm/d/Y g:i A'): string { return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(app_tz())->format($fmt); }
eval($fnSrc);
$check($fnSrc !== '', 'D1 helper exists in bootstrap.php');
$check(fmt_iso_in_text('expiry: 2027-03-14 · issued_date: 2025-09-08') === 'expiry: 03/14/2027 · issued_date: 09/08/2025', 'D1 ISO dates become MM/DD/YYYY');
$check(fmt_iso_in_text('created_at: 2026-10-15 19:59:32') === 'created_at: 10/15/2026 3:59 PM', 'D1 a UTC timestamp becomes local 12-hour time');
$check(fmt_iso_in_text('holder_id: f554ff58-8691-47c3-a17d-16c0f6570177') === 'holder_id: f554ff58-8691-47c3-a17d-16c0f6570177', 'D1 ids are left alone');
$check(fmt_iso_in_text('file: 2026-13-45.pdf and 2026-10-15x') === 'file: 2026-13-45.pdf and 2026-10-15x', 'D1 impossible dates and glued text are left alone');
$check(fmt_iso_in_text('') === '' && fmt_iso_in_text('no dates here') === 'no dates here', 'D1 empty / plain text unchanged');

// ---- D2 (view source guards; behaviour is proven in the browser test) ----
$cal = file_get_contents($ROOT . '/app/views/calendar/index.php');
$check(str_contains($cal, '$__ad($e) ? substr((string)$e[\'due_at\'], 0, 10)'), 'D2 month grid places an all-day item on its stored day');
$check(substr_count($cal, '$__when($e)') >= 2, 'D2 list and "Next 30 days" show a date, not a clock time, for all-day items');

// ---- L1 ----
$idx = file_get_contents($ROOT . '/index.php');
$check(str_contains($idx, "\$_COOKIE['flx_lang']") && str_contains($idx, "setcookie('flx_lang'"), 'L1 resolver reads and writes the flx_lang cookie');
$ok = true; foreach (glob($ROOT . '/app/views/auth/*.php') as $f) { if (basename($f) === '_lang.php') continue; if (!str_contains(file_get_contents($f), "_lang.php")) { $ok = false; echo "   missing in " . basename($f) . "\n"; } }
$check($ok, 'L1 every signed-out page includes the language switch');

// ---- M1 ----
$sa = file_get_contents($ROOT . '/app/src/SuperadminService.php');
$check(substr_count($sa, "strlen(\$tempPass) < 10") === 1 && substr_count($sa, "strlen(\$pass) < 10") >= 1 && !str_contains($sa, 'at least 8 characters'), 'M1 temporary password minimum is 10 everywhere');
$check(!str_contains($idx, "\$_GET['error_description']"), 'M1 OAuth callback no longer echoes error_description');
exit($fail ? 1 : 0);
