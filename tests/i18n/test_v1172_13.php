<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * v1.172.13 — every user-visible message literal has a Spanish entry. Runs WITHOUT a database:  php tests/i18n/test_v1172_13.php
 * F1 each flash('...') literal in index.php / app/src and each `return [bool, '...']` message literal in app/src has an entry in app/lang/es.php
 *    (the three Claude Code found in v1.172.12 - invite accepted, public sign-up disabled, bad verification link - are checked by name too)
 * F2 messages that are built by joining English pieces cannot be translated; the list below is FROZEN (22 operator/console messages).
 *    A new joined-English message fails this test: write it with t('... {placeholder} ...', [...]) instead.
 * F3 the HTTPS installer keeps cookies HTTPS-only
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$ROOT = dirname(__DIR__, 2);
$fail = false;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };
$es = file_get_contents($ROOT . '/app/lang/es.php');
preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'\\s*=>/", $es, $m); $have = array_flip($m[1]);
$FROZEN = [
    'Added "',
    'Applied "',
    'Cannot permanently delete: ',
    'Could not create organization: ',
    'Five demo organizations created with document requests, an external share (password: ',
    'Global role “',
    'Healthcare demo build failed: ',
    'Invoice ',
    'LEIE list loaded: ',
    'LEIE sweep complete: ',
    'No template for profession "',
    'Note saved for ',
    'Organization ',
    'Organization "',
    'Role “',
    'SAM.gov list loaded: ',
    'Stripe request failed: ',
    'Support session open until ',
    'Templates, regulators and roles synced for ',
    'Timezone set to ',
    'User ',
    'You cannot grant capabilities you do not hold yourself: '
];
$files = [$ROOT . '/index.php'];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/app/src', FilesystemIterator::SKIP_DOTS)) as $f) if ($f->getExtension() === 'php') $files[] = $f->getPathname();
$missing = []; $joined = []; $n = 0;
foreach ($files as $f) {
    $src = file_get_contents($f); $isSrc = str_contains($f, '/app/src/');
    $pats = ["/\\bflash\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/"]; if ($isSrc) $pats[] = "/return \\[\\s*(?:false|true)\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'/";
    foreach ($pats as $p) {
        preg_match_all($p, $src, $mm);
        foreach ($mm[1] as $k) {
            if ($k === '') continue; $n++;
            if (isset($have[$k])) continue;
            if (preg_match('/[ (:“"]$/u', $k)) { if (!in_array($k, $FROZEN, true)) $joined[$k] = basename($f); }
            else $missing[$k] = basename($f);
        }
    }
}
$check($n > 250, "F1 scanned $n message literals");
$check($missing === [], 'F1 every complete message has a Spanish entry' . ($missing ? ' - MISSING: ' . implode(' | ', array_map(fn($k, $f) => "$k [$f]", array_keys($missing), $missing)) : ''));
$check($joined === [], 'F2 no NEW message built by joining English pieces' . ($joined ? ' - NEW: ' . implode(' | ', array_keys($joined)) : ''));
foreach (['Your account is ready — please sign in.', 'Public sign-up is disabled. Please ask your administrator for an invitation.',
          'It may have expired or been revoked by the organization that shared it. Ask them to send a fresh verification link.'] as $k) {
    $check(isset($have[$k]), "F1 named: $k");
}
$inst = file_get_contents($ROOT . '/install.php');
$check(str_contains($inst, "|| \$isHttps,") && str_contains($inst, 'name="force_secure_cookies" value="1"<?='), 'F3 an install done over HTTPS writes force_secure_cookies => true (box pre-ticked too)');
// F4 the messages rewritten with placeholders translate with the name kept (real translator)
spl_autoload_register(function ($c) use ($ROOT) {
    if (str_starts_with($c, 'FlxCoreHC\\')) { $f = $ROOT . '/app/src/' . str_replace('\\', '/', substr($c, 10)) . '.php'; if (is_file($f)) require $f; }
});
if (!function_exists('config')) { function config(string $key, mixed $default = null): mixed { return $default; } }
if (!function_exists('t')) { function t(string $en, array $vars = []): string { return \FlxCoreHC\I18n::t($en, $vars); } }
define('FLXCOREHC_APP', $ROOT . '/app');
$_SESSION = ['locale' => 'es'];
$check(t('Assigned to {name}.', ['name' => 'Ana Rivera']) === 'Asignado a Ana Rivera.', 'F4 "Assigned to {name}." -> Spanish with the name kept');
$check(t('Invitation created for {name}.', ['name' => 'Luis Colon']) === 'Invitación creada para Luis Colon.', 'F4 "Invitation created for {name}." -> Spanish');
$check(t('Payment of {amount} recorded.', ['amount' => '$1,250.00']) === 'Pago de $1,250.00 registrado.', 'F4 "Payment of {amount} recorded." -> Spanish');
$_SESSION = [];
exit($fail ? 1 : 0);
