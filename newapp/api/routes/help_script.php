<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — GET /api/help_script/<id>   (BT90, code audit M-40)
 * The scripts of the Help Center visuals, which used to be written into the page itself. Public like the page code of the
 * app (no data in them: the list of systems comes with the page, to the people the page is given to); the app name follows
 * Settings → Branding as in the page text.
 */
require_once __DIR__ . '/../lib/HelpVisuals.php';
if ($method !== 'GET') error_response('Method not allowed', 405);
$js = opsh_help_visual_script((string)($segments[1] ?? ''));
if ($js === null) error_response('Not found', 404);
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');
echo $js;
exit;
