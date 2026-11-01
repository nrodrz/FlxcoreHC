<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

use FlxCoreHC\TenantContext;

/**
 * Tenant self-service organization profile + branding. Every method is scoped to the
 * caller's own tenant ($ctx->tenantId) — it can never read or write another tenant.
 */
final class OrgService
{
    public function __construct(private TenantContext $ctx) {}

    /** Current org row (own tenant only). */
    public function get(): array
    {
        $stmt = Database::pdo()->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
        $stmt->execute([$this->ctx->tenantId]);
        return $stmt->fetch() ?: [];
    }

    /** Update organization profile fields (own tenant). */
    /**
     * Update organization fields. Only keys actually present in $in are written, so a form that
     * covers part of the profile (the settings screen is split across tabs) can never blank out
     * the fields it doesn't render. Checkboxes are the exception — an unchecked box submits
     * nothing — so those are keyed off a companion hidden marker field.
     */
    /** v1.171.10: validation for the organization name field when a form sends it (blank after cleaning = refused). */
    public static function nameError(array $in): ?string
    {
        if (array_key_exists('name', $in) && TextNorm::blank((string) $in['name'])) return 'An organization name is required.';
        return null;
    }

    /** v1.173.0: the workspace ID (XXXX-XXXX-XXXX-XXXX, uppercase hex). Made once on first need and never changed: the UPDATE only fills a NULL. */
    public static function workspaceId(string $tenantId): string
    {
        $pdo = Database::pdo();
        $get = $pdo->prepare('SELECT workspace_id FROM tenants WHERE id = ? LIMIT 1');
        for ($try = 0; $try < 5; $try++) {
            $get->execute([$tenantId]);
            $cur = (string) ($get->fetchColumn() ?: '');
            if ($cur !== '') return $cur;
            $id = strtoupper(implode('-', str_split(bin2hex(random_bytes(8)), 4)));
            try { $pdo->prepare('UPDATE tenants SET workspace_id = ? WHERE id = ? AND workspace_id IS NULL')->execute([$id, $tenantId]); }
            catch (\Throwable $e) { /* unique clash (practically impossible) or column missing before migration 135: try again / give up below */ }
        }
        return '';
    }

    /** v1.172.16: the organization's own time zone (null = use the platform's). Read once per request by index.php. */
    public static function timezoneFor(string $tenantId): ?string
    {
        try {
            $q = Database::pdo()->prepare('SELECT timezone FROM tenants WHERE id = ? LIMIT 1');
            $q->execute([$tenantId]);
            return ($z = $q->fetchColumn()) ? (string) $z : null;
        } catch (\Throwable $e) { return null; }   // column missing before migration 134 → platform zone
    }

    public function updateInfo(array $in): void
    {
        $set = [];
        $args = [];
        $has = static fn(string $k): bool => array_key_exists($k, $in);

        if ($has('name')) {
            // Never allow the org name to be blanked; COALESCE keeps the current value.
            // v1.171.10: identity names go through TextNorm::clean() (invisible characters removed, spacing collapsed);
            // a name that cleans to nothing keeps the current value (callers validate with nameError() first).
            $set[] = 'name = COALESCE(NULLIF(?,\'\'), name)';
            $args[] = mb_substr(TextNorm::clean((string) $in['name']), 0, 160);
        }
        if ($has('brand_name'))    { $set[] = 'brand_name = ?';    $args[] = mb_substr(TextNorm::clean((string) $in['brand_name']), 0, 160) ?: null; }
        if ($has('default_locale')) {
            $loc = (string) $in['default_locale'];
            $set[] = 'default_locale = ?';
            $args[] = isset(\FlxCoreHC\I18n::LOCALES[$loc]) ? $loc : 'en';
        }
        if ($has('timezone')) {   // v1.172.16: blank = use the platform's zone
            $z = (string) $in['timezone'];
            $set[] = 'timezone = ?';
            $args[] = isset(flx_timezones()[$z]) ? $z : null;
        }
        if ($has('contact_email')) {
            $email = trim((string) $in['contact_email']);
            $set[] = 'contact_email = ?';
            $args[] = ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null;
        }
        if ($has('contact_phone')) { $set[] = 'contact_phone = ?'; $args[] = trim((string) $in['contact_phone']) ?: null; }
        if ($has('website'))       { $set[] = 'website = ?';       $args[] = trim((string) $in['website']) ?: null; }
        if ($has('address'))       { $set[] = 'address = ?';       $args[] = trim((string) $in['address']) ?: null; }

        // Lines/states/2FA live on their own tabs now; a hidden marker tells us the form
        // actually covered them, which distinguishes "cleared everything" from "not shown".
        if ($has('business_lines') || $has('_lines_form')) {
            $lines = CatalogService::linesOf(implode(',', (array) ($in['business_lines'] ?? ['medicare'])));
            $set[] = 'business_lines = ?'; $args[] = implode(',', $lines);
        }
        if ($has('operating_states') || $has('_lines_form')) {
            $raw = is_array($in['operating_states'] ?? null)
                ? implode(',', $in['operating_states'])
                : (string) ($in['operating_states'] ?? '');
            $set[] = 'operating_states = ?'; $args[] = implode(',', CatalogService::statesOf($raw));
        }
        if ($has('require_2fa_admins') || $has('_security_form')) {
            $set[] = 'require_2fa_admins = ?'; $args[] = !empty($in['require_2fa_admins']) ? 1 : 0;
        }

        if ($set === []) return;
        $args[] = $this->ctx->tenantId;
        Database::pdo()->prepare('UPDATE tenants SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
    }

    /** Set (or clear) the brand accent colour. Returns the normalized hex or null. */
    public function updateBrand(?string $accent): ?string
    {
        $hex = self::normalizeHex($accent);
        Database::pdo()->prepare("UPDATE tenants SET brand_accent = ? WHERE id = ?")
            ->execute([$hex, $this->ctx->tenantId]);
        return $hex;
    }

    /**
     * Save the tenant's theme choices. Each is optional and stored as NULL when blank, which
     * means "use the product default" — so clearing a field cleanly reverts that token.
     * Audited by the caller; the values themselves are non-sensitive presentation settings.
     */
    public function updateTheme(array $in): void
    {
        $nav    = ThemeService::hex($in['brand_nav'] ?? null);
        $mode   = (string) ($in['brand_theme'] ?? '');
        $mode   = in_array($mode, ['light', 'dark', 'auto'], true) ? $mode : null;
        $radius = (string) ($in['brand_radius'] ?? '');
        $radius = in_array($radius, ['sharp', 'soft', 'round'], true) ? $radius : null;

        Database::pdo()->prepare(
            "UPDATE tenants SET brand_nav = ?, brand_theme = ?, brand_radius = ? WHERE id = ?"
        )->execute([$nav, $mode, $radius, $this->ctx->tenantId]);
    }

    public function setLogo(string $path): void
    {
        Database::pdo()->prepare("UPDATE tenants SET logo_path = ? WHERE id = ?")
            ->execute([$path, $this->ctx->tenantId]);
    }

    public function clearLogo(): ?string
    {
        $cur = $this->get()['logo_path'] ?? null;
        Database::pdo()->prepare("UPDATE tenants SET logo_path = NULL WHERE id = ?")->execute([$this->ctx->tenantId]);
        return $cur;
    }

    /** Validate + normalize a hex colour to '#rrggbb', or null if invalid/empty. */
    public static function normalizeHex(?string $hex): ?string
    {
        $hex = strtolower(trim((string)$hex));
        if ($hex === '') return null;
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        return preg_match('/^[0-9a-f]{6}$/', $hex) ? '#'.$hex : null;
    }

    /** Return a darker shade of a hex colour (for button hover / --accent-2). */
    /** Operating states of a tenant (empty = none configured; no jurisdiction is assumed). Cached per request. */
    public static function statesFor(string $tenantId): array
    {
        static $cache = [];
        if (isset($cache[$tenantId])) return $cache[$tenantId];
        try {
            $st = Database::pdo()->prepare('SELECT operating_states FROM tenants WHERE id = ?');
            $st->execute([$tenantId]);
            return $cache[$tenantId] = CatalogService::statesOf((string) ($st->fetchColumn() ?: ''));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The organization's own regulatory identity (v1.170.7 healthcare): legal name, FEIN, group NPI,
     * taxonomy, Medicare PTAN, Medicaid ID, CLIA, state facility licence, accreditation — what a payer,
     * hospital or state agency asks for about the ENTITY, not about an individual provider.
     */
    public function updateEntity(array $in): array
    {
        $legal = mb_substr(TextNorm::clean((string) ($in['legal_name'] ?? '')), 0, 160);   // v1.171.10: identity name → cleaned
        $fein  = trim((string) ($in['fein'] ?? ''));
        if ($fein !== '' && !preg_match('/^\d{2}-?\d{7}$/', $fein)) {
            return [false, 'FEIN should look like 12-3456789.'];
        }
        $npi = preg_replace('/\D/', '', (string) ($in['group_npi'] ?? ''));
        if (($e = Npi::error($npi, 'Group NPI')) !== null) {
            return [false, $e];
        }
        $clia = strtoupper(trim((string) ($in['clia_number'] ?? '')));
        if ($clia !== '' && !preg_match('/^\d{2}D\d{7}$/', $clia)) {
            return [false, 'CLIA numbers look like 40D1234567 (2 digits, D, 7 digits).'];
        }
        $f = fn(string $k, int $max) => ($v = trim((string) ($in[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
        Database::pdo()->prepare(
            "UPDATE tenants SET legal_name = ?, fein = ?, group_npi = ?, taxonomy_code = ?, medicare_ptan = ?,
                    medicaid_id = ?, clia_number = ?, facility_license = ?, accreditation = ? WHERE id = ?"
        )->execute([
            $legal ?: null,
            $fein ?: null,
            $npi ?: null,
            $f('taxonomy_code', 20), $f('medicare_ptan', 40), $f('medicaid_id', 40),
            $clia ?: null, $f('facility_license', 60), $f('accreditation', 120),
            $this->ctx->tenantId,
        ]);
        return [true, 'Entity profile saved.'];
    }

    public static function darken(string $hex, float $f = 0.82): string
    {
        $h = ltrim($hex, '#');
        if (!preg_match('/^[0-9a-f]{6}$/i', $h)) return $hex;
        $r = (int) round(hexdec(substr($h,0,2)) * $f);
        $g = (int) round(hexdec(substr($h,2,2)) * $f);
        $b = (int) round(hexdec(substr($h,4,2)) * $f);
        return sprintf('#%02x%02x%02x', max(0,min(255,$r)), max(0,min(255,$g)), max(0,min(255,$b)));
    }
}
