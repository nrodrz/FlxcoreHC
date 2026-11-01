<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Provider consent ("security clearance") ledger.
 *
 * Tenants never share data at the database level — two tenants tracking the same real-world provider
 * hold fully independent encrypted records, and nothing here changes that. When information must
 * cross the tenant boundary (another organization, a payer, a hospital), the provider authorizes it,
 * and this ledger records the clearance: who granted what scope to whom, from when to when, how the
 * consent was captured, and whether it was revoked. Share links (read-only, expiring, revocable
 * views) are the transport; a consent is the authority behind them.
 *
 * The ledger answers the audit question "on whose authority was this shared?" for every disclosure.
 */
final class ConsentService
{
    public const SCOPES  = ['summary' => 'Summary (status only)', 'full' => 'Full credential file'];
    public const METHODS = ['written' => 'Written authorization on file', 'portal' => 'Granted by provider in portal', 'verbal_documented' => 'Verbal, documented'];

    public function __construct(private readonly TenantContext $ctx) {}

    /** Record a clearance. Returns the consent id. */
    public function record(string $holderId, string $grantedTo, string $scope, string $method, ?string $expiresAt, ?string $note): string
    {
        $scope  = isset(self::SCOPES[$scope]) ? $scope : 'summary';
        $method = isset(self::METHODS[$method]) ? $method : 'written';
        $id = uuid4();
        Database::pdo()->prepare(
            'INSERT INTO provider_consents (id, tenant_id, holder_id, granted_to, scope, method, granted_at, expires_at, recorded_by, note)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([$id, $this->ctx->tenantId, $holderId, $grantedTo, $scope, $method, utc_now(), $expiresAt, $this->ctx->userId, $note]);
        return $id;
    }

    /** Revoke a clearance (it stays in the ledger, marked revoked). */
    public function revoke(string $consentId): bool
    {
        $st = Database::pdo()->prepare(
            'UPDATE provider_consents SET revoked_at = ? WHERE id = ? AND tenant_id = ? AND revoked_at IS NULL'
        );
        $st->execute([utc_now(), $consentId, $this->ctx->tenantId]);
        return $st->rowCount() > 0;
    }

    /** All consents for a provider, newest first (active and historical — the ledger is append-only). */
    public function forHolder(string $holderId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT * FROM provider_consents WHERE tenant_id = ? AND holder_id = ? ORDER BY granted_at DESC LIMIT 100'
        );
        $st->execute([$this->ctx->tenantId, $holderId]);
        return $st->fetchAll();
    }

    /** Is there an active (unrevoked, unexpired) clearance covering this scope for this provider? */
    public function activeFor(string $holderId, string $scope = 'summary'): ?array
    {
        $st = Database::pdo()->prepare(
            "SELECT * FROM provider_consents
              WHERE tenant_id = ? AND holder_id = ? AND revoked_at IS NULL
                AND (expires_at IS NULL OR expires_at > ?)
                AND (scope = ? OR scope = 'full')
              ORDER BY granted_at DESC LIMIT 1"
        );
        $st->execute([$this->ctx->tenantId, $holderId, utc_now(), $scope]);
        $row = $st->fetch();
        return $row ?: null;
    }
}
