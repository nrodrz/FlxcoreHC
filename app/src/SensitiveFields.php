<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * FIELD-LEVEL access control for sensitive identity data.
 *
 * A field listed here is:
 *   - encrypted at rest (handled by the repository's encryptedColumns)
 *   - decrypted and shown ONLY to users holding the field's capability (default `pii.view`)
 *   - masked for everyone else — the server still decrypts to build the mask, but only the mask
 *     reaches the page, and reads are access-logged
 *   - NEVER included in packets, exports, tenant reports, emails or audit detail (see EXCLUDED_FROM)
 *
 * This is the general mechanism: adding another protected field is one entry here, not new plumbing.
 */
final class SensitiveFields
{
    /** field => [capability, mask style, label] */
    public const FIELDS = [
        'dob'        => ['pii.view', 'date',  'Date of birth'],
        'ssn_last4'  => ['pii.view', 'last4', 'SSN (last 4)'],
    ];

    /** Surfaces these fields must never appear in. Asserted by tests. */
    public const EXCLUDED_FROM = ['profile_packet', 'data_export', 'tenant_report', 'email', 'audit_detail', 'external_share', 'cross_org_share'];

    public static function isSensitive(string $field): bool { return isset(self::FIELDS[$field]); }

    /**
     * Resolution order (Level 2): an explicit per-role grant for this field wins (allow OR deny);
     * otherwise the field's capability decides. Custom fields use the key 'cf:<definition_id>'.
     */
    public static function canView(TenantContext $ctx, string $field): bool
    {
        if ($ctx->role === 'tenant_owner') return true;
        try {
            $grant = self::roleGrant($ctx, $field, true);
        } catch (\Throwable $e) {
            flx_log_error('error', 'sensitive-field grant lookup failed — denying'); // v1.170.9 fail closed
            return false;
        }
        if ($grant !== null) return $grant;
        if (str_starts_with($field, 'cf:')) return Authz::can($ctx, 'pii.view');
        $cap = self::FIELDS[$field][0] ?? null;
        return $cap ? Authz::can($ctx, $cap) : true;
    }

    /** Explicit per-role field rule, or null when none is set. Master Admin always allowed. */
    public static function roleGrant(TenantContext $ctx, string $field, bool $throwOnError = false): ?bool
    {
        if ($ctx->role === 'tenant_owner') return true;
        try {
            $st = Database::pdo()->prepare('SELECT allowed FROM role_field_grants WHERE tenant_id = ? AND role_slug = ? AND field_key = ? LIMIT 1');
            $st->execute([$ctx->tenantId, $ctx->role, $field]);
            $v = $st->fetchColumn();
            return $v === false ? null : (bool)$v;
        } catch (\Throwable $e) {
            if ($throwOnError) throw $e;   // canView() fails closed; display-only callers keep the null
            return null;
        }
    }

    /** All per-role rules for a tenant: [role_slug => [field_key => allowed]]. */
    public static function grantsFor(string $tenantId): array
    {
        $out = [];
        try {
            $st = Database::pdo()->prepare('SELECT role_slug, field_key, allowed FROM role_field_grants WHERE tenant_id = ?');
            $st->execute([$tenantId]);
            foreach ($st->fetchAll() as $r) $out[$r['role_slug']][$r['field_key']] = (bool)$r['allowed'];
        } catch (\Throwable $e) {}
        return $out;
    }

    /** Set or clear per-role rules for the given fields ('allow' | 'deny' | 'inherit'). */
    public static function saveGrants(TenantContext $ctx, string $roleSlug, array $rules): void
    {
        // v1.171.0 (S-1): who may see a sensitive field is an Owner decision — a Manager must not be able to
        // grant their own role access to fields the Owner withheld.
        if (!RoleService::isReservedOwnerSlug($ctx->role)) return;
        $pdo = Database::pdo();
        foreach ($rules as $field => $rule) {
            $field = (string)$field;
            if ($rule === 'inherit') { $pdo->prepare('DELETE FROM role_field_grants WHERE tenant_id = ? AND role_slug = ? AND field_key = ?')->execute([$ctx->tenantId, $roleSlug, $field]); continue; }
            $pdo->prepare('INSERT INTO role_field_grants (id, tenant_id, role_slug, field_key, allowed, created_at) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)')
                ->execute([uuid4(), $ctx->tenantId, $roleSlug, $field, $rule === 'allow' ? 1 : 0, utc_now()]);
        }
        audit_log($ctx, 'role_field_grants_saved', 'roles', $roleSlug, json_encode($rules));
    }

    /** Every protectable field for the tenant: built-ins plus sensitive custom fields. [key => label] */
    public static function protectable(TenantContext $ctx): array
    {
        $out = [];
        foreach (self::FIELDS as $k => $def) $out[$k] = $def[2];
        try {
            $st = Database::pdo()->prepare('SELECT id, label FROM custom_field_definitions WHERE tenant_id = ? AND is_sensitive = 1 AND active = 1 ORDER BY sort_order, label');
            $st->execute([$ctx->tenantId]);
            foreach ($st->fetchAll() as $d) $out['cf:' . $d['id']] = $d['label'] . ' (custom)';
        } catch (\Throwable $e) {}
        return $out;
    }

    /**
     * The value to display: the real value when permitted, otherwise a mask.
     * Returns null when there is nothing stored.
     */
    public static function display(TenantContext $ctx, string $field, ?string $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (self::canView($ctx, $field)) return $value;
        return match (self::FIELDS[$field][1] ?? '') {
            'date'  => '••••-••-••',
            'last4' => '•••-••-' . substr(preg_replace('/\D/', '', $value), -4),
            default => '••••••',
        };
    }

    /** Record that someone viewed a sensitive field (privacy accounting). */
    public static function logView(TenantContext $ctx, string $field, string $holderId): void
    {
        try { AccessLog::record($ctx, 'pii_view:' . $field, 'holders', $holderId); } catch (\Throwable $e) {}
    }

    /** Strip sensitive keys from any array before it leaves the app (export, packet, report, email). */
    public static function strip(array $row): array
    {
        foreach (array_keys(self::FIELDS) as $f) { unset($row[$f], $row[$f . '_enc']); }
        return $row;
    }

    /** Validate + normalise input. Returns [ok, value|null, error|null]. */
    public static function normalise(string $field, string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return [true, null, null];
        if ($field === 'dob') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return [false, null, 'Date of birth must be a date.'];
            $t = strtotime($raw);
            if ($t === false || $t > time() || $t < strtotime('-120 years')) return [false, null, 'Date of birth is not a plausible date.'];
            return [true, $raw, null];
        }
        if ($field === 'ssn_last4') {
            $d = preg_replace('/\D/', '', $raw);
            // Accept a full SSN typed by mistake but keep ONLY the last four — we never store the full number.
            if (strlen($d) === 9) $d = substr($d, -4);
            if (strlen($d) !== 4) return [false, null, 'Enter the last 4 digits of the SSN.'];
            return [true, $d, null];
        }
        return [true, $raw, null];
    }
}
