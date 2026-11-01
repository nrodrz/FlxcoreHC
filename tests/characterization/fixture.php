<?php
/**
 * Phase 0.1 deterministic fixtures — six provider states (A–F) built ONLY from verified APIs/columns.
 * Each returns a fresh holder in one isolated tenant. No invented columns/behavior. No production writes
 * (runs against the isolated flx_char DB set up by run.php).
 *
 * Verified building blocks:
 *   AuthService::registerTenant(6-arg, allowPlatform:false) → ['tenant_id'=>,'id'=>]   (DemoHealthcareService::buildOrg:87)
 *   AuthService::seedTemplatesFor($tid)                                                  (per-tenant catalog+templates)
 *   HolderRepository::insert (TenantRepository::insert:141)  → credential_holders
 *   TemplateService::applyByProviderType → producer_requirements
 *   CredentialRepository::insert (status keys active|expiring|expired) → credentials
 *   DocumentRepository::insert (credential_id,file_path,original_name,mime_type,uploaded_by) → credential_documents
 *   VerificationService::recordManual($hid,['source'=>,'outcome'=>,'credential_id'=>]) → source_verifications
 *
 * Expiring threshold is the app's OWN rule: ComplianceLevelService uses +30 days (ComplianceLevelService.php:75).
 * NOTE (characterization, not a fix): CatalogService::coverageDetail reads the STORED status column and does
 * NOT recompute from dates; ComplianceLevelService recomputes 'expiring' from date+30d. The fixture sets
 * both a realistic expiry date AND the stored status so the suite can observe whether the engines agree.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // CLI only — never runnable from a browser

function char_make_tenant_ctx(string $slug): array {
    $pdo = FlxCoreHC\Database::pdo();
    $auth = new FlxCoreHC\Auth\AuthService();
    $reg = $auth->registerTenant("Char $slug Org", 'medical_group', "Char $slug Owner", "char-$slug@char.test", 'CharPass12345', 'CharPass12345', allowPlatform: false);
    if (!is_array($reg) || empty($reg['tenant_id'])) throw new RuntimeException("registerTenant failed for $slug: ".json_encode($reg));
    $tid = $reg['tenant_id']; $uid = $reg['id'];
    $pdo->prepare("UPDATE tenants SET status='active', vertical='healthcare' WHERE id=?")->execute([$tid]);
    FlxCoreHC\Auth\AuthService::seedTemplatesFor($tid);
    $ctx = new FlxCoreHC\TenantContext($tid, $uid, 'tenant_owner', "Char $slug Org", 'healthcare', 'medical_group', "Char $slug Owner", false);
    return [$ctx, $uid];
}

function char_add_physician(FlxCoreHC\TenantContext $ctx): string {
    $H = new FlxCoreHC\Repository\HolderRepository($ctx);
    $hid = $H->insert(['holder_type'=>'individual','provider_type'=>'physician','first_name'=>'Case','last_name'=>'Zero',
        'npi'=>'1063415271','email'=>'case.zero@char.test','vertical'=>'healthcare','status'=>'active']);
    (new FlxCoreHC\TemplateService($ctx))->applyByProviderType($hid, 'physician', new FlxCoreHC\Repository\ProducerRequirementRepository($ctx));
    return $hid;
}

/** Add credentials covering the full required set; return [holder, credRepo]. States tuned per scenario. */
function char_scenario(string $key): array {
    [$ctx, $uid] = char_make_tenant_ctx($key);
    $hid = char_add_physician($ctx);
    $C = new FlxCoreHC\Repository\CredentialRepository($ctx);
    $req = (new FlxCoreHC\Repository\ProducerRequirementRepository($ctx))->tokensFor($hid);

    $future = date('Y-m-d', strtotime('+2 years'));
    $soon   = date('Y-m-d', strtotime('+20 days'));   // within the app's 30-day expiring window
    $past   = date('Y-m-d', strtotime('-10 days'));

    // Helper to add an active credential satisfying a token.
    $add = function(string $tok, string $status, string $expiry) use ($C, $hid) {
        return $C->insert(['holder_id'=>$hid,'credential_type'=>$tok,'name'=>strtoupper($tok),
            'issued_date'=>date('Y-m-d', strtotime('-1 year')),'expiry_date'=>$expiry,'status'=>$status]);
    };

    switch ($key) {
        case 'complete':                 // A — all required satisfied, active
            foreach ($req as $t) $add($t, 'active', $future);
            break;
        case 'missing_requirement':      // B — omit exactly one required token
            $omit = $req[0] ?? null;
            foreach ($req as $t) { if ($t === $omit) continue; $add($t, 'active', $future); }
            break;
        case 'expiring_credential':      // C — one within the 30-day window (stored 'expiring' + soon date)
            foreach ($req as $i => $t) $add($t, $i === 0 ? 'expiring' : 'active', $i === 0 ? $soon : $future);
            break;
        case 'expired_credential':       // D — one expired (stored 'expired' + past date)
            foreach ($req as $i => $t) $add($t, $i === 0 ? 'expired' : 'active', $i === 0 ? $past : $future);
            break;
        case 'verification_discrepancy': // E — all active; one has a source_verifications record, others none
            $first = null; foreach ($req as $i => $t) { $cid = $add($t, 'active', $future); if ($i === 0) $first = $cid; }
            if ($first) { try { (new FlxCoreHC\VerificationService($ctx))->recordManual($hid, ['source'=>'npi','outcome'=>'clear','credential_id'=>$first,'reference'=>'char-E']); } catch (\Throwable $e) {} }
            break;
        case 'hard_stop_exclusion':      // F — see limitation note; attempt only via supported model
            foreach ($req as $t) $add($t, 'active', $future);
            // Hard-stop via exclusion screening is NOT created here: doing so safely requires the
            // exclusion-screening write path + import data. Recorded as a limitation in the baseline.
            break;
    }
    // One document/evidence relationship on the first credential (all scenarios) for evidence characterization.
    $anyCred = $C->all(['holder_id'=>$hid], 'expiry_date ASC', 1);
    if ($anyCred) { try { (new FlxCoreHC\Repository\DocumentRepository($ctx))->insert(['credential_id'=>$anyCred[0]['id'],'file_path'=>"char/$key.pdf",'original_name'=>"$key.pdf",'mime_type'=>'application/pdf','uploaded_by'=>$uid]); } catch (\Throwable $e) {} }

    $holder = (new FlxCoreHC\Repository\HolderRepository($ctx))->find($hid);
    return [$ctx, $holder];
}
