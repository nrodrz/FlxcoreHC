<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC\Repository;

use FlxCoreHC\Database;

/** Verification-tracking records for credentials (customer-recorded verification evidence and method; not a certification of primary-source verification). */
final class VerificationRepository extends TenantRepository
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

    public const METHODS = [
        'primary_source' => 'Primary source (issuer/registry)',
        'copy'           => 'Copy on file',
        'database'       => 'Designated database check',
        'attestation'    => 'Provider attestation',
        'other'          => 'Other',
    ];
    public const RESULTS = [
        'verified'    => 'Verified',
        'discrepancy' => 'Discrepancy found',
        'unable'      => 'Unable to verify',
        'expired'     => 'Expired at verification',
    ];

    protected function table(): string { return 'credential_verifications'; }
    protected function providerColumn(): ?string { return 'holder_id'; }   // need-to-know scope (v1.170.9)
    protected function fillable(): array
    {
        return ['credential_id', 'holder_id', 'method', 'source', 'verified_on',
                'verified_by', 'verified_by_user', 'result', 'notes'];
    }

    /** Verification history for one credential, newest first. */
    public function forCredential(string $credentialId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM credential_verifications WHERE tenant_id = ? AND credential_id = ?{$this->scopeSql()[0]}
              ORDER BY verified_on DESC, created_at DESC"
        );
        $stmt->execute(array_merge([$this->ctx->tenantId, $credentialId], $this->scopeSql()[1]));
        return $stmt->fetchAll();
    }

    /** All verifications for a provider (for the audit package), newest first. */
    public function forHolder(string $holderId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM credential_verifications WHERE tenant_id = ? AND holder_id = ?{$this->scopeSql()[0]}
              ORDER BY verified_on DESC, created_at DESC"
        );
        $stmt->execute(array_merge([$this->ctx->tenantId, $holderId], $this->scopeSql()[1]));
        return $stmt->fetchAll();
    }
}
