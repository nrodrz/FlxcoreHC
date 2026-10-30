<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Intune policy inventory, backup, restore and clone (BT12).
 *
 * One registry of policy KINDS drives everything, so every type is handled the same way:
 *   compliance    deviceManagement/deviceCompliancePolicies        (v1.0)  device status overview
 *   configuration deviceManagement/deviceConfigurations            (v1.0)  device status overview (incl. update rings)
 *   settings      deviceManagement/configurationPolicies            (beta)  settings catalog + endpoint security
 *                 (Defender antivirus, firewall, BitLocker, attack-surface reduction, EDR…)
 *   mam_ios / mam_android  deviceAppManagement/*ManagedAppProtections (v1.0)  app protection for phones
 *
 * Backups store the FULL policy JSON exactly as Microsoft returned it. Restore / clone always create a
 * NEW policy (never overwrite), unassigned unless asked — so a restore can't make things worse.
 * Permissions: DeviceManagementConfiguration.ReadWrite.All (+ DeviceManagementApps.ReadWrite.All for MAM).
 */

if (!function_exists('opsh_ipol_kinds')) {

    function opsh_ipol_kinds(): array {
        return [
            'compliance'    => ['label' => 'Compliance policy', 'path' => 'deviceManagement/deviceCompliancePolicies', 'ver' => 'v1.0',
                                'expand' => 'assignments,scheduledActionsForRule($expand=scheduledActionConfigurations)', 'status' => true],
            'configuration' => ['label' => 'Configuration profile', 'path' => 'deviceManagement/deviceConfigurations', 'ver' => 'v1.0',
                                'expand' => 'assignments', 'status' => true],
            'settings'      => ['label' => 'Settings catalog / endpoint security', 'path' => 'deviceManagement/configurationPolicies', 'ver' => 'beta',
                                'expand' => 'assignments', 'status' => false],
            'mam_ios'       => ['label' => 'App protection (iOS)', 'path' => 'deviceAppManagement/iosManagedAppProtections', 'ver' => 'v1.0',
                                'expand' => 'apps,assignments', 'status' => false],
            'mam_android'   => ['label' => 'App protection (Android)', 'path' => 'deviceAppManagement/androidManagedAppProtections', 'ver' => 'v1.0',
                                'expand' => 'apps,assignments', 'status' => false],
        ];
    }

    /** Human platform + sub-type from a policy object. */
    function opsh_ipol_describe(string $kind, array $p): array {
        $t = (string)($p['@odata.type'] ?? '');
        if ($kind === 'mam_ios') return ['iOS / iPadOS', 'App protection'];
        if ($kind === 'mam_android') return ['Android', 'App protection'];
        if ($kind === 'settings') {
            $plat = (string)($p['platforms'] ?? '');
            $tpl = (string)($p['templateReference']['templateFamily'] ?? '');
            $sub = $tpl && $tpl !== 'none' ? ucfirst(preg_replace('/([a-z])([A-Z])/', '$1 $2', preg_replace('/^endpointSecurity/', 'Endpoint security: ', $tpl))) : 'Settings catalog';
            return [$plat === 'windows10' ? 'Windows' : ($plat === 'macOS' ? 'macOS' : ($plat ?: 'multi')), $sub];
        }
        $plat = 'other';
        foreach (['windows10' => 'Windows', 'windows81' => 'Windows', 'macOS' => 'macOS', 'ios' => 'iOS / iPadOS', 'android' => 'Android', 'windows' => 'Windows'] as $k => $l) if (stripos($t, $k) !== false) { $plat = $l; break; }
        $sub = preg_replace(['/^#microsoft\.graph\./', '/^(windows10|windows81|windows|macOS|ios|android)/i', '/(Compliance)?(Policy|Configuration)$/'], '', $t);
        if (stripos($t, 'UpdateForBusiness') !== false) $sub = 'Windows Update ring';
        return [$plat, $sub !== '' ? preg_replace('/([a-z])([A-Z])/', '$1 $2', ucfirst($sub)) : ($kind === 'compliance' ? 'Device compliance' : '')];
    }

    /** Assignment targets → short labels. */
    function opsh_ipol_assign_labels(array $asg, array $groupNames = []): array {
        $out = [];
        foreach ($asg as $a) {
            $t = (string)($a['target']['@odata.type'] ?? '');
            $gid = (string)($a['target']['groupId'] ?? '');
            $ex = stripos($t, 'exclusion') !== false;
            if (stripos($t, 'allDevices') !== false) $out[] = 'All devices';
            elseif (stripos($t, 'allLicensedUsers') !== false) $out[] = 'All users';
            elseif ($gid !== '') $out[] = ($ex ? 'Exclude: ' : '') . ($groupNames[$gid] ?? 'Group ' . substr($gid, 0, 8));
        }
        return $out;
    }

    /** Every policy of every kind, with assignments and (where Microsoft reports it) device status. */
    function opsh_ipol_inventory(): array {
        $rows = []; $errors = []; $gids = [];
        foreach (opsh_ipol_kinds() as $kind => $K) {
            try {   // BT124 (code review SEC-20): every page, not only the first
                require_once __DIR__ . '/IntuneOps.php';
                $r = ['value' => opsh_io_all($K['path'] . '?$expand=' . $K['expand'], $K['ver'], 20)];
            } catch (Throwable $e) { $errors[$kind] = $e->getMessage(); continue; }
            foreach (($r['value'] ?? []) as $p) {
                [$plat, $sub] = opsh_ipol_describe($kind, $p);
                foreach (($p['assignments'] ?? []) as $a) if (!empty($a['target']['groupId'])) $gids[$a['target']['groupId']] = true;
                $rows[] = ['kind' => $kind, 'kind_label' => $K['label'], 'id' => (string)($p['id'] ?? ''),
                    'name' => (string)($p['displayName'] ?? $p['name'] ?? ''), 'description' => (string)($p['description'] ?? ''),
                    'platform' => $plat, 'type' => $sub, 'modified' => (string)($p['lastModifiedDateTime'] ?? ''),
                    '_asg' => $p['assignments'] ?? [], 'status' => null, 'status_supported' => $K['status']];
            }
        }
        // group names for the assignment column (bounded)
        $names = [];
        foreach (array_slice(array_keys($gids), 0, 40) as $gid) {
            try { $g = opsh_graph_get('groups/' . rawurlencode($gid) . '?$select=displayName'); $names[$gid] = (string)($g['displayName'] ?? ''); } catch (Throwable $e) {}
        }
        // device status for compliance + configuration policies (bounded to 80 calls)
        $n = 0;
        foreach ($rows as &$row) {
            $row['assignments'] = opsh_ipol_assign_labels($row['_asg'], $names); unset($row['_asg']);
            if (!$row['status_supported'] || $n >= 80) continue;
            $n++;
            try {
                $K = opsh_ipol_kinds()[$row['kind']];
                $o = opsh_graph_get($K['path'] . '/' . rawurlencode($row['id']) . '/deviceStatusOverview', $K['ver']);
                $row['status'] = ['success' => (int)($o['successCount'] ?? 0), 'error' => (int)($o['errorCount'] ?? 0) + (int)($o['failedCount'] ?? 0),
                    'conflict' => (int)($o['conflictCount'] ?? 0), 'pending' => (int)($o['pendingCount'] ?? 0), 'na' => (int)($o['notApplicableCount'] ?? 0)];
            } catch (Throwable $e) {}
        }
        unset($row);
        usort($rows, fn($a, $b) => strcmp($a['platform'], $b['platform']) ?: strcmp($a['kind'], $b['kind']) ?: strcasecmp($a['name'], $b['name']));
        return ['value' => $rows, 'errors' => $errors];
    }

    /** Full JSON of one policy (for backup). Settings-catalog policies also carry their settings list. */
    function opsh_ipol_fetch(string $kind, string $id): array {
        $K = opsh_ipol_kinds()[$kind] ?? null;
        if (!$K) throw new RuntimeException('Unknown policy kind');
        $p = opsh_graph_get($K['path'] . '/' . rawurlencode($id) . '?$expand=' . $K['expand'], $K['ver']);
        if ($kind === 'settings') {
            $settings = []; $url = 'https://graph.microsoft.com/beta/' . $K['path'] . '/' . rawurlencode($id) . '/settings';
            for ($i = 0; $i < 10 && $url; $i++) {
                [$code, $d] = opsh_graph_http('GET', $url);
                if ($code >= 400) throw new RuntimeException('Could not read the policy settings (HTTP ' . $code . ')');
                foreach (($d['value'] ?? []) as $s) $settings[] = $s;
                $url = $d['@odata.nextLink'] ?? null;
            }
            $p['settings'] = $settings;
        }
        return $p;
    }

    /** Remove read-only / server-generated properties (recursively) so JSON can be POSTed as a new policy. */
    function opsh_ipol_strip($v, int $depth = 0) {
        if (!is_array($v)) return $v;
        $isList = array_keys($v) === range(0, count($v) - 1);
        $out = [];
        foreach ($v as $k => $x) {
            if (!$isList) {
                $k = (string)$k;
                if ($k === 'id' || $k === 'assignments' || $k === 'version' || $k === 'createdDateTime' || $k === 'lastModifiedDateTime'
                    || $k === 'isAssigned' || $k === 'settingCount' || $k === 'creationSource' || $k === 'deployedAppCount'
                    || $k === 'priorityMetaData' || ($k === 'apps' && $depth === 0)) continue;
                if ($k !== '@odata.type' && strpos($k, '@odata.') !== false) continue;   // @odata.context, navigationLink…
                if (substr($k, -13) === '@odata.navigationLink' || substr($k, -21) === '@odata.associationLink') continue;
            }
            $out[$k] = opsh_ipol_strip($x, $depth + 1);
        }
        return $out;
    }

    /**
     * Create a NEW policy from a stored/fetched policy JSON. $opts: name (required), copy_assignments (bool).
     * Returns the new id. Used by restore (from a backup row) and clone (from a live policy).
     */
    function opsh_ipol_create_from(string $kind, array $src, array $opts): string {
        $K = opsh_ipol_kinds()[$kind] ?? null;
        if (!$K) throw new RuntimeException('Unknown policy kind');
        $name = trim((string)($opts['name'] ?? '')); if ($name === '') throw new RuntimeException('A name for the new policy is required');
        $asg = $src['assignments'] ?? [];
        $apps = $src['apps'] ?? [];
        $body = opsh_ipol_strip($src);
        if ($kind === 'settings') { $body['name'] = mb_substr($name, 0, 200); unset($body['displayName']); }
        else $body['displayName'] = mb_substr($name, 0, 200);
        if ($kind === 'compliance' && empty($body['scheduledActionsForRule'])) {   // Graph requires it on create
            $body['scheduledActionsForRule'] = [['ruleName' => 'PasswordRequired', 'scheduledActionConfigurations' => [[
                'actionType' => 'block', 'gracePeriodHours' => 168, 'notificationTemplateId' => '', 'notificationMessageCCList' => []]]]];
        }
        $new = opsh_graph_send('POST', $K['path'], $body, $K['ver']);
        $nid = (string)($new['id'] ?? '');
        if ($nid === '') throw new RuntimeException('Microsoft did not return an id for the new policy');
        if (in_array($kind, ['mam_ios', 'mam_android'], true) && $apps) {
            $list = [];
            foreach ($apps as $a) if (!empty($a['mobileAppIdentifier'])) $list[] = ['mobileAppIdentifier' => opsh_ipol_strip($a['mobileAppIdentifier'], 1)];
            if ($list) opsh_graph_send('POST', $K['path'] . '/' . rawurlencode($nid) . '/targetApps', ['apps' => $list], $K['ver']);
        }
        if (!empty($opts['copy_assignments']) && $asg) {
            $tg = [];
            foreach ($asg as $a) if (!empty($a['target'])) $tg[] = ['target' => opsh_ipol_strip($a['target'], 1)];
            try { opsh_graph_send('POST', $K['path'] . '/' . rawurlencode($nid) . '/assign', ['assignments' => $tg], $K['ver']); }
            catch (Throwable $e) { $GLOBALS['OPSH_IPOL_ASSIGN_ERR'] = $e->getMessage(); }   // BT124 (review SEC-20): said, not swallowed
        }
        return $nid;
    }

    /** Back up one policy (returns the backup row id) or every policy (returns the count) under one batch id. */
    function opsh_ipol_backup(PDO $db, int $uid, ?string $kind = null, ?string $id = null): array {
        $batch = gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $ins = $db->prepare('INSERT INTO opsh_m365_policy_backups (batch,kind,policy_id,name,platform,payload,created_by) VALUES (?,?,?,?,?,?,?)');
        $done = 0; $fail = [];
        $targets = [];
        if ($kind && $id) $targets[] = [$kind, $id];
        else {
            foreach (opsh_ipol_kinds() as $k => $K) {
                try { require_once __DIR__ . '/IntuneOps.php'; foreach (opsh_io_all($K['path'] . '?$select=id', $K['ver'], 20) as $p) $targets[] = [$k, (string)$p['id']]; }
                catch (Throwable $e) { $fail[] = $K['label'] . ': ' . $e->getMessage(); }
            }
        }
        foreach ($targets as [$k, $pid]) {
            try {
                $p = opsh_ipol_fetch($k, $pid);
                [$plat] = opsh_ipol_describe($k, $p);
                $ins->execute([$batch, $k, $pid, (string)($p['displayName'] ?? $p['name'] ?? ''), $plat,
                    json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $uid]);
                $done++;
            } catch (Throwable $e) { $fail[] = $pid . ': ' . $e->getMessage(); }
        }
        return ['batch' => $batch, 'saved' => $done, 'failed' => $fail];
    }
}
