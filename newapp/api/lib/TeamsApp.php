<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * BT29 — the app inside Microsoft Teams.
 *
 *   1. Tab with automatic sign-in: Teams hands the page a Microsoft Entra token for the signed-in person
 *      (Teams SSO); opsh_teams_verify() checks it and auth.php turns it into a normal session.
 *   2. App package: opsh_teams_package() builds the zip (manifest + two icons) that an admin uploads to Teams.
 *   3. Personal notifications: opsh_teams_feed_run() copies new bell notifications of the switched-on kinds to
 *      each person's Teams activity feed (Graph sendActivityNotification). It runs from cron, never inside a request.
 *
 * Settings live in opsh_settings.teams_app (JSON). Nothing here is active until a Super Admin switches it on.
 * The Azure app registration is the same one the Microsoft 365 sign-in and Graph features already use.
 */

/** Client applications to pre-authorize on the Azure app ("Expose an API"). The first two are required for Teams. */
const OPSH_TEAMS_CLIENTS = [
    ['1fec8e78-bce4-4aaf-ab1b-5451cc387264', 'Teams desktop and mobile', true],
    ['5e3ce6c0-2b1f-4285-8d4b-75ee78787346', 'Teams on the web', true],
    ['4765445b-32c6-49b0-83e6-1d93765276ca', 'Microsoft 365 on the web', false],
    ['0ec893e0-5785-4de6-99da-4ed124e5296c', 'Microsoft 365 desktop', false],
    ['d3590ed6-52b3-4102-aeff-aad2292ab01c', 'Microsoft 365 mobile and Outlook desktop', false],
    ['bc59ab01-8403-45c6-8796-ac3ef710b3e3', 'Outlook on the web', false],
    ['27922004-5251-4030-b22d-91ecd9a37ea4', 'Outlook mobile', false],
];
const OPSH_TEAMS_SCOPE = 'access_as_user';
/** Activity types declared in the manifest: kind => [description shown in Teams notification settings, bell categories]. */
const OPSH_TEAMS_KINDS = ['announcements' => 'announcement', 'tickets' => 'ticket', 'alerts' => 'alert'];

function opsh_teams_cfg(PDO $db): array
{
    try { $s = $db->prepare("SELECT sval FROM opsh_settings WHERE skey='teams_app'"); $s->execute(); $j = json_decode((string)$s->fetchColumn(), true); }
    catch (Throwable $e) { $j = null; }
    $j = is_array($j) ? $j : [];
    $n = is_array($j['notify'] ?? null) ? $j['notify'] : [];
    return [
        'enabled'   => !empty($j['enabled']),
        'app_id'    => (string)($j['app_id'] ?? ''),
        'pkg_ver'   => max(1, (int)($j['pkg_ver'] ?? 1)),
        'pkg_hash'  => (string)($j['pkg_hash'] ?? ''),
        'notify'    => ['announcements' => array_key_exists('announcements', $n) ? !empty($n['announcements']) : true,
                        'tickets' => !empty($n['tickets']), 'alerts' => !empty($n['alerts'])],
        'inventory' => array_key_exists('inventory', $j) ? !empty($j['inventory']) : true,
        'cursor'    => isset($j['cursor']) ? (int)$j['cursor'] : null,
        'last_run'  => is_array($j['last_run'] ?? null) ? $j['last_run'] : null,
    ];
}
function opsh_teams_save(PDO $db, array $cfg): void
{
    $db->prepare("INSERT INTO opsh_settings (skey,sval) VALUES ('teams_app',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([json_encode($cfg)]);
}
function opsh_teams_guid(): string
{
    $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
/** Where the app is served: ['url' => base without trailing slash, 'host' => host[:port] lower-case, 'https' => bool]. */
function opsh_teams_base(): array
{
    $url = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';
    $p = parse_url($url) ?: [];
    $host = strtolower((string)($p['host'] ?? ''));
    if ($host !== '' && isset($p['port']) && (int)$p['port'] !== 443) $host .= ':' . (int)$p['port'];
    return ['url' => $url, 'host' => $host, 'https' => strtolower((string)($p['scheme'] ?? '')) === 'https'];
}
/** The Application ID URI Teams SSO needs on the Azure app: api://<host>/<client id>. */
function opsh_teams_resource(string $clientId): string { $b = opsh_teams_base(); return 'api://' . $b['host'] . '/' . strtolower($clientId); }
/** Link that opens a page of the app inside Teams (personal tab "home", page given as sub-entity). */
function opsh_teams_deeplink(string $appId, string $page = ''): string
{
    $u = 'https://teams.microsoft.com/l/entity/' . rawurlencode($appId) . '/home';
    return $page === '' ? $u : $u . '?context=' . rawurlencode(json_encode(['subEntityId' => $page]));
}

// ───────────────────────────── App package ─────────────────────────────

function opsh_teams_manifest(PDO $db, array $cfg, array $o365): array
{
    $b = opsh_teams_base();
    $app = function_exists('opsh_app_name') ? opsh_app_name() : 'OpsHub';
    $org = '';
    try { $org = (string)$db->query("SELECT sval FROM opsh_settings WHERE skey='org_name'")->fetchColumn(); } catch (Throwable $e) {}
    if ($org === '') $org = $app;
    $short = mb_substr($app, 0, 30);
    $cut = fn(string $s, int $n) => mb_strlen($s) > $n ? rtrim(mb_substr($s, 0, $n - 1)) . '…' : $s;
    return [
        '$schema' => 'https://developer.microsoft.com/en-us/json-schemas/teams/v1.17/MicrosoftTeams.schema.json',
        'manifestVersion' => '1.17',
        'version' => '1.0.' . $cfg['pkg_ver'],
        'id' => $cfg['app_id'],
        'developer' => ['name' => $cut($org, 32), 'websiteUrl' => $b['url'] . '/', 'privacyUrl' => $b['url'] . '/guide.html', 'termsOfUseUrl' => $b['url'] . '/guide.html'],
        'name' => ['short' => $short, 'full' => $cut($app . ' — IT help, announcements and tickets', 100)],
        'description' => ['short' => $cut('IT help desk, announcements and knowledge for ' . $org, 80),
                          'full' => $app . ' inside Teams: read and confirm announcements, open and follow IT tickets, search the knowledge base and procedures. You are signed in automatically with your work account.'],
        'icons' => ['color' => 'color.png', 'outline' => 'outline.png'],
        'accentColor' => '#1D4D7A',
        'staticTabs' => [
            ['entityId' => 'home', 'name' => $short, 'contentUrl' => $b['url'] . '/index.html?host=teams', 'websiteUrl' => $b['url'] . '/', 'scopes' => ['personal']],
            ['entityId' => 'announcements', 'name' => 'Announcements', 'contentUrl' => $b['url'] . '/index.html?host=teams&pg=announcements', 'websiteUrl' => $b['url'] . '/#announcements', 'scopes' => ['personal']],
        ],
        'permissions' => ['identity'],
        'validDomains' => [$b['host']],
        'webApplicationInfo' => ['id' => (string)$o365['client_id'], 'resource' => opsh_teams_resource((string)$o365['client_id'])],
        // Lets the app send activity-feed notifications to the people it is installed for, without a tenant-wide Graph permission.
        'authorization' => ['permissions' => ['resourceSpecific' => [['name' => 'TeamsActivity.Send.User', 'type' => 'Application']]]],
        'activities' => ['activityTypes' => [
            ['type' => 'announcement', 'description' => 'Announcements', 'templateText' => '{title}'],
            ['type' => 'ticket', 'description' => 'Tickets', 'templateText' => '{title}'],
            ['type' => 'alert', 'description' => 'Alerts and reminders', 'templateText' => '{title}'],
        ]],
    ];
}
/** Minimal zip writer (stored, no compression) so the package needs no PHP extension. $files: name => bytes. */
function opsh_teams_zip(array $files): string
{
    $out = ''; $dir = ''; $n = 0;
    $dt = getdate(); $time = ($dt['hours'] << 11) | ($dt['minutes'] << 5) | ($dt['seconds'] >> 1); $date = (($dt['year'] - 1980) << 9) | ($dt['mon'] << 5) | $dt['mday'];
    foreach ($files as $name => $data) {
        $crc = crc32($data); $len = strlen($data); $off = strlen($out);
        $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $time, $date, $crc, $len, $len, strlen($name), 0) . $name . $data;
        $dir .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $time, $date, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0x20, $off) . $name;
        $n++;
    }
    return $out . $dir . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($dir), strlen($out), 0);
}
/** Build the package. The version goes up whenever the manifest changes, as Teams requires for an update. Returns [file name, bytes]. */
function opsh_teams_package(PDO $db, array $o365): array
{
    $cfg = opsh_teams_cfg($db);
    if ($cfg['app_id'] === '') $cfg['app_id'] = opsh_teams_guid();
    $probe = opsh_teams_manifest($db, ['pkg_ver' => 0] + $cfg, $o365);
    $hash = sha1(json_encode($probe));
    if ($cfg['pkg_hash'] !== '' && $cfg['pkg_hash'] !== $hash) $cfg['pkg_ver']++;
    $cfg['pkg_hash'] = $hash;
    opsh_teams_save($db, $cfg);
    $man = opsh_teams_manifest($db, $cfg, $o365);
    $dir = dirname(__DIR__, 2) . '/public/teams/';
    $color = @file_get_contents($dir . 'color.png'); $outline = @file_get_contents($dir . 'outline.png');
    if ($color === false || $outline === false) throw new RuntimeException('The Teams icons are missing from public/teams/. Upload the full build again.');
    $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', function_exists('opsh_app_name') ? opsh_app_name() : 'OpsHub'), '-') ?: 'App';
    return [$slug . '-Teams-app-' . $man['version'] . '.zip',
            opsh_teams_zip(['manifest.json' => json_encode($man, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'color.png' => $color, 'outline.png' => $outline])];
}
/** What has to be true before Teams can use the app. Each item: [key, ok, text]. */
function opsh_teams_checks(array $o365): array
{
    $b = opsh_teams_base(); $out = [];
    $out[] = ['https', $b['https'] && $b['host'] !== '', $b['https'] ? 'The app is served over HTTPS at ' . $b['url'] : 'Teams only loads HTTPS pages. APP_URL in config.php is "' . $b['url'] . '".'];
    $guid = (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string)$o365['client_id']);
    $out[] = ['sso', !empty($o365['enabled']) && $o365['tenant'] !== '' && $guid, (!empty($o365['enabled']) && $guid) ? 'Microsoft 365 sign-in is configured (the same Azure app is used).' : 'Microsoft 365 sign-in is not switched on. Set it up under Settings → Sign-in & AI first: Teams signs people in through it.'];
    $out[] = ['graph', function_exists('opsh_graph_configured') && opsh_graph_configured(), (function_exists('opsh_graph_configured') && opsh_graph_configured()) ? 'Microsoft Graph is connected (needed for Teams notifications).' : 'Microsoft Graph is not connected. Tab and sign-in work without it; Teams notifications need it.'];
    $out[] = ['icons', is_file(dirname(__DIR__, 2) . '/public/teams/color.png') && is_file(dirname(__DIR__, 2) . '/public/teams/MicrosoftTeams.min.js'), 'App files (icons and the Teams library) are in public/teams/.'];
    return $out;
}

// ───────────────────────────── Sign-in token ─────────────────────────────

if (!function_exists('opsh_teams_http_get')) {
    /** GET from Microsoft's sign-in service only. Returns the body or throws. (Replaced by a stub in the test environment.) */
    function opsh_teams_http_get(string $url): string
    {
        if (strpos($url, 'https://login.microsoftonline.com/') !== 0) throw new RuntimeException('Refused to fetch ' . $url);
        if (!function_exists('curl_init')) throw new RuntimeException('curl is not available on this server');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($r === false || $code !== 200) throw new RuntimeException('Could not reach Microsoft sign-in (' . ($err ?: 'HTTP ' . $code) . ')');
        return (string)$r;
    }
}
function opsh_teams_cache_file(string $name): string
{
    require_once __DIR__ . '/SafeCache.php';   // BT124 (review M365A-1)
    $dir = opsh_cache_dir('teams');
    return $dir . '/' . preg_replace('/[^a-z0-9_.-]/i', '_', $name) . '.json';
}
/** Fetch JSON from Microsoft with a file cache. $fresh skips the cache (used once when a signing key is unknown). */
function opsh_teams_cached_json(string $url, int $ttl, bool $fresh = false): array
{
    $f = opsh_teams_cache_file(sha1($url));
    // Review M365A-23: a forced read again happens at most once every five minutes (an unknown key id cannot make every
    // sign-in attempt call Microsoft)
    if ($fresh && is_file($f) && time() - (int)@filemtime($f) < 300) $fresh = false;
    if (!$fresh && is_file($f) && time() - (int)@filemtime($f) < $ttl) { $c = opsh_cache_read($f); $j = $c === null ? null : json_decode($c, true); if (is_array($j)) return $j; }
    $j = json_decode(opsh_teams_http_get($url), true);
    if (!is_array($j)) throw new RuntimeException('Microsoft sign-in returned an unreadable answer.');
    opsh_cache_write($f, json_encode($j));
    return $j;
}
function opsh_teams_b64(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
/** RSA public key (PEM) from a JWKS entry: the certificate when present, else built from n / e. */
function opsh_teams_jwk_pem(array $k): string
{
    if (!empty($k['x5c'][0])) return "-----BEGIN CERTIFICATE-----\n" . chunk_split((string)$k['x5c'][0], 64, "\n") . "-----END CERTIFICATE-----\n";
    $n = opsh_teams_b64((string)($k['n'] ?? '')); $e = opsh_teams_b64((string)($k['e'] ?? ''));
    if ($n === '' || $e === '') throw new RuntimeException('Signing key is incomplete.');
    $len = function (int $l): string { if ($l < 128) return chr($l); $b = ltrim(pack('N', $l), "\0"); return chr(0x80 | strlen($b)) . $b; };
    $int = function (string $v) use ($len): string { if (ord($v[0]) > 0x7f) $v = "\0" . $v; return "\x02" . $len(strlen($v)) . $v; };
    $seq = $int($n) . $int($e); $rsa = "\x30" . $len(strlen($seq)) . $seq;
    $bit = "\0" . $rsa; $alg = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $spki = $alg . "\x03" . $len(strlen($bit)) . $bit;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode("\x30" . $len(strlen($spki)) . $spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}
/**
 * Verify the token Teams gives the tab (Teams SSO). Returns its claims or throws RuntimeException with a reason an admin can act on.
 * Checked: RS256 signature against Microsoft's published keys for OUR tenant, issuer and tenant id, audience (this app),
 * validity window, that it is a user token carrying the access_as_user scope, and that it names a person (oid).
 */
function opsh_teams_verify(string $jwt, array $o365): array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) throw new RuntimeException('That is not a sign-in token.');
    $head = json_decode(opsh_teams_b64($parts[0]), true); $c = json_decode(opsh_teams_b64($parts[1]), true); $sig = opsh_teams_b64($parts[2]);
    if (!is_array($head) || !is_array($c) || $sig === '') throw new RuntimeException('That is not a sign-in token.');
    if (($head['alg'] ?? '') !== 'RS256') throw new RuntimeException('Unexpected token signature type.');
    $tenant = strtolower(trim((string)$o365['tenant'])); $client = strtolower((string)$o365['client_id']);
    if ($tenant === '' || in_array($tenant, ['common', 'organizations', 'consumers'], true)) throw new RuntimeException('Microsoft 365 sign-in needs your own tenant ID (not "common").');
    $meta = opsh_teams_cached_json('https://login.microsoftonline.com/' . rawurlencode($tenant) . '/v2.0/.well-known/openid-configuration', 86400);
    if (!preg_match('#^https://login\.microsoftonline\.com/([0-9a-f-]{36})/v2\.0$#i', (string)($meta['issuer'] ?? ''), $m)) throw new RuntimeException('Could not read the tenant from Microsoft sign-in.');
    $tid = strtolower($m[1]);
    $jwksUrl = (string)($meta['jwks_uri'] ?? '');
    if (strpos($jwksUrl, 'https://login.microsoftonline.com/') !== 0) throw new RuntimeException('Unexpected signing-key address.');
    $kid = (string)($head['kid'] ?? ''); $key = null;
    foreach ([false, true] as $fresh) {   // unknown key id → Microsoft may have rotated its keys: read them again once
        foreach ((opsh_teams_cached_json($jwksUrl, 43200, $fresh)['keys'] ?? []) as $k) if (($k['kid'] ?? '') === $kid && ($k['kty'] ?? 'RSA') === 'RSA') { $key = $k; break 2; }
    }
    if (!$key) throw new RuntimeException('The token was not signed by a known Microsoft key.');
    $pub = openssl_pkey_get_public(opsh_teams_jwk_pem($key));
    if (!$pub || openssl_verify($parts[0] . '.' . $parts[1], $sig, $pub, OPENSSL_ALGO_SHA256) !== 1) throw new RuntimeException('The token signature is not valid.');
    $now = time();
    if ((int)($c['exp'] ?? 0) < $now - 60) throw new RuntimeException('The sign-in token has expired. Reopen the app in Teams.');
    if ((int)($c['nbf'] ?? 0) > $now + 300) throw new RuntimeException('The sign-in token is not valid yet (check the server clock).');
    if (strtolower((string)($c['tid'] ?? '')) !== $tid) throw new RuntimeException('This Microsoft account belongs to a different organization.');
    $iss = strtolower(rtrim((string)($c['iss'] ?? ''), '/'));
    if ($iss !== 'https://login.microsoftonline.com/' . $tid . '/v2.0' && $iss !== 'https://sts.windows.net/' . $tid) throw new RuntimeException('The token was not issued by your Microsoft tenant.');
    $aud = strtolower((string)($c['aud'] ?? ''));
    if ($aud !== strtolower($client) && $aud !== strtolower(opsh_teams_resource($client)))   // review M365A-10: this app's own address only
        throw new RuntimeException('The token was issued for another application. Check "Application ID URI" on the Azure app: it must be ' . opsh_teams_resource($client) . '.');
    $scp = preg_split('/\s+/', trim((string)($c['scp'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
    if (!in_array(OPSH_TEAMS_SCOPE, $scp, true)) throw new RuntimeException('The token does not carry the "' . OPSH_TEAMS_SCOPE . '" scope. Add that scope under "Expose an API" on the Azure app.');
    if (!preg_match('/^[0-9a-f-]{36}$/i', (string)($c['oid'] ?? ''))) throw new RuntimeException('The token does not identify a person.');
    return $c;
}

// ───────────────────────────── Teams activity feed ─────────────────────────────

/** Which switch a bell notification belongs to. */
function opsh_teams_category(string $type, string $link): string
{
    if ($type === 'announcement') return 'announcements';
    $l = ltrim($link, '#');
    if (in_array($type, ['assignment', 'comment', 'message'], true) || $l === 'tickets' || strpos($l, 'tickets/') === 0) return 'tickets';
    return 'alerts';
}
/**
 * Send one item to a person's Teams activity feed. $userRef = Entra object id or sign-in name.
 * Returns ['status' => sent | not_installed | denied | throttled | failed, 'error' => text].
 */
function opsh_teams_activity_send(array $cfg, string $userRef, string $category, string $title, string $preview, string $page): array
{
    $app = function_exists('opsh_app_name') ? opsh_app_name() : 'OpsHub';
    $cut = fn(string $s, int $n) => mb_strlen($s) > $n ? rtrim(mb_substr($s, 0, $n - 1)) . '…' : $s;
    $body = ['topic' => ['source' => 'text', 'value' => $app, 'webUrl' => opsh_teams_deeplink($cfg['app_id'], $page)],
             'activityType' => OPSH_TEAMS_KINDS[$category] ?? 'alert',
             'previewText' => ['content' => $cut(trim($preview) !== '' ? $preview : $title, 150)],
             'templateParameters' => [['name' => 'title', 'value' => $cut($title, 120)]]];
    try { [$code, $data] = opsh_graph_http('POST', 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($userRef) . '/teamwork/sendActivityNotification', $body, [], 15); }
    catch (Throwable $e) { return ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 200)]; }
    if ($code >= 200 && $code < 300) return ['status' => 'sent', 'error' => ''];
    $msg = (string)($data['error']['message'] ?? ('HTTP ' . $code));
    if ($code === 429) return ['status' => 'throttled', 'error' => $msg];
    if (($code === 401 || $code === 403) && preg_match('/missing role|requires one of|insufficient privileges|TeamsActivity\.Send/i', $msg)) return ['status' => 'denied', 'error' => $msg];
    if ($code === 403 || $code === 404) return ['status' => 'not_installed', 'error' => $msg];   // the app is not installed for this person, or they are not in Teams
    return ['status' => 'failed', 'error' => mb_substr($msg, 0, 200)];
}
/**
 * Copy new bell notifications to Teams, for the kinds that are switched on. Runs from cron-sla.php.
 * The cursor (last notification id handled) always moves forward: a notification is tried once, never replayed.
 */
function opsh_teams_feed_run(PDO $db, int $max = 150, int $budgetSeconds = 25): array
{
    $cfg = opsh_teams_cfg($db);
    $out = ['sent' => 0, 'skipped' => 0, 'not_installed' => 0, 'failed' => 0, 'error' => ''];
    if (!$cfg['enabled'] || $cfg['app_id'] === '' || !array_filter($cfg['notify'])) return $out;
    if (!function_exists('opsh_graph_configured') || !opsh_graph_configured()) return $out;
    $top = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM opsh_notifications')->fetchColumn();
    if ($cfg['cursor'] === null) { $cfg['cursor'] = $top; opsh_teams_save($db, $cfg); return $out; }   // first run: start from now, never replay history
    if ($top <= $cfg['cursor']) return $out;
    $st = $db->prepare("SELECT n.id, n.title, n.body, n.type, n.link, u.email, u.o365_oid, u.status FROM opsh_notifications n JOIN opsh_users u ON u.id=n.user_id
                        WHERE n.id > ? ORDER BY n.id LIMIT " . max(1, min(500, $max)));
    $st->execute([$cfg['cursor']]);
    $t0 = time(); $cursor = $cfg['cursor'];
    foreach ($st->fetchAll() as $n) {
        if (time() - $t0 >= $budgetSeconds) break;
        $cat = opsh_teams_category((string)$n['type'], (string)$n['link']);
        $ref = (string)($n['o365_oid'] ?: $n['email']);
        if (!function_exists('opsh_link_enabled')) require_once __DIR__ . '/Modules.php';
        if (!opsh_link_enabled($n['link'] ?? null) || empty($cfg['notify'][$cat]) || $n['status'] !== 'active' || $ref === '' || strpos((string)$n['title'], '[Test]') === 0) { $out['skipped']++; $cursor = (int)$n['id']; continue; }
        $page = preg_replace('/[^a-z0-9_]/i', '', explode('/', ltrim((string)$n['link'], '#'))[0]);
        $r = opsh_teams_activity_send($cfg, $ref, $cat, (string)$n['title'], (string)$n['body'], $page);
        if ($r['status'] === 'throttled') { $out['error'] = 'Microsoft asked to slow down; the rest goes out on the next run.'; break; }   // cursor stays before this one
        if ($r['status'] === 'denied') { $out['error'] = 'Teams refused the notification: ' . $r['error']; $cursor = $top; break; }       // nothing can be sent: skip the backlog
        $cursor = (int)$n['id'];
        if ($r['status'] === 'sent') $out['sent']++; elseif ($r['status'] === 'not_installed') $out['not_installed']++; else { $out['failed']++; $out['error'] = $r['error']; }
    }
    $cfg = opsh_teams_cfg($db);   // re-read: a Super Admin may have changed the switches meanwhile
    $cfg['cursor'] = max((int)$cfg['cursor'], $cursor);
    $cfg['last_run'] = ['at' => gmdate('Y-m-d\TH:i:s\Z')] + $out;
    opsh_teams_save($db, $cfg);
    return $out;
}
