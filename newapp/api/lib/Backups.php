<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — backup monitoring (BT62). IT Operations → Servers → Backups.
 *
 * Two kinds of backup are watched:
 *   whm      the backups WHM makes (Backup Configuration): ITOps reads WHM's backup settings and the dates that have a
 *            backup (WHM API 1 backup_config_get, backup_date_list, backup_user_list) at most once an hour. A new backup date
 *            is a backup done; backups turned off in WHM is a failure; how often a backup is expected follows the days
 *            WHM is set to run them.
 *   checkin  any backup script (for example copyBackups.sh): it checks in when it finishes — on this server with
 *              php …/backup-checkin.php <name> [ok|failed] ["note"]
 *            (no key: only someone on the server can run it), or from another server with its private address
 *              curl -fsS "https://…/api/backups/checkin?key=…&status=ok"
 *            The key is stored as a SHA-256 hash and shown once.
 * A backup is late when nothing successful arrived within its interval plus the grace time. Administrators are told once
 * when a backup fails, is late or is turned off, and once when it is fine again (bell and email "Backup alert").
 * BT71: WHM makes the date folder of a backup when the nightly run STARTS and adds each account as it finishes. So a new
 * date is only recorded once every account WHM is meant to back up is in it (accounts with backups on in WHM; suspended
 * accounts only when WHM backs them up). Until then it is "running" (read again every 20 minutes); if accounts are still
 * missing 12 hours after the date appeared, it is recorded as failed and the missing accounts are named.
 * Every result also goes to the Event Log channel "backup", so Server Guardian's "Backup assurance" card and the older
 * Scorecard source "Backup verification success" see it too.
 * Scorecard: bkp_ontime_pct (the backups chosen for the Scorecard, % of expected backups that arrived on time),
 * bkp_missed (count), bkp_j_<id> (one backup, %).
 */
if (!function_exists('itops_bk_ready')) {
    function itops_bk_ready(PDO $db): bool {
        static $ok = null; if ($ok !== null) return $ok;
        try { $db->query('SELECT 1 FROM itops_backup_jobs LIMIT 1'); return $ok = true; } catch (Throwable $e) { return $ok = false; }
    }
    function itops_bk_cfg(PDO $db): array {
        $d = ['notify' => true, 'email' => true];
        try { $j = json_decode((string)$db->query("SELECT sval FROM itops_settings WHERE skey='backup_cfg'")->fetchColumn(), true); } catch (Throwable $e) { $j = null; }
        if (is_array($j)) foreach ($d as $k => $v) if (array_key_exists($k, $j)) $d[$k] = (bool)$j[$k];
        return $d;
    }
    function itops_bk_row(array $r): array {
        return ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'kind' => (string)$r['kind'], 'slug' => (string)$r['slug'], 'has_key' => !empty($r['key_hash']), 'key_hint' => (string)$r['key_hint'],
            'expect_hours' => (int)$r['expect_hours'], 'grace_min' => (int)$r['grace_min'], 'active' => (bool)$r['active'], 'alert' => (bool)$r['alert'], 'on_scorecard' => (bool)$r['on_scorecard'],
            'state' => (string)$r['state'], 'state_since' => $r['state_since'], 'last_ok_at' => $r['last_ok_at'], 'last_seen_at' => $r['last_seen_at'], 'last_detail' => (string)$r['last_detail'],
            'last_read_at' => $r['last_read_at'], 'last_backup_date' => $r['last_backup_date'], 'notes' => (string)$r['notes'], 'created_at' => $r['created_at'],
            'running' => !empty($r['pending_date']) ? ['date' => $r['pending_date'], 'since' => $r['pending_since']] : null,
            'due_at' => date('Y-m-d H:i:s', itops_bk_due_ts($r))];
    }
    function itops_bk_get(PDO $db, int $id): ?array { $s = $db->prepare('SELECT * FROM itops_backup_jobs WHERE id=?'); $s->execute([$id]); $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
    function itops_bk_by_slug(PDO $db, string $slug): ?array { $s = $db->prepare('SELECT * FROM itops_backup_jobs WHERE slug=?'); $s->execute([strtolower($slug)]); $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
    /** When the next successful backup is due at the latest (interval + grace after the last good one, or after it was added). */
    function itops_bk_due_ts(array $r): int {
        // from the last good backup, or from when it was added / switched back on, whichever is later
        $base = max((int)strtotime((string)($r['last_ok_at'] ?: $r['created_at'])), (int)strtotime((string)($r['wait_from'] ?? $r['created_at'])));
        return $base + (int)$r['expect_hours'] * 3600 + (int)$r['grace_min'] * 60;
    }
    function itops_bk_slug(string $s): string { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-'); }
    /** Clean a backup from the form. Throws InvalidArgumentException with a sentence for the person. */
    function itops_bk_clean(array $in, ?array $cur): array {
        $o = $cur ? ['name' => $cur['name'], 'kind' => $cur['kind'], 'slug' => $cur['slug'], 'expect_hours' => (int)$cur['expect_hours'], 'grace_min' => (int)$cur['grace_min'],
                     'alert' => (bool)$cur['alert'], 'on_scorecard' => (bool)$cur['on_scorecard'], 'active' => (bool)$cur['active'], 'notes' => (string)$cur['notes']]
                  : ['name' => '', 'kind' => 'checkin', 'slug' => '', 'expect_hours' => 24, 'grace_min' => 120, 'alert' => true, 'on_scorecard' => true, 'active' => true, 'notes' => ''];
        if (!$cur && isset($in['kind'])) { if (!in_array($in['kind'], ['checkin', 'whm'], true)) throw new InvalidArgumentException('Choose what kind of backup this is.'); $o['kind'] = $in['kind']; }
        if (!$cur) $o['on_scorecard'] = $o['kind'] === 'whm';   // BT63: the WHM backups count on the Scorecard; a backup script (a copy) is only watched, unless ticked
        if (array_key_exists('name', $in)) $o['name'] = trim((string)$in['name']);
        if ($o['name'] === '' || mb_strlen($o['name']) > 120) throw new InvalidArgumentException('Give the backup a name (120 characters at most).');
        if (array_key_exists('slug', $in) || !$cur) { $sl = itops_bk_slug((string)($in['slug'] ?? '') !== '' ? (string)$in['slug'] : $o['name']);
            if (strlen($sl) < 2 || strlen($sl) > 60) throw new InvalidArgumentException('The short name (used by the check-in command) has 2 to 60 letters, numbers or dashes.'); $o['slug'] = $sl; }
        if (array_key_exists('expect_hours', $in)) { $h = $in['expect_hours']; if (!is_numeric($h) || (int)$h < 1 || (int)$h > 744) throw new InvalidArgumentException('Expect a backup every 1 to 744 hours (31 days).'); $o['expect_hours'] = (int)$h; }
        if (array_key_exists('grace_min', $in)) { $g = $in['grace_min']; if (!is_numeric($g) || (int)$g < 0 || (int)$g > 1440) throw new InvalidArgumentException('The grace time is 0 to 1,440 minutes (a day).'); $o['grace_min'] = (int)$g; }
        foreach (['alert', 'on_scorecard', 'active'] as $k) if (array_key_exists($k, $in)) $o[$k] = (bool)$in[$k];
        if (array_key_exists('notes', $in)) { $o['notes'] = trim((string)$in['notes']); if (mb_strlen($o['notes']) > 1000) throw new InvalidArgumentException('The notes are 1,000 characters at most.'); }
        return $o;
    }
    /** A new check-in key: [key (shown once), sha256, hint]. */
    function itops_bk_newkey(): array { $k = bin2hex(random_bytes(24)); return [$k, hash('sha256', $k), substr($k, 0, 6)]; }

    /**
     * Record a result for a backup and move its state. $kind: checkin | whm | missed | off. Returns the state change
     * ('failed', 'late', 'off', 'back') or ''.
     */
    function itops_bk_record(PDO $db, array $job, bool $ok, string $kind, string $detail, string $via, ?string $backupDate = null, ?float $sizeMb = null, ?int $durS = null): string {
        $now = date('Y-m-d H:i:s'); $detail = mb_substr(trim($detail), 0, 500);
        $db->prepare('INSERT INTO itops_backup_events (job_id, at, ok, kind, detail, via, backup_date, size_mb, duration_s) VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([(int)$job['id'], $now, $ok ? 1 : 0, $kind, $detail, $via, $backupDate, $sizeMb, $durS]);
        $old = (string)$job['state'];
        $new = $ok ? 'ok' : ($kind === 'missed' ? 'late' : ($kind === 'off' ? 'off' : 'failed'));
        $sets = 'state=?, last_detail=?' . ($new !== $old ? ', state_since=?' : '') . ($kind !== 'missed' ? ', last_seen_at=?' : '') . ($ok ? ', last_ok_at=?' : '') . ($backupDate ? ', last_backup_date=?' : '');
        $bind = [$new, $detail]; if ($new !== $old) $bind[] = $now; if ($kind !== 'missed') $bind[] = $now; if ($ok) $bind[] = $now; if ($backupDate) $bind[] = $backupDate; $bind[] = (int)$job['id'];
        $db->prepare("UPDATE itops_backup_jobs SET $sets WHERE id=?")->execute($bind);
        if (function_exists('itops_log')) { try { itops_log($ok ? 'info' : 'error', 'backup', ($ok ? 'Backup done: ' : ($kind === 'missed' ? 'Backup LATE: ' : 'Backup FAILED: ')) . $job['name'] . ($detail !== '' ? ' — ' . $detail : ''), ['backup' => (int)$job['id'], 'via' => $via]); } catch (Throwable $e) {} }
        $event = '';
        if (!$ok && $new !== $old) $event = $new;                                   // failed / late / off: once per change
        elseif ($ok && in_array($old, ['failed', 'late', 'off'], true)) $event = 'back';
        if ($event !== '' && !empty($job['alert'])) itops_bk_alert($db, itops_bk_cfg($db), $job, $event, $detail);
        return $event;
    }
    function itops_bk_alert(PDO $db, array $cfg, array $job, string $event, string $detail): void {
        $title = ['failed' => 'Backup FAILED: ', 'late' => 'Backup LATE: ', 'off' => 'Backups OFF: ', 'back' => 'Backup OK again: '][$event] . $job['name'];
        $state = ['failed' => 'failed', 'late' => 'late', 'off' => 'turned off', 'back' => 'working again'][$event];
        if (empty($cfg['notify'])) return;
        try {
            $admins = $db->query("SELECT u.id, u.email, u.display_name FROM itops_users u JOIN itops_roles r ON r.id = u.role_id WHERE r.slug IN ('super_admin','admin','dept_admin') AND u.status='active'")->fetchAll(PDO::FETCH_ASSOC);
            $ins = $db->prepare('INSERT INTO itops_notifications (user_id, title, body, type, link) VALUES (?,?,?,?,?)');
            foreach ($admins as $a) {
                $ins->execute([(int)$a['id'], $title, $detail, $event === 'back' ? 'info' : 'error', 'backups']);
                if (!empty($cfg['email']) && !empty($a['email']) && function_exists('itops_email')) {
                    try { itops_email('backup_alert', (string)$a['email'], ['backup' => $job['name'], 'state' => $state, 'state_es' => ['failed' => 'falló', 'late' => 'atrasado', 'off' => 'desactivado', 'back' => 'funciona de nuevo'][$event], 'detail' => $detail, 'recipient_name' => (string)$a['display_name'],
                        'link' => defined('APP_URL') ? rtrim(APP_URL, '/') . '/#backups' : ''], ['user_id' => (int)$a['id']]); } catch (Throwable $e) {}
                }
            }
        } catch (Throwable $e) {}
    }
    function itops_bk_when(?string $ts): string { return $ts ? date('m/d/Y g:i A', strtotime($ts)) : 'never'; }

    // ── WHM backups ───────────────────────────────────────────────────────────────────────────────────────────

    /** The days WHM runs backups → the longest gap in hours (24 when every day). Null when WHM does not say. */
    function itops_bk_whm_gap(array $c): ?int {
        $daily = (string)($c['backup_daily_enable'] ?? '1') !== '0';
        if (!$daily) { if ((string)($c['backup_weekly_enable'] ?? '0') === '1') return 168; if ((string)($c['backup_monthly_enable'] ?? '0') === '1') return 744; return null; }
        $days = array_values(array_unique(array_filter(array_map('intval', array_filter(explode(',', (string)($c['backupdays'] ?? '0,1,2,3,4,5,6')), 'strlen')), fn($d) => $d >= 0 && $d <= 6)));
        if (!$days) return 24; sort($days); $gap = 1;
        for ($i = 0; $i < count($days); $i++) { $next = $days[($i + 1) % count($days)] + ($i + 1 === count($days) ? 7 : 0); $gap = max($gap, $next - $days[$i]); }
        return $gap * 24;
    }
    function itops_bk_whm_days(array $c): string {
        if ((string)($c['backup_daily_enable'] ?? '1') === '0') return (string)($c['backup_weekly_enable'] ?? '0') === '1' ? 'weekly' : 'monthly';
        $n = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']; $d = array_filter(explode(',', (string)($c['backupdays'] ?? '0,1,2,3,4,5,6')), 'strlen');
        return count($d) >= 7 ? 'every day' : implode(', ', array_map(fn($x) => $n[(int)$x] ?? $x, $d));
    }
    /** Read WHM's backups for one "whm" backup. Returns ['ok'=>bool, 'event'=>string, 'detail'=>string]. */
    function itops_bk_whm_read(PDO $db, array $job): array {
        require_once __DIR__ . '/Guardian.php';
        $db->prepare('UPDATE itops_backup_jobs SET last_read_at=? WHERE id=?')->execute([date('Y-m-d H:i:s'), (int)$job['id']]);
        $c = itops_whm_conn(itops_guardian_settings($db));
        if (!$c) { $db->prepare('UPDATE itops_backup_jobs SET last_detail=? WHERE id=?')->execute(['WHM is not connected (Server Guardian → Server actions → WHM host and API token).', (int)$job['id']]); return ['ok' => false, 'event' => '', 'detail' => 'WHM is not connected.']; }
        $cf = itops_whm_call($c, 'backup_config_get', [], 15);
        if (!$cf['ok']) { $d = 'WHM did not answer (' . ($cf['error'] ?: 'HTTP ' . $cf['code']) . '). The backups are read again within the hour.'; $db->prepare('UPDATE itops_backup_jobs SET last_detail=? WHERE id=?')->execute([$d, (int)$job['id']]); return ['ok' => false, 'event' => '', 'detail' => $d]; }
        $conf = is_array($cf['data']['backup_config'] ?? null) ? $cf['data']['backup_config'] : (array)$cf['data'];
        $enabled = in_array(strtolower((string)($conf['backupenable'] ?? $conf['backup_enabled'] ?? '0')), ['1', 'yes', 'true', 'on'], true);
        if (!$enabled) {
            if ($job['state'] !== 'off') return ['ok' => true, 'event' => itops_bk_record($db, $job, false, 'off', 'Backups are turned off in WHM (WHM → Backup → Backup Configuration).', 'whm'), 'detail' => 'off'];
            return ['ok' => true, 'event' => '', 'detail' => 'off'];
        }
        $gap = itops_bk_whm_gap($conf);
        if ($gap && $gap !== (int)$job['expect_hours']) { $db->prepare('UPDATE itops_backup_jobs SET expect_hours=? WHERE id=?')->execute([$gap, (int)$job['id']]); $job['expect_hours'] = $gap; }
        $dl = itops_whm_call($c, 'backup_date_list', [], 20);
        if (!$dl['ok']) { $d = 'WHM did not list the backup dates (' . ($dl['error'] ?: 'HTTP ' . $dl['code']) . ').'; $db->prepare('UPDATE itops_backup_jobs SET last_detail=? WHERE id=?')->execute([$d, (int)$job['id']]); return ['ok' => false, 'event' => '', 'detail' => $d]; }
        $raw = []; foreach ((array)($dl['data']['backup_set'] ?? []) as $x) { $s = is_array($x) ? (string)($x['date'] ?? reset($x)) : (string)$x; if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) $raw[$m[1]] = $s; }
        if (!$raw) {
            $d = 'WHM backups are on (' . itops_bk_whm_days($conf) . ') but there is no backup on the server yet.';
            $db->prepare('UPDATE itops_backup_jobs SET last_detail=? WHERE id=?')->execute([$d, (int)$job['id']]);
            if ($job['state'] === 'off') return ['ok' => true, 'event' => itops_bk_record($db, $job, false, 'whm', $d, 'whm'), 'detail' => $d];
            return ['ok' => true, 'event' => '', 'detail' => $d];
        }
        ksort($raw); $newest = array_key_last($raw);
        if (!empty($job['pending_date']) && !isset($raw[$job['pending_date']])) { $db->prepare('UPDATE itops_backup_jobs SET pending_date=NULL, pending_since=NULL WHERE id=?')->execute([(int)$job['id']]); $job['pending_date'] = null; }
        if ($job['last_backup_date'] !== null && $newest <= $job['last_backup_date'] && $job['state'] !== 'off' && ($job['pending_date'] ?? null) !== $newest) {
            $db->prepare('UPDATE itops_backup_jobs SET last_detail=? WHERE id=?')->execute(['Newest backup: ' . date('m/d/Y', strtotime($newest)) . '. WHM runs backups ' . itops_bk_whm_days($conf) . '; ' . count($raw) . ' backup date(s) kept.', (int)$job['id']]);
            return ['ok' => true, 'event' => '', 'detail' => 'no new backup'];
        }
        $pend = $job['pending_date'] ?? null;
        // a backup that was still running when last read, and a newer one has started since: settle it first
        if ($pend && $pend < $newest && isset($raw[$pend])) { $r0 = itops_bk_whm_settle($db, $c, $conf, $job, $pend, $raw[$pend], true); $job = itops_bk_get($db, (int)$job['id']) ?: $job; }
        return itops_bk_whm_settle($db, $c, $conf, $job, $newest, $raw[$newest], false);
    }
    /** The accounts of one backup date: who is in it, who is missing, who WHM does not back up. */
    function itops_bk_whm_accounts(array $c, array $conf, string $restorePoint): array {
        $ul = itops_whm_call($c, 'backup_user_list', ['restore_point' => $restorePoint], 20);
        if (!$ul['ok']) return ['ok' => false, 'error' => (string)($ul['error'] ?: 'HTTP ' . $ul['code'])];
        $inB = []; $rawSt = [];
        foreach ((array)($ul['data']['user'] ?? []) as $u) { if (!is_array($u)) continue; $n = (string)($u['username'] ?? $u['user'] ?? ''); if ($n === '') continue;
            $st = strtolower((string)($u['status'] ?? 'active')); $rawSt[$n] = $st; if ($st !== 'no_backup') $inB[$n] = 1; }
        $suspOk = !in_array(strtolower((string)($conf['backupsuspendedaccounts'] ?? '1')), ['0', 'no', 'false', 'off'], true);
        $accts = []; $la = itops_whm_call($c, 'listaccts', [], 20);
        if ($la['ok']) foreach ((array)($la['data']['acct'] ?? []) as $a) { $n = (string)($a['user'] ?? ''); if ($n !== '') $accts[$n] = $a; }
        $rows = []; $in = []; $missing = []; $excluded = []; $gone = [];
        foreach (array_unique(array_merge(array_keys($accts), array_keys($rawSt))) as $n) {
            $a = $accts[$n] ?? null; $why = '';
            if ($a !== null && array_key_exists('backup', $a) && !(int)$a['backup']) $why = 'backups are off for this account in WHM';
            elseif ($a !== null && (int)($a['suspended'] ?? 0) && !$suspOk) $why = 'suspended — WHM does not back up suspended accounts';
            elseif ($a === null && $la['ok']) $why = 'not an account on this server any more';
            $isIn = isset($inB[$n]);
            if ($isIn) $in[] = $n; elseif ($why === 'not an account on this server any more') $gone[] = $n; elseif ($why !== '') $excluded[] = $n; elseif ($a !== null || isset($rawSt[$n])) $missing[] = $n;
            $rows[] = ['user' => $n, 'domain' => (string)($a['domain'] ?? ''), 'suspended' => (bool)($a['suspended'] ?? 0), 'in_backup' => $isIn, 'excluded' => $why, 'status' => $rawSt[$n] ?? 'not listed'];
        }
        usort($rows, fn($x, $y) => strcmp($x['user'], $y['user'])); sort($in); sort($missing); sort($excluded); sort($gone);
        return ['ok' => true, 'in' => $in, 'missing' => $missing, 'excluded' => $excluded, 'gone' => $gone, 'expected' => count($in) + count($missing), 'rows' => $rows, 'accounts_read' => (bool)$la['ok']];
    }
    /**
     * Decide about one backup date: complete → done; still missing accounts → running (no result yet) or, 12 hours after the
     * date appeared (or once a newer backup started: $final), failed with the missing accounts named.
     */
    function itops_bk_whm_settle(PDO $db, array $c, array $conf, array $job, string $date, string $restorePoint, bool $final): array {
        $A = itops_bk_whm_accounts($c, $conf, $restorePoint); $day = date('m/d/Y', strtotime($date)); $days = itops_bk_whm_days($conf);
        $again = $job['last_backup_date'] !== null && $date <= $job['last_backup_date'];   // already recorded once (read too early before BT71)
        $clear = fn() => $db->prepare('UPDATE itops_backup_jobs SET pending_date=NULL, pending_since=NULL WHERE id=?')->execute([(int)$job['id']]);
        if (!$A['ok']) { $clear(); $d = 'Backup of ' . $day . '. WHM did not list its accounts (' . $A['error'] . '). WHM runs backups ' . $days . '.';
            return ['ok' => true, 'event' => $again ? '' : itops_bk_record($db, $job, true, 'whm', $d, 'whm', $date), 'detail' => $d]; }
        $ex = ($A['excluded'] ? ' Not backed up by WHM on purpose: ' . implode(', ', array_slice($A['excluded'], 0, 8)) . '.' : '') . ($A['gone'] ? ' No longer on the server: ' . implode(', ', array_slice($A['gone'], 0, 8)) . '.' : '');
        if (!$A['missing']) {
            $clear(); $d = ($again ? 'Checked again — ' : '') . 'Backup of ' . $day . ': ' . count($A['in']) . ' of ' . $A['expected'] . ' account(s), complete.' . $ex . ' WHM runs backups ' . $days . '.';
            if ($again && $job['state'] === 'ok') { $db->prepare('INSERT INTO itops_backup_events (job_id, at, ok, kind, detail, via, backup_date) VALUES (?,?,1,?,?,?,?)')->execute([(int)$job['id'], date('Y-m-d H:i:s'), 'whm', mb_substr($d, 0, 500), 'whm', $date]);
                $db->prepare('UPDATE itops_backup_jobs SET last_detail=? WHERE id=?')->execute([mb_substr($d, 0, 500), (int)$job['id']]); return ['ok' => true, 'event' => '', 'detail' => $d]; }
            return ['ok' => true, 'event' => itops_bk_record($db, $job, true, 'whm', $d, 'whm', $date), 'detail' => $d];
        }
        $since = ($job['pending_date'] ?? null) === $date && !empty($job['pending_since']) ? (string)$job['pending_since'] : date('Y-m-d H:i:s');
        $mis = implode(', ', array_slice($A['missing'], 0, 8)) . (count($A['missing']) > 8 ? ' and ' . (count($A['missing']) - 8) . ' more' : '');
        if (!$final && time() - strtotime($since) < 12 * 3600) {   // still running
            $d = 'Backup of ' . $day . ' is still running: ' . count($A['in']) . ' of ' . $A['expected'] . ' account(s) so far (waiting for ' . $mis . '). Checked again every 20 minutes.';
            $db->prepare('UPDATE itops_backup_jobs SET pending_date=?, pending_since=?, last_detail=? WHERE id=?')->execute([$date, $since, mb_substr($d, 0, 500), (int)$job['id']]);
            return ['ok' => true, 'event' => '', 'detail' => $d];
        }
        $clear(); $d = 'Backup of ' . $day . ' finished without ' . count($A['missing']) . ' of ' . $A['expected'] . ' account(s): ' . $mis . '.' . $ex . ' Check WHM → Backup → the backup log of that night.';
        return ['ok' => true, 'event' => itops_bk_record($db, $job, false, 'whm', $d, 'whm', $date), 'detail' => $d];
    }
    /** Everything WHM says about its backups, for the backup's page: settings, where they go, the dates kept, and the accounts of one date. */
    function itops_bk_whm_details(PDO $db, ?string $date = null): array {
        require_once __DIR__ . '/Guardian.php';
        $c = itops_whm_conn(itops_guardian_settings($db)); if (!$c) throw new RuntimeException('WHM is not connected (Server Guardian → Server actions → WHM host and API token).');
        $cf = itops_whm_call($c, 'backup_config_get', [], 15); if (!$cf['ok']) throw new RuntimeException('WHM did not answer (' . ($cf['error'] ?: 'HTTP ' . $cf['code']) . ').');
        $conf = is_array($cf['data']['backup_config'] ?? null) ? $cf['data']['backup_config'] : (array)$cf['data'];
        $yes = fn($k, $def = '0') => in_array(strtolower((string)($conf[$k] ?? $def)), ['1', 'yes', 'true', 'on'], true);
        $type = ['compressed' => 'Compressed', 'uncompressed' => 'Uncompressed', 'incremental' => 'Incremental'][strtolower((string)($conf['backuptype'] ?? ''))] ?? (string)($conf['backuptype'] ?? '');
        $settings = ['Backups' => $yes('backupenable', $conf['backup_enabled'] ?? '0') ? 'On' : 'Off', 'Type' => $type, 'Runs' => itops_bk_whm_days($conf),
            'Daily copies kept' => (string)($conf['backup_daily_retention'] ?? ''), 'Weekly' => $yes('backup_weekly_enable') ? 'On (kept: ' . (string)($conf['backup_weekly_retention'] ?? '?') . ')' : 'Off',
            'Monthly' => $yes('backup_monthly_enable') ? 'On (kept: ' . (string)($conf['backup_monthly_retention'] ?? '?') . ')' : 'Off',
            'Folder on the server' => (string)($conf['backupdir'] ?? ''), 'Keep the copy on the server' => $yes('keeplocal', '1') ? 'Yes' : 'No (only at the destinations)',
            'Suspended accounts' => $yes('backupsuspendedaccounts', '1') ? 'Backed up' : 'Not backed up', 'Databases' => (string)($conf['backup_dbs'] ?? ($conf['mysqlbackup'] ?? ''))];
        $settings = array_filter($settings, fn($v) => $v !== '');
        $dest = []; $dl = itops_whm_call($c, 'backup_destination_list', [], 15);
        if ($dl['ok']) foreach ((array)($dl['data']['destination_list'] ?? $dl['data']['destinations'] ?? []) as $x) if (is_array($x)) $dest[] = ['name' => (string)($x['name'] ?? $x['id'] ?? ''), 'type' => (string)($x['type'] ?? ''), 'enabled' => !(int)($x['disabled'] ?? 0)];
        $raw = []; $dts = itops_whm_call($c, 'backup_date_list', [], 20);
        if ($dts['ok']) foreach ((array)($dts['data']['backup_set'] ?? []) as $x) { $s2 = is_array($x) ? (string)($x['date'] ?? reset($x)) : (string)$x; if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s2, $m)) $raw[$m[1]] = $s2; }
        krsort($raw); $dates = array_keys($raw);
        $date = ($date !== null && isset($raw[$date])) ? $date : ($dates[0] ?? null);
        $acc = $date ? itops_bk_whm_accounts($c, $conf, $raw[$date]) : null;
        return ['settings' => $settings, 'destinations' => $dest, 'destinations_read' => (bool)$dl['ok'], 'dates' => $dates, 'date' => $date, 'accounts' => $acc];
    }

    // ── the scheduled check ───────────────────────────────────────────────────────────────────────────────────

    /** Read WHM (at most hourly, or now with $force) and mark late backups. Called every minute by cron-uptime.php. */
    function itops_bk_run(PDO $db, bool $force = false, ?int $only = null): array {
        $res = ['jobs' => 0, 'read' => 0, 'late' => 0, 'events' => [], 'busy' => false];
        if (!itops_bk_ready($db)) return $res;
        $got = (int)$db->query("SELECT GET_LOCK('itops_backup_run', 0)")->fetchColumn();
        if ($got !== 1) { $res['busy'] = true; return $res; }
        try {
            $rows = $db->query('SELECT * FROM itops_backup_jobs WHERE active=1' . ($only ? ' AND id=' . (int)$only : ''))->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $j) {
                $res['jobs']++;
                if ($j['kind'] === 'whm' && ($force || !$j['last_read_at'] || time() - strtotime($j['last_read_at']) >= (!empty($j['pending_date']) ? 1200 : 3600))) {   // BT71: every 20 minutes while a backup is running
                    try { $r = itops_bk_whm_read($db, $j); $res['read']++; if ($r['event']) $res['events'][] = $j['name'] . ': ' . $r['event']; } catch (Throwable $e) { $res['events'][] = $j['name'] . ': ' . $e->getMessage(); }
                    $j = itops_bk_get($db, (int)$j['id']) ?: $j;
                }
                if (!in_array($j['state'], ['late', 'off'], true) && time() > itops_bk_due_ts($j)) {
                    $d = ($j['last_ok_at'] ? 'No successful backup since ' . itops_bk_when($j['last_ok_at']) : 'No backup arrived since it was added on ' . itops_bk_when($j['created_at']))
                       . ' (expected every ' . (int)$j['expect_hours'] . ' h' . ((int)$j['grace_min'] ? ' + ' . (int)$j['grace_min'] . ' min grace' : '') . ').';
                    $ev = itops_bk_record($db, $j, false, 'missed', $d, 'monitor'); $res['late']++; if ($ev) $res['events'][] = $j['name'] . ': ' . $ev;
                }
            }
            if (mt_rand(1, 200) === 1) { try { $db->prepare('DELETE FROM itops_backup_events WHERE at < ?')->execute([date('Y-m-d H:i:s', time() - 400 * 86400)]); } catch (Throwable $e) {} }
        } finally { try { $db->query("SELECT RELEASE_LOCK('itops_backup_run')"); } catch (Throwable $e) {} }
        return $res;
    }

    // ── Scorecard ─────────────────────────────────────────────────────────────────────────────────────────────

    function itops_bk_scorecard_sources(PDO $db): array {
        $o = ['bkp_ontime_pct' => 'Backups on time — the backups chosen for the Scorecard (%)', 'bkp_missed' => 'Backups missed or late — the backups chosen for the Scorecard (count)'];
        try { foreach ($db->query('SELECT id, name FROM itops_backup_jobs ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $j) $o['bkp_j_' . $j['id']] = 'Backup on time — ' . mb_substr($j['name'], 0, 60) . ' (%)'; } catch (Throwable $e) {}
        return $o;
    }
    /**
     * Expected and on-time backups of one backup in [$from, $to): the period is cut into slots of its interval (from the
     * later of $from and the day it was added); a slot counts when a successful backup arrived in it.
     */
    function itops_bk_slots(PDO $db, array $j, string $from, string $to): ?array {
        $a = max(strtotime($from), strtotime((string)$j['created_at'])); $b = min(strtotime($to), time());
        if ($b <= $a) return null;
        $slot = max(3600, (int)$j['expect_hours'] * 3600); $n = max(1, (int)ceil(($b - $a) / $slot - 0.0001));
        $s = $db->prepare('SELECT at FROM itops_backup_events WHERE job_id=? AND ok=1 AND at >= ? AND at < ?'); $s->execute([(int)$j['id'], date('Y-m-d H:i:s', $a), date('Y-m-d H:i:s', $b < strtotime($to) ? $b + 1 : $b)]);   // up to now, this second included
        $hit = []; foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $at) $hit[min($n - 1, intdiv(strtotime($at) - $a, $slot))] = true;
        return [$n, count($hit)];
    }
    function itops_bk_scorecard_value(PDO $db, string $source, string $from, string $to): ?float {
        if (!itops_bk_ready($db) || strpos($source, 'bkp_') !== 0) return null;
        if (preg_match('/^bkp_j_(\d+)$/', $source, $m)) { $j = itops_bk_get($db, (int)$m[1]); if (!$j) return null; $jobs = [$j]; }
        else $jobs = $db->query('SELECT * FROM itops_backup_jobs WHERE on_scorecard=1 AND active=1')->fetchAll(PDO::FETCH_ASSOC);
        $n = 0; $ok = 0;
        foreach ($jobs as $j) { $r = itops_bk_slots($db, $j, $from, $to); if ($r) { $n += $r[0]; $ok += $r[1]; } }
        if (!$n) return null;
        if ($source === 'bkp_missed') return (float)($n - $ok);
        return round(100.0 * $ok / $n, 1);
    }
}
