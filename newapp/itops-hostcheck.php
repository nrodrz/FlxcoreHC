<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — host check: can this server run ITOps?   (BT66 prep — run it on a NEW server before moving the app)
 *
 * Read-only. It changes nothing, stores nothing, sends nothing except the outbound tests listed below.
 *
 * From the command line (the full check):
 *   php itops-hostcheck.php
 *   php itops-hostcheck.php --db-host=database --db-user=itops --db-name=itops      (asks for the password; not shown, not saved)
 *   php itops-hostcheck.php --whm=server.example.com                                   (can this server reach WHM on port 2087, with a valid certificate?)
 * In Docker:  docker compose exec -T webserver php /var/www/html/itops-hostcheck.php   (the service name may differ: docker compose ps)
 *
 * In a browser (only the web-server part: rewrite rules, the Authorization header, HTTPS):
 *   https://YOUR-SITE/itops-hostcheck.php — works for 1 hour after the file is uploaded, then it refuses. Delete the file when done.
 *
 * Outbound tests (CLI only): https://login.microsoftonline.com and https://graph.microsoft.com (Microsoft 365), api.ipify.org (this
 * server's public IP, for allow-lists), TCP 587 and 25 to smtp.office365.com (information only), and WHM if --whm is given.
 */
const ITOPS_HC_PHP_MIN = '8.1.0';
$cli = PHP_SAPI === 'cli';
$res = [];
$add = function (string $area, string $what, string $state, string $detail = '') use (&$res) { $res[] = [$area, $what, $state, $detail]; };
$bytes = function ($v) { $v = trim((string)$v); if ($v === '' ) return 0; if ($v === '-1') return -1; $n = (float)$v; $u = strtolower(substr($v, -1));
    return (int)($u === 'g' ? $n * 1073741824 : ($u === 'm' ? $n * 1048576 : ($u === 'k' ? $n * 1024 : $n))); };

// ───────────────────────── web mode: the web-server part only, for 1 hour after upload ─────────────────────────
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
    if (time() - max((int)@filemtime(__FILE__), (int)@filectime(__FILE__)) > 3600)   // ctime: the moment it was put on this server (unzip keeps the old mtime)
    { http_response_code(403); exit("This check works for 1 hour after upload. Upload it again, or delete it.\n"); }
    if (isset($_GET['echo'])) {   // the second request: did the Authorization header reach PHP?
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($h === '' && function_exists('getallheaders')) foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $h = $v;
        exit($h === 'Bearer itops-hostcheck' ? 'AUTH-OK' : 'AUTH-MISSING');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $add('Web', 'HTTPS', $https ? 'OK' : 'FAIL', $https ? 'the page was opened over HTTPS' : 'open it with https:// — ITOps needs HTTPS');
    $add('Web', 'Server software', 'INFO', (string)($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'));
    $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? ''));
    $ra = (string)($_SERVER['REMOTE_ADDR'] ?? ''); $priv = $ra !== '' && !filter_var($ra, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    if ($xff === '' && $priv) $add('Web', 'Visitor address', 'WARN', 'PHP sees ' . $ra . ' (a private address): a proxy is in front and does not pass the visitor\'s address on — sign-in limits and the Audit Log would see one address for everyone');
    else $add('Web', 'Behind a proxy', $xff !== '' ? 'WARN' : 'OK', $xff !== '' ? 'yes — PHP sees ' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ', your browser is ' . $xff . '. ITOps must be told to trust this proxy (BT66), or sign-in limits and the Audit Log see one address for everyone' : 'PHP sees ' . $ra . ' — that should be YOUR public address (compare with whatismyip); the proxy, if any, passes it on correctly');
    if (function_exists('apache_get_modules')) { $m = apache_get_modules();
        foreach (['mod_rewrite' => 'the .htaccess rules (API routes)', 'mod_headers' => 'no-cache headers for updates'] as $k => $why) $add('Web', $k, in_array($k, $m, true) ? 'OK' : 'FAIL', $why); }
    else $add('Web', 'Apache modules', 'INFO', 'not visible from PHP here (PHP-FPM) — the Authorization test below is what matters');
    $add('Web', '.htaccess files are read', is_file(__DIR__ . '/.htaccess') ? 'INFO' : 'WARN', 'ITOps ships .htaccess files; the vhost needs "AllowOverride All"');
    // Review CORE-19: the server's own configured name, never the Host header a visitor sends.
    $self = ($https ? 'https' : 'http') . '://' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . (in_array((string)($_SERVER['SERVER_PORT'] ?? ''), ['', '80', '443'], true) ? '' : ':' . (int)$_SERVER['SERVER_PORT']) . strtok($_SERVER['REQUEST_URI'] ?? '/', '?') . '?echo=1';
    $ok = null;
    if (function_exists('curl_init')) { $ch = curl_init($self); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ['Authorization: Bearer itops-hostcheck']]);
        $body = (string)curl_exec($ch); curl_close($ch); $ok = $body === 'AUTH-OK' ? true : ($body === 'AUTH-MISSING' ? false : null); }
    $add('Web', 'Authorization header reaches PHP', $ok === true ? 'OK' : ($ok === false ? 'FAIL' : 'WARN'),
        $ok === true ? 'sign-in tokens will work' : ($ok === false ? 'the web server strips it — ITOps\'s .htaccess passes it on when mod_rewrite is on; otherwise add "CGIPassAuth On"' : 'could not call itself to test (' . $self . ')'));
    $add('PHP', 'PHP (web)', version_compare(PHP_VERSION, ITOPS_HC_PHP_MIN, '>=') ? 'OK' : 'FAIL', PHP_VERSION . ' (' . PHP_SAPI . ')');
    foreach (['upload_max_filesize' => 50, 'post_max_size' => 55] as $k => $mb) { $v = $bytes(ini_get($k)); $add('PHP', $k, $v === -1 || $v >= $mb * 1048576 ? 'OK' : 'WARN', ini_get($k) . ' (ITOps allows 50 MB files; ' . $mb . 'M or more)'); }
    $out = "ITOps host check — web part\n" . str_repeat('=', 60) . "\n";
    foreach ($res as $r) $out .= sprintf("%-5s %-34s %s\n", $r[2], $r[1], $r[3]);
    exit($out . "\nThe full check runs from the command line: php itops-hostcheck.php\nDelete this file when you are done.\n");
}

// ───────────────────────── command line: the full check ─────────────────────────
$opt = getopt('', ['db-host::', 'db-port::', 'db-user::', 'db-name::', 'whm::', 'no-net']);
// PHP
$v = PHP_VERSION;
$add('PHP', 'Version', version_compare($v, ITOPS_HC_PHP_MIN, '<') ? 'FAIL' : (version_compare($v, '8.4.0', '>=') ? 'WARN' : 'OK'),
    $v . (version_compare($v, '8.4.0', '>=') ? ' — PHP 8.4 dropped IMAP: fine if email-to-ticket uses Microsoft 365 (Graph); 8.2 or 8.3 is the safe choice' : ' (8.2 matches the current server)'));
$add('PHP', 'Command-line PHP for cron', 'INFO', PHP_BINARY);
$need = ['pdo_mysql' => 'the database', 'curl' => 'Microsoft 365, WHM, uptime checks, vTiger bridge', 'mbstring' => 'text handling', 'openssl' => 'encrypted settings and HTTPS calls',
         'json' => 'the API', 'gd' => 'images (logos, avatars)', 'zip' => 'exports and the Word/Excel files', 'iconv' => 'email text', 'fileinfo' => 'upload type checks', 'session' => 'sign-in', 'ctype' => 'input checks'];
foreach ($need as $e => $why) $add('PHP extensions', $e, extension_loaded($e) ? 'OK' : 'FAIL', $why);
$add('PHP extensions', 'imap', extension_loaded('imap') ? 'OK' : 'WARN', extension_loaded('imap') ? 'email-to-ticket by IMAP is possible' : 'only needed if email-to-ticket reads a mailbox by IMAP — with Microsoft 365 (Graph) it is not used');
$add('PHP extensions', 'sodium', extension_loaded('sodium') ? 'OK' : 'INFO', 'optional');
$add('PHP', 'Argon2id passwords', defined('PASSWORD_ARGON2ID') ? 'OK' : 'FAIL',
    defined('PASSWORD_ARGON2ID') ? 'existing ITOps passwords will keep working' : 'this PHP cannot check Argon2id passwords: after the move nobody could sign in with a password — use a PHP built with Argon2 (the official php images are)');
$add('PHP', 'Timezone functions', @date_default_timezone_set('America/New_York') ? 'OK' : 'FAIL', 'America/New_York (each installation chooses its own zone in Settings → General)');
foreach (['memory_limit' => 256] as $k => $mb) { $x = $bytes(ini_get($k)); $add('PHP', $k, $x === -1 || $x >= $mb * 1048576 ? 'OK' : 'WARN', ini_get($k) . ' (256M or more recommended)'); }
$add('PHP', 'Web limits', 'INFO', 'upload_max_filesize / post_max_size are checked in the browser part (the web PHP has its own settings)');
// disk
$free = @disk_free_space(__DIR__); $tot = @disk_total_space(__DIR__);
if ($free !== false && $tot) { $g = $free / 1073741824; $add('Server', 'Disk free here', $g >= 10 ? 'OK' : ($g >= 3 ? 'WARN' : 'FAIL'), sprintf('%.1f GB free of %.1f GB', $g, $tot / 1073741824)); }
$cpu = 0; if (@is_readable('/proc/cpuinfo')) $cpu = substr_count((string)@file_get_contents('/proc/cpuinfo'), "\nprocessor") + (strpos((string)@file_get_contents('/proc/cpuinfo'), 'processor') === 0 ? 1 : 0);
$mem = 0; if (@is_readable('/proc/meminfo') && preg_match('/MemTotal:\s+(\d+)/', (string)@file_get_contents('/proc/meminfo'), $mm)) $mem = (int)$mm[1] / 1048576;
if ($cpu) $add('Server', 'CPUs', $cpu >= 2 ? 'OK' : 'WARN', $cpu . ' (2 or more recommended)');
if ($mem) $add('Server', 'Memory', $mem >= 3.5 ? 'OK' : ($mem >= 1.8 ? 'WARN' : 'FAIL'), sprintf('%.1f GB (4 GB recommended, 2 GB minimum)', $mem));
// database
if (isset($opt['db-host'])) {
    $h = (string)($opt['db-host'] ?: '127.0.0.1'); $u = (string)($opt['db-user'] ?? ''); $n = (string)($opt['db-name'] ?? ''); $p = (int)($opt['db-port'] ?? 3306);
    $pw = getenv('ITOPS_DB_PASS');
    if ($pw === false) { fwrite(STDOUT, 'Password for ' . $u . '@' . $h . ' (not shown, not saved): '); $tty = @is_readable('/dev/tty') && function_exists('shell_exec'); if ($tty) @shell_exec('stty -echo 2>/dev/null'); $pw = rtrim((string)fgets(STDIN), "\r\n"); if ($tty) @shell_exec('stty echo 2>/dev/null'); fwrite(STDOUT, "\n"); }
    try {
        $db = new PDO("mysql:host=$h;port=$p" . ($n !== '' ? ";dbname=$n" : '') . ';charset=utf8mb4', $u, $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
        $ver = (string)$db->query('SELECT VERSION()')->fetchColumn(); $maria = stripos($ver, 'mariadb') !== false;
        $add('Database', 'Server', $maria ? 'OK' : 'WARN', $ver . ($maria ? '' : ' — ITOps is built and tested on MariaDB; choose MariaDB (10.6 or newer) if you can'));
        if ($maria && preg_match('/^(\d+\.\d+)/', $ver, $vm)) $add('Database', 'MariaDB version', version_compare($vm[1], '10.6', '>=') ? 'OK' : 'WARN', $vm[1] . ' (10.6 or newer)');
        $cs = (string)$db->query('SELECT @@character_set_server')->fetchColumn(); $add('Database', 'Character set', $cs === 'utf8mb4' ? 'OK' : 'INFO', $cs . ' (ITOps creates its tables as utf8mb4 either way)');
        $tz = 0; try { $tz = (int)$db->query("SELECT COUNT(*) FROM mysql.time_zone_name")->fetchColumn(); } catch (Throwable $e) {}
        $add('Database', 'Time zone tables', 'INFO', $tz ? 'loaded' : 'not visible to this user (not required)');
        if ($n !== '') { try { $db->exec('CREATE TABLE itops_hostcheck_tmp (id INT PRIMARY KEY, t VARCHAR(10)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'); $db->exec('DROP TABLE itops_hostcheck_tmp'); $add('Database', 'Can create tables in ' . $n, 'OK', 'the installer / db-update can run'); }
            catch (Throwable $e) { $add('Database', 'Can create tables in ' . $n, 'FAIL', $e->getMessage()); } }
    } catch (Throwable $e) { $add('Database', 'Connect to ' . $h . ':' . $p, 'FAIL', preg_replace('/\s+/', ' ', $e->getMessage())); }
} else $add('Database', 'Connection', 'INFO', 'not tested — add --db-host=… --db-user=… --db-name=… to test it');
// network
if (!isset($opt['no-net']) && function_exists('curl_init')) {
    $get = function (string $url, bool $verify = true, int $port = 0) { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_NOBODY => false,
        CURLOPT_SSL_VERIFYPEER => $verify, CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0, CURLOPT_USERAGENT => 'ITOps-hostcheck']); if ($port) curl_setopt($ch, CURLOPT_PORT, $port);
        $b = curl_exec($ch); $r = ['code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'err' => curl_error($ch), 'errno' => curl_errno($ch), 'body' => is_string($b) ? $b : '']; curl_close($ch); return $r; };
    foreach (['https://login.microsoftonline.com/common/v2.0/.well-known/openid-configuration' => 'Microsoft 365 sign-in', 'https://graph.microsoft.com/v1.0/$metadata' => 'Microsoft 365 Graph (email, Teams, users)'] as $u => $why) {
        $r = $get($u); $add('Network', $why, $r['code'] > 0 ? 'OK' : 'FAIL', $r['code'] > 0 ? 'reachable (HTTP ' . $r['code'] . ')' : $r['err']); }
    $r = $get('https://api.ipify.org'); $ip = preg_match('/^[0-9a-f.:]+$/i', trim($r['body'])) ? trim($r['body']) : '';
    $add('Network', 'This server\'s public IP', $ip ? 'INFO' : 'WARN', $ip ? $ip . ' — allow this address in WHM (API token + firewall on port 2087)' : 'could not learn it (' . $r['err'] . ')');
    foreach ([587 => 'SMTP submission (only if email is sent by SMTP)', 25 => 'SMTP port 25 (many clouds block it; ITOps can send through Microsoft 365 instead)'] as $port => $why) {
        $e = 0; $s = @fsockopen('smtp.office365.com', $port, $e, $es, 6); $add('Network', 'Outbound port ' . $port, $s ? 'OK' : 'INFO', ($s ? 'open — ' : 'closed — ') . $why); if ($s) fclose($s); }
    if (!empty($opt['whm'])) { $wh = preg_replace('~^https?://|[:/].*$~', '', (string)$opt['whm']);
        $s = @fsockopen($wh, 2087, $e, $es, 8);
        if (!$s) $add('WHM', $wh . ':2087', 'FAIL', 'cannot connect (' . trim((string)$es) . ') — allow this server\'s IP in the WHM server\'s firewall (CSF / cPHulk / host firewall)');
        else { fclose($s); $r = $get('https://' . $wh . ':2087/json-api/version', true);
            if ($r['errno'] === 60 || $r['errno'] === 51 || stripos($r['err'], 'certificate') !== false) $add('WHM', $wh . ':2087', 'WARN', 'reachable, but its certificate is not valid for "' . $wh . '" — use the server\'s own hostname, or ITOps will need the certificate pinned (' . $r['err'] . ')');
            else $add('WHM', $wh . ':2087', $r['code'] > 0 ? 'OK' : 'WARN', $r['code'] > 0 ? 'reachable with a valid certificate (HTTP ' . $r['code'] . ' without a token is expected)' : $r['err']); } }
    else $add('WHM', 'Connection', 'INFO', 'not tested — add --whm=your-whm-hostname');
} elseif (!function_exists('curl_init')) $add('Network', 'Outbound tests', 'FAIL', 'curl is missing');
// cron
$add('Cron', 'Every-minute jobs', 'INFO', 'ITOps needs cron (every minute). In Docker, the cron runs on the server and calls PHP inside the container — see the move guide');

// report
$w = 0; foreach ($res as $r) $w = max($w, mb_strlen($r[1]));
$ord = []; foreach ($res as $r) $ord[$r[0]] ??= count($ord); $i = 0; foreach ($res as $k => $r) $res[$k][4] = $i++; usort($res, fn($x, $y) => [$ord[$x[0]], $x[4]] <=> [$ord[$y[0]], $y[4]]);   // grouped by area
$cnt = ['OK' => 0, 'WARN' => 0, 'FAIL' => 0, 'INFO' => 0]; $area = '';
echo "ITOps host check — " . date('m/d/Y g:i A T') . "\n" . str_repeat('=', 72) . "\n";
foreach ($res as $r) { if ($r[0] !== $area) { $area = $r[0]; echo "\n" . $area . "\n"; } $cnt[$r[2]]++;
    echo '  ' . str_pad($r[2], 5) . ' ' . $r[1] . str_repeat(' ', max(1, $w - mb_strlen($r[1]) + 2)) . $r[3] . "\n"; }
echo "\n" . str_repeat('=', 72) . "\n" . $cnt['OK'] . ' OK, ' . $cnt['WARN'] . ' to look at, ' . $cnt['FAIL'] . " must be fixed.\n";
echo $cnt['FAIL'] ? "Not ready yet: fix the FAIL lines first.\n" : "Ready for ITOps" . ($cnt['WARN'] ? ' (read the WARN lines)' : '') . ".\n";
echo "Also open https://YOUR-SITE/itops-hostcheck.php in a browser (within 1 hour of uploading) for the web-server part.\n";
exit($cnt['FAIL'] ? 1 : 0);
