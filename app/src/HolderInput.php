<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * v1.171.0 (B-1) — the ONE place that turns a provider form POST into repository fields.
 *
 * Before this, four routes (add, edit, onboarding, provider self-service) each carried their own copy of the
 * provider-type whitelist and their own field list. The edit route accepted only 5 of the 14 types, so saving
 * a PA or NP silently set provider_type to NULL, and it never persisted the "Application data" block that the
 * same form renders (specialty, CAQH, PTAN, taxonomy, …). One list, one field map, one round-trip test.
 */
final class HolderInput
{
    /** Individual professional types (single source: Vocab). */
    public static function individualTypes(): array
    {
        return array_values(array_diff(array_keys(Vocab::appliesToOptions('healthcare')), self::orgTypes()));
    }

    /** Organization / entity types. */
    public static function orgTypes(): array { return ['lab', 'clinic', 'group']; }

    public static function allTypes(): array { return array_keys(Vocab::appliesToOptions('healthcare')); }

    /**
     * Resolve the submitted provider type. Returns [set(bool), value|null]:
     *   - not submitted            → [false, null]  (leave the stored value alone)
     *   - submitted, blank         → [true,  null]  (user chose "Not specified")
     *   - submitted, valid for the record type → [true, $value]
     *   - submitted, invalid       → [false, null]  (never wipe a stored value because of a bad/foreign option)
     */
    public static function providerType(array $post, string $holderType, string $key = 'provider_type'): array
    {
        if (!array_key_exists($key, $post)) return [false, null];
        $v = trim((string) $post[$key]);
        if ($v === '') return [true, null];
        $valid = $holderType === 'organization' ? self::orgTypes() : self::individualTypes();
        return in_array($v, $valid, true) ? [true, $v] : [false, null];
    }

    /** v1.171.8/9: blank = nothing a person can see (delegates to the shared TextNorm rule). */
    public static function blank(string $v): bool { return TextNorm::blank($v); }

    /** Application fields that only make sense for a person. */
    public const PERSON_ONLY = ['middle_name', 'suffix', 'prior_names', 'gender', 'languages'];

    /** Free-text application fields → max length (matches the columns and the onboarding step). */
    public const APPLICATION_TEXT = [
        'middle_name' => 80, 'suffix' => 12, 'prior_names' => 200, 'gender' => 12, 'languages' => 120,
        'primary_specialty' => 120, 'secondary_specialty' => 120, 'taxonomy_code' => 12, 'caqh_id' => 20,
        'medicare_ptan' => 20, 'medicaid_id' => 20, 'upin' => 12, 'hospital_affiliations' => 300,
    ];

    /**
     * Build the writable field set from a POST. Fields the form did not submit are NOT touched (a portal
     * provider's form has no "Application data" block; it must not blank those columns).
     * Returns [fields, error|null].
     */
    public static function fromPost(TenantContext $ctx, array $post, string $holderType, bool $creating): array
    {
        $txt = static fn(string $k, int $max): ?string => mb_substr(trim((string) ($post[$k] ?? '')), 0, $max) ?: null;
        // v1.171.9: names are stored cleaned (invisible characters removed, spacing collapsed) — TextNorm is the one rule.
        $nm  = static fn(string $k, int $max): ?string => mb_substr(TextNorm::clean((string) ($post[$k] ?? '')), 0, $max) ?: null;
        $f = [];
        $isOrg = $holderType === 'organization';
        // v1.172.0: a person record never carries an organization name and the reverse, whatever the request contains.
        $nameFields = $isOrg ? ['org_name' => 160] : ['first_name' => 80, 'last_name' => 80];
        foreach ($nameFields as $k => $max) {
            if ($creating || array_key_exists($k, $post)) $f[$k] = $nm($k, $max);
        }
        foreach ($isOrg ? ['email' => 190] : ['email' => 190, 'role_title' => 120] as $k => $max) {
            if ($creating || array_key_exists($k, $post)) $f[$k] = $txt($k, $max);
        }
        // v1.171.6/9: neither create nor edit may leave the record without an identity, or save a malformed e-mail.
        // On create every name field is required; on edit only the fields the request carries are checked.
        $nameKeys = $isOrg ? ['org_name'] : ['first_name', 'last_name'];
        foreach ($nameKeys as $nk) {
            if (($creating || array_key_exists($nk, $post)) && self::blank((string) ($post[$nk] ?? ''))) return [[], $isOrg ? 'An organization name is required.' : 'First and last name are required.'];
        }
        if (array_key_exists('email', $post) && trim((string) $post['email']) !== '' && !filter_var(trim((string) $post['email']), FILTER_VALIDATE_EMAIL)) return [[], 'Enter a valid e-mail address.'];
        if (array_key_exists('npi', $post) || $creating) {
            $npi = Npi::normalize((string) ($post['npi'] ?? ''));
            if (($e = Npi::error($npi)) !== null) return [[], $e];
            $f['npi'] = $npi !== '' ? $npi : null;
        }
        if (!$isOrg && ($creating || array_key_exists('birth_month', $post))) {
            $bm = (int) ($post['birth_month'] ?? 0);
            $f['birth_month'] = ($bm >= 1 && $bm <= 12) ? $bm : null;
        }
        if ($creating || array_key_exists('license_code', $post)) $f['license_code'] = $txt('license_code', 60);
        [$set, $pt] = self::providerType($post, $holderType);
        if ($set) $f['provider_type'] = $pt;
        foreach (self::APPLICATION_TEXT as $k => $max) {
            if ($isOrg && in_array($k, self::PERSON_ONLY, true)) continue;
            if (array_key_exists($k, $post)) $f[$k] = $txt($k, $max);
        }
        if (!$isOrg && array_key_exists('accepting_new_patients', $post)) {
            $a = (string) $post['accepting_new_patients'];
            $f['accepting_new_patients'] = $a === '' ? null : (int) ($a === '1');
        }
        if (!$isOrg && Authz::can($ctx, 'pii.view')) {
            foreach (['dob', 'ssn_last4'] as $sf) {
                if (!array_key_exists($sf, $post)) continue;
                [$ok, $val, $err] = SensitiveFields::normalise($sf, (string) $post[$sf]);
                if (!$ok) return [[], (string) $err];
                $f[$sf] = $val;
            }
        }
        return [$f, null];
    }
}
