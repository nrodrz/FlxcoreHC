<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — BT16 Integration Audit & Observability (core engine).
 *
 * OpsHub is the system of record for integration knowledge and audit EVIDENCE — not a monitoring agent.
 *   - Evidence comes in as imported files (Phase 1). Automated collectors exist but are OFF, need a
 *     recorded approval, and only run from cron-audit.php (a cron line an administrator adds on purpose).
 *   - Every timestamp is stored in UTC (DATETIME, written from PHP — never NOW()) next to the raw source
 *     timestamp and its source timezone.
 *   - Two independent judgements per observation:
 *       attribution  confirmed | probable | unknown | investigate   — WHO did it (identifier evidence only)
 *       conformance  expected | unexpected | not_evaluated          — did a known integration behave as documented
 *     Time NEVER contributes to attribution. Temporal correlation is computed on demand and labelled as such;
 *     only a reviewer's conclusion is persisted (opsh_integration_findings).
 *   - SQL text is never stored: only a normalised digest (literals replaced by ?), max 500 chars.
 *   - No secret is stored in evidence tables; the monitoring DB password (if ever configured) is enc:v1:.
 */

if (!function_exists('opsh_ia_schema')) {

    define('OPSH_IA_MAX_RANGE_DAYS', 31);
    define('OPSH_IA_ATTR', ['confirmed', 'probable', 'unknown', 'investigate']);
    define('OPSH_IA_CONF', ['expected', 'unexpected', 'not_evaluated']);
    define('OPSH_IA_SCHED_TYPES', ['cron', 'window', 'interval', 'event', 'business_hours', 'adhoc', 'continuous', 'unknown']);
    define('OPSH_IA_SOURCE_TYPES', ['db_process', 'db_digest', 'db_userstat', 'db_table_stats', 'http', 'cron', 'fabric', 'backup', 'opshub', 'other']);

    /** DDL for every BT16 table (additive; used by the migration). */
    function opsh_ia_schema(): array {
        $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            "CREATE TABLE IF NOT EXISTS opsh_integration_registry (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                type VARCHAR(40) NOT NULL DEFAULT 'custom',
                status VARCHAR(20) NOT NULL DEFAULT 'candidate',
                verification VARCHAR(20) NOT NULL DEFAULT 'unverified',
                owner VARCHAR(190) DEFAULT '',
                purpose TEXT DEFAULT NULL,
                source_system VARCHAR(190) DEFAULT '',
                destination_system VARCHAR(190) DEFAULT '',
                connection_method VARCHAR(120) DEFAULT 'unknown',
                auth_type VARCHAR(80) DEFAULT '',
                account_identifier VARCHAR(190) DEFAULT '',
                db_accounts TEXT DEFAULT NULL,
                source_hosts TEXT DEFAULT NULL,
                endpoints TEXT DEFAULT NULL,
                user_agents TEXT DEFAULT NULL,
                job_identifiers TEXT DEFAULT NULL,
                data_scope TEXT DEFAULT NULL,
                monitoring_method VARCHAR(190) DEFAULT '',
                opsh_managed TINYINT(1) NOT NULL DEFAULT 0,
                seed_key VARCHAR(60) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_by INT UNSIGNED DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_seed (seed_key), KEY idx_status (status), KEY idx_type (type)
            ) $t",
            "CREATE TABLE IF NOT EXISTS opsh_integration_schedules (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                integration_id INT UNSIGNED NOT NULL,
                schedule_type VARCHAR(20) NOT NULL DEFAULT 'unknown',
                cron_expr VARCHAR(120) DEFAULT '',
                window_start TIME DEFAULT NULL,
                window_end TIME DEFAULT NULL,
                days VARCHAR(20) DEFAULT '',
                interval_minutes INT UNSIGNED DEFAULT NULL,
                frequency_label VARCHAR(80) DEFAULT '',
                timezone VARCHAR(60) DEFAULT '',
                tolerance_minutes INT UNSIGNED NOT NULL DEFAULT 15,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                notes TEXT DEFAULT NULL,
                KEY idx_int (integration_id)
            ) $t",
            "CREATE TABLE IF NOT EXISTS opsh_integration_tables (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                integration_id INT UNSIGNED NOT NULL,
                schema_name VARCHAR(128) DEFAULT '',
                table_name VARCHAR(128) NOT NULL,
                access_type VARCHAR(20) NOT NULL DEFAULT 'unknown',
                expected TINYINT(1) NOT NULL DEFAULT 1,
                notes VARCHAR(500) DEFAULT '',
                KEY idx_int (integration_id), KEY idx_tbl (table_name)
            ) $t",
            "CREATE TABLE IF NOT EXISTS opsh_integration_evidence (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                evidence_type VARCHAR(40) NOT NULL DEFAULT 'other',
                title VARCHAR(190) NOT NULL DEFAULT '',
                source VARCHAR(190) DEFAULT '',
                captured_at DATETIME DEFAULT NULL,
                period_start DATETIME DEFAULT NULL,
                period_end DATETIME DEFAULT NULL,
                source_tz VARCHAR(60) DEFAULT '',
                file_name VARCHAR(255) DEFAULT '',
                file_path VARCHAR(255) DEFAULT '',
                file_size INT UNSIGNED DEFAULT 0,
                checksum CHAR(64) DEFAULT '',
                integration_id INT UNSIGNED DEFAULT NULL,
                parser VARCHAR(40) DEFAULT '',
                options TEXT DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'uploaded',
                rows_read INT UNSIGNED DEFAULT 0,
                rows_imported INT UNSIGNED DEFAULT 0,
                rows_rejected INT UNSIGNED DEFAULT 0,
                error_summary TEXT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_by INT UNSIGNED DEFAULT NULL,
                imported_by INT UNSIGNED DEFAULT NULL,
                imported_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_status (status), KEY idx_period (period_start, period_end), KEY idx_checksum (checksum)
            ) $t",
            "CREATE TABLE IF NOT EXISTS opsh_integration_observations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                observed_at DATETIME(3) NOT NULL,
                ended_at DATETIME(3) DEFAULT NULL,
                source_ts_raw VARCHAR(64) DEFAULT '',
                source_tz VARCHAR(60) DEFAULT '',
                source_type VARCHAR(30) NOT NULL,
                source_ref VARCHAR(190) DEFAULT '',
                evidence_id INT UNSIGNED DEFAULT NULL,
                collector_run_id BIGINT UNSIGNED DEFAULT NULL,
                integration_id INT UNSIGNED DEFAULT NULL,
                attribution VARCHAR(12) NOT NULL DEFAULT 'unknown',
                attribution_basis VARCHAR(255) DEFAULT '',
                conformance VARCHAR(14) NOT NULL DEFAULT 'not_evaluated',
                conformance_reason VARCHAR(255) DEFAULT '',
                confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
                account VARCHAR(190) DEFAULT '',
                host VARCHAR(190) DEFAULT '',
                db_name VARCHAR(128) DEFAULT '',
                endpoint VARCHAR(500) DEFAULT '',
                method VARCHAR(10) DEFAULT '',
                status_code VARCHAR(40) DEFAULT '',
                user_agent VARCHAR(255) DEFAULT '',
                query_digest VARCHAR(500) DEFAULT '',
                fingerprint CHAR(40) NOT NULL DEFAULT '',
                duration_ms BIGINT DEFAULT NULL,
                rows_examined BIGINT DEFAULT NULL,
                rows_sent BIGINT DEFAULT NULL,
                rows_affected BIGINT DEFAULT NULL,
                bytes BIGINT DEFAULT NULL,
                meta TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_obs (observed_at), KEY idx_src_obs (source_type, observed_at), KEY idx_int_obs (integration_id, observed_at),
                KEY idx_fp (fingerprint), KEY idx_ev (evidence_id), KEY idx_attr (attribution, observed_at)
            ) $t",
            "CREATE TABLE IF NOT EXISTS opsh_integration_unknowns (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                fingerprint CHAR(40) NOT NULL,
                source_type VARCHAR(30) NOT NULL DEFAULT 'other',
                label VARCHAR(255) DEFAULT '',
                account VARCHAR(190) DEFAULT '',
                host VARCHAR(190) DEFAULT '',
                target VARCHAR(500) DEFAULT '',
                first_seen DATETIME(3) DEFAULT NULL,
                last_seen DATETIME(3) DEFAULT NULL,
                occurrences INT UNSIGNED NOT NULL DEFAULT 0,
                status VARCHAR(16) NOT NULL DEFAULT 'open',
                assigned_to INT UNSIGNED DEFAULT NULL,
                investigation_notes TEXT DEFAULT NULL,
                resolved_integration_id INT UNSIGNED DEFAULT NULL,
                resolution VARCHAR(500) DEFAULT '',
                resolved_by INT UNSIGNED DEFAULT NULL,
                resolved_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_fp (fingerprint), KEY idx_status (status, last_seen)
            ) $t",
            "CREATE TABLE IF NOT EXISTS opsh_integration_findings (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(190) NOT NULL,
                window_start DATETIME DEFAULT NULL,
                window_end DATETIME DEFAULT NULL,
                integration_id INT UNSIGNED DEFAULT NULL,
                attribution VARCHAR(12) NOT NULL DEFAULT 'investigate',
                identifying_evidence TEXT DEFAULT NULL,
                assessment TEXT DEFAULT NULL,
                confidence VARCHAR(8) NOT NULL DEFAULT 'low',
                observation_ids TEXT DEFAULT NULL,
                evidence_ids TEXT DEFAULT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'draft',
                created_by INT UNSIGNED DEFAULT NULL,
                reviewer INT UNSIGNED DEFAULT NULL,
                reviewed_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_win (window_start), KEY idx_status (status)
            ) $t",
            "CREATE TABLE IF NOT EXISTS opsh_collector_runs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                collector VARCHAR(40) NOT NULL,
                run_mode VARCHAR(12) NOT NULL DEFAULT 'manual',
                started_at DATETIME(3) NOT NULL,
                ended_at DATETIME(3) DEFAULT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'running',
                records_seen INT UNSIGNED DEFAULT 0,
                records_imported INT UNSIGNED DEFAULT 0,
                records_skipped INT UNSIGNED DEFAULT 0,
                duration_ms INT UNSIGNED DEFAULT 0,
                error_summary VARCHAR(500) DEFAULT '',
                evidence_id INT UNSIGNED DEFAULT NULL,
                triggered_by INT UNSIGNED DEFAULT NULL,
                KEY idx_col (collector, started_at), KEY idx_started (started_at)
            ) $t",
            "CREATE TABLE IF NOT EXISTS opsh_server_metric_samples (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ts DATETIME NOT NULL,
                metric VARCHAR(40) NOT NULL,
                value DOUBLE NOT NULL,
                unit VARCHAR(16) DEFAULT '',
                source VARCHAR(30) NOT NULL DEFAULT 'import',
                host VARCHAR(120) NOT NULL DEFAULT '',
                interval_sec INT UNSIGNED DEFAULT NULL,
                evidence_id INT UNSIGNED DEFAULT NULL,
                collector_run_id BIGINT UNSIGNED DEFAULT NULL,
                UNIQUE KEY uq_sample (source, host, metric, ts), KEY idx_ts (ts), KEY idx_metric_ts (metric, ts), KEY idx_ev (evidence_id)
            ) $t",
            "CREATE TABLE IF NOT EXISTS opsh_integration_obs_rollup (
                hour DATETIME NOT NULL,
                source_type VARCHAR(30) NOT NULL,
                integration_id INT UNSIGNED NOT NULL DEFAULT 0,
                fingerprint CHAR(40) NOT NULL,
                attribution VARCHAR(12) NOT NULL DEFAULT 'unknown',
                occurrences INT UNSIGNED NOT NULL DEFAULT 0,
                sum_duration_ms BIGINT DEFAULT 0,
                max_duration_ms BIGINT DEFAULT 0,
                sum_rows_examined BIGINT DEFAULT 0,
                sum_bytes BIGINT DEFAULT 0,
                PRIMARY KEY (hour, source_type, integration_id, fingerprint)
            ) $t",
        ];
    }

    /** Table names, newest-dependency first — used for the rollback list in the release notes and tests. */
    function opsh_ia_tables(): array {
        return ['opsh_integration_obs_rollup', 'opsh_server_metric_samples', 'opsh_collector_runs', 'opsh_integration_findings',
                'opsh_integration_unknowns', 'opsh_integration_observations', 'opsh_integration_evidence', 'opsh_integration_tables',
                'opsh_integration_schedules', 'opsh_integration_registry'];
    }

    /* ─────────────────────────── settings ─────────────────────────── */

    function opsh_ia_setting(PDO $db, string $k, $default = null) {
        $s = $db->prepare('SELECT sval FROM opsh_settings WHERE skey=?'); $s->execute([$k]);
        $v = $s->fetchColumn(); if ($v === false || $v === null) return $default;
        $j = json_decode((string)$v, true); return is_array($j) ? $j : $default;
    }
    function opsh_ia_setting_save(PDO $db, string $k, array $v, ?int $uid = null): void {
        $db->prepare('INSERT INTO opsh_settings (skey,sval,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval),updated_by=VALUES(updated_by)')
           ->execute([$k, json_encode($v), $uid]);
    }

    /** Monitoring periods. Intensive = night, everything else = day. Configurable; defaults 00:00–07:00 / 07:00–24:00. */
    function opsh_ia_windows(PDO $db): array {
        $w = opsh_ia_setting($db, 'ia_windows', []);
        return ['night_start' => $w['night_start'] ?? '00:00', 'night_end' => $w['night_end'] ?? '07:00',
                'correlation_minutes' => max(1, min(240, (int)($w['correlation_minutes'] ?? 10)))];
    }

    /** Is a LOCAL time (HH:MM) inside [start, end)? Handles windows that cross midnight; end '24:00' allowed. */
    function opsh_ia_in_window(string $hm, string $start, string $end): bool {
        $m = fn($s) => ((int)substr($s, 0, 2)) * 60 + (int)substr($s, 3, 2);
        $t = $m($hm); $a = $m($start); $b = $m($end);
        if ($a === $b) return true;                       // 24-hour window
        return $a < $b ? ($t >= $a && $t < $b) : ($t >= $a || $t < $b);
    }

    /* ─────────────────────────── time ─────────────────────────── */

    function opsh_ia_server_tz(): string { return date_default_timezone_get() ?: 'UTC'; }

    function opsh_ia_valid_tz(string $tz): string {
        $tz = trim($tz); if ($tz === '') return opsh_ia_server_tz();
        try { new DateTimeZone($tz); return $tz; } catch (\Throwable $e) { return opsh_ia_server_tz(); }
    }

    /**
     * Parse a source timestamp into UTC 'Y-m-d H:i:s.v'. An explicit offset/Z in the value wins over $tz.
     * $fmt may be a DateTime format ('d/M/Y:H:i:s O' for Apache). Returns null when unparseable.
     */
    function opsh_ia_to_utc(string $raw, string $tz, ?string $fmt = null): ?string {
        $raw = trim($raw); if ($raw === '' || strlen($raw) > 64) return null;
        $zone = new DateTimeZone(opsh_ia_valid_tz($tz));
        try {
            if ($fmt) { $d = DateTime::createFromFormat($fmt, $raw, $zone); if (!$d) return null; }
            elseif (preg_match('/^\d{10}(\.\d+)?$/', $raw)) { $d = DateTime::createFromFormat('U.u', sprintf('%.6F', (float)$raw)); }
            elseif (preg_match('/^\d{13}$/', $raw)) { $d = DateTime::createFromFormat('U.u', sprintf('%.6F', ((int)$raw) / 1000)); }
            else { $d = new DateTime($raw, $zone); }
        } catch (\Throwable $e) { return null; }
        $y = (int)$d->format('Y'); if ($y < 2000 || $y > 2100) return null;
        $d->setTimezone(new DateTimeZone('UTC'));
        return $d->format('Y-m-d H:i:s.v');
    }

    /** UTC 'Y-m-d H:i:s' → DateTime in $tz. */
    function opsh_ia_local(string $utc, string $tz): DateTime {
        $d = new DateTime($utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone(opsh_ia_valid_tz($tz))); return $d;
    }

    /**
     * Resolve a range request to UTC [start, end). Presets are computed in the DISPLAY timezone (server time),
     * so "previous night" is correct across DST changes. Custom ranges are capped at OPSH_IA_MAX_RANGE_DAYS.
     */
    function opsh_ia_range(array $q, PDO $db): array {
        $tz = new DateTimeZone(opsh_ia_server_tz()); $W = opsh_ia_windows($db);
        $preset = (string)($q['preset'] ?? '');
        $now = new DateTime('now', $tz);
        if ($preset === 'night' || $preset === 'day') {
            $base = new DateTime(($q['date'] ?? '') !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$q['date']) ? (string)$q['date'] : 'today', $tz);
            // Review OPS-22: after midnight, inside a night that started yesterday, "night" is the night that is running now
            if (($q['date'] ?? '') === '' && $preset === 'night' && $W['night_start'] > $W['night_end'] && $now->format('H:i') < $W['night_end']) $base->modify('-1 day');
            $s = (clone $base)->setTime((int)substr($W['night_start'], 0, 2), (int)substr($W['night_start'], 3, 2));
            $e = (clone $base)->setTime((int)substr($W['night_end'], 0, 2), (int)substr($W['night_end'], 3, 2));
            if ($preset === 'day') { $s = $e; $e = (clone $base)->modify('+1 day')->setTime(0, 0); }
            if ($e <= $s) $e->modify('+1 day');
        } elseif ($preset === 'today') {
            $s = (clone $now)->setTime(0, 0); $e = (clone $s)->modify('+1 day');
        } elseif ($preset === 'custom' || (!empty($q['start']) && !empty($q['end']))) {
            try { $s = new DateTime((string)$q['start'], $tz); $e = new DateTime((string)$q['end'], $tz); }
            catch (\Throwable $ex) { error_response('Invalid start / end'); }
            if ($e <= $s) error_response('The end must be after the start');
            if ($e->getTimestamp() - $s->getTimestamp() > OPSH_IA_MAX_RANGE_DAYS * 86400) error_response('Choose a range of ' . OPSH_IA_MAX_RANGE_DAYS . ' days or less');
        } else {                                               // default: last 24 hours
            $e = clone $now; $s = (clone $now)->modify('-24 hours');
        }
        $u = new DateTimeZone('UTC');
        return ['start' => (clone $s)->setTimezone($u)->format('Y-m-d H:i:s'), 'end' => (clone $e)->setTimezone($u)->format('Y-m-d H:i:s'),
                'display_tz' => $tz->getName(), 'start_local' => $s->format('Y-m-d g:i A'), 'end_local' => $e->format('Y-m-d g:i A')];
    }

    /* ─────────────────────────── fingerprints ─────────────────────────── */

    /** Normalised SQL digest: comments stripped, literals → ?, IN-lists collapsed, whitespace squeezed, 500 chars max. */
    function opsh_ia_sql_digest(string $sql): string {
        $s = trim($sql); if ($s === '') return '';
        $s = preg_replace('~/\*.*?\*/~s', ' ', $s);
        $s = preg_replace('~(--|#)[^\n]*~', ' ', $s);
        $s = preg_replace("~'(?:[^'\\\\]|\\\\.|'')*'~s", '?', $s);
        $s = preg_replace('~"(?:[^"\\\\]|\\\\.)*"~s', '?', $s);
        $s = preg_replace('~\b0x[0-9a-f]+\b~i', '?', $s);
        $s = preg_replace('~(?<![\w.`])-?\d+(\.\d+)?(e[+-]?\d+)?\b~i', '?', $s);
        $s = preg_replace('~\(\s*\?(\s*,\s*\?)+\s*\)~', '(?+)', $s);
        $s = preg_replace('~\s+~', ' ', $s);
        return mb_substr(trim($s), 0, 500);
    }

    /** Tables named in a digest (FROM / JOIN / INTO / UPDATE), lower-case, max 20. */
    function opsh_ia_sql_tables(string $digest): array {
        preg_match_all('~\b(?:from|join|into|update)\s+`?([a-z0-9_$]+)`?(?:\s*\.\s*`?([a-z0-9_$]+)`?)?~i', $digest, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $x) { $t = strtolower(!empty($x[2]) ? $x[2] : $x[1]); if (!in_array($t, ['select', 'dual'], true)) $out[$t] = true; }
        return array_slice(array_keys($out), 0, 20);
    }

    /** URL path with query VALUES removed (keys kept, sorted) and ids replaced — so one endpoint = one fingerprint. */
    function opsh_ia_path_template(string $url): string {
        $p = parse_url($url); $path = (string)($p['path'] ?? $url);
        $segs = array_map(function ($s) {
            if ($s === '') return $s;
            if (preg_match('/^\d+$/', $s) || preg_match('/^[0-9a-f]{16,}$/i', $s) || preg_match('/^[0-9a-f-]{36}$/i', $s)) return ':id';
            return $s;
        }, explode('/', $path));
        $out = implode('/', $segs);
        if (!empty($p['query'])) { parse_str($p['query'], $qa); $keys = array_keys($qa); sort($keys); if ($keys) $out .= '?' . implode('&', array_slice($keys, 0, 12)); }
        return mb_substr($out, 0, 500);
    }

    function opsh_ia_host_only(string $h): string {
        $h = trim($h);
        if (preg_match('/^\[(.+)\](:\d+)?$/', $h, $m)) return $m[1];
        if (substr_count($h, ':') === 1) return explode(':', $h)[0];
        return $h;
    }

    function opsh_ia_fp(array $parts): string { return sha1(implode('|', array_map(fn($x) => strtolower(trim((string)$x)), $parts))); }

    /** Remove secrets from free text (cron commands, URLs): tokens, passwords, keys. */
    function opsh_ia_redact(string $s): string { return mb_substr(opsh_ia_redact_text($s), 0, 1000); }

    /** Same masking over a whole evidence file (no length cap) — applied before the file is stored or parsed. */
    function opsh_ia_redact_text(string $s): string {
        $s = preg_replace('~((?:token|key|secret|pass(?:word)?|pwd|auth|signature|sig|apikey|access_key)=)[^&\s"\']+~i', '$1***', $s);
        $s = preg_replace('~(--password=)\S+~i', '$1***', $s);
        $s = preg_replace('~(?<=\s)(-p)(?![\s-])\S+~', '$1***', $s);                         // mysql -pSECRET
        $s = preg_replace('~(Authorization:\s*\w+\s+)[^\s"\']+~i', '$1***', $s);
        $s = preg_replace('~(X-OpsHub-Cron-Token:\s*)[^\s"\']+~i', '$1***', $s);
        $s = preg_replace('~(https?://)[^/\s:@]+:[^/\s@]+@~i', '$1***:***@', $s);
        // Review OPS-30: "--password secret", JSON "password": "…", "Bearer …" anywhere, X-*-Key / X-*-Token headers
        $s = preg_replace('~(--(?:password|pass|token|secret|api-key)\s+)\S+~i', '$1***', $s);
        $s = preg_replace('~("(?:password|passwd|pwd|secret|token|api_?key|access_?key|client_?secret|refresh_?token)"\s*:\s*")[^"]*(")~i', '$1***$2', $s);
        $s = preg_replace('~\b(Bearer\s+)[A-Za-z0-9\-\._\+/]+=*~', '$1***', $s);
        $s = preg_replace('~(X-[A-Za-z0-9-]*(?:Key|Token|Secret)[A-Za-z0-9-]*:\s*)[^\s"\']+~i', '$1***', $s);
        return (string)$s;
    }

    /* ─────────────────────────── registry ─────────────────────────── */

    function opsh_ia_list(?string $json): array {
        if ($json === null || $json === '') return [];
        $a = json_decode($json, true); if (!is_array($a)) return [];
        return array_values(array_filter(array_map(fn($x) => trim((string)$x), $a), fn($x) => $x !== ''));
    }

    /** Registry with decoded identifier lists and schedules — the input of attribution/conformance. */
    function opsh_ia_registry(PDO $db, bool $includeRetired = false): array {
        $rows = $db->query('SELECT * FROM opsh_integration_registry' . ($includeRetired ? '' : " WHERE status<>'retired'") . ' ORDER BY name')->fetchAll();
        $sch = [];
        foreach ($db->query('SELECT * FROM opsh_integration_schedules WHERE enabled=1')->fetchAll() as $s) $sch[(int)$s['integration_id']][] = $s;
        $out = [];
        foreach ($rows as $r) {
            foreach (['db_accounts', 'source_hosts', 'endpoints', 'user_agents', 'job_identifiers'] as $k) $r[$k] = opsh_ia_list($r[$k]);
            $r['schedules'] = $sch[(int)$r['id']] ?? [];
            $out[(int)$r['id']] = $r;
        }
        return $out;
    }

    /** Glob match (* and ?) case-insensitive. */
    function opsh_ia_glob(string $pattern, string $value): bool {
        if ($pattern === '' || $value === '') return false;
        $re = '~^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '~')) . '$~i';
        return (bool)preg_match($re, $value);
    }

    /** Host / IP / CIDR / *.domain match. */
    function opsh_ia_host_match(string $pattern, string $host): bool {
        $pattern = strtolower(trim($pattern)); $host = strtolower(opsh_ia_host_only($host));
        if ($pattern === '' || $host === '') return false;
        if (strpos($pattern, '/') !== false && filter_var($host, FILTER_VALIDATE_IP)) {
            [$net, $bits] = explode('/', $pattern, 2); $bits = (int)$bits;
            $h = @inet_pton($host); $n = @inet_pton($net);
            if ($h === false || $n === false || strlen($h) !== strlen($n)) return false;
            $bytes = intdiv($bits, 8); $rem = $bits % 8;
            if (substr($h, 0, $bytes) !== substr($n, 0, $bytes)) return false;
            if ($rem === 0) return true;
            $mask = chr((0xFF << (8 - $rem)) & 0xFF);
            return (ord($h[$bytes]) & ord($mask)) === (ord($n[$bytes]) & ord($mask));
        }
        if (strpos($pattern, '*') !== false) return opsh_ia_glob($pattern, $host);
        return $pattern === $host;
    }

    /** Account names compare without the @host part (MariaDB shows user@host). */
    function opsh_ia_account_only(string $a): string { $a = trim($a, " `'\""); $p = strpos($a, '@'); return strtolower($p === false ? $a : substr($a, 0, $p)); }

    /**
     * WHO — attribution from IDENTIFIERS only (never time).
     * Strong: documented DB account · documented source host/IP/CIDR · job identifier · evidence provenance
     *         (the file IS that integration's own execution history).
     * Medium: endpoint pattern · user-agent pattern.
     * One integration with a strong hit → confirmed. Only medium hits on one integration → probable.
     * Hits on more than one integration → investigate. Nothing → unknown.
     */
    function opsh_ia_attribute(array $o, array $registry, ?int $provenanceId = null): array {
        $acct = opsh_ia_account_only((string)($o['account'] ?? ''));
        $host = (string)($o['host'] ?? '');
        $end = (string)($o['endpoint'] ?? '');
        $ua = (string)($o['user_agent'] ?? '');
        // Job identifiers identify a JOB (cron command, Fabric item, backup job) — they are only matched against job
        // records, never against arbitrary URLs (a URL containing "backup" is not the backup job).
        $jobText = in_array($o['source_type'] ?? '', ['cron', 'fabric', 'backup', 'other'], true)
            ? strtolower(($o['source_ref'] ?? '') . ' ' . ($o['_job_text'] ?? '') . ' ' . $end) : '';
        $strong = []; $medium = [];
        if ($provenanceId && isset($registry[$provenanceId])) $strong[$provenanceId][] = 'evidence provenance (this file is the integration\'s own history)';
        foreach ($registry as $id => $r) {
            if ($acct !== '') foreach ($r['db_accounts'] as $a) if (opsh_ia_account_only($a) === $acct) { $strong[$id][] = 'DB account ' . $acct; break; }
            if ($host !== '') foreach ($r['source_hosts'] as $h) if (opsh_ia_host_match($h, $host)) { $strong[$id][] = 'source host ' . opsh_ia_host_only($host); break; }
            if (trim($jobText) !== '') foreach ($r['job_identifiers'] as $j) if ($j !== '' && strpos($jobText, strtolower($j)) !== false) { $strong[$id][] = 'job identifier ' . $j; break; }
            if ($end !== '') foreach ($r['endpoints'] as $p) if (opsh_ia_glob($p, $end)) { $medium[$id][] = 'endpoint ' . $p; break; }
            if ($ua !== '') foreach ($r['user_agents'] as $p) if (stripos($ua, $p) !== false || opsh_ia_glob($p, $ua)) { $medium[$id][] = 'user agent ' . $p; break; }
        }
        if (count($strong) === 1) {
            $id = (int)array_key_first($strong); $why = array_merge($strong[$id], $medium[$id] ?? []);
            return [$id, 'confirmed', mb_substr(implode('; ', $why), 0, 255), min(99, 80 + 5 * count($why)), $strong[$id]];
        }
        if (count($strong) > 1) {
            $why = []; foreach ($strong as $id => $w) $why[] = '#' . $id . ': ' . implode(', ', $w);
            return [null, 'investigate', mb_substr('identifiers match more than one integration — ' . implode(' | ', $why), 0, 255), 30, []];
        }
        if (count($medium) === 1) {
            $id = (int)array_key_first($medium);
            return [$id, 'probable', mb_substr(implode('; ', $medium[$id]) . ' (no account/host/job identifier)', 0, 255), 55, []];
        }
        if (count($medium) > 1) {
            $why = []; foreach ($medium as $id => $w) $why[] = '#' . $id . ': ' . implode(', ', $w);
            return [null, 'investigate', mb_substr('patterns match more than one integration — ' . implode(' | ', $why), 0, 255), 25, []];
        }
        return [null, 'unknown', '', 0, []];
    }

    /* ─────────────────────────── schedules / conformance ─────────────────────────── */

    /** 5-field cron: numbers, *, lists, ranges, steps; names for month/day; @hourly/@daily/@weekly/@monthly/@yearly. */
    function opsh_ia_cron_parse(string $expr): ?array {
        $expr = strtolower(trim($expr));
        $alias = ['@yearly' => '0 0 1 1 *', '@annually' => '0 0 1 1 *', '@monthly' => '0 0 1 * *', '@weekly' => '0 0 * * 0', '@daily' => '0 0 * * *', '@midnight' => '0 0 * * *', '@hourly' => '0 * * * *'];
        if (isset($alias[$expr])) $expr = $alias[$expr];
        $f = preg_split('/\s+/', $expr); if (count($f) !== 5) return null;
        $names = [3 => ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12],
                  4 => ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6]];
        $lim = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
        $out = [];
        foreach ($f as $i => $field) {
            if (isset($names[$i])) $field = strtr($field, $names[$i]);
            $set = [];
            foreach (explode(',', $field) as $part) {
                if (!preg_match('~^(\*|\d+(?:-\d+)?)(?:/(\d+))?$~', $part, $m)) return null;
                $step = isset($m[2]) ? max(1, (int)$m[2]) : 1;
                if ($m[1] === '*') { [$a, $b] = $lim[$i]; }
                elseif (strpos($m[1], '-') !== false) { [$a, $b] = array_map('intval', explode('-', $m[1])); }
                else { $a = (int)$m[1]; $b = isset($m[2]) ? $lim[$i][1] : $a; }
                if ($a < $lim[$i][0] || $b > $lim[$i][1] || $a > $b) return null;
                for ($v = $a; $v <= $b; $v += $step) $set[$i === 4 && $v === 7 ? 0 : $v] = true;
            }
            $out[] = $set;
        }
        $out[] = ['dom_star' => trim($f[2]) === '*', 'dow_star' => trim($f[4]) === '*'];
        return $out;
    }

    function opsh_ia_cron_matches(array $c, DateTime $t): bool {
        if (!isset($c[0][(int)$t->format('i')], $c[1][(int)$t->format('G')], $c[3][(int)$t->format('n')])) return false;
        $dom = isset($c[2][(int)$t->format('j')]); $dow = isset($c[4][(int)$t->format('w')]);
        $f = $c[5];
        if ($f['dom_star'] && $f['dow_star']) return true;
        if ($f['dom_star']) return $dow;
        if ($f['dow_star']) return $dom;
        return $dom || $dow;                                   // classic cron: either field matches
    }

    function opsh_ia_day_ok(string $days, DateTime $t): bool {
        $days = trim($days); if ($days === '' || $days === '*') return true;
        return in_array((int)$t->format('N'), array_map('intval', explode(',', $days)), true);   // ISO 1=Mon … 7=Sun
    }

    /**
     * Evaluate one schedule against an observation time (UTC). Returns 'expected' | 'outside' | 'not_evaluated'.
     * Event-driven, ad hoc, continuous and interval activity is NOT judged by the clock unless a window is set.
     */
    function opsh_ia_schedule_eval(array $s, string $utc): string {
        $type = (string)($s['schedule_type'] ?? 'unknown');
        if ($type === 'unknown') return 'not_evaluated';
        $t = opsh_ia_local($utc, (string)($s['timezone'] ?? ''));
        $hasWin = !empty($s['window_start']) && !empty($s['window_end']);
        $inWin = fn() => opsh_ia_in_window($t->format('H:i'), substr((string)$s['window_start'], 0, 5), substr((string)$s['window_end'], 0, 5) === '00:00' ? '24:00' : substr((string)$s['window_end'], 0, 5));
        switch ($type) {
            case 'continuous': return 'expected';
            case 'event': case 'adhoc': case 'interval':
                if (!$hasWin && trim((string)($s['days'] ?? '')) === '') return 'expected';
                return (opsh_ia_day_ok((string)$s['days'], $t) && (!$hasWin || $inWin())) ? 'expected' : 'outside';
            case 'window':
                if (!$hasWin) return 'not_evaluated';
                return (opsh_ia_day_ok((string)$s['days'], $t) && $inWin()) ? 'expected' : 'outside';
            case 'business_hours':
                $days = trim((string)($s['days'] ?? '')) !== '' ? (string)$s['days'] : '1,2,3,4,5';
                $ws = $hasWin ? substr((string)$s['window_start'], 0, 5) : '08:00'; $we = $hasWin ? substr((string)$s['window_end'], 0, 5) : '18:00';
                return (opsh_ia_day_ok($days, $t) && opsh_ia_in_window($t->format('H:i'), $ws, $we)) ? 'expected' : 'outside';
            case 'cron':
                $c = opsh_ia_cron_parse((string)($s['cron_expr'] ?? '')); if (!$c) return 'not_evaluated';
                $tol = max(0, min(180, (int)($s['tolerance_minutes'] ?? 15)));
                $probe = (clone $t)->setTime((int)$t->format('G'), (int)$t->format('i'));
                for ($i = 0; $i <= $tol; $i++) { if (opsh_ia_cron_matches($c, $probe)) return 'expected'; $probe->modify('-1 minute'); }
                return 'outside';
        }
        return 'not_evaluated';
    }

    /**
     * Did an attributed observation happen as documented? Checks identity scope, then schedules.
     * Unexpected only when there is something to compare against — an integration with no (evaluable)
     * schedule is never "unexpected" because of timing.
     */
    function opsh_ia_conformance(array $o, ?array $int): array {
        if (!$int) return ['not_evaluated', ''];
        $acct = opsh_ia_account_only((string)($o['account'] ?? ''));
        $host = (string)($o['host'] ?? '');
        if ($acct !== '' && $int['db_accounts'] && !in_array($acct, array_map('opsh_ia_account_only', $int['db_accounts']), true))
            return ['unexpected', 'DB account "' . $acct . '" is not documented for this integration'];
        if ($host !== '' && $int['source_hosts'] && !array_filter($int['source_hosts'], fn($h) => opsh_ia_host_match($h, $host)))
            return ['unexpected', 'source host ' . opsh_ia_host_only($host) . ' is not documented for this integration'];
        $evaluable = false; $matched = '';
        foreach ($int['schedules'] as $s) {
            $r = opsh_ia_schedule_eval($s, (string)$o['observed_at']);
            if ($r === 'expected') { $matched = $s['schedule_type'] . ($s['frequency_label'] ? ' · ' . $s['frequency_label'] : ''); break; }
            if ($r === 'outside') $evaluable = true;
        }
        if ($matched !== '') return ['expected', 'within documented schedule (' . $matched . ')'];
        if ($evaluable) return ['unexpected', 'outside every documented schedule'];
        return ['not_evaluated', $int['schedules'] ? 'schedule unknown / not time-bound' : 'no schedule documented'];
    }

    /** Attribution + conformance for one normalised observation (in place). Reviewer decisions are never overwritten. */
    function opsh_ia_classify(array &$o, array $registry, ?int $provenanceId = null): void {
        if (strpos((string)($o['attribution_basis'] ?? ''), 'reviewer:') === 0) return;
        [$id, $attr, $basis, $conf] = opsh_ia_attribute($o, $registry, $provenanceId);
        $o['integration_id'] = $id; $o['attribution'] = $attr; $o['attribution_basis'] = $basis; $o['confidence'] = $conf;
        [$c, $why] = opsh_ia_conformance($o, $id ? ($registry[$id] ?? null) : null);
        $o['conformance'] = $c; $o['conformance_reason'] = mb_substr($why, 0, 255);
    }

    /* ─────────────────────────── writing observations ─────────────────────────── */

    define('OPSH_IA_OBS_COLS', ['observed_at', 'ended_at', 'source_ts_raw', 'source_tz', 'source_type', 'source_ref', 'evidence_id', 'collector_run_id',
        'integration_id', 'attribution', 'attribution_basis', 'conformance', 'conformance_reason', 'confidence', 'account', 'host', 'db_name',
        'endpoint', 'method', 'status_code', 'user_agent', 'query_digest', 'fingerprint', 'duration_ms', 'rows_examined', 'rows_sent',
        'rows_affected', 'bytes', 'meta']);

    /** Bounded multi-row insert. Returns rows written. Unknown attribution feeds the triage queue (if $queue). */
    function opsh_ia_insert_observations(PDO $db, array $rows, bool $queueUnknowns = true): int {
        $n = 0; $lens = ['source_ts_raw' => 64, 'source_tz' => 60, 'source_ref' => 190, 'attribution_basis' => 255, 'conformance_reason' => 255,
            'account' => 190, 'host' => 190, 'db_name' => 128, 'endpoint' => 500, 'method' => 10, 'status_code' => 40, 'user_agent' => 255, 'query_digest' => 500];
        foreach (array_chunk($rows, 400) as $chunk) {
            $ph = []; $args = [];
            foreach ($chunk as $o) {
                $ph[] = '(' . implode(',', array_fill(0, count(OPSH_IA_OBS_COLS), '?')) . ')';
                foreach (OPSH_IA_OBS_COLS as $c) {
                    $v = $o[$c] ?? null;
                    if ($c === 'meta') $v = $v ? mb_substr(json_encode($v, JSON_UNESCAPED_SLASHES), 0, 4000) : null;
                    elseif (isset($lens[$c])) $v = mb_substr((string)($v ?? ''), 0, $lens[$c]);
                    elseif (in_array($c, ['attribution'], true)) $v = in_array($v, OPSH_IA_ATTR, true) ? $v : 'unknown';
                    elseif (in_array($c, ['conformance'], true)) $v = in_array($v, OPSH_IA_CONF, true) ? $v : 'not_evaluated';
                    elseif ($c === 'confidence') $v = max(0, min(100, (int)$v));
                    elseif ($c === 'fingerprint') $v = (string)($v ?? '');
                    $args[] = $v;
                }
            }
            $db->prepare('INSERT INTO opsh_integration_observations (' . implode(',', OPSH_IA_OBS_COLS) . ') VALUES ' . implode(',', $ph))->execute($args);
            $n += count($chunk);
        }
        if ($queueUnknowns) opsh_ia_queue_unknowns($db, $rows);
        return $n;
    }

    /** Aggregate unknown observations into the triage queue by fingerprint (first/last seen, occurrences). */
    function opsh_ia_queue_unknowns(PDO $db, array $rows): void {
        $agg = [];
        foreach ($rows as $o) {
            if (($o['attribution'] ?? '') !== 'unknown' && ($o['attribution'] ?? '') !== 'investigate') continue;
            if (!empty($o['_no_queue'])) continue;
            $fp = (string)($o['fingerprint'] ?? ''); if ($fp === '') continue;
            $a = $agg[$fp] ?? ['n' => 0, 'first' => $o['observed_at'], 'last' => $o['observed_at'], 'o' => $o];
            $a['n']++; if ($o['observed_at'] < $a['first']) $a['first'] = $o['observed_at']; if ($o['observed_at'] > $a['last']) $a['last'] = $o['observed_at'];
            $agg[$fp] = $a;
        }
        $st = $db->prepare("INSERT INTO opsh_integration_unknowns (fingerprint,source_type,label,account,host,target,first_seen,last_seen,occurrences,status)
            VALUES (?,?,?,?,?,?,?,?,?,'open')
            ON DUPLICATE KEY UPDATE occurrences=occurrences+VALUES(occurrences), first_seen=LEAST(first_seen,VALUES(first_seen)), last_seen=GREATEST(last_seen,VALUES(last_seen)),
              status=IF(status='resolved' OR status='dismissed', status, status)");
        foreach ($agg as $fp => $a) {
            $o = $a['o'];
            $target = $o['endpoint'] ?: ($o['query_digest'] ?: ($o['db_name'] ?? ''));
            $label = opsh_ia_label($o);
            $st->execute([$fp, $o['source_type'], mb_substr($label, 0, 255), mb_substr((string)($o['account'] ?? ''), 0, 190), mb_substr(opsh_ia_host_only((string)($o['host'] ?? '')), 0, 190),
                          mb_substr((string)$target, 0, 500), $a['first'], $a['last'], $a['n']]);
        }
    }

    function opsh_ia_label(array $o): string {
        $bits = [];
        $st = ['db_process' => 'DB session', 'db_digest' => 'DB query digest', 'db_userstat' => 'DB account/client counters', 'http' => 'HTTP',
               'cron' => 'Cron entry', 'fabric' => 'Fabric run', 'backup' => 'Backup', 'opshub' => (function_exists('opsh_app_name') ? opsh_app_name() : 'OpsHub'), 'other' => 'Activity'][$o['source_type']] ?? $o['source_type'];
        $bits[] = $st;
        if (!empty($o['account'])) $bits[] = 'account ' . $o['account'];
        if (!empty($o['host'])) $bits[] = 'from ' . opsh_ia_host_only((string)$o['host']);
        if (!empty($o['method'])) $bits[] = $o['method'];
        if (!empty($o['endpoint'])) $bits[] = $o['endpoint'];
        elseif (!empty($o['query_digest'])) $bits[] = mb_substr($o['query_digest'], 0, 80);
        elseif (!empty($o['db_name'])) $bits[] = 'db ' . $o['db_name'];
        return implode(' · ', $bits);
    }

    /** Server metric samples (duplicates for the same source/host/metric/time are ignored). */
    function opsh_ia_insert_metrics(PDO $db, array $rows): int {
        $n = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            $ph = []; $args = [];
            foreach ($chunk as $m) { $ph[] = '(?,?,?,?,?,?,?,?,?)';
                array_push($args, $m['ts'], mb_substr($m['metric'], 0, 40), (float)$m['value'], mb_substr((string)($m['unit'] ?? ''), 0, 16), mb_substr((string)($m['source'] ?? 'import'), 0, 30),
                    mb_substr((string)($m['host'] ?? ''), 0, 120), $m['interval_sec'] ?? null, $m['evidence_id'] ?? null, $m['collector_run_id'] ?? null); }
            $st = $db->prepare('INSERT IGNORE INTO opsh_server_metric_samples (ts,metric,value,unit,source,host,interval_sec,evidence_id,collector_run_id) VALUES ' . implode(',', $ph));
            $st->execute($args); $n += $st->rowCount();
        }
        return $n;
    }

    /* ─────────────────────────── collector runs ─────────────────────────── */

    function opsh_ia_run_start(PDO $db, string $collector, string $mode, ?int $uid = null, ?int $evidenceId = null): int {
        $db->prepare('INSERT INTO opsh_collector_runs (collector,run_mode,started_at,status,triggered_by,evidence_id) VALUES (?,?,?,?,?,?)')
           ->execute([$collector, $mode, gmdate('Y-m-d H:i:s') . '.000', 'running', $uid, $evidenceId]);
        return (int)$db->lastInsertId();
    }
    function opsh_ia_run_end(PDO $db, int $runId, string $status, int $seen, int $imported, int $skipped, string $err, float $t0): void {
        $db->prepare('UPDATE opsh_collector_runs SET ended_at=?, status=?, records_seen=?, records_imported=?, records_skipped=?, duration_ms=?, error_summary=? WHERE id=?')
           ->execute([gmdate('Y-m-d H:i:s') . '.000', $status, $seen, $imported, $skipped, (int)round((microtime(true) - $t0) * 1000), mb_substr($err, 0, 500), $runId]);
    }

    /* ─────────────────────────── timeline / correlation (computed, never stored) ─────────────────────────── */

    /** Bucket size (seconds) for a range so the timeline stays ~150–300 points. */
    function opsh_ia_bucket(int $seconds): int {
        foreach ([60, 120, 300, 600, 900, 1800, 3600, 7200, 21600] as $b) if ($seconds / $b <= 300) return $b;
        return 86400;
    }

    /**
     * Timeline lanes for a range: observation counts per bucket by lane (source type or "unknown"), metric series,
     * expected-schedule projections and OpsHub's own logged activity. Everything is bounded.
     */
    function opsh_ia_timeline(PDO $db, string $s, string $e, array $f = []): array {
        $secs = strtotime($e . ' UTC') - strtotime($s . ' UTC'); $b = opsh_ia_bucket(max(60, $secs));
        $where = 'observed_at >= ? AND observed_at < ?'; $args = [$s, $e];
        if (!empty($f['integration_id'])) { $where .= ' AND integration_id=?'; $args[] = (int)$f['integration_id']; }
        // observed_at is UTC; bucket index = seconds since range start / bucket size
        $st = $db->prepare("SELECT FLOOR(TIMESTAMPDIFF(SECOND, ?, observed_at)/?) AS bk, source_type,
                (attribution IN ('unknown','investigate')) AS unk, SUM(conformance='unexpected') AS unexp, COUNT(*) AS n
            FROM opsh_integration_observations WHERE $where GROUP BY bk, source_type, unk LIMIT 20000");
        $st->execute(array_merge([$s, $b], $args));
        $lanes = [];
        foreach ($st->fetchAll() as $r) {
            $lane = (int)$r['unk'] ? 'unknown' : opsh_ia_lane((string)$r['source_type']);
            $k = (int)$r['bk'];
            $lanes[$lane][$k] = ($lanes[$lane][$k] ?? 0) + (int)$r['n'];
            if ((int)$r['unexp']) $lanes['unexpected'][$k] = ($lanes['unexpected'][$k] ?? 0) + (int)$r['unexp'];
        }
        // OpsHub native evidence (read-only, not copied): vTiger / integrations / cron channels of the event log
        try {
            $q = $db->prepare("SELECT FLOOR(TIMESTAMPDIFF(SECOND, ?, ts)/?) AS bk, COUNT(*) AS n FROM opsh_event_log
                WHERE ts >= ? AND ts < ? AND channel IN ('vtiger','integrations','cron','webhook','m365') GROUP BY bk LIMIT 5000");
            // event_log.ts is server-local; convert the UTC range to local for the query
            $ls = opsh_ia_local($s, '')->format('Y-m-d H:i:s'); $le = opsh_ia_local($e, '')->format('Y-m-d H:i:s');
            $q->execute([$ls, $b, $ls, $le]);
            foreach ($q->fetchAll() as $r) $lanes['opshub'][(int)$r['bk']] = ($lanes['opshub'][(int)$r['bk']] ?? 0) + (int)$r['n'];
        } catch (\Throwable $ex) {}
        // server metrics (avg per bucket) — bounded
        $metrics = [];
        $mq = $db->prepare("SELECT metric, FLOOR(TIMESTAMPDIFF(SECOND, ?, ts)/?) AS bk, AVG(value) AS v, MAX(value) AS mx, MAX(unit) AS unit
            FROM opsh_server_metric_samples WHERE ts >= ? AND ts < ? AND metric IN ('cpu_user','cpu_system','cpu_iowait','cpu_busy','load1','load5','mem_used_pct','runq')
            GROUP BY metric, bk LIMIT 20000");
        $mq->execute([$s, $b, $s, $e]);
        foreach ($mq->fetchAll() as $r) $metrics[$r['metric']][] = [(int)$r['bk'], round((float)$r['v'], 2), round((float)$r['mx'], 2)];
        // expected schedules projected onto the range (display only — they are expectations, not evidence)
        $proj = [];
        foreach (opsh_ia_registry($db) as $id => $int) foreach ($int['schedules'] as $sc) {
            $w = opsh_ia_project_schedule($sc, $s, $e, 200);
            if ($w) $proj[] = ['integration_id' => $id, 'name' => $int['name'], 'type' => $sc['schedule_type'], 'label' => $sc['frequency_label'], 'spans' => $w];
        }
        $toArr = function ($m) { ksort($m); $o = []; foreach ($m as $k => $v) $o[] = [$k, $v]; return $o; };
        $out = []; foreach ($lanes as $k => $v) $out[$k] = $toArr($v);
        return ['bucket_sec' => $b, 'start' => $s, 'end' => $e, 'lanes' => $out, 'metrics' => $metrics, 'expected' => $proj];
    }

    function opsh_ia_lane(string $sourceType): string {
        if (strpos($sourceType, 'db_') === 0) return 'db';
        return in_array($sourceType, ['http', 'cron', 'fabric', 'backup', 'opshub'], true) ? $sourceType : 'other';
    }

    /** Expected spans (UTC [start,end] pairs) of a schedule inside a range. Only time-bound types project. */
    function opsh_ia_project_schedule(array $sc, string $s, string $e, int $max): array {
        $type = $sc['schedule_type']; $tz = opsh_ia_valid_tz((string)$sc['timezone']);
        $spans = []; $u = new DateTimeZone('UTC');
        $start = opsh_ia_local($s, $tz); $end = opsh_ia_local($e, $tz);
        if (in_array($type, ['window', 'business_hours'], true) || (in_array($type, ['event', 'adhoc', 'interval'], true) && !empty($sc['window_start']))) {
            $ws = $sc['window_start'] ?: ($type === 'business_hours' ? '08:00' : null); $we = $sc['window_end'] ?: ($type === 'business_hours' ? '18:00' : null);
            if (!$ws || !$we) return [];
            $days = trim((string)$sc['days']) !== '' ? (string)$sc['days'] : ($type === 'business_hours' ? '1,2,3,4,5' : '');
            $d = (clone $start)->modify('-1 day')->setTime(0, 0);
            while ($d < $end && count($spans) < $max) {
                if (opsh_ia_day_ok($days, $d)) {
                    $a = (clone $d)->setTime((int)substr($ws, 0, 2), (int)substr($ws, 3, 2));
                    $b = (clone $d)->setTime((int)substr($we, 0, 2), (int)substr($we, 3, 2)); if ($b <= $a) $b->modify('+1 day');
                    if ($b > $start && $a < $end) $spans[] = [(clone max($a, $start))->setTimezone($u)->format('Y-m-d H:i:s'), (clone min($b, $end))->setTimezone($u)->format('Y-m-d H:i:s')];
                }
                $d->modify('+1 day');
            }
        } elseif ($type === 'cron') {
            $c = opsh_ia_cron_parse((string)$sc['cron_expr']); if (!$c) return [];
            $t = (clone $start)->setTime((int)$start->format('G'), (int)$start->format('i'));
            $guard = 0;
            while ($t < $end && count($spans) < $max && $guard++ < 50000) {
                if (opsh_ia_cron_matches($c, $t)) { $a = (clone $t)->setTimezone($u); $spans[] = [$a->format('Y-m-d H:i:s'), $a->format('Y-m-d H:i:s')]; }
                $t->modify('+1 minute');
            }
        }
        return $spans;
    }

    /**
     * Temporal correlation around a moment: what else was observed within ±N minutes, grouped by lane.
     * This is labelled "temporal correlation" everywhere — it never sets attribution.
     */
    function opsh_ia_correlate(PDO $db, string $atUtc, int $minutes, ?int $excludeId = null): array {
        $a = gmdate('Y-m-d H:i:s', strtotime($atUtc . ' UTC') - $minutes * 60); $b = gmdate('Y-m-d H:i:s', strtotime($atUtc . ' UTC') + $minutes * 60);
        $st = $db->prepare("SELECT o.id, o.observed_at, o.ended_at, o.source_type, o.integration_id, r.name AS integration, o.attribution, o.conformance,
                o.account, o.host, o.db_name, o.endpoint, o.method, o.status_code, o.query_digest, o.duration_ms, o.rows_examined
            FROM opsh_integration_observations o LEFT JOIN opsh_integration_registry r ON r.id=o.integration_id
            WHERE o.observed_at >= ? AND o.observed_at <= ? ORDER BY o.observed_at LIMIT 600");
        $st->execute([$a, $b]);
        $groups = [];
        foreach ($st->fetchAll() as $r) { if ($excludeId && (int)$r['id'] === $excludeId) continue; $groups[opsh_ia_lane($r['source_type'])][] = $r; }
        $mq = $db->prepare("SELECT metric, MIN(value) AS mn, MAX(value) AS mx, AVG(value) AS av, COUNT(*) AS n FROM opsh_server_metric_samples WHERE ts >= ? AND ts <= ? GROUP BY metric");
        $mq->execute([$a, $b]);
        return ['window_start' => $a, 'window_end' => $b, 'minutes' => $minutes, 'groups' => $groups, 'metrics' => $mq->fetchAll(),
                'notice' => 'Temporal correlation only: these things happened close together in time. That is not evidence of who caused what. Attribution needs an identifier (DB account, source host, job id or the integration\'s own history).'];
    }
}
