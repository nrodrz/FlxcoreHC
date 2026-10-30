<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT16 Integration Audit & Observability (core engine).
 *
 * ITOps is the system of record for integration knowledge and audit EVIDENCE — not a monitoring agent.
 *   - Evidence comes in as imported files (Phase 1). Automated collectors exist but are OFF, need a
 *     recorded approval, and only run from cron-audit.php (a cron line an administrator adds on purpose).
 *   - Every timestamp is stored in UTC (DATETIME, written from PHP — never NOW()) next to the raw source
 *     timestamp and its source timezone.
 *   - Two independent judgements per observation:
 *       attribution  confirmed | probable | unknown | investigate   — WHO did it (identifier evidence only)
 *       conformance  expected | unexpected | not_evaluated          — did a known integration behave as documented
 *     Time NEVER contributes to attribution. Temporal correlation is computed on demand and labelled as such;
 *     only a reviewer's conclusion is persisted (itops_integration_findings).
 *   - SQL text is never stored: only a normalised digest (literals replaced by ?), max 500 chars.
 *   - No secret is stored in evidence tables; the monitoring DB password (if ever configured) is enc:v1:.
 */

if (!function_exists('itops_ia_schema')) {

    define('ITOPS_IA_MAX_RANGE_DAYS', 31);
    define('ITOPS_IA_ATTR', ['confirmed', 'probable', 'unknown', 'investigate']);
    define('ITOPS_IA_CONF', ['expected', 'unexpected', 'not_evaluated']);
    define('ITOPS_IA_SCHED_TYPES', ['cron', 'window', 'interval', 'event', 'business_hours', 'adhoc', 'continuous', 'unknown']);
    define('ITOPS_IA_SOURCE_TYPES', ['db_process', 'db_digest', 'db_userstat', 'db_table_stats', 'http', 'cron', 'fabric', 'backup', 'itops', 'other']);

    /** DDL for every BT16 table (additive; used by the migration). */
    function itops_ia_schema(): array {
        $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            "CREATE TABLE IF NOT EXISTS itops_integration_registry (
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
                itops_managed TINYINT(1) NOT NULL DEFAULT 0,
                seed_key VARCHAR(60) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_by INT UNSIGNED DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_seed (seed_key), KEY idx_status (status), KEY idx_type (type)
            ) $t",
            "CREATE TABLE IF NOT EXISTS itops_integration_schedules (
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
            "CREATE TABLE IF NOT EXISTS itops_integration_tables (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                integration_id INT UNSIGNED NOT NULL,
                schema_name VARCHAR(128) DEFAULT '',
                table_name VARCHAR(128) NOT NULL,
                access_type VARCHAR(20) NOT NULL DEFAULT 'unknown',
                expected TINYINT(1) NOT NULL DEFAULT 1,
                notes VARCHAR(500) DEFAULT '',
                KEY idx_int (integration_id), KEY idx_tbl (table_name)
            ) $t",
            "CREATE TABLE IF NOT EXISTS itops_integration_evidence (
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
            "CREATE TABLE IF NOT EXISTS itops_integration_observations (
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
            "CREATE TABLE IF NOT EXISTS itops_integration_unknowns (
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
            "CREATE TABLE IF NOT EXISTS itops_integration_findings (
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
            "CREATE TABLE IF NOT EXISTS itops_collector_runs (
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
            "CREATE TABLE IF NOT EXISTS itops_server_metric_samples (
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
            "CREATE TABLE IF NOT EXISTS itops_integration_obs_rollup (
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


    /* ─────────────────────────── settings ─────────────────────────── */




    /* ─────────────────────────── time ─────────────────────────── */






    /* ─────────────────────────── fingerprints ─────────────────────────── */








    /* ─────────────────────────── registry ─────────────────────────── */







    /* ─────────────────────────── schedules / conformance ─────────────────────────── */







    /* ─────────────────────────── writing observations ─────────────────────────── */

    define('ITOPS_IA_OBS_COLS', ['observed_at', 'ended_at', 'source_ts_raw', 'source_tz', 'source_type', 'source_ref', 'evidence_id', 'collector_run_id',
        'integration_id', 'attribution', 'attribution_basis', 'conformance', 'conformance_reason', 'confidence', 'account', 'host', 'db_name',
        'endpoint', 'method', 'status_code', 'user_agent', 'query_digest', 'fingerprint', 'duration_ms', 'rows_examined', 'rows_sent',
        'rows_affected', 'bytes', 'meta']);





    /* ─────────────────────────── collector runs ─────────────────────────── */


    /* ─────────────────────────── timeline / correlation (computed, never stored) ─────────────────────────── */





}
