<?php
declare(strict_types=1);

namespace FlxCoreHC\Repository;

/**
 * Provider groups within a tenant — the intermediate grouping layer. Depending on the organization
 * type this reads as a billing company's client groups (the medical groups it serves), a hospital's
 * departments, or a large practice's divisions. Tenant-scoped like every repository.
 */
final class GroupRepository extends TenantRepository
{
    protected function table(): string { return 'provider_groups'; }

    protected function fillable(): array
    {
        return ['name', 'npi', 'code', 'status', 'note'];
    }

    public function active(): array
    {
        return $this->all(['status' => 'active'], 'name ASC');
    }

    /** Every non-deleted group with a live count of member providers. */
    public function withMemberCounts(): array
    {
        $sc = \FlxCoreHC\ProviderScope::for($this->ctx);   // v1.171.0 (B-4): a need-to-know user's counts include only their providers
        $stmt = \FlxCoreHC\Database::pdo()->prepare(
            'SELECT g.*, (
                 SELECT COUNT(*) FROM holder_groups hg
                 JOIN credential_holders h ON h.id = hg.holder_id AND h.deleted_at IS NULL
                 WHERE hg.group_id = g.id AND hg.tenant_id = g.tenant_id' . $sc->sql('hg.holder_id', false) . '
             ) AS member_count
             FROM provider_groups g
             WHERE g.tenant_id = ? AND g.deleted_at IS NULL
             ORDER BY g.status ASC, g.name ASC'
        );
        $stmt->execute(array_merge($sc->params(), [$this->ctx->tenantId]));
        return $stmt->fetchAll();
    }
}
