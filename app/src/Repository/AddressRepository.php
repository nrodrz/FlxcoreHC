<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC\Repository;

use FlxCoreHC\SupportAccess;

/**
 * Addresses for a tenant or a provider — any number, each with a kind. Distinct from locations
 * (practice sites). A credential may reference the address on file with its issuer/payer.
 */
final class AddressRepository extends TenantRepository
{
    public const KINDS = [
        'mailing' => 'Mailing', 'billing' => 'Billing', 'correspondence' => 'Correspondence',
        'service' => 'Service location', 'legal' => 'Legal / registered', 'home' => 'Home', 'other' => 'Other',
    ];

    protected function table(): string { return 'addresses'; }

    protected function fillable(): array
    {
        return ['owner_type','owner_id','kind','label','line1','line2','line1_enc','line2_enc','phone_enc','city','state','zip','country','phone','fax',
                'is_primary','effective_from','effective_to','notes'];
    }

    protected function softDeletes(): bool { return true; }

    /** v1.171.3 — street lines and phone are personal data (provider home addresses): encrypted at rest. */
    protected function encryptedColumns(): array
    {
        return ['line1' => 'line1_enc', 'line2' => 'line2_enc', 'phone' => 'phone_enc'];
    }

    /** Ciphertext columns are written only by the repository itself — never from caller-supplied data. */
    private static function noCipher(array $d): array { unset($d['line1_enc'], $d['line2_enc'], $d['phone_enc']); return $d; }

    public function insert(array $data): string
    {
        $data = self::noCipher($data);
        $id = parent::insert($data);
        $this->scrubPlain($id);
        return $id;
    }

    public function update(string $id, array $data): bool
    {
        $ok = parent::update($id, self::noCipher($data));
        if ($ok) $this->scrubPlain($id);
        return $ok;
    }

    /** Once the encrypted copy exists, no legacy plaintext copy may remain at rest. */
    private function scrubPlain(string $id): void
    {
        \FlxCoreHC\Database::pdo()->prepare(
            'UPDATE addresses SET line1 = IF(line1_enc IS NOT NULL, NULL, line1), line2 = IF(line2_enc IS NOT NULL, NULL, line2), phone = IF(phone_enc IS NOT NULL, NULL, phone) WHERE id = ? AND tenant_id = ?'
        )->execute([$id, $this->ctx->tenantId]);
    }

    public function find(string $id): ?array
    {
        if (SupportAccess::active()) return null;
        return parent::find($id);
    }

    public function all(array $where = [], string $orderBy = 'is_primary DESC, kind ASC, created_at ASC', int $limit = 500): array
    {
        if (SupportAccess::active()) return [];
        return parent::all($where, $orderBy, $limit);
    }

    /** Addresses for one owner (tenant or provider). */
    public function forOwner(string $ownerType, string $ownerId): array
    {
        return $this->all(['owner_type' => $ownerType, 'owner_id' => $ownerId]);
    }

    /** Mark one address primary for its owner (and clear the others). */
    public function setPrimary(string $id): void
    {
        $a = $this->find($id); if (!$a) return;
        \FlxCoreHC\Database::pdo()->prepare('UPDATE addresses SET is_primary = 0 WHERE tenant_id = ? AND owner_type = ? AND owner_id = ?')
            ->execute([$this->ctx->tenantId, $a['owner_type'], $a['owner_id']]);
        $this->update($id, ['is_primary' => 1]);
    }

    /** One-line rendering for pickers and packets. */
    public static function oneLine(array $a): string
    {
        $parts = array_filter([$a['line1'] ?? '', $a['line2'] ?? '', trim(($a['city'] ?? '') . ', ' . ($a['state'] ?? '') . ' ' . ($a['zip'] ?? ''))]);
        return implode(', ', $parts);
    }
}
