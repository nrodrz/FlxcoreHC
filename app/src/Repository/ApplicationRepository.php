<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC\Repository;

use FlxCoreHC\Database;

/** Provider enrollment / credentialing applications and their status lifecycle. */
final class ApplicationRepository extends TenantRepository
{
    // Platform support sessions never see tenant records — see SupportAccess.
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

    public const KINDS = [
        'enrollment'      => 'Enrollment',
        'credentialing'   => 'Credentialing',
        'recredentialing' => 'Recredentialing',
        'revalidation'    => 'Revalidation',
        'other'           => 'Other',
    ];
    public const STATUSES = [
        'not_started'  => 'Not started',
        'in_progress'  => 'In progress',
        'submitted'    => 'Submitted',
        'under_review' => 'Under review',
        'approved'     => 'Approved',
        'denied'       => 'Denied',
        'withdrawn'    => 'Withdrawn',
    ];
    /** Statuses that count as "open" (still needing work / awaiting a decision). */
    public const OPEN_STATUSES = ['not_started', 'in_progress', 'submitted', 'under_review'];

    protected function table(): string { return 'applications'; }
    protected function providerColumn(): ?string { return 'holder_id'; }   // need-to-know scope (v1.170.9)
    protected function fillable(): array
    {
        return ['holder_id', 'regulator_id', 'kind', 'title', 'reference',
                'status', 'submitted_on', 'decision_on', 'due_on', 'notes'];
    }

    /** Applications for one provider, newest first. */
    public function forHolder(string $holderId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM applications WHERE tenant_id = ? AND holder_id = ?{$this->scopeSql()[0]} ORDER BY created_at DESC"
        );
        $stmt->execute(array_merge([$this->ctx->tenantId, $holderId], $this->scopeSql()[1]));
        return $stmt->fetchAll();
    }

    /** Portfolio list with provider + regulator names joined, for the workspace view. */
    public function allWithNames(): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT a.*, r.name AS regulator_name,
                    h.first_name, h.last_name, h.org_name, h.holder_type
               FROM applications a
               LEFT JOIN regulators r ON r.id = a.regulator_id AND r.tenant_id = a.tenant_id
               JOIN credential_holders h ON h.id = a.holder_id AND h.tenant_id = a.tenant_id AND h.deleted_at IS NULL
              WHERE a.tenant_id = ?{$this->scopeSql('a')[0]}
              ORDER BY FIELD(a.status,'under_review','submitted','in_progress','not_started','approved','denied','withdrawn'),
                       a.created_at DESC"
        );
        $stmt->execute(array_merge([$this->ctx->tenantId], $this->scopeSql('a')[1]));
        return $stmt->fetchAll();
    }

    public function statusHistory(string $applicationId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM application_status_history WHERE tenant_id = ? AND application_id = ?
              ORDER BY changed_at DESC"
        );
        $stmt->execute([$this->ctx->tenantId, $applicationId]);
        return $stmt->fetchAll();
    }

    /** Append a status-history entry (call whenever status changes). */
    public function recordStatus(string $applicationId, string $status, ?string $note, ?string $byName, ?string $byUser): void
    {
        Database::pdo()->prepare(
            "INSERT INTO application_status_history
                (id, tenant_id, application_id, status, note, changed_by, changed_by_user, changed_at)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([uuid4(), $this->ctx->tenantId, $applicationId, $status, $note, $byName, $byUser, utc_now()]);
    }

    /** count of open applications for a KPI. */
    public function openCount(): int
    {
        $in = implode(',', array_fill(0, count(self::OPEN_STATUSES), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM applications WHERE tenant_id = ? AND status IN ($in){$this->scopeSql()[0]}"
        );
        $stmt->execute(array_merge([$this->ctx->tenantId], self::OPEN_STATUSES, $this->scopeSql()[1]));
        return (int)$stmt->fetchColumn();
    }
}
