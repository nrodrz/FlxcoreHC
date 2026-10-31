<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — database updates.
 * 0001-A005: sql/schema.sql is the baseline (every table in its final shape); the reference app's 121 updates are gone.
 * A later change of the database is one new entry here, named <date>_<n>_<build>_<what> (for example
 * '2026_11_20_01_0001-A007_ob_runs_add_note'), that works the same on a new installation and an old one. Idempotent,
 * run in name order, tracked in itops_migrations; safe to run any number of times (installer, db-update.php,
 * Settings → Maintenance → Database update, and an installed update).
 */

function itops_migrations_list(): array {
    return [
        // key => function (PDO $db): string (what it did, in words)
    ];
}

function itops_migrations_table(PDO $db): void {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS itops_migrations (
            version VARCHAR(190) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function itops_applied_versions(PDO $db): array {
    itops_migrations_table($db);
    return $db->query("SELECT version FROM itops_migrations")->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function itops_pending_versions(PDO $db): array {
    $applied = itops_applied_versions($db);
    return array_values(array_diff(array_keys(itops_migrations_list()), $applied));
}

/** Run all pending migrations; returns a per-migration report. Stops at the first error. */
function itops_run_migrations(PDO $db): array {
    // Review OPS-26: one runner at a time (two people pressing "Database update", or the update and a scheduled run)
    $got = false; try { $got = (int)$db->query("SELECT GET_LOCK('itops_migrations', 30)")->fetchColumn() === 1; } catch (Throwable $e) { $got = true; }
    if (!$got) return [['version' => '-', 'status' => 'error', 'message' => 'Another database update is running. Try again in a minute.']];
    try { return itops_run_migrations_locked($db); } finally { try { $db->query("SELECT RELEASE_LOCK('itops_migrations')"); } catch (Throwable $e) {} }
}
function itops_run_migrations_locked(PDO $db): array {
    itops_migrations_table($db);
    $applied = itops_applied_versions($db);
    $mark = $db->prepare("INSERT IGNORE INTO itops_migrations (version) VALUES (?)");
    $report = [];
    // Always run in chronological order (the list itself is not strictly ordered), so a fresh
    // database can be built entirely from migrations without one step needing a later table.
    $list = itops_migrations_list(); ksort($list, SORT_STRING);
    foreach ($list as $version => $fn) {
        if (in_array($version, $applied, true)) {
            $report[] = ['version' => $version, 'status' => 'skipped', 'message' => 'Already applied.'];
            continue;
        }
        try {
            $message = $fn($db);
            $mark->execute([$version]);
            $report[] = ['version' => $version, 'status' => 'applied', 'message' => $message];
        } catch (Throwable $e) {
            $report[] = ['version' => $version, 'status' => 'error', 'message' => $e->getMessage()];
            break;
        }
    }
    return $report;
}

