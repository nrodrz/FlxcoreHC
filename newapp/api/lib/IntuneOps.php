<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT114: Microsoft 365 redesign, phase 3 (Devices). Intune through Microsoft Graph v1.0.
 *
 *  - Device actions. Safe ones run at once (restart, Defender scan); retire, wipe and fresh start only run after a second
 *    person approves (api/routes/m365hub.php, table itops_m365_dev_requests). Personal devices: only "retire" (it removes
 *    the organization's apps and data and leaves the person's own); nothing else is ever sent to a personal device.
 *  - Apps (assigned through Intune, with their assignments) and the software found on devices.
 *  - Configuration profiles (with assignments and device status) and Windows update rings (pause / resume).
 *
 * Graph permissions: DeviceManagementManagedDevices.PrivilegedOperations.All (actions), DeviceManagementApps.Read.All
 * (apps), DeviceManagementManagedDevices.Read.All (software found), DeviceManagementConfiguration.Read.All (profiles,
 * rings) and .ReadWrite.All (pause / resume a ring). Uses itops_graph_get / itops_graph_send (lib/Graph.php).
 */

if (!function_exists('itops_io_actions')) {

    /** key => [label, Graph action, needs a second person, allowed on a personal device, Windows only, what it does] */
    function itops_io_actions(): array {
        return [
            'restart'     => ['Restart', 'rebootNow', false, false, false, 'Restarts the device the next time it checks in. Unsaved work on it is lost.'],
            'scan_quick'  => ['Defender quick scan', 'windowsDefenderScan', false, false, true, 'Microsoft Defender scans the places malware usually hides. The person can keep working.'],
            'scan_full'   => ['Defender full scan', 'windowsDefenderScan', false, false, true, 'Microsoft Defender scans every file. It can take hours and slow the device.'],
            'retire'      => ['Retire', 'retire', true, true, false, 'Removes the organization\'s apps, data, email profiles and settings, and takes the device out of Intune. The person\'s own files and apps stay.'],
            'wipe'        => ['Wipe (factory reset)', 'wipe', true, false, false, 'Erases the device and returns it to factory settings. Everything on it is deleted unless you keep it below.'],
            'fresh_start' => ['Fresh start', 'cleanWindowsDevice', true, false, true, 'Reinstalls Windows and removes apps that did not come with it. The device stays in Intune and Entra.'],
        ];
    }

    /** The options an action takes, with their default. */
    function itops_io_options(string $action): array {
        if ($action === 'wipe') return ['keep_enrollment' => false, 'keep_user_data' => false];
        if ($action === 'fresh_start') return ['keep_user_data' => true];
        return [];
    }

    /** One device as Intune has it now (null when Intune no longer has it). */
    function itops_io_device(string $id): ?array {
        if (!preg_match('/^[A-Za-z0-9-]{8,80}$/', $id)) throw new InvalidArgumentException('Not a device id.');
        try {
            $d = itops_graph_get('deviceManagement/managedDevices/' . rawurlencode($id) . '?$select=id,deviceName,operatingSystem,osVersion,managedDeviceOwnerType,userPrincipalName,serialNumber,lastSyncDateTime,deviceActionResults');
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) return null;
            throw $e;
        }
        return ['id' => (string)($d['id'] ?? $id), 'name' => (string)($d['deviceName'] ?? ''), 'os' => (string)($d['operatingSystem'] ?? ''),
                'os_version' => (string)($d['osVersion'] ?? ''), 'owner' => strtolower((string)($d['managedDeviceOwnerType'] ?? '')),
                'user' => strtolower((string)($d['userPrincipalName'] ?? '')), 'serial' => (string)($d['serialNumber'] ?? ''),
                'last_sync' => $d['lastSyncDateTime'] ?? null, 'results' => array_values((array)($d['deviceActionResults'] ?? []))];
    }

    /** Why this action may not go to this device, or null. */
    function itops_io_refuse(array $dev, string $action): ?string {
        $A = itops_io_actions();
        if (!isset($A[$action])) return 'Unknown device action.';
        if ($dev['owner'] === 'personal' && !$A[$action][3])
            return 'This is a personal device. Only "Retire" can be used on it: it removes the organization\'s apps and data and leaves the person\'s own.';
        if ($dev['owner'] === '' || $dev['owner'] === 'unknown') { if (!$A[$action][3]) return 'Intune does not know who owns this device. Set it to "Corporate" in Intune first.'; }
        if ($A[$action][4] && stripos($dev['os'], 'windows') === false) return $A[$action][0] . ' is for Windows devices only.';
        return null;
    }

    /** Send the action to Intune. $opts: the options of itops_io_options(). */
    function itops_io_send(string $id, string $action, array $opts = []): void {
        $A = itops_io_actions(); if (!isset($A[$action])) throw new InvalidArgumentException('Unknown device action.');
        $body = [];
        if ($action === 'scan_quick' || $action === 'scan_full') $body = ['quickScan' => $action === 'scan_quick'];
        if ($action === 'wipe') $body = ['keepEnrollmentData' => !empty($opts['keep_enrollment']), 'keepUserData' => !empty($opts['keep_user_data'])];
        if ($action === 'fresh_start') $body = ['keepUserData' => !array_key_exists('keep_user_data', $opts) || !empty($opts['keep_user_data'])];
        itops_graph_send('POST', 'deviceManagement/managedDevices/' . rawurlencode($id) . '/' . $A[$action][1], $body);
    }

    /** Intune's own record of an action sent to a device: pending, active, done, failed… (null when not reported yet). */
    function itops_io_action_state(array $dev, string $action): ?array {
        $A = itops_io_actions(); $want = strtolower((string)($A[$action][1] ?? ''));
        $alias = ['rebootnow' => 'rebootnow', 'cleanwindowsdevice' => 'cleanwindowsdevice', 'windowsdefenderscan' => 'windowsdefenderscan', 'retire' => 'retire', 'wipe' => 'wipe'];
        $best = null;
        foreach ($dev['results'] as $r) {
            $n = strtolower(str_replace([' ', '_'], '', (string)($r['actionName'] ?? '')));
            if ($n !== ($alias[$want] ?? $want) && !($want === 'cleanwindowsdevice' && $n === 'freshstart')) continue;
            if (!$best || strcmp((string)($r['lastUpdatedDateTime'] ?? ''), (string)($best['lastUpdatedDateTime'] ?? '')) > 0) $best = $r;
        }
        return $best ? ['state' => (string)($best['actionState'] ?? ''), 'started' => $best['startDateTime'] ?? null, 'updated' => $best['lastUpdatedDateTime'] ?? null] : null;
    }

    /** Every page of a Graph list (at most $max pages; only graph.microsoft.com next links are followed). */
    function itops_io_all(string $path, string $version = 'v1.0', int $max = 20): array {
        $out = []; $p = $path;
        for ($i = 0; $i < $max && $p !== ''; $i++) {
            $j = itops_graph_get($p, $version);
            foreach (($j['value'] ?? []) as $v) $out[] = $v;
            $n = (string)($j['@odata.nextLink'] ?? ''); $p = '';
            if ($n !== '') { $pre = 'https://graph.microsoft.com/' . $version . '/'; if (strpos($n, $pre) === 0) $p = substr($n, strlen($pre)); }
        }
        return $out;
    }

    /** Group names for ids (unknown ids keep the id). */
    function itops_io_group_names(array $ids): array {
        $ids = array_values(array_unique(array_filter($ids, fn($x) => is_string($x) && preg_match('/^[0-9a-f-]{36}$/i', $x))));
        $names = [];
        foreach (array_chunk($ids, 1000) as $ch) {
            try { $j = itops_graph_send('POST', 'directoryObjects/getByIds', ['ids' => $ch, 'types' => ['group']]); foreach (($j['value'] ?? []) as $g) $names[strtolower((string)$g['id'])] = (string)($g['displayName'] ?? ''); }
            catch (Throwable $e) {}
        }
        return $names;
    }

    /** One assignment in words: [who, intent, excluded]. */
    function itops_io_target(array $a, array $names): array {
        $t = (array)($a['target'] ?? []); $ty = strtolower((string)($t['@odata.type'] ?? ''));
        $gid = strtolower((string)($t['groupId'] ?? ''));
        $who = str_contains($ty, 'alllicensedusers') ? 'All users' : (str_contains($ty, 'alldevices') ? 'All devices' : ($names[$gid] ?? ($gid !== '' ? 'Group ' . substr($gid, 0, 8) : 'Unknown')));
        return ['who' => $who, 'intent' => (string)($a['intent'] ?? ''), 'excluded' => str_contains($ty, 'exclusion')];
    }

    /** App type and platform from Graph's @odata.type. */
    function itops_io_app_kind(string $t): array {
        $t = strtolower(preg_replace('/^#microsoft\.graph\./', '', $t));
        $map = ['win32lobapp' => ['Windows app (Win32)', 'Windows'], 'wingetapp' => ['Microsoft Store app', 'Windows'], 'windowsmicrosoftedgeapp' => ['Microsoft Edge', 'Windows'],
                'officesuiteapp' => ['Microsoft 365 Apps', 'Windows'], 'windowsmobilemsi' => ['Windows MSI', 'Windows'], 'windowsuniversalappx' => ['Windows app package', 'Windows'],
                'microsoftstoreforbusinessapp' => ['Store for Business app', 'Windows'], 'windowswebapp' => ['Web link', 'Windows'],
                'iosstoreapp' => ['App Store app', 'iOS'], 'iosvppapp' => ['App Store app (purchased)', 'iOS'], 'managediosstoreapp' => ['App Store app (managed)', 'iOS'], 'ioslobapp' => ['iOS line-of-business app', 'iOS'],
                'androidstoreapp' => ['Google Play app', 'Android'], 'androidmanagedstoreapp' => ['Managed Google Play app', 'Android'], 'managedandroidstoreapp' => ['Google Play app (managed)', 'Android'], 'androidlobapp' => ['Android line-of-business app', 'Android'],
                'macosmicrosoftedgeapp' => ['Microsoft Edge', 'macOS'], 'macosofficesuiteapp' => ['Microsoft 365 Apps', 'macOS'], 'macoslobapp' => ['macOS line-of-business app', 'macOS'], 'macosdmgapp' => ['macOS app (DMG)', 'macOS'], 'macosvppapp' => ['App Store app (purchased)', 'macOS'],
                'webapp' => ['Web link', 'Any']];
        return $map[$t] ?? [ucfirst($t), 'Other'];
    }

    /** Apps in Intune with their assignments. */
    function itops_io_apps(): array {
        $rows = itops_io_all('deviceAppManagement/mobileApps?$expand=assignments&$top=200');
        $gids = []; foreach ($rows as $a) foreach ((array)($a['assignments'] ?? []) as $x) $gids[] = (string)($x['target']['groupId'] ?? '');
        $names = itops_io_group_names($gids); $out = [];
        foreach ($rows as $a) {
            [$kind, $plat] = itops_io_app_kind((string)($a['@odata.type'] ?? ''));
            $as = array_map(fn($x) => itops_io_target($x, $names), (array)($a['assignments'] ?? []));
            $out[] = ['id' => (string)$a['id'], 'name' => (string)($a['displayName'] ?? ''), 'publisher' => (string)($a['publisher'] ?? ''), 'kind' => $kind, 'platform' => $plat,
                      'created' => $a['createdDateTime'] ?? null, 'modified' => $a['lastModifiedDateTime'] ?? null, 'assignments' => $as,
                      'required' => count(array_filter($as, fn($x) => $x['intent'] === 'required' && !$x['excluded'])) > 0, 'assigned' => (bool)$as];
        }
        usort($out, fn($x, $y) => strcasecmp($x['name'], $y['name']));
        return $out;
    }

    /** Software found on managed devices, one row per name (versions together). */
    function itops_io_software(): array {
        $rows = itops_io_all('deviceManagement/detectedApps?$top=500', 'v1.0', 40);
        $by = [];
        foreach ($rows as $r) {
            $n = trim((string)($r['displayName'] ?? '')); if ($n === '') continue; $k = strtolower($n);
            if (!isset($by[$k])) $by[$k] = ['name' => $n, 'publisher' => (string)($r['publisher'] ?? ''), 'platform' => (string)($r['platform'] ?? ''), 'devices' => 0, 'versions' => []];
            $by[$k]['devices'] += (int)($r['deviceCount'] ?? 0);
            $by[$k]['versions'][] = ['id' => (string)$r['id'], 'version' => (string)($r['version'] ?? ''), 'devices' => (int)($r['deviceCount'] ?? 0)];
        }
        foreach ($by as &$b) usort($b['versions'], fn($x, $y) => version_compare($y['version'], $x['version']));
        unset($b);
        $out = array_values($by); usort($out, fn($x, $y) => $y['devices'] <=> $x['devices'] ?: strcasecmp($x['name'], $y['name']));
        return $out;
    }

    /** The devices that have one version of a piece of software. */
    function itops_io_software_devices(string $id): array {
        if (!preg_match('/^[A-Za-z0-9_=+\/-]{6,200}$/', $id)) throw new InvalidArgumentException('Not a software id.');
        $out = [];
        foreach (itops_io_all('deviceManagement/detectedApps/' . rawurlencode($id) . '/managedDevices?$select=id,deviceName,userPrincipalName,operatingSystem', 'v1.0', 5) as $d)
            $out[] = ['id' => (string)$d['id'], 'name' => (string)($d['deviceName'] ?? ''), 'user' => strtolower((string)($d['userPrincipalName'] ?? '')), 'os' => (string)($d['operatingSystem'] ?? '')];
        return $out;
    }

    /** Platform and kind of a configuration profile from @odata.type. */
    function itops_io_profile_kind(string $t): array {
        $t = preg_replace('/^#microsoft\.graph\./', '', $t); $l = strtolower($t);
        $plat = str_starts_with($l, 'windows') ? 'Windows' : (str_starts_with($l, 'ios') ? 'iOS' : (str_starts_with($l, 'macos') ? 'macOS' : (str_starts_with($l, 'android') ? 'Android' : 'Other')));
        $kinds = ['generalconfiguration' => 'Device restrictions', 'endpointprotection' => 'Endpoint protection', 'custom' => 'Custom', 'wifi' => 'Wi-Fi', 'vpn' => 'VPN',
                  'devicefeatures' => 'Device features', 'emailprofile' => 'Email', 'certificate' => 'Certificate', 'trustedrootcertificate' => 'Trusted certificate',
                  'pkcs' => 'Certificate (PKCS)', 'scep' => 'Certificate (SCEP)', 'kiosk' => 'Kiosk', 'identityprotection' => 'Identity protection', 'teamgeneral' => 'Teams device',
                  'healthmonitoring' => 'Health monitoring', 'deliveryoptimization' => 'Delivery optimization', 'editionupgrade' => 'Edition upgrade', 'domainjoin' => 'Domain join',
                  'workprofile' => 'Work profile', 'deviceowner' => 'Fully managed'];
        $kind = 'Configuration'; foreach ($kinds as $k => $v) if (str_contains($l, $k)) { $kind = $v; break; }
        return [$plat, $kind];
    }

    /** Configuration profiles and Windows update rings (one read: Intune keeps both as device configurations). */
    function itops_io_config(): array {
        $rows = itops_io_all('deviceManagement/deviceConfigurations?$expand=assignments&$top=200');
        $gids = []; foreach ($rows as $p) foreach ((array)($p['assignments'] ?? []) as $x) $gids[] = (string)($x['target']['groupId'] ?? '');
        $sc = []; $scErr = null;
        try { $sc = itops_io_all('deviceManagement/configurationPolicies?$expand=assignments&$select=id,name,description,platforms,technologies,lastModifiedDateTime,settingCount&$top=100', 'beta', 10);
              foreach ($sc as $p) foreach ((array)($p['assignments'] ?? []) as $x) $gids[] = (string)($x['target']['groupId'] ?? ''); }
        catch (Throwable $e) { $scErr = $e->getMessage(); }
        $names = itops_io_group_names($gids);
        $profiles = []; $rings = []; $n = 0;
        foreach ($rows as $p) {
            $type = (string)($p['@odata.type'] ?? ''); $as = array_map(fn($x) => itops_io_target($x, $names), (array)($p['assignments'] ?? []));
            $st = null;
            if ($n++ < 80) { try { $o = itops_graph_get('deviceManagement/deviceConfigurations/' . rawurlencode((string)$p['id']) . '/deviceStatusOverview');
                $st = ['ok' => (int)($o['successCount'] ?? 0), 'error' => (int)($o['errorCount'] ?? 0) + (int)($o['failedCount'] ?? 0), 'conflict' => (int)($o['conflictCount'] ?? 0), 'pending' => (int)($o['pendingCount'] ?? 0), 'na' => (int)($o['notApplicableCount'] ?? 0)]; } catch (Throwable $e) {} }
            if (stripos($type, 'windowsUpdateForBusinessConfiguration') !== false) {
                $rings[] = ['id' => (string)$p['id'], 'name' => (string)($p['displayName'] ?? ''), 'description' => (string)($p['description'] ?? ''), 'modified' => $p['lastModifiedDateTime'] ?? null,
                    'quality_deferral' => (int)($p['qualityUpdatesDeferralPeriodInDays'] ?? 0), 'feature_deferral' => (int)($p['featureUpdatesDeferralPeriodInDays'] ?? 0),
                    'quality_paused' => !empty($p['qualityUpdatesPaused']), 'feature_paused' => !empty($p['featureUpdatesPaused']),
                    'quality_pause_until' => $p['qualityUpdatesPauseExpiryDateTime'] ?? null, 'feature_pause_until' => $p['featureUpdatesPauseExpiryDateTime'] ?? null,
                    'quality_deadline' => $p['deadlineForQualityUpdatesInDays'] ?? null, 'feature_deadline' => $p['deadlineForFeatureUpdatesInDays'] ?? null,
                    'mode' => (string)($p['automaticUpdateMode'] ?? ''), 'drivers' => empty($p['driversExcluded']), 'assignments' => $as, 'status' => $st];
                continue;
            }
            [$plat, $kind] = itops_io_profile_kind($type);
            $profiles[] = ['id' => (string)$p['id'], 'name' => (string)($p['displayName'] ?? ''), 'description' => (string)($p['description'] ?? ''), 'platform' => $plat, 'kind' => $kind,
                           'source' => 'template', 'modified' => $p['lastModifiedDateTime'] ?? null, 'assignments' => $as, 'status' => $st];
        }
        foreach ($sc as $p) {
            $pl = strtolower((string)($p['platforms'] ?? '')); $plat = str_contains($pl, 'windows') ? 'Windows' : (str_contains($pl, 'ios') ? 'iOS' : (str_contains($pl, 'macos') ? 'macOS' : (str_contains($pl, 'android') ? 'Android' : 'Other')));
            $profiles[] = ['id' => (string)$p['id'], 'name' => (string)($p['name'] ?? ''), 'description' => (string)($p['description'] ?? ''), 'platform' => $plat, 'kind' => 'Settings catalog (' . (int)($p['settingCount'] ?? 0) . ' settings)',
                           'source' => 'catalog', 'modified' => $p['lastModifiedDateTime'] ?? null, 'assignments' => array_map(fn($x) => itops_io_target($x, $names), (array)($p['assignments'] ?? [])), 'status' => null];
        }
        usort($profiles, fn($x, $y) => strcasecmp($x['platform'] . $x['name'], $y['platform'] . $y['name']));
        usort($rings, fn($x, $y) => strcasecmp($x['name'], $y['name']));
        return ['profiles' => $profiles, 'rings' => $rings, 'catalog_error' => $scErr];
    }

    /** Pause or resume quality or feature updates on an update ring (Microsoft keeps a pause for 35 days). */
    function itops_io_ring_pause(string $id, string $which, bool $paused): void {
        if (!preg_match('/^[A-Za-z0-9-]{8,80}$/', $id)) throw new InvalidArgumentException('Not an update ring.');
        if (!in_array($which, ['quality', 'feature'], true)) throw new InvalidArgumentException('Choose quality or feature updates.');
        itops_graph_send('PATCH', 'deviceManagement/deviceConfigurations/' . rawurlencode($id),
            ['@odata.type' => '#microsoft.graph.windowsUpdateForBusinessConfiguration', ($which === 'quality' ? 'qualityUpdatesPaused' : 'featureUpdatesPaused') => $paused]);
    }
}
