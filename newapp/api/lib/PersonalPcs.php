<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub BT134 — personal computers: protected with Microsoft Defender, by choice, without Intune enrollment.
 *
 * The person receives a link (public page pc.html). There they read what the organization will and will not see,
 * agree by typing their name, and run a read-only check that reports their computer's Windows edition and Defender
 * state. Then:
 *   · Windows Pro / Enterprise / Education and Mac: they download the Defender onboarding package the administrator
 *     uploaded, and run it. The computer joins Defender for Endpoint, and Intune's endpoint security policies
 *     (antivirus, firewall) reach it through Defender's "security settings management" — the computer is NOT enrolled
 *     in Intune: no apps, no wipe, no access to files.
 *   · Windows Home cannot join Defender for Endpoint (Microsoft lists only Pro, Enterprise and Education). The person
 *     gets the check of their built-in Defender and how to turn it on; the check can be run again at any time.
 *   · Withdrawing: the person withdraws on the same page; the offboarding package (when uploaded) is offered there.
 *
 * Microsoft facts this rests on (checked 10/25/2026, learn.microsoft.com): security settings management applies
 * Intune endpoint security policies (Antivirus, Firewall, ASR, EDR) to devices onboarded to Defender and not enrolled in
 * Intune; Windows 10/11 Pro and Enterprise, macOS, Linux; policies are assigned to Entra DEVICE groups (dynamic rule
 * device.managementType -eq "MicrosoftSense"); the tenant switches are in the Defender portal (Settings → Endpoints →
 * Configuration management → Enforcement scope) and in Intune (Endpoint security → Microsoft Defender for Endpoint →
 * "Allow Microsoft Defender for Endpoint to enforce Endpoint Security Configurations"). Neither switch has an API, so
 * the screen asks the administrator to confirm each one (recorded).
 *
 * Policies are created from the tenant's own templates (configurationPolicyTemplates, family endpointSecurityAntivirus /
 * endpointSecurityFirewall), found when a policy is created, so no template or setting id is written into this code
 * beyond the setting names of Microsoft's Defender and Firewall CSPs. A setting the template does not have is skipped
 * and reported. Permission: DeviceManagementConfiguration.ReadWrite.All (already used), Group.ReadWrite.All (group).
 */
require_once __DIR__ . '/Secrets.php';
if (!function_exists('opsh_pc_table')) {
    if (!defined('OPSH_PC_LINK_DAYS')) define('OPSH_PC_LINK_DAYS', 60);

    function opsh_pc_table(PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS opsh_pc_people (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL,
            person_name VARCHAR(190) NOT NULL DEFAULT '',
            token_hash CHAR(64) NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'invited',
            invited_by INT UNSIGNED NULL, invited_at DATETIME NOT NULL, expires_at DATETIME NOT NULL,
            consent_at DATETIME NULL, consent_name VARCHAR(190) NULL, consent_ip VARCHAR(45) NULL, consent_version INT NULL,
            report MEDIUMTEXT NULL, reported_at DATETIME NULL, reports INT NOT NULL DEFAULT 0,
            path VARCHAR(10) NULL, package_at DATETIME NULL,
            withdrawn_at DATETIME NULL, withdraw_note VARCHAR(300) NULL,
            UNIQUE KEY uq_token (token_hash), KEY k_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    function opsh_pc_ready(PDO $db): bool { try { $db->query('SELECT 1 FROM opsh_pc_people LIMIT 1'); return true; } catch (Throwable $e) { return false; } }

    // ───────────────────────────── the program's settings ─────────────────────────────

    /** The text the person agrees to. Plain text; {{org}} becomes the organization's name. */
    function opsh_pc_default_consent(): string {
        return "Protecting my own computer with Microsoft Defender — {{org}}\n\n"
            . "I choose to protect my personal computer, which I use for work, with Microsoft Defender managed by {{org}}.\n\n"
            . "What {{org}} will do:\n"
            . "• Turn on and keep Microsoft Defender Antivirus and the Windows Firewall on my computer, with the settings it chooses (for example real-time protection, cloud protection, scans, definitions updates).\n"
            . "• Receive security alerts about threats found on my computer, and the computer's name, Windows version and the software versions Defender uses to find vulnerabilities.\n\n"
            . "What {{org}} will not do:\n"
            . "• Enroll my computer in Intune, install its apps, wipe or reset it, or read my files, photos, email or browsing.\n\n"
            . "What Microsoft Defender does on my computer:\n"
            . "• It records security activity (programs started, files changed, network connections) to detect attacks. Security staff of {{org}} can see that activity in Microsoft Defender when they look into an alert, and can disconnect the computer from the network during an attack.\n\n"
            . "I can withdraw at any time on this page. {{org}} then gives me the file that removes Defender's connection; Microsoft Defender Antivirus stays on my computer as part of Windows.";
    }
    function opsh_pc_cfg(PDO $db): array {
        $v = []; try { $q = $db->prepare("SELECT sval FROM opsh_settings WHERE skey='pc_program'"); $q->execute(); $v = json_decode((string)$q->fetchColumn(), true) ?: []; } catch (Throwable $e) {}
        return $v + ['consent' => '', 'consent_version' => 1, 'steps' => [], 'group_id' => '', 'group_name' => '', 'policies' => [], 'av' => opsh_pc_av_defaults(), 'fw' => opsh_pc_fw_defaults()];
    }
    function opsh_pc_cfg_put(PDO $db, array $c): void {
        $db->prepare("INSERT INTO opsh_settings (skey, sval) VALUES ('pc_program', ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)")->execute([json_encode($c, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    }
    function opsh_pc_consent_text(PDO $db): string {
        $c = opsh_pc_cfg($db); $t = trim((string)$c['consent']) !== '' ? (string)$c['consent'] : opsh_pc_default_consent();
        if (!function_exists('opsh_org_name')) @include_once __DIR__ . '/Brand.php';
        $org = ''; try { $org = function_exists('opsh_org_name') ? trim(opsh_org_name($db)) : ''; } catch (Throwable $e) {}
        return str_replace('{{org}}', $org !== '' ? $org : 'the organization', $t);
    }

    /** Onboarding / offboarding packages, kept wrapped (they let a computer join the tenant's Defender). */
    function opsh_pc_pkg_kinds(): array {
        return ['win_on' => ['Windows onboarding package', 'WindowsDefenderATPOnboardingPackage.zip'], 'mac_on' => ['Mac onboarding package', 'WindowsDefenderATPOnboardingPackage.zip'],
                'win_off' => ['Windows offboarding package', 'WindowsDefenderATPOffboardingPackage.zip']];
    }
    function opsh_pc_pkg_get(PDO $db, string $k): ?array {
        try { $q = $db->prepare('SELECT sval FROM opsh_settings WHERE skey=?'); $q->execute(['pc_pkg_' . $k]); $v = json_decode((string)$q->fetchColumn(), true); } catch (Throwable $e) { return null; }
        return is_array($v) && !empty($v['data']) ? $v : null;
    }
    function opsh_pc_pkg_put(PDO $db, string $k, string $name, string $bytes, string $by): void {
        if (!isset(opsh_pc_pkg_kinds()[$k])) throw new InvalidArgumentException('Unknown package.');
        if ($bytes === '' || strlen($bytes) > 2 * 1024 * 1024) throw new InvalidArgumentException('The package must be a file of up to 2 MB.');
        $isZip = substr($bytes, 0, 4) === "PK\x03\x04";
        $isScript = !$isZip && !preg_match('/[\x00-\x08\x0E-\x1F]/', substr($bytes, 0, 4096));
        if (!$isZip && !$isScript) throw new InvalidArgumentException('That is not the package Microsoft gives (a .zip, or its script).');
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '', basename($name)) ?: opsh_pc_pkg_kinds()[$k][1];
        $w = function_exists('opsh_secret_wrap_checked') ? opsh_secret_wrap_checked(base64_encode($bytes)) : null;
        if ($w === null) throw new RuntimeException('This installation cannot store secrets (VAULT_ENCRYPTION_KEY in config.php). The package was not saved.');
        $db->prepare("INSERT INTO opsh_settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)")
           ->execute(['pc_pkg_' . $k, json_encode(['name' => $name, 'size' => strlen($bytes), 'at' => date('c'), 'by' => $by, 'data' => $w])]);
    }
    function opsh_pc_pkg_bytes(array $p): string { return (string)base64_decode(opsh_secret_unwrap((string)$p['data']), true); }
    function opsh_pc_pkg_public(PDO $db): array {
        $o = []; foreach (opsh_pc_pkg_kinds() as $k => [$label]) { $p = opsh_pc_pkg_get($db, $k); $o[$k] = ['label' => $label, 'saved' => (bool)$p, 'name' => $p['name'] ?? '', 'size' => (int)($p['size'] ?? 0), 'at' => $p['at'] ?? null, 'by' => $p['by'] ?? '']; }
        return $o;
    }

    // ───────────────────────────── people and their links ─────────────────────────────

    function opsh_pc_new_token(): string { return bin2hex(random_bytes(24)); }
    function opsh_pc_by_token(PDO $db, string $tok): ?array {
        if (!preg_match('/^[a-f0-9]{48}$/', $tok)) return null;
        $q = $db->prepare('SELECT * FROM opsh_pc_people WHERE token_hash=?'); $q->execute([hash('sha256', $tok)]); $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
    function opsh_pc_row(array $r): array {
        $rep = $r['report'] ? (json_decode((string)$r['report'], true) ?: null) : null;
        return ['id' => (int)$r['id'], 'email' => (string)$r['email'], 'name' => (string)$r['person_name'], 'status' => (string)$r['status'],
                'invited_at' => $r['invited_at'], 'expires_at' => $r['expires_at'], 'consent_at' => $r['consent_at'], 'consent_name' => (string)($r['consent_name'] ?? ''),
                'consent_version' => $r['consent_version'] !== null ? (int)$r['consent_version'] : null, 'report' => $rep, 'reported_at' => $r['reported_at'], 'reports' => (int)$r['reports'],
                'path' => (string)($r['path'] ?? ''), 'package_at' => $r['package_at'], 'withdrawn_at' => $r['withdrawn_at'], 'withdraw_note' => (string)($r['withdraw_note'] ?? ''),
                'expired' => $r['status'] === 'invited' && strtotime((string)$r['expires_at']) < time()];
    }

    /** Which way a reported computer goes: pro (Defender for Endpoint), home (built-in Defender only), unknown. */
    function opsh_pc_path_of(array $rep): string {
        $e = strtolower((string)($rep['edition'] ?? ''));
        if ($e === '') return 'unknown';
        if (preg_match('/^(core|home)/', $e)) return 'home';
        if (preg_match('/^(professional|enterprise|education|pro|iot)/', $e)) return 'pro';
        return 'unknown';
    }

    /**
     * Clean what the check script sent. Only known fields are kept, each in its type; the rest is dropped.
     * Windows Security Center's productState is decoded the way it is commonly read: the middle byte 0x10/0x11 = on,
     * the low byte 0x00 = definitions up to date.
     */
    function opsh_pc_clean_report($in): array {
        if (!is_array($in)) throw new InvalidArgumentException('The check sent nothing usable.');
        $s = fn($v, $n = 120) => is_scalar($v) ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)$v)), 0, $n) : '';
        $b = fn($v) => $v === true || $v === 1 || $v === '1' || (is_string($v) && strtolower($v) === 'true') ? true : (($v === false || $v === 0 || $v === '0' || (is_string($v) && strtolower($v) === 'false')) ? false : null);
        $i = fn($v) => is_numeric($v) ? (int)$v : null;
        $d = is_array($in['defender'] ?? null) ? $in['defender'] : [];
        $out = ['computer' => $s($in['computer'] ?? '', 63), 'os' => $s($in['os'] ?? ''), 'edition' => $s($in['edition'] ?? '', 40), 'build' => $s($in['build'] ?? '', 20),
            'defender' => ['service' => $b($d['AMServiceEnabled'] ?? null), 'antivirus' => $b($d['AntivirusEnabled'] ?? null), 'realtime' => $b($d['RealTimeProtectionEnabled'] ?? null),
                'behavior' => $b($d['BehaviorMonitorEnabled'] ?? null), 'tamper' => $b($d['IsTamperProtected'] ?? null), 'mode' => $s($d['AMRunningMode'] ?? '', 40),
                'signature_age_days' => $i($d['AntivirusSignatureAge'] ?? null), 'quick_scan_age_days' => $i($d['QuickScanAge'] ?? null), 'error' => $s($d['error'] ?? '', 200)],
            'products' => [], 'onboarded' => $b($in['onboarded'] ?? null)];
        foreach (array_slice(is_array($in['products'] ?? null) ? $in['products'] : [], 0, 8) as $p) {
            if (!is_array($p)) continue; $st = $i($p['state'] ?? null); $hex = $st !== null ? sprintf('%06x', $st) : '';
            $out['products'][] = ['name' => $s($p['name'] ?? '', 120), 'on' => $hex !== '' ? in_array(substr($hex, 2, 2), ['10', '11'], true) : null, 'current' => $hex !== '' ? substr($hex, 4, 2) === '00' : null];
        }
        if ($out['computer'] === '' || !preg_match('/^[A-Za-z0-9._-]{1,63}$/', $out['computer'])) throw new InvalidArgumentException('The check did not send a computer name.');
        return $out;
    }

    /** What needs doing on a reported computer, in words. */
    function opsh_pc_problems(array $rep): array {
        $d = $rep['defender'] ?? []; $p = [];
        $others = array_values(array_filter($rep['products'] ?? [], fn($x) => !preg_match('/defender/i', (string)$x['name']) && $x['on']));
        if (($d['antivirus'] ?? null) === false || ($d['service'] ?? null) === false) $p[] = $others ? 'Microsoft Defender is off because ' . $others[0]['name'] . ' is the active antivirus' : 'Microsoft Defender Antivirus is off';
        if (($d['realtime'] ?? null) === false) $p[] = 'Real-time protection is off';
        if (isset($d['signature_age_days']) && $d['signature_age_days'] !== null && $d['signature_age_days'] > 3) $p[] = 'Definitions are ' . $d['signature_age_days'] . ' days old';
        if (($d['tamper'] ?? null) === false) $p[] = 'Tamper protection is off';
        foreach ($rep['products'] ?? [] as $x) if (!preg_match('/defender/i', (string)$x['name']) && $x['on'] && $x['current'] === false) $p[] = $x['name'] . ' is out of date';
        if (!$rep['products'] && ($d['antivirus'] ?? null) === null) $p[] = 'Windows reported no antivirus';
        return $p;
    }

    // ───────────────────────────── the rules pushed to the computers ─────────────────────────────

    /**
     * The antivirus rules the screen edits: key => [CSP setting (device_vendor_msft_policy_config_defender_<key>), label,
     * kind (choice | int | list), options [value => label] for a choice, [min, max] for an int].
     */
    function opsh_pc_av_fields(): array {
        $onoff = [1 => 'On', 0 => 'Off'];
        return [
            'allowrealtimemonitoring' => ['Real-time protection', 'choice', $onoff],
            'allowbehaviormonitoring' => ['Behavior monitoring', 'choice', $onoff],
            'allowcloudprotection' => ['Cloud protection', 'choice', $onoff],
            'cloudblocklevel' => ['Cloud protection level', 'choice', [0 => 'Default', 2 => 'High', 4 => 'High plus', 6 => 'Zero tolerance']],
            'cloudextendedtimeout' => ['Extra seconds for a cloud check', 'int', [0, 50]],
            'puaprotection' => ['Potentially unwanted apps', 'choice', [1 => 'Block', 2 => 'Audit (report only)', 0 => 'Off']],
            'enablenetworkprotection' => ['Network protection (dangerous websites)', 'choice', [1 => 'Block', 2 => 'Audit (report only)', 0 => 'Off']],
            'allowioavprotection' => ['Scan downloaded files and attachments', 'choice', $onoff],
            'allowarchivescanning' => ['Scan inside archives (.zip)', 'choice', $onoff],
            'allowemailscanning' => ['Scan email files', 'choice', $onoff],
            'allowscanningnetworkfiles' => ['Scan files on network drives', 'choice', $onoff],
            'submitsamplesconsent' => ['Sending samples to Microsoft', 'choice', [1 => 'Send safe samples automatically', 0 => 'Always ask', 2 => 'Never send', 3 => 'Send all samples']],
            'signatureupdateinterval' => ['Check for new definitions every (hours, 0 = Windows default)', 'int', [0, 24]],
            'schedulequickscantime' => ['Daily quick scan at (minutes after midnight)', 'int', [0, 1439]],
            'allowuseruiaccess' => ['The person can open Windows Security', 'choice', $onoff],
            'excludedpaths' => ['Folders and files not scanned', 'list', null],
            'excludedextensions' => ['File types not scanned (e.g. .log)', 'list', null],
            'excludedprocesses' => ['Programs whose files are not scanned', 'list', null],
        ];
    }
    function opsh_pc_av_defaults(): array {
        return ['allowrealtimemonitoring' => 1, 'allowbehaviormonitoring' => 1, 'allowcloudprotection' => 1, 'cloudblocklevel' => 2, 'cloudextendedtimeout' => 50,
                'puaprotection' => 1, 'enablenetworkprotection' => 2, 'allowioavprotection' => 1, 'allowarchivescanning' => 1, 'allowemailscanning' => 1,
                'allowscanningnetworkfiles' => 1, 'submitsamplesconsent' => 1, 'signatureupdateinterval' => 4, 'schedulequickscantime' => 720, 'allowuseruiaccess' => 1,
                'excludedpaths' => [], 'excludedextensions' => [], 'excludedprocesses' => []];
    }
    /** Firewall: the three network profiles on, inbound connections blocked unless allowed. */
    function opsh_pc_fw_defaults(): array { return ['enable' => true, 'block_inbound' => true]; }

    /** Check the rules typed on the screen. Returns the clean set. Throws InvalidArgumentException. */
    function opsh_pc_av_clean($in): array {
        if (!is_array($in)) throw new InvalidArgumentException('The rules are not in the expected form.');
        $out = [];
        foreach (opsh_pc_av_fields() as $k => [$label, $kind, $opt]) {
            $v = $in[$k] ?? opsh_pc_av_defaults()[$k];
            if ($kind === 'choice') { if (!is_numeric($v) || !array_key_exists((int)$v, $opt)) throw new InvalidArgumentException('Choose a value for "' . $label . '".'); $out[$k] = (int)$v; }
            elseif ($kind === 'int') { if (!is_numeric($v) || (int)$v < $opt[0] || (int)$v > $opt[1]) throw new InvalidArgumentException('"' . $label . '" must be between ' . $opt[0] . ' and ' . $opt[1] . '.'); $out[$k] = (int)$v; }
            else {
                if (!is_array($v) || count($v) > 100) throw new InvalidArgumentException('"' . $label . '" must be a list of up to 100 lines.');
                $l = []; foreach ($v as $x) { $x = is_string($x) ? trim($x) : ''; if ($x === '') continue;
                    if (mb_strlen($x) > 260 || preg_match('/[\x00-\x1F<>"|]/u', $x)) throw new InvalidArgumentException('"' . $x . '" is not a valid entry for "' . $label . '".');
                    if ($k === 'excludedextensions' && !preg_match('/^\.?[A-Za-z0-9_-]{1,20}$/', $x)) throw new InvalidArgumentException('"' . $x . '" is not a file type (e.g. .log).');
                    // one form for comparing: forward slashes, no repeats, no trailing \ * . or space, lower case
                    $root = strtolower(str_replace('/', '\\', $x));
                    while (strpos($root, '\\\\') !== false) $root = str_replace('\\\\', '\\', $root);
                    $root = rtrim($root, '\\*. ');
                    $whole = ['', '%systemdrive%', '%systemroot%', '%windir%', '%programfiles%', '%programfiles(x86)%', '%userprofile%', '%programdata%',
                              'c:\\windows', 'c:\\users', 'c:\\program files', 'c:\\program files (x86)', 'c:\\programdata'];
                    if ($k === 'excludedpaths' && (in_array($root, $whole, true) || (strlen($root) === 2 && ctype_alpha($root[0]) && $root[1] === ':')))
                        throw new InvalidArgumentException('"' . $x . '" would stop scanning of a whole disk or of Windows itself.');
                    if ($k === 'excludedprocesses' && trim($x, '*.\\/ ') === '') throw new InvalidArgumentException('"' . $x . '" is not a program.');
                    $l[$x] = 1; }
                $out[$k] = array_keys($l);
            }
        }
        if ($out['allowrealtimemonitoring'] === 0) throw new InvalidArgumentException('Real-time protection stays on: without it Defender does not protect the computer.');
        return $out;
    }
    /** For the Audit Log: what a rule set says, in words. */
    function opsh_pc_av_view(array $av): array {
        $o = []; foreach (opsh_pc_av_fields() as $k => [$label, $kind, $opt]) { $v = $av[$k] ?? null;
            $o[$label] = $kind === 'choice' ? (string)($opt[(int)$v] ?? $v) : ($kind === 'list' ? (implode(', ', (array)$v) ?: '(none)') : (string)$v); }
        return $o;
    }

    // ───────────────────────────── Microsoft: the group, the templates, the policies ─────────────────────────────

    function opsh_pc_policy_names(): array {
        $app = function_exists('opsh_app_name') ? opsh_app_name() : 'OpsHub';
        return ['av' => $app . ' personal computers — Antivirus', 'fw' => $app . ' personal computers — Firewall'];
    }

    /** Create the dynamic device group of computers managed by Defender only. Returns [id, name]. */
    function opsh_pc_make_group(): array {
        $app = function_exists('opsh_app_name') ? opsh_app_name() : 'OpsHub';
        $name = $app . ' - Personal computers (Defender only)';
        $nick = 'pc-defender-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $g = opsh_graph_send('POST', 'groups', ['displayName' => $name, 'description' => 'Computers onboarded to Microsoft Defender and not enrolled in Intune (security settings management). Created by ' . $app . '.',
            'mailEnabled' => false, 'mailNickname' => $nick, 'securityEnabled' => true, 'groupTypes' => ['DynamicMembership'],
            'membershipRule' => '(device.managementType -eq "MicrosoftSense")', 'membershipRuleProcessingState' => 'On']);
        if (empty($g['id'])) throw new RuntimeException('Microsoft did not return the new group.');
        return [(string)$g['id'], $name];
    }

    /** The tenant's template of a family for Windows with Defender (microsoftSense) technology. */
    function opsh_pc_template(string $family, string $display): array {
        $r = opsh_graph_get('deviceManagement/configurationPolicyTemplates?$top=100&$filter=' . rawurlencode("templateFamily eq '" . $family . "'"), 'beta');
        $best = null;
        foreach ((array)($r['value'] ?? []) as $t) {
            if (!is_array($t) || strtolower((string)($t['platforms'] ?? '')) !== 'windows10') continue;
            if (stripos((string)($t['technologies'] ?? ''), 'microsoftsense') === false) continue;
            if (strcasecmp(trim((string)($t['displayName'] ?? '')), $display) !== 0) continue;
            if (isset($t['lifecycleState']) && !in_array(strtolower((string)$t['lifecycleState']), ['active', ''], true)) continue;
            if (!$best || (int)($t['version'] ?? 0) > (int)($best['version'] ?? 0)) $best = $t;
        }
        if (!$best) throw new RuntimeException('Microsoft lists no "' . $display . '" endpoint security template for Windows with Defender in this tenant.');
        $tid = (string)$best['id']; $sts = [];
        $url = 'deviceManagement/configurationPolicyTemplates/' . rawurlencode($tid) . '/settingTemplates?$expand=settingDefinitions&$top=500';
        for ($n = 0; $n < 6 && $url !== ''; $n++) {
            $p = opsh_graph_get($url, 'beta');
            foreach ((array)($p['value'] ?? []) as $s) if (is_array($s)) $sts[] = $s;
            $nx = (string)($p['@odata.nextLink'] ?? '');
            $url = preg_match('#^https://graph\.microsoft\.com/beta/(.+)$#', $nx, $m) ? $m[1] : '';
        }
        return ['id' => $tid, 'technologies' => (string)$best['technologies'], 'settings' => $sts];
    }

    /** One setting instance from the template, or null when the template does not have it. $value: int|bool|string[] */
    function opsh_pc_instance(array $tpl, string $defId, $value): ?array {
        foreach ($tpl['settings'] as $st) {
            $it = (array)($st['settingInstanceTemplate'] ?? []);
            if (strcasecmp((string)($it['settingDefinitionId'] ?? ''), $defId) !== 0) continue;
            $type = (string)($it['@odata.type'] ?? ''); $ref = ['settingInstanceTemplateId' => (string)($it['settingInstanceTemplateId'] ?? '')];
            if (stripos($type, 'ChoiceSettingInstanceTemplate') !== false) {
                $vt = (string)($it['choiceSettingValueTemplate']['settingValueTemplateId'] ?? '');
                $item = null;
                foreach ((array)($st['settingDefinitions'] ?? []) as $def) {
                    if (strcasecmp((string)($def['id'] ?? ''), $defId) !== 0) continue;
                    foreach ((array)($def['options'] ?? []) as $o) {
                        $ov = $o['optionValue']['value'] ?? null; $iid = (string)($o['itemId'] ?? '');
                        $want = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
                        $have = is_bool($ov) ? ($ov ? 'true' : 'false') : (string)$ov;
                        if ($have === $want || preg_match('/_' . preg_quote($want, '/') . '$/i', $iid)) { $item = $iid; break 2; }
                    }
                }
                if ($item === null) return null;
                $v = ['@odata.type' => '#microsoft.graph.deviceManagementConfigurationChoiceSettingValue', 'value' => $item, 'children' => []];
                if ($vt !== '') $v['settingValueTemplateReference'] = ['settingValueTemplateId' => $vt, 'useTemplateDefault' => false];
                return ['@odata.type' => '#microsoft.graph.deviceManagementConfigurationChoiceSettingInstance', 'settingDefinitionId' => $defId, 'settingInstanceTemplateReference' => $ref, 'choiceSettingValue' => $v];
            }
            if (stripos($type, 'SimpleSettingCollectionInstanceTemplate') !== false) {
                if (!is_array($value) || !$value) return null;
                return ['@odata.type' => '#microsoft.graph.deviceManagementConfigurationSimpleSettingCollectionInstance', 'settingDefinitionId' => $defId, 'settingInstanceTemplateReference' => $ref,
                        'simpleSettingCollectionValue' => array_map(fn($x) => ['@odata.type' => '#microsoft.graph.deviceManagementConfigurationStringSettingValue', 'value' => (string)$x], array_values($value))];
            }
            if (stripos($type, 'SimpleSettingInstanceTemplate') !== false) {
                $vt = (string)($it['simpleSettingValueTemplate']['settingValueTemplateId'] ?? '');
                $v = is_int($value) ? ['@odata.type' => '#microsoft.graph.deviceManagementConfigurationIntegerSettingValue', 'value' => $value]
                                    : ['@odata.type' => '#microsoft.graph.deviceManagementConfigurationStringSettingValue', 'value' => (string)$value];
                if ($vt !== '') $v['settingValueTemplateReference'] = ['settingValueTemplateId' => $vt, 'useTemplateDefault' => false];
                return ['@odata.type' => '#microsoft.graph.deviceManagementConfigurationSimpleSettingInstance', 'settingDefinitionId' => $defId, 'settingInstanceTemplateReference' => $ref, 'simpleSettingValue' => $v];
            }
            return null;
        }
        return null;
    }

    /** The policy body for 'av' or 'fw'. Returns [body, skipped settings]. */
    function opsh_pc_policy_body(string $which, array $cfg): array {
        $names = opsh_pc_policy_names(); $app = function_exists('opsh_app_name') ? opsh_app_name() : 'OpsHub';
        $settings = []; $skipped = [];
        if ($which === 'av') {
            $tpl = opsh_pc_template('endpointSecurityAntivirus', 'Microsoft Defender Antivirus');
            foreach (opsh_pc_av_fields() as $k => [$label, $kind]) {
                $v = $cfg['av'][$k] ?? null; if ($kind === 'list' && !$v) continue;
                $inst = opsh_pc_instance($tpl, 'device_vendor_msft_policy_config_defender_' . $k, $v);
                if ($inst) $settings[] = ['@odata.type' => '#microsoft.graph.deviceManagementConfigurationSetting', 'settingInstance' => $inst]; else $skipped[] = $label;
            }
            $desc = 'Antivirus rules for personal computers protected by Microsoft Defender (not enrolled in Intune). Kept by ' . $app . ': change them there.';
        } else {
            $tpl = opsh_pc_template('endpointSecurityFirewall', 'Windows Firewall');
            foreach (['domain', 'private', 'public'] as $pf) {
                foreach ([['enablefirewall', (bool)$cfg['fw']['enable'], 'Firewall on (' . $pf . ')'], ['defaultinboundaction', $cfg['fw']['block_inbound'] ? 1 : 0, 'Block inbound (' . $pf . ')']] as [$s, $v, $label]) {
                    $inst = opsh_pc_instance($tpl, 'vendor_msft_firewall_mdmstore_' . $pf . 'profile_' . $s, $v) ?? opsh_pc_instance($tpl, 'device_vendor_msft_firewall_mdmstore_' . $pf . 'profile_' . $s, $v);
                    if ($inst) $settings[] = ['@odata.type' => '#microsoft.graph.deviceManagementConfigurationSetting', 'settingInstance' => $inst]; else $skipped[] = $label;
                }
            }
            $desc = 'Windows Firewall for personal computers protected by Microsoft Defender (not enrolled in Intune). Kept by ' . $app . '.';
        }
        if (!$settings) throw new RuntimeException('None of the settings were found in Microsoft\'s template; nothing was created.');
        return [['name' => $names[$which], 'description' => $desc, 'platforms' => 'windows10', 'technologies' => $tpl['technologies'] ?: 'mdm,microsoftSense',
                 'roleScopeTagIds' => ['0'], 'templateReference' => ['templateId' => $tpl['id']], 'settings' => $settings], $skipped];
    }

    /**
     * Create the policy (or replace its settings when it exists) and assign it to the group. Returns
     * ['id', 'created' => bool, 'skipped' => [...]].
     */
    function opsh_pc_push(string $which, array $cfg): array {
        if (!preg_match('/^[0-9a-f-]{36}$/i', (string)$cfg['group_id'])) throw new InvalidArgumentException('Create the device group first (step 3).');
        [$body, $skipped] = opsh_pc_policy_body($which, $cfg);
        $id = (string)($cfg['policies'][$which]['id'] ?? ''); $created = false;
        if ($id !== '') {
            try { opsh_graph_get('deviceManagement/configurationPolicies/' . rawurlencode($id) . '?$select=id', 'beta'); }
            catch (Throwable $e) { if (preg_match('/HTTP 404/', $e->getMessage())) $id = ''; else throw $e; }
        }
        if ($id === '') { $r = opsh_graph_send('POST', 'deviceManagement/configurationPolicies', $body, 'beta'); $id = (string)($r['id'] ?? ''); $created = true;
            if ($id === '') throw new RuntimeException('Microsoft did not return the new policy.'); }
        else opsh_graph_send('PUT', 'deviceManagement/configurationPolicies/' . rawurlencode($id), $body, 'beta');
        opsh_graph_send('POST', 'deviceManagement/configurationPolicies/' . rawurlencode($id) . '/assign',
            ['assignments' => [['target' => ['@odata.type' => '#microsoft.graph.groupAssignmentTarget', 'groupId' => (string)$cfg['group_id']]]]], 'beta');
        return ['id' => $id, 'created' => $created, 'skipped' => $skipped];
    }

    /** Has someone changed the policy in Intune since this app last saved it? */
    function opsh_pc_policy_state(array $cfg): array {
        $o = [];
        foreach (['av', 'fw'] as $w) {
            $p = $cfg['policies'][$w] ?? null; if (!$p || empty($p['id'])) { $o[$w] = null; continue; }
            try { $r = opsh_graph_get('deviceManagement/configurationPolicies/' . rawurlencode((string)$p['id']) . '?$select=id,name,lastModifiedDateTime&$expand=assignments', 'beta');
                $mod = strtotime((string)($r['lastModifiedDateTime'] ?? '')); $ours = strtotime((string)($p['at'] ?? ''));
                $groups = []; foreach ((array)($r['assignments'] ?? []) as $a) if (!empty($a['target']['groupId'])) $groups[] = (string)$a['target']['groupId'];
                $o[$w] = ['exists' => true, 'changed_in_intune' => $mod && $ours && $mod > $ours + 300, 'modified' => $r['lastModifiedDateTime'] ?? null, 'assigned' => in_array((string)$cfg['group_id'], $groups, true)];
            } catch (Throwable $e) { $o[$w] = ['exists' => !preg_match('/HTTP 404/', $e->getMessage()), 'error' => mb_substr($e->getMessage(), 0, 200)]; }
        }
        return $o;
    }

    /**
     * The computers of the people in the program as Defender sees them (advanced hunting, read only), matched by the
     * computer name their check reported. Only those computers are asked about.
     */
    function opsh_pc_defender_devices(array $names): array {
        $names = array_values(array_unique(array_filter(array_map(fn($n) => strtolower((string)$n), $names), fn($n) => preg_match('/^[a-z0-9._-]{1,63}$/', $n))));
        if (!$names) return [];
        $list = implode(',', array_map(fn($n) => '"' . $n . '"', array_slice($names, 0, 300)));
        $r = opsh_graph_send('POST', 'security/runHuntingQuery', ['Query' => 'DeviceInfo | where Timestamp > ago(30d)'
            . ' | extend Short = tolower(tostring(split(DeviceName, ".")[0])) | where Short in (' . $list . ')'
            . ' | summarize arg_max(Timestamp, DeviceName, OSPlatform, OnboardingStatus) by DeviceId, Short'
            . ' | join kind=leftouter (DeviceTvmSecureConfigurationAssessment | where ConfigurationId in ("scid-2010", "scid-2011", "scid-2012")'
            . ' | summarize AvOn = maxif(toint(IsCompliant), ConfigurationId == "scid-2010"), DefsOk = maxif(toint(IsCompliant), ConfigurationId == "scid-2011"), RtOn = maxif(toint(IsCompliant), ConfigurationId == "scid-2012") by DeviceId) on DeviceId'
            . ' | project Short, DeviceName, OSPlatform, OnboardingStatus, LastSeen = Timestamp, AvOn, DefsOk, RtOn']);
        $out = [];
        foreach ((array)($r['results'] ?? []) as $x) {
            if (!is_array($x)) continue; $lx = array_change_key_case($x, CASE_LOWER); $g = fn($k) => $lx[strtolower($k)] ?? null;
            $flag = fn($v) => $v === null || $v === '' ? null : ((int)$v === 1);
            $out[strtolower((string)$g('Short'))] = ['name' => (string)$g('DeviceName'), 'os' => (string)$g('OSPlatform'), 'onboarding' => (string)$g('OnboardingStatus'), 'last_seen' => (string)$g('LastSeen'),
                'antivirus' => $flag($g('AvOn')), 'definitions' => $flag($g('DefsOk')), 'realtime' => $flag($g('RtOn'))];
        }
        return $out;
    }

    // ───────────────────────────── the check the person runs ─────────────────────────────

    /** The PowerShell check, for one person's link. Read only: it changes nothing on the computer. */
    function opsh_pc_check_script(string $base, string $token): string {
        $u = rtrim($base, '/') . '/api/public_pc/report?t=' . $token;
        return <<<PS
# Read-only check of this computer's protection. It changes nothing. It sends the result to your organization.
\$ErrorActionPreference = 'SilentlyContinue'
\$os = Get-CimInstance Win32_OperatingSystem
\$cv = Get-ItemProperty 'HKLM:\\SOFTWARE\\Microsoft\\Windows NT\\CurrentVersion'
\$d = @{}
try { \$m = Get-MpComputerStatus -ErrorAction Stop; foreach (\$k in 'AMServiceEnabled','AntivirusEnabled','RealTimeProtectionEnabled','BehaviorMonitorEnabled','IsTamperProtected','AMRunningMode','AntivirusSignatureAge','QuickScanAge') { \$d[\$k] = \$m.\$k } } catch { \$d['error'] = \$_.Exception.Message }
\$p = @(Get-CimInstance -Namespace root/SecurityCenter2 -ClassName AntiVirusProduct | ForEach-Object { @{ name = \$_.displayName; state = [int]\$_.productState } })
\$on = (Get-Service -Name Sense -ErrorAction SilentlyContinue).Status -eq 'Running'
\$r = @{ computer = \$env:COMPUTERNAME; os = \$os.Caption; build = \$os.BuildNumber; edition = \$cv.EditionID; defender = \$d; products = \$p; onboarded = \$on }
try { Invoke-RestMethod -Method Post -Uri '$u' -ContentType 'application/json' -Body (\$r | ConvertTo-Json -Depth 4) | Out-Null; Write-Host 'Sent. Go back to the page in your browser.' -ForegroundColor Green }
catch { Write-Host ('Could not send the result: ' + \$_.Exception.Message) -ForegroundColor Red }
PS;
    }
}
