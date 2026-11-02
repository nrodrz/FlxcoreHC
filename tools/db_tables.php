<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);
/**
 * Keeps database/tables.php (the logical names of every table the application owns) in step with the migrations.
 *   php tools/db_tables.php --check   build a scratch database from database/manifest.php with NO prefix and compare; exit 1 on a difference
 *   php tools/db_tables.php --write   rewrite database/tables.php from that scratch database
 * Needs MySQL credentials (FLX_CONFIG or app/config.php). Creates and drops the database flx_tables_chk.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$cfgFile = (getenv('FLX_CONFIG') ?: '') !== '' && is_file((string) getenv('FLX_CONFIG')) ? (string) getenv('FLX_CONFIG') : $root . '/app/config.php';
if (!is_file($cfgFile)) { fwrite(STDERR, "SKIP(3): no config (FLX_CONFIG or app/config.php)\n"); exit(3); }
$c = require $cfgFile;
try {
    $pdo = new PDO('mysql:host=' . ($c['db_host'] ?? '127.0.0.1') . ';port=' . ($c['db_port'] ?? 3306) . ';charset=utf8mb4', $c['db_user'] ?? 'root', $c['db_pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('DROP DATABASE IF EXISTS flx_tables_chk'); $pdo->exec('CREATE DATABASE flx_tables_chk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $pdo->exec('USE flx_tables_chk');
    foreach (require "$root/database/manifest.php" as $f) {
        $sql = (string) preg_replace('~^\s*--.*$~m', '', (string) file_get_contents("$root/$f"));
        foreach (preg_split('~;\s*[\r\n]+~', $sql) as $st) { $st = trim($st); if ($st === '') continue; try { $pdo->exec($st); } catch (Throwable $e) { /* benign: re-runnable statements */ } }
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (filename VARCHAR(190) PRIMARY KEY, applied_at DATETIME NOT NULL)');   // made by the installer / Migrator
    $real = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('DROP DATABASE flx_tables_chk');
    // names the migrations create, rename or drop but that are gone from the finished schema
    $mentioned = [];
    foreach (array_merge(['database/schema.sql'], array_map(fn($f) => $f, require "$root/database/manifest.php")) as $f) {
        $t = (string) preg_replace('~^\s*--.*$~m', '', (string) file_get_contents(str_starts_with($f, 'database/') ? "$root/$f" : $f));
        if (preg_match_all('/\b(?:CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|DROP\s+TABLE(?:\s+IF\s+EXISTS)?|ALTER\s+TABLE|RENAME\s+TABLE|TRUNCATE\s+TABLE)\s+`?([a-z_][a-z0-9_]*)`?/i', $t, $m)) foreach ($m[1] as $n) $mentioned[$n] = true;
        if (preg_match_all('/\bRENAME\s+TABLE\s+`?\w+`?\s+TO\s+`?([a-z_][a-z0-9_]*)`?/i', $t, $m)) foreach ($m[1] as $n) $mentioned[$n] = true;
    }
} catch (Throwable $e) { fwrite(STDERR, 'SKIP(3): ' . $e->getMessage() . "\n"); exit(3); }
sort($real);
$listed = require "$root/database/tables.php"; sort($listed);
if (in_array('--write', $argv, true)) {
    $out = "<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */\n/**\n * The logical names of every table the application owns (database/schema.sql + database/migrations). The installed tables carry the\n * configured prefix in front (fchc_ by default for a new installation). Kept in step with the migrations by tools/db_tables.php --check.\n */\nreturn [\n";
    foreach ($real as $t) $out .= "    '$t',\n";
    file_put_contents("$root/database/tables.php", $out . "];\n"); echo 'wrote database/tables.php (' . count($real) . " tables)\n"; exit(0);
}
$legacy = require "$root/database/tables_legacy.php";
$needLegacy = array_diff(array_keys($mentioned), $real); sort($needLegacy); $haveLegacy = $legacy; sort($haveLegacy);
foreach (array_diff($needLegacy, $haveLegacy) as $t) { echo "FAIL — the migrations create/drop/rename $t but it is not in database/tables_legacy.php (it would not get the prefix)\n"; $legFail = true; }
$missing = array_diff($real, $listed); $extra = array_diff($listed, $real); if (!empty($legFail)) { $missing[] = '(legacy list)'; }
foreach ($missing as $t) echo "FAIL — table $t is created by the migrations but is not in database/tables.php (it would not get the prefix)\n";
foreach ($extra as $t) echo "FAIL — database/tables.php lists $t but no migration creates it\n";
echo ($missing || $extra ? 'FAIL' : 'PASS') . ' — database/tables.php matches the ' . count($real) . " tables the migrations create\n";
exit($missing || $extra ? 1 : 0);
