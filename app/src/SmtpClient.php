<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * v1.172.4 — dependency-free SMTP client with structured results.
 *
 * Replaces the socket code that used to live inside MailService. What changed and why:
 *  - Authentication picks what the server ADVERTISES (AUTH PLAIN or AUTH LOGIN) instead of assuming LOGIN.
 *  - Every failure reports the STAGE (connect / tls / auth / from / rcpt / data) and the server's own reply,
 *    so the operator can see "535 5.7.8 credentials invalid" instead of a bare "authentication failed".
 *  - The EHLO name is a real host name (from app_url or the server), never "flxcorehc.local" or a Host header with a port.
 *  - STARTTLS requires a TLS 1.2+ handshake and verifies the certificate (platform operator may turn verification off
 *    for a mail host whose certificate names the server instead of the mail host; tenant-supplied SMTP never can).
 *  - Credentials are never sent in clear text to a remote host: AUTH over "None" is refused unless the host is local
 *    or the caller explicitly allows it (platform operator).
 *  - Headers are stripped of CR/LF (no header injection) and the body is base64, so non-ASCII text and long lines are safe.
 *
 * send()/probe() never throw. They return ['ok'=>bool, 'stage'=>string, 'error'=>string, 'reply'=>string, 'log'=>string[]].
 * The password is never placed in 'error', 'reply' or 'log'.
 */
final class SmtpClient
{
    /**
     * @param array $o   host, port, secure (tls|ssl|none), user, pass, peer_name?, verify_tls? (default true),
     *                   allow_plain_auth? (default false), ehlo_name?, timeout?
     * @param array $msg from_email, from_name, to, to_name, subject, body, attach?
     */
    public static function send(array $o, array $msg): array
    {
        return self::run($o, $msg);
    }

    /** Connect, secure and authenticate, then QUIT. Sends nothing. */
    public static function probe(array $o): array
    {
        return self::run($o, null);
    }

    private static function run(array $o, ?array $msg): array
    {
        $log = [];
        $host   = (string) ($o['host'] ?? '');
        $port   = (int) ($o['port'] ?? 587);
        $secure = in_array($o['secure'] ?? 'tls', ['tls', 'ssl', 'none'], true) ? (string) $o['secure'] : 'tls';
        $user   = (string) ($o['user'] ?? '');
        $pass   = (string) ($o['pass'] ?? '');
        $peer   = (string) ($o['peer_name'] ?? $host);
        $verify = ($o['verify_tls'] ?? true) !== false;
        $timeout = (int) ($o['timeout'] ?? 12);
        $ehloName = self::ehloName((string) ($o['ehlo_name'] ?? ''));
        $target = (string) ($o['connect_to'] ?? $host);

        $fail = static function (string $stage, string $error, string $reply = '') use (&$log, $pass, $user) {
            return ['ok' => false, 'stage' => $stage, 'error' => $error, 'reply' => self::clean($reply, $pass), 'log' => $log];
        };

        if ($host === '' || $port < 1) return $fail('connect', 'No SMTP host or port is set.');

        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify,
            'peer_name' => $peer, 'SNI_enabled' => true, 'SNI_server_name' => $peer,
        ]]);
        $transport = $secure === 'ssl' ? "ssl://{$target}" : "tcp://{$target}";
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client("{$transport}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            // The OS/OpenSSL detail goes to the server log only: showing it would let a tenant probe internal networks.
            $why = $errstr !== '' ? $errstr : (string) (error_get_last()['message'] ?? '');
            error_log('smtp connect failed (' . $host . ':' . $port . '): ' . self::clean($why, $pass));
            $stage = ($secure === 'ssl' && self::looksLikeTls($why)) ? 'tls' : 'connect';
            return $fail($stage, $stage === 'tls'
                ? 'The secure (SSL/TLS) connection to ' . $host . ':' . $port . ' could not be established or verified'
                : 'Could not connect to the SMTP server at ' . $host . ':' . $port . ' (check host, port and security mode)');
        }
        stream_set_timeout($fp, $timeout);
        $log[] = 'Connected to ' . $host . ':' . $port . ($secure === 'ssl' ? ' (SSL/TLS)' : '');

        $read = static function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 1024)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] !== '-') break;   // "250-" continues, "250 " ends
            }
            return $data;
        };
        $cmd = static function (string $c) use ($fp, $read): string { fwrite($fp, $c . "\r\n"); return $read(); };
        $code = static fn(string $r): int => (int) substr($r, 0, 3);
        $quit = static function () use ($fp) { @fwrite($fp, "QUIT\r\n"); @fclose($fp); };

        $r = $read();
        if ($code($r) !== 220) { $quit(); return $fail('connect', 'The server did not greet us as an SMTP server.', $r); }
        $r = $cmd('EHLO ' . $ehloName);
        if ($code($r) !== 250) {
            $r = $cmd('HELO ' . $ehloName);
            if ($code($r) !== 250) { $quit(); return $fail('connect', 'The server refused the greeting (EHLO/HELO).', $r); }
        }
        $caps = $r;

        if ($secure === 'tls') {
            if (!preg_match('/^250[ -]STARTTLS/mi', $caps)) { $quit(); return $fail('tls', 'The server does not offer STARTTLS on this port. Try SSL/TLS on port 465.', $caps); }
            $r = $cmd('STARTTLS');
            if ($code($r) !== 220) { $quit(); return $fail('tls', 'The server refused STARTTLS.', $r); }
            $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
            }
            $okTls = @stream_socket_enable_crypto($fp, true, $method);
            if ($okTls !== true) {
                error_log('smtp starttls failed (' . $host . ':' . $port . '): ' . self::clean((string) (error_get_last()['message'] ?? ''), $pass));
                $quit();
                return $fail('tls', 'The secure (TLS) handshake failed');
            }
            $log[] = 'STARTTLS established';
            $caps = $cmd('EHLO ' . $ehloName);
            if ($code($caps) !== 250) { $quit(); return $fail('connect', 'The server refused EHLO after STARTTLS.', $caps); }
        } elseif ($secure === 'ssl') {
            $log[] = 'TLS established';
        }

        if ($user !== '') {
            $encrypted = $secure !== 'none';
            $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
            if (!$encrypted && !$local && empty($o['allow_plain_auth'])) {
                $quit();
                return $fail('auth', 'Refusing to send the password without encryption. Choose STARTTLS (587) or SSL/TLS (465).');
            }
            $mechs = [];
            if (preg_match('/^250[ -]AUTH[ =]([^\r\n]*)/mi', $caps, $m)) {
                $mechs = preg_split('/\s+/', strtoupper(trim($m[1])), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
            if (!$mechs) { $quit(); return $fail('auth', 'The server does not offer a login on this connection. Use the port and security mode the provider documents (usually 587 + STARTTLS, or 465 + SSL/TLS).', $caps); }
            $order = array_values(array_intersect(['PLAIN', 'LOGIN'], $mechs));
            if (!$order) { $quit(); return $fail('auth', 'The server only offers login methods this app does not support (' . implode(', ', $mechs) . ').', $caps); }
            $authOk = false; $lastReply = '';
            foreach ($order as $mech) {
                if ($mech === 'PLAIN') {
                    $r = $cmd('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass));
                } else {
                    $r = $cmd('AUTH LOGIN');
                    if ($code($r) === 334) { $r = $cmd(base64_encode($user)); }
                    if ($code($r) === 334) { $r = $cmd(base64_encode($pass)); }
                }
                $lastReply = $r;
                if ($code($r) === 235) { $authOk = true; $log[] = 'Authenticated (' . $mech . ')'; break; }
                if ($code($r) === 535 || $code($r) === 534 || $code($r) === 454) break;   // credentials/policy: a second method will not help
            }
            if (!$authOk) { $quit(); return $fail('auth', 'The server rejected the login.', $lastReply); }
        } else {
            $log[] = 'No login configured (anonymous submission)';
        }

        if ($msg === null) { $quit(); $log[] = 'Connection test finished (nothing was sent)'; return ['ok' => true, 'stage' => 'done', 'error' => '', 'reply' => '', 'log' => $log]; }

        $fromEmail = self::addr((string) $msg['from_email']);
        $toEmail   = self::addr((string) $msg['to']);
        if ($fromEmail === '' || $toEmail === '') { $quit(); return $fail('from', 'The From or To address is not valid.'); }

        $r = $cmd("MAIL FROM:<{$fromEmail}>");
        if ($code($r) !== 250) { $quit(); return $fail('from', 'The server refused the From address.', $r); }
        $r = $cmd("RCPT TO:<{$toEmail}>");
        if (!in_array($code($r), [250, 251], true)) { $quit(); return $fail('rcpt', 'The server refused the recipient address.', $r); }
        $r = $cmd('DATA');
        if ($code($r) !== 354) { $quit(); return $fail('data', 'The server would not accept the message.', $r); }
        $r = $cmd(self::build($msg, $fromEmail, $toEmail, $ehloName) . "\r\n.");
        if ($code($r) !== 250) { $quit(); return $fail('data', 'The server rejected the message.', $r); }
        $log[] = 'Message accepted: ' . self::clean($r, $pass);
        $quit();
        return ['ok' => true, 'stage' => 'done', 'error' => '', 'reply' => self::clean($r, $pass), 'log' => $log];
    }

    /** A valid host name for EHLO: the app's own host, else this server's, else localhost. */
    public static function ehloName(string $given = ''): string
    {
        $cands = [$given];
        if (function_exists('config')) {
            $u = (string) (config('app_url') ?? '');
            if ($u !== '') $cands[] = (string) (parse_url($u, PHP_URL_HOST) ?: '');
        }
        $cands[] = (string) gethostname();
        foreach ($cands as $c) {
            $c = strtolower(trim($c));
            if ($c !== '' && preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/', $c) && strpos($c, '.') !== false) return $c;
        }
        return 'localhost.localdomain';
    }

    /** Single clean address (no CR/LF/angle brackets/spaces) or '' when invalid. */
    public static function addr(string $a): string
    {
        $a = trim($a);
        return (preg_match('/^[^\s<>"\\\\\r\n]+@[^\s<>"\\\\\r\n]+$/', $a) && filter_var($a, FILTER_VALIDATE_EMAIL)) ? $a : '';
    }

    /** Server text made safe to show: control characters removed, secrets redacted, length capped. */
    public static function clean(string $s, string $secret = ''): string
    {
        $s = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $s) ?? '';
        if ($secret !== '' && strlen($secret) >= 3) $s = str_replace([$secret, base64_encode($secret)], '[hidden]', $s);
        return mb_substr(trim($s), 0, 240);
    }

    private static function looksLikeTls(string $why): bool
    {
        return (bool) preg_match('/ssl|tls|certificate|handshake|crypto/i', $why);
    }

    private static function hdr(string $v): string
    {
        $v = trim(preg_replace('/[\r\n]+/', ' ', $v) ?? '');
        return preg_match('/[^\x20-\x7e]/', $v) ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v;
    }

    private static function mailbox(string $name, string $email): string
    {
        $name = trim(preg_replace('/[\r\n]+/', ' ', $name) ?? '');
        if ($name === '') return '<' . $email . '>';
        if (preg_match('/[^\x20-\x7e]/', $name)) return self::hdr($name) . ' <' . $email . '>';
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '" <' . $email . '>';
    }

    /** Full RFC 5322 message (headers + body) with CRLF line ends, ready for DATA (dot-stuffed). */
    public static function build(array $msg, string $fromEmail, string $toEmail, string $domainHint = ''): string
    {
        $domain = $domainHint !== '' ? $domainHint : (substr(strrchr($fromEmail, '@') ?: '@localhost', 1));
        $h  = 'Date: ' . date('r') . "\r\n";
        $h .= 'From: ' . self::mailbox((string) ($msg['from_name'] ?? ''), $fromEmail) . "\r\n";
        $h .= 'To: ' . self::mailbox((string) ($msg['to_name'] ?? ''), $toEmail) . "\r\n";
        $h .= 'Subject: ' . self::hdr((string) ($msg['subject'] ?? '')) . "\r\n";
        $h .= 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . ">\r\n";
        $h .= "MIME-Version: 1.0\r\n";
        $body = str_replace(["\r\n", "\r"], "\n", (string) ($msg['body'] ?? ''));
        // Plain ASCII with short lines goes as readable 7bit text (every reader and mail sink handles it); anything else as base64.
        $plain = !preg_match('/[^\x09\x0a\x20-\x7e]/', $body) && (!$body || max(array_map('strlen', explode("\n", $body))) <= 900);
        $cte   = $plain ? '7bit' : 'base64';
        $text  = $plain ? str_replace("\n", "\r\n", $body) . "\r\n" : chunk_split(base64_encode($body), 76, "\r\n");
        $att = $msg['attach'] ?? null;
        if (is_array($att) && !empty($att['bytes'])) {
            $b = 'flx_' . bin2hex(random_bytes(10));
            $name = str_replace(['"', "\r", "\n", '\\'], '', (string) ($att['name'] ?? 'attachment'));
            $mime = preg_match('#^[\w.+-]+/[\w.+-]+$#', (string) ($att['mime'] ?? '')) ? (string) $att['mime'] : 'application/octet-stream';
            $out  = $h . "Content-Type: multipart/mixed; boundary=\"{$b}\"\r\n\r\n";
            $out .= "--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: {$cte}\r\n\r\n{$text}";
            $out .= "--{$b}\r\nContent-Type: {$mime}; name=\"{$name}\"\r\nContent-Transfer-Encoding: base64\r\n";
            $out .= "Content-Disposition: attachment; filename=\"{$name}\"\r\n\r\n" . chunk_split(base64_encode((string) $att['bytes']), 76, "\r\n");
            $out .= "--{$b}--";
        } else {
            $out = $h . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: {$cte}\r\n\r\n" . rtrim($text);
        }
        return preg_replace('/^\./m', '..', $out) ?? $out;
    }

    /**
     * Plain-language explanation for a failed result: what it most likely means and what to change.
     * Includes the server's own words (already cleaned) so the operator can quote them to the provider.
     */
    public static function explain(array $res, string $host = ''): string
    {
        if (!empty($res['ok'])) return '';
        $stage = (string) ($res['stage'] ?? '');
        $reply = (string) ($res['reply'] ?? '');
        $base  = (string) ($res['error'] ?? 'Delivery failed.');
        $hint  = '';
        $h = strtolower($host);
        switch ($stage) {
            case 'connect':
                $hint = 'Check the host name and port (465 with SSL/TLS, or 587 with STARTTLS). Many web hosts block outbound mail ports to other servers; ask the host, or use a mailbox on this same server.';
                break;
            case 'tls':
                $hint = 'The encrypted connection could not be verified. Use the exact mail host name the certificate was issued for, and match the port to the mode (465 = SSL/TLS, 587 = STARTTLS).';
                break;
            case 'auth':
                if (preg_match('/application-specific|app password|5\.7\.9|534/i', $reply)) {
                    $hint = 'Google requires an App password: turn on 2-Step Verification, create an App password, and paste the 16 characters here.';
                } elseif (strpos($h, 'office365') !== false || strpos($h, 'outlook') !== false || preg_match('/5\.7\.139|5\.7\.30|basic auth/i', $reply)) {
                    $hint = 'Microsoft 365 usually has password sign-in for SMTP turned off. Use Connect Microsoft 365, or a mailbox on your own domain instead.';
                } elseif (strpos($h, 'gmail') !== false || strpos($h, 'google') !== false) {
                    $hint = 'Google does not accept the normal account password here. Use an App password (requires 2-Step Verification).';
                } else {
                    $hint = 'Use the full e-mail address as the username (for example alerts@yourdomain.com), re-type the password, and make sure the mailbox exists.';
                }
                break;
            case 'from':
                $hint = 'The From address must be the mailbox you log in with (or an address on the same domain).';
                break;
            case 'rcpt':
                $hint = 'The server will not deliver to that recipient address. Check the spelling, or the server may only relay for its own domain.';
                break;
            case 'data':
                $hint = 'The server accepted the login but refused the message itself (often spam filtering or a sending limit).';
                break;
        }
        return rtrim($base, '.') . '.' . ($reply !== '' ? ' Server said: ' . $reply . '.' : '') . ($hint !== '' ? ' ' . $hint : '');
    }
}
