<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — About & ownership (BT96). GET /about: who owns and develops the product, and the organization it is licensed
 * to. BT97: everyone signed in gets the short view; the full view (license terms, license record, installation ID,
 * LICENSE.txt, third-party components) needs the permission "License details" (module `license`, view). Nothing here can
 * be changed from the app (api/lib/Brand.php, itops-license.php).
 */
require_once __DIR__ . '/../lib/Brand.php';
if ($method !== 'GET') error_response('About & ownership is read-only.', 405);
json_response(itops_about(getDB(), itops_can($currentUser, 'license', 'view')));
