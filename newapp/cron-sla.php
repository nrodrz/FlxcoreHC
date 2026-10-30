<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — the main scheduled job (scheduled offboarding, onboarding reminders, access re-check, alerts and digests,
 * the security assessment, Microsoft 365 background reads, License Hub check-in, webhooks, clean-up).
 * Run it every 5–15 minutes:
 *   php /path/to/itops/cron-sla.php
 *   curl -s -H "X-ITOps-Cron-Token: YOUR_TOKEN" "https://YOUR-SITE/cron-sla.php"
 * The token is shown to a Super Admin under Logs & Integrations → Scheduled jobs, always in the header (BT87: ?token= is refused).
 * 0001-A004: the steps of the reference app's removed modules (tickets, SLA, cases, calendar, announcements, company
 * systems, servers, equipment, scorecard, contracts and credentials reminders) and the weekly SLA digest mode are gone.
 */
require __DIR__ . '/config.php';
// BT125: while an update is being installed (Settings → Updates) the scheduled jobs wait for the next run
// BT129: an update the maintenance window placed is finished here when its own step 2 could not start
if (is_file(__DIR__ . '/data/updates/maintenance.json') && time() - (int)@filemtime(__DIR__ . '/data/updates/maintenance.json') < 900) {
    if (php_sapi_name() === 'cli') { define('ITOPS_UPD_FINISH_INCLUDED', true); require __DIR__ . '/update-finish.php'; exit(0); }
    echo "Update in progress: skipped.\n"; exit(0);
}
require_once __DIR__ . '/api/lib/TimeZone.php'; try { itops_tz_apply(getDB()); } catch (Throwable $e) { itops_tz_boot(); }   // BT131: the installation's own zone
require_once __DIR__ . '/api/lib/BusinessHours.php'; try { itops_db_clock_sync(getDB()); } catch (Throwable $e) {}   // BT52: the database clock follows PHP's (due times vs NOW())
require __DIR__ . '/api/lib/Mail.php';

$cli = (php_sapi_name() === 'cli');
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    $cfg0  = function_exists('mail_cfg') ? mail_cfg() : [];
    $token = $cfg0['mail_cron_token'] ?? '';
    if (!$token || !hash_equals((string)$token, (string)($_SERVER['HTTP_X_ITOPS_CRON_TOKEN'] ?? ''))) { http_response_code(403); exit("Forbidden — send the X-ITOps-Cron-Token header (a ?token= in the address is no longer accepted).\n"); }   // BT87
}

$db = getDB();
try { $db->exec('SET SESSION wait_timeout=1800'); } catch (Throwable $e) {}
// BT124 (code review DESK-13, OPS-8): one run at a time. The system cron and the in-app scheduler both start this job; a
// second start while one is running leaves at once (nothing is sent twice).
if ((int)$db->query("SELECT GET_LOCK(" . $db->quote('itops_cron_sla_sla') . ", 0)")->fetchColumn() !== 1) { echo "Another run is still busy: this one stops here.\n"; exit(0); }   // BT29.1: this job waits on Microsoft, mail and other servers between queries
// BT60: this run is recorded (Logs → Scheduled jobs): steps, duration, result; a run that dies is closed at shutdown
require_once __DIR__ . '/api/lib/CronMonitor.php';
require_once __DIR__ . '/api/lib/Modules.php';   // BT101: steps of modules that are not part of this installation are skipped
if (!$cli) ignore_user_abort(true);
itops_cron_begin($db, 'sla');

// BT14: purge expired server-side sessions (a day of slack for clock skew). Best effort.
itops_cron_step('Session cleanup');
try { $db->prepare('DELETE FROM itops_sessions WHERE expires_at < ?')->execute([date('Y-m-d H:i:s', time() - 86400)]); } catch (Throwable $e) {}
itops_cron_step('Database size and cleanup');
// BT26: daily database size snapshot + optional automatic log cleanup (Settings → Maintenance → Housekeeping).
try { require_once __DIR__ . '/api/lib/DbHealth.php'; @require_once __DIR__ . '/api/lib/EventLog.php'; $dbhR = itops_dbh_retention_run($db);
      if ($dbhR !== null && function_exists('itops_log')) itops_log('info', 'cron', 'Database cleanup: ' . $dbhR); } catch (Throwable $e) {}
itops_cron_step('Deleted items (older than the kept days)');
// BT141: entries in Deleted items older than the kept days (Administration → Deleted items) are deleted for good.
try { require_once __DIR__ . '/api/lib/RecycleBin.php'; $binN = itops_bin_auto_purge($db); if ($binN && function_exists('itops_log')) itops_log('info', 'cron', 'Deleted items removed for good (older than the kept days): ' . $binN); } catch (Throwable $e) {}
itops_cron_step('Device inventory (daily)'); $OFF = itops_cron_off('m365');
// BT27: refresh the device inventory (Entra devices + sign-in log) once a day. Quiet when Microsoft 365 is not connected.
if (!$OFF) try {
    require_once __DIR__ . '/api/lib/Graph.php'; require_once __DIR__ . '/api/lib/M365Hub.php'; require_once __DIR__ . '/api/lib/DeviceInventory.php';
    if (function_exists('itops_graph_configured') && itops_graph_configured() && itops_dinv_ready($db)) {
        $dinvS = itops_dinv_state($db);
        if (empty($dinvS['last_sync']) || (time() - (int)strtotime((string)$dinvS['last_sync'])) >= 20 * 3600) {
            $dinvR = itops_dinv_sync($db, ['max_pages' => 40]);
            if (function_exists('itops_log') && empty($dinvR['busy']) && empty($dinvR['error']))
                itops_log(($dinvR['errors'] ?? []) ? 'warning' : 'info', 'cron', 'Device inventory synced', ['counts' => $dinvR['counts'] ?? [], 'needs' => $dinvR['needs'] ?? [], 'errors' => $dinvR['errors'] ?? []]);
        }
    }
} catch (Throwable $e) { itops_cron_fail($e); }
itops_cron_step('Billing (License Hub, daily)');
// BT130: on the License Hub only: make the charges coming due and tell the Super Admins what is due or overdue
try {
    require_once __DIR__ . '/api/lib/LicenseManager.php';
    if (itops_lm_enabled() && itops_lm_ready($db)) { require_once __DIR__ . '/api/lib/HubCheckin.php'; require_once __DIR__ . '/api/lib/Billing.php'; itops_hub_table($db); $bm = itops_bill_daily($db); if ($bm !== '' && function_exists('itops_cron_note')) itops_cron_note($bm); }
} catch (Throwable $e) { itops_cron_fail($e); }
itops_cron_step('License Hub check-in (daily)');
// BT127: once a day (every 20 hours at most) this installation checks in with the License Hub when a token is saved:
// a renewed, changed or revoked license arrives by itself. Nothing is sent when check-in is off or no token is saved.
try {
    require_once __DIR__ . '/api/lib/HubCheckin.php';
    $hbc = itops_hubc_cfg($db);
    if (!$hbc['off'] && $hbc['token_enc'] !== '' && itops_hub_url($db) !== '' && (empty($hbc['last']['at']) || time() - strtotime((string)$hbc['last']['at']) >= 72000)) {
        $hbr = itops_hubc_checkin($db, 'scheduler');
        if (!$hbr['ok'] && function_exists('itops_log')) itops_log('warning', 'cron', 'License Hub check-in: ' . $hbr['note']);
        if (function_exists('itops_cron_note')) itops_cron_note($hbr['note']);
    }
} catch (Throwable $e) { itops_cron_fail($e); }
itops_cron_step('Teams notifications'); $OFF = itops_cron_off('teams_app');
// BT29: copy new bell notifications to Teams (only the kinds a Super Admin switched on; quiet when the Teams app is off).
if (!$OFF) try {
    require_once __DIR__ . '/api/lib/Graph.php'; require_once __DIR__ . '/api/lib/TeamsApp.php';
    $tfR = itops_teams_feed_run($db);
    if (function_exists('itops_log') && ($tfR['sent'] || $tfR['failed'] || $tfR['error'] !== ''))
        itops_log(($tfR['failed'] || $tfR['error'] !== '') ? 'warning' : 'info', 'cron', 'Teams notifications: ' . $tfR['sent'] . ' sent' . ($tfR['not_installed'] ? ', ' . $tfR['not_installed'] . ' without the app' : '') . ($tfR['failed'] ? ', ' . $tfR['failed'] . ' failed' : ''), $tfR);
} catch (Throwable $e) { itops_cron_fail($e); if (function_exists('itops_log')) { try { itops_log('error', 'cron', 'Teams notifications job failed: ' . $e->getMessage()); } catch (Throwable $x) {} } }

function itops_setting(PDO $db, string $k, $default = null) {
    $s = $db->prepare("SELECT sval FROM itops_settings WHERE skey=?"); $s->execute([$k]);
    $v = $s->fetchColumn(); return $v === false ? $default : $v;
}
function itops_admins(PDO $db): array {
    return $db->query("SELECT u.id,u.email,u.display_name FROM itops_users u JOIN itops_roles r ON r.id=u.role_id
                       WHERE r.slug IN ('super_admin','admin','dept_admin') AND u.status='active' AND u.email<>''")->fetchAll();
}
function itops_notify_row(PDO $db, $uid, $title, $body, $type, $link) {
    $db->prepare("INSERT INTO itops_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)")
       ->execute([(int)$uid, $title, $body, $type, $link]);
}
/* ---------------- Scheduled offboarding (e.g. 5:00 pm on the last day) ---------------- */
itops_cron_step('Scheduled offboarding'); $OFF = itops_cron_off('onboarding');
// Runs the automated steps of offboarding processes whose scheduled time (UTC) has passed, as the
// admin who scheduled them. Best effort: one failed step does not stop the others. A summary is
// emailed (template "offboarding_summary"); completion triggers the access verification.
@set_time_limit(0); @ignore_user_abort(true);   // a scheduled run must not be cut off half-way (the in-app scheduler calls this over HTTP)
if (!$OFF) try {
    // Recover runs left "running" by a crash / timeout (> 30 min): report them instead of leaving them stuck.
    $db->exec("UPDATE itops_ob_runs SET auto_run_status='partial', auto_run_log='[{\"task\":\"scheduler\",\"ok\":false,\"msg\":\"The scheduled run was interrupted — open the process and run the remaining steps.\"}]'
               WHERE auto_run_status='running' AND auto_run_at < '" . gmdate('Y-m-d H:i:s', time() - 1800) . "'");
    $due = $db->query("SELECT id FROM itops_ob_runs WHERE auto_run_status='scheduled' AND auto_run_at IS NOT NULL AND auto_run_at <= '" . gmdate('Y-m-d H:i:s') . "' AND status='in_progress' LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
    if ($due) require_once __DIR__ . '/api/lib/ObTasks.php';
    foreach ($due as $rid) {
        $claim = $db->prepare("UPDATE itops_ob_runs SET auto_run_status='running' WHERE id=? AND auto_run_status='scheduled'"); $claim->execute([(int)$rid]);
        if ($claim->rowCount() !== 1) continue;   // another scheduler instance took it
        $rq = $db->prepare('SELECT * FROM itops_ob_runs WHERE id=?'); $rq->execute([(int)$rid]); $srun = $rq->fetch();
        $uq = $db->prepare("SELECT u.*, r.slug AS role_slug, r.permissions AS role_perms FROM itops_users u LEFT JOIN itops_roles r ON r.id=u.role_id WHERE u.id=? AND u.status='active'"); $uq->execute([(int)$srun['auto_run_by']]); $actor = $uq->fetch();
        if (!$actor) {   // whoever scheduled it is no longer an active user — don't act in their name
            $db->prepare("UPDATE itops_ob_runs SET auto_run_status='partial', auto_run_log=? WHERE id=?")->execute([json_encode([['task' => 'scheduler', 'ok' => false, 'msg' => 'Not run: the person who scheduled it is no longer an active user. Reschedule it.']]), (int)$rid]);
            continue;
        }
        $actor['_scheduler'] = true;
        $_SERVER['ITOPS_USER_ID'] = $actor['id'] ?: null; $_SERVER['ITOPS_USER_NAME'] = ($actor['display_name'] ?? 'Scheduler') . ' (scheduled)';
        $res = ['status' => 'partial', 'log' => [['task' => 'scheduler', 'ok' => false, 'msg' => 'did not run']]];
        try { $res = itops_ob_run_automated($db, (int)$rid, $actor); }
        catch (\Throwable $e) { $res['log'] = [['task' => 'scheduler', 'ok' => false, 'msg' => $e->getMessage()]]; }
        $db->prepare("UPDATE itops_ob_runs SET auto_run_status=?, auto_run_log=? WHERE id=?")->execute([$res['status'] === 'completed' ? 'done' : 'partial', json_encode($res['log']), (int)$rid]);
        if (function_exists('audit_log')) { try { audit_log('offboarding_scheduled_run', 'onboarding', (int)$rid, $srun['subject_name'] . ' — ' . $res['status'], null, ['log' => $res['log']]); } catch (\Throwable $e) {} }
        if ($res['status'] !== 'completed') { try { itops_ob_send_summary($db, (int)$rid, 'partial', null); } catch (\Throwable $e) {} }   // completed → summary already sent by the completion step
        if (function_exists('itops_log')) itops_log($res['status'] === 'completed' ? 'info' : 'warning', 'onboarding', 'Scheduled offboarding ran for ' . $srun['subject_name'] . ': ' . $res['status'], ['run' => (int)$rid]);
        unset($_SERVER['ITOPS_USER_ID'], $_SERVER['ITOPS_USER_NAME']);
    }
} catch (\Throwable $e) { itops_cron_fail($e); }

itops_cron_step('Webhook retries');
// Retry any webhook deliveries that failed (backoff handled inside).
$whRetried = 0;
$intFile = __DIR__ . '/api/lib/Integrations.php';
if (is_file($intFile)) {
    require_once $intFile;
    if (function_exists('itops_webhook_retry_failed')) {
        try { $whRetried = itops_webhook_retry_failed($db, 25); } catch (\Throwable $e) {}
    }
}

itops_cron_step('Onboarding task reminders'); $OFF = itops_cron_off('onboarding');
// Onboarding task reminders: notify on pending tasks that are due soon or overdue.
if (!$OFF) try {
    $reminded = 0;
    $due = $db->query("SELECT rt.id, rt.title, rt.due_date, r.subject_name, r.created_by
                       FROM itops_ob_run_tasks rt JOIN itops_ob_runs r ON r.id = rt.run_id
                       WHERE rt.status='pending' AND r.status='in_progress'
                         AND rt.due_date IS NOT NULL AND rt.due_date <= (CURDATE() + INTERVAL 2 DAY)
                         AND (rt.reminded_at IS NULL OR rt.reminded_at < (NOW() - INTERVAL 20 HOUR))")->fetchAll();
    foreach ($due as $tk2) {
        $overdue = $tk2['due_date'] < date('Y-m-d');
        $msg = ($overdue ? 'Overdue: ' : 'Due soon: ') . $tk2['title'] . ' — ' . $tk2['subject_name'] . ' (due ' . $tk2['due_date'] . ')';
        $uids = [];
        if (!empty($tk2['created_by'])) $uids[] = (int)$tk2['created_by'];
        try { foreach ($db->query("SELECT u.id FROM itops_users u JOIN itops_roles r ON r.id=u.role_id WHERE u.status='active' AND r.slug IN ('super_admin','admin','dept_admin')")->fetchAll(PDO::FETCH_COLUMN) as $a) $uids[] = (int)$a; } catch (\Throwable $e) {}
        $ni = $db->prepare("INSERT INTO itops_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)");
        foreach (array_unique($uids) as $uid) { try { $ni->execute([$uid, ($overdue ? 'Onboarding task overdue' : 'Onboarding task due soon'), $msg, 'onboarding', '#onboarding_runs']); } catch (\Throwable $e) {} }
        $db->prepare("UPDATE itops_ob_run_tasks SET reminded_at=NOW() WHERE id=?")->execute([$tk2['id']]);
        $reminded++;
    }
    if ($reminded > 0) { $elf = __DIR__ . '/api/lib/EventLog.php'; if (is_file($elf)) { require_once $elf; if (function_exists('itops_log')) itops_log('info', 'onboarding', 'Sent ' . $reminded . ' onboarding task reminder(s)'); } }
} catch (\Throwable $e) { itops_cron_fail($e); }

itops_cron_step('Monitoring alerts (Guardian, Microsoft 365)');
// Proactive alerting: evaluate Guardian + M365 and raise/clear alerts (edge-triggered).
$alertSummary = ['raised' => 0, 'cleared' => 0, 'open' => 0];
$alFile = __DIR__ . '/api/lib/Alerts.php';
if (is_file($alFile) && !itops_cron_off('servers', 'm365')) {
    require_once $alFile;
    if (function_exists('itops_alerts_evaluate')) {
        try { $alertSummary = itops_alerts_evaluate($db); } catch (\Throwable $e) {}
    }
}

// Operational log of this cron run.
$elFile = __DIR__ . '/api/lib/EventLog.php';
if (is_file($elFile)) {
    require_once $elFile;
itops_cron_step('Webhook queue');
// Drain queued webhook deliveries (when queue mode is on).
try { $wf = __DIR__ . '/api/lib/Integrations.php'; if (is_file($wf)) { require_once $wf; if (function_exists('itops_webhook_process_queue')) itops_webhook_process_queue($db, 100); } } catch (\Throwable $e) {}

itops_cron_step('Weekly operations digest');
// Weekly ops digest: on the configured day, once per week, email admins a rollup.
try {
    if (itops_setting($db, 'weekly_digest_enabled', '0') === '1') {
        $thisWeek = date('o-W'); // ISO year-week
        $dow = (int)date('N');   // 1=Mon
        $wantDow = (int)itops_setting($db, 'weekly_digest_dow', '1');
        if ($dow === $wantDow && itops_setting($db, 'weekly_digest_last', '') !== $thisWeek) {
            $g = function (string $sql) use ($db) { try { return (int)$db->query($sql)->fetchColumn(); } catch (\Throwable $e) { return 0; } };
            $obActive      = $g("SELECT COUNT(*) FROM itops_ob_runs WHERE status='in_progress'");
            $obDueWk       = $g("SELECT COUNT(*) FROM itops_ob_run_tasks rt JOIN itops_ob_runs r ON r.id=rt.run_id WHERE rt.status='pending' AND r.status='in_progress' AND rt.due_date IS NOT NULL AND rt.due_date <= (CURDATE() + INTERVAL 7 DAY)");
            $openAlerts    = $g("SELECT COUNT(*) FROM itops_alerts WHERE status='open'");
            $report = [   // 0001-A003/A004: this product's figures (the reference app also counted tickets, KB and equipment)
                'en' => "- Onboarding/offboarding in progress: $obActive (tasks due within 7 days: $obDueWk)\n- Open alerts: $openAlerts",
                'es' => "- Incorporaciones/salidas en curso: $obActive (tareas que vencen en 7 días: $obDueWk)\n- Alertas abiertas: $openAlerts",
            ];
            $dv = ['date' => itops_tpl_d2(date('Y-m-d')), 'report' => $report, 'link' => defined('APP_URL') ? rtrim(APP_URL, '/') . '/#dashboard' : ''];
            foreach (itops_admins($db) as $ad) { if (!empty($ad['email'])) { try { itops_email('digest_ops_weekly', $ad['email'], $dv + ['recipient_name' => $ad['display_name'] ?? ''], ['user_id' => (int)$ad['id']]); } catch (\Throwable $e) {} } }
            $db->prepare("INSERT INTO itops_settings (skey,sval) VALUES ('weekly_digest_last',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([$thisWeek]);
        }
    }
} catch (\Throwable $e) { itops_cron_fail($e); }

itops_cron_step('Monthly access recertification'); $OFF = itops_cron_off('onboarding');
// Monthly access recertification: re-verify every completed offboarding (M365 sign-in/licences,
// vTiger status/records). Anyone who regained access is reported to admins (in-app + email template
// "access_recert_alert"). Each verification is written to the audit log as evidence.
if (!$OFF) try {
    $recertMonth = date('Y-m');
    $rcLast = (string)itops_setting($db, 'ob_recert_last', '');
    // "YYYY-MM" = done that month; "YYYY-MM:running:<ts>" = claimed (retried if a crash left it older than 1 hour)
    $rcBusy = preg_match('/^' . preg_quote($recertMonth, '/') . ':running:(\d+)$/', $rcLast, $rcm) && (time() - (int)$rcm[1]) < 3600;
    if (itops_setting($db, 'ob_recert_enabled', '1') === '1' && $rcLast !== $recertMonth && !$rcBusy
        && (int)date('j') >= (int)itops_setting($db, 'ob_recert_day', '1')) {
        $db->prepare("INSERT INTO itops_settings (skey,sval) VALUES ('ob_recert_last',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([$recertMonth . ':running:' . time()]);
        require_once __DIR__ . '/api/lib/Offboarding.php';
        $bad = []; $checked = 0;
        foreach ($db->query("SELECT * FROM itops_ob_runs WHERE kind='offboard' AND status='completed' ORDER BY completed_at DESC LIMIT 300")->fetchAll() as $orun) {
            $v = itops_ob_verify($db, $orun, true, 'monthly recertification'); $checked++;
            if (!$v['clean'] && $v['issues']) $bad[] = [$orun, $v['issues']];
        }
        if (function_exists('audit_log')) { try { audit_log('access_recertification', 'onboarding', null, $checked . ' checked, ' . count($bad) . ' with issues (monthly)'); } catch (\Throwable $e) {} }
        if ($bad) {
            $lines = [];
            foreach ($bad as [$orun, $iss]) foreach ($iss as $i) $lines[] = '- ' . $orun['subject_name'] . ': ' . $i;
            $rv = ['count' => (string)count($bad), 'report' => implode("\n", $lines), 'link' => defined('APP_URL') ? rtrim(APP_URL, '/') . '/#onboarding_runs' : ''];
            foreach (itops_admins($db) as $ad) {
                itops_notify_row($db, $ad['id'], 'Access re-check: ' . count($bad) . ' former employee(s) still have access', implode(' · ', array_map(fn($b) => $b[0]['subject_name'], $bad)), 'error', 'onboarding_runs');
                if (!empty($ad['email'])) { try { itops_email('access_recert_alert', $ad['email'], $rv + ['recipient_name' => $ad['display_name'] ?? ''], ['user_id' => (int)$ad['id']]); } catch (\Throwable $e) {} }
            }
        }
        $db->prepare("INSERT INTO itops_settings (skey,sval) VALUES ('ob_recert_last',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([$recertMonth]);   // done for this month
        $elf = __DIR__ . '/api/lib/EventLog.php';
        if (is_file($elf)) { require_once $elf; if (function_exists('itops_log')) itops_log($bad ? 'warning' : 'info', 'onboarding', 'Monthly access recertification: ' . $checked . ' offboarding(s) checked, ' . count($bad) . ' with remaining access'); }
    }
} catch (\Throwable $e) { itops_cron_fail($e); }

itops_cron_step('Daily alert digest'); $OFF = itops_cron_off('servers', 'm365');
// Daily alert digest: once per day, email admins a summary of currently-open alerts.
if (!$OFF) try {
    if (itops_setting($db, 'alert_digest_enabled', '0') === '1' && itops_setting($db, 'alert_digest_last', '') !== date('Y-m-d')) {
        $openA = [];
        try { $openA = $db->query("SELECT level, channel, message, first_seen FROM itops_alerts WHERE status='open' ORDER BY (level='error') DESC, last_seen DESC")->fetchAll(); } catch (\Throwable $e) {}
        if ($openA) {
            $lines = ['en' => [], 'es' => []];
            foreach ($openA as $a) {
                $lines['en'][] = '- [' . strtoupper($a['level']) . '] ' . $a['message'] . ' (' . $a['channel'] . ', since ' . itops_tpl_date($a['first_seen'], 'en') . ')';
                $lines['es'][] = '- [' . strtoupper($a['level']) . '] ' . $a['message'] . ' (' . $a['channel'] . ', desde ' . itops_tpl_date($a['first_seen'], 'es') . ')';
            }
            $av = ['date' => itops_tpl_d2(date('Y-m-d')), 'alert_count' => (string)count($openA), 'report' => ['en' => implode("\n", $lines['en']), 'es' => implode("\n", $lines['es'])],
                   'link' => defined('APP_URL') ? rtrim(APP_URL, '/') . '/#m365_overview' : ''];
            foreach (itops_admins($db) as $ad) { if (!empty($ad['email'])) { try { itops_email('digest_alerts_daily', $ad['email'], $av + ['recipient_name' => $ad['display_name'] ?? ''], ['user_id' => (int)$ad['id']]); } catch (\Throwable $e) {} } }
        }
        $db->prepare("INSERT INTO itops_settings (skey,sval) VALUES ('alert_digest_last',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([date('Y-m-d')]);
    }
} catch (\Throwable $e) { itops_cron_fail($e); }

    if (function_exists('itops_log')) {
        itops_log('info', 'cron', 'Scheduled run complete', [
            'webhooks_retried' => $whRetried, 'alerts_raised' => $alertSummary['raised'],
            'alerts_cleared' => $alertSummary['cleared'], 'alerts_open' => $alertSummary['open'],
        ]);
    }
}

// BT102: the security assessment on its schedule (weekly by default; read-only)
itops_cron_step('Security assessment'); $OFF = itops_cron_off('security_assessment');
if (!$OFF) try {
    require_once __DIR__ . '/api/lib/SecAssess.php'; require_once __DIR__ . '/api/lib/Graph.php';
    if (!itops_sa_ready($db)) itops_cron_note('Waiting for the database update.');
    elseif (!itops_graph_configured()) itops_cron_note('Microsoft 365 is not connected.');
    elseif (!itops_sa_due($db)) itops_cron_note('Not due (' . itops_sa_schedule($db) . ').');
    else { $saR = itops_sa_run($db, 'schedule', null); itops_cron_note('Score ' . ($saR['score'] === null ? '—' : $saR['score'] . '%') . '; ' . $saR['counts']['fail'] . ' failed, ' . $saR['counts']['warn'] . ' warnings, ' . $saR['counts']['unknown'] . ' could not be read.');
           if (function_exists('audit_log')) audit_log('sa_run', 'security_assessment', $saR['id'], 'Security assessment', null, ['Started by' => 'Schedule', 'Score' => $saR['score'] === null ? 'not scored' : $saR['score'] . '%']); }
} catch (\Throwable $e) { itops_cron_fail($e, 'warning'); }
itops_cron_step('Microsoft 365 background reads'); $OFF = itops_cron_off('m365');
// BT54: Microsoft 365 reads kept fresh in the background (when on and due; last, so a slow Microsoft never delays the rest)
$m365bg = 'n/a';
if (!$OFF) try {
    if (is_file(__DIR__ . '/api/lib/M365Background.php')) { require_once __DIR__ . '/api/lib/M365Background.php'; $m365bg = itops_m365_bg_tick($db); }
} catch (Throwable $e) { itops_cron_fail($e); $m365bg = 'error: ' . $e->getMessage(); if (function_exists('itops_log')) itops_log('error', 'm365', 'Microsoft 365 background refresh failed: ' . $e->getMessage()); }

if (is_string($m365bg) && strpos($m365bg, 'error') === 0) itops_cron_fail($m365bg);
// BT118: every sign-in of an emergency access account (Posture → Tenant profile) raises a bell and an Audit Log entry
if (!$OFF) try {
    if (is_file(__DIR__ . '/api/lib/CaOps.php')) { require_once __DIR__ . '/api/lib/Graph.php'; require_once __DIR__ . '/api/lib/Perms.php'; require_once __DIR__ . '/api/lib/CaOps.php'; $emN = itops_ca_watch_emergency($db); if ($emN) itops_cron_note($emN . ' emergency account sign-in(s)'); }
} catch (Throwable $e) { if (function_exists('itops_log')) itops_log('warning', 'm365', 'Emergency account watch: ' . $e->getMessage()); }
// BT60: check the other scheduled jobs (late, failing) and tell administrators once per incident; drop old run history
itops_cron_step('Check the other scheduled jobs');
try { $w = itops_cron_watch($db); if ($w) itops_cron_note(implode(', ', $w)); itops_cron_purge($db); } catch (\Throwable $e) { itops_cron_fail($e); }
itops_cron_end(null, $whRetried . ' webhook retr' . ($whRetried === 1 ? 'y' : 'ies') . ', ' . $alertSummary['raised'] . ' alert(s) raised.');
if (php_sapi_name() !== 'cli' || array_intersect(['-v', '--verbose'], $argv ?? [])) echo json_encode(['mode' => 'scan', 'webhooks_retried' => $whRetried, 'alerts' => $alertSummary]) . "\n";

// BT129: the maintenance window (opt-in, Settings → Updates). Last of all, because placing new files changes the code
// this run was started with: a build offered by the License Hub, checked with no problem, is installed inside the
// window; step 2 runs as its own process on the new code (or at the next run).
if (php_sapi_name() === 'cli') try {   // the command line only: step 2 must be able to run on the new code
    require_once __DIR__ . '/api/lib/UpdateFlow.php'; require_once __DIR__ . '/api/lib/HubCheckin.php';
    $uac = itops_upd_auto_cfg($db); $uoff = itops_hubc_cfg($db)['offer'] ?? null;
    if ($uac['on'] && is_array($uoff) && itops_build_num((string)($uoff['build'] ?? '')) > itops_build_num(ITOPS_BUILD) && !itops_upd_maintenance() && itops_upd_in_window($uac, time())) {
        $ust = itops_upd_staged($db);
        if (($ust['manifest']['build'] ?? '') !== $uoff['build'] || ($ust['from'] ?? '') !== 'hub') $ust = itops_hubc_fetch($db, 0, 'Maintenance window');
        if ($ust['check']['problems'] ?? ['?']) {
            if (function_exists('itops_log')) itops_log('warning', 'cron', 'Maintenance window: build ' . $uoff['build'] . ' was not installed: ' . implode(' · ', (array)($ust['check']['problems'] ?? [])));
        } else {
            $ur = itops_upd_run_install($db, 0, itops_app_name());
            audit_log('update_window', 'update', null, 'Build ' . $ur['to'], null, ['By' => 'the maintenance window', 'Backup' => $ur['backup']]);
            $php = PHP_BINARY !== '' && is_executable(PHP_BINARY) ? PHP_BINARY : '';
            if ($php !== '' && function_exists('proc_open')) { $pp = @proc_open([$php, __DIR__ . '/update-finish.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes); if (is_resource($pp)) proc_close($pp); }
        }
    }
} catch (Throwable $e) { if (function_exists('itops_log')) itops_log('warning', 'cron', 'Maintenance window: ' . $e->getMessage()); try { audit_log('update_window_failed', 'update', null, 'Maintenance window', null, ['Why' => $e->getMessage()]); } catch (Throwable $e2) {} }
