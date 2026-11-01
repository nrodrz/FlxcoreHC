<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */

declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Vertical-aware vocabulary. The same underlying record is called different things depending on the
         * organization's line of work: a billing company tracks "providers" and "payers"; other
 * billing company tracks "providers" and "payers". Rather than fork the UI, the app looks the right
 * noun up here from the tenant's vertical.
 *
 * This is presentation only — it never changes routes, capabilities, data, or behavior. Unknown
 * verticals fall back to neutral wording so nothing ever renders blank.
 */
final class Vocab
{
    /** term key => [healthcare, custom/fallback] */
    private const TERMS = [
        'holder'        => ['Provider',   'Record'],
        'holders'       => ['Providers',  'Records'],
        'holder_lc'     => ['provider',   'record'],
        'holders_lc'    => ['providers',  'records'],
        'partner'       => ['Payer',      'Partner'],
        'partners'      => ['Payers',     'Partners'],
        'partner_lc'    => ['payer',      'partner'],
        'partners_lc'   => ['payers',     'partners'],
        'book'          => ['Panel',      'Portfolio'],
        'roster'        => ['Provider roster', 'Roster'],
    ];

    private static function col(string $vertical): int
    {
        return 0;   // v1.170.7 — healthcare-only product: every tenant uses healthcare vocabulary
    }

    /** Look up a term for a vertical. Falls back to the key itself if unknown. */
    public static function term(string $key, string $vertical): string
    {
        if (!isset(self::TERMS[$key])) return $key;
        return self::TERMS[$key][self::col($vertical)];
    }

    /**
     * The valid "applies to" values for a template in a given vertical, as code => label.
     * Healthcare templates target provider types (physician/nurse/lab/clinic/group).
     * This is the single source of truth for BOTH the picker labels and the server-side whitelist,
     * so the two can never drift.
     */
    public static function appliesToOptions(string $vertical): array
    {
        return match ($vertical) {
            'healthcare' => [
                // Individual professionals
                'physician'    => 'Physician (MD/DO)',
                'np'           => 'Nurse practitioner',
                'pa'           => 'Physician assistant',
                'nurse'        => 'Nurse (RN/LPN)',
                'dentist'      => 'Dentist',
                'pharmacist'   => 'Pharmacist',
                'therapist'    => 'Therapist (PT/OT/SLP)',
                'behavioral'   => 'Behavioral health (Psychologist/LCSW/LMHC)',
                'chiropractor' => 'Chiropractor',
                'technologist' => 'Technologist / Technician',
                'other_pro'    => 'Other licensed professional',
                // Organizations / entities
                'lab'          => 'Laboratory',
                'clinic'       => 'Clinic / Facility',
                'group'        => 'Medical group',
            ],
            default => [
                'individual'   => 'Individual',
                'organization' => 'Organization',
            ],
        };
    }

    /**
     * The valid "line of business" values for a vertical, as code => label. Healthcare's only
     * meaningful axis is care setting — kept deliberately minimal ('all' plus outpatient/facility).
     */
    public static function lineOptions(string $vertical): array
    {
        return match ($vertical) {
            'healthcare' => [
                'all'        => 'Any setting',
                'outpatient' => 'Outpatient / clinic',
                'facility'   => 'Facility / hospital',
            ],
            default => ['all' => 'Any'],
        };
    }

    /** Convenience: resolve straight from a context. */
    public static function of(TenantContext $ctx, string $key): string
    {
        return self::term($key, $ctx->vertical);
    }
}
