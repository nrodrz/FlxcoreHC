<?php
declare(strict_types=1);

namespace FlxCoreHC\Repository;

use FlxCoreHC\Database;

final class RenewalTaskRepository extends TenantRepository
{
    // Platform support sessions never see tenant records — see SupportAccess.
    public function find(string $id): ?array
    {
        if (\FlxCoreHC\SupportAccess::active()) return null;
        return parent::find($id);   // provider scope applied in SQL (base)
    }

    public function all(array $where = [], string $orderBy = 'created_at DESC', int $limit = 500): array
    {
        if (\FlxCoreHC\SupportAccess::active()) return [];
        return parent::all($where, $orderBy, $limit);
    }

    protected function table(): string { return 'renewal_tasks'; }
    protected function providerColumn(): ?string { return 'holder_id'; }   // need-to-know scope (v1.170.9)

    /** Tasks resolve their provider from the task OR (legacy rows with NULL holder_id) from their credential. */
    protected function scopeSql(?string $alias = null): array
    {
        $sc = \FlxCoreHC\ProviderScope::for($this->ctx);
        $pfx = $alias !== null ? $alias . '.' : '';
        return [$sc->sqlHolderOrCredential($pfx . 'holder_id', $pfx . 'credential_id'), $sc->paramsHolderOrCredential()];
    }

    protected function fillable(): array
    {
        return ['credential_id','holder_id','assigned_to','title','status','due_date','priority','steps','completed_at','source_key'];
    }

    public function openWithCredentials(): array
    {
        // LEFT JOINs so standalone tasks (no credential) still appear; holder can come
        // from the task directly or from its linked credential. Provider scope (need-to-know) is in the
        // WHERE clause on that same COALESCE, before ORDER/LIMIT (v1.170.9).
        $sc = \FlxCoreHC\ProviderScope::for($this->ctx);
        $stmt = Database::pdo()->prepare(
            "SELECT t.*, c.name AS credential_name, c.expiry_date,
                    h.holder_type, h.first_name, h.last_name, h.org_name,
                    u.full_name AS assignee_name
             FROM renewal_tasks t
             LEFT JOIN credentials c        ON c.id = t.credential_id AND c.tenant_id = t.tenant_id AND c.deleted_at IS NULL
             LEFT JOIN credential_holders h ON h.id = COALESCE(t.holder_id, c.holder_id) AND h.tenant_id = t.tenant_id
             LEFT JOIN users u              ON u.id = t.assigned_to   AND u.tenant_id = t.tenant_id
             WHERE t.tenant_id = ?{$sc->sql('COALESCE(t.holder_id, c.holder_id)')}
             ORDER BY (t.status = 'done'), (t.due_date IS NULL), t.due_date ASC
             LIMIT 500"
        );
        $stmt->execute(array_merge([$this->ctx->tenantId], $sc->params()));
        return $stmt->fetchAll();
    }

    /** source_key => newest task row, for gap-sync dedupe (auto tasks only). */
    public function autoMap(): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM renewal_tasks
             WHERE tenant_id = ? AND source_key IS NOT NULL
             ORDER BY created_at ASC"
        );
        $stmt->execute([$this->ctx->tenantId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) { $out[$r['source_key']] = $r; } // last write wins = newest
        return $out;
    }
}
