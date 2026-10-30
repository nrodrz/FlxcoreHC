<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * GET /api/changelog — the release history (CHANGELOG.txt) for people who are signed in (BT124, review UI1-19: the file
 * itself is no longer served to anyone without signing in, because it describes security fixes).
 */
if ($method !== 'GET') error_response('Method not allowed', 405);
itops_require($currentUser, 'changelog', 'view');
$f = __DIR__ . '/../../CHANGELOG.txt';
if (!is_file($f)) error_response('The changelog is not on this server.', 404);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
readfile($f);
exit;
