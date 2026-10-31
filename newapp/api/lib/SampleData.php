<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT140: remove the sample records (Settings → Maintenance, Super Admin).
 *
 * The installer's "Add sample records" box loads sql/sample.sql. This finds those records again from the same file (the
 * file is the only list, so it can never drift from what was loaded) and removes them. A record is a sample record only
 * when its identifying fields still hold the sample values (an onboarding template's name AND description). Everything
 * runs in one transaction. 0001-A005: the samples are two onboarding templates (the reference app's were credentials,
 * contracts, articles, tickets and a calendar entry).
 */
if (!function_exists('itops_sample_catalog')) {

    /** table => [label, the columns that identify a sample record]. */
    function itops_sample_tables(): array {
        return [
            'itops_m365_templates' => ['Onboarding templates', ['name', 'description']],
        ];
    }

    /** One SQL value list "( … )" → values (strings; NULL → null). Quotes with '' and \' escapes. */
    function itops_sample_tuple(string $s): array {
        $out = []; $i = 0; $n = strlen($s);
        if ($n === 0 || $s[0] !== '(') return [];
        $i = 1;
        while ($i < $n) {
            while ($i < $n && ($s[$i] === ' ' || $s[$i] === "\t")) $i++;
            if ($i < $n && $s[$i] === "'") {
                $v = ''; $i++;
                while ($i < $n) {
                    if ($s[$i] === '\\' && $i + 1 < $n) { $v .= $s[$i + 1]; $i += 2; continue; }
                    if ($s[$i] === "'") { if ($i + 1 < $n && $s[$i + 1] === "'") { $v .= "'"; $i += 2; continue; } $i++; break; }
                    $v .= $s[$i]; $i++;
                }
                $out[] = $v;
            } else {
                $j = $i; while ($j < $n && $s[$j] !== ',' && $s[$j] !== ')') $j++;
                $raw = trim(substr($s, $i, $j - $i)); $out[] = strtoupper($raw) === 'NULL' ? null : $raw; $i = $j;
            }
            while ($i < $n && ($s[$i] === ' ' || $s[$i] === "\t")) $i++;
            if ($i < $n && $s[$i] === ',') { $i++; continue; }
            break;   // ')'
        }
        return $out;
    }

    /** The sample records as shipped: table => list of [column => value] (identifying columns only). Null if no file. */
    function itops_sample_catalog(?string $file = null): ?array {
        $file = $file ?? dirname(__DIR__, 2) . '/sql/sample.sql';
        if (!is_file($file)) return null;
        $tables = itops_sample_tables(); $cat = array_fill_keys(array_keys($tables), []);
        $cur = null; $cols = [];
        foreach (preg_split('/\R/', (string)file_get_contents($file)) as $line) {
            if (preg_match('/^INSERT INTO `?(itops_[a-z0-9_]+)`?\s*\(([^)]*)\)\s*VALUES/i', $line, $m)) {
                $cur = isset($tables[$m[1]]) ? $m[1] : null;
                $cols = array_map(fn($c) => trim($c, " `"), explode(',', $m[2]));
                continue;
            }
            if ($cur === null || !str_starts_with(ltrim($line), '(')) continue;
            $vals = itops_sample_tuple(ltrim($line));
            if (count($vals) !== count($cols)) continue;
            $row = array_combine($cols, $vals); $key = [];
            foreach ($tables[$cur][1] as $k) $key[$k] = $row[$k] ?? null;
            $cat[$cur][] = $key;
            if (preg_match('/\);\s*$/', $line)) $cur = null;
        }
        return $cat;
    }

    /** Does a table / column exist (older databases may lack a link table)? */
    function itops_sample_has(PDO $db, string $table, string $col = ''): bool {
        static $c = [];
        $k = $table . '.' . $col;
        if (!array_key_exists($k, $c)) {
            try { $db->query('SELECT ' . ($col !== '' ? '`' . $col . '`' : '1') . ' FROM `' . $table . '` LIMIT 0'); $c[$k] = true; }
            catch (Throwable $e) { $c[$k] = false; }
        }
        return $c[$k];
    }

    /** Why a found sample record must be kept ('' = free to remove). An onboarding template is never referred to by id. */
    function itops_sample_in_use(PDO $db, string $table, int $id): string {
        return '';
    }

    /** What is there now: per table, the sample records found (id, name) and the ones kept with the reason. */
    function itops_sample_scan(PDO $db, ?array $cat = null): array {
        $cat = $cat ?? itops_sample_catalog();
        if ($cat === null) return ['available' => false, 'tables' => [], 'remove' => 0, 'keep' => 0];
        $out = []; $nr = 0; $nk = 0;
        foreach (itops_sample_tables() as $table => [$label, $keys]) {
            $remove = []; $keep = [];
            foreach ($cat[$table] ?? [] as $row) {
                $w = []; $a = [];
                foreach ($row as $k => $v) { if ($v === null) $w[] = '`' . $k . '` IS NULL'; else { $w[] = '`' . $k . '`=?'; $a[] = $v; } }
                $st = $db->prepare('SELECT id FROM `' . $table . '` WHERE ' . implode(' AND ', $w));
                $st->execute($a);
                $name = (string)reset($row);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    $why = itops_sample_in_use($db, $table, (int)$id);
                    if ($why === '') $remove[] = ['id' => (int)$id, 'name' => $name]; else $keep[] = ['id' => (int)$id, 'name' => $name, 'why' => $why];
                }
            }
            $nr += count($remove); $nk += count($keep);
            $out[] = ['table' => $table, 'label' => $label, 'remove' => $remove, 'keep' => $keep];
        }
        return ['available' => true, 'tables' => $out, 'remove' => $nr, 'keep' => $nk];
    }

    /** Removes the free sample records, in one transaction. Returns the scan it acted on plus 'removed' per table. */
    function itops_sample_remove(PDO $db): array {
        $db->beginTransaction();
        try {
            $scan = itops_sample_scan($db);
            $done = [];
            $del = function (string $t, string $col, array $ids) use ($db) {
                if (!$ids || !itops_sample_has($db, $t, $col)) return;
                $db->prepare('DELETE FROM `' . $t . '` WHERE `' . $col . '` IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')->execute($ids);
            };
            foreach ($scan['tables'] as $tb) {
                $ids = array_column($tb['remove'], 'id');
                if (!$ids) { $done[$tb['table']] = 0; continue; }
                $del($tb['table'], 'id', $ids);
                $done[$tb['table']] = count($ids);
            }
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        $scan['removed'] = $done;
        return $scan;
    }
}
