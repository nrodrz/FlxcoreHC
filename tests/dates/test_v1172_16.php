<?php
/**
 * v1.172.16 — per-organization time zone: source guards. Runs WITHOUT a database:  php tests/dates/test_v1172_16.php
 * Y1 migration 134 adds tenants.timezone (NULL = platform zone) and is listed by both install.php and the manifest
 * Y2 one list of zones (flx_timezones) feeds the platform picker, the organization picker and the validators
 * Y3 index.php puts the signed-in organization's zone in force right after the context is built
 * Y4 every cross-organization job (sweep, reminders, digests) switches zone per organization and no job compares against one global "today"
 * Y5 the installer asks for the platform zone and writes it to config.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$ROOT = dirname(__DIR__, 2);
$fail = false;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };
$rd = fn(string $f) => (string) file_get_contents("$ROOT/$f");
$mig = $rd('database/migrations/134_tenant_timezone.sql');
$check(preg_match('/ALTER TABLE tenants ADD COLUMN timezone VARCHAR\(40\) NULL/i', $mig) === 1, 'Y1 migration 134 adds tenants.timezone, NULL by default');
$check(str_contains($rd('install.php'), '134_tenant_timezone.sql') && str_contains($rd('database/manifest.php'), '134_tenant_timezone.sql'), 'Y1 install.php and manifest.php both list migration 134');
$check(substr_count($rd('app/bootstrap.php'), "'America/Puerto_Rico' =>") === 1, 'Y2 the zone list is defined once (flx_timezones)');
$check(str_contains($rd('app/views/admin/system.php'), 'flx_timezones()') && str_contains($rd('app/views/settings/index.php'), 'flx_timezones()') && str_contains($rd('app/src/OrgService.php'), 'flx_timezones()'), 'Y2 platform picker, organization picker and OrgService use it');
$check(str_contains($rd('index.php'), 'flx_timezones()[$__tz]'), 'Y2 the platform setting validates against it');
$idx = $rd('index.php');
$p1 = strpos($idx, '$ctx  = TenantContext::fromSession();'); $p2 = strpos($idx, '$__flxTenantTz($ctx);');
$check($p1 !== false && $p2 !== false && $p2 > $p1 && $p2 - $p1 < 900, 'Y3 index.php applies the organization zone right after building the context');
$check(str_contains($idx, 'OrgService::timezoneFor($c->tenantId)') && str_contains($rd('app/src/OrgService.php'), 'SELECT timezone FROM tenants WHERE id = ?'), 'Y3 the zone is read from the organization row on every request (a change applies at once for everyone)');
foreach (['cron/sweep_expiring.php', 'app/src/ReminderService.php', 'app/src/ActivityDigestService.php'] as $f) {
    $src = $rd($f);
    $check(str_contains($src, 'app_tz_use_tenant(') && str_contains($src, 'timezone'), "Y4 $f switches to each organization's zone");
}
$sweep = $rd('cron/sweep_expiring.php');
$check(preg_match_all('/@flx_today/', $sweep) >= 4 && substr_count($sweep, 'tenant_id = ?') + substr_count($sweep, 'tenant_id = :t') >= 3, 'Y4 every statement of the sweep is limited to one organization');
$ins = $rd('install.php');
$check(str_contains($ins, 'name="app_timezone"') && str_contains($ins, "'app_timezone' => in_array("), 'Y5 the installer asks for the platform zone and stores it');
$check(!str_contains($rd('app/bootstrap.php'), "if (\$n === 'America/New_York') { \$c = config('app_timezone')"), 'Y5 config.php zone is used when the operator has chosen none (not only when the setting is Eastern)');
echo $fail ? "DATE 1.172.16: FAIL\n" : "DATE 1.172.16: ALL PASS\n";
exit($fail ? 1 : 0);
