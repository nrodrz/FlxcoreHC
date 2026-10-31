<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT125: the build of this copy of the code, as the server knows it (the screens carry the same stamp in
 * src/app.jsx, APP_BUILD). Settings → Updates compares it with an update bundle; the release maker on the hub reads it
 * from inside a package. Changed with every build.
 */
if (!defined('ITOPS_BUILD')) define('ITOPS_BUILD', '0001-A005');
// BT127: the License Hub's default address (https://…). BT128: an installation linked with a link code (installer or
// Settings → Updates) uses the address in that code instead. Empty and not linked = no check-in.
if (!defined('ITOPS_HUB_URL')) define('ITOPS_HUB_URL', '');
