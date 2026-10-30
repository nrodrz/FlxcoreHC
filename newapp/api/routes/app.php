<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — /api/app (BT147): installing the app. Public (no data in any of them):
 * GET /app/manifest      the web app manifest (404 while installing is switched off)
 * GET /app/icon/{size}   180 | 192 | 512 | maskable — PNG
 * GET /app/offline       the branded "You need an Internet connection to connect" page the service worker keeps
 * GET /app/shortcut      a Linux desktop launcher (.desktop) that opens the app in the browser
 */
require_once __DIR__ . '/../lib/AppInstall.php';
$db = getDB(); $c = itops_ai_cfg($db); $base = itops_ai_base(); $what = (string)($segments[1] ?? '');
if ($method !== 'GET') error_response('Not found', 404);
if (!$c['enabled'] && $what !== 'icon') error_response('Installing the app is switched off.', 404);
switch ($what) {
    case 'manifest':
        header('Content-Type: application/manifest+json; charset=utf-8'); header('Cache-Control: no-cache');
        echo json_encode(itops_ai_manifest($c, $base), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit;
    case 'icon':
        $png = itops_ai_icon($c, (string)($segments[2] ?? '192'));
        if ($png === '') error_response('Not found', 404);
        header('Content-Type: image/png'); header('Cache-Control: public, max-age=86400'); echo $png; exit;
    case 'offline':
        header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-cache');
        echo itops_ai_offline_html($c, $base); exit;
    case 'shortcut':
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
        $url = defined('APP_URL') && APP_URL !== '' ? rtrim(APP_URL, '/') . '/' : $scheme . '://' . preg_replace('/[^A-Za-z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) . $base;
        $file = preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($c['name'])) ?: 'app';
        header('Content-Type: application/x-desktop; charset=utf-8'); header('Content-Disposition: attachment; filename="' . trim($file, '-') . '.desktop"'); header('Cache-Control: no-store');
        echo itops_ai_desktop($c, $url); exit;
}
error_response('Not found', 404);
