<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Provider ↔ group membership within a tenant, and the org-shape-aware label for the concept.
 *
 * Many-to-many: a physician can belong to several groups (covering multiple client groups or
 * departments), a nurse can work for several. One membership may be marked primary. Everything is
 * tenant-scoped and safe to leave empty — a provider with no groups behaves exactly as before.
 *
 * This is entirely within one tenant. It is NOT cross-tenant multi-org: a billing company serving
 * several medical groups is still one tenant with internal groups, sharing no data with anyone.
 */
final class GroupService
{
    /** How the "group" concept is labelled for a given organization type. */
    public static function label(string $orgShape, bool $plural = false): string
    {
        [$one, $many] = match ($orgShape) {
            'billing_company', 'platform' => ['Client group', 'Client groups'],
            'hospital'        => ['Department', 'Departments'],
            default           => ['Group', 'Groups'],
        };
        return $plural ? $many : $one;
    }

    /** Add a provider to a group. Idempotent; optionally set as primary. */
    public static function add(TenantContext $ctx, string $holderId, string $groupId, ?string $roleInGroup, bool $primary = false): bool
    {
        Database::pdo()->prepare(
            'INSERT INTO holder_groups (id, tenant_id, holder_id, group_id, role_in_group, is_primary, joined_at, added_by)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE role_in_group = VALUES(role_in_group)'
        )->execute([uuid4(), $ctx->tenantId, $holderId, $groupId, $roleInGroup, $primary ? 1 : 0, utc_now(), $ctx->userId]);
        if ($primary) self::setPrimary($ctx, $holderId, $groupId);
        return true;
    }

    public static function remove(TenantContext $ctx, string $holderId, string $groupId): bool
    {
        $st = Database::pdo()->prepare(
            'DELETE FROM holder_groups WHERE tenant_id = ? AND holder_id = ? AND group_id = ?'
        );
        $st->execute([$ctx->tenantId, $holderId, $groupId]);
        return $st->rowCount() > 0;
    }

    public static function setPrimary(TenantContext $ctx, string $holderId, string $groupId): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE holder_groups SET is_primary = 0 WHERE tenant_id = ? AND holder_id = ?')
            ->execute([$ctx->tenantId, $holderId]);
        $pdo->prepare('UPDATE holder_groups SET is_primary = 1 WHERE tenant_id = ? AND holder_id = ? AND group_id = ?')
            ->execute([$ctx->tenantId, $holderId, $groupId]);
    }

    /** Groups a provider belongs to. */
    public static function forHolder(TenantContext $ctx, string $holderId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT hg.group_id, hg.role_in_group, hg.is_primary, g.name, g.status
               FROM holder_groups hg
               JOIN provider_groups g ON g.id = hg.group_id AND g.deleted_at IS NULL
              WHERE hg.tenant_id = ? AND hg.holder_id = ?
              ORDER BY hg.is_primary DESC, g.name ASC'
        );
        $st->execute([$ctx->tenantId, $holderId]);
        return $st->fetchAll();
    }

    /** Provider ids in a group (resolve names via HolderRepository). */
    public static function holderIdsIn(TenantContext $ctx, string $groupId): array
    {
        $sc = ProviderScope::for($ctx);   // v1.170.9: membership of unassigned providers is not disclosed (ids, counts)
        $st = Database::pdo()->prepare(
            'SELECT holder_id FROM holder_groups WHERE tenant_id = ? AND group_id = ?' . $sc->sql('holder_id', false)
        );
        $st->execute(array_merge([$ctx->tenantId, $groupId], $sc->params()));
        return array_column($st->fetchAll(), 'holder_id');
    }
}
