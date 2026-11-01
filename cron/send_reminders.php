<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);
/**
 * Compliance reminder sweep — optional, run from cPanel cron (e.g. daily):
 *   /usr/local/bin/php /home/CPANELUSER/flxcred/cron/send_reminders.php
 *
 * Sends expiry reminders for every active, non-demo tenant through the email outbox
 * (MailService), which respects the operator's SMTP settings and records delivery. This is
 * OPTIONAL: the same reminders are also evaluated opportunistically when managers open the
 * compliance or AEP dashboards, so FlxCoreHC works without any cron at all. Wire this up only
 * if you want reminders to go out even when nobody visits the dashboard.
 *
 * Safe to run repeatedly: each (credential, threshold, expiry date) reminds exactly once.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require dirname(__DIR__) . '/app/bootstrap.php';

$queued = FlxCoreHC\ReminderService::runAll();
[$sent, $failed] = FlxCoreHC\MailService::flush(500);

echo sprintf("[%s] reminders: %d queued, %d delivered, %d failed/queued\n",
    utc_now(), $queued, $sent, $failed);
