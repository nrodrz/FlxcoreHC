<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — API Router
 * All API requests route through /api/index.php
 */
// BT135: not installed yet (no config.php, or the installer's blank template): say so plainly. The app page then sends the
// browser to install/ (itops-boot.js). Nothing else is reachable before an installation exists.
$itopsCfgFile = __DIR__ . '/../config.php';
if (!is_file($itopsCfgFile) || strpos((string)@file_get_contents($itopsCfgFile), '%%DB_HOST%%') !== false) {
    http_response_code(503); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode(['error' => ['code' => 503, 'message' => 'Not installed yet. Open install/ to install.', 'not_installed' => true]]);
    exit;
}
require_once __DIR__ . '/../config.php';
// BT167: every answer of the API is fresh: no browser, proxy or host cache (LiteSpeed, Cloudflare) may keep one — a saved change
// (menu icons, settings) shows at once. A route that sends a file sets its own header after this one.
if (!headers_sent()) { header('Cache-Control: no-store, private'); header('Pragma: no-cache'); }
require_once __DIR__ . '/lib/TimeZone.php'; itops_tz_boot();   // BT131: the installation's own zone (applied below once the database is open)
require_once __DIR__ . '/lib/BusinessHours.php';
/**
 * BT55: connect to the database first, with two short retries. A busy database ("too many connections", the account's
 * connection limit, a restart, a nightly backup) used to surface as a bare "Something went wrong" 500 on every request
 * at once. Now a short blip is ridden out, and a longer one answers 503 "the database is busy" with its error number,
 * so the cause is visible without the server log.
 */
(function () {
    $last = null;
    foreach ([0, 400000, 1200000] as $wait) {
        if ($wait) usleep($wait);
        try { $tzdb = getDB(); itops_tz_apply($tzdb); itops_db_clock_sync($tzdb); return; }
        catch (PDOException $e) { $last = $e; }
        catch (Throwable $e) { return; }   // not a connection problem: the route reports it as before
    }
    $ref = bin2hex(random_bytes(4));
    $no = (int)($last->errorInfo[1] ?? 0) ?: (int)$last->getCode();
    error_log('[ITOps ' . $ref . '] database connection failed: ' . $last->getMessage());
    if (!headers_sent()) { http_response_code(503); header('Content-Type: application/json'); header('Retry-After: 30'); }
    echo json_encode(['error' => ['code' => 503, 'db' => $no,
        'message' => 'The database is busy or not answering right now' . ($no ? ' (MySQL error ' . $no . ')' : '') . '. Please try again in a minute. Ref ' . $ref . '.', 'ref' => $ref]]);
    exit;
})();
require_once __DIR__ . '/lib/Brand.php';   // BT20: itops_app_name() / itops_brand()
cors_headers();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once __DIR__ . '/lib/Perms.php';   // BT66: itops_can() lives in lib/Perms.php (the background calendar sync uses it too)
function itops_require($user, $module, $action = 'view') {
    if (!itops_can($user, $module, $action)) { itops_refusal_log($user, (string)$module, (string)$action); error_response('Insufficient permissions', 403); }
}
/**
 * BT124 (code review CORE-6, OPS-10, WORK-5, M365B-9): every permission refusal is in the Audit Log ("permission_refused":
 * who, what was wanted, the request). A screen that keeps asking for something it may not see writes it once per ten
 * minutes, not once per request.
 */
function itops_refusal_log($user, string $module, string $action, string $why = ''): void {
    try {
        $db = getDB(); $uid = (int)($user['id'] ?? 0); $what = $module . ' · ' . $action;
        $q = $db->prepare("SELECT COUNT(*) FROM itops_audit_log WHERE action='permission_refused' AND user_id <=> ? AND entity_name=? AND created_at > NOW() - INTERVAL 10 MINUTE");
        $q->execute([$uid ?: null, $what]);
        if ((int)$q->fetchColumn() > 0) return;
        audit_log('permission_refused', 'permission', null, $what, null, ['Wanted' => strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) . ' ' . mb_substr((string)strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?'), 0, 200),
            'Needs' => $what] + ($why !== '' ? ['Why' => $why] : []) + (!empty($user['is_api_key']) ? ['API key' => (string)($user['display_name'] ?? '')] : []));
    } catch (\Throwable $e) {}
}

/**
 * Whether this user must enrol in 2FA before using the app, per the
 * configurable policy (mfa_required_scope = none|admins|all). O365-only
 * accounts are always exempt (Microsoft handles their MFA).
 */
/**
 * Server-side sessions (BT14). Every signed-in token must have a live row in itops_sessions
 * (only the SHA-256 of the token is stored). Logout, password changes and deactivation delete
 * rows, so a copied token stops working at once instead of living until its JWT expiry.
 */
function itops_session_create(PDO $db, int $userId, string $token): void {
    $db->prepare('INSERT INTO itops_sessions (user_id, token_hash, ip_address, user_agent, expires_at) VALUES (?,?,?,?,?)')
       ->execute([$userId, hash('sha256', $token), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                  substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500), date('Y-m-d H:i:s', time() + JWT_EXPIRY_HOURS * 3600)]);
}
function itops_session_valid(PDO $db, string $token): bool {
    $h = hash('sha256', $token);
    try { $st = $db->prepare('SELECT id, created_at, last_seen_at FROM itops_sessions WHERE token_hash=? AND expires_at>=? LIMIT 1'); $st->execute([$h, date('Y-m-d H:i:s')]); $row = $st->fetch(PDO::FETCH_ASSOC); }
    catch (Throwable $e) {   // last_seen_at arrives with the database update
        $st = $db->prepare('SELECT 1 FROM itops_sessions WHERE token_hash=? AND expires_at>=? LIMIT 1'); $st->execute([$h, date('Y-m-d H:i:s')]); return (bool)$st->fetchColumn();
    }
    if (!$row) return false;
    // BT90: the idle sign-out (Settings → session_idle_min, default 30, 0 = off) is enforced here too, not only in the browser.
    // Background calls (the bell, the keep-warm reads) send X-ITOps-Background: 1 and do not count as activity.
    $idle = 30;
    try { $v = $db->query("SELECT sval FROM itops_settings WHERE skey='session_idle_min'")->fetchColumn(); if ($v !== false && $v !== '' && is_numeric($v)) $idle = (int)$v; } catch (Throwable $e) {}
    $last = strtotime((string)($row['last_seen_at'] ?: $row['created_at']));
    if ($idle > 0 && $last && time() - $last > ($idle + 2) * 60) {
        $db->prepare('DELETE FROM itops_sessions WHERE id=?')->execute([(int)$row['id']]);
        return false;
    }
    $GLOBALS['ITOPS_SESSION_ROW'] = $row;
    if ((string)($_SERVER['HTTP_X_ITOPS_BACKGROUND'] ?? '') !== '1' && (!$last || time() - $last >= 30))
        $db->prepare('UPDATE itops_sessions SET last_seen_at=? WHERE id=?')->execute([date('Y-m-d H:i:s'), (int)$row['id']]);
    return true;
}
/** Revoke every session of a user, optionally keeping one token (e.g. the admin's own current one). Returns rows removed. */
function itops_sessions_revoke(PDO $db, int $userId, string $keepToken = ''): int {
    if ($keepToken !== '') { $st = $db->prepare('DELETE FROM itops_sessions WHERE user_id=? AND token_hash<>?'); $st->execute([$userId, hash('sha256', $keepToken)]); }
    else { $st = $db->prepare('DELETE FROM itops_sessions WHERE user_id=?'); $st->execute([$userId]); }
    return $st->rowCount();
}

/** BT14: is every permission of role $roleId also held by $currentUser? (Super Admin role / wildcard → never, for non-Super callers.) */
function itops_role_within(array $currentUser, ?int $roleId): bool {
    if (!$roleId) return true;
    $st = getDB()->prepare('SELECT slug, permissions FROM itops_roles WHERE id=?'); $st->execute([$roleId]); $r = $st->fetch();
    if (!$r) return true;
    if (($r['slug'] ?? '') === 'super_admin') return false;
    $perms = json_decode((string)($r['permissions'] ?? ''), true);
    if (!is_array($perms)) return true;
    if (!empty($perms['*'])) return false;
    // Review CORE-22: what the role grants is read with itops_can itself, so legacy values ('own', 'all', true…) count
    // exactly as they do when the role is used.
    $as = ['role_slug' => (string)($r['slug'] ?? ''), 'role_perms' => (string)$r['permissions'], 'id' => 0];
    foreach ($perms as $mod => $acts) {
        foreach (['view', 'create', 'edit', 'delete', 'all'] as $a) {
            if ($a === 'all' && !in_array($mod, ['tickets', 'cases', 'users', 'onboarding', 'creds'], true)) continue;   /* BT90: "All records" of users, onboarding and the vault count too */
            if (itops_can($as, (string)$mod, $a) && !itops_can($currentUser, (string)$mod, $a)) return false;
        }
    }
    return true;
}

function itops_mfa_pending($db, $user): bool {
    if (empty($user) || !empty($user['mfa_enabled'])) return false;
    if (!in_array($user['auth_provider'] ?? 'local', ['local','both'], true)) return false;
    $scope = '';
    try { $r = $db->query("SELECT sval FROM itops_settings WHERE skey='mfa_required_scope'")->fetchColumn(); $scope = $r ?: ''; } catch (Throwable $e) { $scope = ''; }
    if ($scope === 'all') return true;
    // BT90: "admins" is decided by what the role can do (users, settings, integrations, the vault, Microsoft 365 and
    // vTiger accounts …), not by the role's name.
    if ($scope === 'admins') { require_once __DIR__ . '/lib/AuthGuard.php'; return itops_user_is_admin($user); }
    return false;
}

// Uncaught errors → JSON, never an opaque HTML 500. Super admins see the real message;
// everyone else gets a short reference id that matches the line in the server error log.
set_exception_handler(function (Throwable $e) {
    $ref = bin2hex(random_bytes(4));
    error_log('[ITOps ' . $ref . '] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    // BT55: the database went away or hit its connection limit mid-request → 503 "busy", with its number (no account names)
    $dbNo = $e instanceof PDOException ? ((int)($e->errorInfo[1] ?? 0) ?: (int)$e->getCode()) : 0;
    if (in_array($dbNo, [1040, 1203, 1226, 2002, 2006, 2013], true)) {
        if (!headers_sent()) { http_response_code(503); header('Content-Type: application/json'); header('Retry-After: 30'); }
        echo json_encode(['error' => ['code' => 503, 'db' => $dbNo, 'message' => 'The database is busy or not answering right now (MySQL error ' . $dbNo . '). Please try again in a minute. Ref ' . $ref . '.', 'ref' => $ref]]);
        return;
    }
    $isSuper = (($GLOBALS['currentUser']['role_slug'] ?? '') === 'super_admin');
    $msg = $isSuper ? ('Server error: ' . $e->getMessage() . ' (ref ' . $ref . ')')
                    : ('Something went wrong on the server (ref ' . $ref . '). Please try again or contact IT with this reference.');
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json'); }
    echo json_encode(['error' => ['code' => 500, 'message' => $msg, 'ref' => $ref]]);
});

// Rate limiting: see the file-based limiter below (review CORE-12: the PHP session limiter is gone; it made a session
// file per request and was skipped by not sending the cookie).

// Parse route
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Strip everything up to and including the '/api/' segment so routing works
// whether the app is at the web root (/api/...) or in a subdirectory
// (e.g. /itops/api/...). trim() must NOT run before this or the anchor breaks.
$uri = preg_replace('#^.*?/api/#', '', $path);
$uri = trim($uri, '/');
$segments = $uri === '' ? [] : explode('/', $uri);
$resource = $segments[0] ?? '';
$id = $segments[1] ?? null;
$sub = $segments[2] ?? null;

// Auth middleware — skip for login, o365 callback, and install check
$publicRoutes = ['terms/text', 'auth/login', 'auth/mfa_login', 'auth/o365/callback', 'auth/o365/url', 'auth/teams', 'health', 'install/check', 'jobs/monitor', 'hub/checkin', 'hub/release', 'app/manifest', 'app/icon', 'app/offline', 'app/shortcut'];   // BT147: installing the app (no data in them)   // hub/checkin (BT127): checks the installation's own token;   // backups/checkin (BT62): checks its own key;   // jobs/monitor (BT60): checks its own key   // auth/teams (BT29): the token it carries is verified in the route
// Match against BOTH a 2-segment and a 3-segment key so 3-part public routes
// (auth/o365/url, auth/o365/callback) are recognized — a 2-segment key alone
// would read as "auth/o365" and never match, forcing a 401 before login.
$routeKey  = implode('/', array_slice($segments, 0, 2));
$routeKey3 = implode('/', array_slice($segments, 0, 3));
$isPublicRoute = in_array($routeKey, $publicRoutes, true) || in_array($routeKey3, $publicRoutes, true);

// BT125: while an update is being installed (Settings → Updates) every request but signing in and the update itself waits.
// The flag ends by itself after 15 minutes so a stopped install cannot lock the app.
if (is_file(dirname(__DIR__) . '/data/updates/maintenance.json') && !in_array($resource, ['updates', 'auth', 'health', 'terms', 'lookups', 'about', 'branding', 'modules', 'app'], true)) {
    require_once __DIR__ . '/lib/Updates.php';
    if (($mm = itops_upd_maintenance()) !== null) { header('Retry-After: 60'); error_response('The app is being updated to build ' . preg_replace('/[^0-9A-Za-z-]/', '', (string)($mm['to'] ?? '')) . '. Please try again in a minute.', 503); }
}

// BT14: IP-keyed rate limit that does NOT depend on the session cookie (the session limiter above is trivially
// skipped by not sending a cookie). File-based so it needs no DB write per request. Public / unauthenticated
// endpoints: 60 requests per minute per IP; signed-in traffic: 300 per minute per session.
(function () use ($isPublicRoute, $resource) {
    $pub = $isPublicRoute || in_array($resource, ['install','kb_public','public_ticket','branding','public_form','public_upload','public_sign','public_csat','public_ann','public_pc'], true);
    $limit = $pub ? 60 : 300;
    require_once __DIR__ . '/lib/SafeCache.php';   // BT124 (review M365A-1)
    $dir = itops_cache_dir('rl');
    // Signed-in traffic is counted per session token (an office behind one NAT address must not share one bucket).
    $tokK = (string)($_SERVER['HTTP_X_ITOPS_TOKEN'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? ($_COOKIE['itops_token'] ?? '')));
    $key = $pub || $tokK === '' ? ($pub ? 'p|' : 'a|') . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') : 't|' . $tokK;
    if (!$pub && $tokK === '') $limit = 120;
    $f = $dir . '/' . hash('sha256', $key) . '.' . (int)floor(time() / 60);
    $fh = @fopen($f, 'c+'); if (!$fh) return;   // never block traffic because the counter can't be written
    $n = 0;
    if (flock($fh, LOCK_EX)) { $n = (int)stream_get_contents($fh) + 1; ftruncate($fh, 0); rewind($fh); fwrite($fh, (string)$n); flock($fh, LOCK_UN); }
    fclose($fh);
    if (mt_rand(1, 200) === 1) foreach ((array)@glob($dir . '/*') as $old) if (is_file($old) && @filemtime($old) < time() - 180) @unlink($old);
    if ($n > $limit) { header('Retry-After: 60'); error_response('Too many requests — please wait a minute and try again', 429); }
    // Review CORE-12: a made-up token per request must not escape the limit, so every address also has an overall cap
    // (generous: an office behind one address shares it).
    if (!$pub) {
        $f2 = $dir . '/' . hash('sha256', 'ip|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) . '.' . (int)floor(time() / 60);
        $fh2 = @fopen($f2, 'c+'); $n2 = 0;
        if ($fh2) { if (flock($fh2, LOCK_EX)) { $n2 = (int)stream_get_contents($fh2) + 1; ftruncate($fh2, 0); rewind($fh2); fwrite($fh2, (string)$n2); flock($fh2, LOCK_UN); } fclose($fh2); }
        if ($n2 > 3000) { header('Retry-After: 60'); error_response('Too many requests — please wait a minute and try again', 429); }
    }
})();

$currentUser = null;
// Review OPS-10 / WORK-5 / M365B-9: any other refusal (a route answering 403 itself) is also in the Audit Log. Routes that
// already wrote their own refusal row in this request are left alone; the same refusal is written once per ten minutes.
$GLOBALS['itops_req_t0'] = time();
register_shutdown_function(function () {
    if (http_response_code() !== 403 || empty($GLOBALS['currentUser']['id'])) return;
    try {
        $u = $GLOBALS['currentUser'];
        $q = getDB()->prepare("SELECT COUNT(*) FROM itops_audit_log WHERE user_id=? AND created_at >= FROM_UNIXTIME(?) AND (action LIKE '%refus%' OR action LIKE '%denied%' OR action LIKE '%blocked%')");
        $q->execute([(int)$u['id'], (int)$GLOBALS['itops_req_t0']]);
        if ((int)$q->fetchColumn() > 0) return;
        $path = (string)strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
        itops_refusal_log($u, 'route', mb_substr(preg_replace('#/\d+#', '/{id}', $path), 0, 120), 'refused by the page itself');
    } catch (\Throwable $e) {}
});
if (!$isPublicRoute && $resource !== 'install' && $resource !== 'kb_public' && $resource !== 'public_ticket' && $resource !== 'branding' && $resource !== 'public_form' && $resource !== 'public_upload' && $resource !== 'public_sign' && $resource !== 'public_csat' && $resource !== 'public_ann' && $resource !== 'public_pc') {   // public_csat (BT81): the rating link's token is checked in the route
    // Read the bearer token from wherever the host leaves it. Many cPanel/
    // LiteSpeed setups strip or relocate the Authorization header, so check
    // every common location before giving up.
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if (!$authHeader && function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        $authHeader = $h['Authorization'] ?? $h['authorization'] ?? '';
    }
    if (!$authHeader && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $authHeader = $v; break; }
        }
    }
    // Token sources, in order:
    //  1. X-ITOps-Token custom header — set by the SPA. A plain X- header is passed
    //     to PHP reliably and is NOT stripped or blocked by the WAF/mod_security rules
    //     that often reject a standard Authorization header on cPanel/LiteSpeed.
    //  2. A standard Authorization: Bearer header (for API clients that send one).
    //  3. The httpOnly itops_token cookie — the most host-proof path.
    $bearer = '';
    $authViaCookie = false;
    $xTok = $_SERVER['HTTP_X_ITOPS_TOKEN'] ?? '';
    if ($xTok !== '') {
        $bearer = $xTok;
    } elseif (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
        $bearer = $m[1];
    } elseif (!empty($_COOKIE['itops_token'])) {
        $bearer = $_COOKIE['itops_token'];
        $authViaCookie = true;
    }
    // ── API key auth (machine-to-machine) ────────────────────
    // Sent as "X-API-Key: opsk_live_..." or "Authorization: Bearer opsk_live_...".
    // Keys are scoped, hashed at rest, and can never be super_admin.
    $apiKeyRaw = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!$apiKeyRaw && strpos($bearer, 'opsk_') === 0) $apiKeyRaw = $bearer;
    if ($apiKeyRaw) {
        // Machine-to-machine: scoped key, no cookie, no CSRF, no MFA, never super_admin.
        require_once __DIR__ . '/lib/Integrations.php';
        $keyUser = itops_api_key_user(getDB(), $apiKeyRaw);
        if (!$keyUser) error_response('Invalid or revoked API key', 401);
        $currentUser = $keyUser;
        $_SERVER['ITOPS_USER_ID']   = 0;
        $_SERVER['ITOPS_USER_NAME'] = $currentUser['display_name'];
    } else {
        if (!$bearer) error_response('Authentication required', 401);
        $payload = jwt_decode($bearer);
        if (!$payload) error_response('Invalid or expired token', 401);
        if (!empty($payload['mfa'])) error_response('Two-factor verification required', 401);

        // Load user
        $db = getDB();
        $stmt = $db->prepare('SELECT u.*, r.slug as role_slug, r.permissions as role_perms, d.name as dept_name FROM itops_users u LEFT JOIN itops_roles r ON u.role_id=r.id LEFT JOIN itops_departments d ON u.department_id=d.id WHERE u.id=? AND u.status="active"');
        $stmt->execute([$payload['uid']]);
        $currentUser = $stmt->fetch();
        if (!$currentUser) error_response('User not found or inactive', 401);
        // The token must belong to a live server-side session (revoked on logout / password change / deactivation).
        if (!itops_session_valid($db, $bearer)) { if ($authViaCookie) { require_once __DIR__ . '/lib/AuthGuard.php'; itops_set_cookie('itops_token', '', time() - 3600); } error_response('Session ended — please sign in again', 401); }
        $_SERVER['ITOPS_TOKEN'] = $bearer;

        // CSRF protection: cookie-authenticated state-changing requests must carry a
        // custom header. Browsers cannot set custom headers on cross-site requests
        // without a CORS preflight, which this API never grants — so a forged cross-site
        // request cannot include it. Bearer-token API clients are exempt.
        if ($authViaCookie) {
            $reqM = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            if (in_array($reqM, ['POST','PUT','PATCH','DELETE'], true)) {
                $csrfHeader = $_SERVER['HTTP_X_ITOPS_CSRF'] ?? '';
                if ($csrfHeader !== '1') error_response('CSRF validation failed', 403);
            }
            // BT124 (review M365B-5): a read with the sign-in cookie that another site started (a link or a redirect) is
            // refused too, so no GET can be used to make a signed-in person do something. Browsers say where a request
            // came from in Sec-Fetch-Site; requests without it (older browsers, tools) are left as they were.
            $sfs = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
            if ($sfs === 'cross-site') error_response('This address cannot be opened from another site.', 403);
        }

        $_SERVER['ITOPS_USER_ID'] = $currentUser['id'];
        $_SERVER['ITOPS_USER_NAME'] = $currentUser['display_name'];

        // Enforce the 2FA-required policy: until enrolled, only auth/lookups/health are reachable.
        if (itops_mfa_pending($db, $currentUser) && !in_array($resource, ['auth','lookups','health'], true)) {
            error_response('Two-factor enrollment required', 403);
        }
        // BT97: until this person has accepted the current Terms of Use (and the first administrator has accepted them for
        // the organization), only signing in and out, the Terms themselves and About & ownership are reachable.
        if (!in_array($resource, ['auth','terms','about','health','branding'], true)) {
            require_once __DIR__ . '/lib/TermsOfUse.php';
            if (itops_tou_required($db, $currentUser)) error_response('Terms of Use acceptance required', 403);
        }
    }
}

// BT101: a module that is not part of this installation (not licensed, or switched off) has no API — signed in or not.
require_once __DIR__ . '/lib/Brand.php';
require_once __DIR__ . '/lib/Modules.php';
if (($rm = itops_route_module((string)$resource)) !== null && !itops_module_on($rm)) {
    $mc = itops_modules_catalog();
    error_response(($mc[$rm][0] ?? 'This module') . ' is not part of this installation.', 404);
}

// Route to handlers
$routeFile = __DIR__ . "/routes/{$resource}.php";
if (file_exists($routeFile)) {
    require $routeFile;
} else {
    error_response('Not found', 404);
}
