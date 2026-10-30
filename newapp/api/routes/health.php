<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
require_once __DIR__ . '/../lib/O365.php';
try { $db = getDB(); $db->query('SELECT 1'); $dbOk = true; } catch(Exception $e) { $dbOk = false; }
$o365 = false; try { $o365 = o365_enabled(); } catch(Exception $e) {}
json_response(['status'=>'ok','database'=>$dbOk,'version'=>APP_VERSION,'o365'=>$o365,'timestamp'=>date('c')]);
