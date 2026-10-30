<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub BT134 — /api/public_pc (UNAUTHENTICATED; the person's link token is the only credential). Page: pc.html.
 *
 *   GET  /public_pc?t=                what the page shows (state, consent text, the check's result, what is offered)
 *   POST /public_pc?t=                {action: consent, name, agree: true} | {action: withdraw, note}
 *   GET  /public_pc/script?t=         the read-only PowerShell check (text), after agreeing
 *   POST /public_pc/report?t=         what the check found (sent by the check itself)
 *   GET  /public_pc/package?t=&k=     win_on / mac_on after agreeing (and when the computer can use it); win_off after withdrawing
 *
 * The token is 48 hex characters; only its hash is stored. Links stop after OPSH_PC_LINK_DAYS days unless agreed.
 */
require_once __DIR__ . '/../lib/PersonalPcs.php';

$db = getDB();
$sub = (string)($segments[1] ?? '');
$tok = strtolower(trim((string)($_GET['t'] ?? '')));
if (!preg_match('/^[a-f0-9]{48}$/', $tok)) error_response('This link is not complete. Open it again from the email.', 400);
if (!opsh_pc_ready($db)) error_response('This page is not available yet.', 503);
$r = opsh_pc_by_token($db, $tok);
if (!$r) error_response('This link is not valid any more. Ask IT for a new one.', 404);
$who = (string)$r['email'] . ' - personal computer';
$expired = $r['status'] === 'invited' && strtotime((string)$r['expires_at']) < time();
if ($expired) error_response('This link has expired. Ask IT for a new one.', 410);
$ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
$reload = function () use ($db, $r) { $q = $db->prepare('SELECT * FROM opsh_pc_people WHERE id=?'); $q->execute([(int)$r['id']]); return $q->fetch(PDO::FETCH_ASSOC); };

$view = function (array $r) use ($db): array {
    $row = opsh_pc_row($r); $c = opsh_pc_cfg($db); $pk = opsh_pc_pkg_public($db);
    $org = ''; try { if (!function_exists('opsh_org_name')) @include_once __DIR__ . '/../lib/Brand.php'; $org = function_exists('opsh_org_name') ? trim(opsh_org_name($db)) : ''; } catch (Throwable $e) {}
    return ['state' => $row['status'], 'name' => $row['name'], 'email' => $row['email'], 'org' => $org,
            'consent' => opsh_pc_consent_text($db), 'consent_version' => (int)$c['consent_version'], 'agreed' => $row['consent_at'] ? ['at' => $row['consent_at'], 'name' => $row['consent_name'], 'version' => $row['consent_version']] : null,
            'report' => $row['report'], 'reported_at' => $row['reported_at'], 'path' => $row['path'], 'problems' => $row['report'] ? opsh_pc_problems($row['report']) : [],
            'offer' => ['win_on' => $pk['win_on']['saved'] && $row['status'] === 'consented' && $row['path'] === 'pro', 'mac_on' => $pk['mac_on']['saved'] && $row['status'] === 'consented',
                        'win_off' => $pk['win_off']['saved'] && $row['status'] === 'withdrawn'],
            'package_at' => $row['package_at']];
};

if ($sub === '' && $method === 'GET') json_response($view($r));

if ($sub === '' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true); if (!is_array($b)) error_response('The request is not in the expected form.', 400);
    $act = (string)($b['action'] ?? '');
    if ($act === 'consent') {
        if ($r['status'] !== 'invited') error_response($r['status'] === 'consented' ? 'You already agreed.' : 'You withdrew. Ask IT for a new link to join again.', 409);
        $name = trim(preg_replace('/\s+/u', ' ', (string)($b['name'] ?? '')));
        if (($b['agree'] ?? null) !== true) error_response('Tick that you agree.', 400);
        if (mb_strlen($name) < 3 || mb_strlen($name) > 120 || preg_match('/[<>\x00-\x1F]/u', $name)) error_response('Type your full name to agree.', 400);
        $ver = (int)opsh_pc_cfg($db)['consent_version'];
        $db->prepare("UPDATE opsh_pc_people SET status='consented', consent_at=NOW(), consent_name=?, consent_ip=?, consent_version=? WHERE id=? AND status='invited'")->execute([$name, $ip, $ver, (int)$r['id']]);
        audit_log('pc_program_consent_given', 'm365', (int)$r['id'], $who, ['Agreed' => 'no'], ['Agreed' => 'yes', 'Name typed' => $name, 'Text version' => (string)$ver, 'From' => $ip]);
        json_response(['ok' => true] + $view($reload()));
    }
    if ($act === 'withdraw') {
        if ($r['status'] !== 'consented') error_response('There is nothing to withdraw.', 409);
        $note = mb_substr(trim(preg_replace('/[\x00-\x1F]/u', ' ', (string)($b['note'] ?? ''))), 0, 300);
        $db->prepare("UPDATE opsh_pc_people SET status='withdrawn', withdrawn_at=NOW(), withdraw_note=? WHERE id=?")->execute([$note !== '' ? $note : null, (int)$r['id']]);
        audit_log('pc_program_withdrawn', 'm365', (int)$r['id'], $who, ['Agreed' => 'yes'], ['Agreed' => 'withdrawn by the person'] + ($note !== '' ? ['Note' => $note] : []));
        try { $ins = $db->prepare('INSERT INTO opsh_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)');
              foreach ($db->query("SELECT u.id FROM opsh_users u JOIN opsh_roles ro ON ro.id = u.role_id WHERE u.status='active' AND ro.slug='super_admin'")->fetchAll(PDO::FETCH_COLUMN) as $aid)
                  $ins->execute([(int)$aid, 'Personal computer: ' . $r['email'] . ' withdrew', 'Their computer should leave Defender: the offboarding file is on their page (Devices → Personal computers).', 'alert', '#m365_devices']); } catch (Throwable $e) {}
        json_response(['ok' => true] + $view($reload()));
    }
    error_response('Unknown action.', 400);
}

if ($sub === 'script' && $method === 'GET') {
    if ($r['status'] !== 'consented') error_response('Agree on the page first.', 409);
    $base = defined('APP_URL') && preg_match('#^https?://#', (string)APP_URL) ? rtrim((string)APP_URL, '/') : '';
    if ($base === '') error_response('The app\'s address is not set; tell IT.', 503);
    header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
    echo opsh_pc_check_script($base, $tok); exit;
}

if ($sub === 'report' && $method === 'POST') {
    if ($r['status'] !== 'consented') error_response('Agree on the page first.', 409);
    $raw = file_get_contents('php://input');
    if (strlen((string)$raw) > 20000) error_response('Too much was sent.', 413);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string)$raw);
    try { $rep = opsh_pc_clean_report(json_decode($raw, true)); } catch (InvalidArgumentException $e) { error_response($e->getMessage(), 400); }
    $path = opsh_pc_path_of($rep); $old = $r['report'] ? json_decode((string)$r['report'], true) : null;
    $db->prepare('UPDATE opsh_pc_people SET report=?, reported_at=NOW(), reports=reports+1, path=? WHERE id=?')->execute([json_encode($rep, JSON_UNESCAPED_UNICODE), $path, (int)$r['id']]);
    if (!$old || ($old['computer'] ?? '') !== $rep['computer'] || ($r['path'] ?? '') !== $path)
        audit_log('pc_program_check', 'm365', (int)$r['id'], $who, $old ? ['Computer' => (string)($old['computer'] ?? ''), 'Way' => (string)$r['path']] : null,
            ['Computer' => $rep['computer'], 'Windows' => trim($rep['os'] . ' (' . $rep['edition'] . ')'), 'Way' => $path === 'pro' ? 'Defender for Endpoint' : ($path === 'home' ? 'built-in Defender (Home)' : 'unknown')]);
    json_response(['ok' => true]);
}

if ($sub === 'package' && $method === 'GET') {
    $k = (string)($_GET['k'] ?? ''); $kinds = opsh_pc_pkg_kinds(); if (!isset($kinds[$k])) error_response('Unknown file.', 404);
    $v = $view($r); if (empty($v['offer'][$k])) { audit_log('pc_program_refused', 'm365', (int)$r['id'], $who, null, ['Why' => 'package ' . $k . ' asked for, not offered in this state (' . $r['status'] . ', ' . ($r['path'] ?: 'no check') . ')']); error_response('This file is not offered to you now.', 403); }
    $p = opsh_pc_pkg_get($db, $k); $bytes = $p ? opsh_pc_pkg_bytes($p) : '';
    if ($bytes === '') error_response('The file is not available. Tell IT.', 503);
    if ($k !== 'win_off') $db->prepare('UPDATE opsh_pc_people SET package_at=NOW() WHERE id=?')->execute([(int)$r['id']]);
    audit_log('pc_program_package_taken', 'm365', (int)$r['id'], $who, null, ['File' => $kinds[$k][0]]);
    $isZip = substr($bytes, 0, 4) === "PK\x03\x04";
    header('Content-Type: ' . ($isZip ? 'application/zip' : 'application/octet-stream')); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$p['name']) . '"');
    echo $bytes; exit;
}
error_response('Not found', 404);
