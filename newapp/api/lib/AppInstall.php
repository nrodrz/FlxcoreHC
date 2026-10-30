<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — BT147: installing the app on a computer or phone (an installable web app). What a device keeps is a shortcut:
 * the manifest (name, colors, the address to open), the icons, and one branded "You need an Internet connection" page that
 * a tiny service worker (sw.js) shows when there is no connection. No app code, no records and no sign-in are kept for
 * offline use. Signing out can tell the browser to delete what it kept for this site (Clear-Site-Data: cache, storage).
 * Settings (Settings → Branding → Install as an app): app_install_enabled, app_short_name, app_theme_color, app_wipe_signout.
 */
if (!function_exists('opsh_ai_cfg')) {
    function opsh_ai_cfg(PDO $db): array {
        $v = []; try { foreach ($db->query("SELECT skey,sval FROM opsh_settings WHERE skey IN ('app_name','app_tagline','app_logo_url','accent_color','app_install_enabled','app_short_name','app_theme_color','app_wipe_signout')") as $r) $v[$r['skey']] = (string)$r['sval']; } catch (Throwable $e) {}
        $name = trim($v['app_name'] ?? '') !== '' ? trim($v['app_name']) : (defined('OPSH_PRODUCT') ? OPSH_PRODUCT : 'OpsHub');
        $hex = fn($c) => preg_match('/^#[0-9a-fA-F]{6}$/', (string)$c) ? strtoupper((string)$c) : '';
        $theme = $hex($v['app_theme_color'] ?? '') ?: ($hex($v['accent_color'] ?? '') ?: '#1D4D7A');
        $short = trim($v['app_short_name'] ?? ''); if ($short === '') $short = mb_substr($name, 0, 12);
        return ['enabled' => ($v['app_install_enabled'] ?? '1') !== '0', 'name' => $name, 'short_name' => $short, 'theme_color' => $theme,
                'description' => trim($v['app_tagline'] ?? '') !== '' ? trim($v['app_tagline']) : 'IT Master Operations Hub', 'logo_url' => (string)($v['app_logo_url'] ?? ''),
                'wipe_signout' => ($v['app_wipe_signout'] ?? '1') !== '0'];
    }
    /** The app's own address path ("/" or "/folder/"), from where api/index.php is served. */
    function opsh_ai_base(): string {
        $p = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/api/'), PHP_URL_PATH); $i = strpos($p, '/api/');
        return $i === false ? '/' : substr($p, 0, $i + 1);
    }
    function opsh_ai_manifest(array $c, string $base): array {
        return ['id' => $base, 'name' => $c['name'], 'short_name' => $c['short_name'], 'description' => $c['description'], 'start_url' => $base, 'scope' => $base,
                'display' => 'standalone', 'background_color' => '#FFFFFF', 'theme_color' => $c['theme_color'], 'lang' => 'en',
                'icons' => [['src' => $base . 'api/app/icon/192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                            ['src' => $base . 'api/app/icon/512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                            ['src' => $base . 'api/app/icon/maskable', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable']]];
    }
    /** The uploaded logo as a file on this server, or '' (an outside address is never fetched). */
    function opsh_ai_logo_file(string $url): string {
        if ($url === '' || !defined('UPLOAD_DIR')) return '';
        $f = UPLOAD_DIR . 'logos/' . basename((string)parse_url($url, PHP_URL_PATH));
        return preg_match('/^logo_[0-9a-f]{12}\.(png|jpg|gif|webp)$/', basename($f)) && is_file($f) ? $f : '';
    }
    /** PNG bytes of the app icon: the uploaded logo on white (maskable: smaller, inside the safe zone), else the default icon. */
    function opsh_ai_icon(array $c, string $size): string {
        $px = ['180' => 180, '192' => 192, '512' => 512, 'maskable' => 512][$size] ?? 192;
        $def = dirname(__DIR__, 2) . '/public/app/icon-' . ($size === 'maskable' ? 'maskable-512' : ($px === 180 ? '180' : $px)) . '.png';
        $logo = opsh_ai_logo_file($c['logo_url']);
        if ($logo === '' || !function_exists('imagecreatetruecolor')) return (string)@file_get_contents($def);
        $cdir = UPLOAD_DIR . 'logos/'; $cf = $cdir . 'appicon_' . substr(sha1($logo . '|' . filemtime($logo) . '|' . $size), 0, 16) . '.png';
        if (is_file($cf)) return (string)file_get_contents($cf);
        $src = @imagecreatefromstring((string)file_get_contents($logo)); if (!$src) return (string)@file_get_contents($def);
        $im = imagecreatetruecolor($px, $px); imagealphablending($im, true); imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        $box = $px * ($size === 'maskable' ? 0.62 : 0.80); $w = imagesx($src); $h = imagesy($src); $k = min($box / $w, $box / $h);
        $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
        imagecopyresampled($im, $src, (int)(($px - $nw) / 2), (int)(($px - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
        ob_start(); imagepng($im); $png = (string)ob_get_clean(); imagedestroy($im); imagedestroy($src);
        @file_put_contents($cf, $png);
        return $png;
    }
    /** The one page a device keeps: name, logo, "You need an Internet connection to connect", Try again. No script. */
    function opsh_ai_offline_html(array $c, string $base): string {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $img = ''; $png = opsh_ai_icon($c, '192'); if ($png !== '') $img = '<img src="data:image/png;base64,' . base64_encode($png) . '" alt="" width="88" height="88" style="border-radius:20px;box-shadow:0 6px 18px rgba(0,0,0,.12)">';
        $t = $c['theme_color'];
        return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="' . $e($t) . '">'
            . '<title>' . $e($c['name']) . ' · No connection</title></head>'
            . '<body style="margin:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Ubuntu,sans-serif;background:#F4F6F9;color:#2E3A44;min-height:100vh;display:flex;flex-direction:column">'
            . '<div style="background:' . $e($t) . ';color:#fff;padding:14px 20px;font-weight:600;font-size:15px;text-align:center" role="alert">You need an Internet connection to connect.</div>'
            . '<main style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:32px 20px;text-align:center">' . $img
            . '<h1 style="font-size:26px;margin:22px 0 8px">' . $e($c['name']) . '</h1>'
            . '<p style="font-size:15.5px;line-height:1.5;color:#5A6774;max-width:420px;margin:0 0 24px">This device is not connected to the Internet. Connect to Wi-Fi or a network, then try again.</p>'
            . '<a href="' . $e($base) . '" style="display:inline-block;background:' . $e($t) . ';color:#fff;text-decoration:none;font-weight:600;padding:11px 26px;border-radius:9px">Try again</a>'
            . '<p style="font-size:12.5px;color:#717E8B;margin-top:28px;max-width:420px">Your work stays on the server. This page is all that ' . $e($c['name']) . ' keeps on this device.</p></main></body></html>';
    }
    /** A Linux desktop launcher (for browsers that cannot install apps, such as Firefox): opens the app in the default browser. */
    function opsh_ai_desktop(array $c, string $url): string {
        $clean = fn($s) => trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)$s));
        return "[Desktop Entry]\nVersion=1.0\nType=Application\nName=" . $clean($c['name']) . "\nComment=Opens " . $clean($c['name']) . " in your web browser\n"
             . "Exec=xdg-open " . preg_replace('/[^A-Za-z0-9:\/._~%?&=#+-]/', '', $url) . "\nIcon=web-browser\nTerminal=false\nCategories=Network;Office;\n";
    }
}
