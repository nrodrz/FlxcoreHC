<?php

declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Reads the error_log table for the admin view. Platform-level: errors are recorded across all
 * tenants (and some occur before a tenant is even resolved), so this is not tenant-scoped and is
 * listed in tools/check_isolation.sh accordingly. Only reachable by a platform admin via the route.
 */
final class ErrorLogService
{
    /** Most recent failures first. */
    public static function recent(int $limit = 100, bool $unresolvedOnly = false): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT * FROM error_log';
        if ($unresolvedOnly) $sql .= ' WHERE resolved_at IS NULL';
        $sql .= ' ORDER BY created_at DESC LIMIT ' . $limit;
        return Database::pdo()->query($sql)->fetchAll();
    }

    /** Count of failures not yet marked handled — drives the badge in the admin nav. */
    public static function unresolvedCount(): int
    {
        try {
            return (int) Database::pdo()->query('SELECT COUNT(*) FROM error_log WHERE resolved_at IS NULL')->fetchColumn();
        } catch (\Throwable $e) {
            return 0;   // table not yet migrated
        }
    }

    /** Mark one entry handled, or all of them. */
    public static function resolve(?string $id): int
    {
        if ($id === null || $id === '') {
            return (int) Database::pdo()->exec("UPDATE error_log SET resolved_at = '" . gmdate('Y-m-d H:i:s') . "' WHERE resolved_at IS NULL");
        }
        $st = Database::pdo()->prepare('UPDATE error_log SET resolved_at = ? WHERE id = ?');
        $st->execute([gmdate('Y-m-d H:i:s'), $id]);
        return $st->rowCount();
    }

    /** Remove entries older than the given number of days — housekeeping. */
    public static function purgeOlderThan(int $days = 90): int
    {
        $cut = (new \DateTimeImmutable('now'))->modify('-' . max(1, $days) . ' days')->format('Y-m-d H:i:s');
        $st = Database::pdo()->prepare('DELETE FROM error_log WHERE created_at < ?');
        $st->execute([$cut]);
        return $st->rowCount();
    }
}
