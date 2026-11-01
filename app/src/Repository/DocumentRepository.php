<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC\Repository;

use FlxCoreHC\Database;

final class DocumentRepository extends TenantRepository
{
    /** Support mode (consent-gated platform access): PHI repositories refuse all reads. */
    /**
     * Provider scope for documents resolves through the owning credential (documents carry credential_id,
     * not holder_id). Applied in SQL (v1.170.9) so a valid document id can never bypass need-to-know.
     */
    protected function credentialColumn(): ?string { return 'credential_id'; }
    private function docScope(): array { return $this->scopeSql(); }

    public function find(string $id): ?array
    {
        if (\FlxCoreHC\SupportAccess::active()) return null;
        [$scope, $sp] = $this->docScope();
        $st = Database::pdo()->prepare("SELECT * FROM credential_documents WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL{$scope} LIMIT 1");
        $st->execute(array_merge([$id, $this->ctx->tenantId], $sp));
        $row = $st->fetch();
        return $row === false ? null : $this->decryptRow($row);
    }
    public function all(array $where = [], string $orderBy = 'created_at DESC', int $limit = 500): array
    {
        if (\FlxCoreHC\SupportAccess::active()) return [];
        $this->assertOrderBy($orderBy);
        $limit = max(1, min($limit, 5000));
        [$scope, $sp] = $this->docScope();
        $sql = "SELECT * FROM credential_documents WHERE tenant_id = ? AND deleted_at IS NULL{$scope}";
        $params = array_merge([$this->ctx->tenantId], $sp);
        foreach ($where as $col => $val) { $this->assertColumn($col); $sql .= " AND `{$col}` = ?"; $params[] = $val; }
        $sql .= " ORDER BY {$orderBy} LIMIT {$limit}";
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return $this->decryptRows($st->fetchAll());
    }

    protected function table(): string { return 'credential_documents'; }

    protected function fillable(): array
    {
        return ['credential_id','supersedes_id','kind','sort_order','file_path','original_name','mime_type','uploaded_by', 'size_bytes',
                'verified_at','verified_by','verification_method','verification_note','verification_expires'];
    }

    /** credential_id => number of evidence documents on file (tenant-scoped). */
    public function countByCredential(): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT credential_id, COUNT(*) AS n FROM credential_documents
             WHERE tenant_id = ? GROUP BY credential_id"
        );
        $stmt->execute([$this->ctx->tenantId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) { $out[$r['credential_id']] = (int)$r['n']; }
        return $out;
    }
}
