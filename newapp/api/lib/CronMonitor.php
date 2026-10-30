<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — scheduled jobs: every run recorded, checked and alerted on (BT60).
 *
 * Each cron entry point (cron-sla.php, cron-sla.php digest, cron-mail.php, cron-audit.php) and the in-app scheduler
 * (POST /api/tick) records a run in itops_cron_runs: when it started and finished, how it was started (cron on the
 * server, a web call, the in-app scheduler, "Run now"), its steps with their result and duration, PHP version, memory,
 * and the error when it failed. A run that never finishes (a fatal error, a time-out) is closed by a shutdown handler,
 * or shown as "did not finish" after 30 minutes.
 *
 * Health per job: healthy · running · warning (a step failed) · failing (the run failed) · late (no run in more than
 * twice the expected interval + 5 minutes) · stuck · never ran · not needed (its feature is off).
 * The watchdog (itops_cron_watch) runs from every other job, the in-app scheduler and the Scheduled jobs page, and tells
 * administrators once per incident (bell + email "cron_job_alert"), and again when the job recovers. An outside
 * uptime monitor can also call GET /api/jobs/monitor?key=… (200 healthy / 503 not), which catches "nothing runs at all".
 *
 * Recording never breaks a job: every write here is best effort.
 */

if (!function_exists('itops_cron_php_bin')) {
    /** BT64/BT96: the command-line PHP of the SAME version as the website (cPanel EA-PHP: …/ea-phpXY/root/usr/bin/php). */
    function itops_cron_php_bin(): string {
        $bin = PHP_BINARY; $eaGuess = '/opt/cpanel/ea-php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '/root/usr/bin/php';
        if (preg_match('~^(/opt/cpanel/ea-php\d+/root/usr)/s?bin/~', (string)$bin, $em)) $bin = $em[1] . '/bin/php';
        elseif ($bin === '' || preg_match('/fpm|cgi|lsphp|httpd|apache/i', basename($bin))) $bin = (@is_file($eaGuess) ? $eaGuess : '/usr/local/bin/php');
        return $bin;
    }
    /** BT96: the folder this installation runs from, so every cron line shown is this installation's own (no fixed path). */
    function itops_install_root(): string { return realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2); }
}
if (!function_exists('itops_cron_jobs')) {
    /** 0001-A004: the token the web call of cron-sla.php needs (setting mail_cron_token), made the first time it is needed.
     *  Before, only saving the Email settings made it, so the in-app scheduler could not start the main job on a new installation. */
    function itops_cron_token(PDO $db): string {
        $q = function () use ($db) { return (string)($db->query("SELECT sval FROM itops_settings WHERE skey='mail_cron_token'")->fetchColumn() ?: ''); };
        $t = $q(); if ($t !== '') return $t;
        $db->prepare("INSERT IGNORE INTO itops_settings (skey, sval) VALUES ('mail_cron_token', ?)")->execute([bin2hex(random_bytes(24))]);
        return $q();   // the one saved (another request may have made it first)
    }

    /** The scheduled jobs, what they do and how often they are expected to run (minutes). */
    function itops_cron_jobs(): array {
        return [
            'sla'    => ['label' => 'Main scheduler', 'script' => 'cron-sla.php', 'every' => 15, 'alert' => true,
                         'does' => 'Scheduled offboarding, onboarding reminders, the monthly access re-check, alerts and digests, Teams notifications, the security assessment, device inventory, Microsoft 365 background reads, License Hub check-in, webhook retries and clean-up.'],
            'tick'   => ['label' => 'In-app scheduler (no cron)', 'script' => 'POST /api/tick', 'every' => 3, 'alert' => false,
                         'does' => 'While someone has the app open, runs the main scheduler every 3 minutes. A fallback: it stops when nobody is signed in, so set up the cron job.'],
        ];
    }
    function itops_cron_ready(PDO $db): bool {
        static $ok = null; if ($ok !== null) return $ok;
        try { $db->query('SELECT 1 FROM itops_cron_runs LIMIT 1'); return $ok = true; } catch (Throwable $e) { return $ok = false; }
    }
    function itops_cron_cfg(PDO $db): array {
        $d = ['jobs' => [], 'notify' => true, 'email' => true];
        try { $s = $db->prepare("SELECT sval FROM itops_settings WHERE skey='cron_cfg'"); $s->execute(); $j = json_decode((string)$s->fetchColumn(), true); } catch (Throwable $e) { $j = null; }
        if (is_array($j)) { $d['notify'] = array_key_exists('notify', $j) ? (bool)$j['notify'] : true; $d['email'] = array_key_exists('email', $j) ? (bool)$j['email'] : true; $d['jobs'] = is_array($j['jobs'] ?? null) ? $j['jobs'] : []; }
        foreach (itops_cron_jobs() as $k => $J) {
            $c = is_array($d['jobs'][$k] ?? null) ? $d['jobs'][$k] : [];
            $d['jobs'][$k] = ['every' => max(1, min(20160, (int)($c['every'] ?? $J['every']))), 'alert' => array_key_exists('alert', $c) ? (bool)$c['alert'] : $J['alert']];
        }
        return $d;
    }
    /** Whether a job is needed at all with the current settings (a job whose feature is off is "not needed"). */
    function itops_cron_needed(PDO $db, string $job): bool {
        $get = function (string $k, string $d) use ($db): string { try { $s = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $s->execute([$k]); $v = $s->fetchColumn(); return $v === false ? $d : (string)$v; } catch (Throwable $e) { return $d; } };
        switch ($job) {
            case 'tick': return $get('mail_auto_tick', '1') === '1';
            default: return true;
        }
    }

    // ── recording a run ──────────────────────────────────────────────────────────────────────────────────────────────

    /** Start recording a run of $job. $via: cli | web | tick | manual. Returns the run id (0 when recording is off). */
    function itops_cron_begin(PDO $db, string $job, ?string $via = null): int {
        if ($via === null) {
            if (PHP_SAPI === 'cli') $via = 'cli';
            elseif (($_SERVER['HTTP_X_ITOPS_RUN'] ?? '') === 'manual') $via = 'manual';
            elseif (($_SERVER['HTTP_X_ITOPS_RUN'] ?? '') === 'tick') $via = 'tick';
            else $via = 'web';
        }
        $GLOBALS['__itops_cron'] = ['id' => 0, 'job' => $job, 't0' => microtime(true), 'steps' => [], 'step' => null, 'done' => false, 'db' => $db];
        if (!itops_cron_ready($db)) return 0;
        try {
            $db->prepare('INSERT INTO itops_cron_runs (job, via, started_at, status, php, host, pid) VALUES (?,?,?,?,?,?,?)')
               ->execute([$job, $via, date('Y-m-d H:i:s'), 'running', PHP_VERSION, substr((string)(gethostname() ?: ''), 0, 80), getmypid() ?: null]);
            $id = (int)$db->lastInsertId(); $GLOBALS['__itops_cron']['id'] = $id;
            try { $db->prepare("INSERT IGNORE INTO itops_settings (skey, sval) VALUES ('cron_since', ?)")->execute([date('Y-m-d H:i:s')]); } catch (Throwable $e) {}   // BT62
            register_shutdown_function('itops_cron_shutdown');
            return $id;
        } catch (Throwable $e) { return 0; }
    }
    /** Start a step (the step before it ends as "ok" unless it was marked failed). */
    function itops_cron_step(string $name): void {
        $c = &$GLOBALS['__itops_cron']; if (empty($c)) return;
        itops_cron_step_close();
        $c['step'] = ['name' => mb_substr($name, 0, 80), 't' => microtime(true), 'status' => 'ok', 'msg' => ''];
    }
    /** The current step failed (an exception caught by the job, or a result that is an error). */
    function itops_cron_fail($err, string $status = 'failed'): void {
        $c = &$GLOBALS['__itops_cron']; if (empty($c)) return;
        $msg = $err instanceof Throwable ? $err->getMessage() : (string)$err;
        if ($c['step'] === null) $c['step'] = ['name' => 'Job', 't' => microtime(true), 'status' => 'ok', 'msg' => ''];
        $c['step']['status'] = $status; $c['step']['msg'] = mb_substr(trim($c['step']['msg'] . ' ' . $msg), 0, 300);
    }
    /** A note on the current step (what it did), kept with the step. */
    function itops_cron_note(string $msg): void {
        $c = &$GLOBALS['__itops_cron']; if (empty($c) || $c['step'] === null) return;
        $c['step']['msg'] = mb_substr(trim($c['step']['msg'] . ' ' . $msg), 0, 300);
    }
    function itops_cron_step_close(): void {
        $c = &$GLOBALS['__itops_cron']; if (empty($c) || $c['step'] === null) return;
        $s = $c['step']; $c['steps'][] = ['name' => $s['name'], 'status' => $s['status'], 'ms' => (int)round((microtime(true) - $s['t']) * 1000), 'msg' => $s['msg']];
        $c['step'] = null;
    }
    /** Finish the run. $status null = from the steps (any failed step → warning). */
    function itops_cron_end(?string $status = null, string $summary = ''): void {
        $c = &$GLOBALS['__itops_cron']; if (empty($c) || $c['done']) return;
        itops_cron_step_close(); $c['done'] = true;
        $failed = array_values(array_filter($c['steps'], fn($s) => $s['status'] !== 'ok'));
        if ($status === null) $status = $failed ? 'warning' : 'ok';
        if ($failed) $summary = trim($summary . ' ' . count($failed) . ' step(s) had a problem: ' . implode('; ', array_map(fn($s) => $s['name'] . ($s['msg'] !== '' ? ' — ' . $s['msg'] : ''), array_slice($failed, 0, 3))) . '.');
        if (!$c['id']) return;
        try {
            $c['db']->prepare('UPDATE itops_cron_runs SET finished_at=?, duration_ms=?, status=?, summary=?, steps=?, mem_mb=? WHERE id=?')
               ->execute([date('Y-m-d H:i:s'), (int)round((microtime(true) - $c['t0']) * 1000), $status, mb_substr($summary, 0, 500), json_encode($c['steps']), round(memory_get_peak_usage(true) / 1048576, 1), $c['id']]);
            if (!empty($c['idle']) && $status === 'ok') { try { $c['db']->prepare('UPDATE itops_cron_runs SET idle=1 WHERE id=?')->execute([$c['id']]); } catch (Throwable $e) {} }
        } catch (Throwable $e) {}
    }
    /** Closes a run the script left without calling itops_cron_end (an exit, a fatal error, a time-out). */
    function itops_cron_shutdown(): void {
        $c = $GLOBALS['__itops_cron'] ?? null; if (empty($c) || $c['done'] || !$c['id']) return;
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            itops_cron_fail('PHP error: ' . $e['message'] . ' (' . basename((string)$e['file']) . ':' . $e['line'] . ')');
            $GLOBALS['__itops_cron']['error'] = $e['message'];
            itops_cron_end('failed', 'Stopped by a PHP error: ' . mb_substr($e['message'], 0, 300));
            try { $c['db']->prepare('UPDATE itops_cron_runs SET error=? WHERE id=?')->execute([mb_substr($e['message'] . ' in ' . $e['file'] . ':' . $e['line'], 0, 2000), $c['id']]); } catch (Throwable $x) {}
            return;
        }
        itops_cron_end();
    }


    // ── health ──────────────────────────────────────────────────────────────────────────────────────────────────────

    function itops_cron_health(PDO $db, ?string $only = null): array {
        $cfg = itops_cron_cfg($db); $now = time(); $out = [];
        // BT62: when the run history started (a job that has never run is only "never ran" once a full interval has passed)
        $since = null; try { $since = $db->query("SELECT sval FROM itops_settings WHERE skey='cron_since'")->fetchColumn() ?: $db->query('SELECT MIN(started_at) FROM itops_cron_runs')->fetchColumn(); } catch (Throwable $e) {}
        foreach (itops_cron_jobs() as $k => $J) {
            if ($only !== null && $only !== $k) continue;
            $every = (int)$cfg['jobs'][$k]['every']; $needed = itops_cron_needed($db, $k);
            $last = $lastOk = $lastFail = null; $recent = []; $rate = null; $avg = null;
            if (itops_cron_ready($db)) {
                try {
                    $st = $db->prepare('SELECT id, via, started_at, finished_at, duration_ms, status, summary FROM itops_cron_runs WHERE job=? ORDER BY id DESC LIMIT 48'); $st->execute([$k]);
                    $recent = $st->fetchAll(PDO::FETCH_ASSOC);
                    $last = $recent[0] ?? null;
                    $q = $db->prepare("SELECT id, via, started_at, finished_at, duration_ms, status, summary FROM itops_cron_runs WHERE job=? AND status IN ('ok','warning') ORDER BY id DESC LIMIT 1"); $q->execute([$k]); $lastOk = $q->fetch(PDO::FETCH_ASSOC) ?: null;
                    $q = $db->prepare("SELECT id, via, started_at, finished_at, duration_ms, status, summary FROM itops_cron_runs WHERE job=? AND status='failed' ORDER BY id DESC LIMIT 1"); $q->execute([$k]); $lastFail = $q->fetch(PDO::FETCH_ASSOC) ?: null;
                    $q = $db->prepare("SELECT COUNT(*) n, SUM(status IN ('ok','warning')) ok, AVG(duration_ms) avgms FROM itops_cron_runs WHERE job=? AND started_at >= ? AND status <> 'running'"); $q->execute([$k, date('Y-m-d H:i:s', $now - 86400)]);
                    $r = $q->fetch(PDO::FETCH_ASSOC); if ((int)$r['n'] > 0) { $rate = round((int)$r['ok'] / (int)$r['n'] * 100, 1); $avg = (int)round((float)$r['avgms']); }
                } catch (Throwable $e) {}
            }
            $age = $last ? $now - strtotime((string)$last['started_at']) : null;
            $grace = $every >= 1440 ? $every * 60 + 86400 : $every * 120 + 300;   // weekly/daily jobs: one day of slack
            if (!$last) $state = !$needed ? 'not_needed' : (($since && $now - strtotime((string)$since) < $every * 60 + $grace) || !$since ? 'waiting' : 'never');
            elseif ($last['status'] === 'running') $state = $age > 1800 ? 'stuck' : 'running';
            elseif (!$needed && $age > $grace) $state = 'not_needed';
            elseif ($age > $grace) $state = 'late';
            elseif ($last['status'] === 'failed') $state = 'failing';
            elseif ($last['status'] === 'warning') $state = 'warning';
            else $state = 'healthy';
            $out[$k] = ['key' => $k, 'label' => $J['label'], 'script' => $J['script'], 'does' => $J['does'], 'every' => $every, 'alert' => (bool)$cfg['jobs'][$k]['alert'], 'needed' => $needed,
                        'state' => $state, 'last' => $last, 'last_ok' => $lastOk, 'last_fail' => $lastFail, 'age_s' => $age, 'late_after_s' => $grace,
                        'next_due' => $last ? date('Y-m-d H:i:s', strtotime((string)$last['started_at']) + $every * 60) : null,
                        'success_24h' => $rate, 'avg_ms' => $avg, 'recent' => array_map(fn($r) => ['id' => (int)$r['id'], 'status' => $r['status'], 'at' => $r['started_at'], 'via' => $r['via']], array_reverse($recent))];
        }
        return $out;
    }
    function itops_cron_state_text(string $state): string {
        return ['healthy' => 'running normally', 'running' => 'running now', 'warning' => 'running, with a step that failed', 'failing' => 'failing', 'late' => 'late — not running on time',
                'stuck' => 'stuck — a run did not finish', 'never' => 'never ran', 'waiting' => 'waiting for its first run', 'not_needed' => 'not needed'][$state] ?? $state;
    }
    function itops_cron_bad(string $state): bool { return in_array($state, ['failing', 'late', 'stuck', 'never'], true); }

    /**
     * The watchdog: once per incident, tell administrators (bell + email) that a job is late, failing or stuck — and
     * again when it recovers. Throttled to one check every 5 minutes. "Never ran" is reported once, a day after the
     * install (so a new install is not alarmed in its first hour).
     */
    function itops_cron_watch(PDO $db, bool $force = false): array {
        $sent = [];
        try {
            if (!itops_cron_ready($db)) return $sent;
            if (!$force) {
                $db->prepare("INSERT IGNORE INTO itops_settings (skey, sval) VALUES ('cron_watch_at', '0')")->execute();
                $cl = $db->prepare("UPDATE itops_settings SET sval=? WHERE skey='cron_watch_at' AND CAST(sval AS UNSIGNED) < ?"); $cl->execute([(string)time(), time() - 300]);
                if ($cl->rowCount() !== 1) return $sent;
            }
            $cfg = itops_cron_cfg($db); $H = itops_cron_health($db);
            $st = []; try { $st = json_decode((string)$db->query("SELECT sval FROM itops_settings WHERE skey='cron_watch'")->fetchColumn(), true) ?: []; } catch (Throwable $e) {}
            $first = (string)($db->query("SELECT MIN(started_at) FROM itops_cron_runs")->fetchColumn() ?: '');
            foreach ($H as $k => $h) {
                if (!$h['alert'] || !$h['needed']) { unset($st[$k]); continue; }
                $bad = itops_cron_bad($h['state']);
                if ($h['state'] === 'never' && ($first === '' || time() - strtotime($first) < 86400)) $bad = false;
                $was = $st[$k]['state'] ?? 'healthy';
                if ($bad && !itops_cron_bad($was)) {
                    $st[$k] = ['state' => $h['state'], 'since' => date('Y-m-d H:i:s')];
                    itops_cron_alert($db, $cfg, $h, false); $sent[] = $k . ':' . $h['state'];
                } elseif (!$bad && itops_cron_bad($was)) {
                    unset($st[$k]); itops_cron_alert($db, $cfg, $h, true); $sent[] = $k . ':recovered';
                } elseif ($bad) $st[$k]['state'] = $h['state'];
            }
            $db->prepare("INSERT INTO itops_settings (skey, sval) VALUES ('cron_watch', ?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([json_encode($st)]);
        } catch (Throwable $e) {}
        return $sent;
    }
    function itops_cron_alert(PDO $db, array $cfg, array $h, bool $recovered): void {
        $when = $h['last'] ? date('m/d/Y g:i A', strtotime((string)$h['last']['started_at'])) : 'never';
        $state = $recovered ? 'running again' : itops_cron_state_text($h['state']);
        $detail = 'Expected every ' . itops_cron_every_text((int)$h['every']) . '. Last run: ' . $when . ($h['last'] ? ' (' . $h['last']['status'] . ($h['last']['summary'] ? ': ' . $h['last']['summary'] : '') . ')' : '') . '.'
            . ($recovered ? '' : ' Check the cron job in cPanel (' . $h['script'] . ') and the run history on the Scheduled jobs page.');
        if (function_exists('itops_log')) itops_log($recovered ? 'info' : 'error', 'cron', 'Scheduled job ' . $h['label'] . ': ' . $state, ['job' => $h['key'], 'state' => $h['state']]);
        if (empty($cfg['notify'])) return;
        try {
            $admins = $db->query("SELECT u.id, u.email, u.display_name FROM itops_users u JOIN itops_roles r ON r.id = u.role_id WHERE r.slug IN ('super_admin','admin','dept_admin') AND u.status='active'")->fetchAll(PDO::FETCH_ASSOC);
            $ins = $db->prepare('INSERT INTO itops_notifications (user_id, title, body, type, link) VALUES (?,?,?,?,?)');
            foreach ($admins as $a) {
                $ins->execute([(int)$a['id'], 'Scheduled job ' . ($recovered ? 'recovered' : 'needs attention') . ': ' . $h['label'], ucfirst($state) . '. ' . $detail, $recovered ? 'info' : 'error', 'jobs']);
                if (!empty($cfg['email']) && !empty($a['email']) && function_exists('itops_email')) {
                    try { itops_email('cron_job_alert', (string)$a['email'], ['job' => $h['label'], 'state' => $state, 'detail' => $detail, 'recipient_name' => (string)$a['display_name'],
                        'link' => defined('APP_URL') ? rtrim(APP_URL, '/') . '/#jobs' : ''], ['user_id' => (int)$a['id']]); } catch (Throwable $e) {}
                }
            }
        } catch (Throwable $e) {}
    }
    function itops_cron_every_text(int $m): string {
        if ($m % 10080 === 0) return ($m / 10080 === 1 ? 'week' : ($m / 10080) . ' weeks');
        if ($m % 1440 === 0) return ($m / 1440 === 1 ? 'day' : ($m / 1440) . ' days');
        if ($m % 60 === 0) return ($m / 60 === 1 ? 'hour' : ($m / 60) . ' hours');
        return $m === 1 ? 'minute' : $m . ' minutes';
    }
    /** Old runs are removed after 60 days (and beyond 20,000 rows). */
    /**
     * BT62: the run history keeps the current month. Runs of earlier months are added to the monthly report
     * (itops_cron_months: one line per job and month — runs, OK, a step failed, failed, idle, average and longest time)
     * and deleted; the latest run of each job is always kept so the page still shows its last run. Runs that had
     * nothing to do (idle) are summed into the report after a day. A safety cap of 20,000 rows does the same.
     */
    function itops_cron_summarize(PDO $db, string $where, array $bind = []): int {
        $max = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM itops_cron_runs')->fetchColumn();
        // the newest run of each job (by start time) always stays, so the page keeps its "last run"
        $keep = $db->query('SELECT MAX(r.id) FROM itops_cron_runs r JOIN (SELECT job, MAX(started_at) m FROM itops_cron_runs GROUP BY job) x ON x.job = r.job AND x.m = r.started_at GROUP BY r.job')->fetchAll(PDO::FETCH_COLUMN);
        $cond = "id <= $max AND status <> 'running' AND ($where)" . ($keep ? ' AND id NOT IN (' . implode(',', array_map('intval', $keep)) . ')' : '');
        $db->beginTransaction();
        try {
            $s = $db->prepare("INSERT INTO itops_cron_months (ym, job, runs, ok, warning, failed, idle, total_ms, max_ms, first_at, last_at)
                SELECT DATE_FORMAT(started_at, '%Y-%m'), job, COUNT(*), SUM(status='ok'), SUM(status='warning'), SUM(status='failed'), SUM(idle), COALESCE(SUM(duration_ms),0), MAX(duration_ms), MIN(started_at), MAX(started_at)
                FROM itops_cron_runs WHERE $cond GROUP BY DATE_FORMAT(started_at, '%Y-%m'), job
                ON DUPLICATE KEY UPDATE runs=runs+VALUES(runs), ok=ok+VALUES(ok), warning=warning+VALUES(warning), failed=failed+VALUES(failed), idle=idle+VALUES(idle),
                    total_ms=total_ms+VALUES(total_ms), max_ms=GREATEST(COALESCE(max_ms,0), COALESCE(VALUES(max_ms),0)), first_at=LEAST(COALESCE(first_at,VALUES(first_at)), VALUES(first_at)), last_at=GREATEST(COALESCE(last_at,VALUES(last_at)), VALUES(last_at))");
            $s->execute($bind);
            $d = $db->prepare("DELETE FROM itops_cron_runs WHERE $cond"); $d->execute($bind); $n = $d->rowCount();
            $db->commit(); return $n;
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }
    function itops_cron_purge(PDO $db): int {
        $c = 0;
        try {
            // runs of earlier months → the monthly report (running runs older than a day are closed as failed first)
            $db->prepare("UPDATE itops_cron_runs SET status='failed', error=COALESCE(error,'Did not finish'), finished_at=COALESCE(finished_at, started_at) WHERE status='running' AND started_at < ?")->execute([date('Y-m-d H:i:s', time() - 86400)]);
            $c += itops_cron_summarize($db, 'started_at < ?', [date('Y-m-01 00:00:00')]);
            // runs with nothing to do → the report after a day (two every-minute jobs would otherwise add 2,880 rows a day)
            try { $c += itops_cron_summarize($db, 'idle=1 AND started_at < ?', [date('Y-m-d H:i:s', time() - 86400)]); } catch (Throwable $e) {}
            $max = (int)$db->query('SELECT MAX(id) FROM itops_cron_runs')->fetchColumn();
            if ($max > 20000) $c += itops_cron_summarize($db, 'id < ?', [$max - 20000]);
        } catch (Throwable $e) {}
        return $c;
    }
    /** The monthly report: past months from itops_cron_months, the current month from the runs (plus what was already summed). */
    function itops_cron_months(PDO $db, ?string $job = null, int $months = 12): array {
        $rows = [];
        try {
            $s = $db->prepare('SELECT * FROM itops_cron_months' . ($job ? ' WHERE job=?' : '') . ' ORDER BY ym DESC, job'); $s->execute($job ? [$job] : []);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[$r['ym'] . '|' . $r['job']] = ['ym' => $r['ym'], 'job' => $r['job'], 'runs' => (int)$r['runs'], 'ok' => (int)$r['ok'], 'warning' => (int)$r['warning'], 'failed' => (int)$r['failed'], 'idle' => (int)$r['idle'], 'total_ms' => (int)$r['total_ms'], 'max_ms' => $r['max_ms'] === null ? null : (int)$r['max_ms'], 'first_at' => $r['first_at'], 'last_at' => $r['last_at']];
            $s = $db->prepare("SELECT DATE_FORMAT(started_at, '%Y-%m') ym, job, COUNT(*) runs, SUM(status='ok') ok, SUM(status='warning') warning, SUM(status='failed') failed, SUM(idle) idle, COALESCE(SUM(duration_ms),0) total_ms, MAX(duration_ms) max_ms, MIN(started_at) first_at, MAX(started_at) last_at
                FROM itops_cron_runs WHERE status <> 'running'" . ($job ? ' AND job=?' : '') . " GROUP BY ym, job"); $s->execute($job ? [$job] : []);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $k = $r['ym'] . '|' . $r['job']; $o = $rows[$k] ?? ['ym' => $r['ym'], 'job' => $r['job'], 'runs' => 0, 'ok' => 0, 'warning' => 0, 'failed' => 0, 'idle' => 0, 'total_ms' => 0, 'max_ms' => null, 'first_at' => null, 'last_at' => null];
                foreach (['runs', 'ok', 'warning', 'failed', 'idle', 'total_ms'] as $f) $o[$f] += (int)$r[$f];
                $o['max_ms'] = max((int)$o['max_ms'], (int)$r['max_ms']); $o['first_at'] = $o['first_at'] ? min($o['first_at'], $r['first_at']) : $r['first_at']; $o['last_at'] = max((string)$o['last_at'], (string)$r['last_at']);
                $rows[$k] = $o;
            }
        } catch (Throwable $e) {}
        $rows = array_values($rows);
        usort($rows, fn($a, $b) => strcmp($b['ym'], $a['ym']) ?: strcmp($a['job'], $b['job']));
        $yms = array_slice(array_values(array_unique(array_column($rows, 'ym'))), 0, $months);
        return array_values(array_map(function ($r) { $r['avg_ms'] = $r['runs'] ? (int)round($r['total_ms'] / $r['runs']) : null; $r['ok_pct'] = $r['runs'] ? round(100 * ($r['ok'] + $r['warning']) / $r['runs'], 1) : null; $r['current'] = $r['ym'] === date('Y-m'); return $r; },
            array_filter($rows, fn($r) => in_array($r['ym'], $yms, true))));
    }
}
