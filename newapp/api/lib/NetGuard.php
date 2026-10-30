<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — BT88 (code audit M-30 and the Microsoft 365 Lows): calls the app makes to addresses people type (webhooks, the
 * Knowledge Base assistant's trusted sources) only reach the public internet. Every address the name resolves to — IPv4
 * and IPv6, IPv4-mapped IPv6 included — must be public, and the call is pinned to the address checked, so the name cannot
 * point somewhere else between the check and the call (DNS rebinding).
 */
if (!function_exists('opsh_net_ip_public')) {
    function opsh_net_ip_public(string $ip): bool {
        $ip = trim($ip, '[] ');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = @inet_pton($ip); if ($bin === false) return false;
            if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") return opsh_net_ip_public(inet_ntop(substr($bin, 12)));   // ::ffff:a.b.c.d
            if (substr($bin, 0, 12) === str_repeat("\0", 12)) return false;                                                           // ::a.b.c.d, ::1, ::
            $b0 = ord($bin[0]); $b1 = ord($bin[1]);
            if (($b0 & 0xfe) === 0xfc) return false;                  // fc00::/7 unique local
            if ($b0 === 0xfe && ($b1 & 0xc0) === 0x80) return false; // fe80::/10 link local
            if ($b0 === 0xff) return false;                           // multicast
            if ($b0 === 0x20 && $b1 === 0x01 && ord($bin[2]) === 0x0d && ord($bin[3]) === 0xb8) return false;   // 2001:db8:: documentation
            if ($b0 === 0x00 && $b1 === 0x64 && ord($bin[2]) === 0xff && ord($bin[3]) === 0x9b) return false;   // 64:ff9b:: NAT64
            if ($b0 === 0x20 && $b1 === 0x02) return false;                                                     // 2002::/16 6to4 (review CORE-26: can carry a private IPv4)
            if ($b0 === 0x20 && $b1 === 0x01 && ord($bin[2]) === 0x00 && ord($bin[3]) === 0x00) return false;   // 2001::/32 Teredo
        } else {
            $l = ip2long($ip);
            foreach ([['100.64.0.0', 10], ['169.254.0.0', 16], ['0.0.0.0', 8], ['192.0.0.0', 24], ['198.18.0.0', 15], ['224.0.0.0', 4]] as [$n, $m])
                if (($l & (-1 << (32 - $m))) === (ip2long($n) & (-1 << (32 - $m)))) return false;
        }
        return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    /** Every address a host name resolves to (A and AAAA), or the host itself when it is an address. */
    function opsh_net_resolve(string $host): array {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];
        $ips = [];
        $recs = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach ((array)$recs as $r) { if (!empty($r['ip'])) $ips[] = $r['ip']; if (!empty($r['ipv6'])) $ips[] = $r['ipv6']; }
        if (!$ips) foreach ((array)@gethostbynamel($host) as $ip) $ips[] = $ip;
        return array_values(array_unique($ips));
    }
    /**
     * Is this https address safe to call? Returns ['ok' => true, 'host', 'port', 'ip'] (the address to pin) or
     * ['ok' => false, 'error' => sentence].
     */
    function opsh_net_check(string $url, bool $httpsOnly = true): array {
        $p = parse_url($url);
        if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), $httpsOnly ? ['https'] : ['https', 'http'], true)) return ['ok' => false, 'error' => 'The address must start with https://.'];
        if (isset($p['user']) || isset($p['pass'])) return ['ok' => false, 'error' => 'The address cannot carry a user name or password.'];
        $host = strtolower($p['host']); $port = (int)($p['port'] ?? (strtolower($p['scheme']) === 'http' ? 80 : 443));
        if (in_array($host, ['localhost', 'localhost.localdomain'], true) || preg_match('/\.(local|internal|localhost|lan|home|corp)$/', $host)) return ['ok' => false, 'error' => 'That address is inside your network: only public addresses can be called.'];
        $ips = opsh_net_resolve($host);
        if (!$ips) return ['ok' => false, 'error' => 'That address cannot be found (no DNS record).'];
        foreach ($ips as $ip) if (!opsh_net_ip_public($ip)) return ['ok' => false, 'error' => 'That address leads inside your network (' . $ip . '): only public addresses can be called.'];
        return ['ok' => true, 'host' => trim($host, '[]'), 'port' => $port, 'ip' => $ips[0]];
    }
    /** Pin a cURL handle to the address checked (no second lookup), and never follow redirects. */
    function opsh_net_pin($ch, array $chk): void {
        $ip = strpos($chk['ip'], ':') !== false ? '[' . $chk['ip'] . ']' : $chk['ip'];
        curl_setopt($ch, CURLOPT_RESOLVE, [$chk['host'] . ':' . $chk['port'] . ':' . $ip]);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        if (defined('CURLOPT_PROTOCOLS')) curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
    }
}
