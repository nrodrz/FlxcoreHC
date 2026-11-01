<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** Applies schema.sql + the migration files, in the EXACT order install.php lists them, into the
 *  already-selected flx_char database. Reads that ordered list out of install.php so it can never drift
 *  from production ordering. Does not modify install.php, schema.sql, or any migration. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // CLI only — never runnable from a browser
$ROOT = dirname(__DIR__, 2);
$pdo  = FlxCoreHC\Database::pdo();

// Pull the ordered file list from install.php without executing its installer logic.
$installSrc = file_get_contents($ROOT . '/install.php');
preg_match_all("~__DIR__ \\. '(/database/[A-Za-z0-9_./]+\\.sql)'~", $installSrc, $m);
$files = $m[1] ?? [];
if (!$files) { throw new RuntimeException('Could not read migration order from install.php'); }

// Reuse install.php's own statement splitter if present; else a conservative splitter.
$split = function(string $sql): array {
    // strip line comments, split on ';' at line ends (matches the FlxCoreHC DDL style)
    $sql = preg_replace('~^\s*--.*$~m', '', $sql);
    $parts = preg_split('~;\s*[\r\n]+~', $sql);
    return array_filter(array_map('trim', $parts), fn($s) => $s !== '');
};

foreach ($files as $rel) {
    $path = $ROOT . $rel;
    if (!is_file($path)) continue;                 // some entries are conditional
    foreach ($split(file_get_contents($path)) as $stmt) {
        try {
            if (preg_match('/^\s*\(?\s*(SELECT|SHOW|WITH|DESCRIBE|DESC|EXPLAIN|CALL)\b/i', $stmt)) {
                $q = $pdo->query($stmt); if ($q) { $q->fetchAll(); $q->closeCursor(); }
            } else { $pdo->exec($stmt); }
        } catch (\PDOException $e) {
            // Same "already exists" codes the installer tolerates; anything else is SURFACED (v1.170.6 —
            // swallowing every error here hid the 122 HY000/2014 failure from the runtime check).
            $code = (int) ($e->errorInfo[1] ?? 0);
            if (!in_array($code, [1050, 1060, 1061, 1062, 1022, 1826, 1091, 3940, 3959], true)) {
                fwrite(STDERR, "MIGRATION ERROR in " . basename($path) . " [$code]: " . $e->getMessage() . "\n");
                $GLOBALS['__flx_migration_errors'] = ($GLOBALS['__flx_migration_errors'] ?? 0) + 1;
            }
        }
    }
}
if (!empty($GLOBALS['__flx_migration_errors'])) {
    throw new RuntimeException($GLOBALS['__flx_migration_errors'] . ' migration statement(s) failed on a clean database — see MIGRATION ERROR lines above.');
}
