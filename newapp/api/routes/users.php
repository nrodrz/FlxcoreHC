<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
require_once __DIR__ . '/../lib/CrudEngine.php';
// Access is driven by the role's "users" permission (view/create/edit/delete).
// Legacy dept_admin roles carry users:"dept_manage" which maps to full CRUD.
itops_require($currentUser, 'users', $method==='GET'?'view':($method==='POST'?'create':($method==='PUT'?'edit':($method==='DELETE'?'delete':'view'))));
$isSuper = (($currentUser['role_slug'] ?? '') === 'super_admin');
/**
 * Privilege-escalation guard: only a Super Admin may grant the Super Admin role,
 * or modify / delete an account that already holds it.
 */
/**
 * BT14: a non-Super user may only hand out — or manage accounts holding — roles whose permissions are a SUBSET of
 * their own (no granting yourself or anyone else more than you have), and may never change their own role.
 */
$roleWithin = function (?int $roleId) use ($currentUser): bool { return itops_role_within($currentUser, $roleId); };
$guardRole = function (array $body, ?int $targetId) use ($isSuper, $currentUser, $roleWithin) {
    if ($isSuper) return;
    $db = getDB();
    if ($targetId && $targetId === (int)$currentUser['id'] && array_key_exists('role_id', $body) && (int)$body['role_id'] !== (int)$currentUser['role_id'])
        error_response('You cannot change your own role — ask another administrator', 403);
    if (!empty($body['role_id']) && !$roleWithin((int)$body['role_id'])) error_response('You can only assign roles whose permissions you hold yourself', 403);
    if ($targetId && $targetId !== (int)$currentUser['id']) {
        $st = $db->prepare('SELECT role_id FROM itops_users WHERE id=?'); $st->execute([$targetId]);
        if (!$roleWithin((int)($st->fetchColumn() ?: 0))) error_response('That account has permissions you don\'t hold — ask a Super Admin to change it', 403);
    }
    if (!empty($body['role_id'])) {
        $st = $db->prepare('SELECT slug FROM itops_roles WHERE id=?'); $st->execute([(int)$body['role_id']]);
        if (($st->fetchColumn() ?: '') === 'super_admin') error_response('Only a Super Admin can grant the Super Admin role', 403);
    }
    if ($targetId) {
        $st = $db->prepare('SELECT r.slug FROM itops_users u LEFT JOIN itops_roles r ON r.id=u.role_id WHERE u.id=?'); $st->execute([$targetId]);
        if (($st->fetchColumn() ?: '') === 'super_admin') error_response('Only a Super Admin can modify a Super Admin account', 403);
    }
};
/**
 * BT90 (code audit M-12): without Users · All records (the Super Admin always has it) a person manages only the accounts
 * of their own department: the list, opening, adding (in their department), changing and deleting. Who signs in how
 * (auth_provider) and allowing a Microsoft link (o365_link_ok) are Super Admin only. Every refusal is in the Audit Log.
 */
$uPerm = json_decode((string)($currentUser['role_perms'] ?? ''), true);
$allDept = $isSuper || (itops_can($currentUser, 'users', 'all') && !(is_array($uPerm) && ($uPerm['users'] ?? null) === 'dept_manage'));   // an older role saved as "dept_manage" is a department admin
$myDept = (int)($currentUser['department_id'] ?? 0);
$uRefuse = function (?int $tid, string $wanted, string $why, string $msg, int $code = 403) {
    audit_log('user_refused', 'user', $tid, mb_substr($wanted, 0, 250), null, ['Wanted' => $wanted, 'Why' => $why, 'Result' => 'Nothing was changed']);
    error_response($msg, $code);
};
$inMyDept = function (int $tid) use ($allDept, $myDept): bool {
    if ($allDept) return true;
    $q = getDB()->prepare('SELECT department_id FROM itops_users WHERE id=?'); $q->execute([$tid]); $d = $q->fetchColumn();
    if ($d === false) return true;   // not found: the 404 comes from the normal path
    return $myDept > 0 && (int)$d === $myDept;
};
$editable = ['email','display_name','first_name','last_name','avatar_path','role_id','department_id','status','language'];
if ($isSuper) { $editable[] = 'auth_provider'; $editable[] = 'o365_link_ok'; }
$crud = new CrudEngine('itops_users','user',
  ['email','display_name','first_name','last_name','avatar_path','role_id','department_id','auth_provider','status','language'],
  ['t.email','t.display_name','t.first_name','t.last_name'], $currentUser);
$joins = 'LEFT JOIN itops_roles r ON t.role_id=r.id LEFT JOIN itops_departments d ON t.department_id=d.id';
$extra = 'r.name as role_name, r.slug as role_slug, d.name as dept_name';
switch($method){
  case 'GET':
    if ($id) { if (!$inMyDept((int)$id)) error_response('Record not found', 404); json_response($crud->getById((int)$id,$joins,$extra)); }
    if ($allDept) json_response($crud->listAll($_GET,$joins,$extra) + ['all_departments' => true]);
    json_response($crud->listAll($_GET,$joins,$extra, 't.department_id = ?', [$myDept ?: -1]) + ['all_departments' => false]);
    break;
  case 'POST':
    $body=json_decode(file_get_contents('php://input'),true);
    if (!is_array($body)) error_response('JSON object expected', 400);
    unset($body['password_hash']);   // BT14: only a plain 'password' is accepted (hashed here)
    if (empty($body['email'])) error_response('Email required');
    $guardRole($body, null);
    if (!$isSuper) { unset($body['auth_provider'], $body['o365_link_ok']); }
    if (!$allDept) {   // BT90: added in the person's own department
      if ($myDept <= 0) $uRefuse(null, 'Add the account ' . (string)$body['email'], 'no department of their own', 'Your account has no department, so you can add accounts only with Users · All records.');
      if (!empty($body['department_id']) && (int)$body['department_id'] !== $myDept) $uRefuse(null, 'Add the account ' . (string)$body['email'] . ' in another department', 'another department', 'You can add accounts in your own department only.');
      $body['department_id'] = $myDept;
    }
    // Check duplicate
    $stmt=getDB()->prepare('SELECT id FROM itops_users WHERE email=?'); $stmt->execute([$body['email']]);
    if ($stmt->fetch()) error_response('Email already exists');
    if (!empty($body['password'])) { require_once __DIR__ . '/../lib/AuthGuard.php'; $pp = itops_password_problem((string)$body['password'], (string)($body['email'] ?? ''), (string)($body['display_name'] ?? '')); if ($pp !== '') error_response($pp, 400); $body['password_hash'] = hash_password($body['password']); unset($body['password']); }   // review CORE-20
    // Add password_hash to allowed fields for this operation
    $userCrud = new CrudEngine('itops_users','user', array_merge(['password_hash'], $editable), [], $currentUser);
    $newUser = $userCrud->create($body);
    require_once __DIR__ . '/../lib/Requesters.php';
    if (!empty($newUser['id'])) itops_claim_tickets(getDB(), (int)$newUser['id'], (string)$body['email']);
    json_response($newUser,201); break;
  case 'PUT':
    $body=json_decode(file_get_contents('php://input'),true);
    if (!is_array($body)) error_response('JSON object expected', 400);
    unset($body['password_hash']);   // BT14: only a plain 'password' is accepted (hashed here)
    $guardRole($body, (int)$id);
    if (!$isSuper && (array_key_exists('auth_provider', $body) || array_key_exists('o365_link_ok', $body))) {
      // an unchanged value (an older page sends the whole form) is simply left out; a change is refused
      $cq = getDB()->prepare('SELECT auth_provider, o365_link_ok FROM itops_users WHERE id=?'); $cq->execute([(int)$id]); $cur = $cq->fetch(PDO::FETCH_ASSOC) ?: [];
      $chg = (array_key_exists('auth_provider', $body) && (string)$body['auth_provider'] !== (string)($cur['auth_provider'] ?? ''))
          || (array_key_exists('o365_link_ok', $body) && (int)!empty($body['o365_link_ok']) !== (int)($cur['o365_link_ok'] ?? 0));
      if ($chg) $uRefuse((int)$id, 'Change how account ' . (int)$id . ' signs in', 'Super Admin only', 'Only a Super Admin changes how an account signs in or allows a Microsoft link.');
      unset($body['auth_provider'], $body['o365_link_ok']);
    }
    if (!$inMyDept((int)$id)) $uRefuse((int)$id, 'Change account ' . (int)$id . ' of another department', 'another department', 'You can change accounts of your own department only.');
    if (!$allDept && array_key_exists('department_id', $body) && (int)$body['department_id'] !== $myDept) $uRefuse((int)$id, 'Move account ' . (int)$id . ' to another department', 'another department', 'You can keep accounts in your own department only.');
    if (array_key_exists('o365_link_ok', $body)) $body['o365_link_ok'] = !empty($body['o365_link_ok']) ? 1 : 0;
    if (!empty($body['password'])) { require_once __DIR__ . '/../lib/AuthGuard.php'; $pp = itops_password_problem((string)$body['password'], (string)($body['email'] ?? ''), (string)($body['display_name'] ?? '')); if ($pp !== '') error_response($pp, 400); $body['password_hash'] = hash_password($body['password']); unset($body['password']); }   // review CORE-20
    $userCrud = new CrudEngine('itops_users','user', array_merge(['password_hash'], $editable), [], $currentUser);
    $pwChanged = !empty($body['password_hash']);
    $deactivated = isset($body['status']) && $body['status'] !== 'active';
    // BT99: a role or department change gets its own Audit Log entry, with the names (the general "updated" entry only
    // carries the numbers)
    $was = getDB()->prepare('SELECT u.display_name, u.email, u.role_id, u.department_id, r.name AS role_name, d.name AS dept_name FROM itops_users u LEFT JOIN itops_roles r ON r.id=u.role_id LEFT JOIN itops_departments d ON d.id=u.department_id WHERE u.id=?');
    $was->execute([(int)$id]); $was = $was->fetch(PDO::FETCH_ASSOC) ?: null;
    $upd = $userCrud->update((int)$id,$body);
    if ($was) {
      $who = (string)($was['display_name'] !== '' ? $was['display_name'] : $was['email']);
      $nm = function (string $t, $v) { if ($v === null || $v === '') return '(none)'; $q = getDB()->prepare("SELECT name FROM $t WHERE id=?"); $q->execute([(int)$v]); return (string)($q->fetchColumn() ?: ('#' . (int)$v)); };
      if (array_key_exists('role_id', $body) && (int)$body['role_id'] !== (int)$was['role_id'])
        audit_log('user_role_changed', 'user', (int)$id, $who, ['Role' => (string)($was['role_name'] ?? '(none)')], ['Role' => $nm('itops_roles', $body['role_id'])]);
      if (array_key_exists('department_id', $body) && (int)$body['department_id'] !== (int)$was['department_id'])
        audit_log('user_department_changed', 'user', (int)$id, $who, ['Department' => (string)($was['dept_name'] ?? '(none)')], ['Department' => $nm('itops_departments', $body['department_id'])]);
    }
    // BT14: a new password or deactivation ends the person's other sessions immediately
    // (an admin changing their OWN password keeps the session they are using).
    if ($pwChanged || $deactivated) {
      $keep = ((int)$id === (int)$currentUser['id'] && !$deactivated) ? (string)($_SERVER['ITOPS_TOKEN'] ?? '') : '';
      $nRev = itops_sessions_revoke(getDB(), (int)$id, $keep);
      audit_log('sessions_revoked', 'user', (int)$id, ($pwChanged ? 'password changed' : 'deactivated') . ' — ' . $nRev . ' session(s) ended');
    }
    // BT80: work left in Cases & Projects by someone deactivated — the admin is told what to hand over
    if ($deactivated && is_file(__DIR__ . '/../lib/Cases.php')) { try {
      require_once __DIR__ . '/../lib/Cases.php'; $dbc = getDB();
      if (itops_cs_has($dbc)) {
        $one = function (string $q) use ($dbc, $id) { $st = $dbc->prepare($q); $st->execute([(int)$id]); return (int)$st->fetchColumn(); };
        $own = $one("SELECT COUNT(*) FROM itops_cases WHERE owner_id=? AND status IN ('open','in_progress')");
        $spn = function_exists('itops_cs_has6') && itops_cs_has6($dbc) ? $one("SELECT COUNT(*) FROM itops_cases WHERE sponsor_id=? AND status IN ('open','in_progress')") : 0;
        $tsk = $one("SELECT COUNT(*) FROM itops_case_tasks t JOIN itops_cases c ON c.id=t.case_id WHERE t.assignee_id=? AND t.kind<>'requester' AND t.status IN ('open','in_progress','waiting') AND c.status IN ('open','in_progress')");
        if ($own || $spn || $tsk) {
          $nm = $dbc->prepare('SELECT display_name FROM itops_users WHERE id=?'); $nm->execute([(int)$id]); $who = (string)$nm->fetchColumn();
          $parts = array_filter([$own ? $own . ' open case' . ($own === 1 ? '' : 's') . ' or project' . ($own === 1 ? '' : 's') . ' they own' : '', $spn ? $spn . ' project' . ($spn === 1 ? '' : 's') . ' they sponsor' : '', $tsk ? $tsk . ' open task' . ($tsk === 1 ? '' : 's') : '']);
          $msg = $who . ' still has ' . implode(', ', $parts) . '. Hand them over in Cases & Projects (a new owner, sponsor or assignee).';
          $dbc->prepare('INSERT INTO itops_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)')->execute([(int)$currentUser['id'], 'Case work to hand over', $msg, 'case', 'cases']);
          audit_log('user_case_work_left', 'user', (int)$id, $who, null, ['owns' => $own, 'sponsors' => $spn, 'open tasks' => $tsk]);
          if (is_array($upd)) $upd['case_work'] = ['owns' => $own, 'sponsors' => $spn, 'tasks' => $tsk, 'message' => $msg];
        }
      }
    } catch (Throwable $e) {} }
    json_response($upd); break;
  case 'DELETE':
    if ((int)$id === (int)$currentUser['id']) error_response('You cannot delete your own account');
    $guardRole([], (int)$id);
    if (!$inMyDept((int)$id)) $uRefuse((int)$id, 'Delete account ' . (int)$id . ' of another department', 'another department', 'You can delete accounts of your own department only.');
    // BT86 (code audit M-11): a deleted account is turned off (status Inactive — the list of statuses has no "archived");
    // its sessions end first, its history (tickets, cases, the Audit Log) keeps the name.
    { $dbd = getDB(); $q = $dbd->prepare('SELECT id, email, display_name, status FROM itops_users WHERE id=?'); $q->execute([(int)$id]); $ud = $q->fetch(PDO::FETCH_ASSOC);
      if (!$ud) error_response('Record not found', 404);
      itops_sessions_revoke($dbd, (int)$id);
      $dbd->prepare("UPDATE itops_users SET status='inactive' WHERE id=?")->execute([(int)$id]);
      audit_log('deleted', 'user', (int)$id, (string)($ud['display_name'] ?: $ud['email']), ['status' => $ud['status']], ['status' => 'inactive (account deleted: turned off, sessions ended)']);
      if (function_exists('itops_emit')) { try { itops_emit('user.deleted', ['id' => (int)$id, 'email' => $ud['email'], 'display_name' => $ud['display_name']]); } catch (Throwable $e) {} } }
    json_response(['ok'=>true]); break;
}
