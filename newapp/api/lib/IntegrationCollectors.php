<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
require_once __DIR__ . '/Brand.php';   // BT20: opsh_app_name()
/**
 * OpsHub — BT16 collectors, import pipeline and retention.
 *
 * SAFETY MODEL
 *   - Every automated collector ships OFF. To run on a schedule it needs, in this order:
 *       1. an APPROVAL recorded by a Super Admin (who, when, reference to the approval decision);
 *       2. a frequency for the INTENSIVE window (default 00:00–07:00) and for the DAYTIME window (07:00–24:00)
 *          — no default frequencies are shipped;
 *       3. "Enable" by a Super Admin with a typed confirmation;
 *       4. cron-audit.php added to cron by an administrator (nothing else calls the scheduler).
 *   - Collectors that need something outside OpsHub (a new DB account/privilege, userstat on the server,
 *     a Fabric app registration) cannot even be approved until that prerequisite is configured — and OpsHub
 *     never creates it: no GRANT, no SET GLOBAL, no logging change, no app registration.
 *   - A manual "Test connection" contacts nothing but the configured endpoint with SELECT 1 / a WHM ping.
 *   - Every run (import, manual, scheduled) writes opsh_collector_runs. A failure is recorded and swallowed.
 */

require_once __DIR__ . '/IntegrationAudit.php';
require_once __DIR__ . '/EvidenceParsers.php';

if (!function_exists('opsh_ic_catalog')) {

    /**
     * [label, kind, source, query/API, timeout s, max rows, overhead, prerequisite]
     * kind: import (always available, no production contact) | sampler (approval-gated) | unavailable (future phase)
     */
    function opsh_ic_catalog(): array {
        $imp = fn($label, $parser) => ['label' => $label, 'kind' => 'import', 'parser' => $parser, 'source' => 'File uploaded by an administrator',
            'query' => 'none — parses the uploaded file only', 'timeout' => OPSH_EP_MAX_SECONDS, 'max_rows' => OPSH_EP_MAX_ROWS, 'overhead' => 'None on production', 'prereq' => ''];
        return [
            'import_processlist' => $imp('MariaDB processlist import', 'db_processlist'),
            'import_ps_digest'   => $imp('Performance Schema digest import', 'db_ps_digest'),
            'import_userstat'    => $imp('MariaDB userstat import', 'db_userstat'),
            'import_http'        => $imp('Apache / vTiger access log import', 'http_access'),
            'import_cron'        => $imp('Cron inventory import', 'cron_inventory'),
            'import_sar'         => $imp('sar (sysstat) import', 'sar'),
            'import_fabric'      => $imp('Fabric / Power BI history import', 'fabric_history'),
            'import_generic'     => $imp('Other CSV import', 'generic_csv'),
            'opshub_native'      => ['label' => '' . opsh_app_name() . ' native records', 'kind' => 'native', 'source' => 'opsh_event_log (vtiger, integrations, cron, webhook, m365 channels)',
                'query' => 'SELECT … FROM opsh_event_log WHERE ts in range (bounded)', 'timeout' => 5, 'max_rows' => 5000, 'overhead' => opsh_app_name() . ' database only', 'prereq' => ''],
            'whm_load' => ['label' => 'Server load sampler (WHM)', 'kind' => 'sampler', 'source' => 'WHM API loadavg — existing Guardian token',
                'query' => 'GET json-api/loadavg', 'timeout' => 8, 'max_rows' => 3, 'overhead' => 'Negligible (one WHM API call)', 'prereq' => 'whm'],
            'db_processlist' => ['label' => 'MariaDB processlist sampler', 'kind' => 'sampler', 'source' => 'information_schema.PROCESSLIST via a DEDICATED read-only account',
                'query' => "SELECT ID,USER,HOST,DB,COMMAND,TIME,STATE,LEFT(INFO,2000) FROM information_schema.PROCESSLIST WHERE COMMAND<>'Sleep' AND ID<>CONNECTION_ID() LIMIT 200",
                'timeout' => 3, 'max_rows' => 200, 'overhead' => 'Very low (reads an in-memory list)', 'prereq' => 'monitor_db'],
            'db_userstat' => ['label' => 'MariaDB userstat snapshot', 'kind' => 'sampler', 'source' => 'information_schema.USER_/CLIENT_/TABLE_STATISTICS via the dedicated account',
                'query' => 'SELECT * FROM information_schema.CLIENT_STATISTICS LIMIT 500 (+ USER_STATISTICS, TABLE_STATISTICS)', 'timeout' => 3, 'max_rows' => 1500,
                'overhead' => 'Very low — but only returns data if userstat is ENABLED on the server (a separate approval; ' . opsh_app_name() . ' never enables it)', 'prereq' => 'monitor_db'],
            'fabric_api' => ['label' => 'Fabric / Power BI API collector', 'kind' => 'unavailable', 'source' => 'Power BI / Fabric REST via a SEPARATE read-only app registration',
                'query' => 'Not built (Phase 4)', 'timeout' => 15, 'max_rows' => 1000, 'overhead' => 'None on vTiger', 'prereq' => 'fabric_app'],
        ];
    }

    function opsh_ic_state(PDO $db): array {
        $st = opsh_ia_setting($db, 'ia_collectors', []);
        $out = [];
        foreach (opsh_ic_catalog() as $k => $c) {
            $s = is_array($st[$k] ?? null) ? $st[$k] : [];
            $out[$k] = ['approved' => !empty($s['approved']), 'approved_by' => $s['approved_by'] ?? '', 'approved_at' => $s['approved_at'] ?? '', 'approval_ref' => $s['approval_ref'] ?? '',
                        'enabled' => !empty($s['enabled']) && !empty($s['approved']), 'night_sec' => isset($s['night_sec']) ? (int)$s['night_sec'] : null,
                        'day_sec' => isset($s['day_sec']) ? (int)$s['day_sec'] : null, 'last_run' => $s['last_run'] ?? null];
        }
        return $out;
    }
    function opsh_ic_state_save(PDO $db, array $state, ?int $uid): void {
        $raw = [];
        foreach ($state as $k => $s) $raw[$k] = array_intersect_key($s, array_flip(['approved', 'approved_by', 'approved_at', 'approval_ref', 'enabled', 'night_sec', 'day_sec', 'last_run']));
        opsh_ia_setting_save($db, 'ia_collectors', $raw, $uid);
    }

    /** Prerequisite status — never creates anything; only reports whether it is configured. */
    function opsh_ic_prereq(PDO $db, string $p): array {
        if ($p === '') return ['ok' => true, 'text' => ''];
        if ($p === 'whm') {
            require_once __DIR__ . '/Guardian.php';
            $ok = (bool)opsh_whm_conn(opsh_guardian_settings($db));
            return ['ok' => $ok, 'text' => $ok ? 'WHM host + token configured (Server Guardian)' : 'Add the WHM host + token in Server Guardian → Server actions'];
        }
        if ($p === 'monitor_db') {
            $c = opsh_ic_monitor_db_cfg($db);
            $ok = $c['host'] !== '' && $c['user'] !== '' && $c['pass'] !== '';
            return ['ok' => $ok, 'text' => $ok ? 'Dedicated monitoring account "' . $c['user'] . '" configured' : 'Needs a DEDICATED MariaDB monitoring account (PROCESS privilege only), created by the DB administrator after approval — ' . opsh_app_name() . ' never creates accounts or grants'];
        }
        if ($p === 'fabric_app') return ['ok' => false, 'text' => 'Phase 4 — separate read-only app registration, reviewed on its own. Use the Fabric history import meanwhile.'];
        return ['ok' => false, 'text' => 'Unknown prerequisite'];
    }

    /** Dedicated monitoring DB account — never the vTiger lookup account (OpshVTigerDb stays untouched). Password stored enc:v1:. */
    function opsh_ic_monitor_db_cfg(PDO $db): array {
        require_once __DIR__ . '/Secrets.php';
        $c = opsh_ia_setting($db, 'ia_monitor_db', []);
        $pass = '';
        if (!empty($c['pass'])) { try { $pass = opsh_secret_unwrap((string)$c['pass']); } catch (\Throwable $e) { $pass = ''; } }
        return ['host' => (string)($c['host'] ?? ''), 'port' => (int)($c['port'] ?? 3306), 'user' => (string)($c['user'] ?? ''), 'pass' => $pass, 'label' => (string)($c['label'] ?? '')];
    }

    function opsh_ic_monitor_pdo(PDO $db, int $timeout = 3): PDO {
        $c = opsh_ic_monitor_db_cfg($db);
        if ($c['host'] === '' || $c['user'] === '') throw new RuntimeException('Monitoring account not configured');
        $pdo = new PDO('mysql:host=' . $c['host'] . ';port=' . $c['port'] . ';charset=utf8mb4', $c['user'], $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => $timeout]);
        // Session-only statement limit (MariaDB ≥10.1). Affects this connection only — not a server setting.
        try { $pdo->exec('SET SESSION max_statement_time=' . (int)$timeout); } catch (\Throwable $e) {}
        return $pdo;
    }

    /* ─────────────────────────── scheduler (cron-audit.php only) ─────────────────────────── */

    /** Which monitoring period a local time falls in. */
    function opsh_ic_period(PDO $db, ?DateTime $now = null): string {
        $W = opsh_ia_windows($db); $now = $now ?: new DateTime('now', new DateTimeZone(opsh_ia_server_tz()));
        return opsh_ia_in_window($now->format('H:i'), $W['night_start'], $W['night_end']) ? 'night' : 'day';
    }

    /** Run every ENABLED + APPROVED sampler whose frequency for the current period has elapsed. Bounded, locked. */
    function opsh_ic_scheduler(PDO $db): array {
        $got = (int)$db->query("SELECT GET_LOCK('opsh_ia_scheduler', 0)")->fetchColumn();
        if ($got !== 1) return ['ran' => [], 'note' => 'another scheduler run is in progress'];
        $ran = []; $t0 = microtime(true);
        try {
            $state = opsh_ic_state($db); $period = opsh_ic_period($db); $cat = opsh_ic_catalog();
            foreach ($state as $k => $s) {
                if (($cat[$k]['kind'] ?? '') !== 'sampler' || !$s['enabled'] || !$s['approved']) continue;
                $freq = $period === 'night' ? $s['night_sec'] : $s['day_sec'];
                if (!$freq || $freq < 60) continue;                                    // 0 / unset = do not run in this period
                if ($s['last_run'] && (time() - strtotime($s['last_run'] . ' UTC')) < $freq - 5) continue;
                if (microtime(true) - $t0 > 40) break;                                 // stay well inside a cron minute
                $ran[$k] = opsh_ic_run($db, $k, 'scheduled', null);
            }
            opsh_ic_retention_run($db, false);
        } finally { $db->query("SELECT RELEASE_LOCK('opsh_ia_scheduler')"); }
        return ['ran' => $ran, 'period' => $period ?? ''];
    }

    /**
     * Run one sampler once. $mode: scheduled | manual. Manual runs also require the approval (a manual run
     * still reads production). Returns the collector-run summary; never throws.
     */
    function opsh_ic_run(PDO $db, string $key, string $mode, ?int $uid): array {
        $cat = opsh_ic_catalog(); $c = $cat[$key] ?? null;
        if (!$c || $c['kind'] !== 'sampler') return ['status' => 'failed', 'error' => 'Not a runnable collector'];
        $state = opsh_ic_state($db);
        if (!$state[$key]['approved']) return ['status' => 'skipped', 'error' => 'Not approved'];
        $t0 = microtime(true); $run = opsh_ia_run_start($db, $key, $mode, $uid);
        $seen = 0; $imp = 0; $skip = 0; $err = ''; $status = 'ok';
        try {
            $cap = gmdate('Y-m-d H:i:s') . '.000';
            if ($key === 'whm_load') {
                require_once __DIR__ . '/Guardian.php';
                $conn = opsh_whm_conn(opsh_guardian_settings($db)); if (!$conn) throw new RuntimeException('WHM not configured');
                $r = opsh_whm_call($conn, 'loadavg', [], 8); if (!$r['ok']) throw new RuntimeException('WHM loadavg: ' . ($r['error'] ?: $r['code']));
                $ts = substr($cap, 0, 19); $m = [];
                foreach (['one' => 'load1', 'five' => 'load5', 'fifteen' => 'load15'] as $k2 => $metric) if (isset($r['data'][$k2]))
                    $m[] = ['ts' => $ts, 'metric' => $metric, 'value' => (float)$r['data'][$k2], 'unit' => '', 'source' => 'whm', 'host' => (string)($conn['host'] ?? ''), 'collector_run_id' => $run];
                $seen = count($m); $imp = opsh_ia_insert_metrics($db, $m);
            } elseif ($key === 'db_processlist') {
                $pdo = opsh_ic_monitor_pdo($db, 3);
                $rows = $pdo->query("SELECT ID AS id, USER AS user, HOST AS host, DB AS db, COMMAND AS command, TIME AS time, STATE AS state, LEFT(INFO,2000) AS info
                    FROM information_schema.PROCESSLIST WHERE COMMAND <> 'Sleep' AND ID <> CONNECTION_ID() LIMIT 200")->fetchAll();
                $reg = opsh_ia_registry($db); $obs = [];
                foreach ($rows as $row) { $seen++; $o = opsh_ep_processlist_row(array_map(fn($v) => (string)$v, $row), $cap, opsh_ia_server_tz()); if (!$o) { $skip++; continue; }
                    $o['collector_run_id'] = $run; opsh_ia_classify($o, $reg); $obs[] = $o; }
                $imp = opsh_ia_insert_observations($db, $obs);
            } elseif ($key === 'db_userstat') {
                $pdo = opsh_ic_monitor_pdo($db, 3); $reg = opsh_ia_registry($db); $obs = [];
                foreach (['CLIENT_STATISTICS', 'USER_STATISTICS', 'TABLE_STATISTICS'] as $tbl) {
                    try { $rows = $pdo->query("SELECT * FROM information_schema.$tbl LIMIT 500")->fetchAll(); }
                    catch (\Throwable $e) { $err .= $tbl . ' unavailable. '; $status = 'partial'; continue; }
                    foreach ($rows as $row) { $seen++; $row = array_change_key_case(array_map(fn($v) => (string)$v, $row), CASE_LOWER);
                        $o = opsh_ep_userstat_row($row, $cap, opsh_ia_server_tz()); if (!$o) { $skip++; continue; }
                        $o['collector_run_id'] = $run; opsh_ia_classify($o, $reg); $obs[] = $o; }
                }
                if ($seen === 0 && $status === 'ok') { $status = 'partial'; $err = 'No rows — userstat is probably OFF on the server (' . opsh_app_name() . ' never enables it).'; }
                $imp = opsh_ia_insert_observations($db, $obs);
            }
        } catch (\Throwable $e) { $status = 'failed'; $err = $e->getMessage(); }
        opsh_ia_run_end($db, $run, $status, $seen, $imp, $skip, $err, $t0);
        $state[$key]['last_run'] = gmdate('Y-m-d H:i:s'); opsh_ic_state_save($db, $state, $uid);
        if ($status !== 'ok' && function_exists('opsh_log')) opsh_log('warning', 'integrations', 'Integration-audit collector ' . $key . ' ' . $status, ['error' => mb_substr($err, 0, 200), 'run' => $run]);
        return ['run_id' => $run, 'status' => $status, 'seen' => $seen, 'imported' => $imp, 'skipped' => $skip, 'error' => $err];
    }

    /** Connectivity check only (SELECT 1 / WHM version) — no data is collected. */
    function opsh_ic_test(PDO $db, string $key): array {
        try {
            if ($key === 'whm_load') { require_once __DIR__ . '/Guardian.php'; $c = opsh_whm_conn(opsh_guardian_settings($db)); if (!$c) return ['ok' => false, 'message' => 'WHM not configured'];
                $r = opsh_whm_call($c, 'version', [], 8); return ['ok' => (bool)$r['ok'], 'message' => $r['ok'] ? 'WHM reachable' : ('WHM: ' . ($r['error'] ?: $r['code']))]; }
            if ($key === 'db_processlist' || $key === 'db_userstat') { $p = opsh_ic_monitor_pdo($db, 3); $p->query('SELECT 1'); return ['ok' => true, 'message' => 'Monitoring account can connect (SELECT 1)']; }
        } catch (\Throwable $e) { return ['ok' => false, 'message' => mb_substr($e->getMessage(), 0, 200)]; }
        return ['ok' => false, 'message' => 'Nothing to test for this collector'];
    }

    /* ─────────────────────────── import pipeline ─────────────────────────── */

    /**
     * Parse an evidence file and (when $commit) store the result. Dry-run returns the same summary + preview
     * without writing observations. Every import is a collector run.
     */
    function opsh_ic_import(PDO $db, array $ev, string $raw, array $opt, bool $commit, ?int $uid): array {
        $parser = (string)$ev['parser'];
        $cat = opsh_ep_catalog(); if (!isset($cat[$parser])) throw new RuntimeException('Unknown parser');
        $t0 = microtime(true);
        $R = opsh_ep_parse($parser, $raw, $opt);
        $reg = opsh_ia_registry($db);
        $prov = !empty($opt['integration_id']) ? (int)$opt['integration_id'] : null;
        if ($prov && !isset($reg[$prov])) $prov = null;
        foreach ($R['observations'] as &$o) { $o['evidence_id'] = (int)$ev['id']; opsh_ia_classify($o, $reg, $prov); }
        unset($o);
        foreach ($R['metrics'] as &$m) $m['evidence_id'] = (int)$ev['id']; unset($m);
        $sum = ['read' => $R['read'], 'rejected' => $R['rejected'], 'observations' => count($R['observations']), 'metrics' => count($R['metrics']),
                'errors' => $R['errors'], 'notes' => $R['notes'], 'period_start' => $R['period_start'], 'period_end' => $R['period_end'],
                'by_attribution' => array_count_values(array_column($R['observations'], 'attribution')),
                'by_conformance' => array_count_values(array_column($R['observations'], 'conformance')),
                'unknown_fingerprints' => count(array_unique(array_column(array_filter($R['observations'], fn($x) => in_array($x['attribution'], ['unknown', 'investigate'], true) && empty($x['_no_queue'])), 'fingerprint')))];
        $sum['preview'] = array_map(fn($o) => array_intersect_key($o, array_flip(['observed_at', 'ended_at', 'source_type', 'account', 'host', 'db_name', 'endpoint', 'method', 'status_code',
            'query_digest', 'duration_ms', 'rows_examined', 'bytes', 'attribution', 'attribution_basis', 'conformance', 'conformance_reason', 'integration_id'])), array_slice($R['observations'], 0, 40));
        $sum['metric_preview'] = array_slice($R['metrics'], 0, 20);
        if (!$commit) {
            $db->prepare("UPDATE opsh_integration_evidence SET status=IF(status='imported',status,'previewed'), rows_read=?, rows_rejected=?, options=? WHERE id=?")
               ->execute([$R['read'], $R['rejected'], json_encode($opt), $ev['id']]);
            return $sum + ['committed' => false];
        }
        $run = opsh_ia_run_start($db, 'import:' . $parser, 'import', $uid, (int)$ev['id']);
        $db->beginTransaction();
        try {
            $n = opsh_ia_insert_observations($db, array_map(fn($o) => $o + ['collector_run_id' => $run], $R['observations']));
            $m = $R['metrics'] ? opsh_ia_insert_metrics($db, array_map(fn($x) => $x + ['collector_run_id' => $run], $R['metrics'])) : 0;
            $db->prepare("UPDATE opsh_integration_evidence SET status='imported', rows_read=?, rows_imported=?, rows_rejected=?, period_start=COALESCE(period_start,?), period_end=COALESCE(period_end,?),
                    options=?, integration_id=?, error_summary=?, imported_by=?, imported_at=? WHERE id=?")
               ->execute([$R['read'], $n + $m, $R['rejected'], $R['period_start'] ? substr($R['period_start'], 0, 19) : null, $R['period_end'] ? substr($R['period_end'], 0, 19) : null,
                          json_encode($opt), $prov, $R['errors'] ? mb_substr(implode("\n", $R['errors']), 0, 4000) : null, $uid, gmdate('Y-m-d H:i:s'), $ev['id']]);
            $db->commit();
        } catch (\Throwable $e) { $db->rollBack(); opsh_ia_run_end($db, $run, 'failed', $R['read'], 0, $R['rejected'], $e->getMessage(), $t0); throw $e; }
        opsh_ia_run_end($db, $run, $R['rejected'] ? 'partial' : 'ok', $R['read'], $n + $m, $R['rejected'], $R['errors'] ? $R['errors'][0] : '', $t0);
        return $sum + ['committed' => true, 'imported_observations' => $n, 'imported_metrics' => $m, 'run_id' => $run];
    }

    /* ─────────────────────────── retention ─────────────────────────── */

    function opsh_ic_retention(PDO $db): array {
        $r = opsh_ia_setting($db, 'ia_retention', []);
        return ['enabled' => !empty($r['enabled']), 'raw_days' => max(7, (int)($r['raw_days'] ?? 90)), 'metric_days' => max(7, (int)($r['metric_days'] ?? 90)),
                'evidence_days' => max(30, (int)($r['evidence_days'] ?? 365)), 'runs_days' => max(30, (int)($r['runs_days'] ?? 365)),
                'saved_by' => $r['saved_by'] ?? '', 'saved_at' => $r['saved_at'] ?? '', 'last_purge' => $r['last_purge'] ?? ''];
    }

    /**
     * Purge — does NOTHING until an administrator has saved a policy with enabled=true. Raw observations are
     * rolled up hourly (per source/integration/fingerprint) before deletion. Findings, unknowns, registry and
     * audit history are never purged. Bounded: 20 × 5,000 rows per class per run. $force = manual "Purge now".
     */
    function opsh_ic_retention_run(PDO $db, bool $force): array {
        $p = opsh_ic_retention($db);
        if (!$p['enabled']) return ['ran' => false, 'reason' => 'No retention policy saved — purge is disabled'];
        if (!$force && $p['last_purge'] && substr($p['last_purge'], 0, 10) === gmdate('Y-m-d')) return ['ran' => false, 'reason' => 'Already ran today'];
        $res = ['observations' => 0, 'metrics' => 0, 'evidence_files' => 0, 'runs' => 0];
        $cut = gmdate('Y-m-d H:i:s', time() - $p['raw_days'] * 86400);
        for ($i = 0; $i < 20; $i++) {
            $ids = $db->prepare('SELECT id FROM opsh_integration_observations WHERE observed_at < ? ORDER BY id LIMIT 5000'); $ids->execute([$cut]);
            $ids = array_column($ids->fetchAll(), 'id'); if (!$ids) break;
            $in = implode(',', array_map('intval', $ids));
            $db->exec("INSERT INTO opsh_integration_obs_rollup (hour, source_type, integration_id, fingerprint, attribution, occurrences, sum_duration_ms, max_duration_ms, sum_rows_examined, sum_bytes)
                SELECT DATE_FORMAT(observed_at,'%Y-%m-%d %H:00:00'), source_type, COALESCE(integration_id,0), fingerprint, MAX(attribution), COUNT(*),
                       COALESCE(SUM(duration_ms),0), COALESCE(MAX(duration_ms),0), COALESCE(SUM(rows_examined),0), COALESCE(SUM(bytes),0)
                FROM opsh_integration_observations WHERE id IN ($in) GROUP BY 1,2,3,4
                ON DUPLICATE KEY UPDATE occurrences=occurrences+VALUES(occurrences), sum_duration_ms=sum_duration_ms+VALUES(sum_duration_ms),
                  max_duration_ms=GREATEST(max_duration_ms,VALUES(max_duration_ms)), sum_rows_examined=sum_rows_examined+VALUES(sum_rows_examined), sum_bytes=sum_bytes+VALUES(sum_bytes)");
            $res['observations'] += $db->exec("DELETE FROM opsh_integration_observations WHERE id IN ($in)");
        }
        $mc = gmdate('Y-m-d H:i:s', time() - $p['metric_days'] * 86400);
        for ($i = 0; $i < 20; $i++) { $n = $db->exec("DELETE FROM opsh_server_metric_samples WHERE ts < " . $db->quote($mc) . " LIMIT 5000"); $res['metrics'] += $n; if ($n < 5000) break; }
        $ec = gmdate('Y-m-d H:i:s', time() - $p['evidence_days'] * 86400);
        $old = $db->prepare("SELECT id, file_path FROM opsh_integration_evidence WHERE status<>'deleted' AND created_at < ? LIMIT 500"); $old->execute([$ec]);
        foreach ($old->fetchAll() as $ev) {
            $f = opsh_ic_evidence_dir() . '/' . basename((string)$ev['file_path']); if ($ev['file_path'] && is_file($f)) @unlink($f);
            $db->prepare("UPDATE opsh_integration_evidence SET status='deleted', file_path='', notes=CONCAT(COALESCE(notes,''),'\n[file removed by retention ',?,']') WHERE id=?")->execute([gmdate('Y-m-d'), $ev['id']]);
            $res['evidence_files']++;
        }
        $rc = gmdate('Y-m-d H:i:s', time() - $p['runs_days'] * 86400);
        $res['runs'] = $db->exec("DELETE FROM opsh_collector_runs WHERE started_at < " . $db->quote($rc) . " LIMIT 20000");
        $raw = opsh_ia_setting($db, 'ia_retention', []); $raw['last_purge'] = gmdate('Y-m-d H:i:s'); opsh_ia_setting_save($db, 'ia_retention', $raw);
        if (function_exists('opsh_log')) opsh_log('info', 'integrations', 'Integration-audit retention purge', $res);
        return ['ran' => true] + $res;
    }

    /** Evidence files live outside direct web access: uploads/evidence has its own deny-all .htaccess. */
    function opsh_ic_evidence_dir(): string {
        $d = dirname(__DIR__, 2) . '/public/uploads/evidence';
        if (!is_dir($d)) { @mkdir($d, 0750, true); }
        if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "# OpsHub BT16 — evidence files are served only through the API\nRequire all denied\n");
        return $d;
    }
}
