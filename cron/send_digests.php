<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * Activity-pulse digests. Run daily; weekly digests go out on Mondays.
 *   0 7 * * *  /usr/local/bin/php /path/to/cron/send_digests.php
 * CLI-only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require dirname(__DIR__) . '/app/bootstrap.php';

$daily = FlxCoreHC\ActivityDigestService::runAll('daily');
$weekly = ((int) date('N') === 1) ? FlxCoreHC\ActivityDigestService::runAll('weekly') : 0;   // Monday
[$sent, $failed] = FlxCoreHC\MailService::flush(500);
fwrite(STDOUT, "digests queued: daily={$daily} weekly={$weekly}; delivered {$sent}, failed {$failed}\n");
