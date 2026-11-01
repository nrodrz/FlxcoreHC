<?php
declare(strict_types=1);

namespace FlxCoreHC\Repository;

use FlxCoreHC\Database;

/**
 * Typed evidence links between a case requirement and the row that satisfies it. NO polymorphic
 * evidence_type/id — one nullable typed FK per supported target. Exactly ONE target must be set per
 * row; enforced here (MySQL on shared hosting lacks a portable multi-column XOR CHECK). Cross-tenant
 * evidence can never satisfy a requirement: the target's tenant is validated before linking.
 */
final class CaseRequirementEvidenceRepository extends TenantRepository
{
    /** target column => the table it points at (for tenant-ownership validation). */
    public const TARGETS = [
        'credential_id'          => 'credentials',
        'credential_document_id' => 'credential_documents',
        'case_document_id'       => 'case_documents',
        'verification_id'        => 'source_verifications',
        'attestation_id'         => 'holder_attestations',
    ];

    protected function table(): string { return 'case_requirement_evidence'; }

    protected function fillable(): array
    {
        return ['case_requirement_id','credential_id','credential_document_id','case_document_id',
                'verification_id','attestation_id','created_by'];
    }

    protected function softDeletes(): bool { return false; }

    public function forRequirement(string $caseRequirementId): array
    {
        return $this->all(['case_requirement_id' => $caseRequirementId], 'created_at DESC', 200);
    }

    /**
     * Link one evidence target to a case requirement. Returns [ok, messageOrId].
     * - exactly one target column must be provided
     * - the target row must exist AND belong to this tenant (no cross-tenant evidence)
     * - duplicate links (same requirement + same target) are rejected (idempotent)
     */
    public function link(string $caseRequirementId, array $target, ?string $createdBy = null): array
    {
        $set = array_intersect_key($target, self::TARGETS);
        $set = array_filter($set, fn($v) => $v !== null && $v !== '');
        if (count($set) !== 1) return [false, 'Provide exactly one evidence target.'];
        $col = array_key_first($set); $id = (string) $set[$col]; $table = self::TARGETS[$col];
        $pdo = Database::pdo(); $t = $this->ctx->tenantId;

        // (1) the case requirement must exist in this tenant …
        $rq = $pdo->prepare("SELECT case_id FROM credentialing_case_requirements WHERE id = ? AND tenant_id = ? LIMIT 1");
        $rq->execute([$caseRequirementId, $t]);
        $caseId = $rq->fetchColumn();
        if ($caseId === false) return [false, 'Case requirement not found in this tenant.'];

        // (2) … and (3) its case must exist in this tenant, giving us the case provider (4).
        $cs = $pdo->prepare("SELECT holder_id FROM credentialing_cases WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL LIMIT 1");
        $cs->execute([(string) $caseId, $t]);
        $caseHolder = $cs->fetchColumn();
        if ($caseHolder === false) return [false, 'Case not found in this tenant.'];
        $caseHolder = (string) $caseHolder;

        // (5) the evidence target must exist in this tenant AND resolve to the SAME provider
        //     (and, where case-scoped, must not belong to a DIFFERENT case). Tenant equality is not enough.
        [$okOwner, $why] = $this->targetBelongsToCase($col, $id, $t, $caseHolder, (string) $caseId);
        if (!$okOwner) return [false, $why];

        // duplicate guard (idempotent)
        $dup = $pdo->prepare(
            "SELECT id FROM case_requirement_evidence WHERE tenant_id = ? AND case_requirement_id = ? AND {$col} = ? LIMIT 1");
        $dup->execute([$t, $caseRequirementId, $id]);
        if ($existing = $dup->fetchColumn()) return [true, (string) $existing];

        $rowId = $this->insert(['case_requirement_id' => $caseRequirementId, $col => $id, 'created_by' => $createdBy]);
        return [true, $rowId];
    }

    /**
     * Validate that an evidence target belongs to the case's provider (and correct case where applicable).
     * @return array{0:bool,1:string}
     */
    private function targetBelongsToCase(string $col, string $id, string $tenantId, string $caseHolder, string $caseId): array
    {
        $pdo = Database::pdo();
        switch ($col) {
            case 'credential_id':          // must belong to the case provider
            case 'attestation_id':         // holder_attestations.holder_id
            case 'case_document_id':       // case_documents.holder_id (+ case check below)
            case 'verification_id':        // source_verifications.holder_id (+ case check below)
                $table = self::TARGETS[$col];
                $st = $pdo->prepare("SELECT holder_id" . (in_array($col, ['case_document_id','verification_id'], true) ? ", case_id" : "") . " FROM {$table} WHERE id = ? AND tenant_id = ? LIMIT 1");
                $st->execute([$id, $tenantId]);
                $row = $st->fetch();
                if (!$row) return [false, 'Evidence not found in this tenant.'];
                if ((string) $row['holder_id'] !== $caseHolder) return [false, 'Evidence belongs to a different provider.'];
                if (in_array($col, ['case_document_id','verification_id'], true)) {
                    $rc = $row['case_id'] ?? null;
                    if ($rc !== null && (string) $rc !== $caseId) return [false, 'Evidence belongs to a different case.'];
                }
                return [true, ''];
            case 'credential_document_id': // resolve through its credential to the case provider
                $st = $pdo->prepare(
                    "SELECT c.holder_id FROM credential_documents d
                       JOIN credentials c ON c.id = d.credential_id AND c.tenant_id = d.tenant_id AND c.deleted_at IS NULL
                      WHERE d.id = ? AND d.tenant_id = ? AND d.deleted_at IS NULL LIMIT 1");
                $st->execute([$id, $tenantId]);
                $h = $st->fetchColumn();
                if ($h === false) return [false, 'Document not found in this tenant.'];
                if ((string) $h !== $caseHolder) return [false, 'Document belongs to a different provider.'];
                return [true, ''];
        }
        return [false, 'Unsupported evidence target.'];
    }
}
