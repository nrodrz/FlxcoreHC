<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub BT134 — Microsoft 365 → Devices → Personal computers (lib/PersonalPcs.php).
 *
 *   GET    /api/pcprog                       the program: setup steps, group, policies, rules, consent text, packages, people
 *   GET    /api/pcprog/devices               the people's computers as Microsoft Defender sees them (read only)
 *   POST   /api/pcprog/step                  {step: scope|intune, done}  a switch with no API, confirmed by the admin
 *   POST   /api/pcprog/group                 create the dynamic device group (Defender-only computers)
 *   PUT    /api/pcprog/rules                 {av, fw, push: [av, fw]}  save the rules; push = create / update the policies
 *   PUT    /api/pcprog/consent               {text}  the text people agree to (a new version; earlier agreements keep theirs)
 *   POST   /api/pcprog/package/<kind>        {name, data (base64)}  win_on | mac_on | win_off
 *   DELETE /api/pcprog/package/<kind>
 *   POST   /api/pcprog/invite                {emails[], note}  a personal link each (shown once), sent by email
 *   POST   /api/pcprog/people/<id>/resend    a new link (the old one stops working)
 *   POST   /api/pcprog/people/<id>/remove    {confirm: REMOVE}  take the person off the list (their computer is not touched)
 *
 * Reading needs Microsoft 365 (view). Changes need "Microsoft 365 · Device configuration" (m365_admin, edit); packages
 * and the consent text a Super Admin. Every change and refusal is in the Audit Log; package contents never are.
 */
require_once __DIR__ . '/../lib/PersonalPcs.php';
require_once __DIR__ . '/../lib/Graph.php';

$db = getDB();
$what = (string)($segments[1] ?? ''); $arg = (string)($segments[2] ?? ''); $verb = (string)($segments[3] ?? '');
$body = in_array($method, ['POST', 'PUT'], true) ? json_decode(file_get_contents('php://input'), true) : [];
if (in_array($method, ['POST', 'PUT'], true) && !is_array($body)) error_response('The request is not in the expected form (a JSON object). Nothing was changed.', 400);
$isSuper = (($currentUser['role_slug'] ?? '') === 'super_admin');
$canView = opsh_can($currentUser, 'm365', 'view');
$canEdit = opsh_can($currentUser, 'm365_admin', 'edit');
$uid = (int)($currentUser['id'] ?? 0); $me = (string)($currentUser['display_name'] ?? '');
$refuse = function (string $what, string $why, int $code = 400) { audit_log('pc_program_refused', 'm365', null, mb_substr($what, 0, 250), null, ['Why' => $why]); error_response($why, $code); };
$needEdit = function (string $what) use ($canEdit, $currentUser) { if (!$canEdit) { opsh_refusal_log($currentUser, 'm365_admin', 'edit', $what); error_response('This needs "Microsoft 365 · Device configuration" with Edit.', 403); } };
$needSuper = function (string $what) use ($isSuper, $currentUser) { if (!$isSuper) { opsh_refusal_log($currentUser, 'super_admin', 'edit', $what); error_response('Only a Super Admin can do this.', 403); } };
if (!$canView && !$canEdit) { opsh_refusal_log($currentUser, 'm365', 'view'); error_response('Insufficient permissions', 403); }
if (!opsh_pc_ready($db)) error_response('Run db-update.php once (Settings → Maintenance → Database update): this build adds the personal computers list. Then reload.', 409);
$base = defined('APP_URL') && preg_match('#^https?://#', (string)APP_URL) ? rtrim((string)APP_URL, '/') : '';
$linkOf = fn(string $tok) => $base . '/pc.html?t=' . $tok;

$payload = function () use ($db, $canEdit, $isSuper): array {
    $c = opsh_pc_cfg($db);
    $people = array_map('opsh_pc_row', $db->query('SELECT * FROM opsh_pc_people ORDER BY (status = \'withdrawn\'), invited_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC));
    foreach ($people as &$p) { $p['problems'] = $p['report'] ? opsh_pc_problems($p['report']) : []; } unset($p);
    $state = null; if (opsh_graph_configured() && ($c['policies']['av']['id'] ?? $c['policies']['fw']['id'] ?? '')) { try { $state = opsh_pc_policy_state($c); } catch (Throwable $e) {} }
    return ['configured' => opsh_graph_configured(), 'steps' => (object)$c['steps'], 'group' => ['id' => $c['group_id'], 'name' => $c['group_name']], 'policies' => (object)$c['policies'], 'policy_state' => $state,
            'av' => $c['av'], 'fw' => $c['fw'], 'av_fields' => array_map(fn($k, $f) => ['key' => $k, 'label' => $f[0], 'kind' => $f[1], 'options' => $f[1] === 'choice' ? array_map(fn($v, $l) => [(string)$v, $l], array_keys($f[2]), $f[2]) : null, 'range' => $f[1] === 'int' ? $f[2] : null], array_keys(opsh_pc_av_fields()), opsh_pc_av_fields()),
            'consent' => ['text' => trim((string)$c['consent']) !== '' ? (string)$c['consent'] : opsh_pc_default_consent(), 'custom' => trim((string)$c['consent']) !== '', 'version' => (int)$c['consent_version']],
            'packages' => opsh_pc_pkg_public($db), 'people' => $people, 'can_edit' => $canEdit, 'is_super' => $isSuper, 'link_days' => OPSH_PC_LINK_DAYS];
};

if ($method === 'GET' && $what === '') json_response($payload());

if ($method === 'GET' && $what === 'devices') {
    if (!opsh_graph_configured()) json_response(['configured' => false, 'devices' => (object)[]]);
    $names = []; foreach ($db->query("SELECT report FROM opsh_pc_people WHERE status = 'consented' AND report IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $r) { $j = json_decode((string)$r, true); if (!empty($j['computer'])) $names[] = $j['computer']; }
    try { json_response(['configured' => true, 'ok' => true, 'devices' => (object)opsh_pc_defender_devices($names), 'read_at' => date('c')]); }
    catch (Throwable $e) { json_response(['configured' => true, 'ok' => false, 'needs_permission' => preg_match('/HTTP (401|403)/', $e->getMessage()) ? 'ThreatHunting.Read.All' : null, 'error' => mb_substr($e->getMessage(), 0, 300), 'devices' => (object)[]]); }
}

if ($method === 'POST' && $what === 'step') {
    $needEdit('confirm a setup step of personal computers');
    $st = (string)($body['step'] ?? ''); if (!in_array($st, ['scope', 'intune'], true)) error_response('Unknown step.', 400);
    if (!is_bool($body['done'] ?? null)) error_response('The request is not in the expected form. Nothing was changed.', 400);
    $c = opsh_pc_cfg($db); $was = !empty($c['steps'][$st]);
    if ($body['done']) $c['steps'][$st] = ['at' => date('c'), 'by' => $me]; else unset($c['steps'][$st]);
    opsh_pc_cfg_put($db, $c);
    $label = $st === 'scope' ? 'Defender portal: enforcement scope' : 'Intune: Defender may enforce endpoint security';
    audit_log('pc_program_step', 'm365', null, 'Personal computers - ' . $label, ['Confirmed' => $was ? 'yes' : 'no'], ['Confirmed' => $body['done'] ? 'yes' : 'no']);
    json_response(['ok' => true] + $payload());
}

if ($method === 'POST' && $what === 'group') {
    $needEdit('create the personal computers group');
    if (!opsh_graph_configured()) error_response('Microsoft 365 is not connected.', 409);
    $c = opsh_pc_cfg($db);
    if ($c['group_id'] !== '') {
        try { opsh_graph_get('groups/' . rawurlencode($c['group_id']) . '?$select=id'); $refuse('Personal computers - device group', 'The group already exists (' . $c['group_name'] . '). Nothing was changed.', 409); }
        catch (RuntimeException $e) { if (!preg_match('/HTTP 404/', $e->getMessage())) error_response('Microsoft could not be asked about the group: ' . $e->getMessage(), 502); }
    }
    try { [$gid, $gname] = opsh_pc_make_group(); }
    catch (Throwable $e) { $refuse('Personal computers - device group', 'Microsoft did not create the group: ' . mb_substr($e->getMessage(), 0, 300) . (preg_match('/HTTP 403/', $e->getMessage()) ? ' The app needs Group.ReadWrite.All.' : ''), 502); }
    $c['group_id'] = $gid; $c['group_name'] = $gname; opsh_pc_cfg_put($db, $c);
    audit_log('pc_program_group', 'm365', null, 'Personal computers - device group', null, ['Group' => $gname, 'Rule' => 'device.managementType -eq "MicrosoftSense"']);
    json_response(['ok' => true] + $payload());
}

if ($method === 'PUT' && $what === 'rules') {
    $needEdit('change the antivirus or firewall rules of personal computers');
    $c = opsh_pc_cfg($db);
    try { $av = array_key_exists('av', $body) ? opsh_pc_av_clean($body['av']) : $c['av']; }
    catch (InvalidArgumentException $e) { $refuse('Personal computers - antivirus rules', $e->getMessage()); }
    $fw = $c['fw'];
    if (array_key_exists('fw', $body)) { if (!is_array($body['fw']) || !is_bool($body['fw']['enable'] ?? null) || !is_bool($body['fw']['block_inbound'] ?? null)) error_response('The firewall choice is not in the expected form.', 400);
        $fw = ['enable' => $body['fw']['enable'], 'block_inbound' => $body['fw']['block_inbound']]; }
    $push = array_values(array_intersect(['av', 'fw'], is_array($body['push'] ?? null) ? $body['push'] : []));
    [$o, $n] = (function ($a, $b) { $o = []; $n = []; foreach ($b as $k => $v) if (($a[$k] ?? null) !== $v) { $o[$k] = $a[$k] ?? ''; $n[$k] = $v; } return [$o, $n]; })(opsh_pc_av_view($c['av']) + ['Firewall on' => $c['fw']['enable'] ? 'yes' : 'no', 'Block inbound' => $c['fw']['block_inbound'] ? 'yes' : 'no'],
                                                                                                                      opsh_pc_av_view($av) + ['Firewall on' => $fw['enable'] ? 'yes' : 'no', 'Block inbound' => $fw['block_inbound'] ? 'yes' : 'no']);
    $c['av'] = $av; $c['fw'] = $fw; opsh_pc_cfg_put($db, $c);
    if ($o) audit_log('pc_program_rules', 'm365', null, 'Personal computers - rules', $o, $n);
    $done = [];
    foreach ($push as $w) {
        if (!opsh_graph_configured()) error_response('Saved here, but Microsoft 365 is not connected, so nothing was sent to Intune.', 409);
        try { $r = opsh_pc_push($w, $c); }
        catch (InvalidArgumentException $e) { $refuse('Personal computers - ' . ($w === 'av' ? 'antivirus' : 'firewall') . ' policy', 'Saved here, not sent: ' . $e->getMessage(), 409); }
        catch (Throwable $e) { $refuse('Personal computers - ' . ($w === 'av' ? 'antivirus' : 'firewall') . ' policy', 'Saved here, but Intune did not take the policy: ' . mb_substr($e->getMessage(), 0, 300), 502); }
        $c = opsh_pc_cfg($db); $c['policies'][$w] = ['id' => $r['id'], 'at' => date('c'), 'by' => $me, 'skipped' => $r['skipped']]; opsh_pc_cfg_put($db, $c);
        audit_log('pc_program_policy', 'm365', null, opsh_pc_policy_names()[$w], null, ['Intune policy' => $r['created'] ? 'created and assigned' : 'updated and assigned', 'Group' => $c['group_name']] + ($r['skipped'] ? ['Not in Microsoft\'s template' => implode(', ', $r['skipped'])] : []));
        $done[$w] = $r;
    }
    json_response(['ok' => true, 'pushed' => $done] + $payload());
}

if ($method === 'PUT' && $what === 'consent') {
    $needSuper('change the consent text of personal computers');
    $t = is_string($body['text'] ?? null) ? trim(str_replace("\r", '', $body['text'])) : null;
    if ($t === null || mb_strlen($t) < 200 || mb_strlen($t) > 8000) error_response('The text must be 200 to 8,000 characters. Nothing was saved.', 400);
    if (preg_match('/<\s*(script|iframe|object)/i', $t)) error_response('The text is shown as plain text; remove the HTML. Nothing was saved.', 400);
    $c = opsh_pc_cfg($db); $old = trim((string)$c['consent']) !== '' ? (string)$c['consent'] : opsh_pc_default_consent();
    if ($t === $old) error_response('The text is the same. Nothing was changed.', 400);
    $c['consent'] = $t; $c['consent_version'] = (int)$c['consent_version'] + 1; opsh_pc_cfg_put($db, $c);
    audit_log('pc_program_consent', 'm365', null, 'Personal computers - consent text', ['Version' => (string)($c['consent_version'] - 1), 'Text' => mb_substr($old, 0, 1500)], ['Version' => (string)$c['consent_version'], 'Text' => mb_substr($t, 0, 1500)]);
    json_response(['ok' => true] + $payload());
}

if ($what === 'package' && in_array($method, ['POST', 'DELETE'], true)) {
    $needSuper('change a Defender package of personal computers');
    $kinds = opsh_pc_pkg_kinds(); if (!isset($kinds[$arg])) error_response('Unknown package.', 404);
    if ($method === 'DELETE') {
        $had = (bool)opsh_pc_pkg_get($db, $arg); $db->prepare('DELETE FROM opsh_settings WHERE skey=?')->execute(['pc_pkg_' . $arg]);
        audit_log('pc_program_package', 'm365', null, 'Personal computers - ' . $kinds[$arg][0], ['Saved' => $had ? 'yes' : 'no'], ['Saved' => 'deleted']);
        json_response(['ok' => true] + $payload());
    }
    $raw = is_string($body['data'] ?? null) ? base64_decode($body['data'], true) : false;
    if ($raw === false) error_response('The file did not arrive. Choose it again.', 400);
    try { opsh_pc_pkg_put($db, $arg, (string)($body['name'] ?? ''), $raw, $me); }
    catch (InvalidArgumentException $e) { $refuse('Personal computers - ' . $kinds[$arg][0], $e->getMessage()); }
    catch (RuntimeException $e) { error_response($e->getMessage(), 409); }
    audit_log('pc_program_package', 'm365', null, 'Personal computers - ' . $kinds[$arg][0], null, ['Saved' => 'yes', 'File' => preg_replace('/[^A-Za-z0-9._ -]/', '', basename((string)($body['name'] ?? ''))), 'Size' => strlen($raw) . ' bytes']);
    json_response(['ok' => true] + $payload());
}

if ($method === 'POST' && $what === 'invite') {
    $needEdit('invite people to protect their personal computers');
    if ($base === '') error_response('The app\'s address (APP_URL in config.php) is not set, so no link can be made.', 409);
    $emails = array_values(array_unique(array_map(fn($e) => mb_strtolower(trim((string)$e)), is_array($body['emails'] ?? null) ? $body['emails'] : [])));
    $emails = array_values(array_filter($emails, fn($e) => $e !== ''));
    if (!$emails) error_response('Enter at least one work email.', 400);
    if (count($emails) > 100) error_response('Invite at most 100 people at a time.', 400);
    $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 500);
    require_once __DIR__ . '/../lib/EmailTemplates.php';
    $out = [];
    foreach ($emails as $e) {
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) { $out[] = ['email' => $e, 'ok' => false, 'error' => 'not an email']; continue; }
        $q = $db->prepare("SELECT id FROM opsh_pc_people WHERE email=? AND status IN ('invited','consented')"); $q->execute([$e]);
        if ($q->fetchColumn()) { $out[] = ['email' => $e, 'ok' => false, 'error' => 'already on the list (use "New link")']; continue; }
        $name = ''; try { $u = $db->prepare('SELECT display_name FROM opsh_users WHERE email=?'); $u->execute([$e]); $name = (string)$u->fetchColumn(); } catch (Throwable $x) {}
        $tok = opsh_pc_new_token();
        $db->prepare("INSERT INTO opsh_pc_people (email, person_name, token_hash, status, invited_by, invited_at, expires_at) VALUES (?,?,?,'invited',?,NOW(),DATE_ADD(NOW(), INTERVAL " . (int)OPSH_PC_LINK_DAYS . " DAY))")
           ->execute([$e, $name, hash('sha256', $tok), $uid]);
        $id = (int)$db->lastInsertId();
        $m = opsh_email('pc_protect_invite', $e, ['pc_link' => $linkOf($tok), 'note' => $note] + ($name !== '' ? ['recipient_name' => $name] : []));
        audit_log('pc_program_invite', 'm365', $id, $e . ' - personal computer', null, ['Invited' => 'yes', 'Email' => !empty($m['ok']) ? 'sent' : 'not sent: ' . mb_substr((string)($m['error'] ?? ''), 0, 120)]);
        $out[] = ['email' => $e, 'ok' => true, 'link' => $linkOf($tok), 'emailed' => !empty($m['ok']), 'email_error' => !empty($m['ok']) ? '' : (string)($m['error'] ?? '')];
    }
    json_response(['ok' => true, 'results' => $out] + $payload());
}

if ($method === 'POST' && $what === 'people' && preg_match('/^\d+$/', $arg)) {
    $needEdit('change a person on the personal computers list');
    $q = $db->prepare('SELECT * FROM opsh_pc_people WHERE id=?'); $q->execute([(int)$arg]); $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) error_response('That person is not on the list any more. Reload.', 404);
    if ($verb === 'resend') {
        if ($r['status'] === 'withdrawn') $refuse($r['email'] . ' - personal computer', 'This person withdrew. Invite them again only if they ask.', 409);
        if ($base === '') error_response('The app\'s address (APP_URL in config.php) is not set, so no link can be made.', 409);
        $tok = opsh_pc_new_token();
        $db->prepare('UPDATE opsh_pc_people SET token_hash=?, expires_at=DATE_ADD(NOW(), INTERVAL ' . (int)OPSH_PC_LINK_DAYS . ' DAY) WHERE id=?')->execute([hash('sha256', $tok), (int)$r['id']]);
        require_once __DIR__ . '/../lib/EmailTemplates.php';
        $m = opsh_email('pc_protect_invite', (string)$r['email'], ['pc_link' => $linkOf($tok), 'note' => ''] + ($r['person_name'] !== '' ? ['recipient_name' => (string)$r['person_name']] : []));
        audit_log('pc_program_invite', 'm365', (int)$r['id'], $r['email'] . ' - personal computer', ['Link' => 'previous'], ['Link' => 'new (the previous one stopped working)', 'Email' => !empty($m['ok']) ? 'sent' : 'not sent']);
        json_response(['ok' => true, 'link' => $linkOf($tok), 'emailed' => !empty($m['ok'])] + $payload());
    }
    if ($verb === 'remove') {
        if (($body['confirm'] ?? null) !== 'REMOVE') error_response('Type REMOVE to take the person off the list. Nothing was changed.', 400);
        $db->prepare('DELETE FROM opsh_pc_people WHERE id=?')->execute([(int)$r['id']]);
        audit_log('pc_program_remove', 'm365', (int)$r['id'], $r['email'] . ' - personal computer', ['Status' => (string)$r['status'], 'Agreed' => $r['consent_at'] ? (string)$r['consent_at'] . ' by ' . $r['consent_name'] : 'no'], ['On the list' => 'removed (the computer was not touched)']);
        json_response(['ok' => true] + $payload());
    }
}
error_response('Not found', 404);
