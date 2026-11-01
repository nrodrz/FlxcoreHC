<?php
declare(strict_types=1);

namespace FlxCoreHC\Repository;

use FlxCoreHC\SupportAccess;

/**
 * Office contacts for a provider (office manager, credentialing coordinator, billing contact…)
 * who help keep the record current without needing a portal login. Email encrypted like the
 * provider's own. Flags decide whether document-request nudges and reminders go to them.
 */
final class ProviderContactRepository extends TenantRepository
{
    protected function table(): string { return 'provider_contacts'; }
    protected function providerColumn(): ?string { return 'holder_id'; }   // need-to-know scope (v1.170.9)

    protected function fillable(): array
    {
        return ['holder_id','full_name','role_title','email','email_enc','email_bidx','phone','preferred_language',
                'gets_document_requests','gets_reminders','is_primary','notes'];
    }

    protected function encryptedColumns(): array { return ['email' => 'email_enc']; }
    protected function blindIndexColumns(): array { return ['email' => 'email_bidx']; }
    protected function softDeletes(): bool { return true; }

    public function find(string $id): ?array
    {
        if (SupportAccess::active()) return null;
        return parent::find($id);
    }

    public function all(array $where = [], string $orderBy = 'is_primary DESC, full_name ASC', int $limit = 500): array
    {
        if (SupportAccess::active()) return [];
        return parent::all($where, $orderBy, $limit);
    }

    public function forHolder(string $holderId): array
    {
        return $this->all(['holder_id' => $holderId]);
    }

    /** Emails of contacts who should receive a given kind of message ('requests' | 'reminders'). */
    public function recipientsFor(string $holderId, string $kind): array
    {
        $flag = $kind === 'reminders' ? 'gets_reminders' : 'gets_document_requests';
        $out = [];
        foreach ($this->forHolder($holderId) as $c) {
            if (!empty($c[$flag]) && !empty($c['email'])) $out[] = ['email' => $c['email'], 'name' => $c['full_name'], 'locale' => $c['preferred_language'] ?: null];
        }
        return $out;
    }
}
