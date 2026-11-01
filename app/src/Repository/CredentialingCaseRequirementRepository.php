<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC\Repository;

use FlxCoreHC\Database;

/**
 * Case-specific requirement checklist. Bound to the ACTIVE requirement identity: credential_catalog.code
 * (NOT the dormant requirement_defs/holder_requirements path). Distinct from ongoing provider compliance.
 */
final class CredentialingCaseRequirementRepository extends TenantRepository
{
    public const STATUSES = ['pending'=>'Pending','in_progress'=>'In progress','satisfied'=>'Satisfied','waived'=>'Waived','failed'=>'Failed'];

    protected function table(): string { return 'credentialing_case_requirements'; }

    protected function fillable(): array
    {
        return ['case_id','requirement_code','label','status','is_required','is_hard_stop',
                'due_date','completed_at','assigned_to','notes'];
    }

    protected function softDeletes(): bool { return false; }

    /** Requirements for a case, problems first. Uses a raw tenant-scoped prepared statement because the
     *  FIELD() ordering (quoted status literals) is intentionally rejected by the guarded all()'s
     *  ORDER BY backstop — this mirrors the established pattern in ApplicationRepository /
     *  DocumentRequestService. Tenant scoping is preserved. */
    public function forCase(string $caseId): array
    {
        $st = Database::pdo()->prepare(
            "SELECT * FROM credentialing_case_requirements
             WHERE tenant_id = ? AND case_id = ?
             ORDER BY FIELD(status,'failed','pending','in_progress','waived','satisfied'), requirement_code
             LIMIT 1000");
        $st->execute([$this->ctx->tenantId, $caseId]);
        return $st->fetchAll();
    }

    /** Unresolved hard-stop requirements for a case — the gate for ready_for_submission. */
    public function unresolvedHardStops(string $caseId): array
    {
        $st = Database::pdo()->prepare(
            "SELECT * FROM credentialing_case_requirements
             WHERE tenant_id = ? AND case_id = ? AND is_required = 1 AND is_hard_stop = 1
               AND status NOT IN ('satisfied','waived')");
        $st->execute([$this->ctx->tenantId, $caseId]);
        return $st->fetchAll();
    }

    /** Guarded insert: rejects a duplicate (case_id, requirement_code) within the tenant. */
    public function addRequirement(array $data): string
    {
        $exists = Database::pdo()->prepare(
            "SELECT id FROM credentialing_case_requirements WHERE tenant_id = ? AND case_id = ? AND requirement_code = ? LIMIT 1");
        $exists->execute([$this->ctx->tenantId, $data['case_id'] ?? '', $data['requirement_code'] ?? '']);
        if ($dup = $exists->fetchColumn()) return (string) $dup;   // idempotent: return the existing row
        return $this->insert($data);
    }
}
