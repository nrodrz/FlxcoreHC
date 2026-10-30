<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — Auth Routes
 * POST /api/auth/login — Local email/password login
 * GET  /api/auth/o365/url — Get O365 OAuth2 authorization URL
 * POST /api/auth/o365/callback — Exchange O365 auth code for token
 * GET  /api/auth/me — Get current user profile
 * POST /api/auth/logout — Invalidate session
 * POST /api/auth/refresh — Refresh JWT token
 * POST /api/auth/teams — BT29: sign in with the token Teams gives the tab (Teams SSO)
 * POST /api/auth/client — BT29: the device the app was opened on (Device inventory)
 */

$action = $segments[1] ?? '';
$db = getDB();
require_once __DIR__ . '/../lib/O365.php';
require_once __DIR__ . '/../lib/Totp.php';
require_once __DIR__ . '/../lib/Requesters.php';
require_once __DIR__ . '/../lib/TermsOfUse.php';   // BT97: terms_required in the sign-in answers
require_once __DIR__ . '/../lib/AuthGuard.php';   // BT90 (code audit M-8, M-9): throttles, 2FA codes used once, Microsoft linking rules
/** BT108: the person's screen preferences, with the defaults (Ticket Categories opens on the grid). */
if (!function_exists('itops_ui_prefs')) {
    function itops_ui_prefs(?array $u): array {
        $p = json_decode((string)($u['ui_prefs'] ?? ''), true);
        $p = is_array($p) ? $p : [];
        return ['tc_view' => in_array($p['tc_view'] ?? '', ['grid', 'cards'], true) ? $p['tc_view'] : 'grid'];
    }
}

/** BT90 (M-40): the browser keeps the session in the HttpOnly cookie only. The token itself is in the answer only for a
 *  client that asks for it (X-ITOps-Want-Token: 1 — API scripts and the Teams tab, which keeps it in memory). */
function itops_login_token_out(string $token): array {
    return (string)($_SERVER['HTTP_X_ITOPS_WANT_TOKEN'] ?? '') === '1' ? ['token' => $token] : [];
}

/** BT99: a sign-in in the Audit Log, under the person who signed in (it was written as "System"). The very first sign-in
 *  of an account is "login_first". $how: password, microsoft or teams. */
function itops_audit_signin(array $user, string $how): void {
    $_SERVER['ITOPS_USER_ID'] = (int)$user['id'];
    $_SERVER['ITOPS_USER_NAME'] = (string)(($user['display_name'] ?? '') !== '' ? $user['display_name'] : $user['email']);
    $first = (int)($user['login_count'] ?? 0) === 0;
    $act = $first ? 'login_first' : ['password' => 'login_success', 'microsoft' => 'o365_login', 'teams' => 'teams_login'][$how];
    audit_log($act, 'auth', (int)$user['id'], (string)$user['email'], null, ['Signed in with' => ['password' => 'email and password', 'microsoft' => 'Microsoft', 'teams' => 'Teams'][$how]] + ($first ? ['First sign-in' => 'yes'] : []));
}

/** Issue a full session + JWT + cookie and emit the standard user payload. */
function itops_issue_login(PDO $db, array $user) {
    itops_claim_tickets($db, (int)$user['id'], (string)$user['email']);   // tickets they sent by email / form before having an account
    $db->prepare('UPDATE itops_users SET last_login_at=NOW(), last_login_ip=?, login_count=login_count+1 WHERE id=?')
       ->execute([$_SERVER['REMOTE_ADDR'] ?? '', $user['id']]);
    $token = jwt_encode(['uid'=>(int)$user['id'],'email'=>$user['email'],'role'=>$user['role_slug'],'dept'=>(int)$user['department_id'],'sid'=>bin2hex(random_bytes(12))]);   // sid: every login gets a unique token/session
    itops_session_create($db, (int)$user['id'], $token);
    itops_audit_signin($user, 'password');   // BT99: under the person, first sign-in marked
    itops_set_cookie('itops_token', $token, time() + 8 * 3600);
    json_response(itops_login_token_out($token) + ['user'=>[
        'id'=>(int)$user['id'],'email'=>$user['email'],'display_name'=>$user['display_name'],'first_name'=>$user['first_name'],
        'last_name'=>$user['last_name'],'avatar_path'=>$user['avatar_path'],'role'=>$user['role_slug'],
        'role_permissions'=>itops_perm_overlay(json_decode($user['role_perms'], true), (int)$user['id']),'department_id'=>(int)$user['department_id'],
        'department_name'=>$user['dept_name'],'auth_provider'=>$user['auth_provider'],'mfa_enabled'=>(bool)$user['mfa_enabled'],
        'mfa_enrollment_required'=>itops_mfa_pending($db,$user),
        'terms_required'=>itops_tou_required($db,$user)   // BT97
    ]]);
}

/** Consume a one-time backup code; returns true if it matched (and was removed). */
function mfa_consume_backup(PDO $db, array $user, string $code): bool {
    if (empty($user['mfa_backup_codes'])) return false;
    $code = strtoupper(trim($code));
    $hashes = json_decode($user['mfa_backup_codes'], true) ?: [];
    $idx = array_search(hash('sha256', $code), $hashes, true);
    if ($idx === false) return false;
    array_splice($hashes, $idx, 1);
    $db->prepare('UPDATE itops_users SET mfa_backup_codes=? WHERE id=?')->execute([json_encode(array_values($hashes)), $user['id']]);
    return true;
}

switch ("$method:$action") {
    
    // ── Local Login ──────────────────────────────────────────
    case 'POST:login':
        itops_require_json_body();   // BT90: JSON only
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) error_response('Email and password required');
        $email = trim((string)($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');
        
        if (!$email || !$password) error_response('Email and password required');

        // BT90 (M-8): failures counted per account (8 in 15 minutes, from any address) and per address (30 in 15 minutes,
        // any account); the attempts on one account are taken one at a time, so parallel guesses cannot pass the limit.
        $acctKey = 'lg|a|' . hash('sha256', strtolower($email)); $ipKey = 'lg|i|' . itops_auth_ip();
        if (!itops_auth_lock($db, $acctKey)) error_response('Too many sign-in attempts at once. Please try again in a moment.', 429);
        if (itops_auth_fail_count($db, $acctKey, 900) >= 8 || itops_auth_fail_count($db, $ipKey, 900) >= 30) {
            itops_auth_unlock($db, $acctKey);
            audit_log('login_lockout', 'auth', null, $email);
            error_response('Too many failed attempts. Please wait about 15 minutes and try again.', 429);
        }

        $stmt = $db->prepare('SELECT u.*, r.slug as role_slug, r.permissions as role_perms, d.name as dept_name FROM itops_users u LEFT JOIN itops_roles r ON u.role_id=r.id LEFT JOIN itops_departments d ON u.department_id=d.id WHERE u.email=? AND u.status="active"');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        // Review CORE-11: a missing account costs the same time as a wrong password (no account discovery by timing).
        if (!$user || !$user['password_hash']) verify_password($password, defined('PASSWORD_ARGON2ID') ? '$argon2id$v=19$m=65536,t=4,p=1$d2cubWV1cEJCRDNlVkpOaw$/wkgmHJWPxrEpiPXIhgZuzNLk6NV135LUBp1EjTXaOQ' : '$2y$12$C6UzMDM.H6dfI/f/IKcEeO5Wq1Pr6s0bXeh7h6m9H1i3bVZkV9mXK');
        if (!$user || !$user['password_hash'] || !verify_password($password, $user['password_hash'])) {
            itops_auth_fail_add($db, $acctKey); itops_auth_fail_add($db, $ipKey);
            itops_auth_unlock($db, $acctKey);
            audit_log('login_failed', 'auth', null, $email);
            error_response('Invalid email or password', 401);
        }
        // Successful login — clear the account's counter
        itops_auth_fail_clear($db, $acctKey);
        itops_auth_unlock($db, $acctKey);

        // App-level 2FA applies to local logins only; O365 users rely on Microsoft MFA.
        if ((int)$user['mfa_enabled'] === 1 && !empty($user['mfa_secret'])) {
            json_response(['mfa_required' => true, 'mfa_token' => jwt_encode(['uid'=>(int)$user['id'],'mfa'=>1,'mfa_iat'=>time()])]);
            break;
        }
        itops_issue_login($db, $user);
        break;

    // ── 2FA login challenge (PUBLIC) ─────────────────────────
    case 'POST:mfa_login':
        itops_require_json_body();   // BT90: JSON only
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $p = !empty($body['mfa_token']) ? jwt_decode($body['mfa_token']) : null;
        if (!$p || empty($p['mfa']) || empty($p['uid'])) error_response('Your verification session expired — please sign in again.', 401);
        $stmt = $db->prepare('SELECT u.*, r.slug as role_slug, r.permissions as role_perms, d.name as dept_name FROM itops_users u LEFT JOIN itops_roles r ON u.role_id=r.id LEFT JOIN itops_departments d ON u.department_id=d.id WHERE u.id=? AND u.status="active"');
        $stmt->execute([(int)$p['uid']]);
        $user = $stmt->fetch();
        if (!$user || !$user['mfa_enabled'] || empty($user['mfa_secret'])) error_response('Two-factor authentication is not active for this account.', 400);
        // The verification step must be finished within 10 minutes of the password step.
        if (empty($p['mfa_iat']) || time() - (int)$p['mfa_iat'] > 600) error_response('Your verification session expired — please sign in again.', 401);
        // Per-account lockout (stored in the database, so it can't be dodged by dropping cookies). BT90 (M-8): one
        // attempt at a time per account, and a code is accepted once (its time step is remembered).
        $mKey = 'mf|' . (int)$user['id'];
        if (!itops_auth_lock($db, $mKey)) error_response('Too many attempts at once. Please try again in a moment.', 429);
        if (itops_auth_fail_count($db, $mKey, 900) >= 6) { itops_auth_unlock($db, $mKey); audit_log('mfa_lockout', 'auth', $user['id'], $user['email']); error_response('Too many wrong codes. Please wait about 15 minutes and sign in again.', 429); }
        $code = trim((string)($body['code'] ?? ''));
        $ok = itops_totp_once($db, $user, $code) || mfa_consume_backup($db, $user, $code);
        if (!$ok) {
            itops_auth_fail_add($db, $mKey); itops_auth_unlock($db, $mKey);
            audit_log('mfa_failed', 'auth', $user['id'], $user['email']); error_response('That code is not valid (a code works once). Try the next code, or use a backup code.', 401);
        }
        itops_auth_fail_clear($db, $mKey); itops_auth_unlock($db, $mKey);
        itops_issue_login($db, $user);
        break;

    // ── 2FA management (authenticated) ───────────────────────
    case 'GET:mfa':
        if (!$currentUser) error_response('Not authenticated', 401);
        if ($sub === 'status') {
            json_response([
                'enabled' => (bool)$currentUser['mfa_enabled'],
                'provider' => $currentUser['auth_provider'],
                'can_enroll' => in_array($currentUser['auth_provider'], ['local','both'], true),
            ]);
        } else error_response('Not found', 404);
        break;

    case 'POST:mfa':
        if (!$currentUser) error_response('Not authenticated', 401);
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        if ($sub === 'setup') {
            if (!in_array($currentUser['auth_provider'], ['local','both'], true)) error_response('Two-factor authentication is managed by your identity provider.', 400);
            // Re-running setup replaces (and so turns off) the current 2FA — require proof first.
            if ((int)$currentUser['mfa_enabled'] === 1) {
                $c = trim((string)($body['code'] ?? ''));
                $ok = itops_recheck($db, $currentUser, 'mfa_setup', $c);   // BT90: throttled; a code works once
                if (!$ok) error_response('2FA is already on. Turn it off first (with a current code or your password), then set it up again.', 400);
            }
            $secret = totp_secret();
            $db->prepare('UPDATE itops_users SET mfa_secret=?, mfa_enabled=0 WHERE id=?')->execute([vault_encrypt($secret), $currentUser['id']]);
            audit_log((int)$currentUser['mfa_enabled'] === 1 ? 'mfa_disabled_by_setup' : 'mfa_setup_started', 'user', $currentUser['id'], $currentUser['email']);   // review CORE-8
            json_response(['secret' => $secret, 'otpauth' => totp_uri($secret, $currentUser['email'], itops_app_name())]);
        } elseif ($sub === 'enable') {
            if (empty($currentUser['mfa_secret'])) error_response('Start setup first.', 400);
            $ek = 'rc|mfa_enable|' . (int)$currentUser['id'];
            if (itops_auth_fail_count($db, $ek, 900) >= 5) error_response('Too many wrong codes. Please wait about 15 minutes and try again.', 429);
            $step = totp_match_step(vault_decrypt($currentUser['mfa_secret']), trim((string)($body['code'] ?? '')));
            if ($step === null) { itops_auth_fail_add($db, $ek); error_response('That code is not valid — check your authenticator and try again.', 400); }
            itops_auth_fail_clear($db, $ek);
            try { $db->prepare('UPDATE itops_users SET mfa_last_step=? WHERE id=?')->execute([$step, $currentUser['id']]); } catch (Throwable $e) {}
            $codes = []; $hashes = [];
            for ($i = 0; $i < 10; $i++) { $c = strtoupper(bin2hex(random_bytes(4))); $c = substr($c,0,4).'-'.substr($c,4,4); $codes[] = $c; $hashes[] = hash('sha256', $c); }
            $db->prepare('UPDATE itops_users SET mfa_enabled=1, mfa_backup_codes=? WHERE id=?')->execute([json_encode($hashes), $currentUser['id']]);
            $nEnd = itops_sessions_revoke($db, (int)$currentUser['id'], (string)($_SERVER['ITOPS_TOKEN'] ?? ''));   // review CORE-8: other sessions sign in again
            audit_log('mfa_enabled', 'user', $currentUser['id'], $currentUser['email'], null, ['Other sessions ended' => $nEnd]);
            json_response(['ok' => true, 'backup_codes' => $codes]);
        } elseif ($sub === 'disable') {
            $code = trim((string)($body['code'] ?? ''));
            $ok = itops_recheck($db, $currentUser, 'mfa_disable', $code);   // BT90: throttled; a code works once
            if (!$ok) error_response('Enter a current 2FA code (or your password) to turn it off.', 400);
            $db->prepare('UPDATE itops_users SET mfa_enabled=0, mfa_secret=NULL, mfa_backup_codes=NULL WHERE id=?')->execute([$currentUser['id']]);
            $nEnd = itops_sessions_revoke($db, (int)$currentUser['id'], (string)($_SERVER['ITOPS_TOKEN'] ?? ''));   // review CORE-8
            audit_log('mfa_disabled', 'user', $currentUser['id'], $currentUser['email'], null, ['Other sessions ended' => $nEnd]);
            json_response(['ok' => true]);
        } elseif ($sub === 'reset') {
            if (($currentUser['role_slug'] ?? '') !== 'super_admin') error_response('Insufficient permissions', 403);
            $uid = (int)($body['user_id'] ?? 0); $em = trim($body['email'] ?? '');
            if (!$uid && $em !== '') { $q = $db->prepare('SELECT id FROM itops_users WHERE email=?'); $q->execute([$em]); $uid = (int)$q->fetchColumn(); }
            if (!$uid) error_response('User not found', 404);
            $db->prepare('UPDATE itops_users SET mfa_enabled=0, mfa_secret=NULL, mfa_backup_codes=NULL WHERE id=?')->execute([$uid]);
            // BT90: the person's sessions end too (whoever was signed in with the old 2FA signs in again)
            $nRev = itops_sessions_revoke($db, $uid, $uid === (int)$currentUser['id'] ? (string)($_SERVER['ITOPS_TOKEN'] ?? '') : '');
            audit_log('mfa_reset', 'user', $uid, 'reset by ' . $currentUser['email'] . ' - ' . $nRev . ' session(s) ended');
            json_response(['ok' => true, 'sessions_ended' => $nRev]);
        } else error_response('Not found', 404);
        break;

    
    // ── O365 Auth URL ────────────────────────────────────────
    case 'GET:o365':
        if ($sub === 'url') {
            $cfg = o365_cfg();
            if (!o365_enabled()) error_response('Microsoft 365 SSO is not configured');

            $state = bin2hex(random_bytes(16));
            itops_set_cookie('o365_state', $state, time() + 600);

            $params = http_build_query([
                'client_id' => $cfg['client_id'],
                'response_type' => 'code',
                'redirect_uri' => $cfg['redirect'],
                'response_mode' => 'query',
                'scope' => 'openid profile email User.Read',
                'state' => $state,
                'prompt' => 'select_account',
            ]);
            $authUrl = "https://login.microsoftonline.com/" . $cfg['tenant'] . "/oauth2/v2.0/authorize?" . $params;
            json_response(['url' => $authUrl]);
        }
        break;
    
    // ── O365 Callback ────────────────────────────────────────
    case 'POST:o365':
        if ($sub === 'callback') {
            $body = json_decode(file_get_contents('php://input'), true);
            $code = $body['code'] ?? '';
            
            if (!$code) error_response('Authorization code required');
            $cfg = o365_cfg();
            if (!o365_enabled()) error_response('Microsoft 365 SSO is not configured');
            // BT14: the state must match the one we set when the sign-in started (blocks login-CSRF / code injection).
            $stCookie = (string)($_COOKIE['o365_state'] ?? '');
            if ($stCookie === '' || !hash_equals($stCookie, (string)($body['state'] ?? ''))) error_response('Microsoft sign-in expired or was not started here — please try again.', 400);
            itops_set_cookie('o365_state', '', time() - 3600);
            // BT14: single-tenant only — a multi-tenant authority would let any Microsoft account sign in.
            if (in_array(strtolower(trim((string)$cfg['tenant'])), ['', 'common', 'organizations', 'consumers'], true)) error_response('Microsoft sign-in needs your own tenant ID (not "common") — set it under Connection → Microsoft 365 sign-in.', 400);
            
            // Exchange code for token
            $tokenUrl = "https://login.microsoftonline.com/" . $cfg['tenant'] . "/oauth2/v2.0/token";
            $tokenData = http_build_query([
                'client_id' => $cfg['client_id'],
                'client_secret' => $cfg['secret'],
                'code' => $code,
                'redirect_uri' => $cfg['redirect'],
                'grant_type' => 'authorization_code',
                'scope' => 'openid profile email User.Read',
            ]);
            
            $ch = curl_init($tokenUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $tokenData,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,   // review CORE-15
            ]);
            $response = json_decode(curl_exec($ch), true);
            curl_close($ch);
            
            if (isset($response['error'])) {   // BT90: Microsoft's details go to the Audit Log, not to the screen
                audit_log('o365_login_rejected', 'auth', null, mb_substr((string)$response['error'] . ': ' . (string)($response['error_description'] ?? ''), 0, 240));
                error_response('Microsoft sign-in did not complete. Please try again; if it keeps failing, ask an administrator (the details are in the Audit Log).', 400);
            }
            
            $accessToken = $response['access_token'] ?? '';
            if (!$accessToken) error_response('No access token received');
            // BT14: the account must belong to OUR tenant. The id_token comes straight from Microsoft's token endpoint over
            // TLS (confidential-client code flow), so its claims can be read without re-verifying the signature here.
            $idc = [];
            if (!empty($response['id_token'])) { $pp = explode('.', (string)$response['id_token']); if (count($pp) >= 2) $idc = json_decode(base64_decode(strtr($pp[1], '-_', '+/') . str_repeat('=', (4 - strlen($pp[1]) % 4) % 4)), true) ?: []; }
            $tid = strtolower((string)($idc['tid'] ?? ''));
            $want = strtolower(trim((string)$cfg['tenant']));
            if (preg_match('/^[0-9a-f-]{36}$/', $want) && $tid !== $want) {
                audit_log('o365_login_rejected', 'auth', null, 'foreign tenant ' . $tid);
                error_response('This Microsoft account belongs to a different organization.', 403);
            }
            
            // Get user profile from Microsoft Graph
            $ch = curl_init('https://graph.microsoft.com/v1.0/me?$select=id,mail,userPrincipalName,displayName,givenName,surname,userType');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ["Authorization: Bearer $accessToken"],
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
            ]);
            $profile = json_decode(curl_exec($ch), true);
            curl_close($ch);
            
            if (!isset($profile['mail']) && !isset($profile['userPrincipalName'])) {
                error_response('Could not retrieve user profile from Microsoft');
            }
            
            $email = $profile['mail'] ?? $profile['userPrincipalName'];
            $oid = $profile['id'] ?? '';
            $displayName = ($profile['displayName'] ?? $email);
            $firstName = $profile['givenName'] ?? '';
            $lastName = $profile['surname'] ?? '';
            
            // Find or create user
            // Review CORE-15: the account linked to this Microsoft identity first, then the one with the address (as for Teams).
            $__sel = 'SELECT u.*, r.slug as role_slug, r.permissions as role_perms, d.name as dept_name FROM itops_users u LEFT JOIN itops_roles r ON u.role_id=r.id LEFT JOIN itops_departments d ON u.department_id=d.id WHERE ';
            $user = false;
            if ($oid !== '') { $stmt = $db->prepare($__sel . 'u.o365_oid=? LIMIT 1'); $stmt->execute([$oid]); $user = $stmt->fetch(); }
            if (!$user) { $stmt = $db->prepare($__sel . 'u.email=? LIMIT 1'); $stmt->execute([$email]); $user = $stmt->fetch(); }
            if ($user && $user['status'] !== 'active') { audit_log('o365_login_rejected', 'auth', (int)$user['id'], 'inactive account ' . $email); error_response('Account is inactive', 403); }
            // BT14: once an account is linked to a Microsoft identity (oid), only THAT identity may sign in to it —
            // a different Microsoft user who merely shows the same email address is refused.
            if ($user && $oid !== '' && !empty($user['o365_oid']) && !hash_equals((string)$user['o365_oid'], (string)$oid)) {
                audit_log('o365_login_rejected', 'auth', (int)$user['id'], 'oid mismatch for ' . $email);
                error_response('This Microsoft account is not the one linked to the ' . itops_app_name() . ' user ' . $email . '. Ask an administrator.', 403);
            }
            // BT90 (M-9): an existing account found by email is linked only for a member of the organization, never for an
            // administrator or an account with the app's 2FA — unless a Super Admin allowed this link.
            if ($user && empty($user['o365_oid'])) {
                $why = itops_sso_link_refusal($db, $user, (string)($profile['userType'] ?? ''));
                if ($why !== '') {
                    audit_log('o365_link_refused', 'auth', (int)$user['id'], mb_substr($email . ' - ' . $why, 0, 240));
                    error_response('Your Microsoft account could not be linked to the ' . itops_app_name() . ' user ' . $email . ' automatically. Sign in with your password, or ask a Super Admin to allow the link (Users → the person → "Allow Microsoft sign-in to link").', 403);
                }
            }
            
            if (!$user) {
                // BT124 (code review CORE-3): a new account is made on the first Microsoft sign-in only for a member of the
                // organization — never for a guest (vendors, partners) or when Microsoft's profile could not say which
                if (strcasecmp((string)($profile['userType'] ?? ''), 'Member') !== 0) {
                    audit_log('o365_provision_refused', 'auth', null, mb_substr($email . ' - not a member of the organization (' . ((string)($profile['userType'] ?? '') ?: 'type unknown') . ')', 0, 240));
                    error_response('This Microsoft account is not a member of the organization, so no ' . itops_app_name() . ' account is made for it. Ask an administrator to add you.', 403);
                }
                // Auto-provision with the default role (Viewer, found by its slug)
                $db->prepare('INSERT INTO itops_users (email, display_name, first_name, last_name, role_id, auth_provider, o365_oid, status) VALUES (?,?,?,?,?,"o365",?,"active")')
                   ->execute([$email, $displayName, $firstName, $lastName, itops_sso_default_role($db), $oid]);
                $userId = $db->lastInsertId();
                
                audit_log('o365_auto_provision', 'user', $userId, $email);
                
                // Re-fetch with joins
                $stmt = $db->prepare('SELECT u.*, r.slug as role_slug, r.permissions as role_perms, d.name as dept_name FROM itops_users u LEFT JOIN itops_roles r ON u.role_id=r.id LEFT JOIN itops_departments d ON u.department_id=d.id WHERE u.id=?');
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
            } else {
                // Update O365 OID if not set
                if (!$user['o365_oid']) {
                    $db->prepare('UPDATE itops_users SET o365_oid=?, auth_provider="both" WHERE id=?')->execute([$oid, $user['id']]);
                    try { $db->prepare('UPDATE itops_users SET o365_link_ok=0 WHERE id=?')->execute([$user['id']]); } catch (Throwable $e) {}
                    audit_log('o365_linked', 'user', (int)$user['id'], $email);
                }
            }
            
            if ($user['status'] !== 'active') error_response('Account is inactive', 403);
            
            // Update login stats
            $db->prepare('UPDATE itops_users SET last_login_at=NOW(), last_login_ip=?, login_count=login_count+1 WHERE id=?')
               ->execute([$_SERVER['REMOTE_ADDR'] ?? '', $user['id']]);
            itops_claim_tickets($db, (int)$user['id'], (string)$user['email']);   // e.g. a form ticket sent before their first Microsoft sign-in
            
            $token = jwt_encode([
                'uid' => (int)$user['id'],
                'email' => $user['email'],
                'role' => $user['role_slug'],
                'dept' => (int)$user['department_id'],
                'sid' => bin2hex(random_bytes(12))
            ]);
            
            itops_session_create($db, (int)$user['id'], $token);   // BT14: Microsoft sign-ins get a server-side session too
            itops_audit_signin($user, 'microsoft');   // BT99
            itops_set_cookie('itops_token', $token, time() + 8 * 3600);
            
            json_response(itops_login_token_out($token) + [
                'user' => [
                    'id' => (int)$user['id'],
                    'email' => $user['email'],
                    'display_name' => $user['display_name'],
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name'],
                    'avatar_path' => $user['avatar_path'],
                    'role' => $user['role_slug'],
                    'role_permissions' => itops_perm_overlay(json_decode($user['role_perms'], true), (int)$user['id']),
                    'department_id' => (int)$user['department_id'],
                    'department_name' => $user['dept_name'],
                    'auth_provider' => $user['auth_provider'],
                ]
            ]);
        }
        break;
    
    // ── BT29: sign-in from inside Microsoft Teams (Teams SSO) ─────────────────
    // Teams gives the tab a Microsoft Entra token for the signed-in person. It is verified (signature, tenant, audience,
    // scope) and then follows exactly the rules of the Microsoft 365 sign-in above: oid binding, auto-provision as
    // Viewer, inactive accounts refused, a server-side session.
    case 'POST:teams':
        require_once __DIR__ . '/../lib/TeamsApp.php';
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $jwt = trim((string)($body['token'] ?? ''));
        if ($jwt === '' || strlen($jwt) > 8192) error_response('Token required');
        if (!itops_teams_cfg($db)['enabled']) error_response('The Teams app is not switched on. A Super Admin can do that under Integrations → Microsoft Teams.', 403);
        $cfg = o365_cfg();
        if (!o365_enabled()) error_response('Microsoft 365 sign-in is not configured.', 400);
        try { $claims = itops_teams_verify($jwt, $cfg); }
        catch (Throwable $e) { audit_log('teams_login_rejected', 'auth', null, mb_substr($e->getMessage(), 0, 180)); error_response('Teams sign-in failed. Please try again; if it keeps failing, ask an administrator (the details are in the Audit Log).', 401); }
        $oid = strtolower((string)$claims['oid']);
        // The same profile source as the Microsoft 365 sign-in (mail, else sign-in name), read with the app's own Graph access;
        // the token's own claims are the fallback when Graph cannot be asked.
        $email = ''; $displayName = (string)($claims['name'] ?? ''); $firstName = (string)($claims['given_name'] ?? ''); $lastName = (string)($claims['family_name'] ?? '');
        $userType = ''; $fromGraph = false;
        try {
            require_once __DIR__ . '/../lib/Graph.php';
            if (function_exists('itops_graph_configured') && itops_graph_configured()) {
                [$gc, $gp] = itops_graph_http('GET', 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($oid) . '?$select=id,mail,userPrincipalName,displayName,givenName,surname,userType', null, [], 10);
                if ($gc === 200 && is_array($gp) && strtolower((string)($gp['id'] ?? '')) === $oid) {
                    $email = (string)($gp['mail'] ?? '') ?: (string)($gp['userPrincipalName'] ?? '');
                    $displayName = (string)($gp['displayName'] ?? $displayName); $firstName = (string)($gp['givenName'] ?? $firstName); $lastName = (string)($gp['surname'] ?? $lastName);
                    $userType = (string)($gp['userType'] ?? ''); $fromGraph = true;
                }
            }
        } catch (Throwable $e) { /* fall back to the claims */ }
        // BT90 (M-9): an email claim in the token is not proof (it can be unverified). Without Microsoft's own profile the
        // address is used only to create a new account or to find one already linked by its Microsoft id — never to link.
        if ($email === '') $email = (string)($claims['preferred_username'] ?? '') ?: (string)($claims['upn'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) error_response('Teams sign-in failed: your email address could not be read from Microsoft.', 400);
        if ($displayName === '') $displayName = $email;

        $sel = 'SELECT u.*, r.slug as role_slug, r.permissions as role_perms, d.name as dept_name FROM itops_users u LEFT JOIN itops_roles r ON u.role_id=r.id LEFT JOIN itops_departments d ON u.department_id=d.id ';
        $stmt = $db->prepare($sel . 'WHERE u.o365_oid=? LIMIT 1'); $stmt->execute([$oid]); $user = $stmt->fetch();     // an account already linked to this Microsoft identity wins
        if (!$user) { $stmt = $db->prepare($sel . 'WHERE u.email=? LIMIT 1'); $stmt->execute([$email]); $user = $stmt->fetch(); }
        if ($user && !empty($user['o365_oid']) && !hash_equals(strtolower((string)$user['o365_oid']), $oid)) {
            audit_log('teams_login_rejected', 'auth', (int)$user['id'], 'oid mismatch for ' . $email);
            error_response('This Microsoft account is not the one linked to the ' . itops_app_name() . ' user ' . $email . '. Ask an administrator.', 403);
        }
        if ($user && empty($user['o365_oid'])) {   // BT90 (M-9): the same linking rule as the Microsoft 365 sign-in
            $why = !$fromGraph && empty($user['o365_link_ok']) ? 'Microsoft\'s profile could not be read (the address in the Teams token is not proof)' : itops_sso_link_refusal($db, $user, $userType);
            if ($why !== '') {
                audit_log('o365_link_refused', 'auth', (int)$user['id'], mb_substr($email . ' (Teams) - ' . $why, 0, 240));
                error_response('Your Microsoft account could not be linked to the ' . itops_app_name() . ' user ' . $email . ' automatically. Ask a Super Admin to allow the link (Users → the person → "Allow Microsoft sign-in to link").', 403);
            }
        }
        if (!$user) {
            if (!$fromGraph || strcasecmp((string)$userType, 'Member') !== 0) {   // BT124 (code review CORE-3)
                audit_log('o365_provision_refused', 'auth', null, mb_substr($email . ' (Teams) - ' . (!$fromGraph ? 'Microsoft\'s profile could not be read' : 'not a member of the organization'), 0, 240));
                error_response('No ' . itops_app_name() . ' account is made for this Microsoft account (it is not a member of the organization, or Microsoft\'s profile could not be read). Ask an administrator to add you.', 403);
            }
            $db->prepare('INSERT INTO itops_users (email, display_name, first_name, last_name, role_id, auth_provider, o365_oid, status) VALUES (?,?,?,?,?,"o365",?,"active")')
               ->execute([$email, $displayName, $firstName, $lastName, itops_sso_default_role($db), $oid]);
            $userId = (int)$db->lastInsertId();
            audit_log('o365_auto_provision', 'user', $userId, $email . ' (from Teams)');
            $stmt = $db->prepare($sel . 'WHERE u.id=?'); $stmt->execute([$userId]); $user = $stmt->fetch();
        } elseif (!$user['o365_oid']) {
            $db->prepare('UPDATE itops_users SET o365_oid=?, auth_provider="both" WHERE id=?')->execute([$oid, $user['id']]);
            try { $db->prepare('UPDATE itops_users SET o365_link_ok=0 WHERE id=?')->execute([$user['id']]); } catch (Throwable $e) {}
            audit_log('o365_linked', 'user', (int)$user['id'], $email . ' (Teams)');
            $user['o365_oid'] = $oid; $user['auth_provider'] = 'both';
        }
        if ($user['status'] !== 'active') error_response('Account is inactive', 403);
        $db->prepare('UPDATE itops_users SET last_login_at=NOW(), last_login_ip=?, login_count=login_count+1 WHERE id=?')->execute([$_SERVER['REMOTE_ADDR'] ?? '', $user['id']]);
        itops_claim_tickets($db, (int)$user['id'], (string)$user['email']);
        $token = jwt_encode(['uid' => (int)$user['id'], 'email' => $user['email'], 'role' => $user['role_slug'], 'dept' => (int)$user['department_id'], 'sid' => bin2hex(random_bytes(12))]);
        itops_session_create($db, (int)$user['id'], $token);
        itops_audit_signin($user, 'teams');   // BT99
        // No cookie: inside Teams the page is a third-party frame, so the session travels in the X-ITOps-Token header only.
        json_response(['token' => $token, 'user' => [
            'id' => (int)$user['id'], 'email' => $user['email'], 'display_name' => $user['display_name'], 'first_name' => $user['first_name'], 'last_name' => $user['last_name'],
            'avatar_path' => $user['avatar_path'], 'role' => $user['role_slug'], 'role_permissions' => itops_perm_overlay(json_decode((string)$user['role_perms'], true), (int)$user['id']),
            'department_id' => (int)$user['department_id'], 'department_name' => $user['dept_name'], 'auth_provider' => $user['auth_provider'],
            'mfa_enabled' => (bool)$user['mfa_enabled'], 'mfa_enrollment_required' => itops_mfa_pending($db, $user), 'terms_required' => itops_tou_required($db, $user)]]);
        break;

    // ── BT29: the device this person opened the app on (Device inventory) ─────
    case 'POST:client':
        if (!$currentUser || empty($currentUser['id'])) error_response('Not authenticated', 401);
        require_once __DIR__ . '/../lib/TeamsApp.php';
        require_once __DIR__ . '/../lib/DeviceInventory.php';
        if (!itops_teams_cfg($db)['inventory']) json_response(['ok' => false, 'reason' => 'off']);
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $f = [];
        foreach (['host', 'client_type', 'platform', 'platform_version', 'browser'] as $k) $f[$k] = mb_substr(preg_replace('/[^\x20-\x7e]/', '', (string)($body[$k] ?? '')), 0, 60);
        $f['touch'] = (int)($body['touch'] ?? 0);
        try { json_response(itops_dinv_client_report($db, $currentUser, $f, substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400), (string)($_SERVER['REMOTE_ADDR'] ?? ''))); }
        catch (Throwable $e) { json_response(['ok' => false, 'reason' => 'error']); }
        break;

    // ── Get Current User ─────────────────────────────────────
    case 'GET:me':
        if (!$currentUser) error_response('Not authenticated', 401);
        json_response([
            'user' => [
                'id' => (int)$currentUser['id'],
                'email' => $currentUser['email'],
                'display_name' => $currentUser['display_name'],
                'first_name' => $currentUser['first_name'],
                'last_name' => $currentUser['last_name'],
                'avatar_path' => $currentUser['avatar_path'],
                'role' => $currentUser['role_slug'],
                'role_permissions' => itops_perm_overlay(json_decode($currentUser['role_perms'], true), (int)$currentUser['id']),
                'department_id' => (int)$currentUser['department_id'],
                'department_name' => $currentUser['dept_name'],
                'auth_provider' => $currentUser['auth_provider'],
                'mfa_enabled' => (bool)$currentUser['mfa_enabled'],
                'terms_required' => itops_tou_required($db, $currentUser),   // BT97
                'language' => $currentUser['language'] ?? null,
                'ui_prefs' => itops_ui_prefs($currentUser),   // BT108
                'role_name' => (function () use ($db, $currentUser) { try { $q = $db->prepare('SELECT name FROM itops_roles WHERE id=?'); $q->execute([(int)($currentUser['role_id'] ?? 0)]); return (string)($q->fetchColumn() ?: ''); } catch (Throwable $e) { return ''; } })(),
            ]
        ]);
        break;

    // ── Update own preferences (email language, BT108 screen preferences) ─────────────
    case 'PUT:me':
        if (!$currentUser) error_response('Not authenticated', 401);
        $pb = json_decode(file_get_contents('php://input'), true) ?: [];
        // BT103: my own name (local accounts; Microsoft 365 accounts get it from Microsoft)
        $nameKeys = array_values(array_intersect(['display_name', 'first_name', 'last_name'], array_keys($pb)));
        if ($nameKeys) {
            if ((string)$currentUser['auth_provider'] !== 'local') error_response('Your name comes from Microsoft 365: change it there, or ask an administrator.', 400);
            $set = []; $old = []; $new = [];
            foreach ($nameKeys as $k) {
                $v = $pb[$k]; if (!is_string($v)) error_response('Names are text.', 400);
                $v = trim(preg_replace('/[\x00-\x1F\x7F<>]/u', '', $v));
                if (mb_strlen($v) > 100) error_response('Names are at most 100 characters.', 400);
                if ($k === 'display_name' && $v === '') error_response('The display name cannot be empty.', 400);
                if ((string)$currentUser[$k] !== $v) { $set[$k] = $v; $old[$k] = (string)$currentUser[$k]; $new[$k] = $v; }
            }
            if ($set) {
                $db->prepare('UPDATE itops_users SET ' . implode(',', array_map(fn($k) => $k . '=?', array_keys($set))) . ' WHERE id=?')->execute(array_merge(array_values($set), [(int)$currentUser['id']]));
                audit_log('profile_updated', 'user', (int)$currentUser['id'], (string)($set['display_name'] ?? $currentUser['display_name']), $old, $new);
            }
        }
        if (array_key_exists('ui_prefs', $pb)) {   // BT108: screen preferences, only the known keys and values
            $allowed = ['tc_view' => ['grid', 'cards']];
            $in = is_array($pb['ui_prefs']) ? $pb['ui_prefs'] : [];
            $cur = itops_ui_prefs($currentUser); $chg = [];
            foreach ($in as $k => $v) {
                if (!isset($allowed[$k]) || !in_array($v, $allowed[$k], true)) {
                    audit_log('user_ui_pref_refused', 'user', (int)$currentUser['id'], $currentUser['display_name'] ?? '', null, ['key' => (string)$k, 'value' => is_scalar($v) ? (string)$v : '(not text)']);
                    error_response('Unknown preference.', 400);
                }
                if (($cur[$k] ?? null) !== $v) { $chg[$k] = [$cur[$k] ?? null, $v]; $cur[$k] = $v; }
            }
            if ($chg) {
                try { $db->prepare('UPDATE itops_users SET ui_prefs=? WHERE id=?')->execute([json_encode($cur), (int)$currentUser['id']]); }
                catch (Throwable $e) { error_response('Run db-update.php once to enable screen preferences.', 409); }
                audit_log('user_ui_pref', 'user', (int)$currentUser['id'], $currentUser['display_name'] ?? '', array_map(fn($x) => $x[0], $chg), array_map(fn($x) => $x[1], $chg));
            }
        }
        if (array_key_exists('language', $pb)) {
            $lang = (string)$pb['language'];
            if (!in_array($lang, ['', 'en', 'es'], true)) error_response('Language must be en, es or empty (organisation default)', 400);
            try { getDB()->prepare('UPDATE itops_users SET language=? WHERE id=?')->execute([$lang === '' ? null : $lang, (int)$currentUser['id']]); }
            catch (Throwable $e) { error_response('Run db-update.php once to enable email language preferences.', 409); }
            audit_log('user_language', 'user', (int)$currentUser['id'], $currentUser['display_name'] ?? '', null, ['language' => $lang ?: 'default']);
        }
        json_response(['ok' => true]);
        break;
    
    // ── BT103: My profile ────────────────────────────────────
    // GET /auth/profile: my details, sign-in, recent sign-ins. PUT /auth/me also takes display_name / first_name /
    // last_name for local accounts (Microsoft 365 accounts get their names from Microsoft).
    case 'GET:profile':
        if (!$currentUser) error_response('Not authenticated', 401);
        $rn = ''; try { $q = $db->prepare('SELECT name FROM itops_roles WHERE id=?'); $q->execute([(int)$currentUser['role_id']]); $rn = (string)$q->fetchColumn(); } catch (Throwable $e) {}
        $recent = [];
        try {
            $q = $db->prepare("SELECT action, new_value, ip_address, created_at FROM itops_audit_log WHERE entity_type='auth' AND entity_id=? AND action IN ('login_success','login_first','o365_login','teams_login','password_changed','logout_everywhere','session_ended') ORDER BY id DESC LIMIT 12");
            $q->execute([(int)$currentUser['id']]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $nv = json_decode((string)$r['new_value'], true); $recent[] = ['action' => $r['action'], 'how' => is_array($nv) ? (string)($nv['Signed in with'] ?? '') : '', 'ip' => (string)$r['ip_address'], 'at' => $r['created_at']]; }
        } catch (Throwable $e) {}
        $prov = (string)$currentUser['auth_provider'];
        json_response(['id' => (int)$currentUser['id'], 'email' => $currentUser['email'], 'display_name' => $currentUser['display_name'], 'first_name' => $currentUser['first_name'],
            'last_name' => $currentUser['last_name'], 'role' => $currentUser['role_slug'], 'role_name' => $rn, 'department_name' => $currentUser['dept_name'], 'auth_provider' => $prov,
            'has_password' => !empty($currentUser['password_hash']), 'can_change_password' => in_array($prov, ['local', 'both'], true) && !empty($currentUser['password_hash']),
            'can_edit_name' => $prov === 'local', 'mfa_enabled' => (bool)$currentUser['mfa_enabled'], 'language' => $currentUser['language'] ?? null,
            'last_login_at' => $currentUser['last_login_at'], 'login_count' => (int)$currentUser['login_count'], 'created_at' => $currentUser['created_at'], 'recent' => $recent]);
        break;

    // POST /auth/password {current, new}: change my own password (local accounts). Ends my other sessions.
    case 'POST:password':
        if (!$currentUser) error_response('Not authenticated', 401);
        itops_require_json_body();
        $pb = json_decode(file_get_contents('php://input'), true);
        if (!is_array($pb) || !is_string($pb['current'] ?? null) || !is_string($pb['new'] ?? null)) error_response('Send {current, new}.', 400);
        if (!in_array((string)$currentUser['auth_provider'], ['local', 'both'], true) || empty($currentUser['password_hash']))
            error_response('Your sign-in is handled by Microsoft 365: change your password there (https://myaccount.microsoft.com).', 400);
        $pk = 'pw|' . (int)$currentUser['id'];
        if (itops_auth_fail_count($db, $pk, 900) >= 5) { audit_log('password_change_refused', 'auth', (int)$currentUser['id'], (string)$currentUser['email'], null, ['Why' => 'too many wrong current passwords']); error_response('Too many wrong passwords. Please wait about 15 minutes and try again.', 429); }
        if (!verify_password($pb['current'], (string)$currentUser['password_hash'])) {
            itops_auth_fail_add($db, $pk);
            audit_log('password_change_refused', 'auth', (int)$currentUser['id'], (string)$currentUser['email'], null, ['Why' => 'current password wrong']);
            error_response('Your current password is not right.', 400);
        }
        $problem = verify_password($pb['new'], (string)$currentUser['password_hash']) ? 'Choose a password different from the current one.'
                 : itops_password_problem($pb['new'], (string)$currentUser['email'], (string)$currentUser['display_name']);
        if ($problem !== '') error_response($problem, 400);
        itops_auth_fail_clear($db, $pk);
        $db->prepare('UPDATE itops_users SET password_hash=? WHERE id=?')->execute([hash_password($pb['new']), (int)$currentUser['id']]);
        $nEnd = itops_sessions_revoke($db, (int)$currentUser['id'], (string)($_SERVER['ITOPS_TOKEN'] ?? ''));
        audit_log('password_changed', 'auth', (int)$currentUser['id'], (string)$currentUser['email'], null, ['By' => 'the person themselves', 'Other sessions ended' => $nEnd]);
        json_response(['ok' => true, 'sessions_ended' => $nEnd]);
        break;

    // GET /auth/sessions: where I am signed in. DELETE /auth/sessions/:id ends one of them.
    case 'GET:sessions':
        if (!$currentUser) error_response('Not authenticated', 401);
        $cur = hash('sha256', (string)($_SERVER['ITOPS_TOKEN'] ?? ''));
        $q = $db->prepare('SELECT id, token_hash, ip_address, user_agent, created_at, last_seen_at, expires_at FROM itops_sessions WHERE user_id=? AND expires_at>=? ORDER BY COALESCE(last_seen_at, created_at) DESC');
        $q->execute([(int)$currentUser['id'], date('Y-m-d H:i:s')]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = ['id' => (int)$r['id'], 'current' => hash_equals($cur, (string)$r['token_hash']), 'ip' => (string)$r['ip_address'],
            'device' => itops_ua_label((string)$r['user_agent']), 'started' => $r['created_at'], 'last_seen' => $r['last_seen_at'] ?: $r['created_at']];
        json_response(['data' => $out]);
        break;
    case 'DELETE:sessions':
        if (!$currentUser) error_response('Not authenticated', 401);
        if (!ctype_digit((string)$sub)) error_response('Unknown session.', 404);
        $q = $db->prepare('SELECT token_hash, ip_address, user_agent FROM itops_sessions WHERE id=? AND user_id=?'); $q->execute([(int)$sub, (int)$currentUser['id']]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) error_response('Unknown session.', 404);   // someone else's session is "unknown" too
        $db->prepare('DELETE FROM itops_sessions WHERE id=? AND user_id=?')->execute([(int)$sub, (int)$currentUser['id']]);
        $isCur = hash_equals(hash('sha256', (string)($_SERVER['ITOPS_TOKEN'] ?? '')), (string)$row['token_hash']);
        audit_log('session_ended', 'auth', (int)$currentUser['id'], (string)$currentUser['email'], null, ['Device' => itops_ua_label((string)$row['user_agent']), 'Address' => (string)$row['ip_address'], 'This browser' => $isCur ? 'yes' : 'no']);
        if ($isCur) { require_once __DIR__ . '/../lib/AuthGuard.php'; itops_set_cookie('itops_token', '', time() - 3600); }   // review CORE-28: this browser is signed out fully
        json_response(['ok' => true, 'signed_out' => $isCur]);
        break;

    // ── Logout ───────────────────────────────────────────────
    case 'POST:logout':
        if ($currentUser) {
            // End the server-side session for whichever token this request used (app header, Bearer or cookie).
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            $tok = (string)($_SERVER['HTTP_X_ITOPS_TOKEN'] ?? '');
            if ($tok === '' && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) $tok = $m[1];
            if ($tok === '' && !empty($_COOKIE['itops_token'])) $tok = (string)$_COOKIE['itops_token'];
            $lb = json_decode(file_get_contents('php://input'), true) ?: [];
            if (!empty($lb['everywhere'])) {   // "Sign out of all devices"
                $n = itops_sessions_revoke($db, (int)$currentUser['id']);
                audit_log('logout_everywhere', 'auth', $currentUser['id'], $currentUser['email'] . ' (' . $n . ' sessions)');
            } else {
                if ($tok !== '') $db->prepare('DELETE FROM itops_sessions WHERE token_hash=?')->execute([hash('sha256', $tok)]);
                audit_log('logout', 'auth', $currentUser['id'], $currentUser['email']);
            }
        }
        // Clear the auth cookie
        itops_set_cookie('itops_token', '', time() - 3600);
        // BT147: and (setting on by default) ask the browser to delete what it kept for this site on this device — cached
        // files, saved settings, the installed app's offline page and its helper. Cookies of other sites are not touched.
        try { require_once __DIR__ . '/../lib/AppInstall.php'; if (itops_ai_cfg($db ?? getDB())['wipe_signout']) header('Clear-Site-Data: "cache", "storage"'); } catch (Throwable $e) {}
        json_response(['ok' => true]);
        break;
    
    default:
        error_response('Not found', 404);
}
