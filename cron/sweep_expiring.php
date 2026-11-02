<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);
/**
 * Daily sweep — run from cPanel cron:
 *   /usr/local/bin/php /home/CPANELUSER/flxcorehc/cron/sweep_expiring.php
 *
 * 1. Moves credential status: active -> expiring (within window) -> expired (past date).
 * 2. Queues reminders at each configured threshold (180/90/30 by default).
 * Operates across ALL tenants; every statement remains tenant-correct because
 * it derives tenant_id from the credential row itself.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require dirname(__DIR__) . '/app/bootstrap.php';

use FlxCoreHC\Database;

$pdo    = Database::pdo();
$window = (int) config('expiring_window_days', 90);

// 1) Status transitions — one organization at a time, because "today" is each ORGANIZATION's calendar day (v1.172.16:
//    Puerto Rico and Florida are not on the same clock). app_tz_use_tenant() also re-points @flx_today at that zone's day.
$nExpired = 0; $nExpiring = 0;
$tenants = $pdo->query("SELECT id, timezone FROM tenants")->fetchAll();
foreach ($tenants as $t) {
    app_tz_use_tenant($t['timezone'] ?: null);
    $tid = $t['id'];

    // Capture which credentials are about to flip to 'expired' so we can record the moment permanently.
    $aboutToExpire = $pdo->prepare(
        "SELECT c.id, c.tenant_id, c.holder_id, c.name, c.expiry_date
         FROM credentials c
         WHERE c.tenant_id = ? AND c.expiry_date IS NOT NULL AND c.expiry_date < @flx_today AND c.status <> 'expired' AND c.deleted_at IS NULL"
    );
    $aboutToExpire->execute([$tid]);
    $aboutToExpire = $aboutToExpire->fetchAll();

    $expired = $pdo->prepare(
        "UPDATE credentials SET status = 'expired'
         WHERE tenant_id = ? AND expiry_date IS NOT NULL AND expiry_date < @flx_today AND status <> 'expired' AND deleted_at IS NULL"
    );
    $expired->execute([$tid]);
    $nExpired += $expired->rowCount();

    // Permanent compliance-history entry per newly-expired credential (system actor).
    foreach ($aboutToExpire as $c) {
        try {
            $pdo->prepare(
                'INSERT INTO compliance_events (id, tenant_id, holder_id, credential_id, event_type, summary, effective_on, actor_name, created_at)
                 VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
            )->execute([$c['tenant_id'], $c['holder_id'], $c['id'], 'credential_expired',
                'Credential expired: ' . $c['name'], $c['expiry_date'], 'System']);
        } catch (\Throwable $e) { /* ledger is best-effort */ }
    }

    $expiring = $pdo->prepare(
        "UPDATE credentials SET status = 'expiring'
         WHERE tenant_id = :t
           AND expiry_date IS NOT NULL
           AND expiry_date >= @flx_today
           AND expiry_date <= DATE_ADD(@flx_today, INTERVAL :w DAY)
           AND status = 'active'
           AND deleted_at IS NULL"
    );
    $expiring->execute(['t' => $tid, 'w' => $window]);
    $nExpiring += $expiring->rowCount();
}
app_tz_use_tenant(null);

// 2) (v1.170.8) Reminder pre-queueing REMOVED. It inserted credential_reminders rows with sent_at NULL
//    that nothing consumed, and ReminderService::claim() (INSERT IGNORE on the same UNIQUE key) then treated
//    the duplicate as "already sent" — so the 30-day email was never sent for any credential this sweep
//    reached first. ReminderService (cron/send_reminders.php) is the single sender and claims its own rows.
$nQueued = 0;

echo sprintf("[%s] sweep: %d expired, %d expiring, %d reminders queued\n",
    utc_now(), $nExpired, $nExpiring, $nQueued);
