<?php
declare(strict_types=1);

namespace FlxCoreHC\Auth;

use FlxCoreHC\Database;
use FlxCoreHC\Crypto;
use FlxCoreHC\TeamService;
use FlxCoreHC\RoleService;
use FlxCoreHC\SettingsService;
use FlxCoreHC\MailService;
use FlxCoreHC\I18n;
use PDO;

final class AuthService
{
    /** org_shape => [vertical, plan_tier] */
    public const ORG_SHAPES = [
        'billing_company' => ['healthcare', 'agency', 'Billing / credentialing company (manages many providers)'],
        'medical_group' => ['healthcare', 'small_group', 'Medical group / practice with providers'],
        'hospital'      => ['healthcare', 'agency',      'Hospital / health system (providers by department)'],
        'solo_practice' => ['healthcare', 'solo',        "Solo doctor's practice"],
        'platform'      => ['platform',   'platform',    'SaaS platform administrator'],
    ];

    /**
     * Create tenant + owner user + starter holder records in one transaction.
     * Returns the new user row, or a string error message.
     */
    public function registerTenant(
        string $orgName,
        string $orgShape,
        string $fullName,
        string $email,
        string $password,
        string $passwordConfirm = '',
        string $contactPhone = '',
        string $website = '',
        bool $allowPlatform = false,
        string $operatingState = ''
    ): array|string {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Enter a valid email address.';
        }
        if (strlen($password) < 10) {
            return 'Password must be at least 10 characters.';
        }
        if ($passwordConfirm !== '' && $password !== $passwordConfirm) {
            return 'The two passwords do not match.';
        }
        // 'platform' is an internal shape (SaaS operator) — it must never be self-assignable
        // through public sign-up, or anyone could register themselves a platform account.
        // The edition lock additionally rejects shapes from the other vertical, so a
        // single-vertical app can never grow tenants of the other industry.
        // The installer creates the one SaaS operator tenant by passing $allowPlatform=true; public
        // sign-up never can (the default), so nobody can self-register a platform account.
        $isPlatform = $orgShape === 'platform';
        if (!isset(self::ORG_SHAPES[$orgShape]) || ($isPlatform && !$allowPlatform)
            || (!$isPlatform && !in_array($orgShape, edition_shapes(), true))) {
            return 'Choose an organization type.';
        }
        if (trim($orgName) === '' || !TeamService::validFullName($fullName)) {
            return 'Enter your organization name and your real first and last name.';
        }

        $pdo = Database::pdo();
        // No global email check any more: a brand-new organization gets a brand-new
        // tenant, so the same person can legitimately own or work for several agencies.
        // Uniqueness is enforced per tenant by uq_users_tenant_email.

        [$vertical, $tier] = self::ORG_SHAPES[$orgShape];
        // The SaaS operator's own top-level account keeps org_shape='platform' (for console powers)
        // but should also BE a usable workspace in the edition it sells — otherwise every
        // vertical-gated feature is hidden from the operator. Give it the edition's vertical.
        if ($orgShape === 'platform' && FLXCOREHC_EDITION !== 'both') {
            $vertical = FLXCOREHC_EDITION;
        }
        $now      = utc_now();
        $tenantId = uuid4();
        $userId   = uuid4();

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO tenants (id, name, vertical, org_shape, plan_tier, status, contact_email, contact_phone, website, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            )->execute([$tenantId, trim($orgName), $vertical, $orgShape, $tier, 'pending',
                $email,
                trim($contactPhone) !== '' ? substr(trim($contactPhone), 0, 40) : null,
                trim($website) !== '' ? substr(trim($website), 0, 190) : null,
                $now]);
            // v1.170.7 — jurisdiction chosen at sign-up drives which state-specific requirements apply
            // (PR catalog rows are hidden from an FL-default tenant). Unknown/blank keeps the column default.
            $__st = strtoupper(trim($operatingState));
            if ($__st !== '' && isset(\FlxCoreHC\CatalogService::STATES[$__st])) {
                $pdo->prepare('UPDATE tenants SET operating_states = ? WHERE id = ?')->execute([$__st, $tenantId]);
            }

            // Starter holders so the account isn't empty on first login:
            $holderId = null;
            $nameParts = preg_split('/\s+/', trim($fullName), 2);
            if ($orgShape === 'solo_practice') {
                // The owner IS the credential holder.
                $holderId = uuid4();
                $pdo->prepare(
                    'INSERT INTO credential_holders (id, tenant_id, holder_type, first_name, last_name, email_enc, vertical, status, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $holderId, $tenantId, 'individual',
                    $nameParts[0], $nameParts[1] ?? '', Crypto::encrypt($email, $tenantId . '|credential_holders|email_enc'), $vertical, 'active', $now,
                ]);
            }
            if ($orgShape === 'billing_company' || $orgShape === 'medical_group' || $orgShape === 'solo_practice') {
                // The org itself holds org-level credentials (group license, EIN, CLIA...).
                $pdo->prepare(
                    'INSERT INTO credential_holders (id, tenant_id, holder_type, org_name, vertical, status, created_at)
                     VALUES (?,?,?,?,?,?,?)'
                )->execute([uuid4(), $tenantId, 'organization', trim($orgName), $vertical, 'active', $now]);
            }

            $pdo->prepare(
                'INSERT INTO users (id, tenant_id, email, password_hash, full_name, role, holder_id, status, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $userId, $tenantId, $email,
                password_hash($password, PASSWORD_ARGON2ID),
                trim($fullName), 'tenant_owner', $holderId, 'active', $now,
            ]);

            $pdo->prepare(
                'INSERT INTO audit_logs (id, tenant_id, user_id, action, entity_type, entity_id, changes, ip_address, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                uuid4(), $tenantId, $userId, 'create', 'tenants', $tenantId,
                json_encode(['org_shape' => $orgShape, 'vertical' => $vertical]),
                $_SERVER['REMOTE_ADDR'] ?? null, $now,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Give the new organization the starter compliance templates (same set the
        // migration seeds into existing tenants). Best-effort: a template-less org is
        // still usable, so a failure here must not kill a signup.
        try { self::seedTemplatesFor($tenantId); } catch (\Throwable $e) { /* non-fatal */ }

        return $this->loadUser($userId);
    }

    /** Copy the starter templates into a brand-new tenant (mirrors migration 035). */
    public static function seedTemplatesFor(string $tenantId): void
    {
        $base = dirname(__DIR__, 3) . '/database/migrations/';
        $sql  = '';
        // Healthcare Edition: new tenants get the healthcare provider-type template bundles only.
        // (035 insurance producer templates, 037 insurance entity items, and 041 carrier cert
        // schedule are insurance-era seeds and are no longer applied.)
        foreach (['069_healthcare_templates.sql', '097_healthcare_templates_by_profession.sql', '074_seed_regulators.sql', '120_pr_regulators_expansion.sql', '126_pr_seed_corrections.sql', '079_calendar_event_types.sql'] as $f) {
            if (is_file($base . $f)) $sql .= (string) file_get_contents($base . $f) . "\n";
        }
        if ($sql === '') return;
        $q = Database::pdo()->quote($tenantId);
        // The migrations seed every tenant; scope each tenant-cross-join to just this one.
        // (Statements that fill template items are already idempotent.)
        $sql = str_replace(
            'WHERE NOT EXISTS (' . "\n" . '  SELECT 1 FROM requirement_templates rt',
            'WHERE t.id = ' . $q . ' AND NOT EXISTS (' . "\n" . '  SELECT 1 FROM requirement_templates rt',
            $sql
        );
        // 069 (healthcare templates) already filters WHERE t.vertical = 'healthcare'; scope it to
        // this tenant so a new healthcare signup gets the provider-type bundles immediately. Its
        // item insert joins healthcare tenants and is idempotent, so no extra scoping is needed there.
        $sql = str_replace(
            "WHERE t.vertical = 'healthcare'" . "\n" . '  AND NOT EXISTS (',
            "WHERE t.id = " . $q . " AND t.vertical = 'healthcare'" . "\n" . '  AND NOT EXISTS (',
            $sql
        );
        // Strip SQL comment lines first (they'd otherwise glue onto the next statement).
        $lines = array_filter(
            array_map('rtrim', explode("\n", $sql)),
            fn(string $l) => !str_starts_with(ltrim($l), '--')
        );
        $clean = implode("\n", $lines);
        // Split on semicolons that terminate statements, NOT those inside string literals.
        // Seed note/body text legitimately contains ';', so a naive explode(';') corrupts it.
        foreach (self::splitStatements($clean) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') continue;
            if (self::isGlobalCatalogWrite($stmt)) continue;   // v1.171.5: tenant seeding never re-applies global catalog changes
            // A single non-idempotent DDL (e.g. DROP CONSTRAINT on a DB that lacks it) must not abort
            // the whole seed — the INSERTs that follow are what matter. Skip failures individually.
            try { Database::pdo()->exec($stmt); } catch (\Throwable $e) { /* continue seeding */ }
        }

        // Every tenant also gets the system roles seeded (idempotent). This backfills
        // existing tenants on their next template sync and equips new signups immediately.
        try { RoleService::seedFor($tenantId); } catch (\Throwable $e) { /* non-fatal */ }
    }

    /**
     * v1.171.5 — the seed files replayed for a new tenant also contain statements that change GLOBAL catalog rows
     * (credential_catalog / requirement_sources, tenant_id NULL). Those belong to the one-time migrations only:
     * replaying them on every signup silently reverts later corrections (e.g. migration 132's wording).
     */
    public static function isGlobalCatalogWrite(string $stmt): bool
    {
        return (bool) preg_match('/^\s*(UPDATE|DELETE\s+FROM|INSERT(\s+IGNORE)?\s+INTO)\s+`?(credential_catalog|requirement_sources)`?\b/i', $stmt);
    }

    /** Split a SQL batch into statements, respecting single-quoted strings (incl. '' escapes). */
    private static function splitStatements(string $sql): array
    {
        $out = [];
        $buf = '';
        $inString = false;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($ch === "'") {
                // handle doubled '' escape inside a string
                if ($inString && $i + 1 < $len && $sql[$i + 1] === "'") {
                    $buf .= "''";
                    $i++;
                    continue;
                }
                $inString = !$inString;
                $buf .= $ch;
                continue;
            }
            if ($ch === ';' && !$inString) {
                $out[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') $out[] = $buf;
        return $out;
    }

    /**
     * A real Argon2id hash of a random value — verified against when the email
     * doesn't exist, so "unknown user" and "wrong password" take the same time
     * (prevents account-enumeration via response timing).
     */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$ekZkYnBLclluN1llVS9IUQ$+/iuSpKA/Y54nDH+tV/rnhVbLHLK60PdLA5YUJbawc8';

    /** Returns user row on success, or a string error message. */
    public function attemptLogin(string $email, string $password): array|string
    {
        $email   = strtolower(trim($email));
        $limiter = new RateLimiter();
        $ip      = $_SERVER['REMOTE_ADDR'] ?? 'cli';

        // Layered throttles: combined (8/15min), per-account regardless of IP
        // (12/15min — defeats IP rotation), per-IP regardless of account
        // (30/15min — defeats single-IP spraying across many accounts).
        // S-8: the per-account throttle must not let a stranger lock the real owner out, so it is NOT applied to an
        // address this account has successfully signed in from recently; the combined and per-IP limits still are.
        $knownPlace = $limiter->seenWithin('known|' . $email . '|' . $ip, 30);
        if ($limiter->tooManyAttempts($email . '|' . $ip, 8, 900)
            || (!$knownPlace && $limiter->tooManyAttempts('acct|' . $email, 12, 900))
            || $limiter->tooManyAttempts('ip|' . $ip, 30, 900)) {
            return 'Too many sign-in attempts. Try again in 15 minutes.';
        }

        // One email may now exist in several organizations. Collect every account with
        // this address and keep the ones whose password matches and whose user + tenant
        // are active. Password verification runs against each candidate (constant work
        // either way, so no timing signal about how many orgs a person belongs to).
        $stmt = Database::pdo()->prepare(
            "SELECT u.*, t.name AS tenant_name, t.vertical, t.org_shape, t.status AS tenant_status
             FROM users u JOIN tenants t ON t.id = u.tenant_id
             WHERE u.email = ? ORDER BY t.name"
        );
        $stmt->execute([$email]);
        $rows = $stmt->fetchAll() ?: [];

        $matches = [];
        $blocked = null;   // remembers why a correct password was still turned away
        foreach ($rows as $row) {
            if (!password_verify($password, (string) $row['password_hash'])) continue;
            if ($row['status'] !== 'active') continue;
            // pending tenants may sign in — the front controller demotes everyone in
            // them to read_only until the organization is approved (v1.45).
            if (in_array($row['tenant_status'], ['active', 'pending'], true)) {
                $matches[] = $row;
            } elseif (in_array($row['tenant_status'], ['suspended', 'rejected', 'closed'], true)) {
                $blocked = (string) $row['tenant_status'];
            }
        }
        if ($matches === []) {
            if ($blocked !== null) {
                // The password was right; the organization is the problem. Say so.
                $limiter->clear($email . '|' . $ip);
                return $blocked === 'suspended'
                    ? "This organization's access has been suspended. Contact support if you believe this is an error."
                    : "This organization's registration has not been approved.";
            }
            password_verify($password, self::DUMMY_HASH);   // equalise timing for unknown emails
            $limiter->record($email . '|' . $ip);
            $limiter->record('acct|' . $email);
            $limiter->record('ip|' . $ip);
            return 'Email or password is incorrect.';
        }

        $limiter->clear($email . '|' . $ip);
        // v1.171.6: the per-account counter is cleared only when the sign-in COMPLETES (markKnownPlace), not on a correct password alone.
        // v1.171.5: the place is remembered only when the sign-in is COMPLETE (after the second factor, if any) — see markKnownPlace().

        // Belongs to more than one organization: let the caller ask which one.
        if (count($matches) > 1) {
            return ['__choose_org' => array_map(
                fn(array $r) => ['user_id' => $r['id'], 'tenant_name' => $r['tenant_name'], 'role' => $r['role']],
                $matches
            )];
        }

        $user = $matches[0];
        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
            Database::pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_ARGON2ID), $user['id']]);
        }
        return $user;
    }

    /** After the person picks an organization, re-load that specific account. */
    public function userForOrgChoice(string $userId, array $allowedIds): ?array
    {
        if (!in_array($userId, $allowedIds, true)) return null;   // only ids we offered
        $user = $this->loadUser($userId);
        return ($user && $user['status'] === 'active') ? $user : null;
    }

    /** Update the signed-in user's display name. */
    public function updateProfile(string $userId, string $tenantId, string $fullName): array
    {
        $fullName = trim($fullName);
        if ($fullName === '') return [false, 'Name cannot be empty.'];
        Database::pdo()->prepare("UPDATE users SET full_name = ? WHERE id = ? AND tenant_id = ?")
            ->execute([$fullName, $userId, $tenantId]);
        return [true, 'Profile updated.'];
    }

    /** Change the signed-in user's password (verifies the current one). */
    public function changePassword(string $userId, string $current, string $new): array
    {
        if (strlen($new) < 10) return [false, 'New password must be at least 10 characters.'];
        $stmt = Database::pdo()->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();
        if (!$hash || !password_verify($current, $hash)) return [false, 'Your current password is incorrect.'];
        Database::pdo()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($new, PASSWORD_ARGON2ID), $userId]);
        if (($_SESSION['auth']['user_id'] ?? null) === $userId) self::refreshFingerprint($userId);   // keep THIS session, end the others
        return [true, 'Password changed.'];
    }

    public function establishSession(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['auth'] = [
            'tenant_id'   => $user['tenant_id'],
            'user_id'     => $user['id'],
            'role'        => $user['role'],
            'tenant_name' => $user['tenant_name'],
            'vertical'    => $user['vertical'],
            'org_shape'   => $user['org_shape'],
            'user_name'   => $user['full_name'],
            'platform_admin' => (int) ($user['platform_admin'] ?? 0),
            'holder_id'   => $user['holder_id'] ?? null,
            'user_email'  => $user['email'] ?? '',
            'totp_enabled'=> (int) ($user['totp_enabled'] ?? 0),
            'pfp'         => self::passwordFingerprint((string) ($user['password_hash'] ?? '')),
        ];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['auth_started_at'] = time();
        $_SESSION['auth_last_seen']  = time();
        self::markKnownPlace((string) ($user['email'] ?? ''));   // a finished sign-in (incl. 2FA) makes this address "known"
    }

    /** Remember this address as a place the account has signed in from (exempts it from the per-account throttle for 30 days). */
    public static function markKnownPlace(string $email): void
    {
        (new RateLimiter())->clear('acct|' . strtolower(trim($email)));   // v1.171.6: a complete sign-in resets the per-account counter
        $email = strtolower(trim($email)); if ($email === '') return;
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli'; $lim = new RateLimiter();
        if (!$lim->seenWithin('known|' . $email . '|' . $ip, 30)) $lim->record('known|' . $email . '|' . $ip);
    }

    /**
     * v1.171.0 (S-2) — per-request re-validation of a signed-in session against the database.
     * A session used to be trusted for its whole lifetime, so suspending a user, suspending the organization,
     * resetting a password or changing a role did not take effect until the person signed out. Now, on every
     * request: the user row must still exist and be active; the organization must not be suspended/rejected/
     * closed; the password fingerprint must match the one stored at sign-in (a reset or change signs every
     * other session out); and role / provider link / 2FA flag / platform-admin flag are re-read from the
     * database so a role change applies on the very next request.
     * Returns false when the session must be ended. Support-mode (impersonation) sessions are governed by the
     * support-grant check instead, and are skipped here.
     */
    public static function revalidateSession(): bool
    {
        $a = $_SESSION['auth'] ?? null;
        if (!is_array($a) || empty($a['user_id']) || empty($a['tenant_id'])) return true;   // nothing to validate
        if (!empty($a['impersonated_by']) || !empty($a['support_mode'])) return true;
        try {
            $st = Database::pdo()->prepare(
                'SELECT u.status, u.password_hash, u.role, u.holder_id, u.totp_enabled, u.platform_admin, u.full_name, t.status AS tenant_status
                 FROM users u JOIN tenants t ON t.id = u.tenant_id WHERE u.id = ? AND u.tenant_id = ? LIMIT 1');
            $st->execute([(string) $a['user_id'], (string) $a['tenant_id']]);
            $row = $st->fetch();
        } catch (\Throwable $e) {
            return false;   // fail closed: cannot verify the account, so do not honour the session
        }
        if (!$row || $row['status'] !== 'active') return false;
        if (in_array($row['tenant_status'], ['suspended', 'rejected', 'closed'], true)) return false;
        $fp = self::passwordFingerprint((string) $row['password_hash']);
        if (empty($a['pfp'])) { $_SESSION['auth']['pfp'] = $fp; }          // sessions started before this release adopt once
        elseif (!hash_equals((string) $a['pfp'], $fp)) return false;
        // Live values: a role / link / 2FA change takes effect immediately.
        $_SESSION['auth']['role']         = (string) $row['role'];
        $_SESSION['auth']['holder_id']    = $row['holder_id'] ?? null;
        $_SESSION['auth']['totp_enabled'] = (int) $row['totp_enabled'];
        $_SESSION['auth']['platform_admin'] = (int) $row['platform_admin'];
        $_SESSION['auth']['user_name']    = (string) $row['full_name'];
        return true;
    }

    /** Stable fingerprint of a password hash — changes whenever the password changes, reveals nothing. */
    public static function passwordFingerprint(string $hash): string
    {
        return hash('sha256', 'pfp|' . $hash);
    }

    /** Re-anchor the CURRENT session after the user changed their own password (other sessions are signed out). */
    public static function refreshFingerprint(string $userId): void
    {
        $st = Database::pdo()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $h = $st->fetchColumn();
        if ($h !== false && isset($_SESSION['auth'])) $_SESSION['auth']['pfp'] = self::passwordFingerprint((string) $h);
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /**
     * Finish a login that was parked awaiting a second factor. Accepts either a
     * 6-digit TOTP code or one single-use backup code. Returns [ok, message].
     */
    public function completeTwoFactor(string $userId, string $code): array
    {
        $user = $this->loadUser($userId);
        if (!$user || !self::secondFactorRequired($user)) return [false, 'Please sign in again.'];
        $code = trim($code);
        $byEmail = self::mfaMethod($user) === self::MFA_EMAIL;   // v1.172.0: the second factor is either an authenticator app or an e-mailed code
        if ($code === '') return [false, $byEmail ? 'Enter the 6-digit code we e-mailed you.' : 'Enter the 6-digit code from your authenticator app.'];

        if ($byEmail ? self::checkEmailCode($userId, 'login', $code) : self::checkTotp($userId, (string) $user['totp_secret'], $code)) {
            $this->establishSession($user);
            return [true, 'Signed in.'];
        }
        // Backup code path — consumed on use.
        $left = Totp::consumeBackupCode((string) ($user['totp_backup_codes'] ?? '[]'), $code);
        if ($left !== null) {
            Database::pdo()->prepare("UPDATE users SET totp_backup_codes = ? WHERE id = ?")
                ->execute([$left, $userId]);
            $this->establishSession($user);
            return [true, 'Signed in with a backup code.'];
        }
        return [false, $byEmail ? 'That code is not valid or has expired. Ask for a new code and try again.'
                                : 'That code is not valid. Check your authenticator app and try again.'];
    }

    private const TOTP_AAD = '|users|totp_secret';
    private const TOTP_ENC = 'enc1:';

    /** TOTP secrets are encrypted at rest (S-6): 'enc1:' + base64(Crypto blob), bound to the user id. */
    public static function sealTotpSecret(string $userId, string $secret): string
    {
        return self::TOTP_ENC . base64_encode((string) Crypto::encrypt($secret, $userId . self::TOTP_AAD));
    }

    /** Plain secret from a stored value; values without the prefix are legacy plaintext (still accepted, upgraded on next use). */
    public static function openTotpSecret(string $userId, string $stored): ?string
    {
        if (!str_starts_with($stored, self::TOTP_ENC)) return $stored;
        $raw = base64_decode(substr($stored, strlen(self::TOTP_ENC)), true);
        return $raw === false ? null : Crypto::decrypt($raw, $userId . self::TOTP_AAD);
    }

    /**
     * Verify a TOTP code AND reject replays: the matched time-step must be newer than the last one used
     * (compare-and-set in SQL, so two parallel requests cannot both succeed with the same code).
     */
    public static function checkTotp(string $userId, string $stored, string $code): bool
    {
        $secret = self::openTotpSecret($userId, $stored);
        if ($secret === null || $secret === '') return false;
        $c = Totp::matchCounter($secret, $code);
        if ($c === null) return false;
        $pdo = Database::pdo();
        $u = $pdo->prepare('UPDATE users SET totp_last_counter = ? WHERE id = ? AND (totp_last_counter IS NULL OR totp_last_counter < ?)');
        $u->execute([$c, $userId, $c]);
        if ($u->rowCount() !== 1) return false;   // already used (or older than the last accepted code)
        if (!str_starts_with($stored, self::TOTP_ENC)) {   // lazy upgrade of a legacy plaintext secret
            try { $pdo->prepare('UPDATE users SET totp_secret = ? WHERE id = ?')->execute([self::sealTotpSecret($userId, $secret), $userId]); } catch (\Throwable $e) { /* keep plaintext until keys exist */ }
        }
        return true;
    }

    /** Begin enrolment: hand back a fresh secret + the otpauth URI to display. */
    public static function beginTotpEnrolment(string $email, string $issuer): array
    {
        $secret = Totp::generateSecret();
        return ['secret' => $secret, 'uri' => Totp::uri($secret, $email, $issuer)];
    }

    /** Confirm enrolment: the user must prove the app is set up before we turn it on. */
    public static function confirmTotp(string $userId, string $secret, string $code): array
    {
        // v1.171.6: enrolment may only ADD 2FA. Replacing an active secret must go through "turn off" (which needs a current code),
        // otherwise a hijacked session could swap in its own authenticator without knowing the current one.
        if (self::totpEnabledFor($userId)) {
            return [false, 'Two-factor authentication is already on. Turn it off first (this needs a current code) before enrolling a new device.', []];
        }
        $matched = Totp::matchCounter($secret, $code);
        if ($matched === null) {
            return [false, 'That code did not match. Make sure your phone clock is correct and try again.', []];
        }
        $codes = Totp::backupCodes();
        // The secret is stored encrypted; the enrolment code's counter is burned so it cannot be replayed at login.
        Database::pdo()->prepare(
            "UPDATE users SET totp_secret = ?, totp_last_counter = ?, totp_enabled = 1, mfa_method = 'totp', totp_backup_codes = ?, totp_confirmed_at = ? WHERE id = ?"
        )->execute([self::sealTotpSecret($userId, $secret), $matched, Totp::hashCodes($codes), utc_now(), $userId]);
        return [true, 'Two-factor authentication is on. Save your backup codes now.', $codes];
    }

    public static function totpEnabledFor(string $userId): bool
    {
        $st = Database::pdo()->prepare('SELECT totp_enabled FROM users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        return (int) $st->fetchColumn() === 1;
    }

    /** Turn 2FA off — requires a valid current code, so a hijacked session cannot disable it. */
    public static function disableTotp(string $userId, string $code): array
    {
        $stmt = Database::pdo()->prepare("SELECT totp_enabled, totp_secret, totp_backup_codes, mfa_method FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row || !self::secondFactorRequired($row)) return [false, 'Two-factor is not enabled.'];
        $code = trim($code);
        $valid = (self::mfaMethod($row) === self::MFA_EMAIL
                    ? self::checkEmailCode($userId, 'disable', $code)
                    : self::checkTotp($userId, (string) $row['totp_secret'], $code))
            || Totp::consumeBackupCode((string) ($row['totp_backup_codes'] ?? '[]'), $code) !== null;
        if (!$valid) return [false, 'Enter a valid code to turn two-factor off.'];
        Database::pdo()->prepare(
            "UPDATE users SET totp_secret = NULL, totp_enabled = 0, mfa_method = 'totp', totp_backup_codes = NULL, totp_confirmed_at = NULL WHERE id = ?"
        )->execute([$userId]);
        try { Database::pdo()->prepare('DELETE FROM auth_email_codes WHERE user_id = ?')->execute([$userId]); } catch (\Throwable $e) {}
        return [true, 'Two-factor authentication is off.'];
    }

    // =================================================================================================================
    // v1.172.0 — sign-in options: platform two-factor policy, e-mailed codes, password reset by e-mail
    // =================================================================================================================

    public const MFA_TOTP  = 'totp';
    public const MFA_EMAIL = 'email';
    private const CODE_TTL       = 600;    // an e-mailed code is valid for 10 minutes
    private const CODE_ATTEMPTS  = 5;      // wrong tries allowed per code
    public  const RESET_TTL      = 3600;   // a password-reset link is valid for 60 minutes

    /**
     * Is two-factor REQUIRED to open the platform console? A setting the platform administrator controls
     * (Console → Security). Off on a new installation so the first set-up is not blocked; an installation
     * upgraded from a release where it was mandatory keeps it on (migration 133).
     */
    public static function platformRequires2fa(): bool
    {
        return SettingsService::get('platform_require_2fa', '0') === '1';
    }

    /** May accounts use an e-mailed code as their second factor? (platform setting, on by default) */
    public static function emailCodesAllowed(): bool
    {
        return SettingsService::get('mfa_email_allowed', '1') === '1';
    }

    /** 'email' or 'totp' for a users row (anything unknown reads as the authenticator app). */
    public static function mfaMethod(array $user): string
    {
        return ($user['mfa_method'] ?? self::MFA_TOTP) === self::MFA_EMAIL ? self::MFA_EMAIL : self::MFA_TOTP;
    }

    /** Does this account need a second step at sign-in? (two-factor on, and a usable method on file) */
    public static function secondFactorRequired(array $user): bool
    {
        if (empty($user['totp_enabled'])) return false;
        return self::mfaMethod($user) === self::MFA_EMAIL || !empty($user['totp_secret']);
    }

    /** The second-factor method of a user id, or null when two-factor is off. */
    public static function mfaMethodFor(string $userId): ?string
    {
        $st = Database::pdo()->prepare('SELECT totp_enabled, totp_secret, mfa_method FROM users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $row = $st->fetch();
        return ($row && self::secondFactorRequired($row)) ? self::mfaMethod($row) : null;
    }

    /** n***@example.com — enough for the owner to recognise the mailbox, not enough to disclose it. */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return ($local === '' ? '' : mb_substr($local, 0, 1)) . '***' . ($domain !== '' ? '@' . $domain : '');
    }

    private static function codeKey(): string
    {
        $k = (string) config('crypto_index_key', '');
        return $k !== '' ? $k : hash('sha256', 'flx-code|' . (string) config('db_name', '') . '|' . (string) config('db_pass', ''));
    }

    private static function codeHash(string $userId, string $purpose, string $code): string
    {
        return hash_hmac('sha256', $userId . '|' . $purpose . '|' . $code, self::codeKey());
    }

    /**
     * Create a fresh 6-digit code for (user, purpose) and return it so the caller can e-mail it.
     * Only its keyed hash is stored. Any earlier code for the same purpose stops working.
     * Purposes: 'login' (second step at sign-in), 'enrol' (turning e-mail codes on), 'disable' (turning two-factor off).
     */
    public static function issueEmailCode(string $userId, string $purpose): string
    {
        $pdo = Database::pdo(); $now = time();
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $pdo->prepare('DELETE FROM auth_email_codes WHERE (user_id = ? AND purpose = ?) OR expires_at < ?')
            ->execute([$userId, $purpose, gmdate('Y-m-d H:i:s', $now - 86400)]);
        $pdo->prepare('INSERT INTO auth_email_codes (id, user_id, purpose, code_hash, attempts, expires_at, created_at) VALUES (?,?,?,?,0,?,?)')
            ->execute([uuid4(), $userId, $purpose, self::codeHash($userId, $purpose, $code),
                gmdate('Y-m-d H:i:s', $now + self::CODE_TTL), gmdate('Y-m-d H:i:s', $now)]);
        return $code;
    }

    /**
     * Verify an e-mailed code: right user, right purpose, not expired, not used, at most CODE_ATTEMPTS wrong tries.
     * A correct code is consumed atomically (two parallel requests cannot both succeed).
     */
    public static function checkEmailCode(string $userId, string $purpose, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) return false;
        $pdo = Database::pdo(); $now = gmdate('Y-m-d H:i:s');
        $st = $pdo->prepare('SELECT id, code_hash, attempts FROM auth_email_codes
                             WHERE user_id = ? AND purpose = ? AND used_at IS NULL AND expires_at > ? ORDER BY created_at DESC LIMIT 1');
        $st->execute([$userId, $purpose, $now]);
        $row = $st->fetch();
        if (!$row || (int) $row['attempts'] >= self::CODE_ATTEMPTS) return false;
        if (!hash_equals((string) $row['code_hash'], self::codeHash($userId, $purpose, $code))) {
            $pdo->prepare('UPDATE auth_email_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
            return false;
        }
        $u = $pdo->prepare('UPDATE auth_email_codes SET used_at = ? WHERE id = ? AND used_at IS NULL');
        $u->execute([$now, $row['id']]);
        return $u->rowCount() === 1;
    }

    /**
     * Issue a code and e-mail it to the account's own address. Returns [sent, message].
     * Sending is limited to 5 codes per 15 minutes per account, so the form cannot be used to flood a mailbox.
     */
    public static function sendEmailCode(string $userId, string $purpose): array
    {
        $st = Database::pdo()->prepare('SELECT u.id, u.tenant_id, u.email, u.full_name, u.locale, u.status FROM users u WHERE u.id = ? LIMIT 1');
        $st->execute([$userId]);
        $u = $st->fetch();
        if (!$u || $u['status'] !== 'active' || !filter_var((string) $u['email'], FILTER_VALIDATE_EMAIL)) return [false, 'This account has no valid e-mail address.'];
        if (!MailService::canSendFor((string) $u['tenant_id'])) {
            return [false, 'E-mail codes need working outbound e-mail, and it is not set up yet. Use an authenticator app, or ask your administrator to configure e-mail.'];
        }
        $lim = new RateLimiter(); $key = 'otp_send|' . $userId;
        if ($lim->tooManyAttempts($key, 5, 900)) return [false, 'Too many codes requested. Wait 15 minutes and try again.'];
        $lim->record($key);
        $code = self::issueEmailCode($userId, $purpose);
        $loc = (string) ($u['locale'] ?: config('default_locale', 'en')); if (!isset(I18n::LOCALES[$loc])) $loc = 'en';
        $tl = static fn(string $en, array $v = []): string => I18n::tl($loc, $en, $v);
        $subject = $tl('Your {app} verification code', ['app' => FLXCOREHC_APP_NAME]);
        $body = $tl('Hello {name},', ['name' => (string) $u['full_name']]) . "\n\n"
              . $tl('Your verification code is:') . "\n\n    " . $code . "\n\n"
              . $tl('It expires in 10 minutes and works once.') . "\n"
              . $tl('If you did not ask for this code, someone may know your password: sign in and change it.') . "\n";
        $sent = MailService::sendSecure((string) $u['email'], $subject, $body, (string) $u['full_name'], (string) $u['tenant_id']);
        return $sent
            ? [true, 'We sent a 6-digit code to ' . self::maskEmail((string) $u['email']) . '. It expires in 10 minutes.']
            : [false, 'We could not send the code. Check the e-mail settings, or use a backup code.'];
    }

    /** Turn two-factor on with e-mailed codes: the user proves they can read the mailbox first. Returns [ok, message, backupCodes]. */
    public static function confirmEmailMfa(string $userId, string $code): array
    {
        if (self::totpEnabledFor($userId)) {
            return [false, 'Two-factor authentication is already on. Turn it off first (this needs a current code) before changing the method.', []];
        }
        if (!self::emailCodesAllowed()) return [false, 'E-mail codes are not allowed on this installation. Use an authenticator app.', []];
        if (!self::checkEmailCode($userId, 'enrol', $code)) return [false, 'That code is not valid or has expired. Ask for a new code and try again.', []];
        $codes = Totp::backupCodes();
        Database::pdo()->prepare(
            "UPDATE users SET totp_secret = NULL, totp_last_counter = NULL, totp_enabled = 1, mfa_method = 'email', totp_backup_codes = ?, totp_confirmed_at = ? WHERE id = ?"
        )->execute([Totp::hashCodes($codes), utc_now(), $userId]);
        return [true, 'Two-factor authentication is on, using e-mailed codes. Save your backup codes now.', $codes];
    }

    // ---- password reset by e-mail -----------------------------------------------------------------------------------

    private static function authAudit(string $tenantId, string $userId, string $action): void
    {
        try {
            Database::pdo()->prepare(
                'INSERT INTO audit_logs (id, tenant_id, user_id, action, entity_type, entity_id, changes, ip_address, created_at) VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([uuid4(), $tenantId, $userId, $action, 'users', $userId, null, substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), utc_now()]);
        } catch (\Throwable $e) { /* audit logging must never break the request */ }
    }

    /**
     * Create a single-use reset link for every ACTIVE account that signs in with this e-mail (one address can belong to
     * several organizations, each with its own password). Returns one entry per account:
     * ['user_id','tenant_id','email','full_name','locale','tenant_name','token'] — the caller e-mails the links.
     * Returns [] for an unknown / inactive address; the caller must answer the same way in both cases.
     * Only the SHA-256 hash of a token is stored; a new request replaces any earlier unused link for the account.
     */
    public static function createPasswordResets(string $email): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [];
        $pdo = Database::pdo(); $now = time();
        $pdo->prepare('DELETE FROM password_resets WHERE expires_at < ?')->execute([gmdate('Y-m-d H:i:s', $now - 86400)]);
        $st = $pdo->prepare(
            "SELECT u.id AS user_id, u.tenant_id, u.email, u.full_name, u.locale, t.name AS tenant_name
             FROM users u JOIN tenants t ON t.id = u.tenant_id
             WHERE u.email = ? AND u.status = 'active' AND t.status IN ('active','pending') ORDER BY t.name");
        $st->execute([$email]);
        $out = [];
        foreach ($st->fetchAll() ?: [] as $u) {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$u['user_id']]);
            $pdo->prepare('INSERT INTO password_resets (id, user_id, tenant_id, token_hash, expires_at, requested_ip, created_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([uuid4(), $u['user_id'], $u['tenant_id'], hash('sha256', $token), gmdate('Y-m-d H:i:s', $now + self::RESET_TTL),
                    substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), gmdate('Y-m-d H:i:s', $now)]);
            self::authAudit((string) $u['tenant_id'], (string) $u['user_id'], 'password_reset_requested');
            $out[] = $u + ['token' => $token];
        }
        return $out;
    }

    /** The account a reset token belongs to, when the link is still usable; null otherwise (unknown, used, expired, account not active). */
    public static function findPasswordReset(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $st = Database::pdo()->prepare(
            "SELECT pr.id AS reset_id, pr.user_id, pr.tenant_id, u.email, u.full_name, t.name AS tenant_name
             FROM password_resets pr
             JOIN users u ON u.id = pr.user_id AND u.tenant_id = pr.tenant_id
             JOIN tenants t ON t.id = pr.tenant_id
             WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > ?
               AND u.status = 'active' AND t.status IN ('active','pending') LIMIT 1");
        $st->execute([hash('sha256', $token), gmdate('Y-m-d H:i:s')]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /**
     * Set a new password with a reset link. The link is consumed first (atomically), so it works exactly once.
     * It does NOT sign the person in and does NOT touch two-factor: the next sign-in still asks for the second step.
     * Changing the password ends every existing session of the account (password fingerprint, see revalidateSession()).
     */
    public static function completePasswordReset(string $token, string $new, string $confirm): array
    {
        $row = self::findPasswordReset($token);
        if ($row === null) return [false, 'This link is no longer valid. Ask for a new one.'];
        if (strlen($new) < 10) return [false, 'New password must be at least 10 characters.'];
        if ($new !== $confirm) return [false, 'The two passwords do not match.'];
        $pdo = Database::pdo();
        $claim = $pdo->prepare('UPDATE password_resets SET used_at = ? WHERE id = ? AND used_at IS NULL');
        $claim->execute([gmdate('Y-m-d H:i:s'), $row['reset_id']]);
        if ($claim->rowCount() !== 1) return [false, 'This link is no longer valid. Ask for a new one.'];
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND tenant_id = ?')
            ->execute([password_hash($new, PASSWORD_ARGON2ID), $row['user_id'], $row['tenant_id']]);
        $pdo->prepare('DELETE FROM password_resets WHERE user_id = ? AND id <> ?')->execute([$row['user_id'], $row['reset_id']]);
        self::authAudit((string) $row['tenant_id'], (string) $row['user_id'], 'password_reset_completed');
        return [true, 'Password changed. Sign in with your new password.'];
    }

    /** E-mail one reset link (see createPasswordResets). Returns whether the message was handed to the mail transport. */
    public static function sendPasswordResetEmail(array $acct, string $link): bool
    {
        $loc = (string) (($acct['locale'] ?? '') ?: config('default_locale', 'en')); if (!isset(I18n::LOCALES[$loc])) $loc = 'en';
        $tl = static fn(string $en, array $v = []): string => I18n::tl($loc, $en, $v);
        $subject = $tl('Reset your {app} password', ['app' => FLXCOREHC_APP_NAME]);
        $body = $tl('Hello {name},', ['name' => (string) $acct['full_name']]) . "\n\n"
              . $tl('We received a request to reset the password of your account at {org}.', ['org' => (string) $acct['tenant_name']]) . "\n"
              . $tl('Open this link to choose a new password:') . "\n\n" . $link . "\n\n"
              . $tl('The link works once and expires in 60 minutes.') . "\n"
              . $tl('If you did not ask for this, you can ignore this message: your password has not changed.') . "\n";
        return MailService::sendSecure((string) $acct['email'], $subject, $body, (string) $acct['full_name'], (string) $acct['tenant_id']);
    }

    private function loadUser(string $userId): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT u.*, t.name AS tenant_name, t.vertical, t.org_shape, t.status AS tenant_status
             FROM users u JOIN tenants t ON t.id = u.tenant_id WHERE u.id = ? LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;   // v1.172.0: a missing account is null, not a type error
    }

    /** Re-confirm the signed-in user's password (for sensitive downloads). */
    public static function passwordMatches(string $userId, string $password): bool
    {
        $st = \FlxCoreHC\Database::pdo()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $h = (string) $st->fetchColumn();
        return $h !== '' && password_verify($password, $h);
    }
}
