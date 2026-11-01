<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Live security posture of THIS installation, for the platform operator's Security area. Checks the
 * configuration and protections that the code audit assumes — so a misconfigured deployment is
 * visible rather than silent. Reads no tenant data.
 */
final class SecurityPosture
{
    /** @return array<int,array{group:string,label:string,state:string,detail:string}> */
    public static function checks(): array
    {
        $c = [];
        $ok = fn(string $g, string $l, string $d) => ['group' => $g, 'label' => $l, 'state' => 'ok', 'detail' => $d];
        $warn = fn(string $g, string $l, string $d) => ['group' => $g, 'label' => $l, 'state' => 'warn', 'detail' => $d];
        $bad = fn(string $g, string $l, string $d) => ['group' => $g, 'label' => $l, 'state' => 'bad', 'detail' => $d];

        // ---- Transport & headers
        $appUrl = (string) config('app_url', '');
        $c[] = $appUrl === '' ? $bad('Transport', 'app_url configured', 'Not set — emailed links fall back to the Host header. Set app_url in app/config.php.')
             : (str_starts_with($appUrl, 'https://') ? $ok('Transport', 'app_url configured', $appUrl)
             : $warn('Transport', 'app_url configured', $appUrl . ' — should be https:// in production.'));
        $c[] = ((bool) config('hsts', false)) ? $ok('Transport', 'HSTS enabled', 'Strict-Transport-Security sent over HTTPS.')
             : $warn('Transport', 'HSTS enabled', 'Off. Turn on once the domain is reliably HTTPS.');
        $c[] = $ok('Transport', 'Security headers', 'CSP with per-request nonce, X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy (camera=self for document capture).');

        // ---- Environment
        $env = (string) config('app_env', 'production');
        $c[] = $env === 'production' ? $ok('Environment', 'Environment', 'production')
             : $warn('Environment', 'Environment', $env . ' — set app_env to production on a live site.');
        $c[] = (ini_get('display_errors') === '' || ini_get('display_errors') === '0') ? $ok('Environment', 'Error display', 'Errors hidden from users; logged server-side.')
             : $bad('Environment', 'Error display', 'display_errors is ON — turn it off in php.ini.');
        $c[] = version_compare(PHP_VERSION, '8.1', '>=') ? $ok('Environment', 'PHP version', PHP_VERSION)
             : $bad('Environment', 'PHP version', PHP_VERSION . ' — 8.1 or newer required.');
        foreach (['pdo_mysql', 'mbstring', 'json', 'fileinfo'] as $ext) {
            if (!extension_loaded($ext)) $c[] = $bad('Environment', 'Extension ' . $ext, 'Missing.');
        }
        $c[] = (extension_loaded('sodium') || extension_loaded('openssl'))
             ? $ok('Environment', 'Crypto extension', extension_loaded('sodium') ? 'libsodium' : 'openssl')
             : $bad('Environment', 'Crypto extension', 'Neither sodium nor openssl — encryption unavailable.');

        // ---- Files & storage
        foreach (['app', 'database', 'cron', 'tools', 'docs'] as $dir) {
            $ht = FLXCOREHC_ROOT . '/' . $dir . '/.htaccess';
            $c[] = is_file($ht) ? $ok('Files', $dir . '/ protected', 'Direct web access denied by .htaccess.')
                 : $bad('Files', $dir . '/ protected', 'No .htaccess — this directory may be web-readable.');
        }
        $docs = (string) config('docs_path', '');
        $inRoot = $docs !== '' && str_starts_with(realpath($docs) ?: $docs, realpath(FLXCOREHC_ROOT) ?: FLXCOREHC_ROOT)
                  && !str_contains($docs, 'storage');
        $c[] = $docs === '' ? $warn('Files', 'Document storage', 'docs_path not set.')
             : ($inRoot ? $warn('Files', 'Document storage', $docs . ' — consider a path outside the web root.')
             : $ok('Files', 'Document storage', $docs . ' (served only through authorized routes)'));
        $cfg = FLXCOREHC_ROOT . '/app/config.php';
        $c[] = is_file($cfg) && (fileperms($cfg) & 0044) === 0
             ? $ok('Files', 'config.php permissions', 'Not world/group readable.')
             : $warn('Files', 'config.php permissions', 'Readable by other users — chmod 600 recommended.');

        // ---- Access control & data protection (structural facts of this build)
        $c[] = $ok('Access control', 'Tenant isolation', 'All tenant queries go through the repository layer; one allow-listed cross-organization reader with per-call re-verification.');
        $c[] = $ok('Access control', 'Support sessions are blind', 'Platform support cannot read providers, credentials, documents, calendar, screenings, audit, notifications, requests or shares, and cannot export data.');
        $c[] = $ok('Access control', 'CSRF', 'Every state-changing form is token-verified.');
        // v1.172.0: the platform console's two-factor requirement is a setting; report it honestly.
        $c[] = Auth\AuthService::platformRequires2fa()
            ? $ok('Access control', 'Platform console two-factor', 'Required: a platform administrator must use a second step to open the console.')
            : $warn('Access control', 'Platform console two-factor', 'Not required. Turn it on under “Sign-in policy” above once set-up is done, and before the first customer organization is added.');
        $c[] = $ok('Data protection', 'Encryption at rest', 'NPI, date of birth, SSN last four, email, credential number and SMTP passwords encrypted; blind indexes used for matching.');
        $c[] = $ok('Data protection', 'Passwords', 'Argon2id with rehash on login; rate-limited authentication.');
        $c[] = $ok('Data protection', 'Uploads', 'MIME sniffed against an allowlist, 10 MB cap, random names, JPEG GPS metadata stripped.');
        $c[] = $ok('Data protection', 'Retention', 'Soft deletes only; permanent compliance ledger; audit log never purged.');

        // ---- Operational
        try {
            $pdo = Database::pdo();
            $stale = (int) $pdo->query("SELECT COUNT(*) FROM impersonation_requests WHERE status = 'approved' AND expires_at > NOW()")->fetchColumn();
            $c[] = $stale === 0 ? $ok('Operations', 'Open support windows', 'None active.')
                 : $warn('Operations', 'Open support windows', $stale . ' active support grant(s) — they expire automatically.');
        } catch (\Throwable $e) {}
        try {
            $pdo = Database::pdo();
            $exp = (int) $pdo->query("SELECT COUNT(*) FROM external_shares WHERE revoked_at IS NULL AND expires_at > NOW()")->fetchColumn();
            $c[] = $exp === 0 ? $ok('Operations', 'Live external share links', 'None active.')
                 : $warn('Operations', 'Live external share links', $exp . ' active link(s) across all tenants — each is password-protected and expires.');
        } catch (\Throwable $e) {}
        return $c;
    }
}
