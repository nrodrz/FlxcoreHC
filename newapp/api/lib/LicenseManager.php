<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — License Manager (BT105). Only in the owner's own installation: it is switched on by one line in that
 * installation's config.php — define('ITOPS_LICENSE_MANAGER', true); — and only a Super Admin uses it. Client
 * installations never have it on.
 *
 * It keeps the owner's signing key (Ed25519; the private half is stored wrapped with the installation's vault key and
 * never sent to the browser), the register of every license issued, and it issues each license as a signed
 * itops-license.php. The public half of the key goes into the ITOps builds (License.php → itops_license_pubkeys()).
 *
 * Key backup: a file encrypted with a passphrase (Argon2id + XSalsa20-Poly1305). Without the key (or its backup) no new
 * license can be issued that the builds accept, so the backup is kept somewhere safe, away from the server.
 */
require_once __DIR__ . '/License.php';
require_once __DIR__ . '/Modules.php';

if (!function_exists('itops_lm_enabled')) {

    function itops_lm_enabled(): bool { return defined('ITOPS_LICENSE_MANAGER') && ITOPS_LICENSE_MANAGER === true && function_exists('sodium_crypto_sign_detached'); }
    function itops_lm_ready(PDO $db): bool { try { $db->query('SELECT 1 FROM itops_lm_licenses LIMIT 1'); return true; } catch (Throwable $e) { return false; } }

    function itops_lm_setting(PDO $db, string $k): string { $q = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $q->execute([$k]); return (string)$q->fetchColumn(); }
    function itops_lm_set(PDO $db, string $k, string $v): void { $db->prepare('INSERT INTO itops_settings (skey,sval) VALUES (?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)')->execute([$k, $v]); }

    /** The signing key as the screens may see it (never the private half). */
    function itops_lm_key_info(PDO $db): array {
        $pub = itops_lm_setting($db, 'lm_sign_pub');
        if ($pub === '') return ['exists' => false];
        $kid = itops_license_key_id($pub);
        return ['exists' => true, 'key_id' => $kid, 'public_key' => $pub, 'created_at' => itops_lm_setting($db, 'lm_sign_created'),
                'in_build' => isset(itops_license_pubkeys()[$kid]), 'build_line' => "'" . $kid . "' => '" . $pub . "',"];
    }
    /** Private key (raw bytes) for signing. Throws when there is none. */
    function itops_lm_secret(PDO $db): string {
        $enc = itops_lm_setting($db, 'lm_sign_secret_enc');
        if ($enc === '') throw new RuntimeException('There is no signing key yet: create it (or restore its backup) first.');
        $b = base64_decode(vault_decrypt($enc), true);
        if ($b === false || strlen($b) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) throw new RuntimeException('The signing key cannot be read (was the vault key of this installation changed?). Restore it from its backup.');
        return $b;
    }
    function itops_lm_store_key(PDO $db, string $secret): array {
        $pub = sodium_crypto_sign_publickey_from_secretkey($secret);
        itops_lm_set($db, 'lm_sign_secret_enc', vault_encrypt(base64_encode($secret)));
        itops_lm_set($db, 'lm_sign_pub', base64_encode($pub));
        itops_lm_set($db, 'lm_sign_created', date('Y-m-d H:i:s'));
        return itops_lm_key_info($db);
    }
    function itops_lm_create_key(PDO $db): array { $kp = sodium_crypto_sign_keypair(); return itops_lm_store_key($db, sodium_crypto_sign_secretkey($kp)); }

    /** Backup of the key, encrypted with a passphrase. Returns the backup as a JSON string. */
    function itops_lm_backup(PDO $db, string $pass): string { return itops_lm_backup_of(itops_lm_secret($db), itops_lm_key_info($db), 'itops-license-key-backup', $pass); }
    /** BT125: a key backup in one format for both keys (license key, release key). */
    function itops_lm_backup_of(string $sk, array $info, string $format, string $pass): string {
        if (mb_strlen($pass) < 12) throw new InvalidArgumentException('Use a passphrase of at least 12 characters (and keep it apart from the backup file).');
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES); $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $k = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $pass, $salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        return json_encode(['format' => $format, 'version' => 1, 'key_id' => $info['key_id'], 'public_key' => $info['public_key'], 'created_at' => $info['created_at'],
            'kdf' => 'argon2id13', 'ops' => SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, 'mem' => SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
            'salt' => base64_encode($salt), 'nonce' => base64_encode($nonce), 'secret' => base64_encode(sodium_crypto_secretbox($sk, $nonce, $k))], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    /** The private key inside a backup (raw bytes). Throws with a sentence on any problem. */
    function itops_lm_open_backup(string $json, string $pass, string $format = 'itops-license-key-backup'): string {
        $b = json_decode($json, true);
        if (!is_array($b) || ($b['format'] ?? '') !== $format) throw new InvalidArgumentException($format === 'itops-license-key-backup' ? 'This is not a license key backup.' : 'This is not a release key backup.');
        $salt = base64_decode((string)($b['salt'] ?? ''), true); $nonce = base64_decode((string)($b['nonce'] ?? ''), true); $box = base64_decode((string)($b['secret'] ?? ''), true);
        $ops = (int)($b['ops'] ?? 0); $mem = (int)($b['mem'] ?? 0);
        if ($salt === false || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES || $nonce === false || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || $box === false
            || $ops < SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE || $ops > 16 || $mem < SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE || $mem > 1073741824) throw new InvalidArgumentException('The backup is damaged.');
        $k = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $pass, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $sk = sodium_crypto_secretbox_open($box, $nonce, $k);
        if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) throw new InvalidArgumentException('The passphrase is not right (or the backup is damaged).');
        if (base64_encode(sodium_crypto_sign_publickey_from_secretkey($sk)) !== (string)($b['public_key'] ?? '')) throw new InvalidArgumentException('The backup is damaged.');
        return $sk;
    }

    /** The next serial number: ITOPS-<year>-<4 digits>. */
    function itops_lm_next_serial(PDO $db): string {
        $y = date('Y'); $q = $db->prepare('SELECT serial FROM itops_lm_licenses WHERE serial LIKE ? ORDER BY id DESC LIMIT 1'); $q->execute(['ITOPS-' . $y . '-%']);
        $n = (int)substr((string)$q->fetchColumn(), 10); return sprintf('ITOPS-%s-%04d', $y, $n + 1);
    }

    /** Checks the form of a license to issue. Returns the clean payload (without serial / issued_at) or throws. */
    function itops_lm_clean(array $b): array {
        $t = function ($k, $max, $req = false) use ($b) {
            $v = $b[$k] ?? ''; if (!is_string($v) && !is_numeric($v)) throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $k)) . ' is text.');
            $v = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$v)); if (mb_strlen($v) > $max) throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $k)) . ' is at most ' . $max . ' characters.');
            if ($req && $v === '') throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $k)) . ' is required.'); return $v; };
        $d = function ($k, $req) use ($b) { $v = trim((string)($b[$k] ?? '')); if ($v === '' && !$req) return '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4))) throw new InvalidArgumentException(($k === 'term_start' ? 'The start date' : 'The end date') . ' is YYYY-MM-DD.'); return $v; };
        $p = ['licensee' => $t('licensee', 200, true), 'install_id' => strtoupper($t('install_id', 19, true)), 'agreement' => $t('agreement', 60), 'kind' => $t('kind', 40), 'notes' => $t('notes', 500),
              'term_start' => $d('term_start', true), 'term_end' => $d('term_end', false)];
        if (!preg_match('/^[A-F0-9]{4}(-[A-F0-9]{4}){3}$/', $p['install_id'])) throw new InvalidArgumentException('The installation ID looks like XXXX-XXXX-XXXX-XXXX (About & ownership of the installation shows it).');
        if ($p['term_end'] !== '' && $p['term_end'] < $p['term_start']) throw new InvalidArgumentException('The end date is before the start date.');
        $plan = (string)($b['plan'] ?? ''); if ($plan !== '' && !isset(itops_plans()[$plan])) throw new InvalidArgumentException('Unknown plan.');
        $mods = $b['modules'] ?? []; if (!is_array($mods)) throw new InvalidArgumentException('Modules is a list.');
        $cat = itops_modules_catalog(); foreach ($mods as $m) if (!is_string($m) || !isset($cat[$m])) throw new InvalidArgumentException('Unknown module.');
        if ($plan === '' && !$mods) throw new InvalidArgumentException('Choose a plan, extra modules, or both.');
        $u = $b['users'] ?? 0; if (!is_int($u) && !(is_string($u) && ctype_digit($u))) throw new InvalidArgumentException('Users is a whole number (0 = no limit).');
        $p['plan'] = $plan; $p['modules'] = array_values(array_unique($mods)); $p['users'] = min(100000, (int)$u);
        $p['contact'] = $t('contact', 200);
        return $p;
    }

    /** Issues (signs and records) a license. Returns the stored row. */
    function itops_lm_issue(PDO $db, array $p, int $by, ?int $replaces = null): array {
        $sk = itops_lm_secret($db); $info = itops_lm_key_info($db);
        $now = time(); $p['serial'] = itops_lm_next_serial($db); $p['issued_at'] = date('Y-m-d', $now);
        $sig = base64_encode(sodium_crypto_sign_detached(itops_license_canonical($p), $sk));
        $db->prepare('INSERT INTO itops_lm_licenses (serial, licensee, contact, install_id, agreement, kind, plan, modules, users, term_start, term_end, notes, status, replaces_id, issued_at, issued_by, key_id, signature)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$p['serial'], $p['licensee'], $p['contact'], $p['install_id'], $p['agreement'], $p['kind'], $p['plan'], json_encode($p['modules']), $p['users'], $p['term_start'],
                      $p['term_end'] === '' ? null : $p['term_end'], $p['notes'], 'issued', $replaces, date('Y-m-d H:i:s', $now), $by, $info['key_id'], $sig]);
        $id = (int)$db->lastInsertId();
        if ($replaces) $db->prepare("UPDATE itops_lm_licenses SET status='replaced' WHERE id=? AND status='issued'")->execute([$replaces]);
        return itops_lm_row($db, $id);
    }
    function itops_lm_row(PDO $db, int $id): ?array {
        $q = $db->prepare('SELECT l.*, u.display_name AS issued_by_name FROM itops_lm_licenses l LEFT JOIN itops_users u ON u.id=l.issued_by WHERE l.id=?'); $q->execute([$id]);
        $r = $q->fetch(PDO::FETCH_ASSOC); if (!$r) return null;
        $r['id'] = (int)$r['id']; $r['users'] = (int)$r['users']; $r['modules'] = json_decode((string)$r['modules'], true) ?: []; $r['replaces_id'] = $r['replaces_id'] === null ? null : (int)$r['replaces_id'];
        $r['term_end'] = (string)($r['term_end'] ?? ''); $r['issued_on'] = substr((string)$r['issued_at'], 0, 10);
        return $r;
    }
    /** The license file of a stored row (rebuilt from the register, with its stored signature). */
    function itops_lm_file(array $r): string {
        $p = ['serial' => $r['serial'], 'agreement' => $r['agreement'], 'licensee' => $r['licensee'], 'install_id' => $r['install_id'], 'kind' => $r['kind'], 'plan' => $r['plan'],
              'modules' => $r['modules'], 'users' => $r['users'], 'term_start' => $r['term_start'], 'term_end' => $r['term_end'], 'notes' => $r['notes'], 'issued_at' => $r['issued_on']];
        return itops_license_file($p, $r['key_id'], $r['signature']);
    }
}
