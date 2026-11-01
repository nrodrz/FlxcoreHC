<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * The authorization service. Every "may this person do that?" question is answered here,
 * from one reviewable matrix, instead of role checks scattered through routes and views.
 *
 * Two independent axes:
 *   * Platform axis  — users.platform_admin. Grants the SaaS console (tenant management,
 *     approvals, platform data). Verified against the DATABASE on each request via
 *     verifyPlatformAdmin(), so a revoked flag loses power immediately rather than
 *     surviving in a stale session.
 *   * Tenant axis    — users.role, scoped to one organization. Roles: tenant_owner,
 *     manager, staff, agent (legacy, treated as staff), producer, read_only.
 *
 * Effective role: if the person's TENANT is not active (e.g. pending approval), every
 * user of that tenant is treated as read_only regardless of their stored role. That one
 * override implements the whole "browse read-only until approved" behaviour — no
 * special-casing in routes.
 */
final class Authz
{
    public const READ_ONLY = 'read_only';

    /** Per-request cache of resolved capability sets, keyed by "tenantId|slug". */
    private static array $capCache = [];

    /**
     * The role this person effectively has right now. A non-active tenant demotes
     * everyone in it to read_only. Tenant status defaults to the per-request value
     * stashed by the front controller.
     */
    public static function effectiveRole(TenantContext $ctx, ?string $tenantStatus = null): string
    {
        $status = $tenantStatus ?? (string) ($GLOBALS['flx_tenant_status'] ?? 'active');
        if ($status !== 'active') return self::READ_ONLY;
        return $ctx->role;
    }

    /** Capabilities held by the effective role, read from the tenant's role definitions. */
    private static function capsFor(TenantContext $ctx, string $role): array
    {
        $key = $ctx->tenantId . '|' . $role;
        if (!isset(self::$capCache[$key])) {
            self::$capCache[$key] = RoleService::capabilitiesForSlug($ctx->tenantId, $role);
        }
        return self::$capCache[$key];
    }

    public static function can(TenantContext $ctx, string $capability, ?string $tenantStatus = null): bool
    {
        $role = self::effectiveRole($ctx, $tenantStatus);
        if ($role === self::READ_ONLY) return false;
        return in_array($capability, self::capsFor($ctx, $role), true);
    }

    /** 403 unless the capability is held. For use at the top of mutating routes. */
    public static function requireCap(TenantContext $ctx, string $capability): void
    {
        if (!self::can($ctx, $capability)) {
            http_response_code(403);
            exit('Forbidden — your role does not permit this action.');
        }
    }

    /** May this effective role mutate anything at all? (read_only may not.) */
    /** Capabilities that make a user "privileged" for the organization's 2FA policy (S-7). */
    public const PRIVILEGED_CAPS = ['team.manage', 'org.manage', 'pii.view', 'data.export'];

    public static function needsTwoFactor(TenantContext $ctx): bool
    {
        foreach (self::PRIVILEGED_CAPS as $c) {
            if (self::can($ctx, $c)) return true;
        }
        return false;
    }

    public static function canMutate(TenantContext $ctx, ?string $tenantStatus = null): bool
    {
        return self::effectiveRole($ctx, $tenantStatus) !== self::READ_ONLY;
    }

    /**
     * Authoritative platform-admin check: the session flag AND the current database row.
     * One cheap indexed query, only run when the session claims the flag — so a flag
     * revoked in the database strips the session's power on its very next request.
     */
    public static function verifyPlatformAdmin(TenantContext $ctx): bool
    {
        if (!$ctx->isPlatformAdmin) return false;
        $stmt = Database::pdo()->prepare(
            'SELECT platform_admin FROM users WHERE id = ? AND tenant_id = ? AND status = \'active\' LIMIT 1'
        );
        $stmt->execute([$ctx->userId, $ctx->tenantId]);
        return (bool) $stmt->fetchColumn();
    }
}
