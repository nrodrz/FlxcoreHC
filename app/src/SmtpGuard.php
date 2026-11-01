<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * SSRF guard for TENANT-SUPPLIED SMTP settings (review finding S-5). A tenant admin types the host and
 * port, so without a guard "Send test" becomes a port scanner for the server's internal network and
 * cloud-metadata endpoint. Rules: a real hostname or public IP; only the standard mail-submission ports;
 * every address the name resolves to must be public; and the connection is made to the address we
 * validated (not re-resolved), so DNS rebinding cannot swap in an internal address after the check.
 * Platform-level SMTP (app/config.php, set by the operator) is NOT subject to this guard.
 */
final class SmtpGuard
{
    public const PORTS = [25, 465, 587, 2525];

    /** Test hook: fn(string $host): string[] of IPs. Null = real DNS. */
    public static $resolver = null;

    public static function portError(int $port): ?string
    {
        return in_array($port, self::PORTS, true) ? null : 'SMTP port must be one of ' . implode(', ', self::PORTS) . '.';
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && !preg_match('/^(::ffff:)?(127\.|10\.|192\.168\.|169\.254\.|0\.)/i', $ip)
            && !preg_match('/^(::1|fe80:|fc|fd)/i', $ip);
    }

    /** @return string[] resolved addresses (may be empty) */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];
        if (is_callable(self::$resolver)) return array_values(array_filter((array) (self::$resolver)($host), fn($x) => is_string($x) && $x !== ''));
        $ips = [];
        // gethostbynamel()/dns_get_record() return false when the name does not resolve; (array)false is [false],
        // which used to reach isPublicIp(string) as a TypeError (HTTP 500). Keep only real address strings.
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) foreach ($v4 as $ip) if (is_string($ip) && $ip !== '') $ips[] = $ip;
        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) foreach ($v6 as $r) if (!empty($r['ipv6']) && is_string($r['ipv6'])) $ips[] = $r['ipv6'];
        return array_values(array_unique($ips));
    }

    /** Validated public address to connect to, or [null, reason]. */
    public static function resolveSafe(string $host): array
    {
        $host = strtolower(trim($host));
        if ($host === '' || strlen($host) > 253) return [null, 'Enter the SMTP host.'];
        if (!filter_var($host, FILTER_VALIDATE_IP)
            && (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) || $host === 'localhost')) {
            return [null, 'SMTP host must be a public server name such as smtp.example.com.'];
        }
        $ips = self::resolve($host);
        if ($ips === []) return [null, 'The SMTP host name could not be resolved.'];
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) return [null, 'SMTP host must be a public mail server (private and internal addresses are not allowed).'];
        }
        // Prefer IPv4 for the actual connection.
        foreach ($ips as $ip) if (strpos($ip, ':') === false) return [$ip, null];
        return [$ips[0], null];
    }

    public static function hostError(string $host): ?string
    {
        return self::resolveSafe($host)[1];
    }
}
