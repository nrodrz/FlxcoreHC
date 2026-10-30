<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * BT24 — SOP owner and audience.
 *
 * Every SOP has an owner (the person who keeps it current and gets its review reminders) and an
 * audience: "all" (everyone who can open SOPs) or "selected" (chosen people and/or departments).
 * Only people whose role can open SOPs (SOPs · View) can ever be asked to acknowledge one — a role
 * without SOPs is never counted, reminded or shown SOP reviews.
 */

/** Active users whose role can view SOPs: [id => row(id, display_name, email, department_id)]. */
function opsh_sop_readers(PDO $db): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $st = $db->query("SELECT u.id, u.display_name, u.email, u.department_id, r.slug AS role_slug, r.permissions AS role_perms
                      FROM opsh_users u LEFT JOIN opsh_roles r ON r.id = u.role_id WHERE u.status = 'active' ORDER BY u.display_name");
    foreach ($st->fetchAll() as $u) {
        if (!opsh_can($u, 'sops', 'view')) continue;
        $cache[(int)$u['id']] = ['id' => (int)$u['id'], 'display_name' => $u['display_name'], 'email' => $u['email'], 'department_id' => $u['department_id'] ? (int)$u['department_id'] : null];
    }
    return $cache;
}

/** The stored audience of one SOP: ['audience' => all|selected, 'users' => [ids], 'departments' => [ids]]. */
function opsh_sop_audience_get(PDO $db, int $sopId, ?string $audience = null): array {
    if ($audience === null) {
        try { $s = $db->prepare("SELECT audience FROM opsh_sops WHERE id=?"); $s->execute([$sopId]); $audience = (string)($s->fetchColumn() ?: 'all'); }
        catch (\Throwable $e) { $audience = 'all'; }
    }
    $out = ['audience' => $audience === 'selected' ? 'selected' : 'all', 'users' => [], 'departments' => []];
    try {
        $s = $db->prepare("SELECT kind, ref_id FROM opsh_sop_audience WHERE sop_id=?"); $s->execute([$sopId]);
        foreach ($s->fetchAll() as $r) {
            if ($r['kind'] === 'user') $out['users'][] = (int)$r['ref_id'];
            elseif ($r['kind'] === 'department') $out['departments'][] = (int)$r['ref_id'];
        }
    } catch (\Throwable $e) { /* table not created yet (database update pending) */ }
    return $out;
}

/** Validate an audience in a request body before anything is written. */
function opsh_sop_audience_validate(array $body): void {
    if (($body['audience'] ?? 'all') !== 'selected') return;
    $users = array_filter(array_map('intval', (array)($body['audience_users'] ?? [])));
    $depts = array_filter(array_map('intval', (array)($body['audience_departments'] ?? [])));
    if (!$users && !$depts) error_response('Choose at least one person or department, or set the audience to "Everyone who can open SOPs".');
}

/** Save the audience of one SOP from a request body (audience, audience_users[], audience_departments[]). */
function opsh_sop_audience_save(PDO $db, int $sopId, array $body): void {
    if (!array_key_exists('audience', $body) && !array_key_exists('audience_users', $body) && !array_key_exists('audience_departments', $body)) return;
    $aud = ($body['audience'] ?? 'all') === 'selected' ? 'selected' : 'all';
    $users = array_values(array_unique(array_filter(array_map('intval', (array)($body['audience_users'] ?? [])))));
    $depts = array_values(array_unique(array_filter(array_map('intval', (array)($body['audience_departments'] ?? [])))));
    if ($aud === 'selected' && !$users && !$depts) error_response('Choose at least one person or department, or set the audience to "Everyone who can open SOPs".');
    $was = opsh_sop_audience_get($db, $sopId);   // review WORK-6: the change of audience is in the Audit Log
    $fmt = function (string $a, array $u, array $d) use ($db): string {
        if ($a !== 'selected') return 'Everyone who can open SOPs';
        $n = []; if ($u) { $q = $db->query('SELECT display_name FROM opsh_users WHERE id IN (' . implode(',', array_map('intval', $u)) . ')'); $n = array_merge($n, $q->fetchAll(PDO::FETCH_COLUMN)); }
        if ($d) { $q = $db->query('SELECT name FROM opsh_departments WHERE id IN (' . implode(',', array_map('intval', $d)) . ')'); $n = array_merge($n, array_map(fn($x) => $x . ' (department)', $q->fetchAll(PDO::FETCH_COLUMN))); }
        sort($n); return mb_substr(implode(', ', $n), 0, 2000);
    };
    $before = $fmt((string)($was['audience'] ?? 'all'), array_map('intval', (array)($was['users'] ?? [])), array_map('intval', (array)($was['departments'] ?? [])));
    $after = $fmt($aud, $users, $depts);
    $db->prepare("UPDATE opsh_sops SET audience=? WHERE id=?")->execute([$aud, $sopId]);
    $db->prepare("DELETE FROM opsh_sop_audience WHERE sop_id=?")->execute([$sopId]);
    if ($aud === 'selected') {
        $ins = $db->prepare("INSERT IGNORE INTO opsh_sop_audience (sop_id, kind, ref_id) VALUES (?,?,?)");
        foreach ($users as $u) $ins->execute([$sopId, 'user', $u]);
        foreach ($depts as $d) $ins->execute([$sopId, 'department', $d]);
    }
    if ($before !== $after && function_exists('audit_log')) audit_log('sop_audience', 'sop', $sopId, 'Audience', ['Audience' => $before], ['Audience' => $after]);
}

/** People who must acknowledge a SOP (readers ∩ audience): [id => row]. */
function opsh_sop_required(PDO $db, int $sopId, ?array $aud = null): array {
    $readers = opsh_sop_readers($db);
    $aud = $aud ?? opsh_sop_audience_get($db, $sopId);
    if ($aud['audience'] !== 'selected') return $readers;
    $u = array_flip($aud['users']); $d = array_flip($aud['departments']); $out = [];
    foreach ($readers as $id => $r) if (isset($u[$id]) || ($r['department_id'] && isset($d[$r['department_id']]))) $out[$id] = $r;
    return $out;
}

/** Whether a SOP is for this user (they are in its audience). */
function opsh_sop_for_user(PDO $db, int $sopId, array $user, ?array $aud = null): bool {
    $aud = $aud ?? opsh_sop_audience_get($db, $sopId);
    if ($aud['audience'] !== 'selected') return true;
    if (in_array((int)$user['id'], $aud['users'], true)) return true;
    return !empty($user['department_id']) && in_array((int)$user['department_id'], $aud['departments'], true);
}

/** Selected people who can't open SOPs (their role lacks SOPs · View) — shown as a warning in the editor. */
function opsh_sop_unreachable(PDO $db, array $userIds): array {
    if (!$userIds) return [];
    $readers = opsh_sop_readers($db); $out = [];
    $in = implode(',', array_map('intval', $userIds));
    foreach ($db->query("SELECT id, display_name FROM opsh_users WHERE id IN ($in) AND status='active'") as $r)
        if (!isset($readers[(int)$r['id']])) $out[] = ['id' => (int)$r['id'], 'display_name' => $r['display_name']];
    return $out;
}
