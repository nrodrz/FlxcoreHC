<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC\Repository;

use FlxCoreHC\Database;

/**
 * Compliance Requirements engine (per agent & per agency).
 *
 * Combines three sources into one "is this holder current?" view:
 *   1. requirement_defs    — catalog of obligations for this vertical/scope.
 *   2. holder_requirements — manual status/attestations the tenant recorded.
 *   3. credentials         — auto-satisfaction: a 'credential' requirement is MET
 *      when the holder has a live, non-expired credential of the matching type
 *      (and matching plan_year for plan_year cadence).
 *
 * Derived state per (holder, requirement): met | due_soon | overdue | in_progress | open.
 * Credential-backed items stay current automatically as credentials renew;
 * attestation/policy items are driven by recorded status + cadence.
 */
final class RequirementRepository extends TenantRepository
{
    // Platform support sessions never see provider-linked rows — see SupportAccess.
    public function find(string $id): ?array { if (\FlxCoreHC\SupportAccess::active()) return null; return parent::find($id); }
    public function all(array $where = [], string $orderBy = 'created_at DESC', int $limit = 500): array
    { if (\FlxCoreHC\SupportAccess::active()) return []; return parent::all($where, $orderBy, $limit); }

    protected function table(): string { return 'holder_requirements'; }
    protected function fillable(): array
    {
        return ['holder_id','requirement_id','plan_year','status','completed_on',
                'expires_on','evidence_url','note','updated_by'];
    }
    protected function softDeletes(): bool { return true; }

    public function defsFor(string $holderType): array
    {
        $applies = $holderType === 'organization' ? 'agency' : 'agent';
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM requirement_defs
             WHERE active = 1 AND vertical = ?
               AND (tenant_id IS NULL OR tenant_id = ?)
               AND applies_to IN ('both', ?)
             ORDER BY FIELD(severity,'required','recommended'), sort_order"
        );
        $stmt->execute([$this->ctx->vertical, $this->ctx->tenantId, $applies]);
        return $stmt->fetchAll();
    }

    public function evaluateHolder(array $holder, int $planYear): array
    {
        $defs   = $this->defsFor($holder['holder_type']);
        $manual = $this->manualMap($holder['id']);
        $creds  = $this->liveCredentialTypes($holder['id'], $planYear);
        $today  = today_local_dt();

        $out = [];
        foreach ($defs as $d) {
            $state = 'open'; $detail = ''; $expires = null;

            if ($d['evidence_kind'] === 'credential' && $d['satisfied_by_credential_type']) {
                $match = $creds[$d['satisfied_by_credential_type']] ?? null;
                if ($match) {
                    $state = 'met'; $expires = $match['expiry_date']; $detail = 'Auto-satisfied by credential';
                    if ($expires) {
                        $days = (int)$today->diff(new \DateTimeImmutable($expires))->format('%r%a');
                        if ($days < 0)       { $state = 'overdue';  $detail = 'Backing credential expired'; }
                        elseif ($days <= 60) { $state = 'due_soon'; $detail = "Credential expires in {$days}d"; }
                    }
                } else {
                    $detail = 'No current credential on file';
                }
            }

            $key = $d['id'] . '|' . ($d['cadence'] === 'plan_year' ? $planYear : '');
            $m = $manual[$key] ?? ($manual[$d['id'] . '|'] ?? null);
            if ($m) {
                if ($m['status'] === 'waived') { $state = 'met'; $detail = 'Waived' . ($m['note'] ? ': ' . $m['note'] : ''); }
                elseif ($m['status'] === 'met') {
                    $state = 'met'; $detail = $m['completed_on'] ? 'Completed ' . $m['completed_on'] : 'Marked met';
                    $expires = $m['expires_on'] ?: $expires;
                    if ($m['expires_on']) {
                        $days = (int)$today->diff(new \DateTimeImmutable($m['expires_on']))->format('%r%a');
                        if ($days < 0)       { $state = 'overdue';  $detail = 'Lapsed ' . $m['expires_on']; }
                        elseif ($days <= 60) { $state = 'due_soon'; $detail = "Renews in {$days}d"; }
                    }
                } elseif ($m['status'] === 'in_progress') {
                    $state = 'in_progress'; $detail = $m['note'] ?: 'In progress';
                }
            }

            $out[] = ['def'=>$d, 'state'=>$state, 'detail'=>$detail, 'expires'=>$expires, 'manual'=>$m];
        }
        return $out;
    }

    public function summarize(array $evaluation): array
    {
        $r = ['total'=>0,'met'=>0,'overdue'=>0,'due_soon'=>0,'open'=>0];
        foreach ($evaluation as $e) {
            if ($e['def']['severity'] !== 'required') continue;
            $r['total']++;
            $s = $e['state'];
            if ($s === 'met') $r['met']++;
            elseif ($s === 'overdue') $r['overdue']++;
            elseif ($s === 'due_soon') $r['due_soon']++;
            else $r['open']++;
        }
        $r['pct'] = $r['total'] ? (int) round($r['met'] / $r['total'] * 100) : 100;
        $r['compliant'] = ($r['overdue'] === 0 && $r['open'] === 0);
        return $r;
    }

    public function setStatus(string $holderId, string $requirementId, ?int $planYear,
                              string $status, ?string $completedOn, ?string $expiresOn, ?string $note): bool
    {
        if (!in_array($status, ['open','in_progress','met','waived'], true)) return false;
        $h = Database::pdo()->prepare("SELECT id FROM credential_holders WHERE id=? AND tenant_id=? AND deleted_at IS NULL");
        $h->execute([$holderId, $this->ctx->tenantId]);
        if (!$h->fetch()) return false;
        $d = Database::pdo()->prepare("SELECT id FROM requirement_defs WHERE id=? AND (tenant_id IS NULL OR tenant_id=?)");
        $d->execute([$requirementId, $this->ctx->tenantId]);
        if (!$d->fetch()) return false;

        $existing = Database::pdo()->prepare(
            "SELECT id FROM holder_requirements WHERE tenant_id=? AND holder_id=? AND requirement_id=? AND plan_year <=> ? AND deleted_at IS NULL"
        );
        $existing->execute([$this->ctx->tenantId, $holderId, $requirementId, $planYear]);
        $row = $existing->fetch();
        if ($row) {
            Database::pdo()->prepare(
                "UPDATE holder_requirements SET status=?, completed_on=?, expires_on=?, note=?, updated_by=? WHERE id=? AND tenant_id=?"
            )->execute([$status,$completedOn,$expiresOn,$note,$this->ctx->userId,$row['id'],$this->ctx->tenantId]);
        } else {
            Database::pdo()->prepare(
                "INSERT INTO holder_requirements (id,tenant_id,holder_id,requirement_id,plan_year,status,completed_on,expires_on,note,updated_by,created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([uuid4(),$this->ctx->tenantId,$holderId,$requirementId,$planYear,$status,$completedOn,$expiresOn,$note,$this->ctx->userId,utc_now()]);
        }
        return true;
    }

    private function manualMap(string $holderId): array
    {
        $stmt = Database::pdo()->prepare("SELECT * FROM holder_requirements WHERE tenant_id=? AND holder_id=? AND deleted_at IS NULL");
        $stmt->execute([$this->ctx->tenantId, $holderId]);
        $map = [];
        foreach ($stmt->fetchAll() as $r) $map[$r['requirement_id'] . '|' . ($r['plan_year'] ?? '')] = $r;
        return $map;
    }

    private function liveCredentialTypes(string $holderId, int $planYear): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT credential_type, plan_year, MAX(expiry_date) AS expiry_date
             FROM credentials WHERE tenant_id=? AND holder_id=? AND deleted_at IS NULL AND status <> 'suspended'
             GROUP BY credential_type, plan_year"
        );
        $stmt->execute([$this->ctx->tenantId, $holderId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            if (!isset($out[$r['credential_type']]) || $r['expiry_date'] > $out[$r['credential_type']]['expiry_date']) {
                $out[$r['credential_type']] = $r;
            }
        }
        return $out;
    }
}
