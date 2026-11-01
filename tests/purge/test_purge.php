<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * v1.170.6 — PurgeService + related security fixes. AUTHORED; RUNTIME VALIDATION PENDING.
 * Run ONLY through the isolated runner:  php tests/purge/run.php
 *
 * Groups:
 *  Q1 full provider purge removes every dependent row (incl. FK children) and the stored files
 *  Q2 purge refused for a record that is not in Deleted records
 *  Q3 tenant B cannot purge tenant A's record
 *  Q4 active linked login blocks soft delete + purge; nothing is removed
 *  Q5 suspended portal-only login is released (holder_id → NULL) and purge succeeds
 *  Q6 ROLLBACK: an unexpected FK makes the final delete fail → every earlier delete is rolled back, files stay
 *  Q7 credential purge removes docs/reminders/renewal tasks/evidence + file
 *  Q8 a file path outside the tenant storage dir is never deleted
 *  Q9 platform-wide payer (tenant_id NULL) accepted on case open; unknown payer still rejected
 *  Q10 suspended user cannot be assigned to a case; route-level result is reported
 *  Q11 trashed provider hidden from share-link resolver
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // CLI only — never runnable from a browser

use FlxCoreHC\TenantContext;
use FlxCoreHC\PurgeService;
use FlxCoreHC\Repository\HolderRepository;
use FlxCoreHC\Repository\CredentialRepository;
use FlxCoreHC\Repository\CaseDocumentRepository;
use FlxCoreHC\Repository\CaseRequirementEvidenceRepository;
use FlxCoreHC\Repository\CredentialingCaseRequirementRepository;
use FlxCoreHC\CredentialingCaseService;

$fail = false;
$check = function (bool $cond, string $label) use (&$fail): void { echo ($cond ? 'PASS' : 'FAIL') . " — $label\n"; if (!$cond) $fail = true; };
$pdo = FlxCoreHC\Database::pdo();
$now = utc_now();
$docsBase = rtrim((string) config('docs_path'), '/');
$cnt = function (string $sql, array $p) use ($pdo): int { $s = $pdo->prepare($sql); $s->execute($p); return (int) $s->fetchColumn(); };

$mkTenant = function (string $slug) use ($pdo): array {
    $auth = new FlxCoreHC\Auth\AuthService();
    $r = $auth->registerTenant("T$slug", 'medical_group', "Owner $slug Test", "o-$slug@purge.test", 'Pass123456789', 'Pass123456789', allowPlatform: false);
    $pdo->prepare("UPDATE tenants SET status='active', vertical='healthcare' WHERE id=?")->execute([$r['tenant_id']]);
    FlxCoreHC\Auth\AuthService::seedTemplatesFor($r['tenant_id']);
    $ctx = new TenantContext($r['tenant_id'], $r['id'], 'tenant_owner', "T$slug", 'healthcare', 'medical_group', "Owner $slug Test", false);
    return [$ctx, $r['id']];
};
$mkHolder = fn(TenantContext $c, string $n) => (new HolderRepository($c))->insert(['holder_type' => 'individual', 'provider_type' => 'physician', 'first_name' => 'P', 'last_name' => $n, 'vertical' => 'healthcare', 'status' => 'active']);
$mkFile = function (string $tenantId, string $name) use ($docsBase): string {
    $dir = $docsBase . '/' . $tenantId; if (!is_dir($dir)) mkdir($dir, 0750, true);
    $p = $dir . '/' . $name; file_put_contents($p, 'test'); return $p;
};
/** Credential with document(+file), renewal task and reminder (the three FK children). */
$mkCred = function (TenantContext $c, string $holderId, string $tag) use ($pdo, $now, $mkFile): array {
    $credId = (new CredentialRepository($c))->insert(['holder_id' => $holderId, 'credential_type' => 'state_license', 'name' => "Lic $tag", 'status' => 'active']);
    $file = $mkFile($c->tenantId, "doc-$tag.pdf");
    $docId = uuid4();
    $pdo->prepare('INSERT INTO credential_documents (id, tenant_id, credential_id, file_path, original_name, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$docId, $c->tenantId, $credId, $file, "doc-$tag.pdf", $now]);
    $pdo->prepare('INSERT INTO renewal_tasks (id, tenant_id, credential_id, title, created_at) VALUES (?,?,?,?,?)')
        ->execute([uuid4(), $c->tenantId, $credId, "Renew $tag", $now]);
    $pdo->prepare('INSERT INTO credential_reminders (id, tenant_id, credential_id, days_before, queued_at) VALUES (?,?,?,?,?)')
        ->execute([uuid4(), $c->tenantId, $credId, 30, $now]);
    return [$credId, $docId, $file];
};
$mkUser = function (TenantContext $c, string $holderId, string $role, string $status) use ($pdo, $now): string {
    $id = uuid4();
    $pdo->prepare('INSERT INTO users (id, tenant_id, email, password_hash, full_name, role, status, platform_admin, holder_id, created_at) VALUES (?,?,?,?,?,?,?,0,?,?)')
        ->execute([$id, $c->tenantId, "u-$id@purge.test", password_hash('x', PASSWORD_DEFAULT), 'Linked User', $role, $status, $holderId, $now]);
    return $id;
};

[$A, $uA] = $mkTenant('PA');
[$B]      = $mkTenant('PB');
$HA = new HolderRepository($A); $CA = new CredentialRepository($A);

// ---------------------------------------------------------------- Q1 full purge
$h1 = $mkHolder($A, 'Full');
[$c1, $d1, $f1] = $mkCred($A, $h1, 'q1a');
[$c2, $d2, $f2] = $mkCred($A, $h1, 'q1b');
$pdo->prepare('INSERT INTO producer_requirements (id, tenant_id, holder_id, catalog_code, created_at) VALUES (?,?,?,?,?)')->execute([uuid4(), $A->tenantId, $h1, 'state_license', $now]);
$pdo->prepare("INSERT INTO addresses (id, tenant_id, owner_type, owner_id, line1, city, state, created_at) VALUES (?,?,'provider',?,?,?,?,?)")->execute([uuid4(), $A->tenantId, $h1, '1 Main', 'San Juan', 'PR', $now]);
$pdo->prepare('INSERT INTO share_links (id, tenant_id, holder_id, token, expires_at, created_at) VALUES (?,?,?,?,?,?)')->execute([uuid4(), $A->tenantId, $h1, bin2hex(random_bytes(16)), '2099-01-01 00:00:00', $now]);
$svc = new CredentialingCaseService($A);
[$okCase, $case1] = $svc->open(['holder_id' => $h1, 'case_type' => 'initial_credentialing']);
$check($okCase, 'Q1 setup: case opened');
$codes = array_keys(FlxCoreHC\CatalogService::labelsFor($A->tenantId));
$svc->addRequirement($case1, $codes[0] ?? 'state_license');
$req1 = (new CredentialingCaseRequirementRepository($A))->forCase($case1)[0]['id'] ?? '';
[$okEv] = (new CaseRequirementEvidenceRepository($A))->link($req1, ['credential_id' => $c1]);
$check($okEv === true, 'Q1 setup: evidence linked');
$cf1 = $mkFile($A->tenantId, 'case-q1.pdf');
(new CaseDocumentRepository($A))->insert(['case_id' => $case1, 'holder_id' => $h1, 'file_path' => $cf1, 'original_name' => 'case-q1.pdf', 'mime_type' => 'application/pdf', 'byte_size' => 4]);
$evBefore = $cnt('SELECT COUNT(*) FROM compliance_events WHERE tenant_id=? AND holder_id=?', [$A->tenantId, $h1]);

$HA->delete($h1);
[$okOpen, $msgOpen] = (new PurgeService($A))->purgeHolder($h1);
$check($okOpen === false && str_contains($msgOpen, 'credentialing case'), "Q1 purge blocked while a case is OPEN ($msgOpen)");
[$okCx] = $svc->transition($case1, 'cancelled');
$check($okCx === true, 'Q1 setup: case cancelled');
[$ok1, $msg1] = (new PurgeService($A))->purgeHolder($h1);
$check($ok1 === true, "Q1 purge succeeds once cases are closed ($msg1)");
$t = $A->tenantId;
$check($cnt('SELECT COUNT(*) FROM credential_holders WHERE tenant_id=? AND id=?', [$t, $h1]) === 0, 'Q1 holder row gone');
$check($cnt('SELECT COUNT(*) FROM credentials WHERE tenant_id=? AND holder_id=?', [$t, $h1]) === 0, 'Q1 credentials gone');
$check($cnt('SELECT COUNT(*) FROM credential_documents WHERE tenant_id=? AND credential_id IN (?,?)', [$t, $c1, $c2]) === 0, 'Q1 credential_documents gone (FK child)');
$check($cnt('SELECT COUNT(*) FROM renewal_tasks WHERE tenant_id=? AND credential_id IN (?,?)', [$t, $c1, $c2]) === 0, 'Q1 renewal_tasks gone (FK child)');
$check($cnt('SELECT COUNT(*) FROM credential_reminders WHERE tenant_id=? AND credential_id IN (?,?)', [$t, $c1, $c2]) === 0, 'Q1 credential_reminders gone (FK child)');
$check($cnt('SELECT COUNT(*) FROM producer_requirements WHERE tenant_id=? AND holder_id=?', [$t, $h1]) === 0, 'Q1 producer_requirements gone');
$check($cnt("SELECT COUNT(*) FROM addresses WHERE tenant_id=? AND owner_type='provider' AND owner_id=?", [$t, $h1]) === 0, 'Q1 provider addresses gone');
$check($cnt('SELECT COUNT(*) FROM credentialing_cases WHERE tenant_id=? AND holder_id=?', [$t, $h1]) === 0, 'Q1 credentialing cases gone');
$check($cnt('SELECT COUNT(*) FROM credentialing_case_requirements WHERE tenant_id=? AND case_id=?', [$t, $case1]) === 0, 'Q1 case requirements gone');
$check($cnt('SELECT COUNT(*) FROM case_requirement_evidence WHERE tenant_id=? AND credential_id=?', [$t, $c1]) === 0, 'Q1 case evidence gone');
$check($cnt('SELECT COUNT(*) FROM case_documents WHERE tenant_id=? AND holder_id=?', [$t, $h1]) === 0, 'Q1 case documents gone');
$check(!is_file($f1) && !is_file($f2) && !is_file($cf1), 'Q1 stored files removed from disk');
$check($cnt('SELECT COUNT(*) FROM share_links WHERE tenant_id=? AND holder_id=? AND revoked_at IS NULL', [$t, $h1]) === 0, 'Q1 share links revoked');
$check($cnt('SELECT COUNT(*) FROM compliance_events WHERE tenant_id=? AND holder_id=?', [$t, $h1]) >= $evBefore, 'Q1 compliance timeline retained');

// ---------------------------------------------------------------- Q2 not trashed
$h2 = $mkHolder($A, 'Live');
[$ok2] = (new PurgeService($A))->purgeHolder($h2);
$check($ok2 === false && $HA->find($h2) !== null, 'Q2 purge refused for a live (not deleted) record');

// ---------------------------------------------------------------- Q3 cross-tenant
$HA->delete($h2);
[$ok3] = (new PurgeService($B))->purgeHolder($h2);
$check($ok3 === false && $HA->findTrashed($h2) !== null, 'Q3 tenant B cannot purge tenant A record');

// ---------------------------------------------------------------- Q4 active linked login blocks
$h4 = $mkHolder($A, 'Linked');
[$c4] = $mkCred($A, $h4, 'q4');
$u4 = $mkUser($A, $h4, 'producer', 'active');
$check((new PurgeService($A))->activeLinkedUsers($h4) !== [], 'Q4 soft-delete guard sees the active linked login');
$HA->delete($h4);                                  // (repo-level; the route refuses this — guard verified above)
[$ok4, $msg4] = (new PurgeService($A))->purgeHolder($h4);
$check($ok4 === false, "Q4 purge blocked by active linked login ($msg4)");
$check($cnt('SELECT COUNT(*) FROM credentials WHERE tenant_id=? AND id=?', [$t, $c4]) === 1
    && $cnt('SELECT COUNT(*) FROM credential_documents WHERE tenant_id=? AND credential_id=?', [$t, $c4]) === 1, 'Q4 nothing was removed when blocked');
$pdo->prepare("UPDATE users SET role='manager' WHERE id=?")->execute([$u4]);
$pdo->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$u4]);
[$ok4b] = (new PurgeService($A))->purgeHolder($h4);
$check($ok4b === false, 'Q4 suspended STAFF login still blocks purge (only portal-only links are released)');

// ---------------------------------------------------------------- Q5 suspended producer released
$pdo->prepare("UPDATE users SET role='producer' WHERE id=?")->execute([$u4]);
[$ok5, $msg5] = (new PurgeService($A))->purgeHolder($h4);
$check($ok5 === true, "Q5 purge succeeds once the only link is a suspended portal account ($msg5)");
$check($cnt('SELECT COUNT(*) FROM users WHERE id=? AND holder_id IS NULL', [$u4]) === 1, 'Q5 suspended portal account unlinked (holder_id NULL)');

// ---------------------------------------------------------------- Q6 rollback
$h6 = $mkHolder($A, 'Rollback');
[$c6, $d6, $f6] = $mkCred($A, $h6, 'q6');
$pdo->prepare('INSERT INTO producer_requirements (id, tenant_id, holder_id, catalog_code, created_at) VALUES (?,?,?,?,?)')->execute([uuid4(), $t, $h6, 'state_license', $now]);
$pdo->exec('DROP TABLE IF EXISTS purge_probe');
$pdo->exec('CREATE TABLE purge_probe (id CHAR(36) NOT NULL PRIMARY KEY, tenant_id CHAR(36) NOT NULL, holder_id CHAR(36) NOT NULL,
            CONSTRAINT fk_probe_holder FOREIGN KEY (tenant_id, holder_id) REFERENCES credential_holders(tenant_id, id)) ENGINE=InnoDB');
$pdo->prepare('INSERT INTO purge_probe VALUES (?,?,?)')->execute([uuid4(), $t, $h6]);
$HA->delete($h6);
[$ok6, $msg6] = (new PurgeService($A))->purgeHolder($h6);
$check($ok6 === false && str_contains($msg6, 'rolled back'), 'Q6 unexpected FK → purge fails with rollback message');
$check($cnt('SELECT COUNT(*) FROM credentials WHERE tenant_id=? AND id=?', [$t, $c6]) === 1, 'Q6 credential restored by rollback');
$check($cnt('SELECT COUNT(*) FROM credential_documents WHERE tenant_id=? AND id=?', [$t, $d6]) === 1, 'Q6 credential document restored by rollback');
$check($cnt('SELECT COUNT(*) FROM renewal_tasks WHERE tenant_id=? AND credential_id=?', [$t, $c6]) === 1, 'Q6 renewal task restored by rollback');
$check($cnt('SELECT COUNT(*) FROM producer_requirements WHERE tenant_id=? AND holder_id=?', [$t, $h6]) === 1, 'Q6 producer requirement restored by rollback');
$check(is_file($f6), 'Q6 stored file NOT deleted when the transaction rolled back');
$pdo->exec('DROP TABLE purge_probe');

// ---------------------------------------------------------------- Q7 credential purge
$h7 = $mkHolder($A, 'CredPurge');
[$c7, $d7, $f7] = $mkCred($A, $h7, 'q7');
$CA->delete($c7);
[$ok7, $msg7] = (new PurgeService($A))->purgeCredential($c7);
$check($ok7 === true, "Q7 credential purge succeeds ($msg7)");
$check($cnt('SELECT COUNT(*) FROM credentials WHERE tenant_id=? AND id=?', [$t, $c7]) === 0
    && $cnt('SELECT COUNT(*) FROM credential_documents WHERE tenant_id=? AND credential_id=?', [$t, $c7]) === 0
    && $cnt('SELECT COUNT(*) FROM renewal_tasks WHERE tenant_id=? AND credential_id=?', [$t, $c7]) === 0
    && $cnt('SELECT COUNT(*) FROM credential_reminders WHERE tenant_id=? AND credential_id=?', [$t, $c7]) === 0, 'Q7 credential + FK children gone');
$check(!is_file($f7), 'Q7 credential file removed');
$check($HA->find($h7) !== null, 'Q7 provider untouched by credential purge');
[$ok7b] = (new PurgeService($B))->purgeCredential($c6);
$check($ok7b === false, 'Q7 tenant B cannot purge tenant A credential');

// ---------------------------------------------------------------- Q8 file outside tenant dir
$h8 = $mkHolder($A, 'Outside');
[$c8, $d8] = $mkCred($A, $h8, 'q8');
$outside = sys_get_temp_dir() . '/flx_purge_outside_' . bin2hex(random_bytes(4)) . '.txt';
file_put_contents($outside, 'must survive');
$pdo->prepare('UPDATE credential_documents SET file_path=? WHERE id=?')->execute([$outside, $d8]);
$CA->delete($c8);
[$ok8] = (new PurgeService($A))->purgeCredential($c8);
$check($ok8 === true && is_file($outside), 'Q8 file outside the tenant storage dir is NOT deleted');
@unlink($outside);

// ---------------------------------------------------------------- Q9 payers
$gp = uuid4();
$pdo->prepare("INSERT INTO payers (id, tenant_id, name, created_at) VALUES (?, NULL, 'Global Payer', ?)")->execute([$gp, $now]);
$h9 = $mkHolder($A, 'Payer');
[$ok9] = $svc->open(['holder_id' => $h9, 'case_type' => 'payer_enrollment', 'payer_id' => $gp]);
$check($ok9 === true, 'Q9 platform-wide payer accepted on case open');
[$ok9b] = $svc->open(['holder_id' => $h9, 'case_type' => 'payer_enrollment', 'payer_id' => 'no-such-payer']);
$check($ok9b === false, 'Q9 unknown payer still rejected');
$bp = uuid4();
$pdo->prepare("INSERT INTO payers (id, tenant_id, name, created_at) VALUES (?, ?, 'B private payer', ?)")->execute([$bp, $B->tenantId, $now]);
[$ok9c] = $svc->open(['holder_id' => $h9, 'case_type' => 'payer_enrollment', 'payer_id' => $bp]);
$check($ok9c === false, "Q9 another tenant's private payer rejected");
[$ok9d] = $svc->open(['holder_id' => $h2, 'case_type' => 'initial_credentialing']);   // h2 is in Deleted records
$check($ok9d === false, 'Q9 case cannot be opened for a provider in Deleted records');

// ---------------------------------------------------------------- Q10 assignment
[, $case10] = $svc->open(['holder_id' => $h9, 'case_type' => 'initial_credentialing']);
$susp = $mkUser($A, '', 'staff', 'suspended');
$pdo->prepare('UPDATE users SET holder_id=NULL WHERE id=?')->execute([$susp]);
[$ok10] = $svc->assign($case10, $susp);
$check($ok10 === false, 'Q10 suspended user cannot be assigned');
[$ok10b] = $svc->assign($case10, $uA);
$check($ok10b === true, 'Q10 active user can be assigned');

// ---------------------------------------------------------------- Q11 share link for trashed provider
$h11 = $mkHolder($A, 'Shared');
$tok = bin2hex(random_bytes(16));
$pdo->prepare('INSERT INTO share_links (id, tenant_id, holder_id, token, expires_at, created_at) VALUES (?,?,?,?,?,?)')->execute([uuid4(), $t, $h11, $tok, '2099-01-01 00:00:00', $now]);
$check(FlxCoreHC\ShareLinkService::resolveForView($tok) !== null, 'Q11 share link resolves for a live provider');
$HA->delete($h11);
$check(FlxCoreHC\ShareLinkService::resolveForView($tok) === null, 'Q11 share link does NOT resolve once the provider is in Deleted records');
$HA->restore($h11);
$check(FlxCoreHC\ShareLinkService::resolveForView($tok) !== null, 'Q11 share link works again after restore');

echo "\n" . ($fail ? "SOME FAILED\n" : "ALL PASS\n");
return $fail ? 1 : 0;
