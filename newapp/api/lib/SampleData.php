<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT140: remove the sample records (Settings → Maintenance, Super Admin).
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

if (!function_exists('itops_sample_catalog')) {

    /** table => [label, the columns that identify a sample record]. */
    function itops_sample_tables(): array {
        return [
            'itops_tickets'         => ['Tickets', ['ticket_number', 'subject']],
            'itops_calendar_events' => ['Calendar entries', ['title', 'description']],
            'itops_kb_articles'     => ['Knowledge Base articles', ['title', 'slug']],
            'itops_contracts'       => ['Contracts', ['title', 'contract_type', 'start_date']],
            'itops_credentials'     => ['Credentials', ['name', 'description', 'system_url']],
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
    function itops_sample_count(PDO $db, string $table, string $col, int $id, string $extra = '', array $args = []): int {
        if (!itops_sample_has($db, $table, $col)) return 0;
        $st = $db->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $col . '`=?' . $extra);
        $st->execute(array_merge([$id], $args)); return (int)$st->fetchColumn();
    }

    /** Why a found sample record must be kept ('' = free to remove). */
    function itops_sample_in_use(PDO $db, string $table, int $id): string {
        $c = fn($t, $col, $x = '', $a = []) => itops_sample_count($db, $t, $col, $id, $x, $a);
        $ci = fn($type) => itops_sample_count($db, 'itops_ci_links', 'entity_id', $id, ' AND entity_type=?', [$type]);
        switch ($table) {
            case 'itops_credentials':
                $st = $db->prepare("SELECT COALESCE(username_enc,''), COALESCE(password_enc,''), COALESCE(api_key_enc,''), COALESCE(mfa_details_enc,''), COALESCE(notes_enc,'') FROM itops_credentials WHERE id=?");
                $st->execute([$id]); $r = $st->fetch(PDO::FETCH_NUM) ?: [];
                if (implode('', $r) !== '') return 'a value was saved on it';
                if ($ci('credential')) return 'linked to an asset';
                return '';
            case 'itops_contracts':
                if ($c('itops_invoice_lines', 'contract_id')) return 'an invoice line points to it';
                if ($c('itops_budget_lines', 'contract_id')) return 'a budget line points to it';
                if ($c('itops_license_subs', 'contract_id')) return 'a license subscription points to it';
                if (itops_sample_count($db, 'itops_attachments', 'entity_id', $id, ' AND entity_type=?', ['contracts'])) return 'a file is attached';
                if ($ci('contract')) return 'linked to an asset';
                return '';
            case 'itops_kb_articles':
                if ($c('itops_case_svc', 'kb_article_id')) return 'a case refers to it';
                return '';
            case 'itops_tickets':
                foreach (['itops_form_submissions' => 'a form submission', 'itops_risk_followups' => 'a risk follow-up', 'itops_sa_plan_items' => 'a security plan item',
                          'itops_m365_incident_tickets' => 'a Microsoft 365 incident', 'itops_major_reviews' => 'a major incident review', 'itops_m360_alerts' => 'a monitoring alert', 'itops_phone_calls' => 'a missed call', 'itops_pbx_cdr' => 'a phone system call record', 'itops_jf_submissions' => 'a Jotform submission'] as $t => $w)
                    if ($c($t, 'ticket_id')) return $w . ' points to it';
                if ($ci('ticket')) return 'linked to an asset';
                return '';
            case 'itops_calendar_events':
                if ($c('itops_case_tasks', 'event_id')) return 'a case task refers to it';
                return '';
        }
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
                $name = (string)reset($row) . ($table === 'itops_tickets' ? ' · ' . $row['subject'] : '');
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
                switch ($tb['table']) {
                    case 'itops_tickets':
                        foreach (['itops_ticket_comments', 'itops_ticket_attachments', 'itops_ticket_csat', 'itops_sla_alerts'] as $t) $del($t, 'ticket_id', $ids);
                        break;
                    case 'itops_kb_articles': $del('itops_kb_votes', 'article_id', $ids); break;
                    case 'itops_calendar_events': $del('itops_calendar_attendees', 'event_id', $ids); $del('itops_calendar_reminders_sent', 'event_id', $ids); break;
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
