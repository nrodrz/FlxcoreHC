<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — signed license files (BT105).
 *
 * The owner (Indisse-BTS) issues each installation a license file, itops-license.php, from the License Manager of its own
 * installation. The file is signed with the owner's private signing key (Ed25519); every ITOps build carries only the
 * public half (itops_license_pubkeys() below), so an installation can tell a genuine, unchanged file from one that was
 * edited or written by someone else. Nothing is sent anywhere: the check is done here, offline.
 *
 * Enforcement starts with the first build that carries a public key. From then on (decisions, 10/23/2026):
 *   · a genuine file for this installation, within its term, decides what is licensed (plan + extra modules);
 *   · no file, or a file that is not genuine (edited, damaged, unsigned, for another installation, or whose term ended
 *     more than 14 days ago), gets a 30-day trial with every module, counted from the day this installation first ran a
 *     build with a key (so installations that upgrade are not cut off at once); after the trial only the core is on.
 * Builds without a key behave as before (no file = everything; the file is read as it is).
 *
 * Honest limit: the installation holds the program's source, so a determined person could edit the check itself. The
 * signature makes any change to the license file evident; the license agreement is what is enforced.
 */
require_once __DIR__ . '/Brand.php';

if (!function_exists('itops_license_pubkeys')) {

    /** key id => base64 Ed25519 public key. Filled in when Indisse-BTS creates its signing key (License Manager). */
    function itops_license_pubkeys(): array {
        return [
            // A new key goes on a new line; earlier lines stay, so files signed with them keep working.
            // 'xxxxxxxxxxxxxxxx' => 'base64 public key',
        ];
    }
    function itops_license_enforced(): bool { return (bool)itops_license_pubkeys() && function_exists('sodium_crypto_sign_verify_detached'); }
    if (!defined('ITOPS_LICENSE_TRIAL_DAYS')) define('ITOPS_LICENSE_TRIAL_DAYS', 30);
    if (!defined('ITOPS_LICENSE_GRACE_DAYS')) define('ITOPS_LICENSE_GRACE_DAYS', 14);

    /** Key id of a public key: the first 16 hex characters of its SHA-256. */
    function itops_license_key_id(string $pubB64): string { return substr(hash('sha256', (string)base64_decode($pubB64, true)), 0, 16); }

    /** The fields that are signed, in a fixed order. */
    function itops_license_fields(): array {
        return ['serial', 'agreement', 'licensee', 'install_id', 'kind', 'plan', 'modules', 'users', 'term_start', 'term_end', 'notes', 'issued_at'];
    }
    /** The exact text that is signed: the fields in order, as JSON. */
    function itops_license_canonical(array $r): string {
        $o = [];
        foreach (itops_license_fields() as $k) {
            $v = $r[$k] ?? ($k === 'modules' ? [] : ($k === 'users' ? 0 : ''));
            if ($k === 'modules') $v = array_values(array_map('strval', is_array($v) ? $v : []));
            elseif ($k === 'users') $v = max(0, (int)$v);
            else $v = (string)$v;
            $o[$k] = $v;
        }
        return json_encode($o, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Signature check of a raw license array: valid | invalid | unsigned | unknown_key | unavailable. */
    function itops_license_check(array $raw): string {
        if (!isset($raw['signature']) || $raw['signature'] === '') return 'unsigned';
        if (!function_exists('sodium_crypto_sign_verify_detached')) return 'unavailable';
        $keys = itops_license_pubkeys(); $kid = (string)($raw['key_id'] ?? '');
        if (!$keys) return 'unchecked';   // this build carries no key yet: nothing to check against
        if (!isset($keys[$kid])) return 'unknown_key';
        $sig = base64_decode((string)$raw['signature'], true); $pub = base64_decode($keys[$kid], true);
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || $pub === false || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return 'invalid';
        try { return sodium_crypto_sign_verify_detached($sig, itops_license_canonical($raw), $pub) ? 'valid' : 'invalid'; } catch (Throwable $e) { return 'invalid'; }
    }

    /** The PHP file an installation receives (rename to itops-license.php, upload next to config.php). */
    function itops_license_file(array $payload, string $keyId, string $sigB64): string {
        $r = [];
        foreach (itops_license_fields() as $k) $r[$k] = $payload[$k] ?? ($k === 'modules' ? [] : ($k === 'users' ? 0 : ''));
        $r['key_id'] = $keyId; $r['signature'] = $sigB64;
        // BT127: only safe characters reach the comment line (a file may now be written from what the License Hub sent)
        $safe = fn($v) => preg_replace('/[^A-Za-z0-9 ._:-]/', '', (string)$v);
        return "<?php\n// © " . ITOPS_COPYRIGHT_YEARS . ' ' . ITOPS_OWNER . ". " . ITOPS_PRODUCT . " license record " . $safe($payload['serial'] ?? '') . ", issued " . $safe($payload['issued_at'] ?? '') . ".\n"
             . "// Upload this file, named itops-license.php, to the installation folder (next to config.php).\n"
             . "// It is signed: any change makes it invalid. Ask " . ITOPS_OWNER . " for a new one instead of editing it.\n"
             . 'return ' . var_export($r, true) . ";\n";
    }

    /** The day the trial counts from: the first day this installation ran a build that enforces licenses (kept). */
    function itops_license_trial_from(PDO $db): string {
        try {
            $v = (string)$db->query("SELECT sval FROM itops_settings WHERE skey='license_trial_from'")->fetchColumn();
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
            $v = date('Y-m-d');
            $db->prepare("INSERT IGNORE INTO itops_settings (skey, sval) VALUES ('license_trial_from', ?)")->execute([$v]);
            return (string)$db->query("SELECT sval FROM itops_settings WHERE skey='license_trial_from'")->fetchColumn();
        } catch (Throwable $e) { return date('Y-m-d'); }
    }

    /**
     * What the license allows here: ['mode' => off | licensed | trial | unlicensed, 'why' => …, 'trial_ends' => …,
     * 'grace_ends' => …]. 'licensed' means the record decides; the others are decided here.
     */
    function itops_license_status(PDO $db, ?array $rec): array {
        if (!itops_license_enforced()) return ['mode' => 'off', 'why' => ''];
        $today = date('Y-m-d'); $why = '';
        if ($rec === null) $why = 'none';
        elseif (!empty($rec['error'])) $why = 'unreadable';
        elseif (($rec['signed'] ?? '') !== 'valid') $why = $rec['signed'] ?? 'unsigned';
        elseif (!empty($rec['other_installation'])) $why = 'other_installation';
        elseif ($rec['term_start'] !== '' && $rec['term_start'] > $today) $why = 'not_started';
        elseif (!empty($rec['expired'])) {
            $grace = date('Y-m-d', strtotime($rec['term_end'] . ' +' . ITOPS_LICENSE_GRACE_DAYS . ' days'));
            if ($today <= $grace) return ['mode' => 'licensed', 'why' => 'grace', 'grace_ends' => $grace];
            $why = 'expired';
        }
        if ($why === '') return ['mode' => 'licensed', 'why' => ''];
        $ends = date('Y-m-d', strtotime(itops_license_trial_from($db) . ' +' . ITOPS_LICENSE_TRIAL_DAYS . ' days'));
        return ['mode' => $today < $ends ? 'trial' : 'unlicensed', 'why' => $why, 'trial_ends' => $ends];
    }
    /** One sentence for Super Admins about the state (empty when there is nothing to say). */
    function itops_license_notice(array $st): string {
        $d = fn($v) => $v ? date('m/d/Y', strtotime($v)) : '';
        $why = ['none' => 'There is no license file', 'unreadable' => 'The license file cannot be read', 'unsigned' => 'The license file is not signed',
                'invalid' => 'The license file was changed or damaged (its signature does not match)', 'unknown_key' => 'The license file was not issued by ' . ITOPS_OWNER,
                'unavailable' => 'This server cannot check license signatures (the PHP sodium extension is missing)', 'other_installation' => 'The license file belongs to another installation',
                'not_started' => 'The license term has not started yet', 'expired' => 'The license term has ended'][$st['why'] ?? ''] ?? '';
        if ($st['mode'] === 'licensed' && ($st['why'] ?? '') === 'grace') return 'The license term has ended. Everything keeps working until ' . $d($st['grace_ends']) . '; ask ' . ITOPS_OWNER . ' to renew it.';
        if ($st['mode'] === 'trial') return $why . '. Every module is on as a trial until ' . $d($st['trial_ends']) . '; after that only the core stays on. Ask ' . ITOPS_OWNER . ' for a license file.';
        if ($st['mode'] === 'unlicensed') return $why . ' and the trial ended on ' . $d($st['trial_ends']) . ': only the core is on. Ask ' . ITOPS_OWNER . ' for a license file; everything comes back with it (no data was removed).';
        return '';
    }
}
