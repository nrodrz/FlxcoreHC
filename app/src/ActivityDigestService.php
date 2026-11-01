<?php
declare(strict_types=1);

namespace FlxCoreHC;

use FlxCoreHC\Repository\AuditLogRepository;

/**
 * The activity-pulse email digest. For each user who opted in (users.activity_digest = daily|weekly),
 * builds a plain summary of their organization's activity over the period and queues it through the
 * normal mail pipeline (so it honours per-tenant email delivery). Idempotent per (user, period) via a
 * dedupe subject stamp is unnecessary — the cron runs once per day and only matches the due cadence.
 */
final class ActivityDigestService
{
    /**
     * Send digests due today. `$cadence` is 'daily' or 'weekly'; weekly runs are expected to be
     * invoked on Mondays by the cron. Returns the number of digests queued.
     */
    public static function runAll(string $cadence = 'daily'): int
    {
        $cadence = $cadence === 'weekly' ? 'weekly' : 'daily';
        $pdo = Database::pdo();
        $sent = 0;

        try {
            $users = $pdo->prepare(
                "SELECT u.id, u.tenant_id, u.email, u.full_name, u.role, u.locale,
                        t.name AS tenant_name, t.vertical, t.org_shape, t.timezone
                 FROM users u JOIN tenants t ON t.id = u.tenant_id
                 WHERE u.activity_digest = ? AND u.status = 'active' AND t.status = 'active'
                   AND u.email IS NOT NULL AND u.email <> ''");
            $users->execute([$cadence]);
            $rows = $users->fetchAll();
        } catch (\Throwable $e) {
            return 0; // column may not exist pre-migration
        }

        $days = $cadence === 'weekly' ? 7 : 1;

        foreach ($rows as $u) {
            app_tz_use_tenant($u['timezone'] ?: null);   // v1.172.16: the digest period is this organization's days
            $from = today_local('-' . ($days - 1) . ' days');
            $to   = today_local();
            // Only roles that can see the audit log get an activity digest.
            if (!in_array($u['role'], ['tenant_owner', 'manager', 'admin'], true)) continue;
            $ctx = new TenantContext($u['tenant_id'], $u['id'], (string)$u['role'], (string)$u['tenant_name'],
                (string)($u['vertical'] ?? 'healthcare'), (string)($u['org_shape'] ?? 'medical_group'), (string)$u['full_name'], false);
            $audit = new AuditLogRepository($ctx);
            $totals = $audit->activityTotals($from, $to);
            if ((int)($totals['total'] ?? 0) === 0) continue; // nothing to report — don't nag

            [$subject, $body] = self::compose($ctx, $audit, $from, $to, $cadence, $totals);
            MailService::queue((string)$u['email'], $subject, $body, 'digest', (string)$u['full_name'], (string)$u['tenant_id']);
            $sent++;
        }
        app_tz_use_tenant(null);
        return $sent;
    }

    /** @return array{0:string,1:string} [subject, body] */
    private static function compose(TenantContext $ctx, AuditLogRepository $audit, string $from, string $to, string $cadence, array $totals): array
    {
        $period = $cadence === 'weekly' ? 'this week' : 'today';
        $counts = $audit->actionCounts($from, $to);
        $bucket = function (array $keys) use ($counts) { $n = 0; foreach ($keys as $k) $n += $counts[$k] ?? 0; return $n; };
        $lines = [
            ['Providers added',      $bucket(['provider_created', 'holder_onboard_finished'])],
            ['Credentials added',    $bucket(['credential_created', 'credential_import'])],
            ['Credentials updated',  $bucket(['credential_updated'])],
            ['Credentials removed',  $bucket(['credential_deleted'])],
            ['Documents verified',   $bucket(['document_verified'])],
            ['Screenings run',       $bucket(['exclusion_screen', 'exclusion_screen_recorded'])],
            ['Reminders sent',       $bucket(['holder_nudged'])],
            ['Reports exported',     $bucket(['report_export', 'history_export', 'data_export', 'audit_export'])],
            ['Team & role changes',  $bucket(['team_member_invited', 'role_created', 'role_updated', 'role_deleted', 'provider_access_mode'])],
        ];

        $subject = $ctx->tenantName . ' — activity ' . ($cadence === 'weekly' ? 'this week' : 'today')
                 . ' (' . (int)($totals['total'] ?? 0) . ' actions)';

        $b = "Activity summary for {$ctx->tenantName} — {$period}.\n";
        $b .= str_repeat('=', 52) . "\n\n";
        $b .= "Total actions: " . (int)($totals['total'] ?? 0)
            . "   People active: " . (int)($totals['actors'] ?? 0)
            . "   Records touched: " . (int)($totals['records'] ?? 0) . "\n\n";
        $b .= "What happened:\n";
        foreach ($lines as [$label, $n]) {
            if ($n > 0) $b .= sprintf("  %-24s %d\n", $label . ':', $n);
        }

        $people = $audit->activityByPerson($from, $to, 8);
        if ($people) {
            $b .= "\nMost active people:\n";
            foreach ($people as $p) { $b .= sprintf("  %-28s %d actions\n", $p['name'], (int)$p['n']); }
        }

        $base = flx_public_base_safe();
        $b .= "\nOpen the activity dashboard: {$base}/audit/pulse\n";
        $b .= "\n—\nYou're receiving this because your FlxCoreHC digest is set to {$cadence}. "
            . "Change it in Profile.\n";
        return [$subject, $b];
    }
}
