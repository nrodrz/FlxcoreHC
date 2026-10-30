<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — Microsoft 365 admin (WRITE tier).
 * Routes: /api/m365_admin/{action}. Gated by the 'm365_admin' permission module,
 * separate from the read-only 'm365' view module.
 *
 * Actions (all reversible; there is deliberately NO hard-delete of users):
 *   POST /api/m365_admin/create_user   onboard a user (+ optional license)
 *   POST /api/m365_admin/set_enabled   enable / disable (offboard) a user
 *   POST /api/m365_admin/license       add / remove a license
 *
 * Every action is audit-logged, written to the operational Event Log, and emits a
 * webhook event. Disabling an account requires a typed confirmation.
 *
 * Requires Graph WRITE Application permissions (User.ReadWrite.All /
 * Directory.ReadWrite.All) with admin consent — see INSTALL notes.
 */

require_once __DIR__ . '/../lib/Graph.php';
require_once __DIR__ . '/../lib/EventLog.php';

// BT21: sign-in methods and Temporary Access Pass moved into Microsoft 365 → Accounts (the credentials
// permission); everything else here stays on "M365 Admin" (tenant configuration: policies, rollout, CA…).
require_once __DIR__ . '/../lib/ReadStore.php';
itops_rs_drop_on_change();   // BT72: a change here drops the Microsoft 365 reads kept on the server
$__credSubs = ['user_methods', 'remove_method', 'issue_tap'];
if (!(in_array((string)($segments[1] ?? ''), $__credSubs, true) && itops_can($currentUser, 'm365_credentials', 'edit'))) {
    itops_require($currentUser, 'm365_admin', 'edit');
}

if (!itops_graph_configured()) error_response('Microsoft 365 is not configured', 400);

$action = $id; // /api/m365_admin/{action}
$body   = json_decode(file_get_contents('php://input'), true) ?: [];

/**
 * BT14 — privileged-target guard. Account-takeover actions (password reset, Temporary Access Pass, MFA reset / method
 * removal, disable, sign-out) against a Microsoft 365 account that holds an ADMIN directory role are Super Admin only.
 * Fails closed for non-Super callers when the roles can't be read. Secret-returning actions are never available to
 * API keys.
 */
require_once __DIR__ . '/../lib/M365Guard.php';
$guardPriv = function (string $upn, bool $returnsSecret = false) use ($currentUser) {   // BT88 (code audit M-31): the same guard as the hub — roles through groups and PIM count
    itops_m365_guard_target($currentUser, $upn, 'do this', $returnsSecret);
};

/** Self-lockout guard: an admin must not disable / de-MFA / sign out their OWN account from here. */
$guardSelf = function (string $upn) use ($currentUser) {
    $upn = strtolower(trim($upn)); if ($upn === '') return;
    if ($upn === strtolower((string)($currentUser['email'] ?? ''))) {
        error_response('This is your own account — ask another administrator (this prevents locking yourself out).', 400);
    }
    if (!empty($currentUser['o365_oid'])) {
        try { $u = itops_graph_get('users/' . rawurlencode($upn) . '?$select=id');
              if (strcasecmp((string)($u['id'] ?? ''), (string)$currentUser['o365_oid']) === 0)
                  error_response('This is your own account — ask another administrator (this prevents locking yourself out).', 400);
        } catch (\Throwable $e) {}
    }
};

$fire = function ($event, $payload) {
    $f = __DIR__ . '/../lib/Integrations.php';
    if (is_file($f)) { require_once $f; if (function_exists('itops_fire_event')) { try { itops_fire_event($event, $payload); } catch (\Throwable $e) {} } }
};

/** Create/update a compliance policy from curated fields; returns the policy id. Used by the
 *  compliance_policy_save action and the baseline seeder, so both build identical payloads. */
function itops_intune_policy_save(array $b): string {
    $plat = (string)($b['platform'] ?? '');
    $TYPES = ['windows' => '#microsoft.graph.windows10CompliancePolicy', 'ios' => '#microsoft.graph.iosCompliancePolicy',
              'android' => '#microsoft.graph.androidWorkProfileCompliancePolicy', 'macos' => '#microsoft.graph.macOSCompliancePolicy'];
    if (!isset($TYPES[$plat])) throw new RuntimeException('platform must be windows | ios | android | macos');
    $name = trim((string)($b['name'] ?? '')); if ($name === '') throw new RuntimeException('A policy name is required');
    $S = is_array($b['settings'] ?? null) ? $b['settings'] : [];
    $pwKey  = $plat === 'ios' ? 'passcodeRequired' : 'passwordRequired';
    $lenKey = $plat === 'ios' ? 'passcodeMinimumLength' : 'passwordMinimumLength';
    $pl = ['@odata.type' => $TYPES[$plat], 'displayName' => mb_substr($name, 0, 200), 'description' => mb_substr((string)($b['description'] ?? ''), 0, 500)];
    $pl[$pwKey] = !empty($S['password']);
    if (!empty($S['password']) && (int)($S['min_length'] ?? 0) > 0) $pl[$lenKey] = min(16, max(4, (int)$S['min_length']));
    if (!empty($S['os_min'])) $pl['osMinimumVersion'] = mb_substr((string)$S['os_min'], 0, 40);
    if ($plat === 'windows') { $pl['bitLockerEnabled'] = !empty($S['encryption']); $pl['defenderEnabled'] = !empty($S['defender']);
        // BT15: Defender machine risk score — a PC with an active threat above this level becomes non-compliant.
        $risk = (string)($S['defender_risk'] ?? '');
        if (in_array($risk, ['secured', 'low', 'medium', 'high'], true)) { $pl['deviceThreatProtectionEnabled'] = true; $pl['deviceThreatProtectionRequiredSecurityLevel'] = $risk; }
        elseif (array_key_exists('defender_risk', $S)) { $pl['deviceThreatProtectionEnabled'] = false; $pl['deviceThreatProtectionRequiredSecurityLevel'] = 'unavailable'; } }
    elseif ($plat === 'macos') { $pl['storageRequireEncryption'] = !empty($S['encryption']); }
    elseif ($plat === 'android') { $pl['storageRequireEncryption'] = !empty($S['encryption']); $pl['securityBlockJailbrokenDevices'] = !empty($S['no_jailbreak']); }
    elseif ($plat === 'ios') { $pl['securityBlockJailbrokenDevices'] = !empty($S['no_jailbreak']); }
    $grace = max(0, min(8760, (int)($b['grace_hours'] ?? 168)));
    $sched = [['ruleName' => 'PasswordRequired', 'scheduledActionConfigurations' => [[
        'actionType' => 'block', 'gracePeriodHours' => $grace, 'notificationTemplateId' => '', 'notificationMessageCCList' => []]]]];
    $pid = trim((string)($b['id'] ?? ''));
    if ($pid !== '') {
        // Editing: keep the policy's OWN type (e.g. an Android fully-managed policy is not a work-profile one)
        // and only send properties that type really has — otherwise Microsoft rejects the whole save.
        $cur = itops_graph_get('deviceManagement/deviceCompliancePolicies/' . rawurlencode($pid));
        $keep = ['displayName' => 1, 'description' => 1];
        foreach ($pl as $k => $v) if ($k !== '@odata.type' && !isset($keep[$k]) && !array_key_exists($k, $cur)) unset($pl[$k]);
        $pl['@odata.type'] = (string)($cur['@odata.type'] ?? $pl['@odata.type']);
    }
    if ($pid === '') {
        $pl['scheduledActionsForRule'] = $sched;   // Graph REQUIRES this on create
        $r = itops_graph_send('POST', 'deviceManagement/deviceCompliancePolicies', $pl);
        $pid = (string)($r['id'] ?? '');
    } else {
        itops_graph_send('PATCH', 'deviceManagement/deviceCompliancePolicies/' . rawurlencode($pid), $pl);
        try { itops_graph_send('POST', 'deviceManagement/deviceCompliancePolicies/' . rawurlencode($pid) . '/scheduleActionsForRules',
            ['deviceComplianceScheduledActionForRules' => $sched]); }
        catch (\Throwable $e) { if (function_exists('itops_log')) itops_log('warning', 'm365', 'Compliance policy saved, but its grace period / actions were not: ' . $e->getMessage(), ['policy' => $pid]); $GLOBALS['ITOPS_POLICY_WARN'] = 'The policy was saved, but Microsoft did not take its grace period and actions: ' . $e->getMessage(); }   // BT88: said, not hidden
    }
    if ($pid !== '' && array_key_exists('assign', $b)) {
        $tg = [];
        foreach ((array)$b['assign'] as $a3) {
            if (($a3['type'] ?? '') === 'all_devices') $tg[] = ['target' => ['@odata.type' => '#microsoft.graph.allDevicesAssignmentTarget']];
            elseif (($a3['type'] ?? '') === 'all_users') $tg[] = ['target' => ['@odata.type' => '#microsoft.graph.allLicensedUsersAssignmentTarget']];
            elseif (!empty($a3['id'])) $tg[] = ['target' => ['@odata.type' => '#microsoft.graph.groupAssignmentTarget', 'groupId' => (string)$a3['id']]];
        }
        itops_graph_send('POST', 'deviceManagement/deviceCompliancePolicies/' . rawurlencode($pid) . '/assign', ['assignments' => $tg]);
    }
    return $pid;
}

try {
    switch ($action) {

        case 'create_user':
        case 'set_enabled':
        case 'reset_password':
        case 'reset_mfa':
        case 'revoke_sessions':
        case 'license':
        case 'bulk_remove_licenses': {
            // BT124 (review M365B-6): the account actions live in Microsoft 365 → People (their own permissions and guards).
            // These older copies answered to "Tenant configuration" alone; no screen used them any more.
            audit_log('m365_refused', 'm365', null, 'Microsoft 365 account', null, ['Wanted' => $action, 'Why' => 'Retired: done from Microsoft 365 → People.']);
            error_response('This action moved to Microsoft 365 → People, under its own permission.', 410);
        }
        case 'intune_action': {
            // Device actions, deliberately limited to the two SAFE ones:
            //   sync — ask the device to check in and re-evaluate compliance (harmless);
            //   lock — remote-lock the screen (for a lost/stolen device; unlocked with the user's PIN).
            // Retire and wipe are destructive and stay in the Intune admin center on purpose.
            $devId = trim((string)($body['device_id'] ?? ''));
            $act   = (string)($body['action'] ?? '');
            $name  = trim((string)($body['device_name'] ?? $devId));
            if ($devId === '' || !in_array($act, ['sync', 'lock'], true)) error_response('A device_id and an action (sync | lock) are required');
            if ($act === 'lock' && ($body['confirm'] ?? '') !== 'LOCK') error_response('Type LOCK to confirm locking this device');
            if ($act === 'lock') {   // BT15: personal devices are voluntary — IT never acts on one, not even a lock.
                $own = '';
                try { $own = strtolower((string)(itops_graph_get('deviceManagement/managedDevices/' . rawurlencode($devId) . '?$select=managedDeviceOwnerType')['managedDeviceOwnerType'] ?? '')); } catch (\Throwable $e) {}
                // Review M365B-16: when the owner cannot be read, nothing is sent (the check fails closed)
                if (!in_array($own, ['company', 'personal'], true)) { audit_log('m365_admin_target_refused', 'm365', null, (string)$devId, null, ['wanted' => 'remote lock', 'reason' => 'the device owner could not be read']); error_response('Whether this device is the company\'s could not be read from Intune, so it was not locked. Try again.', 409); }
                if ($own === 'personal') error_response('This is a personal device — ' . itops_app_name() . ' never locks, wipes or changes a device the company does not own. If it should not be in Intune, delete its record in the Intune admin center (that does not touch the device).', 400);
            }
            itops_graph_send('POST', 'deviceManagement/managedDevices/' . rawurlencode($devId) . '/' . ($act === 'sync' ? 'syncDevice' : 'remoteLock'), []);
            audit_log($act === 'sync' ? 'intune_device_synced' : 'intune_device_locked', 'm365', null, $name);
            itops_log($act === 'sync' ? 'info' : 'warning', 'm365', ($act === 'sync' ? 'Requested Intune sync for ' : 'Remote-locked device ') . $name, ['by' => $currentUser['display_name'] ?? '']);
            $fire('m365.intune_' . $act, ['device_id' => $devId, 'device' => $name]);
            json_response(['ok' => true, 'note' => $act === 'sync'
                ? 'Sync requested — the device checks in the next time it is online (compliance can take a few minutes to update).'
                : 'Lock sent. The device locks when it receives the command; the user unlocks it with their own PIN/password.']);
            break;
        }

        case 'resolve_alert': {
            // Close the loop on a Defender alert from ITOps (needs SecurityAlert.ReadWrite.All).
            $aid    = trim((string)($body['alert_id'] ?? ''));
            $status = (string)($body['status'] ?? 'resolved');
            if ($aid === '' || !in_array($status, ['resolved', 'inProgress'], true)) error_response('An alert_id and a status (resolved | inProgress) are required');
            itops_graph_send('PATCH', 'security/alerts_v2/' . rawurlencode($aid), ['status' => $status]);
            audit_log('m365_alert_' . ($status === 'resolved' ? 'resolved' : 'in_progress'), 'm365', null, $aid);
            itops_log('info', 'm365', 'Defender alert ' . $aid . ' set to ' . $status, ['by' => $currentUser['display_name'] ?? '']);
            $fire('m365.alert_updated', ['alert_id' => $aid, 'status' => $status]);
            json_response(['ok' => true]);
            break;
        }

        case 'compliance_policy_save': {
            // Create / update an Intune compliance policy — CURATED fields only, so the editor
            // can't produce a policy that bricks a fleet. Needs DeviceManagementConfiguration.ReadWrite.All.
            $pid = itops_intune_policy_save($body);
            audit_log('intune_policy_saved', 'm365', null, ($body['name'] ?? '') . ' (' . ($body['platform'] ?? '') . ')');
            itops_log('info', 'm365', 'Intune compliance policy saved: ' . ($body['name'] ?? ''), ['platform' => $body['platform'] ?? '', 'by' => $currentUser['display_name'] ?? '']);
            $fire('m365.intune_policy_saved', ['id' => $pid, 'name' => $body['name'] ?? '', 'platform' => $body['platform'] ?? '']);
            json_response(['ok' => true, 'id' => $pid]);
            break;
        }

        case 'compliance_policy_delete': {
            $pid = trim((string)($body['id'] ?? '')); if ($pid === '') error_response('A policy id is required');
            if (($body['confirm'] ?? '') !== 'DELETE') error_response('Type DELETE to confirm removing this policy');
            if (!preg_match('/^[A-Za-z0-9\-]{1,64}$/', $pid)) error_response('That policy id is not valid.', 400);
            // Review M365B-21: a copy is kept first (Devices → policy backups), so the policy can be put back
            require_once __DIR__ . '/../lib/IntunePolicies.php';
            $bk = itops_ipol_backup(getDB(), (int)$currentUser['id'], 'compliance', $pid);
            if (empty($bk['saved'])) { audit_log('m365_admin_target_refused', 'm365', null, $pid, null, ['wanted' => 'delete a compliance policy', 'reason' => 'no backup could be made']); error_response('A copy of the policy could not be kept, so it was not deleted.', 409); }
            itops_graph_send('DELETE', 'deviceManagement/deviceCompliancePolicies/' . rawurlencode($pid));
            audit_log('intune_policy_deleted', 'm365', null, $pid, null, ['backup' => $bk['batch']]);
            itops_log('warning', 'm365', 'Intune compliance policy deleted', ['id' => $pid, 'by' => $currentUser['display_name'] ?? '']);
            json_response(['ok' => true, 'backup' => $bk['batch']]);
            break;
        }

        case 'seed_baseline': {
            // One-click BYOD-aware baseline. Compliance policies (assigned to ALL DEVICES — only
            // enrolled, i.e. company, devices are affected) + app protection for personal phones
            // (assigned to ALL USERS: protects Outlook/Teams/OneDrive data with no enrolment).
            // Gentle defaults: 7-day grace before a device is blocked; MAM allows Save-as/print.
            $made = []; $skippedB = [];
            $seedGrace = ($body['mode'] ?? 'report') === 'enforce' ? 168 : 8760;   // report = mark non-compliant, never block
            $seedGroup = trim((string)($body['group_id'] ?? ''));
            $seedAssign = $seedGroup !== '' ? [['type' => 'group', 'id' => $seedGroup]] : [['type' => 'all_devices']];
            $mamTarget = $seedGroup !== '' ? ['@odata.type' => '#microsoft.graph.groupAssignmentTarget', 'groupId' => $seedGroup]
                                            : ['@odata.type' => '#microsoft.graph.allLicensedUsersAssignmentTarget'];
            $have = [];
            try { foreach ((itops_graph_get('deviceManagement/deviceCompliancePolicies')['value'] ?? []) as $p2) $have[] = (string)($p2['displayName'] ?? ''); }
            catch (\Throwable $e) { error_response('Could not read the compliance policies that already exist, so nothing was created (it could make doubles): ' . $e->getMessage(), 502); }   // BT88: no silent failure
            $APPN = itops_app_name();   // review OPS-18 / SEC-26: objects in the tenant carry the app's own name
            $BASE = [
                ['windows', $APPN . ' baseline — Windows', ['password' => true, 'min_length' => 6, 'encryption' => true, 'defender' => true, 'os_min' => '10.0.19045']],
                ['ios',     $APPN . ' baseline — iOS/iPadOS', ['password' => true, 'min_length' => 6, 'no_jailbreak' => true]],
                ['android', $APPN . ' baseline — Android', ['password' => true, 'min_length' => 6, 'encryption' => true, 'no_jailbreak' => true]],
                ['macos',   $APPN . ' baseline — macOS', ['password' => true, 'min_length' => 6, 'encryption' => true]],
            ];
            foreach ($BASE as [$plat2, $nm, $st]) {
                if (in_array($nm, $have, true) || in_array(str_replace($APPN . ' baseline', 'ITOps baseline', $nm), $have, true)) { $skippedB[] = $nm; continue; }   // the name used before BT124 counts too
                try {
                    itops_intune_policy_save(['platform' => $plat2, 'name' => $nm,
                        'description' => $seedGrace >= 8760 ? 'Seeded by ' . $APPN . ' — REPORT ONLY (marks non-compliant, never blocks). Switch to "Block after 7 days" when enforcing.'
                                                            : 'Seeded by ' . $APPN . ' — gentle baseline; 7-day grace before block.',
                        'settings' => $st, 'grace_hours' => $seedGrace, 'assign' => $seedAssign]);
                    $made[] = $nm . ' → created';
                } catch (\Throwable $e) { $made[] = $nm . ' FAILED: ' . $e->getMessage(); }
            }
            // App protection (MAM) for personal phones
            $APPS_IOS = ['com.microsoft.Office.Outlook', 'com.microsoft.skype.teams', 'com.microsoft.skydrive', 'com.microsoft.Office.Word', 'com.microsoft.Office.Excel', 'com.microsoft.Office.Powerpoint', 'com.microsoft.msedge'];
            $APPS_AND = ['com.microsoft.office.outlook', 'com.microsoft.teams', 'com.microsoft.skydrive', 'com.microsoft.office.word', 'com.microsoft.office.excel', 'com.microsoft.office.powerpoint', 'com.microsoft.emmx'];
            foreach ([['ios', 'iosManagedAppProtections', '#microsoft.graph.iosMobileAppIdentifier', 'bundleId', $APPS_IOS],
                      ['android', 'androidManagedAppProtections', '#microsoft.graph.androidMobileAppIdentifier', 'packageId', $APPS_AND]] as [$plat3, $seg, $idType, $idKey, $apps]) {
                $nm = $APPN . ' app protection — ' . ($plat3 === 'ios' ? 'iOS' : 'Android'); $nmOld = 'ITOps app protection — ' . ($plat3 === 'ios' ? 'iOS' : 'Android');
                try {
                    $ex = itops_graph_get('deviceAppManagement/' . $seg);
                    if (array_filter($ex['value'] ?? [], fn($x) => in_array(($x['displayName'] ?? ''), [$nm, $nmOld], true))) { $skippedB[] = $nm; continue; }
                    $mp = itops_graph_send('POST', 'deviceAppManagement/' . $seg, [
                        'displayName' => $nm, 'description' => 'Seeded by ' . $APPN . ' — protects work data in Microsoft apps on personal phones (no enrolment).',
                        'pinRequired' => true, 'minimumPinLength' => 4, 'dataBackupBlocked' => true,
                        'allowedOutboundDataTransferDestinations' => 'managedApps', 'allowedInboundDataTransferSources' => 'allApps',
                        'allowedOutboundClipboardSharingLevel' => 'managedAppsWithPasteIn', 'saveAsBlocked' => false,
                        'printBlocked' => false, 'contactSyncBlocked' => false, 'periodOfflineBeforeWipeIsEnforced' => 'P90D']);
                    $mid = (string)($mp['id'] ?? '');
                    if ($mid !== '') {
                        itops_graph_send('POST', 'deviceAppManagement/' . $seg . '/' . rawurlencode($mid) . '/targetApps',
                            ['apps' => array_map(fn($b2) => ['mobileAppIdentifier' => ['@odata.type' => $idType, $idKey => $b2]], $apps)]);
                        itops_graph_send('POST', 'deviceAppManagement/' . $seg . '/' . rawurlencode($mid) . '/assign',
                            ['assignments' => [['target' => $mamTarget]]]);
                    }
                    $made[] = $nm . ' → created';
                } catch (\Throwable $e) { $made[] = $nm . ' FAILED: ' . $e->getMessage(); }
            }
            audit_log('intune_baseline_seeded', 'm365', null, count($made) . ' created, ' . count($skippedB) . ' already existed');
            itops_log('info', 'm365', 'Business Premium baseline seeded', ['made' => $made, 'skipped' => $skippedB, 'by' => $currentUser['display_name'] ?? '']);
            $fire('m365.baseline_seeded', ['made' => $made, 'skipped' => $skippedB]);
            json_response(['ok' => true, 'made' => $made, 'skipped' => $skippedB]);
            break;
        }

        case 'seed_device_security': {
            // BT15 — configure COMPANY PCs: antivirus, firewall, ASR rules, BitLocker, update ring, LAPS.
            // Always assigned to one DEVICE GROUP (never all users / all devices), so personal PCs are never targeted.
            // Existing ITOps profiles are not overwritten: they get the group added to their assignments instead.
            require_once __DIR__ . '/../lib/DeviceSecurity.php';
            $gid = trim((string)($body['group_id'] ?? ''));
            if (!preg_match('/^[0-9a-f-]{36}$/i', $gid)) error_response('Choose the device group to assign these to (the pilot group first, then the company-PCs group).');
            $mode = ($body['mode'] ?? 'audit') === 'enforce' ? 'enforce' : 'audit';
            $cat = itops_devsec_catalog();
            $want = array_values(array_intersect(array_keys($cat), array_map('strval', (array)($body['items'] ?? array_keys($cat)))));
            if (!$want) error_response('Pick at least one item');
            $have = []; try { $have = itops_devsec_existing(); } catch (\Throwable $e) {}
            $made = []; $added = []; $failed = [];
            foreach ($want as $k) {
                $label = itops_devsec_prefix() . $cat[$k][0];
                try {
                    if (isset($have[$k])) { if (itops_devsec_add_group($have[$k], $gid)) $added[] = $label; else $added[] = $label . ' (already assigned)'; }
                    else { itops_devsec_create($k, $mode, $gid); $made[] = $label; }
                } catch (\Throwable $e) { $failed[] = $label . ': ' . mb_substr($e->getMessage(), 0, 240); }
            }
            audit_log('device_security_seeded', 'm365', null, count($made) . ' created, ' . count($added) . ' assigned, ' . count($failed) . ' failed · ' . $mode . ' · group ' . $gid);
            itops_log($failed ? 'warning' : 'info', 'm365', 'Device security seeded', ['made' => $made, 'assigned' => $added, 'failed' => $failed, 'mode' => $mode, 'group' => $gid, 'by' => $currentUser['display_name'] ?? '']);
            $fire('m365.device_security_seeded', ['made' => $made, 'assigned' => $added, 'failed' => $failed, 'mode' => $mode]);
            json_response(['ok' => !$failed, 'made' => $made, 'assigned' => $added, 'failed' => $failed]);
            break;
        }

        case 'device_security_asr_mode': {
            // BT15 — switch the ITOps attack-surface-reduction profile between AUDIT and BLOCK (block-ready rules only).
            require_once __DIR__ . '/../lib/DeviceSecurity.php';
            $mode = (string)($body['mode'] ?? '');
            if (!in_array($mode, ['audit', 'enforce'], true)) error_response('mode must be audit or enforce');
            if ($mode === 'enforce' && ($body['confirm'] ?? '') !== 'BLOCK') error_response('Type BLOCK to confirm — review the ASR detections report first');
            $have = itops_devsec_existing();
            if (empty($have['asr'])) error_response('The ' . itops_app_name() . ' attack-surface-reduction profile does not exist yet — seed device security first.', 404);
            itops_graph_send('PATCH', 'deviceManagement/deviceConfigurations/' . rawurlencode($have['asr']['id']),
                ['@odata.type' => '#microsoft.graph.windows10EndpointProtectionConfiguration'] + itops_devsec_asr_props($mode));
            audit_log('device_security_asr_' . $mode, 'm365', null, $have['asr']['name']);
            itops_log('warning', 'm365', 'Attack surface reduction set to ' . ($mode === 'enforce' ? 'BLOCK' : 'AUDIT'), ['by' => $currentUser['display_name'] ?? '']);
            $fire('m365.device_security_asr_mode', ['mode' => $mode]);
            json_response(['ok' => true, 'mode' => $mode, 'note' => $mode === 'enforce'
                ? 'Block-ready rules now BLOCK (PSExec/WMI stays Audit; unknown executables WARN). PCs pick it up at their next check-in.'
                : 'All rules back to AUDIT — nothing is blocked, detections are only reported.']);
            break;
        }

        case 'disable_rule': {
            // Turn off a suspicious inbox rule found by the mailbox audit.
            $uid = trim((string)($body['user_id'] ?? '')); $rid = trim((string)($body['rule_id'] ?? ''));
            if ($uid === '' || $rid === '') error_response('user_id and rule_id are required');
            itops_graph_send('PATCH', 'users/' . rawurlencode($uid) . '/mailFolders/inbox/messageRules/' . rawurlencode($rid), ['isEnabled' => false]);
            audit_log('mailbox_rule_disabled', 'm365', null, ($body['upn'] ?? $uid) . ' · ' . ($body['rule'] ?? $rid));
            itops_log('warning', 'm365', 'Disabled inbox rule for ' . ($body['upn'] ?? $uid), ['rule' => $body['rule'] ?? $rid, 'by' => $currentUser['display_name'] ?? '']);
            $fire('m365.mailbox_rule_disabled', ['upn' => $body['upn'] ?? '', 'rule' => $body['rule'] ?? '']);
            json_response(['ok' => true, 'note' => 'Rule disabled. If this looks like a compromise: reset the password, sign the user out everywhere and reset MFA.']);
            break;
        }

        case 'send_enrollment': {
            // Email people their enrolment steps (templates "Set up your work computer/phone",
            // editable under Connection → Email templates).
            $kind = ($body['kind'] ?? '') === 'phone' ? 'phone' : 'computer';
            $upns = array_values(array_filter(array_map('trim', (array)($body['upns'] ?? []))));
            if (!$upns) error_response('At least one recipient (upns) is required');
            if (count($upns) > 100) error_response('Send to at most 100 people at a time');
            require_once __DIR__ . '/../lib/EmailTemplates.php';
            $sent = 0; $fails = [];
            foreach ($upns as $to) {
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $fails[] = $to . ' (not an email)'; continue; }
                $nm2 = '';
                try { $g = itops_graph_get('users/' . rawurlencode($to) . '?$select=displayName'); $nm2 = (string)($g['displayName'] ?? ''); } catch (\Throwable $e) {}
                $vars2 = ['note' => mb_substr((string)($body['note'] ?? ''), 0, 500)];
                if ($nm2 !== '') $vars2['recipient_name'] = $nm2;   // else itops_email fills it from the ITOps user
                $r = itops_email($kind === 'phone' ? 'device_enroll_phone' : 'device_enroll_computer', $to, $vars2);
                if (!empty($r['ok'])) $sent++; else $fails[] = $to . ' (' . ($r['error'] ?? 'send failed') . ')';
            }
            audit_log('enrollment_invites_sent', 'm365', null, $kind . ' × ' . $sent);
            itops_log('info', 'm365', 'Enrolment invitations sent (' . $kind . ')', ['sent' => $sent, 'failed' => count($fails), 'by' => $currentUser['display_name'] ?? '']);
            json_response(['ok' => true, 'sent' => $sent, 'failed' => $fails]);
            break;
        }

        case 'mam_save': {
            // Edit an app-protection (phone) policy: curated fields + assignment.
            $plat = ($body['platform'] ?? '') === 'android' ? 'android' : 'ios';
            $seg = $plat === 'android' ? 'androidManagedAppProtections' : 'iosManagedAppProtections';
            $mid = trim((string)($body['id'] ?? '')); if ($mid === '') error_response('A policy id is required');
            $S = is_array($body['settings'] ?? null) ? $body['settings'] : [];
            $pl = ['@odata.type' => $plat === 'android' ? '#microsoft.graph.androidManagedAppProtection' : '#microsoft.graph.iosManagedAppProtection'];
            if (isset($body['name']) && trim((string)$body['name']) !== '') $pl['displayName'] = mb_substr(trim((string)$body['name']), 0, 200);
            if (array_key_exists('pin', $S)) $pl['pinRequired'] = !empty($S['pin']);
            if (!empty($S['pin_length'])) $pl['minimumPinLength'] = min(8, max(4, (int)$S['pin_length']));
            if (array_key_exists('block_backup', $S)) $pl['dataBackupBlocked'] = !empty($S['block_backup']);
            if (array_key_exists('block_save_as', $S)) $pl['saveAsBlocked'] = !empty($S['block_save_as']);
            if (array_key_exists('block_print', $S)) $pl['printBlocked'] = !empty($S['block_print']);
            if (!empty($S['clipboard']) && in_array($S['clipboard'], ['allApps', 'managedAppsWithPasteIn', 'managedApps', 'blocked'], true)) $pl['allowedOutboundClipboardSharingLevel'] = $S['clipboard'];
            if (!empty($S['wipe_after_days'])) $pl['periodOfflineBeforeWipeIsEnforced'] = 'P' . min(365, max(1, (int)$S['wipe_after_days'])) . 'D';
            itops_graph_send('PATCH', 'deviceAppManagement/' . $seg . '/' . rawurlencode($mid), $pl);
            if (!empty($body['assign']) && in_array($body['assign']['type'] ?? '', ['all_users', 'group'], true)) {
                $t = ($body['assign']['type'] === 'group' && !empty($body['assign']['id']))
                    ? ['@odata.type' => '#microsoft.graph.groupAssignmentTarget', 'groupId' => (string)$body['assign']['id']]
                    : ['@odata.type' => '#microsoft.graph.allLicensedUsersAssignmentTarget'];
                itops_graph_send('POST', 'deviceAppManagement/' . $seg . '/' . rawurlencode($mid) . '/assign', ['assignments' => [['target' => $t]]]);
            }
            audit_log('intune_mam_saved', 'm365', null, ($pl['displayName'] ?? $mid) . ' (' . $plat . ')');
            itops_log('info', 'm365', 'App protection policy saved', ['id' => $mid, 'platform' => $plat, 'by' => $currentUser['display_name'] ?? '']);
            json_response(['ok' => true]);
            break;
        }

        case 'policy_backup': {
            require_once __DIR__ . '/../lib/IntunePolicies.php';
            $k = isset($body['kind']) ? (string)$body['kind'] : null; $pid = isset($body['id']) ? (string)$body['id'] : null;
            if ($k !== null && !isset(itops_ipol_kinds()[$k])) error_response('Unknown policy kind');
            try { $r = itops_ipol_backup(getDB(), (int)$currentUser['id'], $k, $pid); }
            catch (\Throwable $e) { if (stripos($e->getMessage(), 'itops_m365_policy_backups') !== false) error_response('Run db-update.php once (signed in as Super Admin) to enable backups.', 400); throw $e; }
            audit_log('intune_policies_backed_up', 'm365', null, $r['batch'] . ' · ' . $r['saved'] . ' saved');
            itops_log('info', 'm365', 'Intune policies backed up', ['batch' => $r['batch'], 'saved' => $r['saved'], 'failed' => count($r['failed']), 'by' => $currentUser['display_name'] ?? '']);
            json_response(['ok' => true] + $r);
            break;
        }

        case 'policy_restore':
        case 'policy_clone': {
            // Always creates a NEW policy (never overwrites). Restore = from a stored backup; clone = from a live policy.
            require_once __DIR__ . '/../lib/IntunePolicies.php';
            if ($action === 'policy_restore') {
                $st = getDB()->prepare('SELECT * FROM itops_m365_policy_backups WHERE id=?'); $st->execute([(int)($body['backup_id'] ?? 0)]);
                $row = $st->fetch(); if (!$row) error_response('Backup not found', 404);
                $kind = (string)$row['kind']; $src = json_decode((string)$row['payload'], true) ?: [];
                $name = trim((string)($body['name'] ?? '')) ?: ($row['name'] . ' (restored ' . date('Y-m-d') . ')');
            } else {
                $kind = (string)($body['kind'] ?? ''); if (!isset(itops_ipol_kinds()[$kind])) error_response('Unknown policy kind');
                $src = itops_ipol_fetch($kind, (string)($body['id'] ?? ''));
                $name = trim((string)($body['name'] ?? '')) ?: ((string)($src['displayName'] ?? $src['name'] ?? 'Policy') . ' (copy)');
            }
            $nid = itops_ipol_create_from($kind, $src, ['name' => $name, 'copy_assignments' => !empty($body['copy_assignments'])]);
            audit_log($action === 'policy_restore' ? 'intune_policy_restored' : 'intune_policy_cloned', 'm365', null, $name . ' (' . $kind . ')');
            itops_log('info', 'm365', ($action === 'policy_restore' ? 'Restored ' : 'Cloned ') . 'Intune policy as "' . $name . '"', ['kind' => $kind, 'id' => $nid, 'by' => $currentUser['display_name'] ?? '']);
            $fire('m365.intune_policy_created', ['id' => $nid, 'name' => $name, 'kind' => $kind, 'from' => $action]);
            json_response(['ok' => true, 'id' => $nid, 'name' => $name,
                'note' => empty($body['copy_assignments']) ? 'Created UNASSIGNED — assign it (and unassign the old one) in the editor or the Intune admin center when ready.' : (!empty($GLOBALS['ITOPS_IPOL_ASSIGN_ERR']) ? 'Created, but its assignments could not be copied (' . $GLOBALS['ITOPS_IPOL_ASSIGN_ERR'] . '): assign it in the editor or the Intune admin center.' : 'Created with the same assignments as the original.')]);
            break;
        }

        case 'ca_create':
        case 'ca_set_state':
        case 'security_defaults': {
            // BT124 (review M365B-1): these older Conditional Access actions skipped the second person, the emergency-account
            // rule and the change record. Every change is made in Security → Conditional Access (m365hub ca-change) now.
            audit_log('m365_refused', 'm365', null, 'Conditional Access', null, ['Wanted' => $action, 'Why' => 'Retired: changes are made in Microsoft 365 → Security → Conditional Access.']);
            error_response('This action moved to Microsoft 365 → Security → Conditional Access, where changes start report-only, leave the emergency accounts out and can be put back.', 410);
        }
        case 'auth_method_set': {
            // Allow / stop a sign-in (MFA) method tenant-wide. Needs Policy.ReadWrite.AuthenticationMethod.
            $mid = (string)($body['id'] ?? ''); $on = !empty($body['enabled']);
            if (!isset(itops_auth_method_meta()[$mid])) error_response('Unknown sign-in method');
            if (!$on && ($body['confirm'] ?? '') !== 'OFF') error_response('Type OFF to confirm — people using this method will have to pick another one.');
            if (!$on && $mid === 'temporaryAccessPass') {} // allowed, but the UI warns: lost-phone recovery then needs Reset MFA
            $cur = itops_graph_get('policies/authenticationMethodsPolicy/authenticationMethodConfigurations/' . $mid);
            $type = (string)($cur['@odata.type'] ?? ('#microsoft.graph.' . $mid . 'AuthenticationMethodConfiguration'));
            $pl = ['@odata.type' => $type, 'state' => $on ? 'enabled' : 'disabled'];
            // Enabling a method that targets nobody would do nothing — target all users in that case.
            if ($on && empty($cur['includeTargets'])) $pl['includeTargets'] = [['targetType' => 'group', 'id' => 'all_users', 'isRegistrationRequired' => false]];
            itops_graph_send('PATCH', 'policies/authenticationMethodsPolicy/authenticationMethodConfigurations/' . $mid, $pl);
            audit_log('auth_method_' . ($on ? 'enabled' : 'disabled'), 'm365', null, $mid);
            itops_log('warning', 'm365', 'Sign-in method ' . $mid . ' ' . ($on ? 'allowed' : 'turned off'), ['by' => $currentUser['display_name'] ?? '']);
            $fire('m365.auth_method', ['id' => $mid, 'enabled' => $on]);
            json_response(['ok' => true]);
            break;
        }

        case 'user_methods': {
            // A person's registered sign-in methods (for "lost my key / new phone"). Phone numbers are masked.
            $upn = trim((string)($body['upn'] ?? '')); if ($upn === '') error_response('A user (upn) is required');
            $am = itops_graph_get('users/' . rawurlencode($upn) . '/authentication/methods');
            $NAMES = ['#microsoft.graph.phoneAuthenticationMethod' => ['Phone (text / call)', 'phoneMethods'],
                '#microsoft.graph.microsoftAuthenticatorAuthenticationMethod' => ['Microsoft Authenticator', 'microsoftAuthenticatorMethods'],
                '#microsoft.graph.fido2AuthenticationMethod' => ['Security key / passkey', 'fido2Methods'],
                '#microsoft.graph.softwareOathAuthenticationMethod' => ['Authenticator app (codes)', 'softwareOathMethods'],
                '#microsoft.graph.temporaryAccessPassAuthenticationMethod' => ['Temporary Access Pass', 'temporaryAccessPassMethods'],
                '#microsoft.graph.windowsHelloForBusinessAuthenticationMethod' => ['Windows Hello', 'windowsHelloForBusinessMethods'],
                '#microsoft.graph.emailAuthenticationMethod' => ['Email', 'emailMethods'],
                '#microsoft.graph.passwordAuthenticationMethod' => ['Password', null]];
            $out = [];
            foreach (($am['value'] ?? []) as $m) {
                $t = (string)($m['@odata.type'] ?? ''); $n = $NAMES[$t] ?? [preg_replace('/^#microsoft\.graph\.|AuthenticationMethod$/', '', $t), null];
                if ($n[1] === null && $t === '#microsoft.graph.passwordAuthenticationMethod') continue;
                $detail = (string)($m['displayName'] ?? $m['model'] ?? '');
                if (!empty($m['phoneNumber'])) { $pn = preg_replace('/\D/', '', (string)$m['phoneNumber']); $detail = '••• ' . substr($pn, -4) . (!empty($m['phoneType']) ? ' (' . $m['phoneType'] . ')' : ''); }
                $out[] = ['id' => (string)($m['id'] ?? ''), 'type' => $n[1], 'name' => $n[0], 'detail' => $detail,
                    'created' => (string)($m['createdDateTime'] ?? ''), 'removable' => $n[1] !== null];
            }
            json_response(['ok' => true, 'value' => $out]);
            break;
        }

        case 'remove_method': {
            // Remove ONE sign-in method — e.g. a lost security key or an old phone.
            $upn = trim((string)($body['upn'] ?? '')); $seg = (string)($body['type'] ?? ''); $mid = (string)($body['id'] ?? '');
            $OK = ['phoneMethods', 'microsoftAuthenticatorMethods', 'fido2Methods', 'softwareOathMethods', 'temporaryAccessPassMethods', 'windowsHelloForBusinessMethods', 'emailMethods'];
            if ($upn === '' || $mid === '' || !in_array($seg, $OK, true)) error_response('upn, type and id are required');
            $guardSelf($upn);
            $guardPriv($upn);
            itops_graph_send('DELETE', 'users/' . rawurlencode($upn) . '/authentication/' . $seg . '/' . rawurlencode($mid));
            audit_log('m365_method_removed', 'm365', null, $upn . ' · ' . $seg . ' · ' . ($body['name'] ?? ''));
            itops_log('warning', 'm365', 'Removed sign-in method for ' . $upn, ['type' => $seg, 'detail' => $body['name'] ?? '', 'by' => $currentUser['display_name'] ?? '']);
            $fire('m365.method_removed', ['upn' => $upn, 'type' => $seg]);
            json_response(['ok' => true]);
            break;
        }

        case 'issue_tap': {
            // Temporary Access Pass: a time-limited pass to register a security key / new phone, or to get back in.
            // The pass is returned ONCE to the caller and never stored or logged by ITOps.
            $upn = trim((string)($body['upn'] ?? '')); if ($upn === '') error_response('A user (upn) is required');
            $guardSelf($upn); $guardPriv($upn, true);
            $mins = max(60, min(480, (int)($body['hours'] ?? 1) * 60));
            $once = !array_key_exists('one_time', $body) || !empty($body['one_time']);
            try { foreach ((itops_graph_get('users/' . rawurlencode($upn) . '/authentication/temporaryAccessPassMethods')['value'] ?? []) as $old)
                    itops_graph_send('DELETE', 'users/' . rawurlencode($upn) . '/authentication/temporaryAccessPassMethods/' . rawurlencode((string)$old['id'])); } catch (\Throwable $e) {}
            try { $r = itops_graph_send('POST', 'users/' . rawurlencode($upn) . '/authentication/temporaryAccessPassMethods', ['lifetimeInMinutes' => $mins, 'isUsableOnce' => $once]); }
            catch (\Throwable $e) { error_response('Could not issue the pass: ' . $e->getMessage() . ' — check that "Temporary Access Pass" is allowed under Microsoft 365 → Entra ID → Access & sign-in → Sign-in methods, and that ' . itops_app_name() . ' has UserAuthenticationMethod.ReadWrite.All.', 400); }
            audit_log('m365_tap_issued', 'm365', null, $upn . ' (' . ($mins / 60) . 'h' . ($once ? ', one-time' : '') . ')');
            itops_log('warning', 'm365', 'Temporary Access Pass issued for ' . $upn, ['hours' => $mins / 60, 'one_time' => $once, 'by' => $currentUser['display_name'] ?? '']);
            $fire('m365.tap_issued', ['upn' => $upn, 'hours' => $mins / 60]);
            json_response(['ok' => true, 'pass' => (string)($r['temporaryAccessPass'] ?? ''), 'hours' => $mins / 60, 'one_time' => $once,
                'starts' => (string)($r['startDateTime'] ?? ''), 'note' => 'Give it to the person IN PERSON or by phone — never by email or chat. They sign in at aka.ms/mysecurityinfo with their work email and this pass, then add their security key or Authenticator.']);
            break;
        }

        case 'mailsec_evidence': {
            // BT14: record the assessed state of an Exchange Online control (Assess → Implement → Verify → Evidence).
            $ctl = preg_replace('/[^a-z0-9_]/', '', (string)($body['control'] ?? ''));
            $OKC = ['dkim', 'forwarding', 'smtp_auth', 'owa_unmanaged', 'preset_policies'];
            $OKS = ['compliant', 'needs_change', 'exception', 'not_applicable', 'not_assessed'];
            $stt = (string)($body['status'] ?? '');
            if (!in_array($ctl, $OKC, true) || !in_array($stt, $OKS, true)) error_response('control and status are required');
            $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 2000);
            if (in_array($stt, ['exception', 'not_applicable'], true) && $note === '') error_response('Explain the exception / why it does not apply — it becomes the audit evidence.');
            $db2 = getDB();
            $cur = $db2->query("SELECT sval FROM itops_settings WHERE skey='mailsec_evidence'")->fetchColumn();
            $ev = $cur ? (json_decode($cur, true) ?: []) : [];
            if ($stt === 'not_assessed') unset($ev[$ctl]);
            else $ev[$ctl] = ['status' => $stt, 'note' => $note, 'by' => $currentUser['display_name'] ?? '', 'at' => gmdate('c')];
            $db2->prepare("INSERT INTO itops_settings (skey,sval,updated_by) VALUES ('mailsec_evidence',?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval),updated_by=VALUES(updated_by)")
                ->execute([json_encode($ev), $currentUser['id']]);
            audit_log('mailsec_evidence', 'm365', null, $ctl . ' → ' . $stt, null, ['note' => mb_substr($note, 0, 500)]);
            json_response(['ok' => true, 'value' => $ev ?: new stdClass()]);
            break;
        }

        case 'rollout_step': {
            // Tick / untick a rollout checklist step (who + when recorded).
            $sid = preg_replace('/[^a-z0-9_.-]/i', '', (string)($body['id'] ?? '')); if ($sid === '') error_response('A step id is required');
            $db2 = getDB();
            $v = $db2->query("SELECT sval FROM itops_settings WHERE skey='m365_rollout'")->fetchColumn();
            $st = $v ? (json_decode($v, true) ?: []) : [];
            if (!isset($st['steps']) || !is_array($st['steps'])) $st['steps'] = [];
            if (!empty($body['done'])) $st['steps'][$sid] = ['done' => true, 'by' => $currentUser['display_name'] ?? '', 'at' => gmdate('c'), 'note' => mb_substr((string)($body['note'] ?? ''), 0, 300)];
            elseif (!empty($body['skipped'])) {   // a deliberate "not now / never" decision, with the reason
                $why = trim((string)($body['note'] ?? '')); if ($why === '') error_response('Say why this step is skipped — it becomes the record of the decision.');
                $st['steps'][$sid] = ['skipped' => true, 'by' => $currentUser['display_name'] ?? '', 'at' => gmdate('c'), 'note' => mb_substr($why, 0, 300)];
            }
            else unset($st['steps'][$sid]);
            $db2->prepare("INSERT INTO itops_settings (skey,sval,updated_by) VALUES ('m365_rollout',?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval),updated_by=VALUES(updated_by)")
                ->execute([json_encode($st), $currentUser['id']]);
            audit_log('rollout_step_' . (!empty($body['done']) ? 'done' : (!empty($body['skipped']) ? 'skipped' : 'undone')), 'm365', null, $sid . (!empty($body['skipped']) ? ' — ' . mb_substr((string)($body['note'] ?? ''), 0, 120) : ''));
            json_response(['ok' => true, 'steps' => $st['steps'] ?: new stdClass()]);
            break;
        }

        case 'send_rollout': {
            // Send one rollout email to an audience: all | managers | group | incomplete | emails.
            $ALLOWED = ['rollout_announce', 'rollout_manager_brief', 'mfa_setup_request', 'mfa_enforce_notice', 'personal_computer_notice',
                        'rollout_reminder', 'device_enforce_notice', 'rollout_complete', 'device_enroll_computer', 'device_enroll_phone', 'security_key_setup'];
            $tpl = (string)($body['template'] ?? ''); if (!in_array($tpl, $ALLOWED, true)) error_response('Unknown rollout email');
            $aud = is_array($body['audience'] ?? null) ? $body['audience'] : [];
            $type = (string)($aud['type'] ?? '');
            $to = [];
            $licensed = function () { return array_values(array_filter(itops_graph_paged('users?$top=500&$select=id,displayName,userPrincipalName,mail,accountEnabled,assignedLicenses', 4),
                fn($u) => !empty($u['accountEnabled']) && !empty($u['assignedLicenses']))); };
            if ($type === 'all') { foreach ($licensed() as $u) $to[strtolower((string)($u['mail'] ?: $u['userPrincipalName']))] = $u['displayName'] ?? ''; }
            elseif ($type === 'managers') {
                foreach (itops_graph_paged('users?$top=500&$select=id,accountEnabled&$expand=manager($select=displayName,mail,userPrincipalName)', 4) as $u) {
                    if (empty($u['accountEnabled']) || empty($u['manager'])) continue;
                    $m = $u['manager']; $e = strtolower((string)(($m['mail'] ?? '') ?: ($m['userPrincipalName'] ?? ''))); if ($e !== '') $to[$e] = (string)($m['displayName'] ?? '');
                }
            } elseif ($type === 'group') {
                $gid = trim((string)($aud['group_id'] ?? '')); if ($gid === '') error_response('Choose a group');
                foreach (itops_graph_paged('groups/' . rawurlencode($gid) . '/members?$top=500&$select=displayName,mail,userPrincipalName', 4) as $m) {
                    $e = strtolower((string)(($m['mail'] ?? '') ?: ($m['userPrincipalName'] ?? ''))); if ($e !== '' && strpos($e, '@') !== false) $to[$e] = (string)($m['displayName'] ?? '');
                }
            } elseif ($type === 'emails') {
                // BT88: only addresses of the organization's own verified domains (the rollout emails speak in its name)
                $doms = []; try { foreach ((itops_graph_get('domains?$select=id,isVerified')['value'] ?? []) as $d2) if (!empty($d2['isVerified'])) $doms[] = strtolower((string)$d2['id']); } catch (\Throwable $e) {}
                if (!$doms) error_response('Could not read the organization\'s domains, so typed addresses cannot be checked.', 502);
                $outside = [];
                foreach ((array)($aud['emails'] ?? []) as $e) { $e = strtolower(trim((string)$e)); if (!filter_var($e, FILTER_VALIDATE_EMAIL)) continue; if (!in_array(substr(strrchr($e, '@'), 1), $doms, true)) { $outside[] = $e; continue; } $to[$e] = ''; }
                if ($outside) error_response('Rollout emails go only to the organization\'s own addresses (' . implode(', ', $doms) . '). Not allowed: ' . implode(', ', array_slice($outside, 0, 5)) . (count($outside) > 5 ? '…' : '') . '.', 400);
            } elseif ($type === 'incomplete') {
                $miss = (string)($aud['missing'] ?? 'mfa');
                $users = $licensed();
                if ($miss === 'mfa') {
                    $reg = []; foreach (itops_graph_paged('reports/authenticationMethods/userRegistrationDetails?$top=500', 4) as $r2) if (!empty($r2['isMfaRegistered'])) $reg[strtolower((string)$r2['userPrincipalName'])] = 1;
                    foreach ($users as $u) if (empty($reg[strtolower((string)$u['userPrincipalName'])])) $to[strtolower((string)($u['mail'] ?: $u['userPrincipalName']))] = $u['displayName'] ?? '';
                } elseif ($miss === 'phone') {
                    $has = []; foreach (itops_graph_paged('deviceAppManagement/managedAppRegistrations?$top=500&$select=userId', 3) as $m2) $has[(string)($m2['userId'] ?? '')] = 1;
                    foreach ($users as $u) if (empty($has[(string)$u['id']])) $to[strtolower((string)($u['mail'] ?: $u['userPrincipalName']))] = $u['displayName'] ?? '';
                } else error_response('missing must be mfa or phone (company PCs are booked individually)');
            } else error_response('Choose an audience');
            if (!$to) error_response('Nobody matches that audience', 400);
            if (count($to) > 500) error_response('More than 500 recipients — narrow the audience', 400);
            if (!empty($body['preview'])) { json_response(['ok' => true, 'count' => count($to), 'sample' => array_slice(array_keys($to), 0, 15)]); break; }
            require_once __DIR__ . '/../lib/EmailTemplates.php';
            $vars = [];
            foreach (['start_date', 'deadline'] as $dk) if (!empty($body['vars'][$dk])) $vars[$dk] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$body['vars'][$dk]) ? itops_tpl_d2((string)$body['vars'][$dk]) : mb_substr((string)$body['vars'][$dk], 0, 60);
            foreach (['task', 'sender_name', 'note'] as $tk) if (isset($body['vars'][$tk])) $vars[$tk] = mb_substr((string)$body['vars'][$tk], 0, 500);
            $sent = 0; $fails = [];
            foreach ($to as $email => $nm2) {
                $v2 = $vars; if ($nm2 !== '') $v2['recipient_name'] = $nm2;
                $r = itops_email($tpl, $email, $v2);
                if (!empty($r['ok'])) $sent++; else $fails[] = $email . ' (' . ($r['error'] ?? 'failed') . ')';
            }
            audit_log('rollout_email_sent', 'm365', null, $tpl . ' → ' . $type . ' × ' . $sent);
            itops_log('info', 'm365', 'Rollout email "' . $tpl . '" sent', ['audience' => $type, 'sent' => $sent, 'failed' => count($fails), 'by' => $currentUser['display_name'] ?? '']);
            json_response(['ok' => true, 'sent' => $sent, 'failed' => array_slice($fails, 0, 50)]);
            break;
        }

        default:
            error_response('Unknown Microsoft 365 admin action', 404);
    }
} catch (Throwable $e) {
    error_response('Microsoft 365 action failed: ' . $e->getMessage(), 502);
}
