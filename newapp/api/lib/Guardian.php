<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
if (is_file(__DIR__ . '/Graph.php')) require_once __DIR__ . '/Graph.php';
/**
 * ITOps — Server Guardian (view tier).
 *
 * A read-only server health/security surface for the host ITOps runs on. This
 * is a focused, self-contained implementation of a server guardian's *job* — surface
 * the signals that matter on a cPanel/WHM shared host — implemented natively
 * against ITOps's conventions rather than a separate package engine.
 *
 * Sources (each optional, lit up only when configured in itops_settings):
 *   - Server vitals: disk free, load average, memory (from the PHP process/OS).
 *   - WHM API: account count + suspended accounts (needs guardian_whm_* settings).
 *   - Imunify: newest scan-report signal (needs guardian_imunify_path).
 *   - IMAP: monitoring-mailbox reachability (needs guardian_imap_* settings).
 *
 * Every function returns plain arrays (no secrets) suitable for JSON. Nothing
 * here writes to the server or the tenant.
 *
 * Depends on ITOps config.php: getDB() (PDO).
 */

if (!function_exists('itops_guardian_settings')) {

    /** Read all guardian_* settings as an associative array. */
    function itops_guardian_settings(PDO $db): array {
        $rows = $db->query("SELECT skey, sval FROM itops_settings WHERE skey LIKE 'guardian\\_%'")
                   ->fetchAll(PDO::FETCH_KEY_PAIR);
        $rows = is_array($rows) ? $rows : [];
        require_once __DIR__ . '/Secrets.php';   // BT14: WHM token / IMAP password are stored encrypted
        foreach (itops_secret_setting_keys() as $k) if (isset($rows[$k])) { try { $rows[$k] = itops_secret_unwrap($rows[$k]); } catch (\Throwable $e) { $rows[$k] = ''; } }
        return $rows;
    }

    /**
     * Which sources are configured — booleans only, never the secret values.
     * This is what the /status endpoint returns.
     */
    function itops_guardian_status(PDO $db): array {
        $s = itops_guardian_settings($db);
        $label = trim((string)($s['guardian_host_label'] ?? ''));
        if ($label === '') $label = gethostname() ?: 'this-host';
        return [
            'host_label' => $label,
            'sources' => [
                'vitals'  => true, // always available
                'whm'     => trim((string)($s['guardian_whm_host'] ?? '')) !== ''
                             && trim((string)($s['guardian_whm_token'] ?? '')) !== '',
                'services'  => trim((string)($s['guardian_whm_host'] ?? '')) !== '' && trim((string)($s['guardian_whm_token'] ?? '')) !== '',
                'srvload'   => trim((string)($s['guardian_whm_host'] ?? '')) !== '' && trim((string)($s['guardian_whm_token'] ?? '')) !== '',
                'srvbackup' => trim((string)($s['guardian_whm_host'] ?? '')) !== '' && trim((string)($s['guardian_whm_token'] ?? '')) !== '',
                'cphulk'    => trim((string)($s['guardian_whm_host'] ?? '')) !== '' && trim((string)($s['guardian_whm_token'] ?? '')) !== '',
                'cpver'     => trim((string)($s['guardian_whm_host'] ?? '')) !== '' && trim((string)($s['guardian_whm_token'] ?? '')) !== '',
                'bandwidth' => trim((string)($s['guardian_whm_host'] ?? '')) !== '' && trim((string)($s['guardian_whm_token'] ?? '')) !== '',
                'mysql'     => trim((string)($s['guardian_whm_host'] ?? '')) !== '' && trim((string)($s['guardian_whm_token'] ?? '')) !== '',
                'autossl'   => trim((string)($s['guardian_whm_host'] ?? '')) !== '' && trim((string)($s['guardian_whm_token'] ?? '')) !== '',
                'inodes'    => trim((string)($s['guardian_whm_host'] ?? '')) !== '' && trim((string)($s['guardian_whm_token'] ?? '')) !== '',
                'domains'   => trim((string)($s['guardian_whm_host'] ?? '')) !== '' && trim((string)($s['guardian_whm_token'] ?? '')) !== '',
                'mailqueue' => true,
                'updates'   => trim((string)($s['guardian_whm_host'] ?? '')) !== '' && trim((string)($s['guardian_whm_token'] ?? '')) !== '',
                'imunify' => trim((string)($s['guardian_imunify_path'] ?? '')) !== '',
                'imap'    => trim((string)($s['guardian_imap_host'] ?? '')) !== ''
                             && trim((string)($s['guardian_imap_user'] ?? '')) !== '',
                'defender' => itops_guardian_m365_ready(),
                'intune'   => itops_guardian_m365_ready(),
                'mfa'      => itops_guardian_m365_ready(),
                'ssl'      => trim((string)($s['guardian_ssl_hosts'] ?? '')) !== '',
                'backup'   => true,
                'php_eol'  => true,
            ],
        ];
    }

    // ---- individual sections (each returns a normalized section array) --------

    /** Server vitals: disk, load, memory. Always available. */
    function itops_guardian_vitals(): array {
        $findings = [];
        $level = 'ok';

        // Disk free on the app's own filesystem.
        $root = defined('ITOPS_ROOT') ? ITOPS_ROOT : __DIR__;
        $free = @disk_free_space($root);
        $total = @disk_total_space($root);
        if ($free !== false && $total && $total > 0) {
            $pctFree = round(100 * $free / $total, 1);
            $sev = $pctFree < 5 ? 'error' : ($pctFree < 15 ? 'warning' : 'ok');
            if ($sev !== 'ok') $level = $sev === 'error' ? 'error' : ($level === 'error' ? 'error' : 'warning');
            $findings[] = [
                'label' => 'Disk free',
                'value' => $pctFree.'% free ('.itops_guardian_bytes($free).' of '.itops_guardian_bytes($total).')',
                'severity' => $sev,
            ];
        }

        // Load average (Linux only).
        if (function_exists('sys_getloadavg')) {
            $la = sys_getloadavg();
            if (is_array($la) && isset($la[0])) {
                $cpus = itops_guardian_cpu_count();
                $per = $cpus > 0 ? round($la[0] / $cpus, 2) : $la[0];
                $sev = $per > 2 ? 'error' : ($per > 1 ? 'warning' : 'ok');
                if ($sev !== 'ok') $level = $sev === 'error' ? 'error' : ($level === 'error' ? 'error' : 'warning');
                $findings[] = [
                    'label' => 'Load average (1m)',
                    'value' => $la[0].' over '.$cpus.' CPU'.($cpus === 1 ? '' : 's').' ('.$per.' per core)',
                    'severity' => $sev,
                ];
            }
        }

        // PHP process memory (informational).
        $findings[] = [
            'label' => 'PHP memory in use',
            'value' => itops_guardian_bytes(memory_get_usage(true)).' (peak '.itops_guardian_bytes(memory_get_peak_usage(true)).')',
            'severity' => 'ok',
        ];

        return ['name' => 'vitals', 'title' => 'Server vitals', 'level' => $level, 'findings' => $findings];
    }


    /** Imunify: newest scan report signal. Requires guardian_imunify_path (a file the scanner writes). */
    function itops_guardian_imunify(array $s): array {
        $path = trim((string)($s['guardian_imunify_path'] ?? ''));
        if ($path === '') {
            return ['name' => 'imunify', 'title' => 'Malware scan (Imunify)', 'level' => 'off',
                    'findings' => [['label' => 'Imunify', 'value' => 'not configured', 'severity' => 'off']]];
        }
        if (!@is_readable($path)) {
            return ['name' => 'imunify', 'title' => 'Malware scan (Imunify)', 'level' => 'warning',
                    'findings' => [['label' => 'Report file', 'value' => 'not readable at configured path', 'severity' => 'warning']]];
        }
        $raw = (string)@file_get_contents($path);
        $mtime = @filemtime($path) ?: time();
        $ageH = round((time() - $mtime) / 3600, 1);
        // Look for a malicious/infected count in JSON or plain text.
        $infected = 0;
        $j = json_decode($raw, true);
        if (is_array($j)) {
            $infected = (int)($j['malicious'] ?? $j['infected'] ?? $j['detected'] ?? 0);
        } elseif (preg_match('/(\d+)\s+(?:malicious|infected|detected)/i', $raw, $m)) {
            $infected = (int)$m[1];
        }
        $sev = $infected > 0 ? 'error' : ($ageH > 48 ? 'warning' : 'ok');
        return ['name' => 'imunify', 'title' => 'Malware scan (Imunify)', 'level' => $sev, 'findings' => [
            ['label' => 'Detected items', 'value' => (string)$infected, 'severity' => $infected > 0 ? 'error' : 'ok'],
            ['label' => 'Report age', 'value' => itops_guardian_age($ageH), 'severity' => $ageH > 48 ? 'warning' : 'ok'],
        ]];
    }

    /** IMAP: can we reach and log into the monitoring mailbox? Requires the php-imap extension. */
    function itops_guardian_imap(array $s): array {
        $host = trim((string)($s['guardian_imap_host'] ?? ''));
        $user = trim((string)($s['guardian_imap_user'] ?? ''));
        $pass = (string)($s['guardian_imap_pass'] ?? '');
        if ($host === '' || $user === '') {
            return ['name' => 'imap', 'title' => 'Mailbox reachability (IMAP)', 'level' => 'off',
                    'findings' => [['label' => 'IMAP', 'value' => 'not configured', 'severity' => 'off']]];
        }
        if (!function_exists('imap_open')) {
            return ['name' => 'imap', 'title' => 'Mailbox reachability (IMAP)', 'level' => 'warning',
                    'findings' => [['label' => 'IMAP', 'value' => 'php-imap extension not available on this server', 'severity' => 'warning']]];
        }
        $mailbox = '{'.$host.':993/imap/ssl}INBOX';
        $mbox = @imap_open($mailbox, $user, $pass, 0, 1);
        if ($mbox === false) {
            $err = imap_last_error() ?: 'connection/login failed';
            return ['name' => 'imap', 'title' => 'Mailbox reachability (IMAP)', 'level' => 'error',
                    'findings' => [['label' => 'Login', 'value' => 'failed: '.$err, 'severity' => 'error']]];
        }
        $n = @imap_num_msg($mbox);
        @imap_close($mbox);
        return ['name' => 'imap', 'title' => 'Mailbox reachability (IMAP)', 'level' => 'ok', 'findings' => [
            ['label' => 'Login', 'value' => 'ok', 'severity' => 'ok'],
            ['label' => 'Messages in INBOX', 'value' => (string)(int)$n, 'severity' => 'ok'],
        ]];
    }

    /** Run all configured sections and return them. */
    // ---- Microsoft 365 security checks (reuse the existing Graph connection) ----

    function itops_guardian_m365_ready(): bool {
        return function_exists('itops_graph_configured') && itops_graph_configured();
    }

    /** Microsoft Defender: Secure Score %, active incidents, new high/medium alerts. */
    function itops_guardian_defender(): array {
        if (!itops_guardian_m365_ready()) {
            return ['name' => 'defender', 'title' => 'Microsoft Defender', 'level' => 'off',
                    'findings' => [['label' => 'Defender', 'value' => 'connect Microsoft 365 to enable', 'severity' => 'off']]];
        }
        try {
            $level = 'ok'; $findings = [];
            $sc = itops_graph_get('security/secureScores?$top=1');
            $row = $sc['value'][0] ?? null;
            if ($row) {
                $cur = (float)($row['currentScore'] ?? 0); $max = (float)($row['maxScore'] ?? 0);
                $pct = $max > 0 ? round(100 * $cur / $max, 1) : 0;
                $sev = $pct < 40 ? 'error' : ($pct < 60 ? 'warning' : 'ok');
                if ($sev !== 'ok') $level = $sev === 'error' ? 'error' : 'warning';
                $findings[] = ['label' => 'Secure Score', 'value' => $pct.'%', 'severity' => $sev];
            }
            // Incidents/alerts need SecurityIncident.Read.All / SecurityEvents.Read.All. If a
            // call is not permitted, skip that line instead of failing the whole card.
            try {
                $inc = itops_graph_get("security/incidents?\$filter=status eq 'active'");
                $nInc = count($inc['value'] ?? []);
                $findings[] = ['label' => 'Active incidents', 'value' => (string)$nInc, 'severity' => $nInc > 0 ? 'error' : 'ok'];
                if ($nInc > 0) $level = 'error';
            } catch (\Throwable $e) {
                $findings[] = ['label' => 'Active incidents', 'value' => 'needs SecurityIncident.Read.All', 'severity' => 'off'];
            }
            try {
                $al = itops_graph_get("security/alerts_v2?\$filter=status eq 'new'");
                $high = 0; $med = 0;
                foreach (($al['value'] ?? []) as $a) { $sv = strtolower((string)($a['severity'] ?? '')); if ($sv === 'high') $high++; elseif ($sv === 'medium') $med++; }
                $findings[] = ['label' => 'New alerts', 'value' => $high.' high / '.$med.' medium', 'severity' => $high > 0 ? 'error' : ($med > 0 ? 'warning' : 'ok')];
                if ($high > 0) $level = 'error'; elseif ($med > 0 && $level === 'ok') $level = 'warning';
            } catch (\Throwable $e) {
                $findings[] = ['label' => 'New alerts', 'value' => 'needs SecurityAlert.Read.All', 'severity' => 'off'];
            }
            return ['name' => 'defender', 'title' => 'Microsoft Defender', 'level' => $level, 'findings' => $findings];
        } catch (\Throwable $e) {
            return ['name' => 'defender', 'title' => 'Microsoft Defender', 'level' => 'warning',
                    'findings' => [['label' => 'Defender', 'value' => 'Graph error: '.$e->getMessage(), 'severity' => 'warning']]];
        }
    }

    /** Intune: managed device compliance breakdown. */
    function itops_guardian_intune(): array {
        if (!itops_guardian_m365_ready()) {
            return ['name' => 'intune', 'title' => 'Device compliance (Intune)', 'level' => 'off',
                    'findings' => [['label' => 'Intune', 'value' => 'connect Microsoft 365 to enable', 'severity' => 'off']]];
        }
        try {
            $r = itops_graph_get('deviceManagement/managedDevices?$select=complianceState&$top=200');
            $total = 0; $ok = 0; $bad = 0; $grace = 0;
            foreach (($r['value'] ?? []) as $d) {
                $total++;
                switch (strtolower((string)($d['complianceState'] ?? ''))) {
                    case 'compliant': $ok++; break;
                    case 'noncompliant': $bad++; break;
                    case 'ingraceperiod': $grace++; break;
                }
            }
            if ($total === 0) return ['name' => 'intune', 'title' => 'Device compliance (Intune)', 'level' => 'ok',
                'findings' => [['label' => 'Managed devices', 'value' => 'none enrolled', 'severity' => 'ok']]];
            $sev = $bad > 0 ? 'error' : ($grace > 0 ? 'warning' : 'ok');
            return ['name' => 'intune', 'title' => 'Device compliance (Intune)', 'level' => $sev, 'findings' => [
                ['label' => 'Compliant', 'value' => $ok.' of '.$total, 'severity' => 'ok'],
                ['label' => 'Non-compliant', 'value' => (string)$bad, 'severity' => $bad > 0 ? 'error' : 'ok'],
                ['label' => 'In grace period', 'value' => (string)$grace, 'severity' => $grace > 0 ? 'warning' : 'ok'],
            ]];
        } catch (\Throwable $e) {
            return ['name' => 'intune', 'title' => 'Device compliance (Intune)', 'level' => 'warning',
                    'findings' => [['label' => 'Intune', 'value' => 'Graph error: '.$e->getMessage(), 'severity' => 'warning']]];
        }
    }

    /** Email security (SPF / DKIM / DMARC) across verified domains — DNS only, cheap. */
    function itops_guardian_mailsec(): array {
        if (!itops_guardian_m365_ready()) {
            return ['name' => 'mailsec', 'title' => 'Email security (SPF/DKIM/DMARC)', 'level' => 'off',
                    'findings' => [['label' => 'Email security', 'value' => 'connect Microsoft 365 to enable', 'severity' => 'off']]];
        }
        try {
            require_once __DIR__ . '/EmailSecurity.php';
            $r = itops_graph_get('domains?$select=id,isVerified,supportedServices');
            $level = 'ok'; $findings = [];
            foreach (($r['value'] ?? []) as $d) {
                $name = strtolower((string)($d['id'] ?? ''));
                if ($name === '' || empty($d['isVerified'])) continue;
                if (itops_mailsec_skip_reason($name, isset($d['supportedServices']) ? (array)$d['supportedServices'] : null) !== '') continue;   // vendor / Microsoft / non-mail domains
                $c = itops_mailsec_domain($name);
                $bits = [];
                $bits[] = 'SPF ' . ($c['spf']['ok'] ? 'ok' : 'MISSING/WRONG');
                $bits[] = 'DKIM ' . ($c['dkim']['ok'] ? 'ok' : 'MISSING');
                $bits[] = 'DMARC ' . strtoupper($c['dmarc']['grade']);
                $sev = $c['level'] === 'ok' ? 'ok' : ($c['level'] === 'warning' ? 'warning' : 'error');
                if ($sev === 'error') $level = 'error'; elseif ($sev === 'warning' && $level === 'ok') $level = 'warning';
                $findings[] = ['label' => $name, 'value' => implode(' · ', $bits), 'severity' => $sev];
            }
            if (!$findings) $findings[] = ['label' => 'Domains', 'value' => 'no custom domains to check', 'severity' => 'ok'];
            return ['name' => 'mailsec', 'title' => 'Email security (SPF/DKIM/DMARC)', 'level' => $level, 'findings' => $findings];
        } catch (\Throwable $e) {
            return ['name' => 'mailsec', 'title' => 'Email security (SPF/DKIM/DMARC)', 'level' => 'warning',
                    'findings' => [['label' => 'Email security', 'value' => 'check failed: ' . $e->getMessage(), 'severity' => 'warning']]];
        }
    }

    /** MFA / 2FA registration adoption across the tenant. */
    function itops_guardian_mfa(): array {
        if (!itops_guardian_m365_ready()) {
            return ['name' => 'mfa', 'title' => '2FA adoption', 'level' => 'off',
                    'findings' => [['label' => '2FA', 'value' => 'connect Microsoft 365 to enable', 'severity' => 'off']]];
        }
        try {
            $r = itops_graph_get('reports/authenticationMethods/userRegistrationDetails?$top=500');
            $total = 0; $reg = 0;
            foreach (($r['value'] ?? []) as $u) { $total++; if (!empty($u['isMfaRegistered'])) $reg++; }
            if ($total === 0) return ['name' => 'mfa', 'title' => '2FA adoption', 'level' => 'ok',
                'findings' => [['label' => '2FA', 'value' => 'no users reported', 'severity' => 'ok']]];
            $pct = round(100 * $reg / $total, 1);
            $sev = $pct < 80 ? 'error' : ($pct < 95 ? 'warning' : 'ok');
            return ['name' => 'mfa', 'title' => '2FA adoption', 'level' => $sev, 'findings' => [
                ['label' => 'MFA registered', 'value' => $reg.' of '.$total.' ('.$pct.'%)', 'severity' => $sev],
            ]];
        } catch (\Throwable $e) {
            return ['name' => 'mfa', 'title' => '2FA adoption', 'level' => 'warning',
                    'findings' => [['label' => '2FA', 'value' => 'Graph error: '.$e->getMessage(), 'severity' => 'warning']]];
        }
    }

    // ---- Server-side checks ----------------------------------------------------

    /** TLS certificate expiry for a configured list of hostnames (guardian_ssl_hosts). */
    function itops_guardian_ssl(array $s): array {
        $hosts = array_values(array_filter(array_map('trim', explode(',', (string)($s['guardian_ssl_hosts'] ?? '')))));
        if (!$hosts) {
            return ['name' => 'ssl', 'title' => 'TLS certificates', 'level' => 'off',
                    'findings' => [['label' => 'SSL', 'value' => 'add hostnames in Server actions to monitor', 'severity' => 'off']]];
        }
        if (!function_exists('stream_socket_client') || !function_exists('openssl_x509_parse')) {
            return ['name' => 'ssl', 'title' => 'TLS certificates', 'level' => 'warning',
                    'findings' => [['label' => 'SSL', 'value' => 'openssl/stream functions unavailable on this server', 'severity' => 'warning']]];
        }
        $level = 'ok'; $findings = [];
        foreach (array_slice($hosts, 0, 25) as $host) {
            $port = 443; $h = $host;
            if (strpos($host, ':') !== false) { [$h, $p] = explode(':', $host, 2); $port = (int)$p ?: 443; }
            try {
                $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $h]]);
                $cl = @stream_socket_client('ssl://'.$h.':'.$port, $errno, $errstr, 7, STREAM_CLIENT_CONNECT, $ctx);
                if (!$cl) { $findings[] = ['label' => $h, 'value' => 'unreachable: '.$errstr, 'severity' => 'warning']; if ($level === 'ok') $level = 'warning'; continue; }
                $params = stream_context_get_params($cl); fclose($cl);
                $cert = $params['options']['ssl']['peer_certificate'] ?? null;
                $info = $cert ? openssl_x509_parse($cert) : null;
                $exp = $info['validTo_time_t'] ?? 0;
                if (!$exp) { $findings[] = ['label' => $h, 'value' => 'no certificate returned', 'severity' => 'warning']; if ($level === 'ok') $level = 'warning'; continue; }
                $days = (int)floor(($exp - time()) / 86400);
                $sev = $days < 0 ? 'error' : ($days <= 14 ? 'error' : ($days <= 30 ? 'warning' : 'ok'));
                if ($sev === 'error') $level = 'error'; elseif ($sev === 'warning' && $level === 'ok') $level = 'warning';
                $findings[] = ['label' => $h, 'value' => $days < 0 ? ('EXPIRED '.abs($days).'d ago') : ($days.' days left ('.date('m/d/Y', $exp).')'), 'severity' => $sev];
            } catch (\Throwable $e) {
                $findings[] = ['label' => $h, 'value' => 'error: '.$e->getMessage(), 'severity' => 'warning'];
                if ($level === 'ok') $level = 'warning';
            }
        }
        return ['name' => 'ssl', 'title' => 'TLS certificates', 'level' => $level, 'findings' => $findings];
    }

    /** Backup assurance: derived from logged backup verifications (itops_event_log channel=backup). */
    function itops_guardian_backup(PDO $db): array {
        try {
            $st = $db->query("SELECT MAX(ts) AS last, SUM(CASE WHEN level='error' THEN 1 ELSE 0 END) AS fails
                              FROM itops_event_log WHERE channel='backup' AND ts >= (NOW() - INTERVAL 8 DAY)");
            $r = $st ? $st->fetch() : null;
        } catch (\Throwable $e) {
            return ['name' => 'backup', 'title' => 'Backup assurance', 'level' => 'off',
                    'findings' => [['label' => 'Backups', 'value' => 'run db-update.php to enable the event log', 'severity' => 'off']]];
        }
        $last = $r['last'] ?? null;
        if (!$last) {
            return ['name' => 'backup', 'title' => 'Backup assurance', 'level' => 'warning', 'findings' => [
                ['label' => 'Last verified', 'value' => 'no verification logged in 8 days', 'severity' => 'warning'],
                ['label' => 'How', 'value' => 'log one in Server actions, or POST /api/guardian/backup_check', 'severity' => 'off'],
            ]];
        }
        $ageH = (time() - strtotime($last)) / 3600;
        $sev = $ageH > 48 ? 'error' : ($ageH > 26 ? 'warning' : 'ok');
        $fails = (int)($r['fails'] ?? 0);
        $findings = [['label' => 'Last verified', 'value' => itops_guardian_age($ageH).' ago ('.substr($last, 0, 16).')', 'severity' => $sev]];
        if ($fails > 0) $findings[] = ['label' => 'Failures (8d)', 'value' => (string)$fails, 'severity' => 'error'];
        return ['name' => 'backup', 'title' => 'Backup assurance', 'level' => $fails > 0 ? 'error' : $sev, 'findings' => $findings];
    }

    /** PHP end-of-life: the running PHP version against known EOL dates. */
    function itops_guardian_php_eol(): array {
        $eol = ['5.6' => '2018-12-31', '7.0' => '2019-01-10', '7.1' => '2019-12-01', '7.2' => '2020-11-30',
                '7.3' => '2021-12-06', '7.4' => '2022-11-28', '8.0' => '2023-11-26', '8.1' => '2025-12-31',
                '8.2' => '2026-12-31', '8.3' => '2027-12-31', '8.4' => '2028-12-31'];
        $mm = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        $when = $eol[$mm] ?? null;
        if ($when === null) {
            return ['name' => 'php_eol', 'title' => 'PHP version', 'level' => 'ok',
                    'findings' => [['label' => 'PHP', 'value' => PHP_VERSION.' (supported)', 'severity' => 'ok']]];
        }
        $days = (int)floor((strtotime($when) - time()) / 86400);
        $sev = $days < 0 ? 'error' : ($days <= 90 ? 'warning' : 'ok');
        return ['name' => 'php_eol', 'title' => 'PHP version', 'level' => $sev, 'findings' => [
            ['label' => 'Running', 'value' => PHP_VERSION, 'severity' => $sev],
            ['label' => $days < 0 ? 'End of life' : 'EOL', 'value' => $days < 0 ? ('reached '.$when.' — upgrade') : ($when.' ('.$days.' days)'), 'severity' => $sev],
        ]];
    }

    // ---- WHM / cPanel server + accounts (uses the WHM API token) ----------------

    /** Resolve + sanitize WHM connection settings once. Returns [host,port,user,token] or null. */
    function itops_whm_conn(array $s): ?array {
        $host  = trim((string)($s['guardian_whm_host'] ?? ''));
        $token = trim((string)($s['guardian_whm_token'] ?? ''));
        $user  = trim((string)($s['guardian_whm_user'] ?? 'root')) ?: 'root';
        $port  = (int)($s['guardian_whm_port'] ?? 2087);
        $host = preg_replace('~^https?://~i', '', $host);
        $host = preg_replace('~/.*$~', '', $host);
        if (strpos($host, ':') !== false) { [$host, $hp] = explode(':', $host, 2); if (ctype_digit($hp)) $port = (int)$hp; }
        if ($port <= 0) $port = 2087;
        if ($host === '' || $token === '') return null;
        return ['host' => $host, 'port' => $port, 'user' => $user, 'token' => $token, 'pin' => trim((string)($s['guardian_whm_pin'] ?? ''))];
    }

    /**
     * BT91 (code audit M-24): the WHM token (root) goes only to the real WHM. The certificate is checked like any https
     * site; a WHM with a self-signed certificate is trusted by pinning ITS key (Guardian → Configure server checks →
     * "Trust this certificate", Super Admin): then only a server holding that key gets the token.
     */
    function itops_whm_tls_opts(array $c): array {
        $pin = trim((string)($c['pin'] ?? ''));
        if ($pin !== '' && preg_match('~^sha256//[A-Za-z0-9+/=]{40,64}$~', $pin) && defined('CURLOPT_PINNEDPUBLICKEY'))
            return [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_PINNEDPUBLICKEY => $pin];
        return [CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
    }
    /** The certificate WHM shows now: subject, issuer, expiry, whether a trusted authority signed it, and its key pin. */
    function itops_whm_cert_info(string $host, int $port): array {
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $fp = @stream_socket_client('ssl://' . $host . ':' . $port, $en, $es, 8, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) return ['ok' => false, 'error' => 'Could not reach ' . $host . ':' . $port . ' (' . trim((string)$es) . ').'];
        $cert = stream_context_get_params($fp)['options']['ssl']['peer_certificate'] ?? null; fclose($fp);
        if (!$cert) return ['ok' => false, 'error' => 'The server sent no certificate.'];
        $x = openssl_x509_parse($cert) ?: [];
        $pub = openssl_pkey_get_details(openssl_pkey_get_public($cert))['key'] ?? '';
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pub));
        $trusted = false;
        $ctx2 = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $fp2 = @stream_socket_client('ssl://' . $host . ':' . $port, $en2, $es2, 8, STREAM_CLIENT_CONNECT, $ctx2);
        if ($fp2) { $trusted = true; fclose($fp2); }
        $nm = function ($a) { $a = (array)$a; return (string)($a['CN'] ?? ($a['O'] ?? '')); };
        return ['ok' => true, 'subject' => $nm($x['subject'] ?? []), 'issuer' => $nm($x['issuer'] ?? []), 'expires' => isset($x['validTo_time_t']) ? date('Y-m-d', (int)$x['validTo_time_t']) : '',
                'trusted' => $trusted, 'pin' => 'sha256//' . base64_encode(hash('sha256', $der, true))];
    }

    /** Call a WHM API 1 function. Returns ['ok'=>bool,'code'=>int,'data'=>array,'meta'=>array,'error'=>?]. */
    function itops_whm_call(array $c, string $fn, array $params = [], int $timeout = 8): array {
        $qs = http_build_query(array_merge(['api.version' => 1], $params));
        $url = 'https://'.$c['host'].':'.$c['port'].'/json-api/'.$fn.($qs ? ('?'.$qs) : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Authorization: whm '.$c['user'].':'.$c['token']],
        ] + itops_whm_tls_opts($c));
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false || $code >= 400) {
            if (in_array($errno, [35, 51, 58, 60, 77, 83, 90], true)) $err = 'The WHM certificate could not be checked (' . $err . '). If WHM uses its own (self-signed) certificate, a Super Admin trusts it under Server Guardian → Configure server checks → WHM certificate.';
            return ['ok' => false, 'code' => $code, 'data' => [], 'meta' => [], 'error' => $err ?: ('HTTP '.$code)];
        }
        $j = json_decode((string)$resp, true);
        if (!is_array($j)) return ['ok' => false, 'code' => $code, 'data' => [], 'meta' => [], 'error' => 'bad JSON'];
        // WHM API 1 wraps results in {data:{...}, metadata:{result,reason}}
        $meta = $j['metadata'] ?? ($j['result'] ?? []);
        $okFlag = isset($meta['result']) ? ((int)$meta['result'] === 1) : true;
        return ['ok' => $okFlag, 'code' => $code, 'data' => $j['data'] ?? $j, 'meta' => $meta, 'error' => $okFlag ? null : ($meta['reason'] ?? 'API error')];
    }

    /** Try several WHM function names (versions differ); return the first that succeeds. */
    function itops_whm_call_first(array $c, array $fns, array $params = [], int $timeout = 12): array {
        $last = ['ok' => false, 'code' => 0, 'data' => [], 'meta' => [], 'error' => 'no functions tried'];
        foreach ($fns as $fn) {
            $r = itops_whm_call($c, $fn, $params, $timeout);
            if ($r['ok']) return $r;
            $last = $r;
            // If the error is anything other than "unknown app", stop — the function exists but failed.
            if (stripos((string)($r['error'] ?? ''), 'unknown app') === false) return $r;
        }
        return $last;
    }

    /** WHM accounts (enriched): totals, suspended, disk pressure, largest, resellers. */
    function itops_guardian_whm(array $s): array {
        $c = itops_whm_conn($s);
        if (!$c) {
            return ['name' => 'whm', 'title' => 'cPanel accounts', 'level' => 'off',
                    'findings' => [['label' => 'WHM', 'value' => 'add WHM host + token in Server actions', 'severity' => 'off']]];
        }
        $r = itops_whm_call($c, 'listaccts');
        if (!$r['ok']) {
            return ['name' => 'whm', 'title' => 'cPanel accounts', 'level' => 'warning',
                    'findings' => [['label' => 'WHM API', 'value' => 'unreachable ('.($r['error'] ?: ('HTTP '.$r['code'])).')', 'severity' => 'warning']]];
        }
        $accts = $r['data']['acct'] ?? [];
        $count = is_array($accts) ? count($accts) : 0;
        $suspended = 0; $resellers = 0; $overDisk = []; $biggest = ['user' => '', 'pct' => -1, 'used' => ''];
        foreach ((array)$accts as $a) {
            if (!empty($a['suspended'])) $suspended++;
            if (!empty($a['reseller'])) $resellers++;
            // disk: diskused / disklimit (both like "1234M" or "unlimited")
            $limRaw = (string)($a['disklimit'] ?? 'unlimited');
            $usedRaw = (string)($a['diskused'] ?? '0M');
            if (stripos($limRaw, 'unlim') === false) {
                $lim = (float)$limRaw; $used = (float)$usedRaw;
                if ($lim > 0) {
                    $pct = round(100 * $used / $lim);
                    if ($pct >= 90) $overDisk[] = ($a['user'] ?? '?').' ('.$pct.'%)';
                    if ($pct > $biggest['pct']) $biggest = ['user' => (string)($a['user'] ?? '?'), 'pct' => $pct, 'used' => $usedRaw];
                }
            }
        }
        $level = 'ok'; $findings = [];
        $findings[] = ['label' => 'Accounts', 'value' => (string)$count, 'severity' => 'ok'];
        $findings[] = ['label' => 'Suspended', 'value' => (string)$suspended, 'severity' => $suspended > 0 ? 'warning' : 'ok'];
        if ($suspended > 0) $level = 'warning';
        if ($resellers > 0) $findings[] = ['label' => 'Resellers', 'value' => (string)$resellers, 'severity' => 'ok'];
        if ($overDisk) { $findings[] = ['label' => 'Near disk limit', 'value' => implode(', ', array_slice($overDisk, 0, 6)), 'severity' => 'warning']; if ($level === 'ok') $level = 'warning'; }
        if ($biggest['pct'] >= 0) $findings[] = ['label' => 'Largest account', 'value' => $biggest['user'].' — '.$biggest['used'].' ('.$biggest['pct'].'%)', 'severity' => 'ok'];
        return ['name' => 'whm', 'title' => 'cPanel accounts', 'level' => $level, 'findings' => $findings];
    }

    /** Server services up/down (httpd, mysql, exim, dovecot, named, cpsrvd, ...). */
    function itops_guardian_services(array $s): array {
        $c = itops_whm_conn($s);
        if (!$c) return ['name' => 'services', 'title' => 'Server services', 'level' => 'off',
                         'findings' => [['label' => 'Services', 'value' => 'add WHM host + token in Server actions', 'severity' => 'off']]];
        $r = itops_whm_call($c, 'servicestatus');
        if (!$r['ok']) return ['name' => 'services', 'title' => 'Server services', 'level' => 'warning',
                               'findings' => [['label' => 'WHM API', 'value' => 'servicestatus unavailable ('.($r['error'] ?: $r['code']).')', 'severity' => 'warning']]];
        $svcs = $r['data']['service'] ?? [];
        $up = 0; $down = [];
        foreach ((array)$svcs as $sv) {
            $name = (string)($sv['name'] ?? $sv['display_name'] ?? '?');
            $running = isset($sv['running']) ? (int)$sv['running'] : (isset($sv['status']) ? (int)$sv['status'] : 1);
            $monitored = !isset($sv['monitored']) || (int)$sv['monitored'] === 1;
            if (!$monitored) continue;
            if ($running === 1) $up++; else $down[] = $name;
        }
        if ($up === 0 && !$down) return ['name' => 'services', 'title' => 'Server services', 'level' => 'ok',
                                          'findings' => [['label' => 'Services', 'value' => 'no monitored services reported', 'severity' => 'ok']]];
        $findings = [['label' => 'Running', 'value' => (string)$up, 'severity' => 'ok']];
        if ($down) $findings[] = ['label' => 'DOWN', 'value' => implode(', ', $down), 'severity' => 'error'];
        return ['name' => 'services', 'title' => 'Server services', 'level' => $down ? 'error' : 'ok', 'findings' => $findings];
    }

    /** Server load average vs CPU count. */
    function itops_guardian_server_load(array $s): array {
        $c = itops_whm_conn($s);
        if (!$c) return ['name' => 'srvload', 'title' => 'Server load', 'level' => 'off',
                         'findings' => [['label' => 'Load', 'value' => 'add WHM host + token in Server actions', 'severity' => 'off']]];
        $r = itops_whm_call($c, 'loadavg');
        if (!$r['ok']) return ['name' => 'srvload', 'title' => 'Server load', 'level' => 'warning',
                               'findings' => [['label' => 'WHM API', 'value' => 'loadavg unavailable ('.($r['error'] ?: $r['code']).')', 'severity' => 'warning']]];
        $d = $r['data'];
        $one = (float)($d['one'] ?? 0); $five = (float)($d['five'] ?? 0); $fifteen = (float)($d['fifteen'] ?? 0);
        $cpu = (int)($s['guardian_cpu_count'] ?? 0);
        $sev = 'ok';
        if ($cpu > 0) { if ($fifteen > $cpu * 2) $sev = 'error'; elseif ($fifteen > $cpu) $sev = 'warning'; }
        return ['name' => 'srvload', 'title' => 'Server load', 'level' => $sev, 'findings' => [
            ['label' => 'Load (1/5/15m)', 'value' => $one.' / '.$five.' / '.$fifteen, 'severity' => $sev],
            ['label' => 'CPU threshold', 'value' => $cpu > 0 ? ('vs '.$cpu.' cores') : 'set CPU count in Server actions for alerts', 'severity' => 'off'],
        ]];
    }

    /** Server backup configuration (is backup enabled + retention). */
    function itops_guardian_server_backups(array $s): array {
        $c = itops_whm_conn($s);
        if (!$c) return ['name' => 'srvbackup', 'title' => 'Server backups (cPanel)', 'level' => 'off',
                         'findings' => [['label' => 'Backups', 'value' => 'add WHM host + token in Server actions', 'severity' => 'off']]];
        $r = itops_whm_call($c, 'backup_config_get');
        if (!$r['ok']) return ['name' => 'srvbackup', 'title' => 'Server backups (cPanel)', 'level' => 'warning',
                               'findings' => [['label' => 'WHM API', 'value' => 'backup_config_get unavailable ('.($r['error'] ?: $r['code']).')', 'severity' => 'warning']]];
        $d = $r['data'];
        $enabled = (string)($d['backupenable'] ?? $d['backup_enabled'] ?? '0') === '1';
        $findings = [['label' => 'cPanel backups', 'value' => $enabled ? 'enabled' : 'DISABLED', 'severity' => $enabled ? 'ok' : 'error']];
        if ($enabled) {
            $ret = (string)($d['backup_daily_retention'] ?? $d['backupretention'] ?? '');
            if ($ret !== '') $findings[] = ['label' => 'Daily retention', 'value' => $ret, 'severity' => 'ok'];
        }
        return ['name' => 'srvbackup', 'title' => 'Server backups (cPanel)', 'level' => $enabled ? 'ok' : 'error', 'findings' => $findings];
    }

    /** cPHulk brute-force protection status. */
    function itops_guardian_cphulk(array $s): array {
        $c = itops_whm_conn($s);
        if (!$c) return ['name' => 'cphulk', 'title' => 'Brute-force protection (cPHulk)', 'level' => 'off',
                         'findings' => [['label' => 'cPHulk', 'value' => 'add WHM host + token in Server actions', 'severity' => 'off']]];
        $r = itops_whm_call_first($c, ['load_cphulk_config','read_cphulk_config','get_cphulk_config'], [], 12);
        if (!$r['ok']) return ['name' => 'cphulk', 'title' => 'Brute-force protection (cPHulk)', 'level' => 'warning',
                               'findings' => [['label' => 'WHM API', 'value' => 'get_cphulk_config unavailable ('.($r['error'] ?: $r['code']).')', 'severity' => 'warning']]];
        $cfg = $r['data']['cphulk_config'] ?? $r['data'];
        $on = (string)($cfg['is_enabled'] ?? '0') === '1';
        return ['name' => 'cphulk', 'title' => 'Brute-force protection (cPHulk)', 'level' => $on ? 'ok' : 'warning', 'findings' => [
            ['label' => 'cPHulk', 'value' => $on ? 'enabled' : 'disabled', 'severity' => $on ? 'ok' : 'warning'],
        ]];
    }

    /** cPanel / WHM version (informational, warns if a major update is available). */
    function itops_guardian_cpanel_version(array $s): array {
        $c = itops_whm_conn($s);
        if (!$c) return ['name' => 'cpver', 'title' => 'cPanel version', 'level' => 'off',
                         'findings' => [['label' => 'cPanel', 'value' => 'add WHM host + token in Server actions', 'severity' => 'off']]];
        $r = itops_whm_call($c, 'version');
        if (!$r['ok']) return ['name' => 'cpver', 'title' => 'cPanel version', 'level' => 'off',
                               'findings' => [['label' => 'cPanel', 'value' => 'version unavailable', 'severity' => 'off']]];
        $ver = (string)($r['data']['version'] ?? '');
        return ['name' => 'cpver', 'title' => 'cPanel version', 'level' => 'ok', 'findings' => [
            ['label' => 'WHM/cPanel', 'value' => $ver !== '' ? $ver : 'unknown', 'severity' => 'ok'],
        ]];
    }

    /** Per-account bandwidth this month (top talkers) via WHM showbw. */
    function itops_guardian_bandwidth(array $s): array {
        $c = itops_whm_conn($s);
        if (!$c) return ['name' => 'bandwidth', 'title' => 'Bandwidth (this month)', 'level' => 'off',
                         'findings' => [['label' => 'Bandwidth', 'value' => 'add WHM host + token in Server actions', 'severity' => 'off']]];
        $now = getdate();
        $r = itops_whm_call($c, 'showbw', ['month' => $now['mon'], 'year' => $now['year']], 15);
        if (!$r['ok']) return ['name' => 'bandwidth', 'title' => 'Bandwidth (this month)', 'level' => 'off',
                               'findings' => [['label' => 'WHM API', 'value' => 'showbw unavailable ('.($r['error'] ?: $r['code']).')', 'severity' => 'off']]];
        // showbw returns data.acct[] each with user, totalbytes (and a bwlimit sometimes)
        $accts = $r['data']['acct'] ?? [];
        $rows = [];
        $total = 0.0; $overLimit = [];
        foreach ((array)$accts as $a) {
            $user = (string)($a['user'] ?? $a['acct'] ?? '?');
            $bytes = (float)($a['totalbytes'] ?? $a['bytes'] ?? 0);
            $limit = isset($a['bwlimit']) && stripos((string)$a['bwlimit'], 'unlim') === false ? (float)$a['bwlimit'] : 0;
            $total += $bytes;
            if ($limit > 0 && $bytes >= 0.9 * $limit) $overLimit[] = $user.' ('.round(100*$bytes/$limit).'%)';
            $rows[] = ['user' => $user, 'bytes' => $bytes];
        }
        usort($rows, fn($x, $y) => $y['bytes'] <=> $x['bytes']);
        $top = array_slice($rows, 0, 5);
        $findings = [['label' => 'Server total', 'value' => itops_guardian_bytes($total), 'severity' => 'ok']];
        foreach ($top as $t) $findings[] = ['label' => $t['user'], 'value' => itops_guardian_bytes($t['bytes']), 'severity' => 'ok'];
        $level = 'ok';
        if ($overLimit) { $findings[] = ['label' => 'Near BW limit', 'value' => implode(', ', array_slice($overLimit, 0, 6)), 'severity' => 'warning']; $level = 'warning'; }
        if (!$rows) $findings = [['label' => 'Bandwidth', 'value' => 'no per-account data returned', 'severity' => 'ok']];
        return ['name' => 'bandwidth', 'title' => 'Bandwidth (this month)', 'level' => $level, 'findings' => $findings];
    }

    /** MySQL footprint: database + DB-user counts via WHM. */
    function itops_guardian_mysql(array $s): array {
        $c = itops_whm_conn($s);
        if (!$c) return ['name' => 'mysql', 'title' => 'MySQL databases', 'level' => 'off',
                         'findings' => [['label' => 'MySQL', 'value' => 'add WHM host + token in Server actions', 'severity' => 'off']]];
        // list_mysql_databases_and_users is per-account (needs user=), so iterate accounts.
        $acc = itops_whm_call($c, 'listaccts', [], 20);
        if (!$acc['ok']) return ['name' => 'mysql', 'title' => 'MySQL databases', 'level' => 'off',
                               'findings' => [['label' => 'WHM API', 'value' => 'account listing unavailable ('.($acc['error'] ?: $acc['code']).')', 'severity' => 'off']]];
        $users = [];
        foreach ((array)($acc['data']['acct'] ?? []) as $a) { if (!empty($a['user'])) $users[] = (string)$a['user']; }
        $cap = 100; $dbCount = 0; $userCount = 0; $done = 0; $anyOk = false;
        foreach (array_slice($users, 0, $cap) as $u) {
            $r = itops_whm_call($c, 'list_mysql_databases_and_users', ['user' => $u], 10);
            if (!$r['ok']) continue;
            $anyOk = true;
            $d = $r['data']['payload'] ?? $r['data'];
            $dbs = $d['mysql_databases'] ?? $d['databases'] ?? [];
            $mu  = $d['mysql_users'] ?? $d['users'] ?? [];
            $dbCount += is_array($dbs) ? count($dbs) : 0;
            $userCount += is_array($mu) ? count($mu) : 0;
            $done++;
        }
        if (!$anyOk) return ['name' => 'mysql', 'title' => 'MySQL databases', 'level' => 'off',
                             'findings' => [['label' => 'MySQL', 'value' => 'listing unavailable on this cPanel version', 'severity' => 'off']]];
        return ['name' => 'mysql', 'title' => 'MySQL databases', 'level' => 'ok', 'findings' => [
            ['label' => 'Databases', 'value' => (string)$dbCount, 'severity' => 'ok'],
            ['label' => 'DB users', 'value' => (string)$userCount, 'severity' => 'ok'],
            ['label' => 'Across accounts', 'value' => (string)$done.($done >= $cap ? '+' : ''), 'severity' => 'off'],
        ]];
    }

    /** AutoSSL / per-domain certificate expiry across every account. */
    function itops_guardian_autossl(array $s): array {
        $c = itops_whm_conn($s);
        if (!$c) return ['name' => 'autossl', 'title' => 'SSL certificates (all domains)', 'level' => 'off',
                         'findings' => [['label' => 'AutoSSL', 'value' => 'add WHM host + token in Server actions', 'severity' => 'off']]];
        // Server-wide installed TLS hosts (each carries the certificate's not_after).
        $r = itops_whm_call_first($c, ['installed_hosts','get_installed_ssl_hosts'], [], 20);
        if (!$r['ok']) return ['name' => 'autossl', 'title' => 'SSL certificates (all domains)', 'level' => 'off',
                               'findings' => [['label' => 'WHM API', 'value' => 'SSL host listing unavailable ('.($r['error'] ?: $r['code']).')', 'severity' => 'off']]];
        $hosts = $r['data']['hosts'] ?? $r['data']['installed_hosts'] ?? $r['data'];
        if (!is_array($hosts)) $hosts = [];
        $now = time();
        $expired = []; $soon = []; $ok = 0; $seen = 0; $minDays = null;
        foreach ($hosts as $h) {
            $not = $h['notafter'] ?? $h['not_after'] ?? $h['certificate']['not_after'] ?? null;
            if ($not === null) continue;
            $ts = is_numeric($not) ? (int)$not : strtotime((string)$not);
            if (!$ts) continue;
            $seen++;
            $domain = (string)($h['servername'] ?? $h['domain'] ?? ($h['domains'][0] ?? '?'));
            $days = (int)floor(($ts - $now) / 86400);
            $minDays = ($minDays === null) ? $days : min($minDays, $days);
            if ($days < 0) $expired[] = $domain.' (expired)';
            elseif ($days <= 14) $soon[] = $domain.' ('.$days.'d)';
            else $ok++;
        }
        if ($seen === 0) return ['name' => 'autossl', 'title' => 'SSL certificates (all domains)', 'level' => 'off',
                                 'findings' => [['label' => 'AutoSSL', 'value' => 'no certificate data returned by WHM', 'severity' => 'off']]];
        $level = $expired ? 'error' : ($soon ? 'warning' : 'ok');
        $findings = [['label' => 'Certificates', 'value' => $seen.' domains ('.$ok.' healthy)', 'severity' => 'ok']];
        if ($minDays !== null) $findings[] = ['label' => 'Soonest expiry', 'value' => $minDays.' days', 'severity' => $minDays <= 14 ? ($minDays < 0 ? 'error' : 'warning') : 'ok'];
        if ($expired) $findings[] = ['label' => 'Expired', 'value' => implode(', ', array_slice($expired, 0, 8)), 'severity' => 'error'];
        if ($soon) $findings[] = ['label' => 'Expiring <=14d', 'value' => implode(', ', array_slice($soon, 0, 8)), 'severity' => 'warning'];
        return ['name' => 'autossl', 'title' => 'SSL certificates (all domains)', 'level' => $level, 'findings' => $findings];
    }

    /**
     * Fuller, on-demand detail for one section (fetched when a card is expanded).
     * Returns ['title'=>..,'columns'=>[..],'rows'=>[[..],..],'note'=>..]. Heavy WHM
     * calls live here so the main scan stays fast.
     */
    /** Inodes per account (from listaccts); mailboxes shown in Details. */
    function itops_guardian_inodes(array $s): array {
        $c = itops_whm_conn($s);
        if (!$c) return ['name' => 'inodes', 'title' => 'Inodes & mailboxes', 'level' => 'off',
                         'findings' => [['label' => 'Inodes', 'value' => 'add WHM host + token in Server actions', 'severity' => 'off']]];
        $r = itops_whm_call($c, 'listaccts', [], 20);
        if (!$r['ok']) return ['name' => 'inodes', 'title' => 'Inodes & mailboxes', 'level' => 'warning',
                               'findings' => [['label' => 'WHM API', 'value' => 'listaccts unavailable ('.($r['error'] ?: $r['code']).')', 'severity' => 'warning']]];
        $accts = $r['data']['acct'] ?? [];
        $near = []; $have = false; $maxPct = -1;
        foreach ((array)$accts as $a) {
            $il = $a['inodeslimit'] ?? $a['inodes_limit'] ?? null;
            $iu = $a['inodesused'] ?? $a['inodes_used'] ?? null;
            if ($il !== null && $iu !== null && (float)$il > 0) {
                $have = true; $pct = round(100 * (float)$iu / (float)$il);
                if ($pct >= 90) $near[] = ($a['user'] ?? '?').' ('.$pct.'%)';
                if ($pct > $maxPct) $maxPct = $pct;
            }
        }
        if (!$have) return ['name' => 'inodes', 'title' => 'Inodes & mailboxes', 'level' => 'ok',
                            'findings' => [['label' => 'Accounts', 'value' => (string)count((array)$accts), 'severity' => 'ok'],
                                           ['label' => 'Inodes', 'value' => 'not reported by this cPanel; use Details for per-account mailboxes', 'severity' => 'off']]];
        $level = $near ? 'warning' : 'ok';
        $findings = [['label' => 'Accounts', 'value' => (string)count((array)$accts), 'severity' => 'ok'],
                     ['label' => 'Highest inode use', 'value' => $maxPct.'%', 'severity' => $maxPct >= 90 ? 'warning' : 'ok']];
        if ($near) $findings[] = ['label' => 'Near inode limit', 'value' => implode(', ', array_slice($near, 0, 6)), 'severity' => 'warning'];
        return ['name' => 'inodes', 'title' => 'Inodes & mailboxes', 'level' => $level, 'findings' => $findings];
    }

    /** Domains & subdomains across the server (get_domain_info). */
    function itops_guardian_domains(array $s): array {
        $c = itops_whm_conn($s);
        if (!$c) return ['name' => 'domains', 'title' => 'Domains & subdomains', 'level' => 'off',
                         'findings' => [['label' => 'Domains', 'value' => 'add WHM host + token in Server actions', 'severity' => 'off']]];
        $r = itops_whm_call($c, 'get_domain_info', [], 20);
        if (!$r['ok']) return ['name' => 'domains', 'title' => 'Domains & subdomains', 'level' => 'off',
                               'findings' => [['label' => 'WHM API', 'value' => 'get_domain_info unavailable ('.($r['error'] ?: $r['code']).')', 'severity' => 'off']]];
        $domains = $r['data']['domains'] ?? [];
        $by = ['main' => 0, 'addon' => 0, 'sub' => 0, 'parked' => 0]; $total = 0;
        foreach ((array)$domains as $d) {
            $t = strtolower((string)($d['domain_type'] ?? 'main'));
            if (!isset($by[$t])) $by[$t] = 0;
            $by[$t]++; $total++;
        }
        return ['name' => 'domains', 'title' => 'Domains & subdomains', 'level' => 'ok', 'findings' => [
            ['label' => 'Total domains', 'value' => (string)$total, 'severity' => 'ok'],
            ['label' => 'Subdomains', 'value' => (string)($by['sub'] ?? 0), 'severity' => 'ok'],
            ['label' => 'Addon domains', 'value' => (string)($by['addon'] ?? 0), 'severity' => 'ok'],
            ['label' => 'Parked / aliases', 'value' => (string)($by['parked'] ?? 0), 'severity' => 'ok'],
        ]];
    }

    /** Exim mail queue depth (best-effort: logged value first, then WHM). */
    function itops_guardian_mailqueue(PDO $db, array $s): array {
        // Option 1: a server cron can POST the depth to the 'mailq' channel like backups.
        try {
            $st = $db->query("SELECT message, ts FROM itops_event_log WHERE channel='mailq' ORDER BY ts DESC LIMIT 1");
            $row = $st ? $st->fetch() : null;
        } catch (\Throwable $e) { $row = null; }
        if ($row && is_numeric($row['message']) && (time() - strtotime($row['ts'])) < 3600) {
            $n = (int)$row['message'];
            $sev = $n > 1000 ? 'error' : ($n > 100 ? 'warning' : 'ok');
            return ['name' => 'mailqueue', 'title' => 'Mail queue (Exim)', 'level' => $sev, 'findings' => [
                ['label' => 'Queued messages', 'value' => (string)$n, 'severity' => $sev],
                ['label' => 'Source', 'value' => 'reported '.itops_guardian_age((time() - strtotime($row['ts'])) / 3600).' ago', 'severity' => 'off'],
            ]];
        }
        // Option 2: best-effort via WHM.
        $c = itops_whm_conn($s);
        if (!$c) return ['name' => 'mailqueue', 'title' => 'Mail queue (Exim)', 'level' => 'off',
                         'findings' => [['label' => 'Mail queue', 'value' => 'add WHM host + token in Server actions', 'severity' => 'off']]];
        $r = itops_whm_call($c, 'exim_mailq', [], 12);
        if ($r['ok']) {
            $items = $r['data']['messages'] ?? $r['data']['queue'] ?? $r['data']['records'] ?? null;
            $n = is_array($items) ? count($items) : (isset($r['data']['count']) ? (int)$r['data']['count'] : null);
            if ($n !== null) {
                $sev = $n > 1000 ? 'error' : ($n > 100 ? 'warning' : 'ok');
                return ['name' => 'mailqueue', 'title' => 'Mail queue (Exim)', 'level' => $sev,
                        'findings' => [['label' => 'Queued messages', 'value' => (string)$n, 'severity' => $sev]]];
            }
        }
        return ['name' => 'mailqueue', 'title' => 'Mail queue (Exim)', 'level' => 'off', 'findings' => [
            ['label' => 'Mail queue', 'value' => 'not exposed by the WHM API on this host', 'severity' => 'off'],
            ['label' => 'How', 'value' => 'a cron can POST the depth (exim -bpc) to /api/guardian/mailq_check', 'severity' => 'off'],
        ]];
    }

    /** Pending cPanel updates, update policy, reboot-required (best-effort). */
    function itops_guardian_updates(array $s): array {
        $c = itops_whm_conn($s);
        if (!$c) return ['name' => 'updates', 'title' => 'Updates & reboot', 'level' => 'off',
                         'findings' => [['label' => 'Updates', 'value' => 'add WHM host + token in Server actions', 'severity' => 'off']]];
        $findings = []; $level = 'ok';
        $vr = itops_whm_call($c, 'version', [], 10);
        $installed = $vr['ok'] ? (string)($vr['data']['version'] ?? '') : '';
        if ($installed !== '') $findings[] = ['label' => 'Installed', 'value' => $installed, 'severity' => 'ok'];
        $lr = itops_whm_call($c, 'get_current_lts_version', [], 10);
        if ($lr['ok']) {
            $latest = (string)($lr['data']['version'] ?? $lr['data']['lts'] ?? '');
            if ($latest !== '') {
                $behind = $installed !== '' && version_compare(preg_replace('~[^0-9.].*$~', '', $installed), preg_replace('~[^0-9.].*$~', '', $latest), '<');
                $findings[] = ['label' => 'Latest available', 'value' => $latest.($behind ? ' — update available' : ' — up to date'), 'severity' => $behind ? 'warning' : 'ok'];
                if ($behind) $level = 'warning';
            }
        }
        $ur = itops_whm_call($c, 'getupdateconf', [], 10);
        if ($ur['ok']) {
            $pol = strtolower((string)($ur['data']['updates'] ?? $ur['data']['UPDATES'] ?? ''));
            if ($pol !== '') $findings[] = ['label' => 'Update policy', 'value' => $pol, 'severity' => $pol === 'never' || $pol === 'manual' ? 'warning' : 'ok'];
            if ($pol === 'never' || $pol === 'manual') $level = ($level === 'ok') ? 'warning' : $level;
        }
        // Reboot-required is not consistently exposed; try, but never hard-fail.
        $rb = itops_whm_call($c, 'get_reboot_status', [], 8);
        if ($rb['ok']) {
            $need = $rb['data']['reboot_required'] ?? $rb['data']['needs_reboot'] ?? null;
            if ($need !== null) { $nb = ((string)$need === '1' || $need === true); $findings[] = ['label' => 'Reboot required', 'value' => $nb ? 'YES' : 'no', 'severity' => $nb ? 'warning' : 'ok']; if ($nb) $level = 'warning'; }
        }
        if (!$findings) $findings[] = ['label' => 'Updates', 'value' => 'version info unavailable', 'severity' => 'off'];
        return ['name' => 'updates', 'title' => 'Updates & reboot', 'level' => $level, 'findings' => $findings];
    }

    function itops_guardian_detail(array $s, string $name): array {
        $c = itops_whm_conn($s);
        $needWhm = in_array($name, ['whm','services','autossl','bandwidth','mysql','inodes','domains'], true);
        if ($needWhm && !$c) return ['title' => 'Details', 'columns' => [], 'rows' => [], 'note' => 'WHM host + token not configured.'];
        try {
            if ($name === 'whm') {
                $r = itops_whm_call($c, 'listaccts', [], 20);
                if (!$r['ok']) return ['title' => 'cPanel accounts', 'columns' => [], 'rows' => [], 'note' => 'WHM listaccts unavailable.'];
                $rows = [];
                foreach ((array)($r['data']['acct'] ?? []) as $a) {
                    $lim = (string)($a['disklimit'] ?? 'unlimited'); $used = (string)($a['diskused'] ?? '0M');
                    $pct = '';
                    if (stripos($lim, 'unlim') === false && (float)$lim > 0) $pct = round(100 * (float)$used / (float)$lim).'%';
                    $rows[] = [
                        (string)($a['user'] ?? '?'), (string)($a['domain'] ?? ''),
                        $used.($lim && stripos($lim,'unlim')===false ? ' / '.$lim : ''),
                        $pct, (string)($a['plan'] ?? ''),
                        !empty($a['suspended']) ? 'suspended' : 'active',
                        (($pct !== '' && (int)$pct >= 90) ? 'warning' : (!empty($a['suspended']) ? 'warning' : 'ok')),
                    ];
                }
                usort($rows, fn($x, $y) => ((int)$y[3]) <=> ((int)$x[3]));
                return ['title' => 'All cPanel accounts ('.count($rows).')', 'columns' => ['User','Primary domain','Disk','Disk %','Plan','Status'], 'rows' => $rows];
            }
            if ($name === 'services') {
                $r = itops_whm_call($c, 'servicestatus', [], 15);
                if (!$r['ok']) return ['title' => 'Server services', 'columns' => [], 'rows' => [], 'note' => 'WHM servicestatus unavailable.'];
                $rows = [];
                foreach ((array)($r['data']['service'] ?? []) as $sv) {
                    $running = isset($sv['running']) ? (int)$sv['running'] : 1;
                    $rows[] = [(string)($sv['name'] ?? '?'), (string)($sv['display_name'] ?? ($sv['name'] ?? '')), $running ? 'running' : 'DOWN', $running ? 'ok' : 'error'];
                }
                usort($rows, fn($x, $y) => ($x[2] === 'DOWN' ? 0 : 1) <=> ($y[2] === 'DOWN' ? 0 : 1));
                return ['title' => 'All monitored services ('.count($rows).')', 'columns' => ['Service','Name','Status'], 'rows' => $rows];
            }
            if ($name === 'autossl') {
                $r = itops_whm_call_first($c, ['installed_hosts','get_installed_ssl_hosts'], [], 25);
                if (!$r['ok']) return ['title' => 'SSL certificates', 'columns' => [], 'rows' => [], 'note' => 'WHM get_installed_ssl_hosts unavailable.'];
                $hosts = $r['data']['hosts'] ?? $r['data']['installed_hosts'] ?? $r['data'];
                $rows = []; $now = time();
                foreach ((array)$hosts as $h) {
                    $not = $h['notafter'] ?? $h['not_after'] ?? $h['certificate']['not_after'] ?? null;
                    if ($not === null) continue;
                    $ts = is_numeric($not) ? (int)$not : strtotime((string)$not);
                    if (!$ts) continue;
                    $days = (int)floor(($ts - $now) / 86400);
                    $sev = $days < 0 ? 'error' : ($days <= 14 ? 'error' : ($days <= 30 ? 'warning' : 'ok'));
                    $rows[] = [(string)($h['servername'] ?? $h['domain'] ?? ($h['domains'][0] ?? '?')), date('m/d/Y', $ts), ($days < 0 ? 'EXPIRED' : $days.' days'), $sev];
                }
                usort($rows, fn($x, $y) => strtotime($x[1]) <=> strtotime($y[1]));
                return ['title' => 'All installed certificates ('.count($rows).')', 'columns' => ['Domain','Expires','Days left'], 'rows' => $rows];
            }
            if ($name === 'bandwidth') {
                $now = getdate();
                $r = itops_whm_call($c, 'showbw', ['month' => $now['mon'], 'year' => $now['year']], 20);
                if (!$r['ok']) return ['title' => 'Bandwidth', 'columns' => [], 'rows' => [], 'note' => 'WHM showbw unavailable.'];
                $rows = [];
                foreach ((array)($r['data']['acct'] ?? []) as $a) {
                    $bytes = (float)($a['totalbytes'] ?? $a['bytes'] ?? 0);
                    $lim = isset($a['bwlimit']) && stripos((string)$a['bwlimit'], 'unlim') === false ? (float)$a['bwlimit'] : 0;
                    $pct = $lim > 0 ? round(100 * $bytes / $lim).'%' : '';
                    $rows[] = [(string)($a['user'] ?? $a['acct'] ?? '?'), itops_guardian_bytes($bytes), $lim > 0 ? itops_guardian_bytes($lim) : 'unlimited', $pct, (($pct !== '' && (int)$pct >= 90) ? 'warning' : 'ok'), $bytes];
                }
                usort($rows, fn($x, $y) => $y[5] <=> $x[5]);
                foreach ($rows as &$rr) array_pop($rr);
                return ['title' => 'Bandwidth by account ('.count($rows).')', 'columns' => ['Account','Used','Limit','Used %'], 'rows' => $rows];
            }
            if ($name === 'mysql') {
                $acc = itops_whm_call($c, 'listaccts', [], 20);
                if (!$acc['ok']) return ['title' => 'MySQL', 'columns' => [], 'rows' => [], 'note' => 'WHM account listing unavailable.'];
                $rows = []; $cap = 100; $i = 0;
                foreach ((array)($acc['data']['acct'] ?? []) as $a) {
                    if ($i >= $cap) break;
                    $u = (string)($a['user'] ?? '');
                    if ($u === '') continue;
                    $r = itops_whm_call($c, 'list_mysql_databases_and_users', ['user' => $u], 8);
                    if (!$r['ok']) { $i++; continue; }
                    $d = $r['data']['payload'] ?? $r['data'];
                    $dbs = $d['mysql_databases'] ?? $d['databases'] ?? [];
                    $mu  = $d['mysql_users'] ?? $d['users'] ?? [];
                    $rows[] = [$u, (string)(is_array($dbs) ? count($dbs) : 0), (string)(is_array($mu) ? count($mu) : 0), 'ok'];
                    $i++;
                }
                usort($rows, fn($x, $y) => ((int)$y[1]) <=> ((int)$x[1]));
                return ['title' => 'MySQL by account ('.count($rows).')', 'columns' => ['Account','Databases','DB users'], 'rows' => $rows];
            }
        } catch (\Throwable $e) {
            return ['title' => 'Details', 'columns' => [], 'rows' => [], 'note' => 'Error: '.$e->getMessage()];
        }
            if ($name === 'inodes') {
                $r = itops_whm_call($c, 'listaccts', [], 20);
                if (!$r['ok']) return ['title' => 'Inodes & mailboxes', 'columns' => [], 'rows' => [], 'note' => 'WHM listaccts unavailable.'];
                $accts = $r['data']['acct'] ?? [];
                // Mailbox counts are one cpanel call per account — cap to keep it responsive.
                $cap = 60; $i = 0; $rows = [];
                foreach ((array)$accts as $a) {
                    $user = (string)($a['user'] ?? '?');
                    $il = $a['inodeslimit'] ?? $a['inodes_limit'] ?? null; $iu = $a['inodesused'] ?? $a['inodes_used'] ?? null;
                    $ipct = ($il !== null && (float)$il > 0) ? round(100 * (float)($iu ?? 0) / (float)$il).'%' : '';
                    $mbox = '';
                    if ($i < $cap) {
                        $mr = itops_whm_call($c, 'cpanel', ['cpanel_jsonapi_user' => $user, 'cpanel_jsonapi_module' => 'Email', 'cpanel_jsonapi_func' => 'list_pops', 'cpanel_jsonapi_apiversion' => 2], 8);
                        $md = $mr['data']['cpanelresult']['data'] ?? ($mr['data']['result']['data'] ?? null);
                        if (is_array($md)) $mbox = (string)count($md);
                    }
                    $sev = ($ipct !== '' && (int)$ipct >= 90) ? 'warning' : 'ok';
                    $rows[] = [$user, ($iu !== null ? (string)$iu : '?').($il !== null && (float)$il > 0 ? ' / '.$il : ''), $ipct, ($mbox === '' ? '—' : $mbox), $sev];
                    $i++;
                }
                return ['title' => 'Per-account inodes & mailboxes'.($i >= $cap ? ' (first '.$cap.')' : ''), 'columns' => ['Account','Inodes','Inode %','Mailboxes'], 'rows' => $rows];
            }
            if ($name === 'domains') {
                $r = itops_whm_call($c, 'get_domain_info', [], 20);
                if (!$r['ok']) return ['title' => 'Domains', 'columns' => [], 'rows' => [], 'note' => 'WHM get_domain_info unavailable.'];
                $rows = [];
                foreach ((array)($r['data']['domains'] ?? []) as $d) {
                    $rows[] = [(string)($d['domain'] ?? '?'), (string)($d['domain_type'] ?? ''), (string)($d['user'] ?? ''), 'ok'];
                }
                usort($rows, fn($x, $y) => [$x[2], $x[1]] <=> [$y[2], $y[1]]);
                return ['title' => 'All domains ('.count($rows).')', 'columns' => ['Domain','Type','Owner'], 'rows' => $rows];
            }
        // Fallback: expose the section's own findings as rows.
        $sec = itops_guardian_section_for_detail($s, $name);
        $rows = [];
        foreach (($sec['findings'] ?? []) as $f) $rows[] = [(string)$f['label'], (string)$f['value'], (string)($f['severity'] ?? 'ok')];
        return ['title' => $sec['title'] ?? 'Details', 'columns' => ['Item','Value'], 'rows' => $rows];
    }

    /** Helper for detail fallback: get a section by name without a DB where possible. */
    function itops_guardian_section_for_detail(array $s, string $name): array {
        switch ($name) {
            case 'ssl':     return itops_guardian_ssl($s);
            case 'imunify': return itops_guardian_imunify($s);
            case 'imap':    return itops_guardian_imap($s);
            case 'srvload': return itops_guardian_server_load($s);
            case 'srvbackup': return itops_guardian_server_backups($s);
            case 'cphulk':  return itops_guardian_cphulk($s);
            case 'cpver':   return itops_guardian_cpanel_version($s);
            case 'defender': return itops_guardian_defender();
            case 'intune':  return itops_guardian_intune();
            case 'mfa':     return itops_guardian_mfa();
            case 'mailsec': return itops_guardian_mailsec();
            case 'php_eol': return itops_guardian_php_eol();
            case 'vitals':  return itops_guardian_vitals();
            default:        return ['title' => 'Details', 'findings' => []];
        }
    }

    /**
     * BT124 (code review OPS-9): a full scan makes many calls to the server; the result is kept and reused for $maxAge
     * seconds (the scheduler and the alerts reuse it; a person asking again within that time gets the kept one).
     */
    function itops_guardian_run_cached(PDO $db, int $maxAge, bool &$fresh = null): array {
        $fresh = false;
        require_once __DIR__ . '/SafeCache.php'; $cf = itops_cache_dir('guardian') . '/last_scan.json';
        $j = json_decode((string)itops_cache_read($cf), true);
        if (is_array($j) && isset($j['at'], $j['sections']) && time() - (int)$j['at'] < $maxAge) return $j['sections'];
        if ((int)$db->query("SELECT GET_LOCK('itops_guardian_scan', 0)")->fetchColumn() !== 1) return is_array($j) && isset($j['sections']) ? $j['sections'] : [];   // one scan at a time
        try {
            $s = itops_guardian_run($db); $fresh = true;
            itops_cache_write($cf, (string)json_encode(['at' => time(), 'sections' => $s], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
            return $s;
        } finally { $db->query("SELECT RELEASE_LOCK('itops_guardian_scan')"); }
    }
    function itops_guardian_run(PDO $db): array {
        $s = itops_guardian_settings($db);
        $sections = [];
        $sections[] = itops_guardian_vitals();
        $sections[] = itops_guardian_whm($s);
        $sections[] = itops_guardian_services($s);
        $sections[] = itops_guardian_server_load($s);
        $sections[] = itops_guardian_server_backups($s);
        $sections[] = itops_guardian_cphulk($s);
        $sections[] = itops_guardian_cpanel_version($s);
        $sections[] = itops_guardian_bandwidth($s);
        $sections[] = itops_guardian_mysql($s);
        $sections[] = itops_guardian_autossl($s);
        $sections[] = itops_guardian_inodes($s);
        $sections[] = itops_guardian_domains($s);
        $sections[] = itops_guardian_mailqueue($db, $s);
        $sections[] = itops_guardian_updates($s);
        $sections[] = itops_guardian_imunify($s);
        $sections[] = itops_guardian_imap($s);
        $sections[] = itops_guardian_defender();
        $sections[] = itops_guardian_intune();
        $sections[] = itops_guardian_mfa();
        $sections[] = itops_guardian_mailsec();
        $sections[] = itops_guardian_ssl($s);
        $sections[] = itops_guardian_backup($db);
        $sections[] = itops_guardian_php_eol();
        return $sections;
    }

    /** Run a single named section. */
    function itops_guardian_section(PDO $db, string $name): array {
        $s = itops_guardian_settings($db);
        switch ($name) {
            case 'vitals':  return itops_guardian_vitals();
            case 'whm':     return itops_guardian_whm($s);
            case 'services': return itops_guardian_services($s);
            case 'srvload':  return itops_guardian_server_load($s);
            case 'srvbackup': return itops_guardian_server_backups($s);
            case 'cphulk':   return itops_guardian_cphulk($s);
            case 'cpver':    return itops_guardian_cpanel_version($s);
            case 'bandwidth': return itops_guardian_bandwidth($s);
            case 'mysql':    return itops_guardian_mysql($s);
            case 'autossl':  return itops_guardian_autossl($s);
            case 'inodes':   return itops_guardian_inodes($s);
            case 'domains':  return itops_guardian_domains($s);
            case 'mailqueue': return itops_guardian_mailqueue($db, $s);
            case 'updates':  return itops_guardian_updates($s);
            case 'imunify': return itops_guardian_imunify($s);
            case 'imap':    return itops_guardian_imap($s);
            case 'defender': return itops_guardian_defender();
            case 'intune':   return itops_guardian_intune();
            case 'mfa':      return itops_guardian_mfa();
            case 'mailsec':  return itops_guardian_mailsec();
            case 'ssl':      return itops_guardian_ssl($s);
            case 'backup':   return itops_guardian_backup($db);
            case 'php_eol':  return itops_guardian_php_eol();
            default:        throw new RuntimeException('Unknown Guardian section: '.$name);
        }
    }

    // ---- small helpers --------------------------------------------------------

    function itops_guardian_bytes(float $b): string {
        $u = ['B','KB','MB','GB','TB'];
        $i = 0;
        while ($b >= 1024 && $i < count($u) - 1) { $b /= 1024; $i++; }
        return round($b, 1).' '.$u[$i];
    }

    function itops_guardian_age(float $hours): string {
        if ($hours < 1) return 'under 1h ago';
        if ($hours < 48) return round($hours).'h ago';
        return round($hours / 24).'d ago';
    }

    function itops_guardian_cpu_count(): int {
        if (@is_readable('/proc/cpuinfo')) {
            $n = substr_count((string)@file_get_contents('/proc/cpuinfo'), "\nprocessor");
            if ($n > 0) return $n + 1; // first entry has no leading newline
        }
        return 1;
    }
}
