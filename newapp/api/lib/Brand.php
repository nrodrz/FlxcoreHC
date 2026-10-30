<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — client app name (BT20).
 * "OpsHub" is the commercial product name; each client can rename the app for its people under
 * Settings → Branding → App name (setting `app_name`). Everything a person SEES — screens, emails,
 * the Help Center, the authenticator-app label, messages — uses opsh_app_name().
 * Technical identifiers keep the product name on purpose: HTTP headers (X-OpsHub-*), log tags,
 * the installer, and the names of objects OpsHub creates in Microsoft 365 (they are how it finds
 * them again — see the BT20 notes).
 */
if (!function_exists('opsh_app_name')) {
    function opsh_app_name(bool $fresh = false): string {
        static $n = null;
        if ($n !== null && !$fresh) return $n;
        $n = 'OpsHub';
        try {
            $v = getDB()->query("SELECT sval FROM opsh_settings WHERE skey='app_name'")->fetchColumn();
            if (is_string($v) && trim($v) !== '') $n = trim($v);
        } catch (Throwable $e) { /* not installed yet → product name */ }
        return $n;
    }
    /** Replace the product name in text shown to people (not inside header names such as X-OpsHub-Token). */
    function opsh_brand(?string $s): string {
        $s = (string)$s; $n = opsh_app_name();
        if ($n === 'OpsHub' || strpos($s, 'OpsHub') === false) return $s;
        return preg_replace_callback('/(?<![-\w])OpsHub(?![-\w])/', fn() => $n, $s);
    }
}

/**
 * BT96 — ownership of the product. Fixed in the code on purpose: no setting, screen or role can change it. A licensee
 * renames the app for its people (app_name above); the product, its owner and the notices below stay as they are.
 */
if (!defined('OPSH_PRODUCT')) {
    define('OPSH_PRODUCT', 'NewApp');
    define('OPSH_PRODUCT_LINE', 'Microsoft 365 Administration');
    define('OPSH_OWNER', 'Indisse-BTS');   // BT100: the owner is named as the business, never as a person
    define('OPSH_COPYRIGHT_YEARS', '2026');
}
if (!function_exists('opsh_about')) {
    /** The organization's name: the setting org_name, else the name used on exported documents (Settings → Branding → Documents). */
    function opsh_org_name(PDO $db): string {
        try { $q = $db->query("SELECT skey, sval FROM opsh_settings WHERE skey IN ('org_name','doc_org_name')")->fetchAll(PDO::FETCH_KEY_PAIR); }
        catch (Throwable $e) { return ''; }
        return trim((string)($q['org_name'] ?? '')) !== '' ? trim((string)$q['org_name']) : trim((string)($q['doc_org_name'] ?? ''));
    }
    function opsh_copyright(): string { return '© ' . OPSH_COPYRIGHT_YEARS . ' ' . OPSH_OWNER . '. All rights reserved.'; }

    /** The third-party components shipped with the product (full texts: vendor/LICENSES.txt, public/teams/TEAMS-JS-LICENSE.txt, THIRD-PARTY-NOTICES.txt). */
    function opsh_third_party(): array {
        return [
            ['name' => 'React', 'version' => '18.3.1', 'license' => 'MIT'],
            ['name' => 'ReactDOM', 'version' => '18.3.1', 'license' => 'MIT'],
            ['name' => 'DOMPurify', 'version' => '3.0.11', 'license' => 'Apache-2.0 or MPL-2.0'],
            ['name' => 'QRCode.js', 'version' => '1.0.0', 'license' => 'MIT'],
            ['name' => 'html2canvas', 'version' => '1.4.1', 'license' => 'MIT'],
            ['name' => 'PDF.js (pdfjs-dist)', 'version' => '3.11.174', 'license' => 'Apache-2.0'],
            ['name' => 'three.js', 'version' => 'r128', 'license' => 'MIT'],
            ['name' => 'Microsoft Teams JavaScript client library (@microsoft/teams-js)', 'version' => '2.57.0', 'license' => 'MIT'],
            ['name' => 'World map shapes (world-atlas, from Natural Earth 1:110m)', 'version' => '2.0.2', 'license' => 'ISC; map data public domain'],
            ['name' => 'Country names (i18n-iso-countries)', 'version' => '7.11.0', 'license' => 'MIT'],
        ];
    }

    /** This installation's identifier: made once (random); it names the installation on a license, and is sent only to the License Hub it is linked to (BT127). */
    function opsh_install_id(PDO $db): string {
        try {
            $v = (string)$db->query("SELECT sval FROM opsh_settings WHERE skey='install_id'")->fetchColumn();
            if (preg_match('/^[A-F0-9]{4}(-[A-F0-9]{4}){3}$/', $v)) return $v;
            $v = implode('-', str_split(strtoupper(bin2hex(random_bytes(8))), 4));
            $db->prepare("INSERT IGNORE INTO opsh_settings (skey, sval) VALUES ('install_id', ?)")->execute([$v]);
            return (string)$db->query("SELECT sval FROM opsh_settings WHERE skey='install_id'")->fetchColumn();
        } catch (Throwable $e) { return ''; }
    }

    /** BT97: the independent-developer statement (every signed-in person sees it). Draft wording, pending legal review. */
    function opsh_independent(): string {
        return OPSH_PRODUCT . ' is an independent product. It was conceived, designed and developed by ' . OPSH_OWNER . ' as an independent software developer, and not as an employee, contractor or consultant of any company or organization. It was not commissioned by, and is not a work made for hire for, any organization that uses it. Licensing, using, testing or requesting features of ' . OPSH_PRODUCT . ' gives no organization any ownership interest in it, in its updates, or in improvements made at its request.';
    }

    /**
     * BT97: this installation's license record. The owner delivers it with the installation as opsh-license.php in the
     * installation folder (it is never part of an update package, so an update never replaces it). The file returns an
     * array; nothing in the app can write it. A PHP file, not JSON, so the web server never serves its contents.
     *   <?php return ['agreement' => 'OPSH-2026-001', 'licensee' => 'Organization legal name', 'install_id' => 'XXXX-XXXX-XXXX-XXXX',
     *                 'term_start' => '2026-11-01', 'term_end' => '' (empty = perpetual), 'users' => 50 (0 = no limit),
     *                 'plan' => 'complete' | 'service_desk' | 'm365_admin' | 'it_operations' (BT101; empty = every module),
     *                 'modules' => [module keys added on top of the plan, e.g. 'budget'] (BT101; names that are not module keys
     *                 are only shown), 'kind' => 'Free' | 'Paid' | ..., 'notes' => ''];
     * Returns null when there is no record, or ['error' => …] when the file is there but unreadable.
     */
    function opsh_license_record(PDO $db): ?array {
        $f = dirname(__DIR__, 2) . '/opsh-license.php';
        if (!is_file($f)) return null;
        try { $r = (static function (string $f) { return include $f; })($f); } catch (Throwable $e) { return ['error' => 'The license record could not be read.']; }
        if (!is_array($r)) return ['error' => 'The license record could not be read.'];
        $txt = fn($k, $max = 200) => mb_substr(trim((string)($r[$k] ?? '')), 0, $max);
        $date = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($r[$k] ?? '')) ? (string)$r[$k] : '';
        $mods = array_values(array_filter(array_map(fn($m) => mb_substr(trim((string)$m), 0, 80), is_array($r['modules'] ?? null) ? $r['modules'] : []), 'strlen'));
        $out = [
            'agreement' => $txt('agreement', 60), 'licensee' => $txt('licensee'), 'kind' => $txt('kind', 40),
            'install_id' => strtoupper($txt('install_id', 19)), 'term_start' => $date('term_start'), 'term_end' => $date('term_end'),
            'users' => max(0, (int)($r['users'] ?? 0)), 'modules' => array_slice($mods, 0, 50), 'notes' => $txt('notes', 500),
            'plan' => strtolower($txt('plan', 40)), 'module_keys' => array_values(array_filter(array_map('strtolower', array_slice($mods, 0, 50)), fn($m) => (bool)preg_match('/^[a-z0-9_]{2,40}$/', $m))),
        ];
        // BT105: is the file genuine and unchanged (signed by the owner)?
        require_once __DIR__ . '/License.php';
        $out['serial'] = $txt('serial', 40); $out['issued_at'] = $txt('issued_at', 30);
        $out['signed'] = opsh_license_check($r);
        $here = opsh_install_id($db);
        $out['other_installation'] = $out['install_id'] !== '' && $here !== '' && $out['install_id'] !== $here;
        $out['expired'] = $out['term_end'] !== '' && $out['term_end'] < date('Y-m-d');
        return $out;
    }

    /** BT97: active accounts, against the user limit of the license record. */
    function opsh_users_active(PDO $db): ?int {
        try { return (int)$db->query("SELECT COUNT(*) FROM opsh_users WHERE status='active'")->fetchColumn(); } catch (Throwable $e) { return null; }
    }

    /**
     * Administration → About & ownership. BT97: two views. Everyone signed in gets the short view (who owns the product,
     * that it is independent and licensed, the organization it is licensed to). The full view — the license terms, the
     * license record, the installation ID, LICENSE.txt and the third-party components — is for roles with the permission
     * "License details" (module `license`, view); Super Admin always. The short answer carries none of the full fields.
     */
    function opsh_about(PDO $db, bool $full = true): array {
        $s = [];
        try { foreach ($db->query("SELECT skey, sval FROM opsh_settings WHERE skey IN ('installed_at')") as $r) $s[$r['skey']] = (string)$r['sval']; } catch (Throwable $e) {}
        $short = [
            'full' => false,
            'product' => OPSH_PRODUCT, 'product_line' => OPSH_PRODUCT_LINE, 'app_name' => opsh_app_name(),
            'owner' => OPSH_OWNER, 'copyright' => opsh_copyright(),
            'developed_by' => OPSH_PRODUCT . ' is designed, developed and owned by ' . OPSH_OWNER . ', drawing on 30 years managing IT operations for multiple companies in Puerto Rico and Florida.',
            'independent' => opsh_independent(),
            'licensed' => OPSH_PRODUCT . ' is proprietary software. It is licensed, not sold.',
            'licensee' => opsh_org_name($db),
        ];
        if (!$full) return $short;
        $lic = @file_get_contents(dirname(__DIR__, 2) . '/LICENSE.txt');
        return array_merge($short, [
            'full' => true,
            'license' => [
                OPSH_PRODUCT . ' is proprietary software. It is licensed, not sold.',
                'Each organization uses ' . OPSH_PRODUCT . ' under its own written license agreement with the owner. That agreement sets the term, the installation, the number of users and the modules.',
                'A license does not transfer ownership of the software, its source code, design, database structure, documentation, or any change or improvement made to it.',
                'Unless the license agreement allows it in writing: no copying beyond the licensed installation and its backups, no redistribution, sublicensing, resale or hosting for others, no reverse engineering, and no removal of these notices.',
                'The licensed organization owns its own data. The owner of ' . OPSH_PRODUCT . ' claims no right to it.',
                'Names of other products (for example Microsoft 365 or vTiger) only identify the services ' . OPSH_PRODUCT . ' connects to. They belong to their owners and imply no affiliation or endorsement.',
                'Ideas, requests, feedback or testing by an organization do not make it an owner or co-author of ' . OPSH_PRODUCT . ' or of any feature made from them. Every update, module and customization belongs to the owner and is licensed under the organization\'s agreement.',
                'The owner is an independent software vendor, not an employee or agent of any licensed organization. Any other work the owner does for an organization is separate from the license and transfers no right in ' . OPSH_PRODUCT . '.',
            ],
            'install_id' => opsh_install_id($db),
            'installed_at' => $s['installed_at'] ?? '',
            'license_record' => opsh_license_record($db),
            'license_notice' => (function () use ($db) { require_once __DIR__ . '/License.php'; return opsh_license_notice(opsh_license_status($db, opsh_license_record($db))); })(),   // BT105
            'users_active' => opsh_users_active($db),
            'license_text' => is_string($lic) ? mb_substr($lic, 0, 60000) : '',
            'third_party' => opsh_third_party(),
        ]);
    }
}
