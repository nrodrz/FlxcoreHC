<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Payer credentialing, Medicaid/Medicare program enrollment, and the OCS/SICRO profile — the
 * "is this provider billable with X?" layer on top of licensing. Tenant-scoped throughout.
 */
final class EnrollmentService
{
    public const PAYER_STATUSES = [
        'not_started' => 'Not started', 'application_pending' => 'Application pending', 'under_review' => 'Under review',
        'credentialed' => 'Credentialed', 'contracted' => 'Contracted', 'participating' => 'Participating',
        'non_participating' => 'Non-participating', 'denied' => 'Denied', 'suspended' => 'Suspended',
        'terminated' => 'Terminated', 'recredentialing' => 'Recredentialing', 'expired' => 'Expired',
    ];
    /** Statuses that count as "in network / billable" with the payer. */
    public const PAYER_ACTIVE = ['credentialed', 'contracted', 'participating', 'recredentialing'];

    public const PROGRAM_STATUSES = [
        'not_enrolled' => 'Not enrolled', 'pending' => 'Pending', 'enrolled' => 'Enrolled',
        'revalidation_due' => 'Revalidation due', 'revalidating' => 'Revalidating', 'terminated' => 'Terminated', 'opted_out' => 'Opted out',
    ];
    public const PROGRAM_ACTIVE = ['enrolled', 'revalidation_due', 'revalidating'];
    public const PROGRAMS = ['medicaid' => 'Medicaid / Plan Vital (PEP)', 'medicare' => 'Medicare (PECOS / PTAN)'];
    public const OCS_STATUSES = ['not_registered' => 'Not registered', 'registered' => 'Registered', 'attested' => 'Attested', 'pending' => 'Pending', 'expired' => 'Attestation expired'];

    public function __construct(private readonly TenantContext $ctx) {}

    // ---- payers ----------------------------------------------------------------

    /** Platform-seeded payers plus this tenant's own. */
    public function payers(): array
    {
        $st = Database::pdo()->prepare('SELECT * FROM payers WHERE active = 1 AND (tenant_id IS NULL OR tenant_id = ?) ORDER BY kind, name');
        $st->execute([$this->ctx->tenantId]);
        return $st->fetchAll();
    }

    public function addPayer(string $name, string $kind): array
    {
        $name = trim($name);
        if ($name === '') return [false, 'Give the payer a name.'];
        $kind = in_array($kind, ['commercial','medicare_advantage','medicaid_mco','government','other'], true) ? $kind : 'other';
        Database::pdo()->prepare('INSERT INTO payers (id, tenant_id, name, kind, created_at) VALUES (?,?,?,?,?)')
            ->execute([uuid4(), $this->ctx->tenantId, mb_substr($name, 0, 120), $kind, utc_now()]);
        audit_log($this->ctx, 'payer_added', 'payers', null, json_encode(['name' => $name]));
        return [true, 'Payer added.'];
    }

    public function payerRows(string $holderId): array
    {
        if (SupportAccess::active()) return [];
        $st = Database::pdo()->prepare(
            'SELECT pc.*, p.name AS payer_name, p.kind AS payer_kind FROM payer_credentialing pc JOIN payers p ON p.id = pc.payer_id
             WHERE pc.tenant_id = ? AND pc.holder_id = ? ORDER BY p.name, pc.network');
        $st->execute([$this->ctx->tenantId, $holderId]);
        return $st->fetchAll();
    }

    public function savePayer(string $holderId, array $in): array
    {
        $payerId = (string)($in['payer_id'] ?? '');
        $ok = Database::pdo()->prepare('SELECT 1 FROM payers WHERE id = ? AND (tenant_id IS NULL OR tenant_id = ?)'); $ok->execute([$payerId, $this->ctx->tenantId]);
        if (!$ok->fetchColumn()) return [false, 'Choose a payer.'];
        $status = isset(self::PAYER_STATUSES[$in['status'] ?? '']) ? $in['status'] : 'not_started';
        $d = fn(string $k) => (isset($in[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in[$k])) ? $in[$k] : null;
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO payer_credentialing (id, tenant_id, holder_id, payer_id, network, status, application_date, approval_date, effective_date,
                                              recredentialing_date, termination_date, contract_number, payer_provider_id, notes, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), application_date = VALUES(application_date), approval_date = VALUES(approval_date),
               effective_date = VALUES(effective_date), recredentialing_date = VALUES(recredentialing_date), termination_date = VALUES(termination_date),
               contract_number = VALUES(contract_number), payer_provider_id = VALUES(payer_provider_id), notes = VALUES(notes), updated_at = VALUES(updated_at)'
        )->execute([uuid4(), $this->ctx->tenantId, $holderId, $payerId, mb_substr(trim((string)($in['network'] ?? '')), 0, 80) ?: '', $status,
            $d('application_date'), $d('approval_date'), $d('effective_date'), $d('recredentialing_date'), $d('termination_date'),
            mb_substr(trim((string)($in['contract_number'] ?? '')), 0, 80) ?: null, mb_substr(trim((string)($in['payer_provider_id'] ?? '')), 0, 80) ?: null,
            mb_substr(trim((string)($in['notes'] ?? '')), 0, 500) ?: null, utc_now(), utc_now()]);
        audit_log($this->ctx, 'payer_credentialing_saved', 'holders', $holderId, json_encode(['payer' => $payerId, 'status' => $status]));
        ComplianceEventService::record($this->ctx, 'payer_status', 'Payer credentialing: ' . (self::PAYER_STATUSES[$status] ?? $status),
            ['holder_id' => $holderId, 'detail' => ['payer_id' => $payerId, 'status' => $status, 'recredentialing' => $d('recredentialing_date')]]);
        return [true, 'Payer status saved.'];
    }

    public function deletePayerRow(string $holderId, string $rowId): void
    {
        Database::pdo()->prepare('DELETE FROM payer_credentialing WHERE id = ? AND tenant_id = ? AND holder_id = ?')->execute([$rowId, $this->ctx->tenantId, $holderId]);
        audit_log($this->ctx, 'payer_credentialing_removed', 'holders', $holderId, json_encode(['row' => $rowId]));
    }

    // ---- programs (Medicaid / Medicare) --------------------------------------------

    public function programs(string $holderId): array
    {
        if (SupportAccess::active()) return [];
        $st = Database::pdo()->prepare('SELECT * FROM program_enrollments WHERE tenant_id = ? AND holder_id = ?');
        $st->execute([$this->ctx->tenantId, $holderId]);
        $out = [];
        foreach ($st->fetchAll() as $r) { $out[$r['program']] = $r; }
        return $out;
    }

    public function saveProgram(string $holderId, array $in): array
    {
        $program = isset(self::PROGRAMS[$in['program'] ?? '']) ? $in['program'] : null;
        if (!$program) return [false, 'Choose a program.'];
        $status = isset(self::PROGRAM_STATUSES[$in['status'] ?? '']) ? $in['status'] : 'not_enrolled';
        $d = fn(string $k) => (isset($in[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in[$k])) ? $in[$k] : null;
        $etype = in_array($in['enrollment_type'] ?? '', ['individual','group','ordering_referring'], true) ? $in['enrollment_type'] : null;
        Database::pdo()->prepare(
            'INSERT INTO program_enrollments (id, tenant_id, holder_id, program, enrollment_id, status, enrollment_type, effective_date, revalidation_date,
                                              expiration_date, mac_or_region, group_affiliation, locations_registered, notes, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE enrollment_id = VALUES(enrollment_id), status = VALUES(status), enrollment_type = VALUES(enrollment_type),
               effective_date = VALUES(effective_date), revalidation_date = VALUES(revalidation_date), expiration_date = VALUES(expiration_date),
               mac_or_region = VALUES(mac_or_region), group_affiliation = VALUES(group_affiliation), locations_registered = VALUES(locations_registered),
               notes = VALUES(notes), updated_at = VALUES(updated_at)'
        )->execute([uuid4(), $this->ctx->tenantId, $holderId, $program, mb_substr(trim((string)($in['enrollment_id'] ?? '')), 0, 80) ?: null, $status, $etype,
            $d('effective_date'), $d('revalidation_date'), $d('expiration_date'), mb_substr(trim((string)($in['mac_or_region'] ?? '')), 0, 80) ?: null,
            mb_substr(trim((string)($in['group_affiliation'] ?? '')), 0, 160) ?: null, !empty($in['locations_registered']) ? 1 : 0,
            mb_substr(trim((string)($in['notes'] ?? '')), 0, 500) ?: null, utc_now(), utc_now()]);
        audit_log($this->ctx, 'program_enrollment_saved', 'holders', $holderId, json_encode(['program' => $program, 'status' => $status]));
        ComplianceEventService::record($this->ctx, 'program_status', (self::PROGRAMS[$program]) . ': ' . (self::PROGRAM_STATUSES[$status] ?? $status),
            ['holder_id' => $holderId, 'detail' => ['program' => $program, 'status' => $status, 'revalidation' => $d('revalidation_date')]]);
        return [true, 'Enrollment saved.'];
    }

    // ---- OCS / SICRO --------------------------------------------------------------

    public function ocs(string $holderId): ?array
    {
        if (SupportAccess::active()) return null;
        $st = Database::pdo()->prepare('SELECT * FROM ocs_profiles WHERE tenant_id = ? AND holder_id = ? LIMIT 1');
        $st->execute([$this->ctx->tenantId, $holderId]);
        return $st->fetch() ?: null;
    }

    public function saveOcs(string $holderId, array $in): array
    {
        $status = isset(self::OCS_STATUSES[$in['sicro_status'] ?? '']) ? $in['sicro_status'] : 'not_registered';
        $d = fn(string $k) => (isset($in[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in[$k])) ? $in[$k] : null;
        Database::pdo()->prepare(
            'INSERT INTO ocs_profiles (id, tenant_id, holder_id, sicro_status, profile_id, last_attestation, attestation_expires, delegate_cvo, affidavit_on_file, notes, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE sicro_status = VALUES(sicro_status), profile_id = VALUES(profile_id), last_attestation = VALUES(last_attestation),
               attestation_expires = VALUES(attestation_expires), delegate_cvo = VALUES(delegate_cvo), affidavit_on_file = VALUES(affidavit_on_file), notes = VALUES(notes), updated_at = VALUES(updated_at)'
        )->execute([uuid4(), $this->ctx->tenantId, $holderId, $status, mb_substr(trim((string)($in['profile_id'] ?? '')), 0, 80) ?: null,
            $d('last_attestation'), $d('attestation_expires'), mb_substr(trim((string)($in['delegate_cvo'] ?? '')), 0, 160) ?: null,
            !empty($in['affidavit_on_file']) ? 1 : 0, mb_substr(trim((string)($in['notes'] ?? '')), 0, 500) ?: null, utc_now(), utc_now()]);
        audit_log($this->ctx, 'ocs_profile_saved', 'holders', $holderId, json_encode(['status' => $status]));
        ComplianceEventService::record($this->ctx, 'ocs_status', 'OCS / SICRO: ' . (self::OCS_STATUSES[$status] ?? $status),
            ['holder_id' => $holderId, 'detail' => ['status' => $status, 'attestation_expires' => $d('attestation_expires')]]);
        return [true, 'OCS profile saved.'];
    }

    /** Upcoming recredentialing / revalidation / attestation deadlines for the calendar sync. */
    public function deadlines(): array
    {
        $pdo = Database::pdo(); $out = [];
        $st = $pdo->prepare("SELECT pc.id, pc.holder_id, pc.recredentialing_date AS due, p.name FROM payer_credentialing pc JOIN payers p ON p.id = pc.payer_id
                             WHERE pc.tenant_id = ? AND pc.recredentialing_date IS NOT NULL AND pc.status NOT IN ('terminated','denied')");
        $st->execute([$this->ctx->tenantId]);
        foreach ($st->fetchAll() as $r) $out[] = ['source' => 'payer', 'ref' => $r['id'], 'holder_id' => $r['holder_id'], 'due' => $r['due'], 'title' => 'Recredentialing — ' . $r['name']];
        $st = $pdo->prepare("SELECT id, holder_id, program, revalidation_date AS due FROM program_enrollments WHERE tenant_id = ? AND revalidation_date IS NOT NULL AND status IN ('enrolled','revalidation_due','revalidating')");
        $st->execute([$this->ctx->tenantId]);
        foreach ($st->fetchAll() as $r) $out[] = ['source' => 'program', 'ref' => $r['id'], 'holder_id' => $r['holder_id'], 'due' => $r['due'], 'title' => ($r['program'] === 'medicaid' ? 'Medicaid PEP revalidation' : 'Medicare PECOS revalidation')];
        $st = $pdo->prepare("SELECT id, holder_id, attestation_expires AS due FROM ocs_profiles WHERE tenant_id = ? AND attestation_expires IS NOT NULL");
        $st->execute([$this->ctx->tenantId]);
        foreach ($st->fetchAll() as $r) $out[] = ['source' => 'ocs', 'ref' => $r['id'], 'holder_id' => $r['holder_id'], 'due' => $r['due'], 'title' => 'OCS / SICRO attestation'];
        return $out;
    }
}
