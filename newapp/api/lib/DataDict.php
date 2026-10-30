<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * BT137 — the data dictionary and the data grid (Administration → Data, Super Admin).
 *
 * BT138: this file is the ENGINE and is the same in every app of the owner (white-label standard, "Data dictionary").
 * What differs per app lives in DataDictCatalog.php: the table prefix, what each table is for, which reference
 * lists may be changed, the families of similar tables, and the app's own secret columns. Never put an app's table
 * names in this file.
 *
 * The dictionary is read from the live database (information_schema), so it always matches what is installed; this file
 * adds what the database cannot say: what each table is for, its area and kind, and which tables may be changed here.
 *
 *   kind  ref     a reference list other records choose from (categories, departments, vendors) — the only ones editable
 *         main    the records people work with (tickets, cases, documents)
 *         detail  rows that belong to a main record (comments, tasks, lines)
 *         link    who/what is connected to what
 *         log     history and runs (read only; the Audit Log is never changed)
 *         system  settings, migrations, sessions, caches (read only)
 *
 * Editing is limited to chosen columns of reference tables, checked against the column's type, and every change is in
 * the Audit Log with the value before and after. Secrets (passwords, tokens, keys, hashes, wrapped values) are never
 * shown, in any table, and never exported.
 */
require_once __DIR__ . '/DataDictCatalog.php';   // this app's own tables (the only part that differs between apps)
if (!function_exists('opsh_dd_tables')) {

    /** Is this column a secret that is never shown (any table)? */
    function opsh_dd_secret_col(string $table, string $col): bool {
        $c = strtolower($col);
        if (preg_match('/(^|_)(password|passwd|pass|secret|token|hash|salt|otp|mfa_secret|backup_codes|private_key|api_key|key_enc|enc|encrypted|cipher|signing_key|refresh)(_|$)/', $c)) return true;
        if (preg_match('/^(token_hash|sign_token|password_hash|secret_enc|value_enc|data_enc|key_hash)$/', $c)) return true;
        if (function_exists('opsh_dd_app_secret')) { $a = opsh_dd_app_secret($table, $c); if ($a !== null) return $a; }   // the app's own rules (DataDictCatalog.php)
        return false;
    }
    /** A settings value that must not be shown (keys, packages, wrapped values). */
    function opsh_dd_secret_setting(string $skey, $val): bool {
        if (preg_match('/(key|token|secret|pass|pkg|vault|cred|cert|private|webhook|smtp_auth|api)/i', $skey)) return true;
        $v = (string)$val;
        if (function_exists('opsh_secret_is_wrapped') && opsh_secret_is_wrapped($v)) return true;
        return (bool)preg_match('/^(v1:|enc:|wrap:)/', $v);
    }

    /** The tables of this installation (opsh_ only), with their facts. */
    function opsh_dd_tables(PDO $db): array {
        $q = $db->prepare("SELECT table_name AS t, table_rows AS r, data_length + index_length AS s, update_time AS u, create_time AS c FROM information_schema.tables
                         WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND table_name LIKE ? ORDER BY table_name");
        $q->execute([str_replace('_', '\\_', opsh_dd_app()['prefix']) . '%']);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $t = (string)$r['t']; $out[$t] = ['rows_est' => (int)$r['r'], 'size' => (int)$r['s'], 'updated' => $r['u'], 'created' => $r['c']]; }
        return $out;
    }
    function opsh_dd_table_ok(PDO $db, string $t): bool { return preg_match('/^' . preg_quote(opsh_dd_app()['prefix'], '/') . '[a-z0-9_]{1,60}$/', $t) && array_key_exists($t, opsh_dd_tables($db)); }

    /** The columns of a table: name, type, nullable, default, key, extra, enum values, links, secret, editable, description. */
    function opsh_dd_columns(PDO $db, string $t): array {
        $q = $db->prepare("SELECT column_name AS n, column_type AS ty, data_type AS dt, is_nullable AS nl, column_default AS df, column_key AS k, extra AS x, character_maximum_length AS len, column_comment AS cm
                           FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position");
        $q->execute([$t]);
        $fk = [];
        try { $f = $db->prepare("SELECT column_name, referenced_table_name, referenced_column_name FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND table_name = ? AND referenced_table_name IS NOT NULL"); $f->execute([$t]);
              foreach ($f->fetchAll(PDO::FETCH_NUM) as [$c, $rt, $rc]) $fk[$c] = $rt . '.' . $rc; } catch (Throwable $e) {}
        $ed = opsh_dd_editable()[$t] ?? []; $all = opsh_dd_tables($db); $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $n = (string)$c['n']; $enum = [];
            if (preg_match('/^enum\((.*)\)$/i', (string)$c['ty'], $m)) { preg_match_all("/'((?:[^']|'')*)'/", $m[1], $mm); $enum = array_map(fn($x) => str_replace("''", "'", $x), $mm[1]); }
            $link = $fk[$n] ?? opsh_dd_guess_link($n, $all);
            $out[] = ['name' => $n, 'type' => (string)$c['ty'], 'data_type' => strtolower((string)$c['dt']), 'nullable' => $c['nl'] === 'YES', 'default' => $c['df'], 'key' => (string)$c['k'], 'extra' => (string)$c['x'],
                      'max' => $c['len'] !== null ? (int)$c['len'] : null, 'enum' => $enum, 'link' => $link, 'link_declared' => isset($fk[$n]),
                      'secret' => opsh_dd_secret_col($t, $n) && !preg_match('/_at$/', $n) && in_array(strtolower((string)$c['dt']), ['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'json'], true), 'editable' => in_array($n, $ed, true), 'description' => trim((string)$c['cm']) !== '' ? (string)$c['cm'] : opsh_dd_describe_col($n, (string)$c['dt'], $link)];
        }
        return $out;
    }
    /** "department_id" → opsh_departments.id, when such a table exists (the database declares no foreign keys). */
    function opsh_dd_guess_link(string $col, array $tables): ?string {
        if (!preg_match('/^(.+)_id$/', $col, $m)) return null;
        $base = $m[1];
        $map = ['owner' => 'users', 'user' => 'users', 'created_by' => 'users', 'assignee' => 'users', 'assigned_to' => 'users', 'manager' => 'users', 'requester' => 'users', 'default_assignee' => 'users', 'backup_assignee' => 'users', 'parent' => null, 'category' => null, 'role' => 'roles'];
        if (array_key_exists($base, $map)) { $b = $map[$base]; return $b && isset($tables['opsh_' . $b]) ? 'opsh_' . $b . '.id' : null; }
        foreach (['opsh_' . $base . 's', 'opsh_' . $base . 'es', 'opsh_' . preg_replace('/y$/', 'ies', $base), 'opsh_' . $base] as $cand) if (isset($tables[$cand])) return $cand . '.id';
        return null;
    }
    /** A short description of a column from its name and type (the database has no column comments). */
    function opsh_dd_describe_col(string $n, string $dt, ?string $link): string {
        if ($n === 'id') return 'Number of the row (made by the database).';
        if ($link) return 'Points to a row of ' . explode('.', $link)[0] . '.';
        if (preg_match('/^(.+)_at$/', $n, $m)) return 'When it was ' . str_replace('_', ' ', $m[1]) . '.';
        if (preg_match('/^(.+)_by$/', $n, $m)) return 'Who ' . str_replace('_', ' ', $m[1]) . ' it (a person).';
        if (preg_match('/_(date|on)$/', $n)) return 'A date.';
        if (preg_match('/^(is|has|can|allow|require)_/', $n) || in_array($n, ['active', 'enabled', 'pinned', 'required'], true)) return 'Yes or no.';
        if (in_array($n, ['status', 'state'], true)) return 'Where it stands.';
        if ($n === 'sort_order' || $n === 'seq') return 'Order in lists (lower first).';
        if (in_array($n, ['name', 'title', 'display_name'], true)) return 'Name shown on screen.';
        if ($n === 'slug') return 'Short name used inside the app (not changed here).';
        if (in_array($n, ['notes', 'description', 'body', 'message'], true)) return 'Free text.';
        if (preg_match('/email/', $n)) return 'An email address.';
        if (preg_match('/phone/', $n)) return 'A phone number.';
        if (preg_match('/(url|website)/', $n)) return 'A web address.';
        if (in_array($dt, ['json', 'longtext', 'mediumtext']) && preg_match('/(detail|config|data|json|report|payload|settings|permissions)/', $n)) return 'Details kept as JSON.';
        return ucfirst(str_replace('_', ' ', $n)) . '.';
    }

    /** One value checked against its column. Returns the value to store (null allowed when the column allows it). */
    function opsh_dd_clean_value(array $col, $v) {
        $label = $col['name'];
        if ($v === null || (is_string($v) && trim($v) === '' && $col['nullable'] && !in_array($col['name'], ['name'], true))) {
            if (!$col['nullable']) throw new InvalidArgumentException('"' . $label . '" cannot be empty.');
            return null;
        }
        $dt = $col['data_type'];
        if (in_array($dt, ['tinyint'], true) && preg_match('/tinyint\(1\)/', $col['type'])) {
            if ($v === true || $v === 1 || $v === '1' || $v === 'true') return 1; if ($v === false || $v === 0 || $v === '0' || $v === 'false') return 0;
            throw new InvalidArgumentException('"' . $label . '" is yes or no.');
        }
        if (in_array($dt, ['int', 'smallint', 'mediumint', 'bigint', 'tinyint'], true)) {
            if (!is_numeric($v) || (string)(int)$v !== trim((string)$v)) throw new InvalidArgumentException('"' . $label . '" must be a whole number.');
            $v = (int)$v; if (stripos($col['type'], 'unsigned') !== false && $v < 0) throw new InvalidArgumentException('"' . $label . '" cannot be negative.');
            if (abs($v) > 2000000000) throw new InvalidArgumentException('"' . $label . '" is too large.');
            return $v;
        }
        if (in_array($dt, ['decimal', 'float', 'double'], true)) { if (!is_numeric($v)) throw new InvalidArgumentException('"' . $label . '" must be a number.'); return 0 + $v; }
        if ($dt === 'enum') { $v = (string)$v; if (!in_array($v, $col['enum'], true)) throw new InvalidArgumentException('Choose one of: ' . implode(', ', $col['enum']) . '.'); return $v; }
        if (!is_string($v) && !is_numeric($v)) throw new InvalidArgumentException('"' . $label . '" must be text.');
        $v = str_replace("\r\n", "\n", trim((string)$v));
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v)) throw new InvalidArgumentException('"' . $label . '" holds a character that does not belong in text.');
        $max = $col['max'] ?: 65535; if ($max > 10000) $max = 10000;
        if (mb_strlen($v) > $max) throw new InvalidArgumentException('"' . $label . '" can be up to ' . $max . ' characters.');
        if ($col['name'] === 'name' && $v === '') throw new InvalidArgumentException('The name cannot be empty.');
        if ($col['name'] === 'color' && $v !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $v)) throw new InvalidArgumentException('A color is #RRGGBB, for example #1D4D7A.');
        if (preg_match('/email/', $col['name']) && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('"' . $v . '" is not an email address.');
        if (preg_match('/(url|website)/', $col['name']) && $v !== '' && !preg_match('#^https?://[^\s<>"]+$#i', $v)) throw new InvalidArgumentException('A web address starts with https:// (or http://).');
        if (preg_match('/<\s*(script|iframe|object|embed)/i', $v)) throw new InvalidArgumentException('Remove the HTML from "' . $label . '".');
        return $v;
    }

    /**
     * The WHERE of a grid query from the filters [{c, op, v}] (columns checked against the table). Returns [sql, args].
     * ops: contains, eq, ne, starts, gt, lt, empty, notempty. Secret columns cannot be filtered.
     */
    function opsh_dd_where(array $cols, array $filters): array {
        $by = []; foreach ($cols as $c) $by[$c['name']] = $c;
        $w = []; $a = [];
        foreach (array_slice($filters, 0, 20) as $f) {
            if (!is_array($f)) continue; $c = (string)($f['c'] ?? ''); $op = (string)($f['op'] ?? 'contains'); $v = $f['v'] ?? '';
            if (!isset($by[$c]) || $by[$c]['secret']) throw new InvalidArgumentException('That column cannot be filtered.');
            if (!is_scalar($v) && $v !== null) throw new InvalidArgumentException('A filter value must be text.');
            $v = mb_substr((string)$v, 0, 200); $q = '`' . $c . '`';
            switch ($op) {
                case 'contains': $w[] = "$q LIKE ?"; $a[] = '%' . addcslashes($v, '%_\\') . '%'; break;
                case 'starts': $w[] = "$q LIKE ?"; $a[] = addcslashes($v, '%_\\') . '%'; break;
                case 'eq': if ($v === '(empty)') $w[] = "($q IS NULL OR $q = '')"; else { $w[] = "$q = ?"; $a[] = $v; } break;
                case 'ne': $w[] = "($q IS NULL OR $q <> ?)"; $a[] = $v; break;
                case 'gt': $w[] = "$q > ?"; $a[] = $v; break;
                case 'lt': $w[] = "$q < ?"; $a[] = $v; break;
                case 'empty': $w[] = "($q IS NULL OR $q = '')"; break;
                case 'notempty': $w[] = "($q IS NOT NULL AND $q <> '')"; break;
                default: throw new InvalidArgumentException('Unknown filter.');
            }
        }
        return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $a];
    }

    /** A row as the grid shows it: secrets hidden, long text cut (the full text is in the row view). */
    function opsh_dd_mask_row(string $t, array $cols, array $r, bool $full = false): array {
        $o = [];
        foreach ($cols as $c) { $n = $c['name']; $v = $r[$n] ?? null;
            if ($c['secret'] && $v !== null && $v !== '') { $o[$n] = '•••• hidden'; continue; }
            $st = opsh_dd_app()['settings'] ?? null;   // the app's key/value settings table: key-like values are hidden
            if ($st && $t === $st[0] && $n === $st[2] && opsh_dd_secret_setting((string)($r[$st[1]] ?? ''), $v)) { $o[$n] = '•••• hidden'; continue; }
            if (is_string($v) && !$full && mb_strlen($v) > 300) $v = mb_substr($v, 0, 300) . '…';
            $o[$n] = $v; }
        return $o;
    }

    /** An .xlsx file (one sheet, header row bold and frozen, filter on, every cell as written — no formulas). */
    function opsh_dd_xlsx(string $sheet, array $head, iterable $rows): string {
        if (!class_exists('ZipArchive')) throw new RuntimeException('This server cannot make Excel files (the PHP zip extension is missing). Use CSV.');
        $esc = fn($s) => htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $col = function (int $i): string { $s = ''; $i++; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); } return $s; };
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>';
        $x .= '<row r="1">'; foreach ($head as $i => $h) $x .= '<c r="' . $col($i) . '1" t="inlineStr" s="1"><is><t>' . $esc($h) . '</t></is></c>'; $x .= '</row>';
        $n = 1;
        foreach ($rows as $r) { $n++; $x .= '<row r="' . $n . '">';
            foreach (array_values($r) as $i => $v) { $ref = $col($i) . $n;
                if ($v === null || $v === '') continue;
                if ((is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?(0|[1-9]\d{0,14})(\.\d{1,10})?$/', $v))) && !(is_string($v) && strlen($v) > 1 && $v[0] === '0' && $v[1] !== '.')) $x .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
                else $x .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $esc(mb_substr((string)$v, 0, 32000)) . '</t></is></c>'; }
            $x .= '</row>'; }
        $last = $col(max(0, count($head) - 1)) . max(1, $n);
        $x .= '</sheetData><autoFilter ref="A1:' . $last . '"/></worksheet>';
        $sheet = mb_substr(preg_replace('#[\\\\/?*\[\]:]#', ' ', $sheet), 0, 31) ?: 'Data';
        $tmp = tempnam(sys_get_temp_dir(), 'ddx'); $z = new ZipArchive(); $z->open($tmp, ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $esc($sheet) . '" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . $esc(str_replace("'", "''", $sheet)) . '\'!$A$1:$' . preg_replace('/\d+$/', '', $last) . '$' . max(1, $n) . '</definedName></definedNames></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf fontId="0"/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
        $z->addFromString('xl/worksheets/sheet1.xml', $x);
        $z->close(); $bytes = (string)file_get_contents($tmp); @unlink($tmp);
        return $bytes;
    }
    /** A CSV cell that never starts a spreadsheet formula. */
    function opsh_dd_csv_cell($v): string {
        $s = $v === null ? '' : (string)$v;
        if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) $s = "'" . $s;
        return '"' . str_replace('"', '""', $s) . '"';
    }

    /**
     * BT138: the whole dictionary as a Markdown document (Administration → Data → "Download the dictionary"), written from
     * this app's catalog and the live database, so it always matches the installation. Same layout in every app.
     */
    function opsh_dd_markdown(PDO $db, string $product, string $build): string {
        $cat = opsh_dd_catalog(); $ed = opsh_dd_editable(); $tabs = opsh_dd_tables($db);
        $kinds = ['ref' => 'Reference list', 'main' => 'Records', 'detail' => 'Detail rows', 'link' => 'Links', 'log' => 'Log / history', 'system' => 'System'];
        $e = fn($v) => str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], (string)$v);
        $code = fn($v) => '`' . str_replace('`', "'", (string)$v) . '`';
        $byArea = []; $cols = []; $n = 0;
        foreach (array_keys($tabs) as $t) { $c = $cat[$t] ?? ['Other', 'system', 'Not described yet.']; $byArea[$c[0]][] = $t; $cols[$t] = opsh_dd_columns($db, $t); $n += count($cols[$t]); }
        ksort($byArea);
        $o = '# ' . $product . " — Data Dictionary\n\nBuild " . $build . ' · ' . count($tabs) . ' tables · ' . $n . ' columns · written ' . date('m/d/Y') . " from this installation's database.\n\n";
        $o .= "**How to read it.** *Kind*: Reference list (small lists people maintain; those marked ✎ can be changed in Administration → Data), Records, Detail rows (lines that belong to a record), Links, Log / history, System. *Points to*: the table a column refers to; \"(by name)\" means found from the column name. *Hidden*: a secret, never shown or exported.\n\n";
        $o .= "## Similar tables (merge or keep)\n\n";
        foreach (opsh_dd_families() as $f) $o .= '- **' . $f[0] . '** — ' . ($f[3] === 'consider' ? 'could be merged' : 'keep apart') . ': ' . implode(', ', array_map($code, array_values(array_filter($f[1], fn($x) => isset($tabs[$x]))))) . '. ' . $f[2] . "\n";
        $o .= "\n## Tables by area\n\n| Area | Tables |\n|---|---|\n";
        foreach ($byArea as $a => $ts) $o .= '| ' . $e($a) . ' | ' . implode(', ', array_map($code, $ts)) . " |\n";
        foreach ($byArea as $a => $ts) {
            $o .= "\n## " . $a . "\n";
            foreach ($ts as $t) { $c = $cat[$t] ?? ['Other', 'system', 'Not described yet.'];
                $o .= "\n### " . $code($t) . (isset($ed[$t]) ? ' ✎' : '') . "\n\n*" . ($kinds[$c[1]] ?? $c[1]) . '* — ' . $e($c[2]) . (isset($ed[$t]) ? ' Can be changed in Administration → Data: ' . implode(', ', array_map($code, $ed[$t])) . '.' : '') . "\n\n";
                $o .= "| Column | Type | Empty | Default | Key | Points to | What it holds |\n|---|---|---|---|---|---|---|\n";
                foreach ($cols[$t] as $col) {
                    $key = ['PRI' => 'primary', 'UNI' => 'unique', 'MUL' => 'index'][$col['key']] ?? '';
                    $lnk = $col['link'] ? $code($col['link']) . ($col['link_declared'] ? '' : ' (by name)') : '';
                    $o .= '| ' . $code($col['name']) . ($col['secret'] ? ' (hidden)' : '') . (in_array($col['name'], $ed[$t] ?? [], true) ? ' ✎' : '') . ' | ' . $e($col['type']) . ' | ' . ($col['nullable'] ? 'yes' : 'no') . ' | '
                        . ($col['default'] === null || $col['default'] === 'NULL' ? '' : $e($col['default'])) . ' | ' . $key . ' | ' . $lnk . ' | ' . $e($col['description']) . " |\n";
                }
            }
        }
        return $o;
    }

    /** Which code files name a table (kept for a day): used by the report to find tables nothing uses. */
    function opsh_dd_code_use(array $tables): array {
        $f = null;
        if (function_exists('opsh_cache_dir') || @include_once __DIR__ . '/SafeCache.php') { try { $f = opsh_cache_dir('dd') . '/use.json'; if (is_file($f) && filemtime($f) > time() - 86400) { $j = json_decode((string)file_get_contents($f), true); if (is_array($j) && !array_diff($tables, array_keys($j))) return $j; } } catch (Throwable $e) { $f = null; } }
        $root = dirname(__DIR__, 2); $files = [];
        $app = opsh_dd_app();
        foreach ($app['code_dirs'] as $d) foreach (glob(rtrim($root . '/' . $d, '/') . '/*.php') ?: [] as $p) if (!in_array(basename($p, '.php'), $app['code_skip'], true)) $files[] = $p;
        $txt = []; foreach ($files as $p) $txt[substr($p, strlen($root) + 1)] = (string)@file_get_contents($p);
        $out = [];
        foreach ($tables as $t) { $n = []; foreach ($txt as $p => $s) if (preg_match('/\b' . preg_quote($t, '/') . '\b/', $s)) $n[] = $p; $out[$t] = $n; }
        if ($f) { try { file_put_contents($f, json_encode($out), LOCK_EX); } catch (Throwable $e) {} }
        return $out;
    }
}
