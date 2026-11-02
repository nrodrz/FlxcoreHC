<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC\Repository;

use FlxCoreHC\Database;
use FlxCoreHC\Crypto;

/**
 * Calendar & client-interaction timeline.
 *
 * Beyond manual tasks/events, sync() auto-materializes compliance deadlines so an
 * agent sees one unified timeline without configuring anything:
 *   - credential expiries        -> 'deadline' events
 *   - CMS season milestones      -> 'cms_milestone' events
 * Auto events are keyed by (source, source_ref) so re-syncing updates rather than
 * duplicates. Manual events are never touched by sync.
 */
final class CalendarRepository extends TenantRepository
{
    // Platform support sessions never see tenant records — see SupportAccess.
    public function find(string $id): ?array
    {
        if (\FlxCoreHC\SupportAccess::active()) return null;
        return parent::find($id);   // provider scope applied in SQL (base)
    }

    public function all(array $where = [], string $orderBy = 'created_at DESC', int $limit = 500): array
    {
        if (\FlxCoreHC\SupportAccess::active()) return [];
        return parent::all($where, $orderBy, $limit);
    }

    protected function table(): string { return 'calendar_events'; }
    protected function providerColumn(): ?string { return 'holder_id'; }   // need-to-know scope (v1.170.9)

    /**
     * Build a read-only iCalendar (ICS) document of this tenant's events for a subscription feed.
     * Manual events and auto-generated compliance deadlines, over a rolling window.
     */
    public function icsFeed(string $calName): string
    {
        $stmt = Database::pdo()->prepare(
            "SELECT id, kind, title, detail, due_at, end_at, all_day, status, holder_id
               FROM calendar_events
              WHERE tenant_id = ? AND deleted_at IS NULL{$this->scopeSql()[0]}
                AND due_at >= (NOW() - INTERVAL 90 DAY) AND due_at <= (NOW() + INTERVAL 400 DAY)
              ORDER BY due_at ASC"
        );
        $stmt->execute(array_merge([$this->ctx->tenantId], $this->scopeSql()[1]));
        $rows = $stmt->fetchAll();   // provider scope applied in SQL — the feed sees exactly what the user sees
        $esc = fn(string $s) => str_replace([chr(92), ';', ',', "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', ''], $s);
        $fmt = function (string $dt, bool $allDay) {
            $d = new \DateTimeImmutable($dt, new \DateTimeZone('UTC'));
            return $allDay ? ';VALUE=DATE:' . $d->format('Ymd') : ':' . $d->format('Ymd\THis\Z');
        };
        $out = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//FlxCoreHC//Calendar//EN', 'METHOD:PUBLISH',
                'X-WR-CALNAME:' . $esc($calName), 'CALSCALE:GREGORIAN'];
        foreach ($rows as $e) {
            $allDay = (int)($e['all_day'] ?? 0) === 1;
            $out[] = 'BEGIN:VEVENT';
            $out[] = 'UID:' . $e['id'] . '@flxcorehc';
            $out[] = 'DTSTAMP:' . (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd\THis\Z');
            $out[] = 'DTSTART' . $fmt((string)$e['due_at'], $allDay);
            if (!empty($e['end_at']) && !$allDay) $out[] = 'DTEND' . $fmt((string)$e['end_at'], false);
            $out[] = 'SUMMARY:' . $esc((string)$e['title']);
            if (!empty($e['detail'])) $out[] = 'DESCRIPTION:' . $esc((string)$e['detail']);
            $out[] = 'STATUS:' . (($e['status'] ?? '') === 'cancelled' ? 'CANCELLED' : 'CONFIRMED');
            $out[] = 'END:VEVENT';
        }
        $out[] = 'END:VCALENDAR';
        return implode("\r\n", $out) . "\r\n";
    }
    protected function softDeletes(): bool { return true; }
    protected function fillable(): array
    {
        return ['holder_id','kind','source','source_ref','title','detail',
                'client_name','client_name_enc','policy_id','carrier_id',
                'due_at','end_at','all_day','priority','status','completed_at'];
    }
    protected function encryptedColumns(): array { return ['client_name' => 'client_name_enc']; }

    /**
     * v1.172.9: auto-generated events (credential expiries, re-verifications, payer/program/OCS deadlines) are stored
     * with English titles by the sync. This returns the row with title/detail translated for DISPLAY only; manual events
     * (the user's own words) and the stored data are never touched.
     */
    public static function localizeAuto(array $r): array
    {
        if (($r['source'] ?? 'manual') === 'manual') return $r;
        $title = (string)($r['title'] ?? ''); $detail = $r['detail'] ?? null;
        if (preg_match('/^(.+?) expires(?: — (.+))?$/su', $title, $m)) {
            $r['title'] = isset($m[2]) && $m[2] !== '' ? \t('{name} expires — {who}', ['name' => \t($m[1]), 'who' => $m[2]]) : \t('{name} expires', ['name' => \t($m[1])]);
        } elseif (preg_match('/^Re-verify (.+?)(?: — (.+))?$/su', $title, $m)) {
            $r['title'] = isset($m[2]) && $m[2] !== '' ? \t('Re-verify {cred} — {who}', ['cred' => \t($m[1]), 'who' => $m[2]]) : \t('Re-verify {cred}', ['cred' => \t($m[1])]);
        } elseif (preg_match('/^Recredentialing — (.+) — (.+)$/su', $title, $m)) {
            $r['title'] = \t('Recredentialing — {payer} — {who}', ['payer' => $m[1], 'who' => $m[2]]);
        } elseif (preg_match('/^(Medicaid PEP revalidation|Medicare PECOS revalidation|OCS \/ SICRO attestation)(?: — (.+))?$/su', $title, $m)) {
            $r['title'] = isset($m[2]) && $m[2] !== '' ? \t($m[1] . ' — {who}', ['who' => $m[2]]) : \t($m[1]);
        }
        if (is_string($detail) && $detail !== '') {
            if (preg_match('/^Primary-source verification lapses \((.*)\)\.$/su', $detail, $m)) { $r['detail'] = \t('Primary-source verification lapses ({method}).', ['method' => $m[1]]); }
            else { $r['detail'] = \t($detail); }
        }
        return $r;
    }

    /**
     * SQL for "due on a local calendar day between $from and $to (inclusive)". An all-day item is stored as its own
     * day (placeholder time), a timed item as a UTC instant, so the local day bounds are converted to UTC for timed
     * items only. Comparing local bounds with raw UTC values dropped a Honolulu 12/31 23:30 event from December.
     * @return array{0:string,1:array} [sql fragment starting with " AND ", params]
     */
    private static function dayWindow(?string $from, ?string $to): array
    {
        $sql = ''; $p = [];
        if ($from) { $sql .= ' AND ((all_day = 1 AND due_at >= ?) OR (all_day = 0 AND due_at >= ?))'; $p[] = $from . ' 00:00:00'; $p[] = local_to_utc($from . ' 00:00:00'); }
        if ($to) {
            $next = (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
            $sql .= ' AND ((all_day = 1 AND due_at < ?) OR (all_day = 0 AND due_at < ?))'; $p[] = $next . ' 00:00:00'; $p[] = local_to_utc($next . ' 00:00:00');
        }
        return [$sql, $p];
    }

    /** Events in a date window (inclusive), decrypted, ordered by due date. */
    public function between(string $fromDate, string $toDate, array $where = []): array
    {
        $sql = "SELECT * FROM calendar_events
                WHERE tenant_id = ? AND deleted_at IS NULL{$this->scopeSql()[0]}" . self::dayWindow($fromDate, $toDate)[0];
        $params = array_merge([$this->ctx->tenantId], $this->scopeSql()[1], self::dayWindow($fromDate, $toDate)[1]);
        foreach ($where as $col => $val) { $this->assertColumn($col); $sql .= " AND `{$col}` = ?"; $params[] = $val; }
        $sql .= " ORDER BY due_at ASC, priority DESC";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $this->decryptRows($stmt->fetchAll());
    }

    /** Upcoming open items (agenda), next N days. */
    public function upcoming(int $days = 30, int $limit = 50): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM calendar_events
             WHERE tenant_id = ? AND deleted_at IS NULL AND status = 'open'{$this->scopeSql()[0]}
               AND due_at <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY)
             ORDER BY due_at ASC LIMIT {$limit}"
        );
        $stmt->execute(array_merge([$this->ctx->tenantId], $this->scopeSql()[1], [$days]));
        return $this->decryptRows($stmt->fetchAll());
    }

    public function overdueCount(): int
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM calendar_events
             WHERE tenant_id = ? AND deleted_at IS NULL AND status = 'open'
               AND ((all_day = 1 AND due_at < ?) OR (all_day = 0 AND due_at < UTC_TIMESTAMP())){$this->scopeSql()[0]}"
        );
        // an all-day item is overdue once its DAY has passed in the organization's zone; a timed one at its instant
        $stmt->execute(array_merge([$this->ctx->tenantId, today_local() . ' 00:00:00'], $this->scopeSql()[1]));
        return (int)$stmt->fetchColumn();
    }

    /**
     * Filtered event query for large tenants. Narrows by provider, group, location, kind, priority,
     * and status. Group/location use EXISTS sub-selects against the membership tables so a billing
     * company with hundreds of providers can focus on one client group or site. Capped for safety.
     *
     * @param array{from?:string,to?:string,holder_id?:string,group_id?:string,location_id?:string,kind?:string,priority?:string,status?:string} $f
     */
    public function filtered(array $f, int $limit = 500): array
    {
        $sql = "SELECT * FROM calendar_events WHERE tenant_id = ? AND deleted_at IS NULL{$this->scopeSql()[0]}";
        $p = array_merge([$this->ctx->tenantId], $this->scopeSql()[1]);
        [$__w, $__wp] = self::dayWindow($f['from'] ?? null, $f['to'] ?? null); $sql .= $__w; $p = array_merge($p, $__wp);
        if (!empty($f['holder_id'])) { $sql .= " AND holder_id = ?"; $p[] = $f['holder_id']; }
        if (!empty($f['kind']))      { $sql .= " AND kind = ?"; $p[] = $f['kind']; }
        if (!empty($f['priority']))  { $sql .= " AND priority = ?"; $p[] = $f['priority']; }
        if (!empty($f['status']))    { $sql .= " AND status = ?"; $p[] = $f['status']; }
        if (!empty($f['group_id'])) {
            $sql .= " AND holder_id IN (SELECT holder_id FROM holder_groups WHERE tenant_id = ? AND group_id = ?)";
            $p[] = $this->ctx->tenantId; $p[] = $f['group_id'];
        }
        if (!empty($f['location_id'])) {
            $sql .= " AND holder_id IN (SELECT holder_id FROM holder_locations WHERE tenant_id = ? AND location_id = ?)";
            $p[] = $this->ctx->tenantId; $p[] = $f['location_id'];
        }
        $sql .= " ORDER BY due_at ASC, priority DESC LIMIT " . (int)$limit;
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($p);
        return $this->decryptRows($stmt->fetchAll());
    }

    /**
     * Per-day open-event counts for a month, honouring the same filters. Lets the month grid show a
     * count badge per day instead of rendering thousands of events. Returns ['YYYY-MM-DD' => n].
     */
    public function dayCounts(string $monthStart, string $monthEnd, array $f = []): array
    {
        [$__w, $__wp] = self::dayWindow($monthStart, $monthEnd);
        $sql = "SELECT due_at, all_day FROM calendar_events
                WHERE tenant_id = ? AND deleted_at IS NULL{$this->scopeSql()[0]}" . $__w;
        $p = array_merge([$this->ctx->tenantId], $this->scopeSql()[1], $__wp);
        if (!empty($f['holder_id']))   { $sql .= " AND holder_id = ?"; $p[] = $f['holder_id']; }
        if (!empty($f['kind']))        { $sql .= " AND kind = ?"; $p[] = $f['kind']; }
        if (!empty($f['status']))      { $sql .= " AND status = ?"; $p[] = $f['status']; }
        if (!empty($f['group_id']))    { $sql .= " AND holder_id IN (SELECT holder_id FROM holder_groups WHERE tenant_id = ? AND group_id = ?)"; $p[] = $this->ctx->tenantId; $p[] = $f['group_id']; }
        if (!empty($f['location_id'])) { $sql .= " AND holder_id IN (SELECT holder_id FROM holder_locations WHERE tenant_id = ? AND location_id = ?)"; $p[] = $this->ctx->tenantId; $p[] = $f['location_id']; }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($p);
        $out = [];
        // count by the LOCAL day: an all-day item is its stored day, a timed item is converted from UTC
        foreach ($stmt->fetchAll() as $r) {
            $d = ((int)$r['all_day'] === 1) ? substr((string)$r['due_at'], 0, 10) : fmt_dt((string)$r['due_at'], 'Y-m-d');
            $out[$d] = ($out[$d] ?? 0) + 1;
        }
        return $out;
    }

    /**
     * Agenda grouped into urgency buckets — the scale-friendly default for a billing company with
     * thousands of items. One pass over open events in [today .. +horizon], bucketed by due date.
     * @return array{overdue:array,week:array,month:array,later:array,counts:array}
     */
    public function agenda(array $f = [], int $horizonDays = 120): array
    {
        $today = today_local_dt();       // the organization's day (v1.172.11), not the server's UTC day
        $f = $f + ['status' => 'open'];
        $f['from'] = '2000-01-01';                 // include overdue
        $f['to']   = $today->modify("+{$horizonDays} days")->format('Y-m-d');
        $rows = $this->filtered($f, 1000);
        $b = ['overdue' => [], 'week' => [], 'month' => [], 'later' => []];
        $wk = $today->modify('+7 days')->format('Y-m-d');
        $mo = $today->modify('+30 days')->format('Y-m-d');
        $td = $today->format('Y-m-d');
        $nowUtc = gmdate('Y-m-d H:i:s');
        foreach ($rows as $e) {
            $ad = (int)($e['all_day'] ?? 0) === 1;
            $d = $ad ? substr((string)$e['due_at'], 0, 10) : fmt_dt((string)$e['due_at'], 'Y-m-d');
            // same rule as overdueCount() and the list: an all-day item is overdue once its day passed, a timed one at its instant
            if ($ad ? $d < $td : (string)$e['due_at'] < $nowUtc) $b['overdue'][] = $e;
            elseif ($d <= $wk) $b['week'][]    = $e;
            elseif ($d <= $mo) $b['month'][]   = $e;
            else               $b['later'][]   = $e;
        }
        $b['counts'] = ['overdue'=>count($b['overdue']), 'week'=>count($b['week']),
                        'month'=>count($b['month']), 'later'=>count($b['later'])];
        return $b;
    }

    public function setStatus(string $id, string $status): bool
    {
        if (!in_array($status, ['open','done','cancelled'], true)) return false;
        $stmt = Database::pdo()->prepare(
            "UPDATE calendar_events SET status = ?, completed_at = ? WHERE id = ? AND tenant_id = ?{$this->scopeSql()[0]}"
        );
        $stmt->execute(array_merge([$status, $status === 'done' ? utc_now() : null, $id, $this->ctx->tenantId], $this->scopeSql()[1]));
        return $stmt->rowCount() > 0;
    }

    /** Insert or update an auto-generated event keyed by (source, source_ref). */
    private function upsertAuto(string $source, ?string $ref, string $kind, string $title, string $dueAt, string $priority = 'normal', ?string $detail = null, ?string $holderId = null): void
    {
        $sel = Database::pdo()->prepare(
            "SELECT id, status FROM calendar_events WHERE tenant_id = ? AND source = ? AND source_ref <=> ? LIMIT 1"
        );
        $sel->execute([$this->ctx->tenantId, $source, $ref]);
        $row = $sel->fetch();
        if ($row) {
            // refresh schedule/title/owner but preserve a user's done/cancelled choice
            Database::pdo()->prepare(
                "UPDATE calendar_events SET title = ?, due_at = ?, priority = ?, detail = ?, holder_id = ?, deleted_at = NULL
                 WHERE id = ? AND tenant_id = ?"
            )->execute([$title, $dueAt, $priority, $detail, $holderId, $row['id'], $this->ctx->tenantId]);
        } else {
            Database::pdo()->prepare(
                "INSERT INTO calendar_events (id, tenant_id, holder_id, kind, source, source_ref, title, detail, due_at, all_day, priority, status, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,1,?,'open',?)"
            )->execute([uuid4(), $this->ctx->tenantId, $holderId, $kind, $source, $ref, $title, $detail, $dueAt, $priority, utc_now()]);
        }
    }

    /**
     * Materialize compliance deadlines into the calendar. Idempotent.
     * Returns count of auto events present after sync.
     */
    public function sync(int $planYear): int
    {
        // 1) credential expiries -> deadline events
        $creds = Database::pdo()->prepare(
            "SELECT c.id, c.holder_id, c.name, c.expiry_date, h.first_name, h.last_name
             FROM credentials c
             JOIN credential_holders h ON h.id = c.holder_id AND h.tenant_id = c.tenant_id
             WHERE c.tenant_id = ? AND c.deleted_at IS NULL AND h.deleted_at IS NULL AND c.expiry_date IS NOT NULL"
        );
        $creds->execute([$this->ctx->tenantId]);
        foreach ($creds->fetchAll() as $c) {
            $who = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
            $days = (int)today_local_dt()->diff(new \DateTimeImmutable($c['expiry_date']))->format('%r%a');
            $pri = $days <= 30 ? 'high' : ($days <= 90 ? 'normal' : 'low');
            $this->upsertAuto('credential', $c['id'], 'deadline',
                $c['name'] . ' expires' . ($who ? " — {$who}" : ''),
                $c['expiry_date'] . ' 09:00:00', $pri, 'Credential renewal deadline.', $c['holder_id']);
        }

        // Document verifications that expire → "re-verify" deadlines. Primary-source verification is
        // periodic under NCQA/payer rules; letting it lapse is a compliance gap in its own right.
        $rv = Database::pdo()->prepare(
            "SELECT d.id, d.verification_expires, d.verification_method, c.holder_id, c.name AS cred_name,
                    h.first_name, h.last_name
             FROM credential_documents d
             JOIN credentials c ON c.id = d.credential_id AND c.tenant_id = d.tenant_id AND c.deleted_at IS NULL
             JOIN credential_holders h ON h.id = c.holder_id AND h.tenant_id = c.tenant_id
             WHERE d.tenant_id = ? AND d.deleted_at IS NULL AND h.deleted_at IS NULL AND d.verification_expires IS NOT NULL"
        );
        $rv->execute([$this->ctx->tenantId]);
        foreach ($rv->fetchAll() as $d) {
            $who = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
            $days = (int)today_local_dt()->diff(new \DateTimeImmutable($d['verification_expires']))->format('%r%a');
            $pri = $days <= 30 ? 'high' : ($days <= 90 ? 'normal' : 'low');
            $this->upsertAuto('verification', $d['id'], 'deadline',
                'Re-verify ' . $d['cred_name'] . ($who ? " — {$who}" : ''),
                $d['verification_expires'] . ' 09:00:00', $pri,
                'Primary-source verification lapses (' . str_replace('_', ' ', (string)$d['verification_method']) . ').', $d['holder_id']);
        }

        // Payer recredentialing, Medicaid/Medicare revalidation, OCS attestation deadlines.
        try {
            $names = [];
            foreach ((new \FlxCoreHC\EnrollmentService($this->ctx))->deadlines() as $dl) {
                if (!isset($names[$dl['holder_id']])) {
                    $h = Database::pdo()->prepare('SELECT first_name, last_name, org_name FROM credential_holders WHERE id = ? AND tenant_id = ?');
                    $h->execute([$dl['holder_id'], $this->ctx->tenantId]); $r = $h->fetch() ?: [];
                    $names[$dl['holder_id']] = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: (string)($r['org_name'] ?? '');
                }
                $days = (int)today_local_dt()->diff(new \DateTimeImmutable($dl['due']))->format('%r%a');
                $pri = $days <= 30 ? 'high' : ($days <= 90 ? 'normal' : 'low');
                $this->upsertAuto($dl['source'], $dl['ref'], 'deadline', $dl['title'] . ($names[$dl['holder_id']] ? ' — ' . $names[$dl['holder_id']] : ''),
                    $dl['due'] . ' 09:00:00', $pri, null, $dl['holder_id']);
            }
        } catch (\Throwable $e) { /* enrollment tables may predate this build */ }

        // Clean up stale AUTO events (never manual ones): soft-delete any auto event whose provider has
        // been soft-deleted, or whose source credential no longer exists / is soft-deleted. This is what
        // makes a deleted provider/credential drop off the calendar (and stay off) — not just stop syncing.
        try {
            $pdo = Database::pdo();
            // (a) provider soft-deleted
            $pdo->prepare(
                "UPDATE calendar_events e
                    JOIN credential_holders h ON h.id = e.holder_id AND h.tenant_id = e.tenant_id
                    SET e.deleted_at = UTC_TIMESTAMP()
                  WHERE e.tenant_id = ? AND e.source <> 'manual' AND e.deleted_at IS NULL AND h.deleted_at IS NOT NULL"
            )->execute([$this->ctx->tenantId]);
            // (b) source credential gone or soft-deleted (credential-sourced deadline events)
            $pdo->prepare(
                "UPDATE calendar_events e
                    SET e.deleted_at = UTC_TIMESTAMP()
                  WHERE e.tenant_id = ? AND e.source = 'credential' AND e.deleted_at IS NULL
                    AND NOT EXISTS (SELECT 1 FROM credentials c
                                     WHERE c.id = e.source_ref AND c.tenant_id = e.tenant_id AND c.deleted_at IS NULL)"
            )->execute([$this->ctx->tenantId]);
        } catch (\Throwable $e) { flx_log_error('warning', 'calendar stale-event cleanup failed', ['path' => 'CalendarRepository::sync']); }

        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM calendar_events WHERE tenant_id = ? AND source <> 'manual' AND deleted_at IS NULL"
        );
        $stmt->execute([$this->ctx->tenantId]);
        return (int)$stmt->fetchColumn();
    }
}
