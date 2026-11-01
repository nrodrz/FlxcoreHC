<?php
/**
 * v1.172.16 per-organization time zone tests — self-contained runner. Creates an ISOLATED throwaway database `flx_tz`,
 * installs schema + migrations into it (same order as install.php, via tests/characterization/install_into_char.php),
 * runs tests/tz/test_tenant_timezone.php, using FLX_CONFIG (app/config.php is not modified). Never touches the real database.
 *
 *   php tests/tz/run.php          exit 0 = ALL PASS · 1 = a check failed / error · 3 = skipped (no MySQL)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // CLI only — never runnable from a browser
$ROOT = dirname(__DIR__, 2);
$realCfg = (getenv('FLX_CONFIG') ?: '') !== '' && is_file((string) getenv('FLX_CONFIG')) ? (string) getenv('FLX_CONFIG') : $ROOT . '/app/config.php';
$tmpCfg = sys_get_temp_dir() . '/flx_tz_config_' . getmypid() . '.php';
if (!extension_loaded('pdo_mysql')) { fwrite(STDERR, "SKIP(3): pdo_mysql not available.\n"); exit(3); }
if (!is_file($realCfg)) { fwrite(STDERR, "SKIP(3): no config (app/config.php or FLX_CONFIG) — need DB credentials.\n"); exit(3); }
$src = require $realCfg;
$env = ['db_host' => $src['db_host'] ?? '127.0.0.1', 'db_port' => $src['db_port'] ?? 3306, 'db_user' => $src['db_user'] ?? 'root', 'db_pass' => $src['db_pass'] ?? ''];
$docs = sys_get_temp_dir() . '/flx_tz_docs';
$restore = function () use ($tmpCfg) { @unlink($tmpCfg); };
register_shutdown_function($restore);
try {
    $pdo = new PDO("mysql:host={$env['db_host']};port={$env['db_port']};charset=utf8mb4", $env['db_user'], $env['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("DROP DATABASE IF EXISTS flx_tz");
    $pdo->exec("CREATE DATABASE flx_tz CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    putenv('FLX_CONFIG=' . $tmpCfg);   // the real app/config.php is never touched
    file_put_contents($tmpCfg, "<?php return " . var_export($env + ['db_name' => 'flx_tz', 'app_env' => 'dev',
        'app_key' => str_repeat('0', 64), 'app_timezone' => 'America/New_York', 'docs_path' => $docs], true) . ";\n");
    require $ROOT . '/app/bootstrap.php';
    require $ROOT . '/tests/characterization/install_into_char.php';
    $rc = require __DIR__ . '/test_tenant_timezone.php';
    $restore();
    exit((int) $rc);
} catch (\Throwable $e) {
    fwrite(STDERR, "STARTUP ERROR: " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine() . "\n");
    $restore(); exit(1);
}
