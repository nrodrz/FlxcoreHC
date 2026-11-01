<?php
declare(strict_types=1);

namespace FlxCoreHC\Repository;

/** Continuing-education ledger per producer (FL biennial cycle, F.S. 626.2815). */
final class CeEntryRepository extends TenantRepository
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

    protected function table(): string { return 'ce_entries'; }
    protected function providerColumn(): ?string { return 'holder_id'; }   // need-to-know scope (v1.170.9)
    protected function fillable(): array
    {
        return ['holder_id', 'course_name', 'provider', 'hours', 'category', 'completed_date'];
    }
}
