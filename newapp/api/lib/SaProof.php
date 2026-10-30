<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — BT123: security baselines, build 4e. Proof.
 *
 *  - Drift: after each scan, a rule that passed in the scan before and now fails or warns is recorded, with what may
 *    have changed it: changes made in the app (Conditional Access, admin roles) and the Entra audit log (and Intune's)
 *    between the two scans. People with Security assessment (edit) get a bell; everything is in the Audit Log. A
 *    person acknowledges each one with a note.
 *  - Scorecard: the score, the failed rules and the drift found, as automatic Scorecard measurables.
 *  - Executive report: one printable page for the client (score and trend, the plan, the top open risks, accepted
 *    risks, drift, what improved).
 *  - House baseline: export the baseline in use (its base, rules added or left out, a note per rule) as a file;
 *    import it at the next client. Rule ids the library does not know are dropped and listed.
 * Table: opsh_sa_drift.
 */
if (!function_exists('opsh_pf_ready')) {

    function opsh_pf_ready(PDO $db): bool { try { $db->query('SELECT 1 FROM opsh_sa_drift LIMIT 1'); return true; } catch (Throwable $e) { return false; } }

    /* ───────────── drift ───────────── */

    /** Rules of the area → the Entra audit categories that can change them. */
    function opsh_pf_area_audit(string $area): array {
        return ['identity' => ['Policy', 'UserManagement'], 'admins' => ['RoleManagement', 'UserManagement'], 'tenant' => ['Policy', 'ApplicationManagement', 'DirectoryManagement'],
                'collab' => ['Policy'], 'devices' => [], 'email' => []][$area] ?? [];
    }
    /** What may have changed things between $from and $to: [[when, who, what, where]] (newest first, at most 100). */
    function opsh_pf_changes(PDO $db, string $from, string $to, ?callable $graph = null): array {
        $out = []; $errs = [];
        foreach ([['opsh_sa_ca_changes', "CONCAT(action, ': ', policy_name)", 'Conditional Access (in the app)'], ['opsh_m365_role_changes', "CONCAT(action, ': ', role_name, ' · ', principal_name)", 'Admin roles (in the app)']] as [$t, $what, $where]) {
            try {
                $q = $db->prepare("SELECT c.at, u.display_name AS who, $what AS what FROM $t c LEFT JOIN opsh_users u ON u.id=c.by_user WHERE c.at > ? AND c.at <= ? ORDER BY c.at DESC LIMIT 50");
                $q->execute([$from, $to]);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = ['when' => $r['at'], 'who' => (string)$r['who'], 'what' => (string)$r['what'], 'where' => $where, 'cat' => $t === 'opsh_sa_ca_changes' ? 'Policy' : 'RoleManagement'];
            } catch (Throwable $e) {}
        }
        $get = $graph ?? (function_exists('opsh_graph_configured') && opsh_graph_configured() ? 'opsh_graph_get' : null);
        if ($get) {
            $iso = fn($d) => gmdate('Y-m-d\TH:i:s\Z', strtotime($d));
            try {
                $f = rawurlencode('activityDateTime ge ' . $iso($from) . ' and activityDateTime le ' . $iso($to));
                foreach ((array)($get('auditLogs/directoryAudits?$filter=' . $f . '&$top=100')['value'] ?? []) as $a) {
                    $by = $a['initiatedBy']['user']['userPrincipalName'] ?? ($a['initiatedBy']['app']['displayName'] ?? '');
                    $tg = implode(', ', array_filter(array_map(fn($t) => (string)($t['displayName'] ?? $t['userPrincipalName'] ?? ''), (array)($a['targetResources'] ?? []))));
                    $out[] = ['when' => date('Y-m-d H:i:s', strtotime((string)$a['activityDateTime'])), 'who' => (string)$by, 'what' => (string)($a['activityDisplayName'] ?? '') . ($tg ? ': ' . $tg : ''), 'where' => 'Entra audit log', 'cat' => (string)($a['category'] ?? '')];
                }
            } catch (Throwable $e) { $errs[] = 'Entra audit log: ' . $e->getMessage(); }
            try {
                $f = rawurlencode('activityDateTime ge ' . $iso($from) . ' and activityDateTime le ' . $iso($to));
                foreach ((array)($get('deviceManagement/auditEvents?$filter=' . $f . '&$top=50')['value'] ?? []) as $a) {
                    $by = $a['actor']['userPrincipalName'] ?? ($a['actor']['applicationDisplayName'] ?? '');
                    $out[] = ['when' => date('Y-m-d H:i:s', strtotime((string)$a['activityDateTime'])), 'who' => (string)$by, 'what' => (string)($a['displayName'] ?? $a['activity'] ?? ''), 'where' => 'Intune audit log', 'cat' => 'Intune'];
                }
            } catch (Throwable $e) { $errs[] = 'Intune audit log: ' . $e->getMessage(); }
        }
        usort($out, fn($a, $b) => strcmp($b['when'], $a['when']));
        return [array_slice($out, 0, 100), $errs, $get !== null];
    }
    /** Compare a run with the one before it; record and announce what passed and now does not. Returns the new rows. */
    function opsh_pf_drift_detect(PDO $db, int $runId, ?callable $graph = null): array {
        if (!opsh_pf_ready($db)) return [];
        $q = $db->prepare('SELECT id, finished_at FROM opsh_sa_runs WHERE id<? ORDER BY id DESC LIMIT 1'); $q->execute([$runId]); $prev = $q->fetch(PDO::FETCH_ASSOC);
        if (!$prev) return [];
        $q = $db->prepare('SELECT finished_at FROM opsh_sa_runs WHERE id=?'); $q->execute([$runId]); $now = (string)$q->fetchColumn();
        $st = function (int $rid) use ($db): array { $q = $db->prepare('SELECT check_id, status FROM opsh_sa_results WHERE run_id=?'); $q->execute([$rid]); return $q->fetchAll(PDO::FETCH_KEY_PAIR); };
        $b = $st($runId);
        // BT124 (code review SEC-14): each rule is compared with the last scan that could read it (a scan in between that
        // could not is skipped), and a warning that turns into a failure is drift too
        $q = $db->prepare("SELECT r.check_id, r.status, r.run_id, u.finished_at FROM opsh_sa_results r JOIN (SELECT check_id, MAX(run_id) AS mr FROM opsh_sa_results WHERE run_id<? AND status IN ('pass','warn','fail') GROUP BY check_id) x ON x.check_id=r.check_id AND x.mr=r.run_id JOIN opsh_sa_runs u ON u.id=r.run_id");
        $q->execute([$runId]); $last = []; foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $last[$r['check_id']] = $r;
        $bl = opsh_sa_baseline_cur($db); $meta = opsh_sa_meta(); $cat = opsh_sa_catalog();
        $hits = []; $from = (string)$prev['finished_at'];
        foreach ($b as $id => $s) {
            $was = $last[$id]['status'] ?? null; if (!$was || !opsh_sa_in_baseline($meta[$id][0] ?? 'strict', $bl, $id)) continue;
            if (($was === 'pass' && in_array($s, ['fail', 'warn'], true)) || ($was === 'warn' && $s === 'fail')) { $hits[$id] = [$s, $was, (int)$last[$id]['run_id'], (string)$last[$id]['finished_at']]; if ($last[$id]['finished_at'] < $from) $from = (string)$last[$id]['finished_at']; }
        }
        if (!$hits) return [];
        [$changes, $errs, $read] = opsh_pf_changes($db, $from, $now ?: date('Y-m-d H:i:s'), $graph);
        $plan = []; try { foreach ($db->query("SELECT i.rule_id, i.decision FROM opsh_sa_plan_items i JOIN opsh_sa_plans p ON p.id=i.plan_id WHERE p.id=(SELECT MAX(id) FROM opsh_sa_plans)")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $plan[$k] = $v; } catch (Throwable $e) {}
        $ins = $db->prepare('INSERT INTO opsh_sa_drift (run_id, prev_run_id, rule_id, from_status, to_status, detected_at, evidence, plan_decision) VALUES (?,?,?,?,?,NOW(),?,?)');
        $rows = [];
        foreach ($hits as $id => [$s, $was, $prid, $pfin]) {
            $cats = opsh_pf_area_audit($cat[$id][0] ?? '');
            $ev = array_values(array_filter($changes, fn($c) => $c['when'] > $pfin && (in_array($c['cat'], $cats, true) || ($c['cat'] === 'Intune' && ($cat[$id][0] ?? '') === 'devices'))));
            $note = !$read ? 'Microsoft 365 was not read for the audit logs: only changes made in the app are listed.' : (($cat[$id][0] ?? '') === 'email' ? 'Exchange changes are not in the Entra audit log: check the Exchange admin center audit.' : '');
            $evidence = ['changes' => array_slice($ev, 0, 10), 'errors' => $errs, 'note' => $note];
            $ins->execute([$runId, $prid, $id, $was, $s, json_encode($evidence, JSON_UNESCAPED_UNICODE), $plan[$id] ?? null]);
            $rows[] = ['id' => (int)$db->lastInsertId(), 'rule_id' => $id, 'title' => $cat[$id][1] ?? $id, 'from' => $was, 'to' => $s, 'decision' => $plan[$id] ?? null, 'who' => array_values(array_unique(array_filter(array_column($evidence['changes'], 'who'))))];
        }
        // tell the people who look after the posture
        $who = []; if (!function_exists('opsh_can')) require_once __DIR__ . '/Perms.php';
        try { foreach ($db->query("SELECT u.id, r.slug AS role_slug, r.permissions AS role_perms FROM opsh_users u LEFT JOIN opsh_roles r ON r.id=u.role_id WHERE u.status='active'")->fetchAll(PDO::FETCH_ASSOC) as $u)
            if ($u['role_slug'] === 'super_admin' || opsh_can($u, 'security_assessment', 'edit')) $who[] = (int)$u['id']; } catch (Throwable $e) {}
        $title = count($rows) === 1 ? 'Security drift: "' . $rows[0]['title'] . '" ' . ($rows[0]['from'] === 'pass' ? 'passed before and ' . ($rows[0]['to'] === 'warn' ? 'warns' : 'fails') . ' now' : 'warned before and fails now')
                                    : 'Security drift: ' . count($rows) . ' rules got worse since they were last read';
        $body = implode('; ', array_map(fn($r) => $r['title'] . ($r['who'] ? ' (changes by ' . implode(', ', array_slice($r['who'], 0, 3)) . ')' : ''), array_slice($rows, 0, 6)));
        foreach ($who as $uid) { try { $db->prepare('INSERT INTO opsh_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)')->execute([$uid, mb_substr($title, 0, 250), mb_substr($body, 0, 1000), 'warning', '#sa_drift']); } catch (Throwable $e) {} }
        if (function_exists('audit_log')) audit_log('sa_drift', 'security_assessment', $runId, 'Security drift after scan #' . $runId, null, ['Rules' => array_map(fn($r) => $r['title'] . ': ' . $r['from'] . ' → ' . $r['to'] . ($r['decision'] ? ' (plan: ' . $r['decision'] . ')' : ''), $rows)]);
        return $rows;
    }
    function opsh_pf_drift_list(PDO $db, int $days = 180): array {
        $cat = opsh_sa_catalog(); $out = [];
        $q = $db->prepare('SELECT d.*, u.display_name AS ack_name FROM opsh_sa_drift d LEFT JOIN opsh_users u ON u.id=d.ack_by WHERE d.detected_at >= NOW() - INTERVAL ? DAY ORDER BY d.id DESC LIMIT 300'); $q->execute([$days]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $now = null; try { $s = $db->prepare('SELECT status FROM opsh_sa_results WHERE run_id=(SELECT MAX(id) FROM opsh_sa_runs) AND check_id=?'); $s->execute([$r['rule_id']]); $now = $s->fetchColumn() ?: null; } catch (Throwable $e) {}
            $out[] = ['id' => (int)$r['id'], 'rule_id' => $r['rule_id'], 'title' => $cat[$r['rule_id']][1] ?? $r['rule_id'], 'severity' => $cat[$r['rule_id']][2] ?? 'medium',
                      'from' => $r['from_status'], 'to' => $r['to_status'], 'detected_at' => $r['detected_at'], 'run_id' => (int)$r['run_id'], 'decision' => $r['plan_decision'],
                      'evidence' => json_decode((string)$r['evidence'], true) ?: [], 'now' => $now, 'fixed_again' => $now === 'pass',
                      'ack_at' => $r['ack_at'], 'ack_by' => $r['ack_name'], 'ack_note' => $r['ack_note']];
        }
        return $out;
    }

    /* ───────────── Scorecard ───────────── */

    function opsh_pf_scorecard_sources(): array {
        return ['sa_score' => 'Security assessment — score (%)', 'sa_failed' => 'Security assessment — failed rules (count)', 'sa_drift' => 'Security assessment — drift: rules that passed and fail again (count)'];
    }
    /** A Scorecard value for [$from, $to): the last scan before the end (score, failed) or the drift found in the range. */
    function opsh_pf_scorecard_value(PDO $db, string $src, string $from, string $to): ?float {
        if ($src === 'sa_drift') { if (!opsh_pf_ready($db)) return null; $q = $db->prepare('SELECT COUNT(*) FROM opsh_sa_drift WHERE detected_at >= ? AND detected_at < ?'); $q->execute([$from, $to]); return (float)$q->fetchColumn(); }
        $q = $db->prepare('SELECT score, counts FROM opsh_sa_runs WHERE finished_at < ? ORDER BY id DESC LIMIT 1'); $q->execute([$to]); $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        if ($src === 'sa_score') return $r['score'] === null ? null : (float)$r['score'];
        if ($src === 'sa_failed') { $c = json_decode((string)$r['counts'], true) ?: []; return isset($c['fail']) ? (float)$c['fail'] : null; }
        return null;
    }

    /* ───────────── executive report ───────────── */

    function opsh_pf_report_data(PDO $db): array {
        $bl = opsh_sa_baseline_cur($db); $B = opsh_sa_baselines(); $cat = opsh_sa_catalog();
        $runs = $db->query("SELECT id, finished_at, score, counts FROM opsh_sa_runs WHERE finished_at >= NOW() - INTERVAL 180 DAY ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $last = $runs ? end($runs) : null;
        $checks = $last ? opsh_sa_results($db, (int)$last['id'], $bl) : [];
        $in = array_values(array_filter($checks, fn($c) => $c['in_baseline'] && !$c['unlicensed']));
        $cov = $checks ? (opsh_sa_view_summary($checks)['coverage'] ?? null) : null;   // BT124 (review SEC-13)
        $plan = null; $items = [];
        if (function_exists('opsh_sp_current_id') && ($pid = opsh_sp_current_id($db))) { $v = opsh_sp_view($db, $pid); $plan = $v['plan'] ?? null; $items = $v['items'] ?? []; }
        $dec = []; foreach ($items as $i) $dec[$i['rule_id']] = $i;
        $sevO = ['high' => 0, 'medium' => 1, 'low' => 2];
        // BT124 (code review SEC-18): a risk accepted until a date that has passed is open again
        $today = date('Y-m-d');
        $expired = fn($i) => ($i['decision'] ?? '') === 'accept' && !empty($i['expires_on']) && $i['expires_on'] < $today;
        $open = array_values(array_filter($in, fn($c) => in_array($c['status'], ['fail', 'warn'], true) && (!in_array($dec[$c['id']]['decision'] ?? '', ['na', 'accept'], true) || (isset($dec[$c['id']]) && $expired($dec[$c['id']])))));
        usort($open, fn($a, $b) => [$sevO[$a['severity']] ?? 3, $a['status'] === 'fail' ? 0 : 1] <=> [$sevO[$b['severity']] ?? 3, $b['status'] === 'fail' ? 0 : 1]);
        $improved = [];
        if (count($runs) > 1) {
            $first = $runs[0]; $q = $db->prepare('SELECT check_id, status FROM opsh_sa_results WHERE run_id=?'); $q->execute([(int)$first['id']]); $was = $q->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($in as $c) if ($c['status'] === 'pass' && in_array($was[$c['id']] ?? '', ['fail', 'warn'], true)) $improved[] = $c['title'];
        }
        $acc = array_values(array_filter($items, fn($i) => $i['decision'] === 'accept' && !$expired($i)));
        $dl = opsh_pf_ready($db) ? opsh_pf_drift_list($db, 90) : []; $drift = array_slice($dl, 0, 10);
        $org = function_exists('opsh_org_name') ? opsh_org_name($db) : '';
        return ['org' => $org, 'baseline' => $B[$bl][0] ?? $bl, 'runs' => array_map(fn($r) => ['at' => $r['finished_at'], 'score' => $r['score'] === null ? null : (int)$r['score']], $runs),
                'last' => $last ? ['at' => $last['finished_at'], 'score' => $last['score'] === null ? null : (int)$last['score'], 'counts' => json_decode((string)$last['counts'], true) ?: []] : null,
                'coverage' => $cov, 'plan' => $plan, 'counts' => array_count_values(array_column($items, 'decision')), 'fixed' => count(array_filter($items, fn($i) => $i['fixed'])), 'open' => array_slice($open, 0, 10), 'open_n' => count($open),
                'accepted' => $acc, 'drift' => $drift, 'drift_n' => count($dl), 'improved' => array_slice($improved, 0, 15)];
    }
    function opsh_pf_report_html(array $d, string $app, string $logo = ''): string {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
        $dt = fn($s) => $s ? date('m/d/Y', strtotime($s)) : '—';
        $pts = array_values(array_filter($d['runs'], fn($r) => $r['score'] !== null)); $svg = '';
        if (count($pts) > 1) {
            $w = 520; $hh = 120; $n = count($pts); $xy = [];
            foreach ($pts as $i => $p) $xy[] = round(10 + $i * ($w - 20) / ($n - 1), 1) . ',' . round($hh - 10 - $p['score'] * ($hh - 20) / 100, 1);
            $svg = '<svg viewBox="0 0 ' . $w . ' ' . $hh . '" width="100%" height="' . $hh . '" role="img" aria-label="Score over time"><polyline fill="none" stroke="#2F6FAE" stroke-width="2.5" points="' . implode(' ', $xy) . '"/>'
                 . '<text x="10" y="12" font-size="10" fill="#777">100%</text><text x="10" y="' . ($hh - 2) . '" font-size="10" fill="#777">0%</text></svg>';
        }
        $c = $d['last']['counts'] ?? []; $S = ['do' => 'Do it', 'task' => 'Task', 'accept' => 'Accepted risk', 'na' => 'Not for us', 'later' => 'Later', 'undecided' => 'Not decided'];
        $li = fn(array $xs) => $xs ? '<ul>' . implode('', array_map(fn($x) => '<li>' . $x . '</li>', $xs)) . '</ul>' : '<p class="m">None.</p>';
        $o = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $h('Security report' . ($d['org'] ? ' — ' . $d['org'] : '')) . '</title><style>'
           . 'body{font:14px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1d2733;max-width:860px;margin:24px auto;padding:0 20px}h1{font-size:24px;margin:0}h2{font-size:16px;margin:22px 0 6px;border-bottom:1px solid #dde3ea;padding-bottom:4px}'
           . '.m{color:#6b7785}.big{font-size:40px;font-weight:800}.row{display:flex;gap:24px;flex-wrap:wrap;align-items:center}.tile{border:1px solid #dde3ea;border-radius:8px;padding:8px 12px;min-width:110px}'
           . 'table{border-collapse:collapse;width:100%}td,th{border-bottom:1px solid #eef1f4;padding:5px 6px;text-align:left;vertical-align:top}th{font-size:12px;color:#6b7785}@media print{body{margin:0}}</style></head><body>'
           . '<div class="row">' . ($logo ? '<img src="' . $h($logo) . '" alt="" style="height:40px">' : '') . '<div><h1>Security report' . ($d['org'] ? ' — ' . $h($d['org']) : '') . '</h1>'
           . '<div class="m">Microsoft 365 tenant · baseline ' . $h($d['baseline']) . ' · ' . $h(date('m/d/Y')) . ' · prepared with ' . $h($app) . '</div></div></div>';
        if (!$d['last']) return $o . '<p>No scan yet.</p></body></html>';
        $o .= '<h2>Where things stand</h2><div class="row"><div><div class="big">' . $h($d['last']['score'] ?? '—') . '%</div><div class="m">score on ' . $dt($d['last']['at']) . ($d['coverage'] !== null && $d['coverage'] < 100 ? ', from ' . (int)$d['coverage'] . '% of the rules (the rest could not be read)' : '') . '</div></div>'
           . '<div class="tile"><b>' . (int)($c['fail'] ?? 0) . '</b> failed</div><div class="tile"><b>' . (int)($c['warn'] ?? 0) . '</b> warnings</div><div class="tile"><b>' . (int)($c['pass'] ?? 0) . '</b> passed</div></div>' . $svg;
        $o .= '<h2>The plan</h2>' . ($d['plan'] ? '<p>Version ' . (int)$d['plan']['version'] . ', ' . ($d['plan']['status'] === 'signed' ? 'signed off by ' . $h($d['plan']['signed_name']) . ' on ' . $dt($d['plan']['signed_at']) : 'draft') . '. '
           . implode(' · ', array_map(fn($k) => $h($S[$k] ?? $k) . ': ' . (int)$d['counts'][$k], array_keys($d['counts']))) . '. Fixed since the plan was built: ' . (int)$d['fixed'] . '.</p>' : '<p class="m">No plan yet.</p>');
        $o .= '<h2>Top open risks (' . (int)$d['open_n'] . ')</h2>' . ($d['open'] ? '<table><tr><th>Risk</th><th>Severity</th><th>Status</th></tr>' . implode('', array_map(fn($x) => '<tr><td>' . $h($x['title']) . '<div class="m">' . $h($x['why_it_matters']) . '</div></td><td>' . $h(ucfirst($x['severity'])) . '</td><td>' . ($x['status'] === 'fail' ? 'Failed' : 'Warning') . '</td></tr>', $d['open'])) . '</table>' : '<p class="m">None.</p>');
        $o .= '<h2>Risks accepted</h2>' . $li(array_map(fn($i) => $h($i['title']) . ' — until ' . $dt($i['expires_on']) . ($i['note'] ? ': “' . $h($i['note']) . '”' : ''), $d['accepted']));
        $o .= '<h2>Improved in the last six months</h2>' . $li(array_map($h, $d['improved']));
        $o .= '<h2>Drift (last 90 days' . ($d['drift_n'] > count($d['drift']) ? ': the latest ' . count($d['drift']) . ' of ' . (int)$d['drift_n'] : '') . ')</h2>' . $li(array_map(fn($x) => $h($x['title']) . ' failed again on ' . $dt($x['detected_at']) . ($x['fixed_again'] ? ' — passes again now' : '') . ($x['ack_note'] ? ' — ' . $h($x['ack_note']) : ''), $d['drift']));
        return $o . '<p class="m" style="margin-top:28px">Built from the latest scan of the tenant\'s settings. Figures are counts of rules, not of people.</p></body></html>';
    }

    /* ───────────── house baseline ───────────── */

    function opsh_pf_house_export(PDO $db, string $name): array {
        $bl = opsh_sa_baseline_cur($db); $h = opsh_sa_house();
        if ($bl === 'house' && $h) $o = $h + ['name' => $h['name']];
        else $o = ['name' => $name, 'base' => $bl, 'add' => [], 'remove' => [], 'notes' => []];
        if (trim($name) !== '') $o['name'] = mb_substr(trim($name), 0, 60);
        return ['kind' => 'security-house-baseline', 'version' => 1, 'name' => $o['name'], 'base' => $o['base'], 'add' => array_values($o['add']), 'remove' => array_values($o['remove']),
                'notes' => (object)$o['notes'], 'exported_at' => date('c'), 'rules' => count(array_filter(opsh_sa_meta(), fn($m, $id) => opsh_sa_in_baseline($m[0], $bl, $id), ARRAY_FILTER_USE_BOTH))];
    }
    /** Validate a house baseline file. Returns [house, dropped ids]. Throws InvalidArgumentException. */
    function opsh_pf_house_clean(array $j): array {
        if (($j['kind'] ?? '') !== 'security-house-baseline') throw new InvalidArgumentException('This is not a house baseline file.');
        if ((int)($j['version'] ?? 0) !== 1) throw new InvalidArgumentException('This house baseline file comes from a newer version. Update first.');
        $name = trim((string)($j['name'] ?? '')); if (mb_strlen($name) < 2) throw new InvalidArgumentException('The house baseline has no name.');
        $base = (string)($j['base'] ?? ''); if (!in_array($base, ['essentials', 'standard', 'strict'], true)) throw new InvalidArgumentException('The house baseline starts from an unknown baseline.');
        $cat = opsh_sa_catalog(); $drop = [];
        $ids = function ($xs) use ($cat, &$drop) { $o = []; foreach (array_slice((array)$xs, 0, 500) as $x) { $x = (string)$x; if (isset($cat[$x])) $o[$x] = true; elseif ($x !== '') $drop[] = mb_substr($x, 0, 60); } return array_keys($o); };
        $add = $ids($j['add'] ?? []); $rem = array_values(array_diff($ids($j['remove'] ?? []), $add));
        $notes = []; foreach ((array)($j['notes'] ?? []) as $k => $v) { if (isset($cat[$k]) && is_string($v) && trim($v) !== '') $notes[$k] = mb_substr(trim(strip_tags($v)), 0, 300); }
        return [['name' => mb_substr($name, 0, 60), 'base' => $base, 'add' => $add, 'remove' => $rem, 'notes' => $notes], array_values(array_unique($drop))];
    }
    function opsh_pf_house_save(PDO $db, array $h, string $from, int $by): void {
        $h['from'] = mb_substr($from, 0, 60); $h['at'] = date('c');
        $db->prepare('INSERT INTO opsh_settings (skey,sval,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval), updated_by=VALUES(updated_by)')->execute(['sa_house', json_encode($h, JSON_UNESCAPED_UNICODE), $by]);
        opsh_sa_house(true);
    }
}
