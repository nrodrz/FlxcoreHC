<?php
declare(strict_types=1);

namespace FlxCoreHC\Repository;

use FlxCoreHC\SupportAccess;

/** Repeating provider records for credentialing applications (provider_work_history). Tenant-scoped, soft-deleted. */
final class ProviderWorkHistoryRepository extends TenantRepository
{
    protected function table(): string { return 'provider_work_history'; }
    protected function providerColumn(): ?string { return 'holder_id'; }   // need-to-know scope (v1.170.9)
    protected function fillable(): array { return ['holder_id','employer','position','city','state','start_date','end_date','reason_left','gap_explanation']; }
    protected function softDeletes(): bool { return true; }
    public function find(string $id): ?array { if (SupportAccess::active()) return null; return parent::find($id); }
    public function all(array $where = [], string $orderBy = 'start_date DESC, created_at DESC', int $limit = 500): array
    { if (SupportAccess::active()) return []; return parent::all($where, $orderBy, $limit); }
    public function forHolder(string $holderId): array { return $this->all(['holder_id' => $holderId]); }
}
