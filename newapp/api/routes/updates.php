<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — /api/updates (BT125): Settings → Updates. Super Admin only, never an API key. Everything, refusals included,
 * goes to the Audit Log (entity type "update").
 *
 * GET  /updates                                  this build, maintenance mode, the last result, the checked bundle, backups
 * POST /updates/check      multipart "bundle"    checks a bundle (signature, package, build, space, write access); keeps it
 * POST /updates/install    {confirm: "INSTALL"}  step 1: backup, copy of the old files, maintenance on, new files in place
 * POST /updates/finish                           step 2 (runs on the new code): database update, maintenance off
 * POST /updates/put-back   {backup, confirm: "PUT BACK"}   the files of the previous build from a backup
 * POST /updates/discard                          forget the checked bundle
 * GET  /updates/backups/:name/database           the database backup taken before that update (gzip SQL)
 * POST /updates/hub         {link? | token?, off?, unlink?}   link to a License Hub (link code: address + token; the token is stored
 *                            wrapped, never sent back), the off switch, or unlink (BT127, BT128)
 * POST /updates/hub/checkin                      check in now
 * POST /updates/hub/fetch                        download and check the build the License Hub offers (BT129)
 * POST /updates/auto        {on?, days?, start?, end?}   the maintenance window (opt-in, BT129)
 */
require_once __DIR__ . '/../lib/Updates.php';
require_once __DIR__ . '/../lib/UpdateFlow.php';
require_once __DIR__ . '/../lib/HubCheckin.php';
$db = getDB();
$what = (string)($segments[1] ?? ''); $arg = (string)($segments[2] ?? ''); $sub = (string)($segments[3] ?? '');
$uid = (int)($currentUser['id'] ?? 0);
$body = ($method === 'POST' && $what !== 'check') ? (json_decode(file_get_contents('php://input') ?: '', true) ?: []) : [];
$refuse = function (string $why, int $code = 403) use ($method, $segments) {
    audit_log('update_refused', 'update', null, 'Updates', null, ['Why' => $why, 'Asked' => $method . ' ' . implode('/', $segments)]);
    error_response($why, $code);
};
if (!empty($currentUser['is_api_key'])) $refuse('Updates are installed by a person signed in, never with an API key.');
if (($currentUser['role_slug'] ?? '') !== 'super_admin') $refuse('Only a Super Admin installs updates.');

$staged = fn(): array => itops_upd_staged($db);
$setStaged = function (?array $s) use ($db) { itops_upd_set_staged($db, $s); };
$forget = function (array $s) { itops_upd_forget($s); };
$pub = function (array $s): array {   // what the page may see of a checked bundle (no server paths)
    if (!$s) return [];
    return ['build' => $s['manifest']['build'] ?? '', 'notes' => $s['manifest']['notes'] ?? '', 'created_at' => $s['manifest']['created_at'] ?? '',
            'db_update' => !empty($s['manifest']['db_update']), 'bridge' => (string)($s['manifest']['bridge'] ?? ''), 'install_id' => (string)($s['manifest']['install_id'] ?? ''),
            'problems' => $s['check']['problems'] ?? [], 'notes_list' => $s['check']['notes'] ?? [], 'files' => (int)($s['check']['files'] ?? 0),
            'replace' => (int)($s['check']['replace'] ?? 0), 'new' => (int)($s['check']['new'] ?? 0), 'checked_at' => $s['at'] ?? '', 'checked_by' => $s['by_name'] ?? '',
            'from' => (string)($s['from'] ?? 'upload')];
};

if ($method === 'GET' && $what === '') {
    json_response(['build' => ITOPS_BUILD, 'keys_in_build' => count(itops_release_pubkeys()), 'maintenance' => itops_upd_maintenance(),
                   'last' => itops_upd_last($db), 'staged' => $pub($staged()), 'backups' => itops_upd_backups(),
                   'php' => PHP_VERSION, 'upload_max' => ini_get('upload_max_filesize'), 'post_max' => ini_get('post_max_size'),
                   'hub' => itops_hubc_public($db), 'auto' => itops_upd_auto_cfg($db), 'tz' => date('T')]);
}

// BT127: the License Hub check-in (the token is stored wrapped and never sent back). BT128: a link code (address + token).
if ($method === 'POST' && $what === 'hub' && $arg === '') {
    $c = itops_hubc_cfg($db); $old = ['License Hub address' => itops_hub_url($db) ?: 'none', 'Token' => $c['token_enc'] !== '' ? 'saved' : 'none', 'Check-in' => $c['off'] ? 'off' : 'on']; $chg = [];
    $code = array_key_exists('link', $body) ? (string)$body['link'] : (array_key_exists('token', $body) ? (string)$body['token'] : null);
    if (!empty($body['unlink'])) { $c['url'] = ''; $c['token_enc'] = ''; $c['problem'] = ''; itops_hubc_save($db, $c); $chg['Linked'] = 'no (address and token removed)'; }
    elseif ($code !== null) {
        if (trim($code) === '') { $c['token_enc'] = ''; itops_hubc_save($db, $c); $chg['Token'] = 'removed'; }
        else {
            try { $chg += itops_hubc_link($db, $code); }
            catch (InvalidArgumentException $e) { $refuse($e->getMessage(), 400); }
            catch (RuntimeException $e) { $refuse($e->getMessage(), 409); }
        }
    }
    if (array_key_exists('off', $body)) { $c = itops_hubc_cfg($db); $c['off'] = (bool)$body['off']; itops_hubc_save($db, $c); $chg['Check-in'] = $c['off'] ? 'off' : 'on'; }
    if (!$chg) error_response('Nothing to change.', 400);
    audit_log('hub_client_changed', 'license', null, 'License Hub check-in', $old, $chg);
    json_response(['ok' => true, 'hub' => itops_hubc_public($db)]);
}
// BT129: the build the License Hub offers: downloaded and checked like an upload (installing still needs INSTALL)
if ($method === 'POST' && $what === 'hub' && $arg === 'fetch') {
    try { $s = itops_hubc_fetch($db, $uid, (string)($currentUser['display_name'] ?? '')); }
    catch (RuntimeException $e) { $refuse($e->getMessage(), 409); }
    json_response(['ok' => true, 'staged' => $pub($s)]);
}
// BT129: the maintenance window (opt-in): the scheduler installs a build the License Hub offers, checked with no problem
if ($method === 'POST' && $what === 'auto') {
    $old = itops_upd_auto_cfg($db);
    try { $c = itops_upd_auto_save($db, $body); } catch (InvalidArgumentException $e) { $refuse($e->getMessage(), 400); }
    $fmt = fn($x) => ($x['on'] ? 'on' : 'off') . ' · days ' . implode(',', $x['days']) . ' · ' . $x['start'] . '–' . $x['end'];
    audit_log('update_window_set', 'update', null, 'Maintenance window', ['Window' => $fmt($old)], ['Window' => $fmt($c)]);
    json_response(['ok' => true, 'auto' => $c]);
}
if ($method === 'POST' && $what === 'hub' && $arg === 'checkin') {
    $r = itops_hubc_checkin($db, 'person');
    audit_log('hub_checkin', 'license', null, 'License Hub check-in', null, ['Result' => $r['note'], 'By' => 'a person (Check in now)']);
    json_response(['ok' => $r['ok'], 'note' => $r['note'], 'changed' => $r['changed'], 'hub' => itops_hubc_public($db)]);
}

if ($method === 'POST' && $what === 'check') {
    $f = $_FILES['bundle'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $e = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if (in_array($e, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) error_response('The file is larger than this server accepts (upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size') . '). Raise both in PHP settings (cPanel: MultiPHP INI Editor) to at least 20M.', 413);
        error_response('Choose the update bundle (.itops-update) to check.', 400);
    }
    if ((int)$f['size'] > ITOPS_UPD_MAX) error_response('That file is too large for an update bundle.', 413);
    $old = $staged(); if ($old) { $forget($old); $setStaged(null); }
    $dest = itops_upd_dir('staged') . '/bundle-' . bin2hex(random_bytes(6)) . '.itops-update';
    if (!@move_uploaded_file($f['tmp_name'], $dest)) error_response('The file could not be kept in data/updates (can the web server write the installation folder?).', 500);
    @chmod($dest, 0600);
    try { $s = itops_upd_stage_bundle($db, $dest, $uid, (string)($currentUser['display_name'] ?? ''), 'upload'); }
    catch (InvalidArgumentException $e) { $refuse($e->getMessage(), 400); }
    json_response(['ok' => true, 'staged' => $pub($s)]);
}

if ($method === 'POST' && $what === 'discard') {
    $s = $staged(); if ($s) { $forget($s); $setStaged(null); audit_log('update_discarded', 'update', null, 'Build ' . ($s['manifest']['build'] ?? '?')); }
    json_response(['ok' => true]);
}

if ($method === 'POST' && $what === 'install') {
    if (trim((string)($body['confirm'] ?? '')) !== 'INSTALL') $refuse('Type INSTALL to confirm.', 400);
    try { $r = itops_upd_run_install($db, $uid, itops_app_name()); }
    catch (InvalidArgumentException $e) { $refuse($e->getMessage(), 409); }
    catch (RuntimeException $e) { error_response($e->getMessage(), 500); }
    json_response(['ok' => true, 'next' => 'finish', 'to' => $r['to'], 'backup' => $r['backup'], 'placed' => $r['placed']]);
}

if ($method === 'POST' && $what === 'finish') {
    try { $r = itops_upd_run_finish($db, $uid); }
    catch (InvalidArgumentException $e) { $refuse($e->getMessage(), 409); }
    json_response($r);
}

if ($method === 'POST' && $what === 'put-back') {
    if (trim((string)($body['confirm'] ?? '')) !== 'PUT BACK') $refuse('Type PUT BACK to confirm.', 400);
    $name = (string)($body['backup'] ?? '');
    try { $r = itops_upd_put_back($name); } catch (InvalidArgumentException $e) { $refuse($e->getMessage(), 404); }
    itops_upd_maint_off();
    itops_upd_last($db, ['status' => $r['failed'] ? 'put_back_partly' : 'put_back', 'from' => ITOPS_BUILD, 'to' => $r['build'], 'backup' => $name, 'at' => date('Y-m-d H:i:s'), 'by' => $uid,
                        'message' => $r['failed'] ? 'Not put back: ' . implode(', ', array_slice($r['failed'], 0, 5)) : '']);
    audit_log('update_put_back', 'update', null, 'Build ' . $r['build'], ['Build' => ITOPS_BUILD], ['Build' => $r['build'], 'Backup' => $name, 'Not put back' => $r['failed'] ? count($r['failed']) : 0]);
    json_response(['ok' => !$r['failed'], 'build' => $r['build'], 'failed' => $r['failed']]);
}

if ($method === 'GET' && $what === 'backups' && $sub === 'database') {
    if (!preg_match('/^backup-[A-Za-z0-9-]{1,40}-\d{8}-\d{6}$/', $arg)) error_response('Unknown backup.', 404);
    $f = itops_upd_root() . '/data/updates/backups/' . $arg . '/database.sql.gz';
    if (!is_file($f)) error_response('Unknown backup.', 404);
    audit_log('update_backup_downloaded', 'update', null, $arg);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/gzip'); header('Content-Disposition: attachment; filename="' . $arg . '-database.sql.gz"');
    header('Content-Length: ' . filesize($f)); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
    readfile($f); exit;
}

error_response('Not found', 404);
