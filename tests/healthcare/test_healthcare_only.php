<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * v1.170.7 — healthcare-only guarantees. Runs WITHOUT a database:  php tests/healthcare/test_healthcare_only.php
 * H1 context is always healthcare · H2 vocabulary · H3 sign-up offers only healthcare shapes · H4 catalog
 * defaults · H5 no insurance phrases in any user-facing view · H6 migration 124 registered + no bare SELECT.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // CLI only — never runnable from a browser
$ROOT = dirname(__DIR__, 2);
spl_autoload_register(function ($c) use ($ROOT) {
    if (str_starts_with($c, 'FlxCoreHC\\')) { $f = $ROOT . '/app/src/' . str_replace('\\', '/', substr($c, 10)) . '.php'; if (is_file($f)) require $f; }
});
// csv_safe_row lives in app/bootstrap.php (which needs config/DB); evaluate just that function here.
if (preg_match('/function csv_safe_row\(array \$row\): array\s*\{.*?\n\}/s', file_get_contents($ROOT . '/app/bootstrap.php'), $m)) { eval($m[0]); }
$fail = false;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };

// H1
foreach (['insurance', 'custom', 'platform', ''] as $v) {
    $c = new FlxCoreHC\TenantContext('t', 'u', 'staff', 'n', $v, 'medical_group', 'x');
    $check($c->vertical === 'healthcare', "H1 context vertical '$v' → healthcare");
}
// H2
$check(FlxCoreHC\Vocab::term('holder', 'insurance') === 'Provider', 'H2 vocab holder = Provider for any vertical');
$check(FlxCoreHC\Vocab::term('partners', 'custom') === 'Payers', 'H2 vocab partners = Payers for any vertical');
// H3
$shapes = FlxCoreHC\Auth\AuthService::ORG_SHAPES;
$check(!isset($shapes['custom']), 'H3 "Other (custom)" sign-up removed');
$nonHc = array_filter($shapes, fn($s, $k) => $k !== 'platform' && $s[0] !== 'healthcare', ARRAY_FILTER_USE_BOTH);
$check($nonHc === [], 'H3 every sign-up shape is healthcare');
// H4
$check(!str_contains(FlxCoreHC\CatalogService::RULESET_LABEL, 'CMS-4208') && !str_contains(FlxCoreHC\CatalogService::RULESET_LABEL, 'commercial lines'), 'H4 ruleset label is healthcare');
$all = FlxCoreHC\CatalogService::customAppliesTo(null);
$check(str_contains($all, 'physician') && str_contains($all, 'nurse') && !str_contains($all, 'agent'), 'H4 custom document type defaults to all healthcare provider types');
$check(FlxCoreHC\CatalogService::customAppliesTo(['physician', 'agent']) === 'physician', 'H4 invalid applies_to values dropped');
$m124 = file_get_contents($ROOT . '/database/migrations/124_healthcare_only.sql');
$check(strlen($all) <= 255 && preg_match('/MODIFY COLUMN applies_to VARCHAR\(255\)/', $m124) === 1
    && strpos($m124, 'MODIFY COLUMN applies_to') < strpos($m124, 'UPDATE credential_catalog'),
    'H4 applies_to column widened to 255 before the default (' . strlen($all) . ' chars) is written');
$check(FlxCoreHC\CatalogService::holderRole(['holder_type' => 'individual', 'vertical' => 'insurance'], 'agency') === 'physician', 'H4 holder role never falls back to insurance "agent"');
$check(FlxCoreHC\TeamService::ROLES['producer'] === 'Provider (self-service)', 'H4 portal role label is Provider (self-service)');
// H5 — banned user-facing phrases (comments stripped). Any hit is a regression.
$banned = ['/Medicare Advantage requirements/', '/CMS Audit-Readiness/', '/NIPR/', '/F\.S\. 626/', '/myfloridacfo/i', '/Flood \(NFIP\)/',
           '/Carrier verification links/', '/Agency licence number/', '/Entity NPN/', '/National Producer Number/',
           '/>\s*Producers?\b/', '/No producers yet/', '/Add a producer/', '/\(insurance\)/', '/Client call/', '/Agent \(optional\)/',
           '/agents, agencies, (or|and) FMOs/', '/FL Medicare Agent/', '/Producer logins/', '/beneficiary data/'];
$hits = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/app/views', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (substr((string) $f, -4) !== '.php') continue;
    $src = file_get_contents((string) $f);
    $src = preg_replace(['~/\*.*?\*/~s', '~<\?php\s*//[^\n]*~', '~<!--.*?-->~s'], '', $src);
    // drop the insurance-only branches that can no longer render (context is always healthcare)
    foreach ($banned as $re) { if (preg_match($re, $src, $m)) $hits[] = str_replace($ROOT . '/', '', (string) $f) . ' :: ' . trim($m[0]); }
}
$check($hits === [], 'H5 no insurance phrases in views' . ($hits ? ":\n     " . implode("\n     ", array_slice($hits, 0, 20)) : ''));
// H5b — user-facing strings outside views: flash messages, audit action names, labels in index.php + app/src + lang
$bannedSrc = ['/flash\([^)]*(producer|agent|agency|Lines of business|NPN)/i', '/audit\(\'producer_/', '/\'lines_of_business_set\'/',
              '/\(int\)date\(\'n\'\) >= 6/', "/'NPN must be numeric'/", '/Producer \(self-service\)/', '/CMS-4208|commercial lines/'];
$hits2 = [];
foreach (array_merge([$ROOT . '/index.php', $ROOT . '/app/lang/es.php'], glob($ROOT . '/app/src/*.php')) as $f) {
    $src = preg_replace(['~/\*.*?\*/~s', '~^\s*//[^\n]*~m'], '', file_get_contents($f));
    foreach ($bannedSrc as $re) { if (preg_match($re, $src, $m)) $hits2[] = str_replace($ROOT . '/', '', $f) . ' :: ' . trim($m[0]); }
}
$check($hits2 === [], 'H5b no insurance flash/audit/label strings in index.php, app/src, lang' . ($hits2 ? ":\n     " . implode("\n     ", $hits2) : ''));
// H7 — security regressions guarded by this release
$ts = file_get_contents($ROOT . '/app/src/TeamService.php');
$check(str_contains($ts, 'actorMayGrant') && substr_count($ts, '$this->actorMayGrant($role)') >= 3, 'H7 Master Admin grant is owner-only in create/invite/updateRole');
$check(FlxCoreHC\RoleService::isReservedOwnerSlug('master_admin') && FlxCoreHC\RoleService::isReservedOwnerSlug('tenant_owner'), 'H7 reserved owner slugs recognised');
foreach (['CredentialRepository', 'DocumentRepository', 'RenewalTaskRepository', 'CalendarRepository'] as $r) {
    $src = file_get_contents($ROOT . "/app/src/Repository/$r.php");
    $check(str_contains($src, 'providerColumn') || str_contains($src, 'credentialColumn'), "H7 need-to-know (SQL provider scope) enforced in $r");
}
$idx = file_get_contents($ROOT . '/index.php');
$check(substr_count($idx, 'csv_safe_row(') >= 6 && str_contains(file_get_contents($ROOT . '/app/src/DataExportService.php'), 'csv_safe_row('), 'H7 CSV exports pass through csv_safe_row');
$check(str_contains($idx, "'2fa_disable|'"), 'H7 2FA disable is rate-limited');
$check(!str_contains($idx, "'npn'         =>"), 'H7 holder create/edit never store a posted NPN');
$check(csv_safe_row(['=1+1', '-5', 'x'])[0] === "'=1+1" && csv_safe_row(['=1+1', '-5', 'x'])[1] === '-5', 'H7 csv_safe_row neutralises formulas, keeps numbers');
// H6
$inst = file_get_contents($ROOT . '/install.php'); $man = file_get_contents($ROOT . '/database/manifest.php');
$check(str_contains($inst, '124_healthcare_only.sql') && str_contains($man, '124_healthcare_only.sql'), 'H6 migration 124 in installer and updater lists');
foreach (glob($ROOT . '/database/migrations/*.sql') as $mf) {
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($mf)); $buf = '';
    foreach (explode("\n", $sql) as $l) { $buf .= $l . "\n"; if (preg_match('/;\s*$/', $l)) { if (preg_match('/^\s*\(?\s*(SELECT|SHOW)\b/i', trim($buf))) $check(false, 'H6 bare SELECT in ' . basename($mf)); $buf = ''; } }
}
$check(true, 'H6 migrations scanned for bare SELECT');
echo "\n" . ($fail ? "SOME FAILED\n" : "ALL PASS\n");
exit($fail ? 1 : 0);
