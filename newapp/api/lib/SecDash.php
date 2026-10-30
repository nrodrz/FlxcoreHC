<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT146: the security alerts dashboard (Microsoft 365 → Security → Dashboard). Follows Microsoft Defender's
 * alerts and incidents over 7, 30 or 90 days: what is open, how old, who has it, where alerts come from, the trend per
 * day, how fast they are resolved and how many were real, the people and devices hit most, and what needs attention
 * now. Read from Microsoft each time (kept briefly by the read store); nothing is changed in Microsoft 365 here.
 * Needs SecurityAlert.Read.All (alerts) and SecurityIncident.Read.All (incidents); each part degrades on its own.
 */
if (!function_exists('itops_sd_build')) {
    /** Readable names for Microsoft's alert sources. */
    function itops_sd_sources(): array {
        return ['microsoftDefenderForEndpoint' => 'Defender for Endpoint (devices)', 'microsoftDefenderForIdentity' => 'Defender for Identity',
                'microsoftDefenderForCloudApps' => 'Defender for Cloud Apps', 'microsoftDefenderForOffice365' => 'Defender for Office 365 (email)',
                'microsoft365Defender' => 'Microsoft Defender XDR', 'azureAdIdentityProtection' => 'Entra ID Protection (sign-ins)',
                'microsoftAppGovernance' => 'App governance', 'dataLossPrevention' => 'Data loss prevention', 'microsoftDefenderForCloud' => 'Defender for Cloud',
                'microsoftSentinel' => 'Microsoft Sentinel', 'microsoftInsiderRiskManagement' => 'Insider risk management', 'unknown' => 'Other', 'unknownFutureValue' => 'Other'];
    }
    /** Attention rules: an open alert older than this many hours, by severity. */
    function itops_sd_targets(): array { return ['high' => 24, 'medium' => 72, 'low' => 168, 'informational' => 0]; }
    function itops_sd_hours(?string $a, ?string $b = null): ?float {
        $t1 = $a ? strtotime($a) : false; $t2 = $b ? strtotime($b) : time(); if ($t1 === false || $t2 === false) return null; return max(0, ($t2 - $t1) / 3600);
    }
    function itops_sd_median(array $v): ?float { if (!$v) return null; sort($v); $n = count($v); return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2; }

    /** The dashboard from raw alerts_v2 rows and incident rows (either may be null when it could not be read). */
    function itops_sd_build(?array $alerts, ?array $incidents, int $days): array {
        $S = itops_sd_sources(); $T = itops_sd_targets();
        $since = strtotime('-' . $days . ' days'); $sevs = ['high', 'medium', 'low', 'informational'];
        $out = ['days' => $days, 'alerts_read' => $alerts !== null, 'incidents_read' => $incidents !== null];
        if ($alerts !== null) {
            $open = []; $all = []; $ttr = []; $resolvedN = 0; $cls = ['truePositive' => 0, 'falsePositive' => 0, 'informationalExpectedActivity' => 0, 'unknown' => 0];
            $trend = []; for ($i = $days - 1; $i >= 0; $i--) $trend[date('Y-m-d', strtotime('-' . $i . ' days'))] = ['high' => 0, 'medium' => 0, 'low' => 0, 'informational' => 0, 'resolved' => 0];
            $src = []; $cat = []; $usr = []; $dev = []; $status = ['new' => 0, 'inProgress' => 0, 'resolved' => 0];
            $aging = ['< 1 day' => 0, '1–3 days' => 0, '3–7 days' => 0, '7–30 days' => 0, '> 30 days' => 0];
            foreach ($alerts as $a) {
                $c = strtotime((string)($a['createdDateTime'] ?? '')); if ($c === false) continue;
                $sev = strtolower((string)($a['severity'] ?? 'unknown')); if (!in_array($sev, $sevs, true)) $sev = 'informational';
                $st = (string)($a['status'] ?? 'new'); $isOpen = $st !== 'resolved';
                $users = []; $devs = [];
                foreach ((array)($a['evidence'] ?? []) as $e) {
                    $u = (string)($e['userAccount']['userPrincipalName'] ?? ''); if ($u !== '') $users[strtolower($u)] = $u;
                    $d = (string)($e['deviceDnsName'] ?? ''); if ($d !== '') $devs[strtolower($d)] = $d;
                }
                $row = ['id' => (string)($a['id'] ?? ''), 'title' => (string)($a['title'] ?? ''), 'severity' => $sev, 'status' => $st, 'created' => $a['createdDateTime'] ?? null,
                    'source' => $S[(string)($a['serviceSource'] ?? 'unknown')] ?? (string)($a['serviceSource'] ?? 'Other'), 'category' => (string)($a['category'] ?? ''),
                    'assigned' => (string)($a['assignedTo'] ?? ''), 'incident' => (string)($a['incidentId'] ?? ''), 'url' => (string)($a['alertWebUrl'] ?? ''),
                    'users' => array_values($users), 'devices' => array_values($devs), 'age_h' => $isOpen ? round(itops_sd_hours($a['createdDateTime'] ?? null) ?? 0, 1) : null];
                if ($isOpen) {
                    $open[] = $row; $h = $row['age_h'];
                    $aging[$h < 24 ? '< 1 day' : ($h < 72 ? '1–3 days' : ($h < 168 ? '3–7 days' : ($h < 720 ? '7–30 days' : '> 30 days')))]++;
                }
                $rs = $a['resolvedDateTime'] ?? null;
                if ($st === 'resolved' && $rs && ($rt = strtotime((string)$rs)) !== false && $rt >= $since) {   // resolved in the period, whenever it started
                    $hh = itops_sd_hours($a['createdDateTime'] ?? null, $rs); if ($hh !== null) $ttr[] = $hh; $rd = date('Y-m-d', $rt); if (isset($trend[$rd])) $trend[$rd]['resolved']++; $resolvedN = ($resolvedN ?? 0) + 1; }
                if ($c < $since) continue;   // the rest counts only what started in the period; open ones count whatever their age
                $all[] = $row; $status[$st] = ($status[$st] ?? 0) + 1;
                $day = date('Y-m-d', $c); if (isset($trend[$day])) $trend[$day][$sev]++;
                $k = $row['source']; $src[$k] = $src[$k] ?? ['name' => $k, 'total' => 0, 'open' => 0, 'high' => 0]; $src[$k]['total']++; if ($isOpen) $src[$k]['open']++; if ($sev === 'high') $src[$k]['high']++;
                if ($row['category'] !== '') { $cat[$row['category']] = ($cat[$row['category']] ?? 0) + 1; }
                foreach ($users as $u) { $usr[$u] = $usr[$u] ?? ['name' => $u, 'alerts' => 0, 'open' => 0]; $usr[$u]['alerts']++; if ($isOpen) $usr[$u]['open']++; }
                foreach ($devs as $d) { $dev[$d] = $dev[$d] ?? ['name' => $d, 'alerts' => 0, 'open' => 0]; $dev[$d]['alerts']++; if ($isOpen) $dev[$d]['open']++; }
                $cl = (string)($a['classification'] ?? 'unknown'); if ($st === 'resolved') $cls[isset($cls[$cl]) ? $cl : 'unknown']++;
            }
            $attention = array_values(array_filter($open, fn($r) => ($T[$r['severity']] ?? 0) > 0 && $r['age_h'] >= $T[$r['severity']] || ($r['assigned'] === '' && in_array($r['severity'], ['high', 'medium'], true))));
            foreach ($attention as &$r) { $why = []; if (($T[$r['severity']] ?? 0) > 0 && $r['age_h'] >= $T[$r['severity']]) $why[] = 'open longer than ' . ($T[$r['severity']] >= 48 ? ($T[$r['severity']] / 24) . ' days' : $T[$r['severity']] . ' hours'); if ($r['assigned'] === '') $why[] = 'nobody assigned'; $r['why'] = implode(' · ', $why); } unset($r);
            $rank = ['high' => 0, 'medium' => 1, 'low' => 2, 'informational' => 3];
            usort($attention, fn($a, $b) => ($rank[$a['severity']] <=> $rank[$b['severity']]) ?: ($b['age_h'] <=> $a['age_h']));
            $openN = fn($s) => count(array_filter($open, fn($r) => $r['severity'] === $s));
            $classified = $cls['truePositive'] + $cls['falsePositive'] + $cls['informationalExpectedActivity'];
            usort($src, fn($a, $b) => $b['total'] <=> $a['total']); arsort($cat);
            $topU = array_values($usr); usort($topU, fn($a, $b) => [$b['open'], $b['alerts']] <=> [$a['open'], $a['alerts']]);
            $topD = array_values($dev); usort($topD, fn($a, $b) => [$b['open'], $b['alerts']] <=> [$a['open'], $a['alerts']]);
            $out += [
                'kpi' => ['open' => count($open), 'open_high' => $openN('high'), 'open_medium' => $openN('medium'), 'unassigned' => count(array_filter($open, fn($r) => $r['assigned'] === '')),
                    'oldest_days' => $open ? round(max(array_column($open, 'age_h')) / 24, 1) : null, 'new' => count($all), 'resolved' => $resolvedN ?? 0,
                    'median_resolve_h' => ($m = itops_sd_median($ttr)) === null ? null : round($m, 1), 'true_positive_pct' => $classified ? (int)round(100 * $cls['truePositive'] / $classified) : null,
                    'attention' => count($attention)],
                'trend' => array_map(fn($d, $v) => ['day' => $d] + $v, array_keys($trend), array_values($trend)),
                'by_source' => array_values($src), 'by_category' => array_map(fn($k, $v) => ['name' => preg_replace('/(?<!^)([A-Z])/', ' $1', $k), 'count' => $v], array_slice(array_keys($cat), 0, 8), array_slice(array_values($cat), 0, 8)),
                'status' => $status, 'aging' => $aging, 'classification' => $cls, 'top_users' => array_slice($topU, 0, 6), 'top_devices' => array_slice($topD, 0, 6),
                'attention' => array_slice($attention, 0, 25), 'targets' => $T];
        }
        if ($incidents !== null) {
            $io = array_values(array_filter($incidents, fn($i) => in_array((string)($i['status'] ?? ''), ['active', 'inProgress'], true)));
            $inPeriod = array_filter($incidents, fn($i) => ($t = strtotime((string)($i['createdDateTime'] ?? ''))) !== false && $t >= $since);
            $out['incidents'] = ['open' => count($io), 'open_high' => count(array_filter($io, fn($i) => strtolower((string)($i['severity'] ?? '')) === 'high')),
                'unassigned' => count(array_filter($io, fn($i) => (string)($i['assignedTo'] ?? '') === '')), 'new' => count($inPeriod),
                'list' => array_slice(array_map(fn($i) => ['id' => (string)($i['id'] ?? ''), 'name' => (string)($i['displayName'] ?? ''), 'severity' => strtolower((string)($i['severity'] ?? '')),
                    'status' => (string)($i['status'] ?? ''), 'assigned' => (string)($i['assignedTo'] ?? ''), 'created' => $i['createdDateTime'] ?? null, 'url' => (string)($i['incidentWebUrl'] ?? ''),
                    'age_h' => round(itops_sd_hours($i['createdDateTime'] ?? null) ?? 0, 1)], $io), 0, 10)];
        }
        return $out;
    }
}
