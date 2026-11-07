<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC\Repository;

use FlxCoreHC\Database;

final class CredentialRepository extends TenantRepository
{
    /** Support mode (consent-gated platform access): PHI repositories refuse all reads. */
    /** Provider scope (need-to-know) is applied in SQL by the base repository (providerColumn = holder_id). */
    public function find(string $id): ?array
    {
        if (\FlxCoreHC\SupportAccess::active()) return null;
        return parent::find($id);
    }
    public function all(array $where = [], string $orderBy = 'created_at DESC', int $limit = 500): array
    {
        if (\FlxCoreHC\SupportAccess::active()) return [];
        return parent::all($where, $orderBy, $limit);
    }

    protected function table(): string { return 'credentials'; }
    protected function providerColumn(): ?string { return 'holder_id'; }   // need-to-know scope (v1.170.9)

    /** Soft-deleted credentials, for the recovery (trash) screen — includes holder name for context. */
    public function trashed(int $limit = 500): array
    {
        $st = Database::pdo()->prepare(
            "SELECT c.*, h.first_name, h.last_name, h.org_name, h.holder_type
             FROM credentials c
             JOIN credential_holders h ON h.id = c.holder_id AND h.tenant_id = c.tenant_id
             WHERE c.tenant_id = ? AND c.deleted_at IS NOT NULL{$this->scopeSql('c')[0]}
             ORDER BY c.deleted_at DESC LIMIT " . max(1, min($limit, 2000)));
        $st->execute(array_merge([$this->ctx->tenantId], $this->scopeSql('c')[1]));
        return $this->decryptRows($st->fetchAll());
    }

    protected function encryptedColumns(): array { return ['credential_number' => 'credential_number_enc']; }

    protected function fillable(): array
    {
        return ['holder_id','credential_type','name','issuing_body','address_id','credential_number',
                'issued_date','expiry_date','status','category','notes','last_verified_at',
                'plan_year','regulator_id','credential_number_enc'];
    }

    /** Credentials joined with holder names, optionally filtered by status. */
    public function withHolders(?string $status = null): array
    {
        $sql = "SELECT c.*, h.holder_type, h.first_name, h.last_name, h.org_name,
                       DATEDIFF(c.expiry_date, @flx_today) AS days_left
                FROM credentials c
                JOIN credential_holders h ON h.id = c.holder_id AND h.tenant_id = c.tenant_id
                WHERE c.tenant_id = ? AND c.deleted_at IS NULL AND h.deleted_at IS NULL";
        [$scope, $sp] = $this->scopeSql('c'); $sql .= $scope;
        $params = array_merge([$this->ctx->tenantId], $sp);
        if ($status !== null) {
            $sql .= " AND c.status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY (c.expiry_date IS NULL), c.expiry_date ASC LIMIT 1000";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $this->decryptRows($stmt->fetchAll());
    }

    /**
     * Filterable credential report (Feature: reporting layer). Every filter is optional and
     * combines with AND. Powers the on-screen report and its CSV export identically, so what
     * you see is exactly what downloads.
     *
     * $f keys: status ('active'|'expiring'|'expired'), type (catalog code / credential_type),
     *          holder_id, holder_type ('individual'|'organization'), provider_type,
     *          window_days (int — expiry on or before N days from today, incl. already expired),
     *          has_docs ('yes'|'no' — with/without at least one attached document).
     */
    public function report(array $f = []): array
    {
        $sql = "SELECT c.*, h.holder_type, h.provider_type, h.first_name, h.last_name, h.org_name,
                       DATEDIFF(c.expiry_date, @flx_today) AS days_left,
                       (SELECT COUNT(*) FROM credential_documents d
                         WHERE d.credential_id = c.id AND d.tenant_id = c.tenant_id
                           AND d.deleted_at IS NULL) AS doc_count
                FROM credentials c
                JOIN credential_holders h ON h.id = c.holder_id AND h.tenant_id = c.tenant_id
                WHERE c.tenant_id = ? AND c.deleted_at IS NULL AND h.deleted_at IS NULL";
        [$scope, $sp] = $this->scopeSql('c'); $sql .= $scope;
        $params = array_merge([$this->ctx->tenantId], $sp);
        if (!empty($f['status']) && in_array($f['status'], ['active','expiring','expired'], true)) {
            $sql .= " AND c.status = ?"; $params[] = $f['status'];
        }
        if (!empty($f['type'])) { $sql .= " AND c.credential_type = ?"; $params[] = $f['type']; }
        if (!empty($f['holder_id'])) { $sql .= " AND c.holder_id = ?"; $params[] = $f['holder_id']; }
        if (!empty($f['holder_type']) && in_array($f['holder_type'], ['individual','organization'], true)) {
            $sql .= " AND h.holder_type = ?"; $params[] = $f['holder_type'];
        }
        if (!empty($f['provider_type'])) { $sql .= " AND h.provider_type = ?"; $params[] = $f['provider_type']; }
        if (!empty($f['window_days']) && (int)$f['window_days'] > 0) {
            $sql .= " AND c.expiry_date IS NOT NULL AND c.expiry_date <= DATE_ADD(@flx_today, INTERVAL ? DAY)";
            $params[] = (int)$f['window_days'];
        }
        if (($f['has_docs'] ?? '') === 'yes') {
            $sql .= " AND EXISTS (SELECT 1 FROM credential_documents d WHERE d.credential_id = c.id AND d.tenant_id = c.tenant_id AND d.deleted_at IS NULL)";
        } elseif (($f['has_docs'] ?? '') === 'no') {
            $sql .= " AND NOT EXISTS (SELECT 1 FROM credential_documents d WHERE d.credential_id = c.id AND d.tenant_id = c.tenant_id AND d.deleted_at IS NULL)";
        }
        $sql .= " ORDER BY (c.expiry_date IS NULL), c.expiry_date ASC LIMIT 5000";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $this->decryptRows($stmt->fetchAll());
    }

    /** Everything expiring within $days (and not yet expired) — the compliance horizon. */
    public function expiringWithin(int $days): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT c.*, h.holder_type, h.first_name, h.last_name, h.org_name,
                    DATEDIFF(c.expiry_date, @flx_today) AS days_left
             FROM credentials c
             JOIN credential_holders h ON h.id = c.holder_id AND h.tenant_id = c.tenant_id
             WHERE c.tenant_id = ? AND c.deleted_at IS NULL AND h.deleted_at IS NULL
               AND c.expiry_date IS NOT NULL
               AND c.expiry_date >= @flx_today
               AND c.expiry_date <= DATE_ADD(@flx_today, INTERVAL ? DAY){$this->scopeSql('c')[0]}
             ORDER BY c.expiry_date ASC"
        );
        $stmt->execute(array_merge([$this->ctx->tenantId, $days], $this->scopeSql('c')[1]));
        return $this->decryptRows($stmt->fetchAll());
    }

    public function statusCounts(): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT status, COUNT(*) AS c FROM credentials WHERE tenant_id = ? AND deleted_at IS NULL{$this->scopeSql()[0]} GROUP BY status"
        );
        $stmt->execute(array_merge([$this->ctx->tenantId], $this->scopeSql()[1]));
        $out = ['active' => 0, 'expiring' => 0, 'expired' => 0, 'suspended' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['status']] = (int) $row['c'];
        }
        return $out;
    }

    /** Remove queued reminders for a credential (tenant-scoped). Used on delete. */
    public function clearReminders(string $credentialId): void
    {
        Database::pdo()->prepare(
            "DELETE FROM reminder_log WHERE subject_type = 'credential' AND subject_id = ? AND tenant_id = ?"
        )->execute([$credentialId, $this->ctx->tenantId]);
    }
}
