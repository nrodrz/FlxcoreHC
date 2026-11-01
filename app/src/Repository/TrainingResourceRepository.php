<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC\Repository;

use FlxCoreHC\Database;

/** Tenant-authored training resources (an agency's own links, SOPs, carrier notes). */
final class TrainingResourceRepository extends TenantRepository
{
    protected function table(): string { return 'training_resources'; }
    protected function fillable(): array { return ['regulator_id', 'title', 'category', 'body', 'link']; }

    public function allWithRegulator(): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT r.*, c.name AS regulator_name
             FROM training_resources r
             LEFT JOIN regulators c ON c.id = r.regulator_id AND c.tenant_id = r.tenant_id
             WHERE r.tenant_id = ? ORDER BY r.created_at DESC"
        );
        $stmt->execute([$this->ctx->tenantId]);
        return $stmt->fetchAll();
    }
}
