<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT16 Integration Audit scheduler entry point.
 *
 * Does NOTHING unless a Super Admin has approved AND enabled a collector (Integration Audit → Evidence →
 * Collectors) and set its frequency for the current monitoring period. All collectors ship OFF.
 * Add this line to cron only after that approval (the finest frequency you can use is how often cron runs it):
 *
 *   * * * * *  php /path/to/itops/cron-audit.php
 *   or over HTTP with the cron token in a HEADER (no ?token= on this endpoint):
 *   curl -s -H "X-ITOps-Cron-Token: YOUR_TOKEN" https://YOUR-SITE/path-to-itops/cron-audit.php
 *
 * It also applies the retention policy once a day — only if an administrator has saved one.
 */
require __DIR__ . '/config.php';
// BT125: while an update is being installed (Settings → Updates) the scheduled jobs wait for the next run
if (is_file(__DIR__ . '/data/updates/maintenance.json') && time() - (int)@filemtime(__DIR__ . '/data/updates/maintenance.json') < 900) { echo "Update in progress: skipped.\n"; exit(0); }
require_once __DIR__ . '/api/lib/TimeZone.php'; try { itops_tz_apply(getDB()); } catch (Throwable $e) { itops_tz_boot(); }   // BT131: the installation's own zone
require_once __DIR__ . '/api/lib/BusinessHours.php'; try { itops_db_clock_sync(getDB()); } catch (Throwable $e) {}   // BT52: the database clock follows PHP's (due times vs NOW())
require_once __DIR__ . '/api/lib/MailIntake.php';
require_once __DIR__ . '/api/lib/EventLog.php';
require_once __DIR__ . '/api/lib/IntegrationCollectors.php';

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    $cfg0 = function_exists('mail_cfg') ? mail_cfg() : [];
    $token = (string)($cfg0['mail_cron_token'] ?? '');
    if ($token === '' || !hash_equals($token, (string)($_SERVER['HTTP_X_ITOPS_CRON_TOKEN'] ?? ''))) { http_response_code(403); exit("Forbidden — send the X-ITOps-Cron-Token header.\n"); }
}

$db = getDB();
try { $db->query('SELECT 1 FROM itops_collector_runs LIMIT 1'); }
catch (\Throwable $e) { exit("Integration audit not installed yet — run db-update.php.\n"); }

require_once __DIR__ . '/api/lib/CronMonitor.php'; require_once __DIR__ . '/api/lib/Modules.php';   // BT60: the run is recorded (Logs → Scheduled jobs)
if (php_sapi_name() !== 'cli') ignore_user_abort(true);
itops_cron_begin($db, 'audit');
itops_cron_step('Collectors and retention');
if (itops_cron_off('integration_audit')) $r = ['ran' => [], 'note' => 'Integration Audit is not part of this installation.'];   // BT101
else try { $r = itops_ic_scheduler($db); }
catch (\Throwable $e) { itops_cron_fail($e); itops_cron_end('failed', 'Failed: ' . $e->getMessage()); if (function_exists('itops_log')) itops_log('error', 'integrations', 'cron-audit failed: ' . $e->getMessage()); http_response_code(500); exit("error\n"); }
if (!($r['ran'] ?? [])) itops_cron_idle();   // BT62: nothing due — kept a day only in the run history
itops_cron_end(null, ($r['ran'] ?? []) ? count($r['ran']) . ' collector run(s).' : (string)($r['note'] ?? 'Nothing due.'));
if (php_sapi_name() !== 'cli' || array_intersect(['-v', '--verbose'], $argv ?? [])) echo json_encode(['period' => $r['period'] ?? '', 'ran' => array_map(fn($x) => $x['status'] ?? '', $r['ran'] ?? []), 'note' => $r['note'] ?? '']) . "\n";   // BT87: quiet from cron (no email per run; every run is in Logs → Scheduled jobs); -v prints the summary
