<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Compliance expiry reminders. Scans a tenant's credentials and, for each one crossing a
 * reminder threshold that hasn't been notified for its current expiry date, sends an email
 * to the agent (or the organization owner as a fallback) and records it so the same threshold
 * never fires twice. A renewal that moves the expiry date starts a fresh cycle — the dedup key
 * is (credential_id, days_before, expiry_snapshot).
 *
 * Delivery goes through the outbox (MailService), so reminders are visible and retryable like
 * every other message. Runs opportunistically on compliance/AEP page loads (no cron needed),
 * can be triggered manually, and has an optional cron entry point (runAll). Demo orgs skipped.
 */
final class ReminderService
{
    /** Default days-before-expiry at which to remind (v1.172.3: overridable with the `reminder_thresholds` config key). */
    public const THRESHOLDS = [60, 30, 14, 7];

    /**
     * The effective reminder thresholds, largest first. Reads `reminder_thresholds` from app/config.php
     * (a list of whole numbers 1-365; at most 8 are used) and falls back to THRESHOLDS when the key is
     * missing or holds nothing usable. The older, unused `reminder_days` key is ignored on purpose, so an
     * installation written by an earlier installer keeps behaving exactly as before.
     */
    public static function thresholds(): array
    {
        $cfg = function_exists('config') ? config('reminder_thresholds') : null;
        if (!is_array($cfg)) return self::THRESHOLDS;
        $set = [];
        foreach ($cfg as $v) {
            if (is_int($v) || (is_string($v) && ctype_digit($v))) {
                $n = (int) $v;
                if ($n >= 1 && $n <= 365) $set[$n] = true;
            }
        }
        $days = array_keys($set);
        rsort($days);
        $days = array_slice($days, 0, 8);
        return $days ?: self::THRESHOLDS;
    }

    /** "60, 30, 14, and 7" — the thresholds as a readable list for screens. */
    public static function thresholdsLabel(): string
    {
        $d = array_map('strval', self::thresholds());
        if (count($d) === 1) return $d[0];
        $last = array_pop($d);
        return implode(', ', $d) . (count($d) > 1 ? ',' : '') . ' ' . t('and') . ' ' . $last;
    }

    /** Re-check / re-attest cadences (months) before a provider is flagged as due. */
    public const SCREENING_CADENCE_MONTHS = 12;
    public const ATTESTATION_CADENCE_MONTHS = 12;

    public function __construct(private TenantContext $ctx) {}

    /**
     * Evaluate this tenant and send any due reminders. Returns the number sent/queued.
     * Cheap and idempotent — safe to call on every relevant page load.
     */
    public function run(): int
    {
        // Never remind for the demo organization. Tolerate a pre-migration schema.
        try {
            $isDemo = Database::pdo()->prepare('SELECT is_demo FROM tenants WHERE id = ? LIMIT 1');
            $isDemo->execute([$this->ctx->tenantId]);
            if ((int) $isDemo->fetchColumn() === 1) return 0;
        } catch (\Throwable $e) { flx_log_error('error', 'reminder/notification scan failed — result is UNKNOWN, not zero: ' . $e->getMessage()); return 0; }

        $maxThreshold = max(self::thresholds());
        $stmt = Database::pdo()->prepare(
            "SELECT c.id, c.holder_id, c.name, c.credential_type, c.expiry_date,
                    DATEDIFF(c.expiry_date, @flx_today) AS days_left
             FROM credentials c
             WHERE c.tenant_id = ?
               AND c.deleted_at IS NULL
               AND c.expiry_date IS NOT NULL
               AND c.status IN ('active','expiring')
               AND DATEDIFF(c.expiry_date, @flx_today) BETWEEN 0 AND ?
             ORDER BY c.expiry_date ASC"
        );
        $stmt->execute([$this->ctx->tenantId, $maxThreshold]);
        $rows = $stmt->fetchAll();

        $holders = new Repository\HolderRepository($this->ctx);
        $ownerEmail = $this->ownerEmail();
        $sent = 0;

        foreach ($rows as $c) {
            $daysLeft = (int) $c['days_left'];
            // The tightest threshold this credential currently falls within.
            $threshold = null;
            foreach (self::thresholds() as $t) {
                if ($daysLeft <= $t) { $threshold = $t; }
            }
            if ($threshold === null) continue;

            // Dedup on (credential, threshold, expiry date) — renewals re-trigger.
            if (!$this->claim($c['id'], $threshold, (string) $c['expiry_date'])) continue;

            $holder = $holders->find((string) $c['holder_id']);
            if (!$holder) continue;
            $name  = trim(($holder['first_name'] ?? '') . ' ' . ($holder['last_name'] ?? '')) ?: ($holder['org_name'] ?? 'there');
            $email = ($holder['email'] ?? '') !== '' ? $holder['email'] : $ownerEmail;
            if (!$email) continue;

            [$subject, $body] = $this->compose($name, $c, $daysLeft);
            if (MailService::queue($email, $subject, $body, 'reminder', $name, $this->ctx->tenantId)) {
                $sent++;
            }
            // Office contacts flagged to receive reminders get a copy (no portal account needed).
            try {
                foreach ((new Repository\ProviderContactRepository($this->ctx))->recipientsFor((string)$c['holder_id'], 'reminders') as $rc) {
                    if (strcasecmp($rc['email'], $email) === 0) continue;
                    if (MailService::queue($rc['email'], $subject, $body, 'reminder', $rc['name'], $this->ctx->tenantId)) $sent++;
                }
            } catch (\Throwable $e) {}
        }

        if ($sent > 0) { MailService::flush(); }

        // Beyond credential expiry, remind on the newer compliance entities.
        $sent += $this->scanApplications($ownerEmail);
        $sent += $this->scanScreeningCadence($ownerEmail);
        $sent += $this->scanAttestationCadence($ownerEmail);
        if ($sent > 0) { MailService::flush(); }
        return $sent;
    }

    /** Application due/revalidation dates approaching, for still-open applications. */
    private function scanApplications(?string $ownerEmail): int
    {
        $maxT = max(self::thresholds());
        try {
            $stmt = Database::pdo()->prepare(
                "SELECT a.id, a.holder_id, a.title, a.kind, a.due_on,
                        DATEDIFF(a.due_on, @flx_today) AS days_left
                   FROM applications a
                  WHERE a.tenant_id = ? AND a.due_on IS NOT NULL
                    AND a.status IN ('not_started','in_progress','submitted','under_review')
                    AND DATEDIFF(a.due_on, @flx_today) BETWEEN 0 AND ?
                  ORDER BY a.due_on ASC"
            );
            $stmt->execute([$this->ctx->tenantId, $maxT]);
        } catch (\Throwable $e) { flx_log_error('error', 'reminder/notification scan failed — result is UNKNOWN, not zero: ' . $e->getMessage()); return 0; }
        $rows = $stmt->fetchAll();
        if (!$rows) return 0;

        $holders = new Repository\HolderRepository($this->ctx);
        $sent = 0;
        foreach ($rows as $a) {
            $daysLeft = (int) $a['days_left'];
            $threshold = null;
            foreach (self::thresholds() as $t) { if ($daysLeft <= $t) $threshold = $t; }
            if ($threshold === null) continue;
            if (!$this->claimEntity('application', (string)$a['id'], $threshold . ':' . substr((string)$a['due_on'], 0, 10))) continue;

            $holder = $holders->find((string) $a['holder_id']);
            $name = $holder ? (trim(($holder['first_name'] ?? '') . ' ' . ($holder['last_name'] ?? '')) ?: ($holder['org_name'] ?? 'there')) : 'there';
            $email = ($holder['email'] ?? '') !== '' ? $holder['email'] : $ownerEmail;
            if (!$email) continue;
            $when = $daysLeft <= 0 ? 'today' : ($daysLeft === 1 ? 'tomorrow' : "in {$daysLeft} days");
            $kindLabel = Repository\ApplicationRepository::KINDS[$a['kind']] ?? 'application';
            $title = (string)$a['title'] ?: ucfirst($kindLabel);
            $subject = "Reminder: {$title} is due {$when}";
            $body = "Hi {$name},\n\n"
                  . "The {$kindLabel} \"{$title}\" is due on " . fmt_day((string)$a["due_on"]) . " ({$when}).\n\n"
                  . "Please make sure it's submitted or advanced before the deadline. Update its status in "
                  . "FlxCoreHC once it moves so this reminder clears.\n\n— " . $this->ctx->tenantName . "\n";
            if (MailService::queue($email, $subject, $body, 'reminder', $name, $this->ctx->tenantId)) $sent++;
        }
        return $sent;
    }

    /** Exclusion screening cadence: remind when a provider's last screening is older than the
     *  cadence (or never screened). One reminder per provider per cadence period. */
    private function scanScreeningCadence(?string $ownerEmail): int
    {
        if (!$ownerEmail) return 0;
        $months = (int) self::SCREENING_CADENCE_MONTHS;  // class constant, safe to inline
        try {
            // Active providers whose most-recent screening is missing or older than the cadence.
            $stmt = Database::pdo()->prepare(
                "SELECT h.id, MAX(s.screened_on) AS last_screen
                   FROM credential_holders h
                   LEFT JOIN exclusion_screenings s ON s.holder_id = h.id AND s.tenant_id = h.tenant_id
                  WHERE h.tenant_id = ? AND h.deleted_at IS NULL AND h.status = 'active'
                  GROUP BY h.id
                 HAVING last_screen IS NULL OR last_screen < (@flx_today - INTERVAL {$months} MONTH)"
            );
            $stmt->execute([$this->ctx->tenantId]);
        } catch (\Throwable $e) { flx_log_error('error', 'reminder/notification scan failed — result is UNKNOWN, not zero: ' . $e->getMessage()); return 0; }
        $rows = $stmt->fetchAll();
        if (!$rows) return 0;
        $holders = new Repository\HolderRepository($this->ctx);
        $period = date('Y-m'); // one nudge per provider per calendar month
        $sent = 0;
        foreach ($rows as $h) {
            if (!$this->claimEntity('screening', (string)$h['id'], 'due:' . $period)) continue;
            $prov = $holders->find((string)$h['id']);
            $name = $prov ? (trim(($prov['first_name'] ?? '') . ' ' . ($prov['last_name'] ?? '')) ?: ($prov['org_name'] ?? 'a provider')) : 'a provider';
            $last = $h['last_screen'] ? 'last screened ' . fmt_day((string)$h['last_screen']) : 'never screened';
            $subject = "Exclusion screening due — {$name}";
            $body = "Hi,\n\n{$name} is due for an exclusion / sanctions screening ({$last}).\n\n"
                  . "Re-check OIG LEIE and SAM.gov (and state/PR Medicaid lists as applicable) and record the "
                  . "result in FlxCoreHC.\n\n— " . $this->ctx->tenantName . "\n";
            if (MailService::queue($ownerEmail, $subject, $body, 'reminder', $name, $this->ctx->tenantId)) $sent++;
        }
        return $sent;
    }

    /** Attestation cadence: remind when a provider's last attestation is older than the cadence
     *  (or never attested). One reminder per provider per cadence period. */
    private function scanAttestationCadence(?string $ownerEmail): int
    {
        if (!$ownerEmail) return 0;
        $months = (int) self::ATTESTATION_CADENCE_MONTHS;  // class constant, safe to inline
        try {
            $stmt = Database::pdo()->prepare(
                "SELECT h.id, MAX(a.attested_on) AS last_att
                   FROM credential_holders h
                   LEFT JOIN holder_attestations a ON a.holder_id = h.id AND a.tenant_id = h.tenant_id
                  WHERE h.tenant_id = ? AND h.deleted_at IS NULL AND h.status = 'active'
                  GROUP BY h.id
                 HAVING last_att IS NULL OR last_att < (NOW() - INTERVAL {$months} MONTH)"
            );
            $stmt->execute([$this->ctx->tenantId]);
        } catch (\Throwable $e) { flx_log_error('error', 'reminder/notification scan failed — result is UNKNOWN, not zero: ' . $e->getMessage()); return 0; }
        $rows = $stmt->fetchAll();
        if (!$rows) return 0;
        $holders = new Repository\HolderRepository($this->ctx);
        $period = date('Y-m');
        $sent = 0;
        foreach ($rows as $h) {
            if (!$this->claimEntity('attestation', (string)$h['id'], 'due:' . $period)) continue;
            $prov = $holders->find((string)$h['id']);
            $name = $prov ? (trim(($prov['first_name'] ?? '') . ' ' . ($prov['last_name'] ?? '')) ?: ($prov['org_name'] ?? 'a provider')) : 'a provider';
            $email = ($prov['email'] ?? '') !== '' ? $prov['email'] : $ownerEmail;
            $last = $h['last_att'] ? 'last attested ' . fmt_day((string)$h['last_att']) : 'no attestation on file';
            $subject = "Attestation due — {$name}";
            $body = "Hi {$name},\n\nYour credentialing attestation is due ({$last}).\n\n"
                  . "Please confirm your information and documents are accurate and current from your FlxCoreHC "
                  . "portal, or contact us to attest.\n\n— " . $this->ctx->tenantName . "\n";
            if (MailService::queue($email, $subject, $body, 'reminder', $name, $this->ctx->tenantId)) $sent++;
        }
        return $sent;
    }

    /**
     * Claim a reminder for a non-credential entity via entity_reminders
     * (UNIQUE on tenant, entity_type, entity_id, marker). Returns true if newly claimed.
     */
    private function claimEntity(string $type, string $entityId, string $marker): bool
    {
        try {
            $n = Database::pdo()->prepare(
                'INSERT IGNORE INTO entity_reminders
                   (id, tenant_id, entity_type, entity_id, marker, channel, queued_at, sent_at)
                 VALUES (?,?,?,?,?,\'email\',?,?)'
            );
            $now = utc_now();
            $n->execute([uuid4(), $this->ctx->tenantId, $type, $entityId, $marker, $now, $now]);
            return $n->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Claim a reminder via credential_reminders (UNIQUE on credential_id, days_before,
     * expiry_snapshot). Returns true if newly claimed, false if already sent this cycle.
     */
    private function claim(string $credId, int $threshold, string $expiry): bool
    {
        try {
            $n = Database::pdo()->prepare(
                'INSERT IGNORE INTO credential_reminders
                   (id, tenant_id, credential_id, days_before, expiry_snapshot, queued_at, sent_at, channel)
                 VALUES (?,?,?,?,?,?,?,\'email\')'
            );
            $now = utc_now();
            $n->execute([uuid4(), $this->ctx->tenantId, $credId, $threshold, $expiry, $now, $now]);
            if ($n->rowCount() > 0) return true;
            // v1.170.8: a row pre-queued by an older sweep (sent_at NULL) is ours to claim, not a "sent" marker.
            $u = Database::pdo()->prepare('UPDATE credential_reminders SET sent_at = ?, channel = \'email\'
                 WHERE tenant_id = ? AND credential_id = ? AND days_before = ? AND sent_at IS NULL');
            $u->execute([$now, $this->ctx->tenantId, $credId, $threshold]);
            return $u->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Staff-initiated nudge (Feature: provider-directed alerts). Composes ONE summary email
     * listing every expiring/expired credential this holder has and queues it to the holder's
     * own email. Returns [ok, humanMessage]. Unlike run(), this is deliberate — no threshold
     * dedup — so a manager can always re-send; the audit log records who pushed the button.
     */
    public function nudgeHolder(array $holder, array $credRows): array
    {
        $email = trim((string) ($holder['email'] ?? ''));
        if ($email === '') {
            return [false, 'No email on file for this record — add one first.'];
        }
        $items = array_values(array_filter($credRows, fn($c) =>
            in_array((string) $c['status'], ['expiring', 'expired'], true) && !empty($c['expiry_date'])));
        if ($items === []) {
            return [false, 'Nothing is expiring or expired — no reminder needed.'];
        }
        $name = trim(($holder['first_name'] ?? '') . ' ' . ($holder['last_name'] ?? ''))
              ?: ((string) ($holder['org_name'] ?? '') ?: 'there');
        $lines = '';
        foreach ($items as $c) {
            $d = fmt_day((string) $c['expiry_date']);
            $state = (string) $c['status'] === 'expired' ? 'EXPIRED' : 'expiring';
            $lines .= '  - ' . ($c['name'] ?: ucfirst((string) $c['credential_type']))
                    . " — {$state}, " . ((string)$c['status'] === 'expired' ? 'was due ' : 'due ') . $d . "\n";
        }
        $n = count($items);
        $subject = "Action needed: {$n} credential" . ($n === 1 ? '' : 's') . " to renew";
        $body = "Hi {$name},\n\n"
              . "The following item" . ($n === 1 ? ' needs' : 's need') . " your attention:\n\n"
              . $lines . "\n"
              . "Please renew " . ($n === 1 ? 'it' : 'them') . " and send us the updated document, "
              . "or upload it yourself from your portal. If you've already renewed, let us know so "
              . "we can update your record.\n\n"
              . "— " . $this->ctx->tenantName . "\n";
        $ok = MailService::queue($email, $subject, $body, 'reminder', $name, $this->ctx->tenantId);
        if ($ok) { MailService::flush(); }
        return $ok
            ? [true, "Reminder sent to {$email} covering {$n} item" . ($n === 1 ? '' : 's') . '.']
            : [false, 'Could not queue the email — check the mail settings.'];
    }

    private function compose(string $name, array $c, int $daysLeft): array
    {
        $loc = $this->localeFor();
        $tl = fn(string $en, array $v = []) => \FlxCoreHC\I18n::tl($loc, $en, $v);
        $when = $daysLeft <= 0 ? $tl('today') : ($daysLeft === 1 ? $tl('tomorrow') : $tl('in {n} days', ['n' => $daysLeft]));
        $label = $c['name'] ?: ucfirst((string) $c['credential_type']);
        $subject = $tl('Reminder: {label} expires {when}', ['label' => $label, 'when' => $when]);
        $body = $tl('Hi {name},', ['name' => $name]) . "\n\n"
              . $tl('This is a reminder that your {label} expires on {date} ({when}).',
                    ['label' => $label, 'date' => fmt_day((string) $c['expiry_date']), 'when' => $when]) . "\n\n"
              . $tl('Please renew it to stay compliant. If you have already renewed, update the record in FlxCoreHC so this reminder clears.') . "\n\n"
              . "— " . $this->ctx->tenantName . "\n";
        return [$subject, $body];
    }

    /** The tenant's default language for outbound reminders. */
    private function localeFor(): string
    {
        try {
            $st = Database::pdo()->prepare("SELECT default_locale FROM tenants WHERE id = ? LIMIT 1");
            $st->execute([$this->ctx->tenantId]);
            $l = (string) ($st->fetchColumn() ?: '');
            if ($l !== '' && isset(\FlxCoreHC\I18n::LOCALES[$l])) return $l;
        } catch (\Throwable $e) {}
        return (string) config('default_locale', 'en');
    }

    private function ownerEmail(): ?string
    {
        $stmt = Database::pdo()->prepare(
            "SELECT email FROM users WHERE tenant_id = ? AND role = 'tenant_owner'
             ORDER BY created_at ASC LIMIT 1"
        );
        $stmt->execute([$this->ctx->tenantId]);
        $e = $stmt->fetchColumn();
        return $e ?: null;
    }

    /** Global sweep across all real, active tenants — for the optional cron script. */
    public static function runAll(): int
    {
        $total = 0;
        $tenants = Database::pdo()->query(
            "SELECT t.id, t.name, t.vertical, t.org_shape, t.timezone, MIN(u.id) AS uid
             FROM tenants t
             JOIN users u ON u.tenant_id = t.id AND u.role = 'tenant_owner'
             WHERE t.status = 'active' AND t.is_demo = 0
             GROUP BY t.id, t.name, t.vertical, t.org_shape, t.timezone"
        )->fetchAll();
        foreach ($tenants as $t) {
            app_tz_use_tenant($t['timezone'] ?: null);   // v1.172.16: "today" and every date in the e-mail follow this organization's zone
            $ctx = new TenantContext($t['id'], $t['uid'], 'tenant_owner',
                $t['name'], $t['vertical'] ?: 'healthcare', $t['org_shape'] ?: 'medical_group', 'System', false);
            $total += (new self($ctx))->run();
            // Same pass also refreshes the in-app notification center for every tenant.
            try { NotificationService::sweep($ctx, true); } catch (\Throwable $e) {}
        }
        app_tz_use_tenant(null);
        return $total;
    }
}
