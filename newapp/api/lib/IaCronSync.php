<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Integration Audit: fill the registry from the server's cron list (BT64).
 *
 * The cron list is read from the server (WHM API 1 → cPanel API 2 Cron::listcron for one cPanel account, through the
 * Server Guardian WHM connection) or pasted (crontab -l, or the "Current Cron Jobs" table copied from cPanel).
 * Each line is matched to a registry entry by its job identifiers (the same rule attribution uses: the identifier
 * appears in the cron command). One match → the line becomes that entry's cron schedule. No match → a new entry
 * (named after the script, or the script and its first argument, such as "cron.php staging_janitor"). More than one
 * equally good match → nothing is done for that line; it is listed for a person to decide.
 *
 * Applying it: the entry's earlier "from the cron list" and BT16 seed schedules are replaced by the lines found; a
 * Candidate becomes Active; Unverified becomes Partial — the job is configured on the server, a run has not been seen
 * as evidence yet. Verified is never lowered. "Server backups (cPanel / WHM)" is linked to IT Operations → Backups
 * when the WHM backups are watched there. Commands are stored with tokens and passwords masked.
 */
require_once __DIR__ . '/IntegrationAudit.php';
if (!function_exists('opsh_ia_cron_lines')) {
    /** Parse a cron list. Returns [lines => [[expr, command, raw_no]], errors => [..]]. */
    function opsh_ia_cron_lines(string $text): array {
        $out = []; $err = []; $seen = [];
        foreach (preg_split('/\r?\n/', $text) as $i => $line) {
            $line = trim(preg_replace('/\s+(Edit\s+Delete|Edit|Delete)\s*$/i', '', trim($line)));   // copied from the cPanel table
            if ($line === '' || $line[0] === '#' || preg_match('/^(SHELL|MAILTO|PATH|HOME|CRON_TZ)\s*=/i', $line)) continue;
            if (preg_match('/^(minute|current cron jobs)\b/i', $line)) continue;
            if (preg_match('/^(@\w+)\s+(.+)$/', $line, $m)) { $expr = $m[1]; $cmd = $m[2]; }
            else { $p = preg_split('/\s+/', $line, 6); if (count($p) < 6) { $err[] = 'Line ' . ($i + 1) . ': not "minute hour day month weekday command"'; continue; } $expr = implode(' ', array_slice($p, 0, 5)); $cmd = $p[5]; }
            if (!opsh_ia_cron_parse($expr)) { $err[] = 'Line ' . ($i + 1) . ': the schedule "' . $expr . '" is not understood'; continue; }
            $cmd = opsh_ia_redact(trim($cmd)); $k = $expr . '|' . $cmd;
            if (isset($seen[$k])) continue; $seen[$k] = 1;
            $out[] = ['expr' => $expr, 'command' => mb_substr($cmd, 0, 1000), 'line' => $i + 1];
            if (count($out) >= 500) break;
        }
        return ['lines' => $out, 'errors' => $err];
    }
    /** "Every day at 3:00 AM", "Every 15 minutes", "Mondays at 8:00 AM"… (else the expression itself). */
    function opsh_ia_cron_human(string $e): string {
        $p = preg_split('/\s+/', trim($e));
        if (count($p) !== 5) return ['@hourly' => 'Every hour', '@daily' => 'Every day at 12:00 AM', '@midnight' => 'Every day at 12:00 AM', '@weekly' => 'Sundays at 12:00 AM', '@monthly' => 'The 1st of each month at 12:00 AM', '@yearly' => 'January 1 at 12:00 AM', '@annually' => 'January 1 at 12:00 AM'][strtolower($e)] ?? $e;
        [$mi, $h, $dom, $mon, $dow] = $p;
        $t = fn($hh, $mm) => date('g:i A', mktime((int)$hh, (int)$mm, 0, 1, 1, 2026));
        $days = ['0' => 'Sundays', '7' => 'Sundays', '1' => 'Mondays', '2' => 'Tuesdays', '3' => 'Wednesdays', '4' => 'Thursdays', '5' => 'Fridays', '6' => 'Saturdays'];
        if ($dom === '*' && $mon === '*') {
            if ($h === '*' && $dow === '*') { if ($mi === '*') return 'Every minute'; if (preg_match('/^\*\/(\d+)$/', $mi, $m)) return 'Every ' . $m[1] . ' minutes'; if (ctype_digit($mi)) return 'Every hour at :' . str_pad($mi, 2, '0', STR_PAD_LEFT); if (preg_match('/^[\d,]+$/', $mi)) return 'Every hour at :' . implode(', :', array_map(fn($x) => str_pad($x, 2, '0', STR_PAD_LEFT), explode(',', $mi))); }
            if (ctype_digit($mi) && preg_match('/^\*\/(\d+)$/', $h, $m) && $dow === '*') return 'Every ' . $m[1] . ' hours at :' . str_pad($mi, 2, '0', STR_PAD_LEFT);
            if (ctype_digit($mi) && ctype_digit($h)) { if ($dow === '*') return 'Every day at ' . $t($h, $mi); if (isset($days[$dow])) return $days[$dow] . ' at ' . $t($h, $mi); if ($dow === '1-5') return 'Weekdays at ' . $t($h, $mi); }
            if (ctype_digit($mi) && preg_match('/^[\d,]+$/', $h) && $dow === '*') return 'Every day at ' . implode(', ', array_map(fn($x) => $t($x, $mi), explode(',', $h)));
        }
        if (ctype_digit($mi) && ctype_digit($h) && ctype_digit($dom) && $mon === '*' && $dow === '*') return 'Day ' . $dom . ' of each month at ' . $t($h, $mi);
        return $e;
    }
    /** What a command runs: the script, plus its first plain argument when the script is a dispatcher (cron.php action). */
    function opsh_ia_cron_job(string $cmd): array {
        $c = preg_replace('/\s*[12]?>\s*\S+(\s+2>&1)?/', ' ', $cmd);                       // drop redirections
        $c = preg_replace('~^\S*flock\s+(-\w+\s+)*\S+\s+~', '', trim($c));                  // flock -n lockfile …
        $tok = preg_split('/\s+/', trim($c));
        $script = ''; $arg = ''; $site = ''; $path = '';
        foreach ($tok as $i => $t) {
            if (preg_match('~\.(php|sh|py|pl)$~i', $t) || ($i > 0 && preg_match('~^/~', $t) && !preg_match('~/(php\d*|bash|sh|python\d*)$~', $t))) {
                $script = basename($t); $path = $t; $n = $tok[$i + 1] ?? '';
                if (preg_match('~^/home\d*/[^/]+/([^/]*\.[^/]+)/~', $t, $sm)) $site = $sm[1];   // the site folder (hub.example.com)
                if ($n !== '' && preg_match('/^[A-Za-z][\w.-]{1,60}$/', $n)) $arg = $n;
                break;
            }
        }
        if ($script === '' && preg_match('~https?://[^\s"\']+~', $c, $m)) { $u = parse_url($m[0]); $script = basename((string)($u['path'] ?? '')) ?: (string)($u['host'] ?? 'web call'); if (preg_match('/action=([\w-]+)/', $m[0], $a)) $arg = $a[1]; }
        if ($script === '') $script = basename($tok[0] ?? 'command');
        $dispatcher = (bool)preg_match('/^(cron|index|run|task|tasks|jobs?|artisan|console)\.(php|sh)$/i', $script) || $script === 'artisan';
        $id = $dispatcher && $arg !== '' ? $arg : $script;
        $name = $dispatcher && $arg !== '' ? $arg . ' — ' . ($site !== '' ? $site . '/' : '') . $script : $script;
        $type = preg_match('/backup|dump|pkgacct/i', $c) ? 'backup' : 'cron';
        return ['id' => $id, 'name' => $name, 'type' => $type, 'site' => $site, 'script' => $script, 'path' => $dispatcher && $arg !== '' ? $path : '', 'dispatcher' => $dispatcher];
    }
    /** The plan: what each line does. Nothing is changed here. */
    function opsh_ia_cron_plan(PDO $db, array $lines, string $account = ''): array {
        $reg = opsh_ia_registry($db, false);
        // OpsHub's own entry always knows its four cron scripts (whatever its stored identifiers say)
        foreach ($reg as &$rr) if (($rr['seed_key'] ?? '') === 'opshub_cron') $rr['job_identifiers'] = array_values(array_unique(array_merge($rr['job_identifiers'], ['cron-sla.php', 'cron-mail.php', 'cron-audit.php', 'cron-uptime.php']))); unset($rr);
        $rows = []; $byInt = []; $creates = [];
        // pass 1: the entries each line could belong to (the longest job identifier found in its command)
        $cands = []; $hits = [];
        foreach ($lines as $n => $l) {
            $low = strtolower($l['command']); $best = []; $bestLen = 0;
            foreach ($reg as $r) foreach ($r['job_identifiers'] as $j) { $j = strtolower(trim((string)$j, '* ')); if ($j === '' || strpos($low, $j) === false) continue;
                $len = strlen($j); if ($len > $bestLen) { $best = [$r]; $bestLen = $len; } elseif ($len === $bestLen && !in_array($r['id'], array_column($best, 'id'), true)) $best[] = $r; }
            $cands[$n] = $best; foreach ($best as $r) $hits[(int)$r['id']] = ($hits[(int)$r['id']] ?? 0) + 1;
        }
        // pass 2: a tie goes to the entry that takes more of these jobs, then to an Active entry over a Candidate
        $lost = [];
        foreach ($lines as $n => $l) {
            $best = $cands[$n];
            if (count($best) > 1) {
                usort($best, fn($x, $y) => [($hits[(int)$y['id']] ?? 0), $y['status'] === 'active' ? 1 : 0] <=> [($hits[(int)$x['id']] ?? 0), $x['status'] === 'active' ? 1 : 0]);
                $k0 = [($hits[(int)$best[0]['id']] ?? 0), $best[0]['status'] === 'active' ? 1 : 0]; $k1 = [($hits[(int)$best[1]['id']] ?? 0), $best[1]['status'] === 'active' ? 1 : 0];
                if ($k0 !== $k1) { foreach (array_slice($best, 1) as $x) $lost[(int)$x['id']] = $x; $best = [$best[0]]; }
            }
            $job = opsh_ia_cron_job($l['command']);
            $row = ['line' => $l['line'], 'expr' => $l['expr'], 'when' => opsh_ia_cron_human($l['expr']), 'command' => $l['command'], 'job' => $job['id'], '_j' => $job];
            if (count($best) === 1) { $row += ['do' => 'attach', 'integration_id' => (int)$best[0]['id']]; }
            elseif (count($best) > 1) { $row += ['do' => 'decide', 'integration' => implode(' / ', array_column($best, 'name')), 'why' => 'It matches several entries equally — open them and make the identifiers more precise.']; }
            else { $row += ['do' => 'create', 'cname' => $job['name']]; }
            $rows[$n] = $row;
        }
        // BT65: the jobs one script runs (cron.php <job>) belong together. A job no entry knows yet joins the entry that
        // already holds that script's other jobs (its job name is added to that entry's identifiers), and an entry an
        // earlier fill made for one of those jobs is folded into it (and retired). Two real entries sharing one script
        // are left as they are. With no entry for the script yet, its new jobs become one new entry.
        $adds = []; $merge = []; $grp = [];
        $isFill = fn(int $id) => isset($reg[$id]) && ($reg[$id]['monitoring_method'] ?? '') === 'Cron list' && empty($reg[$id]['opsh_managed']);
        foreach ($rows as $n => $r) if ($r['_j']['path'] !== '') $grp[$r['_j']['path']][] = $n;
        foreach ($grp as $path => $idx) {
            $owners = []; foreach ($idx as $n) if ($rows[$n]['do'] === 'attach') $owners[$rows[$n]['integration_id']] = 1;
            $main = array_values(array_filter(array_keys($owners), fn($id) => !$isFill($id)));
            if (count($main) > 1) continue;
            if (!$main) { $fb = [];   // the entry whose recorded schedules already run this script
                foreach ($reg as $r) if (!$isFill((int)$r['id'])) foreach ($r['schedules'] as $sc) if (stripos((string)$sc['notes'], $path) !== false) $fb[(int)$r['id']] = 1;
                if (count($fb) === 1) $main = array_keys($fb); }
            if (!$main && count($owners) === 1) $main = array_keys($owners);
            $sname = basename($path);
            if ($main) { $M = $main[0];
                foreach ($idx as $n) { $r = $rows[$n]; $X = $r['do'] === 'attach' ? $r['integration_id'] : 0;
                    $fold = $X && $X !== $M && $isFill($X) && !array_filter($rows, fn($o) => ($o['integration_id'] ?? 0) === $X && $o['_j']['path'] !== $path);
                    if ($r['do'] === 'create' || $fold) {
                        $rows[$n] = array_diff_key($r, ['cname' => 1]); $rows[$n]['do'] = 'attach'; $rows[$n]['integration_id'] = $M; $rows[$n]['joined'] = true;
                        $rows[$n]['why'] = $fold ? 'Moved here from "' . $reg[$X]['name'] . '" — the same script (' . $sname . ') runs both.' : 'A new job of ' . $sname . ' — added to the entry that holds its other jobs.';
                        if (!in_array(strtolower($r['job']), array_map(fn($j) => strtolower(trim((string)$j, '* ')), $reg[$M]['job_identifiers'] ?? []), true)) $adds[$M][strtolower($r['job'])] = $r['job'];
                        if ($fold) $merge[$X] = $M;
                    } } }
            else {  // only new jobs: one new entry for the script
                $cr = array_values(array_filter($idx, fn($n) => $rows[$n]['do'] === 'create'));
                if (count(array_unique(array_map(fn($n) => $rows[$n]['job'], $cr))) > 1) { $j = $rows[$cr[0]]['_j'];
                    foreach ($cr as $n) $rows[$n]['cname'] = ($j['site'] !== '' ? $j['site'] . '/' : '') . $sname . ' — scheduled jobs'; }
            }
        }
        foreach ($rows as $n => &$row) {
            if ($row['do'] === 'attach') { $r = $reg[$row['integration_id']]; $row += ['integration' => $r['name'], 'status_now' => $r['status'], 'verification_now' => $r['verification']]; $byInt[(int)$r['id']][] = $row; }
            elseif ($row['do'] === 'create') { $job = $row['_j']; $k = strtolower($row['cname']);
                if (!isset($creates[$k])) $creates[$k] = ['name' => $row['cname'], 'type' => $job['type'], 'ids' => [], 'site' => $job['site'], 'lines' => []];
                $creates[$k]['ids'][strtolower($job['id'])] = $job['id']; if ($job['type'] === 'backup') $creates[$k]['type'] = 'backup';
                $creates[$k]['lines'][] = $row; $row['integration'] = $row['cname'] . ($account !== '' && $job['site'] === '' ? ' (' . $account . ')' : ''); }
            unset($row['_j'], $row['cname']);
        } unset($row);
        foreach ($byInt as &$ls) foreach ($ls as &$x) unset($x['_j'], $x['cname']); unset($ls, $x);
        foreach ($creates as &$c) foreach ($c['lines'] as &$x) unset($x['_j'], $x['cname']); unset($c, $x);
        $rows = array_values($rows);
        // the entries that receive jobs (their names can be corrected in the preview), and leftover Candidates to retire:
        // a Candidate that lost every one of its jobs to another entry is a duplicate guess
        $targets = [];
        foreach ($byInt as $iid => $ls) { $r = array_values(array_filter($reg, fn($x) => (int)$x['id'] === $iid))[0];
            $old = 0; try { $q = $db->prepare("SELECT COUNT(*) FROM opsh_integration_schedules WHERE integration_id=?"); $q->execute([$iid]); $old = (int)$q->fetchColumn(); } catch (Throwable $e) {}
            $targets[] = ['id' => $iid, 'name' => $r['name'], 'status' => $r['status'], 'verification' => $r['verification'], 'jobs' => count($ls), 'old_schedules' => $old, 'opsh_managed' => !empty($r['opsh_managed']), 'adds' => array_values($adds[$iid] ?? [])]; }
        $retire = [];
        foreach ($merge as $iid => $M) $retire[] = ['id' => $iid, 'name' => $reg[$iid]['name'], 'goes_to' => $reg[$M]['name'], 'merged' => true];
        foreach ($lost as $iid => $r) if (!isset($byInt[$iid]) && !isset($merge[$iid]) && $r['status'] === 'candidate' && empty($r['opsh_managed'])) {
            $won = []; foreach ($rows as $x) if (($x['do'] ?? '') === 'attach' && in_array($iid, array_map(fn($b) => (int)$b['id'], $cands[array_search($x, $rows, true)] ?? []), true)) $won[] = $x['integration'];
            $retire[] = ['id' => $iid, 'name' => $r['name'], 'goes_to' => implode(', ', array_unique($won))]; }
        // entries that look for a cron job but were not in this list (information only)
        $missing = [];
        foreach ($reg as $r) if ($r['status'] !== 'retired' && in_array($r['type'], ['cron', 'backup'], true) && ($r['seed_key'] ?? '') !== 'server_backups' && $r['job_identifiers'] && !isset($byInt[(int)$r['id']])) $missing[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'status' => $r['status']];
        // Server backups ↔ IT Operations → Backups
        $bk = null;
        try { $s = $db->query("SELECT * FROM opsh_backup_jobs WHERE kind='whm' LIMIT 1"); $w = $s ? $s->fetch(PDO::FETCH_ASSOC) : null;
              $sb = array_values(array_filter($reg, fn($r) => ($r['seed_key'] ?? '') === 'server_backups'))[0] ?? null;
              if ($w && $sb) { $fresh = $w['last_ok_at'] && strtotime($w['last_ok_at']) > time() - 2 * 86400 && $w['state'] === 'ok';
                  $bk = ['integration_id' => (int)$sb['id'], 'integration' => $sb['name'], 'every_hours' => (int)$w['expect_hours'], 'state' => $w['state'], 'last_ok_at' => $w['last_ok_at'],
                         'last_backup_date' => $w['last_backup_date'], 'verification' => $fresh ? 'verified' : 'partial'];
                  if ($sb['verification'] === 'verified' && !$fresh) $bk['verification'] = 'verified'; } } catch (Throwable $e) {}
        return ['targets' => $targets, 'retire' => $retire, 'rows' => $rows, 'attach' => count(array_filter($rows, fn($r) => $r['do'] === 'attach')), 'create' => count($creates), 'decide' => count(array_filter($rows, fn($r) => $r['do'] === 'decide')),
                'new_entries' => array_values(array_map(fn($c) => ['name' => $c['name'], 'type' => $c['type'], 'schedules' => count($c['lines']), 'jobs' => array_values($c['ids'])], $creates)), 'not_in_list' => $missing, 'backups' => $bk,
                '_by' => $byInt, '_creates' => $creates, '_adds' => $adds];
    }
    /** Apply the plan. Returns [created, updated, schedules] and writes the Audit Log. */
    function opsh_ia_cron_apply(PDO $db, array $plan, string $account, string $source, ?int $uid, array $names = [], bool $retire = true): array {
        $tz = opsh_ia_server_tz(); $stamp = date('m/d/Y g:i A'); $note = 'From the cron list ' . ($account !== '' ? 'of ' . $account . ' ' : '') . '(' . $source . ', ' . $stamp . ')';
        $insS = $db->prepare("INSERT INTO opsh_integration_schedules (integration_id, schedule_type, cron_expr, frequency_label, timezone, tolerance_minutes, enabled, notes) VALUES (?, 'cron', ?, ?, ?, 15, 1, ?)");
        $delS = $db->prepare("DELETE FROM opsh_integration_schedules WHERE integration_id=? AND (notes LIKE 'From the cron list%' OR notes LIKE 'Seeded by BT16%')");
        // an entry that receives jobs: every cron schedule it had is replaced by the real ones (the server is the source of truth)
        $delAll = $db->prepare("DELETE FROM opsh_integration_schedules WHERE integration_id=? AND (schedule_type IN ('cron','unknown') OR notes LIKE 'From the cron list%' OR notes LIKE 'Seeded by BT16%')");
        $created = 0; $updated = 0; $sched = 0; $log = [];
        $db->beginTransaction();
        try {
            foreach ($plan['_by'] as $iid => $lines) {
                $r = $db->prepare('SELECT * FROM opsh_integration_registry WHERE id=?'); $r->execute([$iid]); $cur = $r->fetch(PDO::FETCH_ASSOC); if (!$cur) continue;
                $delAll->execute([$iid]);
                foreach ($lines as $l) { $insS->execute([$iid, $l['expr'], $l['when'], $tz, $note . ' — ' . mb_substr($l['command'], 0, 300)]); $sched++; }
                // a name corrected in the preview
                // the jobs now run with PHP on the server (not as web calls): the connection follows
                $web = (bool)array_filter($lines, fn($l) => preg_match('~\b(curl|wget)\b|https?://~i', $l['command']));
                if (!$web && preg_match('~https?|curl|wget|web call~i', (string)$cur['connection_method'])) {
                    $scr = opsh_ia_cron_job($lines[0]['command']); $cm = 'cPanel cron → PHP (' . preg_replace('/\s.*$/', '', $scr['site'] !== '' ? $scr['site'] . '/' : '') . (preg_match('~(\S+\.(php|sh))~', $lines[0]['command'], $sm) ? basename($sm[1]) : 'script') . ')';
                    $db->prepare('UPDATE opsh_integration_registry SET connection_method=? WHERE id=?')->execute([mb_substr($cm, 0, 120), $iid]);
                    $log[] = $cur['name'] . ': connection "' . $cur['connection_method'] . '" → "' . $cm . '" (the jobs run with PHP now, not as web calls)';
                }
                if (!empty($plan['_adds'][$iid])) {   // BT65: the new jobs of the same script become this entry's identifiers
                    $ids = opsh_ia_list($cur['job_identifiers']); $new = array_values(array_diff($plan['_adds'][$iid], $ids));
                    if ($new) { $db->prepare('UPDATE opsh_integration_registry SET job_identifiers=? WHERE id=?')->execute([json_encode(array_values(array_merge($ids, $new))), $iid]);
                        $log[] = $cur['name'] . ': job identifiers + ' . implode(', ', $new) . ' (old: ' . (implode(', ', $ids) ?: 'none') . ')'; } }
                $nn = trim((string)($names[$iid] ?? $names[(string)$iid] ?? ''));
                if ($nn !== '' && $nn !== $cur['name'] && mb_strlen($nn) <= 190) { $db->prepare('UPDATE opsh_integration_registry SET name=? WHERE id=?')->execute([$nn, $iid]); $log[] = 'Renamed "' . $cur['name'] . '" → "' . $nn . '"'; $cur['name'] = $nn; }
                $st = $cur['status'] === 'candidate' ? 'active' : $cur['status']; $ve = $cur['verification'] === 'unverified' ? 'partial' : $cur['verification'];
                $add = date('Y-m-d') . ': ' . count($lines) . ' cron schedule(s) found on the server (configured; a run not yet seen as evidence).';
                $db->prepare('UPDATE opsh_integration_registry SET status=?, verification=?, notes=CONCAT(COALESCE(notes,\'\'), IF(COALESCE(notes,\'\')=\'\', \'\', \'\n\'), ?) WHERE id=?')->execute([$st, $ve, $add, $iid]);
                $updated++; $log[] = $cur['name'] . ': ' . count($lines) . ' schedule(s)' . ($st !== $cur['status'] ? ', ' . $cur['status'] . ' → ' . $st : '') . ($ve !== $cur['verification'] ? ', ' . $cur['verification'] . ' → ' . $ve : '');
            }
            $ins = $db->prepare("INSERT INTO opsh_integration_registry (name, type, status, verification, purpose, source_system, connection_method, job_identifiers, monitoring_method, opsh_managed, created_by, notes) VALUES (?,?, 'active', 'partial', ?,?, 'cron', ?, 'Cron list', 0, ?, ?)");
            foreach ($plan['_creates'] as $c) {
                $name = mb_substr($c['name'] . ($account !== '' && ($c['site'] ?? '') === '' ? ' (' . $account . ')' : ''), 0, 190);
                $ex = $db->prepare("SELECT id FROM opsh_integration_registry WHERE name=? AND status<>'retired'"); $ex->execute([$name]);
                $iid = (int)$ex->fetchColumn();
                if (!$iid) { $ins->execute([$name, $c['type'], 'Found in the server\'s cron list — say what it does and who owns it.', ($c['site'] ?? '') !== '' ? $c['site'] : ($account !== '' ? 'cPanel account ' . $account : 'Server'), json_encode(array_values($c['ids'])), $uid, date('Y-m-d') . ': added from the cron list (configured; a run not yet seen as evidence).']); $iid = (int)$db->lastInsertId(); $created++; }
                else { $delS->execute([$iid]); $updated++; }
                foreach ($c['lines'] as $l) { $insS->execute([$iid, $l['expr'], $l['when'], $tz, $note . ' — ' . mb_substr($l['command'], 0, 300)]); $sched++; }
                $log[] = $name . ' (new): ' . count($c['lines']) . ' schedule(s)';
            }
            if ($retire) foreach (($plan['retire'] ?? []) as $x) {
                if (!empty($x['merged'])) {   // BT65: an entry an earlier fill made, folded into the entry of its script
                    $db->prepare("UPDATE opsh_integration_registry SET status='retired', notes=CONCAT(COALESCE(notes,''), IF(COALESCE(notes,'')='', '', '\n'), ?) WHERE id=? AND status<>'retired' AND opsh_managed=0")
                       ->execute([date('Y-m-d') . ': retired — its job now belongs to ' . $x['goes_to'] . ' (same script; cron list).', (int)$x['id']]);
                    $log[] = 'Retired "' . $x['name'] . '" (merged into ' . $x['goes_to'] . ')'; continue; }
                $db->prepare("UPDATE opsh_integration_registry SET status='retired', notes=CONCAT(COALESCE(notes,''), IF(COALESCE(notes,'')='', '', '\n'), ?) WHERE id=? AND status='candidate'")
                   ->execute([date('Y-m-d') . ': retired — its job belongs to ' . $x['goes_to'] . ' (cron list).', (int)$x['id']]);
                $log[] = 'Retired the leftover candidate "' . $x['name'] . '" (its job belongs to ' . $x['goes_to'] . ')';
            }
            if (!empty($plan['backups'])) {
                $b = $plan['backups']; $iid = $b['integration_id'];
                $db->prepare("DELETE FROM opsh_integration_schedules WHERE integration_id=? AND (notes LIKE 'From IT Operations%' OR notes LIKE 'Seeded by BT16%')")->execute([$iid]);
                $db->prepare("INSERT INTO opsh_integration_schedules (integration_id, schedule_type, interval_minutes, frequency_label, timezone, tolerance_minutes, enabled, notes) VALUES (?, 'interval', ?, ?, ?, 180, 1, ?)")
                   ->execute([$iid, $b['every_hours'] * 60, 'Every ' . $b['every_hours'] . ' hours (WHM backup days)', $tz, 'From IT Operations → Backups (WHM), ' . $stamp]);
                $db->prepare("UPDATE opsh_integration_registry SET status=IF(status='candidate','active',status), verification=?, monitoring_method='IT Operations → Backups (WHM, read hourly)' WHERE id=?")->execute([$b['verification'], $iid]);
                $sched++; $updated++; $log[] = $b['integration'] . ': linked to Backups (' . $b['verification'] . ($b['last_backup_date'] ? ', newest backup ' . $b['last_backup_date'] : '') . ')';
            }
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        audit_log('ia_cron_sync', 'integration_audit', 0, 'Registry from the cron list' . ($account !== '' ? ' (' . $account . ')' : ''), null,
            ['Source' => $source, 'Lines' => (string)count($plan['rows']), 'Entries updated' => (string)$updated, 'Entries created' => (string)$created, 'Schedules' => (string)$sched, 'Not matched (to decide)' => (string)$plan['decide'], 'Changes' => mb_substr(implode('; ', $log), 0, 3000)]);
        return ['created' => $created, 'updated' => $updated, 'schedules' => $sched, 'changes' => $log];
    }
    /** Read one cPanel account's cron list through WHM (cPanel API 2 Cron::listcron). Returns crontab text. */
    function opsh_ia_cron_read_whm(PDO $db, string $user): array {
        require_once __DIR__ . '/Guardian.php';
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $user)) return ['ok' => false, 'error' => 'Type the cPanel account name (letters and numbers).'];
        $c = opsh_whm_conn(opsh_guardian_settings($db));
        if (!$c) return ['ok' => false, 'error' => 'WHM is not connected (Server Guardian → Server actions → WHM host and API token). Paste the cron list instead.'];
        $r = opsh_whm_call($c, 'cpanel', ['cpanel_jsonapi_user' => $user, 'cpanel_jsonapi_module' => 'Cron', 'cpanel_jsonapi_func' => 'listcron', 'cpanel_jsonapi_apiversion' => 2], 20);
        $rows = [];
        $walk = function ($x) use (&$walk, &$rows) { if (!is_array($x)) return; if (isset($x['command'], $x['minute'])) { $rows[] = $x; return; } foreach ($x as $v) $walk($v); };
        $walk($r['data'] ?? []);
        $cerr = (string)($r['data']['cpanelresult']['error'] ?? ($r['data']['error'] ?? ''));
        if (!$rows && (!$r['ok'] || $cerr !== '')) return ['ok' => false, 'error' => 'WHM did not return the cron list of "' . $user . '" (' . ($cerr !== '' ? $cerr : ($r['error'] ?: 'HTTP ' . $r['code'])) . '). Check the account name, or paste the list instead (cPanel → Cron Jobs).'];
        $lines = [];
        foreach ($rows as $x) { if (trim((string)$x['command']) === '') continue; $lines[] = implode(' ', [$x['minute'], $x['hour'], $x['day'] ?? '*', $x['month'] ?? '*', $x['weekday'] ?? '*']) . ' ' . $x['command']; }
        return ['ok' => true, 'text' => implode("\n", $lines), 'count' => count($lines)];
    }
    /** The cPanel account this app runs under (/home/<user>/…), as a default. */
    function opsh_ia_cron_default_user(): string { $p = realpath(__DIR__ . '/../..') ?: ''; return preg_match('~^/home\d*/([a-z][a-z0-9_]{0,31})/~', $p, $m) ? $m[1] : ''; }
}
