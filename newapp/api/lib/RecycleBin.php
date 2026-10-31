<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT141: Deleted items (the recycle bin).
 *
 * Deleting a record anywhere in the app moves it here instead of erasing it: the record, the rows that belong to it
 * (comments, attachments, versions, lines, tasks…) and the names of its files are kept as one entry in itops_recycle,
 * then removed from their tables, so every screen, search and report behaves as if it were gone. The files stay on disk.
 * Administration → Deleted items (Super Admin): put an entry back exactly as it was (same number, same links), or delete
 * it for good (its files leave the disk then). Entries older than the kept days (Settings value bin_keep_days, default
 * 30; 0 = until emptied) are deleted for good by the scheduler once a day.
 *
 * A record that other records still depend on is not deleted at all (the answer says what uses it), so putting things
 * back never has to guess. Links that a delete clears on other records (tickets of a ticket category) are written down
 * and set again on put back where they are still empty.
 *
 * What the app owns and adds here: the list of kinds below (itops_bin_kinds). The engine itself names no table.
 */

if (!function_exists('itops_bin_kinds')) {

    class ItopsBinRefused extends RuntimeException {}

    /**
     * kind => [table, label (singular), module (for the screens), name(row) → shown name,
     *          children: [[table, column, extra WHERE (optional)]], files: [table => file column] (relative to UPLOAD_DIR),
     *          used: [[table, column, label (one), extra WHERE, label (several; default label + s)]] (refuse the delete while any row points to it),
     *          relink: [[table, column]] (rows whose link the delete clears; set again on put back),
     *          legacy_status: status to give a record deleted before BT141 (it was only marked "archived")]
     */
    function itops_bin_kinds(): array {
        $nm = fn(...$k) => function (array $r) use ($k) { foreach ($k as $c) if (isset($r[$c]) && trim((string)$r[$c]) !== '') return (string)$r[$c]; return '#' . ($r['id'] ?? ''); };
        return [   // 0001-A005: the kinds of records this product has (the reference app had tickets, KB, SOPs, contracts…)
            'role' => ['itops_roles', 'Role', 'roles', $nm('name'), 'used' => [['itops_users', 'role_id', 'person', null, 'people']]],
            'ob_template' => ['itops_ob_templates', 'Onboarding checklist template', 'onboarding', $nm('name'), 'children' => [['itops_ob_template_tasks', 'template_id']]],
            'm365_template' => ['itops_m365_templates', 'Onboarding template (Microsoft 365)', 'onboarding', $nm('name')],
        ];
    }
    /** The permission module whose people may see / put back / delete for good an entry ('' = Super Admin only). */
    function itops_bin_module_of(string $kind, array $row): string {
        if ($kind === 'role') return '';   // roles are changed by a Super Admin only
        return (string)(itops_bin_kinds()[$kind][2] ?? '');
    }
    /**
     * BT141: who may do what with one entry. A Super Admin: everything. Others need the role permission "Deleted items"
     * (View to see, Edit to put back, Delete to delete for good) AND the same action on the part of the app the record
     * belongs to (Tickets, Credentials…). An entry of a part they cannot see is not shown to them at all.
     */
    function itops_bin_rights(?array $user, string $module): array {
        $sa = ($user['role_slug'] ?? '') === 'super_admin';
        if ($sa) return ['view' => true, 'restore' => true, 'purge' => true];
        $ok = fn($a) => $module !== '' && function_exists('itops_can') && itops_can($user, 'recycle', $a) && itops_can($user, $module, $a === 'view' ? 'view' : $a);
        return ['view' => $ok('view'), 'restore' => $ok('edit'), 'purge' => $ok('delete')];
    }
    function itops_bin_kind_by_table(string $table): ?string { foreach (itops_bin_kinds() as $k => $s) if ($s[0] === $table) return $k; return null; }

    function itops_bin_table(PDO $db): void {
        static $done = false; if ($done) return;
        $db->exec("CREATE TABLE IF NOT EXISTS itops_recycle (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(40) NOT NULL, record_id INT UNSIGNED NOT NULL, name VARCHAR(255) NOT NULL DEFAULT '',
            data LONGTEXT NOT NULL, files TEXT NULL, rows_kept INT UNSIGNED NOT NULL DEFAULT 1, bytes INT UNSIGNED NOT NULL DEFAULT 0,
            legacy TINYINT(1) NOT NULL DEFAULT 0, module VARCHAR(40) NOT NULL DEFAULT '',
            deleted_by INT UNSIGNED NULL, deleted_by_name VARCHAR(150) NOT NULL DEFAULT '', deleted_at DATETIME NOT NULL,
            KEY idx_recycle_kind (kind, record_id), KEY idx_recycle_at (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    }

    function itops_bin_has(PDO $db, string $table, string $col = ''): bool {
        static $c = [];
        $k = $table . '.' . $col;
        if (!array_key_exists($k, $c)) {
            if (!preg_match('/^itops_[a-z0-9_]+$/', $table) || ($col !== '' && !preg_match('/^[a-z0-9_]+$/', $col))) return $c[$k] = false;
            try { $db->query('SELECT ' . ($col !== '' ? '`' . $col . '`' : '1') . ' FROM `' . $table . '` LIMIT 0'); $c[$k] = true; } catch (Throwable $e) { $c[$k] = false; }
        }
        return $c[$k];
    }
    /** Current columns of a table: name => [nullable, has default, auto]. */
    function itops_bin_columns(PDO $db, string $table): array {
        static $c = [];
        if (!isset($c[$table])) {
            $c[$table] = [];
            foreach ($db->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) as $r)
                $c[$table][$r['Field']] = ['null' => $r['Null'] === 'YES', 'auto' => stripos((string)$r['Extra'], 'auto_increment') !== false];
        }
        return $c[$table];
    }
    /** Foreign keys of a table: column => [ref table, ref column]. */
    function itops_bin_fks(PDO $db, string $table): array {
        static $c = [];
        if (!isset($c[$table])) {
            $q = $db->prepare('SELECT column_name, referenced_table_name, referenced_column_name FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name=? AND referenced_table_name IS NOT NULL');
            $q->execute([$table]); $c[$table] = [];
            foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) $c[$table][$r[0]] = [$r[1], $r[2]];
        }
        return $c[$table];
    }
    function itops_bin_where(array $child): string { return '`' . $child[1] . '`=?' . (!empty($child[2]) ? ' AND ' . $child[2] : ''); }

    /** What still uses a record: list of "N label". */
    function itops_bin_users_of(PDO $db, string $kind, int $id): array {
        $s = itops_bin_kinds()[$kind] ?? null; if (!$s) return [];
        $out = [];
        foreach ($s['used'] ?? [] as $u) {
            if (!itops_bin_has($db, $u[0], $u[1])) continue;
            $q = $db->prepare('SELECT COUNT(*) FROM `' . $u[0] . '` WHERE `' . $u[1] . '`=?' . (!empty($u[3]) ? ' AND ' . $u[3] : '')); $q->execute([$id]);
            $n = (int)$q->fetchColumn(); if ($n) $out[] = $n . ' ' . ($n === 1 ? $u[2] : ($u[4] ?? $u[2] . 's'));
        }
        return $out;
    }

    /**
     * Moves one record to Deleted items: snapshot, then removal of the record and its rows, in one transaction.
     * Returns the entry id, or null when the record does not exist. Throws ItopsBinRefused when something still uses it.
     */
    function itops_bin_put(PDO $db, string $kind, int $id, ?array $user = null, bool $legacy = false): ?int {
        $s = itops_bin_kinds()[$kind] ?? null;
        if (!$s || !itops_bin_has($db, $s[0])) throw new InvalidArgumentException('Unknown kind of record');
        itops_bin_table($db);
        $q = $db->prepare('SELECT * FROM `' . $s[0] . '` WHERE id=?'); $q->execute([$id]); $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $uses = itops_bin_users_of($db, $kind, $id);
        if ($uses) throw new ItopsBinRefused('It is still used by ' . implode(', ', $uses) . '. Change or remove those first, or set it inactive instead.');
        $own = $db->inTransaction(); if (!$own) $db->beginTransaction();
        try {
            $kids = []; $files = []; $n = 1;
            foreach ($s['children'] ?? [] as $c) {
                if (!itops_bin_has($db, $c[0], $c[1])) continue;
                $cq = $db->prepare('SELECT * FROM `' . $c[0] . '` WHERE ' . itops_bin_where($c)); $cq->execute([$id]);
                $rows = $cq->fetchAll(PDO::FETCH_ASSOC);
                if ($rows) { $kids[] = ['table' => $c[0], 'col' => $c[1], 'where' => $c[2] ?? '', 'rows' => $rows]; $n += count($rows); }
            }
            $relink = [];
            foreach ($s['relink'] ?? [] as $r) {
                if (!itops_bin_has($db, $r[0], $r[1])) continue;
                $rq = $db->prepare('SELECT id FROM `' . $r[0] . '` WHERE `' . $r[1] . '`=?'); $rq->execute([$id]);
                $ids = array_map('intval', $rq->fetchAll(PDO::FETCH_COLUMN)); if ($ids) $relink[] = ['table' => $r[0], 'col' => $r[1], 'ids' => $ids];
            }
            foreach ($s['files'] ?? [] as $ft => $fc) {
                if ($ft === $s[0] && !empty($row[$fc])) $files[] = (string)$row[$fc];
                foreach ($kids as $k) if ($k['table'] === $ft) foreach ($k['rows'] as $kr) if (!empty($kr[$fc])) $files[] = (string)$kr[$fc];
            }
            $data = json_encode(['v' => 1, 'table' => $s[0], 'row' => $row, 'children' => $kids, 'relink' => $relink], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($data === false) throw new RuntimeException('the record could not be kept');
            $name = mb_substr((string)$s[3]($row), 0, 255);
            $db->prepare('INSERT INTO itops_recycle (kind, record_id, name, data, files, rows_kept, bytes, legacy, module, deleted_by, deleted_by_name, deleted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())')
               ->execute([$kind, $id, $name, $data, json_encode(array_values(array_unique($files))), $n, strlen($data), $legacy ? 1 : 0, itops_bin_module_of($kind, $row),
                          !empty($user['id']) ? (int)$user['id'] : null, mb_substr((string)($user['display_name'] ?? ($user ? '' : 'System')), 0, 150)]);
            $eid = (int)$db->lastInsertId();
            foreach (array_reverse($kids) as $k) $db->prepare('DELETE FROM `' . $k['table'] . '` WHERE ' . itops_bin_where([$k['table'], $k['col'], $k['where']]))->execute([$id]);
            foreach ($relink as $r) $db->prepare('UPDATE `' . $r['table'] . '` SET `' . $r['col'] . '`=NULL WHERE `' . $r['col'] . '`=?')->execute([$id]);
            $db->prepare('DELETE FROM `' . $s[0] . '` WHERE id=?')->execute([$id]);
            if (!$own) $db->commit();
            return $eid;
        } catch (Throwable $e) { if (!$own && $db->inTransaction()) $db->rollBack(); throw $e; }
    }

    /** Inserts one kept row into its table as it was (columns that no longer exist are left out). Returns notes. */
    function itops_bin_insert(PDO $db, string $table, array $row, array &$notes): void {
        $cols = itops_bin_columns($db, $table); $fks = itops_bin_fks($db, $table);
        $row = array_intersect_key($row, $cols);
        foreach ($fks as $col => [$rt, $rc]) {
            if (!array_key_exists($col, $row) || $row[$col] === null || !preg_match('/^itops_[a-z0-9_]+$/', $rt)) continue;
            $x = $db->prepare('SELECT COUNT(*) FROM `' . $rt . '` WHERE `' . $rc . '`=?'); $x->execute([$row[$col]]);
            if ((int)$x->fetchColumn()) continue;
            if (!empty($cols[$col]['null'])) { $row[$col] = null; $notes[$table . '.' . $col] = str_replace('_', ' ', preg_replace('/_id$/', '', $col)) . ' no longer exists, so it was left empty'; }
            else throw new ItopsBinRefused('It points to a ' . str_replace('_', ' ', preg_replace('/_id$/', '', $col)) . ' that no longer exists. Put that back first.');
        }
        $k = array_keys($row);
        $db->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', $k) . '`) VALUES (' . implode(',', array_fill(0, count($k), '?')) . ')')->execute(array_values($row));
    }

    function itops_bin_get(PDO $db, int $eid): ?array {
        itops_bin_table($db);
        $q = $db->prepare('SELECT * FROM itops_recycle WHERE id=?'); $q->execute([$eid]); $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Puts an entry back. Returns ['kind', 'record_id', 'name', 'notes' => [...]]. Throws ItopsBinRefused with a sentence. */
    function itops_bin_restore(PDO $db, int $eid): array {
        $e = itops_bin_get($db, $eid); if (!$e) throw new ItopsBinRefused('That entry is not in Deleted items any more.');
        $s = itops_bin_kinds()[$e['kind']] ?? null; $d = json_decode((string)$e['data'], true);
        if (!$s || !is_array($d) || empty($d['row']['id'])) throw new ItopsBinRefused('That entry cannot be read, so it cannot be put back.');
        if (!itops_bin_has($db, $d['table'])) throw new ItopsBinRefused('This part of the app is not in this installation any more.');
        $id = (int)$d['row']['id']; $notes = [];
        $x = $db->prepare('SELECT COUNT(*) FROM `' . $d['table'] . '` WHERE id=?'); $x->execute([$id]);
        if ((int)$x->fetchColumn()) throw new ItopsBinRefused('A record with the same number exists again, so this one cannot take its place.');
        $row = $d['row'];
        if ((int)$e['legacy'] === 1 && ($row['status'] ?? '') === 'archived' && !empty($s['legacy_status'])) { $row['status'] = $s['legacy_status']; $notes['status'] = 'deleted before Deleted items existed: it comes back as "' . $s['legacy_status'] . '"'; }
        $db->beginTransaction();
        try {
            itops_bin_insert($db, $d['table'], $row, $notes);
            foreach ($d['children'] ?? [] as $k) {
                if (!itops_bin_has($db, $k['table'])) { $notes[$k['table']] = count($k['rows']) . ' related row(s) could not come back (that part of the app is gone)'; continue; }
                foreach ($k['rows'] as $kr) {
                    if (isset($kr['id'])) { $y = $db->prepare('SELECT COUNT(*) FROM `' . $k['table'] . '` WHERE id=?'); $y->execute([$kr['id']]); if ((int)$y->fetchColumn()) continue; }
                    itops_bin_insert($db, $k['table'], $kr, $notes);
                }
            }
            foreach ($d['relink'] ?? [] as $r) {
                if (!$r['ids'] || !itops_bin_has($db, $r['table'], $r['col'])) continue;
                $ids = array_map('intval', $r['ids']);
                $u = $db->prepare('UPDATE `' . $r['table'] . '` SET `' . $r['col'] . '`=? WHERE `' . $r['col'] . '` IS NULL AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
                $u->execute(array_merge([$id], $ids));
                if ($u->rowCount() < count($ids)) $notes['relink'] = ($u->rowCount()) . ' of ' . count($ids) . ' linked records were linked again (the others were changed meanwhile)';
            }
            $db->prepare('DELETE FROM itops_recycle WHERE id=?')->execute([$eid]);
            $db->commit();
        } catch (PDOException $ex) {
            if ($db->inTransaction()) $db->rollBack();
            if ((string)$ex->getCode() === '23000') {
                $what = preg_match("/Duplicate entry '([^']{0,80})/", $ex->getMessage(), $m) ? ' ("' . $m[1] . '")' : '';
                throw new ItopsBinRefused('Another record now uses the same name or number' . $what . '. Rename or remove that one first.');
            }
            error_log('ITOps bin restore: ' . $ex->getMessage());
            throw new ItopsBinRefused('The database refused to put it back. Nothing changed. Details are in the server log.');
        } catch (Throwable $ex) { if ($db->inTransaction()) $db->rollBack(); throw $ex; }
        return ['kind' => $e['kind'], 'record_id' => $id, 'name' => (string)$e['name'], 'notes' => array_values($notes), 'module' => $s[2]];
    }

    /** Deletes entries for good, with their files. Returns [entries, files removed]. */
    function itops_bin_purge(PDO $db, array $eids): array {
        itops_bin_table($db); $n = 0; $f = 0;
        $base = realpath(UPLOAD_DIR);
        foreach ($eids as $eid) {
            $e = itops_bin_get($db, (int)$eid); if (!$e) continue;
            $db->prepare('DELETE FROM itops_recycle WHERE id=?')->execute([(int)$eid]); $n++;
            foreach ((array)json_decode((string)$e['files'], true) as $p) {
                $p = (string)$p; if ($p === '' || strpos($p, '..') !== false) continue;
                // a file is removed only when no live record and no other entry still refers to it
                $still = false;
                foreach (itops_bin_kinds() as $s) foreach ($s['files'] ?? [] as $ft => $fc) {
                    if ($still || !itops_bin_has($db, $ft, $fc)) continue;
                    $x = $db->prepare('SELECT COUNT(*) FROM `' . $ft . '` WHERE `' . $fc . '`=?'); $x->execute([$p]); if ((int)$x->fetchColumn()) $still = true;
                }
                if (!$still) { $x = $db->prepare('SELECT COUNT(*) FROM itops_recycle WHERE files LIKE ?'); $x->execute(['%' . json_encode($p) . '%']); if ((int)$x->fetchColumn()) $still = true; }
                if ($still) continue;
                $real = realpath(UPLOAD_DIR . $p);
                if ($real && $base && strpos($real, $base . DIRECTORY_SEPARATOR) === 0 && is_file($real) && @unlink($real)) $f++;
            }
        }
        return [$n, $f];
    }

    function itops_bin_keep_days(PDO $db): int {
        try { $v = $db->query("SELECT sval FROM itops_settings WHERE skey='bin_keep_days'")->fetchColumn(); } catch (Throwable $e) { $v = false; }
        return $v === false || $v === null || $v === '' ? 30 : max(0, min(3650, (int)$v));
    }
    /** The scheduler's daily step: entries older than the kept days leave for good. Returns how many. */
    function itops_bin_auto_purge(PDO $db): int {
        $days = itops_bin_keep_days($db); if ($days === 0) return 0;
        itops_bin_table($db);
        $q = $db->prepare('SELECT id FROM itops_recycle WHERE deleted_at < (NOW() - INTERVAL ? DAY) LIMIT 500'); $q->execute([$days]);
        $ids = $q->fetchAll(PDO::FETCH_COLUMN); if (!$ids) return 0;
        [$n, $f] = itops_bin_purge($db, $ids);
        if ($n && function_exists('audit_log')) audit_log('bin_auto_purged', 'system', 0, 'Deleted items older than ' . $days . ' days: ' . $n . ' removed for good', null, ['Entries' => $n, 'Files removed' => $f]);
        return $n;
    }

    /** The list for the screen (no kept data in it). */
    function itops_bin_list(PDO $db, ?array $user = null): array {
        itops_bin_table($db); $K = itops_bin_kinds(); $days = itops_bin_keep_days($db);
        $out = []; $hidden = 0;
        foreach ($db->query('SELECT id, kind, record_id, name, rows_kept, bytes, legacy, module, deleted_by, deleted_by_name, deleted_at, files FROM itops_recycle ORDER BY deleted_at DESC, id DESC LIMIT 5000')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rt = itops_bin_rights($user, (string)$r['module']);
            if (!$rt['view']) { $hidden++; continue; }
            $out[] = ['id' => (int)$r['id'], 'can_restore' => $rt['restore'], 'can_purge' => $rt['purge'], 'kind' => $r['kind'], 'kind_label' => $K[$r['kind']][1] ?? $r['kind'], 'record_id' => (int)$r['record_id'], 'name' => $r['name'],
                      'related' => max(0, (int)$r['rows_kept'] - 1), 'files' => count((array)json_decode((string)$r['files'], true)), 'bytes' => (int)$r['bytes'], 'legacy' => (int)$r['legacy'] === 1,
                      'deleted_by' => $r['deleted_by_name'] !== '' ? $r['deleted_by_name'] : 'System', 'deleted_at' => $r['deleted_at'],
                      'purge_on' => $days ? date('Y-m-d', strtotime($r['deleted_at'] . ' +' . $days . ' days')) : null];
        }
        return ['items' => $out, 'keep_days' => $days, 'kinds' => array_map(fn($s) => $s[1], $K), 'super' => ($user['role_slug'] ?? '') === 'super_admin'];
    }

}
