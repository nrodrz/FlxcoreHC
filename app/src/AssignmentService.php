<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Need-to-know access within a tenant: providers are explicitly assigned to team members, and a user
 * whose provider_access is 'assigned' can only see/resolve their assigned providers.
 *
 * Enforcement lives in HolderRepository (which consults this service), so it applies to every surface
 * that resolves providers — list, detail, compliance engine — and cannot be bypassed by a route.
 * Owners and platform admins always have full visibility; system/sweep contexts (cron, reminders)
 * run as owner-level and are unaffected.
 *
 * Every assignment change and access-mode change is audit-logged by the calling route.
 */
final class AssignmentService
{
    /** @var array<string,array{mode:string,ids:array<string,bool>}> per-request cache keyed by tenant|user */
    private static array $cache = [];

    /** Non-user actors whose authorization is established elsewhere (share token, cron): never restricted. */
    public const SYSTEM_ROLES = ['system:share-link'];

    /** The provider-access mode for a user: 'all' or 'assigned'. Owners are always 'all'. */
    public static function modeFor(TenantContext $ctx): string
    {
        return self::resolve($ctx)['mode'];
    }

    /** True when this context may see the given holder id. */
    public static function canSee(TenantContext $ctx, string $holderId): bool
    {
        $r = self::resolve($ctx);
        return $r['mode'] === 'all' || isset($r['ids'][$holderId]);
    }

    /** Assigned holder ids for a restricted user (empty array means: sees nothing). */
    public static function visibleIds(TenantContext $ctx): array
    {
        return array_keys(self::resolve($ctx)['ids']);
    }

    /** @return array{mode:string,ids:array<string,bool>} */
    private static function resolve(TenantContext $ctx): array
    {
        $key = $ctx->tenantId . '|' . (string) $ctx->userId;
        if (isset(self::$cache[$key])) return self::$cache[$key];

        // Owners, platform admins, and system contexts (no user id, or an explicit system actor such as
        // the token-authorised public share viewer / cron) always see everything.
        if ($ctx->role === 'tenant_owner' || $ctx->isPlatformAdmin || (string) $ctx->userId === ''
            || in_array($ctx->role, self::SYSTEM_ROLES, true)) {
            return self::$cache[$key] = ['mode' => 'all', 'ids' => []];
        }
        // v1.170.9 — FAIL CLOSED: an unknown user row or a failed lookup never widens access to 'all'.
        try {
            $pdo = Database::pdo();
            $st = $pdo->prepare('SELECT provider_access FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
            $st->execute([$ctx->userId, $ctx->tenantId]);
            $raw = $st->fetchColumn();
            if ($raw === false) {                       // no such user in this tenant → sees nothing
                return self::$cache[$key] = ['mode' => 'assigned', 'ids' => []];
            }
            if ((string) $raw !== 'assigned') {
                return self::$cache[$key] = ['mode' => 'all', 'ids' => []];
            }
            $st = $pdo->prepare('SELECT holder_id FROM holder_assignments WHERE tenant_id = ? AND user_id = ?');
            $st->execute([$ctx->tenantId, $ctx->userId]);
            $ids = [];
            foreach ($st->fetchAll() as $r) $ids[(string) $r['holder_id']] = true;
            return self::$cache[$key] = ['mode' => 'assigned', 'ids' => $ids];
        } catch (\Throwable $e) {
            flx_log_error('error', 'need-to-know resolution failed — failing closed');
            return self::$cache[$key] = ['mode' => 'assigned', 'ids' => []];
        }
    }

    // ---- assignment management (called from Team / provider routes) ---------

    /** Assign a provider to a team member. Idempotent. */
    public static function assign(TenantContext $ctx, string $holderId, string $userId, ?string $note = null): bool
    {
        $st = Database::pdo()->prepare(
            'INSERT IGNORE INTO holder_assignments (id, tenant_id, holder_id, user_id, assigned_by, assigned_at, note)
             VALUES (?,?,?,?,?,?,?)'
        );
        $st->execute([uuid4(), $ctx->tenantId, $holderId, $userId, $ctx->userId, utc_now(), $note]);
        self::$cache = []; // invalidate
        $ok = $st->rowCount() > 0;
        if ($ok) {
            // Tell the person they now have a provider to look after.
            try {
                $h = (new \FlxCoreHC\Repository\HolderRepository($ctx))->find($holderId);
                $name = $h ? \FlxCoreHC\Repository\HolderRepository::displayName($h) : 'A provider';
                NotificationService::push($ctx->tenantId, $userId, 'assigned', 'info', "{$name} was assigned to you",
                    $note ? "Note: {$note}" : 'You can now see and manage this provider\'s credentials.',
                    (defined('BASE_PATH') ? BASE_PATH : '') . '/holders/' . $holderId, 'holders', $holderId, 'assign:' . $holderId . ':' . $userId);
            } catch (\Throwable $e) { /* never block an assignment over a notification */ }
        }
        return $ok;
    }

    /** Remove an assignment. */
    public static function unassign(TenantContext $ctx, string $holderId, string $userId): bool
    {
        $st = Database::pdo()->prepare(
            'DELETE FROM holder_assignments WHERE tenant_id = ? AND holder_id = ? AND user_id = ?'
        );
        $st->execute([$ctx->tenantId, $holderId, $userId]);
        self::$cache = [];
        return $st->rowCount() > 0;
    }

    /** Team members assigned to a provider: [{user_id, full_name, assigned_at, note}] */
    public static function assigneesOf(TenantContext $ctx, string $holderId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT a.user_id, a.assigned_at, a.note, u.full_name, u.email
               FROM holder_assignments a JOIN users u ON u.id = a.user_id
              WHERE a.tenant_id = ? AND a.holder_id = ?
              ORDER BY u.full_name'
        );
        $st->execute([$ctx->tenantId, $holderId]);
        return $st->fetchAll();
    }

    /** Holder ids assigned to a user (for the Team page summary). */
    public static function assignmentsOf(TenantContext $ctx, string $userId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT holder_id FROM holder_assignments WHERE tenant_id = ? AND user_id = ?'
        );
        $st->execute([$ctx->tenantId, $userId]);
        return array_column($st->fetchAll(), 'holder_id');
    }

    /** Set a user's provider access mode ('all' | 'assigned'). */
    public static function setMode(TenantContext $ctx, string $userId, string $mode): bool
    {
        if (!in_array($mode, ['all', 'assigned'], true)) return false;
        $st = Database::pdo()->prepare('UPDATE users SET provider_access = ? WHERE id = ? AND tenant_id = ?');
        $st->execute([$mode, $userId, $ctx->tenantId]);
        self::$cache = [];
        return true;
    }
}
