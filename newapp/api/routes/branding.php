<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * GET /api/branding — public app identity (name + logo + favicon)
 * Used by pre-auth screens: the login page and the public submit form.
 * No authentication required.
 */
$db = getDB();
$out = ['app_name' => 'OpsHub', 'logo' => '', 'favicon' => '', 'announcement' => '', 'announcement_level' => 'info',
        'tagline' => 'IT Master Operations Hub', 'signin_default' => 'local'];   // BT103: the sign-in page's line under the logo and first way of signing in
try {
    foreach ($db->query("SELECT skey,sval FROM opsh_settings WHERE skey IN ('app_name','app_logo_url','app_favicon_url','announcement_text','announcement_level','app_tagline','signin_default')") as $r) {
        if ($r['skey'] === 'app_tagline' && trim((string)$r['sval']) !== '') $out['tagline'] = (string)$r['sval'];
        if ($r['skey'] === 'signin_default' && in_array($r['sval'], ['local', 'microsoft'], true)) $out['signin_default'] = $r['sval'];
        if ($r['skey'] === 'app_name' && $r['sval'] !== '') $out['app_name'] = $r['sval'];
        if ($r['skey'] === 'app_logo_url')                   $out['logo']     = $r['sval'];
        if ($r['skey'] === 'app_favicon_url')                $out['favicon']  = $r['sval'];
        if ($r['skey'] === 'announcement_text')              $out['announcement'] = $r['sval'];
        if ($r['skey'] === 'announcement_level')             $out['announcement_level'] = $r['sval'];
    }
} catch (Throwable $e) {}
require_once __DIR__ . '/../lib/TimeZone.php'; $out['timezone'] = opsh_tz_get($db);
try { require_once __DIR__ . '/../lib/AppInstall.php'; $ai = opsh_ai_cfg($db); $out['install'] = ['enabled' => $ai['enabled'], 'short_name' => $ai['short_name'], 'theme_color' => $ai['theme_color']]; } catch (Throwable $e) {}   // BT147   // BT131: the screens show every time in it
json_response($out);
