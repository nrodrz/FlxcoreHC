<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Provider ↔ location affiliations within a tenant. A provider can practice at several of the
 * tenant's locations; one may be marked primary. Everything is tenant-scoped and (like assignments)
 * safe to leave empty — a provider with no affiliations behaves exactly as before.
 *
 * This is the in-tenant half of the multi-org picture. Cross-tenant provider identity is deliberately
 * out of scope here and handled through the consent ledger, not through shared location rows.
 */
final class LocationService
{
    /** Affiliate a provider with a location. Idempotent; optionally set as primary. */
    public static function affiliate(TenantContext $ctx, string $holderId, string $locationId, ?string $roleAtSite, bool $primary = false): bool
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'INSERT INTO holder_locations (id, tenant_id, holder_id, location_id, role_at_site, is_primary, affiliated_at, affiliated_by)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE role_at_site = VALUES(role_at_site)'
        );
        $st->execute([uuid4(), $ctx->tenantId, $holderId, $locationId, $roleAtSite, $primary ? 1 : 0, utc_now(), $ctx->userId]);
        if ($primary) self::setPrimary($ctx, $holderId, $locationId);
        return true;
    }

    public static function unaffiliate(TenantContext $ctx, string $holderId, string $locationId): bool
    {
        $st = Database::pdo()->prepare(
            'DELETE FROM holder_locations WHERE tenant_id = ? AND holder_id = ? AND location_id = ?'
        );
        $st->execute([$ctx->tenantId, $holderId, $locationId]);
        return $st->rowCount() > 0;
    }

    /** Make one affiliation the provider's primary site (clears the others). */
    public static function setPrimary(TenantContext $ctx, string $holderId, string $locationId): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE holder_locations SET is_primary = 0 WHERE tenant_id = ? AND holder_id = ?')
            ->execute([$ctx->tenantId, $holderId]);
        $pdo->prepare('UPDATE holder_locations SET is_primary = 1 WHERE tenant_id = ? AND holder_id = ? AND location_id = ?')
            ->execute([$ctx->tenantId, $holderId, $locationId]);
    }

    /** Locations a provider practices at: [{location_id, name, city, state, role_at_site, is_primary}]. */
    public static function forHolder(TenantContext $ctx, string $holderId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT hl.location_id, hl.role_at_site, hl.is_primary, l.name, l.city, l.state, l.status
               FROM holder_locations hl
               JOIN locations l ON l.id = hl.location_id AND l.deleted_at IS NULL
              WHERE hl.tenant_id = ? AND hl.holder_id = ?
              ORDER BY hl.is_primary DESC, l.name ASC'
        );
        $st->execute([$ctx->tenantId, $holderId]);
        return $st->fetchAll();
    }

    /** Providers affiliated with a location (ids only; resolve names via HolderRepository). */
    public static function holderIdsAt(TenantContext $ctx, string $locationId): array
    {
        $sc = ProviderScope::for($ctx);   // v1.170.9: membership of unassigned providers is not disclosed (ids, counts)
        $st = Database::pdo()->prepare(
            'SELECT holder_id FROM holder_locations WHERE tenant_id = ? AND location_id = ?' . $sc->sql('holder_id', false)
        );
        $st->execute(array_merge([$ctx->tenantId, $locationId], $sc->params()));
        return array_column($st->fetchAll(), 'holder_id');
    }
}
