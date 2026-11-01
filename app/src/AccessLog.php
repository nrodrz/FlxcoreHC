<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Access logging for PHI reads/downloads/exports (audit finding H2).
 *
 * Separate from audit_logs (which records mutations). In production this writes
 * via an INSERT-only DB account so the trail can't be altered by the app tier.
 * Failures here must never break the user's request, but should be rare.
 */
final class AccessLog
{
    /** Recent access entries for the tenant (read side of the access log). */
    public static function recent(TenantContext $ctx, int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM access_logs WHERE tenant_id = ? ORDER BY created_at DESC LIMIT {$limit}"
        );
        $stmt->execute([$ctx->tenantId]);
        return $stmt->fetchAll();
    }

    public static function record(
        TenantContext $ctx,
        string $action,            // view | download | export | login | logout
        ?string $entityType = null,
        ?string $entityId = null
    ): void {
        try {
            Database::pdo()->prepare(
                "INSERT INTO access_logs (id, tenant_id, user_id, action, entity_type, entity_id, ip_address, user_agent, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            )->execute([
                uuid4(),
                $ctx->tenantId,
                $ctx->userId,
                $action,
                $entityType,
                $entityId,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                utc_now(),
            ]);
        } catch (\Throwable $e) {
            // swallow — access logging must not break the request
        }
    }
}
