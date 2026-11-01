<?php
declare(strict_types=1);

namespace FlxCoreHC\Repository;

use FlxCoreHC\Database;

/**
 * Per-tenant compliance checklist (audit governance feature).
 *
 * compliance_items holds global template items (tenant_id NULL) plus any
 * tenant-custom items. tenant_compliance holds each tenant's status per item.
 * Reads are tenant-scoped; template items are shared read-only.
 */
final class ComplianceRepository extends TenantRepository
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

    protected function table(): string { return 'tenant_compliance'; }
    protected function fillable(): array
    {
        return ['item_id','status','note','evidence_url','reviewed_at','reviewed_by','due_date'];
    }

    /** Items applicable to this tenant's vertical (global templates + own custom). */
    public function applicableItems(): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM compliance_items
             WHERE active = 1
               AND (tenant_id IS NULL OR tenant_id = ?)
               AND applies_to IN ('all', ?)
             ORDER BY FIELD(severity,'required','recommended'), category, sort_order"
        );
        $stmt->execute([$this->ctx->tenantId, $this->ctx->vertical]);
        return $stmt->fetchAll();
    }

    /** Map item_id => tenant_compliance row for this tenant. */
    public function statusMap(): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM tenant_compliance WHERE tenant_id = ?"
        );
        $stmt->execute([$this->ctx->tenantId]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[$row['item_id']] = $row;
        }
        return $map;
    }

    /** Upsert a status for one item (creates the tenant_compliance row on first touch). */
    public function setStatus(string $itemId, string $status, ?string $note = null): bool
    {
        $valid = ['not_started','in_progress','done','na'];
        if (!in_array($status, $valid, true)) {
            return false;
        }
        // verify item is real & applicable (template or own)
        $chk = Database::pdo()->prepare(
            "SELECT id FROM compliance_items WHERE id = ? AND (tenant_id IS NULL OR tenant_id = ?) LIMIT 1"
        );
        $chk->execute([$itemId, $this->ctx->tenantId]);
        if (!$chk->fetch()) {
            return false;
        }
        $existing = Database::pdo()->prepare(
            "SELECT id FROM tenant_compliance WHERE tenant_id = ? AND item_id = ? LIMIT 1"
        );
        $existing->execute([$this->ctx->tenantId, $itemId]);
        $row = $existing->fetch();
        if ($row) {
            Database::pdo()->prepare(
                "UPDATE tenant_compliance SET status = ?, note = ?, reviewed_at = ?, reviewed_by = ?
                 WHERE id = ? AND tenant_id = ?"
            )->execute([$status, $note, utc_now(), $this->ctx->userId, $row['id'], $this->ctx->tenantId]);
        } else {
            Database::pdo()->prepare(
                "INSERT INTO tenant_compliance (id, tenant_id, item_id, status, note, reviewed_at, reviewed_by, created_at)
                 VALUES (?,?,?,?,?,?,?,?)"
            )->execute([uuid4(), $this->ctx->tenantId, $itemId, $status, $note, utc_now(), $this->ctx->userId, utc_now()]);
        }
        return true;
    }

    /** Compliance score: % of REQUIRED items marked done or n/a. */
    public function score(): array
    {
        $items = $this->applicableItems();
        $map   = $this->statusMap();
        $reqTotal = 0; $reqDone = 0; $allDone = 0;
        foreach ($items as $it) {
            $st = $map[$it['id']]['status'] ?? 'not_started';
            $satisfied = in_array($st, ['done','na'], true);
            if ($satisfied) $allDone++;
            if ($it['severity'] === 'required') {
                $reqTotal++;
                if ($satisfied) $reqDone++;
            }
        }
        return [
            'required_total' => $reqTotal,
            'required_done'  => $reqDone,
            'all_total'      => count($items),
            'all_done'       => $allDone,
            'pct'            => $reqTotal ? (int) round($reqDone / $reqTotal * 100) : 100,
        ];
    }
}
