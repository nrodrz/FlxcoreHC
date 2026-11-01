<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);
/**
 * FlxCoreHC first-run installer.
 *
 * Extract the package into your web root, create an empty database in cPanel,
 * then open this file in a browser. It checks server requirements, writes
 * app/config.php (with freshly generated encryption keys), and creates all tables.
 *
 * Safety: refuses to run once the app is configured. Delete this file after install.
 */
error_reporting(E_ALL);

// Which vertical this installation serves: 'insurance', 'healthcare', or 'both'.
// Must match FLXCOREHC_EDITION in app/bootstrap.php. Single-vertical editions refuse to
// create a first organization of the other industry.
const FLX_INSTALL_EDITION = 'healthcare';

ini_set('display_errors', '1');           // installer only; the app itself hides errors
mb_internal_encoding('UTF-8');

// v1.171.5: honour FLX_CONFIG (a real environment variable set by the operator/test harness), like app/bootstrap.php does.
define('CONFIG_PATH', (getenv('FLX_CONFIG') ?: '') !== '' ? (string) getenv('FLX_CONFIG') : __DIR__ . '/app/config.php');
const DOCS_PATH   = __DIR__ . '/app/storage/docs';

// ---- guard: already installed? ----
$alreadyInstalled = false;
if (is_file(CONFIG_PATH)) {
    $cfg = @include CONFIG_PATH;
    if (is_array($cfg) && !empty($cfg['crypto_data_key'])) $alreadyInstalled = true;
}

// ---- requirement checks ----
function checkRequirements(): array
{
    $php = PHP_VERSION;
    $checks = [];
    $checks[] = ['PHP 8.1 or newer', version_compare($php, '8.1.0', '>='), 'Found ' . $php, true];
    foreach (['pdo_mysql' => 'MySQL/MariaDB access', 'mbstring' => 'text handling', 'intl' => 'Unicode name comparison (NFKC) — v1.171.10',
              'json' => 'JSON', 'fileinfo' => 'upload validation'] as $ext => $why) {
        $ok = extension_loaded($ext);
        $checks[] = ["PHP extension: {$ext} ({$why})", $ok, $ok ? 'loaded' : 'missing', true];
    }
    // Encryption backend: libsodium preferred, OpenSSL (AES-256-GCM) is a full fallback.
    // At least one is required; both fine.
    $sodium  = extension_loaded('sodium');
    $openssl = extension_loaded('openssl');
    $checks[] = ['PHP extension: sodium (preferred encryption)', $sodium,
        $sodium ? 'loaded' : ($openssl ? 'missing — OpenSSL will be used instead' : 'missing'), false];
    $checks[] = ['Encryption backend available (sodium or OpenSSL)', $sodium || $openssl,
        $sodium ? 'libsodium' : ($openssl ? 'OpenSSL AES-256-GCM' : 'NONE — enable ext-sodium or ext-openssl'), true];
    $appWritable = is_writable(__DIR__ . '/app');
    $checks[] = ['app/ directory writable (to save config)', $appWritable,
        $appWritable ? 'writable' : 'not writable — chmod 755 the app/ folder', true];
    $docsOk = is_dir(DOCS_PATH) ? is_writable(DOCS_PATH) : is_writable(dirname(DOCS_PATH));
    $checks[] = ['app/storage/ writable (document uploads)', $docsOk,
        $docsOk ? 'writable' : 'not writable', false];
    return $checks;
}

/** Split a .sql file into individual statements (handles the FlxCoreHC DDL). */
// Run one migration statement. Result-returning statements (SELECT/SHOW/…) are drained and closed so
// they can never leave an open result set that breaks the next query (SQLSTATE HY000/2014).
function runSqlStatement(PDO $pdo, string $stmt): void
{
    if (preg_match('/^\s*\(?\s*(SELECT|SHOW|WITH|DESCRIBE|DESC|EXPLAIN|CALL)\b/i', $stmt)) {
        $q = $pdo->query($stmt);
        if ($q) { $q->fetchAll(); $q->closeCursor(); }
        return;
    }
    $pdo->exec($stmt);
}

function sqlStatements(string $file): array
{
    $sql = file_get_contents($file);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);          // strip line comments
    $out = []; $buf = '';
    foreach (explode("\n", $sql) as $line) {
        $buf .= $line . "\n";
        if (preg_match('/;\s*$/', $line)) { $t = trim($buf); if ($t !== '' && $t !== ';') $out[] = $t; $buf = ''; }
    }
    if (trim($buf) !== '') $out[] = trim($buf);
    return $out;
}

function runInstall(array $in): array
{
    $errors = [];
    // hard requirements
    foreach (checkRequirements() as [$label, $ok, , $hard]) {
        if ($hard && !$ok) $errors[] = "Requirement not met: {$label}";
    }
    if ($errors) return [false, $errors, null];

    $host = trim($in['db_host'] ?? 'localhost');
    $name = trim($in['db_name'] ?? '');
    $user = trim($in['db_user'] ?? '');
    $pass = (string) ($in['db_pass'] ?? '');
    $env  = ($in['app_env'] ?? 'production') === 'dev' ? 'dev' : 'production';
    if ($name === '' || $user === '') return [false, ['Database name and user are required.'], null];

    // administrator account (created after tables exist)
    $adminName  = trim($in['admin_name'] ?? '');
    $adminEmail = strtolower(trim($in['admin_email'] ?? ''));
    $adminPass  = (string) ($in['admin_pass'] ?? '');
    $orgName    = trim($in['org_name'] ?? '');
    $orgShape   = $in['org_shape'] ?? 'platform';
    $adminPass2 = (string) ($in['admin_pass2'] ?? '');
    if (FLX_INSTALL_EDITION !== 'both' && $orgShape !== 'platform') {
        require_once __DIR__ . '/app/src/Auth/AuthService.php';
        $__shapes = \FlxCoreHC\Auth\AuthService::ORG_SHAPES;
        $__v = $__shapes[$orgShape][0] ?? null;
        if ($__v !== FLX_INSTALL_EDITION && $__v !== 'custom') {
            return [false, ['This edition only supports ' . FLX_INSTALL_EDITION . ' organizations — choose a matching organization type.'], null];
        }
    }
    if ($adminName === '' || $orgName === '') return [false, ['Administrator name and organization name are required.'], null];
    // The administrator becomes a real user, which requires a first AND last name. Check it now —
    // before any tables are created — so a single-word name fails cleanly instead of leaving a
    // freshly-built database with no user account.
    if (count(array_filter(preg_split('/\s+/', $adminName))) < 2) {
        return [false, ['Enter the administrator\'s first and last name (e.g. "Jane Smith").'], null];
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) return [false, ['Enter a valid administrator email.'], null];
    if (strlen($adminPass) < 10) return [false, ['Administrator password must be at least 10 characters.'], null];
    if ($adminPass !== $adminPass2) return [false, ['The two passwords do not match.'], null];
    // v1.171.0: the encryption keys are backed up with a passphrase the operator chooses (never e-mailed, never shown in clear).
    require_once __DIR__ . '/app/src/KeyBackup.php';
    if ($kpErr = \FlxCoreHC\KeyBackup::passphraseError((string) ($in['key_passphrase'] ?? ''), (string) ($in['key_passphrase2'] ?? ''))) {
        return [false, [$kpErr], null];
    }

    // 1) connect
    try {
        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    } catch (PDOException $e) {
        return [false, ['Could not connect to the database: ' . $e->getMessage()], null];
    }

    // 2) fresh-install guard: refuse if core tables already exist (avoid clobbering data)
    $exists = $pdo->query("SHOW TABLES LIKE 'tenants'")->fetchColumn();
    if ($exists && empty($in['confirm_overwrite'])) {
        return [false, ['This database already contains FlxCoreHC tables. Use an empty database, '
            . 'or tick "the database is empty / safe to (re)create" to proceed anyway.'], null];
    }

    // 2a) overwrite confirmed: drop every existing table so the install starts from a true clean
    // slate. This is safer than skipping tables that already exist — a previous partial install may
    // have left tables on an older schema that a later migration would then fail to alter. Dropping
    // first guarantees the finished schema is exactly this build's. Only reached when the operator
    // explicitly ticked the box, so it never runs against a database they didn't mark disposable.
    if ($exists && !empty($in['confirm_overwrite'])) {
        try {
            $tables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
            if ($tables) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                foreach ($tables as $t) {
                    $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $t) . '`');
                }
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
        } catch (\Throwable $e) {
            return [false, ['Could not clear the existing tables for a clean re-install: ' . $e->getMessage()
                . ' You can drop the database in cPanel and recreate it empty, then retry.'], null];
        }
    }

    // 3) generate encryption keys
    $dataKey  = bin2hex(random_bytes(32)); // 256-bit key (both backends)
    $indexKey = bin2hex(random_bytes(32));
    $keyBackup = \FlxCoreHC\KeyBackup::encrypt($dataKey, $indexKey, (string) $in['key_passphrase']);   // sealed now; shown on the last screen
    $keyFp     = \FlxCoreHC\KeyBackup::fingerprint($dataKey, $indexKey);

    // 4) write app/config.php — the URL scheme and HSTS follow how the installer is actually being reached (an http dev
    //    install must not be told it is https: invite/calendar links and the HSTS header depend on it).
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $config = "<?php\n// Generated by the FlxCoreHC installer on " . gmdate('Y-m-d H:i:s') . " UTC.\n"
        . "// Keep this file OUT of version control and OUT of the web root where possible.\n"
        . "return " . var_export([
            'db_host' => $host, 'db_name' => $name, 'db_user' => $user, 'db_pass' => $pass,
            'app_env' => $env,
            'app_timezone' => in_array((string) ($in['app_timezone'] ?? ''), ['America/Puerto_Rico','America/New_York','America/Chicago','America/Denver','America/Los_Angeles','America/Anchorage','Pacific/Honolulu','UTC'], true) ? (string) $in['app_timezone'] : 'America/New_York',   // v1.172.16
            'force_secure_cookies' => !empty($in['force_secure_cookies']) || $isHttps,   // v1.172.13: an install done over HTTPS keeps its cookies HTTPS-only
            'docs_path' => DOCS_PATH,
            'mail_enabled' => false,
            'mail_from' => 'no-reply@' . (preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost'),   // v1.171.6: host without the port
            'allow_open_registration' => false,
            'app_url' => ($isHttps ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            'stripe_secret_key' => '',
            'stripe_publishable_key' => '',
            'stripe_webhook_secret' => '',
            'trial_days' => 14,
            'grace_days' => 7,
            'producer_seats_billable' => true,   // set false to make producer logins free
            'hsts' => $isHttps,                  // Strict-Transport-Security (HTTPS responses only; off for an http install)
            'fonts' => 'self',                   // self (bundled) | system | google
            'reminder_thresholds' => [60, 30, 14, 7],   // days before expiry at which reminders go out (1-365, at most 8)
            'expiring_window_days' => 90,
            'crypto_data_key' => $dataKey,
            'crypto_index_key' => $indexKey,
        ], true) . ";\n";
    if (!@file_put_contents(CONFIG_PATH, $config)) {
        return [false, ['Could not write app/config.php — make the app/ directory writable and retry.'], null];
    }
    @chmod(CONFIG_PATH, 0600);
    if (!is_dir(DOCS_PATH)) @mkdir(DOCS_PATH, 0750, true);

    // 5) run migrations in order (fresh install; 002 folded into schema, 098 is CLI/no-op)
    $order = [
        __DIR__ . '/database/schema.sql',
        __DIR__ . '/database/migrations/003_security_phase1.sql',
        __DIR__ . '/database/migrations/004_compliance_seed.sql',
        __DIR__ . '/database/migrations/099_drop_plaintext.sql',
        __DIR__ . '/database/migrations/005_requirements.sql',
        __DIR__ . '/database/migrations/006_requirements_seed.sql',
        __DIR__ . '/database/migrations/007_book_of_business.sql',
        __DIR__ . '/database/migrations/008_calendar.sql',
        __DIR__ . '/database/migrations/009_hierarchy.sql',
        __DIR__ . '/database/migrations/010_network_invites.sql',
        __DIR__ . '/database/migrations/011_scheduling.sql',
        __DIR__ . '/database/migrations/012_platform_admin.sql',
        __DIR__ . '/database/migrations/013_invites.sql',
        __DIR__ . '/database/migrations/014_plans.sql',
        __DIR__ . '/database/migrations/015_billing_usage.sql',
        __DIR__ . '/database/migrations/016_billing_grace.sql',
        __DIR__ . '/database/migrations/017_booking_tz_reminders.sql',
        __DIR__ . '/database/migrations/018_platform_vertical.sql',
        __DIR__ . '/database/migrations/019_org_branding.sql',
        __DIR__ . '/database/migrations/020_credential_catalog.sql',
        __DIR__ . '/database/migrations/021_task_flexibility.sql',
        __DIR__ . '/database/migrations/022_producer_requirements.sql',
        __DIR__ . '/database/migrations/023_carrier_requirements.sql',
        __DIR__ . '/database/migrations/024_cy2027_rules.sql',
        __DIR__ . '/database/migrations/025_training.sql',
        __DIR__ . '/database/migrations/026_training_seed.sql',
        __DIR__ . '/database/migrations/027_training_sources.sql',
        __DIR__ . '/database/migrations/028_training_sources_seed.sql',
        __DIR__ . '/database/migrations/029_commercial_lines.sql',
        __DIR__ . '/database/migrations/030_task_sync.sql',
        __DIR__ . '/database/migrations/031_producer_portal.sql',
        __DIR__ . '/database/migrations/032_two_factor.sql',
        __DIR__ . '/database/migrations/033_multi_org_login.sql',
        __DIR__ . '/database/migrations/034_compliance_templates.sql',
        __DIR__ . '/database/migrations/035_seed_templates.sql',
        __DIR__ . '/database/migrations/036_states_and_entity.sql',
        __DIR__ . '/database/migrations/037_seed_entity_items.sql',
        __DIR__ . '/database/migrations/038_beneficiary_interactions.sql',
        __DIR__ . '/database/migrations/039_share_links.sql',
        __DIR__ . '/database/migrations/040_carrier_cert_schedule.sql',
        __DIR__ . '/database/migrations/042_soa_forms.sql',
        __DIR__ . '/database/migrations/043_access_foundation.sql',
        __DIR__ . '/database/migrations/044_roles_engine.sql',
        __DIR__ . '/database/migrations/045_carrier_master_bob.sql',
        __DIR__ . '/database/migrations/046_plan_type_dsnp.sql',
        __DIR__ . '/database/migrations/047_demo_flag.sql',
        __DIR__ . '/database/migrations/048_email_delivery.sql',
        __DIR__ . '/database/migrations/049_reminder_log.sql',
        __DIR__ . '/database/migrations/050_invoicing.sql',
        __DIR__ . '/database/migrations/051_email_attachments.sql',
        __DIR__ . '/database/migrations/052_carrier_plans.sql',
        __DIR__ . '/database/migrations/053_cms_integration.sql',
        __DIR__ . '/database/migrations/054_sync_mode.sql',
        __DIR__ . '/database/migrations/055_cms_governance.sql',
        __DIR__ . '/database/migrations/056_carrier_mapping.sql',
        __DIR__ . '/database/migrations/057_producer_users.sql',
        __DIR__ . '/database/migrations/058_npi_profile.sql',
        __DIR__ . '/database/migrations/059_producer_pricing.sql',
        __DIR__ . '/database/migrations/060_tenant_theme.sql',
        __DIR__ . '/database/migrations/061_nav_icons.sql',
        __DIR__ . '/database/migrations/062_tenant_domains.sql',
        __DIR__ . '/database/migrations/063_georgia_content.sql',
        __DIR__ . '/database/migrations/064_nipr_interest.sql',
        __DIR__ . '/database/migrations/065_roster_share_links.sql',
        __DIR__ . '/database/migrations/066_error_log.sql',
        __DIR__ . '/database/migrations/067_tenant_catalog.sql',
        __DIR__ . '/database/migrations/068_healthcare_catalog.sql',
        __DIR__ . '/database/migrations/069_healthcare_templates.sql',
        __DIR__ . '/database/migrations/070_puerto_rico_catalog.sql',
        __DIR__ . '/database/migrations/071_training_vertical.sql',
        __DIR__ . '/database/migrations/072_prune_insurance_seed.sql',
        __DIR__ . '/database/migrations/073_regulators.sql',
        __DIR__ . '/database/migrations/074_seed_regulators.sql',
        __DIR__ . '/database/migrations/075_holder_regulators.sql',
        __DIR__ . '/database/migrations/076_verifications_attestations.sql',
        __DIR__ . '/database/migrations/077_applications.sql',
        __DIR__ . '/database/migrations/078_exclusion_screenings.sql',
        __DIR__ . '/database/migrations/079_calendar_event_types.sql',
        __DIR__ . '/database/migrations/080_calendar_feed_token.sql',
        __DIR__ . '/database/migrations/081_entity_reminders.sql',
        __DIR__ . '/database/migrations/082_prune_insurance_vestiges.sql',
        __DIR__ . '/database/migrations/083_prune_insurance_requirement_defs.sql',
        __DIR__ . '/database/migrations/084_prune_beneficiary_interactions.sql',
        __DIR__ . '/database/migrations/085_leie_exclusions.sql',
        __DIR__ . '/database/migrations/086_sam_exclusions.sql',
        __DIR__ . '/database/migrations/087_medicaid_exclusions.sql',
        __DIR__ . '/database/migrations/088_user_locale.sql',
        __DIR__ . '/database/migrations/089_provider_assignments_and_consents.sql',
        __DIR__ . '/database/migrations/090_impersonation_consent.sql',
        __DIR__ . '/database/migrations/091_plan_storage_quota.sql',
        __DIR__ . '/database/migrations/092_global_master_roles.sql',
        __DIR__ . '/database/migrations/093_tighten_master_role_caps.sql',
        __DIR__ . '/database/migrations/094_locations.sql',
        __DIR__ . '/database/migrations/095_provider_groups.sql',
        __DIR__ . '/database/migrations/096_tenant_mail_settings.sql',
        __DIR__ . '/database/migrations/097_healthcare_templates_by_profession.sql',
        __DIR__ . '/database/migrations/098_ui_translations.sql',
        __DIR__ . '/database/migrations/099_notifications.sql',
        __DIR__ . '/database/migrations/100_platform_tenant_vertical.sql',
        __DIR__ . '/database/migrations/101_ui_translation_context.sql',
        __DIR__ . '/database/migrations/102_history_ledger.sql',
        __DIR__ . '/database/migrations/103_digest_pref.sql',
        __DIR__ . '/database/migrations/104_tenant_default_locale.sql',
        __DIR__ . '/database/migrations/105_document_kinds.sql',
        __DIR__ . '/database/migrations/106_document_requests.sql',
        __DIR__ . '/database/migrations/107_external_shares.sql',
        __DIR__ . '/database/migrations/108_cross_tenant_sharing.sql',
        __DIR__ . '/database/migrations/109_table_notes.sql',
        __DIR__ . '/database/migrations/110_payers_enrollment_ocs_sources.sql',
        __DIR__ . '/database/migrations/111_source_verifications.sql',
        __DIR__ . '/database/migrations/112_requirement_source_links.sql',
        __DIR__ . '/database/migrations/113_pr_training_content.sql',
        __DIR__ . '/database/migrations/114_microsoft_oauth.sql',
        __DIR__ . '/database/migrations/115_addresses_contacts.sql',
        __DIR__ . '/database/migrations/116_custom_fields.sql',
        __DIR__ . '/database/migrations/117_provider_application_data.sql',
        __DIR__ . '/database/migrations/118_pii_fields.sql',
        __DIR__ . '/database/migrations/119_field_level_security.sql',
        __DIR__ . '/database/migrations/120_pr_regulators_expansion.sql',
        __DIR__ . '/database/migrations/121_agency_credentialing.sql',
        __DIR__ . '/database/migrations/122_nonclinical_catalog.sql',
        __DIR__ . '/database/migrations/123_nonclinical_catalog_fix.sql',
        __DIR__ . '/database/migrations/124_healthcare_only.sql',
        __DIR__ . '/database/migrations/125_healthcare_cleanup.sql',
        __DIR__ . '/database/migrations/126_pr_seed_corrections.sql',
        __DIR__ . '/database/migrations/127_operating_states_no_default.sql',
        __DIR__ . '/database/migrations/128_totp_hardening.sql',
        __DIR__ . '/database/migrations/129_malpractice_authority.sql',
        __DIR__ . '/database/migrations/130_invite_token_hash.sql',
        __DIR__ . '/database/migrations/131_address_encryption.sql',
        __DIR__ . '/database/migrations/132_pr_regulatory_wording.sql',
        __DIR__ . '/database/migrations/133_signin_options.sql',
        __DIR__ . '/database/migrations/134_tenant_timezone.sql',
        __DIR__ . '/database/migrations/135_workspace_id.sql',
        __DIR__ . '/database/migrations/136_terms_acceptances.sql',
        __DIR__ . '/database/migrations/137_terms_versions.sql',
        __DIR__ . '/database/migrations/099_document_verification.sql',
    ];
    $applied = [];
    // When the operator ticked "safe to (re)create", the database may carry tables or keys from a
    // previous partial install. In that mode we treat "already exists" errors as benign so the run
    // completes over the leftovers, rather than dying on the first duplicate. On a genuinely empty
    // database none of these fire. Codes: 1050 table exists, 1060 dup column, 1061 dup key,
    // 1022/1826 dup constraint/FK name, 1091 can't-drop-missing.
    $overwrite = !empty($in['confirm_overwrite']);
    $existsCodes = [1050, 1060, 1061, 1062, 1022, 1826, 1091, 3940, 3959];
    try {
        foreach ($order as $file) {
            if (!is_file($file)) continue;
            foreach (sqlStatements($file) as $stmt) {
                try {
                    runSqlStatement($pdo, $stmt);
                } catch (\PDOException $e) {
                    $code = (int) ($e->errorInfo[1] ?? 0);
                    // Always swallow the portable constraint-drop-on-fresh-DB case (see v1.77.3):
                    // a plain "DROP CONSTRAINT x" raises 1091 when x isn't there yet.
                    $isDropConstraint = (bool) preg_match('/DROP\s+CONSTRAINT/i', $stmt);
                    if ($isDropConstraint && in_array($code, [1091, 3940, 3959], true)) continue;
                    // In overwrite mode, also swallow "already exists" errors from re-running DDL
                    // over a partial install. Any other error still stops the run.
                    if ($overwrite && in_array($code, $existsCodes, true)) continue;
                    throw $e;
                }
            }
            $applied[] = basename($file);
        }
        // Record every applied migration in schema_migrations so the in-app "Update database"
        // action sees them as done and never replays them (a replay of non-idempotent DDL such
        // as re-adding a CHECK constraint would fail once custom data exists). Baseline the
        // manifest filenames the admin Migrator checks against — a fresh install just applied
        // that whole schema. Uses the local $pdo so it runs before app bootstrap.
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            filename VARCHAR(255) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL)
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $manifest = require __DIR__ . '/database/manifest.php';
        $rec = $pdo->prepare("INSERT IGNORE INTO schema_migrations (filename, applied_at) VALUES (?, ?)");
        foreach ($manifest as $rel) { $rec->execute([basename($rel), gmdate('Y-m-d H:i:s')]); }
    } catch (\Throwable $e) {
        @unlink(CONFIG_PATH);   // roll back config so the installer can be retried cleanly
        return [false, ['Migration failed on ' . (end($applied) ?: 'first file') . ': ' . $e->getMessage()
            . ' — fix the issue and reload this installer.'], null];
    }

    // create the first administrator account, reusing the app's tested registration
    // (Argon2id hashing, encrypted email, transactional tenant+user creation).
    try {
        require __DIR__ . '/app/bootstrap.php';
        $auth = new \FlxCoreHC\Auth\AuthService();
        $result = $auth->registerTenant($orgName, $orgShape, $adminName, $adminEmail, $adminPass, allowPlatform: true);
        if (is_string($result)) {
            return [false, ['Tables were created, but the admin account could not be made: ' . $result
                . ' You can create it from the sign-in page.'], null];
        }
        \FlxCoreHC\Database::pdo()->prepare('UPDATE users SET platform_admin = 1 WHERE id = ?')
            ->execute([$result['id']]);
        // Public sign-ups start pending, but the very first organization is the operator
        // installing FlxCoreHC — activate and approve it so they land in a working console.
        \FlxCoreHC\Database::pdo()->prepare(
            "UPDATE tenants SET status = 'active', approved_at = NOW() WHERE id = ?"
        )->execute([$result['tenant_id']]);
    } catch (\Throwable $e) {
        error_log('[FlxCoreHC installer] admin creation: ' . $e->getMessage());
        return [false, ['Tables were created, but the admin account could not be made: ' . $e->getMessage()
            . ' You can create it from the sign-in page.'], null];
    }

    return [true, $applied, count($pdo->query("SHOW TABLES")->fetchAll()), $keyBackup, $keyFp];
}

$done = null;
// CSRF: the installer is an unauthenticated state-changing form (S-8). A session token ties the POST to a page this browser loaded.
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
if (empty($_SESSION['install_csrf'])) { $_SESSION['install_csrf'] = bin2hex(random_bytes(32)); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyInstalled) {
    if (!hash_equals((string) $_SESSION['install_csrf'], (string) ($_POST['_csrf'] ?? ''))) {
        $done = [false, ['Your session expired or this request did not come from the installer page. Reload the page and try again.'], null];
    } else {
        $done = runInstall($_POST);
    }
}
$reqs = checkRequirements();
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install FlxCoreHC</title>
<style>
:root{--ink:#1a1a1a;--mut:#666;--line:#e3e1da;--ok:#2f7d5b;--bad:#be4b42;--accent:#2563eb;--bg:#faf9f6}
*{box-sizing:border-box}body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:var(--ink);background:var(--bg);margin:0;line-height:1.5}
.wrap{max-width:640px;margin:32px auto;padding:0 20px}
.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:24px;margin-bottom:18px}
h1{font-size:22px;margin:0 0 2px}.sub{color:var(--mut);font-size:14px;margin-bottom:18px}
h2{font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:#555;margin:0 0 10px}
table{width:100%;border-collapse:collapse;font-size:14px}td{padding:6px 4px;border-bottom:1px solid #f0f0f0;vertical-align:top}
.pill{font-size:11px;font-weight:700;padding:2px 9px;border-radius:999px}.pass{background:#e7f4ee;color:var(--ok)}.fail{background:#fbe9e7;color:var(--bad)}.warn{background:#fbf0df;color:#c77b21}
label{display:block;font-size:13px;font-weight:600;margin:12px 0 4px}
input[type=text],input[type=password]{width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:14px}
.row{display:flex;gap:12px}.row>div{flex:1}
.btn{display:inline-block;background:var(--accent);color:#fff;border:0;border-radius:8px;padding:11px 20px;font-size:15px;font-weight:600;cursor:pointer;margin-top:16px}
.note{font-size:13px;color:var(--mut);margin-top:6px}
.flash{border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:14px}
.flash.ok{background:#e7f4ee;color:var(--ok)}.flash.bad{background:#fbe9e7;color:var(--bad)}
code{background:#f0f0f0;padding:1px 5px;border-radius:4px;font-size:13px}
.chk{display:flex;gap:8px;align-items:flex-start;margin-top:10px;font-weight:400;font-size:13px}
</style></head><body><div class="wrap">
<div class="card"><h1>Install FlxCoreHC</h1><div class="sub">Healthcare credentialing &amp; license tracking</div>

<?php if ($alreadyInstalled): ?>
  <div class="flash ok">FlxCoreHC is already installed. For security, delete <code>install.php</code> now.</div>
  <a class="btn" href="./">Go to FlxCoreHC</a>
<?php elseif ($done && $done[0]): ?>
  <div class="flash ok">✓ Installation complete — created <?= (int) $done[2] ?> tables and your administrator account.</div>
  <div class="flash bad" style="background:#fbf0df;color:#7a4a08">
    <strong>Save your encryption-key backup NOW.</strong> Your patient/provider data is encrypted with keys stored in <code>app/config.php</code>.
    If that file is ever lost, the data cannot be recovered. This is the only time this file is offered here — it is locked with the passphrase you just chose
    (never e-mailed). Store it in a password manager or vault, together with the passphrase.
    <div style="margin-top:10px"><a class="btn" style="margin-top:0;background:#7a4a08" download="flxcorehc-keys-<?= $e($done[4] ?? '') ?>.flxkeys"
       href="data:application/json;base64,<?= base64_encode((string) ($done[3] ?? '')) ?>">Download key backup (.flxkeys)</a>
       <span class="note">Key ID: <code><?= $e($done[4] ?? '') ?></code></span></div>
    <div class="note">To restore later: <code>php database/restore_keys.php flxcorehc-keys-….flxkeys</code>. You can also make a fresh backup any time in Console → System.</div>
  </div>
  <p><strong>One last step:</strong> delete <code>install.php</code> from your server for security, then sign in with the administrator email and password you just set.</p>
  <a class="btn" href="./login">Sign in</a>
<?php else: ?>
  <?php if ($done && !$done[0]): ?>
    <div class="flash bad"><?php foreach ($done[1] as $err) echo $e($err) . '<br>'; ?></div>
  <?php endif; ?>

  <h2>Server requirements</h2>
  <table>
    <?php foreach ($reqs as [$label, $ok, $detail, $hard]): ?>
    <tr><td><?= $e($label) ?><div class="note"><?= $e($detail) ?></div></td>
      <td style="text-align:right;white-space:nowrap"><span class="pill <?= $ok ? 'pass' : ($hard ? 'fail' : 'warn') ?>"><?= $ok ? 'OK' : ($hard ? 'required' : 'recommended') ?></span></td></tr>
    <?php endforeach; ?>
  </table>
</div>

<form method="post" class="card">
  <input type="hidden" name="_csrf" value="<?= $e($_SESSION['install_csrf'] ?? '') ?>">
  <h2>Database connection</h2>
  <p class="note">Create an empty database + user in cPanel &raquo; MySQL Databases first, then enter the details here.</p>
  <div class="row">
    <div><label>Database host</label><input type="text" name="db_host" value="<?= $e($_POST['db_host'] ?? 'localhost') ?>"></div>
    <div><label>Database name</label><input type="text" name="db_name" value="<?= $e($_POST['db_name'] ?? '') ?>" placeholder="cpaneluser_flxcred"></div>
  </div>
  <div class="row">
    <div><label>Database user</label><input type="text" name="db_user" value="<?= $e($_POST['db_user'] ?? '') ?>" placeholder="cpaneluser_flx"></div>
    <div><label>Database password</label><input type="password" name="db_pass" value=""></div>
  </div>
  <label class="chk"><input type="checkbox" name="force_secure_cookies" value="1"<?= ((!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') ? ' checked' : '' ?>> Force secure (HTTPS-only) cookies — leave on for any site with SSL.</label>
  <label style="margin-top:12px">Platform time zone <span class="note" style="font-weight:400">(default for every organization; each organization can choose its own later)</span></label>
  <select name="app_timezone" style="padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:14px">
    <?php foreach (['America/New_York'=>'Eastern (EST/EDT) — Florida','America/Puerto_Rico'=>'Atlantic — Puerto Rico (AST)','America/Chicago'=>'Central','America/Denver'=>'Mountain','America/Los_Angeles'=>'Pacific','America/Anchorage'=>'Alaska','Pacific/Honolulu'=>'Hawaii','UTC'=>'UTC'] as $__z => $__l): ?>
      <option value="<?= $e($__z) ?>" <?= (($_POST['app_timezone'] ?? 'America/New_York') === $__z) ? 'selected' : '' ?>><?= $e($__l) ?></option>
    <?php endforeach; ?>
  </select>
  <label class="chk"><input type="checkbox" name="confirm_overwrite" value="1"> The database is empty / safe to (re)create tables.</label>
  <label class="chk"><input type="checkbox" name="app_env" value="dev"> Development mode (shows errors, allows unencrypted dev data). Leave OFF for production.</label>

  <h2 style="margin-top:22px">Administrator account</h2>
  <p class="note">This is your master sign-in — the first account for the app, flagged as the platform administrator.</p>
  <div class="row">
    <div><label>Organization name</label><input type="text" name="org_name" value="<?= $e($_POST['org_name'] ?? '') ?>" placeholder="Clínica Ejemplo Medical Group"></div>
    <div><label>Account type</label>
      <input type="hidden" name="org_shape" value="platform">
      <p class="note"><strong>Organization type: SaaS platform (operator).</strong> This first account is the platform administrator that runs FlxCoreHC. Client organizations (billing companies, medical groups, hospitals, solo practices) are created afterwards from the console, each with its own type.</p>
      <input type="text" value="SaaS Admin — platform operator" disabled style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:14px;background:#f6f6f4;color:#555">
      <div class="note" style="margin-top:4px">This master account administers the entire platform. You'll create customer organizations from the admin console after signing in.</div>
    </div>
  </div>
  <div class="row">
    <div><label>Your name <span style="color:#999;font-weight:400">(first and last)</span></label><input type="text" name="admin_name" value="<?= $e($_POST['admin_name'] ?? '') ?>" placeholder="Jane Smith" required></div>
    <div><label>Your email (sign-in)</label><input type="text" name="admin_email" value="<?= $e($_POST['admin_email'] ?? '') ?>" placeholder="jane@yourclinic.com"></div>
  </div>
  <div class="row">
    <div>
      <label>Password <span class="note" style="font-weight:400">(min 10 characters)</span></label>
      <input type="password" name="admin_pass" value="">
    </div>
    <div>
      <label>Confirm password</label>
      <input type="password" name="admin_pass2" value="">
    </div>
  </div>

  <h2 style="margin-top:22px">Encryption-key backup</h2>
  <p class="note">The installer creates the keys that protect your encrypted data. Choose a passphrase to lock a backup file you will download on the next screen. Keep the passphrase somewhere safe — without it (and the file, or <code>config.php</code>) encrypted data cannot be recovered.</p>
  <div class="row">
    <div><label>Backup passphrase <span class="note" style="font-weight:400">(min 12 characters)</span></label><input type="password" name="key_passphrase" value="" autocomplete="new-password"></div>
    <div><label>Confirm passphrase</label><input type="password" name="key_passphrase2" value="" autocomplete="new-password"></div>
  </div>

  <button class="btn" type="submit">Check &amp; install</button>
  <p class="note">This generates your encryption keys and creates all tables. It won't run again once installed.</p>
</form>
<?php endif; ?>
</div></body></html>
