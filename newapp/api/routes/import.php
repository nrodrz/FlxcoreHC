<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — Bulk import (CSV → records)
 * POST /api/import   body: { type: 'users'|'vendors'|'contracts'|'credentials', rows: [ {col:val,...}, ... ] }
 * Returns { type, total, created, skipped, errors:[{row, reason}] }
 */
$type = '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$type = $body['type'] ?? '';
$rows = $body['rows'] ?? [];

// ---- authorization ----
$permMap = ['vendors' => 'vendors', 'contracts' => 'contracts', 'credentials' => 'creds'];
itops_require($currentUser, 'import', 'view');
if ($type === 'users') {
    itops_require($currentUser, 'users', 'create');
} elseif (isset($permMap[$type])) {
    itops_require($currentUser, $permMap[$type], 'create');
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
function imp_date($v) {
    $v = trim((string)$v); if ($v === '') return null;
    $ts = strtotime($v); return $ts ? date('Y-m-d', $ts) : null;
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
            if (!function_exists('itops_claim_tickets')) require_once __DIR__ . '/../lib/Requesters.php';
            itops_claim_tickets($db, $usersByEmail[$email], $email);
            $created++;
        }
        elseif ($type === 'vendors') {
            $name = imp_get($r,['name','vendor','vendor_name']);
            if ($name === '') { $errors[]=['row'=>$n,'reason'=>'name is required']; continue; }
            $dup = $db->prepare("SELECT id FROM itops_vendors WHERE name=? LIMIT 1"); $dup->execute([$name]);
            if ($dup->fetchColumn()) { $skipped++; continue; }
            $risk = strtolower(imp_get($r,['risk_tier','risk'],'medium')); if (!in_array($risk,['low','medium','high','critical'])) $risk='medium';
            $status = strtolower(imp_get($r,['status'],'active')); if (!in_array($status,['active','inactive','under_review'])) $status='active';
            $db->prepare("INSERT INTO itops_vendors (name,website,primary_contact_name,primary_contact_email,primary_contact_phone,category,risk_tier,notes,status) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$name, imp_get($r,['website','url']), imp_get($r,['contact_name','primary_contact_name','contact']), imp_get($r,['contact_email','primary_contact_email','email']), imp_get($r,['contact_phone','primary_contact_phone','phone']), imp_get($r,['category','type']), $risk, imp_get($r,['notes','description']), $status]);
            $made[] = ['vendor', (int)$db->lastInsertId(), $name, ['risk_tier' => $risk, 'status' => $status]];
            $created++;
        }
        elseif ($type === 'contracts') {
            $title = imp_get($r,['title','name','contract','contract_name']);
            if ($title === '') { $errors[]=['row'=>$n,'reason'=>'title is required']; continue; }
            $sd = imp_date(imp_get($r,['start_date','start','begins'])); $ed = imp_date(imp_get($r,['end_date','end','expires','expiry']));
            if (!$sd || !$ed) { $errors[]=['row'=>$n,'reason'=>'valid start_date and end_date are required']; continue; }
            $vname = imp_get($r,['vendor','vendor_name','supplier']); $vid = null;
            if ($vname !== '') { $vq=$db->prepare("SELECT id FROM itops_vendors WHERE name=? LIMIT 1"); $vq->execute([$vname]); $vid=$vq->fetchColumn() ?: null; }
            $ownerEmail = strtolower(imp_get($r,['owner','owner_email'])); $oid = $ownerEmail!=='' ? ($usersByEmail[$ownerEmail] ?? null) : null;
            $status = strtolower(imp_get($r,['status'],'active'));
            if (!in_array($status,['active','expiring','expired','renewed','terminated','draft'])) $status='active';
            $val = (float)preg_replace('/[^0-9.\-]/','', (string)imp_get($r,['total_value','value','amount'],'0'));
            $db->prepare("INSERT INTO itops_contracts (title,vendor_id,contract_number,department_id,status,start_date,end_date,total_value,owner_id,notes) VALUES (?,?,?,?,?,?,?,?,?,?)")
               ->execute([$title, $vid, imp_get($r,['contract_number','number','ref']), $depts[strtolower(imp_get($r,['department','dept']))] ?? null, $status, $sd, $ed, $val, $oid, imp_get($r,['notes','description'])]);
            $made[] = ['contract', (int)$db->lastInsertId(), $title, ['vendor_id' => $vid, 'status' => $status, 'start_date' => $sd, 'end_date' => $ed, 'total_value' => $val, 'owner_id' => $oid]];
            $created++;
        }
        elseif ($type === 'credentials') {
            $name = imp_get($r,['name','title','system']);
            if ($name === '') { $errors[]=['row'=>$n,'reason'=>'name is required']; continue; }
            $ownerEmail = strtolower(imp_get($r,['owner','owner_email'])); $oid = $ownerEmail!=='' ? ($usersByEmail[$ownerEmail] ?? $currentUser['id']) : $currentUser['id'];
            $env = strtolower(imp_get($r,['environment','env'],'production')); if (!in_array($env,['production','staging','development','other'])) $env='production';
            $rot = (int)preg_replace('/[^0-9]/','',(string)imp_get($r,['rotation_days'],'90')); if ($rot<=0) $rot=90;
            $uEnc = ($v=imp_get($r,['username','user','login']))!=='' ? vault_encrypt($v) : null;
            $pEnc = ($v=imp_get($r,['password','pass','secret']))!=='' ? vault_encrypt($v) : null;
            $kEnc = ($v=imp_get($r,['api_key','apikey','key','token']))!=='' ? vault_encrypt($v) : null;
            $nEnc = ($v=imp_get($r,['notes','note']))!=='' ? vault_encrypt($v) : null;
            $db->prepare("INSERT INTO itops_credentials (name,description,system_url,username_enc,password_enc,api_key_enc,notes_enc,environment,owner_id,rotation_days) VALUES (?,?,?,?,?,?,?,?,?,?)")
               ->execute([$name, imp_get($r,['description','desc']), imp_get($r,['system_url','url','website']), $uEnc, $pEnc, $kEnc, $nEnc, $env, $oid, $rot]);
            $made[] = ['credential', (int)$db->lastInsertId(), $name, ['environment' => $env, 'owner_id' => (int)$oid, 'rotation_days' => $rot,
                       'fields' => implode(', ', array_keys(array_filter(['username' => $uEnc, 'password' => $pEnc, 'api_key' => $kEnc, 'notes' => $nEnc])))]];
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
