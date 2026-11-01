<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC\Repository;

/**
 * Regulators: every entity a provider or facility must satisfy — payers (Aetna, Medicare),
 * government agencies (CMS, FDA, CDC), regulatory frameworks (HIPAA, OSHA), accreditation
 * bodies (The Joint Commission), and state boards, across US and Puerto Rico jurisdictions.
 */
final class RegulatorRepository extends TenantRepository
{
    public const CATEGORIES = [
        'payer'         => 'Payer / Insurance company',
        'government'    => 'Government agency',
        'regulation'    => 'Regulation / Framework',
        'accreditation' => 'Accreditation body',
        'state_board'   => 'State board',
        // v1.171.5 — categories used by the seeded boards/agencies (Puerto Rico and others); they were stored but never listed.
        'licensing'         => 'Licensing board',
        'insurance'         => 'Insurance regulator',
        'medicaid'          => 'Medicaid / program agency',
        'behavioral_health' => 'Behavioral-health agency',
    ];

    protected function table(): string { return 'regulators'; }

    protected function fillable(): array { return ['name', 'category', 'jurisdiction', 'short_name', 'status', 'is_custom']; }

    public function active(): array
    {
        return $this->all(['status' => 'active'], 'category ASC, name ASC');
    }

    /** category => [rows], active only, for grouped displays. */
    public function activeByCategory(): array
    {
        $out = [];
        foreach ($this->active() as $r) { $out[$r['category'] ?? 'payer'][] = $r; }
        return $out;
    }
}
