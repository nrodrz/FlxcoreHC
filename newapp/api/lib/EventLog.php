<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — Operational event log (system/subsystem events).
 *
 * This is DISTINCT from itops_audit_log, which records user actions (who changed
 * what). This records operational events: cron runs, integration/webhook
 * failures, Graph errors, migration runs, background job outcomes.
 *
 * itops_log() never throws — logging must never break the request that called it.
 * Table itops_event_log is created by the migration in sql/migration.php.
 *
 * Depends on ITOps's config.php: getDB() (PDO).
 */

if (!function_exists('itops_log')) {
    function itops_log(string $level, string $channel, string $message, array $context = []): void {
        static $levels = ['error', 'warning', 'info', 'debug'];
        if (!in_array($level, $levels, true)) $level = 'info';
        try {
            $db = getDB();
            $db->prepare("INSERT INTO itops_event_log (level, channel, message, context, user_id, ip_address)
                          VALUES (?,?,?,?,?,?)")
               ->execute([
                   $level,
                   substr($channel, 0, 60),
                   substr($message, 0, 500),
                   $context ? json_encode($context) : null,
                   $_SERVER['ITOPS_USER_ID'] ?? null,
                   substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
               ]);
        } catch (Throwable $e) {
            // Best-effort: swallow. A logging failure must not surface to the caller.
        }
    }
}
