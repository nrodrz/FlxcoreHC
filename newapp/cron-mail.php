<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Email intake cron entry point
 *
 * Run from cron (recommended):
 *   php /path/to/opshub/cron-mail.php
 *
 * Or over HTTP with the token shown in the admin area, in a header (BT87: never in the address — it would land in logs):
 *   curl -s -H "X-OpsHub-Cron-Token: YOUR_TOKEN" "https://YOUR-SITE/path-to-opshub/cron-mail.php"
 *
 * Suggested schedule: every 5 minutes.
 */
require __DIR__ . '/config.php';
// BT125: while an update is being installed (Settings → Updates) the scheduled jobs wait for the next run
if (is_file(__DIR__ . '/data/updates/maintenance.json') && time() - (int)@filemtime(__DIR__ . '/data/updates/maintenance.json') < 900) { echo "Update in progress: skipped.\n"; exit(0); }
require_once __DIR__ . '/api/lib/TimeZone.php'; try { opsh_tz_apply(getDB()); } catch (Throwable $e) { opsh_tz_boot(); }   // BT131: the installation's own zone
require_once __DIR__ . '/api/lib/BusinessHours.php'; try { opsh_db_clock_sync(getDB()); } catch (Throwable $e) {}   // BT52: the database clock follows PHP's (due times vs NOW())
require __DIR__ . '/api/lib/MailIntake.php';

$cli = (php_sapi_name() === 'cli');
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    $cfg = mail_cfg();
    $token = $cfg['mail_cron_token'] ?? '';
    if (!$token || !hash_equals((string)$token, (string)($_SERVER['HTTP_X_OPSHUB_CRON_TOKEN'] ?? ''))) {   // BT87: the header only
        http_response_code(403);
        exit("Forbidden — send the X-OpsHub-Cron-Token header (a ?token= in the address is no longer accepted).\n");
    }
}

// BT60: the run is recorded (Logs → Scheduled jobs)
require_once __DIR__ . '/api/lib/CronMonitor.php'; require_once __DIR__ . '/api/lib/Modules.php';
$db = getDB();
if (!$cli) ignore_user_abort(true);
opsh_cron_begin($db, 'mail');
opsh_cron_step('Read the help desk mailbox');
if (opsh_cron_off('service_desk')) { $res = ['ok' => true, 'created' => 0, 'appended' => 0, 'skipped' => 'Service Desk is not part of this installation.']; opsh_cron_idle(); }   // BT101
else try { $res = mail_intake_poll(); } catch (\Throwable $e) { $res = ['ok' => false, 'error' => $e->getMessage()]; }
$mailOff = (($res['error'] ?? '') === 'Email intake is disabled.'); if ($mailOff) $res['error'] = '';
if (empty($res['ok']) && !empty($res['error'])) opsh_cron_fail((string)$res['error']);
elseif ($mailOff) opsh_cron_note('Email intake is switched off.');
elseif (!empty($res['skipped'])) {}
else opsh_cron_note((int)($res['created'] ?? 0) . ' ticket(s) created, ' . (int)($res['appended'] ?? 0) . ' reply(ies) added.');
opsh_cron_step('Check the other scheduled jobs');
try { opsh_cron_watch($db); } catch (\Throwable $e) {}
opsh_cron_end(empty($res['ok']) && !empty($res['error']) ? 'failed' : null, empty($res['ok']) && !empty($res['error']) ? 'Mailbox not read: ' . $res['error'] : ($mailOff ? 'Email intake is switched off.' : (int)($res['created'] ?? 0) . ' ticket(s) created, ' . (int)($res['appended'] ?? 0) . ' reply(ies) added.'));
if ($mailOff) $res['error'] = 'Email intake is disabled.';
if (php_sapi_name() !== 'cli' || array_intersect(['-v', '--verbose'], $argv ?? [])) echo json_encode($res) . "\n";   // BT87: quiet from cron (no email per run; every run is in Logs → Scheduled jobs); -v prints the summary
