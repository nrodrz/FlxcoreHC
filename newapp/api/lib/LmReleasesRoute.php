<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT125: the release key and update bundles of the License Manager (License Hub, phase 1). Included by
 * api/routes/license_manager.php after its checks (owner's installation, Super Admin); never served on its own.
 *
 * POST /license_manager/release-key        {replace?, confirm?}      create the release key (replacing needs "REPLACE")
 * POST /license_manager/release-key/backup {passphrase}              its backup (passphrase-encrypted), as a file
 * POST /license_manager/release-key/restore {backup, passphrase, replace?, confirm?}
 * POST /license_manager/releases           multipart: package (full-install zip), notes, install_id ("" = every installation)
 * GET  /license_manager/releases/:id/file                            the signed bundle (.itops-update)
 * POST /license_manager/releases/:id      {action: promote|pause|resume|withdraw, confirm?}   its channel state (BT129)
 * POST /license_manager/releases/settings  {pilot_days}               the waiting period before Stable (BT129)
 */
if (!isset($method, $what, $arg, $db, $uid) || !function_exists('itops_rel_key_info')) return;   // only when included by that route

if ($method === 'POST' && $what === 'release-key' && $arg === '') {
    $had = itops_rel_key_info($db);
    if ($had['exists'] && !(($body['replace'] ?? false) === true && ($body['confirm'] ?? '') === 'REPLACE')) {
        audit_log('lm_refused', 'license', null, 'Release key', null, ['Why' => 'a release key exists; replacing needs confirm REPLACE']);
        error_response('There is already a release key. Replacing it means installations accept bundles signed with the new key only after a build that carries it; send {replace: true, confirm: "REPLACE"} to do it.', 409);
    }
    $k = itops_rel_create_key($db);
    audit_log($had['exists'] ? 'lm_release_key_replaced' : 'lm_release_key_created', 'license', null, 'Release key', $had['exists'] ? ['Key' => $had['key_id']] : null, ['Key' => $k['key_id']]);
    json_response(['ok' => true, 'release_key' => $k]);
}
if ($method === 'POST' && $what === 'release-key' && $arg === 'backup') {
    try { $j = itops_lm_backup_of(itops_rel_secret($db), itops_rel_key_info($db), 'itops-release-key-backup', (string)($body['passphrase'] ?? '')); }
    catch (InvalidArgumentException $e) { error_response($e->getMessage(), 400); }
    catch (RuntimeException $e) { error_response($e->getMessage(), 409); }
    $kid = itops_rel_key_info($db)['key_id'];
    audit_log('lm_release_key_backup', 'license', null, 'Release key', null, ['Key' => $kid]);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="release-key-' . $kid . '.json"');
    echo $j; exit;
}
if ($method === 'POST' && $what === 'release-key' && $arg === 'restore') {
    $had = itops_rel_key_info($db);
    try { $sk = itops_lm_open_backup((string)($body['backup'] ?? ''), (string)($body['passphrase'] ?? ''), 'itops-release-key-backup'); }
    catch (InvalidArgumentException $e) { audit_log('lm_refused', 'license', null, 'Release key', null, ['Why' => 'restore: ' . $e->getMessage()]); error_response($e->getMessage(), 400); }
    $newId = itops_license_key_id(base64_encode(sodium_crypto_sign_publickey_from_secretkey($sk)));
    if ($had['exists'] && $had['key_id'] !== $newId && !(($body['replace'] ?? false) === true && ($body['confirm'] ?? '') === 'REPLACE'))
        error_response('This installation has a different release key. To replace it with the one in the backup, send {replace: true, confirm: "REPLACE"}.', 409);
    $k = itops_rel_store_key($db, $sk);
    audit_log('lm_release_key_restored', 'license', null, 'Release key', $had['exists'] ? ['Key' => $had['key_id']] : null, ['Key' => $k['key_id']]);
    json_response(['ok' => true, 'release_key' => $k]);
}
if ($method === 'POST' && $what === 'releases' && $arg === '') {
    $f = $_FILES['package'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $e = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if (in_array($e, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) error_response('The zip is larger than this server accepts (upload_max_filesize ' . ini_get('upload_max_filesize') . '). Raise it to at least 20M.', 413);
        error_response('Choose the full-install zip of the build to release.', 400);
    }
    try { $r = itops_rel_make($db, (string)$f['tmp_name'], ['notes' => (string)($_POST['notes'] ?? ''), 'install_id' => (string)($_POST['install_id'] ?? '')]); }
    catch (InvalidArgumentException $e) { audit_log('lm_refused', 'license', null, 'Release', null, ['Why' => $e->getMessage()]); error_response($e->getMessage(), 400); }
    catch (RuntimeException $e) { error_response($e->getMessage(), 409); }
    $m = $r['manifest'];
    $db->prepare('INSERT INTO itops_lm_releases (build, notes, install_id, key_id, size, file_name, created_at, created_by) VALUES (?,?,?,?,?,?,NOW(),?)')
       ->execute([$m['build'], $m['notes'], $m['install_id'], $m['key_id'], $r['size'], $r['name'], $uid]);
    $rid = (int)$db->lastInsertId();
    audit_log('lm_release_made', 'license', $rid, 'Build ' . $m['build'], null, ['Build' => $m['build'], 'For' => $m['install_id'] !== '' ? $m['install_id'] : 'every installation', 'Key' => $m['key_id'], 'Database update' => $m['db_update'] ? 'yes' : 'no']);
    json_response(['ok' => true, 'release' => ['id' => $rid, 'build' => $m['build'], 'file_name' => $r['name'], 'size' => $r['size'], 'db_update' => $m['db_update'], 'bridge' => $m['bridge']]]);
}
// BT129: release channels. Pilot installations are offered a release at once, Stable ones after the waiting period (or
// when it is promoted). Pause stops offering it (Resume starts again); Withdraw stops it for good.
if ($method === 'POST' && $what === 'releases' && $arg === 'settings') {
    $d = $body['pilot_days'] ?? null; if (!is_int($d) && !(is_string($d) && ctype_digit($d))) error_response('The waiting period is a whole number of days (0 to 60).', 400);
    $d = (int)$d; if ($d < 0 || $d > 60) error_response('The waiting period is 0 to 60 days.', 400);
    require_once __DIR__ . '/HubCheckin.php'; $was = itops_hub_pilot_days($db);
    itops_lm_set($db, 'lm_pilot_days', (string)$d);
    audit_log('lm_release_settings', 'license', null, 'Releases', ['Pilot days' => $was], ['Pilot days' => $d]);
    json_response(['ok' => true, 'pilot_days' => $d]);
}
if ($method === 'POST' && $what === 'releases' && ctype_digit($arg) && $sub === '') {
    require_once __DIR__ . '/HubCheckin.php'; itops_hub_table($db);
    $q = $db->prepare('SELECT * FROM itops_lm_releases WHERE id=?'); $q->execute([(int)$arg]); $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) error_response('Unknown release.', 404);
    $act = (string)($body['action'] ?? ''); $was = itops_hub_release_state($db, $r);
    if ($was === 'withdrawn') error_response('This release was withdrawn; make a new release instead.', 409);
    if ($act === 'promote') { if ($was !== 'pilot') error_response('Only a release in Pilot can be promoted.', 409); $db->prepare('UPDATE itops_lm_releases SET stable_at=NOW() WHERE id=?')->execute([$r['id']]); }
    elseif ($act === 'pause') { if ($was === 'paused') error_response('It is already paused.', 409); $db->prepare("UPDATE itops_lm_releases SET status='paused' WHERE id=?")->execute([$r['id']]); }
    elseif ($act === 'resume') { if ($was !== 'paused') error_response('Only a paused release can be resumed.', 409); $db->prepare("UPDATE itops_lm_releases SET status='active' WHERE id=?")->execute([$r['id']]); }
    elseif ($act === 'withdraw') {
        if (($body['confirm'] ?? '') !== 'WITHDRAW') error_response('Type WITHDRAW: installations that have not installed it stop being offered it, for good.', 400);
        $db->prepare("UPDATE itops_lm_releases SET status='withdrawn' WHERE id=?")->execute([$r['id']]);
    } else error_response('The action is promote, pause, resume or withdraw.', 400);
    $q->execute([(int)$arg]); $now = itops_hub_release_state($db, $q->fetch(PDO::FETCH_ASSOC));
    audit_log('lm_release_' . $act, 'license', (int)$r['id'], 'Build ' . $r['build'], ['State' => $was], ['State' => $now]);
    json_response(['ok' => true, 'state' => $now]);
}
if ($method === 'GET' && $what === 'releases' && ctype_digit($arg) && $sub === 'file') {
    $q = $db->prepare('SELECT * FROM itops_lm_releases WHERE id=?'); $q->execute([(int)$arg]); $r = $q->fetch(PDO::FETCH_ASSOC);
    $p = $r ? itops_upd_dir('releases') . '/' . basename((string)$r['file_name']) : '';
    if (!$r || !is_file($p)) error_response('Unknown release (or its file was removed from data/updates/releases).', 404);
    audit_log('lm_release_downloaded', 'license', (int)$r['id'], 'Build ' . $r['build']);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="' . basename((string)$r['file_name']) . '"');
    header('Content-Length: ' . filesize($p)); header('Cache-Control: no-store'); readfile($p); exit;
}
