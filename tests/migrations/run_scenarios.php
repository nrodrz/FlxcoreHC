<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * v1.170.9 — migration validation scenarios A–D for migrations 124/125.  AUTHORED — NOT EXECUTED IN CLAUDE.AI
 * (no MySQL there): REQUIRES EXTERNAL RUNTIME VALIDATION.  Uses throwaway databases flx_mig_a … flx_mig_c and
 * never touches the configured application database.
 *
 *   php tests/migrations/run_scenarios.php      exit 0 = ALL PASS · 1 = failure/setup error · 3 = skipped (no MySQL)
 *
 * A  fresh install, 001→125 in manifest order
 * B  v1.170.7 → 124 → 125 : state = everything up to 123, with legacy data seeded, then 124 and 125 applied
 * C  older install: stop BEFORE the insurance clean-up migrations (072), seed insurance-era rows, apply the rest
 * D  (custom master_admin role, reserved-slug users, tenant_compliance on insurance items, healthcare rows) is seeded
 *    into B and C and asserted there; tests/authz/test_authorization.php group D repeats it through the app layer.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$ROOT = dirname(__DIR__, 2);
if (!extension_loaded('pdo_mysql')) { fwrite(STDERR, "SKIP(3): pdo_mysql not available.\n"); exit(3); }
$cfgFile = (getenv('FLX_CONFIG') ?: '') !== '' && is_file((string) getenv('FLX_CONFIG')) ? (string) getenv('FLX_CONFIG') : $ROOT . '/app/config.php';
$cfg = is_file($cfgFile) ? (require $cfgFile) : [];
$dsn = fn(?string $db) => 'mysql:host=' . ($cfg['db_host'] ?? '127.0.0.1') . ';port=' . ($cfg['db_port'] ?? 3306) . ';charset=utf8mb4' . ($db ? ";dbname=$db" : '');
$user = $cfg['db_user'] ?? 'root'; $pass = $cfg['db_pass'] ?? '';
try { $root = new PDO($dsn(null), $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
catch (\Throwable $e) { fwrite(STDERR, "SKIP(3): cannot connect to MySQL: " . $e->getMessage() . "\n"); exit(3); }

$fail = false;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };
$files = require $ROOT . '/database/manifest.php';

$apply = function (PDO $pdo, string $rel) use ($ROOT): int {
    $sql = preg_replace('~^\s*--.*$~m', '', (string) file_get_contents($ROOT . '/' . $rel));
    $errors = 0;
    foreach (array_filter(array_map('trim', preg_split('~;\s*[\r\n]+~', $sql)), fn($s) => $s !== '') as $stmt) {
        try {
            if (preg_match('/^\s*\(?\s*(SELECT|SHOW|WITH|DESCRIBE|DESC|EXPLAIN|CALL)\b/i', $stmt)) { $q = $pdo->query($stmt); if ($q) { $q->fetchAll(); $q->closeCursor(); } }
            else $pdo->exec($stmt);
        } catch (\PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            if (!in_array($code, [1050, 1060, 1061, 1062, 1022, 1826, 1091, 3940, 3959], true)) { $errors++; fwrite(STDERR, "  MIGRATION ERROR " . basename($rel) . " [$code]: " . $e->getMessage() . "\n"); }
        }
    }
    return $errors;
};
$fresh = function (string $db) use ($root, $dsn, $user, $pass): PDO {
    $root->exec("DROP DATABASE IF EXISTS `$db`");
    $root->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    return new PDO($dsn($db), $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
};
$idx = fn(string $needle) => (function () use ($files, $needle) { foreach ($files as $i => $f) if (str_contains($f, $needle)) return $i; return -1; })();
$i124 = $idx('124_healthcare_only'); $i125 = $idx('125_healthcare_cleanup');
if ($i124 < 0 || $i125 < 0 || $i125 < $i124) { fwrite(STDERR, "manifest does not list 124 then 125\n"); exit(1); }

// legacy data seeded BEFORE 124/125 (scenario D content)
$seedLegacy = function (PDO $pdo, string $tag): array {
    $now = gmdate('Y-m-d H:i:s'); $ids = [];
    $tIns = bin2hex(random_bytes(8)) . "-ins-$tag"; $tHc = bin2hex(random_bytes(8)) . "-hc-$tag";
    // (36-char ids not required by MySQL CHAR(36) with padding, but keep them uuid-shaped)
    $u = fn() => sprintf('%08x-%04x-4%03x-8%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff));
    $tIns = $u(); $tHc = $u();
    $pdo->prepare("INSERT INTO tenants (id, name, vertical, org_shape, status, created_at) VALUES (?,?,?,?,?,?)")->execute([$tIns, "Legacy Insurance $tag", 'insurance', 'agency', 'active', $now]);
    $pdo->prepare("INSERT INTO tenants (id, name, vertical, org_shape, status, created_at) VALUES (?,?,?,?,?,?)")->execute([$tHc, "Healthcare $tag", 'healthcare', 'medical_group', 'active', $now]);
    $h = $u();
    $pdo->prepare("INSERT INTO credential_holders (id, tenant_id, holder_type, first_name, last_name, vertical, status, created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([$h, $tHc, 'individual', 'Pat', 'Provider', 'healthcare', 'active', $now]);
    $c = $u();
    $pdo->prepare("INSERT INTO credentials (id, tenant_id, holder_id, credential_type, name, status, created_at) VALUES (?,?,?,?,?,?,?)")->execute([$c, $tHc, $h, 'state_license', 'Legacy License', 'active', $now]);
    $hi = $u();
    $pdo->prepare("INSERT INTO credential_holders (id, tenant_id, holder_type, first_name, last_name, vertical, status, created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([$hi, $tIns, 'individual', 'Agent', 'Legacy', 'insurance', 'active', $now]);
    $r = $u();
    $pdo->prepare("INSERT INTO roles (id, tenant_id, slug, name, is_system, portal_only, sort_order, created_at) VALUES (?,?,?,?,0,0,100,?)")->execute([$r, $tHc, 'master_admin', 'Master Admin (custom)', $now]);
    // collision case: the tenant ALREADY has a role called master_admin_role (e.g. created on v1.170.8) — 125 must not abort
    $pdo->prepare("INSERT INTO roles (id, tenant_id, slug, name, is_system, portal_only, sort_order, created_at) VALUES (?,?,?,?,0,0,101,?)")->execute([$u(), $tHc, 'master_admin_role', 'Master Admin Role', $now]);
    $usr = $u();
    $pdo->prepare("INSERT INTO users (id, tenant_id, email, password_hash, full_name, role, status, created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([$usr, $tHc, "evil-$tag@mig.test", 'x', 'Reserved Slug User', 'master_admin', 'active', $now]);
    $item = $pdo->query("SELECT id FROM compliance_items WHERE applies_to='insurance' LIMIT 1")->fetchColumn();
    $tc = null;
    if ($item !== false) { $tc = $u(); $pdo->prepare("INSERT INTO tenant_compliance (id, tenant_id, item_id, status, created_at) VALUES (?,?,?,?,?)")->execute([$tc, $tIns, $item, 'done', $now]); }
    return compact('tIns', 'tHc', 'h', 'hi', 'c', 'r', 'usr', 'item', 'tc');
};
$assertAfter = function (PDO $pdo, array $L, string $scen) use ($check) {
    $check((int) $pdo->query("SELECT COUNT(*) FROM tenants WHERE vertical IN ('insurance','custom')")->fetchColumn() === 0, "$scen no insurance/custom tenants remain");
    $check((int) $pdo->query("SELECT COUNT(*) FROM tenants WHERE org_shape IN ('agency','fmo','solo_agent','custom')")->fetchColumn() === 0, "$scen no legacy org shapes remain");
    $check((int) $pdo->query("SELECT COUNT(*) FROM credential_holders WHERE vertical <> 'healthcare'")->fetchColumn() === 0, "$scen all holders healthcare");
    $check((int) $pdo->query("SELECT COUNT(*) FROM compliance_items WHERE applies_to='insurance'")->fetchColumn() === 0, "$scen insurance compliance items removed (FK-safe with tenant_compliance children)");
    $check((int) $pdo->query("SELECT COUNT(*) FROM credentials WHERE id=" . $pdo->quote($L['c']))->fetchColumn() === 1, "$scen healthcare credential intact");
    $check((int) $pdo->query("SELECT COUNT(*) FROM credential_holders WHERE id=" . $pdo->quote($L['h']))->fetchColumn() === 1, "$scen healthcare provider intact");
    $check(str_starts_with((string)$pdo->query("SELECT slug FROM roles WHERE id=" . $pdo->quote($L['r']))->fetchColumn(), 'master_admin_role_'), "$scen custom role with reserved slug renamed");
    $check($pdo->query("SELECT role FROM users WHERE id=" . $pdo->quote($L['usr']))->fetchColumn() === $pdo->query("SELECT slug FROM roles WHERE id=" . $pdo->quote($L['r']))->fetchColumn(), "$scen user moved with the renamed role (not orphaned)");
    $check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='tenants' AND column_name IN ('group_npi','taxonomy_code','medicare_ptan','medicaid_id','clia_number','facility_license','accreditation')")->fetchColumn() === 7, "$scen tenants has the 7 healthcare entity columns");
    $len = (int) $pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='credential_catalog' AND column_name='applies_to'")->fetchColumn();
    $check($len >= 255, "$scen credential_catalog.applies_to widened (len $len)");
    $check($pdo->query("SELECT name FROM roles WHERE tenant_id IS NULL AND slug='producer' LIMIT 1")->fetchColumn() !== 'Producer (self-service)', "$scen portal role display name is not 'Producer'");
};
$rerun = function (PDO $pdo, string $rel, int &$errs) use ($apply) { $errs += $apply($pdo, $rel); };

// ---- A: fresh install
$pdo = $fresh('flx_mig_a'); $errs = 0;
foreach ($files as $f) $errs += $apply($pdo, $f);
$check($errs === 0, "A fresh install 001→125: zero migration errors (got $errs)");
$check((int) $pdo->query("SELECT COUNT(*) FROM compliance_items WHERE applies_to='insurance'")->fetchColumn() === 0, 'A no insurance compliance items on a fresh install');
$check((int) $pdo->query("SELECT COUNT(*) FROM tenants WHERE vertical IN ('insurance','custom')")->fetchColumn() === 0, 'A no insurance tenants');
$e2 = $apply($pdo, $files[$i124]) + $apply($pdo, $files[$i125]);
$check($e2 === 0, 'A migrations 124+125 are re-runnable (idempotent) on a migrated DB');

// ---- B: v1.170.7 → 124 → 125
$pdo = $fresh('flx_mig_b'); $errs = 0;
foreach ($files as $i => $f) { if ($i < $i124) $errs += $apply($pdo, $f); }
$check($errs === 0, "B baseline (everything before 124) applied cleanly (got $errs)");
try { $L = $seedLegacy($pdo, 'b'); } catch (\Throwable $e) { fwrite(STDERR, "B SETUP ERROR: " . $e->getMessage() . "\n"); $check(false, 'B legacy data seeded'); $L = null; }
if ($L) {
    $e124 = $apply($pdo, $files[$i124]); $e125 = $apply($pdo, $files[$i125]);
    $check($e124 === 0 && $e125 === 0, "B 124 then 125 apply with legacy data present (errors: $e124/$e125)");
    $assertAfter($pdo, $L, 'B');
}

// ---- C: older install (stop before the insurance clean-up migrations)
$i072 = $idx('072_');
$pdo = $fresh('flx_mig_c'); $errs = 0;
$stop = $i072 > 0 ? $i072 : $i124;
foreach ($files as $i => $f) { if ($i < $stop) $errs += $apply($pdo, $f); }
$check($errs === 0, "C older baseline (before " . basename($files[$stop]) . ") applied cleanly (got $errs)");
try { $L = $seedLegacy($pdo, 'c'); } catch (\Throwable $e) { fwrite(STDERR, "C SETUP ERROR: " . $e->getMessage() . "\n"); $check(false, 'C legacy data seeded'); $L = null; }
if ($L) {
    $errs = 0; foreach ($files as $i => $f) { if ($i >= $stop) $errs += $apply($pdo, $f); }
    $check($errs === 0, "C every later migration applies on top of the older install (errors: $errs)");
    $assertAfter($pdo, $L, 'C');
}

foreach (['flx_mig_a', 'flx_mig_b', 'flx_mig_c'] as $db) { try { $root->exec("DROP DATABASE IF EXISTS `$db`"); } catch (\Throwable $e) {} }
echo "\n" . ($fail ? "SOME FAILED\n" : "ALL PASS\n");
exit($fail ? 1 : 0);
