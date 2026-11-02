<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

// First-run: if the app hasn't been configured yet, hand off to the web installer.
// v1.171.5: honours FLX_CONFIG exactly like app/bootstrap.php (flx_config_path()).
if (!is_file((getenv('FLX_CONFIG') ?: '') !== '' ? (string) getenv('FLX_CONFIG') : __DIR__ . '/app/config.php') && is_file(__DIR__ . '/install.php')) {
    header('Location: install.php');
    exit;
}

require __DIR__ . '/app/bootstrap.php';

use FlxCoreHC\Auth\AuthService;
use FlxCoreHC\Auth\CsrfGuard;
use FlxCoreHC\TenantContext;
use FlxCoreHC\Repository\HolderRepository;
use FlxCoreHC\Repository\CredentialRepository;
use FlxCoreHC\Repository\RenewalTaskRepository;
use FlxCoreHC\Repository\DocumentRepository;
use FlxCoreHC\Repository\AuditLogRepository;
use FlxCoreHC\Repository\RegulatorRepository;
use FlxCoreHC\Repository\ComplianceRepository;
use FlxCoreHC\Repository\CalendarRepository;
use FlxCoreHC\Mailer;
use FlxCoreHC\SuperadminService;
use FlxCoreHC\TeamService;
use FlxCoreHC\PlanService;
use FlxCoreHC\BillingService;
use FlxCoreHC\OrgService;
use FlxCoreHC\RiskScoreService;
use FlxCoreHC\AccessLog;

// ---- Routing ------------------------------------------------------------
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// v1.172.14: only a real script name (…/index.php) gives the subfolder. PHP's built-in server (8.3) reports the
// request path itself as SCRIPT_NAME for a URL with a file extension (/audit/export.csv), which made the base '/audit'.
$base = str_ends_with((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php') ? rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') : '';   // supports subdirectory installs
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base)) ?: '/';
}
// Fallback for servers without mod_rewrite: allow /index.php/login style URLs.
if (str_starts_with($path, '/index.php')) {
    $path = substr($path, strlen('/index.php')) ?: '/';
}
$seg    = array_values(array_filter(explode('/', $path), fn($s) => $s !== ''));
// The root path yields no segments. Routes compare $seg[0] directly in many places, so guarantee
// index 0 exists (as '') rather than relying on every comparison to guard it — an undefined-index
// warning on the home page is both noisy and a needless E_ALL failure.
if ($seg === []) { $seg[0] = ''; }
$method = $_SERVER['REQUEST_METHOD'];

function view(string $template, array $data = []): never
{
    extract($data);
    $viewFile = FLXCOREHC_APP . '/views/' . $template . '.php';
    if (FlxCoreHC\I18n::locale() === 'en') {
        require FLXCOREHC_APP . '/views/layout.php';
        exit;
    }
    // Non-English: render, then translate the whole page (exact-match text nodes + attributes) so the
    // full UI follows the user's language, and record any untranslated strings for the editor.
    // The translation editor itself opts out ($skipOutputTranslate) — it must show English sources
    // verbatim and never collect its own chrome.
    if (!empty($skipOutputTranslate)) { require FLXCOREHC_APP . '/views/layout.php'; exit; }
    ob_start();
    require FLXCOREHC_APP . '/views/layout.php';
    $html = (string) ob_get_clean();
    echo FlxCoreHC\I18n::translateHtml($html);
    FlxCoreHC\I18n::flushMissing();
    exit;
}

function flash(?string $msg = null, ?string $type = null): ?string
{
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        $_SESSION['flash_type'] = $type ?? flx_flash_type($msg);   // v1.171.5: refusals no longer look like successes
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    $GLOBALS['flx_flash_last_type'] = (string) ($_SESSION['flash_type'] ?? 'ok');
    unset($_SESSION['flash'], $_SESSION['flash_type']);
    return $m;
}

if (isset($_SESSION['auth']) && !FlxCoreHC\Auth\AuthService::revalidateSession()) {
    // v1.171.0 (S-2): account suspended/removed, organization closed, or password changed elsewhere.
    $_SESSION = [];
    if (($seg[0] ?? '') !== 'login' && ($seg[0] ?? '') !== 'logout') {
        if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); }
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        flash('You have been signed out. Please sign in again.', 'warn');
        redirect('/login');
    }
}
$ctx  = TenantContext::fromSession();
// v1.172.16: the organization's own time zone (NULL = the platform's) is the app zone for this request, so every date,
// "today", overdue test and clock time follows the organization, not the server.
$__flxTenantTz = static function (?TenantContext $c): void {
    app_tz_use_tenant($c ? OrgService::timezoneFor($c->tenantId) : null);
};
$__flxTenantTz($ctx);
// The platform_admin flag must never outlive its database grant: if the session claims
// it but the users row no longer carries it, strip it and rebuild the context.
if ($ctx && $ctx->isPlatformAdmin && !FlxCoreHC\Authz::verifyPlatformAdmin($ctx)) {
    $_SESSION['auth']['platform_admin'] = 0;
    $ctx = TenantContext::fromSession();
}
$auth = new AuthService();

// Make the resolved context available to the error logger in bootstrap, so a logged failure
// records which tenant and user hit it. Read-only use there; the request still owns $ctx.
$GLOBALS['flx_ctx'] = $ctx;

// Interface language (PR market): if the user hasn't chosen a locale this session, adopt their saved
// preference, then the tenant's default language, then English. A URL switch (?setlang=) overrides.
if ($ctx && !isset($_SESSION['locale'])) {
    try {
        $__lst = FlxCoreHC\Database::pdo()->prepare('SELECT locale FROM users WHERE id = ? LIMIT 1');
        $__lst->execute([$ctx->userId]);
        $__loc = (string) ($__lst->fetchColumn() ?: '');
        if ($__loc === '') {
            // No personal preference — use the organization's default language.
            $__tl = FlxCoreHC\Database::pdo()->prepare('SELECT default_locale FROM tenants WHERE id = ? LIMIT 1');
            $__tl->execute([$ctx->tenantId]);
            $__loc = (string) ($__tl->fetchColumn() ?: '');
        }
        if ($__loc !== '' && isset(FlxCoreHC\I18n::LOCALES[$__loc])) FlxCoreHC\I18n::setLocale($__loc);
    } catch (\Throwable $e) { /* locale is best-effort */ }
}
// Language switch: /?setlang=es (or es/en) sets the session + saves to the user row, then continues.
if ($ctx && isset($_GET['setlang']) && isset(FlxCoreHC\I18n::LOCALES[$_GET['setlang']])) {
    FlxCoreHC\I18n::setLocale((string) $_GET['setlang']);
    // Persist only for a same-site navigation (Sec-Fetch-Site), so a third-party page cannot change a user's saved language via a GET.
    if (in_array($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '', ['same-origin', 'same-site', 'none'], true)) {
        try {
            FlxCoreHC\Database::pdo()->prepare('UPDATE users SET locale = ? WHERE id = ?')
                ->execute([(string) $_GET['setlang'], $ctx->userId]);
        } catch (\Throwable $e) { /* best-effort persist */ }
    }
}

// v1.172.10: signed-out pages (login, forgot, reset, register, invitation, 2FA) follow the visitor's language.
// Order: ?lang= (remembered in a small functional cookie) → that cookie → the browser's Accept-Language (Spanish
// only; everything else stays English). A signed-in user keeps the saved preference handled above.
if (!$ctx && !preg_match('#^/s/#', (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH))) {
    $__ql = (string) ($_GET['lang'] ?? '');
    $__ck = (string) ($_COOKIE['flx_lang'] ?? '');
    if ($__ql !== '' && isset(FlxCoreHC\I18n::LOCALES[$__ql])) {
        FlxCoreHC\I18n::setLocale($__ql);
        if (!headers_sent()) setcookie('flx_lang', $__ql, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true]);
    } elseif (empty($_SESSION['locale']) && $__ck !== '' && isset(FlxCoreHC\I18n::LOCALES[$__ck])) {
        FlxCoreHC\I18n::setLocale($__ck);
    } elseif (empty($_SESSION['locale']) && str_starts_with(strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')), 'es')) {
        FlxCoreHC\I18n::setLocale('es');
    }
}

// ---- Support mode (consent-gated platform access) -----------------------------
// A support session may only use settings/training surfaces, and only while its 4-hour grant is
// still live: if the Master Admin revokes (or the window lapses) the session is ejected on the very
// next request. PHI repositories additionally refuse all reads in support mode.
if ($ctx && FlxCoreHC\SupportAccess::active()) {
    $__grant = FlxCoreHC\SupportAccess::activeGrant($ctx->tenantId, (string)($_SESSION['auth']['impersonated_by'] ?? ''));
    if (!$__grant) {
        $saved = $_SESSION['admin_return'] ?? null;
        FlxCoreHC\SupportAccess::markEnded((string)($_SESSION['auth']['support_request_id'] ?? ''));
        audit_log($ctx, 'support_session_ended', 'tenants', $ctx->tenantId, json_encode(['cause' => 'revoked_or_expired']));
        FlxCoreHC\AccessLog::record($ctx, 'support_session_ended', 'tenants', $ctx->tenantId);
        session_regenerate_id(true);
        if (is_array($saved)) { $_SESSION['auth'] = $saved; unset($_SESSION['admin_return']); }
        else { $_SESSION = []; }
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        flash('Support access ended (revoked or the 4-hour window expired). Request a new approval for more time.', 'error');
        redirect('/admin/tenants');
    }
    if (!FlxCoreHC\SupportAccess::routeAllowed((string)($seg[0] ?? ''))) {
        http_response_code(403);
        exit('Support mode: this area is not accessible. Support sessions cover tenant settings and training only — provider, credential, and document data stay private to the tenant.');
    }
}

// ---- Branding for signed-out pages --------------------------------------
// Login, invite acceptance, and 2FA render before there is a tenant context, so without this
// they always showed stock colours — jarring on a white-labelled deployment. Branding is resolved
// in order of confidence: the request's hostname, then the org this browser last signed into,
// then the only tenant if the install has just one. If none apply we leave the stock theme alone
// rather than guessing, since showing one agency's branding to another's staff would be worse.
if (!$ctx && empty($GLOBALS['flx_brand'])) {
    $__lt = null;
    try {
        // 1. The hostname this request arrived on. Strongest signal on a white-labelled deployment,
        //    and correct on a visitor's very first visit. Presentation only — see DomainService.
        $__lt = FlxCoreHC\DomainService::tenantForHost($_SERVER['HTTP_HOST'] ?? '');

        // 2. Otherwise, the organization this browser last signed into.
        if ($__lt === null) {
            $__hint = (string) ($_COOKIE['flx_org'] ?? '');
            if ($__hint !== '' && preg_match('/^[0-9a-f-]{36}$/i', $__hint)) {
                $q = FlxCoreHC\Database::pdo()->prepare('SELECT * FROM tenants WHERE id = ? LIMIT 1');
                $q->execute([$__hint]);
                $__lt = $q->fetch() ?: null;
            }
        }
        // 3. Otherwise, a single-tenant install is unambiguous.
        if ($__lt === null) {
            $q = FlxCoreHC\Database::pdo()->query('SELECT * FROM tenants LIMIT 2');
            $rows = $q->fetchAll();
            if (count($rows) === 1) $__lt = $rows[0];
        }
    } catch (\Throwable $e) {
        $__lt = null;   // pre-install or unreachable DB: fall through to the stock theme
    }
    if ($__lt) {
        $__ll = (string) ($__lt['logo_path'] ?? '');
        $GLOBALS['flx_brand'] = [
            'name'     => ($__lt['brand_name'] ?? '') ?: ($__lt['name'] ?? 'FlxCoreHC'),
            'logo'     => $__ll !== '' ? (str_starts_with($__ll, 'data:') ? $__ll : BASE_PATH . '/' . ltrim($__ll, '/')) : null,
            'themeCss' => FlxCoreHC\ThemeService::css($__lt),
            'mode'     => FlxCoreHC\ThemeService::defaultMode($__lt),
            'navIcons' => $__lt['nav_icons'] ?? null,
        ];
    }
}

// ---- Public routes ------------------------------------------------------
// v1.172.0: park a sign-in until the second step is proven. The step is either an authenticator-app code or a code
// e-mailed to the account; the e-mail is sent here (on the POST that proved the password), never on a page view.
$__park2fa = static function (array $u): void {
    $m = FlxCoreHC\Auth\AuthService::mfaMethod($u);
    $_SESSION['pending_2fa'] = ['user_id' => $u['id'], 'at' => time(), 'method' => $m,
        'hint' => $m === FlxCoreHC\Auth\AuthService::MFA_EMAIL ? FlxCoreHC\Auth\AuthService::maskEmail((string) ($u['email'] ?? '')) : ''];
    if ($m === FlxCoreHC\Auth\AuthService::MFA_EMAIL) {
        [$__ok, $__msg] = FlxCoreHC\Auth\AuthService::sendEmailCode((string) $u['id'], 'login');
        flash($__msg, $__ok ? 'ok' : 'error');
    }
    redirect('/login/verify');
};
if (($seg[0] ?? '') === 'login' && ($seg[1] ?? '') === '') {
    if ($ctx) redirect('/');
    if ($method === 'POST') {
        CsrfGuard::verify();
        $result = $auth->attemptLogin((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
        // Password was right, but this email belongs to more than one organization.
        if (is_array($result) && isset($result['__choose_org'])) {
            $_SESSION['pending_org'] = ['options' => $result['__choose_org'], 'at' => time()];
            redirect('/login/organization');
        }
        if (is_array($result)) {
            // Password is correct. If this user has 2FA on, park them in a pending
            // state — no session is established until the second factor is proven.
            if (FlxCoreHC\Auth\AuthService::secondFactorRequired($result)) { $__park2fa($result); }
            $auth->establishSession($result);
            $__c = TenantContext::fromSession();
            AccessLog::record($__c, 'login');
            // Remember which org this browser belongs to, so the sign-in page is branded next
            // time. Presentation only — it grants no access and is re-checked at every login.
            if ($__c) {
                setcookie('flx_org', $__c->tenantId, [
                    'expires'  => time() + 31536000, 'path' => BASE_PATH ?: '/',
                    'httponly' => true, 'samesite' => 'Lax',
                    'secure'   => !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
                ]);
            }
            redirect('/');
        }
        view('auth/login', ['error' => $result, 'email' => $_POST['email'] ?? '', 'bare' => true]);
    }
    $__notices = [
        'org_suspended'    => "This organization's access has been suspended. Contact support if you believe this is an error.",
        'org_not_approved' => "This organization's registration has not been approved.",
    ];
    view('auth/login', ['bare' => true, 'error' => $__notices[$_GET['notice'] ?? ''] ?? null]);
}

// ---- Organization chooser (one email, several agencies) ----------------------
if (($seg[0] ?? '') === 'login' && ($seg[1] ?? '') === 'organization') {
    $pending = $_SESSION['pending_org'] ?? null;
    if (!$pending || (time() - (int)$pending['at']) > 300) {
        unset($_SESSION['pending_org']);
        flash('That took too long — please sign in again.', 'error');
        redirect('/login');
    }
    $options = $pending['options'];
    if ($method === 'POST') {
        CsrfGuard::verify();
        $ids  = array_map(fn($o) => $o['user_id'], $options);
        $user = $auth->userForOrgChoice((string)($_POST['user_id'] ?? ''), $ids);
        if ($user) {
            unset($_SESSION['pending_org']);
            // The chosen account may itself have 2FA switched on.
            if (FlxCoreHC\Auth\AuthService::secondFactorRequired($user)) { $__park2fa($user); }
            $auth->establishSession($user);
            AccessLog::record(TenantContext::fromSession(), 'login');
            redirect('/');
        }
        flash('Please choose one of your organizations.', 'error');
    }
    view('auth/organization', ['options' => $options, 'bare' => true]);
}

// ---- Second factor challenge -------------------------------------------------
if (($seg[0] ?? '') === 'login' && ($seg[1] ?? '') === 'verify') {
    $pending = $_SESSION['pending_2fa'] ?? null;
    $__byEmail = is_array($pending) && ($pending['method'] ?? 'totp') === FlxCoreHC\Auth\AuthService::MFA_EMAIL;
    // 5-minute challenge window for an authenticator code; 15 minutes when the code travels by e-mail.
    if (!$pending || (time() - (int)$pending['at']) > ($__byEmail ? 900 : 300)) {
        unset($_SESSION['pending_2fa']);
        flash('That took too long — please sign in again.', 'error');
        redirect('/login');
    }
    $err = null;
    if ($method === 'POST') {
        CsrfGuard::verify();
        if ($__byEmail && ($_POST['action'] ?? '') === 'resend') {   // v1.172.0: a fresh code (the earlier one stops working); sending is rate-limited
            [$__ok, $__msg] = FlxCoreHC\Auth\AuthService::sendEmailCode((string) $pending['user_id'], 'login');
            flash($__msg, $__ok ? 'ok' : 'error');
            redirect('/login/verify');
        }
        $limiter = new FlxCoreHC\Auth\RateLimiter();
        $key = '2fa|' . $pending['user_id'];
        if ($limiter->tooManyAttempts($key, 8, 900)) {
            unset($_SESSION['pending_2fa']);
            flash('Too many codes tried. Please sign in again.', 'error');
            redirect('/login');
        }
        [$ok, $msg] = $auth->completeTwoFactor((string)$pending['user_id'], (string)($_POST['code'] ?? ''));
        if ($ok) {
            unset($_SESSION['pending_2fa']);
            AccessLog::record(TenantContext::fromSession(), 'login_2fa');
            redirect('/');
        }
        $limiter->record($key);
        $err = $msg;
    }
    view('auth/twofactor', ['error' => $err, 'bare' => true, 'byEmail' => $__byEmail, 'emailHint' => (string) ($pending['hint'] ?? '')]);
}

// ---- Forgot password (v1.172.0) ----------------------------------------------------------------
// Public. The answer is identical whether or not the address belongs to an account, so the form cannot be used to
// discover who has one. A link is single-use, expires in 60 minutes, never signs anyone in and never bypasses two-factor.
if (($seg[0] ?? '') === 'forgot' && ($seg[1] ?? '') === '') {
    if ($ctx) redirect('/profile');
    if ($method === 'POST') {
        CsrfGuard::verify();
        $__em = strtolower(trim((string) ($_POST['email'] ?? '')));
        $__rl = new FlxCoreHC\Auth\RateLimiter(); $__ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
        $__same = 'If that address belongs to an active account, we have sent a link to choose a new password. It expires in 60 minutes.';
        if ($__rl->tooManyAttempts('pwreset_ip|' . $__ip, 10, 900)) { flash('Too many requests. Try again in 15 minutes.', 'error'); redirect('/forgot'); }
        $__rl->record('pwreset_ip|' . $__ip);
        if (filter_var($__em, FILTER_VALIDATE_EMAIL) && !$__rl->tooManyAttempts('pwreset|' . $__em, 3, 900)) {
            $__rl->record('pwreset|' . $__em);
            $__accts = FlxCoreHC\Auth\AuthService::createPasswordResets($__em);
            if ($__accts) {
                $__base = flx_public_base_safe();
                // The mail is sent after the answer has gone out, so the response time does not reveal whether an account exists.
                register_shutdown_function(static function () use ($__accts, $__base): void {
                    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
                    elseif (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); }
                    foreach ($__accts as $__a) {
                        try { FlxCoreHC\Auth\AuthService::sendPasswordResetEmail($__a, $__base . '/reset/' . $__a['token']); } catch (\Throwable $e) { error_log('[FlxCoreHC] reset mail: ' . $e->getMessage()); }
                    }
                });
            }
        }
        flash($__same, 'ok');
        redirect('/forgot');
    }
    view('auth/forgot', ['bare' => true]);
}
if (($seg[0] ?? '') === 'reset' && isset($seg[1]) && ($seg[2] ?? '') === '') {
    $__tok = (string) $seg[1];
    $__acct = FlxCoreHC\Auth\AuthService::findPasswordReset($__tok);
    if ($method === 'POST') {
        CsrfGuard::verify();
        $__rl = new FlxCoreHC\Auth\RateLimiter(); $__ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
        if ($__rl->tooManyAttempts('pwreset_use|' . $__ip, 20, 900)) { flash('Too many attempts. Try again in 15 minutes.', 'error'); redirect('/login'); }
        $__rl->record('pwreset_use|' . $__ip);
        [$__ok, $__msg] = FlxCoreHC\Auth\AuthService::completePasswordReset($__tok, (string) ($_POST['new'] ?? ''), (string) ($_POST['confirm'] ?? ''));
        if ($__ok) {
            flash($__msg, 'ok');
            redirect('/login');
        }
        if ($__acct === null) { flash($__msg, 'error'); redirect('/forgot'); }
        view('auth/reset', ['bare' => true, 'error' => $__msg, 'token' => $__tok, 'acct' => $__acct]);
    }
    if ($__acct === null) { flash('This link is no longer valid. Ask for a new one.', 'error'); redirect('/forgot'); }
    header('Referrer-Policy: no-referrer'); header('Cache-Control: no-store');   // the address carries the token
    view('auth/reset', ['bare' => true, 'token' => $__tok, 'acct' => $__acct]);
}

if (($seg[0] ?? '') === 'register') {
    if (!config('allow_open_registration', false)) {
        flash('Public sign-up is disabled. Please ask your administrator for an invitation.', 'error');
        redirect('/login');
    }
    if ($ctx) redirect('/');
    if ($method === 'POST') {
        CsrfGuard::verify();
        $result = $auth->registerTenant(
            (string)($_POST['org_name'] ?? ''),
            (string)($_POST['org_shape'] ?? ''),
            (string)($_POST['full_name'] ?? ''),
            (string)($_POST['email'] ?? ''),
            (string)($_POST['password'] ?? ''),
            (string)($_POST['password_confirm'] ?? ''),
            (string)($_POST['contact_phone'] ?? ''),
            (string)($_POST['website'] ?? ''),
            false,
            (string)($_POST['operating_state'] ?? ''),
        );
        if (is_array($result)) {
            // New organizations start pending: the owner signs in immediately with
            // read-only access (enforced by the lifecycle gate) and sees the review
            // banner, while the sign-up lands in the platform Approvals queue.
            $auth->establishSession($result);
            flash('Welcome to FlxCoreHC. Your organization is under review — you have read-only access until it\'s approved.', 'warn');
            redirect('/');
        }
        view('auth/register', ['error' => $result, 'old' => $_POST, 'bare' => true]);
    }
    view('auth/register', ['old' => [], 'bare' => true]);
}

if (($seg[0] ?? '') === 'logout' && $method === 'POST') {
    CsrfGuard::verify();
    if ($ctx) { AccessLog::record($ctx, 'logout'); }
    $__lo = FlxCoreHC\I18n::locale();
    $auth->logout();
    // keep the sign-in page in the language the person was just using
    if (!headers_sent()) setcookie('flx_lang', $__lo, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true]);
    redirect('/login');
}

// ---- PUBLIC credential verification (no auth): payer/regulator share link -----
// Possession of the 32-char token authorises a read-only view of ONE producer's credential
// status. No session, no access to anything else. Expiry + revocation checked on resolve;
// every view is counted. This is the one place that reads across tenants, gated by the token.
// ---- Terms of Use (public; linked from the sign-in page) — v1.173.0
if (($seg[0] ?? '') === 'terms' && !isset($seg[1]) && $method === 'GET') {
    view('terms/public', ['bare' => true, 'org' => 'the Organization', 'effective' => null]);
}

if (($seg[0] ?? '') === 'verify' && isset($seg[1])) {
    $link = FlxCoreHC\ShareLinkService::resolveForView((string)$seg[1]);
    if (!$link) {
        http_response_code(404);
        view('verify/invalid', ['standalone' => true]);
        exit;
    }

    // Build a read-only context for the link's tenant (no user, minimal privileges).
    $trow = FlxCoreHC\Database::pdo()->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
    $trow->execute([$link['tenant_id']]);
    $trow = $trow->fetch();
    if (!$trow) { http_response_code(404); view('verify/invalid', ['standalone' => true]); exit; }

    $vctx = new FlxCoreHC\TenantContext(
        $link['tenant_id'], 'share-link', 'system:share-link',
        (string)($trow['name'] ?? ''), (string)($trow['vertical'] ?? 'healthcare'),
        (string)($trow['org_shape'] ?? 'agency'), 'Share link'
    );

    $vHolders     = new FlxCoreHC\Repository\HolderRepository($vctx);
    $vCreds       = new FlxCoreHC\Repository\CredentialRepository($vctx);
    $vReqs        = new FlxCoreHC\Repository\ProducerRequirementRepository($vctx);
    $vRegulatorReqs = new FlxCoreHC\Repository\RegulatorRequirementRepository($vctx);
    $vRegulators    = new FlxCoreHC\Repository\RegulatorRepository($vctx);
    $vDocs        = new FlxCoreHC\Repository\DocumentRepository($vctx);


    $holder = $vHolders->find((string) $link['holder_id']);
    if (!$holder) { http_response_code(404); view('verify/invalid', ['standalone' => true]); exit; }

    $vLines  = FlxCoreHC\CatalogService::linesOf($trow['business_lines'] ?? 'medicare');
    $vStates = FlxCoreHC\CatalogService::statesOf($trow['operating_states'] ?? '');
    $data = flx_compliance_data($vHolders, $vCreds, $vReqs, $vRegulatorReqs, $vRegulators,
        (string)($trow['org_shape'] ?? 'agency'), $vLines, $vDocs, $vStates);

    $producer = null;
    foreach ($data['producers'] as $pp) {
        if (($pp['holder']['id'] ?? null) === $link['holder_id']) { $producer = $pp; break; }
    }

    FlxCoreHC\AccessLog::record($vctx, 'view', 'share_link', null);

    view('verify/show', [
        'standalone' => true,
        'link'     => $link,
        'tenant'   => $trow,
        'holder'   => $holder,
        'producer' => $producer,
        'scope'    => $link['scope'],
    ]);
    exit;
}


// ---- Accept a user invitation (public, unauthenticated) ---------------------
if (($seg[0] ?? '') === 'invite' && isset($seg[1])) {
    $inv = FlxCoreHC\TeamService::findInvite($seg[1]);
    if ($method === 'POST') {
        CsrfGuard::verify();
        [$ok, $msg] = FlxCoreHC\TeamService::acceptInvite($seg[1], (string) ($_POST['password'] ?? ''));
        if ($ok) { flash($msg, $ok ? 'ok' : 'error'); redirect('/login'); }
        view('auth/accept_invite', ['inv' => $inv, 'error' => $msg, 'token' => $seg[1], 'bare' => true]);
        exit;
    }
    if (!$inv) { http_response_code(404); view('auth/accept_invite', ['inv' => null, 'token' => $seg[1], 'bare' => true]); exit; }
    view('auth/accept_invite', ['inv' => $inv, 'token' => $seg[1], 'bare' => true]);
    exit;
}

// ---- Stripe webhook (public, signature-verified) ----------------------------
if (($seg[0] ?? '') === 'webhooks' && ($seg[1] ?? '') === 'stripe') {
    $payload = file_get_contents('php://input') ?: '';
    $sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    [$code, $msg] = BillingService::handleWebhook($payload, $sig);
    http_response_code($code);
    echo $msg;
    exit;
}

// ---- Everything below requires a signed-in tenant context ---------------
// ---- PUBLIC calendar feed (no auth): read-only ICS by per-user token ----------
// Possession of the 40-char token authorises a read-only iCalendar feed of the user's tenant
// events. Subscribe in Google Calendar / Microsoft 365 / Apple Calendar via "add by URL".
if (($seg[0] ?? '') === 'ical' && isset($seg[1])) {
    $tok = preg_replace('/\.ics$/', '', (string)$seg[1]);
    if (!preg_match('/^[a-f0-9]{40}$/', $tok)) { http_response_code(404); exit("Not found."); }
    // v1.170.8 (security): the feed dies with a suspended user or tenant, and runs AS that user so
    // need-to-know (assigned providers only) applies to the calendar the same as in the app.
    $u = FlxCoreHC\Database::pdo()->prepare("SELECT u.id, u.role, u.full_name, u.tenant_id, t.name FROM users u JOIN tenants t ON t.id = u.tenant_id
        WHERE u.ical_token = ? AND u.status = 'active' AND t.status IN ('active','pending') LIMIT 1");
    $u->execute([$tok]);
    $row = $u->fetch();
    if (!$row) { http_response_code(404); exit("Not found."); }
    $fctx = new FlxCoreHC\TenantContext((string)$row['tenant_id'], (string)$row['id'], (string)$row['role'],
        (string)($row['name'] ?? ''), 'healthcare', 'medical_group', (string)($row['full_name'] ?? 'Calendar feed'));
    $feed = (new FlxCoreHC\Repository\CalendarRepository($fctx))->icsFeed((string)($row['name'] ?? 'FlxCoreHC') . ' — Compliance calendar');
    header('Content-Type: text/calendar; charset=UTF-8');
    header('Content-Disposition: inline; filename="flxcorehc.ics"');
    header('Cache-Control: max-age=3600');
    echo $feed;
    exit;
}

// ---- External share (public, password-protected, time-limited) ------------------------------
// /s/{token}            → password prompt (or blocked notice)
// /s/{token} POST       → verify password → session-scoped access for this share
// /s/{token}/view       → read-only profile within scope
// /s/{token}/doc/{id}   → stream an in-scope document (logged)
if ($seg[0] === 's' && ($seg[1] ?? '') !== '') {
    $__tok = (string)$seg[1];
    // v1.172.9: the recipient is not a user, so there is no saved language. ?lang=es|en wins and is kept in the
    // session; otherwise the browser's Accept-Language decides (Spanish only; everything else stays English).
    // A signed-in user keeps their own saved language unless they pick one here.
    if (isset($_GET['lang']) && isset(FlxCoreHC\I18n::LOCALES[(string)$_GET['lang']])) {
        FlxCoreHC\I18n::setLocale((string)$_GET['lang']);
    } elseif (empty($_SESSION['locale']) && empty($_SESSION['auth']) && str_starts_with(strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')), 'es')) {
        FlxCoreHC\I18n::setLocale('es');
    }
    $__share = FlxCoreHC\ExternalShareService::byToken($__tok);
    $__blocked = $__share ? FlxCoreHC\ExternalShareService::blockedReason($__share) : 'This link is not valid.';
    if ($__blocked) {
        if ($__share) FlxCoreHC\ExternalShareService::logAccess($__share, str_contains($__blocked, 'expired') ? 'expired' : 'blocked');
        http_response_code(410);
        view('share/external_blocked', ['bare' => true, 'reason' => $__blocked, 'skipOutputTranslate' => false]);
    }
    $__unlockedKey = 'ext_share_' . $__share['id'];
    $__unlocked = !empty($_SESSION[$__unlockedKey]) && (time() - (int)$_SESSION[$__unlockedKey]) < 3600;

    if (($seg[2] ?? '') === '' ) {
        if ($method === 'POST') {
            CsrfGuard::verify();
            // Per-IP limit across ALL share links (the per-share lock alone could be spread over many tokens).
            $__rl = new FlxCoreHC\Auth\RateLimiter();
            $__rlKey = 'extshare|' . substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            if ($__rl->tooManyAttempts($__rlKey, 20, 900)) {
                FlxCoreHC\ExternalShareService::logAccess($__share, 'locked');
                http_response_code(429);
                view('share/external_blocked', ['bare' => true, 'reason' => 'Too many attempts from your network — try again in 15 minutes.']);
            }
            $__rl->record($__rlKey);
            if (FlxCoreHC\ExternalShareService::checkPassword($__share, (string)($_POST['password'] ?? ''))) {
                $__rl->clear($__rlKey);
                $_SESSION[$__unlockedKey] = time();
                FlxCoreHC\ExternalShareService::recordView($__share);
                redirect('/s/' . $__tok . '/view');
            }
            view('share/external_password', ['bare' => true, 'token' => $__tok, 'error' => 'Incorrect password.', 'recipient' => $__share['recipient_name']]);
        }
        if ($__unlocked) redirect('/s/' . $__tok . '/view');
        view('share/external_password', ['bare' => true, 'token' => $__tok, 'error' => null, 'recipient' => $__share['recipient_name']]);
    }
    if (!$__unlocked) redirect('/s/' . $__tok);

    if (($seg[2] ?? '') === 'view') {
        view('share/external_view', ['bare' => true, 'share' => $__share, 'token' => $__tok,
            'profile' => FlxCoreHC\ExternalShareService::profileFor($__share)]);
    }
    if (($seg[2] ?? '') === 'doc' && ($seg[3] ?? '') !== '') {
        $__docId = (string)$seg[3];
        $__prof = FlxCoreHC\ExternalShareService::profileFor($__share);
        $__doc = null;
        foreach ($__prof['docs'] as $list) { foreach ($list as $d) { if ($d['id'] === $__docId) { $__doc = $d; break 2; } } }
        if (!$__doc) { http_response_code(404); exit('Not found.'); }
        $__row = FlxCoreHC\Database::pdo()->prepare('SELECT file_path, original_name, mime_type FROM credential_documents WHERE id = ? AND tenant_id = ? LIMIT 1');
        $__row->execute([$__docId, $__share['tenant_id']]);
        $__f = $__row->fetch();
        if (!$__f || !is_file($__f['file_path'])) { http_response_code(404); exit('File not available.'); }
        FlxCoreHC\ExternalShareService::logAccess($__share, 'download', $__docId);
        header('Content-Type: ' . ($__f['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $__f['original_name']) . '"');
        header('X-Content-Type-Options: nosniff');
        if (!FlxCoreHC\EncryptedFile::send($__f['file_path'])) { http_response_code(404); }
        exit;
    }
    http_response_code(404); exit('Not found.');
}

// ---- Microsoft 365 OAuth callback (signed-in user; state is bound to their session) ---------
if ($seg[0] === 'oauth' && ($seg[1] ?? '') === 'microsoft' && ($seg[2] ?? '') === 'callback') {
    if (!$ctx) redirect('/login');
    $__scope = $_SESSION['ms_oauth_state']['scope'] ?? 'platform';
    if (!empty($_GET['error'])) { // Show only a short machine code from the query string, never free text a crafted link could supply.
        $__oe = substr(preg_replace('/[^a-z_]/', '', strtolower((string) $_GET['error'])), 0, 40);
        audit_log($ctx, 'm365_connect_failed', 'mail', $__scope, json_encode(['ok' => false, 'error' => $__oe]));
        flash('Microsoft sign-in was cancelled or failed. Try again from Connect.', 'error'); }
    else {
        [$ok, $msg] = FlxCoreHC\MicrosoftOAuth::handleCallback((string)($_GET['code'] ?? ''), (string)($_GET['state'] ?? ''));
        audit_log($ctx, $ok ? 'm365_connected' : 'm365_connect_failed', 'mail', $__scope, json_encode(['ok' => $ok]));
        flash($msg, $ok ? 'ok' : 'error');
    }
    redirect($__scope === 'platform' ? '/admin/email' : '/organization/email');
}

if (!$ctx) {
    redirect('/login');
}

// ---- Terms of Use (Ownership & White-Label Standard, element 10): every user accepts the current version at their first sign-in
// (and again whenever the version changes); nothing else opens until then. The platform operator (the owner) is exempt.
if (($seg[0] ?? '') === 'terms' && ($seg[1] ?? '') === 'accept') {
    $__tAccepted = FlxCoreHC\TermsService::userAccepted($ctx->userId);
    $__tOrgAcc = FlxCoreHC\TermsService::organizationAcceptance($ctx->tenantId);
    $__tMayOrg = FlxCoreHC\Authz::can($ctx, FlxCoreHC\TermsService::PERMISSION);
    if ($method === 'POST') {
        CsrfGuard::verify();
        $__done = FlxCoreHC\TermsService::accept($ctx, !empty($_POST['for_organization']) && !empty($_POST['authority']));
        flash($__done ? 'Thank you — the Terms of Use are accepted.' : 'No change.', 'ok');
        redirect('/');
    }
    $__org = (new OrgService($ctx))->get();
    view('terms/accept', ['ctx' => $ctx, 'bare' => !$__tAccepted, 'accepted' => $__tAccepted, 'orgAcceptance' => $__tOrgAcc, 'mayAcceptForOrg' => $__tMayOrg,
        'org' => $ctx->tenantName, 'effective' => $__org['created_at'] ?? null]);
}
if (!$ctx->isPlatformAdmin && empty($_SESSION['auth']['impersonated_by']) && !in_array($seg[0] ?? '', ['terms', 'logout'], true)) {
    $__tk = $ctx->userId . '|' . FlxCoreHC\TermsService::version();
    if (($_SESSION['terms_ok'] ?? '') !== $__tk) {
        if (FlxCoreHC\TermsService::userAccepted($ctx->userId)) { $_SESSION['terms_ok'] = $__tk; }
        else { redirect('/terms/accept'); }
    }
}

// Resolve the tenant's plan features for this request (product-tier gating).
$GLOBALS['flx_features'] = PlanService::featuresFor($ctx->tenantId);

// Billing state (trial / grace / read-only lock).
$__ts = FlxCoreHC\Database::pdo()->prepare("SELECT * FROM tenants WHERE id = ?");
$__ts->execute([$ctx->tenantId]);
$__trow = $__ts->fetch() ?: [];
$GLOBALS['flx_billing'] = BillingService::state($__trow);

// ---- Tenant lifecycle enforcement (v1.45) -----------------------------------
// suspended/rejected: the session ends now, with an explanation at the login screen.
// pending: everyone in the tenant is effectively read_only — pages render, no POST lands.
$__tenantStatus = (string) ($__trow['status'] ?? 'active');
$GLOBALS['flx_tenant_status'] = $__tenantStatus;
if (in_array($__tenantStatus, ['suspended', 'rejected', 'closed'], true)) {
    $auth->logout();   // destroys the session — carry the reason as a code, not a flash
    redirect('/login?notice=' . ($__tenantStatus === 'suspended' ? 'org_suspended' : 'org_not_approved'));
}
// v1.171.5: a person's OWN sign-in security (2FA enrol/confirm/disable on /profile) is not a change to the organization's data,
// so the read-only block does not apply to it (read-only users could not enrol even when the org required 2FA).
$__ownSecurity = ($seg[0] ?? '') === 'profile' && in_array((string) ($_POST['action'] ?? ''), ['2fa_start', '2fa_confirm', '2fa_disable', '2fa_email_start', '2fa_email_confirm', '2fa_email_code'], true);
if ($method === 'POST' && !$__ownSecurity && !FlxCoreHC\Authz::canMutate($ctx, $__tenantStatus)) {
    // Read-only (pending tenant, or the read_only role): the page they came from reloads
    // with an explanation. Logout is handled earlier and is unaffected.
    flash($__tenantStatus === 'pending'
        ? 'Your organization is pending approval — access is read-only until it\'s activated.'
        : 'Your account has read-only access.');
    $__back = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
    if (BASE_PATH !== '' && str_starts_with($__back, BASE_PATH)) { $__back = substr($__back, strlen(BASE_PATH)); }
    redirect($__back !== '' ? $__back : '/');
}
// ---- Org policy: require 2FA for privileged users (S-7) ------------------------------------------
// Keyed on CAPABILITIES, not role names: anyone who can manage the team/organization, unmask PII or export data
// (owner, manager, or any custom role granted those) must enrol before using anything but their profile. Runs
// right after the lifecycle gate so no route (billing, dashboard, …) is reachable first. Support/impersonation
// sessions are exempt — the platform operator's own 2FA is enforced at /admin.
if (!empty($__trow['require_2fa_admins'])
    && FlxCoreHC\Authz::needsTwoFactor($ctx)
    && !$ctx->twoFactor
    && empty($_SESSION['auth']['impersonated_by'])
    && !in_array(($seg[0] ?? ''), ['profile', 'logout', 'account'], true)) {
    flash('Your organization requires two-factor authentication. Please set it up to continue.', 'warn');
    redirect('/profile');
}
// Per-tenant branding -> layout (accent color, logo, display name).
$__accent = $__trow['brand_accent'] ?? null;
$__logo = $__trow['logo_path'] ?? null;
$GLOBALS['flx_brand'] = [
    'name'    => ($__trow['brand_name'] ?? '') ?: ($__trow['name'] ?? 'FlxCoreHC'),
    'accent'  => $__accent ?: null,
    'accent2' => $__accent ? FlxCoreHC\OrgService::darken($__accent) : null,
    'logo'    => $__logo ? (str_starts_with($__logo, 'data:') ? $__logo : BASE_PATH . '/' . ltrim($__logo, '/')) : null,
    // Full token override set (accent, nav, corner style) + the tenant's default colour scheme.
    'themeCss' => FlxCoreHC\ThemeService::css($__trow ?: []),
    'mode'     => FlxCoreHC\ThemeService::defaultMode($__trow ?: []),
    'navIcons' => $__trow['nav_icons'] ?? null,
];
// Enforce read-only for mutating requests when locked (allow billing, logout, webhook, invite-accept).
if ($GLOBALS['flx_billing']['locked'] && $method === 'POST'
    && !in_array(($seg[0] ?? ''), ['billing','logout'], true)) {
    if (in_array($ctx->role, ['tenant_owner','manager'], true)) {
        flash($GLOBALS['flx_billing']['notice']);
        redirect('/billing');
    }
    http_response_code(403);
    exit('This account is read-only. Please ask an owner to update billing.');
}

// Profile (self-service) — always available to any signed-in user.
if (($seg[0] ?? '') === 'profile') {
    $newCodes = null;
    if ($method === 'POST') {
        CsrfGuard::verify();
        $act = $_POST['action'] ?? '';
        if ($act === 'password') {
            [$ok, $msg] = $auth->changePassword($ctx->userId, (string)($_POST['current'] ?? ''), (string)($_POST['new'] ?? ''));
        } elseif ($act === '2fa_start' && FlxCoreHC\Auth\AuthService::totpEnabledFor($ctx->userId)) {
            [$ok, $msg] = [false, 'Two-factor authentication is already on. Turn it off first (this needs a current code) before enrolling a new device.'];
        } elseif ($act === '2fa_start') {
            $enrol = FlxCoreHC\Auth\AuthService::beginTotpEnrolment($ctx->userEmail ?? '', $GLOBALS['flx_brand']['name'] ?? 'FlxCoreHC');
            $_SESSION['totp_pending_secret'] = $enrol['secret'];
            view('profile', ['ctx' => $ctx, 'enrol' => $enrol]);
        } elseif ($act === '2fa_confirm') {
            $secret = (string)($_SESSION['totp_pending_secret'] ?? '');
            // v1.171.8: a pending secret left in another session must not survive 2FA being turned on elsewhere.
            if (FlxCoreHC\Auth\AuthService::totpEnabledFor($ctx->userId)) {
                unset($_SESSION['totp_pending_secret']);
                flash('Two-factor authentication is already on. Turn it off first (this needs a current code) before enrolling a new device.', 'error');
                redirect('/profile');
            }
            [$ok, $msg, $codes] = FlxCoreHC\Auth\AuthService::confirmTotp($ctx->userId, $secret, (string)($_POST['code'] ?? ''));
            if ($ok) {
                audit_log($ctx, '2fa_enabled', 'users', $ctx->userId, null);
                unset($_SESSION['totp_pending_secret']);
                $_SESSION['auth']['totp_enabled'] = 1;
                flash($msg, $ok ? 'ok' : 'error');
                view('profile', ['ctx' => $ctx, 'backupCodes' => $codes]);
            }
            if ($secret === '') { flash($msg, 'error'); redirect('/profile'); }   // v1.171.6: nothing pending (e.g. 2FA already on) → no empty enrolment form
            $enrol = ['secret' => $secret, 'uri' => FlxCoreHC\Auth\Totp::uri($secret, $ctx->userEmail ?? '', $GLOBALS['flx_brand']['name'] ?? 'FlxCoreHC')];
            view('profile', ['ctx' => $ctx, 'enrol' => $enrol, 'error' => $msg]);
        } elseif ($act === '2fa_email_start') {
            // v1.172.0: e-mailed codes as the second step. The account proves it can read its mailbox before the method turns on.
            if (FlxCoreHC\Auth\AuthService::totpEnabledFor($ctx->userId)) {
                [$ok, $msg] = [false, 'Two-factor authentication is already on. Turn it off first (this needs a current code) before changing the method.'];
            } elseif (!FlxCoreHC\Auth\AuthService::emailCodesAllowed()) {
                [$ok, $msg] = [false, 'E-mail codes are not allowed on this installation. Use an authenticator app.'];
            } else {
                [$ok, $msg] = FlxCoreHC\Auth\AuthService::sendEmailCode($ctx->userId, 'enrol');
                if ($ok) { flash($msg, 'ok'); view('profile', ['ctx' => $ctx, 'emailEnrol' => true]); }
            }
        } elseif ($act === '2fa_email_confirm') {
            $__rlE = new FlxCoreHC\Auth\RateLimiter(); $__rlEk = '2fa_email_confirm|' . $ctx->userId;
            if ($__rlE->tooManyAttempts($__rlEk, 8, 900)) { flash('Too many attempts. Try again in 15 minutes.', 'error'); redirect('/profile'); }
            [$ok, $msg, $codes] = FlxCoreHC\Auth\AuthService::confirmEmailMfa($ctx->userId, (string) ($_POST['code'] ?? ''));
            if ($ok) {
                $__rlE->clear($__rlEk);
                audit_log($ctx, '2fa_enabled', 'users', $ctx->userId, json_encode(['method' => 'email']));
                $_SESSION['auth']['totp_enabled'] = 1;
                flash($msg, 'ok');
                view('profile', ['ctx' => $ctx, 'backupCodes' => $codes]);
            }
            $__rlE->record($__rlEk);
            if (FlxCoreHC\Auth\AuthService::totpEnabledFor($ctx->userId) || !FlxCoreHC\Auth\AuthService::emailCodesAllowed()) { flash($msg, 'error'); redirect('/profile'); }
            view('profile', ['ctx' => $ctx, 'emailEnrol' => true, 'error' => $msg]);
        } elseif ($act === '2fa_email_code') {
            // A code to turn two-factor OFF when the method is e-mail (the authenticator method uses the app's current code).
            if (FlxCoreHC\Auth\AuthService::mfaMethodFor($ctx->userId) !== FlxCoreHC\Auth\AuthService::MFA_EMAIL) { [$ok, $msg] = [false, 'This account does not use e-mail codes.']; }
            else { [$ok, $msg] = FlxCoreHC\Auth\AuthService::sendEmailCode($ctx->userId, 'disable'); }
        } elseif ($act === '2fa_disable') {
            // v1.170.8 (security): rate-limit code guesses like /login/verify, so a hijacked session
            // cannot brute-force the 6-digit code to switch 2FA off.
            $__rl2 = new FlxCoreHC\Auth\RateLimiter(); $__rlKey2 = '2fa_disable|' . $ctx->userId;
            if ($__rl2->tooManyAttempts($__rlKey2, 8, 900)) { flash('Too many attempts. Try again in 15 minutes.', 'error'); redirect('/profile'); }
            [$ok, $msg] = FlxCoreHC\Auth\AuthService::disableTotp($ctx->userId, (string)($_POST['code'] ?? ''));
            if ($ok) { $__rl2->clear($__rlKey2); audit_log($ctx, '2fa_disabled', 'users', $ctx->userId, null); } else { $__rl2->record($__rlKey2); }
            flash($msg, $ok ? 'ok' : 'error');
            redirect('/profile');
        } else {
            [$ok, $msg] = $auth->updateProfile($ctx->userId, $ctx->tenantId, (string)($_POST['full_name'] ?? ''));
            // Activity-digest cadence (best-effort; column added in migration 103).
            $dg = in_array($_POST['activity_digest'] ?? '', ['off','daily','weekly'], true) ? $_POST['activity_digest'] : 'off';
            try { FlxCoreHC\Database::pdo()->prepare('UPDATE users SET activity_digest = ? WHERE id = ?')->execute([$dg, $ctx->userId]); } catch (\Throwable $e) {}
            if ($ok) { $_SESSION['auth']['user_name'] = trim((string)$_POST['full_name']); }
        }
        flash($msg, $ok ? 'ok' : 'error');
        redirect('/profile');
    }
    $__dg = 'off'; try { $__s = FlxCoreHC\Database::pdo()->prepare('SELECT activity_digest FROM users WHERE id = ?'); $__s->execute([$ctx->userId]); $__dg = (string)($__s->fetchColumn() ?: 'off'); } catch (\Throwable $e) {}
    view('profile', ['ctx' => $ctx, 'digest' => $__dg]);
}

// Billing & subscription (owner/manager)
if (($seg[0] ?? '') === 'billing') {
    if (!in_array($ctx->role, ['tenant_owner','manager'], true)) { http_response_code(403); exit('Forbidden.'); }
    $tenant = FlxCoreHC\PlanService::forTenant($ctx->tenantId); // plan row (for display)
    $trow = FlxCoreHC\Database::pdo()->prepare("SELECT * FROM tenants WHERE id = ?");
    $trow->execute([$ctx->tenantId]); $tenantRow = $trow->fetch();
    if ($method === 'POST') {
        CsrfGuard::verify();
        $act = $_POST['action'] ?? '';
        if ($act === 'checkout') {
            $plan = FlxCoreHC\PlanService::find((string)($_POST['plan_id'] ?? ''));
            if ($plan) { [$ok, $url] = BillingService::checkout($tenantRow, $plan); if ($ok && $url) redirect($url); flash(is_string($url)?$url:'Could not start checkout.', 'error'); }
            redirect('/billing');
        }
        if ($act === 'portal') {
            [$ok, $url] = BillingService::portal($tenantRow);
            if ($ok && $url) redirect($url);
            flash(is_string($url)?$url:'Billing portal unavailable.', 'error'); redirect('/billing');
        }
    }
    if (($seg[1] ?? '') === 'subscribe' && !empty($seg[2])) {
        $plan = FlxCoreHC\PlanService::find($seg[2]);
        if (!$plan) { flash('Plan not found.', 'error'); redirect('/billing'); }
        [$ok, $secret] = BillingService::embeddedSession($tenantRow, $plan);
        view('billing_checkout', ['ctx' => $ctx, 'plan' => $plan, 'ok' => $ok,
            'clientSecret' => $ok ? $secret : '', 'error' => $ok ? '' : $secret,
            'pk' => BillingService::publishableKey()]);
    }

    // ---- This organization's own invoices + balance (offline billing) --------
    if (($seg[1] ?? '') === 'invoices') {
        view('billing/invoices', ['ctx' => $ctx,
            'invoices' => FlxCoreHC\InvoiceService::all($ctx->tenantId),
            'ledger' => FlxCoreHC\InvoiceService::ledger($ctx->tenantId),
            'plan' => $tenant]);
    }
    if (($seg[1] ?? '') === 'invoice' && !empty($seg[2])) {
        $invId = (string) $seg[2];
        $invoice = FlxCoreHC\InvoiceService::find($invId);
        // Tenant-scoped: only this organization's own invoices, never another's.
        if (!$invoice || $invoice['tenant_id'] !== $ctx->tenantId) { http_response_code(404); exit('Invoice not found.'); }
        if (($seg[3] ?? '') === 'pdf') {
            $brandName = $GLOBALS['flx_brand']['name'] ?? $ctx->tenantName;
            $bytes = FlxCoreHC\InvoiceService::pdf($invId, ['name' => $brandName]);
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $invoice['number'] . '.pdf"');
            header('X-Content-Type-Options: nosniff');
            echo $bytes; exit;
        }
        view('billing/invoice', ['ctx' => $ctx, 'invoice' => $invoice,
            'items' => FlxCoreHC\InvoiceService::items($invId),
            'payments' => FlxCoreHC\InvoiceService::paymentsFor($invId),
            'methods' => FlxCoreHC\InvoiceService::METHODS]);
    }

    view('billing', ['ctx' => $ctx, 'tenantRow' => $tenantRow,
        'usage' => FlxCoreHC\PlanService::usage($ctx->tenantId),
        'plans' => FlxCoreHC\PlanService::all(),
        'billingOn' => BillingService::isConfigured()]);
}

// Plan feature gate: block routes for features not in the tenant's plan.
$__gated = ['scheduling'=>'scheduling',
            'reports'=>'analytics','network'=>'network'];
if (isset($seg[0], $__gated[$seg[0]]) && !feature_on($__gated[$seg[0]])) {
    flash('That feature is not included in your current plan.', 'error');
    redirect('/');
}

$holders     = new HolderRepository($ctx);
$credentials = new CredentialRepository($ctx);
$tasks       = new RenewalTaskRepository($ctx);
$documents   = new DocumentRepository($ctx);
$auditLogs   = new AuditLogRepository($ctx);
$regulators    = new RegulatorRepository($ctx);
$compliance  = new ComplianceRepository($ctx);
$producerReqs = new FlxCoreHC\Repository\ProducerRequirementRepository($ctx);
$regulatorReqs  = new FlxCoreHC\Repository\RegulatorRequirementRepository($ctx);
$holderRegs   = new FlxCoreHC\Repository\HolderRegulatorRepository($ctx);
$verifications = new FlxCoreHC\Repository\VerificationRepository($ctx);
$attestations = new FlxCoreHC\Repository\AttestationRepository($ctx);
$applications = new FlxCoreHC\Repository\ApplicationRepository($ctx);
$screenings   = new FlxCoreHC\Repository\ScreeningRepository($ctx);
$__tlines     = FlxCoreHC\CatalogService::linesOf($__trow['business_lines'] ?? 'medicare');
$__tstates    = FlxCoreHC\CatalogService::statesOf($__trow['operating_states'] ?? '');
$training     = new FlxCoreHC\Repository\TrainingResourceRepository($ctx);
$ceEntries    = new FlxCoreHC\Repository\CeEntryRepository($ctx);
$templates    = new FlxCoreHC\TemplateService($ctx);
$shareLinks   = new FlxCoreHC\ShareLinkService($ctx);
$calendar     = new CalendarRepository($ctx);
$calTypes     = new FlxCoreHC\Repository\CalendarEventTypeRepository($ctx);
$riskScore    = $ctx ? new RiskScoreService($ctx) : null;

// v1.170.9 (security): confinement of provider self-service logins covers EVERY portal-only role, incl. on '/'.
$__isPortalRole = FlxCoreHC\RoleService::isPortalContext($ctx);

// ---- Dashboard ----------------------------------------------------------
if (($seg[0] ?? '') === '') {   // root path — the dashboard
    if ($__isPortalRole) { redirect('/me'); }
    if ($ctx->isPlatformAdmin) {
        $admin = new SuperadminService($ctx);
        view('dashboard/platform', ['ctx'=>$ctx, 'metrics'=>$admin->metrics(), 'rev'=>$admin->billingOverview(), 'tenants'=>array_slice($admin->tenants(), 0, 6)]);
        return;
    }
    // Org-wide readiness roll-up: average the compliance-graded score across providers, and
    // count how many need attention. One engine pass yields every provider's score.
    $__rollData = flx_compliance_data($holders, $credentials, $producerReqs, $regulatorReqs, $regulators,
        (string)($__trow['org_shape'] ?? 'agency'), $__tlines, $documents, $__tstates, $holderRegs);
    $__scores = [];
    foreach ($__rollData['producers'] as $__p) { if (($__p['score'] ?? null) !== null) $__scores[] = (int)$__p['score']; }
    $readinessAvg = $__scores === [] ? null : (int) round(array_sum($__scores) / count($__scores));
    $readinessLow = count(array_filter($__scores, fn($s) => $s < 70));

    $__supPending = $ctx->role === 'tenant_owner' ? FlxCoreHC\SupportAccess::pendingFor($ctx->tenantId) : null;
    $__supActive  = null;
    if ($ctx->role === 'tenant_owner') {
        foreach (FlxCoreHC\SupportAccess::historyFor($ctx->tenantId, 5) as $__g) {
            if ($__g['status'] === 'approved' && $__g['revoked_at'] === null && $__g['expires_at'] > utc_now()) { $__supActive = $__g; break; }
        }
    }
    view('dashboard', [
        'supportPending' => $__supPending, 'supportActive' => $__supActive,
        'ctx'       => $ctx,
        'onboarding' => FlxCoreHC\Authz::can($ctx, 'org.manage') ? (new FlxCoreHC\OnboardingService($ctx))->checklist() : null,   // setup checklist is for people who can act on it
        'notifSwept' => (function() use ($ctx) { try { return FlxCoreHC\NotificationService::sweep($ctx); } catch (\Throwable $e) { return 0; } })(),
        'counts'    => $credentials->statusCounts(),
        'horizon'   => $credentials->expiringWithin(180),
        'holderCnt' => $holders->count(),
        'openTasks' => $tasks->count(['status' => 'pending']) + $tasks->count(['status' => 'in_progress']),
        'openApps'  => $applications->openCount(),
        'screenFlags' => $screenings->flaggedCount(),
        'readinessAvg' => $readinessAvg,
        'readinessLow' => $readinessLow,
        'dash'      => (function() use ($ctx) { unset($_SESSION['nav_from_dashboard']); try { return (new FlxCoreHC\DashboardService($ctx))->build(); } catch (\Throwable $e) { flx_log_error('error', 'dashboard build failed: ' . $e->getMessage()); return null; } })(),
    ]);
}

// ---- Holders --------------------------------------------------------------
// ---- Producer self-service guard --------------------------------------------
// Producer-role users get their own portal: /me, Training, and their documents.
// They may also add/upload credentials for THEIR OWN record (create only — the handler
// forces holder_id to their own and blocks the list, edit, and delete of others).
// Create is open to them; so is opening ONE of their own credentials to correct it or attach the
// certificate. Ownership is enforced in the handler, where the record can actually be loaded —
// here we only allow the URL shape through. Deleting is never theirs.
$__producerCredSelfServe = ($seg[0] ?? '') === 'credentials'
    && ((($seg[1] ?? '') === 'new')
        || (($seg[1] ?? null) === null && $method === 'POST')
        || (($seg[1] ?? '') !== '' && ($seg[1] ?? '') !== 'new'
            && in_array($seg[2] ?? '', ['edit', 'documents'], true))
        || (($seg[1] ?? '') !== '' && ($seg[1] ?? '') !== 'new' && ($seg[2] ?? '') === '' && $method === 'POST'));
// v1.170.8 (security): every portal-only role is confined to the portal, not just the built-in 'producer' slug.
$__isPortalRole = FlxCoreHC\RoleService::isPortalContext($ctx);   // (defined above the dashboard route, v1.170.9)
if ($__isPortalRole && !in_array(($seg[0] ?? ''), ['me', 'training', 'logout', 'documents', 'account'], true)
    && !$__producerCredSelfServe
    && !($seg[0] === 'holders' && ($seg[1] ?? '') === $ctx->holderId && in_array($seg[2] ?? '', ['ce', 'ce-delete'], true))) {
    redirect('/me');
}

// ---- Producer portal ---------------------------------------------------------
if ($seg[0] === 'me') {
    $hid = $ctx->holderId;
    if (!$hid || !($meRow = $holders->find($hid))) {
        // Any portal-only role without a linked provider stops here (never bounce to '/', which the
        // portal guard would send straight back → redirect loop).
        if ($__isPortalRole) { http_response_code(403); exit('Your login is not linked to a provider record — ask your administrator.'); }
        redirect('/');
    }
    if (($seg[1] ?? '') === 'cv') {
        // A provider can download their OWN credential CV. $meRow is already their tenant-scoped
        // record (resolved from $ctx->holderId above), so no further ownership check is needed.
        stream_holder_cv($meRow, $ctx, $credentials, $documents);
    }
    // ---- Cross-organization links: the provider is the consent anchor ----------------------
    if (($seg[1] ?? '') === 'links') {
        $npiBidx = !empty($meRow['npi']) ? FlxCoreHC\Crypto::blindIndex(preg_replace('/\D/', '', (string)$meRow['npi'])) : null;
        if ($method === 'POST' && $npiBidx) {
            CsrfGuard::verify();
            if (($seg[2] ?? '') === 'withdraw-all') {
                $n = FlxCoreHC\CrossTenantService::providerRevokeAll($npiBidx);
                audit_log($ctx, 'cross_link_provider_withdrew', 'holders', $hid, json_encode(['links' => $n]));
                flash($n ? "Consent withdrawn — $n link(s) and all shares revoked." : 'No active links.'); redirect('/me/links');
            }
            if (($seg[2] ?? '') !== '' && in_array($seg[3] ?? '', ['approve','decline'], true)) {
                [$ok, $msg] = FlxCoreHC\CrossTenantService::providerConsent((string)$seg[2], $npiBidx, (string)$ctx->userId, $seg[3] === 'approve');
                audit_log($ctx, 'cross_link_provider_' . $seg[3], 'holders', $hid, json_encode(['link_id' => $seg[2]]));
                flash($msg, $ok ? 'ok' : 'error'); redirect('/me/links');
            }
        }
        view('me/links', ['ctx' => $ctx, 'row' => $meRow,
            'pending' => $npiBidx ? FlxCoreHC\CrossTenantService::pendingForProvider($npiBidx) : [],
            'links'   => $npiBidx ? FlxCoreHC\CrossTenantService::linksForProvider($npiBidx) : [], 'hasNpi' => (bool)$npiBidx]);
    }
    // ---- Document requests from the organization: the provider/office fulfils them here ----
    if (($seg[1] ?? '') === 'requests') {
        $svc = new FlxCoreHC\DocumentRequestService($ctx);
        if ($method === 'POST' && ($seg[2] ?? '') !== '' && ($seg[3] ?? '') === 'submit') {
            CsrfGuard::verify();
            $req = $svc->find((string)$seg[2]);
            // Ownership: only requests addressed to THIS provider, and only while open/returned/submitted.
            if (!$req || $req['holder_id'] !== $hid) { http_response_code(403); exit('Forbidden.'); }
            if (!in_array($req['status'], ['open', 'returned', 'submitted'], true)) { flash('That request is closed.', 'error'); redirect('/me/requests'); }
            // Land the file on the target credential (or a placeholder credential if none was named).
            $credId = $req['credential_id'];
            if (!$credId) {
                $credId = $credentials->insert([
                    'holder_id' => $hid, 'credential_type' => 'other', 'name' => $req['requested_kind'],
                    'status' => 'active', 'category' => 'other', 'notes' => 'Created from a document request.',
                ]);
                FlxCoreHC\Database::pdo()->prepare('UPDATE document_requests SET credential_id = ? WHERE id = ? AND tenant_id = ?')->execute([$credId, $req['id'], $ctx->tenantId]);
            }
            $before = $documents->all(['credential_id' => $credId], 'created_at DESC', 1);
            handle_document_upload($documents, $ctx, $credId, $credentials);
            $after = $documents->all(['credential_id' => $credId], 'created_at DESC', 1);
            $newDoc = ($after[0]['id'] ?? null) !== ($before[0]['id'] ?? null) ? ($after[0]['id'] ?? null) : null;
            if ($newDoc) {
                [$ok, $msg] = $svc->submit($req['id'], $newDoc, $ctx->userName ?? 'Provider');
                flash($msg, $ok ? 'ok' : 'error');
            }
            redirect('/me/requests');
        }
        view('me/requests', ['ctx' => $ctx, 'row' => $meRow, 'requests' => $svc->forHolder($hid), 'skipOutputTranslate' => false]);
    }
    if (($seg[1] ?? '') === 'profile') {
        // Self-service intake (Feature: onboarding). The producer completes their OWN record; the
        // update goes through the same tenant-scoped repository as staff edits. Only self-service
        // fields are writable — never name, login email, status, or another holder's row.
        if ($method === 'POST') {
            FlxCoreHC\Auth\CsrfGuard::verify();
            // v1.171.0 (B-1): shared parser, but a self-service provider may only submit these four fields.
            $__selfPost = array_intersect_key($_POST, array_flip(['npi', 'provider_type', 'birth_month', 'role_title']));
            [$upd, $__hErr] = FlxCoreHC\HolderInput::fromPost($ctx, $__selfPost + ['npi' => (string)($_POST['npi'] ?? ''), 'birth_month' => (string)($_POST['birth_month'] ?? ''), 'role_title' => (string)($_POST['role_title'] ?? '')], (string)($meRow['holder_type'] ?? 'individual'), false);
            if ($__hErr) { flash($__hErr, 'error'); redirect('/me/profile'); }
            $holders->update($hid, $upd);
            audit_log($ctx, 'self_profile_update', 'holders', $hid, json_encode(array_keys(array_filter($upd, fn($v)=>$v!==null))));
            flash('Profile saved — your credentialing team can see it now.', 'ok');
            redirect('/me');
        }
        view('me/profile', ['ctx' => $ctx, 'row' => $meRow]);
        exit;
    }
    $data = flx_compliance_data($holders, $credentials, $producerReqs, $regulatorReqs, $regulators,
        (string)($__trow['org_shape'] ?? 'agency'), $__tlines, $documents, $__tstates, $holderRegs);
    FlxCoreHC\TaskSyncService::sync($tasks, $data['producers'], $producerReqs->allAssignees());
    $mine = null;
    foreach ($data['producers'] as $p) { if ($p['holder']['id'] === $hid) { $mine = $p; break; } }
    $myTasks = array_values(array_filter($tasks->openWithCredentials(),
        fn($t) => ($t['holder_id'] ?? null) === $hid && $t['status'] !== 'done'));
    $cyc = flx_ce_cycle(null, flx_ce_months($__tstates));   // jurisdiction-aware look-back (PR = 36 months)
    $ce = $ceEntries->all(['holder_id' => $hid], 'completed_date DESC');
    $ceHours = 0.0; $ceLaw = 0.0;
    foreach ($ce as $e) {
        $d = (string)$e['completed_date'];
        if ($d >= $cyc['start']->format('Y-m-d') && $d <= $cyc['end']->format('Y-m-d')) {
            $ceHours += (float)$e['hours'];
            if ($e['category'] === 'law_ethics') $ceLaw += (float)$e['hours'];
        }
    }
    // Provider self-attestation from their own portal.
    if (($seg[1] ?? '') === 'attest' && $method === 'POST') {
        FlxCoreHC\Auth\CsrfGuard::verify();
        $attestations->insert([
            'holder_id'        => $hid,
            'statement'        => FlxCoreHC\Repository\AttestationRepository::DEFAULT_STATEMENT,
            'method'           => 'portal_ack',
            'attested_by'      => (string)($meRow['first_name'] ?? '') . ' ' . (string)($meRow['last_name'] ?? ($meRow['org_name'] ?? '')),
            'attested_by_user' => $ctx->userId,
            'attested_on'      => utc_now(),
            'ip_address'       => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'notes'            => null,
        ]);
        audit_log($ctx, 'attestation_recorded', 'holders', $id ?? null, null);
        FlxCoreHC\AccessLog::record($ctx, 'write', 'holder_attestation', $hid);
        flash('Thank you — your attestation has been recorded.', 'ok');
        redirect('/me');
    }
    $creds = $credentials->all(['holder_id' => $hid], '(expiry_date IS NULL), expiry_date ASC');
    view('me/index', ['ctx' => $ctx, 'row' => $meRow, 'mine' => $mine, 'myTasks' => $myTasks,
        'openRequests' => (function() use ($ctx, $hid) { try { return (new FlxCoreHC\DocumentRequestService($ctx))->openCountFor($hid); } catch (\Throwable $e) { return 0; } })(),
        'creds' => $creds, 'ce' => $ce, 'ceHours' => $ceHours, 'ceLaw' => $ceLaw, 'cycle' => $cyc,
        'latestAttestation' => $attestations->latestFor($hid)]);
}

// ---- Support-access decisions (Master Admin approves/denies/revokes platform access) ----------
// ---- Onboarding checklist dismissal --------------------------------------------------------
if ($seg[0] === 'onboarding' && ($seg[1] ?? '') === 'dismiss' && $method === 'POST') {
    CsrfGuard::verify();
    FlxCoreHC\Authz::requireCap($ctx, 'org.manage');
    (new FlxCoreHC\OnboardingService($ctx))->dismiss();
    audit_log($ctx, 'onboarding_dismissed', 'tenants', substr($ctx->tenantId, 0, 36), null);
    redirect('/');
}
// ---- Help center (bilingual, content in app/help/{locale}.php) ------------------------
// ---- About & ownership (Ownership & White-Label Standard, element 1) — every signed-in user; the full view needs license.view
if ($seg[0] === 'about' && $method === 'GET') {
    $__about = FlxCoreHC\Ownership::about($ctx);
    if (($_GET['format'] ?? '') === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($__about, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    view('about/index', ['ctx' => $ctx, 'about' => $__about]);
}

if ($seg[0] === 'help') {
    $loc = FlxCoreHC\I18n::locale();
    $file = FLXCOREHC_APP . '/help/' . (is_file(FLXCOREHC_APP . '/help/' . $loc . '.php') ? $loc : 'en') . '.php';
    $sections = require $file;
    $q = trim((string)($_GET['q'] ?? ''));
    $cur = ($seg[1] ?? '') !== '' && isset($sections[$seg[1]]) ? (string)$seg[1] : null;
    $hits = [];
    if ($q !== '') {
        $needle = mb_strtolower($q);
        foreach ($sections as $key => $sec) {
            $hay = mb_strtolower($sec['title'] . ' ' . $sec['summary'] . ' ' . implode(' ', array_map(fn($b) => is_array($b[1]) ? implode(' ', $b[1]) : $b[1], $sec['blocks'])));
            if (str_contains($hay, $needle)) $hits[$key] = $sec;
        }
    }
    view('help/index', ['ctx' => $ctx, 'sections' => $sections, 'cur' => $cur, 'q' => $q, 'hits' => $hits]);   // v1.172.17: the frame is translated; the topics are per-language files (no-translate region in the view)
}

// ---- Notification center ---------------------------------------------------------------
if ($seg[0] === 'notifications') {
    if ($__isPortalRole || $ctx->role === 'provider') { redirect('/'); }
    if ($method === 'POST' && ($seg[1] ?? '') === 'read-all') {
        CsrfGuard::verify();
        FlxCoreHC\NotificationService::markAllRead($ctx);
        redirect('/notifications');
    }
    if ($method === 'POST' && ($seg[1] ?? '') !== '' && ($seg[2] ?? '') === 'read') {
        CsrfGuard::verify();
        FlxCoreHC\NotificationService::markRead($ctx, (string)$seg[1]);
        $to = (string)($_POST['to'] ?? '');
        redirect($to !== '' && str_starts_with($to, '/') ? $to : '/notifications');
    }
    if ($method === 'GET') {
        try { FlxCoreHC\NotificationService::sweep($ctx); } catch (\Throwable $e) {}
        $unreadOnly = !empty($_GET['unread']);
        view('notifications/index', ['ctx' => $ctx, 'rows' => FlxCoreHC\NotificationService::list($ctx, $unreadOnly), 'unreadOnly' => $unreadOnly]);
    }
    exit;
}

if ($seg[0] === 'support-access' && $method === 'POST') {
    CsrfGuard::verify();
    if ($ctx->role !== 'tenant_owner') { http_response_code(403); exit('Only the Master Admin decides support access.'); }
    $act = (string)($_POST['action'] ?? '');
    $rid = (string)($_POST['request_id'] ?? '');
    if ($act === 'approve' || $act === 'deny') {
        $row = FlxCoreHC\SupportAccess::decide($ctx, $rid, $act === 'approve');
        if ($row) {
            $payload = json_encode(['approved_admin_id' => $row['requested_by'], 'admin' => $row['requester_name'],
                'window_start' => $row['decided_at'] ?? null, 'expires' => $row['expires_at'] ?? null, 'decision' => $row['status']]);
            audit_log($ctx, 'support_' . $row['status'], 'impersonation', $rid, $payload);
            FlxCoreHC\AccessLog::record($ctx, 'support_' . $row['status'], 'tenants', $ctx->tenantId);
            flash($act === 'approve'
                ? 'Support access approved for ' . FlxCoreHC\SupportAccess::WINDOW_HOURS . ' hours (until ' . fmt_dt((string)$row['expires_at'], 'm/d/Y g:i A') . '). You can revoke at any time.'
                : 'Support request denied.', 'ok');
        }
    }
    if ($act === 'revoke') {
        if (FlxCoreHC\SupportAccess::revoke($ctx, $rid)) {
            audit_log($ctx, 'support_revoked', 'impersonation', $rid);
            FlxCoreHC\AccessLog::record($ctx, 'support_revoked', 'tenants', $ctx->tenantId);
            flash('Support access revoked — any live session ends on its next click.', 'ok');
        }
    }
    redirect('/');
}

// ---- Locations (practice sites within the tenant) -----------------------------
// ---- Groups (client groups / departments — intermediate grouping within the tenant) ----------
if ($seg[0] === 'groups') {
    FlxCoreHC\Authz::requireCap($ctx, 'org.manage');
    $groups = new FlxCoreHC\Repository\GroupRepository($ctx);
    $glabel = FlxCoreHC\GroupService::label($ctx->orgShape);
    if ($method === 'POST') {
        CsrfGuard::verify();
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'create' || $act === 'update') {
            $data = [
                'name' => trim((string)($_POST['name'] ?? '')),
                'npi' => preg_replace('/\D/', '', (string)($_POST['npi'] ?? '')) ?: null,
                'code' => trim((string)($_POST['code'] ?? '')) ?: null,
                'status' => in_array($_POST['status'] ?? '', ['active','inactive'], true) ? $_POST['status'] : 'active',
                'note' => trim((string)($_POST['note'] ?? '')) ?: null,
            ];
            if ($data['name'] === '') { flash('Enter a name.', 'error'); redirect('/groups'); }
            if (($__ne = FlxCoreHC\Npi::error($data['npi'], 'Group NPI')) !== null) { flash($__ne, 'error'); redirect('/groups'); }
            if ($act === 'create') {
                $gid = $groups->insert($data);
                audit_log($ctx, 'group_create', 'provider_groups', $gid, json_encode(['name' => $data['name']]));
                flash(t('{what} added.', ['what' => t($glabel)]));
            } else {
                $gid = (string)($_POST['id'] ?? '');
                if ($groups->find($gid)) { $groups->update($gid, $data); audit_log($ctx, 'group_update', 'provider_groups', $gid, null); flash(t('{what} updated.', ['what' => t($glabel)])); }
            }
        } elseif ($act === 'delete') {
            $gid = (string)($_POST['id'] ?? '');
            if ($groups->find($gid)) { $groups->delete($gid); audit_log($ctx, 'group_delete', 'provider_groups', $gid, null); flash(t('{what} removed.', ['what' => t($glabel)])); }
        }
        redirect('/groups');
    }
    if (($seg[1] ?? '') !== '') {
        $group = $groups->find((string)$seg[1]);
        if (!$group) { http_response_code(404); exit('Not found.'); }
        $ids = FlxCoreHC\GroupService::holderIdsIn($ctx, (string)$seg[1]);
        $holders = new FlxCoreHC\Repository\HolderRepository($ctx);
        $members = [];
        foreach ($ids as $hid) { if ($h = $holders->find($hid)) $members[] = $h; }
        view('groups/show', ['ctx' => $ctx, 'group' => $group, 'members' => $members, 'glabel' => $glabel]);
        exit;
    }
    view('groups/index', ['ctx' => $ctx, 'rows' => $groups->withMemberCounts(),
        'glabel' => $glabel, 'glabelPlural' => FlxCoreHC\GroupService::label($ctx->orgShape, true),
        'edit' => isset($_GET['edit']) ? $groups->find((string)$_GET['edit']) : null]);
    exit;
}

if ($seg[0] === 'locations') {
    FlxCoreHC\Authz::requireCap($ctx, 'org.manage');
    $locs = new FlxCoreHC\Repository\LocationRepository($ctx);
    if ($method === 'POST') {
        CsrfGuard::verify();
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'create' || $act === 'update') {
            $data = [
                'name' => trim((string)($_POST['name'] ?? '')),
                'npi' => preg_replace('/\D/', '', (string)($_POST['npi'] ?? '')) ?: null,
                'address1' => trim((string)($_POST['address1'] ?? '')) ?: null,
                'address2' => trim((string)($_POST['address2'] ?? '')) ?: null,
                'city' => trim((string)($_POST['city'] ?? '')) ?: null,
                'state' => strtoupper(trim((string)($_POST['state'] ?? ''))) ?: null,
                'zip' => trim((string)($_POST['zip'] ?? '')) ?: null,
                'phone' => trim((string)($_POST['phone'] ?? '')) ?: null,
                'status' => in_array($_POST['status'] ?? '', ['active','inactive'], true) ? $_POST['status'] : 'active',
                'note' => trim((string)($_POST['note'] ?? '')) ?: null,
            ];
            if ($data['name'] === '') { flash('Enter a location name.', 'error'); redirect('/locations'); }
            if (($__ne = FlxCoreHC\Npi::error($data['npi'], 'Location NPI')) !== null) { flash($__ne, 'error'); redirect('/locations'); }
            if ($act === 'create') {
                $lid = $locs->insert($data);
                audit_log($ctx, 'location_create', 'locations', $lid, json_encode(['name' => $data['name']]));
                flash('Location added.', 'ok');
            } else {
                $lid = (string)($_POST['id'] ?? '');
                if ($locs->find($lid)) { $locs->update($lid, $data); audit_log($ctx, 'location_update', 'locations', $lid, null); flash('Location updated.', 'ok'); }
            }
        } elseif ($act === 'delete') {
            $lid = (string)($_POST['id'] ?? '');
            if ($locs->find($lid)) { $locs->delete($lid); audit_log($ctx, 'location_delete', 'locations', $lid, null); flash('Location removed.', 'ok'); }
        }
        redirect('/locations');
    }
    if (($seg[1] ?? '') !== '') {
        $loc = $locs->find((string)$seg[1]);
        if (!$loc) { http_response_code(404); exit('Location not found.'); }
        $ids = FlxCoreHC\LocationService::holderIdsAt($ctx, (string)$seg[1]);
        $holders = new FlxCoreHC\Repository\HolderRepository($ctx);
        $providers = [];
        foreach ($ids as $hid) { if ($h = $holders->find($hid)) $providers[] = $h; }
        view('locations/show', ['ctx' => $ctx, 'loc' => $loc, 'providers' => $providers]);
        exit;
    }
    view('locations/index', ['ctx' => $ctx, 'rows' => $locs->withProviderCounts(),
        'edit' => isset($_GET['edit']) ? $locs->find((string)$_GET['edit']) : null]);
    exit;
}

if ($seg[0] === 'holders') {
    $id = $seg[1] ?? null;
    // ---- Deleted records (trash): list soft-deleted providers/orgs for restore or permanent delete ----
    if ($method === 'GET' && $id === 'trash') {
        FlxCoreHC\Authz::requireCap($ctx, 'records.delete');
        view('holders/trash', ['ctx' => $ctx, 'rows' => $holders->trashed()]);
    }

    // ---- Restore / permanently delete a SOFT-DELETED holder. These operate on deleted records, so they
    //      must sit OUTSIDE the find()-guarded block below (find() returns only live records). ----
    if ($id !== null && $method === 'POST' && ($seg[2] ?? '') === 'restore') {
        FlxCoreHC\Authz::requireCap($ctx, 'records.delete');
        CsrfGuard::verify();
        if (!FlxCoreHC\AssignmentService::canSee($ctx, $id)) { flash('Record not found in Deleted records.', 'error'); redirect('/holders/trash'); }
        if ($holders->restore($id)) { audit_log($ctx, 'provider_restored', 'holders', $id, null); flash('Record restored.', 'ok'); }
        else { flash('Record not found in Deleted records.', 'error'); }
        redirect('/holders/trash');
    }
    if ($id !== null && $method === 'POST' && ($seg[2] ?? '') === 'purge') {
        FlxCoreHC\Authz::requireCap($ctx, 'records.delete');
        CsrfGuard::verify();
        if (!FlxCoreHC\AssignmentService::canSee($ctx, $id)) { flash('Record not found in Deleted records.', 'error'); redirect('/holders/trash'); }
        if (($_POST['confirm'] ?? '') !== 'PERMANENTLY DELETE') { flash('Type the confirmation phrase exactly to permanently delete.', 'error'); redirect('/holders/trash'); }
        // v1.170.6 — single transactional, FK-ordered purge with blockers (linked logins, insurance records).
        [$__ok, $__msg] = (new FlxCoreHC\PurgeService($ctx))->purgeHolder($id);
        flash($__msg, $__ok ? 'ok' : 'error');
        redirect('/holders/trash');
    }

    // NPI lookup (NPPES) — used by the holder form's "Look up" button. JSON, cached server-side.
    // Role-gated (npi.lookup capability) and every lookup is written to the audit trail.
    if ($id === 'npi-lookup') {
        header('Content-Type: application/json');
        if (!FlxCoreHC\Authz::can($ctx, 'npi.lookup')) {
            http_response_code(403);
            echo json_encode(['found' => false, 'error' => 'Your role does not allow NPI lookups.']);
            exit;
        }
        $npi = (string) ($_GET['npi'] ?? '');
        $result = FlxCoreHC\NpiService::lookup($npi);
        $clean = preg_replace('/\D/', '', $npi);
        audit_log($ctx, 'npi_lookup', 'npi', $clean !== '' ? $clean : null,
            json_encode(['npi' => $clean, 'found' => (bool) ($result['found'] ?? false)]));
        echo json_encode($result);
        exit;
    }

    if ($method === 'GET' && $id === null) {
        $rows = $holders->withCredentialSummary();
        // Optional narrowing by group or location. Applied AFTER the repository's need-to-know and
        // support-mode filtering, so a restricted user filtering by group still sees only their
        // assigned providers within it. Both filters compose (intersection).
        $fGroup = trim((string)($_GET['group'] ?? ''));
        $fLoc   = trim((string)($_GET['location'] ?? ''));
        $groupsRepo = new FlxCoreHC\Repository\GroupRepository($ctx);
        $locsRepo   = new FlxCoreHC\Repository\LocationRepository($ctx);
        $activeGroup = $fGroup !== '' ? $groupsRepo->find($fGroup) : null;
        $activeLoc   = $fLoc !== '' ? $locsRepo->find($fLoc) : null;
        if ($activeGroup) {
            $ids = array_flip(FlxCoreHC\GroupService::holderIdsIn($ctx, $fGroup));
            $rows = array_values(array_filter($rows, fn($r) => isset($ids[(string)$r['id']])));
        }
        if ($activeLoc) {
            $ids = array_flip(FlxCoreHC\LocationService::holderIdsAt($ctx, $fLoc));
            $rows = array_values(array_filter($rows, fn($r) => isset($ids[(string)$r['id']])));
        }
        view('holders/index', ['ctx' => $ctx, 'rows' => $rows,
            'allGroups' => $groupsRepo->active(), 'allLocations' => $locsRepo->active(),
            'groupLabelPlural' => FlxCoreHC\GroupService::label($ctx->orgShape, true),
            'activeGroup' => $activeGroup, 'activeLocation' => $activeLoc]);
    }
    if ($method === 'GET' && $id === 'new') {
        view('holders/form', ['ctx' => $ctx, 'row' => null, 'type' => (($_GET['type'] ?? '') === 'organization' ? 'organization' : 'individual'), 'licenses' => FlxCoreHC\CatalogService::all(null, $__tlines, $__tstates, $ctx->tenantId), 'customFieldsHtml' => (new FlxCoreHC\CustomFieldService($ctx))->render('provider', '')]);
    }
    // ---- Bulk CSV import ---------------------------------------------------------
    if ($id === 'import') {
        FlxCoreHC\Authz::requireCap($ctx, 'records.write');
        // CSV template download
        if ($method === 'GET' && ($seg[2] ?? '') === 'template') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="provider-import-template.csv"');
            header('X-Content-Type-Options: nosniff');
            echo FlxCoreHC\ProviderImportService::TEMPLATE_HEADER . "\n";
            echo "individual,physician,Jane,Smith,,1234567893,jane@example.com,Grupo Medico A,San Juan Clinic,Attending\n";
            echo "individual,np,Carlos,Rivera,,1679576722,carlos@example.com,Grupo Medico A,Ponce Office,Nurse practitioner\n";
            echo "individual,therapist,Ana,Lopez,,1245319599,ana@example.com,Grupo Medico B,,Physical therapist\n";
            echo "organization,lab,,,Bayview Laboratory LLC,1003000126,,,,\n";
            exit;
        }
        if ($method === 'GET') {
            view('holders/import', ['ctx' => $ctx, 'preview' => null, 'csv' => '']);
        }
        if ($method === 'POST') {
            CsrfGuard::verify();
            // gather CSV from an uploaded file or a pasted textarea
            $csv = '';
            if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
                if (($_FILES['file']['size'] ?? 0) > 2_000_000) { flash('That file is larger than 2 MB.', 'error'); redirect('/holders/import'); }
                $csv = (string) file_get_contents($_FILES['file']['tmp_name']);
            } else {
                $csv = (string) ($_POST['csv'] ?? '');
            }
            if (trim($csv) === '') { flash('Upload a CSV file or paste rows to import.', 'error'); redirect('/holders/import'); }

            $svc = new FlxCoreHC\ProviderImportService($ctx);
            if (($_POST['step'] ?? '') === 'commit') {
                $r = $svc->commit($csv);
                if (!empty($r['error'])) { flash($r['error'], 'error'); redirect('/holders/import'); }
                audit_log($ctx, 'provider_import', 'holders', null, json_encode($r));
                $msg = $r['created'] . ' provider(s) imported';
                if ($r['skipped']) $msg .= ', ' . $r['skipped'] . ' skipped';
                if ($r['errors'])  $msg .= ', ' . $r['errors'] . ' with errors';
                if (empty($r['capacity'])) $msg .= ' — stopped at your plan limit (upgrade to add more)';
                flash($msg . '.', ($r['errors'] || ($r['skipped'] && !$r['created'])) ? (($r['created']) ? 'warn' : 'error') : 'ok');
                redirect('/holders');
            }
            // default: preview
            $preview = $svc->preview($csv);
            view('holders/import', ['ctx' => $ctx, 'preview' => $preview, 'csv' => $csv]);
        }
    }
    // ---- Guided onboarding (step-by-step alternative to the quick form) ----------
    // v1.172.0: the wizard is rendered by the server for the chosen record type (a person or an organization), so it does
    // not depend on JavaScript (the old show/hide toggle was an inline handler, which the Content-Security-Policy blocks).
    // Each type has its own fields, type list and steps; the first step also adapts to the kind of organization using
    // the app (solo practice, medical group, hospital, billing/credentialing company).
    if ($id === 'onboard') {
        FlxCoreHC\Authz::requireCap($ctx, 'records.write');   // v1.172.0: creating a record through the wizard needs the same capability as the quick form
        $__obShape = (string) ($__trow['org_shape'] ?? '');
        $__obLists = static function () use ($ctx, $__obShape): array {
            $g = []; $l = [];
            try { if ($__obShape !== 'solo_practice') $g = (new FlxCoreHC\Repository\GroupRepository($ctx))->active(); } catch (\Throwable $e) {}
            try { $l = (new FlxCoreHC\Repository\LocationRepository($ctx))->active(); } catch (\Throwable $e) {}
            return ['groups' => $g, 'locations' => $l];
        };
        if ($method === 'GET') {
            $__obType = (($_GET['type'] ?? '') === 'organization') ? 'organization' : 'individual';
            $__old = $_SESSION['ob_old'] ?? []; unset($_SESSION['ob_old']);
            if (!empty($__old['holder_type'])) $__obType = $__old['holder_type'] === 'organization' ? 'organization' : 'individual';
            if (!$__old && $__obType === 'individual' && !empty($_GET['me'])) {   // solo practice: "the doctor is me"
                $__parts = preg_split('/\s+/', trim((string) $ctx->userName), 2) ?: [];
                $__old = ['first_name' => $__parts[0] ?? '', 'last_name' => $__parts[1] ?? '', 'email' => (string) ($ctx->userEmail ?? '')];
            }
            view('holders/onboard', ['ctx' => $ctx, 'step' => 'who', 'row' => null, 'type' => $__obType, 'orgShape' => $__obShape, 'old' => $__old] + $__obLists());
            exit;
        }
        // POST: create the record from step 1, then continue to identifiers.
        CsrfGuard::verify();
        if (!PlanService::canAddHolder($ctx->tenantId)) {
            flash('You have reached your plan\'s holder limit. Upgrade to add more.', 'error');
            redirect('/holders');
        }
        $type = ($_POST['holder_type'] ?? 'individual') === 'organization' ? 'organization' : 'individual';
        $__back = '/holders/onboard?type=' . $type;
        // Keep what was typed so a refused form comes back filled in (names, type, e-mail, group, location — nothing sensitive).
        $_SESSION['ob_old'] = array_intersect_key($_POST, array_flip(['holder_type', 'first_name', 'last_name', 'org_name', 'email', 'provider_type', 'org_provider_type', 'group_id', 'location_id']));
        $obEmail = trim((string)($_POST['email'] ?? '')) ?: null;
        if ($type === 'individual' && ($obEmail === null || !filter_var($obEmail, FILTER_VALIDATE_EMAIL))) {
            flash('A valid email is required for individuals — it becomes their portal login.', 'error');
            redirect($__back);
        }
        if ($type === 'organization' && $obEmail !== null && !filter_var($obEmail, FILTER_VALIDATE_EMAIL)) {
            flash('Enter a valid e-mail address.', 'error');
            redirect($__back);
        }
        // v1.171.9: same blank rule as every other entry point (invisible characters are not a name); both names required.
        if ($type === 'individual' && (FlxCoreHC\TextNorm::blank((string)($_POST['first_name'] ?? '')) || FlxCoreHC\TextNorm::blank((string)($_POST['last_name'] ?? '')))) {
            flash('First and last name are required.', 'error');
            redirect($__back);
        }
        if ($type === 'organization' && FlxCoreHC\TextNorm::blank((string)($_POST['org_name'] ?? ''))) {
            flash('Give the organization a name to continue.', 'error');
            redirect($__back);
        }
        $obPt = null;
        if ($ctx->vertical === 'healthcare') {
            $obPt = $type === 'organization'
                ? (in_array($_POST['org_provider_type'] ?? '', FlxCoreHC\HolderInput::orgTypes(), true) ? $_POST['org_provider_type'] : 'group')
                : (in_array($_POST['provider_type'] ?? '', FlxCoreHC\HolderInput::individualTypes(), true) ? $_POST['provider_type'] : 'physician');
        }
        // A person record never stores an organization name and the reverse: only the fields of the chosen type are kept.
        $obId = $holders->insert([
            'holder_type'   => $type,
            'provider_type' => $obPt,
            'first_name'    => $type === 'individual' ? (FlxCoreHC\TextNorm::clean((string)($_POST['first_name'] ?? '')) ?: null) : null,
            'last_name'     => $type === 'individual' ? (FlxCoreHC\TextNorm::clean((string)($_POST['last_name'] ?? '')) ?: null) : null,
            'org_name'      => $type === 'organization' ? (FlxCoreHC\TextNorm::clean((string)($_POST['org_name'] ?? '')) ?: null) : null,
            'email'         => $obEmail,
            'vertical'      => $ctx->vertical,
            'status'        => 'active',
        ]);
        unset($_SESSION['ob_old']);
        try { (new FlxCoreHC\TemplateService($ctx))->applyByProviderType((string)$obId, $obPt, $producerReqs); } catch (\Throwable $e) {}
        // Where the record belongs: client group / department / group, and practice location (both optional, both tenant-checked).
        $__gid = (string) ($_POST['group_id'] ?? ''); $__lid = (string) ($_POST['location_id'] ?? '');
        try { if ($__gid !== '' && $__obShape !== 'solo_practice' && (new FlxCoreHC\Repository\GroupRepository($ctx))->find($__gid)) FlxCoreHC\GroupService::add($ctx, (string) $obId, $__gid, null, true); } catch (\Throwable $e) {}
        try { if ($__lid !== '' && (new FlxCoreHC\Repository\LocationRepository($ctx))->find($__lid)) FlxCoreHC\LocationService::affiliate($ctx, (string) $obId, $__lid, null, true); } catch (\Throwable $e) {}
        audit_log($ctx, 'holder_onboard_started', 'holders', (string)$obId, json_encode(['type' => $type]));
        flash($type === 'organization' ? 'Organization record created — the requirements for this kind of organization were added automatically.'
                                        : 'Record created — required credentials for this profession were added automatically.', 'ok');
        redirect('/holders/' . $obId . '/onboard/identifiers');
    }

    if ($method === 'POST' && $id === null) {
        CsrfGuard::verify();
        FlxCoreHC\Authz::requireCap($ctx, 'records.write');   // v1.170.8
        if (!PlanService::canAddHolder($ctx->tenantId)) {
            flash('You have reached your plan\'s holder limit. Upgrade to add more.', 'error');
            redirect('/holders');
        }
        $type = ($_POST['holder_type'] ?? 'individual') === 'organization' ? 'organization' : 'individual';
        $hEmail = trim((string)($_POST['email'] ?? '')) ?: null;
        // Every individual producer must be an app user, which requires an email.
        if ($type === 'individual' && ($hEmail === null || !filter_var($hEmail, FILTER_VALIDATE_EMAIL))) {
            flash('A valid email is required — every provider gets a portal login.', 'error');
            redirect('/holders/new');
        }
        // v1.171.0 (B-1): one shared parser (HolderInput) — same type list and fields as edit / onboarding.
        [$__hf, $__hErr] = FlxCoreHC\HolderInput::fromPost($ctx, $_POST, $type, true);
        if ($__hErr) { flash($__hErr, 'error'); redirect('/holders/new'); }
        $newHolderId = $holders->insert($__hf + [
            'holder_type' => $type,
            'vertical'    => $ctx->vertical,
            'status'      => 'active',
        ]);
        try { (new FlxCoreHC\CustomFieldService($ctx))->save('provider', $newHolderId, $_POST); } catch (\Throwable $e) {}
        try { $__pt = $holders->find((string)$newHolderId)['provider_type'] ?? null; if ($__pt) (new FlxCoreHC\TemplateService($ctx))->applyByProviderType((string)$newHolderId, $__pt, $producerReqs); } catch (\Throwable $e) {}
        audit_log($ctx, 'provider_created', 'holders', is_string($newHolderId) ? $newHolderId : null, json_encode(['type' => $type]));
        // Provision the producer's portal login and send the invite.
        $note = ($type === 'organization' ? 'Organization' : 'Provider') . ' added.';
        if ($type === 'individual' && is_string($newHolderId) && $newHolderId !== '' && !TeamService::canManage($ctx)) {
            $note = 'Provider added. A user manager can send the portal invite from the provider page.';
        } elseif ($type === 'individual' && is_string($newHolderId) && $newHolderId !== '') {
            $team = new TeamService($ctx);
            $pname = trim((string)($_POST['first_name'] ?? '') . ' ' . (string)($_POST['last_name'] ?? ''));
            [$ok, $m, $token] = $team->ensureProducerUser($newHolderId, $pname, (string) $hEmail);
            if ($ok && $token) {
                $link = invite_link($token);
                send_invite_email((string) $hEmail, $pname ?: (string) $hEmail, $link, $ctx->tenantName);
                $note = 'Provider added and portal invite sent to ' . $hEmail . '.';
            } elseif ($ok) {
                $note = 'Provider added. ' . $m;
            }
        }
        flash($note);
        redirect('/holders');
    }
    if ($id !== null && ($row = $holders->find($id))) {
        if ($method === 'GET' && ($seg[2] ?? '') === 'edit') {
            view('holders/form', ['ctx' => $ctx, 'row' => $row, 'licenses' => FlxCoreHC\CatalogService::all(null, $__tlines, $__tstates, $ctx->tenantId), 'customFieldsHtml' => (new FlxCoreHC\CustomFieldService($ctx))->render('provider', (string)$row['id'])]);
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'consent') {
            CsrfGuard::verify();
            FlxCoreHC\Authz::requireCap($ctx, 'share.manage');
            $to = trim((string)($_POST['granted_to'] ?? ''));
            if ($to === '') { flash('Say who the clearance is granted to.', 'error'); redirect('/holders/' . $id . '#share'); }
            $exp = trim((string)($_POST['expires_at'] ?? ''));
            $exp = preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) ? $exp . ' 23:59:59' : null;
            $cid = (new FlxCoreHC\ConsentService($ctx))->record($id, $to,
                (string)($_POST['scope'] ?? 'summary'), (string)($_POST['method'] ?? 'written'),
                $exp, trim((string)($_POST['note'] ?? '')) ?: null);
            audit_log($ctx, 'consent_record', 'holders', $id, json_encode(['consent_id' => $cid, 'granted_to' => $to, 'scope' => $_POST['scope'] ?? 'summary']));
            flash('Provider clearance recorded.', 'ok');
            redirect('/holders/' . $id . '#share');
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'consent-revoke') {
            CsrfGuard::verify();
            FlxCoreHC\Authz::requireCap($ctx, 'share.manage');
            $cid = (string)($_POST['consent_id'] ?? '');
            if ((new FlxCoreHC\ConsentService($ctx))->revoke($cid)) {
                audit_log($ctx, 'consent_revoke', 'holders', $id, json_encode(['consent_id' => $cid]));
                flash('Clearance revoked. Existing links tied to it should be revoked too if no longer authorized.', 'ok');
            }
            redirect('/holders/' . $id . '#share');
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'share') {
            CsrfGuard::verify();
            FlxCoreHC\Authz::requireCap($ctx, 'share.manage');
            [$ok, $msg, $token] = $shareLinks->create($id, $_POST);
            if ($ok) audit_log($ctx, 'share_link_created', 'holders', $id, null);
            flash($msg, $ok ? 'ok' : 'error');
            redirect('/holders/' . $id . '#share');
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'share-revoke') {
            CsrfGuard::verify();
            FlxCoreHC\Authz::requireCap($ctx, 'share.manage');
            $__rv = $shareLinks->revoke((string)($_POST['link_id'] ?? ''));
            if ($__rv) audit_log($ctx, 'share_link_revoked', 'holders', $id, json_encode(['link_id' => (string)($_POST['link_id'] ?? '')]));   // v1.170.9
            flash($__rv ? 'Share link revoked.' : 'Link not found.', $__rv ? 'ok' : 'error');
            redirect('/holders/' . $id . '#share');
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'apply-template') {
            CsrfGuard::verify();
            if ($__isPortalRole) { http_response_code(403); exit('Forbidden.'); }
            FlxCoreHC\Authz::requireCap($ctx, 'records.write');   // v1.170.8
            $tid  = trim((string)($_POST['template_id'] ?? ''));
            $mode = ($_POST['mode'] ?? 'replace') === 'merge' ? 'merge' : 'replace';
            if ($tid === '') {
                FlxCoreHC\Database::pdo()->prepare("UPDATE credential_holders SET template_id = NULL WHERE id = ? AND tenant_id = ?")
                    ->execute([$id, $ctx->tenantId]);
                flash('Template detached — this provider now has a custom requirement set.', 'ok');
            } else {
                [$aok, $amsg] = $templates->applyTo($id, $tid, $producerReqs, $mode);
                flash($amsg, $aok ? 'ok' : 'error');
            }
            redirect('/holders/' . $id);
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'ce') {
            FlxCoreHC\Auth\CsrfGuard::verify();
            if ($__isPortalRole && $ctx->holderId !== $id) { http_response_code(403); exit('Forbidden.'); }
            $hours = (float)($_POST['hours'] ?? 0);
            $cname = trim((string)($_POST['course_name'] ?? ''));
            $cdate = (string)($_POST['completed_date'] ?? '');
            $cat = in_array($_POST['category'] ?? '', ['cme_cat1','cme_cat2','ce','ethics','controlled_substances','patient_safety','general','other'], true) ? $_POST['category'] : 'general';
            if ($cname !== '' && $hours > 0 && $hours <= 100 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $cdate)) {
                $ceEntries->insert(['holder_id' => $id, 'course_name' => $cname,
                    'provider' => trim((string)($_POST['provider'] ?? '')) ?: null,
                    'hours' => $hours, 'category' => $cat, 'completed_date' => $cdate]);
                audit_log($ctx, 'ce_hours_recorded', 'holders', $id, json_encode(['course' => $cname]));
                flash('CME/CE hours recorded.', 'ok');
            } else { flash('Enter a course name, hours (0-100), and completion date.', 'error'); }
            redirect($__isPortalRole ? '/me' : '/holders/' . $id);
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'ce-delete' && isset($seg[3])) {
            FlxCoreHC\Auth\CsrfGuard::verify();
            if ($__isPortalRole && $ctx->holderId !== $id) { http_response_code(403); exit('Forbidden.'); }
            $e = $ceEntries->find($seg[3]);
            if ($e && $e['holder_id'] === $id) { $ceEntries->delete($seg[3]); flash('CE entry removed.', 'ok'); }
            if ($e && $e['holder_id'] === $id) audit_log($ctx, 'ce_entry_deleted', 'holders', $id, null);
            redirect($__isPortalRole ? '/me' : '/holders/' . $id);
        }
        if (($seg[2] ?? '') === 'onboard' && isset($seg[3])) {
            $obStep = (string) $seg[3];
            FlxCoreHC\Authz::requireCap($ctx, 'records.write');   // v1.172.0: every wizard step changes the record
            $__obShape = (string) ($__trow['org_shape'] ?? '');
            // Step 1 again, for a record that already exists: correct the name / type chosen at the start.
            if ($obStep === 'who') {
                $__ht = (string) ($row['holder_type'] ?? 'individual');
                if ($method === 'POST') {
                    CsrfGuard::verify();
                    $__in = array_intersect_key($_POST, array_flip($__ht === 'organization' ? ['org_name', 'email'] : ['first_name', 'last_name', 'email']));
                    $__in['provider_type'] = (string) ($__ht === 'organization' ? ($_POST['org_provider_type'] ?? '') : ($_POST['provider_type'] ?? ''));
                    if ($__ht === 'individual' && array_key_exists('email', $__in) && trim((string) $__in['email']) === '') { flash('A valid email is required for individuals — it becomes their portal login.', 'error'); redirect('/holders/' . $id . '/onboard/who'); }
                    [$upd, $__hErr] = FlxCoreHC\HolderInput::fromPost($ctx, $__in, $__ht, false);
                    if ($__hErr) { flash($__hErr, 'error'); redirect('/holders/' . $id . '/onboard/who'); }
                    $holders->update($id, $upd);
                    audit_log($ctx, 'provider_updated', 'holders', $id, json_encode(array_keys($upd)));
                    redirect('/holders/' . $id . '/onboard/identifiers');
                }
                view('holders/onboard', ['ctx' => $ctx, 'step' => 'who', 'row' => $row, 'type' => $__ht, 'orgShape' => $__obShape, 'old' => [],
                    'groups' => [], 'locations' => []]);
                exit;
            }
            // Step 2 — identifiers. Same validation rules as the self-service profile.
            if ($obStep === 'identifiers') {
                if ($method === 'POST') {
                    CsrfGuard::verify();
                    FlxCoreHC\Authz::requireCap($ctx, 'records.write');   // v1.170.8
                    // v1.171.0 (B-1): shared parser (identifiers + application data + provider type).
                    [$upd, $__hErr] = FlxCoreHC\HolderInput::fromPost($ctx, $_POST, (string)($row['holder_type'] ?? 'individual'), false);
                    if ($__hErr) { flash($__hErr, 'error'); redirect('/holders/' . $id . '/onboard/identifiers'); }
                    $holders->update($id, $upd);
                    // If a profession is set but no requirements exist yet, seed them from its template.
                    try { $__pt = $holders->find($id)['provider_type'] ?? null; if ($__pt) (new FlxCoreHC\TemplateService($ctx))->applyByProviderType($id, $__pt, $producerReqs, true); } catch (\Throwable $e) {}
                    [$cfOk, $cfMsg] = (new FlxCoreHC\CustomFieldService($ctx))->save('provider', $id, $_POST); if (!$cfOk) flash($cfMsg, 'error');
        audit_log($ctx, 'provider_updated', 'holders', $id, json_encode(array_keys($upd)));
                    redirect('/holders/' . $id . '/onboard/requirements');
                }
                view('holders/onboard', ['ctx' => $ctx, 'step' => 'identifiers', 'row' => $row, 'type' => (string) ($row['holder_type'] ?? 'individual'), 'orgShape' => $__obShape,
                    'licenses' => FlxCoreHC\CatalogService::all(null, $__tlines, $__tstates, $ctx->tenantId)]);
                exit;
            }
            // Step 3 — requirements via template (suggested best-first), or none.
            if ($obStep === 'requirements') {
                if ($method === 'POST') {
                    CsrfGuard::verify();
                    $tplId = trim((string)($_POST['template_id'] ?? ''));
                    if ($tplId !== '') {
                        [$okT, $msgT] = $templates->applyTo($id, $tplId, $producerReqs);
                        flash($okT ? $msgT : 'Template not applied: ' . $msgT, $okT ? 'ok' : 'error');
                    }
                    redirect('/holders/' . $id . '/onboard/portal');
                }
                $__obRole = FlxCoreHC\CatalogService::holderRole($row, (string)($__trow['org_shape'] ?? 'agency'));
                view('holders/onboard', ['ctx' => $ctx, 'step' => 'requirements', 'row' => $row, 'type' => (string) ($row['holder_type'] ?? 'individual'), 'orgShape' => $__obShape,
                    'tplList' => $templates->suggestFor($row, $__obRole, $__tlines, $__tstates)]);
                exit;
            }
            // Step 4 — portal access: explicit choice, not automatic.
            if ($obStep === 'portal') {
                $__hasPortal = false;
                $__pq = FlxCoreHC\Database::pdo()->prepare('SELECT id FROM users WHERE tenant_id = ? AND holder_id = ? LIMIT 1');
                $__pq->execute([$ctx->tenantId, $id]);
                $__hasPortal = (bool) $__pq->fetchColumn();
                if ($method === 'POST') {
                    CsrfGuard::verify();
                    if ($row['holder_type'] === 'individual' && !$__hasPortal && !empty($row['email']) && TeamService::canManage($ctx)) {
                        $team = new TeamService($ctx);
                        $pname = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                        [$okP, $msgP, $tokenP] = $team->ensureProducerUser($id, $pname, (string) $row['email']);
                        if ($okP && $tokenP) {
                            send_invite_email((string) $row['email'], $pname ?: (string) $row['email'], invite_link($tokenP), $ctx->tenantName);
                            flash(t('Portal invite sent to {email} — onboarding complete.', ['email' => $row['email']]));
                        } else {
                            flash($okP ? 'Onboarding complete. ' . $msgP : $msgP, $okP ? 'ok' : 'error');
                        }
                    }
                    audit_log($ctx, 'holder_onboard_finished', 'holders', $id, null);
                    redirect('/holders/' . $id);
                }
                $__sumGroups = []; $__sumLocs = [];
                try { $__sumGroups = FlxCoreHC\GroupService::forHolder($ctx, $id); } catch (\Throwable $e) {}
                try { $__sumLocs = FlxCoreHC\LocationService::forHolder($ctx, $id); } catch (\Throwable $e) {}
                view('holders/onboard', ['ctx' => $ctx, 'step' => 'portal', 'row' => $row, 'hasPortal' => $__hasPortal, 'type' => (string) ($row['holder_type'] ?? 'individual'), 'orgShape' => $__obShape,
                    'sumGroups' => $__sumGroups, 'sumLocations' => $__sumLocs]);
                exit;
            }
        }
        if ($method === 'GET' && ($seg[2] ?? '') === 'cv') {
            // CV / Credential Summary — one-click professional PDF from the data on file.
            // find() above is tenant-scoped and producers were already narrowed to their own record.
            stream_holder_cv($row, $ctx, $credentials, $documents);
        }
        if ($method === 'GET' && ($seg[2] ?? '') === 'package' && ($_GET['pick'] ?? '') === '1') {
            // Curated packet: choose exactly which credentials/documents to include, like a payer packet.
            $picks = [];
            foreach ($credentials->all(['holder_id' => $id], '(expiry_date IS NULL), expiry_date ASC') as $c) {
                $st = in_array((string)$c['status'], ['active','expiring','expired'], true) ? (string)$c['status'] : 'active';
                $picks[] = ['id' => (string)$c['id'], 'name' => (string)($c['name'] ?: $c['credential_type']),
                    'type' => (string)$c['credential_type'], 'status' => $st,
                    'expiry' => $c['expiry_date'] ? fmt_day((string)$c['expiry_date']) : '—',
                    'docs' => count($documents->all(['credential_id' => $c['id']]))];
            }
            view('holders/package_pick', ['ctx' => $ctx, 'row' => $row, 'picks' => $picks,
                'templates' => (new FlxCoreHC\TemplateService($ctx))->all('tenant_owner')]);
            exit;
        }
        if ($method === 'GET' && ($seg[2] ?? '') === 'package') {
            $data = flx_compliance_data($holders, $credentials, $producerReqs, $regulatorReqs, $regulators,
                (string)($__trow['org_shape'] ?? 'agency'), $__tlines, $documents, $__tstates, $holderRegs);
            $me = null;
            foreach ($data['producers'] as $p) { if ($p['holder']['id'] === $id) { $me = $p; break; } }
            $regulatorId = trim((string)($_GET['regulator'] ?? ''));
            $regulatorRow = $regulatorId !== '' ? $regulators->find($regulatorId) : null;
            $rows = $me['rows'] ?? [];
            if ($regulatorRow) {
                $rows = array_values(array_filter($rows,
                    fn($r) => $r['regulator'] === null || $r['regulator'] === $regulatorRow['name']));
            }
            // score scoped to the rows in this package
            $pts = 0.0;
            foreach ($rows as $r) { $pts += $r['status'] === 'active' ? 1 : ($r['status'] === 'expiring' ? 0.5 : 0); }
            $score = $rows !== [] ? (int) round($pts * 100 / count($rows)) : null;
            $catByCode = [];
            foreach (FlxCoreHC\CatalogService::all(null, null, null, $ctx->tenantId) as $cat) { $catByCode[$cat['code']] = $cat; }
            // Include retired custom types for LABELLING existing records (they're gone from the
            // picker, but a stored credential of that type must still show its name, not its code).
            $__labels = FlxCoreHC\CatalogService::labelsFor($ctx->tenantId);
            $credsOut = [];
            $pickIds = array_filter(array_map('trim', explode(',', (string)($_GET['ids'] ?? ''))));
            foreach ($credentials->all(['holder_id' => $id], '(expiry_date IS NULL), expiry_date ASC') as $c) {
                if ($pickIds && !in_array((string)$c['id'], $pickIds, true)) continue;
                if ($regulatorRow && !empty($c['regulator_id']) && $c['regulator_id'] !== $regulatorRow['id']) continue;
                // Evidence index shows the verification state so a payer sees the recorded verification at a glance.
                $docs = array_map(fn($d) => (string)$d['original_name']
                        . (!empty($d['verified_at']) ? ' [verified ' . fmt_date((string)$d['verified_at'])
                            . ', ' . str_replace('_', ' ', (string)$d['verification_method']) . ']' : ' [unverified]'),
                    $documents->all(['credential_id' => $c['id']], 'created_at ASC'));
                $code = FlxCoreHC\CatalogService::codeFor((string)$c['credential_type']);
                $credsOut[] = [
                    'name'   => (string)$c['name'],
                    'type'   => $catByCode[$code]['name'] ?? ($__labels[$code] ?? (string)$c['credential_type']),
                    'number' => (string)($c['credential_number'] ?? ''),
                    'issued' => $c['issued_date'] ? fmt_day((string)$c['issued_date']) : '',
                    'expiry' => $c['expiry_date'] ? fmt_day((string)$c['expiry_date']) : '',
                    'status' => in_array((string)$c['status'], ['active','expiring','expired'], true) ? (string)$c['status'] : 'active',
                    'docs'   => $docs,
                ];
            }
            $holderName = $row['holder_type'] === 'individual'
                ? trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))
                : (string)($row['org_name'] ?? 'Provider');
            $pdfBytes = FlxCoreHC\CredentialPackageService::build([
                'org' => (string)($__trow['brand_name'] ?: $__trow['name']),
                'holderName' => $holderName,
                // Healthcare: show the professional type and NPI (the identifier payers actually use).
                'role' => FlxCoreHC\Vocab::appliesToOptions('healthcare')[$row['provider_type'] ?? ''] ?? ucfirst((string)($row['provider_type'] ?? 'Provider')),
                'npn' => (string)($row['npi'] ?? ''), 'email' => (string)($row['email'] ?? ''),
                'regulator' => $regulatorRow['name'] ?? null,
                'score' => $score, 'rows' => $rows, 'creds' => $credsOut,
                'verifications' => $verifications->forHolder($id),
                'attestation' => $attestations->latestFor($id),
                'ruleset' => 'Ruleset ' . FlxCoreHC\CatalogService::RULESET_VERSION,
                'generatedBy' => $ctx->userName ?? '',
            ]);
            try {
                FlxCoreHC\Database::pdo()->prepare(
                    "INSERT INTO audit_logs (id, tenant_id, user_id, action, entity_type, entity_id, ip_address, created_at)
                     VALUES (?,?,?,?,?,?,?,?)"
                )->execute([uuid4(), $ctx->tenantId, $ctx->userId,
                    'package_generated' . ($regulatorRow ? ':' . $regulatorRow['name'] : ''),
                    'holders', $id, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), utc_now()]);
            } catch (\Throwable $e) { /* best-effort */ }
            $fn = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $holderName . ($regulatorRow ? '-' . $regulatorRow['name'] : '')));
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="credential-package-' . trim($fn, '-') . '.pdf"');
            header('Content-Length: ' . strlen($pdfBytes));
            echo $pdfBytes;
            exit;
        }
        // ---- Education & work history (application data) ---------------------------------------
        if ($method === 'POST' && in_array($seg[2] ?? '', ['education','work'], true)) {
            FlxCoreHC\Authz::requireCap($ctx, 'records.write');
            CsrfGuard::verify();
            $isEdu = $seg[2] === 'education';
            $R = $isEdu ? new FlxCoreHC\Repository\ProviderEducationRepository($ctx) : new FlxCoreHC\Repository\ProviderWorkHistoryRepository($ctx);
            $rid = (string)($seg[3] ?? ''); $act = (string)($seg[4] ?? '');
            // v1.170.8 (security): an entry id in the URL must belong to THIS provider (not just this tenant).
            if ($rid !== '' && (($__own = $R->find($rid)) === null || (string)$__own['holder_id'] !== (string)$id)) { http_response_code(404); exit('Not found.'); }
            $d = fn(string $k) => (isset($_POST[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_POST[$k])) ? $_POST[$k] : null;
            if ($rid !== '' && $act === 'delete') { $R->delete($rid); flash('Entry removed.', 'ok'); }
            else {
                if ($isEdu) {
                    $inst = trim((string)($_POST['institution'] ?? '')); if ($inst === '') { flash('Enter the institution.', 'error'); redirect('/holders/' . $id . '#history'); }
                    $data = ['holder_id' => $id, 'kind' => in_array($_POST['kind'] ?? '', ['undergraduate','professional','internship','residency','fellowship','other'], true) ? $_POST['kind'] : 'other',
                        'institution' => mb_substr($inst, 0, 200), 'program' => mb_substr(trim((string)($_POST['program'] ?? '')), 0, 160) ?: null,
                        'city' => mb_substr(trim((string)($_POST['city'] ?? '')), 0, 80) ?: null, 'state' => mb_substr(strtoupper(trim((string)($_POST['state'] ?? ''))), 0, 8) ?: null,
                        'country' => preg_match('/^[A-Za-z]{2}$/', (string)($_POST['country'] ?? '')) ? strtoupper($_POST['country']) : null,
                        'start_date' => $d('start_date'), 'end_date' => $d('end_date'), 'completed' => !empty($_POST['completed']) ? 1 : 0, 'notes' => mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 300) ?: null];
                } else {
                    $emp = trim((string)($_POST['employer'] ?? '')); if ($emp === '') { flash('Enter the employer.', 'error'); redirect('/holders/' . $id . '#history'); }
                    $data = ['holder_id' => $id, 'employer' => mb_substr($emp, 0, 200), 'position' => mb_substr(trim((string)($_POST['position'] ?? '')), 0, 160) ?: null,
                        'city' => mb_substr(trim((string)($_POST['city'] ?? '')), 0, 80) ?: null, 'state' => mb_substr(strtoupper(trim((string)($_POST['state'] ?? ''))), 0, 8) ?: null,
                        'start_date' => $d('start_date'), 'end_date' => $d('end_date'), 'reason_left' => mb_substr(trim((string)($_POST['reason_left'] ?? '')), 0, 300) ?: null,
                        'gap_explanation' => mb_substr(trim((string)($_POST['gap_explanation'] ?? '')), 0, 300) ?: null];
                }
                if ($rid !== '') { $R->update($rid, $data); flash('Entry updated.', 'ok'); } else { $R->insert($data); flash('Entry added.', 'ok'); }
                audit_log($ctx, ($isEdu ? 'education' : 'work_history') . ($rid ? '_updated' : '_added'), 'holders', $id, null);
            }
            redirect('/holders/' . $id . '#history');
        }
        // ---- Addresses (provider) & office contacts -----------------------------------------------
        if ($method === 'POST' && ($seg[2] ?? '') === 'addresses') {
            FlxCoreHC\Authz::requireCap($ctx, 'records.write');
            CsrfGuard::verify();
            $A = new FlxCoreHC\Repository\AddressRepository($ctx);
            $aid = (string)($seg[3] ?? ''); $act = (string)($seg[4] ?? '');
            // v1.170.8 (security): the address must belong to THIS provider.
            if ($aid !== '' && (($__own = $A->find($aid)) === null || $__own['owner_type'] !== 'provider' || (string)$__own['owner_id'] !== (string)$id)) { http_response_code(404); exit('Not found.'); }
            if ($aid !== '' && $act === 'delete')      { $A->delete($aid); audit_log($ctx, 'address_removed', 'holders', $id, json_encode(['address_id' => $aid])); flash('Address removed.', 'ok'); }
            elseif ($aid !== '' && $act === 'primary') { $A->setPrimary($aid); flash('Primary address set.', 'ok'); }
            else {
                [$ok, $msg] = flx_save_address($A, 'provider', $id, $_POST, $aid ?: null);
                if ($ok) audit_log($ctx, $aid ? 'address_updated' : 'address_added', 'holders', $id, json_encode(['kind' => $_POST['kind'] ?? null]));
                flash($msg, $ok ? 'ok' : 'error');
            }
            redirect('/holders/' . $id . '#addresses');
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'contacts') {
            FlxCoreHC\Authz::requireCap($ctx, 'records.write');
            CsrfGuard::verify();
            $C = new FlxCoreHC\Repository\ProviderContactRepository($ctx);
            $cid = (string)($seg[3] ?? ''); $act = (string)($seg[4] ?? '');
            // v1.170.8 (security): the contact must belong to THIS provider.
            if ($cid !== '' && (($__own = $C->find($cid)) === null || (string)$__own['holder_id'] !== (string)$id)) { http_response_code(404); exit('Not found.'); }
            if ($cid !== '' && $act === 'delete') { $C->delete($cid); audit_log($ctx, 'contact_removed', 'holders', $id, json_encode(['contact_id' => $cid])); flash('Contact removed.', 'ok'); }
            else {
                $name = trim((string)($_POST['full_name'] ?? ''));
                if ($name === '') { flash('Enter the contact\'s name.', 'error'); redirect('/holders/' . $id . '#contacts'); }
                $email = trim((string)($_POST['email'] ?? ''));
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('Enter a valid email for the contact.', 'error'); redirect('/holders/' . $id . '#contacts'); }
                $data = ['holder_id' => $id, 'full_name' => mb_substr($name, 0, 160), 'role_title' => mb_substr(trim((string)($_POST['role_title'] ?? '')), 0, 80) ?: null,
                    'email' => $email ?: null, 'phone' => mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 40) ?: null,
                    'preferred_language' => in_array($_POST['preferred_language'] ?? '', ['en','es'], true) ? $_POST['preferred_language'] : null,
                    'gets_document_requests' => !empty($_POST['gets_document_requests']) ? 1 : 0, 'gets_reminders' => !empty($_POST['gets_reminders']) ? 1 : 0,
                    'is_primary' => !empty($_POST['is_primary']) ? 1 : 0, 'notes' => mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 300) ?: null];
                if ($cid !== '') { $C->update($cid, $data); audit_log($ctx, 'contact_updated', 'holders', $id, json_encode(['contact_id' => $cid])); flash('Contact updated.', 'ok'); }
                else { $C->insert($data); audit_log($ctx, 'contact_added', 'holders', $id, json_encode(['role' => $data['role_title']])); flash('Contact added.', 'ok'); }
            }
            redirect('/holders/' . $id . '#contacts');
        }
        // ---- Primary-source verification (Tier A automated / Tier B assisted) -------------------
        if ($method === 'POST' && ($seg[2] ?? '') === 'verify-source') {
            FlxCoreHC\Authz::requireCap($ctx, 'documents.verify');
            CsrfGuard::verify();
            $svc = new FlxCoreHC\EnrollmentService($ctx); // not used; keep row scope simple
            $vsvc = new FlxCoreHC\VerificationService($ctx);
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'nppes') { $vsvc->verifyNppes($holders->find($id) ?: [], (string)($_POST['reverify_on'] ?? '') ?: null); flash('NPPES verification recorded.', 'ok'); }
            elseif ($action === 'sam') { [$ok, $msg] = $vsvc->verifySam($holders->find($id) ?: [], (string)($_POST['reverify_on'] ?? '') ?: null); flash($msg, $ok ? 'ok' : 'error'); }
            else { [$ok, $msg] = $vsvc->recordManual($id, $_POST); flash($msg, $ok ? 'ok' : 'error'); }
            redirect('/holders/' . $id . '#verify');
        }
        // ---- Payer credentialing / program enrollment / OCS profile ----------------------------
        if ($method === 'POST' && ($seg[2] ?? '') === 'payer') {
            FlxCoreHC\Authz::requireCap($ctx, 'records.write');
            CsrfGuard::verify();
            $svc = new FlxCoreHC\EnrollmentService($ctx);
            if (($seg[3] ?? '') !== '' && ($seg[4] ?? '') === 'delete') { $svc->deletePayerRow($id, (string)$seg[3]); flash('Payer entry removed.', 'ok'); }
            else { [$ok, $msg] = $svc->savePayer($id, $_POST); flash($msg, $ok ? 'ok' : 'error'); }
            redirect('/holders/' . $id . '#enrollment');
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'program') {
            FlxCoreHC\Authz::requireCap($ctx, 'records.write');
            CsrfGuard::verify();
            [$ok, $msg] = (new FlxCoreHC\EnrollmentService($ctx))->saveProgram($id, $_POST);
            flash($msg, $ok ? 'ok' : 'error'); redirect('/holders/' . $id . '#enrollment');
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'ocs') {
            FlxCoreHC\Authz::requireCap($ctx, 'records.write');
            CsrfGuard::verify();
            [$ok, $msg] = (new FlxCoreHC\EnrollmentService($ctx))->saveOcs($id, $_POST);
            flash($msg, $ok ? 'ok' : 'error'); redirect('/holders/' . $id . '#enrollment');
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'telehealth') {
            FlxCoreHC\Authz::requireCap($ctx, 'records.write');
            CsrfGuard::verify();
            $holders->update($id, ['telehealth_enabled' => !empty($_POST['enabled']) ? 1 : 0]);
            audit_log($ctx, 'telehealth_flag_set', 'holders', $id, json_encode(['enabled' => !empty($_POST['enabled'])]));
            flash('Telehealth setting saved.', 'ok'); redirect('/holders/' . $id . '#enrollment');
        }
        // ---- Cross-organization linking & sharing (consent model) --------------------------------
        if ($method === 'POST' && ($seg[2] ?? '') === 'link-request') {
            if ($ctx->role !== 'tenant_owner') { http_response_code(403); exit('Master Admin only.'); }
            CsrfGuard::verify();
            [$ok, $msg] = (new FlxCoreHC\CrossTenantService($ctx))->requestLinks($id);
            flash($msg, $ok ? 'ok' : 'error'); redirect('/holders/' . $id . '#crossorg');
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'cross-share') {
            FlxCoreHC\Authz::requireCap($ctx, 'crossorg.manage');
            CsrfGuard::verify();
            $svc = new FlxCoreHC\CrossTenantService($ctx);
            $n = 0; $last = '';
            foreach ((array)($_POST['credential_ids'] ?? []) as $cid) {
                [$ok, $last] = $svc->shareCredential((string)$cid, (string)($_POST['link_id'] ?? ''), !empty($_POST['include_documents']));
                if ($ok) $n++;
            }
            flash($n ? "$n credential(s) shared." : $last, $n ? 'ok' : 'error'); redirect('/holders/' . $id . '#crossorg');
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'cross-shares' && ($seg[3] ?? '') !== '' && ($seg[4] ?? '') === 'revoke') {
            FlxCoreHC\Authz::requireCap($ctx, 'crossorg.manage');
            CsrfGuard::verify();
            [$ok, $msg] = (new FlxCoreHC\CrossTenantService($ctx))->revokeShare((string)$seg[3]);
            flash($msg, $ok ? 'ok' : 'error'); redirect('/holders/' . $id . '#crossorg');
        }
        if ($method === 'GET' && ($seg[2] ?? '') === 'cross-shares' && ($seg[3] ?? '') !== '' && ($seg[4] ?? '') === 'log') {
            FlxCoreHC\Authz::requireCap($ctx, 'share.manage');
            $svc = new FlxCoreHC\CrossTenantService($ctx);
            $row = $holders->find($id);
            view('holders/cross_share_log', ['ctx' => $ctx, 'row' => $row, 'shareId' => (string)$seg[3], 'log' => $svc->accessLog((string)$seg[3])]);
        }
        // Recipient downloads a document shared to it (re-verified + logged in the service).
        if ($method === 'GET' && ($seg[2] ?? '') === 'shared-doc' && ($seg[3] ?? '') !== '' && ($seg[4] ?? '') !== '') {
            FlxCoreHC\Authz::requireCap($ctx, 'documents.view');
            $f = (new FlxCoreHC\CrossTenantService($ctx))->sharedDocument((string)$seg[3], (string)$seg[4], $id);
            if (!$f || !is_file($f['file_path'])) { http_response_code(404); exit('Not available.'); }
            header('Content-Type: ' . ($f['mime_type'] ?: 'application/octet-stream'));
            header('Content-Disposition: inline; filename="' . str_replace('"', '', $f['original_name']) . '"');
            header('X-Content-Type-Options: nosniff');
            if (!FlxCoreHC\EncryptedFile::send($f['file_path'])) { http_response_code(404); }
            exit;
        }
        // ---- Document requests (tenant asks the provider/office for a document) -------------------
        if ($method === 'POST' && ($seg[2] ?? '') === 'request-document') {
            FlxCoreHC\Authz::requireCap($ctx, 'documents.request');
            CsrfGuard::verify();
            $svc = new FlxCoreHC\DocumentRequestService($ctx);
            [$ok, $msg] = $svc->create($id, (string)($_POST['credential_id'] ?? '') ?: null, (string)($_POST['kind'] ?? 'other'),
                                       trim((string)($_POST['message'] ?? '')) ?: null, (string)($_POST['due_at'] ?? '') ?: null);
            audit_log($ctx, 'document_requested', 'holders', $id, json_encode(['kind' => $_POST['kind'] ?? null]));
            flash($msg, $ok ? 'ok' : 'error'); redirect('/holders/' . $id . '#requests');
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'requests' && ($seg[3] ?? '') !== '' && in_array($seg[4] ?? '', ['accept','return','cancel'], true)) {
            FlxCoreHC\Authz::requireCap($ctx, 'documents.manage');
            CsrfGuard::verify();
            $svc = new FlxCoreHC\DocumentRequestService($ctx);
            $rid = (string)$seg[3];
            [$ok, $msg] = match ($seg[4]) {
                'accept' => $svc->accept($rid),
                'return' => $svc->returnRequest($rid, (string)($_POST['note'] ?? '')),
                default  => $svc->cancel($rid),
            };
            audit_log($ctx, 'document_request_' . $seg[4], 'holders', $id, json_encode(['request_id' => $rid]));
            flash($msg, $ok ? 'ok' : 'error'); redirect('/holders/' . $id . '#requests');
        }
        // ---- External sharing (payer / state agency / other, password + expiry) -------------------
        if ($method === 'POST' && ($seg[2] ?? '') === 'share-external') {
            FlxCoreHC\Authz::requireCap($ctx, 'share.manage');
            CsrfGuard::verify();
            $svc = new FlxCoreHC\ExternalShareService($ctx);
            $credIds = array_values(array_filter((array)($_POST['credential_ids'] ?? []), 'is_string'));
            [$ok, $msg, $out] = $svc->create($id, (string)($_POST['recipient_type'] ?? 'other'), (string)($_POST['recipient_name'] ?? ''),
                $credIds, !empty($_POST['include_documents']), (int)($_POST['days'] ?? 30),
                (string)($_POST['password'] ?? ''), (int)($_POST['max_views'] ?? 0) ?: null);
            if ($ok) { $_SESSION['ext_share_reveal'] = $out; }
            flash($msg, $ok ? 'ok' : 'error'); redirect('/holders/' . $id . '#external');
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'external-shares' && ($seg[3] ?? '') !== '' && ($seg[4] ?? '') === 'revoke') {
            FlxCoreHC\Authz::requireCap($ctx, 'share.manage');
            CsrfGuard::verify();
            $svc = new FlxCoreHC\ExternalShareService($ctx);
            flash(($__rvk = $svc->revoke((string)$seg[3])) ? 'Share link revoked.' : 'Link not found.', $__rvk ? 'ok' : 'error');   // ExternalShareService audits create/revoke itself
            redirect('/holders/' . $id . '#external');
        }
        if ($method === 'GET' && ($seg[2] ?? '') === 'external-shares' && ($seg[3] ?? '') !== '' && ($seg[4] ?? '') === 'log') {
            FlxCoreHC\Authz::requireCap($ctx, 'share.manage');
            $svc = new FlxCoreHC\ExternalShareService($ctx);
            $share = $svc->find((string)$seg[3]);
            if (!$share || $share['holder_id'] !== $id) { http_response_code(404); exit('Not found.'); }
            $row = $holders->find($id);
            view('holders/share_log', ['ctx' => $ctx, 'row' => $row, 'share' => $share, 'log' => $svc->accessLog((string)$seg[3])]);
        }
        if ($method === 'GET') {
            $creds = $credentials->all(['holder_id' => $id], '(expiry_date IS NULL), expiry_date ASC');
            $__pRole = FlxCoreHC\CatalogService::holderRole($row, (string)($__trow['org_shape'] ?? 'agency'));
            $data = flx_compliance_data($holders, $credentials, $producerReqs, $regulatorReqs, $regulators,
                (string)($__trow['org_shape'] ?? 'agency'), $__tlines, $documents, $__tstates, $holderRegs);
            $mine = null;
            foreach ($data['producers'] as $pp) { if ($pp['holder']['id'] === $id) { $mine = $pp; break; } }
            $cyc = flx_ce_cycle(null, flx_ce_months($__tstates));   // jurisdiction-aware look-back
            $ce = $ceEntries->all(['holder_id' => $id], 'completed_date DESC');
            $ceHours = 0.0; $ceLaw = 0.0;
            foreach ($ce as $e) {
                $d = (string)$e['completed_date'];
                if ($d >= $cyc['start']->format('Y-m-d') && $d <= $cyc['end']->format('Y-m-d')) {
                    $ceHours += (float)$e['hours'];
                    if ($e['category'] === 'law_ethics') $ceLaw += (float)$e['hours'];
                }
            }
        // ---- Full compliance history export (permanent record, CSV) --------------------------
        if ($method === 'GET' && ($seg[2] ?? '') === 'history.csv') {
            FlxCoreHC\Authz::requireCap($ctx, 'audit.view');
            $row = $holders->find($id);
            if (!$row) { http_response_code(404); exit('Not found.'); }
            $events = FlxCoreHC\ComplianceEventService::forHolder($ctx, $id, 20000);
            audit_log($ctx, 'history_export', 'holders', $id, null);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="history-' . preg_replace('/[^a-z0-9]+/i','-', strtolower(FlxCoreHC\Repository\HolderRepository::displayName($row))) . '.csv"');
            header('X-Content-Type-Options: nosniff');
            $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
            flx_fputcsv($out, ['When (UTC)', 'Event', 'Summary', 'Effective date', 'By', 'Detail']);
            foreach ($events as $e) {
                flx_fputcsv($out, csv_safe_row([$e['created_at'], str_replace('_',' ',(string)$e['event_type']), $e['summary'],
                    $e['effective_on'] ?? '', $e['actor_name'] ?? 'System', (string)($e['detail'] ?? '')]));
            }
            fclose($out); exit;
        }
        // ---- Provider profile packet: a print-ready dossier for payer enrollment / audits ---------
        if ($method === 'GET' && ($seg[2] ?? '') === 'packet') {
            FlxCoreHC\Authz::requireCap($ctx, 'documents.view');
            $docsByCred = [];
            $__credIds = array_flip(array_column($creds, 'id'));
            foreach ($documents->all([], 'created_at ASC', 2000) as $d) {
                if (isset($__credIds[$d['credential_id']])) $docsByCred[$d['credential_id']][] = $d;
            }
            $verifiers = [];
            foreach (FlxCoreHC\TeamService::directory($ctx) as $u) { $verifiers[$u['id']] = $u['full_name']; }
            // Exclusion screening history (OIG LEIE / SAM / state Medicaid) — the "not excluded as of"
            // line payers look for first. Read via the repository already bound to this holder.
            $__screen = [];
            try { $__screen = $screenings->forHolder($id); } catch (\Throwable $e) {}
            audit_log($ctx, 'provider_packet_viewed', 'holders', $id, null);
            view('holders/packet', ['ctx' => $ctx, 'row' => $row, 'creds' => $creds, 'docsByCred' => $docsByCred,
                'verifiers' => $verifiers, 'screenings' => $__screen,
                'catalog' => FlxCoreHC\CatalogService::all(null, $__tlines, $__tstates, $ctx->tenantId),
                'coverageDetail' => FlxCoreHC\CatalogService::coverageDetail($creds),
                'assigned' => $producerReqs->tokensFor($id),
                'holderLocations' => FlxCoreHC\LocationService::forHolder($ctx, $id),
                'holderGroups'    => FlxCoreHC\GroupService::forHolder($ctx, $id),
                'groupLabel'      => FlxCoreHC\GroupService::label($ctx->orgShape),
                'tplCurrent'  => !empty($row['template_id']) ? $templates->find((string)$row['template_id']) : null,
                'org' => (new FlxCoreHC\OrgService($ctx))->get(),
            ]);
        }
            $__tplList = $templates->suggestFor($row, $__pRole, $__tlines, $__tstates);
            $__shareLinks = $shareLinks->forHolder($id);
            $__drift   = !empty($row['template_id']) ? $templates->drift($id, (string)$row['template_id'], $producerReqs) : null;
            view('holders/show', ['ctx' => $ctx, 'row' => $row, 'creds' => $creds, 'tenantStates' => $__tstates,
                'agencyCases' => (function() use ($ctx, $id) { try { return (new FlxCoreHC\Repository\CredentialingCaseRepository($ctx))->forHolder($id, 50); } catch (\Throwable $e) { return []; } })(),
                'catalog'     => FlxCoreHC\CatalogService::all(null, $__tlines, $__tstates, $ctx->tenantId),
                'assigned'    => $producerReqs->tokensFor($id),
                'pRole'       => $__pRole,
                'tplList'     => $__tplList,
                'tplCurrent'  => !empty($row['template_id']) ? $templates->find((string)$row['template_id']) : null,
                'drift'       => $__drift,
                'coverage'    => FlxCoreHC\CatalogService::coverageTokens($creds),
                'coverageDetail' => FlxCoreHC\CatalogService::coverageDetail($creds),
                'regulatorList' => $regulators->all([], 'name ASC'),
                'assignedRegulators' => $holderRegs->forHolder($id),
                'assignees'   => FlxCoreHC\AssignmentService::assigneesOf($ctx, $id),
                'holderLocations' => FlxCoreHC\LocationService::forHolder($ctx, $id),
                'holderGroups'    => FlxCoreHC\GroupService::forHolder($ctx, $id),
                'history'         => FlxCoreHC\Authz::can($ctx, 'audit.view') ? (new FlxCoreHC\Repository\AuditLogRepository($ctx))->forHolder($id, 100) : null,
                'timeline'        => FlxCoreHC\ComplianceEventService::forHolder($ctx, $id, 500),
                'docRequests'     => (new FlxCoreHC\DocumentRequestService($ctx))->forHolder($id),
                'extShares'       => FlxCoreHC\Authz::can($ctx, 'share.manage') ? (new FlxCoreHC\ExternalShareService($ctx))->forHolder($id) : [],
                'extReveal'       => (function() { $r = $_SESSION['ext_share_reveal'] ?? null; unset($_SESSION['ext_share_reveal']); return $r; })(),
                'holderCreds'     => $credentials->all(['holder_id' => $id], 'expiry_date ASC'),
                'enroll'          => (function() use ($ctx, $id) { $e = new FlxCoreHC\EnrollmentService($ctx); return ['payers' => $e->payers(), 'rows' => $e->payerRows($id), 'programs' => $e->programs($id), 'ocs' => $e->ocs($id)]; })(),
                'verifs'          => (new FlxCoreHC\VerificationService($ctx))->forHolder($id),
                'addresses'       => (new FlxCoreHC\Repository\AddressRepository($ctx))->forOwner('provider', $id),
                'education'       => (new FlxCoreHC\Repository\ProviderEducationRepository($ctx))->forHolder($id),
                'workHistory'     => (new FlxCoreHC\Repository\ProviderWorkHistoryRepository($ctx))->forHolder($id),
                'readiness'       => (new FlxCoreHC\ApplicationReadinessService($ctx))->evaluate($row, $__tstates),
                'checklist'       => (new FlxCoreHC\ChecklistService($ctx))->forProvider($row),
                'tenant'          => (function() use ($ctx) { try { $st = FlxCoreHC\Database::pdo()->prepare('SELECT name, legal_name, fein FROM tenants WHERE id = ?'); $st->execute([$ctx->tenantId]); return $st->fetch() ?: []; } catch (\Throwable $e) { return []; } })(),
                'contacts'        => (new FlxCoreHC\Repository\ProviderContactRepository($ctx))->forHolder($id),
                'samConfigured'   => FlxCoreHC\VerificationService::samKey() !== '',
                'level'           => FlxCoreHC\ComplianceLevelService::evaluate($ctx, $row, $credentials->all(['holder_id' => $id], 'expiry_date ASC'), $producerReqs->tokensFor($id), FlxCoreHC\CatalogService::coverageTokens($credentials->all(['holder_id' => $id], 'expiry_date ASC'))),
                'crossOthers'     => !empty($row['npi']) ? FlxCoreHC\CrossTenantService::othersManaging((string)$row['npi'], $ctx->tenantId) : 0,
                'crossLinks'      => (new FlxCoreHC\CrossTenantService($ctx))->activeLinksForHolder($id),
                'crossSharesOut'  => FlxCoreHC\Authz::can($ctx, 'share.manage') ? (new FlxCoreHC\CrossTenantService($ctx))->sharesByMeFor($id) : [],
                'crossSharesIn'   => (new FlxCoreHC\CrossTenantService($ctx))->readShared($id),
                'crossPending'    => array_values(array_filter((new FlxCoreHC\CrossTenantService($ctx))->linksForTenant(), fn($l) => $l['my_holder_id'] === $id && in_array($l['status'], ['pending_provider','pending_owner'], true))),
                'allGroups'       => (new FlxCoreHC\Repository\GroupRepository($ctx))->active(),
                'groupLabel'      => FlxCoreHC\GroupService::label($ctx->orgShape),
                'allLocations'    => (new FlxCoreHC\Repository\LocationRepository($ctx))->active(),
                'consents'    => (new FlxCoreHC\ConsentService($ctx))->forHolder($id),
                'teamMembers' => FlxCoreHC\TeamService::canManage($ctx) ? (new FlxCoreHC\TeamService($ctx))->users() : [],
                'attestations' => $attestations->forHolder($id),
                'applications' => $applications->forHolder($id),
                'screenings' => $screenings->forHolder($id),
                'legacyReadiness' => FlxCoreHC\ReadinessService::forProvider(
                    $mine ?? ['rows' => [], 'score' => null],
                    $applications->forHolder($id),
                    $verifications->forHolder($id),
                    $attestations->latestFor($id),
                    $creds,
                    $documents->countByCredential(),
                    $screenings->latestFor($id)
                ),
                'regulatorsByCategory' => $regulators->activeByCategory(),
                'customReqs'  => $regulatorReqs->byRegulator(),
                'mine'        => $mine,
                'shareLinks'  => $__shareLinks,
                'baseUrl'     => flx_public_base(),
                'ce'          => $ce, 'ceHours' => $ceHours, 'ceLaw' => $ceLaw, 'cycle' => $cyc,
                'portalUser'  => TeamService::portalUserFor($ctx, $id),
                'catalogOk'   => FlxCoreHC\CatalogService::available()]);
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'delete') {
            FlxCoreHC\Authz::requireCap($ctx, 'records.delete');
            CsrfGuard::verify();
            // Soft delete — recoverable. Credentials stay attached and return intact on restore.
            // v1.170.6 — refuse while a usable login account is linked to this record (it would point at a
            // trashed record; for a staff/admin account this can break their access).
            $__linked = (new FlxCoreHC\PurgeService($ctx))->activeLinkedUsers($id);
            if ($__linked) {
                flash(t('This record is linked to the login account {email}. Suspend that account (Team) or unlink it before deleting the record.', ['email' => $__linked[0]['email']]), 'error');
                redirect('/holders/' . $id);
            }
            if ($holders->delete($id)) {
                audit_log($ctx, 'provider_deleted', 'holders', $id, null);
                flash('Moved to Deleted records. You can restore it from there.', 'ok');
            } else {
                flash('Record not found or already deleted.', 'error');
            }
            redirect('/holders');
        }
        // ---- Provider ↔ employee assignment (need-to-know access) ----------------
        // Only user managers change who is responsible for (and can see) a provider.
        // ---- Provider ↔ group membership (client groups / departments) -----------
        if ($method === 'POST' && ($seg[2] ?? '') === 'join-group') {
            CsrfGuard::verify();
            FlxCoreHC\Authz::requireCap($ctx, 'records.write');
            $gid = (string)($_POST['group_id'] ?? '');
            if ($gid !== '' && (new FlxCoreHC\Repository\GroupRepository($ctx))->find($gid)) {
                FlxCoreHC\GroupService::add($ctx, $id, $gid, trim((string)($_POST['role_in_group'] ?? '')) ?: null, !empty($_POST['is_primary']));
                audit_log($ctx, 'provider_join_group', 'holders', $id, json_encode(['group_id' => $gid]));
                flash('Added to group.', 'ok');
            }
            redirect('/holders/' . $id . '#groups');
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'leave-group') {
            CsrfGuard::verify();
            FlxCoreHC\Authz::requireCap($ctx, 'records.write');
            $gid = (string)($_POST['group_id'] ?? '');
            if (FlxCoreHC\GroupService::remove($ctx, $id, $gid)) {
                audit_log($ctx, 'provider_leave_group', 'holders', $id, json_encode(['group_id' => $gid]));
                flash('Removed from group.', 'ok');
            }
            redirect('/holders/' . $id . '#groups');
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'primary-group') {
            CsrfGuard::verify();
            FlxCoreHC\Authz::requireCap($ctx, 'records.write');
            FlxCoreHC\GroupService::setPrimary($ctx, $id, (string)($_POST['group_id'] ?? ''));
            audit_log($ctx, 'provider_primary_group', 'holders', $id, json_encode(['group_id' => $_POST['group_id'] ?? '']));
            redirect('/holders/' . $id . '#groups');
        }
        // ---- Provider ↔ location affiliation (practice sites) --------------------
        if ($method === 'POST' && ($seg[2] ?? '') === 'affiliate') {
            CsrfGuard::verify();
            FlxCoreHC\Authz::requireCap($ctx, 'records.write');
            $locId = (string)($_POST['location_id'] ?? '');
            if ($locId !== '' && (new FlxCoreHC\Repository\LocationRepository($ctx))->find($locId)) {
                FlxCoreHC\LocationService::affiliate($ctx, $id, $locId, trim((string)($_POST['role_at_site'] ?? '')) ?: null, !empty($_POST['is_primary']));
                audit_log($ctx, 'provider_affiliate_location', 'holders', $id, json_encode(['location_id' => $locId]));
                flash('Location affiliation added.', 'ok');
            }
            redirect('/holders/' . $id . '#locations');
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'unaffiliate') {
            CsrfGuard::verify();
            FlxCoreHC\Authz::requireCap($ctx, 'records.write');
            $locId = (string)($_POST['location_id'] ?? '');
            if (FlxCoreHC\LocationService::unaffiliate($ctx, $id, $locId)) {
                audit_log($ctx, 'provider_unaffiliate_location', 'holders', $id, json_encode(['location_id' => $locId]));
                flash('Location affiliation removed.', 'ok');
            }
            redirect('/holders/' . $id . '#locations');
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'primary-location') {
            CsrfGuard::verify();
            FlxCoreHC\Authz::requireCap($ctx, 'records.write');
            FlxCoreHC\LocationService::setPrimary($ctx, $id, (string)($_POST['location_id'] ?? ''));
            audit_log($ctx, 'provider_primary_location', 'holders', $id, json_encode(['location_id' => $_POST['location_id'] ?? '']));
            redirect('/holders/' . $id . '#locations');
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'assign') {
            CsrfGuard::verify();
            if (!FlxCoreHC\TeamService::canManage($ctx)) { http_response_code(403); exit('Forbidden'); }
            $uid = (string)($_POST['user_id'] ?? '');
            $urow = null;
            foreach ((new FlxCoreHC\TeamService($ctx))->users() as $u) { if ((string)$u['id'] === $uid) { $urow = $u; break; } }
            if ($urow) {
                FlxCoreHC\AssignmentService::assign($ctx, $id, $uid, trim((string)($_POST['note'] ?? '')) ?: null);
                audit_log($ctx, 'provider_assign', 'holders', $id, json_encode(['user_id' => $uid, 'user' => $urow['full_name'] ?? '']));
                flash(t('Assigned to {name}.', ['name' => ($urow['full_name'] ?: t('team member'))]));
            }
            redirect('/holders/' . $id);
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'unassign') {
            CsrfGuard::verify();
            if (!FlxCoreHC\TeamService::canManage($ctx)) { http_response_code(403); exit('Forbidden'); }
            $uid = (string)($_POST['user_id'] ?? '');
            if (FlxCoreHC\AssignmentService::unassign($ctx, $id, $uid)) {
                audit_log($ctx, 'provider_unassign', 'holders', $id, json_encode(['user_id' => $uid]));
                flash('Assignment removed.', 'ok');
            }
            redirect('/holders/' . $id);
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'verify-npi') {
            FlxCoreHC\Authz::requireCap($ctx, 'records.write');
            CsrfGuard::verify();
            if (!FlxCoreHC\Authz::can($ctx, 'npi.lookup')) { flash('Your role cannot verify NPIs.', 'error'); redirect('/holders/' . $id); }
            $npiIn = preg_replace('/\D/', '', (string)($_POST['npi'] ?? $row['npi'] ?? ''));
            $res = FlxCoreHC\NpiService::lookup($npiIn, true); // force refresh from CMS
            audit_log($ctx, 'npi_verify', 'holders', $id, json_encode(['npi' => $npiIn, 'found' => (bool)($res['found'] ?? false)]));
            if (empty($res['found'])) {
                flash($res['error'] ?? 'No CMS record found for that NPI.', 'error');
            } else {
                $holders->saveNpiVerification($id, $res, (string) $ctx->userId);
                audit_log($ctx, 'npi_verified', 'holders', $id, json_encode([
                    'npi' => $res['npi'] ?? $npiIn, 'legal_name' => $res['name'] ?? '',
                    'entity_type' => $res['entity_type'] ?? '', 'taxonomy' => $res['taxonomy'] ?? '',
                    'city' => $res['city'] ?? '', 'state' => $res['state'] ?? '']));
                flash('NPI verified against CMS — profile identity locked to the registry record.', 'ok');
            }
            redirect('/holders/' . $id);
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'invite-portal') {
            FlxCoreHC\Authz::requireCap($ctx, 'team.manage');
            CsrfGuard::verify();
            $email = trim((string)($_POST['email'] ?? '')) ?: (string)($row['email'] ?? '');
            $pname = FlxCoreHC\Repository\HolderRepository::displayName($row);
            [$ok, $m, $token] = (new TeamService($ctx))->ensureProducerUser($id, $pname, $email);
            if ($ok && $token) {
                $link = invite_link($token);
                send_invite_email($email, $pname ?: $email, $link, $ctx->tenantName);
                flash(t('Portal invite sent to {email}.', ['email' => $email]));
            } else {
                flash($m, $ok ? 'ok' : 'error');
            }
            redirect('/holders/' . $id);
        }
        // v1.171.6: only the bare provider URL (the edit form's action) updates the record. An unknown sub-path used to fall
        // through to this handler and blank every field the request did not send.
        if ($method === 'POST' && ($seg[2] ?? '') === '') {
            CsrfGuard::verify();
            FlxCoreHC\Authz::requireCap($ctx, 'records.write');   // v1.170.8
            // v1.171.0 (B-1): shared parser. Accepts every provider type, persists the Application-data block,
            // and never blanks a field the form did not submit. (NPN stays untouched — removed from the form in 1.170.7.)
            // v1.171.6: creating=false — a field is written only if the request carried it (was true → blanked name/NPI/e-mail).
            [$__hf, $__hErr] = FlxCoreHC\HolderInput::fromPost($ctx, $_POST, (string)($row['holder_type'] ?? 'individual'), false);
            if ($__hErr) { flash($__hErr, 'error'); redirect('/holders/' . $id . '/edit'); }
            $holders->update($id, $__hf);
            audit_log($ctx, 'provider_updated', 'holders', $id, json_encode(array_keys($__hf)));
            flash((($row['holder_type'] ?? '') === 'organization' ? 'Organization' : 'Provider') . ' updated.', 'ok');
            redirect('/holders/' . $id);
        }
    }
    http_response_code(404);
    exit('Not found.');
}

// ---- Regulator interaction records ------------------------------------------



// ---- Entity compliance (the agency / FMO itself) -----------------------------
// The organization is a licensed entity with its own statutory duties — agency licence,
// designated agent in charge, FEIN, breach-response plan. It is represented by the
// organization-type credential_holder, so it flows through the same engine as producers.
// ---- Organization hub (v1.47): tabbed management area -----------------------
// Tabs: Profile (/settings) · Team (/team) · Plan (/billing) · Security · Audit.
// The bare path lands on Profile; Security renders here. Everything gated to managers.
if ($seg[0] === 'organization') {
    FlxCoreHC\Authz::requireCap($ctx, 'org.manage');
    $sub = $seg[1] ?? null;
    if ($sub === null || $sub === 'profile') { redirect('/settings'); }
    // ---- Linked providers (cross-organization consent): Master Admin approves / declines / revokes ----
    if ($sub === 'custom-fields') {
        FlxCoreHC\Authz::requireCap($ctx, 'org.manage');
        $cf = new FlxCoreHC\CustomFieldService($ctx);
        if ($method === 'POST') {
            CsrfGuard::verify();
            $fid = (string)($seg[2] ?? ''); $act = (string)($seg[3] ?? '');
            if ($fid !== '' && $act === 'retire') { $cf->retireDefinition($fid); flash('Field retired (existing values are kept).', 'ok'); }
            else { [$ok, $msg] = $cf->saveDefinition($_POST, $fid ?: null); flash($msg, $ok ? 'ok' : 'error'); }
            redirect('/organization/custom-fields');
        }
        view('org/custom_fields', ['ctx' => $ctx, 'provider' => $cf->definitions('provider', false), 'tenant' => $cf->definitions('tenant', false)]);
    }
    if ($sub === 'addresses') {
        FlxCoreHC\Authz::requireCap($ctx, 'org.manage');
        $A = new FlxCoreHC\Repository\AddressRepository($ctx);
        if ($method === 'POST') {
            CsrfGuard::verify();
            $aid = (string)($seg[2] ?? ''); $act = (string)($seg[3] ?? '');
            // v1.170.8 (security): only the organization's OWN addresses are editable here (never a provider's).
            if ($aid !== '' && (($__own = $A->find($aid)) === null || $__own['owner_type'] !== 'tenant' || (string)$__own['owner_id'] !== $ctx->tenantId)) { http_response_code(404); exit('Not found.'); }
            if ($aid !== '' && $act === 'delete')      { $A->delete($aid); audit_log($ctx, 'address_removed', 'tenants', $ctx->tenantId, json_encode(['address_id' => $aid])); flash('Address removed.', 'ok'); }
            elseif ($aid !== '' && $act === 'primary') { $A->setPrimary($aid); flash('Primary address set.', 'ok'); }
            else { [$ok, $msg] = flx_save_address($A, 'tenant', $ctx->tenantId, $_POST, $aid ?: null); if ($ok) audit_log($ctx, $aid ? 'address_updated' : 'address_added', 'tenants', $ctx->tenantId, null); flash($msg, $ok ? 'ok' : 'error'); }
            redirect('/organization/addresses');
        }
        view('org/addresses', ['ctx' => $ctx, 'addresses' => $A->forOwner('tenant', $ctx->tenantId)]);
    }
    if ($sub === 'payers') {
        FlxCoreHC\Authz::requireCap($ctx, 'org.manage');
        $svc = new FlxCoreHC\EnrollmentService($ctx);
        if ($method === 'POST') { CsrfGuard::verify(); [$ok, $msg] = $svc->addPayer((string)($_POST['name'] ?? ''), (string)($_POST['kind'] ?? 'commercial')); flash($msg, $ok ? 'ok' : 'error'); redirect('/organization/payers'); }
        view('org/payers', ['ctx' => $ctx, 'payers' => $svc->payers()]);
    }
    if ($sub === 'sources') {
        view('org/sources', ['ctx' => $ctx, 'sources' => FlxCoreHC\RegulatorySources::all()]);
    }
    if ($sub === 'links') {
        FlxCoreHC\SupportAccess::denyIfSupport('Viewing linked providers');
        if ($ctx->role !== 'tenant_owner') { http_response_code(403); exit('Master Admin only.'); }
        $svc = new FlxCoreHC\CrossTenantService($ctx);
        if ($method === 'POST' && ($seg[2] ?? '') !== '' && in_array($seg[3] ?? '', ['approve','decline','revoke'], true)) {
            CsrfGuard::verify();
            $lid = (string)$seg[2];
            [$ok, $msg] = match ($seg[3]) {
                'approve' => $svc->ownerDecide($lid, true),
                'decline' => $svc->ownerDecide($lid, false),
                default   => $svc->revokeLink($lid, (string)($_POST['reason'] ?? '')),
            };
            flash($msg, $ok ? 'ok' : 'error'); redirect('/organization/links');
        }
        view('org/links', ['ctx' => $ctx, 'links' => $svc->linksForTenant()]);
    }
    // ---- Data export: the organization downloads everything it entered, as a ZIP of CSVs ----
    if ($sub === 'export' && $method === 'POST') {
        FlxCoreHC\SupportAccess::denyIfSupport('Exporting organization data');
        FlxCoreHC\Authz::requireCap($ctx, 'data.export');
        CsrfGuard::verify();
        $bytes = (new FlxCoreHC\DataExportService($ctx))->build();
        audit_log($ctx, 'data_export', 'tenants', substr($ctx->tenantId, 0, 36), json_encode(['bytes' => strlen($bytes)]));
        AccessLog::record($ctx, 'data_export', 'tenants', $ctx->tenantId);
        $fname = 'flxcorehc-export-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($ctx->tenantName)) . '-' . date('Y-m-d') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
        exit;
    }
    // ---- Email delivery: the tenant's own transport (Google / M365 / SMTP) ----
    if ($sub === 'email') {
        if ($method === 'POST') {
            CsrfGuard::verify();
            $ea = (string) ($_POST['action'] ?? '');
            if ($ea === 'ms_connect') {
                FlxCoreHC\Authz::requireCap($ctx, 'org.manage');
                if (!FlxCoreHC\MicrosoftOAuth::configured()) { flash('Microsoft 365 sign-in is not enabled on this platform yet — ask your platform administrator.', 'error'); redirect('/organization/email'); }
                header('Location: ' . FlxCoreHC\MicrosoftOAuth::authorizeUrl($ctx->tenantId)); exit;
            }
            if ($ea === 'ms_disconnect') {
                FlxCoreHC\Authz::requireCap($ctx, 'org.manage');
                FlxCoreHC\MicrosoftOAuth::disconnect($ctx->tenantId);
                audit_log($ctx, 'm365_disconnected', 'mail', $ctx->tenantId, null);
                flash('Microsoft 365 disconnected. Delivery falls back to SMTP or the platform sender.', 'ok'); redirect('/organization/email');
            }
            if ($ea === 'save') {
                [$ok, $msg] = FlxCoreHC\TenantMailService::save($ctx, $_POST);
                if ($ok) audit_log($ctx, 'mail_settings_saved', 'tenants', substr($ctx->tenantId, 0, 36),
                    json_encode(['transport' => ($_POST['transport'] ?? 'platform'), 'host' => (string)($_POST['smtp_host'] ?? '')]));
                flash($msg, $ok ? 'ok' : 'error');
            } elseif ($ea === 'probe') {
                FlxCoreHC\Authz::requireCap($ctx, 'org.manage');
                $cfg = FlxCoreHC\TenantMailService::cfgFor($ctx->tenantId);
                if ($cfg === null || ($cfg['mail_transport'] ?? '') !== 'smtp') { flash('Save your SMTP settings first (transport: Own SMTP).', 'error'); redirect('/organization/email'); }
                $r = FlxCoreHC\MailService::smtpRun($cfg, (string) $cfg['mail_from'], '', '', '', '', '', null, true);
                $_SESSION['mail_test'] = ['what' => 'Connection test', 'ok' => (bool) $r['ok'], 'error' => $r['ok'] ? '' : FlxCoreHC\SmtpClient::explain($r, (string) $cfg['smtp_host']), 'log' => $r['log']];
                FlxCoreHC\TenantMailService::markTest($ctx->tenantId, (bool) $r['ok'], (string) $_SESSION['mail_test']['error']);
            } elseif ($ea === 'retry') {
                FlxCoreHC\Authz::requireCap($ctx, 'org.manage');
                $n = FlxCoreHC\MailService::retryFailed($ctx->tenantId);
                if ($n > 0) FlxCoreHC\MailService::flush(50);
                flash($n === 0 ? 'No failed messages to retry.' : "Retrying {$n} message(s).", 'ok');
            } elseif ($ea === 'test') {
                $to = trim((string) ($_POST['test_to'] ?? ''));
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { flash('Enter a valid test recipient.', 'error'); redirect('/organization/email'); }
                $cfg = FlxCoreHC\TenantMailService::cfgFor($ctx->tenantId);
                if ($cfg === null) { flash('Save your SMTP settings first (transport: Own SMTP).', 'error'); redirect('/organization/email'); }
                [$ok, $err] = FlxCoreHC\MailService::testDelivery($cfg, $to, 'FlxCoreHC test message',
                    "This is a test from " . $ctx->tenantName . "'s FlxCoreHC email delivery settings. If you received it, delivery works.");
                FlxCoreHC\TenantMailService::markTest($ctx->tenantId, $ok, $err);
                audit_log($ctx, 'mail_settings_tested', 'tenants', substr($ctx->tenantId, 0, 36),
                    json_encode(['ok' => $ok]));
                flash($ok ? 'Test message sent — check the inbox. Delivery is verified.' : 'Test failed: ' . $err, $ok ? 'ok' : 'error');
            }
            redirect('/organization/email');
        }
        $mailTest = $_SESSION['mail_test'] ?? null; unset($_SESSION['mail_test']);
        view('org/email', ['ctx' => $ctx, 'mailTest' => $mailTest,
            'settings' => FlxCoreHC\TenantMailService::get($ctx->tenantId),
            'presets'  => FlxCoreHC\TenantMailService::PRESETS,
            'msReady'  => FlxCoreHC\MicrosoftOAuth::configured()]);
        exit;
    }
    // ---- Tracked documents: the tenant's own credential types (v1.78) --------
    if ($sub === 'documents-tracked') {
        if ($method === 'POST') {
            CsrfGuard::verify();
            $da = (string) ($_POST['action'] ?? '');
            if ($da === 'add') {
                [$ok, $msg] = FlxCoreHC\CatalogService::addCustom($ctx->tenantId, $_POST, $ctx->userId);
                if ($ok) audit_log($ctx, 'catalog_type_added', 'tenants', substr($ctx->tenantId, 0, 36),
                    json_encode(['name' => trim((string)($_POST['name'] ?? ''))]));
                flash($msg, $ok ? 'ok' : 'error');
            } elseif ($da === 'update') {
                [$ok, $msg] = FlxCoreHC\CatalogService::updateCustom($ctx->tenantId, (string)($_POST['type_id'] ?? ''), $_POST);
                if ($ok) audit_log($ctx, 'catalog_type_updated', 'tenants', substr($ctx->tenantId, 0, 36),
                    json_encode(['id' => (string)($_POST['type_id'] ?? '')]));
                flash($msg, $ok ? 'ok' : 'error');
            } elseif ($da === 'retire' || $da === 'restore') {
                $done = FlxCoreHC\CatalogService::setRetired($ctx->tenantId, (string)($_POST['type_id'] ?? ''), $da === 'retire');
                if ($done) audit_log($ctx, 'catalog_type_' . ($da === 'retire' ? 'retired' : 'restored'),
                    'tenants', substr($ctx->tenantId, 0, 36), json_encode(['id' => (string)($_POST['type_id'] ?? '')]));
                flash($done ? ($da === 'retire' ? 'Document type retired — existing records keep it; it can no longer be newly assigned.'
                                                : 'Document type restored.')
                            : 'That document type was not found.');
            }
            redirect('/organization/documents-tracked');
        }
        $__custom = FlxCoreHC\CatalogService::customFor($ctx->tenantId);
        $__edit = null;
        if (isset($_GET['edit'])) {
            foreach ($__custom as $__t) { if ($__t['id'] === (string)$_GET['edit']) { $__edit = $__t; break; } }
        }
        view('org/documents_tracked', [
            'ctx'    => $ctx,
            'custom' => $__custom,
            'categories' => FlxCoreHC\CatalogService::CATEGORIES,
            'states' => FlxCoreHC\CatalogService::STATES,
            'edit'   => $__edit,
        ]);
        exit;
    }

    if ($sub === 'security' && $method === 'POST') {
        CsrfGuard::verify();
        (new OrgService($ctx))->updateInfo($_POST);
        audit_log($ctx, 'security_policy_set', 'tenants', substr($ctx->tenantId, 0, 36),
            json_encode(['require_2fa_admins' => !empty($_POST['require_2fa_admins']) ? 1 : 0]));
        flash('Security policy saved.', 'ok');
        redirect('/organization/security');
    }
    if ($sub === 'security' && $method === 'GET') {
        $__su = FlxCoreHC\Database::pdo()->prepare(
            "SELECT full_name, email, role, status, totp_enabled FROM users
             WHERE tenant_id = ? ORDER BY FIELD(role,'tenant_owner','manager','staff','agent','producer','read_only'), full_name");
        $__su->execute([$ctx->tenantId]);
        view('org/security', ['ctx' => $ctx, 'users' => $__su->fetchAll(), 'org' => $__trow]);
    }

    // (v1.170.7: NIPR insurance-licence interest route removed — healthcare-only product.)

    // ---- Menu icon customisation (v1.70) ------------------------------------
    if ($sub === 'icons' && $method === 'POST') {
        CsrfGuard::verify();
        // Field names use icons[...] because PHP rewrites dots in top-level POST keys.
        $json = FlxCoreHC\IconService::encode((array) ($_POST['icons'] ?? []));
        FlxCoreHC\Database::pdo()->prepare('UPDATE tenants SET nav_icons = ? WHERE id = ?')
            ->execute([$json, $ctx->tenantId]);
        audit_log($ctx, 'nav_icons_set', 'tenants', substr($ctx->tenantId, 0, 36),
            json_encode(['custom' => $json === null ? 0 : count(json_decode($json, true) ?: [])]));
        flash($json === null ? 'Menu icons reset to the defaults.' : 'Menu icons saved.');
        redirect('/organization/icons');
    }
    if ($sub === 'icons' && $method === 'GET') {
        view('organization/icons', ['ctx' => $ctx,
            'overrides' => FlxCoreHC\IconService::overrides($__trow['nav_icons'] ?? null)]);
    }

    // ---- Security & compliance posture (v1.68) ------------------------------
    if ($sub === 'compliance' && $method === 'GET') {
        $__cp = new FlxCoreHC\CompliancePostureService($ctx);
        if (($seg[2] ?? '') === 'pdf') {
            $orgName = (string) (($__trow['brand_name'] ?? '') ?: ($__trow['name'] ?? 'Your agency'));
            $bytes = $__cp->pdf($orgName);
            audit_log($ctx, 'compliance_pdf', 'tenants', substr($ctx->tenantId, 0, 36), null);
            $fname = 'security-compliance-' . preg_replace('/[^A-Za-z0-9]+/', '-', $orgName) . '-' . gmdate('Y-m-d') . '.pdf';
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $fname . '"');
            header('Content-Length: ' . strlen($bytes));
            header('X-Content-Type-Options: nosniff');
            echo $bytes;
            exit;
        }
        view('organization/compliance', ['ctx' => $ctx,
            'sections' => $__cp->sections(), 'summary' => $__cp->summary(), 'recs' => $__cp->recommendations()]);
    }

    // ---- Lines of business + operating states (own tab, v1.68) --------------
    if ($sub === 'lines' && $method === 'POST') {
        CsrfGuard::verify();
        (new OrgService($ctx))->updateInfo($_POST);
        audit_log($ctx, 'operating_jurisdictions_set', 'tenants', substr($ctx->tenantId, 0, 36),
            json_encode(['lines' => (array)($_POST['business_lines'] ?? []),
                         'states' => (array)($_POST['operating_states'] ?? [])]));
        flash('Operating jurisdictions saved.', 'ok');
        redirect('/organization/lines');
    }
    if ($sub === 'lines' && $method === 'GET') {
        view('organization/lines', ['ctx' => $ctx, 'org' => $__trow]);
    }

    // ---- Roles & permissions (custom-role builder, v1.50) -------------------
    if ($sub === 'roles') {
        // Managing roles is itself a team-management action.
        FlxCoreHC\Authz::requireCap($ctx, 'team.manage');
        $roleSvc = new FlxCoreHC\RoleService($ctx);
        $rid = $seg[2] ?? null;

        if ($method === 'POST') {
            CsrfGuard::verify();
            $action = (string) ($_POST['action'] ?? '');
            // v1.171.9: the role form always carries caps_form=1, so an empty checkbox set means "no capabilities";
            // a request without either (a rename-only POST) must not wipe the role's capabilities.
            $capsSent = array_key_exists('capabilities', $_POST) || isset($_POST['caps_form']);
            $caps   = $capsSent ? array_values((array) ($_POST['capabilities'] ?? [])) : null;
            $portal = ($_POST['portal_only'] ?? '') === '1';
            if ($action === 'create') {
                [$ok, $msg] = $roleSvc->create((string) ($_POST['name'] ?? ''), $caps ?? [], $portal);
            } elseif ($action === 'update' && $rid) {
                [$ok, $msg] = $roleSvc->update($rid, (string) ($_POST['name'] ?? ''), $caps, $portal);
                // Level 2: per-field sensitive-data rules for this role (allow | deny | inherit)
                if ($ok && !empty($_POST['field_rules']) && is_array($_POST['field_rules'])) {
                    $__slug = (string)($_POST['role_slug'] ?? '');
                    $rules = []; foreach ($_POST['field_rules'] as $fk => $rule) { if (in_array($rule, ['allow','deny','inherit'], true)) $rules[(string)$fk] = $rule; }
                    if ($__slug !== '' && $__slug !== 'tenant_owner' && $rules) FlxCoreHC\SensitiveFields::saveGrants($ctx, $__slug, $rules);
                }
            } elseif ($action === 'field_rules' && $rid) {
                // Per-field rules on a built-in (global) role: tenant-scoped, so allowed even though caps are locked.
                $__slug = (string)($_POST['role_slug'] ?? '');
                $rules = []; foreach ((array)($_POST['field_rules'] ?? []) as $fk => $rule) { if (in_array($rule, ['allow','deny','inherit'], true)) $rules[(string)$fk] = $rule; }
                if ($__slug !== '' && $__slug !== 'tenant_owner' && $rules) { FlxCoreHC\SensitiveFields::saveGrants($ctx, $__slug, $rules); [$ok, $msg] = [true, 'Field rules saved.']; }
                else { [$ok, $msg] = [false, 'Nothing to save.']; }
            } elseif ($action === 'delete' && $rid) {
                [$ok, $msg] = $roleSvc->delete($rid);
                flash($msg, $ok ? 'ok' : 'error'); redirect('/organization/roles');
            } else {
                $ok = false; $msg = 'Unknown action.';
            }
            flash($msg, $ok ? 'ok' : 'error');
            redirect($ok ? '/organization/roles' : ($rid ? '/organization/roles/' . $rid : '/organization/roles/new'));
        }

        if ($rid === 'new' || ($rid !== null && $method === 'GET')) {
            $role = ($rid === 'new') ? null : $roleSvc->find($rid);
            $lockedGlobal = false;
            if ($rid !== 'new' && !$role) {
                // Built-in/global roles are merged into the list but live outside the tenant; show
                // them read-only instead of a dead "Role not found".
                $role = FlxCoreHC\RoleService::findGlobal($rid);
                $lockedGlobal = $role !== null;
            }
            if ($rid !== 'new' && !$role) { http_response_code(404); exit('Role not found.'); }
            view('org/role_form', ['ctx' => $ctx, 'role' => $role, 'lockedGlobal' => $lockedGlobal,
                'fieldRules' => $role ? (FlxCoreHC\SensitiveFields::grantsFor($ctx->tenantId)[$role['slug']] ?? []) : [], 'protectable' => FlxCoreHC\SensitiveFields::protectable($ctx),
                'catalog' => FlxCoreHC\Capabilities::CATALOG]);
        }
        view('org/roles', ['ctx' => $ctx, 'roles' => $roleSvc->all(),
            'catalog' => FlxCoreHC\Capabilities::CATALOG]);
    }

    http_response_code(404); exit('Not found.');
}

if ($seg[0] === 'entity') {
    if ($__isPortalRole) { redirect('/me'); }
    $canManage = FlxCoreHC\TeamService::canManage($ctx);
    // v1.170.9: the organization's own record is not one of a restricted user's assigned providers. Without this
    // guard the lookup below found nothing, CREATED another organization holder on every visit, then crashed (500).
    if (FlxCoreHC\ProviderScope::for($ctx)->restricted()) { http_response_code(403); exit('Forbidden — entity compliance needs access to all providers.'); }

    // Find (or create) the entity holder — every agency should have exactly one.
    $entity = null;
    foreach ($holders->all([], 'created_at ASC') as $h) {
        if ($h['holder_type'] === 'organization') { $entity = $h; break; }
    }
    if ($entity === null) {
        $newId = $holders->insert([
            'holder_type' => 'organization',
            'org_name'    => $__trow['name'] ?? 'Our organization',
            'vertical'    => $ctx->vertical,
            'status'      => 'active',
        ]);
        $entity = $holders->find($newId);
    }
    $eid = $entity['id'];

    if ($method === 'POST') {
        CsrfGuard::verify();
        if (!$canManage) { http_response_code(403); exit('Forbidden — entity compliance is managed by owners and managers.'); }

        if (($seg[1] ?? '') === 'assign-owner') {
            $producerReqs->assign($eid, (string)($_POST['token'] ?? ''), ($_POST['user_id'] ?? '') !== '' ? (string)$_POST['user_id'] : null);
            flash('Responsibility updated.', 'ok');
            redirect('/entity');
        }
        if (($seg[1] ?? '') === 'apply-template') {
            $tid = trim((string)($_POST['template_id'] ?? ''));
            if ($tid !== '') {
                [$aok, $amsg] = $templates->applyTo($eid, $tid, $producerReqs, ($_POST['mode'] ?? 'replace') === 'merge' ? 'merge' : 'replace');
                flash($amsg, $aok ? 'ok' : 'error');
            }
            redirect('/entity');
        }
        // entity profile (legal name, FEIN, NPN, agency licence number)
        [$ok, $msg] = (new FlxCoreHC\OrgService($ctx))->updateEntity($_POST);
        flash($msg, $ok ? 'ok' : 'error');
        redirect('/entity');
    }

    $eRole = FlxCoreHC\CatalogService::holderRole($entity, (string)($__trow['org_shape'] ?? 'agency'));
    $data  = flx_compliance_data($holders, $credentials, $producerReqs, $regulatorReqs, $regulators,
        (string)($__trow['org_shape'] ?? 'agency'), $__tlines, $documents, $__tstates, $holderRegs);
    $row   = null;
    foreach ($data['producers'] as $p) { if (($p['holder']['id'] ?? null) === $eid) { $row = $p; break; } }

    view('organization/index', [
        'ctx'        => $ctx,
        'tenant'     => $__trow,
        'entity'     => $entity,
        'row'        => $row,
        'assigned'   => $producerReqs->tokensFor($eid),
        'assignees'  => $producerReqs->assigneesFor($eid),
        'team'       => $canManage ? (new FlxCoreHC\TeamService($ctx))->users() : [],
        'templates'  => $templates->all($eRole, $__tlines, $__tstates),
        'tplCurrent' => !empty($entity['template_id']) ? $templates->find((string)$entity['template_id']) : null,
        'catalog'    => FlxCoreHC\CatalogService::all($eRole, $__tlines, $__tstates, $ctx->tenantId),
        'creds'      => $credentials->all(['holder_id' => $eid], 'expiry_date ASC'),
        'states'     => $__tstates,
        'canManage'  => $canManage,
    ]);
}

// ---- Compliance templates ----------------------------------------------------
// A template is a named requirement bundle anchored to a licence type. This is where
// an agency defines "what a FL 2-15 Medicare agent must have" once, and reuses it.


// ---- Applications: enrollment / credentialing submissions in flight -----------
if ($seg[0] === 'applications') {
    if (!FlxCoreHC\TeamService::canManage($ctx)) { redirect('/'); }
    $aid = $seg[1] ?? null;

    if ($method === 'POST') {
        FlxCoreHC\Auth\CsrfGuard::verify();
        FlxCoreHC\Authz::requireCap($ctx, 'records.write');
        $act = (string)($_POST['action'] ?? '');

        if ($act === 'save') {
            $hid = (string)($_POST['holder_id'] ?? '');
            if (!$holders->find($hid)) { flash('Pick a valid provider.', 'error'); redirect('/applications'); }
            $kind = array_key_exists($_POST['kind'] ?? '', FlxCoreHC\Repository\ApplicationRepository::KINDS) ? $_POST['kind'] : 'enrollment';
            $rid  = trim((string)($_POST['regulator_id'] ?? '')) ?: null;
            if ($rid !== null && !$regulators->find($rid)) $rid = null;
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '') { flash('Give the application a title.', 'error'); redirect('/applications'); }
            $existingId = (string)($_POST['id'] ?? '');
            $in = [
                'holder_id' => $hid, 'regulator_id' => $rid, 'kind' => $kind, 'title' => $title,
                'reference' => trim((string)($_POST['reference'] ?? '')) ?: null,
                'due_on'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['due_on'] ?? '')) ? $_POST['due_on'] : null,
                'notes'     => trim((string)($_POST['notes'] ?? '')) ?: null,
            ];
            if ($existingId !== '' && $applications->find($existingId)) {
                $applications->update($existingId, $in);
                $rid2 = $existingId;
            } else {
                $in['status'] = 'not_started';
                $rid2 = $applications->insert($in);
                $applications->recordStatus($rid2, 'not_started', 'Application created', $ctx->userName ?? null, $ctx->userId);
            }
            FlxCoreHC\AccessLog::record($ctx, 'write', 'application', $rid2);
            flash('Application saved.', 'ok');
            redirect('/applications/' . $rid2);
        }

        if ($act === 'status' && $aid && ($app = $applications->find($aid))) {
            $new = array_key_exists($_POST['status'] ?? '', FlxCoreHC\Repository\ApplicationRepository::STATUSES) ? $_POST['status'] : $app['status'];
            $upd = ['status' => $new];
            // stamp submitted/decision dates as the lifecycle advances
            if ($new === 'submitted' && empty($app['submitted_on'])) $upd['submitted_on'] = today_local();
            if (in_array($new, ['approved', 'denied', 'withdrawn'], true) && empty($app['decision_on'])) $upd['decision_on'] = today_local();
            $applications->update($aid, $upd);
            $applications->recordStatus($aid, $new, trim((string)($_POST['note'] ?? '')) ?: null, $ctx->userName ?? null, $ctx->userId);
            FlxCoreHC\AccessLog::record($ctx, 'write', 'application_status', $aid);
            flash(t('Status updated to {status}.', ['status' => t((string)(FlxCoreHC\Repository\ApplicationRepository::STATUSES[$new] ?? $new))]));
            redirect('/applications/' . $aid);
        }

        if ($act === 'delete' && $aid && $applications->find($aid)) {
            FlxCoreHC\Authz::requireCap($ctx, 'records.delete');
            $applications->delete($aid);
            audit_log($ctx, 'application_deleted', 'applications', $aid, null);
            flash('Application removed.', 'ok');
            redirect('/applications');
        }
        redirect('/applications');
    }

    if ($aid !== null && ($seg[2] ?? '') === 'edit-form' && ($app = $applications->find($aid))) {
        view('applications/form', ['ctx' => $ctx, 'app' => $app,
            'holderList' => $holders->all([], 'holder_type DESC, last_name, org_name'),
            'regulatorList' => $regulators->all([], 'name ASC'), 'preHolder' => '']);
    }
    if ($aid !== null && ($app = $applications->find($aid))) {
        view('applications/show', ['ctx' => $ctx, 'app' => $app,
            'history' => $applications->statusHistory($aid),
            'holderList' => $holders->all([], 'holder_type DESC, last_name, org_name'),
            'regulatorList' => $regulators->all([], 'name ASC')]);
    }
    if ($aid === 'new') {
        view('applications/form', ['ctx' => $ctx,
            'holderList' => $holders->all([], 'holder_type DESC, last_name, org_name'),
            'regulatorList' => $regulators->all([], 'name ASC'),
            'preHolder' => (string)($_GET['holder'] ?? '')]);
    }
    view('applications/index', ['ctx' => $ctx, 'apps' => $applications->allWithNames()]);
}

// ---- Regulators: payers, agencies, frameworks, accreditors, state boards ----
if ($seg[0] === 'regulators') {
    if (!FlxCoreHC\TeamService::canManage($ctx)) { redirect('/'); }
    $id = $seg[1] ?? null;

    if ($method === 'POST') {
        FlxCoreHC\Auth\CsrfGuard::verify();
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'save') {
            $cats = array_keys(FlxCoreHC\Repository\RegulatorRepository::CATEGORIES);
            $__jur = strtoupper(trim((string)($_POST['jurisdiction'] ?? '')));
            if ($__jur !== '' && !isset(FlxCoreHC\CatalogService::STATES[$__jur])) $__jur = '';
            $in = [
                'name'         => trim((string)($_POST['name'] ?? '')),
                'category'     => in_array($_POST['category'] ?? '', $cats, true) ? $_POST['category'] : 'payer',
                'jurisdiction' => $__jur ?: null,
                'short_name'   => trim((string)($_POST['short_name'] ?? '')) ?: null,
                'status'       => ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            ];
            if ($in['name'] === '') { flash('A name is required.', 'error'); redirect('/regulators'); }
            $rid = (string)($_POST['id'] ?? '');
            if ($rid !== '' && $regulators->find($rid)) { $regulators->update($rid, $in); }
            else { $in['is_custom'] = 1; $rid = $regulators->insert($in); }
            FlxCoreHC\AccessLog::record($ctx, 'write', 'regulator', $rid);
            flash('Regulator saved.', 'ok');
            redirect('/regulators/' . $rid);
        }
        if ($act === 'add_req' && $id && $regulators->find($id)) {
            $kind = ($_POST['kind'] ?? 'credential') === 'document' ? 'document' : 'credential';
            $code = trim((string)($_POST['catalog_code'] ?? '')) ?: null;
            $name = trim((string)($_POST['req_name'] ?? ''));
            if ($kind === 'credential' && $code !== null && $name === '') {
                foreach (FlxCoreHC\CatalogService::all(null, $__tlines, $__tstates, $ctx->tenantId) as $cRow) {
                    if (($cRow['code'] ?? '') === $code) { $name = $cRow['name']; break; }
                }
                if ($name === '') $name = $code;
            }
            if ($name === '') { flash('A requirement name is required.', 'error'); redirect('/regulators/' . $id); }
            // The regulator_requirements table has no migration in the shipped schema (see
            // docs/architecture P1-4). If it is absent, adding a requirement would fatal; surface a
            // clean message instead. When the table exists, this is a no-op and the insert proceeds.
            if (!$regulatorReqs->tableReady()) { flash('Regulator-defined requirements are not available on this installation.', 'error'); redirect('/regulators/' . $id); }
            $regulatorReqs->insert([
                'regulator_id'   => $id,
                'kind'           => $kind,
                'catalog_code'   => $kind === 'credential' ? $code : null,
                'name'           => $name,
                'category'       => $kind,
                'renewal_months' => ($_POST['renewal_months'] ?? '') !== '' ? (int)$_POST['renewal_months'] : null,
                'notes'          => trim((string)($_POST['notes'] ?? '')) ?: null,
            ]);
            flash('Requirement added.', 'ok');
            redirect('/regulators/' . $id);
        }
        if ($act === 'del_req' && $id) {
            $rq = $regulatorReqs->find((string)($_POST['req_id'] ?? ''));
            if ($rq && $rq['regulator_id'] === $id) { $regulatorReqs->delete($rq['id']); flash('Requirement removed.', 'ok'); }
            redirect('/regulators/' . $id);
        }
        redirect('/regulators');
    }

    if ($id !== null && ($row = $regulators->find($id))) {
        view('regulators/show', ['ctx' => $ctx, 'row' => $row,
            'reqs' => $regulatorReqs->forRegulator($id),
            'states' => FlxCoreHC\CatalogService::STATES,
            'catalog' => FlxCoreHC\CatalogService::all(null, $__tlines, $__tstates, $ctx->tenantId)]);
    }
    view('regulators/index', ['ctx' => $ctx, 'byCategory' => $regulators->activeByCategory(),
        'states' => FlxCoreHC\CatalogService::STATES]);
}

if ($seg[0] === 'templates') {
    $canManage = FlxCoreHC\TeamService::canManage($ctx);
    $id = $seg[1] ?? null;

    if ($method === 'POST') {
        CsrfGuard::verify();
        if (!$canManage) { http_response_code(403); exit('Forbidden — templates are managed by owners and managers.'); }

        if ($id !== null && ($seg[2] ?? '') === 'delete') {
            FlxCoreHC\Authz::requireCap($ctx, 'records.delete');
            flash(($__td = $templates->delete($id)) ? 'Template deleted.' : 'Template not found.', $__td ? 'ok' : 'error');
            audit_log($ctx, 'template_deleted', 'requirement_templates', $id, null);
            redirect('/templates');
        }
        $tokens = array_values(array_filter((array) ($_POST['tokens'] ?? []), 'is_string'));
        [$ok, $msg, $newId] = $templates->save($id === 'new' ? null : $id, $_POST, $tokens);
        flash($msg, $ok ? 'ok' : 'error');
        redirect($ok ? '/templates' : '/templates/' . ($id ?? 'new'));
    }

    if ($id === 'new' || ($id !== null && $method === 'GET')) {
        $tpl = $id === 'new' ? null : $templates->find($id);
        if ($id !== 'new' && !$tpl) { http_response_code(404); exit('Template not found.'); }
        view('templates/form', [
            'ctx' => $ctx, 'tpl' => $tpl,
            'tokens'      => $tpl ? $templates->tokens($tpl['id']) : [],
            'catalog'     => FlxCoreHC\CatalogService::all(null, $__tlines, $__tstates, $ctx->tenantId),
            'regulatorList' => $regulators->all([], 'name ASC'),
            'customReqs'  => $regulatorReqs->byRegulator(),
            'canManage'   => $canManage,
        ]);
    }

    view('templates/index', [
        'ctx' => $ctx,
        'templates' => $templates->all(null, $__tlines, $__tstates),
        'regulatorNames' => array_column($regulators->all([], 'name ASC'), 'name', 'id'),
        'catalog'   => FlxCoreHC\CatalogService::all(null, $__tlines, $__tstates, $ctx->tenantId),
        'canManage' => $canManage,
    ]);
}



// ---- Credentials -----------------------------------------------------------
if ($seg[0] === 'credentials') {
    $id = $seg[1] ?? null;

    // ---- Deleted credentials (trash) ----
    if ($method === 'GET' && $id === 'trash') {
        FlxCoreHC\Authz::requireCap($ctx, 'records.delete');
        view('credentials/trash', ['ctx' => $ctx, 'rows' => $credentials->trashed()]);
    }

    // ---- Restore / permanently delete a SOFT-DELETED credential (operate on deleted ids → outside find() guard) ----
    if ($id !== null && $method === 'POST' && ($seg[2] ?? '') === 'restore') {
        FlxCoreHC\Authz::requireCap($ctx, 'records.delete');
        CsrfGuard::verify();
        $__t = $credentials->findTrashed($id);
        if (!$__t || !FlxCoreHC\AssignmentService::canSee($ctx, (string) $__t['holder_id'])) { flash('Credential not found in Deleted records.', 'error'); redirect('/credentials/trash'); }
        if ($credentials->restore($id)) { audit_log($ctx, 'credential_restored', 'credentials', $id, null); flash('Credential restored.', 'ok'); }
        else { flash('Credential not found in Deleted records.', 'error'); }
        redirect('/credentials/trash');
    }
    if ($id !== null && $method === 'POST' && ($seg[2] ?? '') === 'purge') {
        FlxCoreHC\Authz::requireCap($ctx, 'records.delete');
        CsrfGuard::verify();
        $__t = $credentials->findTrashed($id);
        if (!$__t || !FlxCoreHC\AssignmentService::canSee($ctx, (string) $__t['holder_id'])) { flash('Delete the credential first, then purge.', 'error'); redirect('/credentials/trash'); }
        if (($_POST['confirm'] ?? '') !== 'PERMANENTLY DELETE') { flash('Type the confirmation phrase exactly.', 'error'); redirect('/credentials/trash'); }
        // v1.170.6 — single transactional, FK-ordered purge (docs, reminders, renewal tasks, evidence, files).
        [$__ok, $__msg] = (new FlxCoreHC\PurgeService($ctx))->purgeCredential($id);
        flash($__msg, $__ok ? 'ok' : 'error');
        redirect('/credentials/trash');
    }

    // ---- Bulk credential import (second half of onboarding) ----------------------------------
    if ($id === 'import') {
        FlxCoreHC\Authz::requireCap($ctx, 'records.write');
        if ($method === 'GET' && ($seg[2] ?? '') === 'template') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="credential-import-template.csv"');
            header('X-Content-Type-Options: nosniff');
            echo FlxCoreHC\CredentialImportService::TEMPLATE_HEADER . "\n";
            echo "1234567893,,,state_medical_license,FL Medical License,ME123456,Board of Medicine,2024-07-01,2026-06-30,FL,\n";
            echo "1234567893,,,dea,DEA Registration,BS1234567,DEA,2023-03-15,2026-03-14,,\n";
            echo ",Carlos,Rivera,malpractice,Malpractice policy,POL-8899,Acme Insurance,2026-01-01,,,Auto-renews yearly\n";
            echo ",Ana,Lopez,cme,CME 2026,,,2026-01-10,,,\n";
            exit;
        }
        if ($method === 'GET') {
            view('credentials/import', ['ctx' => $ctx, 'preview' => null, 'csv' => '']);
        }
        if ($method === 'POST') {
            CsrfGuard::verify();
            $csv = '';
            if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
                if (($_FILES['file']['size'] ?? 0) > 2_000_000) { flash('That file is larger than 2 MB.', 'error'); redirect('/credentials/import'); }
                $csv = (string) file_get_contents($_FILES['file']['tmp_name']);
            } else {
                $csv = (string) ($_POST['csv'] ?? '');
            }
            if (trim($csv) === '') { flash('Upload a CSV file or paste rows to import.', 'error'); redirect('/credentials/import'); }
            $svc = new FlxCoreHC\CredentialImportService($ctx);
            if (($_POST['step'] ?? '') === 'commit') {
                $r = $svc->commit($csv);
                audit_log($ctx, 'credential_import', 'credentials', null, json_encode($r));
                FlxCoreHC\NotificationService::push($ctx->tenantId, (string)$ctx->userId, 'import', 'info',
                    "Credential import finished: {$r['created']} imported" . ($r['errors'] ? ", {$r['errors']} skipped" : ''),
                    'Deadlines were added to the calendar automatically.', BASE_PATH . '/credentials', 'credentials', null, 'import:cred:' . time());
                $msg = $r['created'] . ' credential(s) imported';
                if ($r['errors']) $msg .= ', ' . $r['errors'] . ' row(s) skipped with errors';
                flash($msg . '. Deadlines will appear on the calendar automatically.', $r['errors'] ? (($r['created']) ? 'warn' : 'error') : 'ok');
                redirect('/credentials');
            }
            $preview = $svc->preview($csv);
            if (!$preview['ok']) { flash($preview['error'], 'error'); redirect('/credentials/import'); }
            view('credentials/import', ['ctx' => $ctx, 'preview' => $preview, 'csv' => $csv]);
        }
        exit;
    }

    if ($method === 'GET' && $id === null) {
        $filter = $_GET['status'] ?? null;
        $filter = in_array($filter, ['active', 'expiring', 'expired', 'suspended'], true) ? $filter : null;
        view('credentials/index', [
            'ctx'    => $ctx,
            'rows'   => $credentials->withHolders($filter),
            'filter' => $filter,
            'counts' => $credentials->statusCounts(),
        ]);
    }
    if ($method === 'GET' && $id === 'requirements') {
        $orgShape = $__trow['org_shape'] ?? 'agency';
        $holderRows = $holders->all([], 'holder_type DESC, last_name, org_name');
        $checklist = [];
        foreach ($holderRows as $h) {
            $role = FlxCoreHC\CatalogService::holderRole($h, (string)$orgShape);
            $creds = $credentials->all(['holder_id' => $h['id']], 'expiry_date ASC');
            $cover = FlxCoreHC\CatalogService::coverage($creds);
            $req = FlxCoreHC\CatalogService::all($role, $__tlines, $__tstates, $ctx->tenantId);
            $checklist[] = ['holder' => $h, 'role' => $role, 'items' => $req, 'coverage' => $cover];
        }
        view('credentials/requirements', [
            'ctx' => $ctx, 'catalog' => FlxCoreHC\CatalogService::all(null, $__tlines, $__tstates, $ctx->tenantId),
            'checklist' => $checklist, 'available' => FlxCoreHC\CatalogService::available(),
        ]);
    }
    if ($method === 'GET' && $id === 'new') {
        // Producers self-service against their own record only.
        $formHolders = $__isPortalRole
            ? array_values(array_filter($holders->all([], 'last_name'), fn($h) => $h['id'] === $ctx->holderId))
            : $holders->all([], 'holder_type DESC, last_name, org_name');
        view('credentials/form', [
            'ctx'         => $ctx,
            'row'         => null,
            'addressOptions' => flx_address_options($ctx, (string)($_GET['holder'] ?? '')),
            'holderList'  => $formHolders,
            'preselect'   => $__isPortalRole ? $ctx->holderId : ($_GET['holder'] ?? null),
            'regulatorList' => $regulators->active(),
            'customReqs'  => $regulatorReqs->byRegulator(),
        ]);
    }
    if ($method === 'POST' && $id === null) {
        CsrfGuard::verify();
        if ($ctx->role !== 'producer') FlxCoreHC\Authz::requireCap($ctx, 'records.write');   // v1.170.8
        // Producers can only file credentials against their own record.
        $holderId = $__isPortalRole ? (string) $ctx->holderId : (string)($_POST['holder_id'] ?? '');
        if (!$holders->find($holderId)) {                 // tenant-scoped existence check
            flash('Pick a valid credential holder.', 'error');
            redirect($__isPortalRole ? '/me' : '/credentials/new');
        }
        $expiry    = trim((string)($_POST['expiry_date'] ?? '')) ?: null;
        $issued    = trim((string)($_POST['issued_date'] ?? '')) ?: null;
        $credType  = trim((string)($_POST['credential_type'] ?? 'other'));
        // If no expiry was given but this type renews on a fixed cycle and we know the issue date,
        // derive the expiry so the calendar and reminder engine have a date to work from.
        if ($expiry === null && $issued !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $issued)) {
            $rm = FlxCoreHC\CatalogService::renewalMonthsForType($ctx->tenantId, $credType);
            if ($rm !== null) {
                $expiry = (new DateTimeImmutable($issued))->modify('+' . $rm . ' months')->format('Y-m-d');
            }
        }
        $planYear  = (int)($_POST['plan_year'] ?? 0);
        $regulatorId = (string)($_POST['regulator_id'] ?? '');
        $regulatorId = ($regulatorId !== '' && $regulators->find($regulatorId)) ? $regulatorId : null;
        $credId = $credentials->insert([
            'holder_id'         => $holderId,
            'credential_type'   => $credType,
            'name'              => trim((string)($_POST['name'] ?? '')),
            'issuing_body'      => trim((string)($_POST['issuing_body'] ?? '')) ?: null,
            'address_id'        => preg_match('/^[a-f0-9-]{36}$/', (string)($_POST['address_id'] ?? '')) ? $_POST['address_id'] : null,
            'credential_number' => trim((string)($_POST['credential_number'] ?? '')) ?: null,
            'issued_date'       => trim((string)($_POST['issued_date'] ?? '')) ?: null,
            'expiry_date'       => $expiry,
            'status'            => credential_status_for($expiry),
            'plan_year'         => $planYear >= 2020 && $planYear <= 2100 ? $planYear : null,
            'regulator_id'        => $regulatorId,
            'notes'             => trim((string)($_POST['notes'] ?? '')) ?: null,
        ]);
        flash('Credential added — it\'s on the horizon now.', 'ok');
        audit_log($ctx, 'credential_created', 'credentials', $credId, json_encode(['type' => $credType, 'expiry' => $expiry]));
        FlxCoreHC\ComplianceEventService::record($ctx, 'credential_added',
            'Credential added: ' . ($_POST['name'] ?? $credType) . ($expiry ? ' (expires ' . $expiry . ')' : ''),
            ['holder_id' => $holderId, 'credential_id' => $credId, 'effective_on' => $issued ?: null,
             'detail' => ['type' => $credType, 'number' => ($_POST['credential_number'] ?? null), 'issued' => $issued, 'expiry' => $expiry]]);
        // Return to the provider's checklist when the add came from there (?return=holder), so the row
        // checks off in place — the merged-checklist flow. Otherwise the normal credentials list.
        if (($_POST['return'] ?? $_GET['return'] ?? '') === 'holder' && $holderId) redirect('/holders/' . $holderId . '#checklist');
        redirect($__isPortalRole ? '/me' : '/credentials');
    }
    if ($id !== null && ($row = $credentials->find($id))) {
        // A producer may only ever touch their own credential, and may never delete one.
        // find() is already tenant-scoped; this narrows it to the single record that is theirs.
        if ($__isPortalRole) {
            if (($row['holder_id'] ?? null) !== $ctx->holderId) { http_response_code(403); exit('Forbidden.'); }
            if (($seg[2] ?? '') === 'delete') { http_response_code(403); exit('Forbidden.'); }
        }
        if ($method === 'GET' && ($seg[2] ?? '') === 'edit') {
            view('credentials/form', [
                'ctx'         => $ctx,
                'row'         => $row,
                'addressOptions' => flx_address_options($ctx, (string)($row['holder_id'] ?? '')),
                'holderList'  => $holders->all([], 'holder_type DESC, last_name, org_name'),
                'preselect'   => null,
                'docs'        => $documents->all(['credential_id' => $id]),
                'verifications' => $verifications->forCredential($id),
                'credHistory' => FlxCoreHC\Authz::can($ctx, 'audit.view') ? $auditLogs->forCredential($id) : null,
                'credEvents'  => FlxCoreHC\ComplianceEventService::forCredential($ctx, $id),
                'docHistory'  => FlxCoreHC\Authz::can($ctx, 'audit.view') ? (function() use ($documents, $auditLogs, $id) {
                        $m = []; foreach ($documents->all(['credential_id' => $id]) as $d) { $m[$d['id']] = $auditLogs->forDocument($d['id']); } return $m;
                    })() : [],
                'regulatorList' => $regulators->active(),
                'customReqs'  => $regulatorReqs->byRegulator(),
            ]);
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'verify') {
            FlxCoreHC\Authz::requireCap($ctx, 'documents.verify');
            CsrfGuard::verify();
            // Producers may not perform verification — it's a staff/credentialing act.
            if ($__isPortalRole) { http_response_code(403); exit('Forbidden.'); }
            $vm = array_key_exists($_POST['method'] ?? '', FlxCoreHC\Repository\VerificationRepository::METHODS)
                ? $_POST['method'] : 'primary_source';
            $vr = array_key_exists($_POST['result'] ?? '', FlxCoreHC\Repository\VerificationRepository::RESULTS)
                ? $_POST['result'] : 'verified';
            $von = trim((string)($_POST['verified_on'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $von)) $von = today_local();
            $verifications->insert([
                'credential_id'    => $id,
                'holder_id'        => (string)($row['holder_id'] ?? ''),
                'method'           => $vm,
                'source'           => trim((string)($_POST['source'] ?? '')) ?: null,
                'verified_on'      => $von,
                'verified_by'      => trim((string)($_POST['verified_by'] ?? '')) ?: ($ctx->userName ?? null),
                'verified_by_user' => $ctx->userId,
                'result'           => $vr,
                'notes'            => trim((string)($_POST['notes'] ?? '')) ?: null,
            ]);
            audit_log($ctx, 'verification_recorded', 'credentials', $id, json_encode(['result' => $_POST['result'] ?? null]));
            // Keep the credential's "last verified" stamp in sync for the existing display.
            if ($vr === 'verified') $credentials->update($id, ['last_verified_at' => $von . ' 00:00:00']);
            FlxCoreHC\AccessLog::record($ctx, 'write', 'credential_verification', $id);
            flash('Verification recorded.', 'ok');
            redirect('/credentials/' . $id . '/edit');
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'delete') {
            FlxCoreHC\Authz::requireCap($ctx, 'records.delete');
            CsrfGuard::verify();
            $credentials->clearReminders($id);
            $__delRow = $credentials->find($id) ?: [];
            $credentials->delete($id);
            audit_log($ctx, 'credential_deleted', 'credentials', $id, null);
            FlxCoreHC\ComplianceEventService::record($ctx, 'credential_removed',
                'Credential removed: ' . ($__delRow['name'] ?? 'credential'),
                ['holder_id' => ($__delRow['holder_id'] ?? null), 'credential_id' => $id,
                 'detail' => ['note' => 'Soft-deleted; record retained.']]);
            flash('Credential moved to Deleted records — you can restore it.', 'ok');
            if ((($_POST['return'] ?? '') === 'holder') && !empty($__delRow['holder_id'])) redirect('/holders/' . $__delRow['holder_id'] . '#checklist');
            redirect('/credentials');
        }
        if ($method === 'POST' && ($seg[2] ?? '') === 'documents') {
            // Producers are not granted documents.manage across the agency, but attaching evidence
            // to their own credential is the whole point of the portal — ownership was checked above.
            if ($ctx->role !== 'producer') FlxCoreHC\Authz::requireCap($ctx, 'documents.manage');
            CsrfGuard::verify();
            handle_document_upload($documents, $ctx, $id, $credentials);
            redirect('/credentials/' . $id . '/edit');
        }
        if ($method === 'POST') {
            CsrfGuard::verify();
            if ($ctx->role !== 'producer') FlxCoreHC\Authz::requireCap($ctx, 'records.write');   // v1.170.8
            $__before = $credentials->find($id) ?: [];
            $expiry    = trim((string)($_POST['expiry_date'] ?? '')) ?: null;
            $issued    = trim((string)($_POST['issued_date'] ?? '')) ?: null;
            $credType  = trim((string)($_POST['credential_type'] ?? 'other'));
            if ($expiry === null && $issued !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $issued)) {
                $rm = FlxCoreHC\CatalogService::renewalMonthsForType($ctx->tenantId, $credType);
                if ($rm !== null) {
                    $expiry = (new DateTimeImmutable($issued))->modify('+' . $rm . ' months')->format('Y-m-d');
                }
            }
            $planYear  = (int)($_POST['plan_year'] ?? 0);
            $regulatorId = (string)($_POST['regulator_id'] ?? '');
            $regulatorId = ($regulatorId !== '' && $regulators->find($regulatorId)) ? $regulatorId : null;
            $credentials->update($id, [
                'credential_type'   => $credType,
                'name'              => trim((string)($_POST['name'] ?? '')),
                'issuing_body'      => trim((string)($_POST['issuing_body'] ?? '')) ?: null,
                'address_id'        => preg_match('/^[a-f0-9-]{36}$/', (string)($_POST['address_id'] ?? '')) ? $_POST['address_id'] : null,
            'address_id'        => preg_match('/^[a-f0-9-]{36}$/', (string)($_POST['address_id'] ?? '')) ? $_POST['address_id'] : null,
                'credential_number' => trim((string)($_POST['credential_number'] ?? '')) ?: null,
                'issued_date'       => trim((string)($_POST['issued_date'] ?? '')) ?: null,
                'expiry_date'       => $expiry,
                'status'            => credential_status_for($expiry),
                'plan_year'         => $planYear >= 2020 && $planYear <= 2100 ? $planYear : null,
                'regulator_id'        => $regulatorId,
                'notes'             => trim((string)($_POST['notes'] ?? '')) ?: null,
            ]);
            $__after = ['credential_type' => $credType, 'name' => ($_POST['name'] ?? null),
                'credential_number' => ($_POST['credential_number'] ?? null), 'issued_date' => $issued,
                'expiry_date' => $expiry, 'issuing_body' => ($_POST['issuing_body'] ?? null)];
            $__diff = FlxCoreHC\ComplianceEventService::diff($__before, $__after,
                ['credential_type','name','credential_number','issued_date','expiry_date','issuing_body']);
            // Before→after is preserved in the audit trail, and the change recorded in the permanent ledger.
            audit_log($ctx, 'credential_updated', 'credentials', $id, json_encode(['changes' => $__diff]));
            if ($__diff) {
                // A later expiry than before = a renewal; otherwise a plain edit. Both kept forever.
                $renewed = isset($__diff['expiry_date']) && $expiry && (($__before['expiry_date'] ?? '') < $expiry);
                FlxCoreHC\ComplianceEventService::record($ctx, $renewed ? 'credential_renewed' : 'credential_edited',
                    ($renewed ? 'Credential renewed: ' : 'Credential edited: ') . ($_POST['name'] ?? $credType)
                        . ($renewed && $expiry ? ' (now expires ' . $expiry . ')' : ''),
                    ['holder_id' => ($__before['holder_id'] ?? null), 'credential_id' => $id,
                     'effective_on' => $issued ?: null, 'detail' => $__diff]);
            }
            flash('Credential updated.', 'ok');
            redirect('/credentials');
        }
    }
    http_response_code(404);
    exit('Not found.');
}

// ---- Renewal tasks ---------------------------------------------------------
if ($seg[0] === 'tasks') {
    $id = $seg[1] ?? null;
    if ($method === 'GET') {
        view('tasks/index', ['ctx' => $ctx, 'rows' => $tasks->openWithCredentials(),
            'holderList' => $holders->all([], 'holder_type DESC, last_name, org_name'),
            'credList' => $credentials->withHolders(null)]);
    }
    if ($method === 'POST' && $id === null) {
        CsrfGuard::verify();
        FlxCoreHC\Authz::requireCap($ctx, 'records.write');   // v1.170.8
        $credId = (string)($_POST['credential_id'] ?? '');
        $cred   = $credId !== '' ? $credentials->find($credId) : null;
        $title  = trim((string)($_POST['title'] ?? ''));
        $pri    = in_array($_POST['priority'] ?? '', ['low','normal','high'], true) ? $_POST['priority'] : 'normal';
        $due    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['due_date'] ?? '') ? $_POST['due_date'] : null;
        $holderId = (string)($_POST['holder_id'] ?? '');
        $holderId = ($holderId !== '' && $holders->find($holderId)) ? $holderId : null;
        if ($cred) {                                   // renewal task tied to a credential
            $tasks->insert([
                'credential_id' => $credId,
                'holder_id'     => $cred['holder_id'] ?? null,
                'title'         => $title !== '' ? $title : ('Renew: ' . $cred['name']),
                'status'        => 'pending',
                'due_date'      => $due ?: ($cred['expiry_date'] ?? null),
                'priority'      => $pri,
                'assigned_to'   => $ctx->userId,
            ]);
            audit_log($ctx, 'task_created', 'tasks', null, json_encode(['title' => $_POST['title'] ?? null]));
            flash('Renewal task created.', 'ok');
        } elseif ($title !== '') {                      // standalone task / to-do
            $tasks->insert([
                'credential_id' => null,
                'holder_id'     => $holderId,
                'title'         => $title,
                'status'        => 'pending',
                'due_date'      => $due,
                'priority'      => $pri,
                'assigned_to'   => $ctx->userId,
            ]);
            flash('Task added.', 'ok');
        } else {
            flash('Enter a task title (or pick a credential to renew).', 'error');
        }
        redirect('/tasks');
    }
    if ($method === 'POST' && $id !== null && ($seg[2] ?? '') === 'status') {
        CsrfGuard::verify();
        $status = (string)($_POST['status'] ?? 'pending');
        if (in_array($status, ['pending', 'in_progress', 'done'], true)) {
            $tasks->update($id, [
                'status'       => $status,
                'completed_at' => $status === 'done' ? utc_now() : null,
            ]);
            audit_log($ctx, 'task_updated', 'tasks', $id, null);
        }
        redirect('/tasks');
    }
    http_response_code(404);
    exit('Not found.');
}

// ---- Documents gatekeeper (the ONLY way files are read) ---------------------
// ---- Document verification (primary-source verification on the evidence itself) ----
if ($seg[0] === 'documents' && isset($seg[1]) && $method === 'POST' && in_array($seg[2] ?? '', ['verify', 'unverify'], true)) {
    CsrfGuard::verify();
    // v1.170.9: verification is its own privilege (documents.verify). Coordinators have records.write but
    // must not attest primary-source verification — the previous check let them.
    FlxCoreHC\Authz::requireCap($ctx, 'documents.verify');
    $doc = $documents->find((string)$seg[1]);
    if (!$doc) { http_response_code(404); exit('Document not found.'); }
    $credRow = $credentials->find((string)$doc['credential_id']);
    $back = $credRow ? '/credentials/' . $credRow['id'] . '/edit' : '/credentials';
    if (($seg[2] ?? '') === 'verify') {
        $method_ = in_array($_POST['method'] ?? '', ['primary_source', 'copy', 'attestation'], true) ? $_POST['method'] : 'primary_source';
        $exp = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['verification_expires'] ?? '') ? $_POST['verification_expires'] : null;
        $documents->update((string)$doc['id'], [
            'verified_at' => utc_now(), 'verified_by' => $ctx->userId, 'verification_method' => $method_,
            'verification_note' => trim((string)($_POST['note'] ?? '')) ?: null, 'verification_expires' => $exp,
        ]);
        audit_log($ctx, 'document_verified', 'credential_documents', (string)$doc['id'],
            json_encode(['method' => $method_, 'credential_id' => $doc['credential_id']]));
        FlxCoreHC\ComplianceEventService::record($ctx, 'document_verified',
            'Document verified (' . str_replace('_', ' ', $method_) . '): ' . ($doc['original_name'] ?? 'document'),
            ['holder_id' => ($credRow['holder_id'] ?? null), 'credential_id' => (string)$doc['credential_id'],
             'document_id' => (string)$doc['id'], 'effective_on' => today_local(),
             'detail' => ['method' => $method_, 'note' => trim((string)($_POST['note'] ?? '')) ?: null]]);
        flash(t('Document marked verified ({method}).', ['method' => t(str_replace('_', ' ', $method_))]));
    } else {
        $documents->update((string)$doc['id'], ['verified_at' => null, 'verified_by' => null, 'verification_method' => null, 'verification_note' => null, 'verification_expires' => null]);
        audit_log($ctx, 'document_unverified', 'credential_documents', (string)$doc['id'], null);
        flash('Verification cleared.', 'ok');
    }
    redirect($back);
}
if ($seg[0] === 'documents' && isset($seg[1]) && $method === 'GET') {
    FlxCoreHC\Authz::requireCap($ctx, 'documents.view');
    $doc = $documents->find($seg[1]);          // tenant-scoped; cross-tenant id → null
    if ($doc === null || !is_file($doc['file_path'])) {
        http_response_code(404);
        exit('Document not found.');
    }
    // A producer may only read evidence attached to their OWN credentials. Documents are
    // tenant-scoped, so without this a producer holding a colleague's document id could
    // fetch it (licences, E&O certificates, personal identifiers).
    if ($__isPortalRole) {
        $ownCred = $doc['credential_id'] ? $credentials->find((string)$doc['credential_id']) : null;
        if (!$ownCred || ($ownCred['holder_id'] ?? null) !== $ctx->holderId) {
            http_response_code(404);
            exit('Document not found.');
        }
    }
    header('Content-Type: ' . ($doc['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . rawurlencode($doc['original_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    AccessLog::record($ctx, 'download', 'credential_documents', $doc['id']);
    if (!FlxCoreHC\EncryptedFile::send($doc['file_path'])) { http_response_code(404); }
    exit;
}

// ---- Team: tenant-level user management (owner/admin only) -------------------
if ($seg[0] === 'team') {
    if (!TeamService::canManage($ctx)) { http_response_code(403); exit('Forbidden — you do not have permission to manage users.'); }
    $team = new TeamService($ctx);
    if ($method === 'POST') {
        CsrfGuard::verify();
        $act = $_POST['action'] ?? 'create';
        $uid = (string) ($_POST['user_id'] ?? '');
        $link = null;
        switch ($act) {
            case 'add':
                // Direct create when a temp password is supplied; otherwise send an invite.
                if (trim((string)($_POST['password'] ?? '')) !== '') {
                    [$ok, $msg] = $team->create($_POST);
            if ($ok) audit_log($ctx, 'team_member_invited', 'users', null, json_encode(['email'=>$_POST['email']??null,'role'=>$_POST['role']??null]));
                    break;
                }
                // fall through to invite
            case 'invite':
                [$ok, $msg, $token] = $team->invite($_POST);
                if ($ok && $token) { $link = invite_link($token); send_invite_email((string) $_POST['email'], (string) $_POST['full_name'], $link, $ctx->tenantName); }
                break;
            case 'resend':
                [$ok, $msg, $token] = $team->resendInvite($uid);
                if ($ok && $token) { $link = invite_link($token); }
                break;
            case 'role':    [$ok, $msg] = $team->updateRole($uid, (string) ($_POST['role'] ?? 'staff')); break;
            case 'provider_access':
                $mode = (string) ($_POST['mode'] ?? 'all');
                $ok = FlxCoreHC\AssignmentService::setMode($ctx, $uid, $mode);
                if ($ok) audit_log($ctx, 'provider_access_mode', 'users', $uid, json_encode(['mode' => $mode]));
                $msg = $ok
                    ? ($mode === 'assigned' ? 'User now sees only their assigned providers.' : 'User now sees all providers.')
                    : 'Invalid access mode.';
                break;
            case 'update_user': [$ok, $msg] = $team->updateUser($uid, (string) ($_POST['full_name'] ?? ''), (string) ($_POST['email'] ?? '')); break;
            case 'disable': [$ok, $msg] = $team->setStatus($uid, 'suspended'); break;
            case 'enable':  [$ok, $msg] = $team->setStatus($uid, 'active'); break;
            case 'reset':   [$ok, $msg] = $team->resetPassword($uid, (string) ($_POST['password'] ?? '')); break;
            default:        $msg = 'Unknown action.';
        }
        flash($msg . ($link ? ' Invite link: ' . $link : ''), !empty($ok) ? 'ok' : 'error');
        redirect('/team');
    }
    view('team/index', ['ctx' => $ctx, 'users' => $team->users(), 'termsMap' => FlxCoreHC\TermsService::acceptanceMap($ctx->tenantId), 'termsVersion' => FlxCoreHC\TermsService::version(),
        'roleList'   => (new FlxCoreHC\RoleService($ctx))->assignable(),
        'holderList' => $holders->all(['holder_type' => 'individual'], 'last_name, first_name')]);
}

// ---- Platform administration (SaaS operator; cross-tenant, gated) -----------
// ---- Return from "Open as tenant" (impersonation) ----------------------------
if ($seg[0] === 'impersonate' && ($seg[1] ?? '') === 'return' && $method === 'POST') {
    CsrfGuard::verify();
    $saved = $_SESSION['admin_return'] ?? null;
    if (!is_array($saved) || empty($_SESSION['auth']['impersonated_by'])) {
        flash('No console session to return to.', 'error');
        redirect('/');
    }
    AccessLog::record($ctx, 'impersonate_return', 'tenants', $ctx->tenantId);
    if (!empty($_SESSION['auth']['support_request_id'])) {
        FlxCoreHC\SupportAccess::markEnded((string)$_SESSION['auth']['support_request_id']);
        audit_log($ctx, 'support_session_end', 'impersonation', (string)$_SESSION['auth']['support_request_id']);
    }
    session_regenerate_id(true);
    $_SESSION['auth'] = $saved;
    unset($_SESSION['admin_return']);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    flash('Back in the platform console.', 'ok');
    redirect('/admin/tenants');
}

if ($seg[0] === 'admin') {
    if (!$ctx->isPlatformAdmin) { http_response_code(403); exit('Forbidden — platform administrator access required.'); }
    // v1.171.0 (S-3): the console can reach every organization, so it requires two-factor sign-in. An operator
    // without 2FA is sent to their profile to enrol (profile / logout stay reachable, so nobody is locked out).
    // v1.172.0: the requirement is a platform setting (Console → Security). It is off on a new installation so the first
    // set-up is not blocked, and the platform administrator can turn it on at any time.
    if (FlxCoreHC\Auth\AuthService::platformRequires2fa() && !$ctx->twoFactor && empty($_SESSION['auth']['impersonated_by'])) {
        flash('Platform administrators must enable two-factor authentication before using the console.', 'warn');
        redirect('/profile');
    }
    // (v1.171.3: no mail is sent on a GET — the outbox is flushed by cron/send_reminders.php and by explicit POST actions.)
    $admin = new SuperadminService($ctx);
    $id  = $seg[1] ?? null;   // 'tenants' | 'tenant'
    $tid = $seg[2] ?? null;

    // Error-log actions are handled here, ahead of the generic POST dispatch below whose match()
    // would otherwise swallow them as an unknown action and redirect away.
    if ($id === 'users' && $method === 'POST') {
        CsrfGuard::verify();
        if ((string)($_POST['action'] ?? '') === 'add_viewer') {
            [$ok, $msg, $token] = $admin->addUserAsViewer((string)($_POST['tenant_id'] ?? ''), (string)($_POST['full_name'] ?? ''), (string)($_POST['email'] ?? ''));
            if ($ok) {
                AccessLog::record($ctx, 'admin_user_added_viewer', 'tenants', (string)($_POST['tenant_id'] ?? ''));
                if ($token) { try { send_invite_email((string)$_POST['email'], (string)$_POST['full_name'], invite_link($token), 'your organization'); } catch (\Throwable $e) {} }
            }
            flash($msg, $ok ? 'ok' : 'error');
        }
        redirect('/admin/users');
    }
    if ($id === 'roles' && $method === 'POST') {        CsrfGuard::verify();
        $ra = (string) ($_POST['action'] ?? '');
        $caps = array_values(array_filter((array)($_POST['caps'] ?? []), fn($c) => FlxCoreHC\Capabilities::isValid((string)$c)));
        if ($ra === 'create') {
            [$ok, $msg] = FlxCoreHC\RoleService::createGlobal((string)($_POST['name'] ?? ''), $caps, !empty($_POST['portal_only']));
            if ($ok) AccessLog::record($ctx, 'global_role_create', 'roles', null);
            flash($msg, $ok ? 'ok' : 'error');
        } elseif ($ra === 'update') {
            [$ok, $msg] = FlxCoreHC\RoleService::updateGlobal((string)($_POST['role_id'] ?? ''), (string)($_POST['name'] ?? ''), $caps);
            if ($ok) AccessLog::record($ctx, 'global_role_update', 'roles', (string)($_POST['role_id'] ?? ''));
            flash($msg, $ok ? 'ok' : 'error');
        } elseif ($ra === 'delete') {
            [$ok, $msg] = FlxCoreHC\RoleService::deleteGlobal((string)($_POST['role_id'] ?? ''));
            if ($ok) AccessLog::record($ctx, 'global_role_delete', 'roles', (string)($_POST['role_id'] ?? ''));
            flash($msg, $ok ? 'ok' : 'error');
        }
        redirect('/admin/roles');
    }
    if ($id === 'errors' && $method === 'POST') {
        CsrfGuard::verify();
        $ea = (string) ($_POST['action'] ?? '');
        if ($ea === 'resolve')         { FlxCoreHC\ErrorLogService::resolve((string) ($_POST['error_id'] ?? '')); flash('Marked handled.', 'ok'); }
        elseif ($ea === 'resolve_all') { $n = FlxCoreHC\ErrorLogService::resolve(null); flash($n . ' error(s) marked handled.'); }
        elseif ($ea === 'purge')       { $n = FlxCoreHC\ErrorLogService::purgeOlderThan(90); flash($n . ' entr(ies) older than 90 days removed.'); }
        redirect('/admin/errors');
    }

    if ($id === 'changelog') {
        view('admin/changelog', ['ctx' => $ctx, 'doc' => @file_get_contents(FLXCOREHC_ROOT . '/docs/CHANGELOG.md') ?: '',
            'pendingCount' => count($admin->pendingTenants())]);
    }
    if ($id === 'capabilities') {
        view('admin/doc_page', ['ctx' => $ctx, 'heading' => 'Master capabilities & features',
            'intro' => 'The complete map of what the platform does, with the Puerto Rico regulatory grounding behind each requirement.',
            'doc' => @file_get_contents(FLXCOREHC_ROOT . '/docs/CAPABILITIES.md') ?: '', 'file' => 'docs/CAPABILITIES.md',
            'pendingCount' => count($admin->pendingTenants())]);
    }
    if ($id === 'test-plan') {
        // The feature test & validation plan, rendered in-app for QA sessions.
        view('admin/doc_page', ['ctx' => $ctx, 'heading' => 'Feature test & validation plan',
            'intro' => 'A step-by-step plan covering every feature. Use it with the demo organizations; suites A, G, H, J and K are the go-live gate.',
            'doc' => @file_get_contents(FLXCOREHC_ROOT . '/docs/TEST_PLAN.md') ?: '',
            'file' => 'docs/TEST_PLAN.md', 'pendingCount' => count($admin->pendingTenants())]);
    }
    if ($id === 'demo-accounts') {
        // The demo credential sheet, rendered from the shipped doc so it can be handed to testers.
        $doc = @file_get_contents(FLXCOREHC_ROOT . '/docs/DEMO_ACCOUNTS.md') ?: '';
        $built = false;
        try { $built = FlxCoreHC\DemoHealthcareService::existingTenantId() !== null; } catch (\Throwable $e) {}
        view('admin/demo_accounts', ['ctx' => $ctx, 'doc' => $doc, 'built' => $built,
            'pendingCount' => count($admin->pendingTenants())]);
    }
    if ($id === 'tenant' && ($seg[2] ?? '') !== '' && in_array($seg[3] ?? '', ['report.pdf','report.zip'], true)) {
        $svc = new FlxCoreHC\TenantReportService($ctx);
        $r = $svc->assemble((string)$seg[2]);
        if (!$r) { http_response_code(404); exit('Tenant not found.'); }
        $safe = preg_replace('/[^A-Za-z0-9]+/', '-', (string)$r['tenant']['name']);
        audit_log($ctx, 'tenant_report_exported', 'tenants', (string)$seg[2], json_encode(['format' => $seg[3]]));
        if ($seg[3] === 'report.pdf') {
            header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="tenant-' . $safe . '-' . date('Y-m-d') . '.pdf"');
            echo $svc->pdf($r); exit;
        }
        header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="tenant-' . $safe . '-' . date('Y-m-d') . '.zip"');
        echo $svc->csvZip($r); exit;
    }
    if ($id === 'regulatory') {
        // Regulatory sources — the citations behind requirements. Platform-wide; edited by the operator.
        if ($method === 'POST') {
            CsrfGuard::verify();
            [$ok, $msg] = FlxCoreHC\RegulatorySources::save($_POST, $ctx->userId);
            audit_log($ctx, 'regulatory_source_saved', 'requirement_sources', null, json_encode(['code' => $_POST['code'] ?? null]));
            flash($msg, $ok ? 'ok' : 'error'); redirect('/admin/regulatory');
        }
        view('admin/regulatory', ['ctx' => $ctx, 'sources' => FlxCoreHC\RegulatorySources::all(), 'due' => FlxCoreHC\RegulatorySources::dueForReview(),
            'pendingCount' => count($admin->pendingTenants())]);
    }
    // ---- Licenses (platform operator): issue, renew and revoke the signed license of an organization
    if ($id === 'licenses') {
        if ($method === 'POST') {
            CsrfGuard::verify();
            if (($_POST['action'] ?? '') === 'revoke') {
                [$__ok, $__m] = FlxCoreHC\LicenseService::revoke($ctx, (string)($_POST['serial'] ?? ''));
                flash($__ok ? 'License ' . $__m . ' revoked.' : $__m, $__ok ? 'ok' : 'error');
            } else {
                [$__ok, $__m] = FlxCoreHC\LicenseService::issue($ctx, $_POST);
                flash($__ok ? 'License ' . $__m . ' issued.' : $__m, $__ok ? 'ok' : 'error');
            }
            FlxCoreHC\Entitlement::reset();
            redirect('/admin/licenses' . (!empty($_POST['tenant_id']) && ($_POST['action'] ?? '') !== 'revoke' ? '?tenant=' . urlencode((string)$_POST['tenant_id']) : ''));
        }
        $__sel = (string)($_GET['tenant'] ?? '');
        view('admin/licenses', ['ctx' => $ctx, 'rows' => FlxCoreHC\LicenseService::overview(), 'selected' => $__sel,
            'history' => $__sel !== '' ? FlxCoreHC\LicenseService::history($__sel) : [], 'plans' => FlxCoreHC\PlanService::all(), 'sodium' => FlxCoreHC\LicenseService::available()]);
    }
    // ---- Terms of Use editor (platform operator): every save publishes a NEW version that everyone accepts again
    if ($id === 'terms') {
        if ($method === 'POST') {
            CsrfGuard::verify();
            $__keepNow = ['text_en' => (string)($_POST['text_en'] ?? ''), 'text_es' => (string)($_POST['text_es'] ?? ''), 'draft_en' => (string)($_POST['draft_en'] ?? ''), 'draft_es' => (string)($_POST['draft_es'] ?? '')];
            if (empty($_POST['confirm_publish'])) { $_SESSION['terms_edit_keep'] = $__keepNow; flash('Tick the box to confirm that everyone will have to accept the new version.', 'error'); redirect('/admin/terms'); }
            [$__ok, $__m] = FlxCoreHC\TermsService::publish($ctx, $__keepNow['text_en'], $__keepNow['text_es'], $__keepNow['draft_en'], $__keepNow['draft_es']);
            if (!$__ok) { $_SESSION['terms_edit_keep'] = $__keepNow; flash($__m, 'error'); redirect('/admin/terms'); }
            flash(t('Published version {v}. Everyone accepts it at their next sign-in.', ['v' => $__m]), 'ok');
            redirect('/admin/terms');
        }
        $__keep = $_SESSION['terms_edit_keep'] ?? null; unset($_SESSION['terms_edit_keep']);
        view('admin/terms', ['ctx' => $ctx, 'version' => FlxCoreHC\TermsService::version(), 'history' => FlxCoreHC\TermsService::history(),
            'totals' => FlxCoreHC\TermsService::acceptanceTotals(),
            'text_en' => $__keep['text_en'] ?? FlxCoreHC\TermsService::editorText('en'), 'text_es' => $__keep['text_es'] ?? FlxCoreHC\TermsService::editorText('es'),
            'draft_en' => $__keep['draft_en'] ?? FlxCoreHC\TermsService::editorDraft('en'), 'draft_es' => $__keep['draft_es'] ?? FlxCoreHC\TermsService::editorDraft('es')]);
    }
    if ($id === 'database') {
        // Data dictionary + database health for the platform operator. Structure and counts only —
        // no tenant record contents are read here.
        if ($method === 'POST') {
            CsrfGuard::verify();
            $tbl = preg_replace('/[^a-z0-9_]/i', '', (string)($_POST['table'] ?? ''));
            if ($tbl !== '') {
                FlxCoreHC\SchemaDoctor::saveNote($tbl, (string)($_POST['note'] ?? ''), $ctx->userId);
                AccessLog::record($ctx, 'schema_note_saved', 'schema_notes', $tbl);
                flash('Note saved for ' . $tbl . '.');
            }
            redirect('/admin/database');
        }
        $h = FlxCoreHC\SchemaDoctor::health();
        view('admin/database', ['ctx' => $ctx, 'summary' => $h['summary'], 'checks' => $h['checks'],
            'dictionary' => $h['dictionary'], 'q' => trim((string)($_GET['q'] ?? '')),
            'pendingCount' => count($admin->pendingTenants())]);
    }
    if ($id === 'security') {
        // Security & code audit reference for the platform operator: the shipped audit report plus
        // live posture checks of THIS install (headers, config, protections) — no tenant data.
        $doc = @file_get_contents(FLXCOREHC_ROOT . '/docs/CODE_AUDIT_REPORT.md') ?: '';
        $checks = FlxCoreHC\SecurityPosture::checks();
        view('admin/security', ['ctx' => $ctx, 'report' => $doc, 'checks' => $checks,
            'platformAdmins' => $admin->platformAdmins(),
            'pendingCount' => count($admin->pendingTenants())]);
    }
    if ($id === 'translations') {
        $loc = preg_match('/^[a-z]{2}$/', $_GET['locale'] ?? $_POST['locale'] ?? '') ? ($_GET['locale'] ?? $_POST['locale']) : 'es';
        if ($method === 'POST') {
            CsrfGuard::verify();
            $n = 0;
            foreach ((array)($_POST['tr'] ?? []) as $hash => $pair) {
                $src = (string)($pair['source'] ?? ''); $tgt = (string)($pair['target'] ?? '');
                if ($src === '') continue;
                // Only write rows that actually changed vs. what was shown, to keep the audit meaningful.
                if (($pair['was'] ?? null) === $tgt) continue;
                FlxCoreHC\I18n::saveTranslation($loc, $src, $tgt, $ctx->userId); $n++;
            }
            AccessLog::record($ctx, 'ui_translations_saved', 'ui_translations', $loc);
            flash($n ? "{$n} translation(s) saved." : 'No changes.');
            $qstr = '/admin/translations?locale=' . $loc
                . (isset($_POST['q']) && $_POST['q'] !== '' ? '&q=' . urlencode((string)$_POST['q']) : '')
                . (!empty($_POST['missing']) ? '&missing=1' : '');
            redirect($qstr);
        }
        $q = trim((string)($_GET['q'] ?? '')); $onlyMissing = !empty($_GET['missing']);
        view('admin/translations', ['ctx' => $ctx, 'locale' => $loc, 'q' => $q, 'onlyMissing' => $onlyMissing,
            'groups' => FlxCoreHC\I18n::editorGroups($loc, $q, $onlyMissing), 'pendingCount' => count($admin->pendingTenants()),
            'skipOutputTranslate' => true]);
    }
    if ($method === 'POST') {
        CsrfGuard::verify();
        $act = $_POST['action'] ?? '';
        $targetTenant = (string) ($_POST['tenant_id'] ?? '');
        $targetUser   = (string) ($_POST['user_id'] ?? '');
        $link = null;
        if ($act === 'save_plan') {
            [$ok, $msg] = PlanService::save($_POST, ($_POST['plan_id'] ?? '') ?: null);
            flash($msg, $ok ? 'ok' : 'error'); redirect('/admin/plans');
        }
        if ($act === 'import_leie') {
            $month = trim((string)($_POST['file_month'] ?? ''));
            $csv = null; $note = null;
            if (($_POST['mode'] ?? '') === 'download') {
                $csv = FlxCoreHC\LeieService::download();
                $note = 'Downloaded from OIG';
                if ($csv === null) { flash('Could not download the LEIE file from OIG (the server may need outbound HTTPS, or the URL changed). Paste the CSV instead.', 'error'); redirect('/admin/leie'); }
                if ($month === '') $month = date('Y-m');
            } else {
                $csv = (string)($_POST['csv'] ?? '');
                $note = 'Pasted CSV';
                if (trim($csv) === '') { flash('Paste the LEIE CSV, or choose download.', 'error'); redirect('/admin/leie'); }
            }
            $r = FlxCoreHC\LeieService::ingestCsv($csv, $month, (string)$ctx->userId, $note);
            if (!empty($r['ok'])) {
                audit_log($ctx, 'leie_import', 'leie_imports', $r['import_id'] ?? null, json_encode(['month' => $r['file_month'] ?? $month, 'rows' => $r['rows'] ?? 0]));
                flash('LEIE list loaded: ' . number_format((int)($r['rows'] ?? 0)) . ' rows for ' . ($r['file_month'] ?? $month) . '.');
            } else {
                flash($r['error'] ?? 'Import failed.', 'error');
            }
            redirect('/admin/leie');
        }
        if ($act === 'import_medicaid') {
            $jur = strtoupper(trim((string)($_POST['jurisdiction'] ?? 'PR')));
            $month = trim((string)($_POST['file_month'] ?? '')) ?: date('Y-m');
            $csv = (string)($_POST['csv'] ?? '');
            if (trim($csv) === '') { flash('Paste the Medicaid exclusion CSV.', 'error'); redirect('/admin/leie'); }
            $r = FlxCoreHC\MedicaidExclusionService::ingestCsv($csv, $jur, $month, (string)$ctx->userId, 'Pasted CSV');
            if (!empty($r['ok'])) {
                audit_log($ctx, 'medicaid_import', 'medicaid_imports', $r['import_id'] ?? null, json_encode(['jurisdiction' => $jur, 'month' => $r['file_month'] ?? $month, 'rows' => $r['rows'] ?? 0]));
                flash(($r['jurisdiction'] ?? $jur) . ' Medicaid list loaded: ' . number_format((int)($r['rows'] ?? 0)) . ' rows for ' . ($r['file_month'] ?? $month) . '.');
            } else {
                flash($r['error'] ?? 'Import failed.', 'error');
            }
            redirect('/admin/leie');
        }
        if ($act === 'import_sam') {
            $month = trim((string)($_POST['file_month'] ?? ''));
            $csv = (string)($_POST['csv'] ?? '');
            if (trim($csv) === '') { flash('Paste the SAM.gov exclusions CSV.', 'error'); redirect('/admin/leie'); }
            if ($month === '') $month = date('Y-m');
            $r = FlxCoreHC\SamService::ingestCsv($csv, $month, (string)$ctx->userId, 'Pasted CSV');
            if (!empty($r['ok'])) {
                audit_log($ctx, 'sam_import', 'sam_imports', $r['import_id'] ?? null, json_encode(['month' => $r['file_month'] ?? $month, 'rows' => $r['rows'] ?? 0]));
                flash('SAM.gov list loaded: ' . number_format((int)($r['rows'] ?? 0)) . ' rows for ' . ($r['file_month'] ?? $month) . '.');
            } else {
                flash($r['error'] ?? 'Import failed.', 'error');
            }
            redirect('/admin/leie');
        }
        if ($act === 'sweep_leie') {
            $r = FlxCoreHC\LeieService::sweepAll();
            if (empty($r['loaded'])) {
                flash('No LEIE list is loaded yet — import the monthly OIG file first.', 'error');
            } else {
                FlxCoreHC\MailService::flush(500);
                audit_log($ctx, 'leie_sweep_all', 'leie_imports', null, json_encode($r));
                flash('LEIE sweep complete: ' . (int)$r['screened'] . ' provider(s) across ' . (int)$r['tenants']
                    . ' tenant(s) screened, ' . (int)$r['matches'] . ' potential match(es) flagged for review.');
            }
            redirect('/admin/leie');
        }
        if ($act === 'assign_plan') {
            [$ok, $msg] = PlanService::assign((string)($_POST['tenant_id'] ?? ''), (string)($_POST['plan_id'] ?? ''));
            flash($msg, $ok ? 'ok' : 'error'); redirect('/admin/tenant/' . (string)($_POST['tenant_id'] ?? ''));
        }
        if ($act === 'add_domain') {
            $tid = (string)($_POST['tenant_id'] ?? '');
            [$ok, $msg] = FlxCoreHC\DomainService::add($tid, (string)($_POST['host'] ?? ''), !empty($_POST['is_primary']));
            if ($ok) audit_log($ctx, 'domain_added', 'tenants', substr($tid, 0, 36),
                json_encode(['host' => FlxCoreHC\DomainService::normalize($_POST['host'] ?? '')]));
            flash($msg, $ok ? 'ok' : 'error');
            redirect('/admin/tenant/' . $tid);
        }
        if ($act === 'remove_domain') {
            $tid = (string)($_POST['tenant_id'] ?? '');
            $did = (string)($_POST['domain_id'] ?? '');
            $gone = FlxCoreHC\DomainService::remove($tid, $did);
            if ($gone) audit_log($ctx, 'domain_removed', 'tenants', substr($tid, 0, 36), json_encode(['id' => $did]));
            flash($gone ? 'Domain mapping removed.' : 'That domain mapping no longer exists.', $gone ? 'ok' : 'error');
            redirect('/admin/tenant/' . $tid);
        }
        if ($act === 'set_seat_override') {
            $tid = (string)($_POST['tenant_id'] ?? '');
            $ov  = ($_POST['seat_override'] ?? '') === '' ? null : max(1, (int) $_POST['seat_override']);
            FlxCoreHC\Database::pdo()->prepare("UPDATE tenants SET seat_override = ? WHERE id = ?")->execute([$ov, $tid]);
            audit_log($ctx, 'seat_override_set', 'tenants', substr($tid, 0, 36), json_encode(['seat_override' => $ov]));
            flash($ov === null ? 'Seat override cleared — using the plan limit.' : "Custom seat limit set to {$ov}.");
            redirect('/admin/tenant/' . $tid);
        }
        if ($act === 'invite_admin') {
            [$ok, $msg, $token] = $admin->invitePlatformAdmin($_POST);
            if ($ok && $token) { $link = invite_link($token); send_invite_email((string) $_POST['email'], (string) $_POST['full_name'], $link, 'Platform administration'); }
            flash($msg . ($link ? ' Invite link: ' . $link : ''), !empty($ok) ? 'ok' : 'error');
            redirect('/admin');
        }
        if ($act === 'create_invoice') {
            $items = [];
            foreach ((array) ($_POST['desc'] ?? []) as $i => $d) {
                $items[] = ['description' => $d,
                            'quantity' => ($_POST['qty'][$i] ?? 1),
                            'unit' => ($_POST['unit'][$i] ?? 0)];
            }
            [$ok, $msg, $iid] = FlxCoreHC\InvoiceService::create(
                (string) ($_POST['tenant_id'] ?? ''), $items,
                (string) ($_POST['due_date'] ?? '') ?: null,
                (string) ($_POST['notes'] ?? '') ?: null, $ctx->userId);
            flash($msg, $ok ? 'ok' : 'error');
            redirect($ok && $iid ? '/admin/invoices/' . $iid : '/admin/invoices');
        }
        if ($act === 'send_invoice') {
            [$ok, $msg] = FlxCoreHC\InvoiceService::send((string) ($_POST['invoice_id'] ?? ''));
            flash($msg, $ok ? 'ok' : 'error'); redirect('/admin/invoices/' . (string) ($_POST['invoice_id'] ?? ''));
        }
        if ($act === 'void_invoice') {
            FlxCoreHC\InvoiceService::void((string) ($_POST['invoice_id'] ?? ''));
            flash('Invoice voided.', 'ok'); redirect('/admin/invoices/' . (string) ($_POST['invoice_id'] ?? ''));
        }
        if ($act === 'record_payment') {
            [$ok, $msg] = FlxCoreHC\InvoiceService::recordPayment(
                (string) ($_POST['tenant_id'] ?? ''),
                (string) ($_POST['invoice_id'] ?? '') ?: null,
                (float) ($_POST['amount'] ?? 0),
                (string) ($_POST['method'] ?? 'other'),
                (string) ($_POST['reference'] ?? '') ?: null,
                (string) ($_POST['received_on'] ?? '') ?: null,
                (string) ($_POST['notes'] ?? '') ?: null, $ctx->userId);
            flash($msg, $ok ? 'ok' : 'error');
            $back = ($_POST['invoice_id'] ?? '') ? '/admin/invoices/' . $_POST['invoice_id']
                    : '/admin/ledger/' . (string) ($_POST['tenant_id'] ?? '');
            redirect($back);
        }
        if ($act === 'save_email_settings') {
            $keys = array_keys(FlxCoreHC\SettingsService::EMAIL_KEYS);
            $vals = [];
            foreach ($keys as $k) {
                // A blank or masked password means "keep the saved one" (the form never receives the real value).
                if ($k === 'smtp_pass' && in_array(trim((string) ($_POST['smtp_pass'] ?? '')), ['', '********'], true)) continue;
                if ($k === 'mail_enabled') { $vals[$k] = isset($_POST['mail_enabled']) ? '1' : '0'; continue; }
                if ($k === 'smtp_verify_tls') { $vals[$k] = isset($_POST['smtp_verify_tls']) ? '1' : '0'; continue; }
                $v = (string) ($_POST[$k] ?? FlxCoreHC\SettingsService::EMAIL_KEYS[$k]);
                $vals[$k] = ($k === 'smtp_pass') ? $v : trim($v);   // a password is kept exactly as typed
            }
            if (!in_array($vals['mail_transport'] ?? 'mail', ['smtp', 'mail'], true)) $vals['mail_transport'] = 'mail';
            if (!in_array($vals['smtp_secure'] ?? 'tls', ['tls', 'ssl', 'none'], true)) $vals['smtp_secure'] = 'tls';
            $vals['smtp_port'] = (string) max(1, min(65535, (int) ($vals['smtp_port'] ?? 587) ?: 587));
            FlxCoreHC\SettingsService::put($vals);
            audit_log($ctx, 'platform_mail_settings_saved', 'mail', 'platform', json_encode(['transport' => $vals['mail_transport'] ?? '', 'host' => $vals['smtp_host'] ?? '']));
            flash('Email settings saved. Use "Test connection" to check the login, then "Send test email".', 'ok'); redirect('/admin/email');
        }
        if ($act === 'test_mail_connection' || $act === 'send_test_email') {
            if ($act === 'send_test_email') {
                $to = trim((string) ($_POST['test_to'] ?? ''));
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { flash('Enter a valid test address.', 'error'); redirect('/admin/email'); }
                $res = FlxCoreHC\MailService::platformTest($to);
                $_SESSION['mail_test'] = ['what' => 'Test email to ' . $to] + $res;
            } else {
                $res = FlxCoreHC\MailService::platformProbe();
                $_SESSION['mail_test'] = ['what' => 'Connection test'] + $res;
            }
            audit_log($ctx, 'platform_mail_tested', 'mail', 'platform', json_encode(['ok' => $res['ok'], 'kind' => $act]));
            redirect('/admin/email');
        }
        if ($act === 'retry_failed_mail') {
            $n = FlxCoreHC\MailService::retryFailed();
            [$sent, $failed] = $n > 0 ? FlxCoreHC\MailService::flush(50) : [0, 0];
            flash($n === 0 ? 'No failed messages to retry.' : "Retried {$n} message(s): {$sent} sent, {$failed} failed.", ($n > 0 && $failed > 0) ? 'warn' : 'ok');
            redirect('/admin/email');
        }
        if ($act === 'flush_outbox') {
            [$sent, $failed] = FlxCoreHC\MailService::flush(50);
            flash("Outbox flushed — {$sent} sent, {$failed} failed/queued.", ((int)$failed > 0) ? 'warn' : 'ok');
            redirect('/admin/email');
        }
        if ($act === 'build_demo_hc' && FLXCOREHC_EDITION !== 'insurance') {
            try {
                [$dtid, $demail, $dpass] = FlxCoreHC\DemoHealthcareService::build();
                flash('Five demo organizations created with document requests, an external share (password: ' . FlxCoreHC\DemoHealthcareService::SHARE_PASSWORD . '), a consented cross-organization link, and multi-document credentials. Billing-company owner sign-in: ' . $demail . ' / ' . $dpass);
            } catch (\Throwable $e) {
                flash('Healthcare demo build failed: ' . $e->getMessage(), 'error');
            }
            redirect('/admin/system');
        }
        if ($act === 'remove_demo_hc') {
            flash(FlxCoreHC\DemoHealthcareService::teardownAll() ? 'Demo organizations removed.' : 'No demo organizations found.');
            redirect('/admin/system');
        }
        if ($act === 'ms_app_save') {
            FlxCoreHC\MicrosoftOAuth::saveApp((string)($_POST['ms_client_id'] ?? ''), (string)($_POST['ms_client_secret'] ?? ''), (string)($_POST['ms_secret_expires'] ?? ''));
            audit_log($ctx, 'm365_app_saved', 'platform_settings', null, null);
            flash('Microsoft app registration saved.', 'ok'); redirect('/admin/email');
        }
        if ($act === 'ms_connect_platform') {
            if (!FlxCoreHC\MicrosoftOAuth::configured()) { flash('Save the Microsoft app registration first.', 'error'); redirect('/admin/email'); }
            header('Location: ' . FlxCoreHC\MicrosoftOAuth::authorizeUrl('platform')); exit;
        }
        if ($act === 'ms_disconnect_platform') {
            FlxCoreHC\MicrosoftOAuth::disconnect('platform');
            audit_log($ctx, 'm365_disconnected', 'mail', 'platform', null);
            flash('Microsoft 365 disconnected for the platform sender.', 'ok'); redirect('/admin/email');
        }
        // v1.171.0 — encryption-key backup (passphrase-sealed download; never plain text, never e-mailed).
        if ($act === 'key_backup_download') {
            $__rlK = new FlxCoreHC\Auth\RateLimiter(); $__rlKey = 'keybackup|' . $ctx->userId;
            if ($__rlK->tooManyAttempts($__rlKey, 5, 900)) { flash('Too many attempts. Try again in 15 minutes.', 'error'); redirect('/admin/system'); }
            $__hash = FlxCoreHC\Database::pdo()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
            $__hash->execute([$ctx->userId]);
            $__ph = (string) $__hash->fetchColumn();
            $__dk = (string) config('crypto_data_key', ''); $__ik = (string) config('crypto_index_key', '');
            if ($__ph === '' || !password_verify((string)($_POST['current_password'] ?? ''), $__ph)) { $__rlK->record($__rlKey); flash('Your current password is incorrect.', 'error'); redirect('/admin/system'); }
            if ($__dk === '' || $__ik === '') { flash('No encryption keys are configured on this installation, so there is nothing to back up.', 'error'); redirect('/admin/system'); }
            if ($__pe = FlxCoreHC\KeyBackup::passphraseError((string)($_POST['key_passphrase'] ?? ''), (string)($_POST['key_passphrase2'] ?? ''))) { flash($__pe, 'error'); redirect('/admin/system'); }
            $__rlK->clear($__rlKey);
            $__blob = FlxCoreHC\KeyBackup::encrypt($__dk, $__ik, (string)$_POST['key_passphrase']);
            audit_log($ctx, 'key_backup_downloaded', 'platform_settings', null, json_encode(['fingerprint' => FlxCoreHC\KeyBackup::fingerprint($__dk, $__ik)]));
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="flxcorehc-keys-' . FlxCoreHC\KeyBackup::fingerprint($__dk, $__ik) . '.flxkeys"');
            header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
            echo $__blob; exit;
        }
        // v1.171.3 — database dump sealed with a passphrase (PBKDF2 + AES-256-GCM); restore via database/decrypt_dump.php.
        if ($act === 'db_backup_encrypted') {
            $__rlD = new FlxCoreHC\Auth\RateLimiter(); $__rlDk = 'dbbackup|' . $ctx->userId;
            if ($__rlD->tooManyAttempts($__rlDk, 5, 900)) { flash('Too many attempts. Try again in 15 minutes.', 'error'); redirect('/admin/system'); }
            if (!FlxCoreHC\Auth\AuthService::passwordMatches($ctx->userId, (string)($_POST['current_password'] ?? ''))) { $__rlD->record($__rlDk); flash('Your current password is incorrect.', 'error'); redirect('/admin/system'); }
            if ($__pe2 = FlxCoreHC\KeyBackup::passphraseError((string)($_POST['dump_passphrase'] ?? ''), (string)($_POST['dump_passphrase2'] ?? ''))) { flash($__pe2, 'error'); redirect('/admin/system'); }
            $__rlD->clear($__rlDk);
            $__sealed = FlxCoreHC\DumpSeal::seal(FlxCoreHC\BackupService::databaseDump(), (string) $_POST['dump_passphrase']);
            FlxCoreHC\BackupService::markTaken();
            audit_log($ctx, 'db_backup_downloaded', 'platform_settings', null, json_encode(['sealed' => true]));
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="flxcorehc-db-' . date('Y-m-d-His') . '.sql.gz.flxenc"');
            header('Content-Length: ' . strlen($__sealed)); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
            echo $__sealed; exit;
        }
        if ($act === 'key_backup_confirm') {
            FlxCoreHC\SettingsService::put(['keys_backup_confirmed_at' => utc_now()]);
            audit_log($ctx, 'key_backup_confirmed', 'platform_settings', null, null);
            flash('Thanks — the key backup is recorded as stored. The reminder is now hidden.', 'ok');
            redirect('/admin/system');
        }
        // v1.172.0 — sign-in policy for the platform: require two-factor for platform administrators; allow e-mailed codes.
        if ($act === 'signin_policy') {
            $__rlP = new FlxCoreHC\Auth\RateLimiter(); $__rlPk = 'signinpolicy|' . $ctx->userId;
            if ($__rlP->tooManyAttempts($__rlPk, 5, 900)) { flash('Too many attempts. Try again in 15 minutes.', 'error'); redirect('/admin/security'); }
            if (!FlxCoreHC\Auth\AuthService::passwordMatches($ctx->userId, (string) ($_POST['current_password'] ?? ''))) { $__rlP->record($__rlPk); flash('Your current password is incorrect.', 'error'); redirect('/admin/security'); }
            $__rlP->clear($__rlPk);
            $__req = !empty($_POST['platform_require_2fa']); $__eml = !empty($_POST['mfa_email_allowed']);
            // Turning the requirement ON while your own account has no second step would send you straight to your profile; ask first.
            if ($__req && !$ctx->twoFactor) { flash('Turn on two-factor for your own account first (Your profile), then require it for all platform administrators.', 'error'); redirect('/admin/security'); }
            $__was = ['platform_require_2fa' => FlxCoreHC\Auth\AuthService::platformRequires2fa() ? 1 : 0, 'mfa_email_allowed' => FlxCoreHC\Auth\AuthService::emailCodesAllowed() ? 1 : 0];
            FlxCoreHC\SettingsService::put(['platform_require_2fa' => $__req ? '1' : '0', 'mfa_email_allowed' => $__eml ? '1' : '0']);
            audit_log($ctx, 'signin_policy_set', 'platform_settings', null, json_encode(['from' => $__was, 'to' => ['platform_require_2fa' => $__req ? 1 : 0, 'mfa_email_allowed' => $__eml ? 1 : 0]]));
            flash('Sign-in policy saved.', 'ok');
            redirect('/admin/security');
        }
        if ($act === 'set_timezone') {
            $__tz = (string)($_POST['app_timezone'] ?? '');
            if (isset(flx_timezones()[$__tz])) { FlxCoreHC\SettingsService::put(['app_timezone' => $__tz]); audit_log($ctx, 'timezone_set', 'platform_settings', null, json_encode(['tz'=>$__tz])); flash('Timezone set to ' . $__tz . '.'); }
            else flash('Pick a valid timezone.', 'error');
            redirect('/admin/system');
        }
        if ($act === 'sync_templates') {
            $n = 0;
            try {
                foreach (FlxCoreHC\Database::pdo()->query("SELECT id FROM tenants WHERE vertical = 'healthcare'")->fetchAll() as $__t) {
                    FlxCoreHC\Auth\AuthService::seedTemplatesFor((string) $__t['id']); $n++;
                }
            } catch (\Throwable $e) {}
            audit_log($ctx, 'templates_synced', 'tenants', null, json_encode(['tenants' => $n]));
            flash('Templates, regulators and roles synced for ' . $n . ' healthcare organization(s).');
            redirect('/admin/system');
        }
        if ($act === 'set_sam_key') {
            FlxCoreHC\VerificationService::setSamKey((string)($_POST['sam_api_key'] ?? ''));
            audit_log($ctx, 'sam_key_set', 'platform_settings', null, null);
            flash(trim((string)($_POST['sam_api_key'] ?? '')) === '' ? 'SAM.gov key cleared.' : 'SAM.gov API key saved.');
            redirect('/admin/system');
        }
        if ($act === 'run_migrations') {
            $report = FlxCoreHC\Migrator::run();
            $okAll = array_reduce($report, fn($c,$r)=>$c && $r['ok'], true);
            $applied = count(array_filter($report, fn($r)=>$r['ok']));
            $fail = array_values(array_filter($report, fn($r)=>!$r['ok']));
            // Backfill: ensure every tenant has its system roles seeded (idempotent). This
            // equips existing organizations the first time the roles engine lands.
            if ($okAll) {
                try {
                    foreach (FlxCoreHC\Database::pdo()->query('SELECT id FROM tenants')->fetchAll() as $__t) {
                        FlxCoreHC\RoleService::seedFor((string) $__t['id']);
                    }
                    // Also equip healthcare tenants (including the operator's own platform account,
                    // now flipped to healthcare by migration 100) with templates, regulators and
                    // calendar types if they never got them. Idempotent.
                    foreach (FlxCoreHC\Database::pdo()->query("SELECT id FROM tenants WHERE vertical = 'healthcare'")->fetchAll() as $__t) {
                        FlxCoreHC\Auth\AuthService::seedTemplatesFor((string) $__t['id']);
                    }
                } catch (\Throwable $e) { /* non-fatal */ }
            }
            $__skipped = array_sum(array_map(fn($r) => (int)($r['skipped'] ?? 0), $report));   // v1.171.0 (B-3): never silent
            flash($okAll ? (($applied ? "Database updated — {$applied} migration(s) applied." : 'Database already up to date.')
                            . ($__skipped ? " {$__skipped} statement(s) were already in place and skipped." : ''))
                         : ('Update stopped at ' . ($fail[0]['file'] ?? '?') . ': ' . ($fail[0]['error'] ?? 'error')));
            redirect('/admin/system');
        }
        if ($act === 'baseline_migrations') {
            $n = FlxCoreHC\Migrator::baseline();
            flash("Marked {$n} migration(s) as already applied (baseline).");
            redirect('/admin/system');
        }
        if ($act === 'create_user') {
            [$ok, $msg, $token] = $admin->createUserInTenant($targetTenant, $_POST);
            if ($ok && $token) { $link = invite_link($token); send_invite_email((string) $_POST['email'], (string) $_POST['full_name'], $link, 'Your FlxCoreHC organization'); }
            flash($msg . ($link ? ' Invite link: ' . $link : ''), !empty($ok) ? 'ok' : 'error');
            redirect('/admin/tenant/' . $targetTenant);
        }
        if ($act === 'create_org') {
            [$ok, $msg, $token] = $admin->createOrganization($_POST);
            if ($ok && $token) { $link = invite_link($token); send_invite_email((string) $_POST['owner_email'], (string) $_POST['owner_name'], $link, (string) $_POST['org_name']); }
            flash($msg . ($link ? ' Owner invite link: ' . $link : ''), !empty($ok) ? 'ok' : 'error');
            redirect('/admin');
        }
        if ($act === 'request_support') {
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($reason === '') { flash('A reason is required — the tenant\'s Master Admin sees it when approving.', 'error'); redirect('/admin/tenant/' . $targetTenant); }
            $rid = FlxCoreHC\SupportAccess::request($ctx, $targetTenant, $reason);
            // dual log: platform + tenant trail (write the tenant-side row directly with the target tenant id)
            AccessLog::record($ctx, 'support_requested', 'tenants', $targetTenant);
            FlxCoreHC\Database::pdo()->prepare('INSERT INTO audit_logs (id, tenant_id, user_id, action, entity_type, entity_id, changes, ip_address, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([uuid4(), $targetTenant, $ctx->userId, 'support_requested', 'impersonation', $rid, json_encode(['by' => $ctx->userName, 'reason' => $reason]), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), utc_now()]);
            flash('Support access requested. The tenant\'s Master Admin must approve before you can enter.', 'ok');
            redirect('/admin/tenant/' . $targetTenant);
        }
        if ($act === 'impersonate') {
            // Demo tenants carry no real PHI, so skip the consent-gated support flow entirely: the
            // platform admin enters directly with full access, just like signing in as the owner.
            $__isDemo = false;
            try {
                $__dq = FlxCoreHC\Database::pdo()->prepare('SELECT is_demo FROM tenants WHERE id = ? LIMIT 1');
                $__dq->execute([$targetTenant]);
                $__isDemo = (bool) $__dq->fetchColumn();
            } catch (\Throwable $e) {}

            if ($__isDemo) {
                $owner = $admin->ownerRowFor($targetTenant);
                if (!$owner) { flash('That demo organization has no active owner account.', 'error'); redirect('/admin/tenant/' . $targetTenant); }
                AccessLog::record($ctx, 'demo_impersonate', 'tenants', $targetTenant);
                $saved = $_SESSION['auth'];
                $auth->establishSession($owner);
                $_SESSION['admin_return'] = $saved;
                $_SESSION['auth']['impersonated_by'] = $saved['user_id'];
                // No support_mode: this is a full demo session, not a PHI-blind support window.
                flash('Opened the demo organization with full access. Use “Return to console” when done.', 'ok');
                redirect('/');
            }

            // Consent-gated: entry requires an ACTIVE approved grant (4-hour window) for THIS admin.
            $grant = FlxCoreHC\SupportAccess::activeGrant($targetTenant, (string)$ctx->userId);
            if (!$grant) { flash('No active approval. Request support access and wait for the tenant\'s Master Admin to approve (each approval is a 4-hour window).', 'error'); redirect('/admin/tenant/' . $targetTenant); }
            $owner = $admin->ownerRowFor($targetTenant);
            if (!$owner) { flash('That organization has no active owner account.', 'error'); redirect('/admin/tenant/' . $targetTenant); }
            FlxCoreHC\SupportAccess::markEntered((string)$grant['id']);
            AccessLog::record($ctx, 'support_session_start', 'tenants', $targetTenant);
            FlxCoreHC\Database::pdo()->prepare('INSERT INTO audit_logs (id, tenant_id, user_id, action, entity_type, entity_id, changes, ip_address, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([uuid4(), $targetTenant, $ctx->userId, 'support_session_start', 'impersonation', (string)$grant['id'],
                    json_encode(['admin' => $ctx->userName, 'approved_admin_id' => $grant['requested_by'], 'window_start' => $grant['decided_at'], 'expires' => $grant['expires_at']]), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), utc_now()]);
            $saved = $_SESSION['auth'];
            $auth->establishSession($owner);
            $_SESSION['admin_return'] = $saved;
            $_SESSION['auth']['impersonated_by'] = $saved['user_id'];
            $_SESSION['auth']['support_mode'] = true;
            $_SESSION['auth']['support_request_id'] = (string)$grant['id'];
            flash('Support session open until ' . substr((string)$grant['expires_at'], 0, 16) . ' UTC — settings and training only.');
            redirect('/');
        }
        if ($act === 'reset_password') {
            [$ok, $msg] = $admin->resetUserPassword($targetTenant, $targetUser, (string) ($_POST['new_password'] ?? ''));
            flash($msg, $ok ? 'ok' : 'error');
            redirect('/admin/tenant/' . $targetTenant);
        }
        if ($act === 'update_tenant') {
            [$ok, $msg] = $admin->updateTenantProfile($targetTenant, $_POST);
            flash($msg, $ok ? 'ok' : 'error');
            redirect('/admin/tenant/' . $targetTenant);
        }
        [$ok, $msg] = match ($act) {
            'suspend_tenant'  => $admin->setTenantStatus($targetTenant, 'suspended', (string) ($_POST['reason'] ?? '')),
            'activate_tenant' => $admin->setTenantStatus($targetTenant, 'active'),
            'approve_tenant'  => $admin->setTenantStatus($targetTenant, 'active'),
            'reject_tenant'   => $admin->setTenantStatus($targetTenant, 'rejected', (string) ($_POST['reason'] ?? '')),
            'disable_user'    => $admin->setUserStatus($targetTenant, $targetUser, 'suspended'),
            'enable_user'     => $admin->setUserStatus($targetTenant, $targetUser, 'active'),
            'set_user_role'   => $admin->setUserRole($targetTenant, $targetUser, (string)($_POST['role'] ?? '')),
            'grant_admin'     => $admin->setPlatformAdmin($targetTenant, $targetUser, true),
            'revoke_admin'    => $admin->setPlatformAdmin($targetTenant, $targetUser, false),
            default           => [false, 'Unknown action.'],
        };
        flash($msg, $ok ? 'ok' : 'error');
        if (in_array($act, ['approve_tenant', 'reject_tenant'], true)) { redirect('/admin/approvals'); }
        redirect($targetTenant && $act !== 'suspend_tenant' && $act !== 'activate_tenant' ? '/admin/tenant/' . $targetTenant : '/admin/tenants');
    }

    if ($id === null || $id === '') { redirect('/admin/tenants'); }
    if ($id === 'tenants') {
        view('admin/tenants', ['ctx' => $ctx, 'metrics' => $admin->metrics(),
            'tenants' => $admin->tenants($_GET['q'] ?? null, $_GET['status'] ?? null),
            'q' => (string) ($_GET['q'] ?? ''), 'statusF' => (string) ($_GET['status'] ?? ''),
            'pendingCount' => count($admin->pendingTenants())]);
    }
    if ($id === 'approvals') {
        view('admin/approvals', ['ctx' => $ctx, 'rows' => $admin->pendingTenants(),
            'pendingCount' => null]);
    }
    if ($id === 'users') {
        $q = (string) ($_GET['q'] ?? '');
        view('admin/users', ['ctx' => $ctx, 'q' => $q,
            'rows' => $q !== '' ? $admin->searchUsers($q) : [],
            'groups' => $admin->allUsersByTenant($q !== '' ? $q : null),
            'tenants' => $admin->tenants(),
            'pendingCount' => count($admin->pendingTenants())]);
    }
    if ($id === 'backup' && ($seg[2] ?? '') === 'database') {
        $bytes = FlxCoreHC\BackupService::databaseDump();
        FlxCoreHC\BackupService::markTaken();
        $fname = 'flxcorehc-db-' . date('Y-m-d-His') . '.sql.gz';
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        echo $bytes; exit;
    }
    if ($id === 'backup' && ($seg[2] ?? '') === 'documents') {
        $bytes = FlxCoreHC\BackupService::documentsArchive();
        FlxCoreHC\BackupService::markTaken();
        $fname = 'flxcorehc-docs-' . date('Y-m-d-His') . '.tar.gz';
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        echo $bytes; exit;
    }
    if ($id === 'email') {
        // v1.171.5: viewing this page never sends mail. Queued mail is sent by cron (send_reminders.php) or the
        // explicit "Send queued now" action below.
        $cfg = FlxCoreHC\SettingsService::emailConfig();
        $cfg['smtp_pass'] = $cfg['smtp_pass'] !== '' ? '********' : '';   // never echo the real pass
        $mailTest = $_SESSION['mail_test'] ?? null; unset($_SESSION['mail_test']);
        // The outbox may not exist yet if migration 048 is pending — degrade to empty.
        $outbox = [];
        $emailReady = true;
        try {
            $outbox = FlxCoreHC\Database::pdo()->query(
                "SELECT * FROM email_outbox ORDER BY created_at DESC LIMIT 30")->fetchAll();
        } catch (\Throwable $e) { $emailReady = false; }
        view('admin/email', ['ctx' => $ctx, 'cfg' => $cfg, 'outbox' => $outbox, 'mailTest' => $mailTest,
            'presets' => FlxCoreHC\TenantMailService::PRESETS,
            'emailReady' => $emailReady,
            'msClientId' => FlxCoreHC\MicrosoftOAuth::clientId(), 'msSecretExpires' => FlxCoreHC\MicrosoftOAuth::secretExpires(), 'msSecretDays' => FlxCoreHC\MicrosoftOAuth::secretDaysLeft(), 'msConfigured' => FlxCoreHC\MicrosoftOAuth::configured(),
            'msAccount' => FlxCoreHC\MicrosoftOAuth::platformAccount(), 'msRedirect' => FlxCoreHC\MicrosoftOAuth::redirectUri(),
            'pendingCount' => count($admin->pendingTenants())]);
    }
    if ($id === 'invoices') {
        $iid = $seg[2] ?? null;
        if ($iid === 'new') {
            view('admin/invoice_form', ['ctx' => $ctx, 'tenants' => $admin->tenants(),
                'preTenant' => (string) ($_GET['tenant'] ?? ''),
                'pendingCount' => count($admin->pendingTenants())]);
        }
        if ($iid !== null && ($seg[3] ?? '') === 'pdf') {
            $invoice = FlxCoreHC\InvoiceService::find($iid);
            if (!$invoice) { http_response_code(404); exit('Invoice not found.'); }
            $bytes = FlxCoreHC\InvoiceService::pdf($iid, ['name' => 'FlxCoreHC']);
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $invoice['number'] . '.pdf"');
            header('X-Content-Type-Options: nosniff');
            echo $bytes; exit;
        }
        if ($iid !== null && $iid !== '' && $iid !== 'new') {
            $invoice = FlxCoreHC\InvoiceService::find($iid);
            if (!$invoice) { http_response_code(404); exit('Invoice not found.'); }
            view('admin/invoice', ['ctx' => $ctx, 'invoice' => $invoice,
                'items' => FlxCoreHC\InvoiceService::items($iid),
                'payments' => FlxCoreHC\InvoiceService::paymentsFor($iid),
                'methods' => FlxCoreHC\InvoiceService::METHODS,
                'pendingCount' => count($admin->pendingTenants())]);
        }
        view('admin/invoices', ['ctx' => $ctx, 'invoices' => FlxCoreHC\InvoiceService::all(),
            'summary' => FlxCoreHC\InvoiceService::summary(),
            'pendingCount' => count($admin->pendingTenants())]);
    }
    if ($id === 'ledger' && ($seg[2] ?? '') !== '') {
        $tid2 = $seg[2];
        $detail = $admin->tenantDetail($tid2);
        if (!$detail) { http_response_code(404); exit('Client not found.'); }
        view('admin/ledger', ['ctx' => $ctx, 'tenant' => $detail['tenant'] ?? ['id' => $tid2, 'name' => ''],
            'ledger' => FlxCoreHC\InvoiceService::ledger($tid2),
            'invoices' => FlxCoreHC\InvoiceService::all($tid2),
            'methods' => FlxCoreHC\InvoiceService::METHODS,
            'pendingCount' => count($admin->pendingTenants())]);
    }
    if ($id === 'platform') {
        view('admin/platform', ['ctx' => $ctx, 'plans' => PlanService::all(),
            'pendingCount' => count($admin->pendingTenants())]);
    }
    if ($id === 'billing') {
        view('admin/billing', ['ctx' => $ctx, 'o' => $admin->billingOverview()]);
    }
    if ($id === 'leie') {
        $imp = FlxCoreHC\LeieService::latestImport();
        $total = $imp ? (int) FlxCoreHC\Database::pdo()->query('SELECT COUNT(*) FROM leie_exclusions')->fetchColumn() : 0;
        $samImp = FlxCoreHC\SamService::latestImport();
        $samTotal = $samImp ? (int) FlxCoreHC\Database::pdo()->query('SELECT COUNT(*) FROM sam_exclusions')->fetchColumn() : 0;
        $medicaid = FlxCoreHC\MedicaidExclusionService::loadedJurisdictions();
        view('admin/leie', ['ctx' => $ctx, 'import' => $imp, 'total' => $total, 'samImport' => $samImp, 'samTotal' => $samTotal, 'medicaid' => $medicaid]);
    }
    if ($id === 'roles') {
        view('admin/roles', ['ctx' => $ctx, 'roles' => FlxCoreHC\RoleService::globals(),
            'catalog' => FlxCoreHC\Capabilities::CATALOG, 'seeded' => array_keys(FlxCoreHC\RoleService::GLOBAL_ROLES),
            'edit' => $tid ? FlxCoreHC\RoleService::findGlobal($tid) : null,
            'pendingCount' => count($admin->pendingTenants())]);
    }
    if ($id === 'plans') {
        view('admin/plans', ['ctx' => $ctx, 'plans' => PlanService::all(), 'features' => PlanService::FEATURES,
            'edit' => $tid ? PlanService::find($tid) : null]);
    }
    if ($id === 'tenant' && $tid) {
        $detail = $admin->tenantDetail($tid);
        if (!$detail) { http_response_code(404); exit('Tenant not found.'); }
        view('admin/tenant', ['ctx' => $ctx, 'd' => $detail, 'plans' => PlanService::all(),
            'domains' => FlxCoreHC\DomainService::forTenant((string) $detail['tenant']['id']),
            'usage' => $admin->tenantUsage((string) $detail['tenant']['id']),
            'pendingCount' => count($admin->pendingTenants())]);
    }
    if ($id === 'audit') {   // v1.172.4: cross-organization audit log, platform administrators only (the router already requires it)
        $af = array_filter([
            'tenant_id' => preg_match('/^[0-9a-f-]{36}$/i', (string) ($_GET['tenant_id'] ?? '')) ? (string) $_GET['tenant_id'] : null,
            'action'    => trim((string) ($_GET['action'] ?? '')) ?: null,
            'from'      => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : null,
            'to'        => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : null,
            'q'         => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80) ?: null,
            'staff'     => ($_GET['staff'] ?? '') === '1' ? '1' : null,
        ]);
        if (($seg[2] ?? '') === 'export.csv') {
            audit_log($ctx, 'platform_audit_export', 'audit_logs', null, json_encode($af));
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="platform-audit-' . date('Y-m-d') . '.csv"');
            header('X-Content-Type-Options: nosniff');
            $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
            flx_fputcsv($out, ['When (UTC)', 'Organization', 'Who', 'E-mail', 'Platform admin', 'Action', 'Record type', 'Changes (platform admins only)', 'IP']);
            $off = 0;
            do {
                $batch = $admin->auditPage($af, 500, $off);
                foreach ($batch as $a) {
                    flx_fputcsv($out, csv_safe_row([$a['created_at'], $a['tenant_name'], $a['user_name'] ?? 'System', $a['user_email'] ?? '', $a['is_staff'] ? 'yes' : '',
                        str_replace('_', ' ', (string) $a['action']), (string) $a['entity_type'], (string) ($a['changes'] ?? ''), (string) ($a['ip_address'] ?? '')]));
                }
                $off += 500;
            } while (count($batch) === 500 && $off < 50000);
            fclose($out); exit;
        }
        $apage = max(1, (int) ($_GET['page'] ?? 1)); $aper = 100;
        $atotal = $admin->auditCount($af);
        view('admin/audit', ['ctx' => $ctx, 'rows' => $admin->auditPage($af, $aper, ($apage - 1) * $aper), 'filter' => $af,
            'tenants' => $admin->auditTenants(), 'actions' => $admin->auditActions(),
            'total' => $atotal, 'page' => $apage, 'per' => $aper, 'pages' => (int) ceil($atotal / $aper),
            'pendingCount' => count($admin->pendingTenants())]);
        exit;
    }
    if ($id === 'errors') {
        $unresolvedOnly = ($_GET['all'] ?? '') !== '1';
        view('admin/errors', [
            'ctx'        => $ctx,
            'errors'     => FlxCoreHC\ErrorLogService::recent(200, $unresolvedOnly),
            'unresolved' => FlxCoreHC\ErrorLogService::unresolvedCount(),
            'showingOpen'=> $unresolvedOnly,
            'pendingCount' => count($admin->pendingTenants()),
        ]);
        exit;
    }
    if ($id === 'system') {
        $ext = get_loaded_extensions();
        $rewrite = function_exists('apache_get_modules') ? in_array('mod_rewrite', apache_get_modules(), true) : null;
        $diag = [
            'php'        => PHP_VERSION,
            'db'         => config('db_name', ''),
            'base_path'  => BASE_PATH === '' ? '(web root)' : BASE_PATH,
            'rewrite'    => $rewrite,
            'ext_pdo'    => in_array('pdo_mysql', $ext, true),
            'ext_mbstr'  => in_array('mbstring', $ext, true),
            'ext_curl'   => in_array('curl', $ext, true),
            'ext_sodium' => in_array('sodium', $ext, true),
            'uploads_w'  => is_writable(FLXCOREHC_ROOT . '/uploads') || @mkdir(FLXCOREHC_ROOT . '/uploads', 0755, true),
            'storage_w'  => is_writable(FLXCOREHC_APP . '/storage'),
            'mail'       => (bool) config('mail_enabled', false),
            'stripe'     => FlxCoreHC\BillingService::isConfigured(),
            'tz'         => app_tz()->getName(),
        ];
        view('admin/system', ['ctx' => $ctx, 'status' => FlxCoreHC\Migrator::status(), 'diag' => $diag, 'demoHcTenantId' => FlxCoreHC\DemoHealthcareService::existingTenantId(), 'lastBackupDays' => FlxCoreHC\BackupService::daysSinceBackup(), 'samConfigured' => FlxCoreHC\VerificationService::samKey() !== '', 'pendingCount' => count($admin->pendingTenants())]);
    }
    redirect('/admin/tenants');
}

// ---- Scheduling (authenticated management; owner/manager only) ---------------

// ---- Compliance reports & audit-readiness -----------------------------------
if ($seg[0] === 'reports') {
    $planYear = (int) date('Y');   // v1.170.8: calendar year (was the Medicare plan-year rollover in June)
    $sub = $seg[1] ?? null;
    if ($method === 'GET' && $sub === null) {
        $__rd = flx_compliance_data($holders, $credentials, $producerReqs, $regulatorReqs, $regulators,
            (string)($__trow['org_shape'] ?? 'agency'), $__tlines, $documents, $__tstates, $holderRegs);
        view('reports/index', ['ctx'=>$ctx, 'rollup'=>$riskScore->rollupFromCompliance($__rd['producers']), 'planYear'=>$planYear]);
    }
    if ($method === 'GET' && $sub === 'readiness' && ($seg[2] ?? '') === 'export') {
        FlxCoreHC\Authz::requireCap($ctx, 'audit.view');
        // Compliance readiness rollup: one row per provider, then aggregates by client group /
        // department and by location — the report a billing company hands to a client or an auditor.
        $__rd = flx_compliance_data($holders, $credentials, $producerReqs, $regulatorReqs, $regulators,
            (string)($__trow['org_shape'] ?? 'agency'), $__tlines, $documents, $__tstates, $holderRegs);
        $byHolder = [];
        foreach ($__rd['producers'] as $p) {
            $h = $p['holder'];
            $byHolder[$h['id']] = ['name' => FlxCoreHC\Repository\HolderRepository::displayName($h), 'type' => $h['provider_type'] ?? '',
                'items' => (int)($p['total'] ?? 0), 'active' => (int)($p['active'] ?? 0), 'pct' => (int)($p['pct'] ?? 0), 'score' => $p['score'] ?? null];
        }
        // memberships for aggregation
        $gm = []; $lm = [];
        foreach ((new FlxCoreHC\Repository\GroupRepository($ctx))->active() as $g) { foreach (FlxCoreHC\GroupService::holderIdsIn($ctx, $g['id']) as $hid) $gm[$g['name']][] = $hid; }
        foreach ((new FlxCoreHC\Repository\LocationRepository($ctx))->active() as $l) { foreach (FlxCoreHC\LocationService::holderIdsAt($ctx, $l['id']) as $hid) $lm[$l['name']][] = $hid; }
        $agg = function (array $ids) use ($byHolder): array {
            $n = 0; $items = 0; $active = 0; $sum = 0;
            foreach ($ids as $id) { if (!isset($byHolder[$id])) continue; $n++; $items += $byHolder[$id]['items']; $active += $byHolder[$id]['active']; $sum += $byHolder[$id]['pct']; }
            return [$n, $items, $active, $n ? (int)round($sum / $n) : 0];
        };
        audit_log($ctx, 'report_export', 'readiness', null, null);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="readiness-report-' . date('Y-m-d') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
        flx_fputcsv($out, ['Section', 'Name', 'Provider type / members', 'Required items', 'On file (active)', 'Readiness %']);
        flx_fputcsv($out, csv_safe_row(['Organization', $ctx->tenantName, count($byHolder) . ' providers', (int)($__rd['totals']['items'] ?? 0), (int)($__rd['totals']['active'] ?? 0), (int)($__rd['totals']['pct'] ?? 0)]));
        $glabel = FlxCoreHC\GroupService::label($ctx->orgShape);
        foreach ($gm as $gname => $ids) { [$n, $it, $ac, $pct] = $agg($ids); flx_fputcsv($out, csv_safe_row([$glabel, $gname, $n . ' providers', $it, $ac, $pct])); }
        foreach ($lm as $lname => $ids) { [$n, $it, $ac, $pct] = $agg($ids); flx_fputcsv($out, csv_safe_row(['Location', $lname, $n . ' providers', $it, $ac, $pct])); }
        foreach ($byHolder as $r) { flx_fputcsv($out, csv_safe_row(['Provider', $r['name'], $r['type'], $r['items'], $r['active'], $r['pct']])); }
        fclose($out);
        exit;
    }
    if ($method === 'GET' && ($sub === 'credentials')) {
        $f = [
            'status'        => (string)($_GET['status'] ?? ''),
            'type'          => (string)($_GET['type'] ?? ''),
            'holder_id'     => (string)($_GET['holder_id'] ?? ''),
            'provider_type' => (string)($_GET['provider_type'] ?? ''),
            'window_days'   => (string)($_GET['window_days'] ?? ''),
            'has_docs'      => (string)($_GET['has_docs'] ?? ''),
        ];
        $rows = $credentials->report($f);
        if (($seg[2] ?? '') === 'export') {
            // CSV mirrors the on-screen rows exactly. Cells are formula-escaped so a value
            // beginning with = + - @ can't execute when opened in a spreadsheet.
            $esc = fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : (string)$v;
            $out = fopen('php://temp', 'r+');
            flx_fputcsv($out, ['Provider','Entity type','Provider type','Credential','Type','Number','Issued','Expires','Days left','Status','Documents']);
            $__labels = FlxCoreHC\CatalogService::labelsFor($ctx->tenantId);
            foreach ($rows as $r) {
                $who = $r['holder_type'] === 'organization' ? (string)$r['org_name']
                     : trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                flx_fputcsv($out, [
                    $esc($who), $r['holder_type'], (string)($r['provider_type'] ?? ''),
                    $esc((string)$r['name']),
                    $esc($__labels[$r['credential_type']] ?? (string)$r['credential_type']),
                    $esc((string)($r['credential_number'] ?? '')),
                    (string)($r['issued_date'] ?? ''), (string)($r['expiry_date'] ?? ''),
                    $r['days_left'] !== null ? (string)(int)$r['days_left'] : '',
                    (string)$r['status'], (string)(int)$r['doc_count'],
                ]);
            }
            rewind($out); $csv = stream_get_contents($out); fclose($out);
            audit_log($ctx, 'report_export', 'credentials', null, json_encode(array_filter($f)));
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="credential-report-' . date('Y-m-d') . '.csv"');
            header('X-Content-Type-Options: nosniff');
            echo $csv; exit;
        }
        $typeOptions = FlxCoreHC\CatalogService::labelsFor($ctx->tenantId);
        $holderOptions = [];
        foreach ($holders->all([], 'holder_type DESC, org_name, last_name, first_name') as $h) {
            $holderOptions[$h['id']] = $h['holder_type'] === 'organization'
                ? (string)$h['org_name'] : trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? ''));
        }
        view('reports/credentials', ['ctx'=>$ctx, 'rows'=>$rows, 'f'=>$f,
            'typeOptions'=>$typeOptions, 'holderOptions'=>$holderOptions]);
    }
    if ($method === 'GET' && $sub === 'audit') {
        $holderId = $seg[2] ?? null;
        $holder = $holderId ? $holders->find($holderId) : null;
        if (!$holder) { http_response_code(404); exit('Not found.'); }
        $__ad = flx_compliance_data($holders, $credentials, $producerReqs, $regulatorReqs, $regulators,
            (string)($__trow['org_shape'] ?? 'agency'), $__tlines, $documents, $__tstates, $holderRegs);
        $__me = null;
        foreach ($__ad['producers'] as $__p) { if (($__p['holder']['id'] ?? null) === $holder['id']) { $__me = $__p; break; } }
        $score = $__me ? $riskScore->scoreFromCompliance($__me) : $riskScore->scoreFromCompliance(['rows'=>[], 'total'=>0, 'score'=>null]);
        // merge compliance items with their per-tenant status
        $statusMap = $compliance->statusMap();
        $complianceRows = array_map(function ($it) use ($statusMap) {
            return $it + ['status' => $statusMap[$it['id']]['status'] ?? 'not_started'];
        }, $compliance->applicableItems());
        $auditRepo = new FlxCoreHC\Repository\AuditLogRepository($ctx);
        AccessLog::record($ctx, 'export', 'audit_package', $holderId);
        // render the standalone printable package (its own full HTML doc)
        extract([
            'ctx'         => $ctx,
            'holder'      => $holder,
            'score'       => $score,
            'planYear'    => $planYear,
            'credentials' => $credentials->all(['holder_id' => $holderId], 'expiry_date ASC'),
            'compliance'  => $complianceRows,
            'requirementRows' => $__me['rows'] ?? [],
            'auditLog'    => $auditRepo->recent(40),
            'accessLog'   => AccessLog::recent($ctx, 20),
            'generatedAt' => utc_now(),
        ]);
        // v1.172.17: this printable page is rendered without view(), so it never went through the translator (Spanish users got English headers)
        if (FlxCoreHC\I18n::locale() === 'en') { require FLXCOREHC_APP . '/views/reports/audit_package.php'; exit; }
        ob_start();
        require FLXCOREHC_APP . '/views/reports/audit_package.php';
        echo FlxCoreHC\I18n::translateHtml((string) ob_get_clean());
        FlxCoreHC\I18n::flushMissing();
        exit;
    }
    http_response_code(404); exit('Not found.');
}


// ---- Organization settings & branding --------------------------------------
if ($seg[0] === 'settings') {
    FlxCoreHC\Authz::requireCap($ctx, 'org.manage');   // capability, not role names (S-8)
    $org = new OrgService($ctx);
    $sub = $seg[1] ?? null;

    if ($method === 'POST' && $sub === null) {
        CsrfGuard::verify();
        if (($__ne = OrgService::nameError($_POST)) !== null) { flash($__ne, 'error'); redirect('/settings'); }   // v1.171.10
        $__before = $org->get();
        $org->updateInfo($_POST);
        // Ownership standard (element: organization name): the name "Licensed to" and the name shown to people change only here, and every change is audited old -> new.
        $__after = $org->get();
        foreach (['name' => 'org_name_changed', 'brand_name' => 'org_display_name_changed'] as $__f => $__act) {
            if ((string) ($__before[$__f] ?? '') !== (string) ($__after[$__f] ?? ''))
                audit_log($ctx, $__act, 'tenants', substr($ctx->tenantId, 0, 36), json_encode(['from' => (string) ($__before[$__f] ?? ''), 'to' => (string) ($__after[$__f] ?? '')], JSON_UNESCAPED_UNICODE));
        }
        $org->updateBrand($_POST['brand_accent'] ?? null);
        $org->updateTheme($_POST);
        [$cfOk, $cfMsg] = (new FlxCoreHC\CustomFieldService($ctx))->save('tenant', $ctx->tenantId, $_POST);
        flash($cfOk ? 'Organization settings saved.' : 'Settings saved, but: ' . $cfMsg); redirect('/settings');
    }
    if ($method === 'POST' && $sub === 'logo') {
        CsrfGuard::verify();
        [$ok, $msg] = handle_logo_upload($ctx, $org);
        flash($msg, $ok ? 'ok' : 'error'); redirect('/settings');
    }
    if ($method === 'POST' && $sub === 'logo-remove') {
        CsrfGuard::verify();
        $old = $org->clearLogo();
        if ($old && !str_starts_with($old, 'data:')) { @unlink(FLXCOREHC_ROOT . '/' . ltrim($old, '/')); }
        flash('Logo removed.', 'ok'); redirect('/settings');
    }
    if ($method === 'GET' && $sub === null) {
        view('settings/index', ['ctx'=>$ctx, 'org'=>$org->get(), 'customFieldsHtml' => (new FlxCoreHC\CustomFieldService($ctx))->render('tenant', $ctx->tenantId)]);
    }
    http_response_code(404); exit('Not found.');
}

// ---- Calendar & tasks -------------------------------------------------------
if ($seg[0] === 'calendar') {
    $id = $seg[1] ?? null; $sub = $seg[2] ?? null;
    $planYear = (int) date('Y');   // v1.170.8: calendar year
    $agents = array_values(array_filter($holders->all([], 'created_at ASC'), fn($h) => $h['holder_type'] === 'individual'));

    // --- drag-drop move (AJAX JSON) ---
    if ($method === 'POST' && $id !== null && $sub === 'move') {
        CsrfGuard::verify();
        header('Content-Type: application/json');
        $ev = $calendar->find($id);
        if (!$ev) { echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }
        $date = (string)($_POST['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { echo json_encode(['ok'=>false,'error'=>'Invalid date']); exit; }
        $__ad = (int)($ev['all_day'] ?? 1) === 1;
        // keep the wall-clock time the person sees: take it in the organization's zone, change the day, convert back
        $time = substr($__ad ? (string)$ev['due_at'] : fmt_dt((string)$ev['due_at'], 'Y-m-d H:i:s'), 11, 5) ?: '09:00';
        if ($ev['source'] !== 'manual') { echo json_encode(['ok'=>false,'error'=>"Auto deadlines can't be moved"]); exit; }
        $dur = !empty($ev['end_at']) ? (strtotime($ev['end_at']) - strtotime($ev['due_at'])) : 0;
        if (!$__ad && local_time_status($date.' '.$time.':00') === 'gap') { echo json_encode(['ok'=>false,'error'=>t('That time does not exist on that date (clocks move forward). Choose another time.')]); exit; }
        $newDue = $__ad ? $date.' '.$time.':00' : local_to_utc($date.' '.$time.':00');
        $newEnd = $dur > 0 ? gmdate('Y-m-d H:i:s', strtotime($newDue) + $dur) : null;
        $ok = $calendar->update($id, ['due_at'=>$newDue, 'end_at'=>$newEnd]);
        if ($ok) audit_log($ctx, 'calendar_event_moved', 'calendar_events', $id, json_encode(['due_at' => $newDue]));
        echo json_encode(['ok'=>$ok]); exit;
    }
    // --- edit a manual event ---
    if ($method === 'POST' && $id !== null && $sub === 'update') {
        CsrfGuard::verify();
        $ev = $calendar->find($id);
        if ($ev && $ev['source'] === 'manual') {
            $due = str_replace('T', ' ', (string)($_POST['due_at'] ?? '')); if (strlen($due) === 16) $due .= ':00';
            if ($due !== '' && (int)($ev['all_day'] ?? 1) !== 1) {   // v1.172.11: typed in the organization's zone
                if (local_time_status($due) === 'gap' && substr(fmt_dt((string)$ev['due_at'], 'Y-m-d H:i:s'), 0, 16) !== substr($due, 0, 16)) {
                    flash('That time does not exist on that date (clocks move forward). Choose another time.', 'error');
                    redirect('/calendar' . (!empty($_POST['back']) ? $_POST['back'] : ''));
                }
                $due = local_to_utc_keep($due, (string)$ev['due_at']);
            }
            $calendar->update($id, [
                'title'    => trim((string)($_POST['title'] ?? '')) ?: $ev['title'],
                'kind'     => $calTypes->findByCode((string)($_POST['kind'] ?? '')) ? $_POST['kind'] : $ev['kind'],
                'priority' => in_array($_POST['priority'] ?? '', ['low','normal','high'], true) ? $_POST['priority'] : 'normal',
                'detail'   => trim((string)($_POST['detail'] ?? '')) ?: null,
                'holder_id'=> ($_POST['holder_id'] ?? '') ?: null,
                'due_at'   => $due ?: $ev['due_at'],
            ]);
        audit_log($ctx, 'calendar_event_updated', 'calendar_events', $id, null);
            flash('Event updated.', 'ok');
        } else { flash("That item can't be edited from the calendar.", 'error'); }
        redirect('/calendar' . (!empty($_POST['back']) ? $_POST['back'] : ''));
    }
    // --- delete manual event ---
    if ($method === 'POST' && $id !== null && $sub === 'delete') {
        CsrfGuard::verify();
        $ev = $calendar->find($id);
        if ($ev) { $calendar->delete($id); flash('Event removed.', 'ok'); }
        redirect('/calendar' . (!empty($_POST['back']) ? $_POST['back'] : ''));
    }
    // --- mark done ---
    if ($method === 'POST' && $id !== null && $sub === 'done') {
        CsrfGuard::verify();
        $calendar->setStatus($id, 'done');
        audit_log($ctx, 'calendar_event_done', 'calendar_events', $id, null);
        flash('Marked done.', 'ok'); redirect('/calendar' . (!empty($_POST['back']) ? $_POST['back'] : ''));
    }
    // --- ICS subscription feed: show/generate/rotate the user's feed URL ---
    if ($id === 'subscribe') {
        if ($method === 'POST') {
            CsrfGuard::verify();
            $tok = bin2hex(random_bytes(20));
            FlxCoreHC\Database::pdo()->prepare("UPDATE users SET ical_token = ? WHERE id = ?")
                ->execute([$tok, $ctx->userId]);
            flash(($_POST['action'] ?? '') === 'revoke' ? 'Feed link rotated — old subscriptions will stop updating.' : 'Feed link ready.');
            redirect('/calendar/subscribe');
        }
        $tokRow = FlxCoreHC\Database::pdo()->prepare("SELECT ical_token FROM users WHERE id = ? LIMIT 1");
        $tokRow->execute([$ctx->userId]);
        $token = (string)($tokRow->fetchColumn() ?: '');
        view('calendar/subscribe', ['ctx'=>$ctx, 'token'=>$token]);
    }
    if ($method === 'POST' && $id === 'sync') {
        CsrfGuard::verify();
        $n = $calendar->sync($planYear);
        flash("Compliance deadlines synced ({$n} auto events)."); redirect('/calendar');
    }
    // --- manage event types ---
    if ($id === 'types') {
        if (!FlxCoreHC\TeamService::canManage($ctx)) { redirect('/calendar'); }
        if ($method === 'POST') {
            CsrfGuard::verify();
            $act = (string)($_POST['action'] ?? '');
            if ($act === 'add') {
                $label = trim((string)($_POST['label'] ?? ''));
                if ($label !== '') {
                    $code = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label));
                    $code = trim(substr($code, 0, 24), '_') ?: 'type_' . substr(md5($label), 0, 6);
                    if (!$calTypes->findByCode($code)) {
                        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['color'] ?? '')) ? $_POST['color'] : '#2f6fd6';
                        $calTypes->insert(['code'=>$code, 'label'=>$label, 'color'=>$color, 'is_system'=>0, 'is_active'=>1, 'sort'=>100]);
                        flash('Event type added.', 'ok');
                    } else { flash('A type with that name already exists.', 'error'); }
                }
            }
            if ($act === 'toggle') {
                $t = $calTypes->find((string)($_POST['id'] ?? ''));
                if ($t && !$t['is_system']) $calTypes->update($t['id'], ['is_active'=>$t['is_active'] ? 0 : 1]);
            }
            if ($act === 'delete') {
                $t = $calTypes->find((string)($_POST['id'] ?? ''));
                if ($t && !$t['is_system']) { $calTypes->delete($t['id']); flash('Event type removed.', 'ok'); }
            }
            redirect('/calendar/types');
        }
        view('calendar/types', ['ctx'=>$ctx, 'types'=>$calTypes->all([], 'sort ASC, label ASC')]);
    }
    // --- create manual event ---
    if ($method === 'POST' && $id === null) {
        CsrfGuard::verify();
        FlxCoreHC\Authz::requireCap($ctx, 'records.write');   // v1.170.8
        $due = str_replace('T', ' ', (string)($_POST['due_at'] ?? '')); if (strlen($due) === 16) $due .= ':00';
        $allday = !empty($_POST['all_day']) ? 1 : 0;
        if ($due !== '' && !$allday) {   // v1.172.11: a timed event is typed in the organization's zone, stored in UTC
            if (local_time_status($due) === 'gap') {
                flash('That time does not exist on that date (clocks move forward). Choose another time.', 'error');
                redirect('/calendar' . (!empty($_POST['back']) ? $_POST['back'] : ''));
            }
            $due = local_to_utc($due);
        }
        $calendar->insert([
            'holder_id'=>($_POST['holder_id'] ?? '') ?: null,
            'kind'=>$calTypes->findByCode((string)($_POST['kind'] ?? '')) ? $_POST['kind'] : 'meeting',
            'source'=>'manual',
            'title'=>trim((string)($_POST['title'] ?? '')) ?: 'Untitled',
            'detail'=>trim((string)($_POST['detail'] ?? '')) ?: null,
            'client_name'=>trim((string)($_POST['client_name'] ?? '')) ?: null,
            'due_at'=>$due ?: utc_now(), 'all_day'=>$allday,
            'priority'=>in_array($_POST['priority'] ?? '', ['low','normal','high'], true) ? $_POST['priority'] : 'normal',
            'status'=>'open',
        ]);
        audit_log($ctx, 'calendar_event_created', 'calendar_events', null, json_encode(['title' => $_POST['title'] ?? null]));
        flash('Added to calendar.', 'ok'); redirect('/calendar' . (!empty($_POST['back']) ? $_POST['back'] : ''));
    }
    if ($method === 'GET' && $id === 'new') {
        view('calendar/form', ['ctx'=>$ctx, 'agents'=>$agents, 'types'=>$calTypes->active()]);
    }
    if ($method === 'GET' && $id === null) {
        // Keep compliance deadlines fresh without a manual click or cron. Idempotent (upserts by
        // source_ref), best-effort so a sync hiccup never blocks the calendar from rendering.
        try { $calendar->sync($planYear); } catch (\Throwable $e) {}
        // Scale-friendly: default to the Agenda (urgency buckets) for large tenants; month/list on ask.
        $viewMode = in_array($_GET['view'] ?? '', ['month','list','agenda'], true) ? $_GET['view'] : 'agenda';
        $mParam = $_GET['m'] ?? date('Y-m');
        $monthStart = preg_match('/^\d{4}-\d{2}$/', $mParam) ? $mParam . '-01' : date('Y-m-01');
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        // Shared filters (provider / group / location / type / status) — narrow thousands to the few.
        $filter = [
            'holder_id'   => trim((string)($_GET['holder_id'] ?? '')) ?: null,
            'group_id'    => trim((string)($_GET['group_id'] ?? '')) ?: null,
            'location_id' => trim((string)($_GET['location_id'] ?? '')) ?: null,
            'kind'        => trim((string)($_GET['kind'] ?? '')) ?: null,
        ];
        $filter = array_filter($filter);
        $groupsRepo = new FlxCoreHC\Repository\GroupRepository($ctx);
        $locsRepo   = new FlxCoreHC\Repository\LocationRepository($ctx);
        // list-view filters
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : $monthStart;
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')   ? $_GET['to']   : $monthEnd;
        $statusF = in_array($_GET['status'] ?? '', ['open','done','cancelled'], true) ? $_GET['status'] : '';
        $listEvents = $calendar->filtered($filter + ['from'=>$from, 'to'=>$to] + ($statusF ? ['status'=>$statusF] : []));
        view('calendar/index', [
            'ctx'=>$ctx,
            'events'=>$calendar->filtered($filter + ['from'=>$monthStart, 'to'=>$monthEnd]),
            'dayCounts'=>$calendar->dayCounts($monthStart, $monthEnd, $filter),
            'agenda'=>$calendar->agenda($filter),
            'upcoming'=>$calendar->upcoming(30),
            'monthStart'=>$monthStart,
            'overdue'=>$calendar->overdueCount(),
            'planYear'=>$planYear,
            'viewMode'=>$viewMode, 'from'=>$from, 'to'=>$to, 'statusF'=>$statusF,
            'listEvents'=>$listEvents, 'agents'=>$agents,
            'filter'=>$filter,
            'allGroups'=>$groupsRepo->active(), 'allLocations'=>$locsRepo->active(),
            'groupLabelPlural'=>FlxCoreHC\GroupService::label($ctx->orgShape, true),
            'typeLabels'=>$calTypes->labelMap(), 'typeColors'=>$calTypes->colorMap(), 'types'=>$calTypes->active(),
        ]);
    }
    http_response_code(404); exit('Not found.');
}




// ---- Compliance checklist ----------------------------------------------------
if ($seg[0] === 'training') {
    $id = $seg[1] ?? null;
    $canManage = FlxCoreHC\TeamService::canManage($ctx);

    // Add a tenant training resource (managers only).
    if ($method === 'POST' && $id === null) {
        CsrfGuard::verify();
        if (!$canManage) { http_response_code(403); exit('Forbidden.'); }
        $ttl = trim((string)($_POST['title'] ?? ''));
        if ($ttl !== '') {
            $cat = (string)($_POST['category'] ?? 'general');
            $cat = array_key_exists($cat, FlxCoreHC\TrainingLibraryService::CATEGORIES) ? $cat : 'general';
            $training->insert([
                'title'      => $ttl,
                'category'   => $cat,
                'body'       => trim((string)($_POST['body'] ?? '')) ?: null,
                'link'       => trim((string)($_POST['link'] ?? '')) ?: null,
                'regulator_id' => ($_POST['regulator_id'] ?? '') ?: null,
            ]);
            flash('Resource added to the Training Center.', 'ok');
        }
        redirect('/training');
    }
    if ($method === 'POST' && $id !== null && ($seg[2] ?? '') === 'delete') {
        CsrfGuard::verify();
        if (!$canManage) { http_response_code(403); exit('Forbidden.'); }
        flash(($__trd = $training->delete($id)) ? 'Resource removed.' : 'Resource not found.', $__trd ? 'ok' : 'error');
        redirect('/training');
    }
    // Library article detail.
    if ($method === 'GET' && $id !== null && $id !== 'search') {
        $article = FlxCoreHC\TrainingLibraryService::find($id);
        if (!$article) { http_response_code(404); exit('Not found.'); }
        // Don't serve another vertical's article by direct code (e.g. a healthcare user opening an
        // MA-agent article link). The library the user sees is already vertical-filtered; enforce it here too.
        if (!empty($article['vertical']) && $article['vertical'] !== $ctx->vertical) {
            flash('That training article isn\'t part of your library.', 'error');
            redirect('/training');
        }
        view('training/article', ['ctx' => $ctx, 'article' => $article, 'sources' => FlxCoreHC\TrainingLibraryService::sourcesFor($id)]);
    }
    // Center (with optional ?q= search).
    if ($method === 'GET') {
        $q = trim((string)($_GET['q'] ?? ''));
        view('training/index', [
            'ctx'       => $ctx,
            'library'   => FlxCoreHC\TrainingLibraryService::all($ctx->vertical),
            'resources' => $training->allWithRegulator(),
            'regulators' => $regulators->all([], 'name ASC'),
            'canManage' => $canManage,
            'q'         => $q,
            'results'   => $q !== '' ? FlxCoreHC\TrainingLibraryService::search($q, $ctx->vertical) : null,
            'available' => FlxCoreHC\TrainingLibraryService::available(),
            'sourceCounts' => FlxCoreHC\TrainingLibraryService::sourceCounts(),
        ]);
    }
    http_response_code(404);
    exit('Not found.');
}

if ($seg[0] === 'cases') {
    // ---- Agency Credentialing: Credentialing Cases (orchestration layer) ----
    FlxCoreHC\Authz::requireCap($ctx, 'records.write');
    $caseRepo = new FlxCoreHC\Repository\CredentialingCaseRepository($ctx);
    $reqRepo  = new FlxCoreHC\Repository\CredentialingCaseRequirementRepository($ctx);
    $svc      = new FlxCoreHC\CredentialingCaseService($ctx);
    $id = $seg[1] ?? null;

    if ($method === 'POST') {
        CsrfGuard::verify();
        $act = (string)($_POST['action'] ?? '');
        $case = $id ? $caseRepo->find($id) : null;   // ownership + event context for mutations
        if ($act === 'create') {
            $__autopop = true; // auto-populate requirements from the provider's profession template
            [$ok, $res] = $svc->open([
                'holder_id'       => (string)($_POST['holder_id'] ?? ''),
                'client_group_id' => trim((string)($_POST['client_group_id'] ?? '')) ?: null,
                'payer_id'        => trim((string)($_POST['payer_id'] ?? '')) ?: null,
                'case_type'       => in_array($_POST['case_type'] ?? '', array_keys(FlxCoreHC\Repository\CredentialingCaseRepository::TYPES), true) ? $_POST['case_type'] : 'initial',
                'target_date'     => trim((string)($_POST['target_date'] ?? '')) ?: null,
                'assigned_to'     => trim((string)($_POST['assigned_to'] ?? '')) ?: null,
                'notes'           => trim((string)($_POST['notes'] ?? '')) ?: null,
            ]);
            if ($ok) { try { $svc->populateRequirements($res); } catch (\Throwable $e) {} }
            flash($ok ? 'Case opened.' : $res, $ok ? 'ok' : 'error');
            redirect($ok ? '/cases/' . $res : '/cases');
        }
        if ($act === 'transition' && $id) {
            [$ok, $msg, $blockers] = $svc->transition($id, (string)($_POST['to'] ?? ''));
            flash($ok ? 'Case updated.' : ($msg . ($blockers ? ' (' . count($blockers) . ' hard stop(s))' : '')), $ok ? 'ok' : 'error');
            redirect('/cases/' . $id);
        }
        if ($act === 'assign' && $id) {
            [$__ok, $__msg] = $svc->assign($id, trim((string)($_POST['assigned_to'] ?? '')) ?: null);
            flash($__ok ? 'Assignment updated.' : $__msg, $__ok ? 'ok' : 'error'); redirect('/cases/' . $id);
        }
        if ($act === 'populate_requirements' && $id) {
            [$ok, $res] = $svc->populateRequirements($id);
            flash($ok ? ('Added ' . (int)$res . ' requirement(s) from the template.') : $res, $ok ? 'ok' : 'error');
            redirect('/cases/' . $id);
        }
        if ($act === 'add_requirement' && $id) {
            [$ok, $res] = $svc->addRequirement($id, (string)($_POST['requirement_code'] ?? ''), [
                'label'        => trim((string)($_POST['label'] ?? '')) ?: null,
                'is_hard_stop' => !empty($_POST['is_hard_stop']) ? 1 : 0,
                'due_date'     => trim((string)($_POST['due_date'] ?? '')) ?: null,
            ]);
            flash($ok ? 'Requirement added.' : $res, $ok ? 'ok' : 'error'); redirect('/cases/' . $id);
        }
        if ($act === 'req_status' && $id) {
            [$ok, $msg] = $svc->updateRequirementStatus($id, (string)($_POST['req_id'] ?? ''), (string)($_POST['status'] ?? ''));
            flash($ok ? 'Requirement updated.' : $msg, $ok ? 'ok' : 'error');
            redirect('/cases/' . $id);
        }
        if ($act === 'link_evidence' && $id) {
            $eviRepo = new FlxCoreHC\Repository\CaseRequirementEvidenceRepository($ctx);
            // v1.170.6 — the requirement must belong to THIS case (the URL's case), not just this tenant.
            $__req = (new FlxCoreHC\Repository\CredentialingCaseRequirementRepository($ctx))->find((string)($_POST['req_id'] ?? ''));
            if (!$__req || (string)$__req['case_id'] !== (string)$id) { flash('That requirement does not belong to this case.', 'error'); redirect('/cases/' . $id); }
            $target = [];
            foreach (FlxCoreHC\Repository\CaseRequirementEvidenceRepository::TARGETS as $col => $_t) {
                $v = trim((string)($_POST[$col] ?? '')); if ($v !== '') $target[$col] = $v;
            }
            [$ok, $res] = $eviRepo->link((string)($_POST['req_id'] ?? ''), $target, $ctx->userId);
            if ($ok) { FlxCoreHC\ComplianceEventService::record($ctx, 'case_evidence_linked', 'Evidence linked to requirement', ['holder_id' => $case['holder_id'] ?? null, 'case_id' => $id, 'detail' => ['req' => $_POST['req_id'] ?? null]]); }
            flash($ok ? 'Evidence linked.' : $res, $ok ? 'ok' : 'error');
            redirect('/cases/' . $id);
        }
        if ($act === 'upload_case_document' && $id && $case) {
            $caseDocs = new FlxCoreHC\Repository\CaseDocumentRepository($ctx);
            [$ok, $res] = handle_case_document_upload($caseDocs, $ctx, (string)$case['holder_id'], $id, trim((string)($_POST['doc_type'] ?? '')) ?: null);
            flash($ok ? 'Document uploaded.' : $res, $ok ? 'ok' : 'error');
            redirect('/cases/' . $id);
        }
        if ($act === 'delete_case_document' && $id) {
            [$ok, $msg] = $svc->deleteCaseDocument($id, (string)($_POST['doc_id'] ?? ''));
            flash($ok ? 'Document removed.' : $msg, $ok ? 'ok' : 'error');
            redirect('/cases/' . $id);
        }
        if ($act === 'unlink_evidence' && $id) {
            [$ok, $msg] = $svc->unlinkEvidence($id, (string)($_POST['evidence_id'] ?? ''));
            flash($ok ? 'Evidence unlinked.' : $msg, $ok ? 'ok' : 'error');
            redirect('/cases/' . $id);
        }
        redirect('/cases');
    }

    // GET case document download — tenant-scoped; a shared provider never exposes another tenant's case docs.
    if ($id && ($seg[2] ?? '') === 'document' && !empty($seg[3])) {
        $caseDocs = new FlxCoreHC\Repository\CaseDocumentRepository($ctx);
        $doc = $caseDocs->find((string)$seg[3]);
        if (!$doc || (string)$doc['case_id'] !== $id) { http_response_code(404); exit('Not found.'); }
        $path = (string)$doc['file_path'];
        if (!is_file($path)) { http_response_code(404); exit('File missing.'); }
        FlxCoreHC\ComplianceEventService::record($ctx, 'case_document_accessed', 'Case document downloaded', ['case_id' => $id, 'holder_id' => $doc['holder_id'] ?? null]);
        header('Content-Type: ' . ($doc['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . rawurlencode((string)($doc['original_name'] ?: 'document')) . '"');
        if (!FlxCoreHC\EncryptedFile::send($path)) { http_response_code(404); }
        exit;
    }

    // GET one case
    if ($id) {
        $case = $caseRepo->find($id);
        if (!$case) { http_response_code(404); exit('Case not found.'); }   // (the errors/404 view never existed → fatal)
        $holder = (new FlxCoreHC\Repository\HolderRepository($ctx))->find((string)$case['holder_id']);
        $__eviRepo = new FlxCoreHC\Repository\CaseRequirementEvidenceRepository($ctx);
        $__reqs = $reqRepo->forCase($id);
        $__evidence = [];
        foreach ($__reqs as $__r) { $__evidence[$__r['id']] = $__eviRepo->forRequirement($__r['id']); }
        view('cases/show', [
            'ctx' => $ctx, 'case' => $case, 'holder' => $holder,
            'requirements' => $__reqs,
            'evidence' => $__evidence,
            'caseDocuments' => (new FlxCoreHC\Repository\CaseDocumentRepository($ctx))->forCase($id),
            'reusable' => $svc->reusableEvidenceFor((string)$case['holder_id']),
            'applications' => (function() use ($ctx, $id) { try { $st = FlxCoreHC\Database::pdo()->prepare("SELECT * FROM applications WHERE tenant_id = ? AND case_id = ? ORDER BY created_at DESC"); $st->execute([$ctx->tenantId, $id]); return $st->fetchAll(); } catch (\Throwable $e) { return []; } })(),
            'progress' => (function() use ($reqRepo, $id) { $rows = $reqRepo->forCase($id); $req = array_filter($rows, fn($r)=>!empty($r['is_required'])); $sat = array_filter($req, fn($r)=>in_array($r['status'],['satisfied','waived'],true)); $hs = array_filter($rows, fn($r)=>!empty($r['is_hard_stop']) && !in_array($r['status'],['satisfied','waived'],true)); return ['total'=>count($req), 'satisfied'=>count($sat), 'pct'=>count($req)?(int)round(100*count($sat)/count($req)):0, 'hard_stops'=>count($hs), 'all'=>count($rows)]; })(),
            'blockers' => $svc->submissionBlockers($id, (string)$case['holder_id'], false),
            'history' => FlxCoreHC\ComplianceEventService::forCase($ctx, $id, 200),
            'catalog' => FlxCoreHC\CatalogService::all(null, null, null, $ctx->tenantId),
            'transitions' => FlxCoreHC\Repository\CredentialingCaseRepository::STATUSES,
        ]);
    }

    // GET list + work queue
    view('cases/index', [
        'ctx' => $ctx,
        'cases' => $caseRepo->all([], 'opened_at DESC', 300),
        'queue' => (new FlxCoreHC\AgencyWorkQueueService($ctx))->build($ctx->userId),
        'types' => FlxCoreHC\Repository\CredentialingCaseRepository::TYPES,
        // v1.170.7 — pickers instead of raw ids (need-to-know applies via HolderRepository::all()).
        'pickHolders' => $holders->all([], 'last_name ASC, org_name ASC', 2000),
        'pickGroups'  => (new FlxCoreHC\Repository\GroupRepository($ctx))->all([], 'name ASC', 500),
        'pickPayers'  => (new FlxCoreHC\EnrollmentService($ctx))->payers(),
    ]);
}

if ($seg[0] === 'compliance') {
    $id = $seg[1] ?? null;

    // Evaluate expiry reminders opportunistically on the compliance dashboard (cron-free).
    // (v1.171.3: reminders are sent by cron/send_reminders.php or the explicit "send now" POST below — never as a side effect of viewing the page.)

    // Staff-initiated, provider-directed alert: one summary email of everything expiring/expired.
    if ($method === 'POST' && $id === 'nudge' && !empty($seg[2])) {
        FlxCoreHC\Authz::requireCap($ctx, 'records.write');
        CsrfGuard::verify();
        $nh = $holders->find((string) $seg[2]);
        if (!$nh) { http_response_code(404); exit('Not found.'); }
        $nCreds = $credentials->all(['holder_id' => $nh['id']], '(expiry_date IS NULL), expiry_date ASC');
        [$ok, $msg] = (new FlxCoreHC\ReminderService($ctx))->nudgeHolder($nh, $nCreds);
        if ($ok) audit_log($ctx, 'holder_nudged', 'holders', (string) $nh['id'], null);
        flash($msg, $ok ? 'ok' : 'error');
        redirect('/compliance/producers');
    }

    // Managers can trigger a reminder sweep on demand.
    if ($method === 'POST' && $id === 'run-reminders') {
        FlxCoreHC\Authz::requireCap($ctx, 'records.write');
        CsrfGuard::verify();
        $n = (new FlxCoreHC\ReminderService($ctx))->run();
        flash($n > 0 ? "Queued {$n} expiry reminder" . ($n === 1 ? '' : 's') . '.' : 'No new reminders due right now.');
        redirect('/compliance');
    }

    // Save a producer's assigned requirements (tokens: code | code@regulator | cr:id).
    if ($method === 'POST' && $id === 'assign') {
        CsrfGuard::verify();
        $hid = (string)($_POST['holder_id'] ?? '');
        if ($holders->find($hid)) {
            $tokens = array_values(array_filter((array)($_POST['codes'] ?? []), 'is_string'));
            $customRegulatorMap = array_map(fn($r) => $r['regulator_id'], $regulatorReqs->mapById());
            $producerReqs->setTokens($hid, $tokens, $customRegulatorMap);
            flash('Required credentials updated.', 'ok');
        } else {
            flash('Pick a valid provider.', 'error');
        }
        redirect('/holders/' . $hid);
    }
    // Record an exclusion / sanctions screening for a provider.
    if ($method === 'POST' && $id === 'screen') {
        CsrfGuard::verify();
        $hid = (string)($_POST['holder_id'] ?? '');
        if ($holders->find($hid)) {
            $src = array_key_exists($_POST['source'] ?? '', FlxCoreHC\Repository\ScreeningRepository::SOURCES) ? $_POST['source'] : 'oig_leie';
            $res = array_key_exists($_POST['result'] ?? '', FlxCoreHC\Repository\ScreeningRepository::RESULTS) ? $_POST['result'] : 'clear';
            $son = trim((string)($_POST['screened_on'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $son)) $son = today_local();
            $screenings->insert([
                'holder_id'        => $hid,
                'source'           => $src,
                'screened_on'      => $son,
                'result'           => $res,
                'reference'        => trim((string)($_POST['reference'] ?? '')) ?: null,
                'screened_by'      => trim((string)($_POST['screened_by'] ?? '')) ?: ($ctx->userName ?? null),
                'screened_by_user' => $ctx->userId,
                'notes'            => trim((string)($_POST['notes'] ?? '')) ?: null,
            ]);
            FlxCoreHC\AccessLog::record($ctx, 'write', 'exclusion_screening', $hid);
            flash('Screening recorded.', 'ok');
        }
        redirect('/holders/' . $hid);
    }
    // Automated OIG LEIE screen: match the provider against the loaded LEIE list and record the
    // result as a review signal stamped with the list vintage. Never concludes "excluded".
    if ($method === 'POST' && $id === 'screen-leie') {
        CsrfGuard::verify();
        FlxCoreHC\Authz::requireCap($ctx, 'records.write');
        $hid = (string)($_POST['holder_id'] ?? '');
        $h = $holders->find($hid);
        if ($h) {
            $who = [
                'npi'        => $h['npi'] ?? null,
                'first_name' => $h['first_name'] ?? null,
                'last_name'  => $h['last_name'] ?? null,
                'org_name'   => $h['org_name'] ?? null,
            ];
            $sources = [
                ['key'=>'oig_leie','label'=>'OIG LEIE','authority'=>'oig.hhs.gov','scan'=>FlxCoreHC\LeieService::screen($who)],
                ['key'=>'sam_gov','label'=>'SAM.gov','authority'=>'sam.gov','scan'=>FlxCoreHC\SamService::screen($who)],
            ];
            foreach (FlxCoreHC\MedicaidExclusionService::loadedJurisdictions() as $mi) {
                $jur = $mi['jurisdiction'];
                $sources[] = [
                    'key' => $jur === 'PR' ? 'pr_medicaid' : 'state_medicaid',
                    'label' => (FlxCoreHC\MedicaidExclusionService::JURISDICTIONS[$jur] ?? ($jur . ' Medicaid')),
                    'authority' => $jur . ' Medicaid agency',
                    'scan' => FlxCoreHC\MedicaidExclusionService::screen($who, $jur),
                ];
            }
            $ran = 0; $hits = 0; $loadedLabels = [];
            foreach ($sources as $s) {
                $scan = $s['scan'];
                if (!$scan['loaded']) continue;
                $ran++; $loadedLabels[] = $s['label'];
                $note = null;
                if ($scan['matches']) {
                    $hits += count($scan['matches']);
                    $bits = [];
                    foreach ($scan['matches'] as $m) {
                        $nm = ($m['business_name'] ?? $m['entity_name'] ?? '') ?: trim(($m['last_name'] ?? '') . ', ' . ($m['first_name'] ?? ''));
                        $extra = $m['excltype'] ?? $m['excl_type'] ?? $m['excl_reason'] ?? '';
                        $bits[] = $nm . ' [' . strtoupper((string)($m['match_on'] ?? '')) . ($extra ? ', ' . $extra : '') . ']';
                    }
                    $note = 'Auto-screen against ' . $s['label'] . ' (' . $scan['vintage'] . '). REVIEW REQUIRED — confirm identity at ' . $s['authority'] . ' before acting. Signals: ' . implode('; ', $bits);
                }
                $screenings->insert([
                    'holder_id'        => $hid,
                    'source'           => $s['key'],
                    'screened_on'      => today_local(),
                    'result'           => $scan['result'], // 'clear' or 'potential_match' — never 'excluded'
                    'reference'        => $s['label'] . ' ' . $scan['vintage'] . ($scan['matches'] ? ' — ' . count($scan['matches']) . ' potential match(es)' : ' — no match'),
                    'screened_by'      => 'Automated (' . $s['label'] . ' ' . $scan['vintage'] . ')',
                    'screened_by_user' => $ctx->userId,
                    'notes'            => $note,
                ]);
            }
            if ($ran === 0) {
                flash('No exclusion list is loaded yet — an admin needs to import the OIG LEIE and/or SAM.gov file first.', 'error');
            } else {
                FlxCoreHC\AccessLog::record($ctx, 'write', 'exclusion_screening', $hid);
                audit_log($ctx, 'exclusion_screen', 'holders', $hid, json_encode(['sources' => $loadedLabels, 'matches' => $hits]));
                flash($hits > 0
                    ? 'Potential match — recorded for review. Confirm identity at the source (oig.hhs.gov / sam.gov) before acting.'
                    : 'Screened clear against ' . implode(' + ', $loadedLabels) . '.');
            }
        }
        redirect('/holders/' . $hid);
    }
    // Record a provider attestation (staff, on the provider's behalf).
    if ($method === 'POST' && $id === 'attest') {
        CsrfGuard::verify();
        $hid = (string)($_POST['holder_id'] ?? '');
        if ($holders->find($hid)) {
            $vm = array_key_exists($_POST['method'] ?? '', FlxCoreHC\Repository\AttestationRepository::METHODS)
                ? $_POST['method'] : 'portal_ack';
            $attestations->insert([
                'holder_id'        => $hid,
                'statement'        => trim((string)($_POST['statement'] ?? '')) ?: FlxCoreHC\Repository\AttestationRepository::DEFAULT_STATEMENT,
                'method'           => $vm,
                'attested_by'      => trim((string)($_POST['attested_by'] ?? '')) ?: ($ctx->userName ?? null),
                'attested_by_user' => $ctx->userId,
                'attested_on'      => utc_now(),
                'ip_address'       => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                'notes'            => trim((string)($_POST['notes'] ?? '')) ?: null,
            ]);
            FlxCoreHC\AccessLog::record($ctx, 'write', 'holder_attestation', $hid);
            flash('Attestation recorded.', 'ok');
        }
        redirect('/holders/' . $hid);
    }
    // Assign regulators to a provider (their requirements flow into the compliance view).
    if ($method === 'POST' && $id === 'assign-regulators') {
        CsrfGuard::verify();
        $hid = (string)($_POST['holder_id'] ?? '');
        if ($holders->find($hid)) {
            $ids = array_values(array_filter((array)($_POST['regulator_ids'] ?? []), 'is_string'));
            // keep only ids that are real regulators for this tenant
            $valid = array_column($regulators->all([], 'name ASC'), 'id');
            $ids = array_values(array_intersect($ids, $valid));
            $holderRegs->setForHolder($hid, $ids);
            flash('Regulators updated for this provider.', 'ok');
        } else {
            flash('Pick a valid provider.', 'error');
        }
        redirect('/holders/' . $hid);
    }
    if ($method === 'POST' && $id === 'assign-defaults') {
        CsrfGuard::verify();
        $hid = (string)($_POST['holder_id'] ?? '');
        $h = $holders->find($hid);
        if ($h) {
            $role = FlxCoreHC\CatalogService::holderRole($h, (string)($__trow['org_shape'] ?? 'agency'));
            $producerReqs->setTokens($hid, FlxCoreHC\CatalogService::requiredCodesForRole($role, $__tlines, $__tstates));
            flash('Default requirements assigned.', 'ok');
        }
        redirect('/holders/' . $hid);
    }

    // ---- Security & privacy checklist tab ----
    if ($id === 'security') {
        if ($method === 'POST' && ($seg[2] ?? '') !== '') {
            CsrfGuard::verify();
            $compliance->setStatus((string)$seg[2], (string)($_POST['status'] ?? ''), trim((string)($_POST['note'] ?? '')) ?: null);
        audit_log($ctx, 'compliance_status_set', 'compliance_items', (string)$seg[2], json_encode(['status' => $_POST['status'] ?? null]));
            flash('Compliance item updated.', 'ok');
            redirect('/compliance/security');
        }
        view('compliance/security', [
            'ctx' => $ctx, 'tab' => 'security',
            'items' => $compliance->applicableItems(),
            'statusMap' => $compliance->statusMap(),
            'score' => $compliance->score(),
        ]);
    }

    // ---- Producers tab (per-producer status cards) ----
    if ($method === 'GET' && $id === 'producers') {
        $data = flx_compliance_data($holders, $credentials, $producerReqs, $regulatorReqs, $regulators, (string)($__trow['org_shape'] ?? 'agency'), $__tlines, $documents, $__tstates, $holderRegs);
        FlxCoreHC\TaskSyncService::sync($tasks, $data['producers'], $producerReqs->allAssignees());
        // Which holders actually have something expiring/expired on file — one query, gates
        // the per-holder "Send reminder" button (Feature: provider-directed alerts).
        $nudgeable = [];
        foreach ($credentials->withHolders() as $__nc) {
            if (in_array((string)$__nc['status'], ['expiring', 'expired'], true)) $nudgeable[(string)$__nc['holder_id']] = true;
        }
        view('compliance/producers', ['ctx' => $ctx, 'tab' => 'producers',
            'producers' => $data['producers'], 'totals' => $data['totals'],
            'nudgeable' => $nudgeable,
            'available' => FlxCoreHC\CatalogService::available()]);
    }

    // ---- Overview tab (default) ----
    if ($method === 'GET' && $id === null) {
        $data = flx_compliance_data($holders, $credentials, $producerReqs, $regulatorReqs, $regulators, (string)($__trow['org_shape'] ?? 'agency'), $__tlines, $documents, $__tstates, $holderRegs);
        FlxCoreHC\TaskSyncService::sync($tasks, $data['producers'], $producerReqs->allAssignees());
        // expiring within 30 days across all credentials, for the horizon panel
        $soon = $credentials->expiringWithin(30);
        view('compliance/index', ['ctx' => $ctx, 'tab' => 'overview',
            'producers' => $data['producers'], 'totals' => $data['totals'],
            'soon' => $soon, 'available' => FlxCoreHC\CatalogService::available(),
            'canManage' => FlxCoreHC\Authz::can($ctx, 'records.write')]);
    }

    http_response_code(404);
    exit('Not found.');
}

// ---- Audit trail (master log per tenant) -------------------------------------
if ($seg[0] === 'audit') {
    FlxCoreHC\Authz::requireCap($ctx, 'audit.view');   // the activity log names who did what — owner/manager only
    if (FlxCoreHC\ProviderScope::for($ctx)->restricted()) {   // v1.171.0 (B-4): tenant-wide log is not for need-to-know users
        http_response_code(403);
        exit('The organization-wide activity log is not available to users limited to their assigned providers. Open a provider to see that provider\'s history.');
    }

    // ---- Activity pulse dashboard: at-a-glance daily/weekly activity across all staff ----
    if (($seg[1] ?? '') === 'pulse') {
        $range = in_array($_GET['range'] ?? '', ['today','7','30'], true) ? $_GET['range'] : 'today';
        $today = today_local();
        $from = $range === 'today' ? $today : today_local('-' . ((int)$range - 1) . ' days');
        $counts = $auditLogs->actionCounts($from, $today);
        // group the raw actions into human-friendly buckets
        $bucket = function(array $keys) use ($counts) { $n = 0; foreach ($keys as $k) $n += $counts[$k] ?? 0; return $n; };
        $highlights = [
            ['Providers added', $bucket(['provider_created','holder_onboard_finished']), 'holders'],
            ['Credentials added', $bucket(['credential_created','credential_import']), 'credentials'],
            ['Credentials updated', $bucket(['credential_updated']), 'credentials'],
            ['Credentials removed', $bucket(['credential_deleted']), 'credentials'],
            ['Documents verified', $bucket(['document_verified']), 'documents'],
            ['Screenings run', $bucket(['exclusion_screen','exclusion_screen_recorded']), 'screening'],
            ['Reminders / nudges', $bucket(['holder_nudged']), 'compliance'],
            ['Reports exported', $bucket(['report_export','history_export','data_export','audit_export']), 'reports'],
            ['Team & role changes', $bucket(['team_member_invited','role_created','role_updated','role_deleted','provider_access_mode']), 'team'],
        ];
        view('audit/pulse', ['ctx' => $ctx, 'range' => $range, 'from' => $from, 'today' => $today,
            'totals' => $auditLogs->activityTotals($from, $today),
            'highlights' => $highlights,
            'byPerson' => $auditLogs->activityByPerson($from, $today, 25),
            'byDay' => $auditLogs->activityByDay(today_local('-29 days'), $today),
            'recent' => $auditLogs->filtered(['from' => $from], 30)]);
        exit;
    }

    $filter = [
        'from'        => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : null,
        'to'          => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : null,
        'user_id'     => trim((string)($_GET['user_id'] ?? '')) ?: null,
        'action'      => trim((string)($_GET['action'] ?? '')) ?: null,
        'entity_type' => trim((string)($_GET['entity_type'] ?? '')) ?: null,
        'entity_id'   => trim((string)($_GET['entity_id'] ?? '')) ?: null,
        'q'           => trim((string)($_GET['q'] ?? '')) ?: null,
    ];
    $filter = array_filter($filter);

    if ($method === 'GET' && ($seg[1] ?? '') === 'export.csv') {
        audit_log($ctx, 'audit_export', 'audit_logs', null, json_encode($filter));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit-log-' . date('Y-m-d') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
        flx_fputcsv($out, ['When (UTC)', 'Who', 'Action', 'Record type', 'Record id', 'Changes', 'IP']);
        $off = 0;
        do {
            $batch = $auditLogs->filtered($filter, 500, $off);
            foreach ($batch as $a) {
                flx_fputcsv($out, csv_safe_row([$a['created_at'], $a['user_name'] ?? 'System', str_replace('_',' ',(string)$a['action']),
                    (string)$a['entity_type'], (string)$a['entity_id'], (string)($a['changes'] ?? ''), (string)($a['ip_address'] ?? '')]));
            }
            $off += 500;
        } while (count($batch) === 500 && $off < 50000);
        fclose($out); exit;
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 100;
    $total = $auditLogs->countFiltered($filter);
    $rows = $auditLogs->filtered($filter, $per, ($page - 1) * $per);
    view('audit/index', ['ctx' => $ctx, 'rows' => $rows, 'filter' => $filter,
        'users' => FlxCoreHC\TeamService::directory($ctx),
        'actions' => $auditLogs->distinctActions(),
        'total' => $total, 'page' => $page, 'per' => $per, 'pages' => (int)ceil($total / $per)]);
    exit;
}

http_response_code(404);
exit('Not found.');

// ---- Local helpers -------------------------------------------------------------
function credential_status_for(?string $expiry): string
{
    if ($expiry === null) return 'active';
    $today = today_local_dt();      // the organization's calendar day, not the server's (UTC) day
    $exp   = DateTimeImmutable::createFromFormat('!Y-m-d', $expiry) ?: $today;
    if ($exp < $today) return 'expired';
    $days = (int) $today->diff($exp)->format('%a');
    return $days <= (int) config('expiring_window_days', 90) ? 'expiring' : 'active';
}

/**
 * Build a holder's CV / Credential Summary PDF and stream it as a download. Shared by the staff
 * route (/holders/{id}/cv) and the producer self-service route (/me/cv) so both produce an
 * identical document. All data is fetched through the passed tenant-scoped repositories.
 */
function stream_holder_cv(array $row, TenantContext $ctx, CredentialRepository $credentials, DocumentRepository $documents): void
{
    $labels = FlxCoreHC\CatalogService::labelsFor($ctx->tenantId);
    $catMeta = [];
    foreach (FlxCoreHC\CatalogService::all(null, null, null, $ctx->tenantId) as $cat) { $catMeta[$cat['code']] = $cat; }
    $credsOut = [];
    foreach ($credentials->all(['holder_id' => $row['id']], '(expiry_date IS NULL), expiry_date ASC') as $c) {
        $code = FlxCoreHC\CatalogService::codeFor((string)$c['credential_type']);
        $docCount = count($documents->all(['credential_id' => $c['id']]));
        $credsOut[] = [
            'name'         => (string)$c['name'],
            'type'         => $catMeta[$code]['name'] ?? ($labels[$code] ?? (string)$c['credential_type']),
            'category'     => (string)($catMeta[$code]['category'] ?? 'other'),
            'issuing_body' => (string)($c['issuing_body'] ?? ''),
            'number'       => (string)($c['credential_number'] ?? ''),
            'issued'       => $c['issued_date'] ? fmt_day((string)$c['issued_date']) : '',
            'expiry'       => $c['expiry_date'] ? fmt_day((string)$c['expiry_date']) : '',
            'status'       => in_array((string)$c['status'], ['active','expiring','expired'], true) ? (string)$c['status'] : 'active',
            'docs'         => $docCount,
        ];
    }
    $holderName = $row['holder_type'] === 'individual'
        ? trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))
        : (string)($row['org_name'] ?? vocab('holder'));
    $ptLabels = ['physician'=>'Physician','nurse'=>'Nurse / Advanced Practice','lab'=>'Laboratory','clinic'=>'Clinic','group'=>'Medical Group'];
    $subBits = [];
    if (!empty($row['provider_type']) && isset($ptLabels[$row['provider_type']])) $subBits[] = $ptLabels[$row['provider_type']];
    elseif ($row['holder_type'] === 'organization') $subBits[] = 'Organization';
    if (!empty($row['license_code'])) $subBits[] = strtoupper(str_replace('_', '-', (string)$row['license_code']));
    $trow = FlxCoreHC\Database::pdo()->prepare('SELECT name, brand_name FROM tenants WHERE id = ?');
    $trow->execute([$ctx->tenantId]); $t = $trow->fetch() ?: [];
    $pdfBytes = FlxCoreHC\CvService::build([
        'org'         => (string)(($t['brand_name'] ?? '') ?: ($t['name'] ?? $ctx->tenantName)),
        'holderName'  => $holderName,
        'roleWord'    => vocab('holder'),
        'subtitle'    => implode(' · ', $subBits),
        'email'       => (string)($row['email'] ?? ''),
        'npi'         => (string)($row['npi'] ?? ''),
        'creds'       => $credsOut,
        'generatedBy' => $ctx->userName ?? '',
    ]);
    audit_log($ctx, 'cv_generated', 'holders', (string)$row['id'], null);
    $fn = preg_replace('/[^A-Za-z0-9]+/', '-', $holderName) ?: 'cv';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fn . '-credential-cv.pdf"');
    header('X-Content-Type-Options: nosniff');
    echo $pdfBytes; exit;
}

function handle_case_document_upload(FlxCoreHC\Repository\CaseDocumentRepository $caseDocs, TenantContext $ctx, string $holderId, ?string $caseId, ?string $docType): array
{
    // §7 — reuse the SAME storage/security convention as credential documents (size, plan quota, MIME
    // allowlist, metadata stripping, per-tenant path). Never touches credential_documents semantics.
    if (empty($_FILES['document']['tmp_name']) || !is_uploaded_file($_FILES['document']['tmp_name'])) return [false, 'Choose a file to upload.'];
    $f = $_FILES['document'];
    if ($f['size'] > 10 * 1024 * 1024) return [false, 'File too large (10 MB max).'];
    if (!FlxCoreHC\PlanService::canStoreFile($ctx->tenantId, (int) $f['size'])) return [false, 'Document storage limit reached.'];
    $allowed = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/heic'=>'heic','image/heif'=>'heic','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    if (!isset($allowed[$mime])) return [false, 'Only PDF, JPG, PNG, HEIC, or DOCX files are accepted.'];
    if ($mime === 'image/jpeg') { FlxCoreHC\ImageSanitizer::stripJpegMetadata($f['tmp_name']); }
    $dir = rtrim((string) config('docs_path'), '/') . '/' . $ctx->tenantId;
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) return [false, 'Storage directory is not writable.'];
    $dest = $dir . '/' . uuid4() . '.' . $allowed[$mime];
    $__sum = hash_file('sha256', $f['tmp_name']) ?: null;
    if (!FlxCoreHC\EncryptedFile::store($f['tmp_name'], $dest)) return [false, 'Upload failed. Try again.'];
    $id = $caseDocs->insert([
        'case_id' => $caseId, 'holder_id' => $holderId, 'doc_type' => $docType,
        'file_path' => $dest, 'original_name' => mb_substr((string)($f['name'] ?? ''), 0, 255),
        'mime_type' => $mime, 'byte_size' => (int) $f['size'], 'checksum' => $__sum,
        'uploaded_by' => $ctx->userId ?: null,
    ]);
    FlxCoreHC\ComplianceEventService::record($ctx, 'case_document_added', 'Case document uploaded',
        ['holder_id' => $holderId, 'case_id' => $caseId, 'detail' => ['doc_type' => $docType]]);
    return [true, $id];
}

function handle_document_upload(DocumentRepository $documents, TenantContext $ctx, string $credId, CredentialRepository $credentials): void
{
    if (empty($_FILES['document']['tmp_name']) || !is_uploaded_file($_FILES['document']['tmp_name'])) {
        flash('Choose a file to upload.', 'error');
        return;
    }
    $f = $_FILES['document'];
    if ($f['size'] > 10 * 1024 * 1024) {
        flash('File too large (10 MB max).', 'error');
        return;
    }
    if (!FlxCoreHC\PlanService::canStoreFile($ctx->tenantId, (int) $f['size'])) {
        $lim = FlxCoreHC\PlanService::usage($ctx->tenantId)['storage_mb'] ?? null;
        flash($lim ? t('Document storage limit reached ({used} of {limit} MB used). Upgrade the plan or remove old documents.', ['used' => (int)$lim['used'], 'limit' => (int)$lim['limit']]) : t('Document storage limit reached (plan quota). Upgrade the plan or remove old documents.'), 'error');
        return;
    }
    $allowed = [
        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
        'image/heic' => 'heic', 'image/heif' => 'heic',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    if (!isset($allowed[$mime])) {
        flash('Only PDF, JPG, PNG, HEIC, or DOCX files are accepted.', 'error');
        return;
    }
    // Privacy: strip metadata (incl. GPS) from JPEG photos before storing. Rewrites the file keeping
    // only image scan segments — no image library required, so it works on bare shared hosting.
    if ($mime === 'image/jpeg') { FlxCoreHC\ImageSanitizer::stripJpegMetadata($f['tmp_name']); }
    $dir = rtrim((string) config('docs_path'), '/') . '/' . $ctx->tenantId;
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        flash('Storage directory is not writable. Check docs_path in config.', 'error');
        return;
    }
    $dest = $dir . '/' . uuid4() . '.' . $allowed[$mime];
    if (!FlxCoreHC\EncryptedFile::store($f['tmp_name'], $dest)) {
        flash('Upload failed. Try again.', 'error');
        return;
    }
    // A prior document on this credential is treated as superseded by the new one — the old file is
    // retained (never deleted), and the renewal chain becomes a visible timeline.
    $prior = $documents->all(['credential_id' => $credId], 'created_at DESC', 1);
    $supersedes = $prior[0]['id'] ?? null;
    $kind = in_array($_POST['kind'] ?? '', ['primary','renewal','supporting','correspondence','other'], true) ? $_POST['kind'] : 'primary';
    $docId = $documents->insert([
        'credential_id' => $credId,
        'supersedes_id' => $kind === 'primary' || $kind === 'renewal' ? $supersedes : null,  // only a primary/renewal replaces the prior primary
        'kind'          => $kind,
        'file_path'     => $dest,
        'original_name' => basename((string) $f['name']),
        'mime_type'     => $mime,
        'size_bytes'    => (int) $f['size'],
        'uploaded_by'   => $ctx->userId,
    ]);
    $credRow = $credentials->find($credId);
    $holderId = $credRow['holder_id'] ?? null;
    FlxCoreHC\ComplianceEventService::record($ctx, $supersedes ? 'document_replaced' : 'document_uploaded',
        ($supersedes ? 'Document replaced: ' : 'Document uploaded: ') . basename((string) $f['name'])
            . ($credRow ? ' on ' . ($credRow['name'] ?? 'credential') : ''),
        ['holder_id' => $holderId, 'credential_id' => $credId, 'document_id' => $docId,
         'detail' => ['file' => basename((string) $f['name']), 'bytes' => (int) $f['size'], 'supersedes' => $supersedes]]);
    flash('Document attached.', 'ok');
}

/**
 * Build the credential-compliance dataset: every producer with their assigned
 * requirement tokens resolved to labeled rows + live status, plus tenant totals.
 */
/**
 * FL CE cycle window for a producer: biennial, ending the last day of the
 * birth month (F.S. 626.2815). Without a known parity year we anchor to the
 * next end-of-birth-month occurrence; entries in the trailing 24 months count.
 */
// Deleting records is an owner/manager action; staff and agents do data entry only.
function require_manager(FlxCoreHC\TenantContext $ctx): void
{
    // Kept for compatibility; the answer now comes from the central Authz matrix
    // (which also demotes everyone in a non-active tenant to read_only).
    FlxCoreHC\Authz::requireCap($ctx, 'org.manage');
}

function flx_public_base(): string
{
    // Build the public origin + app base path from the current request, so share links
    // work on shared hosting in a subfolder without any configured base URL.
    $https  = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['SERVER_PORT'] ?? '') === '443')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . rtrim(BASE_PATH, '/');
}

function flx_compliance_data($holders, $credentials, $producerReqs, $regulatorReqs, $regulators, string $orgShape, ?array $lines = null, $documents = null, ?array $states = null, $holderRegulators = null): array
{
    $catByCode = [];
    // Include the tenant's custom document types, derived from the (tenant-scoped) holders repo —
    // otherwise a custom credential would show as unrecognized in compliance views.
    foreach (FlxCoreHC\CatalogService::all(null, null, null, $holders->tenantId()) as $cat) { $catByCode[$cat['code']] = $cat; }
    $regulatorsById = [];
    foreach ($regulators->all([], 'name ASC') as $car) { $regulatorsById[$car['id']] = $car; }
    $customById = $regulatorReqs->mapById();
    $assignMap  = $producerReqs->map();
    $docCounts  = $documents ? $documents->countByCredential() : [];

    // Which regulators each provider must answer to, and the requirement tokens each regulator
    // contributes. A credential requirement becomes "<code>@<regulatorId>" so it resolves through
    // the catalog and displays grouped under that regulator; a custom requirement becomes "cr:<id>".
    $holderRegMap = $holderRegulators ? $holderRegulators->map() : [];
    $reqsByRegulator = [];
    foreach ($regulatorReqs->byRegulator() as $rid => $rows) {
        foreach ($rows as $rq) {
            if (($rq['kind'] ?? 'credential') === 'credential' && !empty($rq['catalog_code'])) {
                $reqsByRegulator[$rid][] = $rq['catalog_code'] . '@' . $rid;
            } else {
                $reqsByRegulator[$rid][] = 'cr:' . $rq['id'];
            }
        }
    }

    $resolve = function (string $tok) use ($catByCode, $regulatorsById, $customById): ?array {
        [$code, $regulatorId] = FlxCoreHC\Repository\ProducerRequirementRepository::parse($tok);
        if (str_starts_with($code, 'cr:')) {
            $req = $customById[substr($code, 3)] ?? null;
            if (!$req) return null;                                   // requirement was deleted
            $car = $regulatorsById[$req['regulator_id']] ?? null;
            return ['token' => $tok, 'name' => $req['name'], 'authority' => 'regulator',
                'regulator' => $car['name'] ?? null, 'category' => $req['category'],
                'renewal' => $req['renewal_months']];
        }
        $cat = $catByCode[$code] ?? null;
        if (!$cat) return null;
        $car = $regulatorId ? ($regulatorsById[$regulatorId] ?? null) : null;
        if ($regulatorId && !$car) return null;                          // regulator was deleted
        return ['token' => $tok, 'name' => $cat['name'], 'authority' => $cat['authority'],
            'regulator' => $car['name'] ?? null, 'category' => $cat['category'],
            'renewal' => $cat['renewal_months']];
    };

    $producers = [];
    $tot = 0; $active = 0; $expiring = 0; $expired = 0; $missing = 0;
    foreach ($holders->all([], 'holder_type DESC, last_name, org_name') as $h) {
        $role = FlxCoreHC\CatalogService::holderRole($h, $orgShape);
        $creds = $credentials->all(['holder_id' => $h['id']], 'expiry_date ASC');
        $cover = FlxCoreHC\CatalogService::coverageDetail($creds);
        $tokens = $assignMap[$h['id']] ?? FlxCoreHC\CatalogService::requiredCodesForRole($role, $lines, $states);
        $usingDefaults = !isset($assignMap[$h['id']]);
        // Merge in requirements inherited from the regulators this provider is assigned to.
        foreach (($holderRegMap[$h['id']] ?? []) as $rid) {
            foreach (($reqsByRegulator[$rid] ?? []) as $rtok) { $tokens[] = $rtok; }
        }
        $tokens = array_values(array_unique($tokens));
        $rows = []; $pActive = 0; $pExpiring = 0;
        foreach ($tokens as $tok) {
            $def = $resolve($tok);
            if (!$def) continue;
            // Coverage: try the exact token, then fall back to the base catalog code for a
            // regulator-scoped credential (holding an active NPI satisfies "CMS requires NPI"
            // regardless of which regulator asked for it).
            $d = $cover[$tok] ?? null;
            if ($d === null && ($def['authority'] ?? '') !== 'regulator') {
                [$baseCode] = FlxCoreHC\Repository\ProducerRequirementRepository::parse($tok);
                $d = $cover[$baseCode] ?? null;
            }
            $st = $d['status'] ?? 'missing';
            $def['status'] = $st;
            $def['credential_id']   = $d['credential_id'] ?? null;
            $def['credential_name'] = $d['credential_name'] ?? null;
            $def['expiry']          = $d['expiry_date'] ?? null;
            $def['evidence']        = $d ? (int)($docCounts[$d['credential_id']] ?? 0) : 0;
            $rows[] = $def;
            $tot++;
            match ($st) {
                'active'   => [$active++, $pActive++],
                'expiring' => [$expiring++, $pExpiring++],
                'expired'  => $expired++,
                default    => $missing++,
            };
        }
        usort($rows, fn($a, $b) => [$a['regulator'] ?? '', $a['authority']] <=> [$b['regulator'] ?? '', $b['authority']]);
        $n = count($rows);
        $producers[] = ['holder' => $h, 'role' => $role, 'rows' => $rows, 'defaults' => $usingDefaults,
            'total' => $n, 'active' => $pActive,
            'pct' => $n > 0 ? (int) round($pActive * 100 / $n) : 100,
            // Graded compliance score: active = 1 point, expiring = 1/2, expired/missing = 0.
            'score' => $n > 0 ? (int) round(($pActive + $pExpiring * 0.5) * 100 / $n) : null];
    }
    usort($producers, fn($a, $b) => $a['pct'] <=> $b['pct']);          // worst first
    return ['producers' => $producers, 'totals' => [
        'items' => $tot, 'active' => $active, 'expiring' => $expiring,
        'expired' => $expired, 'missing' => $missing,
        'pct' => $tot > 0 ? (int) round($active * 100 / $tot) : 100,
        'score' => $tot > 0 ? (int) round(($active + $expiring * 0.5) * 100 / $tot) : null,
        'satisfied' => $active + $expiring,
        'evidenced' => array_sum(array_map(
            fn($p) => count(array_filter($p['rows'], fn($r) => $r['status'] !== 'missing' && $r['status'] !== 'expired' && $r['evidence'] > 0)),
            $producers)),
        'compliant_producers' => count(array_filter($producers, fn($p) => $p['pct'] === 100 && $p['total'] > 0)),
        'producer_count' => count($producers),
    ]];
}
