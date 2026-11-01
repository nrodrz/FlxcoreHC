<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Transactional email with an outbox. Every message is queued to email_outbox first, so
 * nothing is lost if delivery fails and the operator can see exactly what was sent. flush()
 * then delivers queued messages — via a dependency-free SMTP client when SMTP is configured,
 * otherwise PHP mail(). Designed for cron-free shared hosting: flush() runs opportunistically
 * on admin page loads (like TaskSyncService) and can also be triggered manually.
 *
 * No external libraries: the SMTP conversation is spoken directly over a socket.
 */
final class MailService
{
    private const MAX_ATTEMPTS = 3;

    /**
     * Queue a message. Returns the outbox id. Delivery happens later in flush().
     * $attach (optional) = ['name' => 'invoice.pdf', 'mime' => 'application/pdf', 'bytes' => '...'].
     */
    public static function queue(string $to, string $subject, string $body,
        string $category = 'general', ?string $toName = null, ?string $tenantId = null,
        ?array $attach = null): ?string
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return null;
        $id = uuid4();
        $an = $am = $ab = null;
        if ($attach !== null && !empty($attach['bytes'])) {
            $an = mb_substr((string) ($attach['name'] ?? 'attachment'), 0, 120);
            $am = mb_substr((string) ($attach['mime'] ?? 'application/octet-stream'), 0, 80);
            $ab = base64_encode((string) $attach['bytes']);
        }
        try {
            // Try with attachment columns; fall back if the migration hasn't run.
            try {
                Database::pdo()->prepare(
                    'INSERT INTO email_outbox (id, tenant_id, to_email, to_name, subject, body, attach_name, attach_mime, attach_b64, category, status, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,\'queued\',?)'
                )->execute([$id, $tenantId, $to, $toName, mb_substr($subject, 0, 255), $body, $an, $am, $ab, $category, utc_now()]);
            } catch (\Throwable $inner) {
                Database::pdo()->prepare(
                    'INSERT INTO email_outbox (id, tenant_id, to_email, to_name, subject, body, category, status, created_at)
                     VALUES (?,?,?,?,?,?,?,\'queued\',?)'
                )->execute([$id, $tenantId, $to, $toName, mb_substr($subject, 0, 255), $body, $category, utc_now()]);
            }
        } catch (\Throwable $e) {
            return null;   // outbox not available (pre-migration) — caller degrades gracefully
        }
        return $id;
    }

    /**
     * Attempt delivery of up to $limit queued messages. Safe to call often; a no-op when
     * mail is disabled or nothing is queued. Returns [sent, failed].
     */
    public static function flush(int $limit = 20): array
    {
        $platformOn  = SettingsService::bool('mail_enabled');
        $platformCfg = SettingsService::emailConfig();
        // Platform sender connected to Microsoft 365 (modern auth) takes precedence over SMTP/mail().
        try {
            if (MicrosoftOAuth::platformRefreshEnc() !== '') {
                $platformCfg = array_merge($platformCfg, ['mail_transport' => 'graph', 'graph_scope' => 'platform', 'graph_refresh' => MicrosoftOAuth::platformRefreshEnc(),
                    'mail_from' => MicrosoftOAuth::platformAccount() ?: ($platformCfg['mail_from'] ?? '')]);
                $platformOn = true;
            }
        } catch (\Throwable $e) {}
        $pdo = Database::pdo();
        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM email_outbox WHERE status = 'queued' AND attempts < ? AND category <> 'security'
                 ORDER BY created_at ASC LIMIT ?"
            );
            $stmt->bindValue(1, self::MAX_ATTEMPTS, \PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [0, 0];
        }

        $sent = 0; $failed = 0;
        foreach ($rows as $m) {
            // Per-tenant delivery: a tenant with its own transport sends through it — even when
            // platform mail is off. Others use the platform config when enabled, else stay queued.
            $cfg = null;
            try { $cfg = TenantMailService::cfgFor($m['tenant_id'] ?? null); } catch (\Throwable $e) { $cfg = null; }
            if ($cfg === null) {
                if (!$platformOn) continue;   // leave queued; no attempt burned
                $cfg = $platformCfg;
            }
            $attach = null;
            if (!empty($m['attach_b64'])) {
                $attach = ['name' => $m['attach_name'] ?: 'attachment',
                           'mime' => $m['attach_mime'] ?: 'application/octet-stream',
                           'bytes' => base64_decode((string) $m['attach_b64'])];
            }
            [$ok, $err] = self::deliver($cfg, $m['to_email'], (string) $m['to_name'], $m['subject'], $m['body'], $attach);
            if ($ok) {
                $pdo->prepare("UPDATE email_outbox SET status='sent', sent_at=?, attempts=attempts+1 WHERE id=?")
                    ->execute([utc_now(), $m['id']]);
                $sent++;
            } else {
                $attempts = (int) $m['attempts'] + 1;
                $status = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'queued';
                $pdo->prepare("UPDATE email_outbox SET status=?, attempts=?, last_error=? WHERE id=?")
                    ->execute([$status, $attempts, mb_substr($err, 0, 255), $m['id']]);
                $failed++;
            }
        }
        return [$sent, $failed];
    }

    /**
     * v1.172.0 — can a message for this organization actually leave the server right now? True when the organization has
     * its own transport, or the platform sender is on (SMTP/mail() enabled, or Microsoft 365 connected).
     * Used to decide whether e-mail sign-in codes and password-reset links can be offered.
     */
    public static function canSendFor(?string $tenantId): bool
    {
        try { if (TenantMailService::cfgFor($tenantId) !== null) return true; } catch (\Throwable $e) {}
        if (SettingsService::bool('mail_enabled')) return true;
        try { return MicrosoftOAuth::platformRefreshEnc() !== ''; } catch (\Throwable $e) { return false; }
    }

    /**
     * v1.172.0 — send a security message (sign-in code, password-reset link) NOW, once, and never keep its content.
     * The message is recorded in the outbox for the operator (who, when, subject, delivered or not) but its body is
     * scrubbed as soon as the attempt is made, whether it succeeded or not: a code or reset link must not sit readable
     * in the database, and a failed one is not retried later (it would arrive after it expired).
     * Returns true when the message was handed to the mail transport.
     */
    public static function sendSecure(string $to, string $subject, string $body, ?string $toName = null, ?string $tenantId = null): bool
    {
        $id = self::queue($to, $subject, $body, 'security', $toName, $tenantId);
        if ($id === null) return false;
        $ok = false; $err = 'outbound e-mail is not configured';
        try {
            $cfg = null;
            try { $cfg = TenantMailService::cfgFor($tenantId); } catch (\Throwable $e) { $cfg = null; }
            if ($cfg === null) {
                $platformCfg = SettingsService::emailConfig(); $platformOn = SettingsService::bool('mail_enabled');
                try {
                    if (MicrosoftOAuth::platformRefreshEnc() !== '') {
                        $platformCfg = array_merge($platformCfg, ['mail_transport' => 'graph', 'graph_scope' => 'platform', 'graph_refresh' => MicrosoftOAuth::platformRefreshEnc(),
                            'mail_from' => MicrosoftOAuth::platformAccount() ?: ($platformCfg['mail_from'] ?? '')]);
                        $platformOn = true;
                    }
                } catch (\Throwable $e) {}
                if ($platformOn) $cfg = $platformCfg;
            }
            if ($cfg !== null) [$ok, $err] = self::deliver($cfg, $to, (string) $toName, $subject, $body, null);
        } catch (\Throwable $e) { $ok = false; $err = $e->getMessage(); }
        try {
            Database::pdo()->prepare('UPDATE email_outbox SET status = ?, sent_at = ?, attempts = attempts + 1, last_error = ?, body = ? WHERE id = ?')
                ->execute([$ok ? 'sent' : 'failed', $ok ? utc_now() : null, $ok ? null : mb_substr((string) $err, 0, 255),
                    '[security message — content removed after the delivery attempt]', $id]);
        } catch (\Throwable $e) { /* the outbox row is a record, never a reason to fail the request */ }
        return (bool) $ok;
    }

    /** Deliver a single message now with an explicit config (used by the tenant test-send). */
    public static function testDelivery(array $cfg, string $to, string $subject, string $body): array
    {
        return self::deliver($cfg, $to, '', $subject, $body, null);
    }

    /** Deliver a single message now. Returns [ok, error]. */
    private static function deliver(array $cfg, string $to, string $toName, string $subject, string $body, ?array $attach = null): array
    {
        $fromEmail = $cfg['mail_from'] !== '' ? $cfg['mail_from'] : ('no-reply@' . SmtpClient::ehloName());
        $fromName  = $cfg['mail_from_name'] ?: FLXCOREHC_APP_NAME;

        if (($cfg['mail_transport'] ?? 'mail') === 'graph' && !empty($cfg['graph_refresh'])) {
            return MicrosoftOAuth::sendMail((string)$cfg['graph_refresh'], $fromName, $to, $toName, $subject, $body, $attach, (string) ($cfg['graph_scope'] ?? 'platform'));
        }
        if (($cfg['mail_transport'] ?? 'mail') === 'smtp' && $cfg['smtp_host'] !== '') {
            return self::smtpDeliver($cfg, $fromEmail, $fromName, $to, $toName, $subject, $body, $attach);
        }
        // Fallback: PHP mail(). The envelope sender (-f) matters on cPanel/Exim: without it the server uses
        // "nobody@host" and many receivers reject or spam-fold the message.
        $from = SmtpClient::addr($fromEmail);
        if ($from === '') return [false, 'The From address is not valid.'];
        $extra = '-f' . $from;
        $subjectEnc = preg_replace('/[\r\n]+/', ' ', $subject) ?? '';
        if ($attach !== null) {
            [$hdr, $mimeBody] = self::multipart($fromName, $from, $to, $body, $attach);
            $ok = @mail($to, $subjectEnc, $mimeBody, $hdr, $extra);
        } else {
            $headers = 'From: ' . self::header($fromName) . ' <' . $from . ">\r\n"
                     . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8";
            $ok = @mail($to, $subjectEnc, $body, $headers, $extra);
        }
        return [$ok, $ok ? '' : 'The server\'s built-in mail (PHP mail()) refused the message. Switch to SMTP with a mailbox from cPanel → Email Accounts.'];
    }

    /** Build MIME headers + body for a text message with one attachment. */
    private static function multipart(string $fromName, string $fromEmail, string $to, string $body, array $attach): array
    {
        $b = 'flx_' . bin2hex(random_bytes(10));
        $headers = 'From: ' . self::header($fromName) . " <{$fromEmail}>\r\n"
                 . "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"{$b}\"";
        $enc = chunk_split(base64_encode((string) $attach['bytes']));
        $mime  = "--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n" . self::dotSafe($body) . "\r\n";
        $mime .= "--{$b}\r\nContent-Type: " . $attach['mime'] . "; name=\"" . $attach['name'] . "\"\r\n";
        $mime .= "Content-Transfer-Encoding: base64\r\n";
        $mime .= "Content-Disposition: attachment; filename=\"" . $attach['name'] . "\"\r\n\r\n";
        $mime .= $enc . "\r\n--{$b}--";
        return [$headers, $mime];
    }

    private static function dotSafe(string $s): string { return str_replace(["\r\n", "\r", "\n"], "\r\n", $s); }

    /**
     * SMTP delivery through SmtpClient. Tenant-supplied settings are SSRF-guarded (validated public address, connect to
     * that exact address) and always verify the certificate; the platform operator's own settings may skip verification.
     */
    private static function smtpDeliver(array $cfg, string $fromEmail, string $fromName, string $to, string $toName,
        string $subject, string $body, ?array $attach): array
    {
        $r = self::smtpRun($cfg, $fromEmail, $fromName, $to, $toName, $subject, $body, $attach, false);
        return [$r['ok'], $r['ok'] ? '' : SmtpClient::explain($r, (string) $cfg['smtp_host'])];
    }

    /** Shared by delivery and the "Test connection" button. $probeOnly = log in but send nothing. */
    public static function smtpRun(array $cfg, string $fromEmail, string $fromName, string $to, string $toName,
        string $subject, string $body, ?array $attach, bool $probeOnly): array
    {
        $host = (string) $cfg['smtp_host'];
        $port = (int) ($cfg['smtp_port'] ?: 587);
        $opt = ['host' => $host, 'port' => $port, 'secure' => (string) ($cfg['smtp_secure'] ?? 'tls'),
                'user' => (string) ($cfg['smtp_user'] ?? ''), 'pass' => (string) ($cfg['smtp_pass'] ?? '')];
        if (!empty($cfg['tenant_supplied'])) {
            if (SmtpGuard::portError($port) !== null) return ['ok' => false, 'stage' => 'connect', 'error' => 'SMTP port is not allowed.', 'reply' => '', 'log' => []];
            [$ip, $why] = SmtpGuard::resolveSafe($host);
            if ($ip === null) return ['ok' => false, 'stage' => 'connect', 'error' => (string) $why, 'reply' => '', 'log' => []];
            $opt['connect_to'] = strpos($ip, ':') !== false ? "[$ip]" : $ip;
            $opt['peer_name'] = $host;
            $opt['verify_tls'] = true;
        } else {
            $opt['verify_tls'] = ($cfg['smtp_verify_tls'] ?? '1') !== '0';
            $opt['allow_plain_auth'] = true;   // operator-configured platform sender
        }
        if ($probeOnly) return SmtpClient::probe($opt);
        return SmtpClient::send($opt, ['from_email' => $fromEmail, 'from_name' => $fromName, 'to' => $to, 'to_name' => $toName,
            'subject' => $subject, 'body' => $body, 'attach' => $attach]);
    }

    /** Which sender would a platform-level message use right now? Returns the config or null when none is on. */
    public static function platformConfig(): ?array
    {
        $on  = SettingsService::bool('mail_enabled');
        $cfg = SettingsService::emailConfig();
        try {
            if (MicrosoftOAuth::platformRefreshEnc() !== '') {
                $cfg = array_merge($cfg, ['mail_transport' => 'graph', 'graph_scope' => 'platform', 'graph_refresh' => MicrosoftOAuth::platformRefreshEnc(),
                    'mail_from' => MicrosoftOAuth::platformAccount() ?: ($cfg['mail_from'] ?? '')]);
                $on = true;
            }
        } catch (\Throwable $e) {}
        return $on ? $cfg : null;
    }

    /**
     * Send one test message right now through the platform sender (bypasses the outbox) and report exactly what happened.
     * Works even when "Send email" is off so the operator can verify settings BEFORE switching it on.
     * @return array{ok:bool,error:string,log:string[]}
     */
    public static function platformTest(string $to): array
    {
        $cfg = self::platformConfig() ?? SettingsService::emailConfig();
        $fromEmail = ($cfg['mail_from'] ?? '') !== '' ? (string) $cfg['mail_from'] : '';
        if ($fromEmail === '') return ['ok' => false, 'error' => 'Enter a From address and save the settings first.', 'log' => []];
        $subject = FLXCOREHC_APP_NAME . ' test email';
        $body = "This is a test message from " . FLXCOREHC_APP_NAME . ".\n\nIf you received it, outbound email is working.\n";
        $name = (string) (($cfg['mail_from_name'] ?? '') ?: FLXCOREHC_APP_NAME);
        if (($cfg['mail_transport'] ?? 'mail') === 'smtp' && ($cfg['smtp_host'] ?? '') !== '') {
            $r = self::smtpRun($cfg, $fromEmail, $name, $to, '', $subject, $body, null, false);
            return ['ok' => (bool) $r['ok'], 'error' => $r['ok'] ? '' : SmtpClient::explain($r, (string) $cfg['smtp_host']), 'log' => $r['log']];
        }
        [$ok, $err] = self::deliver($cfg, $to, '', $subject, $body, null);
        return ['ok' => (bool) $ok, 'error' => (string) $err, 'log' => []];
    }

    /** Log in to the platform SMTP server without sending anything. */
    public static function platformProbe(): array
    {
        $cfg = SettingsService::emailConfig();
        if (($cfg['mail_transport'] ?? '') !== 'smtp' || ($cfg['smtp_host'] ?? '') === '') {
            return ['ok' => false, 'error' => 'Choose the SMTP transport and enter the host first.', 'log' => []];
        }
        $r = self::smtpRun($cfg, (string) $cfg['mail_from'], '', '', '', '', '', null, true);
        return ['ok' => (bool) $r['ok'], 'error' => $r['ok'] ? '' : SmtpClient::explain($r, (string) $cfg['smtp_host']), 'log' => $r['log']];
    }

    /** Put messages that gave up (status failed) back in the queue, e.g. after fixing the settings. Security messages are never retried. */
    public static function retryFailed(?string $tenantId = null): int
    {
        try {
            $sql = "UPDATE email_outbox SET status='queued', attempts=0, last_error=NULL WHERE status='failed' AND category <> 'security'";
            $args = [];
            if ($tenantId !== null) { $sql .= ' AND tenant_id = ?'; $args[] = $tenantId; }
            $st = Database::pdo()->prepare($sql); $st->execute($args);
            return $st->rowCount();
        } catch (\Throwable $e) { return 0; }
    }

    /** RFC 2047 encode a header value if it contains non-ASCII. */
    private static function header(string $v): string
    {
        return preg_match('/[^\x20-\x7e]/', $v) ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v;
    }
}
