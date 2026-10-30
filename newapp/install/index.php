<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — Web Installer
 * Deployed to /install/index.php (a licensed installation) and /install/hub.php (BT128: the main installation, the
 * License Hub; it includes this file).
 * Guides through: DB setup → Schema → Admin user → O365 config → Branding → License Hub link (licensed, optional) or
 * License Hub setup (main) → Finalize
 */
session_start();
$mode = defined('ITOPS_INSTALL_HUB') && ITOPS_INSTALL_HUB === true ? 'hub' : 'licensed';
$self = $mode === 'hub' ? 'hub.php' : 'index.php';
$step = $_POST['step'] ?? $_GET['step'] ?? 'welcome';
$errors = [];
$success = '';

// BT128: right after the main installation is set up, its two key backups can be downloaded from this browser session
// (they are encrypted with the passphrase chosen in the installer). Nothing else is reachable once installed.
if ($mode === 'hub' && isset($_GET['dl']) && in_array($_GET['dl'], ['license', 'release'], true) && !empty($_SESSION['hub_done']['backups'][$_GET['dl']])) {
    $k = $_GET['dl']; $_SESSION['hub_done']['got'][$k] = true;
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    header('Content-Disposition: attachment; filename="' . ($k === 'license' ? 'itops-license-key-' : 'release-key-') . preg_replace('/[^a-f0-9]/', '', (string)$_SESSION['hub_done']['ids'][$k]) . '.json"');
    echo $_SESSION['hub_done']['backups'][$k];
    if (!empty($_SESSION['hub_done']['got']['license']) && !empty($_SESSION['hub_done']['got']['release'])) unset($_SESSION['hub_done']['backups']);
    exit;
}

// If config.php exists and has real DB creds, redirect to app
$configFile = __DIR__ . '/../config.php';
$configExists = file_exists($configFile) && strpos(file_get_contents($configFile), '%%DB_HOST%%') === false;
// SECURITY: once ITOps is installed the installer is closed for EVERY step (it used to guard only the
// welcome page, so anyone could POST the later steps and overwrite config.php). To reinstall, delete
// config.php on the server first. Tip: remove or deny the /install folder after setup.
if ($configExists) {
    if ($step === 'welcome' && $_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../'); exit; }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("ITOps is already installed. The installer is disabled. To reinstall, remove config.php on the server first.\n");
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($step) {
        case 'welcome':
            $step = 'database'; // advance to first data-entry step
            break;
        case 'database':
            $host = trim($_POST['db_host'] ?? 'localhost');
            $port = trim($_POST['db_port'] ?? '3306');
            $name = trim($_POST['db_name'] ?? '');
            $user = trim($_POST['db_user'] ?? '');
            $pass = $_POST['db_pass'] ?? '';
            
            if (!$name || !$user) { $errors[] = 'Database name and username are required.'; break; }
            
            try {
                $dsn = "mysql:host=$host;port=$port;charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                
                // Create database if not exists
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `$name`");
                
                // Never run the schema over an existing installation: schema.sql drops and recreates the
                // tables, so a missing config.php would otherwise wipe every record (review CORE-4).
                $has = $pdo->query("SHOW TABLES LIKE 'itops\\_%'")->fetchAll();
                if ($has) throw new RuntimeException('This database already holds an installation (' . count($has) . ' tables). Use a new, empty database, or restore config.php for the existing one. Nothing was changed.');

                // Run schema
                $schema = file_get_contents(__DIR__ . '/../sql/schema.sql');
                $pdo->exec($schema);
                
                // Run seed data: the first admin, categories and defaults. Sample records only when asked.
                $seed = file_get_contents(__DIR__ . '/../sql/seed.sql');
                if ($seed) $pdo->exec($seed);
                if (!empty($_POST['sample']) && is_file(__DIR__ . '/../sql/sample.sql')) $pdo->exec(file_get_contents(__DIR__ . '/../sql/sample.sql'));
                
                // Store in session for next steps
                $_SESSION['install'] = [
                    'mode' => $mode, 'db_host' => $host, 'db_port' => $port, 'db_name' => $name,
                    'db_user' => $user, 'db_pass' => $pass,
                ];
                $step = 'admin';
            } catch (PDOException $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
            break;
            
        case 'admin':
            $email = trim($_POST['admin_email'] ?? '');
            $password = $_POST['admin_password'] ?? '';
            $name = trim($_POST['admin_name'] ?? '');
            
            if (!$email || !$password || !$name) { $errors[] = 'All fields are required.'; break; }
            require_once __DIR__ . '/../api/lib/AuthGuard.php';   // review CORE-20: the same rule as everywhere else
            if (($pp = itops_password_problem($password, (string)($_POST['admin_email'] ?? ''), (string)($_POST['admin_name'] ?? ''))) !== '') { $errors[] = $pp; break; }
            
            $_SESSION['install']['admin_email'] = $email;
            $_SESSION['install']['admin_password'] = $password;
            $_SESSION['install']['admin_name'] = $name;
            $step = 'o365';
            break;
            
        case 'o365':
            $_SESSION['install']['o365_tenant'] = trim($_POST['o365_tenant'] ?? '');
            $_SESSION['install']['o365_client_id'] = trim($_POST['o365_client_id'] ?? '');
            $_SESSION['install']['o365_client_secret'] = trim($_POST['o365_client_secret'] ?? '');
            $_SESSION['install']['o365_redirect'] = trim($_POST['o365_redirect'] ?? '');
            $_SESSION['install']['o365_enabled'] = !empty($_POST['o365_tenant']);
            $step = 'branding';
            break;
            
        case 'branding':
            $_SESSION['install']['app_name'] = trim($_POST['app_name'] ?? 'ITOps');
            $_SESSION['install']['app_url'] = rtrim(trim($_POST['app_url'] ?? ''), '/');
            $_SESSION['install']['accent'] = trim($_POST['accent'] ?? '#4A7FB5');
            $_SESSION['install']['theme'] = $_POST['theme'] ?? 'light';
            // BT131: the installation's time zone (every time is stored and shown in it)
            require_once __DIR__ . '/../api/lib/TimeZone.php';
            $tzIn = trim((string)($_POST['timezone_other'] ?? '')) ?: trim((string)($_POST['timezone'] ?? ITOPS_TZ_DEFAULT));
            if (!itops_tz_valid($tzIn)) { $errors[] = 'Choose a time zone from the list, or type an IANA name such as America/Chicago.'; break; }
            $_SESSION['install']['timezone'] = $tzIn;
            
            // Handle logo upload
            if (!empty($_FILES['logo']['tmp_name'])) {
                $logoDir = __DIR__ . '/../public/uploads/logos/';
                if (!is_dir($logoDir)) mkdir($logoDir, 0755, true);
                $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) || @getimagesize($_FILES['logo']['tmp_name']) === false) { $errors[] = 'Logo must be an image (png, jpg, gif or webp).'; break; }
                $logoFile = 'logo_' . time() . '.' . $ext;
                move_uploaded_file($_FILES['logo']['tmp_name'], $logoDir . $logoFile);
                $_SESSION['install']['logo_path'] = 'uploads/logos/' . $logoFile;
            }
            // Handle favicon upload
            if (!empty($_FILES['favicon']['tmp_name'])) {
                $logoDir = __DIR__ . '/../public/uploads/logos/';
                $ext = strtolower(pathinfo($_FILES['favicon']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['png', 'ico', 'jpg', 'jpeg', 'gif', 'webp'], true) || ($ext !== 'ico' && @getimagesize($_FILES['favicon']['tmp_name']) === false)) { $errors[] = 'Favicon must be an image (png or ico).'; break; }
                $favFile = 'favicon_' . time() . '.' . $ext;
                move_uploaded_file($_FILES['favicon']['tmp_name'], $logoDir . $favFile);
                $_SESSION['install']['favicon_path'] = 'uploads/logos/' . $favFile;
            }
            
            $step = $mode === 'hub' ? 'hubsetup' : 'link';
            break;

        case 'link':   // BT128: a licensed installation can be linked to the License Hub now (or later in Settings → Updates)
            $code = trim((string)($_POST['link_code'] ?? ''));
            unset($_SESSION['install']['link_code']);
            if ($code !== '' && empty($_POST['skip'])) {
                require_once __DIR__ . '/../api/lib/HubCheckin.php';
                try { itops_hub_parse_link($code); $_SESSION['install']['link_code'] = $code; }
                catch (InvalidArgumentException $e) { $errors[] = $e->getMessage() . ' Ask for a new link code, or skip this step.'; break; }
            }
            $step = 'finalize';
            break;

        case 'hubsetup':   // BT128: the main installation, the License Hub
            require_once __DIR__ . '/../api/lib/HubCheckin.php';
            $pu = itops_hub_url_clean((string)($_POST['hub_url'] ?? '')); $p1 = (string)($_POST['pass1'] ?? ''); $p2 = (string)($_POST['pass2'] ?? '');
            $lic = trim((string)($_POST['licensee'] ?? ''));
            if (!function_exists('sodium_crypto_sign_detached') || !function_exists('sodium_crypto_pwhash')) { $errors[] = 'This server\'s PHP has no sodium extension, which the License Hub needs for its keys. Ask the host to enable it.'; break; }
            if (!itops_hub_url_ok($pu) || mb_strlen($pu) > 200) { $errors[] = 'Enter the hub\'s public HTTPS address, for example https://licenses.example.com.'; break; }
            if ($lic === '' || mb_strlen($lic) > 200) { $errors[] = 'Enter the organization this main installation belongs to (its own license is issued to it).'; break; }
            if (mb_strlen($p1) < 12) { $errors[] = 'Use a backup passphrase of at least 12 characters.'; break; }
            if ($p1 !== $p2) { $errors[] = 'The two passphrases are not the same.'; break; }
            $_SESSION['install']['hub_url'] = $pu; $_SESSION['install']['hub_pass'] = $p1; $_SESSION['install']['hub_licensee'] = $lic;
            $step = 'finalize';
            break;

        case 'finalize':
            $i = $_SESSION['install'] ?? [];
            if (empty($i['db_host'])) { $errors[] = 'Installation data lost. Please start over.'; $step = 'welcome'; break; }
            if (($i['mode'] ?? '') !== $mode) { $errors[] = 'This installation was started with the other installer. Please start over.'; $step = 'welcome'; unset($_SESSION['install']); break; }
            if ($mode === 'hub' && (empty($i['hub_url']) || empty($i['hub_pass']))) { $errors[] = 'The License Hub setup is missing. Please fill it in again.'; $step = 'hubsetup'; break; }
            
            // Generate secrets
            $vaultKey = bin2hex(random_bytes(32)); // 64-char hex
            $jwtSecret = bin2hex(random_bytes(32));
            
            // Write config.php
            // Read the template (shipped as config.sample.php so the package
            // can never overwrite a live config.php). Fall back to config.php
            // for older installs.
            $tplFile = file_exists(__DIR__ . '/../config.sample.php') ? __DIR__ . '/../config.sample.php' : __DIR__ . '/../config.php';
            $configTemplate = file_get_contents($tplFile);
            if ($mode === 'hub') {   // BT128: the main installation has the License Manager (only this installer writes this line)
                $anchor = "define('APP_URL', '%%APP_URL%%');";
                if (substr_count($configTemplate, $anchor) !== 1) { $errors[] = 'The configuration template is not the expected one. Nothing was written.'; break; }
                $configTemplate = str_replace($anchor, $anchor . "\n// The main installation: the License Hub (written by install/hub.php)\ndefine('ITOPS_LICENSE_MANAGER', true);", $configTemplate);
            }
            $replacements = [
                '%%DB_HOST%%' => $i['db_host'], '%%DB_NAME%%' => $i['db_name'],
                '%%DB_USER%%' => $i['db_user'], '%%DB_PASS%%' => $i['db_pass'],
                '%%DB_PORT%%' => $i['db_port'], '%%VAULT_KEY%%' => $vaultKey,
                // Review CORE-24: the Microsoft 365 values go to Settings (the secret wrapped), never into config.php.
                '%%JWT_SECRET%%' => $jwtSecret, '%%O365_TENANT%%' => '', '%%O365_CLIENT_ID%%' => '', '%%O365_CLIENT_SECRET%%' => '', '%%O365_REDIRECT%%' => '',
                '%%APP_URL%%' => $i['app_url'] ?? '',
            ];
            // Values land inside single-quoted PHP strings in config.php — escape them so a quote or
            // backslash in (say) a password can't break out and inject code.
            $replacements = array_map(fn($v) => str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$v), $replacements);
            $config = str_replace(array_keys($replacements), array_values($replacements), $configTemplate);
            file_put_contents($configFile, $config);
            
            // Create admin user
            require_once $configFile;
            $db = getDB();
            if (!empty($i['timezone'])) { date_default_timezone_set($i['timezone']); try { $db->exec("SET time_zone = '" . date('P') . "'"); } catch (Throwable $e) {} }   // BT131: from the first record on
            $hash = hash_password($i['admin_password']);
            $nameParts = explode(' ', $i['admin_name'], 2);
            // Promote the bootstrap user (id=1, seeded so FK refs resolve) into the real admin.
            $db->prepare('UPDATE itops_users SET email=?, password_hash=?, display_name=?, first_name=?, last_name=?, role_id=1, department_id=1, auth_provider="local", status="active" WHERE id=1')
               ->execute([$i['admin_email'], $hash, $i['admin_name'], $nameParts[0] ?? '', $nameParts[1] ?? '']);
            $adminId = 1;
            
            // Update settings
            $o365Ready = ($i['o365_tenant'] ?? '') !== '' && ($i['o365_client_id'] ?? '') !== '' && ($i['o365_client_secret'] ?? '') !== '';
            $settings = [
                'app_name' => $i['app_name'] ?? 'ITOps',
                'accent_color' => $i['accent'] ?? '#4A7FB5',
                'default_theme' => $i['theme'] ?? 'light',
                'app_logo_url' => !empty($i['logo_path']) ? rtrim((string)($i['app_url'] ?? ''), '/') . '/public/' . $i['logo_path'] : '',
                'app_favicon_url' => !empty($i['favicon_path']) ? rtrim((string)($i['app_url'] ?? ''), '/') . '/public/' . $i['favicon_path'] : '',
                'o365_enabled' => $o365Ready ? '1' : '0',
                'o365_tenant' => $i['o365_tenant'] ?? '',
                'o365_tenant_id' => $i['o365_tenant'] ?? '',
                'o365_client_id' => $i['o365_client_id'] ?? '',
                'o365_client_secret_enc' => ($i['o365_client_secret'] ?? '') !== '' ? vault_encrypt($i['o365_client_secret']) : '',
                'o365_redirect' => $i['o365_redirect'] ?? '',
                'installed_at' => date('Y-m-d H:i:s'),
                'app_timezone' => $i['timezone'] ?? 'America/New_York',   // BT131
            ];
            foreach ($settings as $k => $v) {
                $db->prepare('INSERT INTO itops_settings (skey,sval,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval), updated_by=VALUES(updated_by)')
                   ->execute([$k, $v, $adminId]);
            }
            unset($_SESSION['install']['o365_client_secret'], $_SESSION['install']['db_pass'], $_SESSION['install']['admin_password']);
            
            // Update credential seed data owner_id
            $db->exec("UPDATE itops_credentials SET owner_id=$adminId WHERE owner_id=1 OR owner_id IS NULL");
            $db->exec("UPDATE itops_contracts SET owner_id=$adminId WHERE owner_id=1 OR owner_id IS NULL");
            
            // Encrypt seed credentials with vault key
            // (In production, seed credentials would be entered through the UI)
            
            // Bring the fresh schema fully up to date. schema.sql holds the original tables; every later
            // feature (equipment loans, mail log, terms, email templates, …) is created by the migrations.
            // They are idempotent and skip content that the seed already inserted.
            @require_once __DIR__ . '/../api/lib/Migrations.php';
            if (function_exists('itops_run_migrations')) {
                // Review OPS-26: a failed update is said on the last page (Maintenance → Database update, or db-update.php, finishes it)
                try { itops_migrations_table($db); foreach (itops_run_migrations($db) as $__m) if (($__m['status'] ?? '') === 'error') $_SESSION['install_mig_error'] = $__m['version'] . ': ' . $__m['message']; }
                catch (Throwable $e) { $_SESSION['install_mig_error'] = $e->getMessage(); }
            }
            
            // Log installation
            $db->prepare('INSERT INTO itops_audit_log (user_id,user_name,action,entity_type,entity_name,ip_address) VALUES (?,?,?,?,?,?)')
               ->execute([$adminId, $i['admin_name'], 'system_installed', 'system', 'ITOps v1.0', $_SERVER['REMOTE_ADDR'] ?? '']);
            $_SERVER['ITOPS_USER_ID'] = $adminId; $_SERVER['ITOPS_USER_NAME'] = $i['admin_name'];

            $done = ['mode' => $mode, 'mig_error' => $_SESSION['install_mig_error'] ?? ''];
            if ($mode === 'licensed') {   // BT128: a licensed installation has no use for the main installation's installer
                $hf = __DIR__ . '/hub.php';
                if (is_file($hf) && !@unlink($hf)) $done['hub_file'] = 'install/hub.php could not be removed by the installer: delete it on the server (it is the main installation\'s installer and is locked anyway).';
            }
            if ($mode === 'hub') {
                // BT128: the License Hub: both keys (with their backups), the hub's address, its own license
                require_once __DIR__ . '/../api/lib/LicenseManager.php'; require_once __DIR__ . '/../api/lib/HubCheckin.php';
                try {
                    $lk = itops_lm_create_key($db); $rk = itops_rel_create_key($db);
                    itops_lm_set($db, 'lm_public_url', $i['hub_url']);
                    $own = itops_lm_issue($db, itops_lm_clean(['licensee' => $i['hub_licensee'], 'install_id' => itops_install_id($db), 'kind' => 'Owner', 'plan' => 'complete', 'users' => 0,
                                                             'term_start' => date('Y-m-d'), 'notes' => 'The main installation (License Hub).']), $adminId);
                    $lf = itops_lm_file($own); $root = dirname(__DIR__);
                    if (@file_put_contents($root . '/itops-license.php', $lf) !== strlen($lf)) throw new RuntimeException('its own license file could not be written next to config.php (download it later from License Manager → Register → File)');
                    $done['backups'] = ['license' => itops_lm_backup($db, $i['hub_pass']), 'release' => itops_lm_backup_of(itops_rel_secret($db), $rk, 'itops-release-key-backup', $i['hub_pass'])];
                    $done['ids'] = ['license' => $lk['key_id'], 'release' => $rk['key_id']];
                    $done['lines'] = ['license' => $lk['build_line'], 'release' => $rk['build_line']];
                    $done['hub_url'] = $i['hub_url']; $done['serial'] = $own['serial'];
                    audit_log('hub_installed', 'license', null, 'License Hub', null, ['Address' => $i['hub_url'], 'License key' => $lk['key_id'], 'Release key' => $rk['key_id'], 'Own license' => $own['serial']]);
                } catch (Throwable $e) { $done['hub_error'] = $e->getMessage(); }
            } elseif (!empty($i['link_code'])) {
                // BT128: linked to the License Hub from the start; the first check-in is tried now (the scheduler repeats it daily)
                require_once __DIR__ . '/../api/lib/HubCheckin.php';
                try { $chg = itops_hubc_link($db, $i['link_code']); audit_log('hub_client_changed', 'license', null, 'License Hub check-in', null, $chg + ['By' => 'the installer']);
                      $r = itops_hubc_checkin($db, 'installer'); $done['link'] = $r['note']; $done['link_ok'] = $r['ok']; }
                catch (Throwable $e) { $done['link'] = 'Not linked: ' . $e->getMessage(); $done['link_ok'] = false; }
            }
            $_SESSION = ['hub_done' => $done];
            $step = 'complete';
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>ITOps — <?= $mode === 'hub' ? 'Main installation (License Hub)' : 'Installer' ?></title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',system-ui,sans-serif;background:#f0f4f8;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.installer{max-width:560px;width:100%;background:#fff;border-radius:16px;padding:40px;box-shadow:0 20px 60px rgba(0,0,0,.08)}
.logo{text-align:center;margin-bottom:32px}
.logo h1{font-size:30px;font-weight:800;color:#4A7FB5;letter-spacing:-.03em}
.logo p{font-size:13px;color:#64748b;margin-top:4px}
.steps{display:flex;gap:4px;margin-bottom:28px}
.step{flex:1;height:4px;border-radius:2px;background:#e2e8f0}
.step.done{background:#3D9A6E}
.step.active{background:#4A7FB5}
h2{font-size:18px;font-weight:700;margin-bottom:16px}
.field{margin-bottom:16px}
.field label{display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:6px}
.field input,.field select{width:100%;padding:10px 14px;border:1px solid #e2e8f0;border-radius:8px;font-size:14px;font-family:'DM Sans',sans-serif;color:#0f172a;outline:none}
.field input:focus,.field select:focus{border-color:#4A7FB5}
.field small{font-size:11px;color:#94a3b8;margin-top:4px;display:block}
.row{display:flex;gap:12px}
.row .field{flex:1}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:12px 28px;border-radius:10px;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;cursor:pointer;border:none;transition:all .15s}
.btn-primary{background:#4A7FB5;color:#fff}
.btn-primary:hover{background:#0284c7}
.btn-ghost{background:transparent;color:#64748b;border:1px solid #e2e8f0}
.actions{display:flex;justify-content:space-between;margin-top:28px}
.error{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;margin-bottom:16px;color:#dc2626;font-size:13px}
.success-box{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:20px;text-align:center;margin-top:20px}
.success-box h3{color:#3D9A6E;font-size:20px;margin-bottom:8px}
.optional{color:#94a3b8;font-weight:400;font-size:12px}
</style>
<script src="../itops-select.js?v=37"></script>
</head>
<body>
<div class="installer">
<div class="logo"><h1>ITOps</h1><p>IT Master Operations Hub — <?= $mode === 'hub' ? 'Main installation (License Hub)' : 'Installer' ?></p></div>

<div class="steps">
<?php
$stepOrder = ['welcome','database','admin','o365','branding',$mode === 'hub' ? 'hubsetup' : 'link','finalize','complete'];
$currentIdx = array_search($step, $stepOrder);
for ($i = 0; $i < 7; $i++):
  $class = $i < $currentIdx ? 'done' : ($i === $currentIdx ? 'active' : '');
?>
<div class="step <?= $class ?>"></div>
<?php endfor; ?>
</div>

<?php if ($errors): ?>
<div class="error"><?= implode('<br>', array_map(fn($e) => htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8'), $errors)) ?></div>
<?php endif; ?>

<?php if ($step === 'welcome'): ?>
<h2>Welcome to ITOps</h2>
<?php if ($mode === 'hub'): ?>
<div class="error" style="background:#fffbeb;border-color:#fde68a;color:#92400e;line-height:1.6"><strong>This is the main installation: the License Hub.</strong> It issues the licenses and update bundles for every other installation. There is only one, run by Indisse-BTS. To install ITOps for an organization, use <a href="index.php" style="color:#92400e">the regular installer</a> instead.</div>
<?php endif; ?>
<p style="color:#64748b;font-size:14px;line-height:1.7;margin-bottom:16px">
This wizard will set up ITOps on your server. You'll need your MySQL database credentials, and optionally your Microsoft 365 (Azure AD) app registration details for SSO.<?= $mode === 'hub' ? ' At the end it creates the License Hub\'s two keys; have a place ready to keep their backups.' : ' If Indisse-BTS gave you a License Hub link code, have it ready (optional).' ?>
</p>
<p style="color:#64748b;font-size:13px;margin-bottom:8px"><strong>Requirements:</strong></p>
<ul style="color:#64748b;font-size:13px;line-height:1.8;margin-left:20px;margin-bottom:20px">
<li>PHP 8.0+ with PDO, OpenSSL, cURL, JSON</li>
<li>MySQL 5.7+ or MariaDB 10.3+</li>
<li>mod_rewrite enabled</li>
<li>Write access to /public/uploads/<?= $mode === 'hub' ? ' and to the installation folder (its own license file)' : '' ?></li><?php if ($mode === 'hub'): ?>
<li>The PHP sodium extension (for the keys)</li><?php endif; ?>
</ul>
<div class="actions">
<div></div>
<div class="actions"><div></div><a class="btn btn-primary" href="?step=database">Begin Installation →</a></div>
</div>

<?php elseif ($step === 'database'): ?>
<h2>Database Configuration</h2>
<form method="POST">
<input type="hidden" name="step" value="database">
<div class="row">
<div class="field"><label>Host</label><input name="db_host" value="localhost" required></div>
<div class="field" style="max-width:100px"><label>Port</label><input name="db_port" value="3306" required></div>
</div>
<div class="field"><label>Database Name</label><input name="db_name" placeholder="itops_db" required><small>Will be created if it doesn't exist</small></div>
<div class="row">
<div class="field"><label>Username</label><input name="db_user" required></div>
<div class="field"><label>Password</label><input name="db_pass" type="password"></div>
</div>
<div class="field"><label style="display:flex;gap:8px;align-items:center;font-weight:normal"><input type="checkbox" name="sample" value="1" style="width:auto"> Add sample records (for trying the app: credentials, contracts, articles, tickets and calendar entries with made-up values)</label><small>Leave this off for a real installation.</small></div>
<div class="actions"><div></div><button class="btn btn-primary" type="submit">Test & Install Schema →</button></div>
</form>

<?php elseif ($step === 'admin'): ?>
<h2>Create Admin Account</h2>
<form method="POST">
<input type="hidden" name="step" value="admin">
<div class="field"><label>Full Name</label><input name="admin_name" placeholder="First and last name" required></div>
<div class="field"><label>Email</label><input name="admin_email" type="email" placeholder="admin@company.com" required></div>
<div class="field"><label>Password</label><input name="admin_password" type="password" placeholder="At least 12 characters" required><small>At least 12 characters, with a letter and a digit</small></div>
<div class="actions"><div></div><button class="btn btn-primary" type="submit">Create Admin →</button></div>
</form>

<?php elseif ($step === 'o365'): ?>
<h2>Microsoft 365 SSO <span class="optional">(optional)</span></h2>
<p style="color:#64748b;font-size:13px;margin-bottom:16px">Register an app in Azure AD → App registrations → New registration. Set redirect URI to your ITOps callback URL.</p>
<form method="POST">
<input type="hidden" name="step" value="o365">
<div class="field"><label>Tenant ID</label><input name="o365_tenant" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></div>
<div class="field"><label>Client ID (Application ID)</label><input name="o365_client_id" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></div>
<div class="field"><label>Client Secret</label><input name="o365_client_secret" type="password" placeholder="Client secret value"></div>
<div class="field"><label>Redirect URI</label><input name="o365_redirect" placeholder="https://ops.company.com/api/auth/o365/callback"></div>
<div class="actions">
<button class="btn btn-ghost" type="submit">Skip for now →</button>
<button class="btn btn-primary" type="submit">Save & Continue →</button>
</div>
</form>

<?php elseif ($step === 'branding'): ?>
<h2>Branding & Appearance</h2>
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="step" value="branding">
<div class="field"><label>Platform Name</label><input name="app_name" value="ITOps" required></div>
<div class="field"><label>App URL</label><input name="app_url" placeholder="https://ops.company.com" required></div>
<?php require_once __DIR__ . '/../api/lib/TimeZone.php'; $tzSel = (string)($_SESSION['install']['timezone'] ?? 'America/New_York'); $tzCommon = itops_tz_common(); ?>
<div class="row">
<div class="field"><label>Time zone</label><select name="timezone"><?php foreach ($tzCommon as $k => $v): ?><option value="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>"<?= $k === $tzSel ? ' selected' : '' ?>><?= htmlspecialchars($v . ' (' . $k . ')', ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><small>Every time is recorded and shown in it, 12-hour. Choose it now: if it changes later, times already written as clock values are not rewritten.</small></div>
<div class="field"><label>Other time zone <span class="optional">(optional)</span></label><input name="timezone_other" placeholder="e.g. America/Guatemala" value="<?= isset($tzCommon[$tzSel]) ? '' : htmlspecialchars($tzSel, ENT_QUOTES, 'UTF-8') ?>"><small>An IANA name; it wins over the list.</small></div>
</div>
<div class="row">
<div class="field"><label>Accent Color</label><input name="accent" type="color" value="#4A7FB5" style="height:40px;cursor:pointer"></div>
<div class="field"><label>Default Theme</label><select name="theme"><option value="light">Light</option><option value="dark">Dark</option></select></div>
</div>
<div class="row">
<div class="field"><label>Logo <span class="optional">(PNG/SVG, max 2MB)</span></label><input name="logo" type="file" accept="image/*"></div>
<div class="field"><label>Favicon <span class="optional">(ICO/PNG, max 500KB)</span></label><input name="favicon" type="file" accept="image/*,.ico"></div>
</div>
<div class="actions"><div></div><button class="btn btn-primary" type="submit">Save Branding →</button></div>
</form>

<?php elseif ($step === 'link'): ?>
<h2>License Hub <span class="optional">(optional)</span></h2>
<p style="color:#64748b;font-size:13px;line-height:1.6;margin-bottom:16px">Paste the link code Indisse-BTS gave you (it starts with <code>ohl1.</code>). Once a day this installation then asks the License Hub for its license, so renewals and plan changes arrive by themselves, and updates can be offered. It sends only its installation ID, build, PHP and database versions, number of active users and license state; nothing about people, tickets or settings. You can also do this later in Administration → Updates → License Hub.</p>
<form method="POST">
<input type="hidden" name="step" value="link">
<div class="field"><label>Link code</label><input name="link_code" autocomplete="off" placeholder="ohl1.…" value=""></div>
<div class="actions">
<button class="btn btn-ghost" type="submit" name="skip" value="1">Skip for now →</button>
<button class="btn btn-primary" type="submit">Save & Continue →</button>
</div>
</form>

<?php elseif ($step === 'hubsetup'): ?>
<h2>License Hub setup</h2>
<p style="color:#64748b;font-size:13px;line-height:1.6;margin-bottom:16px">Finalizing creates the two keys of the License Hub: the <strong>license key</strong> (signs licenses) and the <strong>release key</strong> (signs update bundles). Their backups are encrypted with the passphrase below and are downloaded on the last page. Keep the backup files and the passphrase in two different safe places: without them no new license or update can be made that existing builds accept.</p>
<form method="POST">
<input type="hidden" name="step" value="hubsetup">
<div class="field"><label>Public address of this hub</label><input name="hub_url" placeholder="https://licenses.example.com" value="<?= htmlspecialchars((string)($_SESSION['install']['hub_url'] ?? ($_SESSION['install']['app_url'] ?? '')), ENT_QUOTES, 'UTF-8') ?>" required><small>HTTPS, on a domain you own. Installations check in here; it goes into every link code.</small></div>
<div class="field"><label>Organization</label><input name="licensee" value="<?= htmlspecialchars((string)($_SESSION['install']['hub_licensee'] ?? 'Indisse-BTS'), ENT_QUOTES, 'UTF-8') ?>" required><small>This main installation gets its own license (Complete, no end date) issued to it.</small></div>
<div class="row">
<div class="field"><label>Backup passphrase</label><input name="pass1" type="password" autocomplete="new-password" required><small>At least 12 characters</small></div>
<div class="field"><label>Passphrase again</label><input name="pass2" type="password" autocomplete="new-password" required></div>
</div>
<div class="actions"><div></div><button class="btn btn-primary" type="submit">Continue →</button></div>
</form>

<?php elseif ($step === 'finalize'): ?>
<h2>Ready to Install</h2>
<p style="color:#64748b;font-size:14px;line-height:1.7;margin-bottom:20px">
Everything is configured. Click below to write the configuration file and finalize your installation.
</p>
<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:20px">
<div style="font-size:13px;color:#334155;line-height:1.8">
<strong>Database:</strong> <?= htmlspecialchars($_SESSION['install']['db_name'] ?? '') ?>@<?= htmlspecialchars($_SESSION['install']['db_host'] ?? '') ?><br>
<strong>Admin:</strong> <?= htmlspecialchars($_SESSION['install']['admin_email'] ?? '') ?><br>
<strong>O365 SSO:</strong> <?= !empty($_SESSION['install']['o365_enabled']) ? 'Enabled' : 'Disabled' ?><br>
<strong>Platform:</strong> <?= htmlspecialchars($_SESSION['install']['app_name'] ?? 'ITOps') ?><br>
<strong>Time zone:</strong> <?= htmlspecialchars((string)($_SESSION['install']['timezone'] ?? 'America/New_York'), ENT_QUOTES, 'UTF-8') ?><br>
<?php if ($mode === 'hub'): ?><strong>Role:</strong> Main installation (License Hub) at <?= htmlspecialchars((string)($_SESSION['install']['hub_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
<?php else: ?><strong>License Hub:</strong> <?= !empty($_SESSION['install']['link_code']) ? 'Linked with a link code' : 'Not linked (can be done later)' ?><?php endif; ?>
</div>
</div>
<form method="POST">
<input type="hidden" name="step" value="finalize">
<div class="actions"><div></div><button class="btn btn-primary" type="submit">🚀 Finalize Installation</button></div>
</form>

<?php elseif ($step === 'complete'): ?>
<div class="success-box">
<h3>✅ Installation Complete!</h3>
<p style="color:#334155;font-size:14px;margin-bottom:16px">ITOps has been installed successfully.</p>
<?php $hd = $_SESSION['hub_done'] ?? []; ?>
<?php if (!empty($hd['mig_error'])): ?><div class="error" style="margin-bottom:14px">The database update did not finish (<?= htmlspecialchars((string)$hd['mig_error'], ENT_QUOTES, 'UTF-8') ?>). Sign in and run Settings → Maintenance → Database update, or run db-update.php once.</div><?php endif; ?>
<?php if (($hd['mode'] ?? '') === 'hub'): ?>
<?php if (!empty($hd['hub_error'])): ?><div class="error" style="text-align:left">The License Hub setup did not finish: <?= htmlspecialchars((string)$hd['hub_error'], ENT_QUOTES, 'UTF-8') ?>. Sign in and finish it in Administration → License Manager.</div>
<?php else: ?>
<div style="text-align:left;font-size:13.5px;color:#334155;line-height:1.6" data-hub-done="1">
<p style="margin-bottom:10px"><strong>This is the License Hub</strong> at <?= htmlspecialchars((string)$hd['hub_url'], ENT_QUOTES, 'UTF-8') ?>, with its own license <?= htmlspecialchars((string)$hd['serial'], ENT_QUOTES, 'UTF-8') ?>.</p>
<p style="margin-bottom:6px"><strong>1. Download both key backups now</strong> (they are not offered again; later, License Manager makes new ones):</p>
<p style="margin-bottom:12px"><a class="btn btn-ghost" href="hub.php?dl=license" data-hub-dl="license">License key backup</a> <a class="btn btn-ghost" href="hub.php?dl=release" data-hub-dl="release">Release key backup</a></p>
<p style="margin-bottom:6px"><strong>2. Send these two lines to be put into the ITOps builds</strong> (public halves, safe to share):</p>
<code style="display:block;background:#f1f5f9;padding:8px;border-radius:6px;font-size:11.5px;word-break:break-all;margin-bottom:4px" data-hub-line="license">License key: <?= htmlspecialchars((string)$hd['lines']['license'], ENT_QUOTES, 'UTF-8') ?></code>
<code style="display:block;background:#f1f5f9;padding:8px;border-radius:6px;font-size:11.5px;word-break:break-all;margin-bottom:12px" data-hub-line="release">Release key: <?= htmlspecialchars((string)$hd['lines']['release'], ENT_QUOTES, 'UTF-8') ?></code>
<p style="margin-bottom:12px"><strong>3.</strong> Link code for each installation: License Manager → Installations.</p>
</div>
<script src="hub-done.js?v=133"></script>
<?php endif; ?>
<?php endif; ?>
<?php if (!empty($hd['hub_file'])): ?><div class="error" style="text-align:left;margin-bottom:14px" data-hubfile-left="1"><?= htmlspecialchars((string)$hd['hub_file'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if (($hd['mode'] ?? '') !== 'hub' && isset($hd['link'])): ?>
<div class="<?= !empty($hd['link_ok']) ? 'success-box' : 'error' ?>" style="text-align:left;margin:0 0 14px;padding:10px 14px;font-size:13px" data-link-done="<?= !empty($hd['link_ok']) ? 'ok' : 'fail' ?>">License Hub: <?= htmlspecialchars((string)$hd['link'], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<p style="color:#64748b;font-size:13px;margin-bottom:20px"><strong>Important:</strong> Delete the /install/ directory for security.</p>
<?php // BT133: no inline script (the site's Content-Security-Policy blocks it): hub-done.js unlocks the button; a reload
      // after both downloads unlocks it from the session too
      $wait = ($hd['mode'] ?? '') === 'hub' && empty($hd['hub_error']) && !(!empty($hd['got']['license']) && !empty($hd['got']['release'])); ?>
<a href="../" id="hubOpen" class="btn btn-primary" style="text-decoration:none<?= $wait ? ';pointer-events:none;opacity:.45' : '' ?>"><?= $wait ? 'Download both backups first' : 'Open ITOps →' ?></a>
</div>
<?php endif; ?>

</div>
</body>
</html>
