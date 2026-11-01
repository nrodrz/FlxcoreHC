<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * v1.170.9 — Authorization regression suite. AUTHORED; REQUIRES EXTERNAL RUNTIME VALIDATION (MySQL).
 * Run ONLY through the isolated runner:  php tests/authz/run.php   (creates/drops database flx_authz)
 *
 * Model under test:  Tenant scope + Capability + Provider scope + Record ownership = authorized access.
 *
 * Fixture:  Tenant A { P1, P2, P3 }   Tenant B { B1 }
 *           A users: owner, manager, staff, restricted (assigned → P1 only), readonly, portal (linked → P2),
 *                    custom portal-only role user with NO holder (redirect-loop regression), suspended user
 * Groups:
 *   X  cross-tenant: every A user vs B1's holder / credential / document / task / calendar / case
 *   N  need-to-know in SQL: restricted user lists, finds, counts, aggregates, dashboard, work queue, iCal
 *   L  pagination/limit correctness: 60 credentials on P2 + 5 on P1, LIMIT 10 → restricted user gets ALL 5 of P1
 *   M  capability matrix (Authz::can) per role incl. documents.verify vs records.write
 *   W  scoped writes: restricted user cannot update/delete P2 rows by id through repositories
 *   P  portal: producer confined; custom portal role w/o holder → 403 not loop (guard logic); suspended user → no feed
 *   F  fail-closed: unknown user → sees nothing; ProviderScope for unknown context sees nothing
 *   D  migration 125 data scenario (custom master_admin role + users + tenant_compliance on insurance items)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use FlxCoreHC\TenantContext;
use FlxCoreHC\Authz;
use FlxCoreHC\AssignmentService;
use FlxCoreHC\ProviderScope;
use FlxCoreHC\Repository\HolderRepository;
use FlxCoreHC\Repository\CredentialRepository;
use FlxCoreHC\Repository\DocumentRepository;
use FlxCoreHC\Repository\RenewalTaskRepository;
use FlxCoreHC\Repository\CalendarRepository;
use FlxCoreHC\Repository\CredentialingCaseRepository;

$fail = false;
$check = function (bool $ok, string $label) use (&$fail): void { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };
$pdo = FlxCoreHC\Database::pdo();
$now = utc_now();
$GLOBALS['flx_tenant_status'] = 'active';

// ------------------------------------------------------------------ fixture helpers
$mkTenant = function (string $slug) use ($pdo): array {
    $auth = new FlxCoreHC\Auth\AuthService();
    $r = $auth->registerTenant("Tenant $slug Org", 'medical_group', "Owner $slug Test", "o-$slug@authz.test", 'Pass123456789', 'Pass123456789', allowPlatform: false);
    if (is_string($r)) { fwrite(STDERR, "registerTenant failed: $r\n"); exit(1); }
    $pdo->prepare("UPDATE tenants SET status='active', vertical='healthcare' WHERE id=?")->execute([$r['tenant_id']]);
    // sign-up seeds a starter organization holder; remove it so counts in the fixture are exact
    $pdo->prepare("DELETE FROM credential_holders WHERE tenant_id=?")->execute([$r['tenant_id']]);
    FlxCoreHC\Auth\AuthService::seedTemplatesFor($r['tenant_id']);
    FlxCoreHC\RoleService::seedFor($r['tenant_id']);
    return [$r['tenant_id'], $r['id']];
};
$mkUser = function (string $tid, string $role, string $status = 'active', ?string $holderId = null, string $access = 'all') use ($pdo, $now): string {
    $id = uuid4();
    $pdo->prepare('INSERT INTO users (id, tenant_id, email, password_hash, full_name, role, status, platform_admin, holder_id, provider_access, created_at) VALUES (?,?,?,?,?,?,?,0,?,?,?)')
        ->execute([$id, $tid, "u-$id@authz.test", password_hash('x', PASSWORD_DEFAULT), "User $role", $role, $status, $holderId, $access, $now]);
    return $id;
};
$ctxFor = function (string $tid, string $uid, string $role, ?string $holderId = null): TenantContext {
    return new TenantContext($tid, $uid, $role, 'T', 'healthcare', 'medical_group', 'U', false, $holderId);
};
$mkHolder = fn(TenantContext $c, string $n) => (new HolderRepository($c))->insert(['holder_type' => 'individual', 'provider_type' => 'physician', 'first_name' => 'P', 'last_name' => $n, 'vertical' => 'healthcare', 'status' => 'active']);
$mkCred = function (TenantContext $c, string $hid, string $name, ?string $expiry = null) use ($pdo, $now): array {
    $cid = (new CredentialRepository($c))->insert(['holder_id' => $hid, 'credential_type' => 'state_license', 'name' => $name, 'status' => 'active', 'expiry_date' => $expiry]);
    $did = uuid4();
    $pdo->prepare('INSERT INTO credential_documents (id, tenant_id, credential_id, file_path, original_name, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$did, $c->tenantId, $cid, "/nonexistent/$did.pdf", "$name.pdf", $now]);
    return [$cid, $did];
};

// ------------------------------------------------------------------ build fixture
[$tA, $ownerA] = $mkTenant('A');
[$tB, $ownerB] = $mkTenant('B');
$A_owner = $ctxFor($tA, $ownerA, 'tenant_owner');
$B_owner = $ctxFor($tB, $ownerB, 'tenant_owner');

$P1 = $mkHolder($A_owner, 'One'); $P2 = $mkHolder($A_owner, 'Two'); $P3 = $mkHolder($A_owner, 'Three');
$B1 = $mkHolder($B_owner, 'BeeOne');
[$c1, $d1] = $mkCred($A_owner, $P1, 'P1 License', date('Y-m-d', strtotime('+10 days')));
[$c2, $d2] = $mkCred($A_owner, $P2, 'P2 License', date('Y-m-d', strtotime('-10 days')));   // expired
[$c3, $d3] = $mkCred($A_owner, $P3, 'P3 License', date('Y-m-d', strtotime('+400 days')));
[$cb, $db] = $mkCred($B_owner, $B1, 'B1 License', date('Y-m-d', strtotime('+20 days')));
$tasksA = new RenewalTaskRepository($A_owner);
$t1 = $tasksA->insert(['credential_id' => $c1, 'holder_id' => $P1, 'title' => 'Renew P1', 'status' => 'open', 'priority' => 'normal']);
$t2 = $tasksA->insert(['credential_id' => $c2, 'holder_id' => $P2, 'title' => 'Renew P2', 'status' => 'open', 'priority' => 'normal']);
$t0 = $tasksA->insert(['credential_id' => null, 'holder_id' => null, 'title' => 'Org-wide task', 'status' => 'open', 'priority' => 'normal']);
$tb = (new RenewalTaskRepository($B_owner))->insert(['credential_id' => $cb, 'holder_id' => $B1, 'title' => 'Renew B1', 'status' => 'open', 'priority' => 'normal']);
$calA = new CalendarRepository($A_owner);
$e1 = $calA->insert(['holder_id' => $P1, 'kind' => 'task', 'source' => 'manual', 'title' => 'P1 event', 'due_at' => date('Y-m-d 10:00:00', strtotime('+3 days')), 'all_day' => 0, 'priority' => 'normal', 'status' => 'open']);
$e2 = $calA->insert(['holder_id' => $P2, 'kind' => 'task', 'source' => 'manual', 'title' => 'P2 event', 'due_at' => date('Y-m-d 10:00:00', strtotime('+3 days')), 'all_day' => 0, 'priority' => 'normal', 'status' => 'open']);
$e0 = $calA->insert(['holder_id' => null, 'kind' => 'note', 'source' => 'manual', 'title' => 'Org event', 'due_at' => date('Y-m-d 10:00:00', strtotime('+3 days')), 'all_day' => 0, 'priority' => 'normal', 'status' => 'open']);
$eb = (new CalendarRepository($B_owner))->insert(['holder_id' => $B1, 'kind' => 'task', 'source' => 'manual', 'title' => 'B1 event', 'due_at' => date('Y-m-d 10:00:00', strtotime('+3 days')), 'all_day' => 0, 'priority' => 'normal', 'status' => 'open']);
$caseSvcA = new FlxCoreHC\CredentialingCaseService($A_owner);
[, $case1] = $caseSvcA->open(['holder_id' => $P1, 'case_type' => 'initial_credentialing']);
[, $case2] = $caseSvcA->open(['holder_id' => $P2, 'case_type' => 'initial_credentialing']);
[, $caseB] = (new FlxCoreHC\CredentialingCaseService($B_owner))->open(['holder_id' => $B1, 'case_type' => 'initial_credentialing']);

$uManager    = $mkUser($tA, 'manager');
$uStaff      = $mkUser($tA, 'staff');
$uRestricted = $mkUser($tA, 'staff', 'active', null, 'assigned');
$uReadonly   = $mkUser($tA, 'read_only');
$uPortal     = $mkUser($tA, 'producer', 'active', $P2);
$uSuspended  = $mkUser($tA, 'staff', 'suspended');
AssignmentService::assign($A_owner, $P1, $uRestricted);
$A_manager = $ctxFor($tA, $uManager, 'manager');
$A_staff = $ctxFor($tA, $uStaff, 'staff');
$A_restricted = $ctxFor($tA, $uRestricted, 'staff');
$A_readonly = $ctxFor($tA, $uReadonly, 'read_only');
$A_portal = $ctxFor($tA, $uPortal, 'producer', $P2);

echo "fixture: A=$tA B=$tB P1=$P1 P2=$P2 restricted=$uRestricted\n";

// ================================================================== X — cross-tenant with valid ids
foreach (['owner' => $A_owner, 'manager' => $A_manager, 'staff' => $A_staff, 'restricted' => $A_restricted, 'readonly' => $A_readonly] as $name => $c) {
    $check((new HolderRepository($c))->find($B1) === null, "X $name: B1 holder not readable");
    $check((new CredentialRepository($c))->find($cb) === null, "X $name: B1 credential not readable");
    $check((new DocumentRepository($c))->find($db) === null, "X $name: B1 document not readable");
    $check((new RenewalTaskRepository($c))->find($tb) === null, "X $name: B1 task not readable");
    $check((new CalendarRepository($c))->find($eb) === null, "X $name: B1 calendar item not readable");
    $check((new CredentialingCaseRepository($c))->find($caseB) === null, "X $name: B1 case not readable");
    $check((new CredentialRepository($c))->update($cb, ['name' => 'hacked']) === false, "X $name: B1 credential not writable");
}
$check(FlxCoreHC\ComplianceEventService::forHolder($A_owner, $B1) === [], 'X owner: B1 compliance timeline empty');

// ================================================================== N — need-to-know in SQL
$R = $A_restricted;
$check(AssignmentService::modeFor($R) === 'assigned', 'N restricted mode resolved');
$check(ProviderScope::for($R)->restricted() && ProviderScope::for($A_staff)->restricted() === false, 'N scope restricted only for the assigned user');
$hs = array_column((new HolderRepository($R))->all(), 'id');
$check($hs === [$P1], 'N holders list = [P1] only');
$check((new HolderRepository($R))->find($P2) === null && (new HolderRepository($R))->find($P1) !== null, 'N holder find: P2 hidden, P1 visible');
$check((new HolderRepository($R))->count() === 1, 'N holder COUNT = 1 (aggregate scoped)');
$cs = array_column((new CredentialRepository($R))->all(), 'id');
$check($cs === [$c1], 'N credentials list = [c1] only');
$check((new CredentialRepository($R))->find($c2) === null, 'N credential find by VALID id of P2 → null');
$check((new CredentialRepository($R))->count() === 1, 'N credential COUNT = 1');
$sc = (new CredentialRepository($R))->statusCounts();
$check(array_sum($sc) === 1, 'N statusCounts aggregate = 1 (not 3)');
$check(count((new CredentialRepository($R))->withHolders()) === 1 && count((new CredentialRepository($R))->report()) === 1, 'N withHolders/report scoped');
$check((new DocumentRepository($R))->find($d2) === null && (new DocumentRepository($R))->find($d1) !== null, 'N document by VALID id of P2 → null; P1 doc visible');
$check(count((new DocumentRepository($R))->all()) === 1, 'N documents list = 1');
$tk = array_column((new RenewalTaskRepository($R))->openWithCredentials(), 'id');
$check(in_array($t1, $tk, true) && in_array($t0, $tk, true) && !in_array($t2, $tk, true), 'N tasks: P1 + org-wide visible, P2 hidden');
$check((new RenewalTaskRepository($R))->find($t2) === null, 'N task find by VALID id of P2 → null');
// legacy task: holder_id NULL but linked to P2's credential — must resolve through the credential
$tLegacy = $tasksA->insert(['credential_id' => $c2, 'holder_id' => null, 'title' => 'Legacy P2 task', 'status' => 'open', 'priority' => 'normal']);
$check((new RenewalTaskRepository($R))->find($tLegacy) === null && !in_array($tLegacy, array_column((new RenewalTaskRepository($R))->all(), 'id'), true), 'N legacy task (NULL holder, P2 credential) hidden from restricted user');
$check(!in_array($tLegacy, array_column((new RenewalTaskRepository($R))->openWithCredentials(), 'id'), true), 'N legacy task hidden in the joined task list too');
$check((new RenewalTaskRepository($A_staff))->find($tLegacy) !== null, 'N legacy task visible to unrestricted staff');
$ev = array_column((new CalendarRepository($R))->filtered(['from' => '2000-01-01', 'to' => '2099-01-01']), 'id');
$check(in_array($e1, $ev, true) && in_array($e0, $ev, true) && !in_array($e2, $ev, true), 'N calendar: P1 + org event visible, P2 hidden');
$dc = (new CalendarRepository($R))->dayCounts('2000-01-01', '2099-01-01');
$check(array_sum($dc) === 2, 'N calendar dayCounts aggregate = 2 (P1 + org), not 3');
$ics = (new CalendarRepository($R))->icsFeed('t');
$check(str_contains($ics, 'P1 event') && !str_contains($ics, 'P2 event'), 'N iCal feed scoped in SQL');
$check((new FlxCoreHC\Repository\ApplicationRepository($R))->allWithNames() === [] && (new FlxCoreHC\Repository\ApplicationRepository($R))->openCount() === 0, 'N applications portfolio/KPI empty for restricted user with no assigned applications');
$check((new FlxCoreHC\Repository\ScreeningRepository($R))->flaggedCount() === 0 && (new FlxCoreHC\Repository\ScreeningRepository($R))->forHolder($P2) === [], 'N screening KPI/history scoped');
$check((new CalendarRepository($R))->setStatus($e2, 'done') === false && (new CalendarRepository($R))->setStatus($e1, 'done') === true, 'W restricted cannot mark P2 calendar event done; can mark own P1 event');
$check((new CredentialingCaseRepository($R))->find($case2) === null && (new CredentialingCaseRepository($R))->find($case1) !== null, 'N case by VALID id of P2 → null');
$wq = (new FlxCoreHC\AgencyWorkQueueService($R))->build($uRestricted);
$check($wq['active_total'] === 1, 'N work queue active_total = 1 (not 2)');
$dash = (new FlxCoreHC\DashboardService($R))->build();
$check($dash['providers'] === 1 && $dash['expired'] === 0 && $dash['scoped'] === true, 'N dashboard: providers=1, expired=0 (P2 expired credential hidden), scoped flag');
$check(array_sum(array_column($dash['forecast'], 'n')) === 1, 'N dashboard forecast counts only P1');
$names = implode(' ', array_column($dash['queue'], 'who'));
$check(!str_contains($names, 'Two'), 'N dashboard queue never names P2');
$check(FlxCoreHC\ComplianceEventService::forHolder($R, $P2) === [], 'N compliance timeline of P2 empty for restricted');
$check((new FlxCoreHC\VerificationService($R))->forHolder($P2) === [], 'N verifications of P2 empty for restricted');
// unrestricted staff sees everything in the tenant
$check(count((new CredentialRepository($A_staff))->all()) === 3 && (new HolderRepository($A_staff))->count() === 3, 'N unrestricted staff unchanged (3/3)');
// assignment change takes effect immediately (per-request cache invalidated by assign())
AssignmentService::assign($A_owner, $P3, $uRestricted);
$check(count((new CredentialRepository($R))->all()) === 2, 'N assignment change reflected immediately (2 credentials)');
AssignmentService::unassign($A_owner, $P3, $uRestricted);
$check(count((new CredentialRepository($R))->all()) === 1, 'N unassign reflected immediately');

// ================================================================== L — LIMIT correctness
for ($i = 0; $i < 60; $i++) { $mkCred($A_owner, $P2, "P2 bulk $i"); }
for ($i = 0; $i < 4; $i++)  { $mkCred($A_owner, $P1, "P1 bulk $i"); }
$lim = (new CredentialRepository($R))->all([], 'created_at ASC', 10);
$check(count($lim) === 5, 'L LIMIT 10 for restricted user returns all 5 of P1 (scope applied BEFORE LIMIT), got ' . count($lim));
$check((new CredentialRepository($R))->count() === 5, 'L COUNT = 5');

// ================================================================== M — capability matrix
$m = [
  // capability            owner manager staff restricted readonly portal
  // (Authz::can() is false for EVERY capability when the effective role is read_only — by design.)
  'records.write'      => [true,  true,  true,  true,  false, false],
  'records.delete'     => [true,  true,  false, false, false, false],
  'documents.manage'   => [true,  true,  true,  true,  false, false],
  'documents.verify'   => [true,  true,  true,  true,  false, false],
  'documents.view'     => [true,  true,  true,  true,  false, true],
  'team.manage'        => [true,  true,  false, false, false, false],
  'data.export'        => [true,  true,  false, false, false, false],
  'reports.view'       => [true,  true,  true,  true,  false, false],
];
$ctxs = [$A_owner, $A_manager, $A_staff, $A_restricted, $A_readonly, $A_portal];
$cols = ['owner', 'manager', 'staff', 'restricted', 'readonly', 'portal'];
foreach ($m as $cap => $exp) {
    foreach ($ctxs as $i => $c) {
        $got = Authz::can($c, $cap, 'active');
        $check($got === $exp[$i], "M {$cols[$i]} can('$cap') = " . var_export($exp[$i], true));
    }
}
// Coordinator (slug 'agent') must NOT verify documents even though it can write records
$uCoord = $mkUser($tA, 'agent'); $A_coord = $ctxFor($tA, $uCoord, 'agent');
$check(Authz::can($A_coord, 'records.write', 'active') && !Authz::can($A_coord, 'documents.verify', 'active'), 'M coordinator: records.write yes, documents.verify NO');
// owner-level grants
$team = new FlxCoreHC\TeamService($A_manager);
[$okG] = $team->updateRole($uStaff, 'tenant_owner');
$check($okG === false, 'M manager cannot grant tenant_owner');
[$okG2] = $team->create(['full_name' => 'Evil Person', 'email' => 'evil@authz.test', 'role' => 'master_admin', 'password' => 'Pass123456789']);
$granted = $pdo->query("SELECT role FROM users WHERE email = 'evil@authz.test'")->fetchColumn();
$check($okG2 === false || ($granted !== 'master_admin' && $granted !== 'tenant_owner'), 'M manager cannot create a master_admin/tenant_owner (unknown slug degrades to a normal role; got ' . var_export($granted, true) . ')');
// a TENANT-LOCAL custom role that carries a reserved slug (the pre-125 vulnerable state) must still be un-grantable by a manager
$pdo->prepare("INSERT INTO roles (id, tenant_id, slug, name, is_system, portal_only, sort_order, created_at) VALUES (?,?,?,?,0,0,100,?)")->execute([uuid4(), $tA, 'master_admin', 'Legacy reserved-slug role', $now]);
[$okG3] = $team->create(['full_name' => 'Evil Person', 'email' => 'evil3@authz.test', 'role' => 'master_admin', 'password' => 'Pass123456789']);
$check($okG3 === false, 'M manager cannot grant a tenant-local reserved-slug role (actorMayGrant)');
$pdo->prepare("DELETE FROM roles WHERE tenant_id = ? AND slug = 'master_admin'")->execute([$tA]);
$rs = new FlxCoreHC\RoleService($A_owner);
[$okR, , $rid1] = $rs->create('Master Admin', []);
$slugMA = $okR ? (string)$pdo->query("SELECT slug FROM roles WHERE id = " . $pdo->quote((string)$rid1))->fetchColumn() : '';
$check($okR === false || !FlxCoreHC\RoleService::isReservedOwnerSlug($slugMA), 'M custom role named "Master Admin" never gets a reserved slug (got "' . $slugMA . '")');

// ================================================================== W — scoped writes
$check((new CredentialRepository($R))->update($c2, ['name' => 'tampered']) === false, 'W restricted cannot UPDATE P2 credential by id');
$check((new CredentialRepository($R))->delete($c2) === false, 'W restricted cannot DELETE P2 credential by id');
$check((new HolderRepository($R))->update($P2, ['first_name' => 'X']) === false, 'W restricted cannot UPDATE P2 holder');
$check((new CredentialRepository($R))->update($c1, ['name' => 'P1 License (renamed)']) === true, 'W restricted CAN update own assigned P1 credential');
$row = $pdo->query("SELECT name FROM credentials WHERE id = " . $pdo->quote($c2))->fetchColumn();
$check($row === 'P2 License', 'W P2 credential untouched in DB');

// ================================================================== P — portal
$check((new CredentialRepository($A_portal))->find($c2) !== null, 'P portal user sees own credential (provider_access=all, ownership enforced by routes)');
$check(FlxCoreHC\RoleService::isPortalOnly($tA, 'producer') === true, 'P producer is portal-only');
[$okC, $msgC, ] = (new FlxCoreHC\TeamService($A_owner))->create(['full_name' => 'No Holder', 'email' => 'nh@authz.test', 'role' => 'producer', 'password' => 'Pass123456789']);
$check($okC === false, 'P cannot create a portal-only login without a provider record (' . $msgC . ')');
// custom portal-only role: same rule
[$okRole, , $rid2] = $rs->create('Field Portal', ['documents.view'], true);
$slug = $okRole ? (string)$pdo->query("SELECT slug FROM roles WHERE id = " . $pdo->quote((string)$rid2))->fetchColumn() : '';
$check($okRole === true && FlxCoreHC\RoleService::isPortalOnly($tA, (string)$slug), 'P custom portal-only role created');
[$okC2] = (new FlxCoreHC\TeamService($A_owner))->create(['full_name' => 'No Holder', 'email' => 'nh2@authz.test', 'role' => (string)$slug, 'password' => 'Pass123456789']);
$check($okC2 === false, 'P custom portal-only role also requires a provider record (redirect-loop regression)');
// suspended user: iCal token must not resolve
$pdo->prepare("UPDATE users SET ical_token = ? WHERE id = ?")->execute([str_repeat('a', 40), $uSuspended]);
$st = $pdo->prepare("SELECT 1 FROM users u JOIN tenants t ON t.id = u.tenant_id WHERE u.ical_token = ? AND u.status = 'active' AND t.status IN ('active','pending')");
$st->execute([str_repeat('a', 40)]);
$check($st->fetchColumn() === false, 'P suspended user iCal token does not resolve (route predicate)');

// ================================================================== F — fail closed
$ghost = $ctxFor($tA, uuid4(), 'staff');
$check(AssignmentService::modeFor($ghost) === 'assigned' && (new CredentialRepository($ghost))->all() === [], 'F unknown user id → sees nothing');
$check((new CredentialRepository($ghost))->count() === 0, 'F unknown user COUNT = 0');
$lv = FlxCoreHC\ComplianceLevelService::evaluate($A_owner, (new HolderRepository($A_owner))->find($P1), (new CredentialRepository($A_owner))->all(['holder_id' => $P1]), [], []);
$check(isset($lv['level']), 'F compliance evaluation runs (screening lookup healthy)');

// ================================================================== D — migration 125 data scenario
$rid = uuid4();
$pdo->prepare("INSERT INTO roles (id, tenant_id, slug, name, is_system, portal_only, sort_order, created_at) VALUES (?,?,?,?,0,0,100,?)")->execute([$rid, $tA, 'master_admin', 'Master Admin (custom)', $now]);
$uEvil = $mkUser($tA, 'master_admin');
$check(FlxCoreHC\RoleService::capabilitiesForSlug($tA, 'master_admin') === FlxCoreHC\Capabilities::all(), 'D (pre-125) custom master_admin slug resolves to ALL caps — the vulnerability 125 fixes');
$itemId = $pdo->query("SELECT id FROM compliance_items WHERE applies_to='insurance' LIMIT 1")->fetchColumn();
if ($itemId === false) {   // fresh install already ran 125 → recreate the legacy state to prove FK-safety
    $itemId = uuid4();
    $pdo->prepare("INSERT INTO compliance_items (id, tenant_id, applies_to, category, code, title, description, sort_order, created_at) VALUES (?,NULL,'insurance','policy','legacy_test','Legacy','x',999,?)")->execute([$itemId, $now]);
}
$pdo->prepare("INSERT INTO tenant_compliance (id, tenant_id, item_id, status, created_at) VALUES (?,?,?,'done',?)")->execute([uuid4(), $tA, $itemId, $now]);
$sql = preg_replace('/^\s*--.*$/m', '', file_get_contents(dirname(__DIR__, 2) . '/database/migrations/125_healthcare_cleanup.sql'));
$errs = 0;
foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $stmt) { try { $pdo->exec($stmt); } catch (\Throwable $e) { $errs++; echo "   125 error: " . $e->getMessage() . "\n"; } }
$check($errs === 0, 'D migration 125 applies with children present (FK-safe)');
$check((int)$pdo->query("SELECT COUNT(*) FROM compliance_items WHERE applies_to='insurance'")->fetchColumn() === 0, 'D insurance compliance items removed');
$newSlug = (string)$pdo->query("SELECT slug FROM roles WHERE id = " . $pdo->quote($rid))->fetchColumn();
$check(str_starts_with($newSlug, 'master_admin_role_'), "D custom reserved slug renamed (collision-safe, even though 'master_admin_role' already exists in this tenant): $newSlug");
$check($pdo->query("SELECT role FROM users WHERE id = " . $pdo->quote($uEvil))->fetchColumn() === $newSlug, 'D user moved to renamed slug (not orphaned)');
$check(FlxCoreHC\RoleService::capabilitiesForSlug($tA, $newSlug) !== FlxCoreHC\Capabilities::all(), 'D renamed role no longer all-caps');
$check((int)$pdo->query("SELECT COUNT(*) FROM credentials WHERE tenant_id = " . $pdo->quote($tA))->fetchColumn() === 67, 'D credential rows intact after 125 (67)');

echo "\n" . ($fail ? "SOME FAILED\n" : "ALL PASS\n");
return $fail ? 1 : 0;
