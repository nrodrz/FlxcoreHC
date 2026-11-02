<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * The one entitlement check: what an organization may use right now. Nothing else asks "which plan?" — screens and features ask this.
 *
 *   licensed   a license record whose signature verifies, within its term           → the record's modules and user limit
 *   grace      the term ended less than 14 days ago                                  → the same, with a warning
 *   trial      no usable license, within 30 days of the organization's creation      → every module
 *   core       no usable license and the trial is over                               → the core only (no optional module). Nothing is deleted.
 *   internal   the platform operator's own organization and demo organizations       → every module, no end
 * A record that is revoked, not yet started, ended past the grace period, or whose signature does not verify is not usable.
 */
final class Entitlement
{
    /** @var array<string,array> */
    private static array $cache = [];

    /**
     * @return array{state:string,modules:array<int,string>,users:?int,plan:?string,kind:?string,serial:?string,agreement:?string,term_start:?string,term_end:?string,
     *               days_left:?int,tampered:bool,licensee:string}
     */
    public static function forTenant(string $tenantId): array
    {
        if (isset(self::$cache[$tenantId])) return self::$cache[$tenantId];
        $all = array_keys(LicenseService::MODULES);
        $out = ['state' => 'core', 'modules' => [], 'users' => null, 'plan' => null, 'kind' => null, 'serial' => null, 'agreement' => null,
                'term_start' => null, 'term_end' => null, 'days_left' => null, 'tampered' => false, 'licensee' => ''];
        try {
            $q = Database::pdo()->prepare('SELECT name, org_shape, is_demo, created_at FROM tenants WHERE id = ? LIMIT 1'); $q->execute([$tenantId]);
            $t = $q->fetch();
        } catch (\Throwable $e) { $t = false; }
        if (!$t) return self::$cache[$tenantId] = ['state' => 'trial', 'modules' => $all] + $out;   // unknown tenant (a fixture): do not lock anything
        $out['licensee'] = (string) $t['name'];
        if ((string) $t['org_shape'] === 'platform' || !empty($t['is_demo'])) {
            return self::$cache[$tenantId] = ['state' => 'internal', 'modules' => $all] + $out;
        }
        $today = today_local();
        try {
            $l = Database::pdo()->prepare('SELECT * FROM tenant_licenses WHERE tenant_id = ? AND revoked_at IS NULL ORDER BY issued_at DESC, serial DESC'); $l->execute([$tenantId]);
            $rows = $l->fetchAll();
        } catch (\Throwable $e) { $rows = []; }
        foreach ($rows as $r) {
            // the signature must verify AND the record must have been issued to THIS workspace (a record copied from another organization does not count)
            if (!LicenseService::verify($r) || (string) $r['workspace_id'] !== OrgService::workspaceId($tenantId)) { $out['tampered'] = true; continue; }
            if ($r['term_start'] > $today) continue;
            $end = $r['term_end'];
            $state = null;
            if ($end === null || $today <= $end) $state = 'licensed';
            elseif ($today <= gmdate('Y-m-d', strtotime($end . ' +' . LicenseService::GRACE_DAYS . ' days'))) $state = 'grace';
            if ($state === null) continue;
            $mods = array_values(array_intersect($all, array_filter(explode(',', (string) $r['modules']))));
            return self::$cache[$tenantId] = ['state' => $state, 'modules' => $mods, 'users' => $r['users'] === null ? null : (int) $r['users'], 'plan' => $r['plan'], 'kind' => $r['kind'],
                'serial' => $r['serial'], 'agreement' => $r['agreement'], 'term_start' => $r['term_start'], 'term_end' => $end,
                'days_left' => $end === null ? null : (int) round((strtotime($end) - strtotime($today)) / 86400)] + $out;
        }
        $trialEnd = gmdate('Y-m-d', strtotime(substr((string) $t['created_at'], 0, 10) . ' +' . LicenseService::TRIAL_DAYS . ' days'));
        if ($today <= $trialEnd) {
            return self::$cache[$tenantId] = ['state' => 'trial', 'modules' => $all, 'term_end' => $trialEnd, 'days_left' => (int) round((strtotime($trialEnd) - strtotime($today)) / 86400)] + $out;
        }
        return self::$cache[$tenantId] = ['state' => 'core', 'term_end' => $trialEnd, 'days_left' => 0] + $out;
    }

    public static function allows(string $tenantId, string $module): bool
    {
        return in_array($module, self::forTenant($tenantId)['modules'], true);
    }

    /** Forget the per-request cache (tests; after issuing or revoking). */
    public static function reset(): void
    {
        self::$cache = [];
    }
}
