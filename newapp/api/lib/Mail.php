<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
require_once __DIR__ . '/Brand.php';   // BT20: itops_app_name() for cron scripts too
/**
 * ITOps — outgoing email: PHP mail(), SMTP, or Microsoft 365 Graph. Configuration lives in itops_settings (Settings → Email).
 * 0001-A004: was MailIntake.php; the email-to-ticket intake (IMAP / Graph mailbox reading) is gone with the Service Desk.
 */
require_once __DIR__ . '/O365.php';
require_once __DIR__ . '/EmailTemplates.php';

if (!function_exists('mail_cfg')) {
    function mail_cfg(): array {
        $rows = getDB()->query("SELECT skey, sval FROM itops_settings WHERE skey LIKE 'mail_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        return $rows ?: [];
    }
}







if (!function_exists('mail_send')) {
    /** Outbound email via the configured method: graph | smtp | mailfn. */
    function mail_send(string $to, string $subject, string $body, array $ctx = []): array {
        require_once __DIR__ . '/Brand.php'; $subject = itops_brand($subject);   // BT20: the log shows the subject as sent
        $r = mail_send_raw($to, $subject, $body, $ctx['html'] ?? null);
        // Every attempt is recorded (Connection → Email → Outbound email log) so "no email arrived"
        // can be diagnosed without guessing.
        try {
            getDB()->prepare('INSERT INTO itops_mail_log (to_email,subject,method,kind,ok,error,sent_by) VALUES (?,?,?,?,?,?,?)')
                   ->execute([$to, mb_substr($subject, 0, 255), (string)(mail_cfg()['mail_out_method'] ?? 'mailfn'), (string)($ctx['kind'] ?? ''),
                              !empty($r['ok']) ? 1 : 0, mb_substr((string)($r['error'] ?? ''), 0, 1000),
                              (int)($_SERVER['ITOPS_USER_ID'] ?? 0) ?: null]);
        } catch (Throwable $e) { /* log table missing until db-update runs — never block the send */ }
        return $r;
    }
}

if (!function_exists('mail_send_raw')) {
    /** MIME body: plain text only, or multipart/alternative (text + HTML). Base64 parts: safe for
     *  UTF-8/Spanish text, long lines and SMTP dot-stuffing. Returns [content-type header, body]. */
    function mail_mime(string $text, ?string $html): array {
        $b64 = fn($s) => rtrim(chunk_split(base64_encode($s), 76, "\r\n"));
        if (!$html) return ["Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64", $b64($text)];
        $bd = '=_itops_' . bin2hex(random_bytes(8));
        $body = "--$bd\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($text) . "\r\n"
              . "--$bd\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($html) . "\r\n--$bd--";
        return ["Content-Type: multipart/alternative; boundary=\"$bd\"", $body];
    }
    /** RFC 2047 header encoding for non-ASCII subjects / names (accents, ñ, em-dashes). */
    function mail_hdr(string $s): string {
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }
    function mail_send_raw(string $to, string $subject, string $body, ?string $html = null): array {
        // BT20: every outgoing email carries the client's app name (also covers templates saved before BT20).
        require_once __DIR__ . '/Brand.php';
        $subject = itops_brand($subject); $body = itops_brand($body); if ($html !== null) $html = itops_brand($html);
        $cfg = mail_cfg();
        if (($cfg['mail_out_enabled'] ?? '0') !== '1') return ['ok' => false, 'error' => 'Outbound email is disabled (Connection → Email → Outbound email)'];
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Invalid recipient'];
        $method = $cfg['mail_out_method'] ?? 'mailfn';

        if ($method === 'graph') {
            return graph_send_mail($to, $subject, $body, $html);
        }

        $fromEmail = ($cfg['mail_from_email'] ?? '') ?: ($cfg['mail_username'] ?? '');
        $fromName  = itops_brand(($cfg['mail_from_name'] ?? '') ?: 'IT Help Desk');   // BT20
        if (!$fromEmail) return ['ok' => false, 'error' => 'No From address configured'];

        if ($method === 'smtp') {
            return smtp_send($cfg, $fromEmail, $fromName, $to, $subject, $body, $html);
        }
        [$ctype, $mime] = mail_mime($body, $html);
        $headers  = 'From: ' . mail_hdr($fromName) . ' <' . $fromEmail . ">\r\n";
        $headers .= 'Reply-To: ' . $fromEmail . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n" . $ctype . "\r\n";
        $ok = @mail($to, mail_hdr($subject), $mime, $headers, '-f' . $fromEmail);
        return ['ok' => (bool)$ok, 'error' => $ok ? '' : 'PHP mail() returned false (check server mail / try SMTP or Microsoft 365)'];
    }
}

if (!function_exists('smtp_send')) {
    function smtp_send(array $cfg, string $fromEmail, string $fromName, string $to, string $subject, string $body, ?string $html = null): array {
        $host = $cfg['mail_smtp_host'] ?? '';
        if (!$host) return ['ok' => false, 'error' => 'SMTP host not set'];
        $sec  = $cfg['mail_smtp_security'] ?? 'tls';
        $port = (int)(($cfg['mail_smtp_port'] ?? '') ?: ($sec === 'ssl' ? 465 : 587));
        $user = ($cfg['mail_smtp_user'] ?? '') ?: ($cfg['mail_username'] ?? '');
        $pass = !empty($cfg['mail_smtp_pass_enc']) ? vault_decrypt($cfg['mail_smtp_pass_enc'])
              : (!empty($cfg['mail_password_enc']) ? vault_decrypt($cfg['mail_password_enc']) : '');

        $transport = ($sec === 'ssl' ? 'ssl://' : '') . $host;
        $fp = @fsockopen($transport, $port, $errno, $errstr, 15);
        if (!$fp) return ['ok' => false, 'error' => "SMTP connect failed: {$errstr}"];
        stream_set_timeout($fp, 15);
        $read = function () use ($fp) {
            $data = '';
            while ($line = fgets($fp, 515)) { $data .= $line; if (isset($line[3]) && $line[3] === ' ') break; }
            return $data;
        };
        $cmd = function ($c) use ($fp, $read) { fputs($fp, $c . "\r\n"); return $read(); };
        $ehlo = 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $read();
        $cmd($ehlo);
        if ($sec === 'tls') {
            $r = $cmd('STARTTLS');
            if (substr($r, 0, 3) !== '220') { fclose($fp); return ['ok' => false, 'error' => 'STARTTLS failed: ' . trim($r)]; }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return ['ok' => false, 'error' => 'TLS negotiation failed']; }
            $cmd($ehlo);
        }
        if ($user) {
            $cmd('AUTH LOGIN');
            $cmd(base64_encode($user));
            $r = $cmd(base64_encode($pass));
            if (substr($r, 0, 3) !== '235') { fclose($fp); return ['ok' => false, 'error' => 'SMTP auth failed: ' . trim($r)]; }
        }
        $cmd('MAIL FROM:<' . $fromEmail . '>');
        $rc = $cmd('RCPT TO:<' . $to . '>');
        if (!in_array(substr($rc, 0, 3), ['250', '251'])) { fclose($fp); return ['ok' => false, 'error' => 'Recipient rejected: ' . trim($rc)]; }
        $cmd('DATA');
        [$ctype, $mime] = mail_mime($body, $html);
        $msg  = 'From: ' . mail_hdr($fromName) . ' <' . $fromEmail . ">\r\n";
        $msg .= 'To: <' . $to . ">\r\n";
        $msg .= 'Reply-To: ' . $fromEmail . "\r\n";
        $msg .= 'Subject: ' . mail_hdr($subject) . "\r\n";
        $msg .= 'Date: ' . date('r') . "\r\n";
        $msg .= "MIME-Version: 1.0\r\n" . $ctype . "\r\n\r\n";
        $msg .= $mime . "\r\n.";
        $r = $cmd($msg);
        $cmd('QUIT');
        fclose($fp);
        if (substr($r, 0, 3) !== '250') return ['ok' => false, 'error' => 'Message rejected: ' . trim($r)];
        return ['ok' => true, 'error' => ''];
    }
}
