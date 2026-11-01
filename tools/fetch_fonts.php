<?php
/**
 * fetch_fonts.php — download the web fonts once so they can be served from your own host.
 *
 * Why: loading fonts from Google's CDN means every page view tells a third party who your
 * users are, and it forces two extra origins into the Content-Security-Policy. Self-hosting
 * removes both. Run this once on the server (it needs outbound HTTPS), then set
 *     'fonts' => 'self'
 * in app/config.php. If you'd rather not use web fonts at all, set 'fonts' => 'system'
 * and skip this entirely — the app falls back to the OS font stack.
 *
 * Usage (from the app root):   php tools/fetch_fonts.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Run this from the command line.\n"); }

$root    = dirname(__DIR__);
$destDir = $root . '/assets/fonts';
if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
    exit("Could not create {$destDir} — create it manually and re-run.\n");
}

// Same families the layout requests.
$cssUrl = 'https://fonts.googleapis.com/css2'
    . '?family=Archivo:wght@600;700;800'
    . '&family=IBM+Plex+Sans:wght@400;500;600'
    . '&family=IBM+Plex+Mono:wght@400;500'
    . '&display=swap';

/** Fetch a URL, pretending to be a modern browser so Google serves woff2. */
function grab(string $url): ?string
{
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30, CURLOPT_USERAGENT => $ua,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body === false || $code >= 400) ? null : (string) $body;
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => $ua, 'follow_location' => 1]]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }
    return null;
}

echo "Fetching font stylesheet…\n";
$css = grab($cssUrl);
if ($css === null) {
    exit("Could not reach fonts.googleapis.com. This host may not allow outbound HTTPS from PHP.\n"
       . "Alternative: set 'fonts' => 'system' in app/config.php to drop web fonts entirely.\n");
}

// Pull every font file out of the stylesheet and rewrite the URLs to local paths.
preg_match_all('#https://fonts\.gstatic\.com/[^)\'"]+#', $css, $m);
$urls = array_values(array_unique($m[0]));
if ($urls === []) exit("No font files found in the stylesheet — nothing to do.\n");

$saved = 0;
foreach ($urls as $u) {
    $name = basename(parse_url($u, PHP_URL_PATH) ?: '');
    if ($name === '') continue;
    // Keep names unique and predictable across families/weights.
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    $bin  = grab($u);
    if ($bin === null) { echo "  ! failed: {$name}\n"; continue; }
    file_put_contents($destDir . '/' . $name, $bin);
    $css = str_replace($u, './' . $name, $css);
    $saved++;
    echo "  saved {$name}\n";
}

file_put_contents($destDir . '/fonts.css', $css);
echo "\nDone — {$saved} font file(s) in assets/fonts/.\n";
echo "Now set 'fonts' => 'self' in app/config.php.\n";
