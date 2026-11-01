<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC\Repository;

use FlxCoreHC\Database;

/** Which regulators a provider (holder) must satisfy. */
final class HolderRegulatorRepository extends TenantRepository
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

    protected function table(): string { return 'holder_regulators'; }
    protected function providerColumn(): ?string { return 'holder_id'; }   // need-to-know scope (v1.170.9)
    protected function fillable(): array { return ['holder_id', 'regulator_id']; }

    /** regulator_id[] assigned to one holder. */
    public function forHolder(string $holderId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT regulator_id FROM holder_regulators WHERE tenant_id = ? AND holder_id = ?{$this->scopeSql()[0]}"
        );
        $stmt->execute(array_merge([$this->ctx->tenantId, $holderId], $this->scopeSql()[1]));
        return array_column($stmt->fetchAll(), 'regulator_id');
    }

    /** holder_id => regulator_id[] for the whole tenant (one query for the compliance engine). */
    public function map(): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT holder_id, regulator_id FROM holder_regulators WHERE tenant_id = ?{$this->scopeSql()[0]}"
        );
        $stmt->execute(array_merge([$this->ctx->tenantId], $this->scopeSql()[1]));
        $out = [];
        foreach ($stmt->fetchAll() as $r) { $out[$r['holder_id']][] = $r['regulator_id']; }
        return $out;
    }

    /** Replace a holder's assigned regulators wholesale. */
    public function setForHolder(string $holderId, array $regulatorIds): void
    {
        $pdo = Database::pdo();
        $del = $pdo->prepare("DELETE FROM holder_regulators WHERE tenant_id = ? AND holder_id = ?");
        $del->execute([$this->ctx->tenantId, $holderId]);
        $ins = $pdo->prepare(
            "INSERT IGNORE INTO holder_regulators (id, tenant_id, holder_id, regulator_id, created_at)
             VALUES (?,?,?,?,?)"
        );
        foreach (array_unique(array_filter($regulatorIds)) as $rid) {
            $ins->execute([uuid4(), $this->ctx->tenantId, $holderId, $rid, utc_now()]);
        }
    }
}
