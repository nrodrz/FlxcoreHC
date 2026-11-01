<?php
declare(strict_types=1);

namespace FlxCoreHC\Repository;

/**
 * Case/provider documents that are NOT tied to a specific credential (CV, W-9, application form).
 * credential_documents remains the store for credential-tied documents (its credential_id stays
 * NOT NULL, unchanged). This is a focused evidence store, not a second credential-document system.
 */
final class CaseDocumentRepository extends TenantRepository
{
    protected function table(): string { return 'case_documents'; }
    protected function providerColumn(): ?string { return 'holder_id'; }   // need-to-know scope (v1.170.9)

    protected function fillable(): array
    {
        return ['case_id','holder_id','doc_type','file_path','original_name','mime_type','byte_size',
                'checksum','expires_on','uploaded_by','notes'];
    }

    protected function softDeletes(): bool { return true; }

    public function forCase(string $caseId): array
    {
        return $this->all(['case_id' => $caseId], 'created_at DESC', 500);
    }

    public function forHolder(string $holderId): array
    {
        return $this->all(['holder_id' => $holderId], 'created_at DESC', 500);
    }
}
