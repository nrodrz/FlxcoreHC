<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * In-app notification center.
 *
 * Two sources feed it:
 *  1. An idempotent SWEEP (run opportunistically on dashboard / notifications loads and from the
 *     reminder cron) that turns the current state of the data into notifications: credentials
 *     expiring soon or expired, document verifications due for re-check, overdue calendar items,
 *     and exclusion-screening flags. Dedupe keys mean each fact notifies once per user.
 *  2. EVENT hooks called at the moment something happens: a provider assigned to a user, an import
 *     completed, etc.
 *
 * Need-to-know is applied at generation: a user in "assigned only" mode is notified only about the
 * providers assigned to them. Provider-portal users get nothing here.
 */
final class NotificationService
{
    public const EXPIRING_DAYS = 30;
    private const SWEEP_TTL_SECONDS = 300;   // at most one sweep per tenant per 5 minutes

    // ---- reading ---------------------------------------------------------------

    /**
     * v1.171.0 (B-4) — read-time need-to-know. Generation already skips providers a user cannot see, but a user who is
     * later un-assigned (or switched to "assigned only") still owned old notifications that name the provider. Every
     * read now also requires that any provider-linked notification points at a provider the user may currently see.
     * Returns [sqlFragment, params].
     */
    private static function scopeFragment(TenantContext $ctx): array
    {
        $sc = ProviderScope::for($ctx);
        if (!$sc->restricted()) return ['', []];
        $in = substr($sc->sql('entity_id', false), 5);   // strip the leading " AND " — the predicate itself is untouched
        return [" AND (entity_type IS NULL OR entity_type <> 'holders' OR {$in})", $sc->params()];
    }

    public static function unreadCount(TenantContext $ctx): int
    {
        if ((string)$ctx->userId === '') return 0;
        try {
            [$sf, $sp] = self::scopeFragment($ctx);
            $st = Database::pdo()->prepare('SELECT COUNT(*) FROM notifications WHERE tenant_id = ? AND user_id = ? AND read_at IS NULL' . $sf);
            $st->execute(array_merge([$ctx->tenantId, $ctx->userId], $sp));
            return (int)$st->fetchColumn();
        } catch (\Throwable $e) { flx_log_error('error', 'reminder/notification scan failed — result is UNKNOWN, not zero: ' . $e->getMessage()); return 0; }
    }

    public static function list(TenantContext $ctx, bool $unreadOnly = false, int $limit = 100): array
    {
        if (SupportAccess::active()) return [];
        [$sf, $sp] = self::scopeFragment($ctx);
        $sql = 'SELECT * FROM notifications WHERE tenant_id = ? AND user_id = ?' . ($unreadOnly ? ' AND read_at IS NULL' : '') . $sf
             . ' ORDER BY (read_at IS NULL) DESC, created_at DESC LIMIT ' . max(1, min($limit, 300));
        $st = Database::pdo()->prepare($sql);
        $st->execute(array_merge([$ctx->tenantId, $ctx->userId], $sp));
        return $st->fetchAll();
    }

    public static function markRead(TenantContext $ctx, string $id): void
    {
        Database::pdo()->prepare('UPDATE notifications SET read_at = ? WHERE id = ? AND tenant_id = ? AND user_id = ? AND read_at IS NULL')
            ->execute([utc_now(), $id, $ctx->tenantId, $ctx->userId]);
    }

    public static function markAllRead(TenantContext $ctx): int
    {
        $st = Database::pdo()->prepare('UPDATE notifications SET read_at = ? WHERE tenant_id = ? AND user_id = ? AND read_at IS NULL');
        $st->execute([utc_now(), $ctx->tenantId, $ctx->userId]);
        return $st->rowCount();
    }

    // ---- writing ---------------------------------------------------------------

    /** Create one notification for one user. Idempotent on (tenant, user, dedupe_key). */
    public static function push(string $tenantId, string $userId, string $kind, string $severity, string $title,
                                ?string $body, ?string $link, ?string $entityType, ?string $entityId, string $dedupeKey): bool
    {
        try {
            $st = Database::pdo()->prepare(
                'INSERT IGNORE INTO notifications (id, tenant_id, user_id, kind, severity, title, body, link, entity_type, entity_id, dedupe_key, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([uuid4(), $tenantId, $userId, $kind, $severity, mb_substr($title, 0, 200), $body !== null ? mb_substr($body, 0, 500) : null,
                          $link, $entityType, $entityId, mb_substr($dedupeKey, 0, 120), utc_now()]);
            return $st->rowCount() > 0;
        } catch (\Throwable $e) { flx_log_error('error', 'reminder/notification scan failed — result is UNKNOWN, not zero: ' . $e->getMessage()); return false; }
    }

    /** Notify every staff user who can see this provider (owners/all-mode users + assigned users). */
    /**
     * Notification titles and bodies are stored in English when the sweep runs (the sweep has no
     * user), so they are translated when SHOWN. Each known template is matched and re-rendered
     * through t() with its placeholders; anything unrecognised goes through t() unchanged.
     */
    public static function localize(string $text): string
    {
        static $rules = [
            ['/^(.+) has expired — (.+)$/su',                     '{name} has expired — {who}',                       ['name', 'who']],
            ['/^(.+) expires in (\d+) day\(s\) — (.+)$/su',       '{name} expires in {days} day(s) — {who}',          ['name', 'days', 'who']],
            ['/^Expired (\d+) day\(s\) ago\. Renew and upload the new document\.$/u', 'Expired {days} day(s) ago. Renew and upload the new document.', ['days']],
            ['/^Due (\S+)\. Start the renewal now\.$/u',        'Due {date}. Start the renewal now.',               ['date']],
            ['/^Was due (\S+)\.$/u',                             'Was due {date}.',                                  ['date']],
            ['/^Overdue: (.+)$/su',                               'Overdue: {title}',                                 ['title']],
            ['/^Re-verify (.+) \((.*)\)$/su',                    'Re-verify {file} ({cred})',                        ['file', 'cred']],
            ['/^Verification is due to be repeated by (\S+)\.$/u', 'Verification is due to be repeated by {date}.',   ['date']],
            ['/^Exclusion screening flag — (.+)$/su',             'Exclusion screening flag — {source}',              ['source']],
            ['/^Result "(.*)" on (\S+)\. Review before any billing\.$/su', 'Result "{result}" on {date}. Review before any billing.', ['result', 'date']],
            ['/^(.+) was assigned to you$/su',                    '{name} was assigned to you',                       ['name']],
            ['/^Note: (.+)$/su',                                  'Note: {note}',                                     ['note']],
            ['/^Credential import finished: (\d+) imported, (\d+) skipped$/u', 'Credential import finished: {n} imported, {e} skipped', ['n', 'e']],
            ['/^Credential import finished: (\d+) imported$/u',   'Credential import finished: {n} imported',         ['n']],
        ];
        foreach ($rules as [$re, $tpl, $keys]) {
            if (preg_match($re, $text, $m)) {
                $vars = [];
                foreach ($keys as $i => $k) { $vars[$k] = $m[$i + 1]; }
                if (isset($vars['name']) && !str_contains($tpl, 'assigned')) { $vars['name'] = \t($vars['name']); }   // credential names come from the catalogue
                if (isset($vars['cred'])) { $vars['cred'] = \t($vars['cred']); }
                return \t($tpl, $vars);
            }
        }
        return \t($text);
    }

    public static function pushForHolder(TenantContext $ctx, string $holderId, string $kind, string $severity, string $title,
                                         ?string $body, ?string $link, string $dedupeKey): int
    {
        $n = 0;
        foreach (self::recipientsFor($ctx, $holderId) as $uid) {
            if (self::push($ctx->tenantId, $uid, $kind, $severity, $title, $body, $link, 'holders', $holderId, $dedupeKey)) $n++;
        }
        return $n;
    }

    /** Staff user ids who may see a given provider: all-mode users plus assigned users. */
    private static function recipientsFor(TenantContext $ctx, string $holderId): array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            "SELECT u.id FROM users u
             WHERE u.tenant_id = ? AND u.status = 'active' AND u.role NOT IN ('provider','producer')
               AND NOT EXISTS (SELECT 1 FROM roles r WHERE r.slug = u.role AND r.portal_only = 1
                               AND (r.tenant_id = u.tenant_id OR r.tenant_id IS NULL))   -- v1.170.9: custom portal-only roles are providers, not staff
               AND (u.role = 'tenant_owner' OR u.platform_admin = 1 OR COALESCE(u.provider_access,'all') = 'all'
                    OR EXISTS (SELECT 1 FROM holder_assignments a WHERE a.tenant_id = u.tenant_id AND a.user_id = u.id AND a.holder_id = ?))");
        $st->execute([$ctx->tenantId, $holderId]);
        return array_column($st->fetchAll(), 'id');
    }

    // ---- sweep -----------------------------------------------------------------

    /**
     * Turn the current state into notifications. Safe to call often: throttled per tenant and
     * idempotent through dedupe keys. Returns the number of new notifications created.
     */
    public static function sweep(TenantContext $ctx, bool $force = false): int
    {
        static $ran = [];
        if (isset($ran[$ctx->tenantId])) return 0;
        $ran[$ctx->tenantId] = true;
        if (!$force && !self::shouldSweep($ctx->tenantId)) return 0;

        $pdo = Database::pdo(); $created = 0; $today = today_local_dt();
        $base = rtrim((string)(defined('BASE_PATH') ? BASE_PATH : ''), '/');

        // 1) Credentials expiring within the window, or already expired (one notice per stage).
        $st = $pdo->prepare(
            "SELECT c.id, c.holder_id, c.name, c.expiry_date, h.first_name, h.last_name, h.org_name, h.holder_type
             FROM credentials c JOIN credential_holders h ON h.id = c.holder_id AND h.tenant_id = c.tenant_id
             WHERE c.tenant_id = ? AND c.deleted_at IS NULL AND h.deleted_at IS NULL
               AND c.expiry_date IS NOT NULL AND c.expiry_date <= DATE_ADD(@flx_today, INTERVAL ? DAY)");
        $st->execute([$ctx->tenantId, self::EXPIRING_DAYS]);
        foreach ($st->fetchAll() as $c) {
            $who = $c['holder_type'] === 'organization' ? (string)$c['org_name'] : trim($c['first_name'] . ' ' . $c['last_name']);
            $days = (int)$today->diff(new \DateTimeImmutable($c['expiry_date']))->format('%r%a');
            if ($days < 0) {
                $created += self::pushForHolder($ctx, $c['holder_id'], 'expired', 'danger',
                    "{$c['name']} has expired — {$who}", "Expired " . abs($days) . " day(s) ago. Renew and upload the new document.",
                    "$base/holders/{$c['holder_id']}", "cred:{$c['id']}:expired");
            } else {
                $stage = $days <= 7 ? '7' : '30';
                $created += self::pushForHolder($ctx, $c['holder_id'], 'expiring', $days <= 7 ? 'danger' : 'warn',
                    "{$c['name']} expires in {$days} day(s) — {$who}", "Due " . fmt_day($c['expiry_date']) . ". Start the renewal now.",
                    "$base/holders/{$c['holder_id']}", "cred:{$c['id']}:exp{$stage}");
            }
        }

        // 2) Document verifications due for re-check within 30 days.
        $st = $pdo->prepare(
            "SELECT d.id, d.original_name, d.verification_expires, c.holder_id, c.name AS cred_name
             FROM credential_documents d JOIN credentials c ON c.id = d.credential_id
             WHERE d.tenant_id = ? AND d.deleted_at IS NULL AND c.deleted_at IS NULL
               AND d.verification_expires IS NOT NULL AND d.verification_expires <= DATE_ADD(@flx_today, INTERVAL 30 DAY)");
        $st->execute([$ctx->tenantId]);
        foreach ($st->fetchAll() as $d) {
            $created += self::pushForHolder($ctx, $d['holder_id'], 'reverify', 'warn',
                "Re-verify {$d['original_name']} ({$d['cred_name']})", "Verification is due to be repeated by {$d['verification_expires']}.",
                "$base/holders/{$d['holder_id']}", "doc:{$d['id']}:reverify");
        }

        // 3) Overdue open calendar items (manual events and deadlines).
        $st = $pdo->prepare(
            "SELECT id, holder_id, title, due_at FROM calendar_events
             WHERE tenant_id = ? AND deleted_at IS NULL AND status = 'open' AND due_at < NOW() AND due_at > DATE_SUB(NOW(), INTERVAL 120 DAY)");
        $st->execute([$ctx->tenantId]);
        foreach ($st->fetchAll() as $e) {
            $title = "Overdue: {$e['title']}";
            if ($e['holder_id']) {
                $created += self::pushForHolder($ctx, $e['holder_id'], 'overdue', 'danger', $title, 'Was due ' . fmt_dt($e['due_at'], 'm/d/Y') . '.', "$base/calendar?view=agenda", "cal:{$e['id']}:overdue");
            } else {
                foreach (self::allStaff($ctx->tenantId) as $uid) {
                    if (self::push($ctx->tenantId, $uid, 'overdue', 'danger', $title, 'Was due ' . fmt_dt($e['due_at'], 'm/d/Y') . '.', "$base/calendar?view=agenda", 'calendar_events', $e['id'], "cal:{$e['id']}:overdue")) $created++;
                }
            }
        }

        // 4) Exclusion-screening flags (any non-clear result).
        try {
            $st = $pdo->prepare(
                "SELECT s.id, s.holder_id, s.source, s.result, s.screened_on FROM exclusion_screenings s
                 WHERE s.tenant_id = ? AND LOWER(s.result) IN ('potential_match','match','possible_match','excluded','hit','flag','flagged')");
            $st->execute([$ctx->tenantId]);
            foreach ($st->fetchAll() as $s) {
                $created += self::pushForHolder($ctx, $s['holder_id'], 'screening', 'danger',
                    'Exclusion screening flag — ' . strtoupper((string)$s['source']), "Result \"{$s['result']}\" on {$s['screened_on']}. Review before any billing.",
                    "$base/holders/{$s['holder_id']}", "screen:{$s['id']}:flag");
            }
        } catch (\Throwable $e) { /* table may be absent on older installs */ }

        self::stampSweep($ctx->tenantId);
        return $created;
    }

    private static function allStaff(string $tenantId): array
    {
        $st = Database::pdo()->prepare("SELECT id FROM users WHERE tenant_id = ? AND status = 'active' AND role NOT IN ('provider','producer')");
        $st->execute([$tenantId]);
        return array_column($st->fetchAll(), 'id');
    }

    private static function shouldSweep(string $tenantId): bool
    {
        $f = self::stampPath($tenantId);
        return !is_file($f) || (time() - (int)filemtime($f)) > self::SWEEP_TTL_SECONDS;
    }

    private static function stampSweep(string $tenantId): void
    {
        @touch(self::stampPath($tenantId));
    }

    private static function stampPath(string $tenantId): string
    {
        // v1.171.5: next to the configured documents folder (kept out of the code tree when docs_path is elsewhere —
        // the test runners point it at a temp folder), else app/storage as before.
        $docs = rtrim((string) config('docs_path', ''), '/');
        $dir = ($docs !== '' && is_dir(dirname($docs)) && is_writable(dirname($docs))) ? dirname($docs) : FLXCOREHC_APP . '/storage';
        return $dir . '/.notif-sweep-' . preg_replace('/[^a-f0-9-]/i', '', $tenantId);
    }
}
