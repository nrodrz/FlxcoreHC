<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT129: step 2 of an update installed by the maintenance window (the database update, then maintenance off).
 * Runs on the NEW code: the scheduler starts it as its own process right after placing the files; if it could not
 * start it, the next scheduler run finishes it. Command line only (the web answers 404).
 */
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
if (!defined('ITOPS_UPD_FINISH_INCLUDED')) require __DIR__ . '/config.php';
require_once __DIR__ . '/api/lib/TimeZone.php'; try { itops_tz_apply(getDB()); } catch (Throwable $e) { itops_tz_boot(); }   // BT131: the installation's own zone
require_once __DIR__ . '/api/lib/UpdateFlow.php';
$__db = getDB(); $__m = itops_upd_maintenance(); $__last = itops_upd_last($__db);
if ($__m && (string)$__m['to'] === ITOPS_BUILD && ($__last['status'] ?? '') === 'placed' && (int)($__last['by'] ?? -1) === 0) {
    try { $__r = itops_upd_run_finish($__db, 0); echo ($__r['ok'] ? 'Build ' . $__r['build'] . ' installed.' : 'The database update failed: ' . $__r['message']) . "\n"; }
    catch (Throwable $e) { echo 'Not finished: ' . $e->getMessage() . "\n"; }
} else echo defined('ITOPS_UPD_FINISH_INCLUDED') ? "Update in progress: skipped.\n" : "Nothing to finish.\n";
