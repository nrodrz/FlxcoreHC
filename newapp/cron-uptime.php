<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — uptime monitoring cron entry point (BT61).
 *
 * Checks every monitor whose interval has passed (websites you added, WHM/cPanel accounts) and reads the WHM accounts
 * every 6 hours. BT66: also runs the Outlook / Teams calendar sync (about every 3 minutes, when it is on). BT62: also watches the backups (IT Operations → Backups): reads WHM's backups hourly and marks late ones. Run it every minute (the monitors keep their own interval):
 *   * * * * *  php /path/to/itops/cron-uptime.php
 * or over HTTP with the cron token in a header:
 *   curl -s -H "X-ITOps-Cron-Token: YOUR_TOKEN" https://YOUR-SITE/path-to-itops/cron-uptime.php
 * Without it, the main scheduler and the in-app scheduler run the checks too (less often).
 */
require __DIR__ . '/config.php';
// BT125: while an update is being installed (Settings → Updates) the scheduled jobs wait for the next run
if (is_file(__DIR__ . '/data/updates/maintenance.json') && time() - (int)@filemtime(__DIR__ . '/data/updates/maintenance.json') < 900) { echo "Update in progress: skipped.\n"; exit(0); }
require_once __DIR__ . '/api/lib/TimeZone.php'; try { itops_tz_apply(getDB()); } catch (Throwable $e) { itops_tz_boot(); }   // BT131: the installation's own zone
require_once __DIR__ . '/api/lib/BusinessHours.php'; try { itops_db_clock_sync(getDB()); } catch (Throwable $e) {}
require_once __DIR__ . '/api/lib/MailIntake.php';
require_once __DIR__ . '/api/lib/EventLog.php';

$cli = (php_sapi_name() === 'cli');
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    $cfg0 = function_exists('mail_cfg') ? mail_cfg() : [];
    $token = (string)($cfg0['mail_cron_token'] ?? '');
    if ($token === '' || !hash_equals($token, (string)($_SERVER['HTTP_X_ITOPS_CRON_TOKEN'] ?? ''))) { http_response_code(403); exit("Forbidden — send the X-ITOps-Cron-Token header.\n"); }
    ignore_user_abort(true);
}
$db = getDB();
require_once __DIR__ . '/api/lib/CronMonitor.php'; require_once __DIR__ . '/api/lib/Modules.php';
require_once __DIR__ . '/api/lib/Brand.php'; require_once __DIR__ . '/api/lib/EmailTemplates.php';
require_once __DIR__ . '/api/lib/Uptime.php';
require_once __DIR__ . '/api/lib/Backups.php';
itops_cron_begin($db, 'uptime');
itops_cron_step('Server performance sample'); if (!itops_cron_off('servers')) try { require_once __DIR__ . '/api/lib/ServerPerf.php'; $spDb = getDB(); if (itops_sp_ready($spDb)) itops_sp_sample($spDb); } catch (Throwable $e) { itops_cron_fail($e); }   // BT155: every minute
itops_cron_step('Server error logs'); if (!itops_cron_off('servers')) try { require_once __DIR__ . '/api/lib/ErrLogs.php'; $elDb = getDB(); if (itops_errlog_ready($elDb)) { $elR = itops_errlog_collect($elDb); if (isset($elR['read'])) itops_cron_note($elR['read'] . ' account(s) read, ' . $elR['events'] . ' new error line(s).' . ($elR['note'] ? ' ' . $elR['note'] : '')); } } catch (Throwable $e) { itops_cron_fail($e); }   // BT162: every 10 minutes
itops_cron_step('Check the monitors'); $srvOff = itops_cron_off('servers');   // BT101
$r = ['checked' => 0, 'down' => 0, 'events' => [], 'whm' => null]; $b = ['read' => 0, 'events' => [], 'late' => 0];
if (!$srvOff) try {
    $r = itops_uptime_run($db);
    if ($r['busy']) itops_cron_note('Another check was still running.');
    else itops_cron_note($r['checked'] . ' checked, ' . $r['down'] . ' failed' . ($r['events'] ? '; ' . implode('; ', $r['events']) : '') . '.');
    if (is_array($r['whm']) && empty($r['whm']['ok'])) itops_cron_fail('WHM accounts not read: ' . ($r['whm']['error'] ?? ''), 'warning');
} catch (Throwable $e) { itops_cron_fail($e); $r = ['checked' => 0, 'down' => 0]; }
// BT62: backups — WHM read hourly, late backups marked
itops_cron_step('Backup checks'); if ($srvOff) itops_cron_off('servers');
if (!$srvOff) try { $b = itops_bk_run($db); itops_cron_note($b['jobs'] . ' backup(s), ' . $b['late'] . ' late' . ($b['events'] ? '; ' . implode('; ', $b['events']) : '') . '.'); }
catch (Throwable $e) { itops_cron_fail($e); $b = ['read' => 0, 'events' => [], 'late' => 0]; }
// BT66: the Outlook / Teams calendar sync (every ~3 minutes; off until an admin turns it on)
$os = ['ran' => false];
itops_cron_step('Outlook calendar sync');
if (!itops_cron_off('calendar')) try { require_once __DIR__ . '/api/lib/OutlookSync.php'; $os = itops_os_run($db);
      if (!empty($os['ran'])) { itops_cron_note('Outlook: ' . $os['created'] . ' added, ' . $os['updated'] . ' updated, ' . $os['removed'] . ' removed, ' . $os['from_outlook'] . ' changed in Outlook, ' . $os['answers'] . ' answer(s).');
          if ($os['failed_people']) itops_cron_fail('Outlook: ' . count($os['failed_people']) . ' person(s) not synced — ' . $os['failed_people'][0], 'warning'); } }
catch (Throwable $e) { itops_cron_fail($e, 'warning'); }
itops_cron_step('Check the other scheduled jobs');
try { itops_cron_watch($db); } catch (Throwable $e) {}
// BT62: a run with nothing due is "idle": kept a day only, so this every-minute job does not crowd the run history
$osBusy = !empty($os['ran']) && ($os['created'] + $os['updated'] + $os['removed'] + $os['from_outlook'] + $os['answers'] + $os['errors']) > 0;
if (!(int)($r['checked'] ?? 0) && empty($r['events']) && empty($r['whm']) && !(int)$b['read'] && !$b['events'] && !(int)$b['late'] && !$osBusy) itops_cron_idle();
itops_cron_end(null, (int)$r['checked'] . ' monitor(s) checked, ' . (int)$r['down'] . ' failed; ' . (int)($b['jobs'] ?? 0) . ' backup(s), ' . (int)$b['late'] . ' late.');
if (php_sapi_name() !== 'cli' || array_intersect(['-v', '--verbose'], $argv ?? [])) echo json_encode(['checked' => $r['checked'] ?? 0, 'down' => $r['down'] ?? 0, 'backups' => $b['jobs'] ?? 0, 'late' => $b['late'] ?? 0]) . "\n";   // BT87: quiet from cron (no email per run; every run is in Logs → Scheduled jobs); -v prints the summary
if (mt_rand(1, 60) === 1) { try { itops_cron_purge($db); } catch (Throwable $e) {} }
