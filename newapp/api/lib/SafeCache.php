<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT124 (code review M365A-1): the folders the app caches in (tokens, Microsoft sign-in keys, Microsoft 365
 * pages, rate-limit counters). On a shared server another account can create a folder of the same name in the system temp
 * folder before the app does; the app then never uses a folder it does not own, that is a link, or that others can write.
 *
 *  itops_cache_dir($name)  ITOPS_CACHE_DIR/<name> when set; else <temp>/itops_<install>_<name> when it is safe; else the
 *                         installation's own folder data/cache/<name> (closed to the web by its .htaccess).
 */
if (!function_exists('itops_cache_dir')) {
    function itops_cache_safe(string $dir): bool {
        if (!is_dir($dir)) { $um = umask(0077); @mkdir($dir, 0700, true); umask($um); }
        clearstatcache(true, $dir);
        if (!is_dir($dir) || is_link($dir)) return false;
        if (function_exists('posix_geteuid') && @fileowner($dir) !== posix_geteuid()) return false;
        if ((@fileperms($dir) & 0077) !== 0) { @chmod($dir, 0700); clearstatcache(true, $dir); if ((@fileperms($dir) & 0077) !== 0) return false; }
        return is_writable($dir);
    }
    function itops_cache_dir(string $name): string {
        static $memo = [];
        $name = preg_replace('/[^A-Za-z0-9_-]/', '_', $name);
        if (isset($memo[$name])) return $memo[$name];
        if (defined('ITOPS_CACHE_DIR') && ITOPS_CACHE_DIR !== '') { $d = rtrim(ITOPS_CACHE_DIR, '/') . '/' . $name; itops_cache_safe($d); return $memo[$name] = $d; }
        $inst = substr(sha1(dirname(__DIR__, 2) . '|' . (defined('DB_NAME') ? DB_NAME : '')), 0, 16);
        $d = rtrim(sys_get_temp_dir(), '/') . '/itops_' . $inst . '_' . $name;
        if (itops_cache_safe($d)) return $memo[$name] = $d;
        $base = dirname(__DIR__, 2) . '/data';
        if (!is_dir($base)) { @mkdir($base, 0700, true); @file_put_contents($base . '/.htaccess', "Require all denied\nDeny from all\n"); }
        $d = $base . '/cache/' . $name; itops_cache_safe($d);
        return $memo[$name] = $d;
    }
    /** Write a cache file only this account can read (0600), in one step. */
    function itops_cache_write(string $file, string $data): bool {
        $tmp = @tempnam(dirname($file), '.w'); if ($tmp === false) return false;
        if (@file_put_contents($tmp, $data) === false) { @unlink($tmp); return false; }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
        return true;
    }
    /** Read a cache file only when this account owns it. */
    function itops_cache_read(string $file): ?string {
        if (!is_file($file) || is_link($file)) return null;
        if (function_exists('posix_geteuid') && @fileowner($file) !== posix_geteuid()) return null;
        $r = @file_get_contents($file); return $r === false ? null : $r;
    }
}
