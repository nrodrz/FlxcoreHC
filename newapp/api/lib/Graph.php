<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
require_once __DIR__ . '/Brand.php';   // BT20: itops_app_name()
require_once __DIR__ . '/GraphPermissions.php';   // BT36: app registration vs. token (Diagnose)
/**
 * ITOps — Microsoft Graph (application / client-credentials) client.
 *
 * Reuses the SAME app registration ITOps already uses for O365 SSO
 * (o365_tenant_id / o365_client_id / ONE encrypted secret o365_client_secret_enc — BT14), but
 * acquires an APP-ONLY token with the https://graph.microsoft.com/.default scope.
 * This is a different grant from the delegated SSO login and requires the
 * Application permissions listed in docs/INSTALL.md, with admin consent.
 *
 * Write actions live in routes/m365_admin.php (permission m365_admin).
 *
 * Depends on ITOps's config.php helpers: getDB() (PDO). Constant ITOPS_CACHE_DIR
 * is optional; if undefined the system temp dir is used for the token cache.
 */

if (!function_exists('itops_graph_cfg')) {

    function itops_graph_cfg(): array {
        static $cfg = null;
        if ($cfg !== null) return $cfg;
        $db = getDB();
        $rows = $db->query("SELECT skey, sval FROM itops_settings WHERE skey IN
            ('o365_tenant_id','o365_tenant','o365_client_id','o365_client_secret','o365_client_secret_enc','o365_graph_secret_enc')")->fetchAll(PDO::FETCH_KEY_PAIR);
        // BT14: ONE Microsoft 365 app configuration. Secret order: o365_graph_secret_enc (only exists when the upgrade
        // found two DIFFERENT saved secrets — kept so nothing changes until an admin saves one), then the shared
        // encrypted o365_client_secret_enc, then legacy plaintext (only before db-update.php has run).
        $dec = function ($k) use ($rows) { if (empty($rows[$k])) return ''; try { return vault_decrypt($rows[$k]); } catch (\Throwable $e) { return ''; } };
        $sec = $dec('o365_graph_secret_enc');
        if ($sec === '') $sec = $dec('o365_client_secret_enc');
        if ($sec === '') $sec = (string)($rows['o365_client_secret'] ?? '');
        $cfg = [
            'tenant' => ($rows['o365_tenant_id'] ?? '') !== '' ? $rows['o365_tenant_id'] : ($rows['o365_tenant'] ?? ''),
            'client' => $rows['o365_client_id']     ?? '',
            'secret' => $sec,
        ];
        return $cfg;
    }

    function itops_graph_configured(): bool {
        $c = itops_graph_cfg();
        return $c['tenant'] !== '' && $c['client'] !== '' && $c['secret'] !== '';
    }

    /**
     * Path of the on-disk token cache (chmod 0600). BT88 (code audit M-32): ITOPS_CACHE_DIR when set; otherwise a folder
     * of this installation only (0700) inside the system temp folder, and the token itself is stored encrypted.
     */
    function itops_graph_token_file(): string {
        $c = itops_graph_cfg();
        require_once __DIR__ . '/SafeCache.php';   // BT124 (review M365A-1): a folder this account owns, never one planted by another
        $dir = itops_cache_dir('tokens');
        return rtrim($dir, '/').'/itops_graph_token_'.substr(sha1($c['tenant'].'|'.$c['client']), 0, 12).'.json';
    }
    /** BT88: the cached token at rest — encrypted with the vault key when the vault is there. */
    function itops_graph_tok_seal(string $t): string { if (function_exists('vault_encrypt')) { try { return 'v1:' . vault_encrypt($t); } catch (\Throwable $e) {} } return $t; }
    // BT124 (review M365A-1): only a sealed token is read back (an unsealed one could have been put there by someone else)
    function itops_graph_tok_open(string $t): string { if (strpos($t, 'v1:') === 0) { if (!function_exists('vault_decrypt')) return ''; try { return (string)vault_decrypt(substr($t, 3)); } catch (\Throwable $e) { return ''; } } return function_exists('vault_decrypt') ? '' : $t; }

    /** BT124: is a fresh token worth asking for after this refusal? 401 always (once per request); 403 when the kept one is old. */
    function itops_graph_fresh_worth(int $code): bool {
        static $done = false; if ($done) return false;
        if ($code === 403) { $j = json_decode((string)@file_get_contents(itops_graph_token_file()), true); if (is_array($j) && time() - (int)($j['minted'] ?? 0) < 300) return false; }
        $done = true; return true;
    }
    /** Drop the cached token so the next call mints a fresh one (picks up newly consented permissions). */
    function itops_graph_token_clear(): void {
        $f = itops_graph_token_file();
        if (is_file($f)) @unlink($f);
    }

    /** Decode a JWT's payload (no signature check — used only to INSPECT roles/appid/tid/exp). */
    function itops_graph_jwt_claims(string $jwt): array {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) return [];
        $p = strtr($parts[1], '-_', '+/');
        $p .= str_repeat('=', (4 - strlen($p) % 4) % 4);
        $j = json_decode((string)base64_decode($p), true);
        return is_array($j) ? $j : [];
    }

    /**
     * App-only bearer token for Graph. Cached on disk until ~2 min before expiry.
     * Pass $force=true to bypass the cache and mint a fresh token (used to self-heal
     * after a 401/403, e.g. when a permission was consented after the token was issued).
     */
    function itops_graph_token(bool $force = false): string {
        $c = itops_graph_cfg();
        if ($c['tenant'] === '') throw new RuntimeException('Microsoft 365 is not configured.');
        $file = itops_graph_token_file();

        if (!$force && is_file($file)) {
            $j = json_decode((string)@file_get_contents($file), true);
            if (is_array($j) && ($j['exp'] ?? 0) > time() + 120 && !empty($j['tok'])) { $t0 = itops_graph_tok_open((string)$j['tok']); if ($t0 !== '') return $t0; }
        }

        $url = 'https://login.microsoftonline.com/'.rawurlencode($c['tenant']).'/oauth2/v2.0/token';
        $post = http_build_query([
            'client_id'     => $c['client'],
            'client_secret' => $c['secret'],
            'grant_type'    => 'client_credentials',
            'scope'         => 'https://graph.microsoft.com/.default',
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode((string)$resp, true);

        if ($code !== 200 || empty($data['access_token'])) {
            $msg = $data['error_description'] ?? ('token endpoint returned HTTP '.$code);
            throw new RuntimeException('Graph token error: '.$msg);
        }
        @file_put_contents($file, json_encode([
            'tok' => itops_graph_tok_seal((string)$data['access_token']),
            'exp' => time() + (int)($data['expires_in'] ?? 3600),
            'minted' => time(),
        ]), LOCK_EX);
        @chmod($file, 0600);
        return $data['access_token'];
    }

    /**
     * Core HTTP call to Graph with self-healing auth: on 401/403 the cached token is
     * discarded, a fresh one is minted, and the call is retried ONCE. This makes a
     * newly consented permission take effect immediately instead of after the
     * cached token expires. Returns [code, decodedJson, rawBody].
     */
    function itops_graph_http(string $method, string $url, ?array $body = null, array $extraHeaders = [], int $timeout = 30): array {
        // BT41: a request that must end inside the host's time limit (a save of several parts) can ask for a shorter
        // time per call and for no second attempt after a 401/403: ItopsGraphClient::setRequestTimeout(). The cached token
        // is still dropped on a 401/403, so the next request gets a new one.
        $rq = $GLOBALS['ITOPS_GRAPH_REQ'] ?? null;
        if (is_array($rq) && !empty($rq['timeout'])) $timeout = min($timeout, max(3, (int)$rq['timeout']));
        $authRetry = !(is_array($rq) && array_key_exists('auth_retry', $rq) && $rq['auth_retry'] === false);
        itops_graph_url_ok($url);   // BT88
        $attempt = 0; $busy = 0;
        while (true) {
            $tok = itops_graph_token($attempt > 0);
            $ch = curl_init($url);
            $retryAfter = 0;   // BT88: Microsoft says "slow down" (429 / 503 with Retry-After)
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, $line) use (&$retryAfter) { if (preg_match('/^Retry-After:\s*(\d+)/i', $line, $m)) $retryAfter = (int)$m[1]; return strlen($line); });
            // BT31: Microsoft requires a User-Agent on some endpoints (BitLocker recovery keys, local admin passwords).
            $headers = array_merge(['Authorization: Bearer '.$tok, 'Accept: application/json', 'User-Agent: ITOps-Graph/1.0'], $extraHeaders);
            $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
                     CURLOPT_HTTPHEADER => $headers];
            if ($method !== 'GET') {
                $opts[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
                if ($body !== null) { $opts[CURLOPT_POSTFIELDS] = $body === [] ? '{}' : json_encode($body); $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json'; }
            }
            curl_setopt_array($ch, $opts);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);
            if ($resp === false) throw new RuntimeException('Graph network error: '.($cerr ?: 'no response'));
            // BT124 (code review M365A-7): a fresh token is worth asking for on 401, or on 403 when the kept token is older than
            // five minutes (a permission just granted) — once per request. A 403 for a permission the tenant never granted no
            // longer throws the shared token away on every refused read.
            if (($code === 401 || $code === 403) && $attempt === 0 && itops_graph_fresh_worth($code)) { itops_graph_token_clear(); if ($authRetry) { $attempt++; continue; } }
            // Review M365A-11: a 503 to a create (POST) may have been carried out, so it is not sent again; 429 means "not done"
            if (($code === 429 || ($code === 503 && strtoupper($method) !== 'POST')) && $busy < 2) {   // BT88: wait as asked (at most 10 seconds, twice), then try again
                $busy++; $w = $retryAfter > 0 ? min(10, $retryAfter) : 2 * $busy;
                if (php_sapi_name() !== 'cli' && $w > 5) $w = 5;
                sleep($w); continue;
            }
            return [$code, json_decode((string)$resp, true), (string)$resp];
        }
    }

    /** GET a Graph resource (path relative to the version root). Returns decoded JSON. */
    function itops_graph_get(string $path, string $version = 'v1.0'): array {
        $url = 'https://graph.microsoft.com/'.$version.'/'.ltrim($path, '/');
        [$code, $data] = itops_graph_http('GET', $url);
        if ($code === 401 || $code === 403) {
            throw new RuntimeException('Graph access denied (HTTP '.$code.') for '.$path.'. '
                .($data['error']['message'] ?? 'The app is missing an Application permission with admin consent.'));
        }
        if ($code >= 400) {
            throw new RuntimeException('Graph error (HTTP '.$code.') for '.$path.': '.($data['error']['message'] ?? 'unknown'));
        }
        return is_array($data) ? $data : [];
    }

    /**
     * Graph usage reports return CSV via a 302 to a temporary URL. Ask for JSON
     * with $format=application/json and follow redirects. Period like 'D7'/'D30'.
     * Returns the 'value' array when present.
     */
    /**
     * Write helper (POST / PATCH / DELETE). Used only by the gated M365 admin route.
     * Requires Application WRITE permissions (User.ReadWrite.All / Directory.ReadWrite.All)
     * with admin consent — separate from the read-only scopes.
     */
    function itops_graph_send(string $method, string $path, array $body = [], string $version = 'v1.0'): array {
        $url = 'https://graph.microsoft.com/'.$version.'/'.ltrim($path, '/');
        [$code, $data] = itops_graph_http($method, $url, $body);
        if ($code === 401 || $code === 403) {
            throw new RuntimeException('Graph write access denied (HTTP '.$code.') for '.$path.'. '
                .($data['error']['message'] ?? 'The app needs the matching WRITE Application permission with admin consent.'));
        }
        if ($code >= 400) {
            throw new RuntimeException('Graph error (HTTP '.$code.') for '.$path.': '.($data['error']['message'] ?? 'unknown'));
        }
        return is_array($data) ? $data : [];
    }

    function itops_graph_report(string $report, string $period = 'D7', array $tried = []): array {
        // Microsoft usage-report functions return CSV. Several reject $format=application/json
        // ("JSON format is not supported."), so we fetch CSV and parse it into rows keyed by
        // the CSV header (matches the column names the UI already reads, e.g. "Display Name").
        $period = itops_graph_period($period);   // BT88 (code audit H-9)
        if (!preg_match('/^[A-Za-z0-9]{3,80}$/', $report)) throw new InvalidArgumentException('Unknown report.');
        $url = 'https://graph.microsoft.com/v1.0/reports/'.$report."(period='".$period."')";
        $attempt = 0; $csv = ''; $code = 0;
        while (true) {
            $tok = itops_graph_token($attempt > 0);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$tok, 'Accept: text/csv'],
            ]);
            $csv = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (($code === 401 || $code === 403) && $attempt === 0 && itops_graph_fresh_worth($code)) { itops_graph_token_clear(); $attempt++; continue; }
            break;
        }
        if ($code >= 400) {
            $why = '';
            $j = json_decode((string)$csv, true);
            if (is_array($j) && isset($j['error']['message'])) $why = ' — '.$j['error']['message'];
            // BT55: Microsoft names the period it wants (see itops_graph_period_next) — ask again with it, at most three more times.
            if ($code === 400 && count($tried) < 3 && function_exists('itops_graph_period_next')) {
                $tried[] = $period; $next = itops_graph_period_next($why, $period, $tried);
                if ($next !== null) return itops_graph_report($report, $next, $tried);
            }
            if ($code === 403) throw new RuntimeException('Graph access denied (HTTP 403) for '.$report.'. Grant Reports.Read.All (Application) with admin consent.'.$why);
            throw new RuntimeException('Graph report error (HTTP '.$code.') for '.$report.$why);
        }
        return itops_graph_parse_csv((string)$csv);
    }

    /** Parse a Graph usage-report CSV into an array of associative rows keyed by header. */
    function itops_graph_parse_csv(string $csv): array {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', trim($csv)); // strip UTF-8 BOM
        if ($csv === '') return [];
        $lines = preg_split('/\r\n|\n|\r/', $csv);
        $header = str_getcsv(array_shift($lines));
        $n = count($header);
        $out = [];
        foreach ($lines as $line) {
            if ($line === '') continue;
            $cells = str_getcsv($line);
            if (count($cells) < $n) $cells = array_pad($cells, $n, '');
            elseif (count($cells) > $n) $cells = array_slice($cells, 0, $n);
            $out[] = array_combine($header, $cells);
        }
        return $out;
    }

    /**
     * Full, honest diagnostic of the Microsoft 365 connection. Never throws.
     * Shows what the app-only token ACTUALLY carries (roles/appid/tid) and live-tests
     * each capability with the exact Graph status + message, so a misconfiguration
     * is visible instead of guessed at. Secrets are never returned.
     */
    function itops_graph_diagnose(bool $freshToken = false): array {
        $out = ['configured' => false, 'config' => [], 'token' => [], 'checks' => [], 'sso' => [], 'advice' => []];
        $c = itops_graph_cfg();
        $out['config'] = [
            'tenant_set' => $c['tenant'] !== '', 'client_set' => $c['client'] !== '', 'secret_set' => $c['secret'] !== '',
            'tenant' => $c['tenant'], 'client_id' => $c['client'],
        ];
        $out['configured'] = itops_graph_configured();
        if (!$out['configured']) { $out['advice'][] = 'Enter the Tenant ID, Client ID and Client secret in Settings → Company systems → Microsoft 365 → Set up….'; return $out; }

        // Token
        try {
            if ($freshToken) itops_graph_token_clear();
            $file = itops_graph_token_file();
            $cached = is_file($file);
            $tok = itops_graph_token();
            $cl = itops_graph_jwt_claims($tok);
            $roles = is_array($cl['roles'] ?? null) ? $cl['roles'] : [];
            sort($roles);
            $out['token'] = [
                'ok' => true, 'from_cache' => $cached && !$freshToken,
                'app_id' => (string)($cl['appid'] ?? $cl['azp'] ?? ''), 'tenant_id' => (string)($cl['tid'] ?? ''),
                'expires_in_min' => isset($cl['exp']) ? max(0, (int)round(((int)$cl['exp'] - time()) / 60)) : null,
                'roles' => $roles, 'role_count' => count($roles),
            ];
            if ($out['token']['app_id'] !== '' && strcasecmp($out['token']['app_id'], $c['client']) !== 0) {
                $out['advice'][] = 'The token was issued to app '.$out['token']['app_id'].' but ' . itops_app_name() . ' is configured with client '.$c['client'].' — check you are looking at the same app registration in Azure.';
            }
            if (!$roles) $out['advice'][] = 'The token carries NO application roles. Grant admin consent on the app\'s API permissions, then click "Refresh token" here.';
            // BT34: permissions that change something cannot be live-tested below; read them from the token instead.
            if ($roles && class_exists('ItopsM365')) $out['writes'] = ItopsM365::writePermissions($roles);
            // BT36: what the app registration asks for — tells "no consent" from "added as Delegated" from "wrong app".
            itops_graph_explain_app($out);
        } catch (\Throwable $e) {
            $out['token'] = ['ok' => false, 'error' => $e->getMessage()];
            $out['advice'][] = 'Could not obtain a token: '.$e->getMessage().' — verify Tenant ID, Client ID and that the client secret is current (not expired).';
            return $out;
        }

        // Capability tests: [label, needed permission, path]
        $tests = [
            ['Users (directory)',          'User.Read.All / User.ReadWrite.All',           'users?$top=1&$select=id'],
            ['Licenses (SKUs)',            'Organization.Read.All',                        'subscribedSkus'],
            ['Security groups',            'Group.Read.All (changes: Group.ReadWrite.All)', 'groups?$top=1&$select=id'],
            ['Usage reports (Exchange)',   'Reports.Read.All',                             "reports/getMailboxUsageDetail(period='D7')"],
            ['MFA registration report',    'Reports.Read.All / AuditLog.Read.All',         'reports/authenticationMethods/userRegistrationDetails?$top=1'],
            ['Defender secure score',      'SecurityEvents.Read.All',                      'security/secureScores?$top=1'],
            ['Intune devices',             'DeviceManagementManagedDevices.Read.All',      'deviceManagement/managedDevices?$top=1&$select=id'],
            ['Service health (optional)',  'ServiceHealth.Read.All',                       'admin/serviceAnnouncement/healthOverviews'],
            ['App registrations (secret expiry)', 'Application.Read.All',                  'applications?$top=1&$select=id'],
            ['Sign-in activity (idle report)',    'AuditLog.Read.All (+ Entra ID P1)',     'users?$top=1&$select=id,signInActivity'],
            ['Intune compliance policies',        'DeviceManagementConfiguration.Read.All','deviceManagement/deviceCompliancePolicies?$top=1&$select=id'],
            ['Intune enrolment restrictions',     'DeviceManagementServiceConfig.Read.All','deviceManagement/deviceEnrollmentConfigurations?$top=1'],
            ['Defender alerts',                   'SecurityAlert.Read.All (write: SecurityAlert.ReadWrite.All)', 'security/alerts_v2?$top=1&$select=id'],
            ['Conditional Access (read)',         'Policy.Read.All',                       'identity/conditionalAccess/policies?$top=1'],
            // BT118: Security → Conditional Access. Changes need Policy.ReadWrite.ConditionalAccess + Application.Read.All; they cannot be tested without changing a policy (see "Permissions in token").
            // BT119: incidents handled from Alerts & actions; Security → Admin roles
            ['Defender incidents',                    'SecurityIncident.Read.All (changes: SecurityIncident.ReadWrite.All)', 'security/incidents?$top=1'],
            ['Admin role assignments (Admin roles)',  'RoleManagement.Read.Directory (changes: RoleManagement.ReadWrite.Directory)', 'roleManagement/directory/roleAssignments?$top=1'],
            ['Security defaults and named locations (Conditional Access)', 'Policy.Read.All (changes, including switching security defaults: Policy.ReadWrite.ConditionalAccess)', 'identity/conditionalAccess/namedLocations?$top=1'],
            ['App protection (MAM) policies',     'DeviceManagementApps.Read.All (changes: DeviceManagementApps.ReadWrite.All)', 'deviceAppManagement/iosManagedAppProtections?$top=1'],
            ['Entra devices (device inventory)',  'Device.Read.All',                       'devices?$top=1&$select=id'],
            ['Sign-in log (device inventory)',    'AuditLog.Read.All (+ Entra ID P1)',     'auditLogs/signIns?$top=1'],
            // BT31: Entra management. Changing devices (Device.ReadWrite.All) cannot be tested without changing one; see "Permissions in token".
            ['BitLocker recovery keys',           'BitlockerKey.Read.All',                 'informationProtection/bitlocker/recoveryKeys'],
            ['Local admin passwords (LAPS)',      'DeviceLocalCredential.Read.All',        'directory/deviceLocalCredentials?$top=1'],
            ['Groups and their members',          'Group.Read.All (members: GroupMember.ReadWrite.All; owners and new groups: Group.ReadWrite.All)', 'groups?$top=1&$select=id'],
            // BT94: announcements and ticket notices to a Microsoft 365 group or distribution list read its members when sent
            ['Group members (announcements and notices to a group)', 'GroupMember.Read.All (or Group.Read.All)', 'groups?$top=1&$select=id'],
            // BT32
            ['Enterprise apps (delegated consents)', 'Directory.Read.All',                 'oauth2PermissionGrants?$top=1'],
            ['Entra audit log',                   'AuditLog.Read.All',                     'auditLogs/directoryAudits?$top=1'],
            // BT124: what security drift (BT123) reads for device changes
            ['Intune audit log (security drift)',  'DeviceManagementApps.Read.All',         'deviceManagement/auditEvents?$top=1'],
            // BT102: the security assessment also reads the admin roles, the tenant-wide settings and the domains
            ['Directory roles (security assessment)', 'RoleManagement.Read.Directory',     'directoryRoles?$select=id'],
            ['Tenant settings (security assessment)', 'Policy.Read.All',                   'policies/authorizationPolicy'],
            ['Domains (security assessment)',         'Domain.Read.All',                   'domains?$select=id'],
            // BT106: Risky people — Microsoft's own sign-in risk (optional; Entra ID P2)
            ['Risky users (Identity Protection)',     'IdentityRiskyUser.Read.All (+ Entra ID P2)', 'identityProtection/riskyUsers?$top=1'],
            // BT114: Devices → Apps, Software found, Configuration and Update rings (reads)
            ['Intune apps (Devices → Apps)',          'DeviceManagementApps.Read.All',         'deviceAppManagement/mobileApps?$top=1'],
            ['Software found on devices',             'DeviceManagementManagedDevices.Read.All', 'deviceManagement/detectedApps?$top=1'],
            ['Configuration profiles and update rings', 'DeviceManagementConfiguration.Read.All', 'deviceManagement/deviceConfigurations?$top=1'],
            // BT116 (4a): reads of the new security rules
            ['Sharing settings (security rules)',     'SharePointTenantSettings.Read.All',     'admin/sharepoint/settings'],
            ['Admin roles when needed (PIM, security rules)', 'RoleEligibilitySchedule.Read.Directory (+ Entra ID P2)', 'roleManagement/directory/roleEligibilityScheduleInstances?$top=1'],
            ["Microsoft's recommended actions (Posture)", 'SecurityEvents.Read.All',           'security/secureScoreControlProfiles?$top=1'],
            // BT113: Email → Message trace (the last hour)
            ['Message trace (Email)',                 'ExchangeMessageTrace.Read.All',         'admin/exchange/tracing/messageTraces?$top=1&$filter=' . rawurlencode('receivedDateTime ge ' . gmdate('Y-m-d\TH:i:s\Z', time() - 3600) . ' and receivedDateTime le ' . gmdate('Y-m-d\TH:i:s\Z'))],
        ];
        foreach ($tests as [$label, $perm, $path]) {
            $r = ['label' => $label, 'permission' => $perm, 'ok' => false, 'status' => 0, 'message' => ''];
            try {
                $url = 'https://graph.microsoft.com/v1.0/'.$path;
                [$code, $data, $raw] = itops_graph_http('GET', $url, null, [], 20);
                $r['status'] = $code;
                if ($code < 400) { $r['ok'] = true; $r['message'] = 'OK'; }
                else { $r['message'] = (string)($data['error']['message'] ?? ('HTTP '.$code)); }
            } catch (\Throwable $e) { $r['message'] = $e->getMessage(); }
            $out['checks'][] = $r;
        }
        // BT113: Email → Mailboxes uses Microsoft's Exchange Admin API (preview), with its own token and an Exchange role
        $r = ['label' => 'Exchange mailboxes (Email, preview)', 'permission' => 'Exchange.ManageAsAppV2 + Exchange role Recipient Management', 'ok' => false, 'status' => 0, 'message' => ''];
        try { require_once __DIR__ . '/ExchangeAdmin.php'; itops_exo_call('OrganizationConfig', 'Get-OrganizationConfig', [], '', ['Name'], 1); $r['ok'] = true; $r['status'] = 200; $r['message'] = 'OK'; }
        catch (\Throwable $e) { $r['message'] = $e->getMessage(); if (preg_match('/HTTP (\d{3})/', $r['message'], $mm)) $r['status'] = (int)$mm[1]; }
        $out['checks'][] = $r;
        itops_graph_explain_checks($out);   // BT36: adds state / why to every refused test and the matching "What to do" line
        foreach ($out['checks'] as $r) {
            if (!$r['ok'] && $r['status'] === 400 && stripos($r['label'], 'report') !== false) $out['advice'][] = $r['label'].': Graph returned 400 — '.$r['message'];
        }

        // SSO (delegated sign-in) readiness — separate from the app-only permissions above.
        try {
            $db = getDB();
            $rows = $db->query("SELECT skey, sval FROM itops_settings WHERE skey IN ('o365_enabled','o365_redirect')")->fetchAll(PDO::FETCH_KEY_PAIR);
            $out['sso'] = ['enabled' => (($rows['o365_enabled'] ?? '0') === '1'), 'redirect_uri' => (string)($rows['o365_redirect'] ?? '')];
            if ($out['sso']['enabled']) {
                if ($out['sso']['redirect_uri'] === '') $out['advice'][] = 'SSO: no Redirect URI is set in ' . itops_app_name() . '. Set it to your ' . itops_app_name() . ' URL and register the SAME value in the Azure app > Authentication > Web.';
                $out['advice'][] = 'SSO uses DELEGATED scopes (openid, profile, email, User.Read) — these are separate from the Application permissions above and must ALSO be added and consented in the Azure app, and the Redirect URI must match exactly.';
            }
        } catch (\Throwable $e) { $out['sso'] = ['error' => $e->getMessage()]; }
        return $out;
    }

    /**
     * Report fetch that never throws: returns a card-shaped payload so a missing
     * permission or a bad report shows as an inline message instead of a 502.
     */
    function itops_graph_report_safe(string $report, string $period = 'D7'): array {
        try { $period = itops_graph_period($period); } catch (\InvalidArgumentException $e) { return ['configured' => true, 'value' => [], 'error' => $e->getMessage(), 'needs_permission' => false]; }
        try {
            return ['configured' => true, 'value' => itops_graph_report($report, $period)];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $needsPerm = (strpos($msg, '403') !== false) || (stripos($msg, 'denied') !== false) || (stripos($msg, 'Reports.Read.All') !== false);
            return ['configured' => true, 'value' => [], 'error' => $msg, 'needs_permission' => $needsPerm];
        }
    }
}

// ── Template field catalog (own guard: also needed when a Graph mock replaces the client above) ──
if (!function_exists('itops_graph_settable_fields')) {
/**
 * Entra user properties an onboarding template (or M365 Admin create) may set.
 * ONE list drives three places so they can't drift: the template field picker
 * (/m365/user-fields), the create allow-list in ObTasks m365_create, and
 * m365_admin/create_user. Person fields captured on the run itself (name,
 * employee id, phone, office) are set from the run and are NOT listed here.
 */
function itops_graph_settable_fields(): array {
    return [
        'jobTitle'         => 'Job title',
        'department'       => 'Department',
        'companyName'      => 'Company name',
        'employeeType'     => 'Employee type (Employee / Contractor…)',
        'officeLocation'   => 'Office location',
        'streetAddress'    => 'Street address',
        'city'             => 'City',
        'state'            => 'State / province',
        'postalCode'       => 'Postal code',
        'country'          => 'Country',
        'businessPhones'   => 'Business phone',
        'employeeHireDate' => 'Hire date (YYYY-MM-DD)',
        'preferredLanguage'=> 'Preferred language (e.g. en-US)',
    ];
}
/** Filter a template's extra-fields map to settable keys and shape values for Graph. */
function itops_graph_clean_fields($fields): array {
    if (!is_array($fields)) return [];
    $allow = itops_graph_settable_fields(); $out = [];
    foreach ($fields as $k => $v) {
        if (!isset($allow[$k]) || !is_scalar($v)) continue;
        $v = trim((string)$v); if ($v === '') continue;
        if ($k === 'businessPhones') { $out[$k] = [substr($v, 0, 64)]; continue; }   // Graph wants an array
        if ($k === 'employeeHireDate') { $ts = strtotime($v); if (!$ts) continue; $out[$k] = gmdate('Y-m-d\T00:00:00\Z', $ts); continue; }
        $out[$k] = substr($v, 0, 256);
    }
    return $out;
}
}

if (!function_exists('itops_graph_paged')) {
/** Follow @odata.nextLink up to $maxPages (bounded — we never walk a huge tenant). */
    function itops_graph_paged(string $path, int $maxPages = 3, array $headers = []): array {
    $rows = []; $url = 'https://graph.microsoft.com/v1.0/' . $path;
    for ($i = 0; $i < $maxPages && $url; $i++) {
        [$code, $data] = itops_graph_http('GET', $url, null, $headers);
        if ($code >= 400) throw new RuntimeException((string)($data['error']['message'] ?? ('HTTP ' . $code)));
        foreach (($data['value'] ?? []) as $r) $rows[] = $r;
        $url = $data['@odata.nextLink'] ?? null;
    }
    if ($url) {   // BT88: a list cut by the page limit is said, not hidden
        $GLOBALS['ITOPS_GRAPH_TRUNCATED'] = true;
        if (function_exists('itops_log')) { try { itops_log('warning', 'm365', 'A Microsoft 365 list was longer than ' . count($rows) . ' rows; only those were read.', ['path' => strtok($path, '?')]); } catch (\Throwable $e) {} }
    }
    return $rows;
}

}

if (!function_exists('itops_auth_method_meta')) {
    /** Sign-in (MFA) methods ITOps explains, in recommended order: id => [name, what it is, advice: on|optional|off]. */
    function itops_auth_method_meta(): array {
        return [
            'microsoftAuthenticator' => ['Microsoft Authenticator app', 'Approve sign-ins in the free phone app (number matching).', 'on'],
            'fido2'                  => ['Security keys / passkeys (FIDO2)', 'USB or NFC keys the company provides — MFA without a personal phone. Strongest option.', 'on'],
            'temporaryAccessPass'    => ['Temporary Access Pass', 'Time-limited pass IT issues so someone can set up a key or a new phone, or get in after losing one.', 'on'],
            'sms'                    => ['Text message', 'Code by SMS to any phone. Weakest option — fine as a start or backup.', 'optional'],
            'voice'                  => ['Phone call', 'Automated call to any phone (including a landline).', 'optional'],
            'softwareOath'           => ['Other authenticator apps (codes)', 'Six-digit codes from apps such as Google Authenticator.', 'optional'],
            'hardwareOath'           => ['Hardware code tokens', 'Small keyfob that shows a code. Needs IT to load each token.', 'optional'],
            'email'                  => ['Email one-time code', 'Only used for guests / password reset — not for staff MFA.', 'off'],
            'x509Certificate'        => ['Certificates / smart cards', 'Needs a certificate authority. Not needed for Business Premium setups like ours.', 'off'],
        ];
    }
}

// ── BT55: usage-report periods (own guard: also needed when a Graph mock replaces the client above) ──
if (!function_exists('itops_graph_period_next')) {
    /**
     * Microsoft's report refusals name the period to use, in two shapes seen on the live tenant:
     *   "The period value 'RL30' is not supported with version 'v2'. Use 'RL28' instead."  (Dn is read as RLn)
     *   "Invalid period value specified: RL28. Supported values are: D7,D28,D30,D90,D180,ALL"
     * Returns the next period to ask with (never one already in $tried), or null when the message names none.
     * "Use 'RLn'" → Dn first (what the caller sends), then RLn; a "Supported values" list → the one closest in
     * days to the period just refused. Only plain codes (letters + digits) are ever taken from the message.
     */
    function itops_graph_period_next(string $msg, string $current, array $tried): ?string {
        $days = fn(string $p): int => preg_match('/(\d+)$/', $p, $m) ? (int)$m[1] : 0;
        $cands = [];
        if (preg_match("/Use\\s+'([A-Z]{1,3})(\\d{1,3})'\\s+instead/i", $msg, $h)) {
            $cands[] = 'D' . (int)$h[2];
            $cands[] = strtoupper($h[1]) . (int)$h[2];
        }
        if (preg_match('/Supported values are:?\s*([A-Za-z0-9 ,]+)/', $msg, $sv)) {
            $want = $days($current) ?: 30;
            $list = array_values(array_filter(array_map(fn($x) => strtoupper(trim($x)), explode(',', $sv[1])), fn($x) => preg_match('/^[A-Z]{1,3}\d{1,3}$/', $x)));
            usort($list, fn($a, $b) => (abs($days($a) - $want) <=> abs($days($b) - $want)) ?: ($days($a) <=> $days($b)));
            $cands = array_merge($cands, $list);
        }
        foreach ($cands as $c) if (preg_match('/^[A-Z]{1,3}\d{1,3}$/', $c) && !in_array($c, $tried, true)) return $c;
        return null;
    }
}

if (!function_exists('itops_graph_period')) {
    /**
     * BT88 (code audit H-9, IDs in Graph paths): a report period is letters and digits only (D7, D30, D90, D180, ALL…),
     * and the path part of every Graph address is plain — no "..", no encoded slash, dot, question mark or #, no spaces.
     */
    function itops_graph_period(string $p): string {
        $p = strtoupper(trim($p));
        if (!preg_match('/^(ALL|[A-Z]{1,3}\d{1,3})$/', $p)) throw new InvalidArgumentException('That report period is not valid (D7, D30, D90 or D180).');
        return $p;
    }
    function itops_graph_url_ok(string $url): void {
        $h = strtolower((string)parse_url($url, PHP_URL_HOST));
        if (!in_array($h, ['graph.microsoft.com', 'login.microsoftonline.com'], true)) throw new RuntimeException('Not a Microsoft Graph address.');
        $path = (string)parse_url($url, PHP_URL_PATH);
        if (preg_match('~(^|/)\.\.(/|$)|\\\\|%2f|%5c|%2e%2e|%3f|%23|%00|[\s\x00-\x1f]~i', $path)) throw new RuntimeException('That id is not valid: it cannot be part of a Microsoft 365 address.');
    }
}
