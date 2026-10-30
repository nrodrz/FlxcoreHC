<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * BT168 — vTiger's configuration and security check.
 *
 * Two readings, both read only:
 *   1. through the vTiger bridge (build o12, action config_check): config.inc.php and config.performance.php (never the
 *      database user, password or name, never application_unique_key or the CSRF secret — only whether they are set),
 *      PHP as vTiger runs it, the permissions of the important files and folders, files left in the web root, the
 *      scheduler's tasks, the admin and unused accounts;
 *   2. from outside, the way a visitor sees vTiger: the security headers, the session cookie, http → https, and whether
 *      the log, the folders, the bridge's secret file or a file left in the web root can be downloaded (only the status
 *      code is kept, never what came back).
 * Each finding: area, setting, file, ours, recommended, level (risk | warn | ok | info), why, how.
 * The last result is kept in opsh_settings 'vtiger_config_check' (nothing secret is in it).
 */
if (!function_exists('opsh_vtc_run')) {

    /** The important vTiger 7.x files: what each does and what to keep in mind (shown in the app and the guide). */
    function opsh_vtc_files_doc(): array {
        return [
            ['config.inc.php', 'Main settings: the database connection, the site address (site_URL), folders, upload size and blocked file types, who may export, time zone, the application key.', 'Holds the database password. Permissions 640 (or 644); never world-writable; keep a copy outside the web root before editing.'],
            ['config.performance.php', 'Performance and debugging switches ($PERFORMANCE_CONFIG): debug logging, SQL logging, list view page counts, record navigation.', 'Debug and SQL logging off in production: they fill logs/ and write queries to disk.'],
            ['config.csrf-secret.php', 'The secret vTiger\'s CSRF protection signs its form tokens with (made at installation).', 'Must exist and be long and random; never shared; permissions 640.'],
            ['config.php', 'Loads config.inc.php (and the other config files) for vTiger\'s pages.', 'Not edited by hand.'],
            ['config.template.php', 'The installer\'s template for config.inc.php.', 'Not used after installation; harmless, can stay.'],
            ['log4php.properties', 'How much vTiger writes to its own logs (log4php.rootLogger = FATAL, ERROR, WARN, INFO or DEBUG).', 'FATAL or ERROR in production; DEBUG only while chasing a problem.'],
            ['.htaccess (web root)', 'Apache / LiteSpeed rules for the vTiger folder: security headers, files that must not be served, the bridge\'s secret (SetEnv).', 'Turn off folder listings, deny log / backup / dump files, add the security headers (see the recommended block).'],
            ['storage/ (and storage/.htaccess)', 'Every attachment and document uploaded to vTiger.', 'Must never be served directly: storage/.htaccess denies it. Folder 755, never 777.'],
            ['user_privileges/', 'Cached permission files vTiger builds for each user and the sharing rules.', 'Written by vTiger; 755 for the folder; never 777.'],
            ['cache/, test/templates_c/', 'Temporary files, imports and the compiled page templates.', 'Writable by PHP (755 under PHP-FPM as the account); never 777.'],
            ['logs/', 'vTiger\'s own logs (vtigercrm.log and others) when logging is on.', 'Must not be downloadable from the web (add logs/.htaccess with "Require all denied"); watch its size.'],
            ['vtigercron.php', 'The scheduler: workflows, scheduled reports, mail scanner, reminders, recurring invoices. Run by a cron job every 15 minutes.', 'Run from cron with PHP on the command line, not from the web.'],
            ['opsh-bridge.php / opsh-bridge.secret', 'This app\'s bridge to vTiger and, if used, the file holding its secret.', 'The secret preferably in .htaccess (SetEnv OPSH_BRIDGE_SECRET); a secret file must be 600 and denied from the web.'],
        ];
    }

    function opsh_vtc_bool($v): ?bool { if ($v === null) return null; if (is_bool($v)) return $v; $s = strtolower(trim((string)$v)); return in_array($s, ['1', 'on', 'yes', 'true', 'stdout', 'stderr'], true) ? true : ($s === '' || in_array($s, ['0', 'off', 'no', 'false', 'none'], true) ? false : true); }
    function opsh_vtc_bytes($v): int { $s = strtolower(trim((string)$v)); if ($s === '' || $s === '-1') return -1; $n = (float)$s; $u = substr($s, -1);
        return (int)($u === 'g' ? $n * 1073741824 : ($u === 'm' ? $n * 1048576 : ($u === 'k' ? $n * 1024 : $n))); }
    function opsh_vtc_size(int $b): string { return $b < 0 ? 'no limit' : ($b >= 1073741824 ? round($b / 1073741824, 1) . ' GB' : ($b >= 1048576 ? round($b / 1048576, 1) . ' MB' : ($b >= 1024 ? round($b / 1024) . ' KB' : $b . ' bytes'))); }

    /** One GET from outside (no redirects followed unless asked); keeps only the status, the headers and up to 2 KB. */
    function opsh_vtc_fetch(string $url, bool $follow = false): array {
        $hdr = []; $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'OpsHub-config-check', CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$hdr) { $p = strpos($h, ':'); if ($p !== false) { $hdr[] = [strtolower(trim(substr($h, 0, $p))), trim(substr($h, $p + 1))]; } elseif (stripos($h, 'HTTP/') === 0) { $hdr = []; } return strlen($h); },
            CURLOPT_WRITEFUNCTION => function ($c, $d) use (&$body) { $body .= $d; return strlen($body) > 2048 ? 0 : strlen($d); }]);
        curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_errno($ch) && strlen($body) <= 2048 ? curl_error($ch) : ''; $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); $eff = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); curl_close($ch);
        return ['code' => $code, 'headers' => $hdr, 'body' => substr($body, 0, 2048), 'type' => $type, 'url' => $eff, 'error' => $err];
    }
    function opsh_vtc_h(array $r, string $name): array { $o = []; foreach ($r['headers'] as $h) if ($h[0] === $name) $o[] = $h[1]; return $o; }

    /** Run both readings, make the findings, keep them. */
    function opsh_vtc_run(PDO $db): array {
        require_once __DIR__ . '/VTigerProvision.php'; require_once __DIR__ . '/VTigerClient.php';
        $prov = opsh_vtiger_cfg($db)['prov'] ?? [];
        $out = ['at' => date('Y-m-d H:i:s'), 'bridge' => null, 'error' => null, 'web' => null, 'checks' => [], 'vtiger' => '', 'php' => ''];
        if (!opsh_vtiger_bridge_ready($prov)) { $out['error'] = 'The vTiger bridge is not set up (Integrations » vTiger provisioning): the check reads vTiger through it.'; return opsh_vtc_keep($db, $out); }
        $r = opsh_vtiger_bridge_call($prov, 'config_check');
        if (empty($r['ok'])) {
            $e = (string)($r['error'] ?? 'no answer');
            $out['error'] = stripos($e, 'Unknown action') !== false ? 'The bridge on the vTiger server is an older build: upload vtiger-bridge/opsh-bridge.php of this release (build o12) over the old one (same secret), then run the check again.' : 'The bridge answered: ' . mb_substr($e, 0, 200);
            return opsh_vtc_keep($db, $out);
        }
        $out['bridge'] = (string)($r['build'] ?? ''); $out['vtiger'] = (string)($r['vtiger'] ?? ''); $out['php'] = (string)($r['php']['version'] ?? '');
        // from outside: the web root is where the bridge is
        $base = preg_replace('~/[^/]*$~', '/', (string)$prov['bridge_url']);
        $web = ['base' => $base, 'paths' => []];
        $root = opsh_vtc_fetch($base . 'index.php', true); $web['root'] = ['code' => $root['code'], 'url' => $root['url'], 'error' => $root['error'],
            'hsts' => opsh_vtc_h($root, 'strict-transport-security'), 'xfo' => opsh_vtc_h($root, 'x-frame-options'), 'csp' => opsh_vtc_h($root, 'content-security-policy'),
            'xcto' => opsh_vtc_h($root, 'x-content-type-options'), 'server' => opsh_vtc_h($root, 'server'), 'powered' => opsh_vtc_h($root, 'x-powered-by'), 'referrer' => opsh_vtc_h($root, 'referrer-policy'),
            'cookies' => array_map(fn($c) => ['name' => strtok($c, '='), 'secure' => (bool)preg_match('/;\s*secure/i', $c), 'httponly' => (bool)preg_match('/;\s*httponly/i', $c), 'samesite' => preg_match('/;\s*samesite=(\w+)/i', $c, $m) ? $m[1] : ''], opsh_vtc_h($root, 'set-cookie'))];
        if (stripos($base, 'https://') === 0) { $h = opsh_vtc_fetch('http://' . substr($base, 8)); $loc = opsh_vtc_h($h, 'location'); $web['http'] = ['code' => $h['code'], 'to_https' => in_array($h['code'], [301, 302, 307, 308], true) && $loc && stripos($loc[0], 'https://') === 0, 'error' => $h['error']]; }
        $probe = ['logs/vtigercrm.log' => 'file', 'logs/' => 'dir', 'storage/' => 'dir', 'cache/' => 'dir', 'user_privileges/' => 'dir', 'test/' => 'dir', 'opsh-bridge.secret' => 'file', '.git/HEAD' => 'git', 'config.inc.php.bak' => 'file', 'log4php.properties' => 'file'];
        foreach ((array)($r['stray'] ?? []) as $s) { $n = (string)($s['name'] ?? ''); if ($n !== '' && preg_match('/^[\w.\-~]+$/', $n) && !preg_match('/^(error_log|core(\.\d+)?)$/i', $n) && count($probe) < 40) $probe[$n . (!empty($s['dir']) ? '/' : '')] = !empty($s['dir']) ? 'dir' : 'file'; }
        foreach ($probe as $p => $kind) {
            $x = opsh_vtc_fetch($base . $p);
            $open = $x['code'] === 200 && ($kind === 'dir' ? stripos($x['body'], 'Index of') !== false : ($kind === 'git' ? strpos($x['body'], 'ref:') === 0 : ($x['body'] !== '' && stripos($x['type'], 'text/html') === false && stripos($x['body'], '<html') === false)));
            $web['paths'][$p] = ['code' => $x['code'], 'open' => $open, 'kind' => $kind];
        }
        $out['web'] = $web;
        $out['checks'] = opsh_vtc_rules($r, $web);
        return opsh_vtc_keep($db, $out);
    }

    function opsh_vtc_keep(PDO $db, array $out): array {
        $n = ['risk' => 0, 'warn' => 0, 'ok' => 0, 'info' => 0]; foreach ($out['checks'] as $c) $n[$c['level']]++; $out['counts'] = $n;
        $j = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $db->prepare('INSERT INTO opsh_settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)')->execute(['vtiger_config_check', $j]);
        return $out;
    }
    function opsh_vtc_last(PDO $db): ?array { $q = $db->prepare('SELECT sval FROM opsh_settings WHERE skey = ?'); $q->execute(['vtiger_config_check']); $v = json_decode((string)$q->fetchColumn(), true); return is_array($v) ? $v : null; }

    /** The findings. */
    function opsh_vtc_rules(array $r, array $web): array {
        $C = []; $add = function ($area, $item, $file, $ours, $rec, $level, $why, $how = '') use (&$C) { $C[] = ['area' => $area, 'item' => $item, 'file' => $file, 'ours' => (string)$ours, 'rec' => (string)$rec, 'level' => $level, 'why' => $why, 'how' => $how]; };
        $cfg = (array)($r['config'] ?? []); $php = (array)($r['php'] ?? []); $perf = $r['performance'] ?? null; $files = (array)($r['files'] ?? []);
        $yn = fn($b) => $b === null ? 'not set' : ($b ? 'On' : 'Off');
        $https = stripos((string)($cfg['site_URL'] ?? ''), 'https://') === 0;

        // ---- from outside (what anyone on the internet sees)
        $W = 'From the internet'; $rt = $web['root'] ?? [];
        if (!empty($rt['error']) && empty($rt['code'])) $add($W, 'vTiger answers from outside', $web['base'] ?? '', 'no answer: ' . $rt['error'], 'answers', 'info', 'This app could not reach vTiger the way a visitor does, so the checks from outside were skipped.');
        else {
            $add($W, 'Address', 'config.inc.php · site_URL', (string)($cfg['site_URL'] ?? ''), 'https://…', $https ? 'ok' : 'risk', 'Over plain http, passwords and every record travel unencrypted.', 'Install a certificate (WHM » AutoSSL) and set $site_URL to the https:// address.');
            if (isset($web['http'])) $add($W, 'http:// sends to https://', '.htaccess', $web['http']['to_https'] ? 'yes' : 'no (HTTP ' . $web['http']['code'] . ')', 'yes (301)', $web['http']['to_https'] ? 'ok' : 'warn', 'Someone typing the address without https:// should land on the encrypted site.', "RewriteEngine On\nRewriteCond %{HTTPS} !=on\nRewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]");
            $add($W, 'Strict-Transport-Security (HSTS)', '.htaccess', $rt['hsts'] ? $rt['hsts'][0] : 'missing', 'max-age=31536000', $rt['hsts'] ? 'ok' : 'warn', 'Tells browsers to always use https for this site, so a first http request cannot be intercepted.', 'Header always set Strict-Transport-Security "max-age=31536000"');
            $fa = $rt['xfo'] || preg_grep('/frame-ancestors/i', $rt['csp']);
            $add($W, 'X-Frame-Options', '.htaccess', $rt['xfo'] ? $rt['xfo'][0] : ($fa ? 'CSP frame-ancestors' : 'missing'), 'SAMEORIGIN', $fa ? 'ok' : 'warn', 'Stops another site from showing vTiger inside a frame to trick people into clicking (clickjacking).', 'Header always set X-Frame-Options "SAMEORIGIN"');
            $add($W, 'X-Content-Type-Options', '.htaccess', $rt['xcto'] ? $rt['xcto'][0] : 'missing', 'nosniff', $rt['xcto'] ? 'ok' : 'warn', 'Stops browsers from running an uploaded file as a script because it "looks like" one.', 'Header always set X-Content-Type-Options "nosniff"');
            $add($W, 'Referrer-Policy', '.htaccess', $rt['referrer'] ? $rt['referrer'][0] : 'missing', 'strict-origin-when-cross-origin', $rt['referrer'] ? 'ok' : 'info', 'Keeps record addresses (with ids) from being sent to other sites when someone clicks a link.', 'Header always set Referrer-Policy "strict-origin-when-cross-origin"');
            $add($W, 'X-Powered-By (PHP version shown)', 'PHP · expose_php', $rt['powered'] ? $rt['powered'][0] : 'hidden', 'hidden', $rt['powered'] ? 'warn' : 'ok', 'Tells attackers the exact PHP version to look up.', 'expose_php = Off (WHM » MultiPHP INI Editor, the PHP version vTiger uses).');
            foreach ($rt['cookies'] as $ck) if (preg_match('/^PHPSESSID$/i', (string)$ck['name'])) {
                $bad = []; if ($https && !$ck['secure']) $bad[] = 'Secure'; if (!$ck['httponly']) $bad[] = 'HttpOnly';
                $add($W, 'Session cookie flags', 'PHP · session.cookie_*', trim(($ck['secure'] ? 'Secure ' : '') . ($ck['httponly'] ? 'HttpOnly ' : '') . ($ck['samesite'] ? 'SameSite=' . $ck['samesite'] : '')) ?: 'no flags', 'Secure HttpOnly SameSite=Lax', $bad ? 'warn' : 'ok', 'Without them the sign-in cookie can travel over http or be read by a script on the page.', 'session.cookie_secure = On, session.cookie_httponly = On, session.cookie_samesite = Lax (MultiPHP INI Editor).');
                break; }
            $lab = ['logs/vtigercrm.log' => ['vTiger\'s log', 'risk', 'The log can hold queries, user names and errors.', "Create logs/.htaccess with:\nRequire all denied"],
                    'logs/' => ['logs/ folder listing', 'risk', 'Anyone can list and download the logs.', "Create logs/.htaccess with:\nRequire all denied"],
                    'storage/' => ['storage/ (attachments) listing', 'risk', 'Every uploaded document could be listed and downloaded without signing in.', "storage/.htaccess must contain:\nRequire all denied\n(vTiger ships it as \"deny from all\")"],
                    'cache/' => ['cache/ folder listing', 'warn', 'Imports and temporary files could be listed.', 'Options -Indexes in the web root .htaccess'],
                    'user_privileges/' => ['user_privileges/ listing', 'warn', 'The permission files\' names (one per user id) could be listed.', 'Options -Indexes in the web root .htaccess'],
                    'test/' => ['test/ folder listing', 'warn', 'Compiled templates and test files could be listed.', 'Options -Indexes in the web root .htaccess'],
                    'opsh-bridge.secret' => ['The bridge\'s secret file', 'risk', 'Whoever downloads it can sign requests to the bridge (create users, change passwords).', "Move the secret to .htaccess (SetEnv OPSH_BRIDGE_SECRET …) and delete the file, or deny it:\n<Files \"opsh-bridge.secret\">\n  Require all denied\n</Files>"],
                    '.git/HEAD' => ['.git folder', 'risk', 'The whole source history (and any password ever committed) can be downloaded.', "Move .git out of the web root, or deny it:\nRedirectMatch 404 /\\.git"],
                    'config.inc.php.bak' => ['config.inc.php.bak', 'risk', 'A copy of the settings with the database password, served as plain text.', 'Delete it, or keep copies outside the web root.'],
                    'log4php.properties' => ['log4php.properties', 'info', 'Shows how vTiger logs; not secret, but no reason to serve it.', "<Files \"log4php.properties\">\n  Require all denied\n</Files>"]];
            foreach (($web['paths'] ?? []) as $p => $x) {
                if ($x['code'] === 0) continue;
                [$label, $lvl, $why, $how] = $lab[$p] ?? ['File left in the web root: ' . $p, 'risk', 'Backups, dumps, archives and test pages left in the web root can be downloaded or run by anyone.', 'Delete it from the web root (keep backups outside public_html).'];
                if (!isset($lab[$p]) || $x['open']) $add($W, $label, $p, $x['open'] ? 'downloadable (HTTP 200)' : 'not served (HTTP ' . $x['code'] . ')', 'not served (403 / 404)', $x['open'] ? $lvl : 'ok', $why, $x['open'] ? $how : '');
                elseif (in_array($p, ['logs/vtigercrm.log', 'storage/', 'opsh-bridge.secret', 'logs/'], true)) $add($W, $label, $p, 'not served (HTTP ' . $x['code'] . ')', 'not served (403 / 404)', 'ok', $why);
            }
        }

        // ---- config.inc.php
        $F = 'config.inc.php';
        if (isset($cfg['root_directory_matches']) && $cfg['root_directory_matches'] === false) $add($F, '$root_directory', $F, (string)($cfg['root_directory'] ?? ''), 'the folder vTiger is in', 'warn', 'Left from another server or folder: uploads, imports and the cache go to the wrong place.', 'Set $root_directory to the full path of the vTiger folder, ending with /.');
        $uk = (array)($cfg['application_unique_key'] ?? []);
        $add($F, '$application_unique_key', $F, empty($uk['set']) ? 'not set' : 'set (' . (int)$uk['length'] . ' characters)', 'set, 32+ random characters', !empty($uk['set']) && (int)$uk['length'] >= 32 ? 'ok' : 'risk', 'Protects vTiger\'s own scheduled and portal requests; a short or known key lets them be forged.', 'Set it once to a long random value (php -r "echo md5(random_bytes(32));"); changing it later signs everyone out.');
        $db = (array)($cfg['db'] ?? []);
        if (!empty($db['user_is_root'])) $add($F, 'Database user', $F . ' · $dbconfig', 'root', 'a user for this database only', 'risk', 'vTiger signing in to MySQL as root can read and change every database on the server.', 'In cPanel » MySQL Databases, create a user with ALL PRIVILEGES on the vTiger database only, and use it in $dbconfig.');
        else $add($F, 'Database user', $F . ' · $dbconfig', 'not root', 'a user for this database only', 'ok', 'vTiger\'s MySQL user should reach its own database only.');
        if (isset($db['password_set']) && !$db['password_set']) $add($F, 'Database password', $F . ' · $dbconfig', 'empty', 'set', 'risk', 'A MySQL user without a password.', 'Set a password for the user in cPanel » MySQL Databases and in $dbconfig.');
        $bad = array_map('strtolower', (array)($cfg['upload_badext'] ?? []));
        if (isset($cfg['upload_badext'])) {
            $must = ['php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'pht', 'phps', 'shtml', 'cgi', 'pl', 'py', 'asp', 'aspx', 'jsp', 'exe', 'sh', 'bat', 'js', 'html', 'htm', 'svg', 'htaccess'];
            $miss = array_values(array_diff($must, $bad)); $core = array_intersect($miss, ['php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'pht', 'htaccess']);
            $add($F, '$upload_badext (file types refused)', $F, count($bad) . ' types' . ($miss ? '; missing: ' . implode(', ', $miss) : ''), 'every script type refused', $miss ? ($core ? 'risk' : 'warn') : 'ok', 'An uploaded .phtml, .phar or .htaccess file can turn an attachment into code the server runs.', $miss ? 'Add to the $upload_badext array: \'' . implode("', '", $miss) . '\'' : '');
        }
        if (isset($cfg['allow_exports'])) $add($F, '$allow_exports', $F, (string)$cfg['allow_exports'], "'admin' (or 'all' if every user needs it)", $cfg['allow_exports'] === 'all' ? 'warn' : 'ok', 'With \'all\', anyone with a vTiger account can export whole modules (every contact, every deal) to a file.', "\$allow_exports = 'admin';   // or 'none'; profiles can still limit Export per module");
        $um = isset($cfg['upload_maxsize']) ? (int)$cfg['upload_maxsize'] : null; $pu = opsh_vtc_bytes($php['upload_max_filesize'] ?? ''); $pp = opsh_vtc_bytes($php['post_max_size'] ?? '');
        if ($um !== null) { $lim = min($pu < 0 ? PHP_INT_MAX : $pu, $pp < 0 ? PHP_INT_MAX : $pp);
            $add($F, '$upload_maxsize vs PHP', $F . ' + PHP', opsh_vtc_size($um) . ' (PHP allows ' . opsh_vtc_size($lim === PHP_INT_MAX ? -1 : $lim) . ')', 'vTiger ≤ upload_max_filesize ≤ post_max_size', $um > $lim ? 'warn' : 'ok', 'When PHP allows less than vTiger, large attachments fail with a blank or confusing error.', 'Raise upload_max_filesize and post_max_size (MultiPHP INI Editor) to at least ' . opsh_vtc_size($um) . ', or lower $upload_maxsize.'); }
        if (isset($cfg['default_timezone'])) $add($F, '$default_timezone', $F, (string)$cfg['default_timezone'], 'your time zone (e.g. America/New_York)', (string)$cfg['default_timezone'] === '' ? 'warn' : 'info', 'The time zone vTiger stores and shows times in when a user has none; it should match PHP\'s date.timezone.');
        if (isset($cfg['list_max_entries_per_page'])) $add($F, '$list_max_entries_per_page', $F, (string)$cfg['list_max_entries_per_page'], '20–50', (int)$cfg['list_max_entries_per_page'] > 100 ? 'warn' : 'ok', 'Very long list pages load slowly and weigh on the database.');
        if (isset($cfg['php_max_execution_time'])) $add($F, '$php_max_execution_time', $F, (string)$cfg['php_max_execution_time'], '0 (PHP\'s own limit)', 'info', 'vTiger\'s own time limit for a page; 0 leaves it to PHP\'s max_execution_time.');
        // CSRF and logging
        $cs = $r['csrf'] ?? null;
        $add($F, 'CSRF secret', 'config.csrf-secret.php', $cs === null ? 'file missing' : (!empty($cs['set']) ? 'set (' . (int)$cs['length'] . ' characters)' : 'empty'), 'set, long and random', $cs !== null && !empty($cs['set']) && (int)$cs['length'] >= 32 ? 'ok' : 'risk', 'vTiger\'s forms are protected against forged requests with this secret.', 'If missing, vTiger creates it on the next page load when the folder is writable; check after loading vTiger once.');
        $ll = $r['log_level'] ?? null;
        if ($ll !== null) $add('Logging', 'log4php.rootLogger', 'log4php.properties', $ll, 'FATAL (or ERROR)', in_array($ll, ['FATAL', 'ERROR', 'OFF'], true) ? 'ok' : 'warn', 'DEBUG and INFO write a line for almost everything vTiger does: logs/ grows fast and holds queries.', 'log4php.rootLogger=FATAL,A1');
        // config.performance.php
        $P = 'config.performance.php';
        if (is_array($perf)) {
            $rec = ['LOG4PHP_DEBUG' => [false, 'risk', 'Debug logging writes every query and much more to logs/.'], 'SQL_LOG_INCLUDE_CALLER' => [false, 'warn', 'Adds the calling code to every logged query: slow and large.'],
                    'DB_DEFAULT_CHARSET_UTF8' => [true, 'warn', 'Without it, accents and other languages can be stored wrong.'], 'LISTVIEW_COMPUTE_PAGE_COUNT' => [false, 'warn', 'Counting every page of a list on each view is slow on large modules.'],
                    'LISTVIEW_DEFAULT_SORTING' => [false, 'info', 'Sorting every list by default costs time on large modules.'], 'DETAILVIEW_RECORD_NAVIGATION' => [true, 'info', 'The previous / next record buttons on a record.']];
            foreach ($perf as $k => $v) { if (isset($rec[$k])) { $b = opsh_vtc_bool($v); $okv = $b === $rec[$k][0];
                    $add($P, $k, $P, is_bool($v) || $v === null ? $yn($b) : (string)$v, $rec[$k][0] ? 'true' : 'false', $okv ? 'ok' : $rec[$k][1], $rec[$k][2], $okv ? '' : "'" . $k . "' => " . ($rec[$k][0] ? 'true' : 'false') . ',');
                } else $add($P, $k, $P, is_bool($v) ? ($v ? 'true' : 'false') : (string)$v, '—', 'info', 'Kept as vTiger set it.'); }
        } else $add($P, 'config.performance.php', $P, 'not read', 'present', 'info', 'The file was not found or could not be read.');

        // ---- PHP as vTiger runs it
        $H = 'PHP (vTiger)'; $pv = (string)($php['version'] ?? ''); $mm = (float)preg_replace('/^(\d+\.\d+).*/', '$1', $pv);
        $eol = ['7.2' => '11/30/2020', '7.3' => '12/06/2021', '7.4' => '11/28/2022', '8.0' => '11/26/2023', '8.1' => '12/31/2025'];
        $k2 = sprintf('%.1f', $mm);
        $add($H, 'PHP version', 'WHM » MultiPHP Manager', $pv . ' (' . (string)($php['sapi'] ?? '') . ')', '8.2 or later (check vTiger\'s support first)', isset($eol[$k2]) || $mm < 7.2 ? 'risk' : 'ok', isset($eol[$k2]) ? 'PHP ' . $k2 . ' stopped getting security fixes on ' . $eol[$k2] . '.' : 'Supported PHP versions get security fixes.', isset($eol[$k2]) ? 'Plan the move: test vTiger on a copy with the newer PHP first (vTiger 7.4 and its extensions must support it).' : '');
        if (preg_match('/cgi/i', (string)($php['sapi'] ?? '')) && !preg_match('/fpm/i', (string)$php['sapi'])) $add($H, 'PHP handler', 'WHM » MultiPHP Manager', (string)$php['sapi'], 'PHP-FPM', 'warn', 'CGI starts a new PHP process for every request: slower and heavier (see the guide, 9.4).', 'Turn on PHP-FPM for the vTiger domain (MultiPHP Manager » PHP-FPM).');
        $set = function ($k, $want, $lvl, $why, $how = '') use ($add, $H, $php, $yn) { if (!array_key_exists($k, $php)) return; $b = opsh_vtc_bool($php[$k]); $okv = $b === $want;
            $add($H, $k, 'PHP ini', $yn($b), $want ? 'On' : 'Off', $okv ? 'ok' : $lvl, $why, $okv ? '' : ($how ?: $k . ' = ' . ($want ? 'On' : 'Off'))); };
        $set('display_errors', false, 'risk', 'Errors shown on the page reveal file paths, queries and sometimes data to whoever is looking.');
        $set('display_startup_errors', false, 'warn', 'Same as display_errors, for errors while PHP starts.');
        $set('log_errors', true, 'warn', 'Errors should go to the error log, where you can read them, not to the page.');
        $set('expose_php', false, 'warn', 'Announces the PHP version in every answer.');
        $set('allow_url_include', false, 'risk', 'Lets an include pull code from another site: a classic way in.');
        $set('session.cookie_httponly', true, 'warn', 'Scripts on the page cannot read the sign-in cookie.');
        if ($https) $set('session.cookie_secure', true, 'warn', 'The sign-in cookie is only sent over https.');
        $set('session.use_strict_mode', true, 'warn', 'PHP refuses session ids it did not create (session fixation).');
        $set('session.use_only_cookies', true, 'risk', 'Session ids never travel in addresses (where they end up in logs and referrers).');
        $min = function ($k, $want, $lvl, $why) use ($add, $H, $php) { if (!array_key_exists($k, $php)) return; $v = (string)$php[$k];
            $isB = in_array($k, ['memory_limit', 'upload_max_filesize', 'post_max_size'], true); $n = $isB ? opsh_vtc_bytes($v) : (int)$v; $okv = $isB ? ($n < 0 || $n >= opsh_vtc_bytes($want)) : ((int)$v === 0 && $k === 'max_execution_time' ? true : (int)$v >= (int)$want);
            $add($H, $k, 'PHP ini', $v, '≥ ' . $want, $okv ? 'ok' : $lvl, $why, $okv ? '' : $k . ' = ' . $want); };
        $min('max_execution_time', '600', 'warn', 'Imports, mass updates and reports stop half way when PHP cuts them off (vTiger\'s installer asks for 600).');
        $min('memory_limit', '256M', 'warn', 'Large lists, reports and PDFs need memory; below 256M they fail with a blank page.');
        $min('max_input_vars', '10000', 'warn', 'Saving Roles, Profiles, picklists or large layouts sends thousands of fields; PHP silently drops the ones above the limit and the save loses settings.');
        $min('post_max_size', '32M', 'info', 'The largest form or upload vTiger can receive.');
        if (array_key_exists('date.timezone', $php)) { $pt = (string)$php['date.timezone']; $vt = (string)($cfg['default_timezone'] ?? ''); $diff = $pt !== '' && $vt !== '' && $pt !== $vt;
            $add($H, 'date.timezone', 'PHP ini', $pt !== '' ? $pt : 'not set', $vt !== '' ? $vt . ' (as vTiger)' : 'your time zone', $pt === '' ? 'warn' : ($diff ? 'info' : 'ok'), $pt === '' ? 'Without it PHP guesses (UTC), and times in logs and scheduled jobs are off.' : ($diff ? 'PHP and vTiger use different time zones: times written by PHP (logs, the scheduler) differ from the ones vTiger shows.' : 'PHP and vTiger use the same time zone.'), $pt === '' || $diff ? 'date.timezone = ' . ($vt !== '' ? $vt : 'America/New_York') : ''); }
        if (array_key_exists('opcache.enable', $php)) $add($H, 'opcache.enable', 'PHP ini', $yn(opsh_vtc_bool($php['opcache.enable'])), 'On', opsh_vtc_bool($php['opcache.enable']) ? 'ok' : 'warn', 'Keeps vTiger\'s compiled code in memory: pages load faster with less CPU (see the guide, 9.4).');
        if (array_key_exists('disable_functions', $php)) { $df = array_map('trim', explode(',', strtolower((string)$php['disable_functions']))); $risky = array_values(array_diff(['exec', 'passthru', 'shell_exec', 'system', 'proc_open', 'popen'], $df));
            $add($H, 'disable_functions', 'PHP ini', $risky ? 'allowed: ' . implode(', ', $risky) : 'shell functions disabled', 'exec, passthru, shell_exec, system, proc_open, popen disabled', $risky ? 'info' : 'ok', 'If someone ever gets code onto the server, these let it run commands. vTiger itself does not need them; test an extension (PDF, backup) after disabling.', $risky ? 'disable_functions = ' . implode(',', $risky) : ''); }

        // ---- files and folders
        $D = 'Files and folders';
        foreach (['config.inc.php', 'config.csrf-secret.php', 'config.performance.php', 'opsh-bridge.secret'] as $f) if (isset($files[$f])) { $x = $files[$f];
            $lvl = $x['world_w'] ? 'risk' : ($x['group_w'] ? 'warn' : (in_array(substr($x['mode'], -1), ['4', '5', '6', '7'], true) && $f !== 'config.performance.php' ? 'info' : 'ok'));
            $add($D, $f, $f, $x['mode'], $f === 'opsh-bridge.secret' ? '0600' : '0640 (or 0644)', $lvl, $f === 'opsh-bridge.secret' ? 'Holds the bridge\'s secret.' : 'Holds settings or secrets; others on the server should not change it, and with PHP running as the account (PHP-FPM / suPHP) it need not be readable by them either.', 'chmod ' . ($f === 'opsh-bridge.secret' ? '600' : '640') . ' ' . $f . '   (WHM » Terminal or cPanel » File Manager » Permissions)'); }
        foreach (['storage', 'cache', 'logs', 'user_privileges', 'test', 'test/templates_c', 'layouts', 'modules'] as $f) if (isset($files[$f])) { $x = $files[$f];
            $add($D, $f . '/', $f, $x['mode'], '0755', $x['world_w'] ? 'risk' : 'ok', 'A world-writable (777) folder lets any other account or process on the server drop files into vTiger.', $x['world_w'] ? 'chmod 755 ' . $f . ' (and find ' . $f . ' -type d -perm -0002 -exec chmod 755 {} \\;)' : ''); }
        $add($D, 'storage/.htaccess', 'storage/.htaccess', isset($files['storage/.htaccess']) ? 'present' : 'missing', 'present (deny from all)', isset($files['storage/.htaccess']) ? 'ok' : 'risk', 'It is what stops attachments from being downloaded directly.', "Create storage/.htaccess with:\nRequire all denied");
        $add($D, '.htaccess (web root)', '.htaccess', isset($files['.htaccess']) ? 'present' : 'missing', 'present, with the recommended block', isset($files['.htaccess']) ? 'info' : 'warn', 'Where the security headers, the denied files and the bridge\'s secret go.', 'See "Recommended .htaccess block" below.');
        if (isset($files['opsh-bridge.secret'])) $add($D, 'Bridge secret kept in a file', 'opsh-bridge.secret', 'file', 'SetEnv in .htaccess', 'info', 'A file next to the bridge is fine only if it is 600 and denied from the web; .htaccess is never served.', 'SetEnv OPSH_BRIDGE_SECRET <the same secret>   (then delete opsh-bridge.secret)');
        foreach (['install', 'migrate'] as $f) if (isset($files[$f])) $add($D, $f . '/', $f, 'present', 'removed after installing / upgrading', 'warn', 'Installer and migration pages left in place can be run again.', 'Move ' . $f . '/ out of the web root once vTiger works.');
        if (isset($files['logs/vtigercrm.log']) && ($files['logs/vtigercrm.log']['size'] ?? 0) > 104857600) $add($D, 'logs/vtigercrm.log size', 'logs/vtigercrm.log', opsh_vtc_size((int)$files['logs/vtigercrm.log']['size']), 'under 100 MB', 'warn', 'A huge log fills the disk and usually means debug logging is on.', 'Turn debug logging off, then empty the file:  : > logs/vtigercrm.log');
        foreach ((array)($r['stray'] ?? []) as $s) { $n = (string)$s['name']; if (isset($web['paths'][$n]) || isset($web['paths'][$n . '/'])) continue;
            $low = preg_match('/^(error_log|core(\.\d+)?)$/i', $n); $add($D, 'Left in the web root: ' . $n, $n, ($s['dir'] ? 'folder' : opsh_vtc_size((int)($s['size'] ?? 0))) . ', ' . date('m/d/Y', strtotime((string)$s['mtime'])), 'not in the web root', $low ? 'info' : 'warn', $low ? 'PHP\'s error log or a crash dump in the web root; read it, then remove it.' : 'Backups, dumps, archives and test pages belong outside public_html.', 'Move it out of the web root or delete it.'); }

        // ---- scheduler
        $S = 'Scheduler (cron)'; $cron = $r['cron'] ?? null;
        if (is_array($cron) && $cron) {
            $act = array_values(array_filter($cron, fn($t) => (int)$t['status'] !== 0)); $now = time(); $stale = []; $stuck = []; $newest = 0;
            foreach ($act as $t) { $newest = max($newest, (int)$t['laststart']); $lim = max(2 * (int)$t['frequency'], 7200);
                if ((int)$t['laststart'] > 0 && $now - (int)$t['laststart'] > $lim) $stale[] = $t['name'];
                if ((int)$t['status'] === 2 && (int)$t['laststart'] > 0 && $now - (int)$t['laststart'] > 86400) $stuck[] = $t['name']; }
            $add($S, 'vtigercron.php runs', 'cPanel » Cron Jobs', $newest ? 'last task started ' . date('m/d/Y g:i A', $newest) : 'never ran', 'every 15 minutes', $newest && $now - $newest < 3 * 3600 ? 'ok' : 'risk', 'Workflows, scheduled reports, reminders, the mail scanner and recurring invoices only run when the scheduler does.', "cPanel » Cron Jobs, every 15 minutes:\ncd " . '/home/ACCOUNT/public_html/VTIGER-FOLDER' . " && php -f vtigercron.php > /dev/null 2>&1");
            if ($stale) $add($S, 'Tasks late', 'Settings » Scheduler', implode(', ', array_slice($stale, 0, 8)) . (count($stale) > 8 ? ' …' : ''), 'each within twice its frequency', 'warn', 'These active tasks have not started for longer than twice their frequency.', 'vTiger » Settings » Scheduler: check their frequency; the cron job runs every 15 minutes.');
            if ($stuck) $add($S, 'Tasks stuck "running"', 'vtiger_cron_task', implode(', ', $stuck), 'none', 'warn', 'A task marked running for over a day is not run again until it is reset.', 'vTiger » Settings » Scheduler: edit and save the task (or set its status back to active).');
            $add($S, 'Active tasks', 'Settings » Scheduler', count($act) . ' of ' . count($cron), '—', 'info', 'The scheduled tasks vTiger has turned on.');
        } elseif ($cron === null) $add($S, 'Scheduler', 'vtiger_cron_task', 'not read', '—', 'info', 'The scheduler\'s table could not be read.');

        // ---- accounts
        $A = 'Accounts'; $acc = (array)($r['accounts'] ?? []);
        if (empty($acc['error'])) {
            $adm = (array)($acc['admins'] ?? []);
            $add($A, 'Administrators', 'Settings » Users', count($adm) . ': ' . implode(', ', array_map(fn($a) => $a['user_name'], array_slice($adm, 0, 10))), '2–3 named people', count($adm) > 3 ? 'warn' : 'ok', 'Each admin can see and change everything, including users and settings.', 'Give admin only to the people who run vTiger; others get a role and profile.');
            if (!empty($acc['admin_named'])) $add($A, 'A user named "admin"', 'Settings » Users', 'active', 'renamed or inactive', 'warn', '"admin" is the first user name attackers try.', 'Create a named admin for yourself, sign in with it, then set "admin" to Inactive (keep its records by moving them first).');
            $u90 = (array)($acc['unused90'] ?? []);
            $add($A, 'Active users not signed in for 90 days', 'Settings » Users', $u90 ? count($u90) . ': ' . implode(', ', array_map(fn($a) => $a['user_name'], array_slice($u90, 0, 12))) . (count($u90) > 12 ? ' …' : '') : 'none', 'none (Inactive)', $u90 ? 'warn' : 'ok', 'Unused accounts are easy targets: nobody notices when they are used.', 'Set them to Inactive (Settings » Users, or People » vTiger in this app).');
            $f7 = (int)($acc['failed7'] ?? 0);
            $add($A, 'Failed sign-ins (7 days)', 'vtiger_loginhistory', (string)$f7, 'low', $f7 > 50 ? 'warn' : 'info', 'Many failures mean someone is guessing passwords.', $f7 > 50 ? 'See Security in Server performance for where they come from; consider cPHulk (WHM » Security Center) and blocking the addresses.' : '');
        } else $add($A, 'Accounts', 'vtiger_users', 'not read', '—', 'info', (string)$acc['error']);

        $order = ['risk' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3];
        usort($C, fn($a, $b) => [$order[$a['level']], $a['area']] <=> [$order[$b['level']], $b['area']]);
        return $C;
    }

    /** The block for the web root .htaccess the app recommends (Apache 2.4 / LiteSpeed). */
    function opsh_vtc_htaccess(): string {
        return "# --- added for security (keep vTiger's own lines above) ---\n"
             . "Options -Indexes\n"
             . "<FilesMatch \"\\.(log|sql|gz|zip|tar|tgz|bak|old|orig|swp|properties|secret)$\">\n  Require all denied\n</FilesMatch>\n"
             . "RedirectMatch 404 /\\.git\n"
             . "<IfModule mod_headers.c>\n"
             . "  Header always set Strict-Transport-Security \"max-age=31536000\"\n"
             . "  Header always set X-Frame-Options \"SAMEORIGIN\"\n"
             . "  Header always set X-Content-Type-Options \"nosniff\"\n"
             . "  Header always set Referrer-Policy \"strict-origin-when-cross-origin\"\n"
             . "  Header always unset X-Powered-By\n"
             . "</IfModule>\n"
             . "RewriteEngine On\nRewriteCond %{HTTPS} !=on\nRewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n";
    }
}
