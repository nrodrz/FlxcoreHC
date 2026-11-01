<?php
declare(strict_types=1);

namespace FlxCoreHC\Repository;

/**
 * Credentialing Case — the operational unit of Agency Credentialing. Orchestrates existing entities;
 * never replaces the provider master (credential_holders). Tenant-local: extends TenantRepository so
 * every read/write is scoped by tenant_id automatically. One provider → many cases.
 */
final class CredentialingCaseRepository extends TenantRepository
{
    public const TYPES = [
        'initial_credentialing' => 'Initial credentialing', 'recredentialing' => 'Recredentialing',
        'payer_enrollment' => 'Payer enrollment', 'payer_revalidation' => 'Payer revalidation',
        'provider_update' => 'Provider update', 'other' => 'Other',
    ];
    public const STATUSES = [
        'open' => 'Open', 'in_progress' => 'In progress', 'ready_for_submission' => 'Ready for submission',
        'submitted' => 'Submitted', 'completed' => 'Completed', 'closed' => 'Closed', 'cancelled' => 'Cancelled',
    ];

    protected function table(): string { return 'credentialing_cases'; }
    protected function providerColumn(): ?string { return 'holder_id'; }   // need-to-know scope (v1.170.9)

    protected function fillable(): array
    {
        return ['holder_id','client_group_id','location_id','payer_id','case_type','status','priority',
                'external_reference','opened_at','target_date','submitted_at','completed_at','closed_at',
                'assigned_to','notes','created_by'];
    }

    protected function softDeletes(): bool { return true; }

    /** Cases for one provider, newest first. */
    public function forHolder(string $holderId, int $limit = 200): array
    {
        return $this->all(['holder_id' => $holderId], 'opened_at DESC', $limit);
    }

    /** Cases assigned to a user (work-queue helper). */
    public function assignedTo(string $userId, int $limit = 200): array
    {
        return $this->all(['assigned_to' => $userId], 'target_date IS NULL, target_date ASC', $limit);
    }
}
