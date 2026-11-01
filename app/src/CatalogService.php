<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Read-only access to the global healthcare credential catalog (US / Puerto Rico)
 * and per-holder compliance coverage. The catalog is reference data shared by all tenants;
 * coverage is computed against a tenant's own credential rows only.
 */
final class CatalogService
{
    /** Regulatory rule-set this catalog reflects. Bump when rule migrations are added. */
    public const RULESET_VERSION = 'HC2026.1';
    public const RULESET_LABEL   = 'Healthcare credentialing ruleset — NPPES/PECOS (42 CFR 424.515), DEA (21 CFR 1301), CLIA (42 CFR 493), OIG/SAM exclusions, PR JLDM & Depto. de Salud, FL Board of Medicine';
    public const RULESET_UPDATED = '2026-07-10';
    public const RULESET_NOTES   = 'Healthcare regulator seed added: CMS/NPPES (45 CFR 162), Medicare revalidation (42 CFR 424.515), CLIA (42 CFR 493), DEA triennial (21 CFR 1301.13), HIPAA training/BAA (45 CFR 164), OSHA bloodborne pathogens (29 CFR 1910.1030), TJC accreditation cycle, FL Board (s. 458.319 / 64B8-13.005), PR JLDM & Depto. de Salud (20 LPRA, 24 LPRA).';

    /** Legacy/free-form credential_type values mapped to catalog codes for coverage matching.
     *  Healthcare types already match catalog codes directly; this map is intentionally empty. */
    private const SYNONYMS = [];

    public static function available(): bool
    {
        try { Database::pdo()->query("SELECT 1 FROM credential_catalog LIMIT 1"); return true; }
        catch (\Throwable $e) { return false; }
    }

    /**
     * Catalog rows, optionally limited to a role (agent|agency|fmo).
     *
     * Two tiers since v1.78: global seeded rows (tenant_id NULL, shipped by release) are visible to
     * every tenant; custom rows belong to exactly one tenant and are visible only to it. Pass the
     * current tenant id to include their custom types; passing null (the historical call shape)
     * returns the global tier only, so every pre-existing caller keeps its exact prior behavior.
     * Retired custom rows are excluded here — existing credentials referencing them still resolve,
     * they just can't be newly assigned.
     *
     * @return array<array<string,mixed>>
     */
    public static function all(?string $role = null, ?array $lines = null, ?array $states = null,
                               ?string $tenantId = null): array
    {
        if (!self::available()) return [];
        if ($tenantId !== null && $tenantId !== '') {
            // A tenant sees the global rows for its OWN vertical plus its own custom rows. The
            // `vertical` tag on seeded rows keeps insurance types out of a healthcare picker and
            // vice-versa; rows with a NULL vertical (pre-068 installs) are treated as universal.
            $vertical = self::verticalOf($tenantId);
            $st = Database::pdo()->prepare(
                "SELECT * FROM credential_catalog
                  WHERE retired_at IS NULL
                    AND ( tenant_id = ?
                          OR (tenant_id IS NULL AND (vertical IS NULL OR vertical = ?)) )
                  ORDER BY sort, name");
            $st->execute([$tenantId, $vertical]);
            $rows = $st->fetchAll();
        } else {
            $rows = Database::pdo()->query(
                "SELECT * FROM credential_catalog WHERE tenant_id IS NULL AND retired_at IS NULL
                  ORDER BY sort, name")->fetchAll();
        }
        if ($lines !== null) {
            $rows = array_values(array_filter($rows,
                fn($r) => ($r['line'] ?? 'all') === 'all' || in_array($r['line'], $lines, true)));
        }
        // State-specific items only apply where the agency actually operates.
        // A NULL state means federal / anywhere (AHIP, FWA, NPN, E&O...).
        if ($states !== null) {
            $rows = array_values(array_filter($rows,
                fn($r) => empty($r['state']) || in_array(strtoupper((string)$r['state']), $states, true)));
        }
        if ($role === null) return $rows;
        return array_values(array_filter($rows, fn($r) => in_array($role, array_map('trim', explode(',', (string)$r['applies_to'])), true)));
    }

    /** The vertical of a tenant, cached per request. Used to scope the global catalog tier. */
    private static function verticalOf(string $tenantId): string
    {
        static $cache = [];
        if (!isset($cache[$tenantId])) {
            try {
                $st = Database::pdo()->prepare('SELECT vertical FROM tenants WHERE id = ? LIMIT 1');
                $st->execute([$tenantId]);
                $cache[$tenantId] = (string) ($st->fetchColumn() ?: 'healthcare');
            } catch (\Throwable $e) { $cache[$tenantId] = 'healthcare'; }
        }
        return $cache[$tenantId];
    }

    // ---- Custom (tenant-owned) credential types --------------------------------------------

    /** Valid categories for a custom type; mirrors the seeded vocabulary. */
    public const CATEGORIES = ['license', 'certification', 'training', 'insurance', 'identifier',
                               'background', 'contract', 'appointment', 'other'];
    /** Display labels for CATEGORIES (the codes are stable; 'insurance'/'appointment' are legacy codes). */
    public const CATEGORY_LABELS = ['license' => 'License', 'certification' => 'Certification', 'training' => 'Training',
        'insurance' => 'Malpractice / liability', 'identifier' => 'Identifier', 'background' => 'Background check',
        'contract' => 'Contract', 'appointment' => 'Payer enrollment', 'other' => 'Other'];
    public static function categoryLabel(string $code): string { return self::CATEGORY_LABELS[$code] ?? ucfirst($code); }

    /**
     * code => display name for every catalog code this tenant can see, INCLUDING retired custom
     * types. Retiring a type stops new assignment (so it leaves all()/the picker), but existing
     * credentials of that type must still show their proper name rather than the raw code — this is
     * the map to use for labelling stored records, as opposed to all() which drives selection.
     */
    public static function labelsFor(?string $tenantId): array
    {
        if (!self::available()) return [];
        if ($tenantId !== null && $tenantId !== '') {
            $vertical = self::verticalOf($tenantId);
            $st = Database::pdo()->prepare(
                "SELECT code, name FROM credential_catalog
                  WHERE tenant_id = ?
                     OR (tenant_id IS NULL AND (vertical IS NULL OR vertical = ?))");
            $st->execute([$tenantId, $vertical]);
        } else {
            $st = Database::pdo()->query("SELECT code, name FROM credential_catalog WHERE tenant_id IS NULL");
        }
        $out = [];
        foreach ($st->fetchAll() as $r) { $out[$r['code']] = $r['name']; }
        return $out;
    }

    /** A tenant's own custom types, retired ones last. */
    public static function customFor(string $tenantId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT * FROM credential_catalog WHERE tenant_id = ?
              ORDER BY (retired_at IS NOT NULL), sort, name');
        $st->execute([$tenantId]);
        return $st->fetchAll();
    }

    /**
     * Add a custom credential type for one tenant.
     *
     * The code is derived from the name (lowercase, underscores) unless supplied. A custom code may
     * not collide with a global seeded code — shadowing would make "which x_license?" ambiguous in
     * every downstream resolve — nor with another of the same tenant's codes. Other tenants' custom
     * codes are irrelevant (and invisible) here.
     *
     * @return array{0:bool,1:string} [ok, message]
     */
    public static function addCustom(string $tenantId, array $in, ?string $userId = null): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) return [false, 'Give the document type a name (up to 160 characters).'];

        $code = strtolower(trim((string) ($in['code'] ?? '')));
        if ($code === '') {
            $code = substr(preg_replace('/_+/', '_', preg_replace('/[^a-z0-9]+/', '_', strtolower($name))) ?? '', 0, 60);
            $code = trim($code, '_');
        }
        if ($code === '' || !preg_match('/^[a-z0-9_]{2,60}$/', $code)) {
            return [false, 'The short code may use only letters, numbers and underscores.'];
        }

        $category = (string) ($in['category'] ?? 'other');
        if (!in_array($category, self::CATEGORIES, true)) $category = 'other';

        $renew = trim((string) ($in['renewal_months'] ?? ''));
        $renewalMonths = ($renew !== '' && (int) $renew > 0 && (int) $renew <= 120) ? (int) $renew : null;

        $state = strtoupper(trim((string) ($in['state'] ?? '')));
        if ($state !== '' && !preg_match('/^[A-Z]{2}$/', $state)) return [false, 'Jurisdiction must be a 2-letter code (or blank for national).'];

        $notes = trim((string) ($in['notes'] ?? ''));
        if (mb_strlen($notes) > 400) return [false, 'Notes are limited to 400 characters.'];

        // Collision check across BOTH tiers this tenant can see: global codes and their own.
        $chk = Database::pdo()->prepare(
            'SELECT tenant_id FROM credential_catalog WHERE code = ? AND (tenant_id IS NULL OR tenant_id = ?) LIMIT 1');
        $chk->execute([$code, $tenantId]);
        $ownerRow = $chk->fetch();
        if ($ownerRow !== false) {
            return [false, $ownerRow['tenant_id'] === null
                ? 'That short code is already used by a built-in document type — pick a different code.'
                : 'You already have a document type with that short code.'];
        }

        Database::pdo()->prepare(
            'INSERT INTO credential_catalog
                (id, tenant_id, code, name, category, authority, applies_to, line, state,
                 is_required, renewal_months, notes, sort, is_custom, created_by, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)'
        )->execute([
            uuid4(), $tenantId, $code, $name, $category,
            'custom',
            self::customAppliesTo($in['applies_to'] ?? null),
            'all',
            $state !== '' ? $state : null,
            !empty($in['is_required']) ? 1 : 0,
            $renewalMonths, $notes !== '' ? $notes : null,
            500,   // custom types sort after the seeded set
            $userId, utc_now(),
        ]);
        return [true, 'Added "' . $name . '" to your tracked document types.'];
    }

    /** Retire (soft-hide) or restore a custom type. Tenant-scoped: another tenant's id does nothing. */
    /**
     * Edit one of the tenant's OWN custom document types (name, category, renewal cycle,
     * jurisdiction, required flag, notes). The short code is stable on purpose — existing
     * credential records reference it. Built-in types are never editable here.
     */
    public static function updateCustom(string $tenantId, string $id, array $in): array
    {
        $st = Database::pdo()->prepare(
            'SELECT id FROM credential_catalog WHERE id = ? AND tenant_id = ? AND is_custom = 1 LIMIT 1');
        $st->execute([$id, $tenantId]);
        if (!$st->fetch()) return [false, 'That document type was not found (built-in types can\'t be edited).'];

        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) return [false, 'Give the document type a name (up to 160 characters).'];

        $category = (string) ($in['category'] ?? 'other');
        if (!in_array($category, self::CATEGORIES, true)) $category = 'other';

        $renew = trim((string) ($in['renewal_months'] ?? ''));
        $renewalMonths = ($renew !== '' && (int) $renew > 0 && (int) $renew <= 120) ? (int) $renew : null;

        $state = strtoupper(trim((string) ($in['state'] ?? '')));
        if ($state !== '' && !isset(self::STATES[$state])) return [false, 'Choose a jurisdiction from the list (or National).'];

        $notes = trim((string) ($in['notes'] ?? ''));
        if (mb_strlen($notes) > 400) return [false, 'Notes are limited to 400 characters.'];

        Database::pdo()->prepare(
            'UPDATE credential_catalog SET name = ?, category = ?, renewal_months = ?, state = ?,
                    is_required = ?, notes = ?
             WHERE id = ? AND tenant_id = ? AND is_custom = 1'
        )->execute([$name, $category, $renewalMonths, $state !== '' ? $state : null,
                    !empty($in['is_required']) ? 1 : 0, $notes !== '' ? $notes : null,
                    $id, $tenantId]);
        return [true, 'Document type updated.'];
    }

    public static function setRetired(string $tenantId, string $id, bool $retired): bool    {
        $st = Database::pdo()->prepare(
            'UPDATE credential_catalog SET retired_at = ? WHERE id = ? AND tenant_id = ? AND is_custom = 1');
        $st->execute([$retired ? utc_now() : null, $id, $tenantId]);
        return $st->rowCount() > 0;
    }

    /**
     * v1.170.7 — applies_to for a tenant-defined document type. applies_to is a list of provider types
     * (there is no "all" wildcard in the role filter), so the default is EVERY healthcare provider type;
     * the old default 'agent' matched no healthcare provider, so custom types were never required.
     */
    public static function customAppliesTo(mixed $raw): string
    {
        $valid = array_keys(Vocab::appliesToOptions('healthcare'));
        $picked = is_array($raw) ? $raw : explode(',', (string) $raw);
        $picked = array_values(array_intersect(array_map('trim', $picked), $valid));
        return implode(',', $picked !== [] ? $picked : $valid);
    }

    /** Tenant operating_states CSV ("FL,GA") -> clean uppercase array. Empty when none is configured (no jurisdiction is assumed). */
    public static function statesOf(?string $csv): array
    {
        $out = array_values(array_unique(array_filter(
            array_map(fn($s) => strtoupper(trim($s)), explode(',', (string) $csv)),
            fn($s) => preg_match('/^[A-Z]{2}$/', $s) === 1
        )));
        return $out;
    }

    /** The states FlxCoreHC ships verified content for. Others are accepted but unseeded. */
    public const STATES = [
        'AL'=>'Alabama','AK'=>'Alaska','AZ'=>'Arizona','AR'=>'Arkansas','CA'=>'California','CO'=>'Colorado',
        'CT'=>'Connecticut','DE'=>'Delaware','DC'=>'District of Columbia','FL'=>'Florida','GA'=>'Georgia',
        'HI'=>'Hawaii','ID'=>'Idaho','IL'=>'Illinois','IN'=>'Indiana','IA'=>'Iowa','KS'=>'Kansas','KY'=>'Kentucky',
        'LA'=>'Louisiana','ME'=>'Maine','MD'=>'Maryland','MA'=>'Massachusetts','MI'=>'Michigan','MN'=>'Minnesota',
        'MS'=>'Mississippi','MO'=>'Missouri','MT'=>'Montana','NE'=>'Nebraska','NV'=>'Nevada','NH'=>'New Hampshire',
        'NJ'=>'New Jersey','NM'=>'New Mexico','NY'=>'New York','NC'=>'North Carolina','ND'=>'North Dakota',
        'OH'=>'Ohio','OK'=>'Oklahoma','OR'=>'Oregon','PA'=>'Pennsylvania','RI'=>'Rhode Island','SC'=>'South Carolina',
        'SD'=>'South Dakota','TN'=>'Tennessee','TX'=>'Texas','UT'=>'Utah','VT'=>'Vermont','VA'=>'Virginia',
        'WA'=>'Washington','WV'=>'West Virginia','WI'=>'Wisconsin','WY'=>'Wyoming',
        // US territories — Puerto Rico participates in Medicare/Medicaid and has its own
        // medical, nursing, pharmacy and laboratory boards, so providers there are tracked here.
        'PR'=>'Puerto Rico','VI'=>'U.S. Virgin Islands','GU'=>'Guam',
    ];

    /** Tenant business_lines CSV ("medicare,commercial") -> clean array. */
    public static function linesOf(?string $csv): array
    {
        $out = array_values(array_intersect(
            array_filter(array_map('trim', explode(',', strtolower((string)$csv)))),
            ['medicare', 'commercial']));
        return $out !== [] ? $out : ['medicare'];
    }

    /** Best credential_type value to store so a given catalog code is satisfied.
     *  Reverses SYNONYMS where a distinct legacy type exists; otherwise the code itself works,
     *  since coverage matching treats an exact code match as covering. */
    public static function typeForCode(string $code): string
    {
        static $rev = null;
        if ($rev === null) {
            $rev = [];
            foreach (self::SYNONYMS as $type => $c) { $rev[$c] ??= $type; }
        }
        return $rev[$code] ?? $code;
    }

    /** Normalise a stored credential_type to a catalog code. */
    /**
     * Renewal cycle (in months) for a credential_type value, or null if the type doesn't define one.
     * Looks the type up in the tenant-visible catalog (built-in + custom) by code. Used to auto-derive
     * a credential's expiry date so the calendar and reminders have a date to run on.
     */
    public static function renewalMonthsForType(?string $tenantId, string $credentialType): ?int
    {
        if ($credentialType === '' || str_starts_with($credentialType, 'cr:')) return null;
        $code = self::codeFor($credentialType);
        foreach (self::all(null, null, null, $tenantId) as $cat) {
            if (($cat['code'] ?? '') === $code) {
                $m = $cat['renewal_months'] ?? null;
                return ($m !== null && (int)$m > 0) ? (int)$m : null;
            }
        }
        return null;
    }

    public static function codeFor(string $credentialType): string
    {
        $t = strtolower(trim($credentialType));
        return self::SYNONYMS[$t] ?? $t;
    }

    /**
     * Given a holder's credential rows, return code => best status among
     * 'active' | 'expiring' | 'expired' for each catalog code the holder has.
     * @param array<array<string,mixed>> $creds rows with credential_type + status
     */
    public static function coverage(array $creds): array
    {
        $rank = ['active' => 3, 'expiring' => 2, 'expired' => 1];
        $out = [];
        foreach ($creds as $c) {
            $code = self::codeFor((string)($c['credential_type'] ?? ''));
            $st = (string)($c['status'] ?? 'active');
            if (!in_array($st, ['active', 'expiring', 'expired'], true)) $st = 'active';
            if (!isset($out[$code]) || ($rank[$st] ?? 0) > ($rank[$out[$code]] ?? 0)) $out[$code] = $st;
        }
        return $out;
    }

    /** Credentials that also satisfy another requirement. Empty in the healthcare edition. */
    private const SATISFIES = [];

    /** Catalog codes that make sense per-regulator/payer (assigned as code@regulatorId).
     *  Empty by default in healthcare; a tenant's payer-specific enrollment credentials are
     *  scoped via a credential's regulator_id rather than a fixed catalog code. */
    public const CARRIER_SCOPED = [];

    /**
     * Carrier-aware coverage. Returns token => best status:
     *   'ahip' => 'active', 'carrier_cert@<uuid>' => 'expiring', 'cr:<uuid>' => 'active', ...
     * Base-code tokens are always present too, so general assignments still match.
     */
    public static function coverageTokens(array $creds): array
    {
        $rank = ['active' => 3, 'expiring' => 2, 'expired' => 1];
        $out = [];
        $put = function (string $tok, string $st) use (&$out, $rank) {
            if (!isset($out[$tok]) || ($rank[$st] ?? 0) > ($rank[$out[$tok]] ?? 0)) $out[$tok] = $st;
        };
        foreach ($creds as $c) {
            $type = (string)($c['credential_type'] ?? '');
            $st = (string)($c['status'] ?? 'active');
            if (!in_array($st, ['active', 'expiring', 'expired'], true)) $st = 'active';
            if (str_starts_with($type, 'cr:')) { $put($type, $st); continue; }
            $code = self::codeFor($type);
            $put($code, $st);
            $carrierId = (string)($c['regulator_id'] ?? '');
            if ($carrierId !== '' && in_array($code, self::CARRIER_SCOPED, true)) {
                $put($code . '@' . $carrierId, $st);
            }
        }
        foreach (self::SATISFIES as $from => $to) {
            if (isset($out[$from])) $put($to, $out[$from]);
        }
        return $out;
    }

    /**
     * Like coverageTokens but returns, per token, WHICH credential satisfies it:
     * token => ['status', 'credential_id', 'credential_name', 'expiry_date'].
     * Powers evidence tracking and gap→task due dates. Keep the ranking logic
     * in lockstep with coverageTokens above.
     */
    public static function coverageDetail(array $creds): array
    {
        $rank = ['active' => 3, 'expiring' => 2, 'expired' => 1];
        $out = [];
        $put = function (string $tok, string $st, array $c) use (&$out, $rank) {
            if (!isset($out[$tok]) || ($rank[$st] ?? 0) > ($rank[$out[$tok]['status']] ?? 0)) {
                $out[$tok] = ['status' => $st, 'credential_id' => (string)($c['id'] ?? ''),
                    'credential_name' => (string)($c['name'] ?? ''), 'expiry_date' => $c['expiry_date'] ?? null];
            }
        };
        foreach ($creds as $c) {
            $type = (string)($c['credential_type'] ?? '');
            $st = (string)($c['status'] ?? 'active');
            if (!in_array($st, ['active', 'expiring', 'expired'], true)) $st = 'active';
            if (str_starts_with($type, 'cr:')) { $put($type, $st, $c); continue; }
            $code = self::codeFor($type);
            $put($code, $st, $c);
            $carrierId = (string)($c['regulator_id'] ?? '');
            if ($carrierId !== '' && in_array($code, self::CARRIER_SCOPED, true)) {
                $put($code . '@' . $carrierId, $st, $c);
            }
        }
        foreach (self::SATISFIES as $from => $to) {
            if (isset($out[$from])) {
                $d = $out[$from];
                if (!isset($out[$to]) || ($rank[$d['status']] ?? 0) > ($rank[$out[$to]['status']] ?? 0)) $out[$to] = $d;
            }
        }
        return $out;
    }

    /** Required catalog codes that apply to a role (agent|agency|fmo) — sensible defaults to assign. */
    public static function requiredCodesForRole(string $role, ?array $lines = null, ?array $states = null): array
    {
        return array_values(array_map(fn($r) => $r['code'],
            array_filter(self::all($role, $lines, $states), fn($r) => (int)$r['is_required'] === 1)));
    }

    /** Role a holder plays, for choosing which catalog items apply. */
    public static function holderRole(array $holder, string $orgShape): string
    {
        // Healthcare holders match templates by provider type (physician/nurse/lab/clinic/group),
        // not by insurance roles — otherwise their templates never suggest.
        // v1.170.7 — healthcare-only: every holder maps to a healthcare provider type.
        $pt = (string) ($holder['provider_type'] ?? '');
        if (isset(Vocab::appliesToOptions('healthcare')[$pt])) return $pt;
        return ($holder['holder_type'] ?? '') === 'organization' ? 'group' : 'physician';
    }
}
