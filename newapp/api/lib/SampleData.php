<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — BT140: remove the sample records (Settings → Maintenance, Super Admin).
 *
 * The installer's "Add sample records" box loads sql/sample.sql. This finds those records again from the same file (the
 * file is the only list, so it can never drift from what was loaded) and removes them. A record is a sample record only
 * when its identifying fields still hold the sample values (for example a ticket's number AND subject). A sample record
 * that is in use is kept and named, never removed:
 *   credentials  a secret, user name, notes or MFA details were saved on it, or it is linked to an asset
 *   contracts    an invoice line, budget line or license subscription points to it, a file is attached, or an asset link
 *   KB articles  a case refers to it (known error / change)
 *   tickets      a form submission, risk follow-up, plan item, Microsoft 365 incident, major review or monitoring alert
 *                points to it, or an asset link
 *   calendar     a case task refers to it
 * Removing a ticket removes its comments and attachments (as when a ticket is deleted), its rating and SLA alerts; a KB
 * article its votes; a calendar entry its attendees and sent reminders. Everything runs in one transaction.
 */

if (!function_exists('opsh_sample_catalog')) {

    /** table => [label, the columns that identify a sample record]. */
    function opsh_sample_tables(): array {
        return [
            'opsh_tickets'         => ['Tickets', ['ticket_number', 'subject']],
            'opsh_calendar_events' => ['Calendar entries', ['title', 'description']],
            'opsh_kb_articles'     => ['Knowledge Base articles', ['title', 'slug']],
            'opsh_contracts'       => ['Contracts', ['title', 'contract_type', 'start_date']],
            'opsh_credentials'     => ['Credentials', ['name', 'description', 'system_url']],
        ];
    }

    /** One SQL value list "( … )" → values (strings; NULL → null). Quotes with '' and \' escapes. */
    function opsh_sample_tuple(string $s): array {
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
    function opsh_sample_catalog(?string $file = null): ?array {
        $file = $file ?? dirname(__DIR__, 2) . '/sql/sample.sql';
        if (!is_file($file)) return null;
        $tables = opsh_sample_tables(); $cat = array_fill_keys(array_keys($tables), []);
        $cur = null; $cols = [];
        foreach (preg_split('/\R/', (string)file_get_contents($file)) as $line) {
            if (preg_match('/^INSERT INTO `?(opsh_[a-z0-9_]+)`?\s*\(([^)]*)\)\s*VALUES/i', $line, $m)) {
                $cur = isset($tables[$m[1]]) ? $m[1] : null;
                $cols = array_map(fn($c) => trim($c, " `"), explode(',', $m[2]));
                continue;
            }
            if ($cur === null || !str_starts_with(ltrim($line), '(')) continue;
            $vals = opsh_sample_tuple(ltrim($line));
            if (count($vals) !== count($cols)) continue;
            $row = array_combine($cols, $vals); $key = [];
            foreach ($tables[$cur][1] as $k) $key[$k] = $row[$k] ?? null;
            $cat[$cur][] = $key;
            if (preg_match('/\);\s*$/', $line)) $cur = null;
        }
        return $cat;
    }

    /** Does a table / column exist (older databases may lack a link table)? */
    function opsh_sample_has(PDO $db, string $table, string $col = ''): bool {
        static $c = [];
        $k = $table . '.' . $col;
        if (!array_key_exists($k, $c)) {
            try { $db->query('SELECT ' . ($col !== '' ? '`' . $col . '`' : '1') . ' FROM `' . $table . '` LIMIT 0'); $c[$k] = true; }
            catch (Throwable $e) { $c[$k] = false; }
        }
        return $c[$k];
    }
    function opsh_sample_count(PDO $db, string $table, string $col, int $id, string $extra = '', array $args = []): int {
        if (!opsh_sample_has($db, $table, $col)) return 0;
        $st = $db->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $col . '`=?' . $extra);
        $st->execute(array_merge([$id], $args)); return (int)$st->fetchColumn();
    }

    /** Why a found sample record must be kept ('' = free to remove). */
    function opsh_sample_in_use(PDO $db, string $table, int $id): string {
        $c = fn($t, $col, $x = '', $a = []) => opsh_sample_count($db, $t, $col, $id, $x, $a);
        $ci = fn($type) => opsh_sample_count($db, 'opsh_ci_links', 'entity_id', $id, ' AND entity_type=?', [$type]);
        switch ($table) {
            case 'opsh_credentials':
                $st = $db->prepare("SELECT COALESCE(username_enc,''), COALESCE(password_enc,''), COALESCE(api_key_enc,''), COALESCE(mfa_details_enc,''), COALESCE(notes_enc,'') FROM opsh_credentials WHERE id=?");
                $st->execute([$id]); $r = $st->fetch(PDO::FETCH_NUM) ?: [];
                if (implode('', $r) !== '') return 'a value was saved on it';
                if ($ci('credential')) return 'linked to an asset';
                return '';
            case 'opsh_contracts':
                if ($c('opsh_invoice_lines', 'contract_id')) return 'an invoice line points to it';
                if ($c('opsh_budget_lines', 'contract_id')) return 'a budget line points to it';
                if ($c('opsh_license_subs', 'contract_id')) return 'a license subscription points to it';
                if (opsh_sample_count($db, 'opsh_attachments', 'entity_id', $id, ' AND entity_type=?', ['contracts'])) return 'a file is attached';
                if ($ci('contract')) return 'linked to an asset';
                return '';
            case 'opsh_kb_articles':
                if ($c('opsh_case_svc', 'kb_article_id')) return 'a case refers to it';
                return '';
            case 'opsh_tickets':
                foreach (['opsh_form_submissions' => 'a form submission', 'opsh_risk_followups' => 'a risk follow-up', 'opsh_sa_plan_items' => 'a security plan item',
                          'opsh_m365_incident_tickets' => 'a Microsoft 365 incident', 'opsh_major_reviews' => 'a major incident review', 'opsh_m360_alerts' => 'a monitoring alert', 'opsh_phone_calls' => 'a missed call', 'opsh_pbx_cdr' => 'a phone system call record', 'opsh_jf_submissions' => 'a Jotform submission'] as $t => $w)
                    if ($c($t, 'ticket_id')) return $w . ' points to it';
                if ($ci('ticket')) return 'linked to an asset';
                return '';
            case 'opsh_calendar_events':
                if ($c('opsh_case_tasks', 'event_id')) return 'a case task refers to it';
                return '';
        }
        return '';
    }

    /** What is there now: per table, the sample records found (id, name) and the ones kept with the reason. */
    function opsh_sample_scan(PDO $db, ?array $cat = null): array {
        $cat = $cat ?? opsh_sample_catalog();
        if ($cat === null) return ['available' => false, 'tables' => [], 'remove' => 0, 'keep' => 0];
        $out = []; $nr = 0; $nk = 0;
        foreach (opsh_sample_tables() as $table => [$label, $keys]) {
            $remove = []; $keep = [];
            foreach ($cat[$table] ?? [] as $row) {
                $w = []; $a = [];
                foreach ($row as $k => $v) { if ($v === null) $w[] = '`' . $k . '` IS NULL'; else { $w[] = '`' . $k . '`=?'; $a[] = $v; } }
                $st = $db->prepare('SELECT id FROM `' . $table . '` WHERE ' . implode(' AND ', $w));
                $st->execute($a);
                $name = (string)reset($row) . ($table === 'opsh_tickets' ? ' · ' . $row['subject'] : '');
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    $why = opsh_sample_in_use($db, $table, (int)$id);
                    if ($why === '') $remove[] = ['id' => (int)$id, 'name' => $name]; else $keep[] = ['id' => (int)$id, 'name' => $name, 'why' => $why];
                }
            }
            $nr += count($remove); $nk += count($keep);
            $out[] = ['table' => $table, 'label' => $label, 'remove' => $remove, 'keep' => $keep];
        }
        return ['available' => true, 'tables' => $out, 'remove' => $nr, 'keep' => $nk];
    }

    /** Removes the free sample records, in one transaction. Returns the scan it acted on plus 'removed' per table. */
    function opsh_sample_remove(PDO $db): array {
        $db->beginTransaction();
        try {
            $scan = opsh_sample_scan($db);
            $done = [];
            $del = function (string $t, string $col, array $ids) use ($db) {
                if (!$ids || !opsh_sample_has($db, $t, $col)) return;
                $db->prepare('DELETE FROM `' . $t . '` WHERE `' . $col . '` IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')->execute($ids);
            };
            foreach ($scan['tables'] as $tb) {
                $ids = array_column($tb['remove'], 'id');
                if (!$ids) { $done[$tb['table']] = 0; continue; }
                switch ($tb['table']) {
                    case 'opsh_tickets':
                        foreach (['opsh_ticket_comments', 'opsh_ticket_attachments', 'opsh_ticket_csat', 'opsh_sla_alerts'] as $t) $del($t, 'ticket_id', $ids);
                        break;
                    case 'opsh_kb_articles': $del('opsh_kb_votes', 'article_id', $ids); break;
                    case 'opsh_calendar_events': $del('opsh_calendar_attendees', 'event_id', $ids); $del('opsh_calendar_reminders_sent', 'event_id', $ids); break;
                }
                $del($tb['table'], 'id', $ids);
                $done[$tb['table']] = count($ids);
            }
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        $scan['removed'] = $done;
        return $scan;
    }
}
