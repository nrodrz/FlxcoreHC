<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

use FlxCoreHC\Repository\CredentialingCaseRepository;
use FlxCoreHC\Repository\CredentialingCaseRequirementRepository;

/**
 * The single authority for Credentialing Case lifecycle transitions and hard-stop enforcement.
 * EVERY path that changes a protected case status (UI route, future API, background job) must call
 * this service — enforcement must not live only in the UI/templates. Reuses ComplianceEventService for
 * history and ComplianceLevelService signals for provider-level hard stops; does not duplicate them.
 */
final class CredentialingCaseService
{
    /** Allowed status transitions. Terminal states have no outgoing edges. */
    private const TRANSITIONS = [
        'open'                 => ['in_progress', 'cancelled'],
        'in_progress'          => ['ready_for_submission', 'open', 'cancelled'],
        'ready_for_submission' => ['submitted', 'in_progress', 'cancelled'],
        'submitted'            => ['completed', 'in_progress'],   // in_progress = payer returned for more info
        'completed'            => ['closed'],
        'closed'               => [],
        'cancelled'            => [],
    ];

    public function __construct(private readonly TenantContext $ctx) {}

    private function cases(): CredentialingCaseRepository { return new CredentialingCaseRepository($this->ctx); }
    private function reqs(): CredentialingCaseRequirementRepository { return new CredentialingCaseRequirementRepository($this->ctx); }

    private function existsInTenant(string $table, string $id): bool
    {
        static $allowed = ['credential_holders','provider_groups','payers','locations'];
        if (!in_array($table, $allowed, true)) return false;
        try {
            // payers: tenant_id NULL rows are platform-wide reference payers (same rule as EnrollmentService).
            $scope = $table === 'payers' ? '(tenant_id = ? OR tenant_id IS NULL)' : 'tenant_id = ?';
            if ($table === 'credential_holders') $scope .= ' AND deleted_at IS NULL';   // no cases on trashed providers
            $st = Database::pdo()->prepare("SELECT 1 FROM {$table} WHERE id = ? AND {$scope} LIMIT 1");
            $st->execute([$id, $this->ctx->tenantId]);
            return (bool) $st->fetchColumn();
        } catch (\Throwable $e) { flx_log_error('warning', 'case relationship check failed'); return false; }
    }

    private function userInTenant(string $userId): bool
    {
        try {
            // v1.170.6 — assignee must be an ACTIVE, non-portal user of this tenant.
            $st = Database::pdo()->prepare("SELECT 1 FROM users WHERE id = ? AND tenant_id = ? AND status = 'active' AND role <> 'producer' LIMIT 1");
            $st->execute([$userId, $this->ctx->tenantId]);
            return (bool) $st->fetchColumn();
        } catch (\Throwable $e) { return false; }
    }

    private function catalogHasCode(string $code): bool
    {
        if ($code === '') return false;
        try { return array_key_exists($code, CatalogService::labelsFor($this->ctx->tenantId)); }
        catch (\Throwable $e) { return false; }
    }

    /** Create a case for a provider. Returns [ok, idOrMessage]. */
    public function open(array $data): array
    {
        if (empty($data['holder_id'])) return [false, 'A provider is required.'];
        if (!$this->existsInTenant('credential_holders', (string) $data['holder_id']))
            return [false, 'Provider not found in this tenant.'];
        if (!empty($data['client_group_id']) && !$this->existsInTenant('provider_groups', (string) $data['client_group_id']))
            return [false, 'Client group not found in this tenant.'];
        if (!empty($data['payer_id']) && !$this->existsInTenant('payers', (string) $data['payer_id']))
            return [false, 'Payer not found in this tenant.'];
        if (!empty($data['location_id']) && !$this->existsInTenant('locations', (string) $data['location_id']))
            return [false, 'Location not found in this tenant.'];
        if (!empty($data['assigned_to']) && !$this->userInTenant((string) $data['assigned_to']))
            return [false, 'Assignee is not a user in this tenant.'];
        $data['status'] = 'open';
        $data['opened_at'] = $data['opened_at'] ?? utc_now();
        $data['created_by'] = $this->ctx->userId ?: null;
        $id = $this->cases()->insert($data);
        ComplianceEventService::record($this->ctx, 'case_created', 'Credentialing case opened',
            ['holder_id' => $data['holder_id'], 'case_id' => $id, 'detail' => ['case_type' => $data['case_type'] ?? 'initial']]);
        return [true, $id];
    }

    /** Assign / reassign. */
    public function assign(string $caseId, ?string $userId): array
    {
        $case = $this->cases()->find($caseId);
        if (!$case) return [false, 'Case not found.'];
        // §1 — a non-null assignee must be a user in THIS tenant. null = unassignment (always valid).
        if ($userId !== null && $userId !== '' && !$this->userInTenant($userId)) {
            return [false, 'Assignee is not a user in this tenant.'];
        }
        $userId = ($userId === '') ? null : $userId;
        $this->cases()->update($caseId, ['assigned_to' => $userId]);
        ComplianceEventService::record($this->ctx, 'case_assignment_changed', 'Case assignment changed',
            ['holder_id' => $case['holder_id'], 'case_id' => $caseId, 'detail' => ['assigned_to' => $userId]]);
        return [true, $caseId];
    }

    /**
     * Attempt a status transition. Centralized gate:
     *  - the transition must be allowed by the state machine
     *  - ready_for_submission is BLOCKED while unresolved required hard-stop requirements remain
     * Returns [ok, messageOrStatus, blockers[]].
     */
    public function transition(string $caseId, string $to): array
    {
        $case = $this->cases()->find($caseId);
        if (!$case) return [false, 'Case not found.', []];
        $from = (string) $case['status'];
        if ($from === $to) return [true, $to, []];
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            return [false, "Cannot move a case from '{$from}' to '{$to}'.", []];
        }

        if ($to === 'ready_for_submission') {
            $blockers = $this->submissionBlockers($caseId, $case['holder_id']);
            if ($blockers !== []) return [false, 'Unresolved hard stops block submission.', $blockers];
        }

        $patch = ['status' => $to];
        if ($to === 'submitted') $patch['submitted_at'] = utc_now();
        if ($to === 'completed') $patch['completed_at'] = utc_now();
        if ($to === 'closed')    $patch['closed_at']    = utc_now();
        $this->cases()->update($caseId, $patch);

        ComplianceEventService::record($this->ctx, 'case_status_changed', "Case status: {$from} → {$to}",
            ['holder_id' => $case['holder_id'], 'case_id' => $caseId, 'detail' => ['from' => $from, 'to' => $to]]);
        return [true, $to, []];
    }

    public function updateRequirementStatus(string $caseId, string $requirementId, string $status): array
    {
        $case = $this->cases()->find($caseId);
        if (!$case) return [false, 'Case not found.'];
        $req = $this->reqs()->find($requirementId);
        if (!$req || (string) $req['case_id'] !== $caseId) return [false, 'Requirement not found on this case.'];
        if (!array_key_exists($status, CredentialingCaseRequirementRepository::STATUSES)) return [false, 'Invalid status.'];
        $this->reqs()->update($requirementId, [
            'status' => $status,
            'completed_at' => in_array($status, ['satisfied','waived'], true) ? utc_now() : null,
        ]);
        ComplianceEventService::record($this->ctx, 'case_requirement_changed',
            "Requirement {$req['requirement_code']} status: {$status}",
            ['holder_id' => $case['holder_id'], 'case_id' => $caseId,
             'detail' => ['code' => $req['requirement_code'], 'status' => $status]]);
        return [true, $requirementId];
    }

    /**
     * §2 — delete a case document, verifying it belongs to THIS case (not just this tenant).
     * @return array{0:bool,1:string}
     */
    public function deleteCaseDocument(string $caseId, string $docId): array
    {
        $case = $this->cases()->find($caseId);
        if (!$case) return [false, 'Case not found.'];
        $docs = new Repository\CaseDocumentRepository($this->ctx);
        $doc = $docs->find($docId);                                   // tenant-scoped
        if (!$doc || (string) ($doc['case_id'] ?? '') !== $caseId) return [false, 'Document not found on this case.'];
        $docs->delete((string) $doc['id']);
        ComplianceEventService::record($this->ctx, 'case_document_removed', 'Case document removed',
            ['case_id' => $caseId, 'holder_id' => $doc['holder_id'] ?? null]);
        return [true, $docId];
    }

    /**
     * §2 — unlink an evidence row, verifying its requirement belongs to THIS case (not just this tenant).
     * @return array{0:bool,1:string}
     */
    public function unlinkEvidence(string $caseId, string $evidenceId): array
    {
        $case = $this->cases()->find($caseId);
        if (!$case) return [false, 'Case not found.'];
        $evi = new Repository\CaseRequirementEvidenceRepository($this->ctx);
        $row = $evi->find($evidenceId);                               // tenant-scoped
        if (!$row) return [false, 'Evidence not found.'];
        $req = $this->reqs()->find((string) $row['case_requirement_id']);
        if (!$req || (string) $req['case_id'] !== $caseId) return [false, 'Evidence does not belong to this case.'];
        $evi->delete((string) $row['id']);
        ComplianceEventService::record($this->ctx, 'case_evidence_unlinked', 'Evidence unlinked from requirement',
            ['case_id' => $caseId, 'holder_id' => $case['holder_id'] ?? null, 'detail' => ['requirement' => $req['requirement_code'] ?? null]]);
        return [true, $evidenceId];
    }

    /**
     * Hard-stop blockers for submission = unresolved required hard-stop CASE requirements, PLUS
     * provider-level hard stops surfaced by ComplianceLevelService (expired license, exclusion hit).
     * The provider-level signal is REUSED, not recomputed here.
     */
    public function submissionBlockers(string $caseId, string $holderId, bool $strict = true): array
    {
        $blockers = [];
        foreach ($this->reqs()->unresolvedHardStops($caseId) as $r) {
            $blockers[] = ['type' => 'case_requirement', 'code' => $r['requirement_code'], 'status' => $r['status']];
        }
        try {
            $holder = (new Repository\HolderRepository($this->ctx))->find($holderId);
            if ($holder) {
                $creds = (new Repository\CredentialRepository($this->ctx))->all(['holder_id' => $holderId], 'expiry_date ASC', 500);
                $req   = (new Repository\ProducerRequirementRepository($this->ctx))->tokensFor($holderId);
                $lvl   = ComplianceLevelService::evaluate($this->ctx, $holder, $creds, $req, CatalogService::coverageTokens($creds));
                if (!empty($lvl['hard_stop'])) {
                    $blockers[] = ['type' => 'provider_compliance', 'reason' => $lvl['hard_stop']];
                }
            } elseif ($strict) {
                // provider unexpectedly missing during an enforcement check → fail CLOSED
                $blockers[] = ['type' => 'compliance_check_error', 'reason' => 'provider_not_found'];
            }
        } catch (\Throwable $e) {
            // §3 — FAIL CLOSED on the protected gate. Never treat an eval failure as "no blocker".
            flx_log_error('error', 'case submission compliance check failed', ['path' => 'CredentialingCaseService::submissionBlockers']);
            if ($strict) {
                $blockers[] = ['type' => 'compliance_check_error', 'reason' => 'evaluation_failed'];
            }
            // non-strict (display) callers get graceful degradation: no synthetic blocker.
        }
        return $blockers;
    }

    /** Add a requirement to a case (dedup-safe). */
    public function addRequirement(string $caseId, string $code, array $opts = []): array
    {
        $case = $this->cases()->find($caseId);
        if (!$case) return [false, 'Case not found.'];
        if (!$this->catalogHasCode($code)) return [false, 'Unknown requirement code.'];
        $id = $this->reqs()->addRequirement([
            'case_id' => $caseId, 'requirement_code' => $code,
            'label' => $opts['label'] ?? null, 'is_required' => $opts['is_required'] ?? 1,
            'is_hard_stop' => $opts['is_hard_stop'] ?? 0, 'due_date' => $opts['due_date'] ?? null,
        ]);
        ComplianceEventService::record($this->ctx, 'case_requirement_changed', "Requirement added: {$code}",
            ['holder_id' => $case['holder_id'], 'case_id' => $caseId, 'detail' => ['code' => $code]]);
        return [true, $id];
    }

    /**
     * §4 — populate a case's requirements from the provider's profession template. REUSES existing
     * infrastructure (requirement_templates → catalog codes); does NOT create a parallel engine or a new
     * requirement master. Idempotent per (case, code). Returns [ok, countAdded|message].
     */
    public function populateRequirements(string $caseId): array
    {
        $case = $this->cases()->find($caseId);
        if (!$case) return [false, 'Case not found.'];
        $holder = (new Repository\HolderRepository($this->ctx))->find((string) $case['holder_id']);
        if (!$holder) return [false, 'Provider not found.'];
        $providerType = trim((string) ($holder['provider_type'] ?? ''));
        if ($providerType === '') return [false, 'Provider has no profession set — add requirements manually.'];

        // Find the profession template (same match TemplateService uses) and read its catalog codes.
        $st = Database::pdo()->prepare(
            "SELECT id FROM requirement_templates WHERE tenant_id = ? AND applies_to = ? ORDER BY is_seed ASC, sort ASC LIMIT 1");
        $st->execute([$this->ctx->tenantId, $providerType]);
        $tid = $st->fetchColumn();
        if (!$tid) return [false, 'No template for profession "' . $providerType . '". Add requirements manually.'];

        $labels = CatalogService::labelsFor($this->ctx->tenantId);
        $codes = (new TemplateService($this->ctx))->tokens((string) $tid);
        $added = 0;
        foreach ($codes as $tok) {
            $code = explode('@', (string) $tok)[0];              // strip any @regulator suffix
            if ($code === '' || !array_key_exists($code, $labels)) continue;   // active catalog only
            $id = $this->reqs()->addRequirement([
                'case_id' => $caseId, 'requirement_code' => $code,
                'label' => $labels[$code] ?? null, 'is_required' => 1, 'is_hard_stop' => 0,
            ]);
            if ($id) $added++;
        }
        ComplianceEventService::record($this->ctx, 'case_requirements_populated',
            "Populated {$added} requirement(s) from the {$providerType} template",
            ['holder_id' => $case['holder_id'], 'case_id' => $caseId, 'detail' => ['count' => $added]]);
        return [true, $added];
    }

    /**
     * §8 — reusable existing evidence for a provider, per requirement code. Presents ELIGIBLE evidence
     * for human selection; NEVER auto-satisfies. Validity (expiry) is surfaced, not assumed.
     * @return array<string,array<int,array>> code => [ ['kind'=>, 'id'=>, 'label'=>, 'expiry'=>, 'valid'=>bool], ... ]
     */
    public function reusableEvidenceFor(string $holderId): array
    {
        $out = [];
        try {
            $creds = (new Repository\CredentialRepository($this->ctx))->all(['holder_id' => $holderId], 'expiry_date ASC', 500);
            $today = today_local();
            foreach ($creds as $c) {
                $code = (string) $c['credential_type'];
                $exp = $c['expiry_date'] ?? null;
                $valid = ($exp === null) || ($exp >= $today);      // expired credential is NOT valid satisfaction
                $out[$code][] = ['kind' => 'credential', 'id' => $c['id'],
                    'label' => (string) ($c['name'] ?: $code), 'expiry' => $exp, 'valid' => $valid,
                    'status' => (string) ($c['status'] ?? '')];
            }
        } catch (\Throwable $e) { flx_log_error('warning', 'reusable evidence scan failed', ['path' => 'CredentialingCaseService']); }
        return $out;
    }

}
