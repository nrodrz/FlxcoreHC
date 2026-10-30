<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — Global Search
 * GET /api/search?q=term → matches the user may VIEW (permission-filtered). The screen adds the pages it can open.
 * 0001-A003: the app's users and the onboarding templates (the reference app searched tickets, KB, SOPs, vendors, contracts).
 */
$db = getDB();
if ($method !== 'GET') error_response('Method not allowed', 405);

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) { json_response(['data' => [], 'query' => $q]); }
$like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
$out = [];
$add = function ($type, $id, $title, $sub, $page) use (&$out) {
    $out[] = ['type' => $type, 'id' => (int)$id, 'title' => $title, 'sub' => $sub, 'page' => $page];
};

if (itops_can($currentUser, 'users')) {
    // the same scope as the Users page: without Users · All records (or as a legacy department admin), one's own department
    $uPerm = json_decode((string)($currentUser['role_perms'] ?? ''), true);
    $allDept = ($currentUser['role_slug'] ?? '') === 'super_admin'
        || (itops_can($currentUser, 'users', 'all') && !(is_array($uPerm) && ($uPerm['users'] ?? null) === 'dept_manage'));
    $sql = "SELECT id, display_name, email, status FROM itops_users WHERE (display_name LIKE ? OR email LIKE ?)";
    $args = [$like, $like];
    if (!$allDept) { $sql .= " AND department_id = ?"; $args[] = (int)($currentUser['department_id'] ?? 0); }
    $st = $db->prepare($sql . " ORDER BY display_name LIMIT 6"); $st->execute($args);
    foreach ($st->fetchAll() as $r) $add('user', $r['id'], $r['display_name'], $r['email'] . ($r['status'] !== 'active' ? ' · ' . $r['status'] : ''), 'users');
}
if (itops_can($currentUser, 'onboarding')) {
    try {
        $st = $db->prepare("SELECT id, name, status FROM itops_ob_templates WHERE name LIKE ? ORDER BY name LIMIT 5"); $st->execute([$like]);
        foreach ($st->fetchAll() as $r) $add('template', $r['id'], $r['name'], 'Onboarding template' . ($r['status'] === 'archived' ? ' · archived' : ''), 'onboarding_templates');
    } catch (\Throwable $e) { /* table not there yet */ }
}
json_response(['data' => $out, 'query' => $q]);
