<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT125: signed update bundles (License Hub, phase 1).
 *
 * A bundle (file name *.itops-update) is a zip that holds:
 *   manifest.json   format "itops-update", build, notes, created_at, key_id, min_php, package_size, package_hash
 *                   (BLAKE2b-256 of package.zip, base64), install_id ("" = any installation), db_update, bridge
 *   manifest.sig    the Ed25519 signature of manifest.json by the Indisse-BTS release key (base64)
 *   package.zip     a full-install zip of that build
 * The release key is not the license key. Builds carry only its public half (itops_release_pubkeys()); the private half
 * lives on the hub (License Manager), wrapped, and in a passphrase-protected backup.
 *
 * On a client, Settings → Updates (Super Admin): check a bundle, then install it in two requests:
 *   install  (old code) signature, checks, every PHP file of the package parsed, database backup, copy of the files it
 *            replaces, maintenance mode on, the new files put in place (one at a time, each written then renamed).
 *            Any failure while placing files puts the old ones back at once.
 *   finish   (new code) the database update, maintenance mode off.
 *   put back the files of the previous build from that copy (the database backup is offered for download).
 * Never touched: config.php, itops-license.php, data/, public/uploads/, .user.ini, php.ini.
 * BT128: install/hub.php (the main installation's installer) is placed only on the main installation.
 */
require_once __DIR__ . '/Build.php';
require_once __DIR__ . '/License.php';

if (!function_exists('itops_release_pubkeys')) {

    /** The release keys this build accepts update bundles from: key id => base64 public key. */
    function itops_release_pubkeys(): array {
        return [
            // A new key goes on a new line; earlier lines stay, so files signed with them keep working.
            // 'xxxxxxxxxxxxxxxx' => 'base64 public key',
        ];
    }

    if (!defined('ITOPS_UPD_MAX')) define('ITOPS_UPD_MAX', 80 * 1048576);           // largest bundle accepted
    if (!defined('ITOPS_UPD_MAINT_MAX')) define('ITOPS_UPD_MAINT_MAX', 15 * 60);     // maintenance mode ends by itself after this

    function itops_upd_root(): string { return dirname(__DIR__, 2); }

    /** data/updates (closed to the web), made on first use. */
    function itops_upd_dir(string $sub = ''): string {
        $base = itops_upd_root() . '/data';
        if (!is_dir($base)) { @mkdir($base, 0700, true); }
        if (!is_file($base . '/.htaccess')) @file_put_contents($base . '/.htaccess', "Require all denied\nDeny from all\n");
        $d = $base . '/updates' . ($sub !== '' ? '/' . $sub : '');
        if (!is_dir($d)) { $um = umask(0077); @mkdir($d, 0700, true); umask($um); }
        return $d;
    }

    /** The number in a build stamp ("0621-BT125" → 125); 0 when there is none. */
    function itops_build_num(string $b): int { return preg_match('/BT(\d+)/i', $b, $m) ? (int)$m[1] : 0; }

    /** Paths an update never writes (relative, forward slashes). */
    function itops_upd_protected(string $rel): bool {
        $r = ltrim(str_replace('\\', '/', $rel), '/');
        foreach (['config.php', 'itops-license.php', '.user.ini', 'php.ini'] as $f) if (strcasecmp($r, $f) === 0) return true;
        if (stripos($r, 'data/') === 0) return true;
        // BT128: the main installation's installer exists only on the main installation (the License Hub)
        if (strcasecmp($r, 'install/hub.php') === 0) return !(defined('ITOPS_LICENSE_MANAGER') && ITOPS_LICENSE_MANAGER === true);
        // people's files; the folder's own guards (.htaccess, index.html) belong to the app and are updated
        if (stripos($r, 'public/uploads/') === 0) return !in_array(strtolower(basename($r)), ['.htaccess', 'index.html'], true);
        return false;
    }

    /** A safe relative path inside the installation, or '' when the zip entry is not one. */
    function itops_upd_clean_path(string $name): string {
        $n = str_replace('\\', '/', $name);
        if ($n === '' || $n[0] === '/' || preg_match('/^[A-Za-z]:/', $n) || strpos($n, "\0") !== false) return '';
        foreach (explode('/', rtrim($n, '/')) as $seg) if ($seg === '..' || $seg === '.') return '';
        return $n;
    }

    /** Is this zip entry a symbolic link (Unix mode in the external attributes)? */
    function itops_upd_is_link(ZipArchive $z, int $i): bool {
        $op = 0; $attr = 0;
        if (!$z->getExternalAttributesIndex($i, $op, $attr)) return false;
        return $op === ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0170000) === 0120000);
    }

    // ───────────────────────── the hub: release key and bundles ─────────────────────────

    function itops_rel_key_info(PDO $db): array {
        $pub = itops_lm_setting($db, 'lm_rel_pub');
        if ($pub === '') return ['exists' => false];
        $kid = itops_license_key_id($pub);
        return ['exists' => true, 'key_id' => $kid, 'public_key' => $pub, 'created_at' => itops_lm_setting($db, 'lm_rel_created'),
                'in_build' => isset(itops_release_pubkeys()[$kid]), 'build_line' => "'" . $kid . "' => '" . $pub . "',"];
    }
    function itops_rel_secret(PDO $db): string {
        $enc = itops_lm_setting($db, 'lm_rel_secret_enc');
        if ($enc === '') throw new RuntimeException('There is no release key yet: create it (or restore its backup) first.');
        $b = base64_decode(vault_decrypt($enc), true);
        if ($b === false || strlen($b) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) throw new RuntimeException('The release key cannot be read (was the vault key of this installation changed?). Restore it from its backup.');
        return $b;
    }
    function itops_rel_store_key(PDO $db, string $secret): array {
        itops_lm_set($db, 'lm_rel_secret_enc', vault_encrypt(base64_encode($secret)));
        itops_lm_set($db, 'lm_rel_pub', base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)));
        itops_lm_set($db, 'lm_rel_created', date('Y-m-d H:i:s'));
        return itops_rel_key_info($db);
    }
    function itops_rel_create_key(PDO $db): array { $kp = sodium_crypto_sign_keypair(); return itops_rel_store_key($db, sodium_crypto_sign_secretkey($kp)); }

    /** The build stamp written in a package's api/lib/Build.php ('' when there is none). */
    function itops_upd_package_build(ZipArchive $z): string {
        $src = $z->getFromName('api/lib/Build.php');
        if ($src === false || strlen($src) > 8192) return '';
        return preg_match("/define\\('ITOPS_BUILD',\\s*'([0-9A-Za-z._-]{3,40})'\\)/", $src, $m) ? $m[1] : '';
    }

    /**
     * Checks a full-install zip before it is signed. Returns ['build', 'files', 'bytes', 'db_update', 'bridge'] or throws
     * InvalidArgumentException with a sentence.
     */
    function itops_rel_inspect(string $zipPath): array {
        $z = new ZipArchive();
        if ($z->open($zipPath, ZipArchive::RDONLY) !== true) throw new InvalidArgumentException('That is not a zip file.');
        try {
            $build = itops_upd_package_build($z);
            if ($build === '' || itops_build_num($build) === 0) throw new InvalidArgumentException('This zip is not a full install of this product built with BT125 or later (no api/lib/Build.php with the build).');
            foreach (['api/index.php', 'index.html', 'app.js', 'api/lib/Updates.php', 'api/lib/Migrations.php'] as $need)
                if ($z->locateName($need) === false) throw new InvalidArgumentException('This zip is not a full install: ' . $need . ' is missing.');
            $files = 0; $bytes = 0;
            for ($i = 0; $i < $z->numFiles; $i++) {
                $st = $z->statIndex($i); $n = (string)$st['name'];
                if (itops_upd_clean_path($n) === '') throw new InvalidArgumentException('The zip holds a path that leaves the installation folder (' . mb_substr($n, 0, 80) . ').');
                if (itops_upd_is_link($z, $i)) throw new InvalidArgumentException('The zip holds a link (' . mb_substr($n, 0, 80) . '); links are not allowed.');
                if (substr($n, -1) === '/') continue;
                if (in_array(strtolower($n), ['config.php', 'itops-license.php'], true)) throw new InvalidArgumentException('The zip holds ' . $n . ' (an installation\'s own file, with its secrets): build the package without it.');
                if (itops_upd_protected($n)) continue;   // never written on a client; left out of the count
                $files++; $bytes += (int)$st['size'];
            }
            $mig = (string)$z->getFromName('api/lib/Migrations.php');
            $dbu = (bool)preg_match("/'\\d{4}_\\d{2}_\\d{2}_\\d{2}_bt" . itops_build_num($build) . "_/i", $mig);
            $br = ''; $bsrc = (string)$z->getFromName('vtiger-bridge/itops-bridge.php');
            if ($bsrc !== '' && preg_match("/ITOPS_BRIDGE_BUILD\\s*=\\s*'([0-9A-Za-z.-]{1,20})'/", $bsrc, $m)) $br = $m[1];
            return ['build' => $build, 'files' => $files, 'bytes' => $bytes, 'db_update' => $dbu, 'bridge' => $br];
        } finally { $z->close(); }
    }

    /**
     * Signs a full-install zip into a bundle. $meta: notes, install_id ('' = any). Returns ['manifest', 'path', 'name', 'size'].
     */
    function itops_rel_make(PDO $db, string $zipPath, array $meta): array {
        $sk = itops_rel_secret($db); $info = itops_rel_key_info($db);
        $pk = itops_rel_inspect($zipPath);
        $iid = strtoupper(trim((string)($meta['install_id'] ?? '')));
        if ($iid !== '' && !preg_match('/^[A-F0-9]{4}(-[A-F0-9]{4}){3}$/', $iid)) throw new InvalidArgumentException('The installation ID looks like XXXX-XXXX-XXXX-XXXX (leave it empty for every installation).');
        $notes = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)($meta['notes'] ?? '')));
        if (mb_strlen($notes) > 4000) throw new InvalidArgumentException('Release notes are at most 4,000 characters.');
        $bytes = (string)file_get_contents($zipPath);
        $man = ['format' => 'itops-update', 'version' => 1, 'build' => $pk['build'], 'notes' => $notes, 'created_at' => date('Y-m-d H:i:s'),
                'key_id' => $info['key_id'], 'min_php' => '8.0.0', 'package_size' => strlen($bytes),
                'package_hash' => base64_encode(sodium_crypto_generichash($bytes, '', 32)), 'install_id' => $iid,
                'db_update' => $pk['db_update'], 'bridge' => $pk['bridge'], 'files' => $pk['files']];
        $mj = json_encode($man, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $sig = base64_encode(sodium_crypto_sign_detached($mj, $sk)); sodium_memzero($sk);
        $dir = itops_upd_dir('releases');
        $name = preg_replace('/[^A-Za-z0-9._-]/', '-', $pk['build'] . ($iid !== '' ? '-' . $iid : '')) . '.itops-update';
        $tmp = $dir . '/.' . bin2hex(random_bytes(6));
        $z = new ZipArchive();
        if ($z->open($tmp, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('the bundle could not be written');
        $z->addFromString('manifest.json', $mj); $z->addFromString('manifest.sig', $sig);
        $z->addFile($zipPath, 'package.zip'); $z->setCompressionName('package.zip', ZipArchive::CM_STORE);
        if (!$z->close()) { @unlink($tmp); throw new RuntimeException('the bundle could not be written'); }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $dir . '/' . $name)) { @unlink($tmp); throw new RuntimeException('the bundle could not be saved'); }
        return ['manifest' => $man, 'path' => $dir . '/' . $name, 'name' => $name, 'size' => (int)filesize($dir . '/' . $name)];
    }

    // ───────────────────────── a client: open, check, install ─────────────────────────

    /**
     * Opens a bundle and checks its signature and package. Returns ['manifest', 'package' => path of package.zip in
     * data/updates]. Throws InvalidArgumentException with a sentence.
     */
    function itops_upd_open(string $bundle): array {
        if (!function_exists('sodium_crypto_sign_verify_detached')) throw new InvalidArgumentException('This server cannot check signatures (the PHP sodium extension is missing), so it cannot install updates.');
        if (!is_file($bundle) || filesize($bundle) > ITOPS_UPD_MAX) throw new InvalidArgumentException('The file is missing or too large for an update bundle.');
        $z = new ZipArchive();
        if ($z->open($bundle, ZipArchive::RDONLY) !== true) throw new InvalidArgumentException('That is not an update bundle (not a zip file).');
        try {
            $mj = $z->getFromName('manifest.json'); $sig = $z->getFromName('manifest.sig');
            if ($mj === false || $sig === false || strlen($mj) > 65536 || $z->locateName('package.zip') === false) throw new InvalidArgumentException('That is not an update bundle (its manifest or package is missing).');
            $man = json_decode($mj, true);
            if (!is_array($man) || ($man['format'] ?? '') !== 'itops-update') throw new InvalidArgumentException('That is not an update bundle.');
            $keys = itops_release_pubkeys(); $kid = (string)($man['key_id'] ?? '');
            if (!$keys) throw new InvalidArgumentException('This build carries no release key, so it cannot check where an update comes from. Install the next build by hand once.');
            if (!isset($keys[$kid])) throw new InvalidArgumentException('The bundle was not signed by a release key this build knows (' . preg_replace('/[^0-9a-f]/', '', $kid) . ').');
            $s = base64_decode(trim($sig), true); $p = base64_decode($keys[$kid], true);
            if ($s === false || $p === false || strlen($s) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($s, $mj, $p))
                throw new InvalidArgumentException('The bundle\'s signature does not match: it was changed or damaged. Nothing was installed.');
            $tmp = itops_upd_dir('staged') . '/package-' . bin2hex(random_bytes(6)) . '.zip';
            $in = $z->getStream('package.zip'); $out = @fopen($tmp, 'wb');
            if (!$in || !$out) throw new RuntimeException('the package could not be unpacked to data/updates');
            $h = sodium_crypto_generichash_init('', 32); $n = 0;
            while (!feof($in)) { $c = fread($in, 1048576); if ($c === false) break; $n += strlen($c); if ($n > ITOPS_UPD_MAX) break; sodium_crypto_generichash_update($h, $c); fwrite($out, $c); }
            fclose($in); fclose($out); @chmod($tmp, 0600);
            if ($n !== (int)($man['package_size'] ?? -1) || !hash_equals((string)($man['package_hash'] ?? ''), base64_encode(sodium_crypto_generichash_final($h, 32)))) {
                @unlink($tmp); throw new InvalidArgumentException('The package inside the bundle does not match its signed manifest: it was changed or damaged. Nothing was installed.');
            }
            return ['manifest' => $man, 'package' => $tmp];
        } finally { $z->close(); }
    }

    /**
     * What stands in the way of installing (problems) and what to know (notes). Never changes anything.
     * Returns ['problems' => [...], 'notes' => [...], 'files' => n, 'replace' => n, 'new' => n].
     */
    function itops_upd_check(PDO $db, array $man, string $pkg): array {
        $pr = []; $no = []; $root = itops_upd_root();
        $cur = ITOPS_BUILD; $to = (string)($man['build'] ?? '');
        if (itops_build_num($to) === 0) $pr[] = 'The bundle does not say which build it holds.';
        elseif (itops_build_num($to) === itops_build_num($cur)) $pr[] = 'Build ' . $to . ' is already installed here.';
        elseif (itops_build_num($to) < itops_build_num($cur)) $pr[] = 'Build ' . $to . ' is older than the installed ' . $cur . ': going back is done with "Put the previous build back", not with an older bundle.';
        $iid = (string)($man['install_id'] ?? '');
        if ($iid !== '' && function_exists('itops_install_id') && strtoupper($iid) !== strtoupper(itops_install_id($db))) $pr[] = 'The bundle was made for another installation (' . $iid . ').';
        if (version_compare(PHP_VERSION, (string)($man['min_php'] ?? '8.0.0'), '<')) $pr[] = 'It needs PHP ' . $man['min_php'] . ' or later; this server runs ' . PHP_VERSION . '.';
        $z = new ZipArchive(); if ($z->open($pkg, ZipArchive::RDONLY) !== true) return ['problems' => array_merge($pr, ['The package cannot be read.']), 'notes' => $no, 'files' => 0, 'replace' => 0, 'new' => 0];
        $files = 0; $rep = 0; $new = 0; $bytes = 0; $ro = []; $badp = [];
        try {
            if (itops_upd_package_build($z) !== $to) $pr[] = 'The package\'s own build stamp does not match the bundle.';
            for ($i = 0; $i < $z->numFiles; $i++) {
                $st = $z->statIndex($i); $n = (string)$st['name'];
                if (itops_upd_clean_path($n) === '' || itops_upd_is_link($z, $i)) { $badp[] = $n; continue; }
                if (substr($n, -1) === '/' || itops_upd_protected($n)) continue;
                $files++; $bytes += (int)$st['size']; $t = $root . '/' . $n;
                if (file_exists($t)) { $rep++; if (!is_writable($t) || is_link($t)) $ro[] = $n; }
                else { $new++; $d = dirname($t); while (!is_dir($d) && $d !== $root) $d = dirname($d); if (!is_writable($d)) $ro[] = $n; }
            }
        } finally { $z->close(); }
        if ($badp) $pr[] = 'The package holds paths that leave the installation folder or links (' . implode(', ', array_slice($badp, 0, 3)) . ').';
        if ($ro) $pr[] = 'The web server cannot write ' . count($ro) . ' file' . (count($ro) === 1 ? '' : 's') . ' it must replace (for example ' . implode(', ', array_slice($ro, 0, 3)) . '). Give the account that runs PHP write access to the installation folder, or install by uploading the files.';
        $free = @disk_free_space($root);
        if ($free !== false && $free < $bytes * 3 + 50 * 1048576) $pr[] = 'Not enough free disk space for the update, its copy of the old files and the database backup.';
        if (!empty($man['db_update'])) $no[] = 'It carries a database update; it runs at the end of the install.';
        $curBr = ''; $cb = (string)@file_get_contents($root . '/vtiger-bridge/itops-bridge.php');
        if ($cb !== '' && preg_match("/ITOPS_BRIDGE_BUILD\\s*=\\s*'([0-9A-Za-z.-]{1,20})'/", $cb, $m)) $curBr = $m[1];
        if (!empty($man['bridge']) && $man['bridge'] !== $curBr) $no[] = 'It carries vTiger bridge build ' . $man['bridge'] . ': upload vtiger-bridge/itops-bridge.php to the vTiger server yourself (it runs on another server).';
        if (is_file($root . '/.htaccess')) $no[] = 'The .htaccess is replaced; your current one is kept with the copy of the old files.';
        return ['problems' => $pr, 'notes' => $no, 'files' => $files, 'replace' => $rep, 'new' => $new];
    }

    /** Every PHP file of the package must parse before anything changes. Returns the first problem or ''. */
    function itops_upd_parse_all(string $pkg): string {
        $z = new ZipArchive(); if ($z->open($pkg, ZipArchive::RDONLY) !== true) return 'The package cannot be read.';
        try {
            for ($i = 0; $i < $z->numFiles; $i++) {
                $n = (string)$z->getNameIndex($i);
                if (substr($n, -4) !== '.php' || itops_upd_protected($n)) continue;
                $src = $z->getFromIndex($i); if ($src === false) return 'The package cannot be read (' . $n . ').';
                try { token_get_all($src, TOKEN_PARSE); } catch (ParseError $e) { return $n . ' does not parse (line ' . $e->getLine() . '): the package is broken. Nothing was installed.'; }
            }
        } finally { $z->close(); }
        return '';
    }

    function itops_upd_maint_file(): string { return itops_upd_dir() . '/maintenance.json'; }
    /** Maintenance mode as the router sees it: null when off (or when it outlived ITOPS_UPD_MAINT_MAX). */
    function itops_upd_maintenance(): ?array {
        $f = itops_upd_root() . '/data/updates/maintenance.json';
        if (!is_file($f)) return null;
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j) || time() - (int)($j['since'] ?? 0) > ITOPS_UPD_MAINT_MAX) return null;
        return $j;
    }
    function itops_upd_maint_on(string $to, int $by): void { itops_cache_write_plain(itops_upd_maint_file(), json_encode(['since' => time(), 'to' => $to, 'by' => $by])); }
    function itops_upd_maint_off(): void { @unlink(itops_upd_maint_file()); }
    function itops_cache_write_plain(string $f, string $data): void { $t = $f . '.' . bin2hex(random_bytes(4)); @file_put_contents($t, $data); @chmod($t, 0600); @rename($t, $f); }

    /** The last result, kept in Settings (for the page and the hub check-in later). */
    function itops_upd_last(PDO $db, ?array $set = null): array {
        if ($set !== null) { $db->prepare("INSERT INTO itops_settings (skey,sval) VALUES ('update_last',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([json_encode($set)]); return $set; }
        $v = $db->query("SELECT sval FROM itops_settings WHERE skey='update_last'")->fetchColumn();
        $j = json_decode((string)$v, true); return is_array($j) ? $j : [];
    }

    /**
     * Step 1 (old code). Returns ['backup' => folder name, 'placed' => n, 'new' => n]. Throws with a sentence; on a
     * failure while placing files the old files are put back before it throws.
     */
    function itops_upd_install(PDO $db, string $pkg, array $man, int $by, string $appName): array {
        $root = itops_upd_root();
        $chk = itops_upd_check($db, $man, $pkg); if ($chk['problems']) throw new InvalidArgumentException($chk['problems'][0]);
        if (($e = itops_upd_parse_all($pkg)) !== '') throw new InvalidArgumentException($e);
        $name = 'backup-' . preg_replace('/[^A-Za-z0-9-]/', '', ITOPS_BUILD) . '-' . date('Ymd-His');
        $bk = itops_upd_dir('backups/' . $name);
        require_once __DIR__ . '/DbHealth.php';
        $dbBytes = itops_dbh_backup_file($db, $appName, $bk . '/database.sql.gz');
        $z = new ZipArchive(); if ($z->open($pkg, ZipArchive::RDONLY) !== true) throw new RuntimeException('the package cannot be read');
        $copied = []; $created = []; $placed = 0;
        $restore = function () use (&$copied, &$created, $bk, $root) {
            foreach ($copied as $rel) { $t = $root . '/' . $rel; $tmp = $t . '.itops-restore'; if (@copy($bk . '/files/' . $rel, $tmp)) @rename($tmp, $t); }
            foreach ($created as $rel) @unlink($root . '/' . $rel);
            if (function_exists('opcache_reset')) @opcache_reset();
        };
        itops_upd_maint_on((string)$man['build'], $by);
        try {
            for ($i = 0; $i < $z->numFiles; $i++) {
                $n = (string)$z->getNameIndex($i);
                if (substr($n, -1) === '/' || itops_upd_protected($n) || itops_upd_clean_path($n) === '') continue;
                $t = $root . '/' . $n;
                if (is_file($t)) {
                    $b = $bk . '/files/' . $n; if (!is_dir(dirname($b))) @mkdir(dirname($b), 0700, true);
                    if (!@copy($t, $b)) throw new RuntimeException('could not copy ' . $n . ' aside before replacing it');
                    $copied[] = $n;
                } else { if (!is_dir(dirname($t)) && !@mkdir(dirname($t), 0755, true)) throw new RuntimeException('could not make the folder for ' . $n); $created[] = $n; }
                $data = $z->getFromIndex($i); if ($data === false) throw new RuntimeException('could not read ' . $n . ' from the package');
                $tmp = $t . '.itops-new';
                if (@file_put_contents($tmp, $data) !== strlen($data)) { @unlink($tmp); throw new RuntimeException('could not write ' . $n); }
                @chmod($tmp, 0644);
                if (!@rename($tmp, $t)) { @unlink($tmp); throw new RuntimeException('could not put ' . $n . ' in place'); }
                $placed++;
            }
        } catch (Throwable $e) {
            $restore(); itops_upd_maint_off(); $z->close();
            throw new RuntimeException('The update stopped (' . $e->getMessage() . '). The previous files were put back; nothing else changed. The database backup is in ' . $name . '.');
        }
        $z->close();
        if (function_exists('opcache_reset')) @opcache_reset();
        file_put_contents($bk . '/manifest.json', json_encode(['from' => ITOPS_BUILD, 'to' => $man['build'], 'copied' => $copied, 'created' => $created, 'db_bytes' => $dbBytes, 'at' => date('Y-m-d H:i:s'), 'by' => $by], JSON_UNESCAPED_SLASHES));
        @unlink($pkg);
        return ['backup' => $name, 'placed' => $placed, 'new' => count($created), 'from' => ITOPS_BUILD];
    }

    /** "Put the previous build back": the files from a backup folder; the database stays as it is (its backup is offered). */
    function itops_upd_put_back(string $name): array {
        if (!preg_match('/^backup-[A-Za-z0-9-]{1,40}-\d{8}-\d{6}$/', $name)) throw new InvalidArgumentException('Unknown backup.');
        $bk = itops_upd_root() . '/data/updates/backups/' . $name; $root = itops_upd_root();
        $m = json_decode((string)@file_get_contents($bk . '/manifest.json'), true);
        if (!is_array($m)) throw new InvalidArgumentException('That backup has no record of what it replaced.');
        $bad = [];
        foreach ((array)$m['copied'] as $rel) {
            if (itops_upd_clean_path((string)$rel) === '' || itops_upd_protected((string)$rel)) continue;
            $t = $root . '/' . $rel; $tmp = $t . '.itops-restore';
            if (!@copy($bk . '/files/' . $rel, $tmp) || !@rename($tmp, $t)) { @unlink($tmp); $bad[] = $rel; }
        }
        foreach ((array)$m['created'] as $rel) if (itops_upd_clean_path((string)$rel) !== '' && !itops_upd_protected((string)$rel)) @unlink($root . '/' . $rel);
        if (function_exists('opcache_reset')) @opcache_reset();
        return ['build' => (string)$m['from'], 'failed' => $bad];
    }

    /** The backup folders, newest first, with what they hold. */
    function itops_upd_backups(): array {
        $out = []; $d = itops_upd_root() . '/data/updates/backups';
        foreach ((array)@glob($d . '/backup-*', GLOB_ONLYDIR) as $p) {
            $m = json_decode((string)@file_get_contents($p . '/manifest.json'), true); if (!is_array($m)) continue;
            $out[] = ['name' => basename($p), 'from' => (string)$m['from'], 'to' => (string)$m['to'], 'at' => (string)$m['at'], 'files' => count((array)$m['copied']), 'db_bytes' => (int)($m['db_bytes'] ?? 0)];
        }
        usort($out, fn($a, $b) => strcmp($b['at'], $a['at']));
        return $out;
    }
    /** Keep the three newest backups. */
    function itops_upd_prune(): void {
        foreach (array_slice(itops_upd_backups(), 3) as $b) {
            $p = itops_upd_root() . '/data/updates/backups/' . $b['name'];
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
            @rmdir($p);
        }
    }
}
