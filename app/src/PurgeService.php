<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

use PDO;

/**
 * PurgeService — the ONLY place that permanently deletes a provider/organization record or a credential.
 * (v1.170.6; replaces the inline loops in index.php, which ran without a transaction, deleted in an order
 * the foreign keys reject, swallowed every error, left uploaded files on disk and could leave a partially
 * deleted record while reporting "nothing was removed".)
 *
 * Guarantees:
 *   1. ALL-OR-NOTHING. Every row change runs inside one transaction. Any failure rolls back everything.
 *   2. FK-SAFE ORDER. Children are deleted before parents, derived from the real FK graph:
 *        credentials            <- renewal_tasks, credential_documents, credential_reminders (FK)
 *        credential_holders     <- credentials, holder_requirements (FK)
 *      plus every holder-/credential-/case-owned table that has no FK (listed below), so nothing is orphaned.
 *   3. BLOCKERS FIRST. Purge is refused (nothing touched) when:
 *        - a login account that could still be used is linked to the provider (users.holder_id)
 *        - financial/legal insurance records exist (policies, commissions, bookings, SOA forms, interactions)
 *      A suspended portal-only (producer) account is simply unlinked inside the transaction.
 *   4. FILES AFTER COMMIT. Stored documents (credential_documents / case_documents file_path) are removed
 *      from disk only after the transaction commits, and only when the real path is inside this tenant's
 *      storage directory (never follows a path outside it).
 *   5. RETAINED BY DESIGN (not deleted): compliance_events (permanent timeline), audit_logs, access_logs,
 *      external_share_access, cross_share_access. Cross-org links and share rows are REVOKED, not deleted,
 *      so the other organization's history stays intact.
 *
 * Missing tables (older installs) are skipped; any other SQL error aborts and rolls back.
 */
final class PurgeService
{
    /**
     * Tables whose rows are legal/financial records — their presence blocks a purge (nothing is touched).
     * v1.170.8: the insurance-era tables (policies, commissions, bookings, SOA…) were dropped by migrations
     * 082/084, so the list now holds the healthcare records that must be resolved deliberately first.
     * Missing tables are tolerated (older installs).
     */
    private const BLOCKING_TABLES = [
        'credentialing_cases' => 'open credentialing cases',
    ];

    /** Holder-owned tables with a holder_id column and no inbound FK — deleted after credentials/cases. */
    private const HOLDER_TABLES = [
        'holder_requirements', 'producer_requirements', 'holder_regulators', 'holder_groups', 'holder_locations',
        'holder_assignments', 'holder_attestations', 'provider_education', 'provider_work_history',
        'provider_contacts', 'provider_consents', 'calendar_events', 'exclusion_screenings', 'ce_entries',
        'ocs_profiles', 'nipr_interest', 'program_enrollments', 'payer_credentialing', 'applications',
        'source_verifications', 'document_requests', 'credential_verifications', 'renewal_tasks',
    ];

    private PDO $pdo;
    private array $filesToRemove = [];

    public function __construct(private readonly TenantContext $ctx)
    {
        $this->pdo = Database::pdo();
    }

    // =====================================================================================================
    // Holder (provider / organization)
    // =====================================================================================================

    /** Human-readable reasons this holder cannot be purged. Empty array = purge allowed. Fail-closed. */
    public function holderBlockers(string $holderId): array
    {
        $out = [];
        try {
            foreach ($this->linkedUsers($holderId) as $u) {
                if (!self::userLinkIsReleasable($u)) {
                    $out[] = 'A login account (' . $u['email'] . ', ' . ($u['role'] ?? '') . ', ' . ($u['status'] ?? '')
                           . ') is linked to this record. Suspend the portal account, or have a platform admin unlink it, first.';
                }
            }
            foreach (self::BLOCKING_TABLES as $table => $label) {
                // Only OPEN cases block (closed/cancelled history is purged with the provider).
                $n = $this->countWhere($table, "holder_id = ? AND status NOT IN ('completed','closed','cancelled','denied')", [$holderId]);
                if ($n > 0) $out[] = "$n $label still reference this record. Close or cancel them first.";
            }
        } catch (\Throwable $e) {
            flx_log_error('error', 'purge blocker check failed', ['holder' => $holderId]);
            $out[] = 'Could not verify that this record is safe to delete. Nothing was removed.';
        }
        return $out;
    }

    /** Linked login accounts that must block a SOFT delete (an active login would point at a trashed record). */
    public function activeLinkedUsers(string $holderId): array
    {
        return array_values(array_filter($this->linkedUsers($holderId), fn($u) => ($u['status'] ?? '') !== 'suspended'));
    }

    /** @return array{0:bool,1:string} */
    public function purgeHolder(string $holderId): array
    {
        $holder = $this->row('SELECT id, org_name, first_name, last_name FROM credential_holders WHERE id = ? AND tenant_id = ? AND deleted_at IS NOT NULL', [$holderId]);
        if (!$holder) return [false, 'Only a deleted record can be permanently removed. Delete it first, then purge.'];

        $blockers = $this->holderBlockers($holderId);
        if ($blockers) return [false, 'Cannot permanently delete: ' . implode(' ', $blockers)];

        $this->filesToRemove = [];
        try {
            $this->pdo->beginTransaction();
            $t = $this->ctx->tenantId;

            $credIds = $this->ids('SELECT id FROM credentials WHERE tenant_id = ? AND holder_id = ?', [$holderId]);
            $caseIds = $this->ids('SELECT id FROM credentialing_cases WHERE tenant_id = ? AND holder_id = ?', [$holderId], true);
            $reqIds  = $caseIds ? $this->ids('SELECT id FROM credentialing_case_requirements WHERE tenant_id = ? AND case_id IN (' . self::in($caseIds) . ')', $caseIds, true) : [];
            $caseDocIds = $this->ids(
                'SELECT id FROM case_documents WHERE tenant_id = ? AND (holder_id = ?' . ($caseIds ? ' OR case_id IN (' . self::in($caseIds) . ')' : '') . ')',
                array_merge([$holderId], $caseIds), true);

            // 1) Agency credentialing case graph (no DB FKs; ownership enforced in repositories).
            if ($reqIds)     $this->del('case_requirement_evidence', 'case_requirement_id IN (' . self::in($reqIds) . ')', $reqIds);
            if ($caseDocIds) {
                $this->del('case_requirement_evidence', 'case_document_id IN (' . self::in($caseDocIds) . ')', $caseDocIds);
                $this->collectFiles('case_documents', $caseDocIds);
                $this->del('case_documents', 'id IN (' . self::in($caseDocIds) . ')', $caseDocIds);
            }
            if ($caseIds) {
                $this->del('credentialing_case_requirements', 'case_id IN (' . self::in($caseIds) . ')', $caseIds);
            }

            // 2) Credentials and everything hanging off them.
            if ($credIds) $this->purgeCredentialChildren($credIds);

            // 3) Holder-owned tables (children of credentials already gone).
            foreach (self::HOLDER_TABLES as $table) { $this->del($table, 'holder_id = ?', [$holderId]); }
            if ($credIds) $this->del('credentials', 'id IN (' . self::in($credIds) . ')', $credIds);
            if ($caseIds) $this->del('credentialing_cases', 'id IN (' . self::in($caseIds) . ')', $caseIds);

            // 4) Polymorphic references (keyed by the holder's UUID).
            $this->del('addresses', "owner_type = 'provider' AND owner_id = ?", [$holderId]);
            $this->del('custom_field_values', 'record_id = ?', [$holderId]);
            $this->del('entity_reminders', 'entity_id = ?', [$holderId]);
            $this->del('notifications', 'entity_id = ?', [$holderId]);

            // 5) Sharing: revoke (keep rows so access logs keep their context), never delete.
            $now = utc_now();
            $this->exec('UPDATE external_shares SET revoked_at = ? WHERE tenant_id = ? AND holder_id = ? AND revoked_at IS NULL', [$now, $t, $holderId]);
            $this->exec('UPDATE share_links SET revoked_at = ? WHERE tenant_id = ? AND holder_id = ? AND revoked_at IS NULL', [$now, $t, $holderId]);
            $this->revokeCrossLinks($holderId, $now);

            // 6) Release suspended portal-only accounts (active/staff links were refused by holderBlockers).
            $this->exec("UPDATE users SET holder_id = NULL WHERE tenant_id = ? AND holder_id = ? AND status = 'suspended' AND role = 'producer' AND platform_admin = 0", [$t, $holderId]);

            // 7) The holder itself (audited by the repository, inside the transaction).
            if (!(new Repository\HolderRepository($this->ctx))->purge($holderId)) {
                throw new \RuntimeException('holder row not removed');
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->filesToRemove = [];
            flx_log_error('error', 'holder purge failed: ' . $e->getMessage(), ['holder' => $holderId]);
            return [false, 'Could not permanently delete. The operation was rolled back — nothing was removed.'];
        }

        $failed = $this->removeCollectedFiles();
        $name = trim(($holder['org_name'] ?? '') ?: trim(($holder['first_name'] ?? '') . ' ' . ($holder['last_name'] ?? ''))) ?: 'record';
        audit_log($this->ctx, 'provider_purged', 'holders', $holderId, json_encode(['name' => $name, 'files_not_removed' => $failed]));
        return [true, 'Record permanently deleted.' . ($failed ? " ($failed stored file(s) could not be removed from disk and were logged.)" : '')];
    }

    // =====================================================================================================
    // Credential
    // =====================================================================================================

    /** @return array{0:bool,1:string} */
    public function purgeCredential(string $credentialId): array
    {
        $cred = $this->row('SELECT id, holder_id FROM credentials WHERE id = ? AND tenant_id = ? AND deleted_at IS NOT NULL', [$credentialId]);
        if (!$cred) return [false, 'Delete the credential first, then purge.'];

        $this->filesToRemove = [];
        try {
            $this->pdo->beginTransaction();
            $this->purgeCredentialChildren([$credentialId]);   // includes FK children (docs, reminders, renewal tasks)
            if (!(new Repository\CredentialRepository($this->ctx))->purge($credentialId)) {
                throw new \RuntimeException('credential row not removed');
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->filesToRemove = [];
            flx_log_error('error', 'credential purge failed: ' . $e->getMessage(), ['credential' => $credentialId]);
            return [false, 'Could not permanently delete. The operation was rolled back — nothing was removed.'];
        }
        $failed = $this->removeCollectedFiles();
        audit_log($this->ctx, 'credential_purged', 'credentials', $credentialId, json_encode(['holder_id' => $cred['holder_id'], 'files_not_removed' => $failed]));
        return [true, 'Credential permanently deleted.' . ($failed ? " ($failed stored file(s) could not be removed from disk and were logged.)" : '')];
    }

    /** Everything that references credential ids (FK children first). Does NOT delete the credentials. */
    private function purgeCredentialChildren(array $credIds): void
    {
        $in = self::in($credIds);
        $docIds = $this->ids("SELECT id FROM credential_documents WHERE tenant_id = ? AND credential_id IN ($in)", $credIds);

        $this->del('case_requirement_evidence', "credential_id IN ($in)", $credIds);
        if ($docIds) {
            $this->del('case_requirement_evidence', 'credential_document_id IN (' . self::in($docIds) . ')', $docIds);
            $this->collectFiles('credential_documents', $docIds);
            $this->del('calendar_events', "source = 'verification' AND source_ref IN (" . self::in($docIds) . ')', $docIds);
        }
        $this->del('credential_documents', "credential_id IN ($in)", $credIds);       // FK fk_doc_cred
        $this->del('credential_reminders', "credential_id IN ($in)", $credIds);       // FK fk_rem_cred
        $this->del('renewal_tasks', "credential_id IN ($in)", $credIds);              // FK fk_renewal_cred
        $this->del('credential_verifications', "credential_id IN ($in)", $credIds);
        $this->del('source_verifications', "credential_id IN ($in)", $credIds);
        $this->del('document_requests', "credential_id IN ($in)", $credIds);
        $this->del('calendar_events', "source = 'credential' AND source_ref IN ($in)", $credIds);   // auto-synced expiry events
        $this->del('entity_reminders', "entity_id IN ($in)", $credIds);
        $this->del('notifications', "entity_id IN ($in)", $credIds);
        // Cross-org shares of these credentials: revoke (owner side), keep rows for the access log.
        $this->exec("UPDATE credential_shares SET revoked_at = ? WHERE owner_tenant_id = ? AND credential_id IN ($in) AND revoked_at IS NULL",
            array_merge([utc_now(), $this->ctx->tenantId], $credIds), true);
    }

    // =====================================================================================================
    // helpers
    // =====================================================================================================

    private function linkedUsers(string $holderId): array
    {
        $st = $this->pdo->prepare('SELECT id, email, role, status, platform_admin FROM users WHERE tenant_id = ? AND holder_id = ?');
        $st->execute([$this->ctx->tenantId, $holderId]);
        return $st->fetchAll();
    }

    /** A link may be released during purge only for a suspended, portal-only, non-platform account. */
    private static function userLinkIsReleasable(array $u): bool
    {
        return ($u['status'] ?? '') === 'suspended' && ($u['role'] ?? '') === 'producer' && (int)($u['platform_admin'] ?? 0) === 0;
    }

    private function revokeCrossLinks(string $holderId, string $now): void
    {
        $t = $this->ctx->tenantId;
        $links = $this->ids(
            "SELECT id FROM provider_links WHERE status IN ('pending_provider','pending_owner','active')
               AND ((requester_tenant_id = ? AND requester_holder_id = ?) OR (owner_tenant_id = ? AND owner_holder_id = ?))",
            [$t, $holderId, $t, $holderId], true, false);
        if (!$links) return;
        $in = self::in($links);
        $this->exec("UPDATE credential_shares SET revoked_at = ? WHERE link_id IN ($in) AND revoked_at IS NULL", array_merge([$now], $links), true);
        $this->exec("UPDATE provider_links SET status = 'revoked', revoked_at = ?,
                        revoked_by_party = CASE WHEN owner_tenant_id = ? THEN 'owner' ELSE 'requester' END,
                        revoked_reason = 'Provider record permanently deleted by the organization'
                     WHERE id IN ($in)", array_merge([$now, $t], $links), true);
    }

    /** DELETE … WHERE tenant_id = ? AND <where>. Missing table/column on older installs is skipped. */
    private function del(string $table, string $where, array $params): void
    {
        $this->exec("DELETE FROM {$table} WHERE tenant_id = ? AND {$where}", array_merge([$this->ctx->tenantId], $params), true);
    }

    private function exec(string $sql, array $params, bool $tolerateMissing = false): void
    {
        try {
            $this->pdo->prepare($sql)->execute($params);
        } catch (\PDOException $e) {
            if ($tolerateMissing && self::isMissingSchema($e)) return;
            throw $e;
        }
    }

    private function countWhere(string $table, string $where, array $params): int
    {
        try {
            $st = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE tenant_id = ? AND {$where}");
            $st->execute(array_merge([$this->ctx->tenantId], $params));
            return (int) $st->fetchColumn();
        } catch (\PDOException $e) {
            if (self::isMissingSchema($e)) return 0;
            throw $e;
        }
    }

    /**
     * Run a single-column SELECT. By default the tenant id is prepended to $params (queries start with
     * "tenant_id = ?"); pass $prependTenant=false when $params is already the full list.
     */
    private function ids(string $sql, array $params, bool $tolerateMissing = false, bool $prependTenant = true): array
    {
        try {
            $st = $this->pdo->prepare($sql);
            $st->execute($prependTenant ? array_merge([$this->ctx->tenantId], $params) : $params);
            return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (\PDOException $e) {
            if ($tolerateMissing && self::isMissingSchema($e)) return [];
            throw $e;
        }
    }

    private function row(string $sql, array $params): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute(array_merge($params, [$this->ctx->tenantId]));
        return $st->fetch() ?: null;
    }

    private function collectFiles(string $table, array $ids): void
    {
        $st = $this->pdo->prepare("SELECT file_path FROM {$table} WHERE tenant_id = ? AND id IN (" . self::in($ids) . ')');
        $st->execute(array_merge([$this->ctx->tenantId], $ids));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) { if ($p) $this->filesToRemove[] = (string) $p; }
    }

    /** Unlink collected files that resolve inside this tenant's storage dir. Returns count NOT removed. */
    private function removeCollectedFiles(): int
    {
        $base = realpath(rtrim((string) config('docs_path'), '/') . '/' . $this->ctx->tenantId);
        $failed = 0;
        foreach (array_unique($this->filesToRemove) as $p) {
            $real = realpath($p);
            if ($real === false) continue;                                             // already gone
            if ($base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_file($real) || !@unlink($real)) {
                $failed++;
                flx_log_error('warning', 'purge: stored file not removed', ['file' => basename($p)]);
            }
        }
        $this->filesToRemove = [];
        return $failed;
    }

    private static function in(array $ids): string { return implode(',', array_fill(0, count($ids), '?')); }

    /** 42S02 = unknown table, 42S22 = unknown column (older installs). */
    private static function isMissingSchema(\PDOException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        return $state === '42S02' || $state === '42S22';
    }
}
