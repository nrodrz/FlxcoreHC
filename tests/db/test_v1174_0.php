<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * v1.174.0 — table prefix (fchc_). Runs WITHOUT a database:  php tests/db/test_v1174_0.php
 * X1 the SQL rewriter puts the prefix exactly where SQL expects a table and nowhere else (strings, comments, columns, aliases, other schemas)
 * X2 it is idempotent and an empty prefix changes nothing
 * X3 every statement of every migration is rewritten without touching a column or a string
 * X4 the installer, the single PDO door and the places that put a table name in a value are prefix-aware
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$ROOT = dirname(__DIR__, 2);
define('FLXCOREHC_ROOT', $ROOT);
require_once "$ROOT/app/src/SqlPrefixer.php";
use FlxCoreHC\SqlPrefixer as P;
$fail = false;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };
$rw = fn(string $s) => P::rewrite($s, 'fchc_');
$eq = function (string $in, string $want, string $label) use ($rw, $check) { $got = $rw($in); $check($got === $want, "X1 $label" . ($got === $want ? '' : "\n   got:  $got\n   want: $want")); };

// ---- X1 ----
$eq("SELECT u.id, t.name FROM users u JOIN tenants t ON t.id = u.tenant_id WHERE u.email = 'users'", "SELECT u.id, t.name FROM fchc_users u JOIN fchc_tenants t ON t.id = u.tenant_id WHERE u.email = 'users'", 'FROM / JOIN, and a table name inside a string literal is left alone');
$eq("INSERT INTO credentials (id, name) VALUES (?, 'x') ON DUPLICATE KEY UPDATE name = VALUES(name)", "INSERT INTO fchc_credentials (id, name) VALUES (?, 'x') ON DUPLICATE KEY UPDATE name = VALUES(name)", 'INSERT INTO … ON DUPLICATE KEY UPDATE (the second UPDATE is not a table position)');
$eq("UPDATE users SET role = ? WHERE id = ?", "UPDATE fchc_users SET role = ? WHERE id = ?", 'UPDATE');
$eq("DELETE FROM credential_holders WHERE tenant_id = ?", "DELETE FROM fchc_credential_holders WHERE tenant_id = ?", 'DELETE FROM');
$eq("CREATE TABLE IF NOT EXISTS tenants (id CHAR(36), CONSTRAINT fk FOREIGN KEY (a) REFERENCES users(id)) ENGINE=InnoDB", "CREATE TABLE IF NOT EXISTS fchc_tenants (id CHAR(36), CONSTRAINT fk FOREIGN KEY (a) REFERENCES fchc_users(id)) ENGINE=InnoDB", 'CREATE TABLE IF NOT EXISTS … REFERENCES');
$eq("SELECT users.id, roles.name FROM users, roles WHERE roles.id = users.role_id", "SELECT fchc_users.id, fchc_roles.name FROM fchc_users, fchc_roles WHERE fchc_roles.id = fchc_users.role_id", 'comma-joined FROM list and table-qualified columns');
$eq("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'users' AND x = 'users'", "SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'fchc_users' AND x = 'users'", 'information_schema itself is untouched; only a TABLE_NAME comparison gets the real name');
$eq("SELECT * FROM (SELECT id FROM users) x WHERE EXISTS (SELECT 1 FROM roles r WHERE r.id = x.id)", "SELECT * FROM (SELECT id FROM fchc_users) x WHERE EXISTS (SELECT 1 FROM fchc_roles r WHERE r.id = x.id)", 'subqueries and EXISTS (…)');
$eq("SELECT id FROM users -- from roles\n WHERE 1", "SELECT id FROM fchc_users -- from roles\n WHERE 1", 'comments are untouched');
$eq("SELECT /* from users */ id FROM users", "SELECT /* from users */ id FROM fchc_users", 'block comments are untouched');
$eq("ALTER TABLE tenants ADD COLUMN plans INT NULL", "ALTER TABLE fchc_tenants ADD COLUMN plans INT NULL", 'ALTER TABLE; a COLUMN named like a table is not renamed');
$eq("SELECT plans, roles FROM tenants", "SELECT plans, roles FROM fchc_tenants", 'a column list that names tables is not renamed');
$eq("SHOW COLUMNS FROM `credentials`", "SHOW COLUMNS FROM `fchc_credentials`", 'backticked name');
$eq("SELECT x FROM tenants t LEFT JOIN users u ON u.tenant_id = t.id, roles r WHERE 1", "SELECT x FROM fchc_tenants t LEFT JOIN fchc_users u ON u.tenant_id = t.id, roles r WHERE 1", 'a comma after ON is not a table list');
$eq("SELECT * FROM users WHERE name = 'it''s users' AND x = \"tenants\"", "SELECT * FROM fchc_users WHERE name = 'it''s users' AND x = \"tenants\"", 'escaped quotes and double-quoted strings');
$eq("INSERT IGNORE INTO schema_migrations (filename) VALUES ('x')", "INSERT IGNORE INTO fchc_schema_migrations (filename) VALUES ('x')", 'schema_migrations too');
$eq("SELECT id FROM users FOR UPDATE", "SELECT id FROM fchc_users FOR UPDATE", 'FOR UPDATE is not an UPDATE of a table');
$eq("DROP TABLE IF EXISTS `fchc_users`", "DROP TABLE IF EXISTS `fchc_users`", 'a name that already has the prefix is left alone');
$eq("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plans' AND COLUMN_NAME = 'plans'", "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fchc_plans' AND COLUMN_NAME = 'plans'", 'TABLE_NAME = \'plans\' in information_schema becomes the real name (a column value is not touched)');
$eq("SET @ddl := IF(@col > 0, 'ALTER TABLE plans DROP COLUMN max_policies', 'DO 0')", "SET @ddl := IF(@col > 0, 'ALTER TABLE fchc_plans DROP COLUMN max_policies', 'DO 0')", 'dynamic DDL kept in a string for PREPARE');
$eq("RENAME TABLE carriers TO regulators", "RENAME TABLE fchc_carriers TO fchc_regulators", 'RENAME TABLE a TO b (a retired table name and its new name)');
$eq("DROP TABLE IF EXISTS bookings", "DROP TABLE IF EXISTS fchc_bookings", 'DROP of a retired table');
$eq("SELECT * FROM somethingelse", "SELECT * FROM somethingelse", 'a table that is not the application\'s is left alone');

// ---- X2 ----
$check($rw($rw("SELECT * FROM users u JOIN tenants t ON t.id = u.tenant_id")) === $rw("SELECT * FROM users u JOIN tenants t ON t.id = u.tenant_id"), 'X2 rewriting twice gives the same as once');
$check(P::rewrite("SELECT * FROM users", '') === "SELECT * FROM users", 'X2 an empty prefix changes nothing');
$check(P::name('users', 'fchc_') === 'fchc_users' && P::name('nope', 'fchc_') === 'nope', 'X2 name(): logical -> real, unknown names unchanged');

// ---- X3 ----
$tables = require "$ROOT/database/tables.php"; $bad = []; $stmts = 0; $touched = 0;
foreach (require "$ROOT/database/manifest.php" as $f) {
    $sql = (string) preg_replace('~^\s*--.*$~m', '', (string) file_get_contents("$ROOT/$f"));
    foreach (preg_split('~;\s*[\r\n]+~', $sql) as $st) {
        $st = trim($st); if ($st === '') continue; $stmts++;
        $out = P::rewrite($st, 'fchc_');
        if ($out !== $st) $touched++;
        // every table name in a table position must now carry the prefix, and no 'fchc_fchc_' may appear
        if (str_contains($out, 'fchc_fchc_')) $bad[] = "$f: double prefix";
        if (preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE|TABLE|REFERENCES)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?([a-z_][a-z0-9_]*)`?/i', $out, $m)) {
            foreach ($m[1] as $name) if (in_array($name, $tables, true)) $bad[] = "$f: $name left without the prefix near: " . substr($st, 0, 70);
        }
        // the statement minus the prefix must be the original (nothing else was changed)
        if (str_replace('fchc_', '', $out) !== str_replace('fchc_', '', $st)) $bad[] = "$f: something other than the prefix changed";
    }
}
$check($bad === [], "X3 $stmts migration statements ($touched touched): every table position prefixed, nothing else altered" . ($bad ? "\n   " . implode("\n   ", array_slice($bad, 0, 5)) : ''));

// ---- X4 ----
$rd = fn(string $f) => (string) file_get_contents("$ROOT/$f");
$db = $rd('app/src/Database.php');
$check(str_contains($db, "config('db_prefix', '')") && str_contains($db, 'new PrefixedPdo(') && substr_count($db, 'new PDO(') === 1, 'X4 Database::pdo() builds the prefixed PDO from config db_prefix (one door)');
$inst = $rd('install.php');
$check(str_contains($inst, "const FLX_TABLE_PREFIX = 'fchc_';") && str_contains($inst, "'db_prefix' => FLX_TABLE_PREFIX") && str_contains($inst, 'new \FlxCoreHC\PrefixedPdo('), 'X4 the installer creates the tables with the fchc_ prefix and writes db_prefix to config');
$check(!str_contains($inst, "SHOW TABLES LIKE 'tenants'"), 'X4 the installer\'s "already installed?" test looks for the prefixed table');
foreach (['app/src/SchemaDoctor.php' => 'Database::logical', 'app/src/BackupService.php' => 'Database::prefix()', 'app/src/DemoHealthcareService.php' => "Database::table('tenants')",
    'app/src/Migrator.php' => "Database::table('credential_holders')", 'app/src/CustomFieldService.php' => "Database::table('custom_field_values')",
    'app/src/Repository/RegulatorRequirementRepository.php' => "Database::table('regulator_requirements')"] as $f => $needle)
    $check(str_contains($rd($f), $needle), "X4 $f is prefix-aware");
$hits = [];
foreach (['app', 'cron', 'database'] as $d) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$ROOT/$d", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->getExtension() === 'php' && preg_match('/new\s+\\\\?PDO\s*\(/', (string) file_get_contents($f->getPathname()))) $hits[] = substr($f->getPathname(), strlen($ROOT) + 1);
}
if (preg_match('/new\s+\\\\?PDO\s*\(/', $rd('index.php'))) $hits[] = 'index.php';
$check($hits === ['app/src/Database.php'], 'X4 no other file opens its own PDO (every query goes through the prefixing door): ' . implode(', ', $hits));
echo $fail ? "DB PREFIX 1.174.0: FAIL\n" : "DB PREFIX 1.174.0: ALL PASS\n";
exit($fail ? 1 : 0);
