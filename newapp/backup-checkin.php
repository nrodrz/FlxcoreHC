<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — a backup script checks in when it finishes (BT62). IT Operations → Servers → Backups.
 *
 * On this server (no key needed — only someone who can run commands on the server can run it):
 *   php /path/to/opshub/backup-checkin.php <short-name> [ok|failed] ["note"]
 * Add it as the last line of the backup script, for example at the end of copyBackups.sh:
 *   if [ $? -eq 0 ]; then php …/backup-checkin.php copybackups ok; else php …/backup-checkin.php copybackups failed "copy failed"; fi
 * From another server, use the private address shown on the Backups page (curl … /api/backups/checkin?key=…).
 *
 * Exit code: 0 recorded, 2 unknown or paused backup, 3 not set up yet. It only runs from the command line.
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); exit("Forbidden — run it from the command line, or use the check-in address shown on the Backups page.\n"); }
require __DIR__ . '/config.php';
require_once __DIR__ . '/api/lib/TimeZone.php'; try { opsh_tz_apply(getDB()); } catch (Throwable $e) { opsh_tz_boot(); }   // BT131: the installation's own zone
require_once __DIR__ . '/api/lib/BusinessHours.php'; try { opsh_db_clock_sync(getDB()); } catch (Throwable $e) {}
require_once __DIR__ . '/api/lib/MailIntake.php';
require_once __DIR__ . '/api/lib/EventLog.php';
require_once __DIR__ . '/api/lib/Brand.php'; require_once __DIR__ . '/api/lib/EmailTemplates.php';
require_once __DIR__ . '/api/lib/Backups.php';
require_once __DIR__ . '/api/lib/Modules.php';
if (!opsh_module_on('servers')) { fwrite(STDERR, "Servers is not part of this installation: the check-in was not recorded.\n"); exit(4); }   // BT101

$args = array_slice($argv, 1);
if (!$args || in_array($args[0], ['-h', '--help'], true)) { fwrite(STDERR, "Usage: php backup-checkin.php <short-name> [ok|failed] [\"note\"]\n"); exit(2); }
$db = getDB();
if (!opsh_bk_ready($db)) { fwrite(STDERR, "Backup monitoring is not installed yet: run db-update.php once.\n"); exit(3); }
$job = opsh_bk_by_slug($db, (string)$args[0]);
if (!$job) { fwrite(STDERR, 'No backup with the short name "' . $args[0] . "\" (IT Operations → Servers → Backups).\n"); exit(2); }
if ($job['kind'] !== 'checkin') { fwrite(STDERR, "That is the WHM backup: it is read from WHM, it does not check in.\n"); exit(2); }
if (!$job['active']) { fwrite(STDERR, "That backup is paused.\n"); exit(2); }
$st = strtolower((string)($args[1] ?? 'ok')); $ok = !in_array($st, ['fail', 'failed', 'error', '0', 'false'], true);
$note = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', implode(' ', array_slice($args, 2)))), 0, 300);
opsh_bk_record($db, $job, $ok, 'checkin', $note !== '' ? $note : ($ok ? 'Checked in: done.' : 'Checked in: the backup failed.'), 'cli');
echo 'Recorded for "' . $job['name'] . '": ' . ($ok ? 'ok' : 'failed') . "\n";
exit(0);
