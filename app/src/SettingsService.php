<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Platform-level key/value settings (operator-wide, not tenant-scoped). Currently holds the
 * outbound email configuration so the operator can set SMTP from the console rather than
 * editing config files on shared hosting. Values are cached per request.
 */
final class SettingsService
{
    private static ?array $cache = null;

    /** Known email keys and their safe defaults. */
    public const EMAIL_KEYS = [
        'mail_enabled'   => '0',              // master switch
        'mail_transport' => 'mail',           // 'smtp' | 'mail'
        'mail_from'      => '',               // From: address
        'mail_from_name' => FLXCOREHC_APP_NAME,
        'smtp_host'      => '',
        'smtp_port'      => '587',
        'smtp_user'      => '',
        'smtp_pass'      => '',
        'smtp_secure'    => 'tls',            // 'tls' | 'ssl' | 'none'
        'smtp_verify_tls' => '1',             // '0' = accept a certificate that does not match the mail host (operator only)
    ];

    private static function load(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (Database::pdo()->query('SELECT skey, sval FROM platform_settings')->fetchAll() as $r) {
                    self::$cache[$r['skey']] = $r['sval'];
                }
            } catch (\Throwable $e) { /* table may not exist pre-migration */ }
        }
        return self::$cache;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::load();
        return $all[$key] ?? self::EMAIL_KEYS[$key] ?? $default;
    }

    public static function bool(string $key): bool
    {
        return self::get($key) === '1';
    }

    /** Persist a set of key => value settings. */
    public static function put(array $kv): void
    {
        $pdo = Database::pdo(); $now = utc_now();
        $stmt = $pdo->prepare(
            'INSERT INTO platform_settings (skey, sval, updated_at) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE sval = VALUES(sval), updated_at = VALUES(updated_at)'
        );
        foreach ($kv as $k => $v) {
            if ($k === 'smtp_pass') $v = self::sealPass((string) $v);
            $stmt->execute([$k, (string) $v, $now]);
        }
        self::$cache = null;   // invalidate
    }

    /** The email configuration as a plain array (password included — caller must guard access). */
    public static function emailConfig(): array
    {
        $out = [];
        foreach (self::EMAIL_KEYS as $k => $def) $out[$k] = self::get($k, $def);
        $out['smtp_pass'] = self::unsealPass((string) $out['smtp_pass']);
        return $out;
    }

    private const PASS_AAD = '|platform_settings|smtp_pass';

    /** The platform SMTP password is stored encrypted ("enc1:" + base64). Without keys (dev) it is kept as typed. */
    private static function sealPass(string $plain): string
    {
        if ($plain === '' || str_starts_with($plain, 'enc1:')) return $plain;
        try {
            $blob = Crypto::encrypt($plain, self::PASS_AAD);
            return $blob === null ? $plain : 'enc1:' . base64_encode($blob);
        } catch (\Throwable $e) { return $plain; }
    }

    /** Reads both the encrypted form and a legacy plain value saved by an earlier version. */
    private static function unsealPass(string $stored): string
    {
        if (!str_starts_with($stored, 'enc1:')) return $stored;
        $blob = base64_decode(substr($stored, 5), true);
        return $blob === false ? '' : (string) (Crypto::decrypt($blob, self::PASS_AAD) ?? '');
    }
}
