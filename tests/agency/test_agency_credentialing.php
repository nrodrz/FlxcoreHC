<?php
/**
 * Agency Credentialing — expanded tests (§8 of the remediation). AUTHORED; RUNTIME VALIDATION PENDING
 * (no PHP/MySQL in the authoring environment). Run on a PHP 8 + MySQL host:
 *   php tests/agency/test_agency_credentialing.php
 * Assumes bootstrap + an isolated flx_char DB are prepared exactly as tests/characterization/run.php does.
 *
 * Coverage: A read isolation · B update/delete isolation · C shared-provider non-leak · D evidence
 * wrong-tenant/wrong-provider/wrong-case rejected · E invalid FK on case create rejected · F invalid
 * requirement code rejected · G requirement mutation via service records history · H hard-stop
 * fail-closed · I backward compat (case_id NULL) · J credential_documents unchanged.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // CLI only — never runnable from a browser
$fail = false;
function check(bool $cond, string $label): void { global $fail; echo ($cond?'PASS':'FAIL')." — $label\n"; if(!$cond)$fail=true; }

use FlxCoreHC\TenantContext;
use FlxCoreHC\Repository\CredentialingCaseRepository;
use FlxCoreHC\Repository\CredentialingCaseRequirementRepository;
use FlxCoreHC\Repository\CaseRequirementEvidenceRepository;
use FlxCoreHC\Repository\CaseDocumentRepository;
use FlxCoreHC\Repository\CredentialRepository;
use FlxCoreHC\Repository\HolderRepository;
use FlxCoreHC\CredentialingCaseService;

$pdo = FlxCoreHC\Database::pdo();
function tenant(PDO $pdo, string $slug): array {
    $auth = new FlxCoreHC\Auth\AuthService();
    $r = $auth->registerTenant("T$slug","medical_group","Owner $slug Test","o-$slug@t.test","Pass123456789","Pass123456789", allowPlatform:false);
    $pdo->prepare("UPDATE tenants SET status='active',vertical='healthcare' WHERE id=?")->execute([$r['tenant_id']]);
    FlxCoreHC\Auth\AuthService::seedTemplatesFor($r['tenant_id']);
    $ctx = new TenantContext($r['tenant_id'],$r['id'],'tenant_owner',"T$slug",'healthcare','medical_group',"Owner $slug Test",false);
    $h = (new HolderRepository($ctx))->insert(['holder_type'=>'individual','provider_type'=>'physician','first_name'=>'P','last_name'=>$slug,'vertical'=>'healthcare','status'=>'active']);
    return [$ctx,$h,$r['id']];
}
[$A,$hA,$uA] = tenant($pdo,'A');
[$B,$hB,$uB] = tenant($pdo,'B');
$svcA = new CredentialingCaseService($A);
$casesA = new CredentialingCaseRepository($A); $casesB = new CredentialingCaseRepository($B);
$reqA = new CredentialingCaseRequirementRepository($A);

// create a case in A with a hard-stop requirement (use a real catalog code)
$codes = array_keys(FlxCoreHC\CatalogService::labelsFor($A->tenantId));
$code = in_array('malpractice',$codes,true) ? 'malpractice' : ($codes[0] ?? 'malpractice');
[$okC,$c1] = $svcA->open(['holder_id'=>$hA,'case_type'=>'initial']);
check($okC, 'case created in A');
$svcA->addRequirement($c1,$code,['is_hard_stop'=>1]);
$reqRow = $reqA->forCase($c1)[0] ?? null;

// A: read isolation
check($casesB->find($c1) === null, 'A: tenant B cannot READ tenant A case');
check($reqA instanceof CredentialingCaseRequirementRepository && (new CredentialingCaseRequirementRepository($B))->find($reqRow['id']??'') === null, 'A: tenant B cannot READ tenant A case requirement');

// B: update/delete isolation (base repo scopes by tenant → 0 rows affected)
check($casesB->update($c1,['notes'=>'x']) === false, 'B: tenant B cannot UPDATE tenant A case');
check($casesB->delete($c1) === false, 'B: tenant B cannot DELETE tenant A case');

// C: shared provider does not leak cases — even if B could see the provider via sharing, cases are tenant-local
check((new CredentialingCaseRepository($B))->forHolder($hA) === [], 'C: shared provider does not expose owner tenant cases');

// D: evidence integrity
$eviA = new CaseRequirementEvidenceRepository($A);
$credB = (new CredentialRepository($B))->insert(['holder_id'=>$hB,'credential_type'=>$code,'name'=>'B','status'=>'active']);   // wrong tenant
$credA_other = null; $hA2 = (new HolderRepository($A))->insert(['holder_type'=>'individual','provider_type'=>'physician','first_name'=>'P2','last_name'=>'A2','vertical'=>'healthcare','status'=>'active']);
$credA_other = (new CredentialRepository($A))->insert(['holder_id'=>$hA2,'credential_type'=>$code,'name'=>'A2','status'=>'active']);  // right tenant, WRONG provider
[$d1] = $eviA->link($reqRow['id'],['credential_id'=>$credB]);        check($d1===false, 'D: evidence from WRONG TENANT rejected');
[$d2] = $eviA->link($reqRow['id'],['credential_id'=>$credA_other]);  check($d2===false, 'D: evidence from correct tenant but WRONG PROVIDER rejected');
[$d3] = $eviA->link($reqRow['id'],['credential_id'=>'x','case_document_id'=>'y']); check($d3===false, 'F: multiple targets rejected');
$credA = (new CredentialRepository($A))->insert(['holder_id'=>$hA,'credential_type'=>$code,'name'=>'ok','status'=>'active']);
[$d4] = $eviA->link($reqRow['id'],['credential_id'=>$credA]);        check($d4===true, 'D: valid same-provider evidence accepted');

// E: invalid FK on case create
[$e1] = $svcA->open(['holder_id'=>'not-a-real-id']);                              check($e1===false, 'E: invalid holder rejected');
[$e2] = $svcA->open(['holder_id'=>$hA,'client_group_id'=>'nope']);               check($e2===false, 'E: invalid client group rejected');
[$e3] = $svcA->open(['holder_id'=>$hA,'payer_id'=>'nope']);                      check($e3===false, 'E: invalid payer rejected');
[$e4] = $svcA->open(['holder_id'=>$hA,'assigned_to'=>$uB]);                      check($e4===false, 'E: assignee from another tenant rejected');

// F: invalid requirement code
[$f1] = $svcA->addRequirement($c1,'__bogus_code__');                             check($f1===false, 'F: invalid requirement code rejected');

// G: requirement status mutation via service records history
$before = count(FlxCoreHC\ComplianceEventService::forCase($A,$c1,500));
$svcA->updateRequirementStatus($c1,$reqRow['id'],'satisfied');
$after = count(FlxCoreHC\ComplianceEventService::forCase($A,$c1,500));
check($after > $before, 'G: requirement mutation via service records case history');

// H: hard-stop fail-closed — force the compliance eval to fail by deleting the provider row, then attempt gate
$svcA->transition($c1,'in_progress');
// resolve the case requirement so ONLY the provider-level check remains, then break it
// (the malpractice req is 'satisfied' from G). Delete the holder to force evaluation failure.
$pdo->prepare("UPDATE credential_holders SET deleted_at=NOW() WHERE id=? AND tenant_id=?")->execute([$hA,$A->tenantId]);
$bl = $svcA->submissionBlockers($c1,$hA,true);
check(!empty(array_filter($bl, fn($b)=>($b['type']??'')==='compliance_check_error')), 'H: hard-stop fails CLOSED when compliance eval cannot complete');
[$okT] = $svcA->transition($c1,'ready_for_submission');
check($okT===false, 'H: ready_for_submission blocked under compliance_check_error');
// restore the provider soft-deleted above to force the compliance-eval failure, so later sections start from a clean fixture
$pdo->prepare("UPDATE credential_holders SET deleted_at=NULL WHERE id=? AND tenant_id=?")->execute([$hA,$A->tenantId]);

// I: backward compatibility — existing tables work with case_id NULL
$iOk=true; try { $pdo->prepare("INSERT INTO applications (id,tenant_id,holder_id,kind,title,status,created_at) VALUES (?,?,?,?,?,?,NOW())")->execute([uuid4(),$A->tenantId,$hA2,'enrollment','Legacy application (no case)','not_started']); } catch(\Throwable $e){ $iOk=false; }
check($iOk, 'I: applications insert works with case_id NULL');
$evOk=true; try { FlxCoreHC\ComplianceEventService::record($A,'credential_added','x',['holder_id'=>$hA2]); } catch(\Throwable $e){ $evOk=false; }
check($evOk, 'I: compliance_events record works without case_id (existing consumers unaffected)');

// J — credential_documents semantics unchanged (real assertions; the old test could not fail).
// A. credential_id must still be NOT NULL in the live schema.
$jA = false;
try {
    $col = $pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='credential_documents' AND COLUMN_NAME='credential_id'")->fetchColumn();
    $jA = (strtoupper((string)$col) === 'NO');
} catch (\Throwable $e) { $jA = false; }
check($jA, 'J-A: credential_documents.credential_id remains NOT NULL');
// B. a valid credential-scoped document still inserts against a real credential.
$jB = false;
try {
    $pdo->prepare("INSERT INTO credential_documents (id,tenant_id,credential_id,file_path,original_name,mime_type,uploaded_by,created_at) VALUES (?,?,?,?,?,?,?,NOW())")
        ->execute([uuid4(), $A->tenantId, $credA, 'char/ok.pdf', 'ok.pdf', 'application/pdf', $uA]);
    $jB = true;
} catch (\Throwable $e) { $jB = false; }
check($jB, 'J-B: a valid credential-scoped document can still be created');
// C. inserting a credential_document with NULL credential_id must be REJECTED (proves Agency
//    Credentialing did NOT make credential_id optional).
$jC = false;
try {
    $pdo->prepare("INSERT INTO credential_documents (id,tenant_id,credential_id,file_path,original_name,created_at) VALUES (?,?,?,?,?,NOW())")
        ->execute([uuid4(), $A->tenantId, null, 'char/bad.pdf', 'bad.pdf']);
    $jC = false;   // if it succeeded, credential_id became optional → REGRESSION
} catch (\Throwable $e) { $jC = true; }   // rejection is the correct behavior
check($jC, 'J-C: credential_documents rejects a NULL credential_id (credential_id still required)');

// §1 — assignment ownership
[$asgOk1] = $svcA->assign($c1, $uA);                                 check($asgOk1===true,  'K: valid same-tenant assignment accepted');
[$asgOk2] = $svcA->assign($c1, null);                                check($asgOk2===true,  'K: unassignment (null) accepted');
[$asgOk3] = $svcA->assign($c1, 'no-such-user');                      check($asgOk3===false, 'K: nonexistent assignee rejected');
[$asgOk4] = $svcA->assign($c1, $uB);                                 check($asgOk4===false, 'K: assignee from another tenant rejected');

// §2 — cross-case mutation within the SAME tenant must be rejected
[$okC2,$c2] = $svcA->open(['holder_id'=>$hA,'case_type'=>'recredential']);
// a document + evidence that belong to case c2
$docsA = new CaseDocumentRepository($A);
$doc2 = $docsA->insert(['case_id'=>$c2,'holder_id'=>$hA,'file_path'=>'char/c2.pdf','uploaded_by'=>$uA]);
$svcA->addRequirement($c2, $code);
$req2 = (new CredentialingCaseRequirementRepository($A))->forCase($c2)[0] ?? null;
[$lok] = $eviA->link($req2['id'],['credential_id'=>$credA]);         // credA belongs to hA (c2's provider) → ok
$ev2 = (new CaseRequirementEvidenceRepository($A))->forRequirement($req2['id'])[0] ?? null;
// case c1 must NOT be able to delete c2's document or unlink c2's evidence
[$xDoc] = $svcA->deleteCaseDocument($c1, $doc2);                     check($xDoc===false, 'L: Case A cannot delete Case B document (same tenant)');
[$xEvi] = $svcA->unlinkEvidence($c1, $ev2['id'] ?? '');             check($xEvi===false, 'L: Case A cannot unlink Case B evidence (same tenant)');
// the rightful case CAN
[$yDoc] = $svcA->deleteCaseDocument($c2, $doc2);                     check($yDoc===true,  'L: rightful case can delete its own document');
[$yEvi] = $svcA->unlinkEvidence($c2, $ev2['id'] ?? '');             check($yEvi===true,  'L: rightful case can unlink its own evidence');

// §4 — template population: a fresh case for a physician gets requirements from the profession template
[$okM,$cM] = $svcA->open(['holder_id'=>$hA,'case_type'=>'initial_credentialing']);
[$pOk,$pCount] = $svcA->populateRequirements($cM);
$mReqs = (new CredentialingCaseRequirementRepository($A))->forCase($cM);
check($pOk===true && count($mReqs) > 0, 'M: populateRequirements seeds case requirements from the profession template');
// idempotent — running again does not duplicate
$before = count($mReqs); $svcA->populateRequirements($cM);
check(count((new CredentialingCaseRequirementRepository($A))->forCase($cM)) === $before, 'M: populate is idempotent (no duplicates)');

// §8 — reusable evidence: an EXPIRED credential is surfaced as not valid; a current one as valid
$expCred = (new CredentialRepository($A))->insert(['holder_id'=>$hA,'credential_type'=>$code,'name'=>'old','status'=>'active','expiry_date'=>date('Y-m-d', strtotime('-10 days'))]);
$curCred = (new CredentialRepository($A))->insert(['holder_id'=>$hA,'credential_type'=>$code,'name'=>'cur','status'=>'active','expiry_date'=>date('Y-m-d', strtotime('+1 year'))]);
$reuse = $svcA->reusableEvidenceFor($hA);
$forCode = $reuse[$code] ?? [];
$hasInvalid = (bool)array_filter($forCode, fn($e)=>$e['id']===$expCred && $e['valid']===false);
$hasValid   = (bool)array_filter($forCode, fn($e)=>$e['id']===$curCred && $e['valid']===true);
check($hasInvalid, 'N: reusable evidence marks an EXPIRED credential as not valid');
check($hasValid,   'N: reusable evidence marks a CURRENT credential as valid');
// reuse never auto-satisfies: the requirement stays pending until explicitly linked
$mr = $mReqs[0] ?? null;
check($mr && $mr['status']==='pending', 'N: existing evidence does NOT auto-satisfy a requirement');

// O — soft delete / restore / purge lifecycle for providers
$H_A = new HolderRepository($A);
$delId = $H_A->insert(['holder_type'=>'individual','provider_type'=>'physician','first_name'=>'Del','last_name'=>'Test','vertical'=>'healthcare','status'=>'active']);
check($H_A->find($delId) !== null, 'O: provider created');
// soft delete → gone from active, present in trashed, restorable
check($H_A->delete($delId) === true, 'O: soft delete succeeds');
check($H_A->find($delId) === null, 'O: soft-deleted provider not in active list');
check($H_A->findTrashed($delId) !== null, 'O: soft-deleted provider IS in trashed');
$trashedNames = array_column($H_A->trashed(), 'id');
check(in_array($delId, $trashedNames, true), 'O: trashed() lists the deleted provider');
// restore → back in active
check($H_A->restore($delId) === true, 'O: restore succeeds');
check($H_A->find($delId) !== null, 'O: restored provider is active again');
check($H_A->findTrashed($delId) === null, 'O: restored provider no longer in trash');
// cross-tenant: B cannot restore or purge A's record
$H_B = new HolderRepository($B);
$H_A->delete($delId);
check($H_B->restore($delId) === false, 'O: tenant B cannot restore tenant A record');
check($H_B->purge($delId) === false, 'O: tenant B cannot purge tenant A record');
// rightful purge removes it permanently
check($H_A->purge($delId) === true, 'O: rightful tenant can permanently delete');
check($H_A->find($delId) === null && $H_A->findTrashed($delId) === null, 'O: purged provider is gone everywhere');

// P — platform admin cannot be demoted into a portal-only role (lockout guard)
try {
    $pdo->prepare("UPDATE users SET platform_admin=1 WHERE id=? AND tenant_id=?")->execute([$uA, $A->tenantId]);
    $team = new FlxCoreHC\TeamService($A);
    [$pd] = $team->updateRole($uA, 'producer');   // producer = portal_only
    check($pd === false, 'P: platform admin cannot be moved to a portal-only (producer) role');
    // confirm the DB role was NOT changed
    $roleNow = $pdo->query("SELECT role FROM users WHERE id='".$uA."'")->fetchColumn();
    check($roleNow !== 'producer', 'P: platform admin role unchanged after blocked demotion');
} catch (\Throwable $e) { check(false, 'P: guard test threw: '.$e->getMessage()); }

echo "\n" . ($fail ? "SOME FAILED\n" : "ALL PASS\n");
exit($fail?1:0);
