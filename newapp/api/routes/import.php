<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — Bulk import (CSV → records)
 * POST /api/import   body: { type: 'users', rows: [ {col:val,...}, ... ] }   (0001-A004: users only)
 * Returns { type, total, created, skipped, errors:[{row, reason}] }
 */
$type = '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$type = $body['type'] ?? '';
$rows = $body['rows'] ?? [];

// ---- authorization ----
itops_require($currentUser, 'import', 'view');
if ($type === 'users') {
    itops_require($currentUser, 'users', 'create');
} else {
    error_response('Unknown import type', 400);
}
if (!is_array($rows) || !count($rows)) error_response('No rows to import', 400);
if (count($rows) > 5000) error_response('Too many rows (max 5000 per import)', 400);
// Each account needs a slow password hash: keep user imports small enough to finish, and ask for more time (review WORK-3).
if (($type ?? '') === 'users' && count($rows) > 500) error_response('Too many rows for people (max 500 per import). Split the file and import it in parts.', 400);
@set_time_limit(300);

$db = getDB();

// normalise a row's keys to lower-case trimmed, and provide an alias getter
function imp_norm(array $row): array {
    $out = [];
    foreach ($row as $k => $v) { $out[strtolower(trim((string)$k))] = is_string($v) ? trim($v) : $v; }
    return $out;
}
function imp_get(array $r, array $aliases, $default = '') {
    foreach ($aliases as $a) { if (isset($r[$a]) && $r[$a] !== '') return $r[$a]; }
    return $default;
}

// ---- shared lookups ----
$roles = []; foreach ($db->query("SELECT id,slug,LOWER(name) nm FROM itops_roles") as $r) { $roles['s_'.$r['slug']] = (int)$r['id']; $roles['n_'.$r['nm']] = (int)$r['id']; }
$depts = []; foreach ($db->query("SELECT id,LOWER(name) nm FROM itops_departments") as $r) { $depts[$r['nm']] = (int)$r['id']; }
$usersByEmail = []; foreach ($db->query("SELECT id,LOWER(email) em FROM itops_users") as $r) { $usersByEmail[$r['em']] = (int)$r['id']; }

$created = 0; $skipped = 0; $errors = [];
$made = [];   // BT91: every record created, for the Audit Log (one row each, with what was set; never a secret)
$allDepts = ($currentUser['role_slug'] ?? '') === 'super_admin' || itops_can($currentUser, 'users', 'all');
$n = 0; $auditN = 0;

foreach ($rows as $raw) {
    $n++;
    if (!is_array($raw)) { $errors[] = ['row' => $n, 'reason' => 'Malformed row']; continue; }
    $r = imp_norm($raw);
    try {
        if ($type === 'users') {
            $email = strtolower(imp_get($r, ['email','e-mail','mail']));
            $name  = imp_get($r, ['display_name','name','full_name','displayname']);
            if ($email === '' || $name === '') { $errors[] = ['row'=>$n,'reason'=>'email and display_name are required']; continue; }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[]=['row'=>$n,'reason'=>'invalid email']; continue; }
            if (isset($usersByEmail[$email])) { $skipped++; continue; }
            $roleRaw = strtolower(imp_get($r, ['role','role_slug','role_name']));
            $roleId = $roles['s_'.$roleRaw] ?? $roles['n_'.$roleRaw] ?? ($roles['s_viewer'] ?? null);
            if (!$roleId) { $errors[]=['row'=>$n,'reason'=>'no matching role and no default "viewer" role']; continue; }
            if ($roleId === ($roles['s_super_admin'] ?? -1) && ($currentUser['role_slug'] ?? '') !== 'super_admin') { $errors[]=['row'=>$n,'reason'=>'only a Super Admin can grant the Super Admin role']; continue; }
            if (($currentUser['role_slug'] ?? '') !== 'super_admin' && !itops_role_within($currentUser, (int)$roleId)) { $errors[]=['row'=>$n,'reason'=>'you can only assign roles whose permissions you hold yourself']; continue; }   // BT14
            $deptId = $depts[strtolower(imp_get($r,['department','dept','department_name']))] ?? null;
            if (!$allDepts) {   // BT90 rule: without Users · All records, people are added to your own department only
                $own = (int)($currentUser['department_id'] ?? 0);
                if ($deptId === null) $deptId = $own ?: null;
                elseif ((int)$deptId !== $own) { $errors[]=['row'=>$n,'reason'=>'you can only add people to your own department']; continue; }
            }
            $status = strtolower(imp_get($r,['status'],'active')); if (!in_array($status,['active','inactive','pending'])) $status='active';
            $pw = imp_get($r,['password','temp_password']);
            if ($pw !== '') {   // review WORK-7: a password from the file follows the same rule as any other
                require_once __DIR__ . '/../lib/AuthGuard.php';
                $pp = itops_password_problem($pw, $email, $name); if ($pp !== '') { $errors[] = ['row'=>$n,'reason'=>'password: ' . $pp]; continue; }
            }
            $hash = hash_password($pw !== '' ? $pw : bin2hex(random_bytes(16)));
            $fn = imp_get($r,['first_name','firstname']); $ln = imp_get($r,['last_name','lastname','surname']);
            $db->prepare("INSERT INTO itops_users (email,password_hash,display_name,first_name,last_name,role_id,department_id,status) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$email,$hash,$name,$fn,$ln,$roleId,$deptId,$status]);
            $usersByEmail[$email] = (int)$db->lastInsertId();
            $made[] = ['user', $usersByEmail[$email], $email, ['display_name' => $name, 'role_id' => (int)$roleId, 'department_id' => $deptId, 'status' => $status, 'password' => $pw !== '' ? 'set from the file' : 'random']];
            $created++;
        }
    } catch (Throwable $e) {
        // Review WORK-21: a plain reason for the person; the full message goes to the server log with a reference
        $ref = substr(bin2hex(random_bytes(4)), 0, 8); error_log('[import ' . $ref . '] row ' . $n . ': ' . $e->getMessage());
        $st = $e instanceof PDOException ? (string)($e->errorInfo[1] ?? '') : '';
        $why = ['1062' => 'already exists (a duplicate)', '1406' => 'a value is too long', '1292' => 'a date or number is not valid', '1366' => 'a value is not valid', '1452' => 'refers to something that does not exist'][$st] ?? 'could not be saved';
        $errors[] = ['row' => $n, 'reason' => $why . ' (ref ' . $ref . ')'];
    }
    // Audit each record as soon as it exists, so a stopped import never leaves records nobody logged (review WORK-3).
    while ($auditN < count($made)) { $m = $made[$auditN++]; audit_log('imported', $m[0], $m[1], (string)$m[2], null, $m[3]); }
}

audit_log('bulk_import', $type, null, "created=$created skipped=$skipped errors=" . count($errors), null,
    ['rows' => $n, 'created' => array_slice(array_map(fn($m) => $m[2], $made), 0, 200), 'errors' => array_slice($errors, 0, 50)]);
json_response(['type' => $type, 'total' => $n, 'created' => $created, 'skipped' => $skipped, 'errors' => $errors]);
