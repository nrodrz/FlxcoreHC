<?php
declare(strict_types=1);

/**
 * FlxCoreHC bootstrap — include this from public/index.php and cron scripts.
 */

define('FLXCOREHC_APP', __DIR__);
define('FLXCOREHC_VERSION', '1.173.0');   // shown on System & updates; bump with each release
// Which vertical this installation serves: 'insurance', 'healthcare', or 'both'.
// The edition locks registration/installer org shapes and the demo builders, so a
// single-vertical app can never grow tenants of the other industry. Everything downstream
// (nav, training, templates, vocabulary) already follows the tenant's vertical.
if (!defined('FLXCOREHC_EDITION')) define('FLXCOREHC_EDITION', 'healthcare');
// Product display name + tagline (what users see). The PHP namespace stays FlxCoreHC\ until the
// dedicated deep-rename pass; this constant is the single source of truth for the shown brand.
if (!defined('FLXCOREHC_APP_NAME')) define('FLXCOREHC_APP_NAME', 'FlxCoreHC');
if (!defined('FLXCOREHC_TAGLINE'))  define('FLXCOREHC_TAGLINE', 'Healthcare Compliance Intelligence Platform');

/** org_shape keys this edition may create (v1.170.7: healthcare-only; 'platform' is the operator). */
function edition_shapes(): array
{
    $all = FlxCoreHC\Auth\AuthService::ORG_SHAPES;
    if (FLXCOREHC_EDITION === 'both') return array_keys($all);
    $out = [];
    foreach ($all as $key => [$vertical, $tier, $label]) {
        if ($vertical === 'healthcare' || $vertical === 'platform') $out[] = $key;
    }
    return $out;
}

define('FLXCOREHC_ROOT', dirname(__DIR__));

/** v1.171.5 — toast style for a flash message: 'error' for refusals/failures, else 'ok' (callers can pass a type explicitly). */
function flx_flash_type(string $m): string
{
    return preg_match('/\b(incorrect|invalid|not allowed|not permitted|cannot|can\'t|could not|couldn\'t|refused|forbidden|denied|failed|must be|must match|must not|do not match|does not match|required|expired|not found|read-only|too many|unable|only an? |only owners|no longer|wrong|is not valid|not a valid|not configured|nothing to|can only|exceed|at least|at most|use a |enter a |choose a |select a |please )\b/i', $m) ? 'error' : 'ok';
}

/**
 * v1.171.3 — where the configuration file lives. Default app/config.php; the operator/test harness may point
 * FLX_CONFIG (a real environment variable — never request input) at another file so tests never have to swap the real one.
 */
function flx_config_path(): string
{
    $e = getenv('FLX_CONFIG');
    return ($e !== false && $e !== '') ? $e : __DIR__ . '/config.php';   // a missing FLX_CONFIG file means "not configured", never a silent fallback
}


// Fail-safe default: never display errors to the browser until config is loaded and
// explicitly opts into dev mode below. This protects production even if config.php is
// missing, malformed, or still carries an old app_env value.
if (PHP_SAPI !== 'cli') { @ini_set('display_errors', '0'); }

// Web base path — supports installs in a subfolder (e.g. /flxcred). Empty at docroot.
if (!defined('BASE_PATH')) {
    if (PHP_SAPI === 'cli') {
        // v1.170.8: under cron, SCRIPT_NAME is a filesystem path (…/cron), which used to leak into links
        // stored by notifications. Take the web base path from the configured app_url (subfolder installs)
        // — config() is defined later in this file, so read the config array directly here.
        $__cfgFile = flx_config_path();
        $__cfg = is_file($__cfgFile) ? (@include $__cfgFile) : [];
        $__p = is_array($__cfg) ? rtrim((string) parse_url((string) ($__cfg['app_url'] ?? ''), PHP_URL_PATH), '/') : '';
        define('BASE_PATH', $__p === '/' ? '' : $__p);
    } else {
        $__sn = (string) ($_SERVER['SCRIPT_NAME'] ?? '');   // v1.172.14: see index.php — a non-.php SCRIPT_NAME is a request path, not a script
        $__b = str_ends_with($__sn, '.php') ? rtrim(str_replace('\\', '/', dirname($__sn)), '/') : '';
        define('BASE_PATH', $__b === '/' ? '' : $__b);
    }
}

// ---- Config -----------------------------------------------------------
$configFile = flx_config_path();
if (!is_file($configFile)) {
    http_response_code(500);
    exit('FlxCoreHC is not configured. Copy app/config.sample.php to app/config.php and edit it.');
}
$GLOBALS['flxcred_config'] = require $configFile;

/** The per-response CSP nonce. Put this on every script tag we emit. */
function csp_nonce(): string
{
    return (string) ($GLOBALS['flx_csp_nonce'] ?? '');
}

/** Ready-to-print nonce attribute, e.g. <script {nonce_attr()}> */
function nonce_attr(): string
{
    $n = csp_nonce();
    return $n === '' ? '' : 'nonce="' . htmlspecialchars($n, ENT_QUOTES, 'UTF-8') . '"';
}

function config(string $key, mixed $default = null): mixed
{
    return $GLOBALS['flxcred_config'][$key] ?? $default;
}

// ---- Error handling (fail safe: never leak stack traces to browsers) ----
error_reporting(E_ALL);
ini_set('log_errors', '1');

/**
 * Record a failure into the error_log table so the admin can see it without shell access.
 *
 * Defensive by design: this runs from inside an error handler, so it must never throw. Any failure
 * to record (DB down, table missing pre-migration) is swallowed — the original error still reaches
 * PHP's error_log() regardless. It deliberately stores no stack trace and no request body, only the
 * request path with its query string stripped, to avoid capturing personal data.
 */
function flx_log_error(string $level, string $message, array $ctx = []): void
{
    try {
        if (!class_exists('FlxCoreHC\\Database', false) && !@class_exists('FlxCoreHC\\Database')) return;
        $pdo = FlxCoreHC\Database::pdo();
        $path = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (($qp = strpos($path, '?')) !== false) $path = substr($path, 0, $qp);
        $pdo->prepare(
            'INSERT INTO error_log (id, created_at, level, error_class, message, file, line, path, method, tenant_id, user_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            (function () { try { return uuid4(); } catch (\Throwable $e) { return bin2hex(random_bytes(16)); } })(),
            gmdate('Y-m-d H:i:s'),
            substr($level, 0, 12),
            isset($ctx['class']) ? substr((string) $ctx['class'], 0, 160) : null,
            substr($message, 0, 500),
            isset($ctx['file']) ? substr((string) $ctx['file'], 0, 300) : null,
            $ctx['line'] ?? null,
            substr($path, 0, 300) ?: null,
            substr((string) ($_SERVER['REQUEST_METHOD'] ?? ''), 0, 10) ?: null,
            $GLOBALS['flx_ctx']->tenantId ?? null,
            $GLOBALS['flx_ctx']->userId ?? null,
        ]);
    } catch (\Throwable $e) {
        // Recording the error must never break the request. The original still went to error_log().
    }
}

if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', config('app_env', 'production') === 'dev' ? '1' : '0');
    set_exception_handler(function (\Throwable $e): void {
        error_log('[FlxCoreHC] Uncaught ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine());
        flx_log_error('error', get_class($e) . ': ' . $e->getMessage(),
            ['class' => get_class($e), 'file' => $e->getFile(), 'line' => $e->getLine()]);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        if (config('app_env', 'production') === 'dev') {
            echo '<pre>' . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>';
        } else {
            echo 'Something went wrong on our side. The error has been logged — please try again.';
        }
        exit(1);
    });

    // Fatal errors bypass the exception handler; catch them on shutdown so they're recorded too.
    register_shutdown_function(function (): void {
        $e = error_get_last();
        if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            flx_log_error('fatal', (string) $e['message'],
                ['class' => 'FatalError', 'file' => $e['file'] ?? null, 'line' => $e['line'] ?? null]);
        }
    });
}

// ---- Autoloader (no Composer needed) ----------------------------------
spl_autoload_register(function (string $class): void {
    $prefix = 'FlxCoreHC\\';
    if (str_starts_with($class, $prefix)) {
        $path = FLXCOREHC_APP . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

// v1.171.10 (REQUIRED 1, v1.171.9 review): PHP ext-intl is a production prerequisite — names are compared with Unicode NFKC and
// production must never fall back to a weaker algorithm silently. Web requests stop with a clear message; CLI tools and dev/test
// installations continue (tests exercise the "missing" path through TextNorm::$assumeNormalizerMissing).
if (PHP_SAPI !== 'cli' && config('app_env', 'production') === 'production' && ($__pre = FlxCoreHC\TextNorm::prerequisiteError()) !== null) {
    error_log('[FlxCoreHC] prerequisite missing: ' . $__pre);
    if (!headers_sent()) { http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); }
    exit('FlxCoreHC cannot start: ' . $__pre);
}


// ---- Helpers -----------------------------------------------------------
function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/** Current UTC timestamp for DATETIME columns. */
/**
 * Write a mutation/action to the audit_logs table — the table the Audit trail view reads.
 * Use this (not AccessLog, which is a separate view/read log) for anything that should show up
 * in Organization → Audit: syncs, lookups, credential changes, etc. Best-effort; never throws.
 */
function audit_log(FlxCoreHC\TenantContext $ctx, string $action, ?string $entityType = null, ?string $entityId = null, ?string $changes = null): void
{
    try {
        FlxCoreHC\Database::pdo()->prepare(
            "INSERT INTO audit_logs (id, tenant_id, user_id, action, entity_type, entity_id, changes, ip_address, created_at)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([uuid4(), $ctx->tenantId, $ctx->userId, $action, $entityType, $entityId, $changes,
            substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), utc_now()]);
    } catch (\Throwable $e) { /* audit logging must never break the request */ }
}

/**
 * Public origin + base path that also works outside a web request (cron). Uses the current request
 * when available, else the configured app_url, else a bare relative base.
 */
function flx_public_base_safe(): string
{
    // A configured app_url always wins: links in emails must never be derived from a client-supplied
    // Host header (host-header poisoning). The Host fallback is only for installs that haven't set it.
    $cfg = (string) config('app_url', '');
    if ($cfg !== '') return rtrim($cfg, '/');
    if (!empty($_SERVER['HTTP_HOST'])) {
        $https  = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['SERVER_PORT'] ?? '') === '443')
                  || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(BASE_PATH, '/');
    }
    $url = (string) config('app_url', '');
    return $url !== '' ? rtrim($url, '/') : rtrim(BASE_PATH, '/');
}

function utc_now(): string{
    return gmdate('Y-m-d H:i:s');
}

/** HTML-escape for views. */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * v1.171.0 (S-4) — JSON for embedding inside an inline <script>. Plain json_encode() leaves "<", ">" and "&" alone, so a
 * value such as  </script><script>alert(1)  (a provider's name, a credential label…) closes the script block and runs.
 * The HEX flags turn those characters into \u003C-style escapes, which are inert in HTML and identical once parsed as JS.
 */
function flx_json(mixed $value, int $extraFlags = 0): string
{
    $j = json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | $extraFlags);
    return $j === false ? 'null' : $j;
}

/**
 * v1.171.0 (B-6) — PHP 8.4 deprecates fputcsv()/fgetcsv()/str_getcsv() called without an explicit $escape
 * argument (every export emitted a "Deprecated" line into the CSV/PDF stream on 8.4). These wrappers pass
 * $escape = '' (RFC 4180: a quote is escaped by doubling it, backslash is not special).
 */
function flx_fputcsv($stream, array $fields): int|false
{
    return fputcsv($stream, $fields, ',', '"', '');
}
function flx_str_getcsv(string $line): array
{
    return str_getcsv($line, ',', '"', '');
}

/**
 * v1.170.8 (security) — CSV formula-injection guard. A cell beginning with = + - @ (or a tab/CR) is
 * interpreted as a formula by Excel/Sheets; prefix it with an apostrophe so exports are inert. Numeric
 * values are left alone (negative numbers stay numbers).
 */
/** CE/CME look-back in months for the tenant's jurisdictions: Puerto Rico recertifies every 36 months; others 24 unless configured. */
function flx_ce_months(array $states): int
{
    return in_array('PR', array_map('strtoupper', $states), true) ? 36 : 24;
}

function flx_ce_cycle(?int $birthMonth, int $months = 24): array
{
    if ($birthMonth === null || $birthMonth < 1 || $birthMonth > 12) {
        $end = new DateTimeImmutable('today');   // trailing window ending today
        return ['start' => $end->modify("-$months months")->modify('+1 day'), 'end' => $end, 'exact' => false, 'months' => $months];
    }
    $end = new DateTimeImmutable(sprintf('last day of %04d-%02d', (int)date('Y'), $birthMonth));
    if ($end < new DateTimeImmutable('today')) { $end = $end->modify('+1 year'); $end = new DateTimeImmutable('last day of ' . $end->format('Y-m')); }
    return ['start' => $end->modify("-$months months")->modify('+1 day'), 'end' => $end, 'exact' => true, 'months' => $months];
}


function csv_safe_row(array $row): array
{
    return array_map(function ($v) {
        if (is_int($v) || is_float($v)) return $v;
        $s = (string) $v;
        return preg_match('/^[=+\-@\t\r]/', $s) && !is_numeric($s) ? "'" . $s : $s;
    }, $row);
}

function redirect(string $path): never
{
    // Defense in depth against open redirects: this app only ever redirects within itself. Anything
    // that isn't an in-app relative path (absolute URLs, protocol-relative //host, CR/LF injection)
    // is replaced by the app root.
    if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || preg_match('/[\r\n]/', $path)
        || str_contains($path, '://') || str_contains($path, '\\')) {   // browsers normalise "/\evil" to "//evil"
        $path = '/';
    }
    if (!str_starts_with($path, BASE_PATH . '/') && $path !== BASE_PATH) {
        $path = BASE_PATH . $path;
    }
    header('Location: ' . $path);
    exit;
}

// ---- Session (web only) -------------------------------------------------
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    // ---- Content-Security-Policy nonce -------------------------------------
    // A fresh random nonce per response. Every script tag we emit carries it, which lets us
    // drop 'unsafe-inline' from script-src — without that, CSP cannot stop an injected
    // injected script from running, which is the whole point of having a policy.
    $GLOBALS['flx_csp_nonce'] = bin2hex(random_bytes(16));

    // HTTPS detection that also works behind a TLS-terminating proxy/CDN,
    // plus a config override for hosts where neither signal is reliable.
    $isHttps = !empty($_SERVER['HTTPS'])
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (bool) config('force_secure_cookies', false);

    // ---- Security headers (defense in depth on every response) ----
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Deny browser features this app never uses, so injected content can't reach for them.
    header('Permissions-Policy: geolocation=(), camera=(self), microphone=(), payment=(), '
        . 'usb=(), magnetometer=(), gyroscope=(), accelerometer=(), interest-cohort=()');
    // Isolate our browsing context from anything we open or that opens us.
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    // HSTS only over HTTPS — sending it over plain HTTP is ignored, and setting it on a
    // host that can't do TLS would lock users out. 1 year, subdomains included.
    if ($isHttps && (bool) config('hsts', true)) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    // Fonts: 'self' (default) serves the bundled woff2 files from assets/fonts — no third-party
    // request, and no Google origins in the policy below. 'system' drops web fonts entirely in
    // favour of the OS stack; 'google' restores the CDN if you prefer it.
    $fontMode  = (string) config('fonts', 'self');
    $styleSrc  = $fontMode === 'google' ? " https://fonts.googleapis.com" : '';
    $fontSrc   = $fontMode === 'google' ? " https://fonts.gstatic.com" : '';
    $nonce     = $GLOBALS['flx_csp_nonce'];
    header("Content-Security-Policy: default-src 'self'; "
        // 'strict-dynamic' is ignored by CSP2 browsers (which fall back to the host allowlist)
        // and honoured by CSP3 ones; no 'unsafe-inline' in either case.
        . "script-src 'self' 'nonce-{$nonce}' https://js.stripe.com; "
        // style-src keeps 'unsafe-inline': the app uses inline style attributes extensively for
        // layout, and inline CSS is a far weaker vector than inline script.
        . "style-src 'self' 'unsafe-inline'{$styleSrc}; "
        . "font-src 'self' data:{$fontSrc}; img-src 'self' data:; "
        . "frame-src https://js.stripe.com https://hooks.stripe.com; "
        . "connect-src 'self' https://api.stripe.com; base-uri 'self'; object-src 'none'; "
        . "form-action 'self'; frame-ancestors 'none'");
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('FLXCOREHCSESSID');
    session_start();

    // --- Session timeouts (audit H4): idle + absolute caps ---
    $idleMax     = (int) config('session_idle_seconds', 1800);      // 30 min inactivity
    $absoluteMax = (int) config('session_absolute_seconds', 43200); // 12 h hard cap
    $now = time();
    if (!empty($_SESSION['auth'])) {
        $started = $_SESSION['auth_started_at'] ?? $now;
        $seen    = $_SESSION['auth_last_seen']  ?? $now;
        if (($now - $seen) > $idleMax || ($now - $started) > $absoluteMax) {
            $_SESSION = [];
            session_regenerate_id(true);
        } else {
            $_SESSION['auth_last_seen'] = $now;
        }
    }
}

/**
 * Best-effort invitation email. Sends via mail() only when 'mail_enabled' is true;
 * returns whether an email was actually dispatched. The caller ALWAYS also shows the
 * invite link on-screen so invites work even without SMTP configured.
 */
function send_invite_email(string $to, string $name, string $link, string $orgName): bool
{
    // Compose in the recipient's language when we know it (existing user row), else default per config.
    $loc = 'en';
    try {
        $st = \FlxCoreHC\Database::pdo()->prepare('SELECT locale FROM users WHERE email = ? AND locale <> \'\' LIMIT 1');
        $st->execute([$to]);
        $loc = (string) ($st->fetchColumn() ?: config('default_locale', 'en'));
    } catch (\Throwable $e) { $loc = (string) config('default_locale', 'en'); }
    if (!isset(\FlxCoreHC\I18n::LOCALES[$loc])) $loc = 'en';

    $tl = fn(string $en, array $v = []) => \FlxCoreHC\I18n::tl($loc, $en, $v);
    $subject = $tl('You have been invited to {org} on FlxCoreHC', ['org' => $orgName]);
    $body    = $tl('Hello {name},', ['name' => $name]) . "\n\n"
             . $tl('You have been invited to join {org} on FlxCoreHC.', ['org' => $orgName]) . "\n"
             . $tl('Set your password and sign in here:') . "\n\n" . $link . "\n\n"
             . $tl('This link expires in 7 days.') . "\n";
    $id = \FlxCoreHC\MailService::queue($to, $subject, $body, 'invite', $name);
    if ($id === null) return false;
    \FlxCoreHC\MailService::flush();
    return true;
}

/** Absolute URL for an invitation token, honoring subfolder installs. */
function invite_link(string $token): string
{
    // Same hardening as flx_public_base_safe(): configured app_url first, Host header only as fallback.
    return flx_public_base_safe() . '/invite/' . $token;
}

/** Whether a plan feature is enabled for the current request's tenant. */
function feature_on(string $code): bool
{
    return in_array($code, $GLOBALS['flx_features'] ?? [], true);
}

/**
 * Vertical-aware noun for the current organization. "producer" for an insurance agency,
 * "provider" for a healthcare organization. Presentation only — see FlxCoreHC\Vocab. Reads the
 * vertical from the resolved request context; falls back to insurance wording if none is set.
 */
function vocab(string $key): string
{
    $vertical = $GLOBALS['flx_ctx']->vertical ?? 'healthcare';
    return \FlxCoreHC\Vocab::term($key, $vertical);
}

/** Translate an English UI string to the active locale (Spanish for the PR market), else unchanged.
 *  Optional named-placeholder interpolation: t('{n} providers', ['n' => $count]). */
function t(string $en, array $vars = []): string
{
    return \FlxCoreHC\I18n::t($en, $vars);
}


/**
 * Securely handle a brand logo upload. Validates it is a real raster image, stores it in
 * the public uploads/ dir (random server-generated name), and falls back to a data-URI in
 * the DB if that dir is not writable. Returns [ok, message].
 */
function handle_logo_upload(\FlxCoreHC\TenantContext $ctx, \FlxCoreHC\OrgService $org): array
{
    $f = $_FILES['logo'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [false, 'Choose an image file.'];
    if ($f['error'] !== UPLOAD_ERR_OK) return [false, 'Upload failed. Please try again.'];
    if ($f['size'] > 1048576) return [false, 'Logo must be under 1 MB.'];
    $info = @getimagesize($f['tmp_name']);
    if ($info === false) return [false, 'That file is not a valid image.'];
    $allowed = [IMAGETYPE_PNG=>'png', IMAGETYPE_JPEG=>'jpg', IMAGETYPE_GIF=>'gif', IMAGETYPE_WEBP=>'webp'];
    if (!isset($allowed[$info[2]])) return [false, 'Use a PNG, JPG, GIF, or WebP image.'];
    $ext = $allowed[$info[2]];
    $data = file_get_contents($f['tmp_name']);
    // remove any previous logo file
    $old = $org->get()['logo_path'] ?? null;
    if ($old && !str_starts_with($old, 'data:')) { @unlink(FLXCOREHC_ROOT . '/' . ltrim($old, '/')); }
    $dir = FLXCOREHC_ROOT . '/uploads';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $name = 'logo_' . $ctx->tenantId . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (is_dir($dir) && is_writable($dir) && @file_put_contents($dir . '/' . $name, $data) !== false) {
        $org->setLogo('uploads/' . $name);
        return [true, 'Logo updated.'];
    }
    // fallback: inline data URI (works even when the filesystem is not writable)
    $mime = image_type_to_mime_type($info[2]);
    $org->setLogo('data:' . $mime . ';base64,' . base64_encode($data));
    return [true, 'Logo updated.'];
}

/** Display helpers — DB stores UTC; the app shows Eastern, 12-hour, MM/DD/YYYY by default. */
/** v1.172.16: the time zones an organization (or the platform) may choose. Value = what the picker shows. */
function flx_timezones(): array {
    return [
        'America/Puerto_Rico' => 'Atlantic — Puerto Rico (AST)', 'America/New_York' => 'Eastern (EST/EDT)',
        'America/Chicago' => 'Central', 'America/Denver' => 'Mountain', 'America/Los_Angeles' => 'Pacific',
        'America/Anchorage' => 'Alaska', 'Pacific/Honolulu' => 'Hawaii', 'UTC' => 'UTC',
    ];
}
/** The platform's own zone: platform setting (operator-chosen, no file edit) → config.php → Eastern. Used when an organization has none. */
function app_tz_platform_name(): string {
    static $name = null;
    if ($name !== null) return $name;
    $n = '';
    try { if (class_exists('FlxCoreHC\\Database', false) || @class_exists('FlxCoreHC\\Database')) { $v = FlxCoreHC\SettingsService::get('app_timezone'); if ($v) $n = (string) $v; } } catch (\Throwable $e) {}
    if ($n === '') { $c = config('app_timezone'); $n = $c ? (string) $c : 'America/New_York'; }   // config.php (set by the installer) when the operator has not chosen one
    try { new \DateTimeZone($n); } catch (\Throwable $e) { $n = 'America/New_York'; }
    return $name = $n;
}
/** v1.172.16: make the signed-in organization's zone the app zone for the rest of the request (null = back to the platform zone).
 *  Also re-points the database's "today" (@flx_today) at that zone's calendar day. */
function app_tz_use_tenant(?string $zone): void {
    $zone = ($zone !== null && isset(flx_timezones()[$zone])) ? $zone : null;
    if (($GLOBALS['flx_tenant_tz'] ?? null) === $zone) return;
    $GLOBALS['flx_tenant_tz'] = $zone;
    try { FlxCoreHC\Database::syncToday(); } catch (\Throwable $e) {}
}
/** The zone in force right now: the organization's if one is in use, else the platform's. */
function app_tz(): \DateTimeZone {
    static $cache = [];
    $name = $GLOBALS['flx_tenant_tz'] ?? app_tz_platform_name();
    return $cache[$name] ??= new \DateTimeZone($name);
}
function fmt_dt(?string $utc, string $fmt = 'm/d/Y g:i A'): string {
    if (!$utc) return '';
    try { return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(app_tz())->format($fmt); }
    catch (\Throwable $e) { return (string) $utc; }
}
/** Today's calendar day (Y-m-d) in the app timezone — use instead of date('Y-m-d'), which follows PHP's default (UTC). */
function today_local(string $modify = ''): string {
    $d = new \DateTimeImmutable('now', app_tz());
    if ($modify !== '') $d = $d->modify($modify);
    return $d->format('Y-m-d');
}
/** The app zone's calendar day as a midnight DateTimeImmutable (what "today" means for expiry/deadline maths). */
function today_local_dt(): \DateTimeImmutable {
    return \DateTimeImmutable::createFromFormat('!Y-m-d', today_local()) ?: new \DateTimeImmutable('today');
}
/** A wall-clock time typed in the app zone ("2026-10-20 23:30[:00]", or with a T) -> the UTC string we store. Unparseable input is returned unchanged. */
function local_to_utc(string $local): string {
    $local = str_replace('T', ' ', trim($local));
    if (strlen($local) === 16) $local .= ':00';
    try { return (new \DateTimeImmutable($local, app_tz()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
    catch (\Throwable $e) { return $local; }
}
/** v1.172.14: how a wall-clock time typed in the app zone maps onto the clock. 'ok' · 'gap' (skipped when clocks move forward,
 *  e.g. 02:30 on the spring-forward day — it never happens) · 'ambiguous' (happens twice when clocks move back; the first one is used). */
function local_time_status(string $local): string {
    $local = str_replace('T', ' ', trim($local));
    if (strlen($local) === 16) $local .= ':00';
    try {
        $tz = app_tz(); $utc = new \DateTimeZone('UTC');
        $at = new \DateTimeImmutable($local, $tz);
        $want = (new \DateTimeImmutable($local, $utc))->format('Y-m-d H:i:s');
        if ($at->setTimezone($tz)->format('Y-m-d H:i:s') !== $want) return 'gap';
        foreach ([-3600, 3600] as $off) {
            if ($at->setTimestamp($at->getTimestamp() + $off)->setTimezone($tz)->format('Y-m-d H:i:s') === $want) return 'ambiguous';   // real seconds, not wall-clock arithmetic
        }
    } catch (\Throwable $e) {}
    return 'ok';
}
/** Like local_to_utc(), but when the typed wall-clock time is the one already stored (an edit that did not touch the time) the stored
 *  instant is kept — otherwise re-saving the second 01:30 of a clocks-back night would silently become the first. */
function local_to_utc_keep(string $local, ?string $currentUtc): string {
    $norm = str_replace('T', ' ', trim($local));
    if ($currentUtc && strlen($norm) >= 16 && substr(fmt_dt($currentUtc, 'Y-m-d H:i:s'), 0, 16) === substr($norm, 0, 16)) return $currentUtc;
    return local_to_utc($local);
}
function fmt_date(?string $utc): string { return fmt_dt($utc, 'm/d/Y'); }
function fmt_time(?string $utc): string { return fmt_dt($utc, 'g:i A'); }
/** Rewrites ISO dates/datetimes inside free text (audit details) to local MM/DD/YYYY; datetimes are shifted from UTC. */
function fmt_iso_text(string $s): string {
    $s = preg_replace_callback('/\b(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}(?::\d{2})?)(?:Z|\+00:00)?/', fn($m) => fmt_dt($m[1] . ' ' . $m[2], 'm/d/Y g:i A'), $s);
    return preg_replace_callback('/\b(\d{4})-(\d{2})-(\d{2})\b/', fn($m) => checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $m[2] . '/' . $m[3] . '/' . $m[1] : $m[0], $s);
}
/** Date-only fields (no time component) — format without timezone shifting. */
/** Display-only: turn raw ISO dates / UTC timestamps inside free text (history, change lists) into the reader's format.
 *  "2026-10-15 19:59:32" (UTC) -> "10/15/2026 3:59 PM" in the app zone; "2027-03-14" -> "03/14/2027". Stored text is untouched. */
function fmt_iso_in_text(string $text): string {
    $text = preg_replace_callback('/(?<![\w-])(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})(?![\w-])/', fn($m) => fmt_dt($m[1] . ' ' . $m[2]), $text) ?? $text;
    return preg_replace_callback('/(?<![\w\/-])(\d{4})-(\d{2})-(\d{2})(?![\w-])/', fn($m) => checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $m[2] . '/' . $m[3] . '/' . $m[1] : $m[0], $text) ?? $text;
}
function fmt_day(?string $ymd): string {
    if (!$ymd) return '';
    try { return (new \DateTimeImmutable(substr($ymd, 0, 10)))->format('m/d/Y'); }
    catch (\Throwable $e) { return (string) $ymd; }
}

/** Validate + save an address (tenant or provider). Returns [ok, message]. */
function flx_save_address(\FlxCoreHC\Repository\AddressRepository $A, string $ownerType, string $ownerId, array $in, ?string $existingId = null): array
{
    $line1 = trim((string)($in['line1'] ?? '')); $city = trim((string)($in['city'] ?? '')); $state = strtoupper(trim((string)($in['state'] ?? '')));
    if ($line1 === '' || $city === '' || $state === '') return [false, 'Address needs at least a street, city and state.'];
    $kind = array_key_exists((string)($in['kind'] ?? ''), \FlxCoreHC\Repository\AddressRepository::KINDS) ? $in['kind'] : 'mailing';
    $d = fn(string $k) => (isset($in[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in[$k])) ? $in[$k] : null;
    $data = ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'kind' => $kind,
        'label' => mb_substr(trim((string)($in['label'] ?? '')), 0, 80) ?: null,
        'line1' => mb_substr($line1, 0, 160), 'line2' => mb_substr(trim((string)($in['line2'] ?? '')), 0, 160) ?: null,
        'city' => mb_substr($city, 0, 80), 'state' => mb_substr($state, 0, 8), 'zip' => mb_substr(trim((string)($in['zip'] ?? '')), 0, 12) ?: null,
        'country' => preg_match('/^[A-Za-z]{2}$/', (string)($in['country'] ?? '')) ? strtoupper($in['country']) : 'US',
        'phone' => mb_substr(trim((string)($in['phone'] ?? '')), 0, 40) ?: null, 'fax' => mb_substr(trim((string)($in['fax'] ?? '')), 0, 40) ?: null,
        'effective_from' => $d('effective_from'), 'effective_to' => $d('effective_to'),
        'notes' => mb_substr(trim((string)($in['notes'] ?? '')), 0, 300) ?: null];
    $wantPrimary = !empty($in['is_primary']);
    if ($existingId) { $A->update($existingId, $data); if ($wantPrimary) $A->setPrimary($existingId); return [true, 'Address updated.']; }
    // first address for an owner becomes primary automatically
    $data['is_primary'] = ($wantPrimary || $A->forOwner($ownerType, $ownerId) === []) ? 1 : 0;
    $newId = $A->insert($data);
    if ($data['is_primary']) $A->setPrimary($newId);
    return [true, 'Address added.'];
}

/** Address picker options for a credential: the provider's own addresses first, then the organization's. */
function flx_address_options(\FlxCoreHC\TenantContext $ctx, string $holderId): array
{
    $A = new \FlxCoreHC\Repository\AddressRepository($ctx); $out = [];
    if ($holderId !== '') foreach ($A->forOwner('provider', $holderId) as $a) $out[] = ['id' => $a['id'], 'label' => 'Provider · ' . ($a['label'] ?: ucfirst($a['kind'])) . ' — ' . \FlxCoreHC\Repository\AddressRepository::oneLine($a)];
    foreach ($A->forOwner('tenant', $ctx->tenantId) as $a) $out[] = ['id' => $a['id'], 'label' => 'Organization · ' . ($a['label'] ?: ucfirst($a['kind'])) . ' — ' . \FlxCoreHC\Repository\AddressRepository::oneLine($a)];
    return $out;
}
