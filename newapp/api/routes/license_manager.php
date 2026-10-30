<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — /api/license_manager (BT105). Only where config.php says define('OPSH_LICENSE_MANAGER', true); (the owner's
 * own installation), and only for a Super Admin. Everywhere else every address here answers 404.
 *
 * GET  /license_manager                          key info (public half only), the register, plans and modules
 * POST /license_manager/key        {replace?, confirm?}            create the signing key (replacing one needs confirm "REPLACE")
 * POST /license_manager/key/backup {passphrase}                    the key backup (encrypted with the passphrase), as a file
 * POST /license_manager/key/restore {backup, passphrase, replace?, confirm?}   put a key back from its backup
 * POST /license_manager/licenses   {licensee, contact, install_id, agreement, kind, plan, modules, users, term_start, term_end, notes, replaces_id?}
 * GET  /license_manager/licenses/:id/file                          the signed opsh-license.php of that license
 * PUT  /license_manager/licenses/:id {status: "revoked", reason}   mark it revoked in the register
 * GET  /license_manager/billing (prices, charges, payments, summary) · GET billing/quote · GET billing/export/charges|payments (CSV)
 * POST /license_manager/billing/prices | settings | run | charges | charges/:id/pay | charges/:id/void | payments/:id/void   (BT130)
 * POST /license_manager/licenses also takes billing {cycle: monthly|annual|'', discount_pct, amount, starts} (BT130)
 * GET  /license_manager/clients | POST {name, contact_name, email, phone, address, notes} | PUT /clients/:id   client profiles (BT129)
 * GET  /license_manager/installs                                  every installation known (register + check-ins) with what needs attention (BT128)
 * POST /license_manager/installs   {install_id?, label, confirm?}   a link code: for that installation, or (no ID) the next one that uses it
 * POST /license_manager/installs/address {url}                     this hub's public HTTPS address (put into link codes)
 * POST /license_manager/installs/links/:ref/stop                   an unused link code stops working
 * POST /license_manager/installs/:install_id/stop                  that installation's token stops working
 * POST /license_manager/installs/:install_id/channel {channel}     pilot | stable (BT129: which releases it is offered)
 *
 * Everything is written to the Audit Log (entity type "license"), and so are refusals.
 */
require_once __DIR__ . '/../lib/LicenseManager.php';
$db = getDB();
if (!opsh_lm_enabled()) error_response('Not found', 404);
if (($currentUser['role_slug'] ?? '') !== 'super_admin') {
    audit_log('lm_refused', 'license', null, 'License Manager', null, ['Why' => 'Super Admin only', 'Asked' => $method . ' ' . implode('/', $segments)]);
    error_response('Only a Super Admin uses the License Manager.', 403);
}
if (!opsh_lm_ready($db)) error_response('The License Manager needs a database update: run db-update.php once.', 409);
$what = (string)($segments[1] ?? ''); $arg = (string)($segments[2] ?? ''); $sub = (string)($segments[3] ?? '');
$multipart = $method === 'POST' && $what === 'releases' && $arg === '';   // BT125: a release is a file upload
$body = in_array($method, ['POST', 'PUT'], true) && !$multipart ? json_decode(file_get_contents('php://input') ?: '', true) : null;
if (in_array($method, ['POST', 'PUT'], true) && !$multipart && !is_array($body)) error_response('JSON object expected.', 400);
require_once __DIR__ . '/../lib/Updates.php';
$uid = (int)$currentUser['id'];

if ($method === 'GET' && $what === '') {
    $rows = []; foreach ($db->query('SELECT id FROM opsh_lm_licenses ORDER BY id DESC')->fetchAll(PDO::FETCH_COLUMN) as $id) { $r = opsh_lm_row($db, (int)$id); unset($r['signature']); $rows[] = $r; }
    $plans = []; foreach (opsh_plans() as $k => $p) $plans[] = ['key' => $k, 'label' => $p[0], 'modules' => $p[2]];
    $mods = []; foreach (opsh_modules_catalog() as $k => $m) $mods[] = ['key' => $k, 'label' => $m[0]];
    $rels = []; try { $rels = $db->query('SELECT r.id, r.build, r.notes, r.install_id, r.key_id, r.size, r.file_name, r.created_at, u.display_name AS created_by_name FROM opsh_lm_releases r LEFT JOIN opsh_users u ON u.id=r.created_by ORDER BY r.id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    foreach ($rels as &$x) { $x['id'] = (int)$x['id']; $x['size'] = (int)$x['size']; } unset($x);
    require_once __DIR__ . '/../lib/HubCheckin.php'; try { $rels = opsh_hub_release_rows($db, $rels); } catch (Throwable $e) {}   // BT129: channels
    json_response(['release_key' => opsh_rel_key_info($db), 'releases' => $rels, 'pilot_days' => function_exists('opsh_hub_pilot_days') ? opsh_hub_pilot_days($db) : 7, 'this_build' => OPSH_BUILD, 'upload_max' => ini_get('upload_max_filesize'),
                   'key' => opsh_lm_key_info($db), 'licenses' => $rows, 'clients' => (function () use ($db) { require_once __DIR__ . '/../lib/HubCheckin.php'; try { return opsh_lm_clients($db); } catch (Throwable $e) { return []; } })(), 'plans' => $plans, 'modules' => $mods, 'next_serial' => opsh_lm_next_serial($db),
                   'this_install_id' => opsh_install_id($db), 'enforced' => opsh_license_enforced()]);
}
if ($method === 'POST' && $what === 'key' && $arg === '') {
    $had = opsh_lm_key_info($db);
    if ($had['exists'] && !(($body['replace'] ?? false) === true && ($body['confirm'] ?? '') === 'REPLACE')) {
        audit_log('lm_refused', 'license', null, 'Signing key', null, ['Why' => 'a key exists; replacing needs confirm REPLACE']);
        error_response('There is already a signing key. Replacing it means builds must carry the new public key before new licenses are accepted; send {replace: true, confirm: "REPLACE"} to do it.', 409);
    }
    $k = opsh_lm_create_key($db);
    audit_log($had['exists'] ? 'lm_key_replaced' : 'lm_key_created', 'license', null, 'Signing key', $had['exists'] ? ['Key' => $had['key_id']] : null, ['Key' => $k['key_id']]);
    json_response(['ok' => true, 'key' => $k]);
}
if ($method === 'POST' && $what === 'key' && $arg === 'backup') {
    try { $j = opsh_lm_backup($db, (string)($body['passphrase'] ?? '')); }
    catch (InvalidArgumentException $e) { error_response($e->getMessage(), 400); }
    catch (RuntimeException $e) { error_response($e->getMessage(), 409); }
    audit_log('lm_key_backup', 'license', null, 'Signing key', null, ['Key' => opsh_lm_key_info($db)['key_id']]);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="opshub-license-key-' . opsh_lm_key_info($db)['key_id'] . '.json"');
    echo $j; exit;
}
if ($method === 'POST' && $what === 'key' && $arg === 'restore') {
    $had = opsh_lm_key_info($db);
    try { $sk = opsh_lm_open_backup((string)($body['backup'] ?? ''), (string)($body['passphrase'] ?? '')); }
    catch (InvalidArgumentException $e) { audit_log('lm_refused', 'license', null, 'Signing key', null, ['Why' => 'restore: ' . $e->getMessage()]); error_response($e->getMessage(), 400); }
    $newId = opsh_license_key_id(base64_encode(sodium_crypto_sign_publickey_from_secretkey($sk)));
    if ($had['exists'] && $had['key_id'] !== $newId && !(($body['replace'] ?? false) === true && ($body['confirm'] ?? '') === 'REPLACE'))
        error_response('This installation has a different signing key. To replace it with the one in the backup, send {replace: true, confirm: "REPLACE"}.', 409);
    $k = opsh_lm_store_key($db, $sk);
    audit_log('lm_key_restored', 'license', null, 'Signing key', $had['exists'] ? ['Key' => $had['key_id']] : null, ['Key' => $k['key_id']]);
    json_response(['ok' => true, 'key' => $k]);
}
if ($method === 'POST' && $what === 'licenses' && $arg === '') {
    // BT129: a license belongs to a client profile; its organization and contact come from there unless typed
    require_once __DIR__ . '/../lib/HubCheckin.php'; opsh_hub_table($db); $cl = null;
    if (isset($body['client_id']) && $body['client_id'] !== null && $body['client_id'] !== '') {
        $q = $db->prepare('SELECT * FROM opsh_lm_clients WHERE id=?'); $q->execute([(int)$body['client_id']]); $cl = $q->fetch(PDO::FETCH_ASSOC);
        if (!$cl) error_response('Unknown client.', 400);
        if (trim((string)($body['licensee'] ?? '')) === '') $body['licensee'] = $cl['name'];
        if (trim((string)($body['contact'] ?? '')) === '') $body['contact'] = trim($cl['contact_name'] . ($cl['email'] !== '' ? ' <' . $cl['email'] . '>' : ''));
    }
    try { $p = opsh_lm_clean($body); } catch (InvalidArgumentException $e) { error_response($e->getMessage(), 400); }
    $rep = null;
    if (isset($body['replaces_id']) && $body['replaces_id'] !== null && $body['replaces_id'] !== '') {
        $rep = (int)$body['replaces_id']; $old = opsh_lm_row($db, $rep);
        if (!$old) error_response('The license it replaces does not exist.', 400);
    }
    // BT130: billing of the license (optional), checked before anything is issued
    require_once __DIR__ . '/../lib/Billing.php'; $bill = is_array($body['billing'] ?? null) ? $body['billing'] : [];
    try { opsh_bill_check($db, $p, $bill); } catch (InvalidArgumentException $e) { error_response($e->getMessage(), 400); }
    try { $r = opsh_lm_issue($db, $p, $uid, $rep); } catch (RuntimeException $e) { error_response($e->getMessage(), 409); }
    if ($cl) { $db->prepare('UPDATE opsh_lm_licenses SET client_id=? WHERE id=?')->execute([(int)$cl['id'], $r['id']]); $r['client_id'] = (int)$cl['id']; }
    elseif ($rep && ($oc = $db->query('SELECT client_id FROM opsh_lm_licenses WHERE id=' . (int)$rep)->fetchColumn())) { $db->prepare('UPDATE opsh_lm_licenses SET client_id=? WHERE id=?')->execute([(int)$oc, $r['id']]); $r['client_id'] = (int)$oc; }
    audit_log('lm_issued', 'license', $r['id'], $r['serial'] . ' · ' . $r['licensee'], $rep ? ['Replaces' => opsh_lm_row($db, $rep)['serial']] : null,
        ['Installation' => $r['install_id'], 'Plan' => $r['plan'] ?: '—', 'Extra modules' => implode(', ', $r['modules']) ?: '—', 'Users' => $r['users'] ?: 'no limit', 'Term' => $r['term_start'] . ' – ' . ($r['term_end'] ?: 'no end')]);
    $r['billing'] = opsh_bill_start($db, $r, $bill, $uid, $rep);
    $od = !empty($r['client_id']) ? (opsh_bill_overdue_clients($db)[(int)$r['client_id']] ?? null) : null;
    unset($r['signature']);
    json_response(['ok' => true, 'license' => $r, 'warning' => $od ? 'This client has ' . $od['overdue'] . ' USD overdue (oldest ' . $od['days_late'] . ' days late).' : '']);
}
if ($method === 'GET' && $what === 'licenses' && $sub === 'file') {
    if (!ctype_digit($arg) || !($r = opsh_lm_row($db, (int)$arg))) error_response('Unknown license.', 404);
    audit_log('lm_downloaded', 'license', $r['id'], $r['serial'] . ' · ' . $r['licensee']);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="opsh-license.php"');
    echo opsh_lm_file($r); exit;
}
if ($method === 'PUT' && $what === 'licenses' && ctype_digit($arg)) {
    $r = opsh_lm_row($db, (int)$arg); if (!$r) error_response('Unknown license.', 404);
    if (($body['status'] ?? '') !== 'revoked') error_response('Only {status: "revoked", reason} can be set.', 400);
    $why = trim((string)($body['reason'] ?? '')); if ($why === '' || mb_strlen($why) > 300) error_response('Say why (up to 300 characters).', 400);
    if ($r['status'] === 'revoked') error_response('It is already revoked.', 409);
    $db->prepare("UPDATE opsh_lm_licenses SET status='revoked', revoked_at=?, revoked_reason=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $why, $r['id']]);
    audit_log('lm_revoked', 'license', $r['id'], $r['serial'] . ' · ' . $r['licensee'], ['Status' => $r['status']], ['Status' => 'revoked', 'Why' => $why]);
    require_once __DIR__ . '/../lib/Billing.php'; opsh_bill_end($db, (int)$r['id'], 'license revoked');   // BT130
    json_response(['ok' => true]);
}
// BT127: installations that check in with this hub (License Hub phase 2); BT128: link codes, the hub's own address,
// one overview with what needs attention
if ($what === 'installs') {
    require_once __DIR__ . '/../lib/HubCheckin.php';
    $pubUrl = opsh_hub_public_url($db);
    if ($method === 'GET' && $arg === '') json_response(opsh_hub_overview($db) + ['public_url' => $pubUrl, 'this_install_id' => opsh_install_id($db)]);
    if ($method === 'POST' && $arg === 'address') {   // this hub's public address, put into link codes
        $u = opsh_hub_url_clean((string)($body['url'] ?? ''));
        if ($u !== '' && (!opsh_hub_url_ok($u) || mb_strlen($u) > 200)) error_response('Use the hub\'s public HTTPS address, for example https://licenses.example.com (no path after it is needed).', 400);
        opsh_lm_set($db, 'lm_public_url', $u);
        audit_log('hub_address_set', 'license', null, 'License Hub address', ['Address' => $pubUrl ?: 'none'], ['Address' => $u ?: 'none']);
        json_response(['ok' => true, 'public_url' => $u]);
    }
    if ($method === 'POST' && $arg === '') {   // a link code: for a known installation ID, or (empty ID) for the next installation that uses it
        $iid = strtoupper(trim((string)($body['install_id'] ?? ''))); $label = trim((string)($body['label'] ?? ''));
        if (mb_strlen($label) > 200) error_response('The name is too long (200 characters at most).', 400);
        opsh_hub_table($db);
        if ($iid === '') {
            if ($label === '') error_response('Give it a name (who it is for), so you can tell it apart when it checks in.', 400);
            $tok = opsh_hub_new_link($db, $label, $uid);
            audit_log('hub_link_made', 'license', null, $label, null, ['Link code' => 'made (shown once, kept as a hash)', 'For' => 'the first installation that uses it, within 30 days']);
            json_response(['ok' => true, 'install_id' => '', 'token' => $tok, 'link_code' => $pubUrl !== '' ? opsh_hub_link_code($pubUrl, $tok) : '',
                           'note' => 'Copy it now: it is not shown again. Paste it in the installer of the new installation (step "License Hub"), or later in Settings → Updates → License Hub. The first installation that uses it within 30 days is linked.']);
        }
        if (!preg_match('/^[A-F0-9]{4}(-[A-F0-9]{4}){3}$/', $iid)) error_response('The installation ID looks like XXXX-XXXX-XXXX-XXXX (About & ownership on that installation), or leave it empty for a new installation.', 400);
        $had = $db->prepare("SELECT token_hash FROM opsh_lm_installs WHERE install_id=?"); $had->execute([$iid]); $had = (string)$had->fetchColumn();
        if ($had !== '' && ($body['confirm'] ?? '') !== 'REPLACE') error_response('This installation is already linked. Type REPLACE to make a new link code (the old one stops working).', 409);
        $tok = opsh_hub_new_token($db, $iid, $label);
        audit_log($had !== '' ? 'hub_token_replaced' : 'hub_token_made', 'license', null, $iid . ($label !== '' ? ' · ' . $label : ''), $had !== '' ? ['Token' => 'replaced'] : null, ['Token' => 'made (shown once, kept as a hash)']);
        json_response(['ok' => true, 'install_id' => $iid, 'token' => $tok, 'link_code' => $pubUrl !== '' ? opsh_hub_link_code($pubUrl, $tok) : '',
                       'note' => 'Copy it now: it is not shown again. Paste it on that installation: Settings → Updates → License Hub.']);
    }
    if ($method === 'POST' && $arg === 'links' && preg_match('/^[a-f0-9]{12}$/', $sub)) {
        opsh_hub_table($db);
        $u = $db->prepare("UPDATE opsh_lm_links SET stopped=1 WHERE token_hash LIKE ? AND claimed_install='' AND stopped=0"); $u->execute([$sub . '%']);
        if (!$u->rowCount()) error_response('No unused link code with that reference.', 404);
        audit_log('hub_link_stopped', 'license', null, 'Link code ' . $sub, ['Link code' => 'unused'], ['Link code' => 'stopped']);
        json_response(['ok' => true]);
    }
    if ($method === 'POST' && preg_match('/^[A-F0-9]{4}(-[A-F0-9]{4}){3}$/', $arg) && $sub === 'channel') {   // BT129
        $chn = (string)($body['channel'] ?? ''); if (!in_array($chn, ['pilot', 'stable'], true)) error_response('The channel is pilot or stable.', 400);
        opsh_hub_table($db);
        $q = $db->prepare('SELECT channel FROM opsh_lm_installs WHERE install_id=?'); $q->execute([$arg]); $was = $q->fetchColumn();
        if ($was === false) error_response('That installation is not linked yet: link it first.', 404);
        $db->prepare('UPDATE opsh_lm_installs SET channel=? WHERE install_id=?')->execute([$chn, $arg]);
        audit_log('hub_channel_set', 'license', null, $arg, ['Channel' => $was ?: 'stable'], ['Channel' => $chn]);
        json_response(['ok' => true]);
    }
    if ($method === 'POST' && preg_match('/^[A-F0-9]{4}(-[A-F0-9]{4}){3}$/', $arg) && $sub === 'stop') {
        opsh_hub_table($db);
        $u = $db->prepare("UPDATE opsh_lm_installs SET token_hash='', token_set_at=NULL WHERE install_id=? AND token_hash<>''"); $u->execute([$arg]);
        if (!$u->rowCount()) error_response('That installation is not linked.', 404);
        audit_log('hub_token_stopped', 'license', null, $arg, ['Token' => 'working'], ['Token' => 'stopped']);
        json_response(['ok' => true]);
    }
    error_response('Not found', 404);
}
// BT130: billing (prices, charges, payments). No money moves: payments are recorded by hand.
if ($what === 'billing') {
    require_once __DIR__ . '/../lib/Billing.php'; require_once __DIR__ . '/../lib/HubCheckin.php'; opsh_hub_table($db); opsh_bill_table($db);
    if ($method === 'GET' && $arg === '') {
        json_response(['prices' => opsh_bill_price_list($db), 'charges' => opsh_bill_charges($db), 'payments' => opsh_bill_payments($db), 'summary' => opsh_bill_summary($db),
                       'cfg' => opsh_bill_cfg($db), 'methods' => opsh_bill_methods(), 'clients' => opsh_lm_clients($db)]);
    }
    if ($method === 'GET' && $arg === 'quote') {
        $cy = (string)($_GET['cycle'] ?? 'monthly'); if (!in_array($cy, ['monthly', 'annual'], true)) error_response('The cycle is monthly or annual.', 400);
        $mods = array_values(array_filter(explode(',', (string)($_GET['modules'] ?? '')), fn($m) => isset(opsh_modules_catalog()[$m])));
        $pl = (string)($_GET['plan'] ?? ''); if ($pl !== '' && !isset(opsh_plans()[$pl])) error_response('Unknown plan.', 400);
        json_response(opsh_bill_quote($db, $pl, $mods, max(0, min(100000, (int)($_GET['users'] ?? 0))), $cy));
    }
    if ($method === 'GET' && $arg === 'export' && in_array($sub, ['charges', 'payments'], true)) {
        audit_log('lm_billing_exported', 'license', null, 'Billing', null, ['Export' => $sub]);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $sub . '-' . date('Y-m-d') . '.csv"'); header('Cache-Control: no-store');
        echo opsh_bill_csv($db, $sub); exit;
    }
    try {
        if ($method === 'POST' && $arg === 'prices') { $r = opsh_bill_set_price($db, $body, $uid); json_response(['ok' => true, 'price' => $r, 'prices' => opsh_bill_price_list($db)]); }
        if ($method === 'POST' && $arg === 'settings') {
            $c = opsh_bill_cfg($db); $old = $c;
            foreach (['due_days' => [0, 120, 'Days to pay'], 'lead_days' => [0, 60, 'Days ahead a charge is made']] as $k => [$lo, $hi, $l]) if (array_key_exists($k, $body)) {
                $v = $body[$k]; if (!is_int($v) && !(is_string($v) && ctype_digit($v))) throw new InvalidArgumentException($l . ' is a whole number.');
                $v = (int)$v; if ($v < $lo || $v > $hi) throw new InvalidArgumentException($l . ' is ' . $lo . ' to ' . $hi . '.'); $c[$k] = $v;
            }
            opsh_lm_set($db, 'lm_bill_cfg', json_encode($c));
            audit_log('lm_billing_settings', 'license', null, 'Billing', ['Days to pay' => $old['due_days'], 'Days ahead' => $old['lead_days']], ['Days to pay' => $c['due_days'], 'Days ahead' => $c['lead_days']]);
            json_response(['ok' => true, 'cfg' => $c]);
        }
        if ($method === 'POST' && $arg === 'run') { $n = opsh_bill_make_charges($db, $uid); audit_log('lm_charges_run', 'license', null, 'Billing', null, ['New charges' => $n]); json_response(['ok' => true, 'made' => $n]); }
        if ($method === 'POST' && $arg === 'charges' && $sub === '') { $id = opsh_bill_add_charge($db, $body, $uid); json_response(['ok' => true, 'id' => $id]); }
        if ($method === 'POST' && $arg === 'charges' && ctype_digit($sub) && ($segments[4] ?? '') === 'pay') { $id = opsh_bill_pay($db, (int)$sub, $body, $uid); json_response(['ok' => true, 'id' => $id, 'charge' => opsh_bill_charges($db, ['charge_id' => (int)$sub])[0]]); }
        if ($method === 'POST' && $arg === 'charges' && ctype_digit($sub) && ($segments[4] ?? '') === 'void') { opsh_bill_void($db, 'charge', (int)$sub, (string)($body['reason'] ?? '')); json_response(['ok' => true]); }
        if ($method === 'POST' && $arg === 'payments' && ctype_digit($sub) && ($segments[4] ?? '') === 'void') { opsh_bill_void($db, 'payment', (int)$sub, (string)($body['reason'] ?? '')); json_response(['ok' => true]); }
    } catch (InvalidArgumentException $e) {
        audit_log('lm_refused', 'license', null, 'Billing', null, ['Why' => $e->getMessage(), 'Asked' => $method . ' ' . implode('/', $segments)]);
        error_response($e->getMessage(), 400);
    }
    error_response('Not found', 404);
}
// BT129: client profiles (organization, contact, email, phone, address, notes); licenses are issued to a client
if ($what === 'clients') {
    require_once __DIR__ . '/../lib/HubCheckin.php'; opsh_hub_table($db);
    if ($method === 'GET' && $arg === '') json_response(['clients' => opsh_lm_clients($db)]);
    if (($method === 'POST' && $arg === '') || ($method === 'PUT' && ctype_digit($arg))) {
        try { $c = opsh_lm_client_clean($body); } catch (InvalidArgumentException $e) { error_response($e->getMessage(), 400); }
        $dup = $db->prepare('SELECT id FROM opsh_lm_clients WHERE name=? AND id<>?'); $dup->execute([$c['name'], $method === 'PUT' ? (int)$arg : 0]);
        if ($dup->fetchColumn()) error_response('There is already a client with that organization name.', 409);
        if ($method === 'POST') {
            $db->prepare('INSERT INTO opsh_lm_clients (name, contact_name, email, phone, address, notes, created_at) VALUES (?,?,?,?,?,?,NOW())')->execute(array_values($c));
            $id = (int)$db->lastInsertId(); audit_log('lm_client_added', 'license', $id, $c['name'], null, array_filter(['Contact' => $c['contact_name'], 'Email' => $c['email'], 'Phone' => $c['phone']]));
        } else {
            $q = $db->prepare('SELECT * FROM opsh_lm_clients WHERE id=?'); $q->execute([(int)$arg]); $old = $q->fetch(PDO::FETCH_ASSOC); if (!$old) error_response('Unknown client.', 404);
            $db->prepare('UPDATE opsh_lm_clients SET name=?, contact_name=?, email=?, phone=?, address=?, notes=?, updated_at=NOW() WHERE id=?')->execute(array_merge(array_values($c), [(int)$arg]));
            $id = (int)$arg; $o = []; $n = [];
            foreach ($c as $k => $v) if ((string)$old[$k] !== $v) { $o[ucfirst(str_replace('_', ' ', $k))] = mb_substr((string)$old[$k], 0, 120); $n[ucfirst(str_replace('_', ' ', $k))] = mb_substr($v, 0, 120); }
            audit_log('lm_client_changed', 'license', $id, $c['name'], $o ?: null, $n ?: ['Changed' => 'nothing']);
        }
        json_response(['ok' => true, 'id' => $id, 'clients' => opsh_lm_clients($db)]);
    }
    error_response('Not found', 404);
}
// BT125: the release key and update bundles
require __DIR__ . '/../lib/LmReleasesRoute.php';

error_response('Not found', 404);
