<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — /api/jobs   (BT60: Scheduled jobs — are the cron jobs running, and working?)
 *
 * GET    /jobs                          every job: state, last run, last success, next due, success rate, the cron
 *                                       lines to set up, server facts (PHP of the web server, time zone)      (Scheduled jobs · View)
 * GET    /jobs/runs?job=&status=&before=&limit=   run history (newest first)                                  (View)
 * GET    /jobs/runs/:id                 one run with its steps and error                                       (View)
 * GET    /jobs/months?job=              BT62: the monthly report (runs, OK, failed, average time per job and month);
 *                                       the runs themselves are kept for the current month only                 (View)
 * PUT    /jobs/settings                 {notify, email, jobs: {key: {every, alert}}}                           (Edit)
 * POST   /jobs/run                      {job: sla|mail} run a job now (in the background)                      (Edit)
 * POST   /jobs/test-alert               send the alert to yourself (bell + email), to check it arrives         (Edit)
 * POST   /jobs/monitor-key              a new key for the outside monitor URL (shown once)                     (Edit)
 * DELETE /jobs/monitor-key              stop the outside monitor URL                                            (Edit)
 * GET    /jobs/monitor?key=             PUBLIC, for an uptime monitor: 200 when every job is fine, 503 when not;
 *                                       only job names and states, nothing else.
 *
 * Every change (settings, run now, monitor key, test) is written to the Audit Log; so is a refused change.
 */
require_once __DIR__ . '/../lib/CronMonitor.php';
$db = getDB();
$what = (string)($segments[1] ?? '');
$arg  = (string)($segments[2] ?? '');

// ── public: the outside monitor ──
if ($what === 'monitor' && $method === 'GET') {
    $key = (string)($_GET['key'] ?? ($_SERVER['HTTP_X_ITOPS_MONITOR_KEY'] ?? ''));
    $hash = (string)($db->query("SELECT sval FROM itops_settings WHERE skey='cron_monitor_key'")->fetchColumn() ?: '');
    if ($hash === '' || $key === '' || !hash_equals($hash, hash('sha256', $key))) error_response('Not found', 404);
    $H = itops_cron_health($db); $jobs = []; $ok = true;
    foreach ($H as $k => $h) { if (!$h['needed'] || !$h['alert']) continue; $jobs[$k] = $h['state']; if (itops_cron_bad($h['state'])) $ok = false; }
    http_response_code($ok ? 200 : 503); header('Cache-Control: no-store');
    json_response(['ok' => $ok, 'jobs' => $jobs, 'checked_at' => date('c')], $ok ? 200 : 503);
}

if (!itops_cron_ready($db)) error_response('Run db-update.php once to install the scheduled jobs history, then reload.', 409);
$body = [];
if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) { $raw = file_get_contents('php://input') ?: ''; if ($raw !== '') { $body = json_decode($raw, true); if (!is_array($body)) error_response('The request is not in the expected form. Nothing was changed.', 400); } }
$need = function (string $act, string $what) use ($currentUser) {
    if (itops_can($currentUser, 'jobs', $act)) return;
    if ($act !== 'view') audit_log('cron_refused', 'settings', null, $what, null, ['Wanted' => $what, 'Needs' => 'Scheduled jobs · ' . ucfirst($act)]);
    error_response('Your role cannot ' . ($act === 'view' ? 'see the scheduled jobs' : 'change the scheduled jobs') . ' (it needs Scheduled jobs · ' . ucfirst($act) . ').', 403);
};
$base = function (): string {
    if (defined('APP_URL') && APP_URL !== '' && strpos((string)APP_URL, '%%') === false) return rtrim((string)APP_URL, '/');   // BT87 (code audit M-17): the app's own address, never the Host header
    return '';   // review OPS-16: without the app's address set, nothing is built from the Host header a visitor sends
};
$runView = function (array $r, bool $full = false): array {
    $o = ['id' => (int)$r['id'], 'job' => $r['job'], 'via' => $r['via'], 'started_at' => $r['started_at'], 'finished_at' => $r['finished_at'], 'duration_ms' => $r['duration_ms'] === null ? null : (int)$r['duration_ms'],
          'status' => $r['status'], 'summary' => (string)$r['summary'], 'php' => (string)$r['php'], 'mem_mb' => $r['mem_mb'] === null ? null : (float)$r['mem_mb']];
    if ($full) { $o['steps'] = json_decode((string)($r['steps'] ?? ''), true) ?: []; $o['error'] = (string)($r['error'] ?? ''); $o['host'] = (string)$r['host']; }
    else { $st = json_decode((string)($r['steps'] ?? ''), true) ?: []; $o['steps_failed'] = count(array_filter($st, fn($s) => ($s['status'] ?? 'ok') !== 'ok')); $o['steps'] = count($st); }
    return $o;
};

if ($what === '' && $method === 'GET') {
    $need('view', 'Scheduled jobs');
    try { itops_cron_watch($db); } catch (Throwable $e) {}
    $H = itops_cron_health($db); $cfg = itops_cron_cfg($db);
    $root = itops_install_root();
    $bin = itops_cron_php_bin(); $eaGuess = '/opt/cpanel/ea-php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '/root/usr/bin/php';
    $tick = json_decode((string)($db->query("SELECT sval FROM itops_settings WHERE skey='auto_tick_last'")->fetchColumn() ?: ''), true);
    $cronPhp = (string)($db->query("SELECT php FROM itops_cron_runs WHERE via='cli' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: '');
    $lines = [
        ['job' => 'sla', 'when' => '*/15 * * * *', 'cmd' => $bin . ' ' . $root . '/cron-sla.php >/dev/null 2>&1', 'note' => 'Every 15 minutes'],
    ];
    $mon = (string)($db->query("SELECT sval FROM itops_settings WHERE skey='cron_monitor_key'")->fetchColumn() ?: '');
    $monAt = (string)($db->query("SELECT sval FROM itops_settings WHERE skey='cron_monitor_key_at'")->fetchColumn() ?: '');
    $bad = array_values(array_filter($H, fn($h) => $h['needed'] && $h['alert'] && itops_cron_bad($h['state'])));
    json_response(['jobs' => array_values($H), 'cfg' => $cfg, 'overall' => $bad ? 'attention' : 'ok', 'attention' => array_map(fn($h) => $h['key'], $bad),
        'server' => ['time' => date('Y-m-d H:i:s'), 'timezone' => date_default_timezone_get(), 'php_web' => PHP_VERSION, 'php_cron' => $cronPhp, 'php_bin' => $bin, 'php_bin_match' => $eaGuess, 'root' => $root],
        'cron_lines' => $lines, 'tick' => ['enabled' => (string)($db->query("SELECT sval FROM itops_settings WHERE skey='mail_auto_tick'")->fetchColumn() ?: '1') === '1', 'last' => $tick],
        'monitor' => ['set' => $mon !== '', 'since' => $monAt], 'url_base' => $base(), 'can_edit' => itops_can($currentUser, 'jobs', 'edit')]);
}

if ($what === 'months' && $method === 'GET') {
    // BT62: the monthly report — one line per job and month (the runs of earlier months are summed here, then deleted)
    $need('view', 'The monthly report of the scheduled jobs');
    $job = (string)($_GET['job'] ?? ''); if ($job !== '' && !isset(itops_cron_jobs()[$job])) error_response('Unknown job.', 400);
    $labels = array_map(fn($J) => $J['label'], itops_cron_jobs());
    json_response(['months' => array_map(fn($r) => $r + ['label' => $labels[$r['job']] ?? $r['job']], itops_cron_months($db, $job !== '' ? $job : null, 12))]);
}
if ($what === 'runs' && $method === 'GET') {
    $need('view', 'Scheduled jobs');
    if ($arg !== '') {
        $s = $db->prepare('SELECT * FROM itops_cron_runs WHERE id=?'); $s->execute([(int)$arg]); $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) error_response('That run is not in the history any more.', 404);
        json_response(['run' => $runView($r, true)]);
    }
    $w = []; $a = [];
    if (!empty($_GET['job'])) { if (!isset(itops_cron_jobs()[$_GET['job']])) error_response('Unknown job.', 400); $w[] = 'job=?'; $a[] = $_GET['job']; }
    if (!empty($_GET['status'])) { if (!in_array($_GET['status'], ['ok', 'warning', 'failed', 'running', 'problem'], true)) error_response('Unknown status.', 400);
        if ($_GET['status'] === 'problem') $w[] = "status IN ('warning','failed')"; else { $w[] = 'status=?'; $a[] = $_GET['status']; } }
    if (!empty($_GET['before']) && ctype_digit((string)$_GET['before'])) { $w[] = 'id < ?'; $a[] = (int)$_GET['before']; }
    $lim = max(10, min(200, (int)($_GET['limit'] ?? 50)));
    $s = $db->prepare('SELECT * FROM itops_cron_runs' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY id DESC LIMIT ' . $lim); $s->execute($a);
    $rows = array_map(fn($r) => $runView($r), $s->fetchAll(PDO::FETCH_ASSOC));
    json_response(['runs' => $rows, 'more' => count($rows) === $lim]);
}

if ($what === 'settings' && $method === 'PUT') {
    $need('edit', 'Scheduled jobs settings');
    $cur = itops_cron_cfg($db); $new = $cur;
    if (array_key_exists('notify', $body)) { if (!is_bool($body['notify'])) error_response('"Tell administrators" is on or off.', 400); $new['notify'] = $body['notify']; }
    if (array_key_exists('email', $body)) { if (!is_bool($body['email'])) error_response('"By email too" is on or off.', 400); $new['email'] = $body['email']; }
    if (array_key_exists('jobs', $body)) {
        if (!is_array($body['jobs'])) error_response('The jobs are not in the expected form.', 400);
        foreach ($body['jobs'] as $k => $j) {
            if (!isset(itops_cron_jobs()[$k]) || !is_array($j)) error_response('Unknown job "' . $k . '".', 400);
            if (array_key_exists('every', $j)) { if (!is_numeric($j['every']) || (int)$j['every'] < 1 || (int)$j['every'] > 20160 || (float)$j['every'] != (int)$j['every']) error_response('A job runs every 1 minute to 2 weeks (in whole minutes).', 400); $new['jobs'][$k]['every'] = (int)$j['every']; }
            if (array_key_exists('alert', $j)) { if (!is_bool($j['alert'])) error_response('"Alert" is on or off.', 400); $new['jobs'][$k]['alert'] = $j['alert']; }
        }
    }
    $view = function (array $c): array { $o = ['Tell administrators' => $c['notify'] ? 'yes' : 'no', 'By email too' => $c['email'] ? 'yes' : 'no'];
        foreach (itops_cron_jobs() as $k => $J) $o[$J['label']] = 'every ' . itops_cron_every_text((int)$c['jobs'][$k]['every']) . ($c['jobs'][$k]['alert'] ? ', alerts on' : ', alerts off'); return $o; };
    $o = []; $n = []; foreach ($view($new) as $k => $v) if (($view($cur)[$k] ?? null) !== $v) { $o[$k] = $view($cur)[$k] ?? ''; $n[$k] = $v; }
    if (!$n) json_response(['ok' => true, 'changed' => 0]);
    $db->prepare("INSERT INTO itops_settings (skey, sval) VALUES ('cron_cfg', ?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([json_encode(['notify' => $new['notify'], 'email' => $new['email'], 'jobs' => $new['jobs']])]);
    audit_log('cron_settings_save', 'settings', null, 'Scheduled jobs', $o, $n);
    json_response(['ok' => true, 'changed' => count($n)]);
}

if ($what === 'run' && $method === 'POST') {
    $job = (string)($body['job'] ?? '');
    $need('edit', 'Run a scheduled job now');
    $script = ['sla' => 'cron-sla.php'][$job] ?? null;
    if ($script === null) error_response('Only the main scheduler can be run from here.', 400);
    $tok = itops_cron_token($db);
    if (!function_exists('curl_init')) error_response('This server cannot call itself (PHP curl is missing). Run the job from cron instead.', 501);
    $b = $base(); if ($b === '') error_response('The address of the app is not known (APP_URL).', 500);
    $before = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM itops_cron_runs')->fetchColumn();
    $ch = curl_init($b . '/' . $script);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_NOSIGNAL => true,
        CURLOPT_HTTPHEADER => ['X-ITOps-Cron-Token: ' . $tok, 'X-ITOps-Run: manual']]);
    curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $errno = curl_errno($ch); curl_close($ch);
    if ($code === 403) error_response('The job refused the cron token. Rotate the token in the Email settings and update the cron jobs.', 502);
    audit_log('cron_run_now', 'settings', null, itops_cron_jobs()[$job]['label'], null, ['Job' => itops_cron_jobs()[$job]['label'], 'Started by' => (string)($currentUser['display_name'] ?? '')]);
    json_response(['ok' => true, 'started' => true, 'after_id' => $before, 'answered' => $code ?: null, 'still_running' => $errno === 28]);
}

if ($what === 'test-alert' && $method === 'POST') {
    $need('edit', 'Send a test scheduled-job alert');
    $u = $currentUser; $h = ['key' => 'test', 'label' => 'Test alert', 'every' => 15, 'script' => 'cron-sla.php', 'state' => 'late', 'last' => null];
    $db->prepare('INSERT INTO itops_notifications (user_id, title, body, type, link) VALUES (?,?,?,?,?)')->execute([(int)$u['id'], 'Scheduled job alert (test)', 'This is how an alert about a scheduled job arrives. Nothing is wrong.', 'info', 'jobs']);
    $mail = null;
    if (!empty($u['email']) && function_exists('itops_email')) {
        try { $r = itops_email('cron_job_alert', (string)$u['email'], ['job' => 'Test alert', 'state' => 'a test — nothing is wrong', 'detail' => 'Sent from the Scheduled jobs page to check that alerts arrive.', 'recipient_name' => (string)$u['display_name'],
            'link' => defined('APP_URL') ? rtrim(APP_URL, '/') . '/#jobs' : ''], ['user_id' => (int)$u['id']]); $mail = !empty($r['ok']); } catch (Throwable $e) { $mail = false; }
    }
    audit_log('cron_test_alert', 'settings', null, 'Scheduled jobs: test alert', null, ['Sent to' => (string)$u['display_name'], 'Email' => $mail === null ? 'no address' : ($mail ? 'sent' : 'not sent')]);
    json_response(['ok' => true, 'email' => $mail]);
}

if ($what === 'monitor-key') {
    $need('edit', 'Outside monitor URL');
    if ($method === 'POST') {
        $key = bin2hex(random_bytes(20));
        $db->prepare("INSERT INTO itops_settings (skey, sval) VALUES ('cron_monitor_key', ?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([hash('sha256', $key)]);
        $db->prepare("INSERT INTO itops_settings (skey, sval) VALUES ('cron_monitor_key_at', ?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([date('Y-m-d H:i:s')]);
        audit_log('cron_monitor_key', 'settings', null, 'Scheduled jobs: outside monitor URL', null, ['Monitor URL' => 'new key issued (shown once; stored as a hash)']);
        if ($base() === '') error_response('Set the app address (APP_URL) first: the monitor address is built from it.', 409);
        json_response(['ok' => true, 'url' => $base() . '/api/jobs/monitor?key=' . $key]);
    }
    if ($method === 'DELETE') {
        $db->prepare("DELETE FROM itops_settings WHERE skey IN ('cron_monitor_key','cron_monitor_key_at')")->execute();
        audit_log('cron_monitor_key', 'settings', null, 'Scheduled jobs: outside monitor URL', ['Monitor URL' => 'on'], ['Monitor URL' => 'stopped']);
        json_response(['ok' => true]);
    }
    error_response('Method not allowed', 405);
}
error_response('Not found', 404);
