<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Per-tenant email delivery settings. Each organization can send through its own mailbox/provider —
 * a mailbox on its own domain (cPanel or any SMTP submission endpoint) or, for Microsoft 365, a connected mailbox sent through
 * Microsoft Graph — independent of the platform's mail. (v1.172.6: the Google and Microsoft password-SMTP presets were removed:
 * both providers reject ordinary passwords, so offering them only produced failed tests.)
 *
 * Resolution: a tenant with transport='smtp' delivers through its own settings, EVEN IF platform mail
 * is disabled (the SaaS's mail and each tenant's mail are independent). Everything else falls back to
 * the platform default. The SMTP password is encrypted at rest (tenant-scoped AAD) and is write-only:
 * it is never returned to the browser, and leaving the field blank on save keeps the existing one.
 */
final class TenantMailService
{
    /** Presets surfaced in the UI as one-click fills. */
    public const PRESETS = [
        'cpanel'    => ['label' => 'cPanel / own domain',      'host' => 'mail.yourdomain.com', 'port' => 465, 'secure' => 'ssl',
                        'hint' => 'Create a mailbox in cPanel → Email Accounts; host is usually mail.<your domain>.'],
    ];

    private const AAD = '|tenant_mail_settings|smtp_pass';

    /** Settings row for display: password never included, only whether one is stored. */
    public static function get(string $tenantId): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM tenant_mail_settings WHERE tenant_id = ? LIMIT 1');
        $st->execute([$tenantId]);
        $row = $st->fetch();
        if (!$row) return null;
        $row['has_password'] = !empty($row['smtp_pass_enc']);
        unset($row['smtp_pass_enc']);
        return $row;
    }

    /** Save settings. Blank password keeps the stored one; a new value replaces it (encrypted). */
    public static function save(TenantContext $ctx, array $in): array
    {
        $transport = ($in['transport'] ?? 'platform') === 'smtp' ? 'smtp' : 'platform';
        $fromEmail = strtolower(trim((string) ($in['from_email'] ?? '')));
        $fromName  = trim((string) ($in['from_name'] ?? ''));
        $host      = trim((string) ($in['smtp_host'] ?? ''));
        $port      = (int) ($in['smtp_port'] ?? 587);
        $secure    = in_array($in['smtp_secure'] ?? '', ['tls', 'ssl', 'none'], true) ? $in['smtp_secure'] : 'tls';
        $user      = trim((string) ($in['smtp_user'] ?? ''));
        $passIn    = (string) ($in['smtp_pass'] ?? '');

        if ($transport === 'smtp') {
            if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) return [false, 'Enter a valid From email.'];
            if ($host === '') return [false, 'Enter the SMTP host.'];
            if (($pe = SmtpGuard::portError($port)) !== null) return [false, $pe];
            if (($he = SmtpGuard::hostError($host)) !== null) return [false, $he];
        }

        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT id, smtp_pass_enc FROM tenant_mail_settings WHERE tenant_id = ? LIMIT 1');
        $st->execute([$ctx->tenantId]);
        $existing = $st->fetch();

        $passEnc = $existing['smtp_pass_enc'] ?? null;
        if ($passIn !== '') {
            $passEnc = Crypto::encrypt($passIn, $ctx->tenantId . self::AAD);
        }
        $now = utc_now();
        if ($existing) {
            $pdo->prepare(
                'UPDATE tenant_mail_settings SET transport=?, from_email=?, from_name=?, smtp_host=?, smtp_port=?,
                        smtp_secure=?, smtp_user=?, smtp_pass_enc=?, updated_by=?, updated_at=?, last_error=NULL
                 WHERE tenant_id=?'
            )->execute([$transport, $fromEmail ?: null, $fromName ?: null, $host ?: null, $port, $secure,
                        $user ?: null, $passEnc, $ctx->userId, $now, $ctx->tenantId]);
        } else {
            $pdo->prepare(
                'INSERT INTO tenant_mail_settings (id, tenant_id, transport, from_email, from_name, smtp_host, smtp_port,
                        smtp_secure, smtp_user, smtp_pass_enc, updated_by, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([uuid4(), $ctx->tenantId, $transport, $fromEmail ?: null, $fromName ?: null, $host ?: null, $port,
                        $secure, $user ?: null, $passEnc, $ctx->userId, $now]);
        }
        return [true, $transport === 'smtp' ? 'Email delivery settings saved. Send a test to verify.' : 'Using the platform default for email delivery.'];
    }

    /**
     * Delivery config for a tenant in the exact shape MailService::deliver expects, or null when the
     * tenant uses the platform default. Decrypts the password just-in-time for the send.
     */
    public static function cfgFor(?string $tenantId): ?array
    {
        if ($tenantId === null || $tenantId === '') return null;
        $st = Database::pdo()->prepare(
            "SELECT * FROM tenant_mail_settings WHERE tenant_id = ? AND transport IN ('smtp','graph') LIMIT 1"
        );
        $st->execute([$tenantId]);
        $row = $st->fetch();
        if (!$row) return null;
        // Microsoft 365 modern auth: send through Graph with the tenant's connected mailbox.
        if (($row['transport'] ?? '') === 'graph' && !empty($row['oauth_refresh_enc'])) {
            return [
                'mail_transport' => 'graph',
                'graph_scope'    => $tenantId,
                'graph_refresh'  => (string) $row['oauth_refresh_enc'],
                'mail_from'      => (string) ($row['oauth_account'] ?: $row['from_email']),
                'mail_from_name' => (string) ($row['from_name'] ?: ''),
                'smtp_host' => '', 'smtp_port' => 0, 'smtp_secure' => '', 'smtp_user' => '', 'smtp_pass' => '',
            ];
        }
        if ((string) $row['smtp_host'] === '') return null;
        return [
            'mail_transport' => 'smtp',
            'tenant_supplied' => true,   // subject to SmtpGuard at connect time
            'smtp_host'      => (string) $row['smtp_host'],
            'smtp_port'      => (int) ($row['smtp_port'] ?: 587),
            'smtp_secure'    => (string) ($row['smtp_secure'] ?: 'tls'),
            'smtp_user'      => (string) ($row['smtp_user'] ?? ''),
            'smtp_pass'      => (string) (Crypto::decrypt($row['smtp_pass_enc'] ?? null, $tenantId . self::AAD) ?? ''),
            'mail_from'      => (string) ($row['from_email'] ?? ''),
            'mail_from_name' => (string) ($row['from_name'] ?: ''),
        ];
    }

    /** Record the outcome of a test send. */
    public static function markTest(string $tenantId, bool $ok, string $error = ''): void
    {
        Database::pdo()->prepare(
            'UPDATE tenant_mail_settings SET verified_at = ?, last_error = ? WHERE tenant_id = ?'
        )->execute([$ok ? utc_now() : null, $ok ? null : mb_substr($error, 0, 255), $tenantId]);
    }
}
