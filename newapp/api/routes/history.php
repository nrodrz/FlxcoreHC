<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Change history for a record, built from the audit log.
 *
 *   GET /api/history?type=credential&id=12
 *   GET /api/history?type=contract&id=5
 *   GET /api/history?type=vendor&id=3
 *
 * Every CrudEngine update stores the touched fields before (old_value) and after (new_value) in opsh_audit_log
 * (BT124, review WORK-18: older entries hold the full previous row), so the timeline of a record can be reconstructed.
 * Encrypted credential fields are never returned here — they appear as "secret changed";
 * a past secret is revealed through /credentials/:id/history/:auditId (re-authenticated + logged).
 */
if ($method !== 'GET') error_response('Method not allowed', 405);

$type = preg_replace('/[^a-z_]/', '', strtolower((string)($_GET['type'] ?? '')));
$rid  = (int)($_GET['id'] ?? 0);
if (!$type || !$rid) error_response('type and id are required', 400);

// entity_type as written by each route  →  permission module that must be viewable
$MAP = ['credential' => 'creds', 'contract' => 'contracts', 'vendor' => 'vendors', 'ticket' => 'tickets',
        'sop' => 'sops', 'job_description' => 'job_descriptions', 'asset' => 'assets', 'kb_article' => 'kb', 'user' => 'users'];
if (!isset($MAP[$type])) error_response('Unknown record type', 400);
opsh_require($currentUser, $MAP[$type], 'view');
if ($type === 'ticket' && !opsh_can($currentUser, 'tickets', 'all')) {   // same record scope as the ticket list
    $vs = getDB()->prepare('SELECT 1 FROM opsh_tickets WHERE id=? AND (assignee_id=? OR requester_id=? OR backup_id=?)');
    $vs->execute([$rid, (int)$currentUser['id'], (int)$currentUser['id'], (int)$currentUser['id']]);
    if (!$vs->fetchColumn()) error_response('You can only see the history of tickets you created or that are assigned to you', 403);
}

if ($type === 'user' && ($currentUser['role_slug'] ?? '') !== 'super_admin') {   // review CORE-21: same department scope as the user list
    $uPerm = json_decode((string)($currentUser['role_perms'] ?? ''), true);
    $allDept = opsh_can($currentUser, 'users', 'all') && !(is_array($uPerm) && ($uPerm['users'] ?? null) === 'dept_manage');
    if (!$allDept) {
        $q = getDB()->prepare('SELECT department_id FROM opsh_users WHERE id=?'); $q->execute([$rid]); $d = $q->fetchColumn();
        if ($d !== false && (int)$d !== (int)($currentUser['department_id'] ?? 0)) error_response('You can only see the history of people in your department', 403);
    }
}
if (in_array($type, ['kb_article', 'sop'], true)) {   // drafts are for editors only
    $tbl = $type === 'kb_article' ? 'opsh_kb_articles' : 'opsh_sops'; $mod = $type === 'kb_article' ? 'kb' : 'sops';
    if (!opsh_can($currentUser, $mod, 'edit')) { $q = getDB()->prepare("SELECT status FROM $tbl WHERE id=?"); $q->execute([$rid]); $stt = $q->fetchColumn(); if ($stt !== false && $stt !== 'published') error_response('Record not found', 404); }
}

$SECRET = ['username_enc','password_enc','api_key_enc','mfa_details_enc','notes_enc','password_hash'];
$HIDE   = ['id','created_at','updated_at','created_by','updated_by'];

$db = getDB();
$st = $db->prepare('SELECT id, user_name, action, old_value, new_value, created_at FROM opsh_audit_log
                    WHERE entity_type=? AND entity_id=? ORDER BY created_at DESC, id DESC LIMIT 300');
$st->execute([$type, $rid]);

$out = [];
foreach ($st->fetchAll() as $row) {
    $old = $row['old_value'] ? (json_decode($row['old_value'], true) ?: []) : [];
    $new = $row['new_value'] ? (json_decode($row['new_value'], true) ?: []) : [];
    $changes = [];
    if ($row['action'] === 'updated') {
        foreach ($new as $k => $v) {
            if (in_array($k, $HIDE, true)) continue;
            $before = $old[$k] ?? null;
            if ((string)$before === (string)$v) continue;               // no real change
            if (in_array($k, $SECRET, true)) {
                $changes[] = ['field' => str_replace('_enc', '', $k), 'secret' => true];
            } else {
                $changes[] = ['field' => $k, 'from' => $before, 'to' => $v];
            }
        }
    }
    $hasOldSecret = $type === 'credential' && $old && count(array_filter($SECRET, fn($k) => !empty($old[$k]))) > 0;
    $out[] = [
        'audit_id'   => (int)$row['id'],
        'at'         => $row['created_at'],
        'by'         => $row['user_name'] ?: 'System',
        'action'     => $row['action'],
        'changes'    => $changes,
        // For credentials: this entry holds the values as they were BEFORE this change.
        'has_secret_snapshot' => $hasOldSecret,
    ];
}
json_response(['data' => $out, 'type' => $type, 'id' => $rid]);
