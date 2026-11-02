<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Idempotent schema updater. Applies any migrations from the manifest that a database
 * has not recorded yet, tolerating "already exists / duplicate" errors so it is safe to
 * run against a database at any prior version (including ones created before tracking
 * existed). Drives the admin "Update database" button.
 */
final class Migrator
{
    /**
     * MySQL error codes that mean "this exact change is already in place" — safe to skip on a re-run:
     * 1050 table exists · 1060 duplicate column · 1061 duplicate key name · 1062 duplicate entry (re-seeding)
     * · 1091 can't drop (already gone) · 1826 duplicate FK name · 1022 duplicate key · 3822 duplicate CHECK name.
     * v1.171.0 (B-3): 1146 (table missing), 1054 (unknown column), 1364 (no default), 1005 (can't create table),
     * 3821 and 4025 (constraint failure) were REMOVED. Those mean the migration did NOT do what it says; swallowing
     * them recorded a half-applied migration as done and hid schema damage until a page broke at runtime.
     */
    private const BENIGN = [1050, 1060, 1061, 1062, 1091, 1826, 1022, 3822];

    public static function manifest(): array
    {
        $list = require FLXCOREHC_ROOT . '/database/manifest.php';
        return array_values(array_filter($list, fn($rel) => is_file(FLXCOREHC_ROOT . '/' . $rel)));
    }

    public static function ensureTable(): void
    {
        Database::pdo()->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                filename VARCHAR(190) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /** @return array<string> filenames already recorded as applied */
    public static function applied(): array
    {
        self::ensureTable();
        return Database::pdo()->query("SELECT filename FROM schema_migrations")->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }

    /** @return array{applied:array,pending:array} */
    public static function status(): array
    {
        $done = self::applied();
        $all = array_map('basename', self::manifest());
        return ['applied' => $done, 'pending' => array_values(array_diff($all, $done)), 'all' => $all];
    }

    /**
     * Apply all pending migrations. Returns a per-file report.
     * @return array<array{file:string, ok:bool, statements:int, skipped:int, error:?string, skipped_detail:array}>
     */
    public static function run(): array
    {
        self::ensureTable();
        $pdo = Database::pdo();
        // v1.171.0 (B-3): one updater at a time (two admins clicking "Update database" must not interleave DDL).
        $lock = $pdo->query("SELECT GET_LOCK('flxcorehc_migrate', 0)")->fetchColumn();
        if ((int) $lock !== 1) {
            return [['file' => '(lock)', 'ok' => false, 'statements' => 0, 'skipped' => 0, 'skipped_detail' => [],
                     'error' => 'Another database update is already running. Wait for it to finish, then try again.']];
        }
        try { return self::runLocked($pdo); }
        finally { try { $pdo->query("SELECT RELEASE_LOCK('flxcorehc_migrate')")->fetchAll(); } catch (\Throwable $e) {} }
    }

    private static function runLocked(\PDO $pdo): array
    {
        $done = self::applied();
        // An existing database with NO update history cannot be replayed from migration 003: later migrations retired
        // whole tables, so an early ALTER would fail mid-way. The safe path for such a database is "baseline" first.
        if ($done === []) {
            $has = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote(Database::table('credential_holders')))->fetchColumn();
            if ((int) $has > 0) {
                return [['file' => '(no history)', 'ok' => false, 'statements' => 0, 'skipped' => 0, 'skipped_detail' => [],
                         'error' => 'This database has tables but no update history. Use "Mark as up to date" (baseline) first if it is already current; do not replay every migration.']];
            }
        }
        $report = [];
        foreach (self::manifest() as $rel) {
            $file = basename($rel);
            if (in_array($file, $done, true)) continue;
            $ran = 0; $skipped = 0; $err = null; $detail = [];
            foreach (self::statements(FLXCOREHC_ROOT . '/' . $rel) as $stmt) {
                try { self::execStatement($pdo, $stmt); $ran++; }
                catch (\PDOException $e) {
                    $code = (int) ($e->errorInfo[1] ?? 0);
                    if (in_array($code, self::BENIGN, true)) {
                        $skipped++;
                        $detail[] = ['code' => $code, 'statement' => mb_substr(preg_replace('/\s+/', ' ', $stmt), 0, 140)];
                        continue;
                    }
                    $err = $e->getMessage() . ' — in: ' . mb_substr(preg_replace('/\s+/', ' ', $stmt), 0, 140);
                    break;
                }
            }
            if ($err === null) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO schema_migrations (filename, applied_at) VALUES (?, ?)");
                $stmt->execute([$file, gmdate('Y-m-d H:i:s')]);
            }
            $report[] = ['file' => $file, 'ok' => $err === null, 'statements' => $ran, 'skipped' => $skipped, 'skipped_detail' => $detail, 'error' => $err];
            if ($err !== null) break;
        }
        return $report;
    }

    /** Record every manifest file as applied without running it (baseline an existing DB). */
    public static function baseline(): int
    {
        self::ensureTable();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("INSERT IGNORE INTO schema_migrations (filename, applied_at) VALUES (?, ?)");
        $n = 0;
        foreach (array_map('basename', self::manifest()) as $f) { $stmt->execute([$f, gmdate('Y-m-d H:i:s')]); $n += $stmt->rowCount(); }
        return $n;
    }

    /** Split a .sql file into individual statements (line-comment aware). */
    public static function statements(string $file): array
    {
        $sql = (string) file_get_contents($file);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $out = []; $buf = '';
        foreach (explode("\n", $sql) as $line) {
            $buf .= $line . "\n";
            if (preg_match('/;\s*$/', $line)) { $t = trim($buf); if ($t !== '' && $t !== ';') $out[] = $t; $buf = ''; }
        }
        if (trim($buf) !== '') $out[] = trim($buf);
        return $out;
    }

    // Run one migration statement. Result-returning statements (SELECT/SHOW/…) are drained and closed so
    // they can never leave an open result set that breaks the next query (SQLSTATE HY000/2014).
    private static function execStatement(\PDO $pdo, string $stmt): void
    {
        if (preg_match('/^\s*\(?\s*(SELECT|SHOW|WITH|DESCRIBE|DESC|EXPLAIN|CALL)\b/i', $stmt)) {
            $q = $pdo->query($stmt);
            if ($q) { $q->fetchAll(); $q->closeCursor(); }
            return;
        }
        $pdo->exec($stmt);
    }
}
