<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);
/**
 * Characterization of BackupService::databaseDump() + DumpSeal memory use (CLI, TEST DATABASES ONLY).
 *   FLX_CONFIG=/path/to/test-config.php php tools/dump_memory_check.php [rows=50000] [payload_bytes=400]
 * Refuses to run unless the configured database name starts with "flx_" (never point it at production). It creates
 * a scratch table, fills it, measures, and drops it. Reports database size, memory_limit, peak memory per phase.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
use FlxCoreHC\Database; use FlxCoreHC\BackupService; use FlxCoreHC\DumpSeal;
$db = (string) config('db_name');
if (!str_starts_with($db, 'flx_')) { fwrite(STDERR, "Refusing: database '$db' is not a flx_* test database.\n"); exit(2); }
$rows = (int) ($argv[1] ?? 50000); $payload = (int) ($argv[2] ?? 400);
$pdo = Database::pdo();
$pdo->exec('DROP TABLE IF EXISTS zz_dump_mem');
$pdo->exec('CREATE TABLE zz_dump_mem (id INT AUTO_INCREMENT PRIMARY KEY, blob1 VARBINARY(2000), txt VARCHAR(255)) ENGINE=InnoDB');
$ins = $pdo->prepare('INSERT INTO zz_dump_mem (blob1, txt) VALUES (?, ?)');
$pdo->beginTransaction();
for ($i = 0; $i < $rows; $i++) { $ins->execute([random_bytes($payload), 'row ' . $i . ' ' . bin2hex(random_bytes(20))]); if ($i % 5000 === 4999) { $pdo->commit(); $pdo->beginTransaction(); } }
$pdo->commit();
$pdo->query('ANALYZE TABLE zz_dump_mem')->fetchAll();
$sz = (float) $pdo->query("SELECT ROUND(SUM(data_length + index_length)/1048576, 1) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
$fmt = fn(int $b) => round($b / 1048576, 1) . ' MB';
echo "database: $db · size on disk: {$sz} MB · memory_limit: " . ini_get('memory_limit') . " · PHP " . PHP_VERSION . "\n";
$base = memory_get_peak_usage(true);
$gz = BackupService::databaseDump();
echo "databaseDump(): output " . $fmt(strlen($gz)) . " · peak " . $fmt(memory_get_peak_usage(true)) . "\n";
$sealed = DumpSeal::seal($gz, 'correct horse battery staple');
echo "DumpSeal::seal(): output " . $fmt(strlen($sealed)) . " · peak " . $fmt(memory_get_peak_usage(true)) . "\n";
$pdo->exec('DROP TABLE IF EXISTS zz_dump_mem');
echo "result: OK (completed within memory_limit)\n";
