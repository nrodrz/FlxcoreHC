<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — sign-in guards (BT90, code audit M-8, M-9 and the sign-in Lows).
 *
 *  - Throttles kept in opsh_auth_throttle (one row per failure, key + time), cleaned as they age. Sign-in counts failures
 *    per account AND per address; each account's attempts are taken one at a time (a named database lock), so parallel
 *    guesses cannot slip past the limit.
 *  - A 2FA code is accepted once: the time step it belongs to is remembered (opsh_users.mfa_last_step).
 *  - Re-checks of a password or 2FA code (2FA set-up and switch-off, revealing a vault secret) are throttled too.
 *  - "Admin" for these rules is decided by what a role can do (opsh_user_is_admin), not by its name.
 *  - The session cookie: HttpOnly, SameSite=Lax, Secure whenever the app runs on https (APP_URL or the request).
 */
require_once __DIR__ . '/Totp.php';

if (!function_exists('opsh_auth_fail_count')) {

    function opsh_auth_fail_count(PDO $db, string $k, int $windowSec): int {
        try { $q = $db->prepare('SELECT COUNT(*) FROM opsh_auth_throttle WHERE k=? AND at>?'); $q->execute([$k, date('Y-m-d H:i:s', time() - $windowSec)]); return (int)$q->fetchColumn(); }
        catch (Throwable $e) { return 0; }   // table arrives with the database update
    }
    function opsh_auth_fail_add(PDO $db, string $k): void {
        try {
            $db->prepare('INSERT INTO opsh_auth_throttle (k, at) VALUES (?, ?)')->execute([$k, date('Y-m-d H:i:s')]);
            if (mt_rand(1, 50) === 1) $db->prepare('DELETE FROM opsh_auth_throttle WHERE at<?')->execute([date('Y-m-d H:i:s', time() - 86400)]);   // rows older than a day go
        } catch (Throwable $e) {}
    }
    function opsh_auth_fail_clear(PDO $db, string $k): void {
        try { $db->prepare('DELETE FROM opsh_auth_throttle WHERE k=?')->execute([$k]); } catch (Throwable $e) {}
    }
    /** One attempt at a time for this key (waits up to $wait seconds for the one before to finish). */
    function opsh_auth_lock(PDO $db, string $name, int $wait = 8): bool {
        try { return (int)$db->query('SELECT GET_LOCK(' . $db->quote('opsh_auth_' . substr(hash('sha256', $name), 0, 40)) . ', ' . (int)$wait . ')')->fetchColumn() === 1; }
        catch (Throwable $e) { return true; }
    }
    function opsh_auth_unlock(PDO $db, string $name): void {
        try { $db->query('SELECT RELEASE_LOCK(' . $db->quote('opsh_auth_' . substr(hash('sha256', $name), 0, 40)) . ')'); } catch (Throwable $e) {}
    }
    function opsh_auth_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45); }

    /**
     * A 2FA code, accepted once: the code's time step must be newer than the last one used by this account.
     * The step is claimed with one conditional UPDATE, so two requests with the same code cannot both pass.
     */
    function opsh_totp_once(PDO $db, array $user, string $code): bool {
        if (empty($user['mfa_secret'])) return false;
        $step = totp_match_step(vault_decrypt($user['mfa_secret']), $code);
        if ($step === null) return false;
        try {
            $u = $db->prepare('UPDATE opsh_users SET mfa_last_step=? WHERE id=? AND (mfa_last_step IS NULL OR mfa_last_step<?)');
            $u->execute([$step, (int)$user['id'], $step]);
            return $u->rowCount() === 1;
        } catch (Throwable $e) { return true; }   // column arrives with the database update
    }

    /**
     * Re-check of a password or a 2FA code for something sensitive ($kind: mfa_setup, mfa_disable, vault ...).
     * At most 5 wrong answers per account and kind in 15 minutes; the 6th is refused without checking (429).
     * $allowPassword: the answer may be the account's password; $allowCode: a current 2FA code (used once).
     */
    function opsh_recheck(PDO $db, array $user, string $kind, string $answer, bool $allowPassword = true, bool $allowCode = true): bool {
        $k = 'rc|' . $kind . '|' . (int)$user['id'];
        if (opsh_auth_fail_count($db, $k, 900) >= 5) {
            if (function_exists('audit_log')) audit_log('recheck_locked', 'auth', (int)$user['id'], $kind);
            error_response('Too many wrong answers. Please wait about 15 minutes and try again.', 429);
        }
        $ok = false;
        if ($answer !== '') {
            if ($allowCode && (int)($user['mfa_enabled'] ?? 0) === 1 && preg_match('/^\s*\d{6}\s*$/', $answer)) $ok = opsh_totp_once($db, $user, $answer);
            if (!$ok && $allowPassword && !empty($user['password_hash'])) $ok = verify_password($answer, $user['password_hash']);
        }
        if ($ok) opsh_auth_fail_clear($db, $k); else opsh_auth_fail_add($db, $k);
        return $ok;
    }

    /**
     * Is this person an administrator for the sign-in rules (2FA required for admins; no automatic Microsoft linking)?
     * Decided by what the role can do: Super Admin, or any of the user, role, settings, integration, vault or
     * Microsoft 365 / vTiger account permissions that let a person act for others.
     */
    function opsh_user_is_admin(array $user): bool {
        if (($user['role_slug'] ?? '') === 'super_admin') return true;
        if (!function_exists('opsh_can')) return false;
        foreach ([['users', 'create'], ['users', 'edit'], ['users', 'delete'], ['settings', 'edit'], ['integrations', 'edit'], ['creds', 'view'],
                  ['m365_users', 'edit'], ['m365_credentials', 'edit'], ['m365_roles', 'edit'], ['m365_admin', 'edit'], ['vtiger_users', 'edit'],
                  ['vtiger_credentials', 'edit'], ['provision_offboard', 'edit'], ['email_cfg', 'edit'], ['access', 'edit']] as [$m, $a])
            if (opsh_can($user, $m, $a)) return true;
        return false;
    }
    /** The same question for an account row that has no role details loaded yet. */
    function opsh_account_is_admin(PDO $db, int $uid): bool {
        $q = $db->prepare('SELECT u.*, r.slug AS role_slug, r.permissions AS role_perms FROM opsh_users u LEFT JOIN opsh_roles r ON r.id=u.role_id WHERE u.id=?'); $q->execute([$uid]);
        $u = $q->fetch(PDO::FETCH_ASSOC);
        return $u ? opsh_user_is_admin($u) : false;
    }

    /**
     * M-9: may a Microsoft sign-in (web or Teams) link itself to this existing account found by email?
     * Only a member of the organization's Microsoft 365 (not a guest), and only an account that is not an admin and has
     * no app 2FA — unless a Super Admin allowed this one link (opsh_users.o365_link_ok, used once).
     * Returns '' when it may, else the reason.
     */
    function opsh_sso_link_refusal(PDO $db, array $user, string $userType): string {
        if (!empty($user['o365_link_ok'])) return '';
        if (strcasecmp($userType, 'Member') !== 0) return 'the Microsoft account is not a member of the organization (' . ($userType !== '' ? $userType : 'type unknown') . ')';
        if ((int)($user['mfa_enabled'] ?? 0) === 1) return 'the account uses the app\'s two-factor sign-in';
        if (opsh_user_is_admin($user)) return 'the account is an administrator';
        return '';
    }

    /** The role a new Microsoft sign-in gets: the Viewer role, found by its slug (falls back to the role with id 5). */
    function opsh_sso_default_role(PDO $db): int {
        try { $q = $db->query("SELECT id FROM opsh_roles WHERE slug='viewer' LIMIT 1"); $id = (int)$q->fetchColumn(); if ($id) return $id; } catch (Throwable $e) {}
        return 5;
    }

    /** The session cookie is Secure when the app's address is https, or this request came over https. */
    function opsh_cookie_secure(): bool {
        if (defined('APP_URL') && stripos((string)APP_URL, 'https://') === 0) return true;
        return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    }
    function opsh_set_cookie(string $name, string $value, int $expires): void {
        setcookie($name, $value, ['expires' => $expires, 'path' => '/', 'secure' => opsh_cookie_secure(), 'httponly' => true, 'samesite' => 'Lax']);
    }

    /** Requests that change sign-in state are JSON only (a cross-site form post cannot sign anyone in). */
    function opsh_require_json_body(): void {
        $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '')));
        if (strpos($ct, 'application/json') !== 0) error_response('Send this request as JSON (Content-Type: application/json).', 415);
    }
}

if (!function_exists('opsh_password_problem')) {
    /** BT103: what is wrong with a new password ('' = fine). 12 to 200 characters, a letter and a digit, not the
     *  email's name, not one of the passwords every attacker tries first. */
    function opsh_password_problem(string $pw, string $email = '', string $name = ''): string {
        $len = mb_strlen($pw);
        if ($len < 12) return 'Use at least 12 characters (a short sentence is easy to remember).';
        if ($len > 200) return 'Use at most 200 characters.';
        if (!preg_match('/\p{L}/u', $pw) || !preg_match('/\d/', $pw)) return 'Use at least one letter and one number.';
        $low = mb_strtolower($pw);
        $local = mb_strtolower((string)strstr($email, '@', true));
        if ($local !== '' && mb_strlen($local) >= 4 && mb_strpos($low, $local) !== false) return 'Do not use your email name in the password.';
        foreach (preg_split('/\s+/', mb_strtolower(trim($name))) as $part) if (mb_strlen($part) >= 4 && mb_strpos($low, $part) !== false) return 'Do not use your name in the password.';
        foreach (['password', 'passw0rd', 'qwerty', '123456', 'welcome', 'letmein', 'opshub', 'iloveyou', 'admin123', 'abc123'] as $w) if (mb_strpos($low, $w) !== false) return 'That password is too easy to guess: avoid common words like "' . $w . '".';
        return '';
    }
    /** BT103: "Chrome on Windows" from a browser's user agent (for the list of sessions). */
    function opsh_ua_label(string $ua): string {
        if ($ua === '') return 'Unknown device';
        $b = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/Teams\//', $ua) ? 'Teams' : (preg_match('/OPR\/|Opera/', $ua) ? 'Opera' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Safari\//', $ua) ? 'Safari' : (preg_match('/curl|python|HeadlessChrome/i', $ua) ? 'Script or automated browser' : 'Browser'))))));
        $o = preg_match('/Windows/', $ua) ? 'Windows' : (preg_match('/iPhone|iPad/', $ua) ? 'iPhone / iPad' : (preg_match('/Android/', $ua) ? 'Android' : (preg_match('/Mac OS X|Macintosh/', $ua) ? 'Mac' : (preg_match('/Linux/', $ua) ? 'Linux' : ''))));
        return $b . ($o !== '' ? ' on ' . $o : '');
    }
}
