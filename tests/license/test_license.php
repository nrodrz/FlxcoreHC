<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * v1.175.0 — licensing. Run ONLY through  php tests/license/run.php  (creates/drops database flx_license).
 * L1 signing: a record verifies; any change to a signed field, a foreign key id or a copied record does not
 * L2 entitlement states: internal · trial (all modules) · core (none) · licensed · grace · not yet started · revoked · altered · expired
 * L3 the user limit and the feature check follow the entitlement
 * L4 issuing validates its input, numbers serials, is audited; revoking works once
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use FlxCoreHC\TenantContext;
use FlxCoreHC\LicenseService as L;
use FlxCoreHC\Entitlement as E;

$fail = false;
$check = function (bool $ok, string $label) use (&$fail): void { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };
if (!L::available()) { echo "SKIP — libsodium not available\n"; return 3; }
$pdo = FlxCoreHC\Database::pdo(); $now = utc_now();
$u = fn() => sprintf('%08x-%04x-4%03x-8%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff));
$mk = function (string $name, string $shape, int $ageDays, int $demo = 0) use ($pdo, $u) {
    $id = $u();
    $pdo->prepare("INSERT INTO tenants (id, name, vertical, org_shape, status, is_demo, created_at) VALUES (?,?,?,?,?,?,?)")
        ->execute([$id, $name, 'healthcare', $shape, 'active', $demo, gmdate('Y-m-d H:i:s', time() - $ageDays * 86400)]);
    return $id;
};
$ctx = fn(string $t) => new TenantContext($t, $u(), 'tenant_owner', 'Op', 'healthcare', 'platform', 'Operator', true);
$tPlat = $mk('Platform Org', 'platform', 400); $tNew = $mk('New Org', 'medical_group', 3); $tOld = $mk('Old Org', 'medical_group', 45); $tDemo = $mk('Demo Org', 'medical_group', 400, 1);
$op = $ctx($tPlat);
$state = function (string $t) { E::reset(); return E::forTenant($t); };
$days = fn(int $n) => gmdate('Y-m-d', strtotime(today_local() . ($n >= 0 ? ' +' : ' ') . $n . ' days'));
$all = array_keys(L::MODULES);

// ---- L2 (no license yet) ----
$s = $state($tPlat); $check($s['state'] === 'internal' && $s['modules'] === $all, 'L2 the platform operator\'s organization is internal: every module, no end');
$s = $state($tDemo); $check($s['state'] === 'internal', 'L2 a demo organization is internal');
$s = $state($tNew); $check($s['state'] === 'trial' && $s['modules'] === $all && $s['days_left'] >= 26 && $s['days_left'] <= 28, 'L2 a 3-day-old organization is in its trial with every module (' . $s['days_left'] . ' days left)');
$s = $state($tOld); $check($s['state'] === 'core' && $s['modules'] === [], 'L2 a 45-day-old organization with no license is on the core only — no optional module');
$check(FlxCoreHC\PlanService::featuresFor($tOld) === [] && FlxCoreHC\PlanService::tenantHasFeature($tNew, 'analytics') === true && FlxCoreHC\PlanService::tenantHasFeature($tOld, 'analytics') === false, 'L3 the feature check (PlanService) follows the entitlement');

// ---- L4 issue ----
[$ok, $m] = L::issue($op, ['tenant_id' => $tOld, 'kind' => 'bogus', 'term_start' => $days(0)]); $check(!$ok, 'L4 an unknown kind is refused');
[$ok, $m] = L::issue($op, ['tenant_id' => $tOld, 'kind' => 'paid', 'term_start' => 'x']); $check(!$ok, 'L4 a bad start date is refused');
[$ok, $m] = L::issue($op, ['tenant_id' => $tOld, 'kind' => 'paid', 'term_start' => $days(0), 'term_end' => $days(-5)]); $check(!$ok, 'L4 an end before the start is refused');
[$ok, $m] = L::issue($op, ['tenant_id' => $tOld, 'kind' => 'paid', 'term_start' => $days(0), 'users' => '0']); $check(!$ok, 'L4 a user limit below 1 is refused');
[$ok, $m] = L::issue($op, ['tenant_id' => 'nope', 'kind' => 'paid', 'term_start' => $days(0)]); $check(!$ok, 'L4 an unknown organization is refused');
$n0 = (int) $pdo->query('SELECT COUNT(*) FROM tenant_licenses')->fetchColumn(); $check($n0 === 0, 'L4 none of the refused requests wrote a row');
[$ok, $serial] = L::issue($op, ['tenant_id' => $tOld, 'kind' => 'paid', 'plan' => 'Agency', 'modules' => ['documents', 'bogus'], 'users' => '3', 'term_start' => $days(-10), 'term_end' => $days(20), 'agreement' => 'AGR-1', 'notes' => 'first']);
$check($ok && preg_match('/^LIC-\d{4}-0001$/', $serial) === 1, "L4 a valid license is issued with a serial ($serial)");
$check((int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'license_issued'")->fetchColumn() === 1, 'L4 issuing is audited');
$row = $pdo->query("SELECT * FROM tenant_licenses WHERE serial = '$serial'")->fetch();
$check($row['modules'] === 'documents' && (int) $row['users'] === 3 && $row['workspace_id'] === FlxCoreHC\OrgService::workspaceId($tOld) && $row['deployment'] === 'hosted', 'L4 unknown modules are dropped; the record carries the workspace ID and deployment');

// ---- L1 ----
$check(L::verify($row), 'L1 the record verifies');
foreach (['modules' => 'documents,analytics', 'users' => 99, 'term_end' => $days(900), 'kind' => 'free', 'licensee' => 'Someone Else', 'serial' => 'LIC-2026-0002', 'notes' => 'x', 'plan' => 'Other', 'agreement' => 'AGR-2', 'workspace_id' => 'AAAA-AAAA-AAAA-AAAA'] as $f => $v) {
    $check(!L::verify([$f => $v] + $row), "L1 changing the signed field \"$f\" breaks the signature");
}
$check(!L::verify(['key_id' => '0000000000000000'] + $row) && !L::verify(['signature' => base64_encode(random_bytes(64))] + $row) && !L::verify(['signature' => ''] + $row), 'L1 a foreign key id, a random signature and an empty signature do not verify');
$check(L::verify(['revoked_at' => $now, 'issued_by' => 'x'] + $row), 'L1 fields that are not signed (revocation, issuer) do not affect the signature');

// ---- L2 licensed ----
$s = $state($tOld);
$check($s['state'] === 'licensed' && $s['modules'] === ['documents'] && $s['users'] === 3 && $s['serial'] === $serial && $s['agreement'] === 'AGR-1' && $s['days_left'] === 20, 'L2 a valid license switches on exactly its modules, user limit and term');
$check(FlxCoreHC\PlanService::seatLimit($tOld) === 3 && FlxCoreHC\PlanService::featuresFor($tOld) === ['documents'] && !FlxCoreHC\PlanService::tenantHasFeature($tOld, 'analytics'), 'L3 the user limit and features follow the license');
// another organization cannot use this record: copy the row to the new organization
$pdo->prepare('UPDATE tenant_licenses SET tenant_id = ? WHERE serial = ?')->execute([$tNew, $serial]);
$s = $state($tNew); $check($s['tampered'] === true && $s['state'] === 'trial' && $s['serial'] === null, 'L2 a record moved to another organization does not count (its signed workspace ID is another\'s) — the trial rules apply');
$pdo->prepare('UPDATE tenant_licenses SET tenant_id = ? WHERE serial = ?')->execute([$tOld, $serial]);
// altered
$pdo->prepare("UPDATE tenant_licenses SET modules = 'documents,scheduling,analytics,network' WHERE serial = ?")->execute([$serial]);
$s = $state($tOld); $check($s['tampered'] === true && $s['state'] === 'core' && $s['modules'] === [], 'L2 a record changed in the database is ignored (core only, flagged as altered)');
$pdo->prepare("UPDATE tenant_licenses SET modules = 'documents' WHERE serial = ?")->execute([$serial]);
$check($state($tOld)['state'] === 'licensed', 'L2 put back as signed it counts again');
// term edges: re-issue with other dates (newest valid record wins)
$edge = function (string $label, array $dates, string $want) use ($op, $tOld, $state, $check, $pdo) {
    $pdo->exec('DELETE FROM tenant_licenses'); \FlxCoreHC\Entitlement::reset();
    [$ok, $sr] = L::issue($op, ['tenant_id' => $tOld, 'kind' => 'paid', 'modules' => ['scheduling'], 'term_start' => $dates[0], 'term_end' => $dates[1]]);
    $got = $state($tOld)['state']; $check($ok && $got === $want, "L2 $label → $want (got $got)");
};
$edge('term ending today', [$days(-30), $days(0)], 'licensed');
$edge('term ended yesterday', [$days(-30), $days(-1)], 'grace');
$edge('term ended 14 days ago (last day of grace)', [$days(-60), $days(-14)], 'grace');
$edge('term ended 15 days ago', [$days(-60), $days(-15)], 'core');
$edge('term not started yet', [$days(5), $days(300)], 'core');
$edge('no end date', [$days(-5), ''], 'licensed');
$pdo->exec('DELETE FROM tenant_licenses'); E::reset();
[$ok, $sr] = L::issue($op, ['tenant_id' => $tOld, 'kind' => 'free', 'modules' => ['analytics'], 'term_start' => $days(-5), 'term_end' => $days(100)]);
[$ok2, $sr2] = L::issue($op, ['tenant_id' => $tOld, 'kind' => 'paid', 'modules' => ['documents', 'scheduling'], 'term_start' => $days(-1), 'term_end' => $days(365)]);
$s = $state($tOld); $check($ok && $ok2 && $s['serial'] === $sr2 && $s['modules'] === ['documents', 'scheduling'], 'L2 a renewal (a newer record) takes over; the older one stays in the history (' . count(L::history($tOld)) . ' rows)');
// revoke
[$okR, $mR] = L::revoke($op, $sr2); $s = $state($tOld);
$check($okR && $s['serial'] === $sr && $s['modules'] === ['analytics'], 'L4 revoking the newer license leaves the older one in force');
L::revoke($op, $sr); $check($state($tOld)['state'] === 'core', 'L4 revoking every license leaves no license: the trial is long over, so the core only');
[$okR2] = L::revoke($op, $sr); $check(!$okR2, 'L4 a revoked license cannot be revoked again');
$check((int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'license_revoked'")->fetchColumn() === 2, 'L4 each revocation is audited');
// a new organization revoked mid-trial goes back to its trial
[$okN, $sn] = L::issue($op, ['tenant_id' => $tNew, 'kind' => 'pilot', 'modules' => ['documents'], 'term_start' => $days(-1), 'term_end' => $days(30)]);
$check($state($tNew)['state'] === 'licensed', 'L2 a license overrides the trial');
L::revoke($op, $sn); $check($state($tNew)['state'] === 'trial', 'L2 revoked during its trial: the trial continues until its date');
// keys
$check(L::publicKey() !== '' && L::keyId(L::publicKey()) === $row['key_id'], 'L1 the key pair was created on first use and the records carry its id');
$blob = base64_decode((string) FlxCoreHC\SettingsService::get('license_signing_key'), true);
$plain = $blob === false ? null : FlxCoreHC\Crypto::decrypt($blob, 'license-signing-key');
$check($blob !== false && $plain !== null && strlen(base64_decode((string) $plain)) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES && $blob !== base64_decode((string) $plain), 'L1 the signing key is stored sealed: only Crypto can turn the stored value into the 64-byte secret key');
return $fail ? 1 : 0;
