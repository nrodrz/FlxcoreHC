<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Append-only compliance history. Every meaningful moment in a provider's compliance life is recorded
 * here permanently — credential added / renewed / edited / removed / expired, a requirement becoming
 * met or lapsing, and documents uploaded / verified / replaced / removed. Rows are never updated or
 * deleted, so a client's full multi-year record survives even if users, credentials or documents are
 * later removed (the actor name and summary are denormalized for that reason).
 *
 * This complements the audit_logs table: audit_logs is the security trail of who did what to the
 * system; compliance_events is the business record of the provider's credentialing timeline that you
 * can show a client or an auditor, filterable by year and month.
 */
final class ComplianceEventService
{
    /** Record one event. Best-effort: history must never block the operation that triggered it. */
    public static function record(TenantContext $ctx, string $eventType, string $summary, array $opts = []): void
    {
        try {
            Database::pdo()->prepare(
                'INSERT INTO compliance_events
                   (id, tenant_id, case_id, holder_id, credential_id, document_id, event_type, summary, detail, effective_on, actor_id, actor_name, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                uuid4(), $ctx->tenantId, $opts['case_id'] ?? null,
                $opts['holder_id'] ?? null, $opts['credential_id'] ?? null, $opts['document_id'] ?? null,
                $eventType, mb_substr($summary, 0, 300),
                isset($opts['detail']) ? json_encode($opts['detail'], JSON_UNESCAPED_UNICODE) : null,
                $opts['effective_on'] ?? null,
                $ctx->userId ?: null, $opts['actor_name'] ?? ($ctx->userName ?? null),
                utc_now(),
            ]);
        } catch (\Throwable $e) { /* never break the caller over history */ }
    }

    /** Timeline for one credential, newest first. */
    public static function forCredential(TenantContext $ctx, string $credentialId, int $limit = 500): array
    {
        if (SupportAccess::active()) return [];
        $sc = ProviderScope::for($ctx);   // need-to-know (v1.170.9)
        $st = Database::pdo()->prepare(
            'SELECT * FROM compliance_events WHERE tenant_id = ? AND credential_id = ?' . $sc->sql('holder_id') . '
             ORDER BY created_at DESC LIMIT ' . max(1, min($limit, 5000)));
        $st->execute(array_merge([$ctx->tenantId, $credentialId], $sc->params()));
        return $st->fetchAll();
    }

    /** Timeline for one credentialing case, newest first. (v1.170.9: the case page called this method but it did not exist → fatal error.) */
    public static function forCase(TenantContext $ctx, string $caseId, int $limit = 500): array
    {
        if (SupportAccess::active()) return [];
        $sc = ProviderScope::for($ctx);   // need-to-know
        $st = Database::pdo()->prepare(
            'SELECT * FROM compliance_events WHERE tenant_id = ? AND case_id = ?' . $sc->sql('holder_id', false) . '
             ORDER BY created_at DESC LIMIT ' . max(1, min($limit, 5000)));
        $st->execute(array_merge([$ctx->tenantId, $caseId], $sc->params()));
        return $st->fetchAll();
    }

    /** Full timeline for one provider, newest first. Not capped — this is the permanent record. */
    public static function forHolder(TenantContext $ctx, string $holderId, int $limit = 5000): array
    {
        if (SupportAccess::active()) return [];
        $sc = ProviderScope::for($ctx);   // need-to-know (v1.170.9)
        $st = Database::pdo()->prepare(
            'SELECT * FROM compliance_events WHERE tenant_id = ? AND holder_id = ?' . $sc->sql('holder_id', false) . '
             ORDER BY created_at DESC LIMIT ' . max(1, min($limit, 20000)));
        $st->execute(array_merge([$ctx->tenantId, $holderId], $sc->params()));
        return $st->fetchAll();
    }

    /** Tenant-wide events within a date range (for a yearly/monthly compliance report). */
    public static function inRange(TenantContext $ctx, string $fromDate, string $toDate, ?string $eventType = null): array
    {
        if (SupportAccess::active()) return [];
        $sc = ProviderScope::for($ctx);   // need-to-know (v1.170.9): tenant-wide history export is scoped too
        $sql = 'SELECT * FROM compliance_events WHERE tenant_id = ? AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)' . $sc->sql('holder_id');
        $p = array_merge([$ctx->tenantId, $fromDate, $toDate], $sc->params());
        if ($eventType) { $sql .= ' AND event_type = ?'; $p[] = $eventType; }
        $sql .= ' ORDER BY created_at DESC LIMIT 20000';
        $st = Database::pdo()->prepare($sql); $st->execute($p);
        return $st->fetchAll();
    }

    /** Convenience: build a diff array of only the fields that actually changed (old vs new). */
    public static function diff(array $before, array $after, array $fields): array
    {
        $d = [];
        foreach ($fields as $f) {
            $b = $before[$f] ?? null; $a = $after[$f] ?? null;
            if ((string)$b !== (string)$a) $d[$f] = ['from' => $b, 'to' => $a];
        }
        return $d;
    }
}
