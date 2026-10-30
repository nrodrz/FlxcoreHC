<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — /api/hub (BT127): the License Hub's check-in address. Only on the owner's installation (License Manager on);
 * everywhere else it answers 404.
 *
 * POST /hub/checkin   header "X-ITOps-Hub-Token: <token>" (or "Authorization: Bearer <token>")
 *                     {install_id, build, php, db, users, last_update}
 *   → {ok, license: {signed fields, key_id, signature} | null, revoked: [serials], checked_at}
 * POST /hub/release   same token  {install_id, build, runs}   the signed bundle of the build offered to it (BT129)
 * The token is the installation's own (License Manager → Installations); only its SHA-256 is kept here. Refused check-ins
 * are counted per address (30 in 10 minutes, then 429), and the first of a burst goes to the Audit Log.
 */
require_once __DIR__ . '/../lib/LicenseManager.php';
require_once __DIR__ . '/../lib/HubCheckin.php';
require_once __DIR__ . '/../lib/AuthGuard.php';
$db = getDB();
$hubWhat = (string)($segments[1] ?? '');
if (!itops_lm_enabled() || !in_array($hubWhat, ['checkin', 'release'], true)) error_response('Not found', 404);
if ($method !== 'POST') error_response('Not found', 404);
header('Cache-Control: no-store');
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? ''); $fk = 'hub|' . $ip;
if (itops_auth_fail_count($db, $fk, 600) >= 30) error_response('Too many refused check-ins from this address. Wait a few minutes.', 429);
$tok = (string)($_SERVER['HTTP_X_ITOPS_HUB_TOKEN'] ?? '');
if ($tok === '') {
    $ah = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+(\S+)$/i', $ah, $m)) $tok = $m[1];
}
$b = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($b)) error_response('JSON object expected.', 400);
try {
    if ($hubWhat === 'release') {   // BT129: the bundle of the build offered to this installation (same token)
        $iid = strtoupper(trim((string)($b['install_id'] ?? '')));
        $p = itops_hub_release_file($db, $tok, $iid, (string)($b['build'] ?? ''), (string)($b['runs'] ?? ''));
        audit_log('hub_release_sent', 'license', null, 'Build ' . preg_replace('/[^0-9A-Za-z-]/', '', (string)$b['build']), null, ['Installation' => $iid, 'Address' => $ip]);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/octet-stream'); header('Content-Length: ' . filesize($p)); header('Content-Disposition: attachment; filename="' . basename($p) . '"');
        readfile($p); exit;
    }
    $out = itops_hub_checkin($db, $tok, $b, $ip);
} catch (RuntimeException $e) {
    audit_log('hub_release_refused', 'license', null, 'Release download', null, ['Why' => $e->getMessage(), 'Installation' => mb_substr(preg_replace('/[^A-Fa-f0-9-]/', '', (string)($b['install_id'] ?? '')), 0, 19)]);
    error_response('Not sent: ' . $e->getMessage() . '.', 409);
} catch (InvalidArgumentException $e) {
    if (itops_auth_fail_count($db, $fk, 600) === 0)
        audit_log('hub_checkin_refused', 'license', null, 'License Hub check-in', null, ['Why' => $e->getMessage(), 'Address' => $ip, 'Installation' => mb_substr(preg_replace('/[^A-Fa-f0-9-]/', '', (string)($b['install_id'] ?? '')), 0, 19)]);
    itops_auth_fail_add($db, $fk);
    error_response('Check-in refused: ' . $e->getMessage() . '.', 401);
}
json_response($out);
