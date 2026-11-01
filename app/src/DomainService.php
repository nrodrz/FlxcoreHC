<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */

declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Hostname → tenant mapping, for white-labelled deployments where each agency has its own domain.
 *
 * Its only job is to work out which branding to show on pages that render before anyone has signed
 * in. The Host header is supplied by the client and can be forged, so it is never allowed to
 * influence authorisation — it selects a logo and a palette, nothing more. Session state and tenant
 * scoping remain the sole authority for what a person can actually see or change.
 *
 * Platform-level by design: it reads across tenants, so it lives outside the tenant repository
 * layer and is listed in tools/check_isolation.sh accordingly.
 */
final class DomainService
{
    /**
     * Reduce a Host header to a comparable hostname: lowercase, port removed, trailing dot removed,
     * and a leading "www." dropped so the apex and www forms resolve to the same agency.
     * Returns '' when the value could not be a hostname, which callers treat as "no match".
     */
    public static function normalize(?string $host): string
    {
        $h = strtolower(trim((string) $host));
        if ($h === '') return '';
        if (str_contains($h, '/')) return '';                 // a URL, not a host
        $h = preg_replace('/:\d+$/', '', $h);                 // strip :8080
        $h = rtrim((string) $h, '.');                         // strip the FQDN root dot
        if (str_starts_with($h, 'www.')) $h = substr($h, 4);
        // Hostname grammar only: letters, digits, dots, hyphens. Anything else is rejected outright
        // rather than queried, so a forged header can't become a search term.
        if ($h === '' || strlen($h) > 190 || !preg_match('/^[a-z0-9.-]+$/', $h)) return '';
        return $h;
    }

    /** The tenant row that owns a hostname, or null. */
    public static function tenantForHost(?string $host): ?array
    {
        $h = self::normalize($host);
        if ($h === '') return null;
        $st = Database::pdo()->prepare(
            'SELECT t.* FROM tenant_domains d JOIN tenants t ON t.id = d.tenant_id
              WHERE d.host = ? LIMIT 1'
        );
        $st->execute([$h]);
        return $st->fetch() ?: null;
    }

    /** Domains mapped to one tenant, primary first. */
    public static function forTenant(string $tenantId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT * FROM tenant_domains WHERE tenant_id = ? ORDER BY is_primary DESC, host'
        );
        $st->execute([$tenantId]);
        return $st->fetchAll();
    }

    /**
     * Map a hostname to a tenant.
     * @return array{0:bool,1:string} [ok, message]
     */
    public static function add(string $tenantId, string $host, bool $primary = false): array
    {
        $h = self::normalize($host);
        if ($h === '') return [false, 'That does not look like a hostname. Use a bare domain such as agency.example.com.'];
        if (!str_contains($h, '.')) return [false, 'Include the full domain, for example agency.example.com.'];

        $dupe = Database::pdo()->prepare('SELECT tenant_id FROM tenant_domains WHERE host = ? LIMIT 1');
        $dupe->execute([$h]);
        $owner = $dupe->fetchColumn();
        if ($owner !== false) {
            return [false, $owner === $tenantId
                ? 'That domain is already mapped to this organization.'
                : 'That domain is already mapped to a different organization.'];
        }

        if ($primary) {
            Database::pdo()->prepare('UPDATE tenant_domains SET is_primary = 0 WHERE tenant_id = ?')
                ->execute([$tenantId]);
        }
        Database::pdo()->prepare(
            'INSERT INTO tenant_domains (id, tenant_id, host, is_primary, created_at) VALUES (?,?,?,?,?)'
        )->execute([uuid4(), $tenantId, $h, $primary ? 1 : 0, utc_now()]);

        return [true, t('Mapped {host} to this organization.', ['host' => $h])];
    }

    /** Remove a mapping. Scoped by tenant so an id from elsewhere cannot be deleted by guessing. */
    public static function remove(string $tenantId, string $id): bool
    {
        $st = Database::pdo()->prepare('DELETE FROM tenant_domains WHERE id = ? AND tenant_id = ?');
        $st->execute([$id, $tenantId]);
        return $st->rowCount() > 0;
    }

    /** Total mappings on the install — used to explain resolution behaviour in the UI. */
    public static function count(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM tenant_domains')->fetchColumn();
    }
}
