<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
// Development router for `php -S` (local testing only, never shipped): php -S 127.0.0.1:8080 -t . dev/router.php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/api(/|$)#', $p)) { chdir($_SERVER['DOCUMENT_ROOT'] . '/api'); require $_SERVER['DOCUMENT_ROOT'] . '/api/index.php'; return true; }
if (preg_match('#^/install/?$#', $p)) { chdir($_SERVER['DOCUMENT_ROOT'] . '/install'); require $_SERVER['DOCUMENT_ROOT'] . '/install/index.php'; return true; }
if ($p === '/') { readfile($_SERVER['DOCUMENT_ROOT'] . '/index.html'); return true; }
return false;
