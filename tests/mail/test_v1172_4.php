<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * v1.172.4 — SmtpClient against a REAL local SMTP conversation (tests/mail/fake_smtp.php), no DB.
 * Every check asserts what the server received / what the operator is told, not source text.
 *   php tests/mail/test_v1172_4.php
 */
declare(strict_types=1);
require __DIR__ . '/../../app/src/SmtpClient.php';
use FlxCoreHC\SmtpClient;

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail++; };

$tmp = sys_get_temp_dir() . '/flxmail_' . bin2hex(random_bytes(4)); mkdir($tmp);
$pem = "$tmp/s.pem";
exec('openssl req -x509 -newkey rsa:2048 -nodes -keyout ' . escapeshellarg("$tmp/k.pem") . ' -out ' . escapeshellarg("$tmp/c.pem") . ' -days 1 -subj /CN=fake.test 2>/dev/null');
file_put_contents($pem, file_get_contents("$tmp/c.pem") . file_get_contents("$tmp/k.pem"));
$procs = [];
$start = function (string $mode) use (&$procs, $pem, $tmp): array {
    for ($try = 0; $try < 5; $try++) {   // a random port can already be taken: pick another instead of failing the test run
        $port = random_int(20000, 40000); $out = "$tmp/$mode.$port.out"; touch($out);
        $p = proc_open([PHP_BINARY, __DIR__ . '/fake_smtp.php', (string) $port, $mode, $pem, $out], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_set_timeout($pipes[1], 5);
        if (trim((string) fgets($pipes[1])) === 'READY') { $procs[] = $p; return [$port, $out]; }
        proc_terminate($p);
    }
    throw new RuntimeException('fake SMTP server did not start');
};
$recv = static fn(string $out): array => array_values(array_filter(array_map(fn($l) => json_decode($l, true), file($out, FILE_IGNORE_NEW_LINES))));
$base = ['host' => '127.0.0.1', 'secure' => 'none', 'user' => 'alerts@x.test', 'pass' => 'S3cret!pw', 'allow_plain_auth' => true];
$msg = ['from_email' => 'alerts@x.test', 'from_name' => 'Flx Alerts', 'to' => 'dest@y.test', 'to_name' => 'Dest', 'subject' => 'Hello', 'body' => "Línea 1\n.punto al inicio\nfin"];

// M1 AUTH PLAIN only
[$port, $out] = $start('plain');
$r = SmtpClient::send($base + ['port' => $port], $msg);
$got = $recv($out);
$raw = $got[0]['data'] ?? '';
$bodyB64 = preg_replace('/\s+/', '', substr($raw, (int) strpos($raw, "\r\n\r\n")));
$check($r['ok'] && count($got) === 1 && str_contains((string) $got[0]['from'], 'alerts@x.test') && str_contains((string) $got[0]['to'], 'dest@y.test'),
    'M1 a PLAIN-only server accepts the login and receives the message (' . ($r['error'] ?: 'ok') . ')');
$check(base64_decode($bodyB64) === "Línea 1\n.punto al inicio\nfin" && preg_match('/^Message-ID: <[0-9a-f]+@/mi', $raw) === 1 && !preg_match('/^\.[^.]/m', $raw),
    'M2 body arrives intact (accents, line starting with a dot) and carries a Message-ID');

[$port2, $out2] = $start('both');
SmtpClient::send($base + ['port' => $port2], ['body' => "Your code is 123456\nsecond line", 'subject' => 'Code'] + $msg);
$raw2 = ($recv($out2)[0]['data'] ?? '');
$check(str_contains($raw2, "\r\n\r\nYour code is 123456\r\nsecond line") && str_contains($raw2, 'Content-Transfer-Encoding: 7bit'),
    'M2b an ASCII body (codes, links) travels as readable plain text, so any mail reader or sink shows it as written');

// M3 LOGIN only
[$port, $out] = $start('login');
$r = SmtpClient::send($base + ['port' => $port], $msg);
$check($r['ok'] && count($recv($out)) === 1 && in_array('Authenticated (LOGIN)', $r['log'], true), 'M3 a LOGIN-only server works (method follows what the server advertises)');

// M4 wrong password: stage, server words, no secret leaked
[$port, $out] = $start('both');
$r = SmtpClient::send(['pass' => 'Wrong-Pass-9'] + $base + ['port' => $port], $msg);
$exp = SmtpClient::explain($r, 'mail.x.test');
$check(!$r['ok'] && $r['stage'] === 'auth' && str_contains($r['reply'], '535') && str_contains($exp, 'full e-mail address') && !str_contains(json_encode($r) . $exp, 'Wrong-Pass-9') && !$recv($out),
    'M4 wrong password: stage=auth, the server\'s 535 text is shown, a plain-language hint is given, nothing sent, password never echoed');

// M5 server without login while a username is set
[$port, $out] = $start('noauth');
$r = SmtpClient::send($base + ['port' => $port], $msg);
$check(!$r['ok'] && $r['stage'] === 'auth' && str_contains($r['error'], 'does not offer a login'), 'M5 a server that offers no login says so instead of a vague "authentication failed"');

// M6 implicit TLS (465) with a self-signed certificate
[$port, $out] = $start('ssl');
$r1 = SmtpClient::send(['secure' => 'ssl', 'peer_name' => 'fake.test'] + $base + ['port' => $port], $msg);
$r2 = SmtpClient::send(['secure' => 'ssl', 'verify_tls' => false] + $base + ['port' => $port], $msg);
$check(!$r1['ok'] && in_array($r1['stage'], ['tls', 'connect'], true) && $r2['ok'],
    'M6 SSL/TLS: an untrusted certificate is refused when verifying, accepted only when the operator turned verification off');
$check(!$r1['ok'] && $r2['ok'] && count($recv($out)) === 1, 'M6b the verified attempt delivered nothing; the unverified one delivered exactly one message (' . $r1['stage'] . '/' . ($r2['error'] ?: 'ok') . ')');

// M7 STARTTLS (587)
[$port, $out] = $start('starttls');
$r1 = SmtpClient::send(['secure' => 'tls'] + $base + ['port' => $port], $msg);
$r2 = SmtpClient::send(['secure' => 'tls', 'verify_tls' => false] + $base + ['port' => $port], $msg);
$check(!$r1['ok'] && $r1['stage'] === 'tls' && $r2['ok'] && in_array('STARTTLS established', $r2['log'], true) && count($recv($out)) === 1,
    'M7 STARTTLS: untrusted certificate refused (stage=tls); with verification off the login happens only after the upgrade and the message arrives');

// M8 STARTTLS requested on a server that does not offer it
[$port, $out] = $start('both');
$r = SmtpClient::send(['secure' => 'tls'] + $base + ['port' => $port], $msg);
$check(!$r['ok'] && $r['stage'] === 'tls' && str_contains($r['error'], 'STARTTLS') && !$recv($out), 'M8 STARTTLS mode against a server without STARTTLS fails clearly and sends nothing');

// M9 recipient refused
[$port, $out] = $start('both');
$r = SmtpClient::send($base + ['port' => $port], ['to' => 'reject@y.test'] + $msg);
$check(!$r['ok'] && $r['stage'] === 'rcpt' && str_contains($r['reply'], '550'), 'M9 a refused recipient reports stage=rcpt with the server\'s 550');

// M10 probe does not send
[$port, $out] = $start('both');
$r = SmtpClient::probe($base + ['port' => $port]);
$check($r['ok'] && !$recv($out) && in_array('Authenticated (PLAIN)', $r['log'], true), 'M10 "test connection" logs in and sends nothing');

// M11 password never goes out in clear to a remote host
$r = SmtpClient::send(['host' => 'mail.example.test', 'connect_to' => '127.0.0.1', 'secure' => 'none', 'user' => 'u', 'pass' => 'p'] + ['port' => $port], $msg);
$check(!$r['ok'] && $r['stage'] === 'auth' && str_contains($r['error'], 'without encryption'), 'M11 AUTH over "None" to a non-local host is refused before the password is sent');

// M12 header injection
[$port, $out] = $start('both');
$r = SmtpClient::send($base + ['port' => $port], ['subject' => "Hi\r\nBcc: evil@x.test", 'to_name' => "A\r\nX-Evil: 1"] + $msg);
$raw = ($recv($out)[0]['data'] ?? '');
$check($r['ok'] && !preg_match('/^(Bcc|X-Evil):/mi', $raw), 'M12 CR/LF in subject or name cannot create new headers');

// M13 nothing listening
$r = SmtpClient::send(['host' => '127.0.0.1', 'port' => 1, 'secure' => 'none'], $msg);
$check(!$r['ok'] && $r['stage'] === 'connect' && str_contains(SmtpClient::explain($r), '465'), 'M13 closed port: stage=connect with port guidance');

// M14 hints and EHLO name
$g = SmtpClient::explain(['ok' => false, 'stage' => 'auth', 'error' => 'The server rejected the login.', 'reply' => '534-5.7.9 Application-specific password required'], 'smtp.gmail.com');
$m = SmtpClient::explain(['ok' => false, 'stage' => 'auth', 'error' => 'x', 'reply' => '535 5.7.139 basic authentication is disabled'], 'smtp.office365.com');
$e = SmtpClient::ehloName();
$check(str_contains($g, 'App password') && str_contains($m, 'Connect Microsoft 365') && $e !== 'flxcred.local' && preg_match('/^[a-z0-9.-]+\.[a-z0-9.-]+$/', $e) === 1,
    'M14 Gmail/Microsoft failures get the right advice; the EHLO name is a real host name (' . $e . ')');

$cl = SmtpClient::clean("535 bad login for u: Pa55word! (" . base64_encode('Pa55word!') . ")\r\n\x00", 'Pa55word!');
$check(!str_contains($cl, 'Pa55word!') && !str_contains($cl, base64_encode('Pa55word!')) && !preg_match('/[\x00-\x1f]/', $cl) && str_contains($cl, '535'),
    'M15 text shown to the operator has control characters removed and the password (plain or base64) redacted');

foreach ($procs as $p) { proc_terminate($p); }
exec('rm -rf ' . escapeshellarg($tmp));
echo $fail ? "\nSOME FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
