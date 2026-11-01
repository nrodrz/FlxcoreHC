<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Application readiness — the data audit for credentialing/enrollment applications, computed per
 * provider. Maps what CAQH ProView, OCS/SICRO, Medicaid PEP, Medicare 855 and payer packets ask for
 * against what is on file, and lists exactly what is missing so staff can collect it BEFORE starting
 * an application (the #1 cause of application delays is chasing missing data mid-process).
 *
 * Items marked 'decision' are deliberately not stored yet (DOB, SSN): they carry privacy weight and
 * need encryption + need-to-know like NPI. The checklist surfaces them so the gap is visible.
 */
final class ApplicationReadinessService
{
    public function __construct(private readonly TenantContext $ctx) {}

    /** @return array{score:int, groups:array<string,array<int,array{label:string,ok:bool,value:?string,note:?string,how:?string}>>} */
    /** @param string[] $states tenant operating states (e.g. ['PR']); PR-only items are shown only when PR is included. */
    public function evaluate(array $h, array $states = []): array
    {
        $pr = in_array('PR', array_map('strtoupper', $states), true);
        $hid = (string)$h['id'];
        $creds = (new Repository\CredentialRepository($this->ctx))->all(['holder_id' => $hid], 'expiry_date ASC', 500);
        $types = array_column($creds, 'credential_type');
        $has = fn(string ...$codes) => (bool) array_intersect($codes, $types);
        $val = fn(?string $v) => ($v !== null && trim((string)$v) !== '') ? (string)$v : null;
        $addr = (new Repository\AddressRepository($this->ctx))->forOwner('provider', $hid);
        $edu  = (new Repository\ProviderEducationRepository($this->ctx))->forHolder($hid);
        $work = (new Repository\ProviderWorkHistoryRepository($this->ctx))->forHolder($hid);
        $contacts = (new Repository\ProviderContactRepository($this->ctx))->forHolder($hid);
        $enroll = new EnrollmentService($this->ctx);
        $programs = $enroll->programs($hid); $ocs = $enroll->ocs($hid);
        $item = fn(string $label, bool $ok, ?string $value = null, ?string $note = null, ?string $how = null) => compact('label','ok','value','note','how');

        $g = [];
        $g['Identity'] = [
            $item('Legal name', $val($h['first_name']) && $val($h['last_name']), trim(($h['first_name'] ?? '') . ' ' . ($h['middle_name'] ?? '') . ' ' . ($h['last_name'] ?? '')), null, 'Provider → Edit'),
            $item('Prior / maiden names (if any)', true, $val($h['prior_names'] ?? null), 'Asked by CAQH and OCS; leave blank if none', 'Provider → Edit'),
            $item('Gender', (bool)$val($h['gender'] ?? null), $val($h['gender'] ?? null), null, 'Provider → Edit'),
            $item('Languages spoken', (bool)$val($h['languages'] ?? null), $val($h['languages'] ?? null), 'Payer directories require it', 'Provider → Edit'),
            $item('Date of birth', (bool)$val($h['dob'] ?? null), SensitiveFields::display($this->ctx, 'dob', $h['dob'] ?? null), 'Encrypted; visible only with the sensitive-data permission', 'Provider → Edit → Sensitive identity data'),
            $item('SSN (last 4)', (bool)$val($h['ssn_last4'] ?? null), SensitiveFields::display($this->ctx, 'ssn_last4', $h['ssn_last4'] ?? null), 'Last 4 only — the full number is never stored', 'Provider → Edit → Sensitive identity data'),
            $item('Government ID on file', $has('gov_id'), null, null, 'Credentials → add Government ID'),
        ];
        $g['Identifiers'] = [
            $item('NPI (Type 1)', (bool)$val($h['npi'] ?? null), $val($h['npi'] ?? null), null, 'Provider → Edit'),
            $item('NPI verified against NPPES', !empty($h['npi_verified_at']), $h['npi_verified_at'] ? substr((string)$h['npi_verified_at'],0,10) : null, null, 'Provider → Verify NPI (NPPES)'),
            $item('Taxonomy code', (bool)$val($h['taxonomy_code'] ?? null), $val($h['taxonomy_code'] ?? null), 'Auto-filled from NPPES when verified', 'Provider → Edit'),
            $item('CAQH ID', (bool)$val($h['caqh_id'] ?? null), $val($h['caqh_id'] ?? null), 'Most commercial payers pull from CAQH', 'Provider → Edit'),
            $item('Medicare PTAN', (bool)($val($h['medicare_ptan'] ?? null) ?: ($programs['medicare']['enrollment_id'] ?? null)), $val($h['medicare_ptan'] ?? null) ?: ($programs['medicare']['enrollment_id'] ?? null), null, 'Payers & enrollment → Medicare'),
            $item('Medicaid provider ID', (bool)($val($h['medicaid_id'] ?? null) ?: ($programs['medicaid']['enrollment_id'] ?? null)), $val($h['medicaid_id'] ?? null) ?: ($programs['medicaid']['enrollment_id'] ?? null), null, 'Payers & enrollment → Medicaid'),
            $item('DEA registration', $has('dea'), null, 'Prescribers only', 'Credentials → add DEA'),
            ...($pr ? [$item('Negative controlled-substances (drug-test) certificate (PR)', $has('pr_cds_negative'), null, 'Applicability UNVERIFIED — confirm with your licensing board before requiring it. A DEA or state controlled-substance registration is a different document.', 'Credentials')] : []),
        ];
        $g['Licensure & standing'] = [
            $item($pr ? 'Professional license (JLDM / PR licensing board)' : 'Professional / state license', $has('pr_medical_license','pr_nursing_license','state_medical_license','professional_license','license'), null, null, 'Credentials'),
            $item($pr ? 'Good Standing (ORCPS/JLDM)' : 'Good Standing / license verification', $has('good_standing'), null, 'Payers request it', 'Credentials → Good Standing'),
            ...($pr ? [$item('Buena Conducta (police certificate)', $has('buena_conducta','pr_good_conduct'), null, 'ORCPS recertification package', 'Credentials')] : []),
            ...($pr ? [$item('ASUME certificate', $has('asume'), null, 'ORCPS recertification package', 'Credentials')] : []),
            $item('Board certification', $has('board_cert'), null, 'If board certified', 'Credentials'),
            $item('CME / CE evidence', $has('cme','pr_cme'), null, $pr ? 'PR recertification cycle: 36 months' : 'Per licensing-board cycle', 'Credentials'),
        ];
        $g['Insurance & practice'] = [
            $item('Malpractice / liability certificate', $has('malpractice','group_malpractice'), null, null, 'Credentials → Malpractice'),
            $item('Primary specialty', (bool)$val($h['primary_specialty'] ?? null), $val($h['primary_specialty'] ?? null), null, 'Provider → Edit'),
            $item('Practice location(s)', count(LocationService::forHolder($this->ctx, $hid)) > 0, null, 'Medicaid PEP: unregistered locations deny claims', 'Provider → Locations'),
            $item('Mailing / correspondence address', (bool)array_filter($addr, fn($a) => in_array($a['kind'], ['mailing','correspondence'], true)), null, null, 'Provider → Addresses'),
            $item('Billing address', (bool)array_filter($addr, fn($a) => $a['kind'] === 'billing'), null, 'Payer contracts', 'Provider → Addresses'),
            $item('Hospital affiliations', (bool)$val($h['hospital_affiliations'] ?? null), $val($h['hospital_affiliations'] ?? null), 'If any', 'Provider → Edit'),
            $item('Office contact', $contacts !== [], null, 'Who payers may call', 'Provider → Office contacts'),
        ];
        $g['History'] = [
            $item('Professional education (medical school etc.)', (bool)array_filter($edu, fn($e) => $e['kind'] === 'professional'), null, null, 'Provider → Education & history'),
            $item('Residency / training', (bool)array_filter($edu, fn($e) => in_array($e['kind'], ['residency','fellowship','internship'], true)), null, 'If applicable', 'Provider → Education & history'),
            $item('Work history (≥ 5 years, gaps explained)', $work !== [], null, 'CAQH asks for 5 years with gaps > 6 months explained', 'Provider → Education & history'),
            $item('CV on file', $has('cv'), null, null, 'Credentials → CV'),
        ];
        $g['Enrollment status'] = [
            ...($pr ? [$item('OCS / SICRO registered or attested', $ocs && in_array($ocs['sicro_status'], ['registered','attested'], true), $ocs['sicro_status'] ?? null, 'Compulsory (Ley 73-2023)', 'Payers & enrollment → OCS')] : []),
            $item($pr ? 'Medicaid PEP enrolled' : 'Medicaid enrolled', in_array($programs['medicaid']['status'] ?? '', EnrollmentService::PROGRAM_ACTIVE, true), $programs['medicaid']['status'] ?? null, null, 'Payers & enrollment'),
            $item('Medicare PECOS enrolled', in_array($programs['medicare']['status'] ?? '', EnrollmentService::PROGRAM_ACTIVE, true), $programs['medicare']['status'] ?? null, 'If billing Medicare', 'Payers & enrollment'),
        ];
        // score: only count items that are decidable (exclude 'decision pending' and 'if any' informational ones)
        $total = 0; $ok = 0;
        foreach ($g as $items) foreach ($items as $i) { if ($i['how'] === null) continue; $total++; if ($i['ok']) $ok++; }
        return ['score' => $total ? (int)round(100 * $ok / $total) : 0, 'ok' => $ok, 'total' => $total, 'groups' => $g];
    }
}
