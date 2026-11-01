<?php
/**
 * Router for PHP's built-in server (TEST / LOCAL USE ONLY — production runs on Apache/cPanel with .htaccess):
 *   php -S 127.0.0.1:8099 tools/router.php
 * Mirrors .htaccess: server-side directories and config/sql/md/sh/txt files are refused, real files (assets, install.php)
 * are served directly, everything else goes to the front controller.
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
if (preg_match('#^/(app|database|cron|tools|tests|docs)(/|$)#', $path) || preg_match('#(^|/)(config[^/]*\.php|\.[^/]*|[^/]*\.(sql|md|sh|txt))$#', $path)) {
    http_response_code(403); echo 'Forbidden'; return true;
}
$file = realpath($root . $path);
if ($file !== false && is_file($file) && str_starts_with($file, $root . '/')) {
    if (str_ends_with($file, '.php') && basename($file) !== 'index.php') {
        if (basename($file) === 'install.php') { require $file; return true; }
        http_response_code(403); echo 'Forbidden'; return true;
    }
    return false;   // let the built-in server stream the static file
}
require $root . '/index.php';
return true;
