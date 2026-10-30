<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT15 device security for COMPANY Windows PCs (Intune + Defender for Business).
 *
 * The compliance baseline (seed_baseline) only CHECKS a device. These profiles CONFIGURE it:
 * antivirus, firewall, attack-surface-reduction rules, BitLocker, Windows Update ring and Windows LAPS.
 * They are always assigned to a DEVICE GROUP chosen by the admin (never "all users"), so personal
 * computers are never targeted. Every profile is independent — unassign one to roll it back.
 *
 * Graph types used (typed schemas, so Microsoft validates every property name):
 *   deviceManagement/deviceConfigurations   windows10GeneralConfiguration          (antivirus)
 *                                           windows10EndpointProtectionConfiguration (firewall · ASR · BitLocker)
 *                                           windowsUpdateForBusinessConfiguration  (update ring)
 *   deviceManagement/configurationPolicies  settings catalog (beta)                (Windows LAPS)
 * Permission: DeviceManagementConfiguration.ReadWrite.All (already needed by the compliance editor).
 * Defender for Business onboarding is NOT created here: it needs the Intune ↔ Defender connector, which is
 * switched on in the Defender portal by an admin (INTUNE-DEFENDER-SETUP.md §2).
 */

if (!function_exists('itops_devsec_catalog')) {

    define('ITOPS_DEVSEC_PREFIX', 'ITOps device security — ');   // the name used before BT124: still recognized
    /** Review SEC-26: profiles are named after the app's own name (Settings → Branding). */
    function itops_devsec_prefix(): string { return (function_exists('itops_app_name') ? itops_app_name() : 'ITOps') . ' device security — '; }

    /** ASR rules on the endpoint-protection profile: [property, label, protection-type?, block-ready?] */
    function itops_devsec_asr_rules(): array {
        return [
            ['defenderPreventCredentialStealingType',              'Block credential stealing from LSASS',                            true,  true],
            ['defenderEmailContentExecutionType',                  'Block executable content from email and webmail',                 false, true],
            ['defenderOfficeAppsExecutableContentCreationOrLaunchType', 'Block Office apps from creating executable content',         false, true],
            ['defenderOfficeAppsOtherProcessInjectionType',        'Block Office apps from injecting code into other processes',      false, true],
            ['defenderOfficeAppsLaunchChildProcessType',           'Block Office apps from creating child processes',                 false, true],
            ['defenderScriptDownloadedPayloadExecutionType',       'Block JavaScript/VBScript from launching downloaded content',     false, true],
            ['defenderScriptObfuscatedMacroCodeType',              'Block potentially obfuscated scripts',                            false, true],
            ['defenderOfficeMacroCodeAllowWin32ImportsType',       'Block Win32 API calls from Office macros',                        false, true],
            ['defenderUntrustedUSBProcessType',                    'Block untrusted and unsigned processes from USB',                 false, true],
            ['defenderAdvancedRansomewareProtectionType',          'Advanced protection against ransomware',                          true,  true],
            ['defenderUntrustedExecutableType',                    'Executables that fail prevalence / age / trusted list → warn',    false, 'warn'],
            ['defenderProcessCreationType',                        'Process creation from PSExec and WMI (stays Audit — IT tools)',   false, false],
        ];
    }

    /** Build the ASR / network-protection properties for a mode: 'audit' | 'enforce'. */
    function itops_devsec_asr_props(string $mode): array {
        $p = [];
        foreach (itops_devsec_asr_rules() as [$prop, , $isProt, $ready]) {
            if ($mode !== 'enforce' || $ready === false) $p[$prop] = 'auditMode';
            elseif ($ready === 'warn') $p[$prop] = 'warn';
            else $p[$prop] = $isProt ? 'enable' : 'block';
        }
        $p['defenderNetworkProtectionType'] = $mode === 'enforce' ? 'enable' : 'auditMode';
        return $p;
    }

    /**
     * Every profile ITOps creates: key => [label, kind ('configuration' | 'settings'), what it does, payload builder].
     * Names are fixed (ITOPS_DEVSEC_PREFIX + label) — that is how the status check and re-seeding find them.
     */
    function itops_devsec_catalog(): array {
        $recovery = ['recoveryPasswordUsage' => 'allowed', 'recoveryKeyUsage' => 'allowed', 'hideRecoveryOptions' => true,
                     'enableRecoveryInformationSaveToStore' => true, 'recoveryInformationToStore' => 'passwordAndKey',
                     'enableBitLockerAfterRecoveryInformationToStore' => true, 'blockDataRecoveryAgent' => true];
        $fw = ['firewallEnabled' => 'allowed', 'inboundConnectionsBlocked' => true, 'outboundConnectionsBlocked' => false,
               'policyRulesFromGroupPolicyMerged' => true, 'authorizedApplicationRulesFromGroupPolicyMerged' => true,
               'globalPortRulesFromGroupPolicyMerged' => true, 'connectionSecurityRulesFromGroupPolicyMerged' => true];
        return [
            'antivirus' => ['Antivirus', 'configuration',
                'Real-time, behaviour and cloud protection (High), blocks unwanted apps, daily quick scan, signatures every 4 h.',
                fn(string $mode) => ['@odata.type' => '#microsoft.graph.windows10GeneralConfiguration',
                    'defenderRequireRealTimeMonitoring' => true, 'defenderRequireBehaviorMonitoring' => true,
                    'defenderRequireCloudProtection' => true, 'defenderRequireNetworkInspectionSystem' => true,
                    'defenderCloudBlockLevel' => 'high', 'defenderPotentiallyUnwantedAppAction' => 'block',
                    'defenderPromptForSampleSubmission' => 'promptBeforeSendingPersonalData',
                    'defenderScanArchiveFiles' => true, 'defenderScanIncomingMail' => true, 'defenderScanNetworkFiles' => true,
                    'defenderScanRemovableDrivesDuringFullScan' => true, 'defenderScanDownloads' => true,
                    'defenderMonitorFileActivity' => 'monitorAllFiles', 'defenderScanType' => 'quick',
                    'defenderSystemScanSchedule' => 'everyday', 'defenderScheduledQuickScanTime' => '12:00:00.0000000',
                    'defenderSignatureUpdateIntervalInHours' => 4, 'defenderBlockEndUserAccess' => false]],
            'firewall' => ['Firewall', 'configuration',
                'Windows Firewall on for domain, private and public networks; unsolicited inbound connections blocked.',
                fn(string $mode) => ['@odata.type' => '#microsoft.graph.windows10EndpointProtectionConfiguration',
                    'firewallProfileDomain' => $fw, 'firewallProfilePrivate' => $fw, 'firewallProfilePublic' => $fw]],
            'asr' => ['Attack surface reduction', 'configuration',
                'Blocks common malware tricks (Office macros, script downloads, password theft, USB). Starts in AUDIT: nothing is blocked, detections are only reported.',
                fn(string $mode) => ['@odata.type' => '#microsoft.graph.windows10EndpointProtectionConfiguration'] + itops_devsec_asr_props($mode)],
            'bitlocker' => ['BitLocker', 'configuration',
                'Encrypts the disk silently (TPM only — nothing extra to type) and saves the recovery key to Entra before encrypting.',
                fn(string $mode) => ['@odata.type' => '#microsoft.graph.windows10EndpointProtectionConfiguration',
                    'bitLockerEncryptDevice' => true, 'bitLockerDisableWarningForOtherDiskEncryption' => true,
                    'bitLockerAllowStandardUserEncryption' => true, 'bitLockerRecoveryPasswordRotation' => 'enabledForAzureAd',
                    'bitLockerSystemDrivePolicy' => ['encryptionMethod' => 'xtsAes256', 'startupAuthenticationRequired' => true,
                        'startupAuthenticationBlockWithoutTpmChip' => true, 'startupAuthenticationTpmUsage' => 'allowed',
                        'startupAuthenticationTpmPinUsage' => 'blocked', 'startupAuthenticationTpmKeyUsage' => 'blocked',
                        'startupAuthenticationTpmPinAndKeyUsage' => 'blocked', 'recoveryOptions' => $recovery],
                    'bitLockerFixedDrivePolicy' => ['encryptionMethod' => 'xtsAes256', 'requireEncryptionForWriteAccess' => false,
                        'recoveryOptions' => $recovery]]],
            'updates' => ['Windows Update ring', 'configuration',
                'Quality updates after 3 days, feature updates after 60; installs outside 7:00–19:00; restart deadline 7 days (+2 grace).',
                fn(string $mode) => ['@odata.type' => '#microsoft.graph.windowsUpdateForBusinessConfiguration',
                    'microsoftUpdateServiceAllowed' => true, 'driversExcluded' => false, 'businessReadyUpdatesOnly' => 'userDefined',
                    'automaticUpdateMode' => 'autoInstallAtMaintenanceTime',
                    'installationSchedule' => ['@odata.type' => '#microsoft.graph.windowsUpdateActiveHoursInstall',
                        'activeHoursStart' => '07:00:00.0000000', 'activeHoursEnd' => '19:00:00.0000000'],
                    'qualityUpdatesDeferralPeriodInDays' => 3, 'featureUpdatesDeferralPeriodInDays' => 60,
                    'qualityUpdatesPaused' => false, 'featureUpdatesPaused' => false,
                    'deadlineForQualityUpdatesInDays' => 7, 'deadlineForFeatureUpdatesInDays' => 14, 'deadlineGracePeriodInDays' => 2,
                    'postponeRebootUntilAfterDeadline' => false, 'userPauseAccess' => 'enabled', 'userWindowsUpdateScanAccess' => 'enabled',
                    'updateNotificationLevel' => 'defaultNotifications', 'allowWindows11Upgrade' => true]],
            'laps' => ['Local admin passwords (LAPS)', 'settings',
                'Gives every PC its own 20-character local administrator password, rotated every 30 days and stored in Entra.',
                fn(string $mode) => ['platforms' => 'windows10', 'technologies' => 'mdm', 'roleScopeTagIds' => ['0'],
                    'settings' => [
                        itops_devsec_choice('device_vendor_msft_laps_policies_backupdirectory', 'device_vendor_msft_laps_policies_backupdirectory_1',
                            [itops_devsec_int('device_vendor_msft_laps_policies_passwordagedays_aad', 30)]),
                        itops_devsec_choice('device_vendor_msft_laps_policies_passwordcomplexity', 'device_vendor_msft_laps_policies_passwordcomplexity_4'),
                        itops_devsec_setting(itops_devsec_int('device_vendor_msft_laps_policies_passwordlength', 20)),
                        itops_devsec_choice('device_vendor_msft_laps_policies_postauthenticationactions', 'device_vendor_msft_laps_policies_postauthenticationactions_3'),
                        itops_devsec_setting(itops_devsec_int('device_vendor_msft_laps_policies_postauthenticationresetdelay', 4)),
                    ]]],
        ];
    }

    /* Settings-catalog helpers. */
    function itops_devsec_int(string $def, int $v): array {
        return ['@odata.type' => '#microsoft.graph.deviceManagementConfigurationSimpleSettingInstance', 'settingDefinitionId' => $def,
                'simpleSettingValue' => ['@odata.type' => '#microsoft.graph.deviceManagementConfigurationIntegerSettingValue', 'value' => $v]];
    }
    function itops_devsec_setting(array $instance): array {
        return ['@odata.type' => '#microsoft.graph.deviceManagementConfigurationSetting', 'settingInstance' => $instance];
    }
    function itops_devsec_choice(string $def, string $value, array $children = []): array {
        return itops_devsec_setting(['@odata.type' => '#microsoft.graph.deviceManagementConfigurationChoiceSettingInstance', 'settingDefinitionId' => $def,
            'choiceSettingValue' => ['@odata.type' => '#microsoft.graph.deviceManagementConfigurationChoiceSettingValue', 'value' => $value, 'children' => $children]]);
    }

    /** Existing ITOps device-security profiles by key: key => ['id','kind','name','assigned'=>[groupIds],'asr_mode'?]. */
    function itops_devsec_existing(): array {
        $cat = itops_devsec_catalog(); $byName = [];
        foreach ($cat as $k => $c) { $byName[ITOPS_DEVSEC_PREFIX . $c[0]] = $k; $byName[itops_devsec_prefix() . $c[0]] = $k; }
        $out = [];
        foreach (itops_graph_paged('deviceManagement/deviceConfigurations?$top=200&$expand=assignments', 3) as $p) {
            $n = (string)($p['displayName'] ?? ''); if (!isset($byName[$n])) continue;
            $k = $byName[$n];
            $row = ['id' => (string)($p['id'] ?? ''), 'kind' => 'configuration', 'name' => $n, 'assigned' => itops_devsec_groups($p['assignments'] ?? []), 'excluded' => itops_devsec_groups($p['assignments'] ?? [], true), 'raw' => (array)($p['assignments'] ?? [])];
            if ($k === 'asr') $row['asr_mode'] = in_array((string)($p['defenderEmailContentExecutionType'] ?? ''), ['block', 'enable'], true) ? 'enforce' : 'audit';
            $out[$k] = $row;
        }
        try {
            $r = itops_graph_get('deviceManagement/configurationPolicies?$top=200&$select=id,name&$expand=assignments', 'beta');
            foreach (($r['value'] ?? []) as $p) {
                $n = (string)($p['name'] ?? ''); if (!isset($byName[$n])) continue;
                $out[$byName[$n]] = ['id' => (string)($p['id'] ?? ''), 'kind' => 'settings', 'name' => $n, 'assigned' => itops_devsec_groups($p['assignments'] ?? []), 'excluded' => itops_devsec_groups($p['assignments'] ?? [], true), 'raw' => (array)($p['assignments'] ?? [])];
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    /** BT124 (review SEC-6): the groups a profile is assigned to ($excluded = false) or excludes ($excluded = true). */
    function itops_devsec_groups(array $asg, bool $excluded = false): array {
        $g = [];
        foreach ($asg as $a) { $t = $a['target'] ?? []; $isEx = stripos((string)($t['@odata.type'] ?? ''), 'exclusion') !== false;
            if ($isEx !== $excluded) continue;
            if (!empty($t['groupId'])) $g[] = (string)$t['groupId'];
            elseif (stripos((string)($t['@odata.type'] ?? ''), 'allDevices') !== false) $g[] = 'all_devices';
            elseif (stripos((string)($t['@odata.type'] ?? ''), 'allLicensedUsers') !== false) $g[] = 'all_users'; }
        return $g;
    }

    /** Create one profile and assign it to $groupId. Returns the new id. */
    function itops_devsec_create(string $key, string $mode, string $groupId): string {
        $c = itops_devsec_catalog()[$key] ?? null; if (!$c) throw new RuntimeException('Unknown device-security item: ' . $key);
        [$label, $kind, $what, $build] = $c;
        $name = itops_devsec_prefix() . $label;
        $desc = 'Created by ' . (function_exists('itops_app_name') ? itops_app_name() : 'ITOps') . ' — ' . $what . ' Company PCs only; unassign to roll back.';
        $target = ['@odata.type' => '#microsoft.graph.groupAssignmentTarget', 'groupId' => $groupId];
        if ($kind === 'settings') {
            $r = itops_graph_send('POST', 'deviceManagement/configurationPolicies', ['name' => $name, 'description' => $desc] + $build($mode), 'beta');
            $nid = (string)($r['id'] ?? '');
            if ($nid !== '') itops_graph_send('POST', 'deviceManagement/configurationPolicies/' . rawurlencode($nid) . '/assign', ['assignments' => [['target' => $target]]], 'beta');
        } else {
            $r = itops_graph_send('POST', 'deviceManagement/deviceConfigurations', ['displayName' => $name, 'description' => $desc] + $build($mode));
            $nid = (string)($r['id'] ?? '');
            if ($nid !== '') itops_graph_send('POST', 'deviceManagement/deviceConfigurations/' . rawurlencode($nid) . '/assign', ['assignments' => [['target' => $target]]]);
        }
        if ($nid === '') throw new RuntimeException('Microsoft did not return an id');
        return $nid;
    }

    /** Add a group to an existing profile's assignments (keeps the groups it already has). */
    function itops_devsec_add_group(array $ex, string $groupId): bool {
        if (in_array($groupId, $ex['assigned'], true)) return false;
        // BT124 (review SEC-6): Intune replaces the whole set on /assign, so every existing assignment is sent back as it
        // is (exclusions stay exclusions, assignment filters stay) and the new group is added
        if (in_array($groupId, $ex['excluded'] ?? [], true)) throw new RuntimeException('This group is excluded from "' . $ex['name'] . '" in Intune: remove the exclusion there first if it should get it.');
        $targets = [];
        foreach ((array)($ex['raw'] ?? []) as $a) {
            $t = (array)($a['target'] ?? []); if (!$t) continue;
            $keep = array_intersect_key($t, array_flip(['@odata.type', 'groupId', 'deviceAndAppManagementAssignmentFilterId', 'deviceAndAppManagementAssignmentFilterType']));
            $targets[] = ['target' => $keep];
        }
        $targets[] = ['target' => ['@odata.type' => '#microsoft.graph.groupAssignmentTarget', 'groupId' => $groupId]];
        if ($ex['kind'] === 'settings') itops_graph_send('POST', 'deviceManagement/configurationPolicies/' . rawurlencode($ex['id']) . '/assign', ['assignments' => $targets], 'beta');
        else itops_graph_send('POST', 'deviceManagement/deviceConfigurations/' . rawurlencode($ex['id']) . '/assign', ['assignments' => $targets]);
        return true;
    }

    /**
     * Enrolment guard-rail: can a PERSONAL Windows PC enrol into Intune? Returns
     * ['personal_blocked' => true|false|null, 'mdm_scope' => 'all'|'selected'|'none'|null, 'personal_windows' => [...], 'notes' => [...]].
     */
    function itops_devsec_enrollment(): array {
        $out = ['personal_blocked' => null, 'restriction' => '', 'mdm_scope' => null, 'personal_windows' => [], 'notes' => []];
        try {
            $r = itops_graph_get('deviceManagement/deviceEnrollmentConfigurations', 'beta');
            $blockedDefault = null; $blockedSpecific = false;
            foreach (($r['value'] ?? []) as $c) {
                $t = (string)($c['@odata.type'] ?? '');
                if (stripos($t, 'deviceEnrollmentPlatformRestrictionsConfiguration') !== false && isset($c['windowsRestriction'])) {
                    $blockedDefault = !empty($c['windowsRestriction']['personalDeviceEnrollmentBlocked']) || !empty($c['windowsRestriction']['platformBlocked']);
                    $out['restriction'] = (string)($c['displayName'] ?? 'Default');
                } elseif (stripos($t, 'deviceEnrollmentPlatformRestrictionConfiguration') !== false
                          && strtolower((string)($c['platformType'] ?? '')) === 'windows'
                          && !empty($c['platformRestriction']['personalDeviceEnrollmentBlocked'])) {
                    $blockedSpecific = true; $out['restriction'] = (string)($c['displayName'] ?? 'Windows restriction');
                }
            }
            $out['personal_blocked'] = $blockedDefault === true || $blockedSpecific ? true : ($blockedDefault === null && !$blockedSpecific ? null : false);
            if ($blockedSpecific && $blockedDefault !== true) $out['notes'][] = 'A Windows-specific restriction blocks personal PCs — confirm it is assigned to all users.';
        } catch (\Throwable $e) {
            $out['notes'][] = 'Enrolment restrictions need DeviceManagementServiceConfig.Read.All (' . mb_substr($e->getMessage(), 0, 120) . ').';
        }
        try {
            $m = itops_graph_get('policies/mobileDeviceManagementPolicies/0000000a-0000-0000-c000-000000000000', 'beta');
            $out['mdm_scope'] = strtolower((string)($m['appliesTo'] ?? '')) ?: null;
        } catch (\Throwable $e) { $out['notes'][] = 'MDM user scope could not be read — check it in Entra → Devices → Device settings (or Mobility → Microsoft Intune).'; }
        try {
            foreach (itops_graph_paged('deviceManagement/managedDevices?$top=200&$select=id,deviceName,userDisplayName,userPrincipalName,operatingSystem,managedDeviceOwnerType,lastSyncDateTime,model,manufacturer', 3) as $d) {
                if (stripos((string)($d['operatingSystem'] ?? ''), 'windows') === false) continue;
                if (strtolower((string)($d['managedDeviceOwnerType'] ?? '')) !== 'personal') continue;
                $out['personal_windows'][] = ['id' => $d['id'] ?? '', 'name' => $d['deviceName'] ?? '', 'user' => $d['userDisplayName'] ?? ($d['userPrincipalName'] ?? ''),
                    'model' => trim(($d['manufacturer'] ?? '') . ' ' . ($d['model'] ?? '')), 'last_sync' => substr((string)($d['lastSyncDateTime'] ?? ''), 0, 10)];
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    /** Defender for Endpoint ↔ Intune connector (the fixed Microsoft connector id). null = unknown. */
    function itops_devsec_defender_connected(): ?bool {
        try {
            $r = itops_graph_get('deviceManagement/mobileThreatDefenseConnectors');
            foreach (($r['value'] ?? []) as $c) {
                if (strtolower((string)($c['id'] ?? '')) !== 'fc780465-2017-40d4-a0c5-307022471b92') continue;
                return strtolower((string)($c['partnerState'] ?? '')) === 'enabled' && (!array_key_exists('windowsEnabled', $c) || !empty($c['windowsEnabled']));
            }
            return false;
        } catch (\Throwable $e) { return null; }
    }
}
