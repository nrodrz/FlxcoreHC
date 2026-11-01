<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC\Repository;

use FlxCoreHC\Database;

/** Provider attestations — signed/acknowledged statements of accuracy. */
final class AttestationRepository extends TenantRepository
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
        'portal_ack'    => 'Portal acknowledgement',
        'esign'         => 'Electronic signature',
        'wet_signature' => 'Wet signature on file',
        'verbal'        => 'Verbal (staff-recorded)',
    ];

    /** The default statement a provider attests to. */
    public const DEFAULT_STATEMENT =
        'I attest that the credentialing information and documents in my provider profile are '
        . 'accurate, complete, and current to the best of my knowledge.';

    protected function table(): string { return 'holder_attestations'; }
    protected function providerColumn(): ?string { return 'holder_id'; }   // need-to-know scope (v1.170.9)
    protected function fillable(): array
    {
        return ['holder_id', 'statement', 'method', 'attested_by', 'attested_by_user',
                'attested_on', 'ip_address', 'notes'];
    }

    /** Attestation history for a provider, newest first. */
    public function forHolder(string $holderId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM holder_attestations WHERE tenant_id = ? AND holder_id = ?{$this->scopeSql()[0]}
              ORDER BY attested_on DESC"
        );
        $stmt->execute(array_merge([$this->ctx->tenantId, $holderId], $this->scopeSql()[1]));
        return $stmt->fetchAll();
    }

    /** Most recent attestation for a provider, or null. */
    public function latestFor(string $holderId): ?array
    {
        $rows = $this->forHolder($holderId);
        return $rows[0] ?? null;
    }
}
