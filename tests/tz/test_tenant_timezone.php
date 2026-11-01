<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * v1.172.16 — each organization has its own time zone. Run ONLY through  php tests/tz/run.php  (creates/drops database flx_tz).
 * Fixture: three organizations — Puerto Rico (AST, no daylight saving), Honolulu, and one with NO zone of its own (follows the platform: Eastern).
 * Z1 settings: OrgService saves a valid zone, turns an unknown/blank one into NULL (= platform zone)
 * Z2 the same instant shows a different clock time / day in each organization (and Puerto Rico vs Eastern differ in winter, match in summer)
 * Z3 the nightly sweep uses EACH organization's own day: a credential expiring on that organization's today is "expiring", its yesterday is "expired" —
 *    including dates that are today in ANOTHER organization's zone (right in one, already past in the other, whenever the two days differ)
 * Z4 @flx_today follows the organization chosen, and goes back to the platform's when none is
 * Z5 the platform zone (config.php here) is what an organization without a zone follows
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use FlxCoreHC\TenantContext;
use FlxCoreHC\OrgService;

$fail = false;
$check = function (bool $ok, string $label) use (&$fail): void { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };
$pdo = FlxCoreHC\Database::pdo();
$now = utc_now();
$u = fn() => sprintf('%08x-%04x-4%03x-8%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff));

$orgs = ['PR' => 'America/Puerto_Rico', 'HI' => 'Pacific/Honolulu', 'DEF' => null];
$T = []; $H = [];
foreach ($orgs as $k => $z) {
    $T[$k] = $u(); $H[$k] = $u();
    $pdo->prepare("INSERT INTO tenants (id, name, vertical, org_shape, status, timezone, created_at) VALUES (?,?,?,?,?,?,?)")->execute([$T[$k], "TZ $k", 'healthcare', 'medical_group', 'active', $z, $now]);
    $pdo->prepare("INSERT INTO credential_holders (id, tenant_id, holder_type, first_name, last_name, vertical, status, created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([$H[$k], $T[$k], 'individual', 'Pat', $k, 'healthcare', 'active', $now]);
}
$zoneOf = fn(string $k): string => $orgs[$k] ?? 'America/New_York';
$dayIn = fn(string $zone, int $plus = 0): string => (new DateTimeImmutable('now', new DateTimeZone($zone)))->modify(($plus >= 0 ? '+' : '') . $plus . ' days')->format('Y-m-d');
$ctxFor = fn(string $k) => new TenantContext($T[$k], $u(), 'tenant_owner', "TZ $k", 'healthcare', 'medical_group', 'Tester', false);

// ---- Z1 ----
(new OrgService($ctxFor('DEF')))->updateInfo(['timezone' => 'America/Chicago']);
$check($pdo->query("SELECT timezone FROM tenants WHERE id = '{$T['DEF']}'")->fetchColumn() === 'America/Chicago', 'Z1 a valid zone is saved');
(new OrgService($ctxFor('DEF')))->updateInfo(['timezone' => 'Mars/Olympus']);
$check($pdo->query("SELECT timezone FROM tenants WHERE id = '{$T['DEF']}'")->fetchColumn() === null, 'Z1 an unknown zone becomes NULL (platform zone)');
(new OrgService($ctxFor('PR')))->updateInfo(['name' => 'TZ PR']);
$check($pdo->query("SELECT timezone FROM tenants WHERE id = '{$T['PR']}'")->fetchColumn() === 'America/Puerto_Rico', 'Z1 a form that does not send the zone leaves it alone');

// ---- Z2 ----
$zoneNow = fn(string $k) => app_tz_use_tenant($orgs[$k]);
$zoneNow('PR');  $prWinter = fmt_dt('2027-01-15 03:30:00', 'Y-m-d H:i'); $prSummer = fmt_dt('2026-07-15 03:30:00', 'Y-m-d H:i');
$zoneNow('HI');  $hiWinter = fmt_dt('2027-01-15 03:30:00', 'Y-m-d H:i');
$zoneNow('DEF'); $defWinter = fmt_dt('2027-01-15 03:30:00', 'Y-m-d H:i'); $defSummer = fmt_dt('2026-07-15 03:30:00', 'Y-m-d H:i');
$check($prWinter === '2027-01-14 23:30', "Z2 Puerto Rico shows 03:30 UTC as 11:30 PM the day before (got $prWinter)");
$check($hiWinter === '2027-01-14 17:30', "Z2 Honolulu shows it as 5:30 PM the day before (got $hiWinter)");
$check($defWinter === '2027-01-14 22:30', "Z2 an organization with no zone follows the platform (Eastern): 10:30 PM (got $defWinter)");
$check($prWinter !== $defWinter, 'Z2 in winter Puerto Rico and Florida differ by an hour');
$check($prSummer === $defSummer, 'Z2 in summer Puerto Rico and Florida show the same clock time');
$check(local_to_utc('2027-01-14 23:30:00') === '2027-01-15 04:30:00', 'Z2 (platform zone in force again) a typed time converts with Eastern');
$zoneNow('PR');
$check(local_to_utc('2027-01-14 23:30:00') === '2027-01-15 03:30:00', 'Z2 a time typed by a Puerto Rico user converts with Puerto Rico');
app_tz_use_tenant(null);

// ---- Z3 ----
$cred = function (string $k, string $name, string $date) use ($pdo, $T, $H, $u, $now) {
    $pdo->prepare("INSERT INTO credentials (id, tenant_id, holder_id, credential_type, name, status, expiry_date, created_at) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$u(), $T[$k], $H[$k], 'state_license', $name, 'active', $date, $now]);
};
$expect = [];   // [org][name] => expected status
$days = [];
foreach (array_keys($orgs) as $k) foreach (array_keys($orgs) as $j) { $days[$dayIn($zoneOf($j), 0)] = true; }
$days[$dayIn('UTC', -2)] = true; $days[$dayIn('UTC', 2)] = true;
foreach (array_keys($orgs) as $k) {
    $myToday = $dayIn($zoneOf($k), 0);
    foreach (array_keys($days) as $d) {
        $name = "exp $d";
        $cred($k, $name, $d);
        $expect[$k][$name] = $d < $myToday ? 'expired' : 'expiring';
    }
}
$env = ['FLX_CONFIG' => getenv('FLX_CONFIG')] + $_ENV;
$out = []; exec('cd ' . escapeshellarg(dirname(__DIR__, 2)) . ' && FLX_CONFIG=' . escapeshellarg((string) getenv('FLX_CONFIG')) . ' php cron/sweep_expiring.php 2>&1', $out, $rc);
$check($rc === 0, 'Z3 the sweep runs (' . implode(' | ', $out) . ')');
foreach (array_keys($orgs) as $k) {
    $rows = $pdo->query("SELECT name, status FROM credentials WHERE tenant_id = '{$T[$k]}'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $bad = [];
    foreach ($expect[$k] as $name => $st) if (($rows[$name] ?? null) !== $st) $bad[] = "$name: " . ($rows[$name] ?? 'none') . " (wanted $st)";
    $check($bad === [], "Z3 organization $k (" . $zoneOf($k) . ', today ' . $dayIn($zoneOf($k)) . '): every credential has the status its own day demands' . ($bad ? ' — ' . implode('; ', $bad) : ''));
}

// ---- Z4 ----
app_tz_use_tenant('Pacific/Honolulu');
$check((string) $pdo->query('SELECT @flx_today')->fetchColumn() === $dayIn('Pacific/Honolulu'), 'Z4 @flx_today is the Honolulu organization\'s day');
app_tz_use_tenant('America/Puerto_Rico');
$check((string) $pdo->query('SELECT @flx_today')->fetchColumn() === $dayIn('America/Puerto_Rico'), 'Z4 @flx_today moves to the Puerto Rico organization\'s day');
app_tz_use_tenant(null);
$check((string) $pdo->query('SELECT @flx_today')->fetchColumn() === $dayIn('America/New_York'), 'Z4 and back to the platform zone (Eastern) when no organization is chosen');

// ---- Z5 ----
$check(app_tz_platform_name() === 'America/New_York', 'Z5 the platform zone comes from config.php when the operator has not chosen one');
return $fail ? 1 : 0;
