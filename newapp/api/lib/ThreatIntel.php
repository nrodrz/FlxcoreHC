<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub BT133 — Security → Threats & vulnerabilities, and a device's Defender status. Read only.
 *
 * Microsoft has no API for the Threat analytics reports of the Defender portal (checked 10/25/2026: neither Microsoft
 * Graph nor the Defender APIs list them). What can be read, and is read here:
 *   · Vulnerabilities on the devices, and which devices: Defender advanced hunting through Microsoft Graph
 *     (POST /security/runHuntingQuery, ThreatHunting.Read.All) on the tables DeviceTvmSoftwareVulnerabilities and
 *     DeviceTvmSoftwareVulnerabilitiesKB. Empty when Defender vulnerability management is not part of the plan.
 *   · Microsoft's threat write-ups (GET /security/threatIntelligence/articles) and one vulnerability's details and the
 *     write-ups about it (/security/threatIntelligence/vulnerabilities/{id}) — ThreatIntelligence.Read.All.
 *   · A Windows device's Defender antivirus state from Intune (managedDevices/{id}/windowsProtectionState,
 *     DeviceManagementManagedDevices.Read.All).
 * Each part answers on its own: one refusal never blanks the others.
 */
if (!function_exists('opsh_ti_hunt')) {
    if (!defined('OPSH_TI_CVE')) define('OPSH_TI_CVE', '/^CVE-\d{4}-\d{4,7}$/');

    /** "needs" text for a refusal, null for other errors. */
    function opsh_ti_needs(Throwable $e, string $perm): ?string {
        return preg_match('/HTTP (401|403)/', $e->getMessage()) ? $perm : null;
    }

    /** One advanced-hunting query. Returns the rows (column names as Microsoft sends them). */
    function opsh_ti_hunt(string $kql): array {
        $r = opsh_graph_send('POST', 'security/runHuntingQuery', ['Query' => $kql]);
        $rows = $r['results'] ?? ($r['Results'] ?? []);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** A value from a row, whatever the casing Microsoft used for the column. */
    function opsh_ti_col(array $row, string $name) {
        if (array_key_exists($name, $row)) return $row[$name];
        $l = lcfirst($name); if (array_key_exists($l, $row)) return $row[$l];
        foreach ($row as $k => $v) if (strcasecmp((string)$k, $name) === 0) return $v;
        return null;
    }

    function opsh_ti_str($v, int $max = 200): string { return is_scalar($v) ? mb_substr(trim((string)$v), 0, $max) : ''; }
    function opsh_ti_list($v, int $n = 5): array {
        if (is_string($v)) { $d = json_decode($v, true); $v = is_array($d) ? $d : [$v]; }
        if (!is_array($v)) return [];
        $out = []; foreach ($v as $x) { $x = opsh_ti_str($x, 120); if ($x !== '' && !in_array($x, $out, true)) $out[] = $x; if (count($out) >= $n) break; }
        return $out;
    }
    function opsh_ti_bool($v): bool { return $v === true || $v === 1 || (is_string($v) && in_array(strtolower($v), ['true', '1'], true)); }
    function opsh_ti_sev(string $s): string { $s = strtolower($s); return in_array($s, ['critical', 'high', 'medium', 'low'], true) ? $s : 'unknown'; }

    /** The vulnerabilities on the devices: totals, the 100 that matter most, and the 25 most exposed devices. */
    function opsh_ti_vulns(): array {
        $out = ['ok' => false, 'totals' => null, 'cves' => [], 'devices' => [], 'needs_permission' => null, 'error' => '', 'empty_note' => ''];
        try {
            $t = opsh_ti_hunt('DeviceTvmSoftwareVulnerabilities | summarize Devices=dcount(DeviceId), Cves=dcount(CveId), Critical=dcountif(CveId, VulnerabilitySeverityLevel == "Critical"), High=dcountif(CveId, VulnerabilitySeverityLevel == "High")');
            $r = $t[0] ?? [];
            $out['totals'] = ['devices' => (int)opsh_ti_col($r, 'Devices'), 'cves' => (int)opsh_ti_col($r, 'Cves'), 'critical' => (int)opsh_ti_col($r, 'Critical'), 'high' => (int)opsh_ti_col($r, 'High')];
            $c = opsh_ti_hunt('DeviceTvmSoftwareVulnerabilities'
                . ' | summarize Devices=dcount(DeviceId), Software=make_set(SoftwareName, 5), Fix=take_any(RecommendedSecurityUpdate), ZeroDay=max(toint(tostring(CveTags) has "ZeroDay")), NoFix=max(toint(tostring(CveTags) has "NoSecurityUpdate")) by CveId, Severity=VulnerabilitySeverityLevel'
                . ' | join kind=leftouter (DeviceTvmSoftwareVulnerabilitiesKB | project CveId, CvssScore, Exploit=IsExploitAvailable, Published=PublishedDate) on CveId'
                . ' | extend Rank = case(Severity == "Critical", 0, Severity == "High", 1, Severity == "Medium", 2, 3)'
                . ' | order by Exploit desc, Rank asc, Devices desc | take 100'
                . ' | project CveId, Severity, Devices, Software, Fix, ZeroDay, NoFix, CvssScore, Exploit, Published');
            foreach ($c as $r) {
                $id = strtoupper(opsh_ti_str(opsh_ti_col($r, 'CveId'), 30)); if (!preg_match(OPSH_TI_CVE, $id)) continue;
                $cv = opsh_ti_col($r, 'CvssScore');
                $out['cves'][] = ['cve' => $id, 'severity' => opsh_ti_sev(opsh_ti_str(opsh_ti_col($r, 'Severity'), 20)), 'devices' => (int)opsh_ti_col($r, 'Devices'),
                    'software' => opsh_ti_list(opsh_ti_col($r, 'Software')), 'fix' => opsh_ti_str(opsh_ti_col($r, 'Fix')), 'cvss' => is_numeric($cv) ? round((float)$cv, 1) : null,
                    'exploit' => opsh_ti_bool(opsh_ti_col($r, 'Exploit')), 'zero_day' => (int)opsh_ti_col($r, 'ZeroDay') > 0, 'no_fix' => (int)opsh_ti_col($r, 'NoFix') > 0,
                    'published' => opsh_ti_str(opsh_ti_col($r, 'Published'), 30)];
            }
            $d = opsh_ti_hunt('DeviceTvmSoftwareVulnerabilities'
                . ' | summarize Critical=dcountif(CveId, VulnerabilitySeverityLevel == "Critical"), High=dcountif(CveId, VulnerabilitySeverityLevel == "High"), Total=dcount(CveId) by DeviceName, OSPlatform'
                . ' | order by Critical desc, High desc, Total desc | take 25');
            foreach ($d as $r) $out['devices'][] = ['name' => opsh_ti_str(opsh_ti_col($r, 'DeviceName'), 190), 'os' => opsh_ti_str(opsh_ti_col($r, 'OSPlatform'), 60),
                'critical' => (int)opsh_ti_col($r, 'Critical'), 'high' => (int)opsh_ti_col($r, 'High'), 'total' => (int)opsh_ti_col($r, 'Total')];
            $out['ok'] = true;
            if (!$out['totals']['devices']) $out['empty_note'] = 'Defender lists no vulnerable software. Either every device is up to date, or Defender vulnerability management is not part of the plan (Defender for Endpoint Plan 2, or the vulnerability management add-on) or the devices are not onboarded to Defender.';
        } catch (Throwable $e) {
            $out['needs_permission'] = opsh_ti_needs($e, 'ThreatHunting.Read.All');
            $out['error'] = mb_substr($e->getMessage(), 0, 300);
        }
        return $out;
    }

    /** One vulnerability: the devices that have it, Microsoft's details and the write-ups about it (each part on its own). */
    function opsh_ti_vuln(string $cve): array {
        $cve = strtoupper(trim($cve));
        if (!preg_match(OPSH_TI_CVE, $cve)) throw new InvalidArgumentException('That is not a CVE number (CVE-YYYY-NNNN).');
        $out = ['cve' => $cve, 'devices' => null, 'devices_error' => '', 'intel' => null, 'intel_error' => '', 'intel_needs' => null, 'articles' => []];
        try {
            $rows = opsh_ti_hunt('DeviceTvmSoftwareVulnerabilities | where CveId == "' . $cve . '"'
                . ' | summarize Software=make_set(strcat(SoftwareName, " ", SoftwareVersion), 5), Fix=take_any(RecommendedSecurityUpdate) by DeviceName, OSPlatform | order by DeviceName asc | take 300');
            $out['devices'] = array_map(fn($r) => ['name' => opsh_ti_str(opsh_ti_col($r, 'DeviceName'), 190), 'os' => opsh_ti_str(opsh_ti_col($r, 'OSPlatform'), 60),
                'software' => opsh_ti_list(opsh_ti_col($r, 'Software')), 'fix' => opsh_ti_str(opsh_ti_col($r, 'Fix'))], $rows);
        } catch (Throwable $e) { $out['devices_error'] = mb_substr($e->getMessage(), 0, 300); }
        try {
            $v = opsh_graph_get('security/threatIntelligence/vulnerabilities/' . rawurlencode($cve));
            $sum = is_array($v['cvss3Summary'] ?? null) ? $v['cvss3Summary'] : [];
            $out['intel'] = ['severity' => opsh_ti_sev(opsh_ti_str($v['severity'] ?? '', 20)), 'priority' => isset($v['priorityScore']) && is_numeric($v['priorityScore']) ? (int)$v['priorityScore'] : null,
                'cvss' => isset($sum['score']) && is_numeric($sum['score']) ? round((float)$sum['score'], 1) : null,
                'exploits_available' => !empty($v['exploitsAvailable']), 'active_exploits' => !empty($v['activeExploitsObserved']), 'chatter' => !empty($v['hasChatter']),
                'description' => opsh_ti_str($v['description']['content'] ?? '', 4000), 'remediation' => opsh_ti_str($v['remediation']['content'] ?? '', 4000),
                'published' => opsh_ti_str($v['publishedDateTime'] ?? '', 30),
                'references' => array_values(array_slice(array_filter(array_map(fn($h) => is_array($h) && preg_match('#^https://#i', (string)($h['url'] ?? '')) ? ['title' => opsh_ti_str($h['title'] ?? '', 160) ?: opsh_ti_str($h['url'], 160), 'url' => mb_substr((string)$h['url'], 0, 500)] : null, (array)($v['references'] ?? []))), 0, 8))];
            try {
                $a = opsh_graph_get('security/threatIntelligence/vulnerabilities/' . rawurlencode($cve) . '/articles?$select=id,title,createdDateTime&$top=10');
                $out['articles'] = array_map(fn($x) => ['id' => opsh_ti_str($x['id'] ?? '', 80), 'title' => opsh_ti_str($x['title'] ?? '', 250), 'created' => opsh_ti_str($x['createdDateTime'] ?? '', 30)], array_filter((array)($a['value'] ?? []), 'is_array'));
            } catch (Throwable $e) {}
        } catch (Throwable $e) {
            $out['intel_needs'] = opsh_ti_needs($e, 'ThreatIntelligence.Read.All');
            $out['intel_error'] = preg_match('/HTTP 404/', $e->getMessage()) ? 'Microsoft has no write-up for this vulnerability.' : mb_substr($e->getMessage(), 0, 300);
        }
        return $out;
    }

    /** Microsoft's threat write-ups, newest first ($q = one word to search for). */
    function opsh_ti_articles(string $q = ''): array {
        $q = trim($q);
        if ($q !== '' && !preg_match('/^[A-Za-z0-9._-]{2,40}$/', $q)) throw new InvalidArgumentException('Search for one word (letters, numbers, dot, dash).');
        $out = ['ok' => false, 'articles' => [], 'needs_permission' => null, 'error' => ''];
        try {
            $p = 'security/threatIntelligence/articles?$top=25&$select=id,title,summary,createdDateTime,lastUpdatedDateTime,isFeatured,tags'
               . ($q !== '' ? '&$search=' . rawurlencode('"' . $q . '"') : '&$orderby=' . rawurlencode('createdDateTime desc'));
            $r = opsh_graph_get($p);
            foreach ((array)($r['value'] ?? []) as $a) if (is_array($a)) $out['articles'][] = ['id' => opsh_ti_str($a['id'] ?? '', 80), 'title' => opsh_ti_str($a['title'] ?? '', 250),
                'summary' => opsh_ti_str($a['summary']['content'] ?? '', 1200), 'created' => opsh_ti_str($a['createdDateTime'] ?? '', 30), 'updated' => opsh_ti_str($a['lastUpdatedDateTime'] ?? '', 30),
                'featured' => !empty($a['isFeatured']), 'tags' => opsh_ti_list($a['tags'] ?? [], 8)];
            $out['ok'] = true;
        } catch (Throwable $e) {
            $out['needs_permission'] = opsh_ti_needs($e, 'ThreatIntelligence.Read.All');
            $out['error'] = mb_substr($e->getMessage(), 0, 300);
        }
        return $out;
    }

    /** One article's full text (Microsoft sends it as markdown or HTML; the page shows it as plain text). */
    function opsh_ti_article(string $id): array {
        if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/', $id)) throw new InvalidArgumentException('Not an article id.');
        $a = opsh_graph_get('security/threatIntelligence/articles/' . rawurlencode($id) . '?$select=id,title,body,summary,createdDateTime,lastUpdatedDateTime,tags');
        $body = (string)($a['body']['content'] ?? '');
        if (strtolower((string)($a['body']['format'] ?? '')) === 'html') $body = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/li|/h\d)[^>]*>#i', "\n", preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $body))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return ['id' => $id, 'title' => opsh_ti_str($a['title'] ?? '', 250), 'body' => mb_substr(trim($body), 0, 60000), 'created' => opsh_ti_str($a['createdDateTime'] ?? '', 30),
                'updated' => opsh_ti_str($a['lastUpdatedDateTime'] ?? '', 30), 'tags' => opsh_ti_list($a['tags'] ?? [], 12)];
    }

    /** A Windows device's Defender antivirus state, from Intune. */
    function opsh_ti_defender_state(string $deviceId): array {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $deviceId)) throw new InvalidArgumentException('Not a device id.');
        try { $s = opsh_graph_get('deviceManagement/managedDevices/' . rawurlencode($deviceId) . '/windowsProtectionState'); }
        catch (Throwable $e) {
            if (preg_match('/HTTP 404/', $e->getMessage())) return ['ok' => true, 'state' => null, 'note' => 'Intune has no Defender report for this device yet (it reports after its next check-in, and only for Windows).'];
            return ['ok' => false, 'needs_permission' => opsh_ti_needs($e, 'DeviceManagementManagedDevices.Read.All'), 'error' => mb_substr($e->getMessage(), 0, 300)];
        }
        $b = fn($k) => array_key_exists($k, $s) && $s[$k] !== null ? (bool)$s[$k] : null;
        $problems = [];
        if ($b('malwareProtectionEnabled') === false) $problems[] = 'Antivirus is off';
        if ($b('realTimeProtectionEnabled') === false) $problems[] = 'Real-time protection is off';
        if ($b('tamperProtectionEnabled') === false) $problems[] = 'Tamper protection is off';
        if ($b('signatureUpdateOverdue')) $problems[] = 'Definitions are out of date';
        if ($b('quickScanOverdue')) $problems[] = 'Quick scan overdue';
        if ($b('fullScanOverdue')) $problems[] = 'Full scan overdue';
        if ($b('rebootRequired')) $problems[] = 'Restart needed';
        $ds = opsh_ti_str($s['deviceState'] ?? '', 120); if ($ds !== '' && strtolower($ds) !== 'clean') $problems[] = 'Device state: ' . $ds;
        $ps = opsh_ti_str($s['productStatus'] ?? '', 200); if ($ps !== '' && strtolower($ps) !== 'noissues') $problems[] = 'Product status: ' . $ps;
        return ['ok' => true, 'state' => [
            'antivirus' => $b('malwareProtectionEnabled'), 'realtime' => $b('realTimeProtectionEnabled'), 'tamper' => $b('tamperProtectionEnabled'), 'network' => $b('networkInspectionSystemEnabled'),
            'signature_overdue' => $b('signatureUpdateOverdue'), 'quick_overdue' => $b('quickScanOverdue'), 'full_overdue' => $b('fullScanOverdue'), 'reboot' => $b('rebootRequired'),
            'device_state' => $ds, 'product_status' => $ps, 'signature' => opsh_ti_str($s['signatureVersion'] ?? '', 60), 'engine' => opsh_ti_str($s['engineVersion'] ?? '', 60),
            'last_quick' => opsh_ti_str($s['lastQuickScanDateTime'] ?? '', 30), 'last_full' => opsh_ti_str($s['lastFullScanDateTime'] ?? '', 30), 'reported' => opsh_ti_str($s['lastReportedDateTime'] ?? '', 30),
            'problems' => $problems]];
    }
}
