<?php
declare(strict_types=1);

namespace FlxCoreHC\Repository;

/**
 * Practice locations within a tenant. A billing company, group, lab, or clinic manages providers
 * across several physical sites; this is the tenant's list of those sites. Tenant-scoped like every
 * repository, so one organization's locations are never visible to another.
 */
final class LocationRepository extends TenantRepository
{
    protected function table(): string { return 'locations'; }

    protected function fillable(): array
    {
        return ['name', 'npi', 'address1', 'address2', 'city', 'state', 'zip', 'phone', 'status', 'note'];
    }

    /** Active locations, name-ordered. */
    public function active(): array
    {
        return $this->all(['status' => 'active'], 'name ASC');
    }

    /** Every non-deleted location with a live count of affiliated providers. */
    public function withProviderCounts(): array
    {
        $sc = \FlxCoreHC\ProviderScope::for($this->ctx);   // v1.171.0 (B-4)
        $stmt = \FlxCoreHC\Database::pdo()->prepare(
            'SELECT l.*, (
                 SELECT COUNT(*) FROM holder_locations hl
                 JOIN credential_holders h ON h.id = hl.holder_id AND h.deleted_at IS NULL
                 WHERE hl.location_id = l.id AND hl.tenant_id = l.tenant_id' . $sc->sql('hl.holder_id', false) . '
             ) AS provider_count
             FROM locations l
             WHERE l.tenant_id = ? AND l.deleted_at IS NULL
             ORDER BY l.status ASC, l.name ASC'
        );
        $stmt->execute(array_merge($sc->params(), [$this->ctx->tenantId]));
        return $stmt->fetchAll();
    }
}
