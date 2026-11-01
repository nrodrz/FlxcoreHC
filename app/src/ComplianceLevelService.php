<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Compliance LEVELS — a ladder, not just a score — with HARD STOPS.
 *
 *   0  Not compliant   — hard stop: PR professional license missing/expired, or an active exclusion flag
 *   1  Licensed        — active professional license on file
 *   2  Credentialed    — level 1 + every required template item present and not expired
 *   3  Payer-credentialed — level 2 + credentialed/participating with at least one payer
 *   4  Billable        — level 3 + NPI on file + enrolled in Medicaid or Medicare (or a commercial contract
 *                        effective) + no expired items
 *   5  All tracked requirements current — level 4 + OCS/SICRO attested + all payers current + nothing expiring within 30 days
 *
 * A provider can be "licensed but not billable" — exactly the distinction a billing company needs.
 */
final class ComplianceLevelService
{
    public const LABELS = [0 => 'Not compliant', 1 => 'Licensed', 2 => 'Credentialed', 3 => 'Payer-credentialed', 4 => 'Billable', 5 => 'All tracked requirements current'];
    private const LICENSE_TYPES = ['state_medical_license', 'license', 'state_license', 'professional_license'];

    /**
     * @param array $creds     the provider's credentials (rows)
     * @param array $required  required catalog codes from the template
     * @param array $coverage  codes present (from CatalogService::coverageTokens)
     * @return array{level:int,label:string,reasons:array<string>,hard_stop:?string}
     */
    public static function evaluate(TenantContext $ctx, array $holder, array $creds, array $required, array $coverage): array
    {
        $svc = new EnrollmentService($ctx);
        $reasons = []; $stop = null;
        $today = today_local();

        // --- hard stops ---
        $license = null;
        foreach ($creds as $c) {
            if (in_array((string)$c['credential_type'], self::LICENSE_TYPES, true) && empty($c['deleted_at'])) { $license = $c; break; }
        }
        if (!$license) $stop = t('No professional license on file.');
        elseif (!empty($license['expiry_date']) && $license['expiry_date'] < $today) $stop = t('Professional license expired on {date}.', ['date' => fmt_day($license['expiry_date'])]);
        elseif (($license['status'] ?? '') === 'suspended') $stop = t('Professional license is suspended.');
        try {
            $f = Database::pdo()->prepare("SELECT COUNT(*) FROM exclusion_screenings WHERE tenant_id = ? AND holder_id = ? AND LOWER(result) IN ('potential_match','match','excluded','hit','flag','flagged','possible_match')");
            $f->execute([$ctx->tenantId, $holder['id']]);
            if ((int)$f->fetchColumn() > 0) $stop = $stop ?? 'Exclusion screening flag unresolved.';
        } catch (\Throwable $e) {
            // v1.170.9 — FAIL CLOSED: an unverifiable screening state is a hard stop, never "no flag".
            flx_log_error('error', 'exclusion screening lookup failed during compliance evaluation');
            $stop = $stop ?? 'Exclusion screening status could not be verified (system error) — treat as unresolved.';
        }
        if ($stop) return ['level' => 0, 'label' => self::LABELS[0], 'reasons' => [$stop], 'hard_stop' => $stop];

        $level = 1;
        // --- level 2: required items present + nothing required is expired ---
        $missing = [];
        foreach ($required as $tok) { $code = CatalogService::codeFor($tok); if (!in_array($code, $coverage, true) && !in_array($tok, $coverage, true)) $missing[] = $code; }
        $expiredReq = array_values(array_filter($creds, fn($c) => empty($c['deleted_at']) && !empty($c['expiry_date']) && $c['expiry_date'] < $today));
        if ($missing === [] && $expiredReq === []) $level = 2;
        else { if ($missing) $reasons[] = t('Missing required: {list}', ['list' => implode(', ', $missing)]); if ($expiredReq) $reasons[] = t('{n} expired credential(s).', ['n' => count($expiredReq)]); return self::out($level, $reasons); }

        // --- level 3: at least one payer in-network ---
        $payers = $svc->payerRows($holder['id']);
        $activePayers = array_values(array_filter($payers, fn($p) => in_array($p['status'], EnrollmentService::PAYER_ACTIVE, true)));
        if ($activePayers) $level = 3; else { $reasons[] = t('Not credentialed with any payer yet.'); return self::out($level, $reasons); }

        // --- level 4: billable ---
        $programs = $svc->programs($holder['id']);
        $enrolled = array_filter($programs, fn($p) => in_array($p['status'], EnrollmentService::PROGRAM_ACTIVE, true));
        $hasNpi = !empty($holder['npi']);
        $contractEffective = array_filter($activePayers, fn($p) => !empty($p['effective_date']) && $p['effective_date'] <= $today);
        if (!$hasNpi) { $reasons[] = t('No NPI on file.'); return self::out($level, $reasons); }
        if (!$enrolled && !$contractEffective) { $reasons[] = t('No Medicaid/Medicare enrollment or effective payer contract.'); return self::out($level, $reasons); }
        $level = 4;

        // --- level 5: every requirement FlxCoreHC tracks is current (not a legal/regulatory compliance determination) ---
        $ocs = $svc->ocs($holder['id']);
        $soon = today_local('+30 days');
        $expiring = array_filter($creds, fn($c) => empty($c['deleted_at']) && !empty($c['expiry_date']) && $c['expiry_date'] <= $soon);
        $stalePayers = array_filter($payers, fn($p) => in_array($p['status'], ['expired','suspended','denied','terminated'], true) || (!empty($p['recredentialing_date']) && $p['recredentialing_date'] < $today));
        $reval = array_filter($programs, fn($p) => !empty($p['revalidation_date']) && $p['revalidation_date'] < $today);
        // OCS/SICRO is a Puerto Rico program: only a tenant that operates in PR is held to it.
        if (in_array('PR', OrgService::statesFor($ctx->tenantId), true) && (!$ocs || $ocs['sicro_status'] !== 'attested')) $reasons[] = t('OCS / SICRO not attested.');
        if ($expiring) $reasons[] = t('{n} credential(s) expiring within 30 days.', ['n' => count($expiring)]);
        if ($stalePayers) $reasons[] = t('A payer credential is expired/lapsed.');
        if ($reval) $reasons[] = t('A program revalidation is overdue.');
        if ($reasons === []) $level = 5;
        return self::out($level, $reasons);
    }

    private static function out(int $level, array $reasons): array
    {
        return ['level' => $level, 'label' => self::LABELS[$level], 'reasons' => $reasons, 'hard_stop' => null];
    }
}
