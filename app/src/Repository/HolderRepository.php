<?php
declare(strict_types=1);

namespace FlxCoreHC\Repository;

use FlxCoreHC\Database;

final class HolderRepository extends TenantRepository
{
    protected function table(): string { return 'credential_holders'; }
    protected function providerColumn(): ?string { return 'id'; }   // need-to-know scope (v1.170.9)

    protected function fillable(): array
    {
        return ['holder_type','provider_type','first_name','middle_name','last_name','suffix','prior_names','gender','languages','primary_specialty','secondary_specialty','taxonomy_code','caqh_id','medicare_ptan','medicaid_id','upin','accepting_new_patients','hospital_affiliations','org_name','npi','npn','birth_month','license_code','template_id',
                'email','department','role_title','vertical','status','telehealth_enabled',
                'npi_verified_at','npi_verified_by','npi_legal_name','npi_entity_type','npi_credential','npi_taxonomy','npi_city','npi_state',
                'npi_enc','npi_bidx','npn_enc','npn_bidx','email_enc','dob','ssn_last4','dob_enc','ssn_last4_enc'];
    }

    protected function encryptedColumns(): array
    {
        return ['npi' => 'npi_enc', 'npn' => 'npn_enc', 'email' => 'email_enc', 'dob' => 'dob_enc', 'ssn_last4' => 'ssn_last4_enc'];
    }

    protected function blindIndexColumns(): array
    {
        return ['npi' => 'npi_bidx', 'npn' => 'npn_bidx'];
    }

    // ---- Need-to-know enforcement (provider ↔ employee assignments) --------------
    // A user whose provider_access is 'assigned' can only resolve their assigned providers.
    // Enforced here — the same layer as tenant scoping — so it covers the provider list, detail
    // pages, the compliance engine, and anything else that resolves holders through this repo.

    /** {@inheritdoc} Provider scope is applied in SQL by the base repository (providerColumn = 'id'). */
    public function find(string $id): ?array
    {
        if (\FlxCoreHC\SupportAccess::active()) return null;   // support mode: PHI invisible
        return parent::find($id);
    }

    /** {@inheritdoc} Restricted users get only their assigned providers — scoped in SQL, before LIMIT. */
    public function all(array $where = [], string $orderBy = 'created_at DESC', int $limit = 500): array
    {
        if (\FlxCoreHC\SupportAccess::active()) return [];      // support mode: PHI invisible
        return parent::all($where, $orderBy, $limit);
    }


    /** Find a holder by NPI/NPN using the blind index (no full-table decrypt). */
    public function findByNpi(string $npi): ?array
    {
        return $this->findByBlindIndex('npi_bidx', $npi);
    }
    public function findByNpn(string $npn): ?array
    {
        return $this->findByBlindIndex('npn_bidx', $npn);
    }
    private function findByBlindIndex(string $bidxCol, string $value): ?array
    {
        $bidx = \FlxCoreHC\Crypto::blindIndex($value);
        if ($bidx === null) return null;
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM credential_holders WHERE tenant_id = ? AND {$bidxCol} = ? AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([$this->ctx->tenantId, $bidx]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->decryptRow($row);
    }

    /** Holders with credential counts and the soonest expiry, for the list view. */
    public function withCredentialSummary(): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT h.*,
                    COUNT(c.id)                                            AS credential_count,
                    SUM(c.status = 'expiring')                             AS expiring_count,
                    SUM(c.status = 'expired')                              AS expired_count,
                    MIN(CASE WHEN c.expiry_date >= @flx_today THEN c.expiry_date END) AS next_expiry
             FROM credential_holders h
             LEFT JOIN credentials c ON c.holder_id = h.id AND c.tenant_id = h.tenant_id AND c.deleted_at IS NULL
             WHERE h.tenant_id = ? AND h.deleted_at IS NULL{$this->scopeSql('h')[0]}
             GROUP BY h.id
             ORDER BY h.holder_type DESC, h.org_name, h.last_name, h.first_name"
        );
        if (\FlxCoreHC\SupportAccess::active()) return [];      // support mode: PHI invisible
        $stmt->execute(array_merge([$this->ctx->tenantId], $this->scopeSql('h')[1]));   // v1.170.9: provider scope in SQL
        return $this->decryptRows($stmt->fetchAll());
    }

    /** Soft-deleted providers/organizations, for the recovery (trash) screen. Decrypts names like all(). */
    public function trashed(int $limit = 500): array
    {
        if (\FlxCoreHC\SupportAccess::active()) return [];
        $stmt = Database::pdo()->prepare(
            "SELECT h.*, COUNT(c.id) AS credential_count
             FROM credential_holders h
             LEFT JOIN credentials c ON c.holder_id = h.id AND c.tenant_id = h.tenant_id AND c.deleted_at IS NULL
             WHERE h.tenant_id = ? AND h.deleted_at IS NOT NULL{$this->scopeSql('h')[0]}
             GROUP BY h.id
             ORDER BY h.deleted_at DESC
             LIMIT " . max(1, min($limit, 2000))
        );
        $stmt->execute(array_merge([$this->ctx->tenantId], $this->scopeSql('h')[1]));
        return $this->decryptRows($stmt->fetchAll());
    }

    /**
     * Persist a CMS NPPES verification snapshot onto a producer. Stores the CMS-owned fields
     * (legal name, entity type, taxonomy, city/state, credential) and stamps who verified when.
     * Only these fields are written — agency-owned fields (role_title, npn, email…) are never
     * touched, so a re-verification can never overwrite what a manager typed.
     */
    public function saveNpiVerification(string $holderId, array $npi, string $userId): bool
    {
        // npi is an encrypted column, so route it through update() (which encrypts + blind-indexes).
        // The snapshot fields are plain columns and can go in the same call.
        return $this->update($holderId, [
            'npi'             => $npi['npi'] ?? null,
            'npi_verified_at' => utc_now(),
            'npi_verified_by' => $userId,
            'npi_legal_name'  => $npi['name'] ?? null,
            'npi_entity_type' => $npi['entity_type'] ?? null,
            'npi_credential'  => $npi['credential'] ?? null,
            'npi_taxonomy'    => $npi['taxonomy'] ?? null,
            'npi_city'        => $npi['city'] ?? null,
            'npi_state'       => $npi['state'] ?? null,
        ]);
    }

    public static function displayName(array $h): string
    {
        return $h['holder_type'] === 'organization'
            ? (string) $h['org_name']
            : trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? ''));
    }
}
