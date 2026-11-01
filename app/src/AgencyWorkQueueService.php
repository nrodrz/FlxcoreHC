<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Agency work queue — DERIVED from existing case + evidence data. No work_queue table. Mirrors the
 * aggregation approach of DashboardService. Every query is tenant-scoped via the current context.
 */
final class AgencyWorkQueueService
{
    public function __construct(private readonly TenantContext $ctx) {}

    /** @return array<string,int|array> buckets for the queue UI. */
    public function build(?string $assignedTo = null): array
    {
        $pdo = Database::pdo(); $t = $this->ctx->tenantId;
        // §10 — availability is preserved (empty/zero on failure) BUT the failure is logged so a
        // schema/query defect is not silently indistinguishable from a legitimate zero.
        $failed = 0;
        $q = function (string $sql, array $p) use ($pdo, &$failed) { try { $s=$pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); } catch (\Throwable $e) { $failed++; flx_log_error('error', 'work queue query failed', ['path' => 'AgencyWorkQueueService']); return []; } };
        $n = function (string $sql, array $p) use ($pdo, &$failed) { try { $s=$pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); } catch (\Throwable $e) { $failed++; flx_log_error('error', 'work queue count failed', ['path' => 'AgencyWorkQueueService']); return 0; } };
        $live = "deleted_at IS NULL";
        // v1.170.9 — provider scope on every bucket: a restricted user's queue and counts cover only their
        // assigned providers. Cases carry holder_id; requirements/verifications/doc-requests resolve via case.
        $sc  = ProviderScope::for($this->ctx);
        $SH  = $sc->sql('holder_id', false);                                             // credentialing_cases (unaliased)
        $SC  = $sc->sql('c.holder_id', false);                                           // credentialing_cases c
        $SR  = $sc->restricted() ? " AND case_id IN (SELECT cx.id FROM credentialing_cases cx JOIN holder_assignments ha ON ha.tenant_id = cx.tenant_id AND ha.holder_id = cx.holder_id WHERE cx.tenant_id = ? AND ha.user_id = ?)" : '';
        $SP  = $sc->params();

        return [
            'my_cases'        => $assignedTo ? $q("SELECT * FROM credentialing_cases WHERE tenant_id=? AND assigned_to=? AND $live AND status NOT IN ('completed','closed','cancelled'){$SH} ORDER BY target_date IS NULL, target_date ASC LIMIT 200", array_merge([$t,$assignedTo], $SP)) : [],
            'unassigned'      => $n("SELECT COUNT(*) FROM credentialing_cases WHERE tenant_id=? AND assigned_to IS NULL AND $live AND status NOT IN ('completed','closed','cancelled'){$SH}", array_merge([$t], $SP)),
            'overdue_reqs'    => $n("SELECT COUNT(*) FROM credentialing_case_requirements WHERE tenant_id=? AND due_date < @flx_today AND status NOT IN ('satisfied','waived'){$SR}", array_merge([$t], $SP)),
            'ready'           => $n("SELECT COUNT(*) FROM credentialing_cases WHERE tenant_id=? AND status='ready_for_submission' AND $live{$SH}", array_merge([$t], $SP)),
            'submitted'       => $n("SELECT COUNT(*) FROM credentialing_cases WHERE tenant_id=? AND status='submitted' AND $live{$SH}", array_merge([$t], $SP)),
            'pending_verif'   => $n("SELECT COUNT(*) FROM source_verifications WHERE tenant_id=? AND case_id IS NOT NULL AND (outcome IS NULL OR outcome=''){$SR}", array_merge([$t], $SP)),
            'open_doc_reqs'   => $n("SELECT COUNT(*) FROM document_requests WHERE tenant_id=? AND case_id IS NOT NULL AND status NOT IN ('accepted','cancelled'){$SR}", array_merge([$t], $SP)),
            'approaching'     => $n("SELECT COUNT(*) FROM credentialing_cases WHERE tenant_id=? AND $live AND target_date IS NOT NULL AND target_date BETWEEN @flx_today AND DATE_ADD(@flx_today, INTERVAL 14 DAY) AND status NOT IN ('completed','closed','cancelled'){$SH}", array_merge([$t], $SP)),
            'hard_stops'      => $n("SELECT COUNT(DISTINCT case_id) FROM credentialing_case_requirements WHERE tenant_id=? AND is_hard_stop=1 AND status NOT IN ('satisfied','waived'){$SR}", array_merge([$t], $SP)),
            'needs_attention' => $n("SELECT COUNT(DISTINCT c.id) FROM credentialing_cases c JOIN credentialing_case_requirements r ON r.case_id=c.id AND r.tenant_id=c.tenant_id WHERE c.tenant_id=? AND c.$live AND c.status NOT IN ('completed','closed','cancelled') AND ((r.is_hard_stop=1 AND r.status NOT IN ('satisfied','waived')) OR (r.due_date < @flx_today AND r.status NOT IN ('satisfied','waived'))){$SC}", array_merge([$t], $SP)),
            'active_total'    => $n("SELECT COUNT(*) FROM credentialing_cases WHERE tenant_id=? AND $live AND status NOT IN ('completed','closed','cancelled'){$SH}", array_merge([$t], $SP)),
            'query_failures'  => $failed,   // evaluated last: >0 means figures above are UNKNOWN, not zero
        ];
    }
}
