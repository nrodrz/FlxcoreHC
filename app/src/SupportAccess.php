<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Consent-gated platform support access ("impersonation" with authorization).
 *
 * The clearance chain: a platform admin REQUESTS access with a reason → the tenant's Master Admin
 * APPROVES (opening a 4-hour window bound to that one admin), DENIES, or later REVOKES → the admin
 * may enter only while the grant is active. More time requires a fresh approval. Every step is
 * written to BOTH logs — the tenant's audit trail (so the tenant sees exactly who was in and when)
 * and the platform access log — including the approved admin's identity, start, and expiration.
 *
 * Approved sessions run in SUPPORT MODE: settings and training only. PHI surfaces (providers,
 * credentials, documents, certifications, screening, reports) are blocked at the route layer AND
 * refused at the repository layer, so a missed route cannot leak.
 */
final class SupportAccess
{
    public const WINDOW_HOURS = 4;

    /** Is the current session a support-mode (impersonation) session? */
    public static function active(): bool
    {
        return !empty($_SESSION['auth']['support_mode']);
    }

    /** Route prefixes a support session may use. Everything else is 403. */
    public const ALLOWED = ['', 'organization', 'training', 'settings', 'account', 'logout', 'impersonate', 'billing'];

    /**
     * Hard stop for any action a platform support session must never perform, regardless of route:
     * bulk export, cross-organization data, and anything that would move tenant data off-platform.
     * Support sessions exist to fix configuration, not to read or move a client's records.
     */
    public static function denyIfSupport(string $what = 'This action'): void
    {
        if (self::active()) {
            http_response_code(403);
            exit($what . ' is not available during a platform support session. Support covers settings and training only; provider, credential, document and team data stay private to the tenant.');
        }
    }

    public static function routeAllowed(string $seg0): bool
    {
        return in_array($seg0, self::ALLOWED, true);
    }

    public static function request(TenantContext $adminCtx, string $tenantId, string $reason): string
    {
        $id = uuid4();
        Database::pdo()->prepare(
            'INSERT INTO impersonation_requests (id, tenant_id, requested_by, requester_name, requester_email, reason, status, requested_at)
             VALUES (?,?,?,?,?,?,\'pending\',?)'
        )->execute([$id, $tenantId, $adminCtx->userId, $adminCtx->userName, null, $reason, utc_now()]);
        return $id;
    }

    /** The pending request for a tenant (newest), or null. */
    public static function pendingFor(string $tenantId): ?array
    {
        $st = Database::pdo()->prepare(
            "SELECT * FROM impersonation_requests WHERE tenant_id = ? AND status = 'pending' ORDER BY requested_at DESC LIMIT 1"
        );
        $st->execute([$tenantId]);
        return $st->fetch() ?: null;
    }

    /** The active approved grant for (tenant, admin), or null. Expires lazily. */
    public static function activeGrant(string $tenantId, string $adminUserId): ?array
    {
        $pdo = Database::pdo();
        $pdo->prepare(
            "UPDATE impersonation_requests SET status='expired'
              WHERE status='approved' AND expires_at IS NOT NULL AND expires_at <= ?"
        )->execute([utc_now()]);
        $st = $pdo->prepare(
            "SELECT * FROM impersonation_requests
              WHERE tenant_id = ? AND requested_by = ? AND status = 'approved'
                AND revoked_at IS NULL AND expires_at > ?
              ORDER BY decided_at DESC LIMIT 1"
        );
        $st->execute([$tenantId, $adminUserId, utc_now()]);
        return $st->fetch() ?: null;
    }

    /** Approve/deny (Master Admin action). Returns the updated row or null. */
    public static function decide(TenantContext $ctx, string $requestId, bool $approve): ?array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT * FROM impersonation_requests WHERE id = ? AND tenant_id = ? AND status='pending' LIMIT 1");
        $st->execute([$requestId, $ctx->tenantId]);
        $row = $st->fetch();
        if (!$row) return null;
        $now = utc_now();
        $exp = $approve ? date('Y-m-d H:i:s', strtotime($now . ' +' . self::WINDOW_HOURS . ' hours')) : null;
        $pdo->prepare('UPDATE impersonation_requests SET status=?, decided_by=?, decided_at=?, expires_at=? WHERE id=?')
            ->execute([$approve ? 'approved' : 'denied', $ctx->userId, $now, $exp, $requestId]);
        $row['status'] = $approve ? 'approved' : 'denied';
        $row['decided_at'] = $now; $row['expires_at'] = $exp; $row['decided_by'] = $ctx->userId;
        return $row;
    }

    /** Revoke an active grant (Master Admin action). Ends any live session at next request. */
    public static function revoke(TenantContext $ctx, string $requestId): bool
    {
        $st = Database::pdo()->prepare(
            "UPDATE impersonation_requests SET status='revoked', revoked_at=? WHERE id=? AND tenant_id=? AND status='approved'"
        );
        $st->execute([utc_now(), $requestId, $ctx->tenantId]);
        return $st->rowCount() > 0;
    }

    /** Grants (any status) for the tenant, newest first — the tenant-visible history. */
    public static function historyFor(string $tenantId, int $limit = 20): array
    {
        $st = Database::pdo()->prepare(
            'SELECT * FROM impersonation_requests WHERE tenant_id = ? ORDER BY requested_at DESC LIMIT ' . max(1, min($limit, 100))
        );
        $st->execute([$tenantId]);
        return $st->fetchAll();
    }

    public static function markEntered(string $requestId): void
    {
        Database::pdo()->prepare('UPDATE impersonation_requests SET entered_at = COALESCE(entered_at, ?) WHERE id = ?')
            ->execute([utc_now(), $requestId]);
    }

    public static function markEnded(string $requestId): void
    {
        Database::pdo()->prepare('UPDATE impersonation_requests SET ended_at = ? WHERE id = ?')
            ->execute([utc_now(), $requestId]);
    }
}
