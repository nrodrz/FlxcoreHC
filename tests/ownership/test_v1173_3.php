<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * v1.173.3 — editable Terms of Use, scheduled-job lines, time-zone change confirmation. Runs WITHOUT a database:  php tests/ownership/test_v1173_3.php
 * E1 the editor's plain-text format round-trips the shipped Terms (English and Spanish) and rejects bad text
 * E2 only the platform console edits the Terms; versions are append-only; a save is a new version
 * E3 the System page builds the cron lines at run time (no account path or domain in code)
 * E4 the time zone pickers ask for confirmation when the value is changed
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$ROOT = dirname(__DIR__, 2);
$fail = false;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };
$rd = fn(string $f) => (string) file_get_contents("$ROOT/$f");
define('FLXCOREHC_VERSION', 'test');
require_once "$ROOT/app/src/TermsService.php";
use FlxCoreHC\TermsService as T;

// ---- E1 ----
foreach (['en', 'es'] as $l) {
    $f = require "$ROOT/app/terms/$l.php";
    [$secs, $err] = T::parseMarkup(T::toMarkup($f['sections']));
    $check($err === null && $secs === $f['sections'], "E1 the shipped $l Terms survive text -> sections -> text unchanged (" . count($secs) . ' sections)');
}
$bad = [
    ['', 'empty text'], ["Just a paragraph with no heading.", 'text before any heading'], ["# Heading only", 'a heading with no paragraph'],
    ["# H\n\nOK {unknown} text", 'an unknown placeholder'], ["# \n\ntext", 'an empty heading'],
];
foreach ($bad as [$t, $what]) { [$s, $e] = T::parseMarkup($t); $check($s === [] && is_string($e), "E1 rejected: $what"); }
[$s, $e] = T::parseMarkup("# One\n\nFirst line\nsecond line of the same paragraph.\n\nNext paragraph with {organization}, {product} and {owner}.\n\n# Two\n\nBody.");
$check($e === null && count($s) === 2 && $s[0][1][0] === 'First line second line of the same paragraph.' && count($s[0][1]) === 2, 'E1 lines of one paragraph are joined; a blank line starts the next; allowed placeholders pass');

// ---- E2 ----
$idx = $rd('index.php'); $svc = $rd('app/src/TermsService.php');
$adminAt = strpos($idx, "if (\$seg[0] === 'admin') {"); $editAt = strpos($idx, "if (\$id === 'terms') {");
$check($adminAt !== false && $editAt > $adminAt && str_contains(substr($idx, $adminAt, 200), 'isPlatformAdmin'), 'E2 the editor is inside the platform-administrator-only console route');
$check(str_contains($idx, "empty(\$_POST['confirm_publish'])") && str_contains($idx, 'CsrfGuard::verify()'), 'E2 saving needs the confirmation tick and a CSRF token');
$check(!preg_match('/\b(UPDATE|DELETE)\s+(FROM\s+)?terms_versions/i', $svc . $idx) && str_contains($svc, 'INSERT INTO terms_versions') && str_contains($svc, "audit_log(\$ctx, 'terms_published'"), 'E2 versions are append-only (INSERT only) and each publish is audited');
$check(str_contains($rd('app/views/admin/_tabs.php'), '/admin/terms') && str_contains($rd('database/manifest.php'), '137_terms_versions.sql') && str_contains($rd('install.php'), '137_terms_versions.sql'), 'E2 console tab and migration 137 are listed');

// ---- E3 ----
$sys = $rd('app/views/admin/system.php');
$check(str_contains($sys, 'PHP_BINARY') && str_contains($sys, 'FLXCOREHC_ROOT') && !preg_match('/public_html|cpaneluser|\/home\/[a-z]/i', $sys), 'E3 cron lines come from PHP_BINARY and FLXCOREHC_ROOT; no account path in the page');
foreach (['sweep_expiring.php', 'screen_leie.php', 'send_reminders.php', 'send_digests.php'] as $j) $check(str_contains($sys, $j) && is_file("$ROOT/cron/$j"), "E3 job listed and present: $j");

// ---- E4 ----
$check(str_contains($sys, 'data-confirm-change=') && str_contains($rd('app/views/settings/index.php'), 'data-confirm-change='), 'E4 both time-zone pickers (platform and organization) carry a change confirmation');
$check(str_contains($rd('assets/app.js'), "select[data-confirm-change]") && str_contains($rd('assets/app.js'), 'defaultSelected'), 'E4 the script asks only when the selected value really changed');
echo $fail ? "OWNERSHIP 1.173.3: FAIL\n" : "OWNERSHIP 1.173.3: ALL PASS\n";
exit($fail ? 1 : 0);
