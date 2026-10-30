<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — SLA alerts + weekly digest cron entry point
 *
 * Breach/approaching scan (run often, e.g. every 15 min):
 *   php /path/to/itops/cron-sla.php
 *   curl -s -H "X-ITOps-Cron-Token: YOUR_TOKEN" "https://YOUR-SITE/path-to-itops/cron-sla.php"
 *
 * Weekly digest (run weekly, e.g. Monday 08:00):
 *   php /path/to/itops/cron-sla.php digest
 *   curl -s -H "X-ITOps-Cron-Token: YOUR_TOKEN" "https://YOUR-SITE/path-to-itops/cron-sla.php?digest=1"
 *
 * The token is the same one shown in the admin area for the mail cron, always in the header (BT87: ?token= is refused).
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
require __DIR__ . '/api/lib/MailIntake.php';

$cli = (php_sapi_name() === 'cli');
$digest = false;
if ($cli) {
    $digest = in_array('digest', array_slice($argv, 1), true);
} else {
    header('Content-Type: text/plain; charset=utf-8');
    $cfg0  = function_exists('mail_cfg') ? mail_cfg() : [];
    $token = $cfg0['mail_cron_token'] ?? '';
    if (!$token || !hash_equals((string)$token, (string)($_SERVER['HTTP_X_ITOPS_CRON_TOKEN'] ?? ''))) { http_response_code(403); exit("Forbidden — send the X-ITOps-Cron-Token header (a ?token= in the address is no longer accepted).\n"); }   // BT87
    $digest = !empty($_GET['digest']);
}

$db = getDB();
try { $db->exec('SET SESSION wait_timeout=1800'); } catch (Throwable $e) {}
// BT124 (code review DESK-13, OPS-8): one run at a time. The system cron and the in-app scheduler both start this job; a
// second start while one is running leaves at once (nothing is sent twice).
if ((int)$db->query("SELECT GET_LOCK(" . $db->quote('itops_cron_sla_' . ($digest ? 'digest' : 'sla')) . ", 0)")->fetchColumn() !== 1) { echo "Another run is still busy: this one stops here.\n"; exit(0); }   // BT29.1: this job waits on Microsoft, mail and other servers between queries
// BT60: this run is recorded (Logs → Scheduled jobs): steps, duration, result; a run that dies is closed at shutdown
require_once __DIR__ . '/api/lib/CronMonitor.php';
require_once __DIR__ . '/api/lib/Modules.php';   // BT101: steps of modules that are not part of this installation are skipped
if (!$cli) ignore_user_abort(true);
itops_cron_begin($db, $digest ? 'digest' : 'sla');

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
itops_cron_step('Public form files nobody sent');
// BT87 (code audit M-18): files uploaded on a public form but never submitted are removed after 24 hours.
// Review CORE-16: the public throttle rows (forms, tickets, uploads) of addresses not seen for a day are removed.
try { $db->exec("DELETE FROM itops_settings WHERE (skey LIKE 'rl\\_pub%') AND updated_at < NOW() - INTERVAL 1 DAY"); } catch (Throwable $e) {}
try { require_once __DIR__ . '/api/lib/PublicIntake.php'; $pubN = itops_pub_uploads_clean($db); if ($pubN && function_exists('itops_log')) itops_log('info', 'cron', 'Public form files removed (never submitted, older than 24 hours): ' . $pubN); } catch (Throwable $e) {}
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
itops_cron_step('360 Monitoring alerts'); $OFF = itops_cron_off('servers');
// BT125: the alerts of 360 Monitoring, every 5 minutes when it is connected (tickets only when switched on there)
if (!$OFF) try {
    require_once __DIR__ . '/api/lib/Mon360.php';
    $m3c = itops_m360_cfg($db);
    if ($m3c['key_enc'] !== '' && time() - (int)$m3c['last_poll'] >= 290) {
        $m3r = itops_m360_poll($db);
        if (!empty($m3r['errors']) && function_exists('itops_log')) itops_log('warning', 'cron', '360 Monitoring: ' . implode('; ', array_slice($m3r['errors'], 0, 3)));
        if ((!empty($m3r['tickets']) || !empty($m3r['notes'])) && function_exists('audit_log')) audit_log('mon360_poll', 'integration', null, '360 Monitoring', null, ['Checked' => $m3r['checked'], 'New alerts' => $m3r['new'], 'Tickets' => $m3r['tickets'], 'Notes' => $m3r['notes'], 'By' => 'scheduler']);
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
itops_cron_step('Microsoft invoices (daily)'); $OFF = itops_cron_off('m365');
// BT124: pull new Microsoft invoices once a day when "daily pull" is on (Assets & Vendors → Invoices → Microsoft billing)
if (!$OFF) try {
    require_once __DIR__ . '/api/lib/Graph.php'; require_once __DIR__ . '/api/lib/Invoices.php'; require_once __DIR__ . '/api/lib/MsBilling.php';
    if (itops_inv_ready($db) && itops_graph_configured()) {
        $msb = itops_msb_cfg($db);
        if ($msb['auto'] && $msb['account'] !== '' && (empty($msb['last']['at']) || time() - (int)strtotime((string)$msb['last']['at']) >= 20 * 3600)) {
            $msbR = itops_msb_run($db, date('Y-m-01', strtotime('-2 months')), date('Y-m-d'), null, 'daily job');
            if ($msbR['errors'] && function_exists('itops_log')) itops_log('warning', 'cron', 'Microsoft invoices: ' . implode('; ', array_slice($msbR['errors'], 0, 3)));
        }
    }
} catch (Throwable $e) { itops_cron_fail($e); }

itops_cron_step('Company systems (every 4 hours)'); $OFF = itops_cron_off('company_systems');
// BT45: read the connected company systems (JustCall…) when their last reading is more than four hours old. Read only.
if (!$OFF) try {
    require_once __DIR__ . '/api/lib/Systems.php';
    if (function_exists('itops_conn_cron')) {
        foreach (itops_conn_cron($db) as $connK => $connR) {
            if (!function_exists('itops_log') || !empty($connR['busy'])) continue;
            if (!$connR['ok']) itops_log('warning', 'cron', 'Company system ' . $connK . ' could not be read: ' . $connR['error']);
            else itops_log($connR['held'] ? 'warning' : 'info', 'cron', 'Company system ' . $connK . ' read: ' . $connR['users'] . ' user(s), ' . $connR['added'] . ' new, ' . $connR['closed'] . ' no longer listed'
                 . ($connR['held'] ? ', ' . count($connR['held']) . ' HELD BACK (the list lacks most of the accounts: a person must look at it)' : ''));
        }
    }
} catch (Throwable $e) { itops_cron_fail($e); if (function_exists('itops_log')) { try { itops_log('error', 'cron', 'Company systems job failed: ' . $e->getMessage()); } catch (Throwable $x) {} } }
itops_cron_step('Missed calls to tickets (every 5 minutes)'); $OFF = itops_cron_off('company_systems');
// BT148 CallTrackingMetrics, BT150 JustCall: missed calls become tickets, when that is switched on (Company systems → the system)
if (!$OFF) foreach (['ctm' => ['CallTrackingMetrics', 'CtmService.php', 'itops_ctm_poll'], 'justcall' => ['JustCall', 'JustCallService.php', 'itops_jc_poll']] as $phK => [$phName, $phLib, $phFn]) try {
    require_once __DIR__ . '/api/lib/PhoneTickets.php';
    $phC = itops_ph_cfg($db, $phK);
    if ($phC['on'] && time() - (int)$phC['last_poll'] >= 290) {
        require_once __DIR__ . '/api/lib/Systems.php'; require_once __DIR__ . '/api/lib/' . $phLib;
        $phR = $phFn($db);
        if (!empty($phR['errors']) && function_exists('itops_log')) itops_log('warning', 'cron', $phName . ' missed calls: ' . implode('; ', array_slice($phR['errors'], 0, 3)));
        if ((!empty($phR['tickets']) || !empty($phR['notes'])) && function_exists('audit_log')) audit_log('phone_tickets_check', 'integration', null, $phName . ' - missed calls', null, ['Calls read' => (string)$phR['read'], 'Missed' => (string)($phR['missed'] ?? 0), 'Tickets' => (string)($phR['tickets'] ?? 0), 'Notes' => (string)($phR['notes'] ?? 0), 'By' => 'scheduler']);
        if (function_exists('itops_cron_note') && isset($phR['tickets'])) itops_cron_note($phName . ': ' . $phR['read'] . ' call(s) read, ' . $phR['tickets'] . ' ticket(s), ' . $phR['notes'] . ' note(s).');
    }
} catch (Throwable $e) { itops_cron_fail($e); }
itops_cron_step('Server performance sample'); $OFF = itops_cron_off('servers');
// BT155: a sample of this server's performance (when cron-uptime.php runs every minute it has taken one already)
if (!$OFF) try { require_once __DIR__ . '/api/lib/ServerPerf.php'; if (itops_sp_ready($db)) { $spR = itops_sp_sample($db); if (isset($spR['id']) && function_exists('itops_cron_note')) itops_cron_note('CPU ' . ($spR['cpu_pct'] ?? $spR['cpu_now'] ?? '?') . '%, load ' . ($spR['load1'] ?? '?')); } } catch (Throwable $e) { itops_cron_fail($e); }
if (!$OFF) try { require_once __DIR__ . '/api/lib/ErrLogs.php'; if (itops_errlog_ready($db)) itops_errlog_collect($db); } catch (Throwable $e) { itops_cron_fail($e); }   // BT162: the error logs (paced to every 10 minutes)
itops_cron_step('Phone system call records (every 5 minutes)'); $OFF = itops_cron_off('company_systems');
// BT152: the call records of the office phone system, for its reports; its missed calls become tickets when that is switched on
if (!$OFF) try {
    require_once __DIR__ . '/api/lib/Systems.php'; require_once __DIR__ . '/api/lib/PbxCdr.php';
    $pbxS = itops_sys_get($db, 'pbx');
    if ($pbxS && $pbxS['mode'] === 'connected' && itops_pbx_cdr_ready($db) && time() - (int)itops_pbx_cdr_state($db)['last_run'] >= 290) {
        $pbxR = itops_pbx_cdr_sync($db);
        if (!empty($pbxR['errors']) && function_exists('itops_log')) itops_log('warning', 'cron', 'Phone system call records: ' . implode('; ', array_slice($pbxR['errors'], 0, 3)));
        if ((!empty($pbxR['tickets']) || !empty($pbxR['notes'])) && function_exists('audit_log')) audit_log('phone_tickets_check', 'integration', null, 'the phone system - missed calls', null, ['Calls read' => (string)$pbxR['read'], 'Missed' => (string)($pbxR['missed'] ?? 0), 'Tickets' => (string)($pbxR['tickets'] ?? 0), 'Notes' => (string)($pbxR['notes'] ?? 0), 'By' => 'scheduler']);
        if (function_exists('itops_cron_note')) itops_cron_note($pbxR['read'] . ' call record(s) read, ' . $pbxR['new'] . ' new' . (isset($pbxR['tickets']) ? ', ' . $pbxR['tickets'] . ' ticket(s)' : '') . ($pbxR['more'] ? ' (more to read next time)' : ''));
    }
} catch (Throwable $e) { itops_cron_fail($e); }
itops_cron_step('Jotform forms to tickets (every 10 minutes)'); $OFF = itops_cron_off('company_systems');
// BT149: new submissions of the Jotform forms switched on become tickets (Company systems → Jotform → Forms)
if (!$OFF) try {
    require_once __DIR__ . '/api/lib/Systems.php'; require_once __DIR__ . '/api/lib/JotformForms.php';
    $jfC = itops_jf_cfg($db);
    if (itops_jf_any_on($jfC) && time() - (int)$jfC['last_poll'] >= 590) {   // one call per form each time: Jotform counts calls per day
        $jfR = itops_jf_poll($db);
        if (!empty($jfR['errors']) && function_exists('itops_log')) itops_log('warning', 'cron', 'Jotform forms: ' . implode('; ', array_slice($jfR['errors'], 0, 3)));
        if (!empty($jfR['tickets']) && function_exists('audit_log')) audit_log('form_tickets_check', 'integration', null, 'Jotform - forms to tickets', null, ['Forms' => (string)$jfR['forms'], 'Submissions read' => (string)$jfR['read'], 'Tickets' => (string)$jfR['tickets'], 'By' => 'scheduler']);
        if (function_exists('itops_cron_note')) itops_cron_note($jfR['read'] . ' submission(s) read, ' . $jfR['tickets'] . ' ticket(s)');
    }
} catch (Throwable $e) { itops_cron_fail($e); }
itops_cron_step('Announcements'); $OFF = itops_cron_off('announcements');
// BT28: announcements — publish the scheduled ones, send automatic reminders and the queued emails (every run).
if (!$OFF) try {
    require_once __DIR__ . '/api/lib/Brand.php'; require_once __DIR__ . '/api/lib/EmailTemplates.php'; require_once __DIR__ . '/api/lib/Announcements.php';
    $annR = itops_ann_cron($db);
    if (function_exists('itops_log') && ($annR['published'] || $annR['reminded'] || $annR['failed']))
        itops_log($annR['failed'] ? 'warning' : 'info', 'cron', 'Announcements: ' . $annR['published'] . ' published, ' . $annR['reminded'] . ' reminder(s), ' . $annR['emails'] . ' email(s) sent' . ($annR['failed'] ? ', ' . $annR['failed'] . ' failed' : ''), $annR);
} catch (Throwable $e) { itops_cron_fail($e); if (function_exists('itops_log')) { try { itops_log('error', 'cron', 'Announcements job failed: ' . $e->getMessage()); } catch (Throwable $x) {} } }
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
function itops_send_alert(PDO $db, array $t, string $kind, string $label, bool $isBreach) {
    $recips = [];
    foreach ([$t['assignee_id'] ?? null, $t['backup_id'] ?? null] as $who) {    // BT17: owner and backup
        if (empty($who)) continue;
        $u = $db->prepare("SELECT id,email,display_name FROM itops_users WHERE id=? AND status='active'");
        $u->execute([(int)$who]);
        if ($r = $u->fetch()) $recips[$r['id']] = $r;
    }
    if (!$recips) foreach (itops_admins($db) as $a) $recips[$a['id']] = $a;

    $title   = $label . ' — ' . $t['ticket_number'];
    $nbody   = $t['ticket_number'] . ': ' . $t['subject'];
    $type    = $isBreach ? 'error' : 'warning';
    // Email: template "sla_breach" / "sla_warning" (editable under Connection → Email templates)
    $isResp = strpos($kind, 'response') === 0;
    $vars = ['ticket_number' => $t['ticket_number'], 'subject' => (string)$t['subject'],
             'sla_type' => itops_tpl_l10n('sla', $isResp ? 'response' : 'resolution'),
             'priority' => itops_tpl_l10n('priority', (string)$t['priority']),
             'due' => itops_tpl_d2($isResp ? ($t['sla_response_due'] ?? '') : ($t['due_date'] ?? '')),
             'link' => defined('APP_URL') ? rtrim(APP_URL, '/') . '/#tickets' : ''];
    foreach ($recips as $r) {
        itops_notify_row($db, $r['id'], $title, $nbody, $type, 'tickets');
        if (!empty($r['email'])) { try { itops_email($isBreach ? 'sla_breach' : 'sla_warning', $r['email'], $vars + ['recipient_name' => $r['display_name'] ?? ''], ['user_id' => (int)$r['id'], 'ticket_id' => (int)$t['id']]); } catch (Throwable $e) {} }
    }
}

/* ---------------- Weekly digest ---------------- */
if ($digest) {
    itops_cron_step('Weekly SLA digest');
    if (itops_cron_off('service_desk')) { itops_cron_end(null, 'Service Desk is not part of this installation.'); echo "Service Desk is not part of this installation.\n"; exit; }
    if (itops_setting($db, 'sla_digest_enabled', '1') !== '1') { echo "Digest disabled.\n"; exit; }
    $today = date('Y-m-d');
    if (itops_setting($db, 'sla_digest_last', '') === $today) { echo "Digest already sent today.\n"; exit; }
    $since = date('Y-m-d H:i:s', time() - 7 * 86400);

    $cnt = function (string $sql) use ($db, $since) { $s = $db->prepare($sql); $s->execute([$since]); return (int)$s->fetchColumn(); };
    $created  = $cnt("SELECT COUNT(*) FROM itops_tickets WHERE created_at>=?");
    $resolved = $cnt("SELECT COUNT(*) FROM itops_tickets WHERE resolved_at>=?");

    $rs = $db->prepare("SELECT
        SUM(sla_response_due IS NOT NULL AND first_response_at IS NOT NULL AND first_response_at<=sla_response_due) met,
        SUM(sla_response_due IS NOT NULL AND ((first_response_at IS NOT NULL AND first_response_at>sla_response_due) OR (first_response_at IS NULL AND status NOT IN ('resolved','closed','cancelled','pending') AND NOW()>sla_response_due))) br
        FROM itops_tickets WHERE sla_response_due>=?");
    $rs->execute([$since]); $r = $rs->fetch(); $respMet = (int)$r['met']; $respBr = (int)$r['br'];

    $xs = $db->prepare("SELECT
        SUM(due_date IS NOT NULL AND COALESCE(resolved_at,closed_at) IS NOT NULL AND COALESCE(resolved_at,closed_at)<=due_date) met,
        SUM(due_date IS NOT NULL AND ((COALESCE(resolved_at,closed_at) IS NOT NULL AND COALESCE(resolved_at,closed_at)>due_date) OR (COALESCE(resolved_at,closed_at) IS NULL AND status NOT IN ('resolved','closed','cancelled','pending') AND NOW()>due_date))) br
        FROM itops_tickets WHERE due_date>=?");
    $xs->execute([$since]); $x = $xs->fetch(); $resoMet = (int)$x['met']; $resoBr = (int)$x['br'];

    $openBreaches = (int)$db->query("SELECT COUNT(*) FROM itops_tickets WHERE status NOT IN ('resolved','closed','cancelled','pending') AND due_date IS NOT NULL AND NOW()>due_date")->fetchColumn();
    $respC = ($respMet + $respBr) > 0 ? round($respMet / ($respMet + $respBr) * 100) : null;
    $resoC = ($resoMet + $resoBr) > 0 ? round($resoMet / ($resoMet + $resoBr) * 100) : null;

    $pc = fn($v) => $v === null ? 'n/a' : $v . '%';
    $report = [
        'en' => "- Tickets created: $created\n- Tickets resolved: $resolved\n- Response SLA compliance: " . $pc($respC) . " ($respMet met / $respBr breached)\n"
              . "- Resolution SLA compliance: " . $pc($resoC) . " ($resoMet met / $resoBr breached)\n- Currently overdue (open past resolution due): $openBreaches",
        'es' => "- Tickets creados: $created\n- Tickets resueltos: $resolved\n- Cumplimiento SLA de respuesta: " . $pc($respC) . " ($respMet cumplidos / $respBr incumplidos)\n"
              . "- Cumplimiento SLA de resolución: " . $pc($resoC) . " ($resoMet cumplidos / $resoBr incumplidos)\n- Vencidos actualmente (abiertos después de la fecha de resolución): $openBreaches",
    ];
    $dvars = ['date' => itops_tpl_d2(date('Y-m-d')), 'report' => $report, 'link' => defined('APP_URL') ? rtrim(APP_URL, '/') . '/#reports' : ''];

    $emailed = 0; $admins = itops_admins($db);
    foreach ($admins as $a) {
        itops_notify_row($db, $a['id'], 'Weekly SLA digest',
            'Response ' . ($respC === null ? 'n/a' : $respC . '%') . ', resolution ' . ($resoC === null ? 'n/a' : $resoC . '%') . ', ' . $openBreaches . ' overdue.',
            'info', 'reports');
        if (!empty($a['email'])) { $res = itops_email('digest_sla_weekly', $a['email'], $dvars + ['recipient_name' => $a['display_name'] ?? ''], ['user_id' => (int)$a['id']]); if (!empty($res['ok'])) $emailed++; }
    }
    $db->prepare("INSERT INTO itops_settings (skey,sval) VALUES ('sla_digest_last',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([$today]);
    itops_cron_end('ok', 'Digest sent: ' . $emailed . ' email(s).');
    if (php_sapi_name() !== 'cli' || array_intersect(['-v', '--verbose'], $argv ?? [])) echo json_encode(['mode' => 'digest', 'created' => $created, 'resolved' => $resolved, 'response_compliance' => $respC, 'resolution_compliance' => $resoC, 'open_breaches' => $openBreaches, 'emailed' => $emailed]) . "\n";   // BT87: quiet from cron (no email per run; every run is in Logs → Scheduled jobs); -v prints the summary
    exit;
}

function itops_send_reminder(PDO $db, $ownerId, string $title, string $body, string $type, string $link, string $tplKey = '', array $vars = []) {
    $targets = [];
    if ($ownerId) {
        $u = $db->prepare("SELECT id,email,display_name FROM itops_users WHERE id=? AND status='active'");
        $u->execute([(int)$ownerId]); $row = $u->fetch(); if ($row) $targets[] = $row;
    }
    if (!$targets) $targets = itops_admins($db);
    foreach ($targets as $r) {
        itops_notify_row($db, $r['id'], $title, $body, $type, $link);
        if ($tplKey !== '' && !empty($r['email'])) { try { itops_email($tplKey, $r['email'], $vars + ['recipient_name' => $r['display_name'] ?? '',
            'link' => defined('APP_URL') ? rtrim(APP_URL, '/') . '/#' . $link : ''], ['user_id' => (int)$r['id']]); } catch (\Throwable $e) {} }
    }
}
function itops_rem_once(PDO $db, string $key): bool {
    $key = substr(preg_replace('/[^A-Za-z0-9_]/', '', $key), 0, 180);
    // BT124 (review DESK-13): claimed in one statement — only the run whose INSERT goes in sends it
    $s = $db->prepare("INSERT IGNORE INTO itops_settings (skey,sval) VALUES (?,?)"); $s->execute([$key, date('Y-m-d')]);
    return $s->rowCount() === 1;
}
function itops_run_reminders(PDO $db): int {
    if (itops_setting($db, 'renewal_reminders_enabled', '1') !== '1') return 0;
    $today = strtotime(date('Y-m-d')); $sent = 0;
    // Contracts: renewal notice + expiry (BT101: each kind only when its module is part of this installation)
    if (itops_module_on('contracts')) foreach ($db->query("SELECT id,title AS name,end_date,renewal_notice_days,owner_id,status,series_id,term_no FROM itops_contracts WHERE status IN ('active','expiring')")->fetchAll() as $c) {
        if (empty($c['end_date'])) continue; $end = strtotime($c['end_date']); if ($end === false) continue;
        $days = (int)floor(($end - $today) / 86400); $notice = (int)($c['renewal_notice_days'] ?: 90); $tag = preg_replace('/[^0-9]/', '', $c['end_date']);
        $cref = sprintf('CT-%04d', (int)($c['series_id'] ?: $c['id'])) . (((int)($c['term_no'] ?? 1)) > 1 ? '-R' . ((int)$c['term_no'] - 1) : '');
        $cv = ['contract_name' => (string)$c['name'], 'ref' => $cref, 'end_date' => itops_tpl_d2($c['end_date']), 'days_left' => (string)max(0, $days)];
        if ($days < 0) {
            if (itops_rem_once($db, "rem_contract_{$c['id']}_expired_{$tag}")) { itops_send_reminder($db, $c['owner_id'], "Contract expired: {$c['name']}", "The contract \"{$c['name']}\" expired on {$c['end_date']}. Review renewal or termination.", 'renewal', 'contracts', 'contract_expired', $cv); $sent++; }
            if (in_array($c['status'], ['active','expiring'], true)) $db->prepare("UPDATE itops_contracts SET status='expired' WHERE id=?")->execute([$c['id']]);
        } elseif ($days <= $notice) {
            if (itops_rem_once($db, "rem_contract_{$c['id']}_notice_{$tag}")) { itops_send_reminder($db, $c['owner_id'], "Contract renewal due in {$days} day(s): {$c['name']}", "The contract \"{$c['name']}\" ends on {$c['end_date']} ({$days} day(s) away). Begin the renewal review.", 'renewal', 'contracts', 'contract_renewal_due', $cv); $sent++; }
            if ($c['status'] === 'active') $db->prepare("UPDATE itops_contracts SET status='expiring' WHERE id=?")->execute([$c['id']]);
        }
    }
    // Credentials: rotation due + overdue
    if (itops_module_on('vault')) foreach ($db->query("SELECT id,name,next_rotation_date,owner_id,status FROM itops_credentials WHERE status IN ('current','due_soon','overdue')")->fetchAll() as $cr) {
        if (empty($cr['next_rotation_date'])) continue; $nd = strtotime($cr['next_rotation_date']); if ($nd === false) continue;
        $days = (int)floor(($nd - $today) / 86400); $tag = preg_replace('/[^0-9]/', '', $cr['next_rotation_date']);
        $crv = ['credential_name' => (string)$cr['name'], 'due_date' => itops_tpl_d2($cr['next_rotation_date']), 'days_left' => (string)max(0, $days)];
        if ($days < 0) {
            if (itops_rem_once($db, "rem_cred_{$cr['id']}_overdue_{$tag}")) { itops_send_reminder($db, $cr['owner_id'], "Credential rotation overdue: {$cr['name']}", "The credential \"{$cr['name']}\" was due for rotation on {$cr['next_rotation_date']}. Please rotate it and update the record.", 'rotation', 'credentials', 'cred_rotation_overdue', $crv); $sent++; }
            if (in_array($cr['status'], ['current','due_soon'], true)) $db->prepare("UPDATE itops_credentials SET status='overdue' WHERE id=?")->execute([$cr['id']]);
        } elseif ($days <= 14) {
            if (itops_rem_once($db, "rem_cred_{$cr['id']}_due_{$tag}")) { itops_send_reminder($db, $cr['owner_id'], "Credential rotation due in {$days} day(s): {$cr['name']}", "The credential \"{$cr['name']}\" should be rotated by {$cr['next_rotation_date']} ({$days} day(s) away).", 'rotation', 'credentials', 'cred_rotation_due', $crv); $sent++; }
            if ($cr['status'] === 'current') $db->prepare("UPDATE itops_credentials SET status='due_soon' WHERE id=?")->execute([$cr['id']]);
        }
    }
    // BT24: SOP reviews — reminder to the SOP owner (or the admins when it has none): 14 days before, and when overdue
    if (itops_module_on('documents')) try {
        foreach ($db->query("SELECT id,title,next_review_date,owner_id FROM itops_sops WHERE next_review_date IS NOT NULL AND status IN ('published','approved','under_review')") as $sp) {
            $nd = strtotime((string)$sp['next_review_date']); if ($nd === false) continue;
            $days = (int)floor(($nd - $today) / 86400); $tag = preg_replace('/[^0-9]/', '', $sp['next_review_date']);
            $spv = ['sop_title' => (string)$sp['title'], 'due_date' => itops_tpl_d2($sp['next_review_date']), 'days_left' => (string)max(0, $days)];
            if ($days < 0) {
                if (itops_rem_once($db, "rem_sop_{$sp['id']}_overdue_{$tag}")) { itops_send_reminder($db, $sp['owner_id'], "SOP review overdue: {$sp['title']}", "The SOP \"{$sp['title']}\" was due for review on {$sp['next_review_date']}.", 'sop', 'sops', 'sop_review_overdue', $spv); $sent++; }
            } elseif ($days <= 14) {
                if (itops_rem_once($db, "rem_sop_{$sp['id']}_due_{$tag}")) { itops_send_reminder($db, $sp['owner_id'], "SOP review due in {$days} day(s): {$sp['title']}", "The SOP \"{$sp['title']}\" is due for review on {$sp['next_review_date']}.", 'sop', 'sops', 'sop_review_due', $spv); $sent++; }
            }
        }
    } catch (\Throwable $e) { /* best effort */ }
    // requirement reviews: due soon + overdue
    if (itops_module_on('standards')) try {
        foreach ($db->query("SELECT id,title,next_review_date,owner_id FROM itops_std_requirements WHERE next_review_date IS NOT NULL") as $rq) {
            if (empty($rq['next_review_date'])) continue;
            $nd = strtotime($rq['next_review_date']); if ($nd === false) continue;
            $days = (int)floor(($nd - $today) / 86400); $tag = preg_replace('/[^0-9]/', '', $rq['next_review_date']);
            $rqv = ['requirement' => (string)$rq['title'], 'due_date' => itops_tpl_d2($rq['next_review_date']), 'days_left' => (string)max(0, $days)];
            if ($days < 0) {
                if (itops_rem_once($db, "rem_std_{$rq['id']}_overdue_{$tag}")) { itops_send_reminder($db, $rq['owner_id'], "Review overdue: {$rq['title']}", "The standards review for \"{$rq['title']}\" was due on {$rq['next_review_date']} and is overdue.", 'standards', 'standards', 'std_review_overdue', $rqv); $sent++; }
            } elseif ($days <= 14) {
                if (itops_rem_once($db, "rem_std_{$rq['id']}_due_{$tag}")) { itops_send_reminder($db, $rq['owner_id'], "Review due soon: {$rq['title']}", "The standards review for \"{$rq['title']}\" is due on {$rq['next_review_date']} ({$days} day(s)).", 'standards', 'standards', 'std_review_due', $rqv); $sent++; }
            }
        }
    } catch (\Throwable $e) { /* table may not exist yet */ }
    return $sent;
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

/* ---------------- BT93: vTiger records left for later on an offboarding — moved yet? reminder after N days ---------------- */
itops_cron_step('vTiger records left for later'); $OFF = itops_cron_off('vtiger');
if (!$OFF) try { require_once __DIR__ . '/api/lib/ObTasks.php'; [$vlC, $vlR, $vlM] = itops_ob_vt_left_run($db); itops_cron_note($vlC . ' checked, ' . $vlR . ' reminded, ' . $vlM . ' moved.'); }
catch (\Throwable $e) { itops_cron_fail($e); }

/* ---------------- BT75: resolved tickets close on their own after N business days with no reply ---------------- */
itops_cron_step('Close resolved tickets'); $OFF = itops_cron_off('service_desk');
if (!$OFF) try { require_once __DIR__ . '/api/lib/TicketLifecycle.php'; $acN = itops_tk_autoclose_run($db); itops_cron_note($acN . ' closed (after ' . itops_tk_close_days($db) . ' business days; 0 = off).'); }
catch (\Throwable $e) { itops_cron_fail($e); }

/* ---------------- BT76: a resolved case closes once every ticket of it is closed ---------------- */
itops_cron_step('Close finished cases'); $OFF = itops_cron_off('cases');
if (!$OFF) try { require_once __DIR__ . '/api/lib/Cases.php'; $csN = itops_cs_close_run($db); itops_cron_note($csN . ' closed.'); }
catch (\Throwable $e) { itops_cron_fail($e); }

/* ---------------- BT77: case meetings follow their calendar events; the daily case digest ---------------- */
itops_cron_step('Case meetings and digest'); $OFF = itops_cron_off('cases');
if (!$OFF) try { require_once __DIR__ . '/api/lib/Cases.php'; require_once __DIR__ . '/api/lib/EmailTemplates.php';
      $cmN = itops_cs_events_reconcile($db); $dg = itops_cs_digest_run($db);
      itops_cron_note($cmN . ' meeting task(s) updated; digest: ' . ($dg['reason'] !== '' ? $dg['reason'] : $dg['sent'] . ' sent, ' . $dg['nothing'] . ' with nothing to report') . '.'); }
catch (\Throwable $e) { itops_cron_fail($e); }

/* ---------------- Breach / approaching scan ---------------- */
$slaModOff = itops_cron_off('service_desk');
itops_cron_step('Renewal and review reminders');
try { $remSent = itops_run_reminders($db); itops_cron_note($remSent . ' sent.'); } catch (\Throwable $e) { $remSent = 0; itops_cron_fail($e); }
// BT60: calendar event reminders (in the app and by email, at the times set on each event)
itops_cron_step('Calendar reminders'); $OFF = itops_cron_off('calendar');
if (!$OFF) try { require_once __DIR__ . '/api/lib/Calendar.php'; if (function_exists('itops_cal_reminders_run')) { $calR = itops_cal_reminders_run($db); itops_cron_note($calR . ' sent.'); } } catch (\Throwable $e) { itops_cron_fail($e); }
// SLA alerts can be switched off without stopping the rest of the scheduled work below
// (webhook retries, onboarding reminders, digests, access recertification, Guardian).
$slaOn = !$slaModOff && itops_setting($db, 'sla_alerts_enabled', '1') === '1';
$respWarn = (int)itops_setting($db, 'sla_response_warn_min', 60);
$resoWarn = (int)itops_setting($db, 'sla_resolution_warn_min', 240);
$now = time();

itops_cron_step('SLA alerts');   // review DESK-19: the step is named where the scan runs, and a failure is recorded, not fatal
$sent = 0;
try {
$tickets = $slaOn ? $db->query("SELECT id,ticket_number,subject,priority,status,assignee_id,backup_id,sla_response_due,first_response_at,due_date
                       FROM itops_tickets WHERE status NOT IN ('resolved','closed','cancelled','pending')")->fetchAll() : [];   // pending = waiting on the requester: SLA paused, no alerts
$ins = $db->prepare("INSERT IGNORE INTO itops_sla_alerts (ticket_id,kind) VALUES (?,?)");
foreach ($tickets as $t) {
    $cand = [];
    if (empty($t['first_response_at']) && !empty($t['sla_response_due'])) {
        $d = strtotime($t['sla_response_due']);
        if ($now > $d) $cand[] = ['response_breach', 'Response SLA breached', true];
        elseif ($d - $now <= $respWarn * 60) $cand[] = ['response_warning', 'Response SLA due soon', false];
    }
    if (!empty($t['due_date'])) {
        $d = strtotime($t['due_date']);
        if ($now > $d) $cand[] = ['resolution_breach', 'Resolution SLA breached', true];
        elseif ($d - $now <= $resoWarn * 60) $cand[] = ['resolution_warning', 'Resolution SLA due soon', false];
    }
    foreach ($cand as $c) {
        $ins->execute([(int)$t['id'], $c[0]]);
        if ($ins->rowCount() === 1) { itops_send_alert($db, $t, $c[0], $c[1], $c[2]); $sent++; }
    }
}
itops_cron_note($slaOn ? $sent . ' alert(s) sent.' : 'switched off.');
} catch (\Throwable $e) { itops_cron_fail($e); }
// BT52: remember when the SLA scan last ran (Ticket Categories → "Routing & SLA check" shows it; never = the cron is not set up)
try { $db->prepare("INSERT INTO itops_settings (skey,sval) VALUES ('sla_scan_last',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([date('Y-m-d H:i:s')]); } catch (Throwable $e) {}
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

itops_cron_step('Overdue equipment loans'); $OFF = itops_cron_off('equipment');
// Overdue equipment loans: remind the issuer + admins about items past their return date.
if (!$OFF) try {
    $due = $db->query("SELECT l.id, l.borrower_name, l.due_date, l.issued_by, a.asset_tag, a.make, a.model
                       FROM itops_asset_loans l JOIN itops_assets a ON a.id=l.asset_id
                       WHERE l.status='out' AND l.due_date IS NOT NULL AND l.due_date < CURDATE()
                         AND (l.reminded_at IS NULL OR l.reminded_at < (NOW() - INTERVAL 20 HOUR))")->fetchAll();
    $ni = $db->prepare("INSERT INTO itops_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)");
    foreach ($due as $ln) {
        $msg = 'Overdue: ' . trim(($ln['make'] ?? '') . ' ' . ($ln['model'] ?? '')) . ' (' . $ln['asset_tag'] . ') with ' . $ln['borrower_name'] . ' — due ' . $ln['due_date'];
        $uids = [];
        if (!empty($ln['issued_by'])) $uids[] = (int)$ln['issued_by'];
        foreach (itops_admins($db) as $ad) $uids[] = (int)$ad['id'];
        foreach (array_unique($uids) as $uid) { try { $ni->execute([$uid, 'Equipment overdue', $msg, 'assets', '#assets']); } catch (\Throwable $e) {} }
        $db->prepare("UPDATE itops_asset_loans SET reminded_at=NOW() WHERE id=?")->execute([(int)$ln['id']]);
    }
} catch (\Throwable $e) { itops_cron_fail($e); }

if (is_file(__DIR__ . '/api/lib/Graph.php')) require_once __DIR__ . '/api/lib/Graph.php';
if (is_file(__DIR__ . '/api/lib/Guardian.php')) require_once __DIR__ . '/api/lib/Guardian.php';
itops_cron_step('Scorecard measurables'); $OFF = itops_cron_off('scorecard');
// Scorecard: refresh auto-measurables for the most-recent completed week (idempotent upsert).
if (!$OFF) try {
    $sf = __DIR__ . '/api/routes/scorecard.php';
    // inline compute to avoid loading the route; compute last Sunday
    $d = new DateTime('now'); $dow = (int)$d->format('N'); $d->modify('-' . $dow . ' days'); $pe = $d->format('Y-m-d');
    $scStart = '2026-09-06'; try { $sv = $db->query("SELECT sval FROM itops_settings WHERE skey='scorecard_start'")->fetchColumn(); if ($sv) $scStart = $sv; } catch (\Throwable $e) {}
    if ($pe < $scStart) { $meas = []; } else {
    $end = new DateTime($pe . ' 00:00:00'); $ws = (clone $end)->modify('-6 days')->format('Y-m-d H:i:s'); $we = (clone $end)->modify('+1 day')->format('Y-m-d H:i:s');
    $meas = $db->query("SELECT id, source FROM itops_scorecard_measurables WHERE active=1 AND source<>'manual'")->fetchAll(); }
    if ($meas) {
        $up = $db->prepare("INSERT INTO itops_scorecard_entries (measurable_id, period_end, value, is_auto) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE value=IF(is_auto=1, VALUES(value), value)");
        foreach ($meas as $m) {
            $v = null; $src = $m['source'];
            if (($scm = itops_sc_source_module((string)$src)) !== null && !itops_module_on($scm)) continue;   // BT101: its module is off — the last value stays
            try {
                if ($src === 'p1_incidents') { $s = $db->prepare("SELECT COUNT(*) FROM itops_tickets WHERE priority='p1_critical' AND created_at>=? AND created_at<?"); $s->execute([$ws,$we]); $v = (float)$s->fetchColumn(); }
                elseif ($src === 'ticket_volume') { $s = $db->prepare("SELECT COUNT(*) FROM itops_tickets WHERE created_at>=? AND created_at<?"); $s->execute([$ws,$we]); $v = (float)$s->fetchColumn(); }
                elseif ($src === 'frt_minutes') { $s = $db->prepare("SELECT AVG(TIMESTAMPDIFF(MINUTE,created_at,first_response_at)) FROM itops_tickets WHERE first_response_at IS NOT NULL AND created_at>=? AND created_at<?"); $s->execute([$ws,$we]); $x=$s->fetchColumn(); $v = $x===null?null:round((float)$x,1); }
                elseif ($src === 'sla_closure_pct') { $s = $db->prepare("SELECT COUNT(*) tot, SUM(CASE WHEN due_date IS NULL OR resolved_at<=due_date THEN 1 ELSE 0 END) ok FROM itops_tickets WHERE resolved_at IS NOT NULL AND resolved_at>=? AND resolved_at<?"); $s->execute([$ws,$we]); $r=$s->fetch(); $tot=(int)($r['tot']??0); $v = $tot? round(100.0*(int)$r['ok']/$tot,1) : null; }
                elseif ($src === 'onboarding_ontime') { $s = $db->prepare("SELECT r.id,(SELECT COUNT(*) FROM itops_ob_run_tasks t WHERE t.run_id=r.id AND t.due_date IS NOT NULL AND t.status='done' AND t.due_date<DATE(COALESCE(t.completed_at, r.completed_at))) late FROM itops_ob_runs r WHERE r.kind='onboard' AND r.status='completed' AND r.completed_at>=? AND r.completed_at<?"); $s->execute([$ws,$we]); $rows=$s->fetchAll(); $tot=count($rows); if($tot){ $g=0; foreach($rows as $rr) if((int)$rr['late']===0)$g++; $v=round(100.0*$g/$tot,1);} }
                elseif ($src === 'open_alerts') { $v = (float)$db->query("SELECT COUNT(*) FROM itops_alerts WHERE status='open'")->fetchColumn(); }
                elseif ($src === 'secure_score_pct') { if (function_exists('itops_graph_configured') && itops_graph_configured()) { $rr = itops_graph_get('security/secureScores?$top=1'); $row = $rr['value'][0] ?? null; $mx = (float)($row['maxScore'] ?? 0); $v = ($row && $mx > 0) ? round(100*(float)($row['currentScore'] ?? 0)/$mx,1) : null; } }
                elseif ($src === 'intune_compliance') { if (function_exists('itops_graph_configured') && itops_graph_configured()) { $rr = itops_graph_get('deviceManagement/managedDevices?$select=complianceState&$top=200'); $tt=0; $okc=0; foreach(($rr['value'] ?? []) as $d){ $tt++; if(strtolower((string)($d['complianceState'] ?? ''))==='compliant')$okc++; } $v = $tt? round(100*$okc/$tt,1):null; } }
                elseif ($src === 'mfa_pct') { if (function_exists('itops_graph_configured') && itops_graph_configured()) { $rr = itops_graph_get('reports/authenticationMethods/userRegistrationDetails?$top=500'); $tt=0; $rg=0; foreach(($rr['value'] ?? []) as $u){ $tt++; if(!empty($u['isMfaRegistered']))$rg++; } $v = $tt? round(100*$rg/$tt,1):null; } }
                elseif ($src === 'ssl_days_min') { if (function_exists('itops_guardian_ssl')) { $sec = itops_guardian_ssl(itops_guardian_settings($db)); $mn=null; foreach(($sec['findings'] ?? []) as $f){ if(preg_match('/EXPIRED/i',(string)$f['value']))$mn=0; elseif(preg_match('/(\\d+)\\s*days? left/i',(string)$f['value'],$dm)){ $d=(int)$dm[1]; $mn=($mn===null)?$d:min($mn,$d);} } $v = $mn===null?null:(float)$mn; } }
                elseif (strpos($src, 'uptime_') === 0 && $src !== 'uptime_pct') { require_once __DIR__ . '/api/lib/Uptime.php'; $v = itops_uptime_scorecard_value($db, $src, $ws, $we); }   // BT61
                elseif (strpos($src, 'bkp_') === 0) { require_once __DIR__ . '/api/lib/Backups.php'; $v = itops_bk_scorecard_value($db, $src, $ws, $we); }   // BT62
                // Review DESK-14: the Reports, priorities and security-posture sources are filled every week too (the same functions the page uses)
                elseif (strpos($src, 'ins_') === 0) { require_once __DIR__ . '/api/lib/ServiceMgmt.php'; require_once __DIR__ . '/api/lib/Insights.php'; $v = itops_ins_scorecard_value($db, $src, $ws, $we); }
                elseif (strpos($src, 'pri_') === 0) { require_once __DIR__ . '/api/lib/BusinessHours.php'; require_once __DIR__ . '/api/lib/ScorecardDetail.php'; $v = itops_scp_value($db, $src, $ws, $we); }
                elseif (strpos($src, 'sa_') === 0) { require_once __DIR__ . '/api/lib/SecAssess.php'; require_once __DIR__ . '/api/lib/SaPlan.php'; require_once __DIR__ . '/api/lib/SaProof.php'; $v = itops_pf_scorecard_value($db, $src, $ws, $we); }
                elseif ($src === 'uptime_pct') { $s2 = $db->prepare("SELECT first_seen, COALESCE(cleared_at,NOW()) ce FROM itops_alerts WHERE level='error' AND channel='guardian' AND first_seen<? AND COALESCE(cleared_at,NOW())>?"); $s2->execute([$we,$ws]); $down=0; foreach($s2->fetchAll() as $rr){ $aa=max(strtotime($rr['first_seen']),strtotime($ws)); $bb=min(strtotime($rr['ce']),strtotime($we)); if($bb>$aa)$down+=($bb-$aa);} $wk=strtotime($we)-strtotime($ws); $v = $wk>0? round(100.0*max(0,$wk-$down)/$wk,2):null; }
                elseif ($src === 'backup_success') { $s2 = $db->prepare("SELECT COUNT(DISTINCT DATE(ts)) FROM itops_event_log WHERE channel='backup' AND level<>'error' AND ts>=? AND ts<?"); $s2->execute([$ws,$we]); $dd=(int)$s2->fetchColumn(); $v = $dd===0? null : round(100.0*min(7,$dd)/7,1); }
            } catch (\Throwable $e) { $v = null; }
            if ($v !== null) $up->execute([(int)$m['id'], $pe, $v]);
        }
    }
} catch (\Throwable $e) { itops_cron_fail($e); }

itops_cron_step('Weekly operations digest');
// Weekly ops digest: on the configured day, once per week, email admins a rollup.
try {
    if (itops_setting($db, 'weekly_digest_enabled', '0') === '1') {
        $thisWeek = date('o-W'); // ISO year-week
        $dow = (int)date('N');   // 1=Mon
        $wantDow = (int)itops_setting($db, 'weekly_digest_dow', '1');
        if ($dow === $wantDow && itops_setting($db, 'weekly_digest_last', '') !== $thisWeek) {
            $g = function (string $sql) use ($db) { try { return (int)$db->query($sql)->fetchColumn(); } catch (\Throwable $e) { return 0; } };
            $openTickets   = $g("SELECT COUNT(*) FROM itops_tickets WHERE status NOT IN ('resolved','closed','cancelled')");   // BT52: cancelled is not open
            $breached      = $g("SELECT COUNT(*) FROM itops_tickets WHERE status NOT IN ('resolved','closed','cancelled','pending') AND due_date IS NOT NULL AND due_date < NOW()");   // Pending: clock paused
            $obActive      = $g("SELECT COUNT(*) FROM itops_ob_runs WHERE status='in_progress'");
            $obDueWk       = $g("SELECT COUNT(*) FROM itops_ob_run_tasks rt JOIN itops_ob_runs r ON r.id=rt.run_id WHERE rt.status='pending' AND r.status='in_progress' AND rt.due_date IS NOT NULL AND rt.due_date <= (CURDATE() + INTERVAL 7 DAY)");
            $openAlerts    = $g("SELECT COUNT(*) FROM itops_alerts WHERE status='open'");
            $kbGaps        = $g("SELECT COUNT(*) FROM itops_kb_gaps");
            $deflected     = $g("SELECT COALESCE(SUM(deflect_count),0) FROM itops_kb_articles");
            $warrExp       = $g("SELECT COUNT(*) FROM itops_assets WHERE status<>'retired' AND warranty_expiry IS NOT NULL AND warranty_expiry BETWEEN CURDATE() AND (CURDATE() + INTERVAL 30 DAY)");
            $loansOut      = $g("SELECT COUNT(*) FROM itops_asset_loans WHERE status='out'");
            $loansOverdue  = $g("SELECT COUNT(*) FROM itops_asset_loans WHERE status='out' AND due_date IS NOT NULL AND due_date < CURDATE()");
            $report = [
                'en' => "- Open tickets: $openTickets (SLA-breached: $breached)\n- Onboarding/offboarding in progress: $obActive (tasks due within 7 days: $obDueWk)\n"
                      . "- Open alerts: $openAlerts\n- KB content gaps logged: $kbGaps\n- Tickets deflected by self-service (all-time): $deflected\n"
                      . "- Equipment on loan: $loansOut (overdue: $loansOverdue)\n- Warranties expiring within 30 days: $warrExp",
                'es' => "- Tickets abiertos: $openTickets (SLA incumplido: $breached)\n- Incorporaciones/salidas en curso: $obActive (tareas que vencen en 7 días: $obDueWk)\n"
                      . "- Alertas abiertas: $openAlerts\n- Vacíos de contenido en la base de conocimiento: $kbGaps\n- Tickets evitados por autoservicio (total): $deflected\n"
                      . "- Equipos en préstamo: $loansOut (vencidos: $loansOverdue)\n- Garantías que vencen en 30 días: $warrExp",
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
                   'link' => defined('APP_URL') ? rtrim(APP_URL, '/') . '/#guardian' : ''];
            foreach (itops_admins($db) as $ad) { if (!empty($ad['email'])) { try { itops_email('digest_alerts_daily', $ad['email'], $av + ['recipient_name' => $ad['display_name'] ?? ''], ['user_id' => (int)$ad['id']]); } catch (\Throwable $e) {} } }
        }
        $db->prepare("INSERT INTO itops_settings (skey,sval) VALUES ('alert_digest_last',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([date('Y-m-d')]);
    }
} catch (\Throwable $e) { itops_cron_fail($e); }

    if (function_exists('itops_log')) {
        itops_log('info', 'cron', 'SLA scan complete', [
            'tickets' => count($tickets), 'alerts_sent' => $sent, 'reminders' => $remSent,
            'webhooks_retried' => $whRetried, 'alerts_raised' => $alertSummary['raised'],
            'alerts_cleared' => $alertSummary['cleared'], 'alerts_open' => $alertSummary['open'],
        ]);
    }
}

// BT61: uptime checks (when cron-uptime.php is not set up, the main scheduler checks the monitors that are due)
itops_cron_step('Uptime checks'); $OFF = itops_cron_off('servers');
if (!$OFF) try { require_once __DIR__ . '/api/lib/Uptime.php'; if (function_exists('itops_uptime_run')) { $upR = itops_uptime_run($db); itops_cron_note($upR['busy'] ? 'cron-uptime.php was running.' : $upR['checked'] . ' checked, ' . $upR['down'] . ' failed.'); } } catch (\Throwable $e) { itops_cron_fail($e); }
// BT62: backups — read WHM (hourly) and mark late backups (cron-uptime.php does it every minute when it is set up)
itops_cron_step('Backup checks'); $OFF = itops_cron_off('servers');
if (!$OFF) try { require_once __DIR__ . '/api/lib/Backups.php'; $bkR = itops_bk_run($db); itops_cron_note($bkR['busy'] ? 'cron-uptime.php was running.' : $bkR['jobs'] . ' backup(s), ' . $bkR['late'] . ' late' . ($bkR['events'] ? '; ' . implode('; ', $bkR['events']) : '') . '.'); } catch (\Throwable $e) { itops_cron_fail($e); }
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
itops_cron_end(null, count($tickets) . ' open tickets checked, ' . $sent . ' SLA alert(s), ' . $remSent . ' reminder(s), ' . $whRetried . ' webhook retr' . ($whRetried === 1 ? 'y' : 'ies') . '.');
if (php_sapi_name() !== 'cli' || array_intersect(['-v', '--verbose'], $argv ?? [])) echo json_encode(['mode' => 'scan', 'tickets' => count($tickets), 'alerts_sent' => $sent, 'reminders_sent' => $remSent, 'webhooks_retried' => $whRetried, 'alerts' => $alertSummary, 'm365_refresh' => $m365bg]) . "\n";   // BT87: quiet from cron (no email per run; every run is in Logs → Scheduled jobs); -v prints the summary

// BT129: the maintenance window (opt-in, Settings → Updates). Last of all, because placing new files changes the code
// this run was started with: a build offered by the License Hub, checked with no problem, is installed inside the
// window; step 2 runs as its own process on the new code (or at the next run).
if (!$digest && php_sapi_name() === 'cli') try {   // the command line only: step 2 must be able to run on the new code
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
