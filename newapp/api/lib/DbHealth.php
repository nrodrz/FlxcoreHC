<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * BT26 — Database health, housekeeping and backup (Settings → Maintenance, Super Admin only).
 *
 * Everything here reads information_schema / SHOW STATUS, or works only on ITOps's own tables (prefix itops_).
 * Nothing touches other databases or tables of other apps that may share the same database.
 */

const ITOPS_DBH_PREFIX = 'itops_';

/** The app name as the client renamed it (BT20 rule: never hard-code it in anything people read). */
function itops_dbh_app(): string { return function_exists('itops_app_name') ? itops_app_name() : 'ITOps'; }

/** Tables that must exist for ITOps to work (a missing one means an install/update problem). */
const ITOPS_DBH_CORE = ['itops_users', 'itops_roles', 'itops_settings', 'itops_tickets', 'itops_ticket_categories', 'itops_ticket_comments',
    'itops_kb_articles', 'itops_sops', 'itops_notifications', 'itops_audit_log', 'itops_event_log', 'itops_sessions', 'itops_migrations'];

/** What each table holds — shown next to the name so the list is readable without knowing the schema. */
function itops_dbh_describe(string $t): string {
    // BT137: one source of descriptions — the data dictionary (lib/DataDict.php); the list below is the older fallback
    if (!function_exists('itops_dd_catalog') && is_file(__DIR__ . '/DataDict.php')) require_once __DIR__ . '/DataDict.php';
    if (function_exists('itops_dd_catalog')) { $c = itops_dd_catalog()[$t] ?? null; if ($c) return $c[2]; }
    static $d = [
        'itops_audit_log' => 'Audit log — who changed what (kept, never purged)', 'itops_event_log' => 'Event log — system, cron and integration events',
        'itops_mail_log' => 'Outbound email log', 'itops_notifications' => 'Bell notifications', 'itops_sessions' => 'Sign-in sessions',
        'itops_webhook_deliveries' => 'Webhook delivery attempts', 'itops_tickets' => 'Tickets', 'itops_ticket_comments' => 'Ticket comments and notes',
        'itops_ticket_attachments' => 'Ticket attachment records (files are on disk)', 'itops_kb_articles' => 'Knowledge Base articles', 'itops_sops' => 'SOPs',
        'itops_sop_versions' => 'SOP version history', 'itops_sop_acks' => 'SOP acknowledgements', 'itops_users' => 'Users', 'itops_roles' => 'Roles and permissions',
        'itops_settings' => 'Settings (secrets encrypted)', 'itops_credentials' => 'Credential vault (encrypted)', 'itops_contracts' => 'Contracts',
        'itops_vendors' => 'Vendors', 'itops_assets' => 'Equipment', 'itops_asset_loans' => 'Equipment loans', 'itops_ob_runs' => 'Onboarding / offboarding history',
        'itops_ob_run_tasks' => 'Onboarding / offboarding steps', 'itops_m365_templates' => 'Onboarding templates', 'itops_m365_policy_backups' => 'Intune policy backups',
        'itops_server_metric_samples' => 'Integration Audit metric samples (own retention)', 'itops_integration_observations' => 'Integration Audit observations',
        'itops_integration_evidence' => 'Integration Audit evidence', 'itops_sla_alerts' => 'SLA alerts already sent (prevents repeats)',
        'itops_migrations' => 'Applied database updates', 'itops_db_snapshots' => 'Daily database size snapshots', 'itops_device_inventory' => 'Microsoft 365 device inventory (Entra devices and sign-ins)', 'itops_announcements' => 'Announcements', 'itops_announcement_audience' => 'Announcements: chosen audience', 'itops_announcement_recipients' => 'Announcements: who received and read each one', 'itops_calendar_events' => 'Calendar events',
        'itops_outlook_users' => 'Outlook calendar sync: each person\'s choice', 'itops_outlook_links' => 'Outlook calendar sync: the app\'s items in Outlook', 'itops_cron_runs' => 'Scheduled job runs (this month; earlier months go to the monthly report)', 'itops_cron_months' => 'Scheduled jobs — monthly report', 'itops_uptime_monitors' => 'Uptime monitors', 'itops_uptime_checks' => 'Uptime checks (kept 90 days)', 'itops_uptime_incidents' => 'Uptime outages', 'itops_backup_jobs' => 'Watched backups', 'itops_backup_events' => 'Backup results (kept 400 days)', 'itops_calendar_attendees' => 'Calendar event attendees', 'itops_calendar_reminders_sent' => 'Calendar reminders already sent (prevents repeats)', 'itops_calendar_reports' => 'Saved calendar reports',
        'itops_forms' => 'Forms', 'itops_form_submissions' => 'Form responses', 'itops_documents' => 'Documents', 'itops_signature_requests' => 'Signature requests',
        'itops_signature_signers' => 'Signers', 'itops_access_log' => 'Access log: credentials revealed, documents opened', 'itops_categories' => 'Categories of the Knowledge Base, documents, SOPs and credentials',
        'itops_api_keys' => 'API keys (hashed)', 'itops_webhooks' => 'Webhooks', 'itops_std_requirements' => 'Standards & Audits requirements', 'itops_std_evidence' => 'Standards & Audits evidence',
    ];
    return $d[$t] ?? '';
}

/** Every table in the current database: ITOps's first. */
function itops_dbh_tables(PDO $db): array {
    $st = $db->query("SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS rows_est, DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes,
                             DATA_FREE AS free_bytes, TABLE_COLLATION AS collation, AUTO_INCREMENT AS auto_inc, CREATE_TIME AS created, UPDATE_TIME AS updated
                      FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $own = strpos($r['name'], ITOPS_DBH_PREFIX) === 0;
        $out[] = ['name' => $r['name'], 'itops' => $own, 'about' => $own ? itops_dbh_describe($r['name']) : '', 'engine' => (string)$r['engine'],
            'rows' => (int)$r['rows_est'], 'data' => (int)$r['data_bytes'], 'index' => (int)$r['index_bytes'], 'total' => (int)$r['data_bytes'] + (int)$r['index_bytes'],
            'free' => (int)$r['free_bytes'], 'collation' => (string)$r['collation'], 'auto_inc' => $r['auto_inc'] === null ? null : (int)$r['auto_inc'],
            'created' => $r['created'], 'updated' => $r['updated']];
    }
    return $out;
}

/** Server facts and a few live counters (all read-only). */
function itops_dbh_server(PDO $db): array {
    $v = function (string $sql) use ($db) { try { return $db->query($sql)->fetchColumn(); } catch (\Throwable $e) { return null; } };
    $status = []; try { foreach ($db->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime','Threads_connected','Max_used_connections','Slow_queries','Questions','Aborted_connects','Innodb_buffer_pool_reads','Innodb_buffer_pool_read_requests')") as $r) $status[$r[0] ?? $r['Variable_name']] = $r[1] ?? $r['Value']; } catch (\Throwable $e) {}
    $vars = []; try { foreach ($db->query("SHOW VARIABLES WHERE Variable_name IN ('version','version_comment','max_connections','max_allowed_packet','innodb_buffer_pool_size','time_zone','system_time_zone','character_set_database','collation_database','sql_mode','wait_timeout','slow_query_log','long_query_time')") as $r) $vars[$r[0] ?? $r['Variable_name']] = $r[1] ?? $r['Value']; } catch (\Throwable $e) {}
    $req = (float)($status['Innodb_buffer_pool_read_requests'] ?? 0); $miss = (float)($status['Innodb_buffer_pool_reads'] ?? 0);
    $uptime = (int)($status['Uptime'] ?? 0);
    return [
        'database' => (string)$v('SELECT DATABASE()'), 'version' => (string)($vars['version'] ?? $v('SELECT VERSION()')), 'flavor' => (string)($vars['version_comment'] ?? ''),
        'charset' => (string)($vars['character_set_database'] ?? ''), 'collation' => (string)($vars['collation_database'] ?? ''),
        'time_zone' => (string)($vars['time_zone'] ?? '') . (($vars['time_zone'] ?? '') === 'SYSTEM' && !empty($vars['system_time_zone']) ? ' (' . $vars['system_time_zone'] . ')' : ''),
        'db_now' => (string)$v('SELECT NOW()'), 'php_now' => date('Y-m-d H:i:s'),
        'uptime_sec' => $uptime, 'connections' => (int)($status['Threads_connected'] ?? 0), 'max_connections' => (int)($vars['max_connections'] ?? 0),
        'max_used_connections' => (int)($status['Max_used_connections'] ?? 0), 'slow_queries' => (int)($status['Slow_queries'] ?? 0),
        'queries_per_sec' => $uptime > 0 ? round((float)($status['Questions'] ?? 0) / $uptime, 1) : null, 'aborted_connects' => (int)($status['Aborted_connects'] ?? 0),
        'buffer_pool' => (int)($vars['innodb_buffer_pool_size'] ?? 0), 'buffer_hit_pct' => $req > 0 ? round(100 * (1 - $miss / $req), 2) : null,
        'max_packet' => (int)($vars['max_allowed_packet'] ?? 0), 'slow_log' => (string)($vars['slow_query_log'] ?? ''), 'long_query_time' => (string)($vars['long_query_time'] ?? ''),
        'wait_timeout' => (int)($vars['wait_timeout'] ?? 0), 'sql_mode' => (string)($vars['sql_mode'] ?? ''),
        'status_readable' => !empty($status),   // shared hosting may hide global status
    ];
}

/** Record today's size snapshot (once a day). Returns true when a row was written. */
function itops_dbh_snapshot(PDO $db, ?array $tables = null): bool {
    try {
        $today = date('Y-m-d');
        $has = $db->prepare("SELECT 1 FROM itops_db_snapshots WHERE day=?"); $has->execute([$today]);
        if ($has->fetchColumn()) return false;
        $tables = $tables ?? itops_dbh_tables($db);
        $per = []; $tot = 0; $rows = 0;
        foreach ($tables as $t) { if (!$t['itops']) continue; $per[$t['name']] = [$t['total'], $t['rows']]; $tot += $t['total']; $rows += $t['rows']; }
        $db->prepare("INSERT IGNORE INTO itops_db_snapshots (day, total_bytes, rows_est, tables_json) VALUES (?,?,?,?)")->execute([$today, $tot, $rows, json_encode($per)]);
        $db->exec("DELETE FROM itops_db_snapshots WHERE day < '" . date('Y-m-d', strtotime('-400 days')) . "'");
        return true;
    } catch (\Throwable $e) { return false; }   // table not created yet (database update pending)
}

/** Size history (up to 90 days) and per-table growth over the last 7 / 30 days. */
function itops_dbh_history(PDO $db, array $tables): array {
    try { $rows = $db->query("SELECT day, total_bytes, rows_est, tables_json FROM itops_db_snapshots WHERE day >= '" . date('Y-m-d', strtotime('-90 days')) . "' ORDER BY day")->fetchAll(); }
    catch (\Throwable $e) { return ['available' => false, 'points' => [], 'growth' => []]; }
    $pts = array_map(fn($r) => ['day' => $r['day'], 'bytes' => (int)$r['total_bytes'], 'rows' => (int)$r['rows_est']], $rows);
    $find = function (int $days) use ($rows) { $cut = date('Y-m-d', strtotime("-$days days")); $best = null; foreach ($rows as $r) { if ($r['day'] <= $cut) $best = $r; } return $best ?: ($rows[0] ?? null); };
    $growth = [];
    foreach ([7, 30] as $d) {
        $old = $find($d); if (!$old || $old['day'] >= date('Y-m-d')) continue;   // growth needs an earlier day than today
        $oj = json_decode((string)$old['tables_json'], true) ?: [];
        foreach ($tables as $t) { if (!$t['itops']) continue; $prev = $oj[$t['name']][0] ?? null; $growth[$t['name']]['d' . $d] = $prev === null ? null : $t['total'] - (int)$prev; }
        $now = 0; foreach ($tables as $t) if ($t['itops']) $now += $t['total'];
        $growth['_total']['d' . $d] = $now - (int)$old['total_bytes']; $growth['_total']['since_' . $d] = $old['day'];
    }
    return ['available' => true, 'points' => $pts, 'growth' => $growth];
}

/** Log tables that can be trimmed. Audit log is deliberately absent (compliance record, never purged). */
function itops_dbh_purge_defs(): array {
    return [
        'sessions'      => ['table' => 'itops_sessions', 'label' => 'Expired sign-in sessions', 'date' => 'expires_at', 'where' => '1=1', 'days' => 0, 'min' => 0,
                            'note' => 'Sessions whose expiry time has passed. They can no longer be used; the scheduled job also removes them a day after they expire.'],
        'notifications' => ['table' => 'itops_notifications', 'label' => 'Read notifications', 'date' => 'created_at', 'where' => 'is_read=1', 'days' => 90, 'min' => 30,
                            'note' => 'Bell notifications people have already read. Unread ones are never removed.'],
        'event_log'     => ['table' => 'itops_event_log', 'label' => 'Event log', 'date' => 'ts', 'where' => '1=1', 'days' => 180, 'min' => 30,
                            'note' => 'System, cron and integration events (Logs & Integrations → Event Log).'],
        'mail_log'      => ['table' => 'itops_mail_log', 'label' => 'Outbound email log', 'date' => 'created_at', 'where' => '1=1', 'days' => 180, 'min' => 30,
                            'note' => 'The send attempts listed under Settings → Email. The emails themselves are not affected.'],
        'webhooks'      => ['table' => 'itops_webhook_deliveries', 'label' => 'Webhook deliveries', 'date' => 'created_at', 'where' => "status<>'queued'", 'days' => 90, 'min' => 30,
                            'note' => 'Finished webhook attempts (delivered or failed). Queued deliveries are never removed.'],
    ];
}

/** Counts for each purge definition: total, eligible with $days, oldest date. */
function itops_dbh_purge_stats(PDO $db, array $retention): array {
    $out = [];
    foreach (itops_dbh_purge_defs() as $k => $d) {
        $days = $d['days'] === 0 ? 0 : max($d['min'], (int)($retention[$k] ?? $d['days']));
        $cond = $k === 'sessions' ? "{$d['date']} < NOW()" : "{$d['date']} < (NOW() - INTERVAL $days DAY)";
        try {
            $tot = (int)$db->query("SELECT COUNT(*) FROM {$d['table']}")->fetchColumn();
            $elig = (int)$db->query("SELECT COUNT(*) FROM {$d['table']} WHERE {$d['where']} AND $cond")->fetchColumn();
            $old = $db->query("SELECT MIN({$d['date']}) FROM {$d['table']}")->fetchColumn();
            $out[$k] = ['label' => $d['label'], 'note' => $d['note'], 'total' => $tot, 'eligible' => $elig, 'oldest' => $old ?: null, 'days' => $days, 'min' => $d['min'], 'fixed' => $d['days'] === 0];
        } catch (\Throwable $e) { $out[$k] = ['label' => $d['label'], 'note' => $d['note'], 'error' => 'Table not available', 'days' => $days, 'min' => $d['min'], 'fixed' => $d['days'] === 0]; }
    }
    return $out;
}

/** Delete rows older than $days (in batches, so a big table never locks for long). Returns rows removed. */
function itops_dbh_purge(PDO $db, string $key, int $days): int {
    $defs = itops_dbh_purge_defs(); if (!isset($defs[$key])) throw new InvalidArgumentException('Unknown log');
    $d = $defs[$key];
    if ($d['days'] !== 0 && $days < $d['min']) throw new InvalidArgumentException($d['label'] . ' keeps at least ' . $d['min'] . ' days.');
    $cond = $key === 'sessions' ? "{$d['date']} < NOW()" : "{$d['date']} < (NOW() - INTERVAL " . (int)$days . " DAY)";
    $n = 0;
    for ($i = 0; $i < 400; $i++) { $c = $db->exec("DELETE FROM {$d['table']} WHERE {$d['where']} AND $cond LIMIT 2000"); $n += (int)$c; if ($c < 2000) break; }
    return $n;
}

/** Retention settings: {enabled: bool, <key>: days}. */
function itops_dbh_retention_get(PDO $db): array {
    $raw = null; try { $s = $db->prepare("SELECT sval FROM itops_settings WHERE skey='db_retention'"); $s->execute(); $raw = $s->fetchColumn(); } catch (\Throwable $e) {}
    $r = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    $out = ['enabled' => !empty($r['enabled']), 'last_run' => $r['last_run'] ?? null, 'last_result' => $r['last_result'] ?? null];
    foreach (itops_dbh_purge_defs() as $k => $d) if ($d['days'] !== 0) $out[$k] = max($d['min'], (int)($r[$k] ?? $d['days']));
    return $out;
}

/** Daily automatic cleanup (called from cron-sla.php). Returns a short summary or null when off / already ran today. */
function itops_dbh_retention_run(PDO $db): ?string {
    $r = itops_dbh_retention_get($db);
    itops_dbh_snapshot($db);   // the daily size snapshot rides on the same job
    if (!$r['enabled'] || ($r['last_run'] && substr((string)$r['last_run'], 0, 10) === date('Y-m-d'))) return null;
    $done = [];
    foreach (itops_dbh_purge_defs() as $k => $d) {
        try { $n = itops_dbh_purge($db, $k, $d['days'] === 0 ? 0 : (int)$r[$k]); if ($n) $done[] = $d['label'] . ': ' . $n; } catch (\Throwable $e) {}
    }
    $r['last_run'] = date('Y-m-d H:i:s'); $r['last_result'] = $done ? implode('; ', $done) : 'nothing to remove';
    $db->prepare("INSERT INTO itops_settings (skey,sval) VALUES ('db_retention',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([json_encode($r)]);
    return $r['last_result'];
}

/** Health checks: [{level: ok|warn|error, title, detail}] */
function itops_dbh_checks(PDO $db, array $tables, array $server, array $pending): array {
    $c = []; $own = array_filter($tables, fn($t) => $t['itops']); $names = array_column($own, 'name');
    $missing = array_values(array_diff(ITOPS_DBH_CORE, $names));
    $c[] = $missing ? ['level' => 'error', 'title' => 'Core tables missing', 'detail' => implode(', ', $missing) . ' — run the database update, or restore from a backup.']
                    : ['level' => 'ok', 'title' => 'All core tables present', 'detail' => count($own) . ' ' . itops_dbh_app() . ' tables.'];
    $c[] = $pending ? ['level' => 'warn', 'title' => count($pending) . ' database update(s) pending', 'detail' => 'Click "Apply updates now" above.']
                    : ['level' => 'ok', 'title' => 'Database updates applied', 'detail' => 'Nothing pending.'];
    $notInno = array_values(array_map(fn($t) => $t['name'] . ' (' . ($t['engine'] ?: '?') . ')', array_filter($own, fn($t) => strcasecmp($t['engine'], 'InnoDB') !== 0)));
    $c[] = $notInno ? ['level' => 'warn', 'title' => 'Tables not using InnoDB', 'detail' => implode(', ', $notInno) . ' — InnoDB is needed for crash safety and transactions.']
                    : ['level' => 'ok', 'title' => 'Every table uses InnoDB', 'detail' => 'Crash-safe storage with transactions.'];
    $cols = []; foreach ($own as $t) $cols[$t['collation']][] = $t['name'];
    if (count($cols) > 1) { uasort($cols, fn($a, $b) => count($b) <=> count($a)); $main = array_key_first($cols); $odd = [];
        foreach ($cols as $k => $l) if ($k !== $main) $odd[] = implode(', ', $l) . ' (' . $k . ')';
        $c[] = ['level' => 'warn', 'title' => 'Mixed collations', 'detail' => 'Most tables use ' . $main . '; ' . implode('; ', $odd) . '. Harmless for ' . itops_dbh_app() . ' today, but joins between them can be slower.'];
    } else $c[] = ['level' => 'ok', 'title' => 'One collation for all tables', 'detail' => (string)array_key_first($cols)];
    $frag = array_filter($own, fn($t) => $t['free'] > 50 * 1048576 && $t['free'] > 0.2 * max(1, $t['total']));
    $c[] = $frag ? ['level' => 'warn', 'title' => 'Tables with reclaimable space', 'detail' => implode(', ', array_map(fn($t) => $t['name'] . ' (' . round($t['free'] / 1048576) . ' MB)', $frag)) . ' — "Optimize" rebuilds them.']
                 : ['level' => 'ok', 'title' => 'No significant wasted space', 'detail' => 'Nothing over 50 MB to reclaim.'];
    $near = []; foreach ($own as $t) if ($t['auto_inc'] !== null && $t['auto_inc'] > 0.8 * 4294967295) $near[] = $t['name'];
    $c[] = $near ? ['level' => 'error', 'title' => 'IDs close to the limit', 'detail' => implode(', ', $near)] : ['level' => 'ok', 'title' => 'ID counters healthy', 'detail' => 'All far below the limit.'];
    if ($server['max_connections'] > 0) {
        $pct = round(100 * $server['max_used_connections'] / $server['max_connections']);
        $c[] = $pct >= 80 ? ['level' => 'warn', 'title' => 'Connections peaked at ' . $pct . '% of the limit', 'detail' => $server['max_used_connections'] . ' of ' . $server['max_connections'] . ' since the database server started.']
                          : ['level' => 'ok', 'title' => 'Connections well below the limit', 'detail' => 'Peak ' . $server['max_used_connections'] . ' of ' . $server['max_connections'] . '.'];
    }
    $skew = abs(strtotime((string)$server['db_now']) - strtotime((string)$server['php_now']));
    $c[] = $skew > 120 ? ['level' => 'warn', 'title' => 'Database clock differs from the web server', 'detail' => 'Database ' . $server['db_now'] . ' vs web server ' . $server['php_now'] . '. Due times may look shifted; ask the host to align time zones.']
                       : ['level' => 'ok', 'title' => 'Clocks agree', 'detail' => 'Database and web server time match.'];
    $big = array_filter($own, fn($t) => in_array($t['name'], ['itops_event_log', 'itops_mail_log', 'itops_webhook_deliveries', 'itops_notifications'], true) && $t['total'] > 200 * 1048576);
    if ($big) $c[] = ['level' => 'warn', 'title' => 'Large log tables', 'detail' => implode(', ', array_map(fn($t) => $t['name'] . ' (' . round($t['total'] / 1048576) . ' MB)', $big)) . ' — see Housekeeping below.'];
    return $c;
}

/** Stream a gzip SQL backup of the ITOps tables (structure + data). */
function itops_dbh_backup_stream(PDO $db, string $appName): void {
    @set_time_limit(0); @ignore_user_abort(false);
    $tables = array_values(array_filter(array_column(itops_dbh_tables($db), 'name'), fn($n) => strpos($n, ITOPS_DBH_PREFIX) === 0));
    $gz = function_exists('deflate_init') ? deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]) : null;
    $fname = preg_replace('/[^A-Za-z0-9_-]+/', '-', $appName) . '-db-' . date('Y-m-d-His') . '.sql' . ($gz ? '.gz' : '');
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . ($gz ? 'application/gzip' : 'application/sql'));
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store');
    $out = function (string $s, bool $end = false) use ($gz) { if ($gz) { $c = deflate_add($gz, $s, $end ? ZLIB_FINISH : ZLIB_NO_FLUSH); if ($c !== '') echo $c; } else echo $s; if ($end) flush(); };
    itops_dbh_backup_sql($db, $appName, $tables, $out);
}

/** BT125: the same backup written to a gzip file (Settings → Updates keeps one before every update). Returns the bytes written. */
function itops_dbh_backup_file(PDO $db, string $appName, string $path): int {
    @set_time_limit(0);
    $tables = array_values(array_filter(array_column(itops_dbh_tables($db), 'name'), fn($n) => strpos($n, ITOPS_DBH_PREFIX) === 0));
    $gz = @gzopen($path, 'wb6'); if (!$gz) throw new RuntimeException('the database backup file could not be written');
    $ok = true;
    $out = function (string $s, bool $end = false) use ($gz, &$ok) { if ($s !== '' && @gzwrite($gz, $s) === false) $ok = false; };
    try { itops_dbh_backup_sql($db, $appName, $tables, $out); } finally { gzclose($gz); }
    if (!$ok) throw new RuntimeException('the database backup file could not be written completely (is the disk full?)');
    @chmod($path, 0600);
    return (int)filesize($path);
}

/** The SQL of a backup, handed piece by piece to $out (shared by the download and the file). */
function itops_dbh_backup_sql(PDO $db, string $appName, array $tables, callable $out): void {
    $out("-- " . $appName . " database backup\n-- Created " . date('c') . " · database " . $db->query('SELECT DATABASE()')->fetchColumn() . " · " . count($tables) . " tables\n"
        . "-- Contains password hashes and encrypted secrets: store it as securely as the server itself.\n"
        . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
    // Review OPS-29: every table is read from the same moment (InnoDB consistent snapshot)
    $snap = false; try { $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY'); $snap = true; } catch (Throwable $e) { try { $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT'); $snap = true; } catch (Throwable $e2) {} }
    foreach ($tables as $t) {
        $cr = $db->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
        $out("-- ---------------- $t\nDROP TABLE IF EXISTS `$t`;\n" . $cr[1] . ";\n");
        $cols = null; $last = null; $pk = null;
        try { $k = $db->query("SHOW KEYS FROM `$t` WHERE Key_name='PRIMARY'")->fetchAll(); if (count($k) === 1) $pk = $k[0]['Column_name']; } catch (\Throwable $e) {}
        $offset = 0; $batch = 500;
        while (true) {
            $sql = $pk ? ("SELECT * FROM `$t`" . ($last !== null ? " WHERE `$pk` > " . $db->quote((string)$last) : '') . " ORDER BY `$pk` LIMIT $batch")
                       : ("SELECT * FROM `$t` LIMIT $batch OFFSET $offset");
            $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) break;
            if ($cols === null) $cols = '`' . implode('`,`', array_keys($rows[0])) . '`';
            $vals = [];
            foreach ($rows as $r) { $vals[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $db->quote((string)$v), $r)) . ')'; if ($pk) $last = $r[$pk]; }
            $out("INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $vals) . ";\n");
            $offset += $batch; if (count($rows) < $batch) break;
        }
        $out("\n");
    }
    if ($snap) { try { $db->exec('COMMIT'); } catch (Throwable $e) {} }
    $out("SET FOREIGN_KEY_CHECKS=1;\n-- End of backup\n", true);
}
