<?php
declare(strict_types=1);

namespace FlxCoreHC\Repository;

use FlxCoreHC\Database;

final class AuditLogRepository extends TenantRepository
{
    /**
     * v1.171.0 (B-4) — the tenant-wide audit trail names providers, credentials and people across the whole
     * organization, so a user limited to assigned providers (need-to-know) gets NONE of the tenant-wide views.
     * They still see the per-provider history of providers they are assigned to (forHolder / forCredential /
     * forDocument), because those are reached only through already-scoped lookups. Fail-closed: empty, never partial.
     */
    private function tenantWideBlocked(): bool
    {
        return \FlxCoreHC\ProviderScope::for($this->ctx)->restricted();
    }

    // Platform support sessions never see tenant records — see SupportAccess.
    public function find(string $id): ?array
    {
        if (\FlxCoreHC\SupportAccess::active() || $this->tenantWideBlocked()) return null;
        return parent::find($id);
    }

    public function all(array $where = [], string $orderBy = 'created_at DESC', int $limit = 500): array
    {
        if (\FlxCoreHC\SupportAccess::active() || $this->tenantWideBlocked()) return [];
        return parent::all($where, $orderBy, $limit);
    }

    protected function table(): string { return 'audit_logs'; }

    protected function fillable(): array { return []; } // written internally only

    /**
     * Filtered master audit query for the tenant-wide log: by date range, actor, action, entity type,
     * and free-text on the record id / changes. Paginated. This is the "what happened, when, by whom"
     * view an owner or manager drills into.
     * @param array{from?:string,to?:string,user_id?:string,action?:string,entity_type?:string,q?:string} $f
     */
    public function filtered(array $f, int $limit = 100, int $offset = 0): array
    {
        if ($this->tenantWideBlocked()) return [];
        [$where, $p] = $this->buildWhere($f);
        $limit = max(1, min($limit, 500)); $offset = max(0, $offset);
        $stmt = Database::pdo()->prepare(
            "SELECT a.*, u.full_name AS user_name
             FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
             WHERE {$where} ORDER BY a.created_at DESC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($p);
        return $stmt->fetchAll();
    }

    public function countFiltered(array $f): int
    {
        if ($this->tenantWideBlocked()) return 0;
        [$where, $p] = $this->buildWhere($f);
        $stmt = Database::pdo()->prepare("SELECT COUNT(*) FROM audit_logs a WHERE {$where}");
        $stmt->execute($p);
        return (int)$stmt->fetchColumn();
    }

    private function buildWhere(array $f): array
    {
        $where = 'a.tenant_id = ?'; $p = [$this->ctx->tenantId];
        if (!empty($f['from']))        { $where .= ' AND a.created_at >= ?'; $p[] = $f['from'] . ' 00:00:00'; }
        if (!empty($f['to']))          { $where .= ' AND a.created_at <= ?'; $p[] = $f['to'] . ' 23:59:59'; }
        if (!empty($f['user_id']))     { $where .= ' AND a.user_id = ?'; $p[] = $f['user_id']; }
        if (!empty($f['action']))      { $where .= ' AND a.action = ?'; $p[] = $f['action']; }
        if (!empty($f['entity_type'])) { $where .= ' AND a.entity_type = ?'; $p[] = $f['entity_type']; }
        if (!empty($f['entity_id']))   { $where .= ' AND a.entity_id = ?'; $p[] = $f['entity_id']; }
        if (!empty($f['q']))           { $where .= ' AND (a.entity_id LIKE ? OR a.changes LIKE ? OR a.action LIKE ?)'; $like = '%'.$f['q'].'%'; $p[] = $like; $p[] = $like; $p[] = $like; }
        return [$where, $p];
    }

    /** Distinct actions seen in this tenant, for the filter dropdown. */
    public function distinctActions(): array
    {
        if ($this->tenantWideBlocked()) return [];
        $st = Database::pdo()->prepare('SELECT DISTINCT action FROM audit_logs WHERE tenant_id = ? ORDER BY action');
        $st->execute([$this->ctx->tenantId]);
        return array_column($st->fetchAll(), 'action');
    }

    /** History for a single credential (audit rows + its documents), newest first. */
    public function forCredential(string $credentialId): array
    {
        $st = Database::pdo()->prepare(
            "SELECT a.*, u.full_name AS user_name FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
             WHERE a.tenant_id = ? AND (
                (a.entity_type = 'credentials' AND a.entity_id = ?)
             OR (a.entity_type = 'credential_documents' AND a.entity_id IN (SELECT id FROM credential_documents WHERE tenant_id = ? AND credential_id = ?)))
             ORDER BY a.created_at DESC LIMIT 500");
        $st->execute([$this->ctx->tenantId, $credentialId, $this->ctx->tenantId, $credentialId]);
        return $st->fetchAll();
    }

    /** History for a single document, newest first. */
    public function forDocument(string $documentId): array
    {
        $st = Database::pdo()->prepare(
            "SELECT a.*, u.full_name AS user_name FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
             WHERE a.tenant_id = ? AND a.entity_type = 'credential_documents' AND a.entity_id = ?
             ORDER BY a.created_at DESC LIMIT 200");
        $st->execute([$this->ctx->tenantId, $documentId]);
        return $st->fetchAll();
    }

    /**
     * Everything that happened to one provider's file: actions on the provider row, on any of their
     * credentials, and on documents attached to those credentials — newest first, with who did it.
     */
    public function forHolder(string $holderId, int $limit = 300): array
    {
        $limit = max(1, min($limit, 1000));
        $stmt = Database::pdo()->prepare(
            "SELECT a.*, u.full_name AS user_name
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
             WHERE a.tenant_id = ?
               AND (
                    (a.entity_type = 'holders' AND a.entity_id = ?)
                 OR (a.entity_type = 'credentials' AND a.entity_id IN (SELECT id FROM credentials WHERE tenant_id = ? AND holder_id = ?))
                 OR (a.entity_type = 'credential_documents' AND a.entity_id IN (
                        SELECT d.id FROM credential_documents d JOIN credentials c ON c.id = d.credential_id
                        WHERE d.tenant_id = ? AND c.holder_id = ?))
               )
             ORDER BY a.created_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$this->ctx->tenantId, $holderId, $this->ctx->tenantId, $holderId, $this->ctx->tenantId, $holderId]);
        return $stmt->fetchAll();
    }

    // ---- Activity pulse (tenant-wide dashboard) --------------------------------

    /** Totals for a window: total actions, distinct actors, distinct records touched. */
    public function activityTotals(string $fromDate, string $toDate): array
    {
        if ($this->tenantWideBlocked()) return ['total' => 0, 'actors' => 0, 'records' => 0];
        $st = Database::pdo()->prepare(
            "SELECT COUNT(*) AS total, COUNT(DISTINCT user_id) AS actors, COUNT(DISTINCT entity_id) AS records
             FROM audit_logs WHERE tenant_id = ? AND created_at >= ? AND created_at <= ?");
        $st->execute([$this->ctx->tenantId, $fromDate . ' 00:00:00', $toDate . ' 23:59:59']);
        return $st->fetch() ?: ['total' => 0, 'actors' => 0, 'records' => 0];
    }

    /** Count of specific actions in a window (e.g. credentials added, documents verified). */
    public function actionCounts(string $fromDate, string $toDate): array
    {
        if ($this->tenantWideBlocked()) return [];
        $st = Database::pdo()->prepare(
            "SELECT action, COUNT(*) n FROM audit_logs
             WHERE tenant_id = ? AND created_at >= ? AND created_at <= ?
             GROUP BY action ORDER BY n DESC");
        $st->execute([$this->ctx->tenantId, $fromDate . ' 00:00:00', $toDate . ' 23:59:59']);
        $out = [];
        foreach ($st->fetchAll() as $r) { $out[(string)$r['action']] = (int)$r['n']; }
        return $out;
    }

    /** Per-person activity counts in a window, busiest first. */
    public function activityByPerson(string $fromDate, string $toDate, int $limit = 25): array
    {
        if ($this->tenantWideBlocked()) return [];
        $st = Database::pdo()->prepare(
            "SELECT COALESCE(u.full_name, 'System') AS name, a.user_id, COUNT(*) n,
                    MAX(a.created_at) AS last_at
             FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
             WHERE a.tenant_id = ? AND a.created_at >= ? AND a.created_at <= ?
             GROUP BY a.user_id, name ORDER BY n DESC LIMIT " . max(1, min($limit, 100)));
        $st->execute([$this->ctx->tenantId, $fromDate . ' 00:00:00', $toDate . ' 23:59:59']);
        return $st->fetchAll();
    }

    /** Daily action counts across a range, for the sparkline/trend. Returns ['YYYY-MM-DD'=>n]. */
    public function activityByDay(string $fromDate, string $toDate): array
    {
        if ($this->tenantWideBlocked()) return [];
        $st = Database::pdo()->prepare(
            "SELECT DATE(created_at) d, COUNT(*) n FROM audit_logs
             WHERE tenant_id = ? AND created_at >= ? AND created_at <= ?
             GROUP BY DATE(created_at) ORDER BY d");
        $st->execute([$this->ctx->tenantId, $fromDate . ' 00:00:00', $toDate . ' 23:59:59']);
        $out = [];
        foreach ($st->fetchAll() as $r) { $out[(string)$r['d']] = (int)$r['n']; }
        return $out;
    }

    public function recent(int $limit = 200): array
    {
        if ($this->tenantWideBlocked()) return [];
        $limit = max(1, min($limit, 500));
        $stmt = Database::pdo()->prepare(
            "SELECT a.*, u.full_name AS user_name
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
             WHERE a.tenant_id = ?
             ORDER BY a.created_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$this->ctx->tenantId]);
        return $stmt->fetchAll();
    }
}
