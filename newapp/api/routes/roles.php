<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Roles & Permissions
 *   GET    /roles          list (with user_count per role)        — needs roles:view
 *   GET    /roles/:id      one role                               — needs roles:view
 *   POST   /roles          create                                 — Super Admin only
 *   PUT    /roles/:id      update (system roles editable too)     — Super Admin only
 *   DELETE /roles/:id      delete (not system roles, not in use)  — Super Admin only
 *
 * Creating/changing roles is Super Admin only: anyone who can edit roles could grant
 * themselves everything. The super_admin role always keeps {"*":true} and its slug.
 */
require_once __DIR__ . '/../lib/CrudEngine.php';

if ($method === 'GET') {
    opsh_require($currentUser, 'roles', 'view');
} elseif (($currentUser['role_slug'] ?? '') !== 'super_admin') {
    error_response('Only a Super Admin can create or change roles', 403);
}

$crud = new CrudEngine('opsh_roles', 'role', ['name','slug','description','permissions'], ['t.name','t.description'], $currentUser);

$readBody = function (): array {
    $b = json_decode(file_get_contents('php://input'), true);
    if (!is_array($b)) error_response('Invalid request body', 400);
    if (array_key_exists('permissions', $b) && is_array($b['permissions'])) {
        // BT143: a sub-permission brings the View it depends on (Microsoft 365 parts, Ask IT, vTiger passwords)
        if (empty($b['permissions']['*'])) { [$b['permissions'], $GLOBALS['opsh_perm_added']] = opsh_perm_normalize($b['permissions']); }
        $b['permissions'] = json_encode($b['permissions'] ?: new stdClass());   // {} not [] for an empty grid
    }
    return $b;
};

switch ($method) {
    case 'GET':
        $res = $id ? $crud->getById((int)$id) : $crud->listAll($_GET);
        // Attach how many users hold each role (the editor shows impact before saving).
        try {
            $cnt = [];
            foreach (getDB()->query('SELECT role_id, COUNT(*) c FROM opsh_users GROUP BY role_id')->fetchAll() as $row) {
                $cnt[(int)$row['role_id']] = (int)$row['c'];
            }
            $stamp = function (&$r) use ($cnt) { if (is_array($r) && isset($r['id'])) $r['user_count'] = $cnt[(int)$r['id']] ?? 0; };
            if ($id) { $stamp($res); }
            elseif (isset($res['data']) && is_array($res['data'])) { foreach ($res['data'] as &$r) { $stamp($r); } unset($r); }
        } catch (Throwable $e) {}
        json_response($res);
        break;

    case 'POST':
        $body = $readBody();
        if (empty($body['name'])) error_response('Role name is required', 400);
        if (($body['slug'] ?? '') === 'super_admin') error_response('That slug is reserved', 400);
        json_response($crud->create($body), 201);
        break;

    case 'PUT':
        if (!$id) error_response('ID required', 400);
        $body = $readBody();
        $cur = $crud->getById((int)$id);
        if (!$cur) error_response('Role not found', 404);
        if (($cur['slug'] ?? '') === 'super_admin') {
            // Super Admin: name/description may change; access is always full and the slug is fixed.
            unset($body['permissions'], $body['slug']);
        } elseif (!empty($cur['is_system'])) {
            // System roles keep their slug (other code relies on it); everything else is editable.
            unset($body['slug']);
        }
        if (($body['slug'] ?? '') === 'super_admin') error_response('That slug is reserved', 400);
        json_response($crud->update((int)$id, $body));
        break;

    case 'DELETE':
        if (!$id) error_response('ID required', 400);
        $role = $crud->getById((int)$id);
        if (!$role) error_response('Role not found', 404);
        if (!empty($role['is_system'])) error_response('System roles cannot be deleted', 400);
        $inUse = getDB()->prepare('SELECT COUNT(*) FROM opsh_users WHERE role_id=?');
        $inUse->execute([(int)$id]);
        if ((int)$inUse->fetchColumn() > 0) error_response('This role is still assigned to users — reassign them first', 400);
        $crud->delete((int)$id);
        json_response(['ok' => true]);
        break;

    default:
        error_response('Method not allowed', 405);
}
