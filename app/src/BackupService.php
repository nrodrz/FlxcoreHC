<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * On-demand backups for the platform operator. Everything is generated IN MEMORY (the complete SQL dump, then its
 * gzip copy, then — for a sealed download — the encrypted copy are all held in memory at once) and then sent to the
 * browser. It is NOT a streaming backup: peak memory grows with database size (see docs/architecture/BACKUP_MEMORY.md
 * for measurements and the practical limit). Nothing is ever written to a web-accessible path, because a SQL
 * dump or a docs archive sitting in the webroot would be a full-data leak.
 *
 * Two artifacts:
 *   - Database: a pure-PHP dump (SHOW CREATE + INSERTs), gzipped. Encrypted columns are dumped
 *     as-is (ciphertext), so a restore is byte-identical — but only decryptable with the SAME
 *     crypto keys from config.php, which are NOT in the database. The operator must back up
 *     config.php separately and securely.
 *   - Documents: the storage/docs tree as a gzipped tar (dependency-free tar writer, since
 *     ZipArchive isn't guaranteed on shared hosting).
 *
 * No shell, no mysqldump, no Composer — all pure PHP.
 */
final class BackupService
{
    private const LAST_BACKUP_KEY = 'last_backup_at';

    /** Record that a backup was taken now (for the freshness nudge). */
    public static function markTaken(): void
    {
        try { SettingsService::put([self::LAST_BACKUP_KEY => utc_now()]); } catch (\Throwable $e) {}
    }

    public static function lastBackupAt(): ?string
    {
        $v = SettingsService::get(self::LAST_BACKUP_KEY, '');
        return $v ?: null;
    }

    /** Whole days since the last backup, or null if never. */
    public static function daysSinceBackup(): ?int
    {
        $last = self::lastBackupAt();
        if (!$last) return null;
        return (int) floor((time() - strtotime($last)) / 86400);
    }

    /**
     * Build a gzipped SQL dump of the whole database and return it as bytes.
     * Reads row by row (unbuffered fetch loop) but ACCUMULATES the whole dump in one string — not streamed.
     */
    public static function databaseDump(): string
    {
        $pdo = Database::pdo();
        $dbName = (string) config('db_name');
        $out  = "-- FlxCoreHC database backup\n";
        $out .= '-- Generated: ' . utc_now() . " UTC\n";
        $out .= '-- Database: ' . $dbName . "\n";
        $out .= "-- NOTE: encrypted columns are stored as ciphertext; restoring requires the\n";
        $out .= "--       crypto_data_key / crypto_index_key from the ORIGINAL config.php.\n\n";
        $out .= "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n";

        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        // v1.174.0: only this application's tables (the database may hold others)
        if (Database::prefix() !== '') $tables = array_values(array_filter($tables, fn($t) => str_starts_with((string) $t, Database::prefix())));
        foreach ($tables as $table) {
            $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(\PDO::FETCH_ASSOC);
            $ddl = $create['Create Table'] ?? ($create['Create View'] ?? '');
            $out .= "\n-- ----- Table: {$table} -----\n";
            $out .= "DROP TABLE IF EXISTS `{$table}`;\n" . $ddl . ";\n\n";

            $rows = $pdo->query('SELECT * FROM `' . $table . '`');
            $cols = null;
            while (($row = $rows->fetch(\PDO::FETCH_ASSOC)) !== false) {
                if ($cols === null) {
                    $cols = array_keys($row);
                    $out .= 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . "`) VALUES\n";
                    $first = true;
                }
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = $v === null ? 'NULL' : $pdo->quote((string) $v);
                }
                $out .= ($first ? '' : ",\n") . '(' . implode(',', $vals) . ')';
                $first = false;
            }
            if ($cols !== null) $out .= ";\n";
        }
        $out .= "\nSET FOREIGN_KEY_CHECKS=1;\n";

        return gzencode($out, 6);
    }

    /**
     * Build a gzipped tar of the documents directory and return it as bytes. Empty archive
     * if there are no documents. Files are archived exactly as stored on disk (already sealed with
     * EncryptedFile, so the archive never holds document plaintext; restore needs the crypto keys).
     * Uses a minimal tar writer (no ZipArchive dependency).
     */
    public static function documentsArchive(): string
    {
        $base = rtrim((string) config('docs_path'), '/');
        $tar = '';
        if (is_dir($base)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (!$file->isFile()) continue;
                $rel = ltrim(substr($file->getPathname(), strlen($base)), '/');
                if ($rel === '' || str_contains($rel, '..')) continue;
                $data = @file_get_contents($file->getPathname());
                if ($data === false) continue;
                $tar .= self::tarHeader($rel, strlen($data)) . $data;
                $pad = 512 - (strlen($data) % 512);
                if ($pad < 512) $tar .= str_repeat("\0", $pad);
            }
        }
        $tar .= str_repeat("\0", 1024);   // two empty blocks terminate a tar
        return gzencode($tar, 6);
    }

    /** A single POSIX ustar header block (512 bytes) for a regular file. */
    private static function tarHeader(string $name, int $size): string
    {
        $name = substr($name, 0, 100);
        $h = pack('a100', $name);              // name
        $h .= pack('a8', '0000644');           // mode
        $h .= pack('a8', '0000000');           // uid
        $h .= pack('a8', '0000000');           // gid
        $h .= pack('a12', sprintf('%011o', $size));       // size (octal)
        $h .= pack('a12', sprintf('%011o', time()));      // mtime (octal)
        $h .= pack('a8', str_repeat(' ', 8));  // checksum placeholder
        $h .= pack('a1', '0');                 // typeflag: regular file
        $h .= pack('a100', '');                // linkname
        $h .= pack('a6', 'ustar') . pack('a2', '00');     // magic + version
        $h .= pack('a32', '') . pack('a32', '');          // uname / gname
        $h .= pack('a8', '') . pack('a8', '');            // devmajor / devminor
        $h .= pack('a155', '');                // prefix
        $h .= pack('a12', '');                 // padding to 512

        // Compute and inject the checksum.
        $sum = 0;
        for ($i = 0; $i < 512; $i++) $sum += ord($h[$i]);
        $chk = pack('a8', sprintf('%06o', $sum) . "\0 ");
        return substr($h, 0, 148) . $chk . substr($h, 156);
    }
}
