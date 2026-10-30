<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — /api/help   (BT50: Help Center visuals, given to roles and people)
 *
 * GET  /help/visuals                 the visuals this person may open: [{id, title, description}], + can_manage
 * GET  /help/visuals/:id             one visual: {id, title, html} (a complete page for an <iframe srcdoc>); 403 when not given
 * GET  /help/visual-access           Super Admin: every visual with its roles and people, + the roles and the active people
 * PUT  /help/visual-access/:id       Super Admin: {roles: [slug…], users: [id…]} — replaces who may open that visual
 *
 * BT74: the Word guides are no longer downloaded from the app (they are support files kept outside it); the BT71–BT73
 * /help/downloads and /help/download-access routes are gone and answer 404.
 *
 * Every change of access is in the Audit Log with the roles and people before and after; a refused attempt too.
 */

require_once __DIR__ . '/../lib/HelpVisuals.php';
if (is_file(__DIR__ . '/../lib/Brand.php')) require_once __DIR__ . '/../lib/Brand.php';

$db  = getDB();
$sub = (string)($segments[1] ?? '');
$vid = (string)($segments[2] ?? '');
$isSA = (($currentUser['role_slug'] ?? '') === 'super_admin');
$VIS = opsh_help_visuals();

// role slug => name; user id => "Name <email>" — for the screen and for the audit text
$roleNames = function () use ($db): array {
    $o = []; foreach ($db->query('SELECT slug, name FROM opsh_roles ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $r) $o[(string)$r['slug']] = (string)$r['name'];
    return $o;
};
$userRows = function (bool $activeOnly) use ($db): array {
    $sql = 'SELECT u.id, u.display_name, u.email, u.status, r.slug AS role_slug FROM opsh_users u LEFT JOIN opsh_roles r ON r.id = u.role_id'
         . ($activeOnly ? " WHERE u.status = 'active'" : '') . ' ORDER BY u.display_name';
    $o = []; foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $u) $o[(int)$u['id']] = $u;
    return $o;
};
$describe = function (array $a) use ($roleNames, $userRows): array {
    $rn = $roleNames(); $us = $userRows(false);
    $roles = array_map(fn($s) => $rn[$s] ?? ($s . ' (role no longer exists)'), $a['roles']);
    $people = array_map(fn($i) => isset($us[$i]) ? trim((string)$us[$i]['display_name']) . ' <' . (string)$us[$i]['email'] . '>' : ('#' . $i . ' (no longer exists)'), $a['users']);
    return ['Roles' => $roles ? implode(', ', $roles) : '(none)', 'People' => $people ? implode(', ', $people) : '(none)'];
};

// ── the visuals this person may open ──────────────────────────────────────────────
if ($method === 'GET' && $sub === 'visuals' && $vid === '') {
    $acc = opsh_help_visual_access($db);
    $list = [];
    foreach ($VIS as $id => [$title, $desc]) if (opsh_help_visual_allowed($currentUser, $id, $acc)) $list[] = ['id' => $id, 'title' => $title, 'description' => $desc];
    json_response(['visuals' => $list, 'can_manage' => $isSA]);
}

// ── one visual ────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $sub === 'visuals' && $vid !== '') {
    if (!isset($VIS[$vid])) error_response('Not found', 404);
    if (!opsh_help_visual_allowed($currentUser, $vid, opsh_help_visual_access($db))) error_response('This page has not been given to you. A Super Admin can give it to your role or to you.', 403);
    $html = opsh_help_visual_html($vid, $db);
    if ($html === null) error_response('This page is missing from the installation. Upload the full install again.', 500);
    header('Cache-Control: no-store');
    json_response(['id' => $vid, 'title' => $VIS[$vid][0], 'html' => $html]);
}

// ── who may open what (Super Admin) ───────────────────────────────────────────────
if ($sub === 'visual-access') {
    if (!$isSA) {
        if ($method === 'PUT' && function_exists('audit_log')) {
            try { audit_log('help_visual_access_refused', 'help_visual', null, mb_substr(($VIS[$vid][0] ?? $vid), 0, 250), ['Reason' => 'Only a Super Admin can change who sees a Help Center visual.'], ['Asked by' => (string)($currentUser['email'] ?? '')]); } catch (Throwable $e) {}
        }
        error_response('Only a Super Admin can change who sees these pages.', 403);
    }
    if ($method === 'GET' && $vid === '') {
        $acc = opsh_help_visual_access($db); $rn = $roleNames(); $us = $userRows(true);
        $out = [];
        foreach ($VIS as $id => [$title, $desc]) $out[] = ['id' => $id, 'title' => $title, 'description' => $desc, 'roles' => $acc[$id]['roles'], 'users' => $acc[$id]['users']];
        json_response([
            'visuals' => $out,
            'roles' => array_values(array_map(fn($s, $n) => ['slug' => $s, 'name' => $n], array_keys($rn), $rn)),
            'users' => array_values(array_map(fn($u) => ['id' => (int)$u['id'], 'name' => (string)$u['display_name'], 'email' => (string)$u['email'], 'role' => (string)($u['role_slug'] ?? '')], $us)),
        ]);
    }
    if ($method === 'PUT' && $vid !== '') {
        if (!isset($VIS[$vid])) error_response('Not found', 404);
        $body = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($body) || !is_array($body['roles'] ?? null) || !is_array($body['users'] ?? null)) error_response('Send the roles and the people as two lists.', 400);
        $rn = $roleNames(); $us = $userRows(true);
        $acc = opsh_help_visual_access($db); $old = $acc[$vid];
        $roles = []; foreach ($body['roles'] as $r) {
            if (!is_string($r) || !isset($rn[$r])) error_response('A role in the list does not exist.', 400);
            if ($r !== 'super_admin') $roles[] = $r;   // a Super Admin always sees every visual
        }
        $users = []; foreach ($body['users'] as $u) {
            if (!is_int($u) && !(is_string($u) && ctype_digit($u))) error_response('A person in the list is not valid.', 400);
            // a person who left keeps nothing new, but one already on the list may stay on it (the screen sends the list back)
            if (!isset($us[(int)$u]) && !in_array((int)$u, $old['users'], true)) error_response('A person in the list does not exist or is not active.', 400);
            $users[] = (int)$u;
        }
        $roles = array_values(array_unique($roles)); sort($roles);
        $users = array_values(array_unique($users)); sort($users);
        $new = ['roles' => $roles, 'users' => $users];
        $so = $old; sort($so['roles']); sort($so['users']);
        if ($so['roles'] === $roles && $so['users'] === $users) json_response(['ok' => true, 'changed' => false, 'access' => $new]);
        $acc[$vid] = $new;
        opsh_help_visual_access_save($db, $acc);
        if (function_exists('audit_log')) audit_log('help_visual_access', 'help_visual', null, mb_substr($VIS[$vid][0], 0, 250), $describe($old), $describe($new));
        json_response(['ok' => true, 'changed' => true, 'access' => $new]);
    }
}

error_response('Not found', 404);
