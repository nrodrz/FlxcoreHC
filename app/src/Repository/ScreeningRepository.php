<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC\Repository;

use FlxCoreHC\Database;

/** Exclusion / sanctions screening records (OIG LEIE, SAM.gov, state/PR Medicaid). */
final class ScreeningRepository extends TenantRepository
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

    public const SOURCES = [
        'oig_leie'       => 'OIG LEIE',
        'sam_gov'        => 'SAM.gov',
        'state_medicaid' => 'State Medicaid exclusion list',
        'pr_medicaid'    => 'PR Medicaid exclusion list',
        'other'          => 'Other',
    ];
    public const RESULTS = [
        'clear'           => 'Clear',
        'potential_match' => 'Potential match',
        'excluded'        => 'Excluded',
    ];

    protected function table(): string { return 'exclusion_screenings'; }
    protected function providerColumn(): ?string { return 'holder_id'; }   // need-to-know scope (v1.170.9)
    protected function fillable(): array
    {
        return ['holder_id', 'source', 'screened_on', 'result', 'reference',
                'screened_by', 'screened_by_user', 'notes'];
    }

    /** Screening history for a provider, newest first. */
    public function forHolder(string $holderId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM exclusion_screenings WHERE tenant_id = ? AND holder_id = ?{$this->scopeSql()[0]}
              ORDER BY screened_on DESC, created_at DESC"
        );
        $stmt->execute(array_merge([$this->ctx->tenantId, $holderId], $this->scopeSql()[1]));
        return $stmt->fetchAll();
    }

    /** Most recent screening for a provider, or null. */
    public function latestFor(string $holderId): ?array
    {
        $rows = $this->forHolder($holderId);
        return $rows[0] ?? null;
    }

    /** Count of providers whose most recent screening is an exclusion/potential match (a KPI). */
    public function flaggedCount(): int
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM (
                SELECT holder_id,
                       SUBSTRING_INDEX(GROUP_CONCAT(result ORDER BY screened_on DESC, created_at DESC), ',', 1) AS latest
                  FROM exclusion_screenings WHERE tenant_id = ?{$this->scopeSql()[0]} GROUP BY holder_id
             ) t WHERE t.latest IN ('excluded','potential_match')"
        );
        $stmt->execute(array_merge([$this->ctx->tenantId], $this->scopeSql()[1]));
        return (int)$stmt->fetchColumn();
    }
}
