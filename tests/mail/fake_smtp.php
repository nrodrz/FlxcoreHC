<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
// Test-only SMTP server. php fake_smtp.php <port> <mode> <pem> <outfile>
// modes: plain (AUTH PLAIN only) | login (AUTH LOGIN only) | both | noauth | ssl (implicit TLS, both) | starttls (both, after STARTTLS)
[$_, $port, $mode, $pem, $out] = $argv;
const USER = 'alerts@x.test'; const PASS = 'S3cret!pw';
$ctx = stream_context_create(['ssl' => ['local_cert' => $pem, 'verify_peer' => false, 'allow_self_signed' => true]]);
$srv = stream_socket_server("tcp://127.0.0.1:$port", $en, $es, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if (!$srv) { fwrite(STDERR, "bind failed $es\n"); exit(1); }
fwrite(STDOUT, "READY\n"); fflush(STDOUT);
while (true) {
    $c = @stream_socket_accept($srv, 30);
    if (!$c) continue;
    stream_set_timeout($c, 5);
    $tls = false;
    if ($mode === 'ssl') { if (!@stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) { fclose($c); continue; } $tls = true; }
    $w = fn(string $s) => fwrite($c, $s . "\r\n");
    $w('220 fake.test ESMTP');
    $authed = false; $from = $to = null; $data = null;
    while (($line = fgets($c, 8192)) !== false) {
        $line = rtrim($line, "\r\n"); $u = strtoupper($line);
        if (str_starts_with($u, 'EHLO')) {
            $w('250-fake.test'); 
            if ($mode === 'starttls' && !$tls) $w('250-STARTTLS');
            $mech = match ($mode) { 'plain' => 'PLAIN', 'login' => 'LOGIN', 'noauth' => '', default => 'PLAIN LOGIN' };
            if ($mech !== '' && ($mode !== 'starttls' || $tls)) $w('250-AUTH ' . $mech);
            $w('250 8BITMIME');
        } elseif ($u === 'STARTTLS') {
            $w('220 ready'); if (!@stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) break; $tls = true;
        } elseif (str_starts_with($u, 'AUTH PLAIN')) {
            [, , $b] = array_pad(explode(' ', $line, 3), 3, '');
            $p = explode("\0", (string) base64_decode($b));
            if (($p[1] ?? '') === USER && ($p[2] ?? '') === PASS && $mode !== 'login') { $authed = true; $w('235 2.7.0 Authentication successful'); }
            else $w('535 5.7.8 Authentication credentials invalid');
        } elseif ($u === 'AUTH LOGIN') {
            $w('334 VXNlcm5hbWU6'); $usr = base64_decode((string) fgets($c));
            $w('334 UGFzc3dvcmQ6'); $pw = base64_decode((string) fgets($c));
            if ($usr === USER && $pw === PASS && $mode !== 'plain') { $authed = true; $w('235 2.7.0 ok'); } else $w('535 5.7.8 Authentication credentials invalid');
        } elseif (str_starts_with($u, 'MAIL FROM:')) { if ($mode !== 'noauth' && !$authed) { $w('530 5.7.0 Authentication required'); continue; } $from = $line; $w('250 ok'); }
        elseif (str_starts_with($u, 'RCPT TO:')) { if (str_contains($line, 'reject@')) $w('550 5.1.1 no such user'); else { $to = $line; $w('250 ok'); } }
        elseif ($u === 'DATA') {
            $w('354 go'); $buf = '';
            while (($l = fgets($c, 8192)) !== false) { if ($l === ".\r\n") break; $buf .= $l; }
            file_put_contents($out, json_encode(['from' => $from, 'to' => $to, 'data' => $buf]) . "\n", FILE_APPEND);
            $w('250 2.0.0 queued as FAKE123');
        } elseif ($u === 'QUIT') { $w('221 bye'); break; }
        else $w('502 unsupported');
    }
    @fclose($c);
}
