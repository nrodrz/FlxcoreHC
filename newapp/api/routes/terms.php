<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Terms of Use (BT97).
 *   GET  /terms/text    the Terms, for the sign-in page (no sign-in needed; nothing private in it)
 *   GET  /terms         the Terms and where this person stands (accepted when, organization accepted)
 *   POST /terms/accept  {for_org: bool, org_name: string} — "I agree". The first administrator also accepts for the
 *                       organization. Each acceptance is a row in opsh_terms_acceptances and an Audit Log entry.
 */
require_once __DIR__ . '/../lib/TermsOfUse.php';
$db = getDB();
if ($method === 'GET' && $id === 'text') json_response(opsh_tou($db));
if (!$currentUser || empty($currentUser['id']) || !empty($currentUser['api_key_id'])) error_response('Not authenticated', 401);
if ($method === 'GET' && ($id === null || $id === '')) {
    $out = opsh_tou($db) + ['status' => opsh_tou_status($db, $currentUser)];
    // BT98: who has accepted, for the people who manage users or may accept for the organization
    if (opsh_can($currentUser, 'users', 'view') || opsh_tou_can_org($currentUser)) { $out['acceptances'] = opsh_tou_acceptances($db); $out['pending'] = opsh_tou_pending_count($db); }
    json_response($out);
}
if ($method === 'POST' && $id === 'accept') {
    $b = json_decode(file_get_contents('php://input'), true);
    if (!is_array($b)) error_response('Send the answer as JSON.', 400);
    if (isset($b['for_org']) && !is_bool($b['for_org'])) error_response('for_org is true or false.', 400);
    if (isset($b['org_name']) && !is_string($b['org_name'])) error_response('org_name is text.', 400);
    if (isset($b['version']) && (string)$b['version'] !== OPSH_TOU_VERSION) error_response('The Terms of Use changed while this page was open. Read the new version and accept it.', 409);
    json_response(['ok' => true, 'status' => opsh_tou_accept($db, $currentUser, (bool)($b['for_org'] ?? false), (string)($b['org_name'] ?? ''))]);
}
error_response('Not found', 404);
