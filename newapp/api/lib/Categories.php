<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT138: one table for the simple category lists and one for the access logs.
 *
 *   itops_categories  (kind = kb | doc | sop | cred) replaces itops_kb_categories, itops_doc_categories,
 *                    itops_sop_categories and itops_cred_categories. Ticket categories stay in their own table
 *                    (they carry service levels, routing and ticket types).
 *   itops_access_log  (kind = cred | doc) replaces itops_cred_access_log and itops_doc_access_log.
 *   itops_notification_prefs (never used by any code) is removed.
 *
 * The database update (itops_bt138_merge) moves the rows. It runs on every installation, new or old: the first
 * installation steps (schema.sql, seed.sql) still create the old lists, and older update steps still write to them,
 * so the move is always the same path. It can be run again after an interruption: each list's move and the change of
 * its records' category numbers are recorded together, in one transaction, before the old table is removed.
 */
if (!function_exists('itops_cat_kinds')) {
    /** kind => [old table, the table whose rows point at it, label] */
    function itops_cat_kinds(): array {
        return [
            'kb'   => ['itops_kb_categories',   'itops_kb_articles', 'Knowledge Base'],
            'doc'  => ['itops_doc_categories',  'itops_documents',   'Documents'],
            'sop'  => ['itops_sop_categories',  'itops_sops',        'SOPs'],
            'cred' => ['itops_cred_categories', 'itops_credentials', 'Credentials'],
        ];
    }



    function itops_bt138_table(PDO $db, string $t): bool {
        $q = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'); $q->execute([$t]);
        return (int)$q->fetchColumn() > 0;
    }
    function itops_bt138_col(PDO $db, string $t, string $c): bool {
        $q = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'); $q->execute([$t, $c]);
        return (int)$q->fetchColumn() > 0;
    }
    /** The foreign keys of $child.category_id that point at $parent (their names differ between installations). */
    function itops_bt138_fks(PDO $db, string $child, string $parent): array {
        $q = $db->prepare('SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME = ?');
        $q->execute([$child, 'category_id', $parent]);
        return array_values(array_unique($q->fetchAll(PDO::FETCH_COLUMN)));
    }
    function itops_bt138_done(PDO $db, string $key): bool {
        $q = $db->prepare('SELECT COUNT(*) FROM itops_settings WHERE skey = ?'); $q->execute(['bt138_' . $key]);
        return (int)$q->fetchColumn() > 0;
    }

    /** The database update. Returns what it did, in words. */
    function itops_bt138_merge(PDO $db): string {
        $said = [];
        $db->exec("CREATE TABLE IF NOT EXISTS itops_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            kind ENUM('kb','doc','sop','cred') NOT NULL,
            name VARCHAR(150) NOT NULL,
            slug VARCHAR(150) NOT NULL DEFAULT '',
            parent_id INT UNSIGNED DEFAULT NULL,
            department_id INT UNSIGNED DEFAULT NULL,
            icon VARCHAR(50) DEFAULT '',
            color VARCHAR(7) DEFAULT '',
            retention_days INT DEFAULT NULL,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            old_id INT UNSIGNED DEFAULT NULL,
            old_parent INT UNSIGNED DEFAULT NULL,
            KEY idx_cat_kind (kind, sort_order),
            KEY idx_cat_slug (kind, slug),
            UNIQUE KEY uq_cat_old (kind, old_id),
            CONSTRAINT fk_categories_department FOREIGN KEY (department_id) REFERENCES itops_departments(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS itops_access_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            kind ENUM('cred','doc') NOT NULL,
            item_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            action VARCHAR(20) NOT NULL,
            ip_address VARCHAR(45) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_access_item (kind, item_id, created_at),
            KEY idx_access_when (created_at),
            CONSTRAINT fk_access_log_user FOREIGN KEY (user_id) REFERENCES itops_users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // ── the four category lists
        $hasOld = itops_bt138_col($db, 'itops_categories', 'old_id');
        foreach (itops_cat_kinds() as $kind => [$old, $child, $label]) {
            if (!itops_bt138_table($db, $old)) continue;
            if (!$hasOld) throw new RuntimeException('itops_categories has no old_id column while ' . $old . ' still exists — restore the backup and run the update again.');
            $c = fn($n) => itops_bt138_col($db, $old, $n);
            $sel = 'SELECT ?, o.name, ' . ($c('slug') ? 'o.slug' : "''") . ', ' . ($c('department_id') ? 'o.department_id' : 'NULL') . ', ' . ($c('icon') ? "COALESCE(o.icon,'')" : "''") . ', '
                 . ($c('color') ? "COALESCE(o.color,'')" : "''") . ', ' . ($c('retention_days') ? 'o.retention_days' : 'NULL') . ', COALESCE(o.sort_order,0), '
                 . ($c('created_at') ? 'COALESCE(o.created_at, NOW())' : 'NOW()') . ', o.id, ' . ($c('parent_id') ? 'o.parent_id' : 'NULL');
            // 1. copy the rows not copied yet (an earlier, interrupted run may have copied some)
            $q = $db->prepare("INSERT INTO itops_categories (kind, name, slug, department_id, icon, color, retention_days, sort_order, created_at, old_id, old_parent) $sel
                               FROM `$old` o WHERE NOT EXISTS (SELECT 1 FROM itops_categories n WHERE n.kind = ? AND n.old_id = o.id)");
            $q->execute([$kind, $kind]); $copied = $q->rowCount();
            // 2. the records stop pointing at the old table
            if (itops_bt138_table($db, $child)) foreach (itops_bt138_fks($db, $child, $old) as $fk) $db->exec("ALTER TABLE `$child` DROP FOREIGN KEY `$fk`");
            // 3. new numbers for the records' categories and the parents — once, recorded in the same transaction
            if (!itops_bt138_done($db, $kind)) {
                $db->beginTransaction();
                try {
                    $moved = 0;
                    if (itops_bt138_table($db, $child) && itops_bt138_col($db, $child, 'category_id')) {
                        $u = $db->prepare("UPDATE `$child` r LEFT JOIN itops_categories n ON n.kind = ? AND n.old_id = r.category_id SET r.category_id = n.id WHERE r.category_id IS NOT NULL");
                        $u->execute([$kind]); $moved = $u->rowCount();
                    }
                    $db->prepare('UPDATE itops_categories c LEFT JOIN itops_categories p ON p.kind = c.kind AND p.old_id = c.old_parent SET c.parent_id = p.id WHERE c.kind = ? AND c.old_parent IS NOT NULL')->execute([$kind]);
                    $db->prepare('INSERT INTO itops_settings (skey, sval) VALUES (?, ?)')->execute(['bt138_' . $kind, date('Y-m-d H:i:s')]);
                    $db->commit();
                } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
                $said[] = $label . ': ' . $copied . ' categor' . ($copied === 1 ? 'y' : 'ies') . ', ' . $moved . ' record' . ($moved === 1 ? '' : 's') . ' renumbered';
            }
            // 4. the records point at the new table; the old list goes
            if (itops_bt138_table($db, $child) && !itops_bt138_fks($db, $child, 'itops_categories'))
                $db->exec("ALTER TABLE `$child` ADD CONSTRAINT `fk_{$kind}_category` FOREIGN KEY (category_id) REFERENCES itops_categories(id) ON DELETE SET NULL");
            $db->exec("DROP TABLE `$old`");
        }
        // the numbers of the old lists are no longer needed once every list has moved
        $left = array_filter(itops_cat_kinds(), fn($k) => itops_bt138_table($db, $k[0]));
        if (!$left && $hasOld) {
            $db->exec('ALTER TABLE itops_categories DROP INDEX uq_cat_old, DROP COLUMN old_id, DROP COLUMN old_parent');
            $db->exec("DELETE FROM itops_settings WHERE skey IN ('bt138_kb','bt138_doc','bt138_sop','bt138_cred')");
        }

        // ── the two access logs (rows kept: they are history; nothing else points at them)
        foreach (['cred' => ['itops_cred_access_log', 'credential_id'], 'doc' => ['itops_doc_access_log', 'document_id']] as $kind => [$old, $col]) {
            if (!itops_bt138_table($db, $old)) continue;
            if (!itops_bt138_done($db, 'log_' . $kind)) {
                $db->beginTransaction();
                try {
                    $q = $db->prepare("INSERT INTO itops_access_log (kind, item_id, user_id, action, ip_address, created_at)
                                       SELECT ?, o.`$col`, o.user_id, o.action, COALESCE(o.ip_address,''), o.created_at FROM `$old` o ORDER BY o.id");
                    $q->execute([$kind]); $n = $q->rowCount();
                    $db->prepare('INSERT INTO itops_settings (skey, sval) VALUES (?, ?)')->execute(['bt138_log_' . $kind, date('Y-m-d H:i:s')]);
                    $db->commit();
                } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
                $said[] = ($kind === 'cred' ? 'Credential' : 'Document') . ' access log: ' . $n . ' line' . ($n === 1 ? '' : 's');
            }
            $db->exec("DROP TABLE `$old`");
            $db->prepare('DELETE FROM itops_settings WHERE skey = ?')->execute(['bt138_log_' . $kind]);
        }

        // ── never used by any code
        if (itops_bt138_table($db, 'itops_notification_prefs')) { $db->exec('DROP TABLE itops_notification_prefs'); $said[] = 'unused table itops_notification_prefs removed'; }

        // Recorded on an installation that already has a history; during a new installation (before "system installed")
        // there is nothing of the organization's to account for, and its history starts with the installation.
        $hasHistory = false; try { $hasHistory = (int)$db->query("SELECT COUNT(*) FROM itops_audit_log WHERE action = 'system_installed'")->fetchColumn() > 0; } catch (Throwable $e) {}
        if ($said && $hasHistory && function_exists('audit_log')) { try { audit_log('database_merge', 'database', null, 'Tables merged (database update BT138)', null, ['Done' => implode('; ', $said)]); } catch (Throwable $e) {} }
        return $said ? 'Tables merged: ' . implode('; ', $said) . '.' : 'Tables already merged.';
    }
}
