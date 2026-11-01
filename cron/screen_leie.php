<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);
/**
 * Scheduled OIG LEIE exclusion sweep — run from cPanel cron (e.g. daily or weekly):
 *   /usr/local/bin/php /home/CPANELUSER/flxcred/cron/screen_leie.php
 *
 * Screens every active provider in every active, non-demo tenant against the currently loaded LEIE
 * list. Each provider is screened once per list vintage, so running this daily is safe: it only does
 * work when a new monthly list has been imported (see /admin/leie). When a provider is flagged as a
 * potential match, the tenant owner receives one summary email.
 *
 * Results are REVIEW SIGNALS, never verdicts — a hit is recorded as 'potential_match', never
 * 'excluded'. Identity must be confirmed at oig.hhs.gov before any action.
 *
 * This is OPTIONAL: providers can also be screened on demand from the provider page. Wire this up if
 * you want continuous screening without anyone clicking.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require dirname(__DIR__) . '/app/bootstrap.php';

$r = FlxCoreHC\LeieService::sweepAll();
if (empty($r['loaded'])) {
    echo sprintf("[%s] LEIE sweep: no list loaded — import the monthly OIG file at /admin/leie first.\n", utc_now());
    exit(0);
}
[$sent, $failed] = FlxCoreHC\MailService::flush(500);

echo sprintf("[%s] LEIE sweep: %d provider(s) across %d tenant(s) screened, %d potential match(es); alerts %d sent, %d queued/failed\n",
    utc_now(), $r['screened'], $r['tenants'], $r['matches'], $sent, $failed);
