<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Management dashboard — the process hub. Computes, in one pass, everything a finance/operations
 * manager needs to run the credentials & compliance process:
 *
 *   - risk exposure   : compliance-level distribution (L0–L5) and how many providers are NOT billable
 *   - expiry forecast : credentials lapsing per month for the next 6 months (a forward risk curve)
 *   - work queue      : prioritized, actionable items — each with a link straight into the record
 *   - throughput      : what got done recently (verifications, documents, requests)
 *
 * Everything returned is drillable: charts link to the filtered list behind each segment, and the
 * work queue links to the exact provider/credential to act on.
 */
final class DashboardService
{
    public function __construct(private readonly TenantContext $ctx) {}

    public function build(): array
    {
        $pdo = Database::pdo(); $t = $this->ctx->tenantId;
        // v1.170.9 — provider scope (need-to-know) is part of every query below: counts, forecast, queues and
        // throughput reflect ONLY the providers this user may see. $sc/$sp are the predicate + params;
        // 'S' in a query is the scope on a credentials alias c, 'SD' on document_requests d, etc.
        $scope = ProviderScope::for($this->ctx);
        $S  = $scope->sql('c.holder_id', false);      $SP = $scope->params();
        $S0 = $scope->sql('holder_id', false);        // unaliased credentials/source_verifications/document_requests
        $SD = $scope->sql('d.holder_id', false);
        $SV = $scope->sqlViaCredential('credential_id');
        $failed = 0;
        // Query failures are LOGGED and counted, never silently reported as "0 / nothing" (healthcare
        // compliance: unknown must not read as compliant). The view shows a warning when $failed > 0.
        $q = function (string $sql, array $p = []) use ($pdo, &$failed) {
            try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }
            catch (\Throwable $e) { $failed++; flx_log_error('error', 'dashboard query failed: ' . $e->getMessage()); return []; }
        };
        $one = function (string $sql, array $p = []) use ($pdo, &$failed) {
            try { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); }
            catch (\Throwable $e) { $failed++; flx_log_error('error', 'dashboard query failed: ' . $e->getMessage()); return 0; }
        };

        // ---- Compliance-level distribution (risk exposure) ---------------------------------
        $levels = [0=>0,1=>0,2=>0,3=>0,4=>0,5=>0]; $notBillable = []; $providers = 0;
        try {
            $H = new Repository\HolderRepository($this->ctx); $C = new Repository\CredentialRepository($this->ctx); $R = new Repository\ProducerRequirementRepository($this->ctx);
            foreach ($H->all([], 'last_name ASC', 2000) as $h) {
                $providers++;
                $cr = $C->all(['holder_id' => $h['id']]);
                $lv = ComplianceLevelService::evaluate($this->ctx, $h, $cr, $R->tokensFor($h['id']), CatalogService::coverageTokens($cr));
                $levels[$lv['level']] = ($levels[$lv['level']] ?? 0) + 1;
                if ($lv['level'] < 4) $notBillable[] = ['id' => $h['id'], 'name' => trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? '')) ?: ($h['org_name'] ?? '—'), 'level' => $lv['level'], 'reason' => $lv['hard_stop'] ?? ($lv['next'] ?? '')];
            }
        } catch (\Throwable $e) { $failed++; flx_log_error('error', 'dashboard compliance evaluation failed: ' . $e->getMessage()); }
        usort($notBillable, fn($a, $b) => $a['level'] <=> $b['level']);

        // ---- Expiry forecast: next 6 months by month ----------------------------------------
        $forecast = [];
        for ($i = 0; $i < 6; $i++) {
            $m = (new \DateTimeImmutable('first day of this month'))->modify("+$i months");
            $forecast[] = ['label' => t($m->format('M')), 'ym' => $m->format('Y-m'),
                'n' => $one("SELECT COUNT(*) FROM credentials WHERE tenant_id=? AND deleted_at IS NULL{$S0} AND expiry_date >= ? AND expiry_date < ?",
                    array_merge([$t], $SP, [$m->format('Y-m-01'), $m->modify('+1 month')->format('Y-m-01')]))];
        }
        $expired = $one("SELECT COUNT(*) FROM credentials WHERE tenant_id=? AND deleted_at IS NULL{$S0} AND expiry_date < @flx_today", array_merge([$t], $SP));
        $exp30   = $one("SELECT COUNT(*) FROM credentials WHERE tenant_id=? AND deleted_at IS NULL{$S0} AND expiry_date >= @flx_today AND expiry_date < DATE_ADD(@flx_today, INTERVAL 30 DAY)", array_merge([$t], $SP));
        $exp90   = $one("SELECT COUNT(*) FROM credentials WHERE tenant_id=? AND deleted_at IS NULL{$S0} AND expiry_date >= @flx_today AND expiry_date < DATE_ADD(@flx_today, INTERVAL 90 DAY)", array_merge([$t], $SP));

        // ---- Work queue: prioritized, actionable, each with a direct link ------------------
        $queue = [];
        foreach ($q("SELECT c.id, c.name, c.expiry_date, c.holder_id, h.first_name, h.last_name, h.org_name FROM credentials c JOIN credential_holders h ON h.id=c.holder_id AND h.tenant_id=c.tenant_id AND h.deleted_at IS NULL WHERE c.tenant_id=? AND c.deleted_at IS NULL{$S} AND c.expiry_date < @flx_today ORDER BY c.expiry_date ASC LIMIT 25", array_merge([$t], $SP)) as $r) {
            $queue[] = ['pri' => 1, 'kind' => 'expired', 'title' => $r['name'] . ' expired', 'who' => $this->who($r), 'when' => $r['expiry_date'], 'href' => '/credentials/' . $r['id'] . '/edit', 'action' => 'Renew'];
        }
        foreach ($q("SELECT c.id, c.name, c.expiry_date, c.holder_id, h.first_name, h.last_name, h.org_name FROM credentials c JOIN credential_holders h ON h.id=c.holder_id AND h.tenant_id=c.tenant_id AND h.deleted_at IS NULL WHERE c.tenant_id=? AND c.deleted_at IS NULL{$S} AND c.expiry_date >= @flx_today AND c.expiry_date < DATE_ADD(@flx_today, INTERVAL 30 DAY) ORDER BY c.expiry_date ASC LIMIT 25", array_merge([$t], $SP)) as $r) {
            $queue[] = ['pri' => 2, 'kind' => 'expiring', 'title' => $r['name'] . ' expires', 'who' => $this->who($r), 'when' => $r['expiry_date'], 'href' => '/credentials/' . $r['id'] . '/edit', 'action' => 'Renew'];
        }
        foreach ($q("SELECT d.id, d.holder_id, d.requested_kind AS credential_type, d.status, COALESCE(d.submitted_at, d.requested_at) AS created_at, h.first_name, h.last_name, h.org_name FROM document_requests d JOIN credential_holders h ON h.id=d.holder_id AND h.tenant_id=d.tenant_id AND h.deleted_at IS NULL WHERE d.tenant_id=? AND d.status='submitted'{$SD} ORDER BY COALESCE(d.submitted_at, d.requested_at) ASC LIMIT 25", array_merge([$t], $SP)) as $r) {
            $queue[] = ['pri' => 2, 'kind' => 'review', 'title' => 'Document submitted — review', 'who' => $this->who($r), 'when' => substr(fmt_dt((string)$r['created_at'], 'Y-m-d H:i'), 0, 10), 'href' => '/holders/' . $r['holder_id'] . '#requests', 'action' => 'Review'];
        }
        foreach ($q("SELECT id, holder_id, source, reverify_on FROM source_verifications WHERE tenant_id=?{$S0} AND reverify_on IS NOT NULL AND reverify_on <= @flx_today ORDER BY reverify_on ASC LIMIT 25", array_merge([$t], $SP)) as $r) {
            $queue[] = ['pri' => 3, 'kind' => 'reverify', 'title' => strtoupper((string)$r['source']) . ' re-verification due', 'who' => '', 'when' => $r['reverify_on'], 'href' => '/holders/' . $r['holder_id'] . '#verify', 'action' => 'Re-verify'];
        }
        foreach (array_slice($notBillable, 0, 25) as $nb) {
            if ($nb['level'] === 0) $queue[] = ['pri' => 1, 'kind' => 'blocked', 'title' => t('Not compliant — {reason}', ['reason' => ($nb['reason'] ?: t('hard stop'))]), 'who' => $nb['name'], 'when' => '', 'href' => '/holders/' . $nb['id'], 'action' => 'Fix'];
        }
        usort($queue, fn($a, $b) => [$a['pri'], $a['when']] <=> [$b['pri'], $b['when']]);

        // ---- Throughput (last 30 days) -------------------------------------------------------
        $tp = [
            'verified'  => $one("SELECT COUNT(*) FROM source_verifications WHERE tenant_id=?{$S0} AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", array_merge([$t], $SP)),
            'docs'      => $one("SELECT COUNT(*) FROM credential_documents WHERE tenant_id=?{$SV} AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", array_merge([$t], $SP)),
            'requests'  => $one("SELECT COUNT(*) FROM document_requests WHERE tenant_id=?{$S0} AND status='accepted' AND accepted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", array_merge([$t], $SP)),
            // audit_logs has no provider column, so a tenant-wide count would leak activity to a restricted user: scoped via entity_id ∈ visible credentials.
            'renewed'   => $one("SELECT COUNT(*) FROM audit_logs WHERE tenant_id=? AND action='credential_updated' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
                                . ($scope->restricted() ? " AND entity_id IN (SELECT cx.id FROM credentials cx JOIN holder_assignments ha ON ha.tenant_id = cx.tenant_id AND ha.holder_id = cx.holder_id WHERE cx.tenant_id = ? AND ha.user_id = ?)" : ''),
                                array_merge([$t], $SP)),
        ];

        $billable = $levels[4] + $levels[5];
        return [
            'providers' => $providers, 'billable' => $billable, 'notBillable' => count($notBillable),
            'billablePct' => $providers ? (int)round(100 * $billable / $providers) : 0,
            'levels' => $levels, 'forecast' => $forecast,
            'expired' => $expired, 'exp30' => $exp30, 'exp90' => $exp90,
            'queue' => array_slice($queue, 0, 40), 'queueTotal' => count($queue),
            'throughput' => $tp, 'blockedList' => array_slice($notBillable, 0, 8),
            'queryFailures' => $failed, 'scoped' => $scope->restricted(),
        ];
    }

    private function who(array $r): string
    {
        return trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: (string)($r['org_name'] ?? '');
    }
}
