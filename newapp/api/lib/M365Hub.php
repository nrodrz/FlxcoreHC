<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
declare(strict_types=1);

// ITOps BT21 — Microsoft 365 domain service (lib/M365.php design), kept as close to that design as
// possible so both apps offer the same Microsoft 365 options. Changes from the original:
//   class M365 → ItopsM365, ItopsM365Exception → ItopsM365Exception, GraphClient → ItopsGraphClient
//   (ITOps's Graph connection), fromConfig() reads ITOps's settings. 0001-A004: the vTiger linkage is gone (Microsoft 365 only).
//
// M365.php — domain service over GraphClient for the Microsoft 365 console.
// Phase 1: directory reads (users, SKUs), account enable/disable, password and
// MFA reset, and session revocation. All app-only via the daemon app registration.

require_once __DIR__ . '/ItopsGraphClient.php';

if (!class_exists('ItopsM365')) {
final class ItopsM365Exception extends Exception {}

final class ItopsM365
{
    private ItopsGraphClient $g;

    public function __construct(ItopsGraphClient $graph)
    {
        $this->g = $graph;
    }

    /** Build from ITOps's Microsoft 365 connection; throws a friendly error if it isn't set up. */
    public static function fromConfig(array $config = []): self
    {
        if (!function_exists('itops_graph_configured') || !itops_graph_configured()) {
            throw new ItopsM365Exception(
                'Microsoft 365 is not connected. Open Settings → Microsoft 365 → Connection and save the app registration first.'
            );
        }
        return new self(new ItopsGraphClient());
    }

    private const USER_SELECT_LIST = 'id,displayName,userPrincipalName,mail,accountEnabled,jobTitle,department,userType,assignedLicenses';
    private const USER_SELECT_FULL = 'id,displayName,userPrincipalName,mail,accountEnabled,jobTitle,department,officeLocation,mobilePhone,usageLocation,userType,createdDateTime,assignedLicenses,'
        // BT38: everything the edit screen shows. Microsoft returns these only when they are asked for by name.
        . 'givenName,surname,companyName,businessPhones,faxNumber,streetAddress,city,state,postalCode,country,employeeId,employeeType,preferredLanguage,onPremisesSyncEnabled,onPremisesExtensionAttributes';

    /** Directory roles that warrant an extra-strong confirmation in the UI. */
    private const HIGH_RISK_ROLES = [
        'Global Administrator', 'Privileged Role Administrator', 'Privileged Authentication Administrator',
        'Application Administrator', 'Cloud Application Administrator', 'Security Administrator',
        'User Administrator', 'Authentication Administrator', 'Hybrid Identity Administrator',
    ];

    // ---- Directory reads --------------------------------------------------

    /**
     * One page of users. $q filters by name/UPN/mail (startswith). Returns
     * ['users' => [...], 'next_token' => ?string].
     */
    public function listUsers(string $q = '', int $top = 50, string $skipToken = '', string $domain = ''): array
    {
        $query = ['$select' => self::USER_SELECT_LIST, '$top' => max(1, min(100, $top))];
        $q = trim($q);
        $search = '';
        if ($q !== '') {
            $safe = str_replace("'", "''", $q);
            $search = "startswith(displayName,'{$safe}') or startswith(userPrincipalName,'{$safe}') or startswith(mail,'{$safe}')";
        }
        // BT36: one domain only. "Ends with" is an advanced directory query: Microsoft wants ConsistencyLevel: eventual
        // and $count=true, and in return gives the total and lets the result be sorted by name.
        $domain = self::cleanDomain($domain);
        if ($domain !== '') {
            $query['$filter'] = "endswith(userPrincipalName,'@{$domain}')" . ($search !== '' ? " and ({$search})" : '');
            $query['$count'] = 'true';
            $query['$orderby'] = 'displayName';
            if ($skipToken !== '') $query['$skiptoken'] = $skipToken;
            $res = $this->g->getWith('users', $query, ['ConsistencyLevel: eventual']);
            return ['users' => array_map([self::class, 'shapeListUser'], $res['value'] ?? []),
                    'next_token' => self::skipTokenFrom($res['@odata.nextLink'] ?? null),
                    'domain' => $domain, 'total' => isset($res['@odata.count']) ? (int)$res['@odata.count'] : null];
        }
        if ($search !== '') $query['$filter'] = $search; else $query['$orderby'] = 'displayName';
        if ($skipToken !== '') $query['$skiptoken'] = $skipToken;

        $res = $this->g->get('users', $query);
        $users = array_map([self::class, 'shapeListUser'], $res['value'] ?? []);
        return ['users' => $users, 'next_token' => self::skipTokenFrom($res['@odata.nextLink'] ?? null), 'domain' => '', 'total' => null];
    }

    /**
     * BT48, the Accounts grid: every account with the values a column can show, in as few requests as Microsoft allows
     * (999 accounts a page). Not read here, because either makes Microsoft answer in small pages: the sign-in dates
     * (directoryForRecon() has them, and the grid asks for them only when that column is shown) and the managers
     * (gridManagers(), the same). 'profile' is keyed like profileFields() plus ext1…ext15, as the edit route expects.
     */
    public function gridUsers(array $skuMap = []): array
    {
        $rows = $this->g->getAll('users', ['$select' => self::GRID_SELECT, '$top' => 999]);
        $out = [];
        foreach ($rows as $u) $out[] = self::gridRow($u, $skuMap);
        usort($out, fn($x, $y) => strcasecmp($x['display_name'] ?: $x['upn'], $y['display_name'] ?: $y['upn']));
        return $out;
    }

    private const GRID_SELECT = 'id,displayName,userPrincipalName,mail,accountEnabled,userType,createdDateTime,assignedLicenses,givenName,surname,jobTitle,department,'
             . 'companyName,officeLocation,businessPhones,mobilePhone,faxNumber,streetAddress,city,state,postalCode,country,usageLocation,employeeId,'
             . 'employeeType,preferredLanguage,onPremisesSyncEnabled,onPremisesExtensionAttributes';

    /** One Graph user → one Accounts-grid row. */
    public static function gridRow(array $u, array $skuMap = []): array
    {
        $lic = [];
        foreach (($u['assignedLicenses'] ?? []) as $l) { $sid = (string)($l['skuId'] ?? ''); if ($sid !== '') $lic[] = (string)($skuMap[$sid] ?? $sid); }
        sort($lic, SORT_NATURAL | SORT_FLAG_CASE);
        return [
            'id'           => (string)($u['id'] ?? ''),
            'display_name' => (string)($u['displayName'] ?? ''),
            'upn'          => (string)($u['userPrincipalName'] ?? ''),
            'mail'         => (string)($u['mail'] ?? ''),
            'enabled'      => (bool)($u['accountEnabled'] ?? false),
            'user_type'    => (string)($u['userType'] ?? ''),
            'synced'       => ($u['onPremisesSyncEnabled'] ?? null) === true,
            'created'      => (string)($u['createdDateTime'] ?? ''),
            'licenses'     => $lic,
            'profile'      => self::profileFrom($u),
        ];
    }

    /** BT53: one account read by its id, as a grid row (right after it was created, before the list shows it). */
    public function gridUser(string $id, array $skuMap = []): array
    {
        $u = $this->g->get('users/' . rawurlencode($id), ['$select' => self::GRID_SELECT]);
        return self::gridRow(is_array($u) ? $u : [], $skuMap);
    }

    /** BT48: account id => its manager {id, name, upn}, for every account that has one. A page of 999 is tried first;
     *  if Microsoft refuses that size together with the expansion, pages of 100. */
    public function gridManagers(): array
    {
        $q = ['$select' => 'id', '$expand' => 'manager($select=id,displayName,userPrincipalName)'];
        try { $rows = $this->g->getAll('users', $q + ['$top' => 999]); }
        catch (Throwable $e) { $rows = $this->g->getAll('users', $q + ['$top' => 100], 200); }
        $out = [];
        foreach ($rows as $u) {
            $m = $u['manager'] ?? null;
            if (is_array($m) && !empty($m['id'])) $out[(string)($u['id'] ?? '')] = ['id' => (string)$m['id'], 'name' => (string)($m['displayName'] ?? ''), 'upn' => (string)($m['userPrincipalName'] ?? '')];
        }
        return $out;
    }

    /** A domain name safe to place in a filter ("example.org"), or '' — a leading "@" is accepted. Anything else throws. */
    public static function cleanDomain(string $domain): string
    {
        $d = strtolower(trim($domain)); if ($d !== '' && $d[0] === '@') $d = substr($d, 1);
        if ($d === '') return '';
        if (strlen($d) > 253 || !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $d)) throw new InvalidArgumentException('That is not a domain name.');
        return $d;
    }

    /** BT49: what the last roster read found out about Microsoft's listing with sign-in dates (for the caller to report). */
    public static array $rosterNote = ['listed' => 0, 'missing' => 0, 'unread' => 0];

    /**
     * BT49: every account, with its sign-in dates where Microsoft gives them.
     *
     * The roster is a plain listing, which Microsoft pages completely (999 a page). The sign-in dates come from a second
     * listing (`$select=id,signInActivity`, 120 a page at most) and are merged in by id. They are NOT read together any
     * more. Reported from the live tenant on 2026-10-07: some twenty accounts that exist were missing from every report
     * built on the combined listing (shown as "In vTiger only / no account"), while the plain listing of the Accounts
     * grid had them. The combined listing is the only place they could have been lost; this is the suspected cause, not
     * a confirmed one, so the difference between the two listings is counted (self::$rosterNote) and written to the Event
     * Log. An account the sign-in listing leaves out is asked for by itself (up to 150 accounts, 25 seconds); what still could not
     * be read is marked `_sia_known = false`, which means "not known", never "never signed in".
     * Returns ['rows' => Graph user rows + 'signInActivity' + '_sia_known', 'has_sign_in' => bool].
     */
    private function rosterWithSignIn(string $select): array
    {
        $rows = $this->g->getAll('users', ['$select' => $select, '$top' => 999]);
        $hasSignIn = true; $sia = [];
        try {
            foreach ($this->g->getAll('users', ['$select' => 'id,signInActivity', '$top' => 120], 200) as $u) {
                $id = (string)($u['id'] ?? ''); if ($id !== '') $sia[$id] = is_array($u['signInActivity'] ?? null) ? $u['signInActivity'] : null;
            }
        } catch (Throwable $e) { $hasSignIn = false; }   // no Entra ID P1, or AuditLog.Read.All is missing: the roster still stands
        $missing = [];
        if ($hasSignIn) foreach ($rows as $u) {
            $id = (string)($u['id'] ?? '');
            if ($id !== '' && ($u['userType'] ?? '') !== 'Guest' && !array_key_exists($id, $sia)) $missing[] = $id;
        }
        $t0 = microtime(true); $asked = 0; $unread = 0;
        foreach ($missing as $id) {
            if ($asked >= 150 || microtime(true) - $t0 > 25) { $unread++; continue; }
            $asked++;
            try { $one = $this->g->get("users/{$id}", ['$select' => 'id,signInActivity']); $sia[$id] = is_array($one['signInActivity'] ?? null) ? $one['signInActivity'] : null; }
            catch (Throwable $e) { $unread++; }
        }
        self::$rosterNote = ['listed' => count($rows), 'missing' => count($missing), 'unread' => $unread];
        if ($missing && function_exists('itops_log')) {
            try { itops_log('warning', 'm365', "Microsoft 365: the listing with sign-in dates left out " . count($missing) . ' of ' . count($rows) . ' accounts. They are in the reports all the same; their sign-in dates were read one by one'
                . ($unread ? ' (' . $unread . ' could not be read and show as not known)' : '') . '.', ['missing' => count($missing), 'accounts' => count($rows), 'unread' => $unread]); } catch (Throwable $e) {}
        }
        foreach ($rows as $i => $u) {
            $id = (string)($u['id'] ?? '');
            $rows[$i]['signInActivity'] = $sia[$id] ?? null;
            $rows[$i]['_sia_known'] = $hasSignIn && array_key_exists($id, $sia);
        }
        return ['rows' => $rows, 'has_sign_in' => $hasSignIn];
    }

    /** License reclamation: licensed accounts that are disabled or idle (no sign-in
     *  within $idleDays). signInActivity needs Entra ID P1; without it, idle can't be
     *  computed and only disabled-but-licensed accounts are flagged. */
    public function licenseReport(int $idleDays = 30): array
    {
        $r = $this->rosterWithSignIn('id,displayName,userPrincipalName,mail,accountEnabled,userType,assignedLicenses');   // BT49: a complete roster
        $rows = $r['rows']; $hasSignIn = $r['has_sign_in'];

        $skus = $this->skus();
        $skuMap = $skus['map'];
        $summary = [];
        foreach ($skus['list'] as $sk) {
            $summary[$sk['sku_id']] = ['sku_id' => $sk['sku_id'], 'name' => $sk['name'], 'total' => $sk['total'], 'consumed' => $sk['consumed'], 'reclaimable' => 0];
        }

        $cutoff = time() - ($idleDays * 86400);
        $candidates = [];
        foreach ($rows as $u) {
            if (($u['userType'] ?? '') === 'Guest') continue;
            $lics = $u['assignedLicenses'] ?? [];
            if (count($lics) === 0) continue;
            $enabled = (bool)($u['accountEnabled'] ?? false);
            $sia = $u['signInActivity'] ?? null;
            // BT124 (code review M365A-5): idle means no sign-in of any kind — a phone app refreshing its sign-in counts
            $last = max((string)($sia['lastSignInDateTime'] ?? ''), (string)($sia['lastNonInteractiveSignInDateTime'] ?? ''), (string)($sia['lastSuccessfulSignInDateTime'] ?? '')) ?: null;
            $lastTs = $last ? strtotime($last) : null;
            // BT49: an account whose sign-in dates could not be read is not called idle ("not known" is not "never")
            $idle = ($hasSignIn && ($u['_sia_known'] ?? true)) ? ($lastTs === null || $lastTs < $cutoff) : false;
            $reasons = [];
            if (!$enabled) $reasons[] = 'disabled';
            if ($enabled && $idle) $reasons[] = ($lastTs === null ? 'never signed in' : ('idle ' . $idleDays . 'd+'));
            if (!$reasons) continue;
            $userSkus = [];
            foreach ($lics as $lic) {
                $sid = (string)($lic['skuId'] ?? '');
                if ($sid === '') continue;
                $userSkus[] = ['sku_id' => $sid, 'name' => $skuMap[$sid] ?? $sid];
                if (isset($summary[$sid])) $summary[$sid]['reclaimable']++;
            }
            $candidates[] = [
                'id' => (string)($u['id'] ?? ''), 'display_name' => (string)($u['displayName'] ?? ''),
                'upn' => (string)($u['userPrincipalName'] ?? ''), 'enabled' => $enabled,
                'last_sign_in' => $last, 'reasons' => $reasons, 'licenses' => $userSkus,
            ];
        }
        usort($candidates, function ($a, $b) {
            if ($a['enabled'] !== $b['enabled']) return $a['enabled'] ? 1 : -1;
            return strcmp((string)($a['last_sign_in'] ?? ''), (string)($b['last_sign_in'] ?? ''));
        });
        return [
            'idle_days' => $idleDays, 'has_sign_in' => $hasSignIn,
            'skus' => array_values($summary), 'candidates' => $candidates,
            'reclaimable_total' => count($candidates),
        ];
    }

    /** All directory members with the few fields reconciliation needs. Guests are
     *  excluded. signInActivity needs Entra ID P1; we degrade gracefully and flag
     *  whether it was available so the UI can explain a missing column. */
    public function directoryForRecon(): array
    {
        // BT49: the roster no longer depends on the listing that carries sign-in dates (see rosterWithSignIn()).
        $r = $this->rosterWithSignIn('id,displayName,userPrincipalName,mail,accountEnabled,userType,assignedLicenses');
        $rows = $r['rows']; $hasSignIn = $r['has_sign_in'];
        $out = [];
        foreach ($rows as $u) {
            if (($u['userType'] ?? '') === 'Guest') continue;
            $sia = $u['signInActivity'] ?? null;
            $out[] = [
                'id'            => (string)($u['id'] ?? ''),
                'display_name'  => (string)($u['displayName'] ?? ''),
                'upn'           => (string)($u['userPrincipalName'] ?? ''),
                'mail'          => (string)($u['mail'] ?? ''),
                'enabled'       => (bool)($u['accountEnabled'] ?? false),
                'license_count' => count($u['assignedLicenses'] ?? []),
                'license_skus'  => array_values(array_filter(array_map(fn($l) => (string)($l['skuId'] ?? ''), $u['assignedLicenses'] ?? []))),
                'sign_in_known' => (bool)($u['_sia_known'] ?? false),
                'last_sign_in'  => $sia['lastSignInDateTime'] ?? ($sia['lastNonInteractiveSignInDateTime'] ?? null),
                // BT27: newest of the interactive and the background (token refresh) sign-in — "is this person still active"
                'last_activity' => max((string)($sia['lastSignInDateTime'] ?? ''), (string)($sia['lastNonInteractiveSignInDateTime'] ?? ''), (string)($sia['lastSuccessfulSignInDateTime'] ?? '')) ?: null,
            ];
        }
        return ['users' => $out, 'has_sign_in' => $hasSignIn, 'roster' => self::$rosterNote];
    }

    /**
     * BT49: ask Microsoft 365 for the account behind each address, one address at a time: its sign-in name, its mail
     * address, or one of its aliases. "Company systems" calls this before it says that an address has no account, so that
     * statement no longer rests on a listing alone. Bounded: at most $max addresses and $seconds seconds; the rest come
     * back as 'unchecked'.
     * Returns [address => ['state' => 'found'|'none'|'unchecked'|'error', 'how' => 'address'|'alias', 'user' => directory row, 'error' => …]].
     */
    public function accountsByAddress(array $emails, int $max = 60, float $seconds = 20.0): array
    {
        $sel = 'id,displayName,userPrincipalName,mail,accountEnabled,userType,assignedLicenses';
        $out = []; $t0 = microtime(true); $n = 0;
        foreach ($emails as $e) {
            $e = strtolower(trim((string)$e));
            if ($e === '' || isset($out[$e])) continue;
            if ($n >= $max || microtime(true) - $t0 > $seconds) { $out[$e] = ['state' => 'unchecked']; continue; }
            $n++; $q = str_replace("'", "''", $e);
            try {
                $how = 'address';
                $r = $this->g->get('users', ['$filter' => "userPrincipalName eq '{$q}' or mail eq '{$q}'", '$select' => $sel, '$top' => 5]);
                $u = $r['value'][0] ?? null;
                if (!$u) {
                    $how = 'alias';
                    $r = $this->g->get('users', ['$filter' => "proxyAddresses/any(p:p eq 'smtp:{$q}')", '$select' => $sel, '$top' => 5]);
                    $u = $r['value'][0] ?? null;
                }
                if (!$u) { $out[$e] = ['state' => 'none']; continue; }
                $out[$e] = ['state' => 'found', 'how' => $how, 'user' => [
                    'id'            => (string)($u['id'] ?? ''),
                    'display_name'  => (string)($u['displayName'] ?? ''),
                    'upn'           => (string)($u['userPrincipalName'] ?? ''),
                    'mail'          => (string)($u['mail'] ?? ''),
                    'enabled'       => (bool)($u['accountEnabled'] ?? false),
                    'guest'         => ($u['userType'] ?? '') === 'Guest',
                    'license_count' => count($u['assignedLicenses'] ?? []),
                    'license_skus'  => array_values(array_filter(array_map(fn($l) => (string)($l['skuId'] ?? ''), $u['assignedLicenses'] ?? []))),
                    'sign_in_known' => false, 'last_sign_in' => null, 'last_activity' => null,
                ]];
            } catch (Throwable $ex) { $out[$e] = ['state' => 'error', 'error' => $ex->getMessage()]; }
        }
        return $out;
    }

    /** Intune managed devices (read-only). Requires DeviceManagementManagedDevices.Read.All. */
    public function managedDevices(): array
    {
        $sel = 'id,deviceName,operatingSystem,osVersion,complianceState,managementState,manufacturer,model,userPrincipalName,lastSyncDateTime,enrolledDateTime,managementAgent,isEncrypted,managedDeviceOwnerType';
        $rows = $this->g->getAll('deviceManagement/managedDevices', ['$select' => $sel, '$top' => 100]);
        $out = [];
        foreach ($rows as $d) {
            $out[] = [
                'id'         => (string)($d['id'] ?? ''),
                'name'       => (string)($d['deviceName'] ?? ''),
                'os'         => trim(((string)($d['operatingSystem'] ?? '')) . ' ' . ((string)($d['osVersion'] ?? ''))),
                'compliance' => (string)($d['complianceState'] ?? ''),
                'managed'    => (string)($d['managementState'] ?? ''),
                'model'      => trim(((string)($d['manufacturer'] ?? '')) . ' ' . ((string)($d['model'] ?? ''))),
                'user'       => (string)($d['userPrincipalName'] ?? ''),
                'last_sync'  => $d['lastSyncDateTime'] ?? null,
                'enrolled'   => $d['enrolledDateTime'] ?? null,
                'agent'      => (string)($d['managementAgent'] ?? ''),
                'encrypted'  => (bool)($d['isEncrypted'] ?? false),
                'owner'      => strtolower((string)($d['managedDeviceOwnerType'] ?? '')),   // BT112: company | personal (personal devices are never locked)
            ];
        }
        return $out;
    }

    /** Microsoft Secure Score (latest). Requires SecurityEvents.Read.All. */
    public function secureScore(): ?array
    {
        $body = $this->g->get('security/secureScores', ['$top' => 1]);
        $s = $body['value'][0] ?? null;
        if (!$s) return null;
        $controls = [];
        foreach (($s['controlScores'] ?? []) as $c) {
            $controls[] = [
                'name'     => (string)($c['controlName'] ?? ''),
                'category' => (string)($c['controlCategory'] ?? ''),
                'score'    => (float)($c['score'] ?? 0),
            ];
        }
        return [
            'current'      => (float)($s['currentScore'] ?? 0),
            'max'          => (float)($s['maxScore'] ?? 0),
            'date'         => $s['createdDateTime'] ?? null,
            'active_users' => (int)($s['activeUserCount'] ?? 0),
            'controls'     => $controls,
        ];
    }

    /** Recent Defender security alerts (alerts_v2). Requires SecurityAlert.Read.All. */
    public function securityAlerts(int $top = 100): array
    {
        $body = $this->g->get('security/alerts_v2', ['$top' => max(1, min(200, $top)), '$orderby' => 'createdDateTime desc']);
        $out = [];
        foreach (($body['value'] ?? []) as $a) {
            $out[] = [
                'id'            => (string)($a['id'] ?? ''),
                'title'         => (string)($a['title'] ?? ''),
                'severity'      => (string)($a['severity'] ?? ''),
                'status'        => (string)($a['status'] ?? ''),
                'category'      => (string)($a['category'] ?? ''),
                'service'       => (string)($a['serviceSource'] ?? ''),
                'created'       => $a['createdDateTime'] ?? null,
                'determination' => (string)($a['determination'] ?? ''),
            ];
        }
        return $out;
    }

    /** Per-user MFA / auth-method registration (Identity report). Requires AuditLog.Read.All. */
    public function mfaRegistration(): array
    {
        // No $select/$top — some tenants reject a narrowed select on this report endpoint; pull full rows.
        $rows = $this->g->getAll('reports/authenticationMethods/userRegistrationDetails', []);
        $out = [];
        foreach ($rows as $u) {
            $out[] = [
                'upn'             => (string)($u['userPrincipalName'] ?? ''),
                'name'            => (string)($u['userDisplayName'] ?? ''),
                'is_admin'        => (bool)($u['isAdmin'] ?? false),
                'mfa_registered'  => (bool)($u['isMfaRegistered'] ?? false),
                'mfa_capable'     => (bool)($u['isMfaCapable'] ?? false),
                'sspr_registered' => (bool)($u['isSsprRegistered'] ?? false),
                'methods'         => array_values(array_filter(array_map('strval', $u['methodsRegistered'] ?? []))),
                'default_method'  => (string)($u['defaultMfaMethod'] ?? ''),
                'user_type'       => (string)($u['userType'] ?? ''),
            ];
        }
        return $out;
    }

    /** Service health: per-service status + issues (incidents/advisories). Requires ServiceHealth.Read.All. */
    public function serviceHealth(): array
    {
        $overviews = [];
        foreach ($this->g->getAll('admin/serviceAnnouncement/healthOverviews', []) as $o) {
            $overviews[] = ['service' => (string)($o['service'] ?? ''), 'status' => (string)($o['status'] ?? '')];
        }
        $issues = [];
        foreach ($this->g->getAll('admin/serviceAnnouncement/issues', []) as $i) {
            $issues[] = [
                'id'             => (string)($i['id'] ?? ''),
                'title'          => (string)($i['title'] ?? ''),
                'service'        => (string)($i['service'] ?? ''),
                'classification' => (string)($i['classification'] ?? ''),
                'status'         => (string)($i['status'] ?? ''),
                'feature'        => (string)($i['feature'] ?? ''),
                'resolved'       => (bool)($i['isResolved'] ?? false),
                'start'          => $i['startDateTime'] ?? null,
                'updated'        => $i['lastModifiedDateTime'] ?? null,
            ];
        }
        return ['overviews' => $overviews, 'issues' => $issues];
    }

    /** Message center posts (service change announcements). Requires ServiceMessage.Read.All. */
    public function messageCenter(int $limit = 100): array
    {
        $out = [];
        foreach ($this->g->getAll('admin/serviceAnnouncement/messages', []) as $m) {
            $out[] = [
                'id'           => (string)($m['id'] ?? ''),
                'title'        => (string)($m['title'] ?? ''),
                'category'     => (string)($m['category'] ?? ''),
                'severity'     => (string)($m['severity'] ?? ''),
                'services'     => array_values(array_map('strval', $m['services'] ?? [])),
                'major_change' => (bool)($m['isMajorChange'] ?? false),
                'act_by'       => $m['actionRequiredByDateTime'] ?? null,
                'start'        => $m['startDateTime'] ?? null,
                'updated'      => $m['lastModifiedDateTime'] ?? null,
            ];
        }
        usort($out, fn($a, $b) => strcmp((string)$b['updated'], (string)$a['updated']));
        return array_slice($out, 0, $limit);
    }

    /** Exchange mailbox usage detail. Requires Reports.Read.All. JSON via $format. */
    public function mailboxUsage(string $period = 'D30'): array
    {
        $period = itops_graph_period($period);   // BT88
        $body = $this->g->getReportBeta("reports/getMailboxUsageDetail(period='{$period}')");
        $out = [];
        foreach (($body['value'] ?? []) as $r) {
            $used  = (float)($r['storageUsedInBytes'] ?? 0);
            $quota = (float)($r['prohibitSendReceiveQuotaInBytes'] ?? 0);
            $out[] = [
                'upn'           => (string)($r['userPrincipalName'] ?? ''),
                'name'          => (string)($r['displayName'] ?? ''),
                'items'         => (int)($r['itemCount'] ?? 0),
                'used_bytes'    => $used,
                'quota_bytes'   => $quota,
                'pct'           => $quota > 0 ? round($used / $quota * 100, 1) : null,
                'last_activity' => $r['lastActivityDate'] ?? null,
                'deleted'       => (bool)($r['isDeleted'] ?? false),
            ];
        }
        return $out;
    }

    /** Microsoft Teams user activity detail. Requires Reports.Read.All. JSON via $format. */
    public function teamsActivity(string $period = 'D30'): array
    {
        $period = itops_graph_period($period);   // BT88
        $body = $this->g->getReportBeta("reports/getTeamsUserActivityUserDetail(period='{$period}')");
        $out = [];
        foreach (($body['value'] ?? []) as $r) {
            $out[] = [
                'upn'           => (string)($r['userPrincipalName'] ?? ''),
                'last_activity' => $r['lastActivityDate'] ?? null,
                'team_chat'     => (int)($r['teamChatMessageCount'] ?? 0),
                'private_chat'  => (int)($r['privateChatMessageCount'] ?? 0),
                'calls'         => (int)($r['callCount'] ?? 0),
                'meetings'      => (int)($r['meetingCount'] ?? ($r['meetingsAttendedCount'] ?? 0)),
                'licensed'      => (bool)($r['isLicensed'] ?? false),
                'deleted'       => (bool)($r['isDeleted'] ?? false),
            ];
        }
        return $out;
    }

    /** BT55: the period Microsoft actually accepted for the last Copilot report (e.g. 'D28'), and its length in days. */
    public string $copilotPeriod = 'D30';
    public static function periodDays(string $p): int { return preg_match('/(\d+)$/', $p, $m) ? (int)$m[1] : 30; }

    /**
     * BT55: Copilot reports, v1.0 first then beta. Microsoft moved these reports to a new version that refuses the
     * documented 30 days and says so in one of two ways ("The period value 'RL30' is not supported with version 'v2'.
     * Use 'RL28' instead." / "Invalid period value specified: RL28. Supported values are: D7,D28,…").
     * itops_graph_period_next() reads both; ask again with what it gives (at most four periods in all) and remember the
     * one that worked per report (cache file copilot-period2; the first file could hold 'RL28', which Microsoft refuses).
     */
    private function copilotReport(string $fn, string $period): array
    {
        $period = itops_graph_period($period);   // BT88
        if (!preg_match('/^[A-Za-z0-9]{3,80}$/', $fn)) throw new InvalidArgumentException('Unknown report.');
        $memo = function_exists('itops_m365_cache_file') ? itops_m365_cache_file('copilot-period2') : null;
        $known = ($memo && is_file($memo)) ? (json_decode((string)@file_get_contents($memo), true) ?: []) : [];
        $asked = $period; $p = $period; $last = null; $tried = [];
        $mk = $fn . '|' . $asked;
        if (!empty($known[$mk]) && preg_match('/^[A-Z]{1,3}\d{1,3}$/', (string)$known[$mk])) $p = (string)$known[$mk];
        while (count($tried) < 4) {
            $tried[] = $p;
            $path = "copilot/reports/{$fn}(period='{$p}')";
            $next = null;
            try { $body = $this->g->getReport($path); $this->remember($memo, $known, $mk, $p, $asked); return $body; }
            catch (\Throwable $e) { $last = $e; $next = itops_graph_period_next($e->getMessage(), $p, $tried); }
            if ($next === null) {
                // Not yet GA on v1.0 in some tenants — fall back to the beta endpoint.
                try { $body = $this->g->getReportBeta($path); $this->remember($memo, $known, $mk, $p, $asked); return $body; }
                catch (\Throwable $e) { $last = $e; $next = itops_graph_period_next($e->getMessage(), $p, $tried); }
            }
            if ($next === null) break;
            $p = $next;
        }
        throw $last;
    }

    private function remember(?string $memo, array $known, string $mk, string $p, string $asked): void
    {
        $this->copilotPeriod = $p;
        if (!$memo || ($known[$mk] ?? $asked) === $p) return;
        if ($p === $asked) unset($known[$mk]); else $known[$mk] = $p;
        @mkdir(dirname($memo), 0700, true); @file_put_contents($memo, json_encode($known), LOCK_EX); @chmod($memo, 0600);
    }

    /** Microsoft 365 Copilot usage per user. Requires Reports.Read.All. JSON via $format. */
    public function copilotUsage(string $period = 'D30'): array
    {
        $body = $this->copilotReport('getMicrosoft365CopilotUsageUserDetail', $period);
        $apps = [
            'Teams'      => 'microsoftTeamsCopilotLastActivityDate',
            'Word'       => 'wordCopilotLastActivityDate',
            'Excel'      => 'excelCopilotLastActivityDate',
            'PowerPoint' => 'powerPointCopilotLastActivityDate',
            'Outlook'    => 'outlookCopilotLastActivityDate',
            'OneNote'    => 'oneNoteCopilotLastActivityDate',
            'Loop'       => 'loopCopilotLastActivityDate',
            'Chat'       => 'copilotChatLastActivityDate',
        ];
        $out = [];
        foreach (($body['value'] ?? []) as $r) {
            $used = [];
            foreach ($apps as $label => $field) { if (!empty($r[$field])) $used[] = $label; }
            $out[] = [
                'upn'           => (string)($r['userPrincipalName'] ?? ''),
                'name'          => (string)($r['displayName'] ?? ''),
                'last_activity' => $r['lastActivityDate'] ?? null,
                'apps_used'     => $used,
                'app_count'     => count($used),
            ];
        }
        return $out;
    }

    /**
     * Daily active-vs-enabled Copilot users per app over a period (D7/D30/D90/
     * D180) from getMicrosoft365CopilotUserCountTrend. This is the adoption
     * TREND the per-user detail can't show: it answers "is usage growing?".
     * Returns ['series' => [per-day Any App], 'per_app' => [latest day], 'latest' => date].
     */
    public function copilotTrend(string $period = 'D30'): array
    {
        $body = $this->copilotReport('getMicrosoft365CopilotUserCountTrend', $period);
        $rows = $body['value'] ?? [];
        $apps = ['Microsoft Teams', 'Word', 'PowerPoint', 'Outlook', 'Excel', 'OneNote', 'Loop', 'Copilot Chat', 'Any App'];
        $latest = null;
        foreach ($rows as $r) {
            if ($latest === null || (string)($r['reportDate'] ?? '') > (string)($latest['reportDate'] ?? '')) $latest = $r;
        }
        $perApp = [];
        if ($latest) {
            foreach ($apps as $app) {
                $k = lcfirst(str_replace(' ', '', $app));
                $en = (int)($latest[$k . 'EnabledUsers'] ?? 0);
                $ac = (int)($latest[$k . 'ActiveUsers'] ?? 0);
                if ($en > 0 || $ac > 0) $perApp[] = ['app' => $app, 'enabled' => $en, 'active' => $ac, 'rate' => $en > 0 ? round(100.0 * $ac / $en, 1) : 0];
            }
        }
        $series = [];
        foreach ($rows as $r) {
            $series[] = ['date' => (string)($r['reportDate'] ?? ''), 'active' => (int)($r['anyAppActiveUsers'] ?? 0), 'enabled' => (int)($r['anyAppEnabledUsers'] ?? 0)];
        }
        usort($series, fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']));
        return ['series' => $series, 'per_app' => $perApp, 'latest' => $latest['reportDate'] ?? null];
    }

    public function directoryRecommendations(): array
    {
        try {
            $rows = $this->g->getAll('directory/recommendations', []);
        } catch (\Throwable $e) {
            $body = $this->g->getBeta('directory/recommendations', []);
            $rows = $body['value'] ?? [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'           => (string)($r['id'] ?? ''),
                'type'         => (string)($r['recommendationType'] ?? ''),
                'category'     => (string)($r['category'] ?? ''),
                'name'         => (string)($r['displayName'] ?? ''),
                'priority'     => (string)($r['priority'] ?? ''),
                'status'       => (string)($r['status'] ?? ''),
                'insight'      => is_string($r['insights'] ?? null) ? $r['insights'] : '',
                'benefits'     => is_string($r['benefits'] ?? null) ? $r['benefits'] : '',
                'impact_type'  => (string)($r['impactType'] ?? ''),
                'impacted'     => (int)($r['impactedResourceCount'] ?? 0),
                'last_checked' => $r['lastCheckedDateTime'] ?? null,
            ];
        }
        return $out;
    }

    private function credInfo(string $type, array $c, DateTime $now): array
    {
        $end = $c['endDateTime'] ?? null;
        $daysLeft = null; $expired = false;
        if ($end) {
            try { $e = new DateTime($end); $daysLeft = (int)$now->diff($e)->format('%r%a'); $expired = $daysLeft < 0; } catch (\Throwable $x) {}
        }
        return ['type' => $type, 'name' => (string)($c['displayName'] ?? ''), 'end' => $end, 'days_left' => $daysLeft, 'expired' => $expired];
    }

    /** App registrations with their secret/certificate credentials and expiry. Requires Application.Read.All. */
    public function appCredentials(): array
    {
        $sel = 'id,appId,displayName,createdDateTime,signInAudience,passwordCredentials,keyCredentials';
        $rows = $this->g->getAll('applications', ['$select' => $sel, '$top' => 200]);
        $now = new DateTime();
        $out = [];
        foreach ($rows as $a) {
            $creds = [];
            foreach (($a['passwordCredentials'] ?? []) as $c) { $creds[] = $this->credInfo('secret', $c, $now); }
            foreach (($a['keyCredentials'] ?? []) as $c) { $creds[] = $this->credInfo('certificate', $c, $now); }
            $soonest = null; $expired = 0; $expiringSoon = 0;
            foreach ($creds as $cr) {
                if ($cr['expired']) $expired++;
                elseif ($cr['days_left'] !== null && $cr['days_left'] <= 30) $expiringSoon++;
                if ($cr['end'] && (!$soonest || $cr['end'] < $soonest)) $soonest = $cr['end'];
            }
            $out[] = [
                'id'            => (string)($a['id'] ?? ''),
                'app_id'        => (string)($a['appId'] ?? ''),
                'name'          => (string)($a['displayName'] ?? ''),
                'created'       => $a['createdDateTime'] ?? null,
                'audience'      => (string)($a['signInAudience'] ?? ''),
                'creds'         => $creds,
                'cred_count'    => count($creds),
                'expired'       => $expired,
                'expiring_soon' => $expiringSoon,
                'soonest'       => $soonest,
            ];
        }
        return $out;
    }

    /** OneDrive usage per account. Requires Reports.Read.All. JSON via $format. */
    public function oneDriveUsage(string $period = 'D30'): array
    {
        $period = itops_graph_period($period);   // BT88
        $body = $this->g->getReportBeta("reports/getOneDriveUsageAccountDetail(period='{$period}')");
        $out = [];
        foreach (($body['value'] ?? []) as $r) {
            $used = (float)($r['storageUsedInBytes'] ?? 0);
            $alloc = (float)($r['storageAllocatedInBytes'] ?? 0);
            $out[] = [
                'owner'         => (string)($r['ownerDisplayName'] ?? ''),
                'upn'           => (string)($r['ownerPrincipalName'] ?? ''),
                'url'           => (string)($r['siteUrl'] ?? ''),
                'files'         => (int)($r['fileCount'] ?? 0),
                'active_files'  => (int)($r['activeFileCount'] ?? 0),
                'used_bytes'    => $used,
                'alloc_bytes'   => $alloc,
                'pct'           => $alloc > 0 ? round($used / $alloc * 100, 1) : null,
                'last_activity' => $r['lastActivityDate'] ?? null,
                'deleted'       => (bool)($r['isDeleted'] ?? false),
            ];
        }
        return $out;
    }

    /** SharePoint site usage. Requires Reports.Read.All. JSON via $format. */
    public function sharePointUsage(string $period = 'D30'): array
    {
        $period = itops_graph_period($period);   // BT88
        $body = $this->g->getReportBeta("reports/getSharePointSiteUsageDetail(period='{$period}')");
        $out = [];
        foreach (($body['value'] ?? []) as $r) {
            $used = (float)($r['storageUsedInBytes'] ?? 0);
            $alloc = (float)($r['storageAllocatedInBytes'] ?? 0);
            $out[] = [
                'owner'         => (string)($r['ownerDisplayName'] ?? ''),
                'url'           => (string)($r['siteUrl'] ?? ''),
                'template'      => (string)($r['rootWebTemplate'] ?? ''),
                'files'         => (int)($r['fileCount'] ?? 0),
                'active_files'  => (int)($r['activeFileCount'] ?? 0),
                'used_bytes'    => $used,
                'alloc_bytes'   => $alloc,
                'pct'           => $alloc > 0 ? round($used / $alloc * 100, 1) : null,
                'last_activity' => $r['lastActivityDate'] ?? null,
                'deleted'       => (bool)($r['isDeleted'] ?? false),
            ];
        }
        return $out;
    }

    /** Full detail for one user: profile, groups, licenses, auth methods, last sign-in. */
    public function getUser(string $id, array $skuMap = []): array
    {
        // signInActivity needs Entra ID P1; degrade gracefully if unavailable.
        try {
            $u = $this->g->get("users/{$id}", ['$select' => self::USER_SELECT_FULL . ',signInActivity']);
        } catch (Throwable $e) {
            $u = $this->g->get("users/{$id}", ['$select' => self::USER_SELECT_FULL]);
        }
        self::sameAccount($id, $u);   // BT41

        $groups = [];
        try {
            $mo = $this->g->get("users/{$id}/memberOf", ['$select' => 'id,displayName']);
            foreach ($mo['value'] ?? [] as $o) {
                if (($o['@odata.type'] ?? '') === '#microsoft.graph.group') {
                    $groups[] = ['id' => (string)($o['id'] ?? ''), 'name' => (string)($o['displayName'] ?? '')];
                }
            }
        } catch (Throwable $e) { /* groups are best-effort */ }

        $methods = [];
        try {
            $am = $this->g->get("users/{$id}/authentication/methods");
            foreach ($am['value'] ?? [] as $m) {
                $methods[] = self::methodLabel((string)($m['@odata.type'] ?? ''));
            }
        } catch (Throwable $e) { /* auth methods are best-effort */ }
        $methods = array_values(array_filter($methods));

        $licenses = [];
        foreach ($u['assignedLicenses'] ?? [] as $lic) {
            $sku = (string)($lic['skuId'] ?? '');
            if ($sku === '') continue;
            $licenses[] = ['sku_id' => $sku, 'name' => $skuMap[$sku] ?? $sku];
        }

        $sia = $u['signInActivity'] ?? null;
        return [
            'id'              => (string)($u['id'] ?? ''),
            'display_name'    => (string)($u['displayName'] ?? ''),
            'upn'             => (string)($u['userPrincipalName'] ?? ''),
            'mail'            => (string)($u['mail'] ?? ''),
            'enabled'         => (bool)($u['accountEnabled'] ?? false),
            'job_title'       => (string)($u['jobTitle'] ?? ''),
            'department'      => (string)($u['department'] ?? ''),
            'office'          => (string)($u['officeLocation'] ?? ''),
            'mobile'          => (string)($u['mobilePhone'] ?? ''),
            'usage_location'  => (string)($u['usageLocation'] ?? ''),
            'user_type'       => (string)($u['userType'] ?? ''),
            'created'         => (string)($u['createdDateTime'] ?? ''),
            'last_sign_in'    => $sia['lastSignInDateTime'] ?? ($sia['lastNonInteractiveSignInDateTime'] ?? null),
            'licenses'        => $licenses,
            'groups'          => $groups,
            'auth_methods'    => $methods,
            // BT38: contact information (keys as Microsoft names them) and whether the account is copied from an on-premises directory
            'profile'         => self::profileFrom($u),
            'synced'          => ($u['onPremisesSyncEnabled'] ?? null) === true,
            'onPremisesSyncEnabled' => ($u['onPremisesSyncEnabled'] ?? null) === true,
        ];
    }

    /** Subscribed SKUs as skuId => friendly name, plus seat usage. */
    public function skus(): array
    {
        $res = $this->g->get('subscribedSkus', ['$select' => 'skuId,skuPartNumber,prepaidUnits,consumedUnits']);
        $map = [];
        $list = [];
        foreach ($res['value'] ?? [] as $s) {
            $id = (string)($s['skuId'] ?? '');
            $part = (string)($s['skuPartNumber'] ?? '');
            $name = self::skuFriendly($part);
            if ($id !== '') $map[$id] = $name;
            $list[] = [
                'sku_id'   => $id,
                'name'     => $name,
                'part'     => $part,
                'consumed' => (int)($s['consumedUnits'] ?? 0),
                'total'    => (int)(($s['prepaidUnits']['enabled'] ?? 0)),
            ];
        }
        return ['map' => $map, 'list' => $list];
    }

    /**
     * BT58: the tenant's subscriptions with their renewal dates (GET directory/subscriptions, Organization.Read.All).
     * One SKU can have several subscriptions (bought at different times). Read only.
     */
    public function subscriptions(): array
    {
        $rows = $this->g->getAll('directory/subscriptions', []);
        $out = [];
        foreach ($rows as $s) {
            $out[] = [
                'sku_id'  => (string)($s['skuId'] ?? ''),
                'part'    => (string)($s['skuPartNumber'] ?? ''),
                'status'  => (string)($s['status'] ?? ''),
                'trial'   => (bool)($s['isTrial'] ?? false),
                'seats'   => (int)($s['totalLicenses'] ?? 0),
                'renews'  => $s['nextLifecycleDateTime'] ?? null,
                'created' => $s['createdDateTime'] ?? null,
            ];
        }
        return $out;
    }

    // ---- Mutations --------------------------------------------------------

    public function setAccountEnabled(string $id, bool $enabled): void
    {
        $this->g->patch("users/{$id}", ['accountEnabled' => $enabled]);
    }

    public function resetPassword(string $id, string $password, bool $forceChange = true): void
    {
        $this->g->patch("users/{$id}", [
            'passwordProfile' => [
                'password' => $password,
                'forceChangePasswordNextSignIn' => $forceChange,
            ],
        ]);
    }

    /** Remove every non-password auth method (phone, authenticator, etc.). Returns count removed. */
    public function resetMfa(string $id): array
    {
        $removed = 0; $errors = [];
        try {
            $am = $this->g->get("users/{$id}/authentication/methods");
        } catch (Throwable $e) {
            throw new ItopsM365Exception('Could not read the user\'s authentication methods: ' . $e->getMessage());
        }
        foreach ($am['value'] ?? [] as $m) {
            $type = (string)($m['@odata.type'] ?? '');
            $mid  = (string)($m['id'] ?? '');
            $seg  = self::methodEndpoint($type);
            if ($seg === null || $mid === '') continue; // password method has no deletable segment
            try {
                $this->g->delete("users/{$id}/authentication/{$seg}/{$mid}");
                $removed++;
            } catch (Throwable $e) {
                $errors[] = self::methodLabel($type) . ': ' . $e->getMessage();
            }
        }
        return ['removed' => $removed, 'errors' => $errors];
    }

    public function revokeSessions(string $id): void
    {
        $this->g->post("users/{$id}/revokeSignInSessions", []);
    }

    // ---- Lifecycle: onboard / offboard (Phase 2) -------------------------

    /** Verified domains, for the UPN builder. Returns [['id'=>..,'isDefault'=>bool], ...]. */
    public function domains(): array
    {
        $res = $this->g->get('domains', ['$select' => 'id,isVerified,isDefault']);
        $out = [];
        foreach ($res['value'] ?? [] as $d) {
            if (($d['isVerified'] ?? false) !== true) continue;
            $out[] = ['id' => (string)($d['id'] ?? ''), 'isDefault' => (bool)($d['isDefault'] ?? false)];
        }
        usort($out, fn($a, $b) => ($b['isDefault'] <=> $a['isDefault']) ?: strcasecmp($a['id'], $b['id']));
        return $out;
    }

    /**
     * Create a new account. Required: displayName, mailNickname, userPrincipalName, password.
     * Returns ['id','userPrincipalName','displayName']. Manager/licenses are set separately.
     */
    /**
     * The Entra/Graph user properties the app can set on create and store as
     * onboarding-template defaults. Shared by createUser (the write allow-list),
     * the m365-user-fields endpoint (the template field picker source), and
     * onboard-template-save (the storable-key allow-list) so all three agree.
     * `common: true` fields show as first-class inputs; the rest appear in the
     * "add a Microsoft field" picker. `businessPhone` maps to businessPhones[].
     */
    public static function settableUserFields(): array
    {
        return [
            ['key' => 'jobTitle',          'label' => 'Job title',          'common' => true],
            ['key' => 'department',        'label' => 'Department',         'common' => true],
            ['key' => 'companyName',       'label' => 'Company',            'common' => true],
            ['key' => 'officeLocation',    'label' => 'Office location',    'common' => true],
            ['key' => 'mobilePhone',       'label' => 'Mobile phone',       'common' => true],
            ['key' => 'businessPhone',     'label' => 'Business phone',     'common' => true],
            ['key' => 'employeeId',        'label' => 'Employee ID',        'common' => true],
            ['key' => 'employeeType',      'label' => 'Employee type',      'common' => false],
            ['key' => 'streetAddress',     'label' => 'Street address',     'common' => false],
            ['key' => 'city',              'label' => 'City',               'common' => false],
            ['key' => 'state',             'label' => 'State / province',   'common' => false],
            ['key' => 'postalCode',        'label' => 'Postal code',        'common' => false],
            ['key' => 'country',           'label' => 'Country',            'common' => false],
            ['key' => 'faxNumber',         'label' => 'Fax',               'common' => false],
            ['key' => 'preferredLanguage', 'label' => 'Preferred language', 'common' => false],
        ];
    }

    public function createUser(array $p): array
    {
        $upn     = trim((string)($p['userPrincipalName'] ?? ''));
        $display = trim((string)($p['displayName'] ?? ''));
        $nick    = trim((string)($p['mailNickname'] ?? ''));
        $pwd     = (string)($p['password'] ?? '');
        if ($upn === '' || $display === '' || $nick === '' || $pwd === '') {
            throw new ItopsM365Exception('Display name, username, domain, and password are all required.');
        }
        $body = [
            'accountEnabled'   => true,
            'displayName'      => $display,
            'mailNickname'     => $nick,
            'userPrincipalName' => $upn,
            'passwordProfile'  => [
                'password' => $pwd,
                'forceChangePasswordNextSignIn' => (bool)($p['forceChange'] ?? true),
            ],
        ];
        // givenName/surname are per-person but still passed through here.
        $passthrough = array_merge(['givenName', 'surname'],
            array_values(array_filter(array_column(self::settableUserFields(), 'key'), fn($k) => $k !== 'businessPhone')));
        foreach ($passthrough as $k) {
            if (!empty($p[$k])) $body[$k] = (string)$p[$k];
        }
        if (!empty($p['businessPhone'])) $body['businessPhones'] = [(string)$p['businessPhone']];
        // BT52: the usage location (two-letter country) was never sent, so Microsoft refused every license on a new account
        // ("License assignment cannot be done for user with invalid usage location").
        $loc = self::usageLocation($p['usageLocation'] ?? '');
        if ($loc !== '') $body['usageLocation'] = $loc;
        // BT47: the organization's custom fields (custom attributes 1 to 15). Microsoft accepts them at creation for a
        // cloud-only account. The caller has already checked each key and value ('custom' => [slot => text]).
        foreach (is_array($p['custom'] ?? null) ? $p['custom'] : [] as $slot => $val) {
            $slot = (int)$slot; if ($slot >= 1 && $slot <= 15 && (string)$val !== '') $body['onPremisesExtensionAttributes']['extensionAttribute' . $slot] = (string)$val;
        }
        $res = $this->g->post('users', $body);
        return [
            'id'                => (string)($res['id'] ?? ''),
            'userPrincipalName' => (string)($res['userPrincipalName'] ?? $upn),
            'displayName'       => (string)($res['displayName'] ?? $display),
        ];
    }

    /** BT52: a usage location as Microsoft wants it — two letters (ISO country), upper case; '' when none was given. */
    public static function usageLocation($v): string
    {
        $loc = strtoupper(trim((string)$v));
        if ($loc === '') return '';
        if (!preg_match('/^[A-Z]{2}$/', $loc)) throw new ItopsM365Exception('Usage location must be a two-letter country code, for example US.');
        return $loc;
    }

    /** BT52: set only the usage location (needed before a license can be assigned). */
    public function setUsageLocation(string $id, string $loc): void
    {
        $this->g->patch("users/{$id}", ['usageLocation' => self::usageLocation($loc)]);
    }

    /** Add and/or remove licenses by SKU id. */
    public function assignLicense(string $id, array $addSkuIds, array $removeSkuIds = []): void
    {
        $add    = array_values(array_map(fn($s) => ['skuId' => (string)$s], array_filter($addSkuIds)));
        $remove = array_values(array_map(fn($s) => (string)$s, array_filter($removeSkuIds)));
        if (!$add && !$remove) return;
        $this->g->post("users/{$id}/assignLicense", ['addLicenses' => $add, 'removeLicenses' => $remove]);
    }

    /** Set a user's manager (by object id). */
    public function setManager(string $id, string $managerId): void
    {
        $this->g->put("users/{$id}/manager/\$ref", ['@odata.id' => 'https://graph.microsoft.com/v1.0/users/' . $managerId]);
    }

    // ---- BT38: contact information, custom fields ------------------------------------------------------------

    /**
     * What this app may change on an account, and how Microsoft limits each value. One list drives the edit screen,
     * the validation and the audit text. "group" is the request the value travels in: Microsoft guards phone numbers
     * and custom attributes separately, so each group is sent by itself and one refusal does not hold back the rest.
     *   main   — PATCH users/{id}                          User.ReadWrite.All
     *   phones — PATCH users/{id} (businessPhones, mobile) User.ReadWrite.All; refused for accounts with an admin role
     *   fax    — PATCH users/{id} (faxNumber)
     *   custom — PATCH users/{id} (onPremisesExtensionAttributes) cloud-only accounts
     * Not here on purpose: mail and the sign-in name (they change the mailbox addresses), and the hire date, which
     * Microsoft does not let an application write.
     */
    public static function profileFields(): array
    {
        return [
            ['key' => 'givenName',         'label' => 'First name',         'section' => 'name',         'group' => 'main',   'max' => 64, 'bulk' => false],
            ['key' => 'surname',           'label' => 'Last name',          'section' => 'name',         'group' => 'main',   'max' => 64, 'bulk' => false],
            ['key' => 'displayName',       'label' => 'Display name',       'section' => 'name',         'group' => 'main',   'max' => 256, 'required' => true, 'bulk' => false],
            ['key' => 'jobTitle',          'label' => 'Job title',          'section' => 'organization', 'group' => 'main',   'max' => 128, 'bulk' => true],
            ['key' => 'department',        'label' => 'Department',         'section' => 'organization', 'group' => 'main',   'max' => 64, 'bulk' => true],
            ['key' => 'companyName',       'label' => 'Company',            'section' => 'organization', 'group' => 'main',   'max' => 64, 'bulk' => true],
            ['key' => 'officeLocation',    'label' => 'Office',             'section' => 'organization', 'group' => 'main',   'max' => 128, 'bulk' => true],
            ['key' => 'employeeId',        'label' => 'Employee ID',        'section' => 'organization', 'group' => 'main',   'max' => 16, 'bulk' => false],
            ['key' => 'employeeType',      'label' => 'Employee type',      'section' => 'organization', 'group' => 'main',   'max' => 64, 'bulk' => true],
            ['key' => 'businessPhone',     'label' => 'Office phone',       'section' => 'contact',      'group' => 'phones', 'max' => 64, 'bulk' => true],
            ['key' => 'mobilePhone',       'label' => 'Mobile phone',       'section' => 'contact',      'group' => 'phones', 'max' => 64, 'bulk' => false],
            ['key' => 'faxNumber',         'label' => 'Fax number',         'section' => 'contact',      'group' => 'fax',    'max' => 64, 'bulk' => true],
            ['key' => 'streetAddress',     'label' => 'Street address',     'section' => 'contact',      'group' => 'main',   'max' => 1024, 'bulk' => true],
            ['key' => 'city',              'label' => 'City',               'section' => 'contact',      'group' => 'main',   'max' => 128, 'bulk' => true],
            ['key' => 'state',             'label' => 'State or province',  'section' => 'contact',      'group' => 'main',   'max' => 128, 'bulk' => true],
            ['key' => 'postalCode',        'label' => 'ZIP or postal code', 'section' => 'contact',      'group' => 'main',   'max' => 40, 'bulk' => true],
            ['key' => 'country',           'label' => 'Country or region',  'section' => 'contact',      'group' => 'main',   'max' => 128, 'bulk' => true],
            ['key' => 'usageLocation',     'label' => 'Usage location',     'section' => 'settings',     'group' => 'main',   'max' => 2, 'bulk' => true],
            ['key' => 'preferredLanguage', 'label' => 'Preferred language', 'section' => 'settings',     'group' => 'main',   'max' => 20, 'bulk' => true],
        ];
    }

    /**
     * BT39, bulk edit. What one account would be asked to change, given the same instruction for many:
     * $fields = [key => ['mode' => 'set'|'fill'|'clear', 'value' => string]].  set = this value for everyone,
     * fill = this value only where the account has none, clear = remove the value. The result goes to profileChanges().
     */
    public static function bulkInput(array $current, array $fields): array
    {
        $in = [];
        foreach ($fields as $k => $f) {
            $mode = (string)($f['mode'] ?? ''); $val = (string)($f['value'] ?? '');
            if ($mode === 'clear') $in[$k] = '';
            elseif ($mode === 'set') $in[$k] = $val;
            elseif ($mode === 'fill' && trim((string)($current[$k] ?? '')) === '') $in[$k] = $val;
        }
        return $in;
    }

    /** The keys a bulk edit may touch: everything that can sensibly be the same for several people. */
    public static function bulkKeys(array $custom = []): array
    {
        $k = [];
        foreach (self::profileFields() as $f) if (!empty($f['bulk'])) $k[] = $f['key'];
        foreach ($custom as $c) if (!empty($c['active'])) $k[] = 'ext' . (int)$c['slot'];
        return $k;
    }

    /** The names of the request groups, as the result list shows them. */
    public const PROFILE_GROUPS = ['main' => 'Name, organization and address', 'phones' => 'Phone numbers', 'fax' => 'Fax number',
                                   'custom' => 'Custom fields', 'manager' => 'Manager'];

    /**
     * BT41 — the answer to "users/{$asked}" must be that account. Some words are addresses of their own in Microsoft
     * Graph ("users/delta" is a list of changes, not a person): they pass the pattern of an id, come back without an id,
     * and a later write would go to no account at all. The id or the sign-in name of the answer has to be what was asked.
     */
    public static function sameAccount(string $asked, array $u): void
    {
        $id = (string)($u['id'] ?? ''); $upn = (string)($u['userPrincipalName'] ?? '');
        if ($id === '' || (strcasecmp($id, trim($asked)) !== 0 && ($upn === '' || strcasecmp($upn, trim($asked)) !== 0)))
            throw new InvalidArgumentException('That is not an account in Microsoft 365. Open the account from the list and try again.');
    }

    /**
     * BT41 — a value as it will be stored: no control characters, none of the invisible characters that make two texts
     * look the same and compare different (zero-width space, word joiner, byte-order mark, soft hyphen, the marks that
     * turn the reading direction), runs of spaces as one space, no space at either end. Line breaks are kept only when
     * $multiline (the street address). Zero-width joiners are left alone: some scripts need them.
     * Throws when the text is far too long to be a value, or is not valid text, before anything else looks at it.
     */
    public static function tidyValue($v, bool $multiline = false, string $label = 'This value'): string
    {
        if ($v === null) return '';
        if (is_bool($v) || !is_scalar($v)) throw new InvalidArgumentException($label . ': one line of text is expected.');
        $v = (string)$v;
        if (strlen($v) > 8192) throw new InvalidArgumentException($label . ' is far too long.');
        if ($v !== '' && !mb_check_encoding($v, 'UTF-8')) throw new InvalidArgumentException($label . ' is not valid text.');
        $v = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}"], "\n", $v);
        // BT42: a pattern that cannot be applied gives null. That must be an error — as an empty text it would read as
        // "clear this field".
        $rx = function (string $pattern, string $to, string $text) use ($label): string {
            $o = preg_replace($pattern, $to, $text);
            if (!is_string($o)) throw new InvalidArgumentException($label . ' could not be read as text.');
            return $o;
        };
        $v = $rx('/[\x{00AD}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u', '', $v);
        $v = $rx('/[^\P{Cc}\n\t]/u', '', $v);           // control characters, except the two handled next
        $v = $rx('/[\t\x{00A0}]/u', ' ', $v);
        if ($multiline) { $v = $rx('/ *\n */', "\n", $v); $v = $rx('/\n{3,}/', "\n\n", $v); }
        else $v = $rx('/ *\n+ */', ' ', $v);
        $v = $rx('/ {2,}/', ' ', $v);
        return trim($v);
    }

    /** The editable values of one account, keyed like profileFields() plus ext1…ext15 (Microsoft's custom attributes). */
    public static function profileFrom(array $u): array
    {
        $p = [];
        foreach (self::profileFields() as $f) {
            $k = $f['key'];
            $p[$k] = $k === 'businessPhone' ? (string)(($u['businessPhones'][0] ?? '')) : (string)($u[$k] ?? '');
        }
        $ext = is_array($u['onPremisesExtensionAttributes'] ?? null) ? $u['onPremisesExtensionAttributes'] : [];
        for ($i = 1; $i <= 15; $i++) $p['ext' . $i] = (string)($ext['extensionAttribute' . $i] ?? '');
        return $p;
    }

    /** Just the editable values of one account (one request), for the update route. */
    public function getProfile(string $id): array
    {
        $u = $this->g->get("users/{$id}", ['$select' => self::USER_SELECT_FULL]);
        self::sameAccount($id, $u);   // BT41
        return ['id' => (string)($u['id'] ?? ''), 'upn' => (string)($u['userPrincipalName'] ?? ''), 'display_name' => (string)($u['displayName'] ?? ''),
                'user_type' => (string)($u['userType'] ?? ''), 'synced' => ($u['onPremisesSyncEnabled'] ?? null) === true,
                'licensed' => !empty($u['assignedLicenses']), 'profile' => self::profileFrom($u)];
    }

    /** The account's manager, or null when it has none (a 404 from Microsoft). Any other failure is thrown: "could not
     *  read" must never be taken for "has no manager". */
    public function getManager(string $id): ?array
    {
        try { $m = $this->g->get("users/{$id}/manager", ['$select' => 'id,displayName,userPrincipalName']); }
        catch (ItopsGraphException $e) { if ($e->getStatus() === 404) return null; throw $e; }
        if (empty($m['id'])) return null;
        return ['id' => (string)$m['id'], 'name' => (string)($m['displayName'] ?? ''), 'upn' => (string)($m['userPrincipalName'] ?? '')];
    }

    public function removeManager(string $id): void
    {
        $this->g->delete("users/{$id}/manager/\$ref");
    }

    /**
     * Check a set of wanted values against the account as it is now and return only what really changes:
     * ['jobTitle' => ['from' => 'Staff', 'to' => 'Lead'], …]. Throws InvalidArgumentException with a sentence the screen
     * can show. $custom is the list of custom fields the organization defined (slot, label, kind, options, active).
     */
    public static function profileChanges(array $current, array $input, array $custom = [], bool $hasLicenses = false): array
    {
        $defs = [];
        foreach (self::profileFields() as $f) $defs[$f['key']] = $f;
        foreach ($custom as $c) {
            if (empty($c['active'])) continue;
            $defs['ext' . (int)$c['slot']] = ['key' => 'ext' . (int)$c['slot'], 'label' => (string)$c['label'], 'group' => 'custom', 'max' => 1024,
                                              'options' => ($c['kind'] ?? 'text') === 'list' ? array_values((array)($c['options'] ?? [])) : null];
        }
        $out = [];
        foreach ($input as $k => $v) {
            $k = (string)$k;
            if (!isset($defs[$k])) {
                if (preg_match('/^ext([1-9]|1[0-5])$/', $k)) throw new InvalidArgumentException('Custom attribute ' . substr($k, 3) . ' is not set up as a field. Add it under Custom fields first.');
                throw new InvalidArgumentException('"' . mb_substr($k, 0, 40) . '" is not a field that can be changed here.');
            }
            $v = self::tidyValue($v, $k === 'streetAddress', $defs[$k]['label']);   // BT41
            $d = $defs[$k]; $was = (string)($current[$k] ?? '');
            if ($v === $was) continue;
            if (mb_strlen($v) > $d['max']) throw new InvalidArgumentException($d['label'] . ': at most ' . $d['max'] . ' characters (it has ' . mb_strlen($v) . ').');
            if ($v === '' && !empty($d['required'])) throw new InvalidArgumentException($d['label'] . ' cannot be empty. Microsoft does not allow an account without one.');
            if ($k === 'usageLocation') {
                $v = strtoupper($v);
                if ($v !== '' && !preg_match('/^[A-Z]{2}$/', $v)) throw new InvalidArgumentException('Usage location is a two-letter country code, for example US.');
                if ($v === '' && $was !== '') throw new InvalidArgumentException($hasLicenses
                    ? 'This account has licenses, and Microsoft needs a usage location on a licensed account. Change it to another country instead of clearing it.'
                    : 'Microsoft does not allow the usage location to be emptied once it is set. Change it to another country instead.');
                if ($v === $was) continue;
            }
            if ($k === 'preferredLanguage' && $v !== '' && !preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8}){0,2}$/', $v))
                throw new InvalidArgumentException('Preferred language is a code like en-US or es-ES.');
            if (in_array($k, ['businessPhone', 'mobilePhone', 'faxNumber'], true) && $v !== '' && !preg_match('/\d/', $v))
                throw new InvalidArgumentException($d['label'] . ' has no digits in it.');
            if (!empty($d['options']) && $v !== '' && !in_array($v, $d['options'], true))
                throw new InvalidArgumentException($d['label'] . ': "' . mb_substr($v, 0, 60) . '" is not one of the choices of this field.');
            $out[$k] = ['from' => $was, 'to' => $v, 'group' => $d['group'], 'label' => $d['label']];
        }
        return $out;
    }

    /**
     * Write the changes profileChanges() returned, and the manager if $manager is given ('' removes it).
     * One request per group; each reports by itself: [['group','label','fields'=>[labels],'ok','error','hint'], …].
     */
    public function updateProfile(string $id, array $changes, ?string $manager = null, ?callable $onResult = null, ?float $deadline = null, ?string $managerWas = null): array
    {
        // BT43, $managerWas: the id of the manager a removal removes. When other parts were sent before the manager's turn
        // (each can take seconds), the manager is read once more right before the removal; if it is no longer that one,
        // the removal is not sent. Microsoft has no "remove only if it is still X", so this is as close as it gets.
        // BT41: $onResult($r) is called as soon as a part has its answer, so the caller can write the Audit Log entry of
        // that part before the next one is sent. Past $deadline (a microtime) nothing more is sent: the remaining parts
        // come back as ok = false, unsent = true — certainly not changed — instead of running into the host's time limit.
        if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $id)) throw new InvalidArgumentException('That account id is not valid.');
        if ($manager !== null && $manager !== '' && !preg_match('/^[A-Za-z0-9-]{1,64}$/', $manager)) throw new InvalidArgumentException('That manager is not valid.');
        $late = fn() => $deadline !== null && microtime(true) > $deadline;
        $unsent = function (array $r): array {
            $r['ok'] = false; $r['unsent'] = true; $r['uncertain'] = false; $r['status'] = 0;
            $r['error'] = 'Not sent: the time allowed for one save was used up by the parts before it.';
            $r['hint'] = 'Nothing was changed for this part. Save again: only what is still different is sent.';
            return $r;
        };
        $by = [];
        foreach ($changes as $k => $c) $by[$c['group']][$k] = $c;
        $results = [];
        foreach (['main', 'phones', 'fax', 'custom'] as $grp) {
            if (empty($by[$grp])) continue;
            $body = [];
            foreach ($by[$grp] as $k => $c) {
                $val = $c['to'] === '' ? null : $c['to'];
                if ($k === 'businessPhone') $body['businessPhones'] = $val === null ? [] : [$val];   // Microsoft accepts one number only
                elseif ($grp === 'custom') $body['onPremisesExtensionAttributes']['extensionAttribute' . substr($k, 3)] = $val;
                else $body[$k] = $val;
            }
            $r = ['group' => $grp, 'label' => self::PROFILE_GROUPS[$grp], 'keys' => array_keys($by[$grp]),
                  'fields' => array_values(array_map(fn($c) => $c['label'], $by[$grp])), 'ok' => true, 'error' => null, 'hint' => null];
            if ($late()) $r = $unsent($r);
            else {
                try { $this->g->patch("users/{$id}", $body); }
                catch (Throwable $e) { $r['ok'] = false; $r['error'] = $e->getMessage(); $r['status'] = $e instanceof ItopsGraphException ? $e->getStatus() : 0; $r['uncertain'] = self::outcomeUnknown($e); $r['hint'] = self::profileHint($grp, $e); }
            }
            if ($onResult) $onResult($r);
            $results[] = $r;
        }
        if ($manager !== null) {
            $r = ['group' => 'manager', 'label' => self::PROFILE_GROUPS['manager'], 'keys' => ['manager'], 'fields' => ['Manager'], 'ok' => true, 'error' => null, 'hint' => null];
            if ($late()) $r = $unsent($r);
            else {
                $gone = ($manager === '' && $managerWas !== null && $managerWas !== '' && $results) ? $this->managerNoLongerIs($id, $managerWas) : null;
                if ($gone !== null) {
                    $r['ok'] = false; $r['error'] = $gone; $r['status'] = 409; $r['uncertain'] = false;
                    $r['hint'] = 'Nothing was sent to Microsoft for the manager. Open the account again to see the manager it has now.';
                }
                elseif ($late()) $r = $unsent($r);   // the read above took its time: the removal is not started past the deadline
                else {
                    try { if ($manager === '') $this->removeManager($id); else $this->setManager($id, $manager); }
                    catch (Throwable $e) { $r['ok'] = false; $r['error'] = $e->getMessage(); $r['status'] = $e instanceof ItopsGraphException ? $e->getStatus() : 0; $r['uncertain'] = self::outcomeUnknown($e); $r['hint'] = self::profileHint('manager', $e); }
                }
            }
            if ($onResult) $onResult($r);
            $results[] = $r;
        }
        return $results;
    }

    /** BT43: null when the account's manager is still $was; otherwise why a removal of $was must not be sent. */
    private function managerNoLongerIs(string $id, string $was): ?string
    {
        try { $now = $this->getManager($id); }
        catch (Throwable $e) { return 'Not removed: the manager could not be read again right before the removal.'; }
        if ($now !== null && strcasecmp((string)($now['id'] ?? ''), $was) === 0) return null;
        return $now === null ? 'Not removed: the account no longer has a manager.' : 'Not removed: the account got another manager while this save was running.';
    }

    /** True when a failed request may still have been carried out: no answer came back (timeout, network) or Microsoft
     *  answered with a server error. A clear 4xx means it was not. */
    public static function outcomeUnknown(Throwable $e): bool
    {
        if (!($e instanceof ItopsGraphException)) return true;
        $st = $e->getStatus();
        return $st === 0 || $st >= 500;
    }

    /** Why Microsoft most likely refused, in words an administrator can act on. */
    public static function profileHint(string $group, Throwable $e): ?string
    {
        if (self::outcomeUnknown($e)) return 'No clear answer came back from Microsoft, so this part may or may not have been saved. Open the account again to see the values it has now.';
        $msg = $e->getMessage();
        $code = $e instanceof ItopsGraphException ? $e->getGraphCode() : '';
        $status = $e instanceof ItopsGraphException ? $e->getStatus() : 0;
        if (stripos($msg, 'on-premises mastered') !== false || stripos($msg, 'Directory Sync') !== false)
            return 'This account, or this value, is kept in an on-premises directory and copied to Microsoft 365. Change it there; the copy follows.';
        if ($status === 403 || stripos($code, 'Authorization_RequestDenied') !== false || stripos($msg, 'Insufficient privileges') !== false) {
            if ($group === 'phones')
                return 'Microsoft guards phone numbers more than other fields. The app needs User.ReadWrite.All (or the narrower User-Phone.ReadWrite.All) with admin consent, '
                     . 'and even then Microsoft refuses an application for an account that holds an administrator role. For such an account, change the number in the Microsoft 365 admin center.';
            return 'Microsoft refused the change for this account. The usual reasons: the account holds an administrator role (Microsoft protects those from an application), '
                 . 'or the app is missing User.ReadWrite.All with admin consent. Run Diagnose on the Microsoft 365 connection to see which permissions the app really has.';
        }
        if ($group === 'custom')
            return 'Custom attributes can be written for accounts that live only in the cloud. If the value is refused, check that the text has no unusual characters and is not longer than 1024.';
        if ($status === 400) return 'Microsoft did not accept one of the values. Its own message above says which one.';
        return null;
    }

    /**
     * How the 15 custom attributes are used across the directory: for each slot, how many accounts have a value and
     * up to three examples. It is how an administrator sees that a slot already carries something before naming it.
     */
    public function customAttributeUsage(): array
    {
        $rows = $this->g->getAll('users', ['$select' => 'id,onPremisesExtensionAttributes', '$top' => 999]);
        $out = [];
        for ($i = 1; $i <= 15; $i++) $out[$i] = ['slot' => $i, 'count' => 0, 'examples' => []];
        foreach ($rows as $u) {
            $ext = is_array($u['onPremisesExtensionAttributes'] ?? null) ? $u['onPremisesExtensionAttributes'] : [];
            for ($i = 1; $i <= 15; $i++) {
                $v = trim((string)($ext['extensionAttribute' . $i] ?? ''));
                if ($v === '') continue;
                $out[$i]['count']++;
                if (count($out[$i]['examples']) < 3 && !in_array($v, $out[$i]['examples'], true)) $out[$i]['examples'][] = mb_substr($v, 0, 60);
            }
        }
        return ['accounts' => count($rows), 'slots' => array_values($out)];
    }

    /** Strip every assigned license. Returns the number removed. */
    public function removeAllLicenses(string $id): int
    {
        $id = self::guid($id, 'account id');
        // Review M365A-16: a license given by a group cannot be removed from the person (it goes when they leave the
        // group), so only the directly assigned ones are removed; asking for all of them used to fail the whole call.
        $skus = [];
        try {
            $u = $this->g->get("users/{$id}", ['$select' => 'licenseAssignmentStates']);
            if (!array_key_exists('licenseAssignmentStates', $u)) throw new RuntimeException('no assignment states');
            foreach ($u['licenseAssignmentStates'] ?? [] as $st) if (!empty($st['skuId']) && empty($st['assignedByGroup'])) $skus[(string)$st['skuId']] = 1;
            $skus = array_keys($skus);
        } catch (Throwable $e) {
            $ld = $this->g->get("users/{$id}/licenseDetails", ['$select' => 'skuId']);
            foreach ($ld['value'] ?? [] as $l) { if (!empty($l['skuId'])) $skus[] = (string)$l['skuId']; }
        }
        if (!$skus) return 0;
        $this->assignLicense($id, [], $skus);
        return count($skus);
    }

    /** List groups for the membership picker. */
    public function listGroups(string $q = '', int $top = 100): array
    {
        $sel = 'id,displayName,description,groupTypes,securityEnabled,mailEnabled,onPremisesSyncEnabled';
        $q = trim($q);
        // When searching, ask Microsoft to filter server-side (startswith on the
        // display name) instead of pulling the whole directory and filtering in
        // PHP — the fetch-all path is slow and throttles on large tenants, which
        // made the group search look like it "wasn't searching". Empty query
        // still lists (bounded) for pickers that want everything.
        if ($q !== '') {
            $safe = str_replace("'", "''", $q);
            try {
                $res = $this->g->get('groups', ['$select' => $sel, '$top' => max(1, min(100, $top)),
                    '$filter' => "startswith(displayName,'{$safe}')", '$orderby' => 'displayName']);
            } catch (Throwable $e) {
                // Some tenants reject $orderby with $filter; retry without it.
                $res = $this->g->get('groups', ['$select' => $sel, '$top' => max(1, min(100, $top)),
                    '$filter' => "startswith(displayName,'{$safe}')"]);
            }
            $items = $res['value'] ?? [];
        } else {
            $res = $this->g->getAll('groups', ['$select' => $sel, '$top' => 999], 3);
            $items = isset($res['value']) ? $res['value'] : $res;   // ITOps fix: getAll() returns the rows themselves
        }
        $out = [];
        foreach ($items as $g) {
            $name = (string)($g['displayName'] ?? '');
            $types = $g['groupTypes'] ?? [];
            $dynamic = in_array('DynamicMembership', $types, true);
            $unified = in_array('Unified', $types, true);
            $onPrem = (bool)($g['onPremisesSyncEnabled'] ?? false);
            $out[] = [
                'id'      => (string)($g['id'] ?? ''),
                'name'    => $name,
                'type'    => $unified ? 'm365' : (($g['securityEnabled'] ?? false) ? 'security' : 'distribution'),
                'addable' => !$dynamic && !$onPrem,
                'note'    => $dynamic ? 'dynamic' : ($onPrem ? 'on-prem' : ''),
            ];
        }
        usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return count($out) > $top ? array_slice($out, 0, $top) : $out;
    }

    /** Add a user to a group. */
    public function addToGroup(string $userId, string $groupId): void
    {
        $this->g->post("groups/{$groupId}/members/\$ref", ['@odata.id' => 'https://graph.microsoft.com/v1.0/directoryObjects/' . $userId]);
    }

    /** Remove a user from a group. */
    public function removeFromGroup(string $userId, string $groupId): void
    {
        $this->g->delete("groups/{$groupId}/members/{$userId}/\$ref");
    }

    /** All assignable directory role definitions (built-in + custom), flagged for risk. */
    public function listRoleDefinitions(): array
    {
        $res = $this->g->getAll('roleManagement/directory/roleDefinitions', ['$select' => 'id,displayName,description,isEnabled'], 5);
        $out = [];
        foreach ((isset($res['value']) ? $res['value'] : $res) as $r) {   // ITOps fix: getAll() returns the rows themselves
            if (array_key_exists('isEnabled', $r) && $r['isEnabled'] === false) continue;
            $name = (string)($r['displayName'] ?? '');
            $out[] = [
                'id'          => (string)($r['id'] ?? ''),
                'name'        => $name,
                'description' => (string)($r['description'] ?? ''),
                'privileged'  => in_array($name, self::HIGH_RISK_ROLES, true) || (function_exists('itops_m365_role_is_high') && itops_m365_role_is_high((string)($r['id'] ?? ''), $name)),   // BT88: the same list as the guard
            ];
        }
        usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /** A user's current directory role assignments. */
    public function userRoles(string $id): array
    {
        $res = $this->g->get('roleManagement/directory/roleAssignments', [
            '$filter' => "principalId eq '" . self::guid($id, 'user id') . "'",   // BT88
            '$expand' => 'roleDefinition',
        ]);
        $out = [];
        foreach ($res['value'] ?? [] as $a) {
            $rd = $a['roleDefinition'] ?? [];
            $name = (string)($rd['displayName'] ?? '');
            $out[] = [
                'assignment_id' => (string)($a['id'] ?? ''),
                'role_id'       => (string)($a['roleDefinitionId'] ?? ($rd['id'] ?? '')),
                'name'          => $name,
                'privileged'    => in_array($name, self::HIGH_RISK_ROLES, true) || (function_exists('itops_m365_role_is_high') && itops_m365_role_is_high((string)($a['roleDefinitionId'] ?? ''), $name)),   // BT88
                'scope'         => (string)($a['directoryScopeId'] ?? '/'),
            ];
        }
        usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /** Grant a directory role at tenant scope. */
    public function assignRole(string $userId, string $roleDefinitionId): array
    {
        return $this->g->post('roleManagement/directory/roleAssignments', [
            'principalId'      => $userId,
            'roleDefinitionId' => $roleDefinitionId,
            'directoryScopeId' => '/',
        ]);
    }

    /** Remove a directory role assignment by its assignment id. */
    public function removeRoleAssignment(string $assignmentId): void
    {
        $this->g->delete('roleManagement/directory/roleAssignments/' . $assignmentId);
    }

    /** Recent sign-ins for a user (needs Entra ID P1; degrades to empty). */
    public function userSignIns(string $id, int $top = 15): array
    {
        $res = $this->g->get('auditLogs/signIns', [
            '$filter' => "userId eq '" . self::guid($id, 'user id') . "'",   // BT88
            '$top'    => max(1, min(50, $top)),
        ]);
        $out = [];
        foreach ($res['value'] ?? [] as $sx) {
            $st = $sx['status'] ?? [];
            $loc = $sx['location'] ?? [];
            $locStr = trim(implode(', ', array_filter([(string)($loc['city'] ?? ''), (string)($loc['countryOrRegion'] ?? '')])), ', ');
            $out[] = [
                'time'     => (string)($sx['createdDateTime'] ?? ''),
                'app'      => (string)($sx['appDisplayName'] ?? ''),
                'ip'       => (string)($sx['ipAddress'] ?? ''),
                'location' => $locStr,
                'failed'   => (int)($st['errorCode'] ?? 0) !== 0,
                'reason'   => (string)($st['failureReason'] ?? ''),
                'client'   => (string)($sx['clientAppUsed'] ?? ''),
            ];
        }
        usort($out, fn($a, $b) => strcmp($b['time'], $a['time']));
        return $out;
    }

    /**
     * BT27: every device object in Entra (joined, hybrid and registered), with its owners.
     * Requires Device.Read.All. Read-only.
     */
    public function entraDevices(?callable $onPage = null): array
    {
        $q = ['$select' => 'id,deviceId,displayName,operatingSystem,operatingSystemVersion,trustType,isManaged,isCompliant,accountEnabled,'
                         . 'approximateLastSignInDateTime,deviceOwnership,manufacturer,model,registrationDateTime',
              '$top' => 100];
        try { $rows = $this->g->getAll('devices', $q + ['$expand' => 'registeredOwners'], 100, $onPage); }
        catch (Throwable $e) {
            // Some tenants refuse $expand together with $select: read the devices without their owners.
            $m = $e->getMessage();
            if (stripos($m, 'authoriz') !== false || stripos($m, 'privilege') !== false || str_contains($m, '403')) throw $e;
            $rows = $this->g->getAll('devices', $q, 100, $onPage);
        }
        $out = [];
        foreach ($rows as $d) {
            $owners = [];
            foreach (($d['registeredOwners'] ?? []) as $o) {
                $upn = (string)($o['userPrincipalName'] ?? '');
                if ($upn !== '') $owners[] = ['upn' => $upn, 'name' => (string)($o['displayName'] ?? '')];
            }
            $out[] = [
                'id'         => (string)($d['id'] ?? ''),
                'device_id'  => strtolower((string)($d['deviceId'] ?? '')),
                'name'       => (string)($d['displayName'] ?? ''),
                'os'         => (string)($d['operatingSystem'] ?? ''),
                'os_version' => (string)($d['operatingSystemVersion'] ?? ''),
                'trust_type' => (string)($d['trustType'] ?? ''),
                'ownership'  => strtolower((string)($d['deviceOwnership'] ?? '')),
                'managed'    => (bool)($d['isManaged'] ?? false),
                'compliant'  => array_key_exists('isCompliant', $d) && $d['isCompliant'] !== null ? (bool)$d['isCompliant'] : null,
                'enabled'    => (bool)($d['accountEnabled'] ?? true),
                'model'      => trim(((string)($d['manufacturer'] ?? '')) . ' ' . ((string)($d['model'] ?? ''))),
                'last_seen'  => $d['approximateLastSignInDateTime'] ?? null,
                'registered' => $d['registrationDateTime'] ?? null,
                'owners'     => $owners,
            ];
        }
        return $out;
    }

    // ───────────────────────── BT31: Entra management (roadmap phase 3a) ─────────────────────────

    /** Directory ids are GUIDs. Anything that could change the meaning of a Graph path or an OData filter (quotes, slashes, brackets) is refused. */
    private static function guid(string $v, string $what = 'id'): string
    {
        $v = trim($v);
        if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $v)) throw new InvalidArgumentException("That $what is not valid.");
        return $v;
    }

    /** One Entra device, read live by its deviceId (not the object id). Null when Entra no longer has it. Device.Read.All. */
    public function entraDevice(string $deviceId): ?array
    {
        $deviceId = self::guid($deviceId, 'device id');
        try {
            $d = $this->g->get("devices(deviceId='{$deviceId}')", ['$select' => 'id,deviceId,displayName,operatingSystem,operatingSystemVersion,trustType,isManaged,'
                . 'isCompliant,accountEnabled,approximateLastSignInDateTime,registrationDateTime,physicalIds,profileType']);
        } catch (ItopsGraphException $e) {
            if ($e->getStatus() === 404) return null;
            throw $e;
        }
        $autopilot = false;
        foreach (($d['physicalIds'] ?? []) as $pid) if (stripos((string)$pid, '[ZTDId]') === 0) $autopilot = true;
        return [
            'id' => (string)($d['id'] ?? ''), 'device_id' => strtolower((string)($d['deviceId'] ?? $deviceId)), 'name' => (string)($d['displayName'] ?? ''),
            'os' => (string)($d['operatingSystem'] ?? ''), 'os_version' => (string)($d['operatingSystemVersion'] ?? ''),
            'trust_type' => (string)($d['trustType'] ?? ''), 'managed' => (bool)($d['isManaged'] ?? false),
            'enabled' => (bool)($d['accountEnabled'] ?? true), 'last_seen' => $d['approximateLastSignInDateTime'] ?? null,
            'registered' => $d['registrationDateTime'] ?? null, 'autopilot' => $autopilot,
        ];
    }

    /** Enable or disable an Entra device. Device.ReadWrite.All; Microsoft only lets an application do this for Windows devices. */
    public function setDeviceEnabled(string $deviceId, bool $enabled): void
    {
        $deviceId = self::guid($deviceId, 'device id');
        $this->g->patch("devices(deviceId='{$deviceId}')", ['accountEnabled' => $enabled]);
    }

    /** Delete an Entra device object. Device.ReadWrite.All. Its BitLocker recovery keys and LAPS password go with it. */
    public function deleteDevice(string $deviceId): void
    {
        $deviceId = self::guid($deviceId, 'device id');
        $this->g->delete("devices(deviceId='{$deviceId}')");
    }

    /** BitLocker recovery keys stored for a device — ids only, never the key. BitlockerKey.ReadBasic.All or BitlockerKey.Read.All. */
    public function bitlockerKeys(string $deviceId): array
    {
        $deviceId = self::guid($deviceId, 'device id');
        $rows = $this->g->getAll('informationProtection/bitlocker/recoveryKeys', ['$filter' => "deviceId eq '{$deviceId}'"], 3);
        $vol = ['1' => 'Operating system drive', '2' => 'Fixed data drive', '3' => 'Removable drive',
                'operatingsystemvolume' => 'Operating system drive', 'fixeddatavolume' => 'Fixed data drive', 'removabledatavolume' => 'Removable drive'];
        $out = [];
        foreach ($rows as $k) {
            $v = strtolower((string)($k['volumeType'] ?? ''));
            $out[] = ['id' => (string)($k['id'] ?? ''), 'created' => $k['createdDateTime'] ?? null, 'volume' => $vol[$v] ?? ($v !== '' ? 'Drive type ' . $v : 'Drive')];
        }
        usort($out, fn($a, $b) => strcmp((string)$b['created'], (string)$a['created']));
        return $out;
    }

    /** One BitLocker recovery key WITH the key. BitlockerKey.Read.All. Microsoft writes its own audit entry for this read. */
    public function bitlockerKey(string $keyId): array
    {
        $keyId = self::guid($keyId, 'key id');
        $k = $this->g->get("informationProtection/bitlocker/recoveryKeys/{$keyId}", ['$select' => 'key']);
        return ['id' => (string)($k['id'] ?? $keyId), 'device_id' => strtolower((string)($k['deviceId'] ?? '')), 'key' => (string)($k['key'] ?? ''), 'created' => $k['createdDateTime'] ?? null];
    }

    /** The local administrator passwords Windows LAPS backed up for a device, newest first. DeviceLocalCredential.Read.All. Null when none is stored. */
    public function lapsCredentials(string $deviceId): ?array
    {
        $deviceId = self::guid($deviceId, 'device id');
        try { $r = $this->g->get("directory/deviceLocalCredentials/{$deviceId}", ['$select' => 'credentials']); }
        catch (ItopsGraphException $e) { if ($e->getStatus() === 404) return null; throw $e; }
        if (isset($r['value']) && is_array($r['value']) && !isset($r['credentials'])) $r = $r['value'];
        $out = [];
        foreach (($r['credentials'] ?? []) as $c) {
            $pw = base64_decode((string)($c['passwordBase64'] ?? ''), true);
            $out[] = ['account' => (string)($c['accountName'] ?? ''), 'password' => $pw === false ? '' : $pw, 'backed_up' => $c['backupDateTime'] ?? null];
        }
        usort($out, fn($a, $b) => strcmp((string)$b['backed_up'], (string)$a['backed_up']));
        return ['device_name' => (string)($r['deviceName'] ?? ''), 'credentials' => $out];
    }

    /** How a group can be managed, from the properties Microsoft returns. */
    private static function shapeGroup(array $g): array
    {
        $types   = $g['groupTypes'] ?? [];
        $unified = in_array('Unified', $types, true);
        $dynamic = in_array('DynamicMembership', $types, true);
        $synced  = (bool)($g['onPremisesSyncEnabled'] ?? false);
        $sec     = (bool)($g['securityEnabled'] ?? false);
        $mail    = (bool)($g['mailEnabled'] ?? false);
        $roleGrp = (bool)($g['isAssignableToRole'] ?? false);
        $type = $unified ? 'm365' : ($sec && $mail ? 'mail_security' : ($sec ? 'security' : 'distribution'));
        // Why the members cannot be changed through Microsoft Graph (empty = they can).
        $locked = $dynamic ? 'Membership is set by a rule (dynamic group).'
            : ($synced ? 'Synced from your on-premises Active Directory: change it there.'
            : (!$unified && $mail ? 'Distribution lists and mail-enabled security groups are managed in the Exchange admin center.'
            : ($roleGrp ? 'This group can hold Entra admin roles: manage it in the Entra admin center.' : '')));
        // Owners can be changed for a dynamic group too (only its members follow the rule).
        $ownersLocked = $synced ? 'Synced from your on-premises Active Directory: its owners are set there.'
            : (!$unified && $mail ? 'Distribution lists and mail-enabled security groups are managed in the Exchange admin center.'
            : ($roleGrp ? 'This group can hold Entra admin roles: manage it in the Entra admin center.' : ''));
        $owners = null;
        if (array_key_exists('owners', $g)) {
            $owners = [];
            foreach (($g['owners'] ?? []) as $o) $owners[] = ['id' => (string)($o['id'] ?? ''), 'name' => (string)($o['displayName'] ?? ''), 'upn' => (string)($o['userPrincipalName'] ?? '')];
        }
        return [
            'id' => (string)($g['id'] ?? ''), 'name' => (string)($g['displayName'] ?? ''), 'description' => (string)($g['description'] ?? ''),
            'mail' => (string)($g['mail'] ?? ''), 'type' => $type, 'team' => in_array('Team', $g['resourceProvisioningOptions'] ?? [], true),
            'dynamic' => $dynamic, 'rule' => $dynamic ? (string)($g['membershipRule'] ?? '') : '', 'synced' => $synced, 'role_assignable' => $roleGrp,
            'visibility' => (string)($g['visibility'] ?? ''), 'created' => $g['createdDateTime'] ?? null,
            'manageable' => $locked === '', 'locked' => $locked, 'owners' => $owners,
            'owners_manageable' => $ownersLocked === '', 'owners_locked' => $ownersLocked,
        ];
    }

    private const GROUP_SELECT = 'id,displayName,description,mail,mailEnabled,securityEnabled,groupTypes,membershipRule,onPremisesSyncEnabled,'
                               . 'createdDateTime,visibility,isAssignableToRole,resourceProvisioningOptions';

    /**
     * Every group with its type, owners and member count. Group.Read.All (or Directory.Read.All).
     * Member counts come from /members/$count in batches of 20; when that is refused the count is null, never a guess.
     */
    public function groupsOverview(): array
    {
        $q = ['$select' => self::GROUP_SELECT, '$top' => 100];
        try { $rows = $this->g->getAll('groups', $q + ['$expand' => 'owners($select=id,displayName,userPrincipalName)'], 40); }
        catch (Throwable $e) {
            $m = $e->getMessage();
            if (stripos($m, 'authoriz') !== false || stripos($m, 'privilege') !== false || str_contains($m, '403')) throw $e;
            $rows = $this->g->getAll('groups', ['$select' => self::GROUP_SELECT, '$top' => 999], 10);   // owners unknown
        }
        $out = [];
        foreach ($rows as $g) { $x = self::shapeGroup($g); if ($x['id'] !== '') $out[$x['id']] = $x + ['members' => null]; }
        $reqs = [];
        foreach (array_keys($out) as $gid) $reqs[$gid] = ['url' => "groups/{$gid}/members/\$count", 'headers' => ['ConsistencyLevel' => 'eventual']];
        $countsOk = true;
        try {
            foreach ($this->g->batch($reqs) as $gid => $r) {
                if ($r['status'] !== 200 || !isset($out[$gid])) continue;
                $b = $r['body'];
                if (is_string($b) && !is_numeric(trim($b))) { $dec = base64_decode($b, true); if ($dec !== false) $b = trim($dec); }   // text/plain answers may arrive base64-encoded
                if (is_numeric(is_string($b) ? trim($b) : $b)) $out[$gid]['members'] = (int)$b;
            }
        } catch (Throwable $e) { $countsOk = false; }
        $out = array_values($out);
        usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return ['groups' => $out, 'counts_ok' => $countsOk];
    }

    /** One group with its owners and direct members (people, devices, nested groups). */
    public function groupDetail(string $id): array
    {
        $id = self::guid($id, 'group id');
        $g = self::shapeGroup($this->g->get("groups/{$id}", ['$select' => self::GROUP_SELECT]));
        $kind = function (array $o): string {
            $t = strtolower((string)($o['@odata.type'] ?? ''));
            return str_contains($t, 'user') ? 'user' : (str_contains($t, 'device') ? 'device' : (str_contains($t, 'group') ? 'group' : (str_contains($t, 'serviceprincipal') ? 'app' : 'other')));
        };
        $shape = function (array $o) use ($kind): array {
            $k = $kind($o);
            return ['id' => (string)($o['id'] ?? ''), 'kind' => $k, 'name' => (string)($o['displayName'] ?? ''),
                    'upn' => (string)($o['userPrincipalName'] ?? ($o['mail'] ?? '')),
                    'enabled' => array_key_exists('accountEnabled', $o) && $o['accountEnabled'] !== null ? (bool)$o['accountEnabled'] : null,
                    'guest' => strcasecmp((string)($o['userType'] ?? ''), 'Guest') === 0];
        };
        $members = array_map($shape, $this->g->getAll("groups/{$id}/members", ['$select' => 'id,displayName,userPrincipalName,mail,accountEnabled,userType', '$top' => 999], 5));
        $owners  = array_map($shape, $this->g->getAll("groups/{$id}/owners", ['$select' => 'id,displayName,userPrincipalName,mail,accountEnabled,userType', '$top' => 999], 2));
        $ord = ['user' => 0, 'device' => 1, 'group' => 2, 'app' => 3, 'other' => 4];
        usort($members, fn($a, $b) => ($ord[$a['kind']] <=> $ord[$b['kind']]) ?: strcasecmp($a['name'], $b['name']));
        $g['owners'] = array_map(fn($o) => ['id' => $o['id'], 'name' => $o['name'], 'upn' => $o['upn'], 'enabled' => $o['enabled']], $owners);
        return ['group' => $g, 'members' => $members, 'capped' => count($members) >= 4995];
    }

    // ───────────────────────── BT32: Entra management (roadmap phase 3b) ─────────────────────────

    /** "ada_gmail.com#EXT#@tenant.onmicrosoft.com" → "ada@gmail.com" (the address a guest was invited with, when Entra has no mail). */
    private static function guestEmail(array $u): string
    {
        $mail = trim((string)($u['mail'] ?? ''));
        if ($mail !== '') return strtolower($mail);
        $upn = (string)($u['userPrincipalName'] ?? '');
        $p = stripos($upn, '#EXT#');
        if ($p === false) return strtolower($upn);
        $local = substr($upn, 0, $p); $us = strrpos($local, '_');
        return strtolower($us === false ? $local : substr($local, 0, $us) . '@' . substr($local, $us + 1));
    }

    /**
     * Every guest (external) account. User.Read.All. Last sign-in needs AuditLog.Read.All and Entra ID P1: when Microsoft
     * refuses it the guests are still listed and has_sign_in is false — a missing date is then "unknown", never "never".
     */
    public function guests(): array
    {
        $sel = 'id,displayName,mail,userPrincipalName,accountEnabled,createdDateTime,externalUserState,externalUserStateChangeDateTime,companyName';
        $q = ['$filter' => "userType eq 'Guest'", '$top' => 999];
        $hasSignIn = true;
        try { $rows = $this->g->getAll('users', $q + ['$select' => $sel . ',signInActivity'], 20); }
        catch (Throwable $e) { $hasSignIn = false; $rows = $this->g->getAll('users', $q + ['$select' => $sel], 20); }
        $out = [];
        foreach ($rows as $u) {
            $sia = $u['signInActivity'] ?? null;
            $email = self::guestEmail($u);
            $out[] = [
                'id' => (string)($u['id'] ?? ''), 'name' => (string)($u['displayName'] ?? ''), 'email' => $email,
                'domain' => str_contains($email, '@') ? substr($email, strrpos($email, '@') + 1) : '',
                'upn' => (string)($u['userPrincipalName'] ?? ''), 'company' => (string)($u['companyName'] ?? ''),
                'enabled' => (bool)($u['accountEnabled'] ?? false), 'created' => $u['createdDateTime'] ?? null,
                'state' => (string)($u['externalUserState'] ?? ''), 'state_changed' => $u['externalUserStateChangeDateTime'] ?? null,
                'last_sign_in' => $hasSignIn ? (max((string)($sia['lastSignInDateTime'] ?? ''), (string)($sia['lastNonInteractiveSignInDateTime'] ?? ''), (string)($sia['lastSuccessfulSignInDateTime'] ?? '')) ?: null) : null,
            ];
        }
        usort($out, fn($a, $b) => strcasecmp($a['name'] ?: $a['email'], $b['name'] ?: $b['email']));
        return ['guests' => $out, 'has_sign_in' => $hasSignIn];
    }

    /** One account, just enough to know whether it is a guest. */
    public function userKind(string $id): array
    {
        $id = self::guid($id, 'user id');
        $u = $this->g->get("users/{$id}", ['$select' => 'id,displayName,mail,userPrincipalName,userType,accountEnabled,externalUserState,createdDateTime']);
        return ['id' => (string)($u['id'] ?? $id), 'name' => (string)($u['displayName'] ?? ''), 'email' => self::guestEmail($u), 'guest' => strcasecmp((string)($u['userType'] ?? ''), 'Guest') === 0, 'enabled' => (bool)($u['accountEnabled'] ?? false),
                'state' => (string)($u['externalUserState'] ?? ''), 'created' => $u['createdDateTime'] ?? null];
    }

    /** Delete an account. Microsoft keeps it under "Deleted users" for 30 days. User.ReadWrite.All. The route only calls this for guests. */
    public function deleteUser(string $id): void
    {
        $id = self::guid($id, 'user id');
        $this->g->delete("users/{$id}");
    }

    // ───────────────────────── BT34: Entra management follow-ups ─────────────────────────

    /**
     * The application permissions the current Graph token carries (its "roles" claim), or null when they cannot be read.
     * Used to say BEFORE someone clicks whether an optional permission (Group.ReadWrite.All) has been granted.
     */
    public static function tokenRoles(): ?array
    {
        try {
            $cl = itops_graph_jwt_claims(itops_graph_token());
            $r = $cl['roles'] ?? null;
            return is_array($r) && $r ? array_values(array_map('strval', $r)) : null;
        } catch (Throwable $e) { return null; }
    }

    /** true / false when the token is known to carry (or lack) one of these permissions; null when the token cannot be inspected. */
    public static function tokenHas(array $anyOf): ?bool
    {
        $roles = self::tokenRoles();
        if ($roles === null) return null;
        foreach ($anyOf as $p) foreach ($roles as $r) if (strcasecmp($r, $p) === 0) return true;
        return false;
    }

    /**
     * Diagnose: the permissions that CHANGE something. They cannot be live-tested without changing something, so this
     * reads them from the token instead. $roles is the token's "roles" claim. Pure: no Microsoft call.
     */
    public static function writePermissions(array $roles): array
    {
        $has = function (array $any) use ($roles): bool { foreach ($any as $p) foreach ($roles as $r) if (strcasecmp((string)$r, $p) === 0) return true; return false; };
        $rows = [
            ['Block and restore sign-in, reset passwords, remove guests', ['User.ReadWrite.All', 'Directory.ReadWrite.All'], false, 'Accounts, Guests (resetting a password also needs the app to hold the Entra role User Administrator, or Privileged Authentication Administrator for admin accounts)'],
            ['Edit contact information, manager and custom fields',       ['User.ReadWrite.All', 'Directory.ReadWrite.All'], false, 'Accounts'],
            ['Edit phone numbers',                                        ['User.ReadWrite.All', 'User-Phone.ReadWrite.All', 'Directory.ReadWrite.All'], false, 'Accounts (Microsoft still refuses for accounts with an admin role)'],
            ['Resend guest invitations',                                  ['User.Invite.All', 'User.ReadWrite.All', 'Directory.ReadWrite.All'], false, 'Guests'],
            ['Add and remove group members',                              ['GroupMember.ReadWrite.All', 'Group.ReadWrite.All', 'Directory.ReadWrite.All'], false, 'Groups, onboarding and offboarding'],
            ['Change group owners',                                       self::GROUP_OWNER_PERMISSIONS, true, 'Groups'],
            ['Create security groups',                                    self::GROUP_CREATE_PERMISSIONS, true, 'Groups'],
            ['Disable, enable and remove Entra devices',                  ['Device.ReadWrite.All'], true, 'Device inventory'],
            // BT124 (code review M365A-8): every button that changes something has its line
            ['Reset multifactor sign-in, Temporary Access Pass',          ['UserAuthenticationMethod.ReadWrite.All'], true, 'Accounts'],
            ['Assign and remove admin roles, make them eligible',         ['RoleManagement.ReadWrite.Directory'], true, 'Accounts, Security → Admin roles'],
            ['Assign and remove licenses',                                ['LicenseAssignment.ReadWrite.All', 'User.ReadWrite.All', 'Directory.ReadWrite.All'], false, 'Accounts, Licenses → Reclaim'],
            ['Sign out of every session',                                 ['User.RevokeSessions.All', 'User.ReadWrite.All', 'Directory.ReadWrite.All'], false, 'Accounts, offboarding'],
            ['Conditional Access changes and security defaults',          ['Policy.ReadWrite.ConditionalAccess'], true, 'Security → Conditional Access'],
            ['Handle Defender incidents',                                 ['SecurityIncident.ReadWrite.All'], true, 'Security → Alerts & actions'],
            ['Resolve Defender alerts',                                   ['SecurityAlert.ReadWrite.All'], true, 'Security → Alerts & actions'],
            ['Intune policies, update rings, device security',            ['DeviceManagementConfiguration.ReadWrite.All'], true, 'Devices'],
            ['Restart, scan, retire and wipe devices',                    ['DeviceManagementManagedDevices.PrivilegedOperations.All'], true, 'Devices'],
        ];
        $out = [];
        foreach ($rows as [$label, $any, $optional, $where])
            $out[] = ['label' => $label, 'permission' => implode(' or ', $any), 'ok' => $has($any), 'optional' => $optional, 'where' => $where];
        return $out;
    }

    /** Permissions that let the app change group owners, and create groups. */
    public const GROUP_OWNER_PERMISSIONS  = ['Group.ReadWrite.All', 'Directory.ReadWrite.All'];
    public const GROUP_CREATE_PERMISSIONS = ['Group.ReadWrite.All', 'Group.Create', 'Directory.ReadWrite.All'];

    /**
     * Send the invitation email again to a guest who has not accepted yet. User.Invite.All, or the User.ReadWrite.All the
     * app already holds. Microsoft matches the address to the existing guest; the returned id says which account it used.
     */
    public function resendInvitation(string $email, string $redirectUrl): array
    {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('This guest has no usable email address.');
        $r = $this->g->post('invitations', ['invitedUserEmailAddress' => $email, 'inviteRedirectUrl' => $redirectUrl, 'sendInvitationMessage' => true]);
        return ['id' => (string)($r['invitedUser']['id'] ?? ''), 'status' => (string)($r['status'] ?? '')];
    }

    /** Add a person as owner of a group. Group.ReadWrite.All (GroupMember.ReadWrite.All is not enough for owners). */
    public function addGroupOwner(string $groupId, string $userId): void
    {
        $groupId = self::guid($groupId, 'group id'); $userId = self::guid($userId, 'user id');
        $this->g->post("groups/{$groupId}/owners/\$ref", ['@odata.id' => 'https://graph.microsoft.com/v1.0/users/' . $userId]);
    }

    /** Take a person off the owners of a group. Group.ReadWrite.All. Microsoft refuses to remove the last owner of a Microsoft 365 group. */
    public function removeGroupOwner(string $groupId, string $userId): void
    {
        $groupId = self::guid($groupId, 'group id'); $userId = self::guid($userId, 'user id');
        $this->g->delete("groups/{$groupId}/owners/{$userId}/\$ref");
    }

    /** Ids of the groups that already carry exactly this name (Entra allows duplicates; ITOps does not create them). */
    public function groupsNamed(string $name): array
    {
        $rows = $this->g->get('groups', ['$filter' => "displayName eq '" . str_replace("'", "''", $name) . "'", '$select' => 'id,displayName', '$top' => 5]);
        return array_values(array_filter(array_map(fn($g) => (string)($g['id'] ?? ''), $rows['value'] ?? [])));
    }

    /**
     * Create a cloud security group with assigned membership (no mailbox, no team) and name its owners in the same call.
     * Group.ReadWrite.All, or Group.Create plus User.Read.All. A group created by an application without owners belongs
     * to nobody, so at least one owner is required.
     */
    public function createSecurityGroup(string $name, string $description, array $ownerIds): array
    {
        $owners = [];
        foreach ($ownerIds as $o) $owners[] = 'https://graph.microsoft.com/v1.0/users/' . self::guid((string)$o, 'owner id');
        if (!$owners) throw new InvalidArgumentException('A group needs at least one owner.');
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?? '', '-'));
        $nick = substr($slug !== '' ? $slug : 'group', 0, 40) . '-' . bin2hex(random_bytes(3));
        $body = ['displayName' => $name, 'mailEnabled' => false, 'mailNickname' => $nick, 'securityEnabled' => true, 'groupTypes' => [],
                 'owners@odata.bind' => array_slice($owners, 0, 20)];
        if ($description !== '') $body['description'] = $description;
        $g = $this->g->post('groups', $body);
        return ['id' => (string)($g['id'] ?? ''), 'name' => (string)($g['displayName'] ?? $name)];
    }

    /** Graph permissions that give an application broad access to mail, files, the directory or its own privileges. */
    public const HIGH_APP_PERMISSIONS = [
        'Directory.ReadWrite.All', 'User.ReadWrite.All', 'Group.ReadWrite.All', 'GroupMember.ReadWrite.All', 'RoleManagement.ReadWrite.Directory',
        'AppRoleAssignment.ReadWrite.All', 'Application.ReadWrite.All', 'Application.ReadWrite.OwnedBy', 'Policy.ReadWrite.ConditionalAccess',
        'UserAuthenticationMethod.ReadWrite.All', 'User.EnableDisableAccount.All', 'Device.ReadWrite.All', 'BitlockerKey.Read.All', 'DeviceLocalCredential.Read.All',
        'Mail.Read', 'Mail.ReadWrite', 'Mail.Send', 'MailboxSettings.ReadWrite', 'Files.Read.All', 'Files.ReadWrite.All', 'Sites.Read.All', 'Sites.ReadWrite.All',
        'Sites.Manage.All', 'Sites.FullControl.All', 'Calendars.ReadWrite', 'Contacts.ReadWrite', 'Chat.Read.All', 'ChannelMessage.Read.All', 'Notes.ReadWrite.All',
        'DeviceManagementConfiguration.ReadWrite.All', 'DeviceManagementManagedDevices.PrivilegedOperations.All', 'DeviceManagementManagedDevices.ReadWrite.All',
        'full_access_as_app', 'Exchange.ManageAsApp', 'Domain.ReadWrite.All', 'Organization.ReadWrite.All',
    ];
    /** Delegated permissions worth a second look when a person — not an administrator — granted them. */
    public const HIGH_DELEGATED_PERMISSIONS = [
        'Mail.Read', 'Mail.ReadWrite', 'Mail.Send', 'Mail.Read.Shared', 'Mail.ReadWrite.Shared', 'MailboxSettings.ReadWrite', 'Files.Read.All', 'Files.ReadWrite.All',
        'Sites.Read.All', 'Sites.ReadWrite.All', 'Sites.FullControl.All', 'Directory.ReadWrite.All', 'Directory.AccessAsUser.All', 'User.ReadWrite.All', 'Group.ReadWrite.All',
        'Contacts.Read', 'Contacts.ReadWrite', 'Calendars.ReadWrite', 'Chat.Read', 'Chat.ReadWrite', 'Notes.Read.All', 'Notes.ReadWrite.All', 'EWS.AccessAsUser.All',
        'IMAP.AccessAsUser.All', 'POP.AccessAsUser.All', 'full_access_as_user', 'RoleManagement.ReadWrite.Directory', 'Application.ReadWrite.All',
    ];
    /** The resource APIs whose application permissions are read (Microsoft Graph, Exchange Online, SharePoint Online). */
    private const APP_ROLE_RESOURCES = ['00000003-0000-0000-c000-000000000000', '00000002-0000-0ff1-ce00-000000000000', '00000003-0000-0ff1-ce00-000000000000'];
    /** Microsoft's own tenants: applications owned by them are Microsoft's, not a third party's. */
    private const MICROSOFT_TENANTS = ['f8cdef31-a31e-4b4a-93e4-5f571e91255a', '72f988bf-86f1-41af-91ab-2d7cd011db47'];

    /**
     * Applications that hold access in this tenant: what each may do without a person (application permissions) and on
     * behalf of people (delegated permissions, granted by an administrator for everyone or by individual people).
     * Application.Read.All for the applications and their application permissions; Directory.Read.All for the delegated
     * grants — refused parts are reported in 'needs', the rest is still returned. Read-only.
     */
    public function enterpriseApps(): array
    {
        $needs = [];
        $sps = $this->g->getAll('servicePrincipals', ['$select' => 'id,appId,displayName,accountEnabled,servicePrincipalType,appOwnerOrganizationId,tags,createdDateTime,verifiedPublisher,publisherName', '$top' => 999], 10);
        $byId = [];
        foreach ($sps as $sp) { $i = (string)($sp['id'] ?? ''); if ($i !== '') $byId[$i] = $sp; }
        $tenant = '';
        try { $org = $this->g->get('organization', ['$select' => 'id']); $tenant = strtolower((string)(($org['value'][0]['id'] ?? '') ?: '')); } catch (Throwable $e) {}
        $denied = fn(Throwable $e): bool => (bool)preg_match('/authoriz|privilege|forbidden|403|permission/i', $e->getMessage());

        // application permissions: who holds roles on Graph / Exchange / SharePoint
        $appPerms = [];
        foreach (self::APP_ROLE_RESOURCES as $rid) {
            try {
                $res = $this->g->get("servicePrincipals(appId='{$rid}')", ['$select' => 'id,displayName,appRoles']);
                $roles = [];
                foreach (($res['appRoles'] ?? []) as $r) $roles[(string)($r['id'] ?? '')] = (string)($r['value'] ?? '');
                foreach ($this->g->getAll("servicePrincipals(appId='{$rid}')/appRoleAssignedTo", ['$top' => 999], 10) as $a) {
                    if (strcasecmp((string)($a['principalType'] ?? ''), 'ServicePrincipal') !== 0) continue;
                    $name = $roles[(string)($a['appRoleId'] ?? '')] ?? '';
                    if ($name === '') continue;
                    $appPerms[(string)($a['principalId'] ?? '')][] = ['resource' => (string)($res['displayName'] ?? $rid), 'name' => $name, 'high' => in_array($name, self::HIGH_APP_PERMISSIONS, true)];
                }
            } catch (ItopsGraphException $e) {
                if ($e->getStatus() === 404) continue;                       // the tenant has no such API (for example no SharePoint)
                if ($denied($e)) { $needs['app_permissions'] = 'Application.Read.All'; break; }
                throw $e;
            }
        }
        // delegated permissions: admin consent (everyone) and consent given by individual people
        $deleg = [];
        try {
            foreach ($this->g->getAll('oauth2PermissionGrants', ['$top' => 999], 10) as $gr) {
                $cid = (string)($gr['clientId'] ?? ''); if ($cid === '') continue;
                $all = strcasecmp((string)($gr['consentType'] ?? ''), 'AllPrincipals') === 0;
                $resName = (string)($byId[(string)($gr['resourceId'] ?? '')]['displayName'] ?? 'API');
                foreach (preg_split('/\s+/', trim((string)($gr['scope'] ?? ''))) ?: [] as $sc) {
                    if ($sc === '') continue;
                    $k = $resName . '|' . $sc;
                    $d = &$deleg[$cid][$k];
                    if (!isset($d)) $d = ['resource' => $resName, 'name' => $sc, 'admin' => false, 'users' => [], 'high' => in_array($sc, self::HIGH_DELEGATED_PERMISSIONS, true)];
                    if ($all) $d['admin'] = true; elseif (($gr['principalId'] ?? '') !== '') $d['users'][(string)$gr['principalId']] = true;
                    unset($d);
                }
            }
        } catch (Throwable $e) { if ($denied($e)) $needs['delegated'] = 'Directory.Read.All'; else throw $e; }

        $out = [];
        foreach ($byId as $id => $sp) {
            $tags = $sp['tags'] ?? [];
            $ap = $appPerms[$id] ?? []; $dl = array_values($deleg[$id] ?? []);
            $owner = strtolower((string)($sp['appOwnerOrganizationId'] ?? ''));
            $kind = ($owner !== '' && $owner === $tenant) ? 'own' : (in_array($owner, self::MICROSOFT_TENANTS, true) ? 'microsoft' : ($owner === '' ? 'unknown' : 'third_party'));
            $integrated = in_array('WindowsAzureActiveDirectoryIntegratedApp', $tags, true);
            // Microsoft's own background services with no access granted here are noise: hundreds of them exist in every tenant.
            if (!$ap && !$dl && ($kind === 'microsoft' || !$integrated)) continue;
            if (strcasecmp((string)($sp['servicePrincipalType'] ?? ''), 'ManagedIdentity') === 0 && !$ap) continue;
            $userCons = 0; $userHigh = false; $adminDel = 0;
            foreach ($dl as &$d) { $d['user_count'] = count($d['users']); unset($d['users']); if ($d['user_count'] > 0) { $userCons = max($userCons, $d['user_count']); if ($d['high']) $userHigh = true; } if ($d['admin']) $adminDel++; }
            unset($d);
            usort($ap, fn($a, $b) => ($b['high'] <=> $a['high']) ?: strcmp($a['name'], $b['name']));
            usort($dl, fn($a, $b) => ($b['high'] <=> $a['high']) ?: strcmp($a['name'], $b['name']));
            $verified = trim((string)($sp['verifiedPublisher']['displayName'] ?? ''));
            $highApp = count(array_filter($ap, fn($x) => $x['high']));
            $flags = [];
            if ($highApp > 0) $flags[] = 'high';
            if ($userCons > 0) $flags[] = $userHigh ? 'user_consent_high' : 'user_consent';
            if ($kind === 'third_party' && $verified === '') $flags[] = 'unverified';
            if (!(bool)($sp['accountEnabled'] ?? true)) $flags[] = 'disabled';
            $out[] = [
                'id' => $id, 'app_id' => (string)($sp['appId'] ?? ''), 'name' => (string)($sp['displayName'] ?? ''), 'kind' => $kind,
                'publisher' => $verified !== '' ? $verified : (string)($sp['publisherName'] ?? ''), 'verified' => $verified !== '',
                'enabled' => (bool)($sp['accountEnabled'] ?? true), 'created' => $sp['createdDateTime'] ?? null,
                'app_permissions' => $ap, 'delegated' => $dl, 'app_count' => count($ap), 'high_count' => $highApp,
                'delegated_count' => count($dl), 'user_consented' => $userCons, 'flags' => $flags,
            ];
        }
        usort($out, fn($a, $b) => ($b['high_count'] <=> $a['high_count']) ?: strcasecmp($a['name'], $b['name']));
        return ['apps' => $out, 'needs' => $needs, 'tenant_known' => $tenant !== ''];
    }

    /**
     * The Entra audit log: who changed what in the directory. AuditLog.Read.All. Newest first; $capped is set when the
     * page limit cut the answer short. Microsoft keeps 7 days on Entra ID Free and 30 days with P1 / P2.
     */
    public function directoryAudits(string $sinceIso, string $category = '', int $maxPages = 4, ?bool &$capped = null): array
    {
        $f = 'activityDateTime ge ' . $sinceIso;
        if ($category !== '') {
            if (!preg_match('/^[A-Za-z]{2,40}$/', $category)) throw new InvalidArgumentException('That category is not valid.');
            $f .= " and category eq '{$category}'";
        }
        $pages = 0;
        $count = function ($n) use (&$pages) { $pages = $n; };
        try { $rows = $this->g->getAll('auditLogs/directoryAudits', ['$filter' => $f, '$top' => 500, '$orderby' => 'activityDateTime desc'], $maxPages, $count); }
        catch (ItopsGraphException $e) {
            if ($e->getStatus() !== 400) throw $e;                              // some tenants refuse $orderby together with $filter
            $pages = 0;
            $rows = $this->g->getAll('auditLogs/directoryAudits', ['$filter' => $f, '$top' => 500], $maxPages, $count);
            usort($rows, fn($x, $y) => strcmp((string)($y['activityDateTime'] ?? ''), (string)($x['activityDateTime'] ?? '')));
        }
        $capped = $pages >= $maxPages && count($rows) >= $maxPages * 500;
        $cut = fn($v, int $n = 300): string => mb_substr(trim((string)(is_scalar($v) || $v === null ? $v : json_encode($v)), " \t\n\r\"[]"), 0, $n);
        $out = [];
        foreach ($rows as $a) {
            $iu = $a['initiatedBy']['user'] ?? null; $ia = $a['initiatedBy']['app'] ?? null;
            $targets = []; $changes = [];
            foreach (($a['targetResources'] ?? []) as $t) {
                $targets[] = ['name' => (string)(($t['displayName'] ?? '') ?: ($t['userPrincipalName'] ?? '')), 'type' => (string)($t['type'] ?? ''), 'upn' => (string)($t['userPrincipalName'] ?? '')];
                foreach (($t['modifiedProperties'] ?? []) as $mp) {
                    if (count($changes) >= 30) break;
                    $changes[] = ['name' => (string)($mp['displayName'] ?? ''), 'old' => $cut($mp['oldValue'] ?? ''), 'new' => $cut($mp['newValue'] ?? '')];
                }
            }
            $out[] = [
                'id' => (string)($a['id'] ?? ''), 'time' => $a['activityDateTime'] ?? null, 'activity' => (string)($a['activityDisplayName'] ?? ''),
                'category' => (string)($a['category'] ?? ''), 'service' => (string)($a['loggedByService'] ?? ''),
                'result' => strtolower((string)($a['result'] ?? '')), 'reason' => $cut($a['resultReason'] ?? '', 400),
                'actor' => $iu ? (string)(($iu['displayName'] ?? '') ?: ($iu['userPrincipalName'] ?? '')) : (string)($ia['displayName'] ?? ''),
                'actor_upn' => (string)($iu['userPrincipalName'] ?? ''), 'actor_kind' => $iu ? 'user' : ($ia ? 'app' : ''), 'ip' => (string)($iu['ipAddress'] ?? ''),
                'targets' => $targets, 'changes' => $changes,
            ];
        }
        return $out;
    }

    /**
     * BT27: successful interactive sign-ins since a moment in time, newest first, reduced to what the
     * device inventory needs. Requires AuditLog.Read.All and Entra ID P1. $capped is set when the page limit was hit.
     */
    public function signInsSince(string $sinceIso, int $maxPages = 20, ?bool &$capped = null, ?callable $onPage = null): array
    {
        $capped = false;
        // Review SEC-29: oldest first, so a long log is read in order and the next run continues where this one stopped
        $rows = $this->g->getAll('auditLogs/signIns', ['$filter' => 'createdDateTime gt ' . $sinceIso, '$orderby' => 'createdDateTime asc', '$top' => 1000], $maxPages + 1, $onPage);
        if (count($rows) > $maxPages * 1000) { $capped = true; $rows = array_slice($rows, 0, $maxPages * 1000); }
        $out = [];
        foreach ($rows as $sx) {
            if ((int)(($sx['status'] ?? [])['errorCode'] ?? 0) !== 0) continue;      // failed attempts are not "a device this person uses"
            $dd = $sx['deviceDetail'] ?? [];
            $loc = $sx['location'] ?? [];
            $out[] = [
                'time'       => (string)($sx['createdDateTime'] ?? ''),
                'upn'        => (string)($sx['userPrincipalName'] ?? ''),
                'user_name'  => (string)($sx['userDisplayName'] ?? ''),
                'app'        => (string)($sx['appDisplayName'] ?? ''),
                'client'     => (string)($dd['browser'] ?? '') !== '' ? (string)$dd['browser'] : (string)($sx['clientAppUsed'] ?? ''),
                'ip'         => (string)($sx['ipAddress'] ?? ''),
                'location'   => trim(implode(', ', array_filter([(string)($loc['city'] ?? ''), (string)($loc['state'] ?? ''), (string)($loc['countryOrRegion'] ?? '')])), ', '),
                'device_id'  => strtolower((string)($dd['deviceId'] ?? '')),
                'device_name'=> (string)($dd['displayName'] ?? ''),
                'os'         => (string)($dd['operatingSystem'] ?? ''),
                'trust_type' => (string)($dd['trustType'] ?? ''),
                'managed'    => (bool)($dd['isManaged'] ?? false),
                'compliant'  => (bool)($dd['isCompliant'] ?? false),
            ];
        }
        return $out;
    }

    /** Remove the user from all (non-synced, non-dynamic) groups. Returns counts + per-group errors. */
    public function removeFromAllGroups(string $id): array
    {
        $removed = 0; $errors = [];
        try {
            $mem = $this->g->get("users/{$id}/memberOf", ['$select' => 'id,displayName']);
        } catch (Throwable $e) {
            return ['removed' => 0, 'errors' => ['Could not read memberships: ' . $e->getMessage()]];
        }
        foreach ($mem['value'] ?? [] as $m) {
            if (($m['@odata.type'] ?? '') !== '#microsoft.graph.group') continue;
            $gid = (string)($m['id'] ?? '');
            if ($gid === '') continue;
            try {
                $this->g->delete("groups/{$gid}/members/{$id}/\$ref");
                $removed++;
            } catch (Throwable $e) {
                $errors[] = (string)($m['displayName'] ?? $gid) . ': ' . $e->getMessage();
            }
        }
        return ['removed' => $removed, 'errors' => $errors];
    }

    /**
     * Offboard orchestration: always blocks sign-in, then optionally revokes
     * sessions, removes licenses, and removes group memberships. Never throws —
     * returns a per-step summary so partial failures are visible.
     */
    public function offboard(string $id, array $opts = []): array
    {
        $steps = [];
        $run = function (string $name, callable $fn) use (&$steps) {
            try { $steps[] = ['step' => $name, 'ok' => true, 'detail' => $fn()]; }
            catch (Throwable $e) { $steps[] = ['step' => $name, 'ok' => false, 'error' => $e->getMessage()]; }
        };
        $run('Block sign-in', function () use ($id) { $this->setAccountEnabled($id, false); return null; });
        if (!empty($opts['revokeSessions'])) $run('Revoke sessions', function () use ($id) { $this->revokeSessions($id); return null; });
        if (!empty($opts['removeLicenses'])) $run('Remove licenses', fn() => ['removed' => $this->removeAllLicenses($id)]);
        if (!empty($opts['removeFromGroups'])) $run('Remove from groups', fn() => $this->removeFromAllGroups($id));
        return ['steps' => $steps];
    }

    // ---- Shaping & helpers ------------------------------------------------

    private static function shapeListUser(array $u): array
    {
        return [
            'id'           => (string)($u['id'] ?? ''),
            'display_name' => (string)($u['displayName'] ?? ''),
            'upn'          => (string)($u['userPrincipalName'] ?? ''),
            'mail'         => (string)($u['mail'] ?? ''),
            'enabled'      => (bool)($u['accountEnabled'] ?? false),
            'job_title'    => (string)($u['jobTitle'] ?? ''),
            'department'   => (string)($u['department'] ?? ''),
            'user_type'    => (string)($u['userType'] ?? ''),
            'license_count' => count($u['assignedLicenses'] ?? []),
        ];
    }

    private static function skipTokenFrom(?string $nextLink): ?string
    {
        if (!$nextLink) return null;
        $qs = parse_url($nextLink, PHP_URL_QUERY);
        if (!$qs) return null;
        parse_str($qs, $parts);
        return isset($parts['$skiptoken']) ? (string)$parts['$skiptoken'] : null;
    }

    private static function methodLabel(string $odataType): string
    {
        return [
            '#microsoft.graph.passwordAuthenticationMethod'               => 'Password',
            '#microsoft.graph.phoneAuthenticationMethod'                  => 'Phone',
            '#microsoft.graph.microsoftAuthenticatorAuthenticationMethod' => 'Authenticator app',
            '#microsoft.graph.fido2AuthenticationMethod'                  => 'FIDO2 key',
            '#microsoft.graph.softwareOathAuthenticationMethod'           => 'Software OATH',
            '#microsoft.graph.emailAuthenticationMethod'                  => 'Email',
            '#microsoft.graph.windowsHelloForBusinessAuthenticationMethod'=> 'Windows Hello',
            '#microsoft.graph.temporaryAccessPassAuthenticationMethod'    => 'Temporary access pass',
        ][$odataType] ?? '';
    }

    /** Endpoint segment for deleting a method; null for password (not removable). */
    private static function methodEndpoint(string $odataType): ?string
    {
        return [
            '#microsoft.graph.phoneAuthenticationMethod'                  => 'phoneMethods',
            '#microsoft.graph.microsoftAuthenticatorAuthenticationMethod' => 'microsoftAuthenticatorMethods',
            '#microsoft.graph.fido2AuthenticationMethod'                  => 'fido2Methods',
            '#microsoft.graph.softwareOathAuthenticationMethod'           => 'softwareOathMethods',
            '#microsoft.graph.emailAuthenticationMethod'                  => 'emailMethods',
            '#microsoft.graph.windowsHelloForBusinessAuthenticationMethod'=> 'windowsHelloForBusinessMethods',
            '#microsoft.graph.temporaryAccessPassAuthenticationMethod'    => 'temporaryAccessPassMethods',
        ][$odataType] ?? null;
    }

    private static function skuFriendly(string $part): string
    {
        $known = [
            'O365_BUSINESS_ESSENTIALS' => 'Microsoft 365 Business Basic',
            'O365_BUSINESS_PREMIUM'    => 'Microsoft 365 Business Standard',
            'SPB'                      => 'Microsoft 365 Business Premium',
            'SPE_E3'                   => 'Microsoft 365 E3',
            'SPE_E5'                   => 'Microsoft 365 E5',
            'ENTERPRISEPACK'           => 'Office 365 E3',
            'ENTERPRISEPREMIUM'        => 'Office 365 E5',
            'EXCHANGESTANDARD'         => 'Exchange Online (Plan 1)',
            'EXCHANGEENTERPRISE'       => 'Exchange Online (Plan 2)',
            'FLOW_FREE'                => 'Power Automate Free',
            'POWER_BI_STANDARD'        => 'Power BI (free)',
            'TEAMS_EXPLORATORY'        => 'Teams Exploratory',
            'STANDARDPACK'             => 'Office 365 E1',
            'DESKLESSPACK'             => 'Office 365 F3',
            'SPE_F1'                   => 'Microsoft 365 F3',
        ];
        return $known[$part] ?? $part;
    }

    /** Generate a strong temporary password that satisfies default Entra policy. */
    public static function generatePassword(int $len = 16): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnpqrstuvwxyz';
        $digit = '23456789';
        $sym   = '!@#$%*?-_';
        $all   = $upper . $lower . $digit . $sym;
        $pick = fn($set) => $set[random_int(0, strlen($set) - 1)];
        $out = [$pick($upper), $pick($lower), $pick($digit), $pick($sym)];
        for ($i = count($out); $i < $len; $i++) $out[] = $pick($all);
        for ($i = count($out) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$out[$i], $out[$j]] = [$out[$j], $out[$i]]; }
        return implode('', $out);
    }
}
}
