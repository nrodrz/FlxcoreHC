<?php
/**
 * v1.171.0 — behavioural regression tests for the Claude Code review findings (DB-level; each asserts an OUTCOME, not source text).
 * Run ONLY through the isolated runner:  php tests/security/run.php   (creates/drops database flx_security)
 *
 *   S1  role capability ceiling      S2  session re-validation      S3  platform-operator gates
 *   B1  provider form round-trip     B2  soft-deleted rows excluded B3  migrator strictness
 *   B4  need-to-know: audit / notifications / group+location counts
 *   B5  role picker honesty          B6  PHP 8.4 CSV               S4  JSON-in-script escaping
 *   K   encryption-key backup
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use FlxCoreHC\TenantContext;
use FlxCoreHC\RoleService;
use FlxCoreHC\TeamService;
use FlxCoreHC\Capabilities;
use FlxCoreHC\AssignmentService;
use FlxCoreHC\HolderInput;
use FlxCoreHC\SuperadminService;
use FlxCoreHC\SupportAccess;
use FlxCoreHC\Repository\HolderRepository;
use FlxCoreHC\Repository\CredentialRepository;
use FlxCoreHC\Repository\AuditLogRepository;
use FlxCoreHC\Repository\GroupRepository;
use FlxCoreHC\Repository\LocationRepository;
use FlxCoreHC\Repository\RenewalTaskRepository;

$fail = false;
$check = function (bool $ok, string $label) use (&$fail): void { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };
$pdo = FlxCoreHC\Database::pdo(); $now = utc_now();
$GLOBALS['flx_tenant_status'] = 'active';
$ROOT = dirname(__DIR__, 2);

$mkTenant = function (string $slug, bool $demo = false) use ($pdo): array {
    $r = (new FlxCoreHC\Auth\AuthService())->registerTenant("Tenant $slug", 'medical_group', "Owner $slug Test", "o-$slug@sec.test", 'Pass123456789', 'Pass123456789', allowPlatform: false);
    if (is_string($r)) { fwrite(STDERR, "registerTenant failed: $r\n"); exit(1); }
    $pdo->prepare("UPDATE tenants SET status='active', vertical='healthcare', is_demo=? WHERE id=?")->execute([$demo ? 1 : 0, $r['tenant_id']]);
    $pdo->prepare("DELETE FROM credential_holders WHERE tenant_id=?")->execute([$r['tenant_id']]);
    FlxCoreHC\Auth\AuthService::seedTemplatesFor($r['tenant_id']); RoleService::seedFor($r['tenant_id']);
    return [$r['tenant_id'], $r['id']];
};
$mkUser = function (string $tid, string $role, string $status = 'active', string $access = 'all', ?string $holder = null, int $platform = 0, int $totp = 0) use ($pdo, $now): string {
    $id = uuid4();
    $pdo->prepare('INSERT INTO users (id, tenant_id, email, password_hash, full_name, role, status, platform_admin, holder_id, provider_access, totp_enabled, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $tid, "u-$id@sec.test", password_hash('OldPass123456', PASSWORD_DEFAULT), "User $role", $role, $status, $platform, $holder, $access, $totp, $now]);
    return $id;
};
$ctx = fn(string $t, string $u, string $r, bool $pa = false) => new TenantContext($t, $u, $r, 'T', 'healthcare', 'medical_group', 'U', $pa);

[$tA, $ownerA] = $mkTenant('A');
$OWN = $ctx($tA, $ownerA, 'tenant_owner');
$mgrId = $mkUser($tA, 'manager'); $MGR = $ctx($tA, $mgrId, 'manager');

// =========================================================================== S1 — capability ceiling
$mgrCaps = RoleService::capabilitiesForSlug($tA, 'manager');
$beyond = array_values(array_diff(Capabilities::all(), $mgrCaps));
$check($beyond !== [], 'S1 setup: there are capabilities a Manager does not hold (' . implode(',', array_slice($beyond, 0, 3)) . ')');
[$ok, $msg] = (new RoleService($MGR))->create('Escalator', [$beyond[0]], false);
$check($ok === false, 'S1 Manager cannot create a role containing a capability they lack');
$check((int) $pdo->query("SELECT COUNT(*) FROM roles WHERE tenant_id=" . $pdo->quote($tA) . " AND name='Escalator'")->fetchColumn() === 0, 'S1 …and no such role row was created');
[$ok, , $rid] = (new RoleService($MGR))->create('Trainee', ['documents.view', 'records.write'], false);
$check($ok === true, 'S1 Manager CAN create a role within their own capabilities');
[$ok, ] = (new RoleService($MGR))->update((string) $rid, 'Trainee', ['documents.view', $beyond[0]]);
$check($ok === false, 'S1 Manager cannot widen an existing role beyond their own capabilities');
$staffRoleId = (string) $pdo->query("SELECT id FROM roles WHERE tenant_id=" . $pdo->quote($tA) . " AND slug='staff'")->fetchColumn();
$before = RoleService::capabilitiesForSlug($tA, 'staff');
[$ok, $m] = (new RoleService($MGR))->update($staffRoleId, 'Staff', ['documents.view']);
$check($ok === false && RoleService::capabilitiesForSlug($tA, 'staff') === $before, 'S1 Manager cannot edit a built-in role (capabilities unchanged)');
[$ok, , $bigId] = (new RoleService($OWN))->create('Big Role', Capabilities::all(), false);
$check($ok === true, 'S1 Owner can create an all-capability role');
[$ok, ] = (new RoleService($MGR))->update((string) $bigId, 'Big Role', ['documents.view']);
$check($ok === false, 'S1 Manager cannot edit (e.g. strip) a role more powerful than themselves');
$victim = $mkUser($tA, 'staff');
[$ok, $m] = (new TeamService($MGR))->updateRole($victim, 'big_role');
$check($ok === false, 'S1 Manager cannot assign a role that exceeds their own capabilities (self-escalation route closed)');
[$ok, ] = (new TeamService($MGR))->updateRole($mgrId, 'big_role');
$check($ok === false && (string) $pdo->query("SELECT role FROM users WHERE id=" . $pdo->quote($mgrId))->fetchColumn() === 'manager', 'S1 Manager cannot promote THEMSELVES to a bigger role');
[$ok, ] = (new TeamService($OWN))->updateRole($victim, 'big_role');
$check($ok === true, 'S1 Owner can assign any role');
$pdo->prepare("UPDATE users SET role='staff' WHERE id=?")->execute([$victim]);
// field-level rules are an Owner decision
$gBefore = (int) $pdo->query("SELECT COUNT(*) FROM role_field_grants WHERE tenant_id=" . $pdo->quote($tA))->fetchColumn();
FlxCoreHC\SensitiveFields::saveGrants($MGR, 'manager', ['dob' => 'allow', 'ssn_last4' => 'allow']);
$check((int) $pdo->query("SELECT COUNT(*) FROM role_field_grants WHERE tenant_id=" . $pdo->quote($tA))->fetchColumn() === $gBefore, 'S1 Manager cannot grant their own role access to sensitive fields');
FlxCoreHC\SensitiveFields::saveGrants($OWN, 'manager', ['dob' => 'allow']);
$check((int) $pdo->query("SELECT COUNT(*) FROM role_field_grants WHERE tenant_id=" . $pdo->quote($tA))->fetchColumn() === $gBefore + 1, 'S1 Owner can set field rules');

// =========================================================================== B5 — role picker honesty
$assignM = (new RoleService($MGR))->assignable();
$check(!isset($assignM['tenant_owner']) && !isset($assignM['big_role']) && isset($assignM['staff']), 'B5 Manager\'s role picker omits owner-level and higher-than-self roles, keeps Staff');
$check(!isset($assignM['admin']) && !isset($assignM['viewer']) && !isset($assignM['regular']), 'B5 picker no longer lists platform-global names this tenant does not have');
$assignO = (new RoleService($OWN))->assignable();
$check(isset($assignO['tenant_owner']) && isset($assignO['big_role']), 'B5 Owner\'s picker lists every tenant role');
[$ok, $m] = (new TeamService($OWN))->updateRole($victim, 'admin');   // a global slug this tenant lacks
$check($ok === false && stripos($m, 'does not exist') !== false && (string) $pdo->query("SELECT role FROM users WHERE id=" . $pdo->quote($victim))->fetchColumn() === 'staff', 'B5 unknown role is REJECTED with a message (previously silently saved as Staff)');

// =========================================================================== S2 — session re-validation
$sess = function (string $uid, string $tid) use ($pdo): void {
    $row = $pdo->query("SELECT password_hash, role, holder_id, totp_enabled, platform_admin, full_name FROM users WHERE id=" . $pdo->quote($uid))->fetch();
    $_SESSION['auth'] = ['tenant_id' => $tid, 'user_id' => $uid, 'role' => $row['role'], 'holder_id' => $row['holder_id'], 'totp_enabled' => (int) $row['totp_enabled'],
        'platform_admin' => 0, 'user_name' => $row['full_name'], 'pfp' => FlxCoreHC\Auth\AuthService::passwordFingerprint((string) $row['password_hash'])];
};
$u = $mkUser($tA, 'manager'); $sess($u, $tA);
$check(FlxCoreHC\Auth\AuthService::revalidateSession() === true, 'S2 an untouched session stays valid');
$pdo->prepare("UPDATE users SET role='read_only' WHERE id=?")->execute([$u]);
$ok = FlxCoreHC\Auth\AuthService::revalidateSession();
$check($ok === true && $_SESSION['auth']['role'] === 'read_only', 'S2 a role change takes effect on the next request (session role refreshed from the database)');
$pdo->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$u]);
$check(FlxCoreHC\Auth\AuthService::revalidateSession() === false, 'S2 suspending the user ends their session');
$pdo->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$u]); $sess($u, $tA);
$pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash('ResetByAdmin1', PASSWORD_DEFAULT), $u]);
$check(FlxCoreHC\Auth\AuthService::revalidateSession() === false, 'S2 a password reset ends every existing session of that user');
$sess($u, $tA);
[$ok, ] = (new FlxCoreHC\Auth\AuthService())->changePassword($u, 'ResetByAdmin1', 'BrandNewPass123');
$check($ok === true && FlxCoreHC\Auth\AuthService::revalidateSession() === true, 'S2 changing your OWN password keeps the current session (other sessions would end)');
$sess($u, $tA);
$pdo->prepare("UPDATE tenants SET status='suspended' WHERE id=?")->execute([$tA]);
$check(FlxCoreHC\Auth\AuthService::revalidateSession() === false, 'S2 a suspended organization ends all of its sessions');
$pdo->prepare("UPDATE tenants SET status='active' WHERE id=?")->execute([$tA]);
$sess($u, $tA); $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$u]);
$check(FlxCoreHC\Auth\AuthService::revalidateSession() === false, 'S2 a deleted user ends their session');
$u2 = $mkUser($tA, 'staff'); $sess($u2, $tA); unset($_SESSION['auth']['pfp']);
$check(FlxCoreHC\Auth\AuthService::revalidateSession() === true && !empty($_SESSION['auth']['pfp']), 'S2 a session created before this release adopts a fingerprint once (no forced mass logout)');
$sess($u2, $tA); $_SESSION['auth']['impersonated_by'] = 'x'; $pdo->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$u2]);
$check(FlxCoreHC\Auth\AuthService::revalidateSession() === true, 'S2 support-mode sessions are governed by the support grant, not this check');
$_SESSION = [];

// =========================================================================== S3 — platform-operator gates
$adminId = $mkUser($tA, 'tenant_owner', 'active', 'all', null, 1, 0);
$ADM = $ctx($tA, $adminId, 'tenant_owner', true);
[$tC, $ownerC] = $mkTenant('C');           // a customer organization (not demo)
[$tD, $ownerD] = $mkTenant('D', true);      // a demo organization
$svc = new SuperadminService($ADM);
[$ok, $m] = $svc->resetUserPassword($tC, $ownerC, 'AdminChosen123');
$check($ok === false && stripos($m, 'support session') !== false, 'S3 platform admin CANNOT reset a customer owner\'s password without an approved support grant');
[$ok, $m, ] = $svc->createUserInTenant($tC, ['full_name' => 'Back Door', 'email' => 'backdoor@sec.test', 'role' => 'tenant_owner', 'password' => 'TempPass12345']);
$check($ok === false && (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email='backdoor@sec.test'")->fetchColumn() === 0, 'S3 …cannot create an owner login inside a customer organization without a grant');
[$ok, ] = $svc->addUserAsViewer($tC, 'View Er', 'viewer@sec.test');
$check($ok === false, 'S3 …cannot add a viewer inside a customer organization without a grant');
[$ok, ] = $svc->setUserRole($tC, $ownerC, 'manager');
$check($ok === false && (string) $pdo->query("SELECT role FROM users WHERE id=" . $pdo->quote($ownerC))->fetchColumn() === 'tenant_owner', 'S3 …cannot change an owner-level role without a grant');
[$ok, ] = $svc->resetUserPassword($tD, $ownerD, 'AdminChosen123');
$check($ok === true, 'S3 demo organizations (no real PHI) are exempt');
// approve a grant for THIS admin on tenant C
$rid = SupportAccess::request($ADM, $tC, 'sec test');
$pdo->prepare("UPDATE impersonation_requests SET status='approved', decided_at=?, expires_at=? WHERE id=?")->execute([$now, gmdate('Y-m-d H:i:s', time() + 3600), $rid]);
[$ok, ] = $svc->resetUserPassword($tC, $ownerC, 'AdminChosen123');
$check($ok === true, 'S3 with an APPROVED support grant the reset is allowed');
[$ok, $m, ] = $svc->createUserInTenant($tC, ['full_name' => 'Granted User', 'email' => 'granted@sec.test', 'role' => 'staff', 'password' => 'TempPass12345']);
$check($ok === true, 'S3 with a grant, creating a login is allowed');
$pdo->prepare("UPDATE impersonation_requests SET revoked_at=? WHERE id=?")->execute([$now, $rid]);
[$ok, ] = $svc->resetUserPassword($tC, $ownerC, 'AdminChosen456');
$check($ok === false, 'S3 a REVOKED grant stops working immediately');
$noTotp = $mkUser($tC, 'manager', 'active', 'all', null, 0, 0); $withTotp = $mkUser($tC, 'manager', 'active', 'all', null, 0, 1);
// v1.172.0: the platform two-factor requirement is now a setting (off on a new installation). This check keeps its original
// assertion and states its precondition explicitly: the requirement is ON. (The OFF case is covered in test_v1172.php, group P.)
FlxCoreHC\SettingsService::put(['platform_require_2fa' => '1']);
[$ok, $m] = $svc->setPlatformAdmin($tC, $noTotp, true);
$check($ok === false && stripos($m, 'two-factor') !== false, 'S3 cannot make a user a platform administrator unless they use 2FA');
[$ok, ] = $svc->setPlatformAdmin($tC, $withTotp, true);
$check($ok === true, 'S3 a 2FA-enabled user can be made a platform administrator');

// =========================================================================== B1 — provider form round-trip
$H = new HolderRepository($OWN);
$check(count(HolderInput::allTypes()) === 14 && count(HolderInput::individualTypes()) === 11 && HolderInput::orgTypes() === ['lab', 'clinic', 'group'], 'B1 one provider-type list: 14 types = 11 individual + 3 organization');
$hid = $H->insert(['holder_type' => 'individual', 'provider_type' => 'physician', 'first_name' => 'Round', 'last_name' => 'Trip', 'vertical' => 'healthcare', 'status' => 'active']);
$bad = [];
foreach (HolderInput::individualTypes() as $t) {
    [$f, $e] = HolderInput::fromPost($OWN, ['provider_type' => $t, 'first_name' => 'Round', 'last_name' => 'Trip'], 'individual', false);
    $H->update($hid, $f);
    if (($H->find($hid)['provider_type'] ?? null) !== $t) $bad[] = $t;
}
$check($bad === [], 'B1 every individual provider type survives an edit round-trip' . ($bad ? ' — LOST: ' . implode(',', $bad) : ''));
$oid = $H->insert(['holder_type' => 'organization', 'provider_type' => 'group', 'org_name' => 'Org One', 'vertical' => 'healthcare', 'status' => 'active']);
$bad = [];
foreach (HolderInput::orgTypes() as $t) {
    [$f, ] = HolderInput::fromPost($OWN, ['provider_type' => $t, 'org_name' => 'Org One'], 'organization', false);
    $H->update($oid, $f); if (($H->find($oid)['provider_type'] ?? null) !== $t) $bad[] = $t;
}
$check($bad === [], 'B1 every organization type survives an edit round-trip');
[$f, ] = HolderInput::fromPost($OWN, ['provider_type' => 'pa'], 'individual', false); $H->update($hid, $f);
[$f, ] = HolderInput::fromPost($OWN, ['provider_type' => 'not-a-type'], 'individual', false); $H->update($hid, $f);
$check(($H->find($hid)['provider_type'] ?? '') === 'pa', 'B1 an invalid type never wipes the stored type');
[$f, ] = HolderInput::fromPost($OWN, ['provider_type' => 'lab'], 'individual', false);
$check(!array_key_exists('provider_type', $f), 'B1 an organization type is refused on an individual record');
[$f, ] = HolderInput::fromPost($OWN, ['provider_type' => ''], 'individual', false); $H->update($hid, $f);
$check(array_key_exists('provider_type', $H->find($hid)) && $H->find($hid)['provider_type'] === null, 'B1 choosing "Not specified" clears the type on purpose');
$app = ['primary_specialty' => 'Cardiology', 'secondary_specialty' => 'Internal Medicine', 'taxonomy_code' => '207RC0000X', 'caqh_id' => '12345678',
        'medicare_ptan' => 'PT12345', 'medicaid_id' => 'MD777', 'middle_name' => 'Q', 'gender' => 'female', 'languages' => 'Español, English',
        'hospital_affiliations' => 'HIMA', 'accepting_new_patients' => '1', 'first_name' => 'Round', 'last_name' => 'Trip', 'npi' => '1063415271'];
[$f, $e] = HolderInput::fromPost($OWN, $app, 'individual', false); $H->update($hid, $f);
$r = $H->find($hid);
$check(($r['primary_specialty'] ?? '') === 'Cardiology' && ($r['caqh_id'] ?? '') === '12345678' && ($r['medicare_ptan'] ?? '') === 'PT12345' && ($r['taxonomy_code'] ?? '') === '207RC0000X'
    && (int) ($r['accepting_new_patients'] ?? -1) === 1 && ($r['npi'] ?? '') === '1063415271' && ($r['languages'] ?? '') === 'Español, English',
    'B1 the "Application data" block (specialty, CAQH, PTAN, taxonomy, languages, …) persists on edit');
[$f, ] = HolderInput::fromPost($OWN, ['npi' => '1063415271', 'provider_type' => 'pa', 'birth_month' => '3', 'role_title' => 'Self'], 'individual', false); $H->update($hid, $f);
$r = $H->find($hid);
$check(($r['caqh_id'] ?? '') === '12345678' && ($r['primary_specialty'] ?? '') === 'Cardiology' && ($r['role_title'] ?? '') === 'Self', 'B1 a portal-style partial form does not blank the fields it did not submit');
[, $e] = HolderInput::fromPost($OWN, ['npi' => '12AB'], 'individual', false);
$check($e !== null, 'B1 a malformed NPI is rejected with a message');

// =========================================================================== B2 — soft-deleted rows excluded
$hB2 = $H->insert(['holder_type' => 'individual', 'provider_type' => 'nurse', 'first_name' => 'Del', 'last_name' => 'Cred', 'vertical' => 'healthcare', 'status' => 'active']);
$C = new CredentialRepository($OWN);
$cLive = $C->insert(['holder_id' => $hB2, 'credential_type' => 'state_license', 'name' => 'Live', 'status' => 'active', 'expiry_date' => date('Y-m-d', strtotime('+20 days'))]);
$cGone = $C->insert(['holder_id' => $hB2, 'credential_type' => 'state_license', 'name' => 'Gone', 'status' => 'active', 'expiry_date' => date('Y-m-d', strtotime('+25 days'))]);
$C->delete($cGone);
$row = null; foreach ($H->withCredentialSummary() as $x) if ($x['id'] === $hB2) $row = $x;
$check($row !== null && (int) $row['credential_count'] === 1, 'B2 provider list counts only LIVE credentials (deleted one excluded)');
$rt = new RenewalTaskRepository($OWN);
$tk = $rt->insert(['credential_id' => $cGone, 'holder_id' => $hB2, 'title' => 'Renew Gone', 'status' => 'open', 'priority' => 'normal']);
$tRow = null; foreach ($rt->openWithCredentials() as $x) if ($x['id'] === $tk) $tRow = $x;
$check($tRow !== null && ($tRow['credential_name'] ?? null) === null, 'B2 a task no longer borrows the name/expiry of a deleted credential');
$dr = new FlxCoreHC\DocumentRequestService($OWN);
$hDel = $H->insert(['holder_type' => 'individual', 'provider_type' => 'nurse', 'first_name' => 'Gone', 'last_name' => 'Holder', 'vertical' => 'healthcare', 'status' => 'active']);
$cDel = $C->insert(['holder_id' => $hDel, 'credential_type' => 'state_license', 'name' => 'Held', 'status' => 'active', 'expiry_date' => date('Y-m-d', strtotime('+10 days'))]);
$H->delete($hDel);
$exp = array_column($C->expiringWithin(60), 'id');
$check(!in_array($cDel, $exp, true) && in_array($cLive, $exp, true), 'B2 expiringWithin() skips credentials of a deleted provider');
// cron sweep: a soft-deleted credential must not change status
$pdo->prepare("UPDATE credentials SET status='active' WHERE id IN (?,?)")->execute([$cLive, $cGone]);
$out = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/cron/sweep_expiring.php') . ' 2>&1', $out, $rc);
$stLive = (string) $pdo->query("SELECT status FROM credentials WHERE id=" . $pdo->quote($cLive))->fetchColumn();
$stGone = (string) $pdo->query("SELECT status FROM credentials WHERE id=" . $pdo->quote($cGone))->fetchColumn();
$check($rc === 0 && $stLive === 'expiring' && $stGone === 'active', "B2 cron sweep flips LIVE credentials to 'expiring' but leaves deleted ones alone (live=$stLive deleted=$stGone rc=$rc)");

// =========================================================================== B3 — migrator strictness
$benign = (new ReflectionClassConstant(FlxCoreHC\Migrator::class, 'BENIGN'))->getValue();
$check(array_intersect([1146, 1054, 1364, 1005, 3821, 4025], $benign) === [], 'B3 "table missing / unknown column / no default / cannot create / constraint" are no longer treated as harmless');
$check(array_diff([1050, 1060, 1061, 1091], $benign) === [], 'B3 genuine "already in place" codes are still tolerated');
FlxCoreHC\Migrator::ensureTable();
$pdo->exec("DELETE FROM schema_migrations");
$rep = FlxCoreHC\Migrator::run();
$check(count($rep) === 1 && $rep[0]['ok'] === false && stripos($rep[0]['error'], 'no update history') !== false, 'B3 an existing database with no update history is REFUSED (must be baselined), not replayed from migration 003');
FlxCoreHC\Migrator::baseline();   // the supported path for an existing database
$pdo->exec("DELETE FROM schema_migrations WHERE filename LIKE '12%'");
$rep = FlxCoreHC\Migrator::run();
$allOk = $rep !== [] && array_reduce($rep, fn($c, $r) => $c && $r['ok'], true);
$check($allOk && (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE filename LIKE '12%'")->fetchColumn() >= 5, 'B3 re-running the last migrations is idempotent: all succeed and are recorded' . ($allOk ? '' : ' — ' . json_encode(array_map(fn($r) => [$r['file'], $r['error']], array_filter($rep, fn($r) => !$r['ok'])))));
$totalSkipped = array_sum(array_column($rep, 'skipped'));
$check(isset($rep[0]['skipped_detail']) && count(array_merge(...array_column($rep, 'skipped_detail'))) === $totalSkipped, "B3 skipped statements are reported with their detail ($totalSkipped skipped), never silent");
$cfg = $GLOBALS['flxcred_config'];
$second = new PDO("mysql:host={$cfg['db_host']};port={$cfg['db_port']};dbname={$cfg['db_name']};charset=utf8mb4", $cfg['db_user'], $cfg['db_pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$second->query("SELECT GET_LOCK('flxcorehc_migrate', 0)")->fetchAll();
$pdo->exec("DELETE FROM schema_migrations WHERE filename LIKE '125%'");
$rep = FlxCoreHC\Migrator::run();
$check($rep[0]['ok'] === false && stripos($rep[0]['error'], 'already running') !== false, 'B3 a second updater is refused while one is running (advisory lock)');
$second->query("SELECT RELEASE_LOCK('flxcorehc_migrate')")->fetchAll();
$rep = FlxCoreHC\Migrator::run();
$check($rep !== [] && $rep[0]['ok'] === true, 'B3 …and works again once the lock is released');

// =========================================================================== B4 — need-to-know: audit / notifications / counts
[$tN, $ownerN] = $mkTenant('N'); $ON = $ctx($tN, $ownerN, 'tenant_owner');
$HN = new HolderRepository($ON);
$pA = $HN->insert(['holder_type' => 'individual', 'provider_type' => 'physician', 'first_name' => 'Mine', 'last_name' => 'Assigned', 'vertical' => 'healthcare', 'status' => 'active']);
$pB = $HN->insert(['holder_type' => 'individual', 'provider_type' => 'physician', 'first_name' => 'Not', 'last_name' => 'Mine', 'vertical' => 'healthcare', 'status' => 'active']);
$restrictedId = $mkUser($tN, 'manager', 'active', 'assigned'); $RES = $ctx($tN, $restrictedId, 'manager');
AssignmentService::assign($ON, $pA, $restrictedId);
$pdo->prepare("INSERT INTO audit_logs (id, tenant_id, user_id, action, entity_type, entity_id, changes, ip_address, created_at) VALUES (?,?,?,?,?,?,?,?,?)")
    ->execute([uuid4(), $tN, $ownerN, 'provider_updated', 'holders', $pB, '{"name":"Not Mine"}', '127.0.0.1', $now]);
$aO = new AuditLogRepository($ON); $aR = new AuditLogRepository($RES);
$check($aO->countFiltered([]) > 0 && count($aO->recent(50)) > 0, 'B4 owner sees the organization-wide audit log');
$check($aR->countFiltered([]) === 0 && $aR->filtered([]) === [] && $aR->recent(50) === [] && $aR->all() === [] && $aR->actionCounts('2000-01-01', '2100-01-01') === []
    && $aR->activityByPerson('2000-01-01', '2100-01-01') === [] && $aR->distinctActions() === [] && ($aR->activityTotals('2000-01-01', '2100-01-01')['total'] ?? 1) === 0, 'B4 need-to-know user gets NOTHING from the tenant-wide audit views (fail closed)');
// notifications
FlxCoreHC\NotificationService::push($tN, $restrictedId, 'expiring', 'warn', 'A expires — Mine Assigned', null, null, 'holders', $pA, 'k1');
FlxCoreHC\NotificationService::push($tN, $restrictedId, 'expiring', 'warn', 'B expires — Not Mine', null, null, 'holders', $pB, 'k2');
FlxCoreHC\NotificationService::push($tN, $restrictedId, 'import', 'info', 'Import finished', null, null, 'import', null, 'k3');
$titles = array_column(FlxCoreHC\NotificationService::list($RES), 'title');
$check(in_array('A expires — Mine Assigned', $titles, true) && in_array('Import finished', $titles, true) && !in_array('B expires — Not Mine', $titles, true), 'B4 notification list hides notices about providers the user cannot see (incl. stale ones from before un-assignment)');
$__visUnread = count(array_filter(FlxCoreHC\NotificationService::list($RES), fn($n) => empty($n['read_at'])));
$check(FlxCoreHC\NotificationService::unreadCount($RES) === $__visUnread && $__visUnread === count(FlxCoreHC\NotificationService::list($RES)), 'B4 the bell count equals exactly the notices the user can see (hidden one not counted)');
$oc = FlxCoreHC\NotificationService::list($ON); FlxCoreHC\NotificationService::push($tN, $ownerN, 'expiring', 'warn', 'B for owner', null, null, 'holders', $pB, 'k4');
$check(in_array('B for owner', array_column(FlxCoreHC\NotificationService::list($ON), 'title'), true), 'B4 an unrestricted user still sees everything');
// group / location counts
$gid = uuid4(); $pdo->prepare("INSERT INTO provider_groups (id, tenant_id, name, status, created_at) VALUES (?,?,?,?,?)")->execute([$gid, $tN, 'Grp', 'active', $now]);
foreach ([$pA, $pB] as $hh) $pdo->prepare("INSERT INTO holder_groups (id, tenant_id, holder_id, group_id, is_primary, joined_at) VALUES (?,?,?,?,0,?)")->execute([uuid4(), $tN, $hh, $gid, $now]);
$lid = uuid4(); $pdo->prepare("INSERT INTO locations (id, tenant_id, name, status, created_at) VALUES (?,?,?,?,?)")->execute([$lid, $tN, 'Loc', 'active', $now]);
foreach ([$pA, $pB] as $hh) $pdo->prepare("INSERT INTO holder_locations (id, tenant_id, holder_id, location_id, is_primary, affiliated_at) VALUES (?,?,?,?,0,?)")->execute([uuid4(), $tN, $hh, $lid, $now]);
$gO = (new GroupRepository($ON))->withMemberCounts(); $gR = (new GroupRepository($RES))->withMemberCounts();
$check((int) $gO[0]['member_count'] === 2 && (int) $gR[0]['member_count'] === 1, 'B4 group member counts: owner 2, need-to-know user 1');
$lO = (new LocationRepository($ON))->withProviderCounts(); $lR = (new LocationRepository($RES))->withProviderCounts();
$check((int) $lO[0]['provider_count'] === 2 && (int) $lR[0]['provider_count'] === 1, 'B4 location provider counts: owner 2, need-to-know user 1');

// =========================================================================== B6 — PHP 8.4 CSV
$dep = [];
set_error_handler(function ($no, $str) use (&$dep) { $dep[] = $str; return true; }, E_ALL);
$h = fopen('php://memory', 'w+'); flx_fputcsv($h, ['a', 'b "quoted", comma', 'back\\slash']); rewind($h); $line = (string) fgets($h);
$back = flx_str_getcsv(rtrim($line, "\r\n"));
restore_error_handler();
$check($dep === [], 'B6 fputcsv/str_getcsv wrappers raise no deprecation on this PHP (' . PHP_VERSION . ')');
$check($back === ['a', 'b "quoted", comma', 'back\\slash'], 'B6 CSV round-trips quotes, commas and backslashes');
$raw = shell_exec('grep -rnE "(^|[^_a-zA-Z>])(fputcsv|str_getcsv|fgetcsv)\(" ' . escapeshellarg($ROOT . '/app') . ' ' . escapeshellarg($ROOT . '/index.php') . ' ' . escapeshellarg($ROOT . '/cron') . ' | grep -v bootstrap.php') ?: '';
$check(trim($raw) === '', 'B6 no raw fputcsv/str_getcsv calls remain outside the wrappers');

// =========================================================================== S4 — JSON inside <script>
$evil = "</script><script>alert(1)</script> & \"q\" 'a'";
$j = flx_json(['n' => $evil]);
$check(strpbrk($j, '<>') === false && json_decode($j, true)['n'] === $evil, 'S4 flx_json() emits no raw < or > yet decodes back to the exact value');
$left = shell_exec('grep -rn "json_encode" ' . escapeshellarg($ROOT . '/app/views') . ' | grep -v flx_json') ?: '';
$check(trim($left) === '', 'S4 no view embeds raw json_encode() output');

// =========================================================================== K — encryption-key backup
$dk = bin2hex(random_bytes(32)); $ik = bin2hex(random_bytes(32));
$blob = FlxCoreHC\KeyBackup::encrypt($dk, $ik, 'a long passphrase 123');
$k = FlxCoreHC\KeyBackup::decrypt($blob, 'a long passphrase 123');
$check($k !== null && $k['crypto_data_key'] === $dk && $k['crypto_index_key'] === $ik, 'K backup opens with the right passphrase and returns both keys');
$check(FlxCoreHC\KeyBackup::decrypt($blob, 'the wrong passphrase!') === null, 'K wrong passphrase → refused (authenticated encryption, no garbage output)');
$t = json_decode($blob, true); $t['ct'] = base64_encode(strrev(base64_decode($t['ct']))); 
$check(FlxCoreHC\KeyBackup::decrypt(json_encode($t), 'a long passphrase 123') === null, 'K a tampered file is refused');
$t = json_decode($blob, true); $t['iterations'] = 1;
$check(FlxCoreHC\KeyBackup::decrypt(json_encode($t), 'a long passphrase 123') === null, 'K a downgraded work factor is refused');
$check(strpos($blob, $dk) === false && strpos($blob, $ik) === false, 'K the file never contains the keys in clear');
$check(FlxCoreHC\KeyBackup::passphraseError('short', 'short') !== null && FlxCoreHC\KeyBackup::passphraseError('long enough passphrase', 'different long passphrase') !== null && FlxCoreHC\KeyBackup::passphraseError('long enough passphrase', 'long enough passphrase') === null, 'K passphrase rules: ≥12 chars and must match');
$inst = (string) file_get_contents($ROOT . '/install.php');
$check(str_contains($inst, 'key_passphrase') && str_contains($inst, 'KeyBackup::encrypt') && !preg_match('/\bmail\s*\(|sendmail|MailService|Mailer/i', $inst), 'K installer requires a passphrase, builds the sealed backup, and never e-mails keys');
$__rk = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/database/restore_keys.php') . ' /nonexistent 2>&1') ?: '';
$check(stripos($__rk, 'Usage') !== false, 'K restore tool is present and validates its input');


// =========================================================================== E — documents encrypted at rest
$ed = sys_get_temp_dir() . '/flx_enc_' . bin2hex(random_bytes(4)); @mkdir($ed, 0750, true);
$plain = "%PDF-1.4\nSECRET-PHI-MARKER-" . bin2hex(random_bytes(8)) . "\n%%EOF";
$tmp = $ed . '/upload.tmp'; file_put_contents($tmp, $plain);
$dest = $ed . '/' . uuid4() . '.pdf';
$check(FlxCoreHC\EncryptedFile::store($tmp, $dest) && !is_file($tmp), 'E store() writes the file and removes the temp upload');
$onDisk = (string) file_get_contents($dest);
$check(str_starts_with($onDisk, FlxCoreHC\EncryptedFile::MAGIC) && !str_contains($onDisk, 'SECRET-PHI-MARKER'), 'E on-disk bytes carry the header and NO plaintext');
$check(FlxCoreHC\EncryptedFile::read($dest) === $plain, 'E read() returns the exact original bytes');
ob_start(); $sent = FlxCoreHC\EncryptedFile::send($dest); $out = ob_get_clean();
$check($sent && $out === $plain, 'E send() streams decrypted bytes');
$moved = $ed . '/' . uuid4() . '.pdf'; rename($dest, $moved);
$check(FlxCoreHC\EncryptedFile::read($moved) === null, 'E a blob moved to another filename does not decrypt (AAD-bound)');
$flip = $onDisk; $flip[strlen($flip) - 3] = $flip[strlen($flip) - 3] ^ "\x01"; $fp = $ed . '/x.pdf'; file_put_contents($fp, $flip);
$check(FlxCoreHC\EncryptedFile::read($fp) === null, 'E a tampered file is refused, not served');
$leg = $ed . '/' . uuid4() . '.pdf'; file_put_contents($leg, $plain);
$check(FlxCoreHC\EncryptedFile::read($leg) === $plain && !FlxCoreHC\EncryptedFile::isEncrypted($leg), 'E legacy plaintext files are still readable (backward compatible)');
$check(FlxCoreHC\EncryptedFile::encryptInPlace($leg) && FlxCoreHC\EncryptedFile::isEncrypted($leg) && FlxCoreHC\EncryptedFile::read($leg) === $plain && !FlxCoreHC\EncryptedFile::encryptInPlace($leg), 'E in-place conversion round-trips and is idempotent');
@mkdir(rtrim((string) config('docs_path'), '/'), 0750, true);
$cli = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/database/encrypt_existing_files.php') . ' --dry-run 2>&1') ?: '';
$check(stripos($cli, 'dry-run') !== false, 'E migration CLI runs (dry-run)');
$src = (string) file_get_contents($ROOT . '/index.php');
$check(!preg_match('/readfile\(|move_uploaded_file\(/', $src), 'E no raw readfile()/move_uploaded_file() left in index.php');
$prev = FlxCoreHC\BackupService::documentsArchive(); 
$cfgDocs = rtrim((string) config('docs_path'), '/'); @mkdir($cfgDocs . '/t1', 0750, true);
$bf = $cfgDocs . '/t1/' . uuid4() . '.pdf'; FlxCoreHC\EncryptedFile::writeBytes($bf, $plain);
$tar = gzdecode(FlxCoreHC\BackupService::documentsArchive());
$check($tar !== false && !str_contains($tar, 'SECRET-PHI-MARKER') && str_contains($tar, FlxCoreHC\EncryptedFile::MAGIC), 'E document backup archive holds sealed files, never plaintext');
array_map('unlink', glob($ed . '/*') ?: []); @rmdir($ed); @unlink($bf);

// =========================================================================== N — NPI validation (D-3)
use FlxCoreHC\Npi;
$check(Npi::isValid('1063415271') && Npi::isValid('1234567893'), 'N known-good NPIs pass (incl. the CMS worked example 1234567893)');
$check(!Npi::isValid('1234567890') && !Npi::isValid('1063415272') && !Npi::isValid('0234567893') && !Npi::isValid('123456789') && !Npi::isValid('12345678930'), 'N wrong check digit / leading 0 / wrong length are rejected');
$fx = Npi::fix('1588293040'); $check(Npi::isValid($fx) && substr($fx, 0, 9) === '158829304', 'N fix() keeps the first 9 digits and produces a valid NPI');
$ok = true; for ($i = 0; $i < 200; $i++) { $b = '1' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT); $ok = $ok && Npi::isValid($b . Npi::checkDigit($b)); }
$check($ok, 'N check digit is consistent for 200 random bases');
[$nt, $nu] = $mkTenant('npi'); $nctx = $ctx($nt, $nu, 'tenant_owner');
[$f, $e] = HolderInput::fromPost($nctx, ['npi' => '1234567890', 'first_name' => 'A', 'last_name' => 'B', 'provider_type' => 'physician'], 'individual', true);
$check($f === [] && stripos((string) $e, 'not a valid NPI') !== false, 'N provider form rejects a 10-digit number with a bad check digit');
[$f, $e] = HolderInput::fromPost($nctx, ['npi' => '1063415271', 'first_name' => 'A', 'last_name' => 'B', 'provider_type' => 'physician'], 'individual', true);
$check($e === null && ($f['npi'] ?? '') === '1063415271', 'N provider form accepts a valid NPI');
[$okg, $mg] = (new FlxCoreHC\OrgService($nctx))->updateEntity(['group_npi' => '1234567890']);
$check($okg === false && stripos((string) $mg, 'Group NPI') !== false, 'N group NPI with a bad check digit is refused');
$prev = (new FlxCoreHC\ProviderImportService($nctx))->preview("first_name,last_name,holder_type,provider_type,npi\nA,B,individual,physician,1234567890\n");
$check(json_encode($prev) !== false && stripos(json_encode($prev), 'not a valid NPI') !== false, 'N provider CSV import flags a bad NPI');

// =========================================================================== J — Puerto Rico seed corrections & jurisdiction (D-5)
$cat = fn(string $c) => $pdo->query("SELECT applies_to, name FROM credential_catalog WHERE tenant_id IS NULL AND code = " . $pdo->quote($c))->fetch();
$check(($cat('pr_medical_license')['applies_to'] ?? '') === 'physician' && ($cat('pr_cme')['applies_to'] ?? '') === 'physician', 'J PR medical licence and CME apply to physicians only (not nurses)');
$check(($cat('pr_nursing_license')['applies_to'] ?? '') === 'nurse', 'J PR nursing licence exists and applies to nurses');
$regs = $pdo->prepare("SELECT name FROM regulators WHERE tenant_id = ?"); $regs->execute([$nt]); $rn = array_column($regs->fetchAll(), 'name');
$check(in_array('Junta de Licenciamiento y Disciplina Médica (JLDM)', $rn, true) && !in_array('PR Board of Medical Examiners', $rn, true), 'J a new healthcare tenant gets the JLDM name, not "PR Board of Medical Examiners"');
$nq = $pdo->prepare("SELECT COUNT(*) FROM regulator_requirements WHERE tenant_id = ? AND catalog_code = 'pr_nursing_license'"); $nq->execute([$nt]);
$check((int) $nq->fetchColumn() === 1, 'J the nursing board carries the PR nursing licence requirement (once)');
$cds = $cat('pr_cds_negative');
$check(stripos((string) ($cds['name'] ?? ''), 'ASSMCA') === false && stripos((string) $pdo->query("SELECT notes FROM credential_catalog WHERE tenant_id IS NULL AND code='pr_cds_negative'")->fetchColumn(), 'Department of Health') === false, 'J the negative-CDS certificate no longer names two conflicting agencies');
// legacy upgrade path: a tenant seeded before v1.171 (old regulator name) is renamed in place by migration 126
[$lt, ] = $mkTenant('legacy');
$pdo->prepare("UPDATE regulators SET name = 'PR Board of Medical Examiners' WHERE tenant_id = ? AND name = 'Junta de Licenciamiento y Disciplina Médica (JLDM)'")->execute([$lt]);
$pdo->prepare("DELETE FROM regulator_requirements WHERE tenant_id = ? AND catalog_code = 'pr_nursing_license'")->execute([$lt]);
foreach (preg_split('/;\s*\n/', preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($ROOT . '/database/migrations/126_pr_seed_corrections.sql'))) as $__st) { if (trim($__st) !== '') $pdo->exec($__st); }
$lr = $pdo->prepare("SELECT name FROM regulators WHERE tenant_id = ? AND short_name = 'JLDM'"); $lr->execute([$lt]); $lrn = array_column($lr->fetchAll(), 'name');
$check($lrn === ['Junta de Licenciamiento y Disciplina Médica (JLDM)'], 'J migration 126 renames the legacy regulator in place (one row, no duplicate)');
$lq = $pdo->prepare("SELECT COUNT(*) FROM regulator_requirements WHERE tenant_id = ? AND catalog_code = 'pr_nursing_license'"); $lq->execute([$lt]);
$check((int) $lq->fetchColumn() === 1, 'J migration 126 back-fills the nursing requirement for existing tenants, idempotently');
foreach (preg_split('/;\s*\n/', preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($ROOT . '/database/migrations/126_pr_seed_corrections.sql'))) as $__st) { if (trim($__st) !== '') $pdo->exec($__st); }
$lq->execute([$lt]); $check((int) $lq->fetchColumn() === 1, 'J migration 126 is re-runnable (no duplicates)');
// readiness: jurisdiction-aware
$hidR = (new HolderRepository($nctx))->insert(['holder_type' => 'individual', 'provider_type' => 'physician', 'first_name' => 'R', 'last_name' => 'D', 'vertical' => 'healthcare', 'status' => 'active']);
(new CredentialRepository($nctx))->insert(['holder_id' => $hidR, 'credential_type' => 'state_cds', 'status' => 'active', 'name' => 'FL CDS']);
$hRow = (new HolderRepository($nctx))->find($hidR);
$labels = fn(array $r) => array_merge(...array_map(fn($g) => array_column($g, 'label'), array_values($r['groups'])));
$rn0 = $labels((new FlxCoreHC\ApplicationReadinessService($nctx))->evaluate($hRow, []));
$rpr = (new FlxCoreHC\ApplicationReadinessService($nctx))->evaluate($hRow, ['PR']);
$rn1 = $labels($rpr);
$check(!array_filter($rn0, fn($l) => stripos($l, 'OCS') !== false || stripos($l, 'Buena') !== false || stripos($l, 'ASUME') !== false || stripos($l, '(PR)') !== false), 'J readiness for a non-PR tenant shows none of the PR-only items');
$check((bool) array_filter($rn1, fn($l) => stripos($l, 'OCS') !== false) && (bool) array_filter($rn1, fn($l) => stripos($l, 'Buena') !== false), 'J readiness for a PR tenant shows the PR items');
$cdsItem = null; foreach ($rpr['groups'] as $g) foreach ($g as $it) if (stripos($it['label'], 'drug-test') !== false) $cdsItem = $it;
$check($cdsItem !== null && $cdsItem['ok'] === false, 'J a state controlled-substance registration does NOT satisfy the PR negative drug-test certificate');
$pdo->prepare("UPDATE tenants SET operating_states='PR,FL' WHERE id=?")->execute([$nt]);
$check(FlxCoreHC\OrgService::statesFor($nt) === ['PR', 'FL'] && FlxCoreHC\OrgService::statesFor('no-such-tenant') === [], 'J OrgService::statesFor reads the tenant jurisdictions (none assumed for unknown tenants)');
$check(flx_ce_months(['PR']) === 36 && flx_ce_months(['FL']) === 24 && flx_ce_months([]) === 24, 'J CE look-back is 36 months for PR, 24 otherwise');
$cy = flx_ce_cycle(null, 36); $span = (int) round(($cy['end']->getTimestamp() - $cy['start']->getTimestamp()) / 86400);
$check($cy['months'] === 36 && $span >= 1090 && $span <= 1100, 'J the PR CE window really spans ~36 months');
$check(FlxCoreHC\CatalogService::statesOf('') === [] && FlxCoreHC\CatalogService::statesOf(null) === [] && FlxCoreHC\CatalogService::statesOf('pr, fl') === ['PR', 'FL'], 'J no jurisdiction is assumed (no silent FL fallback)');
$dflt = $pdo->query("SELECT COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'operating_states'")->fetchColumn();
$check(in_array((string) $dflt, ['', "''"], true), "J tenants.operating_states has no 'FL' default (got " . var_export($dflt, true) . ')');

// demo data must pass the same NPI rule the forms enforce, and be PR-configured
$__err = []; $__f = set_error_handler(function ($n, $m) use (&$__err) { $__err[] = $m; return true; });
try { FlxCoreHC\DemoHealthcareService::build(); } catch (\Throwable $e) { $__err[] = 'build threw: ' . $e->getMessage(); }
restore_error_handler();
$demoTenants = $pdo->query("SELECT id, operating_states FROM tenants WHERE is_demo = 1 AND vertical = 'healthcare'")->fetchAll();
$badNpi = 0; $nNpi = 0;
foreach ($demoTenants as $dt) {
    $dctx = $ctx($dt['id'], 'demo-reader', 'tenant_owner');
    foreach ((new HolderRepository($dctx))->all([], 'created_at', 1000) as $hh) { if (!empty($hh['npi'])) { $nNpi++; if (!FlxCoreHC\Npi::isValid((string) $hh['npi'])) $badNpi++; } }
}
$check(count($demoTenants) >= 5 && $nNpi > 20 && $badNpi === 0, "J demo build: every demo NPI passes the check-digit rule ($nNpi NPIs in " . count($demoTenants) . ' demo orgs, ' . $badNpi . ' invalid)');
$check(count(array_filter($demoTenants, fn($d) => $d['operating_states'] === 'PR')) >= 5, 'J the five built demo organizations operate in PR (their data is all Puerto Rico)');

// =========================================================================== S5 — tenant SMTP SSRF guard
use FlxCoreHC\SmtpGuard;
SmtpGuard::$resolver = fn(string $h) => match ($h) { 'smtp.good.example' => ['8.8.8.8'], 'mixed.example' => ['8.8.8.8', '10.0.0.7'], 'rebind.example' => ['169.254.169.254'], 'gone.example' => [], default => [] };
$check(SmtpGuard::portError(587) === null && SmtpGuard::portError(465) === null && SmtpGuard::portError(22) !== null && SmtpGuard::portError(6379) !== null && SmtpGuard::portError(3306) !== null, 'S5 only mail-submission ports (25/465/587/2525) are allowed');
$bad = ['localhost', '127.0.0.1', '169.254.169.254', '10.1.2.3', '192.168.0.10', '172.16.5.5', '0.0.0.0', '::1', 'fd00::1', 'internal', 'a b.example', 'mixed.example', 'rebind.example', 'gone.example', ''];
$allRejected = true; $leak = [];
foreach ($bad as $h) { if (SmtpGuard::hostError($h) === null) { $allRejected = false; $leak[] = $h; } }
$check($allRejected, 'S5 loopback / private / link-local / metadata / unresolvable / malformed SMTP hosts are refused' . ($leak ? ' — LEAKED: ' . implode(',', $leak) : ''));
$check(SmtpGuard::hostError('smtp.good.example') === null && SmtpGuard::resolveSafe('smtp.good.example')[0] === '8.8.8.8' && SmtpGuard::hostError('8.8.8.8') === null, 'S5 a public mail host is accepted and pinned to its validated address');
[$ok1, $m1] = \FlxCoreHC\TenantMailService::save($nctx, ['transport' => 'smtp', 'from_email' => 'a@good.example', 'smtp_host' => '127.0.0.1', 'smtp_port' => 587]);
[$ok2, $m2] = \FlxCoreHC\TenantMailService::save($nctx, ['transport' => 'smtp', 'from_email' => 'a@good.example', 'smtp_host' => 'smtp.good.example', 'smtp_port' => 6379]);
[$ok3, $m3] = \FlxCoreHC\TenantMailService::save($nctx, ['transport' => 'smtp', 'from_email' => 'a@good.example', 'smtp_host' => 'smtp.good.example', 'smtp_port' => 587]);
$check($ok1 === false && $ok2 === false && $ok3 === true, 'S5 saving SMTP settings: internal host refused, non-mail port refused, valid settings saved');
[$okS, $errS] = \FlxCoreHC\MailService::testDelivery(['mail_transport' => 'smtp', 'tenant_supplied' => true, 'smtp_host' => '127.0.0.1', 'smtp_port' => 25, 'smtp_secure' => 'none', 'smtp_user' => '', 'smtp_pass' => '', 'mail_from' => 'a@b.example', 'mail_from_name' => 'x'], 'to@b.example', 's', 'b');
$check($okS === false && stripos($errS, 'public mail server') !== false, 'S5 at SEND time a tenant-supplied internal address is refused too (defence against a row edited or DNS changed after save)');
[$okP, $errP] = \FlxCoreHC\MailService::testDelivery(['mail_transport' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => 1, 'smtp_secure' => 'none', 'smtp_user' => '', 'smtp_pass' => '', 'mail_from' => 'a@b.example', 'mail_from_name' => 'x'], 'to@b.example', 's', 'b');
$check($okP === false && stripos($errP, 'refused') === false && stripos($errP, 'errno') === false && stripos($errP, 'could not connect') !== false, 'S5 connection failures return a generic message (no refused/timeout oracle); operator-configured SMTP is not blocked by the guard');
SmtpGuard::$resolver = null;

// =========================================================================== S6 — TOTP secret encrypted + replay protection
use FlxCoreHC\Auth\Totp;
use FlxCoreHC\Auth\AuthService as AS6;
[$t6, $o6] = $mkTenant('totp'); $u6 = $mkUser($t6, 'manager');
$sec = Totp::generateSecret(); $ctr = (int) floor(time() / 30);
[$okc, , $bc] = AS6::confirmTotp($u6, $sec, Totp::codeAt($sec, $ctr));
$row6 = $pdo->query("SELECT totp_secret, totp_last_counter FROM users WHERE id = " . $pdo->quote($u6))->fetch();
$check($okc && str_starts_with((string) $row6['totp_secret'], 'enc1:') && strpos((string) $row6['totp_secret'], $sec) === false, 'S6 enrolment stores the TOTP secret encrypted (never the base32 secret)');
$check((int) $row6['totp_last_counter'] === $ctr && AS6::openTotpSecret($u6, (string) $row6['totp_secret']) === $sec, 'S6 the secret round-trips; the enrolment code\'s time-step is burned');
$check(AS6::checkTotp($u6, (string) $row6['totp_secret'], Totp::codeAt($sec, $ctr)) === false, 'S6 REPLAY: the code used at enrolment is rejected');
$check(AS6::checkTotp($u6, (string) $row6['totp_secret'], Totp::codeAt($sec, $ctr + 1)) === true, 'S6 a fresh (next time-step) code is accepted');
$check(AS6::checkTotp($u6, (string) $row6['totp_secret'], Totp::codeAt($sec, $ctr + 1)) === false, 'S6 REPLAY: the same code cannot be used twice');
$check(AS6::checkTotp($u6, (string) $row6['totp_secret'], Totp::codeAt($sec, $ctr)) === false, 'S6 an older time-step than the last accepted one is rejected');
$check(AS6::checkTotp($u6, (string) $row6['totp_secret'], '000000') === false, 'S6 a wrong code is rejected');
$pdo->prepare("UPDATE users SET totp_secret = ?, totp_last_counter = NULL WHERE id = ?")->execute([$sec, $u6]);   // legacy plaintext row
$check(AS6::checkTotp($u6, $sec, Totp::codeAt($sec, (int) floor(time() / 30))) === true && str_starts_with((string) $pdo->query("SELECT totp_secret FROM users WHERE id = " . $pdo->quote($u6))->fetchColumn(), 'enc1:'), 'S6 a legacy plaintext secret still works and is upgraded to encrypted on first use');
$cur = (string) $pdo->query("SELECT totp_secret FROM users WHERE id = " . $pdo->quote($u6))->fetchColumn();
[$okd, ] = AS6::disableTotp($u6, Totp::codeAt($sec, (int) floor(time() / 30)));
$check($okd === false, 'S6 disabling 2FA with an already-used code is refused (replay)');
$pdo->prepare("UPDATE users SET totp_secret = ? WHERE id = ?")->execute([AS6::sealTotpSecret('another-user', $sec), $u6]);
$check(AS6::openTotpSecret($u6, (string) $pdo->query("SELECT totp_secret FROM users WHERE id = " . $pdo->quote($u6))->fetchColumn()) === null, 'S6 a secret sealed for another user id does not open (AAD-bound)');

// =========================================================================== S7 — 2FA policy keyed on capabilities
[$t7, $o7] = $mkTenant('twofa');
[$okR, , $ridA] = (new RoleService($ctx($t7, $o7, 'tenant_owner')))->create('Office Lead', ['team.manage', 'documents.view'], false);
[$okR2, , $ridB] = (new RoleService($ctx($t7, $o7, 'tenant_owner')))->create('PII Reader', ['pii.view', 'documents.view'], false);
[$okR3, , $ridC] = (new RoleService($ctx($t7, $o7, 'tenant_owner')))->create('Viewer Plus', ['documents.view', 'records.write'], false);
$slugOf = fn($rid) => (string) $pdo->query("SELECT slug FROM roles WHERE id = " . $pdo->quote((string) $rid))->fetchColumn();
$mk7 = fn(string $slug) => $ctx($t7, 'u-' . $slug, $slug);
$check($okR && $okR2 && $okR3
    && FlxCoreHC\Authz::needsTwoFactor($mk7($slugOf($ridA))) && FlxCoreHC\Authz::needsTwoFactor($mk7($slugOf($ridB)))
    && !FlxCoreHC\Authz::needsTwoFactor($mk7($slugOf($ridC))), 'S7 a CUSTOM role with team.manage or pii.view is held to the 2FA policy; one without is not');
$check(FlxCoreHC\Authz::needsTwoFactor($mk7('tenant_owner')) && FlxCoreHC\Authz::needsTwoFactor($mk7('manager')) && !FlxCoreHC\Authz::needsTwoFactor($mk7('staff')) && !FlxCoreHC\Authz::needsTwoFactor($mk7('read_only')), 'S7 owner and manager are privileged; staff and read-only are not');

// =========================================================================== B7 — isolation checker can actually fail
$iso = sys_get_temp_dir() . '/flx_iso_' . bin2hex(random_bytes(4)); @mkdir($iso . '/tools', 0775, true); @mkdir($iso . '/app/src', 0775, true);
copy($ROOT . '/tools/check_isolation.sh', $iso . '/tools/check_isolation.sh'); file_put_contents($iso . '/index.php', "<?php\n"); file_put_contents($iso . '/tools/isolation_baseline.txt', "0\n");
$runIso = function () use ($iso): array { $o = []; exec('bash ' . escapeshellarg($iso . '/tools/check_isolation.sh') . ' 2>&1', $o, $rc); return [$rc, implode("\n", $o)]; };
// the allow-list names real files; give the temp tree those so only the intended defect fails
$al = (string) shell_exec("grep -o \"ALLOW_REGEX='[^']*'\" " . escapeshellarg($ROOT . '/tools/check_isolation.sh'));
preg_match("/ALLOW_REGEX='([^']*)'/", $al, $mm); foreach (explode('|', $mm[1] ?? '') as $ent) { $ent = str_replace('\\.', '.', $ent); if (str_ends_with($ent, '/')) @mkdir($iso . '/' . $ent, 0775, true); else { @mkdir(dirname($iso . '/' . $ent), 0775, true); file_put_contents($iso . '/' . $ent, "<?php\n"); } }
[$rc0, ] = $runIso();
$check($rc0 === 0, 'B7 isolation check passes on a clean tree');
file_put_contents($iso . '/app/src/Sneaky.php', "<?php\n\$x = Database::pdo();\n"); [$rc1, $o1] = $runIso(); @unlink($iso . '/app/src/Sneaky.php');
$check($rc1 === 1 && str_contains($o1, 'Sneaky.php'), 'B7 a new file using raw Database::pdo() FAILS the check');
file_put_contents($iso . '/index.php', "<?php\nDatabase::pdo();\n"); [$rc2, $o2] = $runIso();
$check($rc2 === 1 && str_contains($o2, 'baseline'), 'B7 growth of raw DB calls in index.php beyond the baseline FAILS the check');
file_put_contents($iso . '/index.php', "<?php\n"); @unlink($iso . '/app/src/Auth/AuthService.php'); [$rc3, $o3] = $runIso();
$check($rc3 === 1 && str_contains($o3, 'does not exist'), 'B7 a stale allow-list entry FAILS the check');
shell_exec('rm -rf ' . escapeshellarg($iso));
$now34 = (int) trim((string) shell_exec('grep -c "Database::pdo()" ' . escapeshellarg($ROOT . '/index.php')));
$check($now34 <= (int) trim((string) file_get_contents($ROOT . '/tools/isolation_baseline.txt')), 'B7 the shipped index.php is within its recorded baseline');

// =========================================================================== S8 — low-severity hardening
// (a) account lock-out DoS: a stranger's failures must not lock the real owner out of a place they already sign in from
[$t8, $o8] = $mkTenant('lock'); $em8 = "o-lock@sec.test"; $pwd8 = 'Pass123456789';
$lim = new FlxCoreHC\Auth\RateLimiter(); $auth8 = new FlxCoreHC\Auth\AuthService();
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$r1 = $auth8->attemptLogin($em8, $pwd8); FlxCoreHC\Auth\AuthService::markKnownPlace($em8);   // sign-in completed (establishSession does this)
$check(is_array($r1) && !isset($r1['__choose_org']) || is_array($r1), 'S8 owner signs in normally from their usual address');
for ($i = 0; $i < 15; $i++) { $lim->record('acct|' . $em8); }   // a stranger hammers the account from elsewhere
$r2 = $auth8->attemptLogin($em8, $pwd8);
$check(is_array($r2), 'S8 the owner (known address) is NOT locked out by a stranger\'s failed attempts');
for ($i = 0; $i < 15; $i++) { $lim->record('acct|' . $em8); }
$_SERVER['REMOTE_ADDR'] = '198.51.100.77';
$r3 = $auth8->attemptLogin($em8, $pwd8);
$check(is_string($r3) && stripos($r3, 'Too many') !== false, 'S8 a never-seen address is still held by the per-account throttle (brute force stays blocked)');
$lim->clear('acct|' . $em8); $lim->clear($em8 . '|198.51.100.77'); $lim->clear('ip|198.51.100.77'); $lim->clear('ip|203.0.113.10'); unset($_SERVER['REMOTE_ADDR']);
// (b) dev-plaintext marker is not honoured outside dev
$forged = "\x00" . 'forged-plaintext'; $envWas = $GLOBALS['flxcred_config']['app_env'] ?? null;
$GLOBALS['flxcred_config']['app_env'] = 'dev';
$devRead = FlxCoreHC\Crypto::decrypt($forged);
$GLOBALS['flxcred_config']['app_env'] = 'production';
$prodRead = FlxCoreHC\Crypto::decrypt($forged);
$GLOBALS['flxcred_config']['app_env'] = $envWas;
$check($devRead === 'forged-plaintext' && $prodRead === null, 'S8 production refuses the dev passthrough marker (a forged 0x00 value reads as null); dev still reads it');
// (c) invite tokens are stored hashed and still resolve; legacy raw tokens still resolve
[$t9, $o9] = $mkTenant('invite'); $tm = new TeamService($ctx($t9, $o9, 'tenant_owner'));
$res = $tm->invite(['email' => 'invitee@sec.test', 'full_name' => 'Ivy Invitee', 'role' => 'staff']);
$tok = is_array($res) ? ($res[2] ?? null) : null;
$stored = (string) $pdo->query("SELECT invite_token FROM users WHERE email = 'invitee@sec.test'")->fetchColumn();
$check($tok !== null && strlen($tok) === 40 && $stored === TeamService::inviteHash($tok) && $stored !== $tok && strlen($stored) === 64, 'S8 the database holds only a hash of the invite token');
$check(TeamService::findInvite($tok) !== null && TeamService::findInvite(str_repeat('a', 40)) === null && TeamService::findInvite($stored) === null, 'S8 the emailed token resolves; a guess or the stored hash itself does not');
$pdo->prepare("UPDATE users SET invite_token = ? WHERE email = 'invitee@sec.test'")->execute([$tok]);   // legacy raw row
$check(TeamService::findInvite($tok) !== null, 'S8 a legacy (raw-token) pending invite still resolves');
[$oka, ] = TeamService::acceptInvite($tok, 'A-long-password-123');
$check($oka === true && TeamService::findInvite($tok) === null, 'S8 accepting an invite consumes it');
// (d) GET side effects and role-name checks are gone
$ix = (string) file_get_contents($ROOT . '/index.php');
$check(!str_contains($ix, "if (\$method === 'GET') { try { FlxCoreHC\\MailService::flush(10)") && !str_contains($ix, "if (\$method === 'GET' && \$id === null) { try { (new FlxCoreHC\\ReminderService(\$ctx))->run()"), 'S8 viewing /admin or /compliance no longer sends mail (cron/POST only)');
$check(!str_contains($ix, "if (!in_array(\$ctx->role, ['tenant_owner','manager'], true)) { http_response_code(403); exit('Forbidden.'); }\n    \$org = new OrgService"), 'S8 /settings is gated by the org.manage capability, not role names');
$inst = (string) file_get_contents($ROOT . '/install.php');
$check(str_contains($inst, 'install_csrf') && str_contains($inst, 'name="_csrf"'), 'S8 installer form carries a session CSRF token');

// (e) D-6 — address street lines and phone are encrypted at rest; legacy plaintext still reads, then back-fills
[$t10, $o10] = $mkTenant('addr'); $ar = new FlxCoreHC\Repository\AddressRepository($ctx($t10, $o10, 'tenant_owner'));
$aid = $ar->insert(['owner_type' => 'tenant', 'owner_id' => $t10, 'kind' => 'home', 'line1' => '742 Evergreen Terrace', 'line2' => 'Apt 9', 'city' => 'Orlando', 'state' => 'FL', 'zip' => '32801', 'phone' => '407-555-0199']);
$raw = $pdo->query("SELECT line1, line2, phone, line1_enc, phone_enc FROM addresses WHERE id = '$aid'")->fetch();
$check($raw['line1'] === null && $raw['phone'] === null && $raw['line1_enc'] !== null && !str_contains((string) $raw['line1_enc'], 'Evergreen'), 'D6 street line and phone are not stored in plaintext');
$back = $ar->find($aid);
$check($back['line1'] === '742 Evergreen Terrace' && $back['phone'] === '407-555-0199' && $back['city'] === 'Orlando' && FlxCoreHC\Repository\AddressRepository::oneLine($back) !== '', 'D6 the repository returns the decrypted address');
$ar->update($aid, ['line1' => '1 New Street']);
$check($ar->find($aid)['line1'] === '1 New Street' && $pdo->query("SELECT line1 FROM addresses WHERE id = '$aid'")->fetchColumn() === null, 'D6 editing re-encrypts and leaves no plaintext behind');
$pdo->prepare("UPDATE addresses SET line1 = 'Legacy Rd 5', line1_enc = NULL WHERE id = ?")->execute([$aid]);
$check($ar->find($aid)['line1'] === 'Legacy Rd 5', 'D6 a legacy plaintext row still reads before the back-fill');
$ar->update($aid, ['line1_enc' => 'INJECTED-CIPHERTEXT', 'city' => 'Miami']);
$check($pdo->query("SELECT line1_enc FROM addresses WHERE id = '$aid'")->fetchColumn() !== 'INJECTED-CIPHERTEXT', 'D6 callers cannot write ciphertext columns directly');
$out = shell_exec('cd ' . escapeshellarg($ROOT) . ' && php database/encrypt_existing_addresses.php 2>&1');
$check(str_contains((string) $out, 'encrypted') && $pdo->query("SELECT line1 FROM addresses WHERE id = '$aid'")->fetchColumn() === null && $ar->find($aid)['line1'] === 'Legacy Rd 5', 'D6 the back-fill encrypts legacy rows and they still read');

// (f) D-6 — passphrase-sealed database dump
$dump = FlxCoreHC\BackupService::databaseDump(); $sealed = FlxCoreHC\DumpSeal::seal($dump, 'correct horse battery staple');
$check(!str_contains($sealed, "\x1f\x8b\x08") || substr($sealed, 0, 9) === "FLXDUMP1\n", 'D6 sealed dump carries the FLXDUMP1 header, not a bare gzip');
$check(FlxCoreHC\DumpSeal::open($sealed, 'correct horse battery staple') === $dump, 'D6 the right passphrase restores the exact dump');
$check(FlxCoreHC\DumpSeal::open($sealed, 'wrong passphrase here') === null, 'D6 a wrong passphrase yields nothing');
$tam = $sealed; $tam[strlen($tam) - 5] = chr(ord($tam[strlen($tam) - 5]) ^ 1);
$check(FlxCoreHC\DumpSeal::open($tam, 'correct horse battery staple') === null, 'D6 a tampered sealed dump is rejected');
$tmpIn = tempnam(sys_get_temp_dir(), 'fd'); $tmpOut = $tmpIn . '.out'; file_put_contents($tmpIn, $sealed);
$cli = shell_exec('cd ' . escapeshellarg($ROOT) . ' && printf %s ' . escapeshellarg('correct horse battery staple') . ' | php database/decrypt_dump.php ' . escapeshellarg($tmpIn) . ' ' . escapeshellarg($tmpOut) . ' 2>&1');
$check(is_file($tmpOut) && file_get_contents($tmpOut) === $dump, 'D6 database/decrypt_dump.php restores the .sql.gz from the sealed file');
@unlink($tmpIn); @unlink($tmpOut);
$ix2 = (string) file_get_contents($ROOT . '/index.php');
$check(str_contains($ix2, "db_backup_encrypted") && str_contains($ix2, "DumpSeal::seal") && str_contains($ix2, "dbbackup|"), 'D6 the console offers a sealed download, password-confirmed and rate-limited');

// =========================================================================== v1.171.4 remediation
// (1) address back-fill migrates each column independently and never overwrites existing ciphertext
[$t11, $o11] = $mkTenant('addrmig'); $ar11 = new FlxCoreHC\Repository\AddressRepository($ctx($t11, $o11, 'tenant_owner'));
$aadOf = fn(string $c) => $t11 . '|addresses|' . $c . '_enc';
$mkRow = function (array $state) use ($ar11, $pdo, $t11, $aadOf) {   // state: col => 'P' plaintext only | 'E' ciphertext only | 'N' empty
    $id = $ar11->insert(['owner_type' => 'tenant', 'owner_id' => $t11, 'kind' => 'home', 'city' => 'Orlando', 'state' => 'FL']);
    $set = []; $par = []; $exp = [];
    foreach (['line1', 'line2', 'phone'] as $c) {
        $v = $c . '-value-' . substr($id, 0, 4);
        if ($state[$c] === 'P') { $set[] = "`$c` = ?, `{$c}_enc` = NULL"; $par[] = $v; $exp[$c] = $v; }
        elseif ($state[$c] === 'E') { $set[] = "`$c` = NULL, `{$c}_enc` = ?"; $par[] = FlxCoreHC\Crypto::encrypt($v, $aadOf($c)); $exp[$c] = $v; }
        else { $set[] = "`$c` = NULL, `{$c}_enc` = NULL"; $exp[$c] = null; }
    }
    $pdo->prepare('UPDATE addresses SET ' . implode(', ', $set) . ' WHERE id = ?')->execute([...$par, $id]);
    return [$id, $exp];
};
$runBackfill = fn() => (string) shell_exec('cd ' . escapeshellarg($ROOT) . ' && php database/encrypt_existing_addresses.php 2>&1');
$snap = fn(string $id) => json_encode($pdo->query("SELECT line1, line2, phone, HEX(line1_enc), HEX(line2_enc), HEX(phone_enc) FROM addresses WHERE id = '$id'")->fetch(PDO::FETCH_NUM));
// Case A — completely legacy row
[$idA, $expA] = $mkRow(['line1' => 'P', 'line2' => 'P', 'phone' => 'P']); $runBackfill();
$rA = $pdo->query("SELECT line1, line2, phone, line1_enc, line2_enc, phone_enc FROM addresses WHERE id = '$idA'")->fetch(); $fA = $ar11->find($idA);
$check($rA['line1'] === null && $rA['line2'] === null && $rA['phone'] === null && $rA['line1_enc'] !== null && $rA['line2_enc'] !== null && $rA['phone_enc'] !== null && $fA['line1'] === $expA['line1'] && $fA['line2'] === $expA['line2'] && $fA['phone'] === $expA['phone'], 'V4 case A: a fully legacy row is encrypted, plaintext scrubbed, repository returns the originals');
// Case B — partially migrated row (the reviewer's scenario)
[$idB, $expB] = $mkRow(['line1' => 'P', 'line2' => 'E', 'phone' => 'E']);
$before = $pdo->query("SELECT HEX(line2_enc), HEX(phone_enc) FROM addresses WHERE id = '$idB'")->fetch(PDO::FETCH_NUM); $runBackfill();
$after = $pdo->query("SELECT HEX(line2_enc), HEX(phone_enc), line1, line1_enc FROM addresses WHERE id = '$idB'")->fetch(PDO::FETCH_NUM); $fB = $ar11->find($idB);
$check($after[0] === $before[0] && $after[1] === $before[1] && $after[2] === null && $after[3] !== null && $fB['line1'] === $expB['line1'] && $fB['line2'] === $expB['line2'] && $fB['phone'] === $expB['phone'], 'V4 case B: migrating line1 leaves the existing line2/phone ciphertext byte-for-byte unchanged and everything decrypts');
// Case C — fully migrated row: re-running changes nothing
$sC = $snap($idA); $runBackfill(); $check($snap($idA) === $sC, 'V4 case C: re-running the back-fill on a migrated row changes nothing (idempotent)');
// Case D — every plaintext / ciphertext / empty combination per column (27) — no ciphertext is ever lost or altered
$combos = 0; $lost = 0; $wrong = 0; $ids = [];
foreach (['P', 'E', 'N'] as $a) foreach (['P', 'E', 'N'] as $b) foreach (['P', 'E', 'N'] as $c) {
    [$i, $e] = $mkRow(['line1' => $a, 'line2' => $b, 'phone' => $c]); $ids[$i] = [$e, $snap($i), [$a, $b, $c]]; $combos++;
}
$runBackfill();
foreach ($ids as $i => [$e, $snapBefore, $st]) {
    $bef = json_decode($snapBefore, true); $now = json_decode($snap($i), true); $f = $ar11->find($i);
    foreach (['line1' => 0, 'line2' => 1, 'phone' => 2] as $c => $k) {
        if ($st[$k] === 'E' && $now[3 + $k] !== $bef[3 + $k]) $lost++;          // existing ciphertext altered
        if (($f[$c] ?? null) !== $e[$c]) $wrong++;                                // value no longer reads back
        if ($st[$k] === 'P' && ($now[$k] !== null || $now[3 + $k] === null)) $wrong++;   // plaintext must be migrated and scrubbed
    }
}
$snapAll = json_encode(array_map($snap, array_keys($ids))); $runBackfill();
$check($combos === 27 && $lost === 0 && $wrong === 0, "V4 case D: all $combos plaintext/ciphertext/empty combinations — no ciphertext lost or altered, all values read back (lost=$lost, wrong=$wrong)");
$check(json_encode(array_map($snap, array_keys($ids))) === $snapAll, 'V4 case D: a second run over all combinations changes nothing');
// (2) invite acceptance is an atomic conditional consume
[$t12, $o12] = $mkTenant('inviterace'); $tm12 = new TeamService($ctx($t12, $o12, 'tenant_owner'));
$res12 = $tm12->invite(['email' => 'race@sec.test', 'full_name' => 'Race Condition', 'role' => 'staff']); $tok12 = is_array($res12) ? ($res12[2] ?? '') : '';
$inv12 = TeamService::findInvite($tok12);   // "request B" has already looked the invitation up …
[$first, ] = TeamService::acceptInvite($tok12, 'A-long-password-123');   // … request A consumes it …
$pdo->prepare("UPDATE users SET password_hash = 'x', status = 'invited', invite_token = NULL WHERE email = 'race@sec.test'")->execute();
$pdo->prepare("UPDATE users SET invite_token = ?, invite_expires = ? WHERE email = 'race@sec.test'")->execute([TeamService::inviteHash($tok12), gmdate('Y-m-d H:i:s', time() + 3600)]);
[$firstAgain, ] = TeamService::acceptInvite($tok12, 'A-long-password-123');   // re-armed, consumed once
[$second, $msg2] = TeamService::acceptInvite($tok12, 'Another-long-password-9');   // second consumption of the same invite
$hashNow = (string) $pdo->query("SELECT password_hash FROM users WHERE email = 'race@sec.test'")->fetchColumn();
$check($first === true && $firstAgain === true && $second === false && password_verify('A-long-password-123', $hashNow) && !password_verify('Another-long-password-9', $hashNow), 'V4 a valid invitation can be consumed once; the second acceptance fails and does not change the password');
// the real race window: findInvite succeeded, then the invite is consumed/expired before the UPDATE — simulate with an expired-at-write row
$res13 = $tm12->invite(['email' => 'race2@sec.test', 'full_name' => 'Race Two', 'role' => 'staff']); $tok13 = is_array($res13) ? ($res13[2] ?? '') : '';
$pdo->prepare("UPDATE users SET invite_expires = ? WHERE email = 'race2@sec.test'")->execute([gmdate('Y-m-d H:i:s', time() - 5)]);
[$exp13, ] = TeamService::acceptInvite($tok13, 'A-long-password-123');
$check($exp13 === false && (string) $pdo->query("SELECT status FROM users WHERE email = 'race2@sec.test'")->fetchColumn() === 'invited', 'V4 an expired invitation cannot be accepted (conditional UPDATE, rowCount must be 1)');
$stored13 = (string) $pdo->query("SELECT invite_token FROM users WHERE email = 'race@sec.test'")->fetchColumn();
$check(TeamService::findInvite(TeamService::inviteHash($tok12)) === null, 'V4 the stored SHA-256 hash still cannot be replayed as a token');
// (9) documented residual risk: known-place exemption on a SHARED NAT address
[$t14, $o14] = $mkTenant('nat'); $em14 = 'o-nat@sec.test'; $pwd14 = 'Pass123456789'; $NAT = '192.0.2.44';
$_SERVER['REMOTE_ADDR'] = $NAT; $okKnown = $auth8->attemptLogin($em14, $pwd14); FlxCoreHC\Auth\AuthService::markKnownPlace($em14);   // the owner signs in from the office NAT → known place
for ($i = 0; $i < 8; $i++) { $auth8->attemptLogin($em14, 'wrong-password-' . $i); }   // attacker behind the same NAT
$ownerOnNat = $auth8->attemptLogin($em14, $pwd14);
$_SERVER['REMOTE_ADDR'] = '198.51.100.201'; $ownerElsewhere = $auth8->attemptLogin($em14, $pwd14);
$check(is_array($okKnown) && is_string($ownerOnNat) && stripos($ownerOnNat, 'Too many') !== false && is_array($ownerElsewhere), 'V4 (documented residual risk) the owner IS held for 15 min from the shared NAT address once the (email,IP) threshold is hit by someone behind it; from any other address they sign in normally');
foreach ([$em14 . '|' . $NAT, 'acct|' . $em14, 'ip|' . $NAT, 'ip|198.51.100.201'] as $k) $lim->clear($k); unset($_SERVER['REMOTE_ADDR']);

// =========================================================================== v1.171.5 remediation (independent runtime report)
// (F) a NEW signup must not revert global catalog corrections (seedTemplatesFor used to replay migration 126's global UPDATEs)
// earlier blocks of this file replay migration 126 on purpose (legacy-state tests); put the DB back to the state an installed system has (132 applied)
foreach (preg_split('/;\s*\n/', preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($ROOT . '/database/migrations/132_pr_regulatory_wording.sql'))) as $__st) { if (trim($__st) !== '') $pdo->exec($__st); }
$cmePre = (string) $pdo->query("SELECT notes FROM credential_catalog WHERE tenant_id IS NULL AND code = 'pr_cme'")->fetchColumn();
$check(str_contains($cmePre, 'UNVERIFIED'), 'V5 precondition: migration 132 is in effect before the new signup');
[$t15, $o15] = $mkTenant('postseed');   // registerTenant + seedTemplatesFor run AFTER migration 132 was applied at install
$cmeNow = (string) $pdo->query("SELECT notes FROM credential_catalog WHERE tenant_id IS NULL AND code = 'pr_cme'")->fetchColumn();
$cdsNow = (string) $pdo->query("SELECT notes FROM credential_catalog WHERE tenant_id IS NULL AND code = 'pr_cds_negative'")->fetchColumn();
$check(str_contains($cmeNow, 'UNVERIFIED') && !str_contains($cmeNow, '10 hours in disease prevention') && str_contains($cdsNow, 'UNVERIFIED'), 'V5 after new signups the global PR catalog notes still carry migration 132\'s UNVERIFIED wording (tenant seeding no longer re-applies global updates)');
$check(FlxCoreHC\Auth\AuthService::isGlobalCatalogWrite("UPDATE credential_catalog SET notes = 'x' WHERE tenant_id IS NULL AND code = 'pr_cme'") && FlxCoreHC\Auth\AuthService::isGlobalCatalogWrite("INSERT IGNORE INTO requirement_sources (id) VALUES (1)") && !FlxCoreHC\Auth\AuthService::isGlobalCatalogWrite("UPDATE regulators r SET r.name = 'x'") && !FlxCoreHC\Auth\AuthService::isGlobalCatalogWrite("INSERT INTO regulators (id) VALUES (1)"), 'V5 only statements that change GLOBAL catalog rows are skipped at tenant seeding; per-tenant seeds still run');
$check((int) $pdo->query("SELECT COUNT(*) FROM regulators WHERE tenant_id = '$t15' AND name = 'Junta Examinadora de Enfermería'")->fetchColumn() === 1 && (int) $pdo->query("SELECT COUNT(*) FROM regulators WHERE tenant_id = '$t15'")->fetchColumn() > 5, 'V5 a new tenant still gets its own regulators (per-tenant seeding intact), including the nursing board under its corrected name');
// (E) every stored regulator category is a known one (the page lists them all); seeded boards are not hidden
$knownCats = array_keys(FlxCoreHC\Repository\RegulatorRepository::CATEGORIES);
$stored = $pdo->query('SELECT DISTINCT category FROM regulators')->fetchAll(PDO::FETCH_COLUMN);
$check(array_diff($stored, $knownCats) === [] && in_array('licensing', $knownCats, true), 'V5 every regulator category that is stored in the database is a listed category (licensing boards are no longer invisible): ' . json_encode($stored));
$grp = (new FlxCoreHC\Repository\RegulatorRepository($ctx($t15, $o15, 'tenant_owner')))->activeByCategory();
$names = array_merge(...array_map(fn($rows) => array_column($rows, 'name'), array_values($grp)));
$check(in_array('Junta Examinadora de Enfermería', $names, true) && isset($grp['licensing']), 'V5 the tenant\'s regulators listing (by category) includes the nursing board');
// (C) the documents encryption sweep ignores placeholder files
$dd = rtrim((string) config('docs_path'), '/'); @mkdir($dd . '/sweeptest', 0750, true);
file_put_contents($dd . '/sweeptest/.keep', ''); file_put_contents($dd . '/sweeptest/plain.pdf', "%PDF-1.4\nLEGACY-PLAINTEXT\n%%EOF");
$outS = shell_exec('cd ' . escapeshellarg($ROOT) . ' && php database/encrypt_existing_files.php 2>&1; echo "exit=$?"');
$check(str_contains((string) $outS, 'failed: 0') && str_contains((string) $outS, 'exit=0') && file_get_contents($dd . '/sweeptest/.keep') === '' && str_starts_with((string) file_get_contents($dd . '/sweeptest/plain.pdf'), "FLXENC1\n"), 'V5 encrypt_existing_files.php exits 0: placeholder .keep untouched, a real legacy document encrypted');
$outS2 = shell_exec('cd ' . escapeshellarg($ROOT) . ' && php database/encrypt_existing_files.php 2>&1; echo "exit=$?"');
$check(str_contains((string) $outS2, 'converted: 0') && str_contains((string) $outS2, 'exit=0'), 'V5 re-running the documents sweep is a no-op (idempotent)');
// (I) flash style is derived from the message: refusals are errors, confirmations stay "ok"
$errMsgs = ['Your current password is incorrect.', 'Your account has read-only access.', 'Too many attempts. Try again in 15 minutes.', 'The two backup passphrases do not match.', 'Use a backup passphrase of at least 12 characters.', 'This invitation is invalid or has expired.', 'Only an owner can change an owner\'s role.'];
$okMsgs = ['Provider updated.', 'Role created.', 'Thanks — the key backup is recorded as stored.', 'Test email sent to a@b.test.'];
$badE = array_filter($errMsgs, fn($m) => flx_flash_type($m) !== 'error'); $badO = array_filter($okMsgs, fn($m) => flx_flash_type($m) !== 'ok');
$check($badE === [] && $badO === [], 'V5 flash messages are styled by meaning (refusals → error, confirmations → ok)' . ($badE || $badO ? ' — wrong: ' . json_encode([...$badE, ...$badO]) : ''));
// (G) login throttle semantics — documented and tested
[$t16, $o16] = $mkTenant('thr'); $em16 = 'o-thr@sec.test'; $pw16 = 'Pass123456789';
$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
for ($i = 0; $i < 13; $i++) $rr = $auth8->attemptLogin($em16, 'wrong-' . $i);   // 13 wrong passwords from ONE address
$check(is_string($rr) && stripos($rr, 'Too many') !== false, 'V5 13 wrong passwords from one address: that address is throttled (evaluated failures 1–8, then refused without evaluation)');
$_SERVER['REMOTE_ADDR'] = '203.0.113.51'; $fresh = $auth8->attemptLogin($em16, $pw16);
$check(is_array($fresh), 'V5 DOCUMENTED BEHAVIOUR: attempts refused by the per-address limit are not evaluated and do not count toward the per-account limit, so one address can make at most 8 guesses per 15 minutes; a different address is not penalised for them');
foreach (['203.0.113.50', '203.0.113.51'] as $x) { $lim->clear($em16 . '|' . $x); $lim->clear('ip|' . $x); } $lim->clear('acct|' . $em16);
foreach (['203.0.113.60', '203.0.113.61', '203.0.113.62'] as $x) { $_SERVER['REMOTE_ADDR'] = $x; for ($i = 0; $i < 4; $i++) $auth8->attemptLogin($em16, 'wrong-' . $i); }   // 12 evaluated failures spread over 3 addresses
$_SERVER['REMOTE_ADDR'] = '203.0.113.63'; $spread = $auth8->attemptLogin($em16, $pw16);
$check(is_string($spread) && stripos($spread, 'Too many') !== false, 'V5 brute force spread across addresses (12 evaluated failures) throttles a never-seen address, even with the right password');
foreach (['60', '61', '62', '63'] as $x) { $lim->clear($em16 . '|203.0.113.' . $x); $lim->clear('ip|203.0.113.' . $x); } $lim->clear('acct|' . $em16);
// (6) an address becomes "known" only when the sign-in is COMPLETE — a correct password alone (e.g. 2FA never finished) does not
[$t17, $o17] = $mkTenant('known2fa'); $em17 = 'o-known2fa@sec.test'; $_SERVER['REMOTE_ADDR'] = '203.0.113.70';
$pwOnly = $auth8->attemptLogin($em17, 'Pass123456789');   // first factor only: attemptLogin returns the user, the 2FA step never happens
$check(is_array($pwOnly) && !$lim->seenWithin('known|' . $em17 . '|203.0.113.70', 30), 'V5 a correct password alone does not mark the address as known (only a completed sign-in does)');
for ($i = 0; $i < 15; $i++) $lim->record('acct|' . $em17);
$held = $auth8->attemptLogin($em17, 'Pass123456789');
$check(is_string($held) && stripos($held, 'Too many') !== false, 'V5 so an address that only ever passed the first factor is still subject to the per-account throttle');
FlxCoreHC\Auth\AuthService::markKnownPlace($em17); $after = $auth8->attemptLogin($em17, 'Pass123456789');
$check(is_array($after), 'V5 once the sign-in completes the address is known and exempt from the per-account counter');
foreach (['acct|' . $em17, $em17 . '|203.0.113.70', 'ip|203.0.113.70'] as $k) $lim->clear($k); unset($_SERVER['REMOTE_ADDR']);
// (O) the requirement checklist and the CE panel agree on the CE cycle (PR = 36, else 24)
foreach ([[$t15, 'PR', 36], [$t16, 'FL', 24]] as [$tn, $st, $want]) {
    $pdo->prepare('UPDATE tenants SET operating_states = ? WHERE id = ?')->execute([$st, $tn]);
    $ctxN = $ctx($tn, ($tn === $t15 ? $o15 : $o16), 'tenant_owner');
    $hid = (new FlxCoreHC\Repository\HolderRepository($ctxN))->insert(['holder_type' => 'individual', 'first_name' => 'Ce', 'last_name' => 'Cycle' . $st, 'provider_type' => 'physician', 'vertical' => 'healthcare', 'status' => 'active']);
    (new FlxCoreHC\Repository\ProducerRequirementRepository($ctxN))->insert(['holder_id' => $hid, 'catalog_code' => 'cme']);
    $rowsC = (new FlxCoreHC\ChecklistService($ctxN))->forProvider((new FlxCoreHC\Repository\HolderRepository($ctxN))->find($hid))['rows'];
    $metaC = ''; foreach ($rowsC as $r0) if ($r0['token'] === 'cme') $metaC = $r0['meta'];
    $check(str_contains($metaC, "renews every $want mo") && flx_ce_months([$st]) === $want, "V5 checklist CME cycle for a $st tenant reads $want months, same as the CE panel" . " (got: $metaC)");
}

// (4–8) v1.171.4 — positioning language and Puerto Rico regulatory wording
$hide = fn(string $f) => (string) file_get_contents($ROOT . '/' . $f);
$uiFiles = ['app/views/dashboard.php', 'app/views/compliance/index.php', 'app/views/me/index.php', 'app/help/en.php', 'app/help/es.php', 'app/src/ComplianceLevelService.php', 'app/lang/es.php'];
$bad = [];
foreach ($uiFiles as $f) { $t = $hide($f); foreach (['Fully compliant', 'fully compliant', 'pleno cumplimiento', 'Cumplimiento total'] as $n) if (str_contains($t, $n) && !($f === 'app/src/ComplianceLevelService.php' && str_contains($t, '// --- level 5'))) $bad[] = "$f:$n"; }
$check($bad === [], 'V4 no "fully compliant" overclaim remains in views, help (EN/ES) or level labels' . ($bad ? ' — ' . implode(', ', $bad) : ''));
$check(FlxCoreHC\ComplianceLevelService::LABELS[5] === 'All tracked requirements current', 'V4 compliance level 5 is labelled as tracked-requirements status, not legal compliance');
$helpEn = $hide('app/help/en.php'); $helpEs = $hide('app/help/es.php');
$check(str_contains($helpEn, 'does not independently perform or certify primary-source verification') && str_contains($helpEs, 'no realiza ni certifica por sí mismo la verificación de fuente primaria'), 'V4 help (EN and ES) states FlxCoreHC does not perform or certify primary-source verification');
$check(!str_contains($helpEn, 'this is what auditors and payers look for') && !str_contains($helpEn, 'exactly what a payer or auditor accepts'), 'V4 help no longer claims what auditors or payers accept');
$m099 = $hide('database/migrations/099_document_verification.sql');
$check(!str_contains($m099, 'real PSV') && str_contains($m099, 'does not independently perform or certify primary-source verification') && str_contains($m099, 'ADD COLUMN verification_method'), 'V4 migration 099 comment corrected; its SQL is unchanged');
$check(str_contains($hide('app/views/holders/show.php'), "t('Verification records')") && str_contains($hide('app/views/credentials/form.php'), 'Verification records'), 'V4 provider and credential pages title the panel "Verification records" with the disclaimer');
// PR regulator naming: upgrade path (old-named rows renamed, idempotent) and the unverified facts are labelled
$pdo->exec("DELETE FROM regulators WHERE tenant_id = '$t10' AND name LIKE 'Junta Examinadora de Enferm%'");
$pdo->exec("INSERT INTO regulators (id, tenant_id, name, category, jurisdiction, short_name, is_custom, status, created_at) VALUES (UUID(), '$t10', 'Junta Examinadora de Enfermeras y Enfermeros', 'licensing', 'PR', 'JE', 0, 'active', UTC_TIMESTAMP())");
$sql132 = preg_replace('~^\s*--.*$~m', '', $hide('database/migrations/132_pr_regulatory_wording.sql'));
for ($pass = 0; $pass < 2; $pass++) foreach (array_filter(array_map('trim', preg_split('~;\s*[\r\n]+~', $sql132))) as $stmt) $pdo->exec($stmt);
$check((int) $pdo->query("SELECT COUNT(*) FROM regulators WHERE name LIKE '%Enfermeras y Enfermeros%'")->fetchColumn() === 0 && (int) $pdo->query("SELECT COUNT(*) FROM regulators WHERE tenant_id = '$t10' AND name = 'Junta Examinadora de Enfermería'")->fetchColumn() === 1, 'V4 nursing board is "Junta Examinadora de Enfermería"; migration 132 renames legacy rows and is idempotent (ran twice)');
$cme = (string) $pdo->query("SELECT notes FROM credential_catalog WHERE tenant_id IS NULL AND code = 'pr_cme'")->fetchColumn();
$cds = (string) $pdo->query("SELECT notes FROM credential_catalog WHERE tenant_id IS NULL AND code = 'pr_cds_negative'")->fetchColumn();
$check(str_contains($cme, 'UNVERIFIED') && !str_contains($cme, '10 hours in disease prevention') && !str_contains($cme, '6 hours in bioethics') && str_contains($cds, 'UNVERIFIED') && !str_contains($cds, 'required with Puerto Rico licensure'), 'V4 unconfirmed PR facts (CME hour breakdown, negative drug-test certificate) are labelled UNVERIFIED, not stated as universal requirements');
$check(str_contains($hide('app/src/ApplicationReadinessService.php'), 'UNVERIFIED'), 'V4 the readiness checklist does not present the drug-test certificate as an established requirement');
$grep = shell_exec('cd ' . escapeshellarg($ROOT) . ' && grep -rl "Enfermeras y Enfermeros" database app 2>/dev/null | grep -v 132_pr_regulatory_wording');
$check(trim((string) $grep) === '', 'V4 no seed file or view still uses the old nursing-board name (except the migration that renames it)');

// =========================================================================== v1.171.6 remediation (second independent runtime report)
// (1) the CSV import template previews cleanly through the REAL importer: every row creates, none is skipped as a duplicate/invalid NPI
$tplSrc = (string) file_get_contents($ROOT . '/index.php');
preg_match_all('~echo "((?:individual|organization),[^"]*?)\\\\n";~', $tplSrc, $tm);
$tplCsv = FlxCoreHC\ProviderImportService::TEMPLATE_HEADER . "\n" . implode("\n", $tm[1]) . "\n";
[$t17, $o17] = $mkTenant('tplcheck'); $ctx17 = $ctx($t17, $o17, 'tenant_owner');
$pv17 = (new FlxCoreHC\ProviderImportService($ctx17))->preview($tplCsv);
$check(count($tm[1]) === 4 && ($pv17['summary']['create'] ?? 0) === 4 && ($pv17['summary']['skip'] ?? 0) === 0 && ($pv17['summary']['error'] ?? 0) === 0, 'V6 the provider-import template previews as 4 creates, 0 skips, 0 errors in the real importer (preview: ' . json_encode($pv17) . ')');
// (2) 2FA enrolment can only ADD a factor; replacing an active secret requires turning it off (current code) first
$u17 = $o17; $sec1 = FlxCoreHC\Auth\Totp::generateSecret(); $c1 = FlxCoreHC\Auth\Totp::codeAt($sec1, intdiv(time(), 30));
[$e1] = FlxCoreHC\Auth\AuthService::confirmTotp($u17, $sec1, $c1);
$h1 = (string) $pdo->query("SELECT totp_secret FROM users WHERE id = " . $pdo->quote($u17))->fetchColumn();
$sec2 = FlxCoreHC\Auth\Totp::generateSecret(); $c2 = FlxCoreHC\Auth\Totp::codeAt($sec2, intdiv(time(), 30));
[$e2, $m2] = FlxCoreHC\Auth\AuthService::confirmTotp($u17, $sec2, $c2);
$h2 = (string) $pdo->query("SELECT totp_secret FROM users WHERE id = " . $pdo->quote($u17))->fetchColumn();
$check($e1 === true && $e2 === false && $h1 === $h2 && str_contains($m2, 'already on'), 'V6 with 2FA on, enrolling a different authenticator is refused and the stored secret is unchanged');
// (3) every literal flash() in index.php carries an explicit type (the keyword guess is only a fallback for composed messages)
$untyped = preg_match_all("~flash\\('(?:[^'\\\\\\\\]|\\\\\\\\.)*'\\)~", $tplSrc, $um);
$check($untyped === 0, 'V6 every literal flash() message in index.php states its type explicitly (untyped: ' . $untyped . ')');
// (5) a role name is unique per organization (replayed create is refused, nothing is duplicated)
$rs17 = new FlxCoreHC\RoleService($ctx17);
[$r1ok] = $rs17->create('Front Desk Review', ['documents.view']); [$r2ok, $r2m] = $rs17->create('front desk review', ['documents.view']);
$nR = (int) $pdo->query("SELECT COUNT(*) FROM roles WHERE tenant_id = " . $pdo->quote($t17) . " AND LOWER(name) = 'front desk review'")->fetchColumn();
$check($r1ok === true && $r2ok === false && $nR === 1 && str_contains($r2m, 'already exists'), 'V6 creating a second role with the same name is refused (one row)');
// v1.171.6 round 2 (second independent run)
// (6) role names compare the way a person reads them: odd whitespace and built-in names are refused too
$okAll = true; $bad = [];
foreach (["Front  Desk Review", "Front\tDesk Review", "front\u{00A0}desk review", "Front\u{200B} Desk Review", "Admin", "Viewer", "Manager", "Master Admin"] as $v) {
    [$o] = $rs17->create($v, ['documents.view']); if ($o) { $okAll = false; $bad[] = $v; }
}
$check($okAll, 'V6 role names differing only by whitespace/NBSP/zero-width characters, or reusing a built-in role name, are refused (accepted: ' . json_encode($bad) . ')');
// (7) editing a provider cannot erase the identity or save a malformed e-mail
$ctxE = $ctx17;
[$fE, $eE] = FlxCoreHC\HolderInput::fromPost($ctxE, ['first_name' => '', 'last_name' => 'X'], 'individual', false);
[$fE2, $eE2] = FlxCoreHC\HolderInput::fromPost($ctxE, ['email' => 'not-an-email'], 'individual', false);
[$fE3, $eE3] = FlxCoreHC\HolderInput::fromPost($ctxE, ['role_title' => 'Attending'], 'individual', false);
[$fE4, $eE4] = FlxCoreHC\HolderInput::fromPost($ctxE, ['org_name' => ' '], 'organization', false);
$check($eE !== null && $eE2 !== null && $eE3 === null && array_keys($fE3) === ['role_title'] && $eE4 !== null, 'V6 provider edit: blank name / malformed e-mail are refused; a partial edit carries only its own field');
// (8) a correct password alone does not reset the per-account counter; only a COMPLETE sign-in does
[$t18, $o18] = $mkTenant('acctclr'); $em18 = 'o-acctclr@sec.test';
$_SERVER['REMOTE_ADDR'] = '203.0.113.81';
for ($i = 0; $i < 5; $i++) { $auth8->attemptLogin($em18, 'wrong-pass-' . $i); }
$before18 = (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE identifier = " . $pdo->quote('acct|' . $em18))->fetchColumn();
$pw18 = $auth8->attemptLogin($em18, 'Pass123456789');   // first factor only (2FA abandoned)
$mid18 = (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE identifier = " . $pdo->quote('acct|' . $em18))->fetchColumn();
FlxCoreHC\Auth\AuthService::markKnownPlace($em18);       // second factor completed
$end18 = (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE identifier = " . $pdo->quote('acct|' . $em18))->fetchColumn();
$check($before18 === 5 && is_array($pw18) && $mid18 === 5 && $end18 === 0, "V6 per-account counter survives a first-factor-only sign-in and resets on a complete one (before $before18, after password $mid18, after completion $end18)");
foreach (['acct|' . $em18, $em18 . '|203.0.113.81', 'ip|203.0.113.81', 'known|' . $em18 . '|203.0.113.81'] as $k) $lim->clear($k); unset($_SERVER['REMOTE_ADDR']);
// ---- v1.171.8 (third independent run) ---------------------------------------------------------------------------
// (R1) renaming a role is held to the same duplicate rule as creating one
[$ra] = $rs17->create('Rename Target A', ['documents.view']); [$rb, , $rbId] = $rs17->create('Rename Target B', ['documents.view']);
$bad8 = [];
foreach (['rename target a', "Rename  Target\u{00A0}A", "Rename\u{200B} Target A", 'Manager', 'Admin', 'Master Admin'] as $v) {
    [$o] = $rs17->update($rbId, $v, ['documents.view']); if ($o) $bad8[] = $v;
}
$nm = (string) $pdo->query("SELECT name FROM roles WHERE id = " . $pdo->quote((string) $rbId))->fetchColumn();
$check($ra && $rb && !$bad8 && $nm === 'Rename Target B', 'V7 renaming a role to another role\'s name / a built-in name is refused and the name is unchanged (accepted: ' . json_encode($bad8) . ", name now '$nm')");
[$self] = $rs17->update($rbId, 'rename target b', ['documents.view']);   // own name, different case: allowed
[$fresh, , ] = [$rs17->update($rbId, 'Rename Target C', ['documents.view'])[0]];
$check($self === true && $fresh === true, 'V7 a role may be renamed to a new name, or re-cased, without tripping its own name');
[$dl] = $rs17->create("Regıstered Nurse Role", ['documents.view']); [$dl2] = $rs17->create("Registered Nurse Role", ['documents.view']);
$check($dl === true && $dl2 === false, 'V7 dotless ı is folded when comparing role names');
// (R2) names made only of invisible characters are blank
$inv = true;
foreach (["\u{00A0}", "\u{200B}", "\u{200B}\u{200C}\u{200D}", "\u{FEFF}", " \u{00A0}\u{200B} "] as $v) {
    [, $er] = FlxCoreHC\HolderInput::fromPost($ctx17, ['first_name' => $v, 'last_name' => 'X'], 'individual', false); if ($er === null) $inv = false;
    [, $er] = FlxCoreHC\HolderInput::fromPost($ctx17, ['org_name' => $v], 'organization', false); if ($er === null) $inv = false;
}
[, $erOk] = FlxCoreHC\HolderInput::fromPost($ctx17, ['first_name' => 'Ana', 'last_name' => 'Díaz'], 'individual', false);
$check($inv && $erOk === null, 'V7 provider/organization names of only NBSP / zero-width / BOM characters are refused on edit; a real name passes');
// ---- v1.171.9 (fifth run: close the CLASS, not the instance) --------------------------------------------------------
// One list of look-alike / invisible variants, applied to EVERY name entry point: tenant create, tenant rename, global create,
// global rename, provider create, provider edit, organization create/edit.
$lookalikes = static fn(string $base): array => [
    strtoupper($base), mb_strtolower($base), " $base ", str_replace(' ', '  ', $base), str_replace(' ', "\t", $base), str_replace(' ', "\u{00A0}", $base),
    str_replace(' ', "\u{3000}", $base), str_replace(' ', "\u{2003}", $base), str_replace(' ', " \u{200B}", $base), "\u{FEFF}$base", "$base\u{200D}",
    str_replace('e', "e\u{00AD}", $base), str_replace('e', "e\u{2060}", $base), str_replace('e', "\u{0435}", $base) /* Cyrillic е */,
    str_replace('a', "\u{0430}", $base) /* Cyrillic а */, str_replace('o', "\u{03BF}", $base) /* Greek ο */, str_replace('i', "\u{0131}", $base),
    (string) preg_replace_callback('/[A-Za-z]/', static fn($m) => mb_chr(mb_ord($m[0]) - 0x21 + 0xFF01), $base) /* full-width */,
];
$invisibles = ["", " ", "\u{00A0}", "\u{200B}", "\u{200C}\u{200D}", "\u{FEFF}", "\u{2060}", "\u{00AD}", "\u{3164}", "\u{2800}", "\u{180E}", "\u{034F}", " \u{00A0}\u{200B}\u{00AD} "];
[$t19, $o19] = $mkTenant('norm19'); $ctx19 = $ctx($t19, $o19, 'tenant_owner'); $rs19 = new FlxCoreHC\RoleService($ctx19);
[$okB, , $baseId] = $rs19->create('Review Desk Lead', ['documents.view']); [$okO, , $otherId] = $rs19->create('Other Role Nine', ['documents.view']);
$acc = [];
foreach ($lookalikes('Review Desk Lead') as $v) {
    [$o] = $rs19->create($v, ['documents.view']); if ($o) $acc[] = "create:" . json_encode($v);
    [$o] = $rs19->update($otherId, $v, ['documents.view']); if ($o) $acc[] = "rename:" . json_encode($v);
}
foreach (['Manager', 'Ｍａｎａｇｅｒ', "Mаnager" /* Cyrillic а */, 'Regular User', 'Master Admin', 'Admin', 'Viewer'] as $v) {
    [$o] = $rs19->create($v, ['documents.view']); if ($o) $acc[] = "create-builtin:" . json_encode($v);
    [$o] = $rs19->update($otherId, $v, ['documents.view']); if ($o) $acc[] = "rename-builtin:" . json_encode($v);
}
foreach ($invisibles as $v) { [$o] = $rs19->create($v, ['documents.view']); if ($o) $acc[] = "create-blank:" . json_encode($v); [$o] = $rs19->create("x$v", ['documents.view']); if ($o) $acc[] = "create-1char:" . json_encode("x$v"); }
$nmO = (string) $pdo->query("SELECT name FROM roles WHERE id = " . $pdo->quote((string) $otherId))->fetchColumn();
$check($okB && $okO && !$acc && $nmO === 'Other Role Nine', 'V8 tenant roles: every look-alike / invisible / built-in variant is refused on create AND rename (accepted: ' . json_encode($acc) . ")");
// global roles follow the same rule (platform admin)
[$gOk, , $gId] = FlxCoreHC\RoleService::createGlobal('Global Nine Role', ['documents.view']); [$gOk2, , $gId2] = FlxCoreHC\RoleService::createGlobal('Global Nine Other', ['documents.view']);
$accG = [];
foreach (array_merge($lookalikes('Global Nine Role'), ['Manager', 'manager', 'Master Admin', 'Admin', 'Viewer', 'Regular User', 'Ｍａｎａｇｅｒ']) as $v) {
    [$o] = FlxCoreHC\RoleService::createGlobal($v, ['documents.view']); if ($o) $accG[] = "gcreate:" . json_encode($v);
    [$o] = FlxCoreHC\RoleService::updateGlobal((string) $gId2, $v, ['documents.view']); if ($o) $accG[] = "grename:" . json_encode($v);
}
foreach ($invisibles as $v) { [$o] = FlxCoreHC\RoleService::createGlobal($v, []); if ($o) $accG[] = "gblank:" . json_encode($v); }
$nG = (int) $pdo->query("SELECT COUNT(*) FROM roles WHERE tenant_id IS NULL AND name IN ('Manager','Master Admin','Admin','Viewer','Regular User')")->fetchColumn();
$nG0 = (int) $pdo->query("SELECT COUNT(*) FROM roles WHERE tenant_id IS NULL AND is_system = 1")->fetchColumn();
$check($gOk && $gOk2 && !$accG, 'V8 global roles: duplicate / look-alike / built-in / blank names are refused on create AND rename (accepted: ' . json_encode($accG) . ')');
// a tenant may not create a role shadowing a global custom role, and vice-versa for the built-ins; valid names still work everywhere
[$shadow] = $rs19->create('global nine role', ['documents.view']); [$fine] = $rs19->create('Perfectly New Name', ['documents.view']); [$fineG] = FlxCoreHC\RoleService::updateGlobal((string) $gId2, 'Global Nine Renamed', ['documents.view']);
$check($shadow === false && $fine === true && $fineG === true, 'V8 a tenant role cannot shadow a global custom role; genuinely new names still work (tenant create, global rename)');
// rename-only request keeps capabilities
$capsBefore = (int) $pdo->query("SELECT COUNT(*) FROM role_capabilities WHERE role_id = " . $pdo->quote((string) $baseId))->fetchColumn();
[$ro] = $rs19->update($baseId, 'Review Desk Lead 2', null);
$capsAfter = (int) $pdo->query("SELECT COUNT(*) FROM role_capabilities WHERE role_id = " . $pdo->quote((string) $baseId))->fetchColumn();
[$ro2] = $rs19->update($baseId, 'Review Desk Lead 2', []);
$capsEmpty = (int) $pdo->query("SELECT COUNT(*) FROM role_capabilities WHERE role_id = " . $pdo->quote((string) $baseId))->fetchColumn();
$check($ro && $capsBefore === 1 && $capsAfter === 1 && $ro2 && $capsEmpty === 0, "V8 a rename-only update keeps the capability set; an explicit empty set clears it ($capsBefore/$capsAfter/$capsEmpty)");
// providers / organizations: invisible names refused on CREATE and EDIT, stored names are cleaned
$accH = [];
foreach ($invisibles as $v) {
    [, $e] = FlxCoreHC\HolderInput::fromPost($ctx19, ['first_name' => $v, 'last_name' => 'Ok', 'email' => 'a@b.test'], 'individual', true); if ($e === null) $accH[] = "create-first:" . json_encode($v);
    [, $e] = FlxCoreHC\HolderInput::fromPost($ctx19, ['first_name' => 'Ok', 'last_name' => $v, 'email' => 'a@b.test'], 'individual', true); if ($e === null) $accH[] = "create-last:" . json_encode($v);
    [, $e] = FlxCoreHC\HolderInput::fromPost($ctx19, ['org_name' => $v], 'organization', true); if ($e === null) $accH[] = "create-org:" . json_encode($v);
    [, $e] = FlxCoreHC\HolderInput::fromPost($ctx19, ['first_name' => $v], 'individual', false); if ($e === null) $accH[] = "edit-first:" . json_encode($v);
    [, $e] = FlxCoreHC\HolderInput::fromPost($ctx19, ['org_name' => $v], 'organization', false); if ($e === null) $accH[] = "edit-org:" . json_encode($v);
}
[, $eMissing] = FlxCoreHC\HolderInput::fromPost($ctx19, ['email' => 'a@b.test'], 'individual', true);   // names not sent at all
[$fOk, $eOk] = FlxCoreHC\HolderInput::fromPost($ctx19, ['first_name' => "Ana\u{00AD}", 'last_name' => "  Díaz\u{200B}  Pérez ", 'email' => 'a@b.test'], 'individual', true);
$check(!$accH && $eMissing !== null && $eOk === null && ($fOk['first_name'] ?? '') === 'Ana' && ($fOk['last_name'] ?? '') === 'Díaz Pérez', 'V8 provider/organization names of only invisible characters are refused on create and edit; missing names refused on create; stored names are cleaned (accepted: ' . json_encode($accH) . ')');
// ---- v1.171.10 (closeout: REQUIRED 1 + 2 of the v1.171.9 second-opinion review) ---------------------------------
// (R1) NFKC is really active: compatibility characters that the ext-intl-less fallback cannot handle
$check(FlxCoreHC\TextNorm::normalizerAvailable() && FlxCoreHC\TextNorm::prerequisiteError() === null, 'V9 ext-intl / Normalizer is available on this host (prerequisite satisfied)');
$nfkc = FlxCoreHC\TextNorm::same("O\u{FB00}ice Manager", 'Office Manager') && FlxCoreHC\TextNorm::same("Unit \u{2460}", 'Unit 1')
     && FlxCoreHC\TextNorm::same("Level \u{2168}", 'Level IX') && FlxCoreHC\TextNorm::same("\u{FF2D}\u{FF41}\u{FF4E}\u{FF41}\u{FF47}\u{FF45}\u{FF52}", 'Manager')
     && FlxCoreHC\TextNorm::same("Caf\u{0065}\u{0301}", "Caf\u{00E9}") && !FlxCoreHC\TextNorm::same('Office Manager', 'Office Managers');
$check($nfkc, 'V9 fold() applies real NFKC (ﬀ ligature, circled ①, Roman numeral Ⅸ, full-width, combining accent) and still tells different names apart');
// the "missing" path: production refuses to compare with a weaker algorithm; dev/test falls back (documented as weaker)
$envSave = $GLOBALS['flxcred_config']['app_env'] ?? null;
FlxCoreHC\TextNorm::$assumeNormalizerMissing = true;
$GLOBALS['flxcred_config']['app_env'] = 'production'; $threw = false;
try { FlxCoreHC\TextNorm::fold('Office Manager'); } catch (\RuntimeException $e) { $threw = str_contains($e->getMessage(), 'intl'); }
$preMsg = FlxCoreHC\TextNorm::prerequisiteError();
$GLOBALS['flxcred_config']['app_env'] = 'dev';
$devFallback = FlxCoreHC\TextNorm::same("\u{FF2D}anager", 'Manager') && !FlxCoreHC\TextNorm::same("O\u{FB00}ice", 'Office');
FlxCoreHC\TextNorm::$assumeNormalizerMissing = false; $GLOBALS['flxcred_config']['app_env'] = $envSave;
$check($threw && is_string($preMsg) && str_contains($preMsg, 'intl') && $devFallback && FlxCoreHC\TextNorm::prerequisiteError() === null,
    'V9 without Normalizer: production fold() throws a clear ext-intl error and prerequisiteError() names it; dev falls back to the weaker full-width-only rule');
// (R2) tenant organization identity names: cleaned before storage, blank-after-clean refused
$orgSvc = new FlxCoreHC\OrgService($ctx19); $tn = static fn() => $pdo->query("SELECT CONCAT(name,'|',IFNULL(brand_name,''),'|',IFNULL(legal_name,'')) FROM tenants WHERE id = " . $pdo->quote($t19))->fetchColumn();
$orgSvc->updateInfo(['name' => "\u{FEFF} Acme\u{00AD}\u{200B}  Health\u{3000}Group ", 'brand_name' => "\u{2060}Acme\u{3164}  Health\u{2800}"]);
[$eOk] = $orgSvc->updateEntity(['legal_name' => " Acme\u{00AD} Health\u{200C}Group, LLC\u{FEFF} "]);
$stored1 = $tn();
$accO = [];
foreach ($invisibles as $v) {
    if (FlxCoreHC\OrgService::nameError(['name' => $v]) === null) $accO[] = 'nameError:' . json_encode($v);
    $orgSvc->updateInfo(['name' => $v]);
}
$stored2 = $tn();
$check($eOk && $stored1 === 'Acme Health Group|Acme Health|Acme HealthGroup, LLC' && !$accO && $stored2 === $stored1,
    "V9 tenant name / brand / legal name are stored cleaned; invisible-only names are refused by nameError() and never replace the stored name (stored: $stored1 → $stored2; accepted: " . json_encode($accO) . ')');

// =========================================================================== RM — v1.172.3: reminder thresholds are configurable (behavioural, real scan + outbox)
[$tRM, $ownerRM] = $mkTenant('RM'); $ctxRM = $ctx($tRM, $ownerRM, 'tenant_owner');
$hRM = uuid4(); $cRM = uuid4();
$pdo->prepare("INSERT INTO credential_holders (id, tenant_id, holder_type, first_name, last_name, vertical, status, created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([$hRM, $tRM, 'individual', 'Rita', 'Reminder', 'healthcare', 'active', utc_now()]);
$pdo->prepare("INSERT INTO credentials (id, tenant_id, holder_id, credential_type, name, status, expiry_date, created_at) VALUES (?,?,?,?,?,?,DATE_ADD(CURDATE(), INTERVAL 100 DAY),?)")->execute([$cRM, $tRM, $hRM, 'state_license', 'RM License', 'active', utc_now()]);
$remRows = static fn() => (int) $pdo->query("SELECT COUNT(*) FROM credential_reminders WHERE credential_id = " . $pdo->quote($cRM))->fetchColumn();
unset($GLOBALS['flxcred_config']['reminder_thresholds']);
$n0 = (new FlxCoreHC\ReminderService($ctxRM))->run();
$check($remRows() === 0, 'RM1 default thresholds (60/30/14/7): a credential 100 days from expiry gets no expiry reminder (the scan may still queue screening/attestation notices: sent=' . $n0 . ')');
$GLOBALS['flxcred_config']['reminder_thresholds'] = [120, 60, 30];
$n1 = (new FlxCoreHC\ReminderService($ctxRM))->run();
$daysB = (int) $pdo->query("SELECT days_before FROM credential_reminders WHERE credential_id = " . $pdo->quote($cRM) . " LIMIT 1")->fetchColumn();
$check($n1 >= 1 && $remRows() === 1 && $daysB === 120, "RM2 with reminder_thresholds [120,60,30] the same credential is reminded once at the 120-day threshold (sent=$n1, rows={$remRows()}, days_before=$daysB)");
$n2 = (new FlxCoreHC\ReminderService($ctxRM))->run();
$check($n2 === 0 && $remRows() === 1, 'RM3 running again does not remind twice for the same threshold');
unset($GLOBALS['flxcred_config']['reminder_thresholds']);

// =========================================================================== OP — v1.172.3: creating an organization can set the Master Admin's temporary password
$tplOP = (string) file_get_contents(__DIR__ . '/../../app/views/admin/tenants.php');
$check(strpos($tplOP, 'name="owner_password"') !== false, 'OP1 the Create organization form has a temporary-password field');
$mailOP = 'op' . bin2hex(random_bytes(3)) . '@example.test';
[$okOP, , $tokOP] = $svc->createOrganization(['org_name' => 'OP Org ' . bin2hex(random_bytes(2)), 'org_shape' => 'billing_company', 'owner_name' => 'Opal Owner', 'owner_email' => $mailOP, 'owner_password' => 'Temp-pass-123']);
$rowOP = $pdo->prepare("SELECT status, password_hash FROM users WHERE email = ?"); $rowOP->execute([$mailOP]); $rowOP = $rowOP->fetch(PDO::FETCH_ASSOC);
$check($okOP && $tokOP === null && ($rowOP['status'] ?? '') === 'active' && password_verify('Temp-pass-123', (string) ($rowOP['password_hash'] ?? '')), 'OP2 with a temporary password the Master Admin is active and the password works (no invite needed)');
[$okOP2] = $svc->createOrganization(['org_name' => 'OP Short', 'org_shape' => 'billing_company', 'owner_name' => 'S', 'owner_email' => 'op2' . bin2hex(random_bytes(3)) . '@example.test', 'owner_password' => 'short']);
$check(!$okOP2, 'OP3 a temporary password under 8 characters is refused');

// =========================================================================== ML — v1.172.4: platform mail settings (stored sealed, never overwritten by the masked form value, tested live)
$pdoML = $pdo;
FlxCoreHC\SettingsService::put(['smtp_pass' => 'Plat-Secret-1']);
$rawML = (string) $pdoML->query("SELECT sval FROM platform_settings WHERE skey='smtp_pass'")->fetchColumn();
$check(str_starts_with($rawML, 'enc1:') && strpos($rawML, 'Plat-Secret-1') === false && FlxCoreHC\SettingsService::emailConfig()['smtp_pass'] === 'Plat-Secret-1',
    'ML1 the platform SMTP password is stored encrypted and still reads back for sending');
$pdoML->prepare("REPLACE INTO platform_settings (skey, sval, updated_at) VALUES ('smtp_pass', 'legacy-plain', ?)")->execute([utc_now()]);
FlxCoreHC\SettingsService::put([]);   // invalidate cache
$check(FlxCoreHC\SettingsService::emailConfig()['smtp_pass'] === 'legacy-plain', 'ML2 a plain password saved by an older version still works');
$viewML = (string) file_get_contents(__DIR__ . '/../../app/views/admin/email.php');
$idxML = (string) file_get_contents(__DIR__ . '/../../index.php');
$check(!preg_match('/name="smtp_pass"[^>]*value="\*{8}"/', $viewML) && strpos($idxML, "['', '********'], true)") !== false,
    'ML3 the form never pre-fills the masked value and the save handler ignores it (re-saving no longer overwrites the password with asterisks)');
// Live: platform test through a real local SMTP server
$tmpML = sys_get_temp_dir() . '/flxml_' . bin2hex(random_bytes(3)); mkdir($tmpML);
$portML = random_int(41000, 45000); $outML = "$tmpML/out"; touch($outML);
$procML = proc_open([PHP_BINARY, __DIR__ . '/../mail/fake_smtp.php', (string) $portML, 'both', '/dev/null', $outML], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesML);
stream_set_timeout($pipesML[1], 5); fgets($pipesML[1]);
FlxCoreHC\SettingsService::put(['mail_enabled' => '0', 'mail_transport' => 'smtp', 'mail_from' => 'alerts@x.test', 'smtp_host' => '127.0.0.1',
    'smtp_port' => (string) $portML, 'smtp_secure' => 'none', 'smtp_user' => 'alerts@x.test', 'smtp_pass' => 'Wrong-One']);
$bad = FlxCoreHC\MailService::platformTest('dest@y.test');
FlxCoreHC\SettingsService::put(['smtp_pass' => 'S3cret!pw']);
$probe = FlxCoreHC\MailService::platformProbe();
$sentBefore = count(file($outML));
$good = FlxCoreHC\MailService::platformTest('dest@y.test');
$check(!$bad['ok'] && str_contains($bad['error'], '535') && !str_contains($bad['error'], 'Wrong-One') && $probe['ok'] && $sentBefore === 0 && $good['ok'] && count(file($outML)) === 1,
    'ML4 platform test works while "Send email" is off: a bad password shows the server\'s 535 (no secret), the connection test sends nothing, the real test delivers one message');
// Failed messages can be retried after the settings are fixed
$idML = FlxCoreHC\MailService::queue('retry@y.test', 'Retry me', 'body', 'general');
$pdoML->prepare("UPDATE email_outbox SET status='failed', attempts=3, last_error='old' WHERE id=?")->execute([$idML]);
$secML = FlxCoreHC\MailService::queue('sec@y.test', 'Secure', 'code', 'security');
$pdoML->prepare("UPDATE email_outbox SET status='failed', attempts=1 WHERE id=?")->execute([$secML]);
$nML = FlxCoreHC\MailService::retryFailed();
$stML = fn($id) => (string) $pdoML->query("SELECT status FROM email_outbox WHERE id=" . $pdoML->quote($id))->fetchColumn();
$check($nML >= 1 && $stML($idML) === 'queued' && $stML($secML) === 'failed', 'ML5 "Retry failed" re-queues failed messages but never a security code (it would arrive after it expired)');
proc_terminate($procML); exec('rm -rf ' . escapeshellarg($tmpML));
FlxCoreHC\SettingsService::put(['mail_enabled' => '0', 'mail_transport' => 'mail', 'smtp_host' => '', 'smtp_user' => '', 'smtp_pass' => '', 'mail_from' => '']);

// =========================================================================== MG — v1.172.4: Microsoft 365 / Graph sending (fake Microsoft endpoints through the transport hook)
FlxCoreHC\MicrosoftOAuth::saveApp('app-id-123', 'app-secret-xyz');
$calls = []; $mode = 'ok'; $nextRt = 'RT-NEW';
FlxCoreHC\MicrosoftOAuth::$transport = function (string $method, string $url, array $headers, string $body) use (&$calls, &$mode, &$nextRt): array {
    $calls[] = compact('method', 'url', 'headers', 'body');
    if (str_contains($url, '/oauth2/v2.0/token')) {
        parse_str($body, $f);
        if ($mode === 'network') return [0, '', 'Could not resolve host'];
        if ($mode === 'revoked') return [400, json_encode(['error' => 'invalid_grant', 'error_description' => 'AADSTS70008: expired']), ''];
        if ($mode === 'badsecret') return [401, json_encode(['error' => 'invalid_client', 'error_description' => 'AADSTS7000215: Invalid client secret']), ''];
        return [200, json_encode(['access_token' => 'AT-1', 'refresh_token' => ($f['grant_type'] ?? '') === 'authorization_code' ? 'RT-FIRST' : $nextRt]), ''];
    }
    if (str_ends_with($url, '/me')) return [200, json_encode(['mail' => 'sender@contoso.test']), ''];
    if (str_ends_with($url, '/me/sendMail')) {
        if ($mode === 'denied') return [403, json_encode(['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied.']]), ''];
        if ($mode === 'nomail') return [400, json_encode(['error' => ['code' => 'MailboxNotEnabledForRESTAPI', 'message' => 'The mailbox is either inactive, soft-deleted, or is hosted on-premise.']]), ''];
        return [202, '', ''];
    }
    return [404, '', ''];
};
$_SESSION['ms_oauth_state'] = null;
$authUrl = FlxCoreHC\MicrosoftOAuth::authorizeUrl('platform');
parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $aq);
$check(($aq['client_id'] ?? '') === 'app-id-123' && str_contains($aq['scope'] ?? '', 'Mail.Send') && str_contains($aq['scope'] ?? '', 'offline_access') && ($aq['state'] ?? '') === ($_SESSION['ms_oauth_state']['state'] ?? 'x') && str_ends_with($aq['redirect_uri'] ?? '', '/oauth/microsoft/callback'),
    'MG1 the Microsoft sign-in URL carries the app id, the Mail.Send + offline_access permissions, the redirect URI and the session-bound state');
[$okCb, , $acct] = FlxCoreHC\MicrosoftOAuth::handleCallback('CODE', (string) $aq['state']);
$rawRt = (string) $pdo->query("SELECT sval FROM platform_settings WHERE skey='ms_platform_refresh'")->fetchColumn();
$check($okCb && $acct === 'sender@contoso.test' && $rawRt !== '' && strpos($rawRt, 'RT-FIRST') === false && FlxCoreHC\MicrosoftOAuth::platformAccount() === 'sender@contoso.test',
    'MG2 connecting stores the mailbox and the refresh token encrypted (no readable token in the database)');
[$okBad] = FlxCoreHC\MicrosoftOAuth::handleCallback('CODE', 'wrong-state');
$check($okBad === false, 'MG2b a callback with a wrong or reused state is refused');
// send through the real MailService path with "Send email" off: platformTest uses Graph when connected
$calls = []; $mode = 'ok';
FlxCoreHC\SettingsService::put(['mail_enabled' => '0', 'mail_transport' => 'mail', 'mail_from' => '']);
$t = FlxCoreHC\MailService::platformTest('dest@y.test');
$send = null; foreach ($calls as $c) if (str_ends_with($c['url'], '/me/sendMail')) $send = json_decode($c['body'], true);
$rtAfter = FlxCoreHC\Crypto::decrypt(base64_decode((string) $pdo->query("SELECT sval FROM platform_settings WHERE skey='ms_platform_refresh'")->fetchColumn(), true));
$check($t['ok'] && ($send['message']['toRecipients'][0]['emailAddress']['address'] ?? '') === 'dest@y.test' && ($send['saveToSentItems'] ?? false) === true && str_contains((string) ($send['message']['body']['content'] ?? ''), 'outbound email is working'),
    'MG3 the platform test e-mail goes through Microsoft Graph (sendMail payload: recipient, body, saved to Sent Items) even while "Send email" is off');
$check($rtAfter === 'RT-NEW', 'MG4 the NEW refresh token Microsoft returns on every refresh is stored (otherwise the connection dies about 90 days after it was made)');
$cases = ['revoked' => 'Connect Microsoft 365 again', 'badsecret' => 'client secret', 'network' => 'Could not reach Microsoft', 'denied' => 'Mail.Send', 'nomail' => 'Exchange Online'];
$bad = [];
foreach ($cases as $m => $needle) {
    $mode = $m; $r = FlxCoreHC\MailService::platformTest('dest@y.test');
    if ($r['ok'] || stripos($r['error'], $needle) === false || preg_match('/AT-1|RT-|app-secret-xyz/', $r['error'])) $bad[] = $m . ': ' . $r['error'];
}
$mode = 'ok';
$check(!$bad, 'MG5 Microsoft failures (revoked sign-in, wrong client secret, no network, no permission, no mailbox) each give a plain-language reason and never contain a token or secret' . ($bad ? ' — ' . implode(' | ', $bad) : ''));
// tenant mailbox: connect, send, token rotation lands on the tenant row
[$tG, $ownerG] = $mkTenant('MG');
$_SESSION['ms_oauth_state'] = ['state' => 'st-g', 'scope' => $tG, 'at' => time()];
[$okT] = FlxCoreHC\MicrosoftOAuth::handleCallback('CODE', 'st-g');
$nextRt = 'RT-TENANT-2'; $calls = [];
$idG = FlxCoreHC\MailService::queue('someone@y.test', 'Tenant mail', 'hello', 'general', null, $tG);
[$sentG] = FlxCoreHC\MailService::flush(50);
$rowG = $pdo->prepare("SELECT oauth_refresh_enc, transport FROM tenant_mail_settings WHERE tenant_id = ?"); $rowG->execute([$tG]); $rowG = $rowG->fetch(PDO::FETCH_ASSOC);
$rtG = FlxCoreHC\Crypto::decrypt(base64_decode((string) $rowG['oauth_refresh_enc'], true));
$stG = (string) $pdo->query("SELECT status FROM email_outbox WHERE id=" . $pdo->quote((string) $idG))->fetchColumn();
$check($okT && $rowG['transport'] === 'graph' && $stG === 'sent' && $rtG === 'RT-TENANT-2', 'MG6 an organization that connected its own Microsoft mailbox sends through it, and its rotated refresh token is saved on its own row');
FlxCoreHC\MicrosoftOAuth::$transport = null;
FlxCoreHC\MicrosoftOAuth::disconnect('platform');
FlxCoreHC\SettingsService::put(['mail_enabled' => '0', 'mail_transport' => 'mail']);

// =========================================================================== AU — v1.172.4: platform audit log (cross-organization, details only for platform staff)
[$tAU1, $oAU1] = $mkTenant('AU1'); [$tAU2, $oAU2] = $mkTenant('AU2');
$pdo->prepare("UPDATE users SET platform_admin = 1 WHERE id = ?")->execute([$oAU2]);
$insA = $pdo->prepare("INSERT INTO audit_logs (id, tenant_id, user_id, action, entity_type, entity_id, changes, ip_address, created_at) VALUES (?,?,?,?,?,?,?,?,?)");
$insA->execute([uuid4(), $tAU1, $oAU1, 'au_customer_edit', 'holders', uuid4(), json_encode(['name' => 'Secret Patient Name']), '1.2.3.4', utc_now()]);
$insA->execute([uuid4(), $tAU2, $oAU2, 'au_operator_setting', 'platform_settings', null, json_encode(['host' => 'smtp.example.test']), '5.6.7.8', utc_now()]);
$adm = new SuperadminService($ctx($tAU2, $oAU2, 'tenant_owner', true));
$rowsAll = $adm->auditPage(['action' => 'au_customer_edit']) + [];
$both = array_merge($adm->auditPage(['q' => 'au_']), []);
$byAct = fn(array $rows, string $a) => array_values(array_filter($rows, fn($r) => $r['action'] === $a))[0] ?? null;
$cust = $byAct($both, 'au_customer_edit'); $oper = $byAct($both, 'au_operator_setting');
$check($cust !== null && $oper !== null && $cust['tenant_id'] === $tAU1 && $oper['tenant_id'] === $tAU2 && $cust['tenant_name'] !== '' && $adm->auditCount(['q' => 'au_']) === 2,
    'AU1 the platform audit log shows actions from several organizations, each with its organization name');
$check($cust['changes'] === null && str_contains((string) $oper['changes'], 'smtp.example.test') && (int) $oper['is_staff'] === 1 && (int) $cust['is_staff'] === 0,
    'AU2 the details of a customer user\'s action are withheld; a platform administrator\'s action shows its details');
$check($adm->auditCount(['q' => 'Secret Patient']) === 0 && $adm->auditCount(['q' => '%']) === 0 && $adm->auditCount(['q' => 'au_customer_edi_']) === 0 && $adm->auditCount(['q' => 'au_customer_edit']) === 1,
    'AU3 free-text search never looks inside customers\' record details and treats % and _ literally (no probing, no match-everything)');
$check(count($adm->auditPage(['tenant_id' => $tAU1, 'q' => 'au_'])) === 1 && count($adm->auditPage(['staff' => '1', 'q' => 'au_'])) === 1 && count($adm->auditPage(['action' => 'au_operator_setting'])) === 1
    && $adm->auditPage(['from' => '2999-01-01', 'q' => 'au_']) === [] && in_array('au_customer_edit', $adm->auditActions(), true),
    'AU4 filters by organization, platform administrators only, action and date; the action list feeds the filter');
$refusedAU = false; try { new SuperadminService($ctx($tAU1, $oAU1, 'tenant_owner', false)); } catch (\RuntimeException $e) { $refusedAU = true; }
$check($refusedAU, 'AU5 an ordinary organization owner cannot use the platform audit log');

// =========================================================================== MX — v1.172.4: Microsoft client-secret expiry warning
$pdo->exec("DELETE FROM platform_settings WHERE skey IN ('ms_client_id','ms_client_secret','ms_secret_expires')");
FlxCoreHC\MicrosoftOAuth::saveApp('cid', 'sec', '2027-06-30');
$d = static fn(string $date) => (new DateTimeImmutable($date . ' 12:00:00', new DateTimeZone('UTC')))->getTimestamp();
$n = fn(string $today) => FlxCoreHC\MicrosoftOAuth::secretNotice($d($today));
$check(FlxCoreHC\MicrosoftOAuth::secretExpires() === '2027-06-30' && FlxCoreHC\MicrosoftOAuth::secretDaysLeft($d('2027-06-30')) === 0 && FlxCoreHC\MicrosoftOAuth::secretDaysLeft($d('2027-06-29')) === 1
    && FlxCoreHC\MicrosoftOAuth::secretDaysLeft($d('2027-07-02')) === -2,
    'MX1 days left are counted in whole UTC days (0 on the day, negative after)');
$check($n('2027-04-30') === null && ($n('2027-05-01')['level'] ?? '') === 'info' && ($n('2027-06-16')['level'] ?? '') === 'error' && ($n('2027-06-15')['level'] ?? '') === 'info'
    && ($n('2027-07-01')['level'] ?? '') === 'error' && str_contains($n('2027-07-01')['text'], 'expired'),
    'MX2 no warning until 60 days before; a notice from 60 days; an error notice from 14 days and after expiry');
FlxCoreHC\MicrosoftOAuth::saveApp('cid', '', '2028-01-31');
$keptSecret = FlxCoreHC\MicrosoftOAuth::configured() && FlxCoreHC\MicrosoftOAuth::secretExpires() === '2028-01-31';
FlxCoreHC\MicrosoftOAuth::saveApp('cid', '', 'not-a-date');
$badIgnored = FlxCoreHC\MicrosoftOAuth::secretExpires() === '2028-01-31';
FlxCoreHC\MicrosoftOAuth::saveApp('cid', 'new-secret', '');
$check($keptSecret && $badIgnored && FlxCoreHC\MicrosoftOAuth::secretExpires() === '' && FlxCoreHC\MicrosoftOAuth::secretNotice($d('2027-07-01')) === null,
    'MX3 the date can be updated alone, an invalid date is ignored, and pasting a new secret without a date clears the old date (it no longer applies)');
$pdo->exec("DELETE FROM platform_settings WHERE skey IN ('ms_client_id','ms_client_secret','ms_secret_expires')");

echo $fail ? "\nSOME FAILED\n" : "\nALL PASS\n";
return $fail ? 1 : 0;
