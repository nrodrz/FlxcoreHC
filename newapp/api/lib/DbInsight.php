<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — the queries that cost the database most (BT163), from MariaDB/MySQL's own statistics
 * (performance_schema.events_statements_summary_by_digest), read through the read-only monitoring user.
 *
 * Needs performance_schema = ON (my.cnf, one restart) and SELECT on performance_schema.* for the monitoring user; the
 * page says which is missing and how to do it. The statistics count since the database started, so a snapshot of the
 * top 200 is kept every hour (30 days): a period's cost is the latest snapshot minus the one at its start. Every
 * value in a query is replaced by ? by the database itself, and masked again here.
 */
require_once __DIR__ . '/ServerPerf.php';
if (!function_exists('opsh_dbi_table')) {

    function opsh_dbi_table(PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS opsh_server_digest (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, schema_name VARCHAR(64) NULL, digest VARCHAR(64) NOT NULL,
            qtext TEXT NULL, cnt BIGINT NOT NULL DEFAULT 0, sum_ms DOUBLE NOT NULL DEFAULT 0, max_ms DOUBLE NOT NULL DEFAULT 0, rows_exam BIGINT NOT NULL DEFAULT 0, rows_sent BIGINT NOT NULL DEFAULT 0,
            no_index BIGINT NOT NULL DEFAULT 0, tmp_disk BIGINT NOT NULL DEFAULT 0, last_seen DATETIME NULL, INDEX idx_dig_at (at), INDEX idx_dig_d (digest, at))
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    function opsh_dbi_ready(PDO $db): bool { try { $db->query('SELECT 1 FROM opsh_server_digest LIMIT 1'); return true; } catch (Throwable $e) { return false; } }

    /** The monitoring user's connection: [PDO|null, error]. Null without error when no user is saved. */
    function opsh_dbi_pdo(array $c): array {
        $u = trim((string)($c['db_user'] ?? '')); $p = (string)($c['db_pass'] ?? ''); if ($u === '' || $p === '') return [null, null];
        try { require_once __DIR__ . '/Secrets.php';
              return [new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT), $u, opsh_secret_unwrap($p), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]), null]; }
        catch (Throwable $e) { return [null, 'The monitoring user could not sign in: ' . mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 160)]; }
    }
    /** What stands between the app and the statistics: 'nouser' | 'signin' | 'off' (performance_schema off) | 'grant' | 'ok'. */
    function opsh_dbi_state(?PDO $m, ?string $err): array {
        if (!$m && !$err) return ['state' => 'nouser', 'msg' => 'Save the database monitoring user first (Details » Settings » Database monitoring user).'];
        if (!$m) return ['state' => 'signin', 'msg' => (string)$err];
        try { $on = (int)$m->query('SELECT @@performance_schema')->fetchColumn(); } catch (Throwable $e) { $on = 0; }
        if (!$on) return ['state' => 'off', 'msg' => 'The database keeps no query statistics: performance_schema is OFF. That is a server setting (one database restart); see the guide Server Performance and Monitoring, 9.3, or ask the hosting team. The rest of the page works without it.'];
        try { $m->query('SELECT COUNT(*) FROM performance_schema.events_statements_summary_by_digest')->fetchColumn(); }
        catch (Throwable $e) { return ['state' => 'grant', 'msg' => 'The statistics are on, but the monitoring user may not read them yet: one GRANT in WHM, no restart (Help, step 3).', 'detail' => mb_substr($e->getMessage(), 0, 160)]; }
        return ['state' => 'ok', 'msg' => ''];
    }
    /** Keep a snapshot of the 200 costliest queries (by total time since the database started). */
    function opsh_dbi_snap(PDO $db, array $c, ?int $now = null): array {
        $now = $now ?? time(); opsh_dbi_table($db); [$m, $err] = opsh_dbi_pdo($c); $st = opsh_dbi_state($m, $err);
        if ($st['state'] !== 'ok') return $st;
        $rows = $m->query('SELECT SCHEMA_NAME, DIGEST, DIGEST_TEXT, COUNT_STAR, SUM_TIMER_WAIT, MAX_TIMER_WAIT, SUM_ROWS_EXAMINED, SUM_ROWS_SENT, SUM_NO_INDEX_USED, SUM_CREATED_TMP_DISK_TABLES, LAST_SEEN
                            FROM performance_schema.events_statements_summary_by_digest WHERE DIGEST IS NOT NULL ORDER BY SUM_TIMER_WAIT DESC LIMIT 200')->fetchAll(PDO::FETCH_NUM);
        $q = $db->prepare('INSERT INTO opsh_server_digest (at, schema_name, digest, qtext, cnt, sum_ms, max_ms, rows_exam, rows_sent, no_index, tmp_disk, last_seen) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $at = date('Y-m-d H:i:s', $now);
        foreach ($rows as [$sch, $dg, $txt, $n, $sum, $mx, $re, $rs, $ni, $td, $ls]) {
            if (stripos((string)$txt, 'performance_schema') !== false) continue;
            $q->execute([$at, $sch, (string)$dg, mb_substr(opsh_sp_qmask((string)$txt), 0, 2000), (int)$n, (float)$sum / 1e9, (float)$mx / 1e9, (int)$re, (int)$rs, (int)$ni, (int)$td, $ls ? substr((string)$ls, 0, 19) : null]);
        }
        $db->prepare('DELETE FROM opsh_server_digest WHERE at < ?')->execute([date('Y-m-d H:i:s', $now - 30 * 86400)]);
        return ['state' => 'ok', 'msg' => '', 'kept' => count($rows)];
    }
    /**
     * The costliest queries of a period: the latest snapshot minus the one at the period's start ($hours = 0: since the
     * database started). A query whose counters went down (the database restarted) counts from zero.
     */
    function opsh_dbi_top(PDO $db, int $hours, ?int $now = null): array {
        $now = $now ?? time(); if (!opsh_dbi_ready($db)) return ['rows' => [], 'latest' => null, 'base' => null];
        $latest = $db->query('SELECT MAX(at) FROM opsh_server_digest')->fetchColumn(); if (!$latest) return ['rows' => [], 'latest' => null, 'base' => null];
        $base = null; if ($hours > 0) { $b = $db->prepare('SELECT MAX(at) FROM opsh_server_digest WHERE at <= ?'); $b->execute([date('Y-m-d H:i:s', strtotime($latest) - $hours * 3600 + 300)]); $base = $b->fetchColumn() ?: null;
            if (!$base) { $b = $db->prepare('SELECT MIN(at) FROM opsh_server_digest WHERE at < ?'); $b->execute([$latest]); $base = $b->fetchColumn() ?: null; } }
        $L = $db->prepare('SELECT * FROM opsh_server_digest WHERE at = ?'); $L->execute([$latest]); $B = [];
        if ($base) { $q = $db->prepare('SELECT * FROM opsh_server_digest WHERE at = ?'); $q->execute([$base]); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $B[$r['digest'] . '|' . $r['schema_name']] = $r; }
        $out = [];
        foreach ($L->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $p = $B[$r['digest'] . '|' . $r['schema_name']] ?? null; if ($p && (int)$r['cnt'] < (int)$p['cnt']) $p = null;
            $n = (int)$r['cnt'] - (int)($p['cnt'] ?? 0); if ($n <= 0) continue; $ms = (float)$r['sum_ms'] - (float)($p['sum_ms'] ?? 0);
            $out[] = ['db' => $r['schema_name'], 'q' => $r['qtext'], 'runs' => $n, 'total_ms' => round($ms, 1), 'avg_ms' => round($ms / $n, 2), 'max_ms' => round((float)$r['max_ms'], 1),
                      'rows_exam' => (int)round(((int)$r['rows_exam'] - (int)($p['rows_exam'] ?? 0)) / $n), 'rows_sent' => (int)round(((int)$r['rows_sent'] - (int)($p['rows_sent'] ?? 0)) / $n),
                      'no_index' => (int)$r['no_index'] - (int)($p['no_index'] ?? 0), 'tmp_disk' => (int)$r['tmp_disk'] - (int)($p['tmp_disk'] ?? 0), 'last_seen' => $r['last_seen']];
        }
        usort($out, fn($a, $b) => $b['total_ms'] <=> $a['total_ms']); $tot = array_sum(array_column($out, 'total_ms'));
        foreach ($out as &$o) $o['share'] = $tot > 0 ? round(100 * $o['total_ms'] / $tot, 1) : 0; unset($o);
        return ['rows' => array_slice($out, 0, 30), 'latest' => $latest, 'base' => $base, 'total_ms' => round($tot, 1)];
    }
}
