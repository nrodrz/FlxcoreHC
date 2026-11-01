<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * Phase 0.1 capture — compares EQUIVALENT business decisions across the compliance engines, per the
 * corrected methodology. It does NOT compare unrelated metrics (the Phase 0 error): it normalizes each
 * engine to a per-requirement "is this token satisfied?" decision, and captures overall compliance
 * SEPARATELY. Every difference is classified (AGREEMENT / REPRESENTATION_DIFFERENCE /
 * REAL_BUSINESS_DISAGREEMENT / RUNTIME_FAILURE / UNKNOWN). Nothing is normalized away.
 *
 * Entry points (verified signatures, v1.166.0):
 *   ComplianceLevelService::evaluate(TenantContext,array holder,array creds,array required,array coverage)  app/src/ComplianceLevelService.php:30
 *   CatalogService::coverageDetail(array creds)                                                             app/src/CatalogService.php:377
 *   ChecklistService::forProvider(array holder)                                                             app/src/ChecklistService.php:27
 *   flx_compliance_data(...)                                                                                index.php:4561
 *
 * KEY STRUCTURAL FACT (proven from source): flx_compliance_data() internally calls
 * CatalogService::coverageDetail (index.php ~4612), so at the coverage layer it is NOT independent of
 * CatalogService. AND flx_compliance_data() calls RegulatorRequirementRepository::byRegulator() which
 * SELECTs the non-existent `regulator_requirements` table with no try/catch — so flx_compliance_data()
 * throws on any schema without that table. Captured as RUNTIME_FAILURE, not faked.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // CLI only — never runnable from a browser

/** Normalize any engine output to: token => 'satisfied'|'unsatisfied' (satisfied = active|expiring;
 *  unsatisfied = missing|expired|absent). Rationale documented in the baseline .md. */
function char_norm_coverage_from_detail(array $coverageDetail, array $requiredTokens): array {
    $d = [];
    foreach ($requiredTokens as $tok) {
        $st = $coverageDetail[$tok]['status'] ?? 'missing';
        $d[$tok] = in_array($st, ['active','expiring'], true) ? 'satisfied' : 'unsatisfied';
        $d['__status__'][$tok] = $st;   // keep raw status for representation-difference analysis
    }
    return $d;
}
function char_norm_coverage_from_checklist(array $checklist, array $requiredTokens): array {
    $byTok = [];
    foreach (($checklist['rows'] ?? []) as $r) { if (empty($r['is_extra'])) $byTok[$r['token']] = $r['status'] ?? 'missing'; }
    $d = [];
    foreach ($requiredTokens as $tok) {
        $st = $byTok[$tok] ?? 'missing';
        $d[$tok] = in_array($st, ['active','expiring'], true) ? 'satisfied' : 'unsatisfied';
        $d['__status__'][$tok] = $st;
    }
    return $d;
}
function char_norm_coverage_from_flx(array $flxRows, array $requiredTokens): array {
    $byTok = [];
    foreach ($flxRows as $r) { $t = $r['token'] ?? ($r['code'] ?? null); if ($t !== null) $byTok[$t] = $r['status'] ?? 'missing'; }
    $d = [];
    foreach ($requiredTokens as $tok) {
        $st = $byTok[$tok] ?? 'ABSENT_ROW';
        $d[$tok] = in_array($st, ['active','expiring'], true) ? 'satisfied' : 'unsatisfied';
        $d['__status__'][$tok] = $st;
    }
    return $d;
}

/** Classify a pair of per-token decision maps. */
function char_classify(array $a, array $b): array {
    $tokens = array_unique(array_merge(array_keys(array_diff_key($a, ['__status__'=>1])), array_keys(array_diff_key($b, ['__status__'=>1]))));
    $decDiff = []; $repDiff = [];
    foreach ($tokens as $t) {
        if ($t === '__status__') continue;
        $da = $a[$t] ?? 'ABSENT'; $db = $b[$t] ?? 'ABSENT';
        if ($da !== $db) $decDiff[$t] = [$da, $db];
        else {
            $sa = $a['__status__'][$t] ?? null; $sb = $b['__status__'][$t] ?? null;
            if ($sa !== $sb) $repDiff[$t] = [$sa, $sb];   // same decision, different raw status label
        }
    }
    $cls = $decDiff ? 'REAL_BUSINESS_DISAGREEMENT' : ($repDiff ? 'REPRESENTATION_DIFFERENCE' : 'AGREEMENT');
    return ['classification'=>$cls, 'decision_differences'=>$decDiff, 'representation_differences'=>$repDiff];
}

/** Run all engines for one already-built provider row; return the per-scenario capture block. */
function char_capture_scenario(FlxCoreHC\TenantContext $ctx, array $holder): array {
    $hid = $holder['id'];
    $C = new FlxCoreHC\Repository\CredentialRepository($ctx);
    $creds = $C->all(['holder_id'=>$hid], 'expiry_date ASC', 500);
    $required = (new FlxCoreHC\Repository\ProducerRequirementRepository($ctx))->tokensFor($hid);
    $coverageTokens = FlxCoreHC\CatalogService::coverageTokens($creds);

    $block = ['inputs'=>['credentials'=>count($creds),'required_tokens'=>count($required),'tokens'=>$required]];

    // --- Requirement-coverage layer (comparable) ---
    $covDetail = FlxCoreHC\CatalogService::coverageDetail($creds);
    $block['engines']['CatalogService.coverageDetail'] = $covDetail;
    $normCatalog   = char_norm_coverage_from_detail($covDetail, $required);

    $checklist = (new FlxCoreHC\ChecklistService($ctx))->forProvider($holder);
    $block['engines']['ChecklistService.forProvider'] = ['done'=>$checklist['done'],'total'=>$checklist['total'],
        'pct'=>$checklist['pct'],'missing'=>$checklist['missing'],'expiring'=>$checklist['expiring'],
        'expired'=>$checklist['expired'],'rows'=>array_map(fn($r)=>['token'=>$r['token'],'status'=>$r['status'],'is_extra'=>$r['is_extra']],$checklist['rows'])];
    $normChecklist = char_norm_coverage_from_checklist($checklist, $required);

    // --- Overall-compliance layer (SEPARATE concept) ---
    $level = FlxCoreHC\ComplianceLevelService::evaluate($ctx, $holder, $creds, $required, $coverageTokens);
    $block['engines']['ComplianceLevelService.evaluate'] = $level;

    // --- flx_compliance_data (guarded: proven to hit the missing regulator_requirements table) ---
    $flxNorm = null;
    try {
        $H  = new FlxCoreHC\Repository\HolderRepository($ctx);
        $PR = new FlxCoreHC\Repository\ProducerRequirementRepository($ctx);
        $RG = new FlxCoreHC\Repository\RegulatorRepository($ctx);
        $DOC= new FlxCoreHC\Repository\DocumentRepository($ctx);
        $RR = new FlxCoreHC\Repository\RegulatorRequirementRepository($ctx);
        $HR = new FlxCoreHC\Repository\HolderRegulatorRepository($ctx);
        $fx = flx_compliance_data($H, $C, $PR, $RR, $RG, 'medical_group', null, $DOC, null, $HR);
        $mine = null; foreach (($fx['producers'] ?? []) as $p) { if (($p['holder']['id'] ?? null) === $hid) { $mine = $p; break; } }
        $block['engines']['flx_compliance_data'] = $mine ? ['found'=>true,'total'=>$mine['total']??null,'active'=>$mine['active']??null,'pct'=>$mine['pct']??null,'rows'=>$mine['rows']??[]] : ['found'=>false];
        if ($mine) $flxNorm = char_norm_coverage_from_flx($mine['rows'] ?? [], $required);
    } catch (\Throwable $e) {
        $block['engines']['flx_compliance_data'] = ['RUNTIME_FAILURE'=>$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine()];
    }

    // --- Requirement-coverage comparisons (equivalent decisions only) ---
    $block['comparisons'] = [
        'CatalogService_vs_ChecklistService' => char_classify($normCatalog, $normChecklist),
    ];
    if ($flxNorm !== null) {
        $block['comparisons']['CatalogService_vs_flx_compliance_data'] = char_classify($normCatalog, $flxNorm);
    } else {
        $block['comparisons']['CatalogService_vs_flx_compliance_data'] = ['classification'=>'RUNTIME_FAILURE',
            'note'=>'flx_compliance_data did not return a comparable result (see engine RUNTIME_FAILURE).'];
    }
    return $block;
}
