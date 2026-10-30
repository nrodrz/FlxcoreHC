<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — the daily check-in with the License Hub (BT127, extended in BT128).
 *
 * Hub (the main installation, License Manager on):
 *   POST /api/hub/checkin  "X-ITOps-Hub-Token: <token>"  {install_id, build, php, db, users, last_update, serial, state, problem}
 *   → {ok, license: {signed fields, key_id, signature} | null, revocations: [signed notices], checked_at}
 *   A token is either made for one installation ID, or (BT128) is a link code's token: the first installation that
 *   checks in with it claims it. Only the SHA-256 of a token is kept.
 *   The hub keeps what each installation last reported: build, PHP, database, active users, the license number in use,
 *   its license state and the last problem (for example a license it refused).
 * Client (every installation):
 *   Settings → Updates → License Hub, or the installer: a link code (the hub's address + the token) or a token alone.
 *   The token is stored wrapped. The hub's address is the one the installation was linked to, else the build's.
 *   A license that arrives is checked against the license key carried by this build and this installation's ID; the
 *   file is WRITTEN HERE from the verified fields. A revocation is used only when it is signed with that same license key
 *   and names this installation and the license in use (BT128): a hub address that is not the real hub cannot revoke.
 * What is sent: installation ID, build, PHP and database versions, active users, the last update's result, the license
 * number in use, the license state and the last problem. Never people, tickets, settings or secrets.
 */
require_once __DIR__ . '/Build.php';
require_once __DIR__ . '/License.php';
require_once __DIR__ . '/Secrets.php';
require_once __DIR__ . '/Updates.php';

if (!function_exists('itops_hub_url')) {

    /** A check-in address is HTTPS; plain HTTP only to this same machine (tests). */
    function itops_hub_url_ok(string $u): bool {
        $p = parse_url($u); if (!is_array($p) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) return false;
        $s = strtolower((string)($p['scheme'] ?? ''));
        if ($s === 'https') return true;
        return $s === 'http' && in_array(strtolower($p['host']), ['127.0.0.1', 'localhost', '[::1]'], true);
    }
    function itops_hub_url_clean(string $u): string { return rtrim(trim($u), '/'); }

    /** The hub this installation checks in with: the one it was linked to, else the build's. */
    function itops_hub_url(?PDO $db = null): string {
        if ($db) { $c = itops_hubc_cfg($db); if ((string)$c['url'] !== '') return (string)$c['url']; }
        $u = defined('ITOPS_HUB_URL') && is_string(ITOPS_HUB_URL) ? trim(ITOPS_HUB_URL) : '';
        return itops_hub_url_clean($u);
    }

    /**
     * BT142: how this installation names itself to the License Hub. Shared hosting filters (ModSecurity, Imunify360,
     * BitNinja, a CDN's browser check) often refuse a request that carries no User-Agent, before the hub ever sees it.
     */
    function itops_hub_ua(): string { return (defined('ITOPS_PRODUCT') ? preg_replace('/[^A-Za-z0-9]/', '', ITOPS_PRODUCT) : 'App') . '-LicenseHub/' . (defined('ITOPS_BUILD') ? ITOPS_BUILD : '0') . ' (+check-in)'; }
    /** BT142: who answered when the request did not reach the hub: its Server header and the start of its text. */
    function itops_hub_blocker(array $hdr, string $body): string {
        $srv = ''; foreach (['server', 'x-powered-by', 'cf-ray', 'x-sucuri-id', 'x-imunify'] as $k) if (!empty($hdr[$k])) { $srv = $k === 'cf-ray' ? 'cloudflare' : ($k === 'x-sucuri-id' ? 'sucuri' : ($k === 'x-imunify' ? 'imunify360' : $hdr[$k])); break; }
        $txt = trim(preg_replace('/\s+/', ' ', strip_tags($body)));
        $txt = mb_substr(preg_replace('/[^\p{L}\p{N} .,:;()\/_#-]/u', '', $txt), 0, 90);
        $srv = mb_substr(preg_replace('/[^A-Za-z0-9 .\/_-]/', '', (string)$srv), 0, 40);
        return trim(($srv !== '' ? 'answered by "' . $srv . '"' : 'no server name') . ($txt !== '' ? ', saying "' . $txt . '"' : ''));
    }

    /** A link code: the hub's address and a token in one string to paste (ohl1.<base64url JSON>). */
    function itops_hub_link_code(string $url, string $tok): string {
        return 'ohl1.' . rtrim(strtr(base64_encode(json_encode(['u' => $url, 't' => $tok], JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }
    /** Reads a link code. Returns ['url', 'token'] or throws InvalidArgumentException. */
    function itops_hub_parse_link(string $code): array {
        $code = trim($code);
        if (!preg_match('/^ohl1\.([A-Za-z0-9_-]{20,800})$/', $code, $m)) throw new InvalidArgumentException('That is not a License Hub link code (it starts with ohl1.).');
        $j = json_decode((string)base64_decode(strtr($m[1], '-_', '+/'), true), true);
        $u = itops_hub_url_clean((string)($j['u'] ?? '')); $t = (string)($j['t'] ?? '');
        if (!is_array($j) || !itops_hub_url_ok($u) || mb_strlen($u) > 200) throw new InvalidArgumentException('The link code does not carry a usable HTTPS address.');
        if (!preg_match('/^ohc_[A-Za-z0-9_-]{40,60}$/', $t)) throw new InvalidArgumentException('The link code does not carry a check-in token.');
        return ['url' => $u, 'token' => $t];
    }

    // ───────────── hub side ─────────────

    function itops_hub_add_col(PDO $db, string $t, string $c, string $def): void {
        $q = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'); $q->execute([$t, $c]);
        if (!(int)$q->fetchColumn()) $db->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
    }
    function itops_hub_table(PDO $db): void {
        static $done = false; if ($done) return;
        $db->exec("CREATE TABLE IF NOT EXISTS itops_lm_installs (install_id VARCHAR(19) NOT NULL PRIMARY KEY, label VARCHAR(200) NOT NULL DEFAULT '',
            token_hash CHAR(64) NOT NULL DEFAULT '', token_set_at DATETIME NULL, build VARCHAR(40) NOT NULL DEFAULT '', php VARCHAR(20) NOT NULL DEFAULT '',
            dbv VARCHAR(60) NOT NULL DEFAULT '', users INT NULL, last_update VARCHAR(40) NOT NULL DEFAULT '', last_seen DATETIME NULL, last_ip VARCHAR(45) NOT NULL DEFAULT '',
            last_serial VARCHAR(40) NOT NULL DEFAULT '', created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // BT128: what the installation reports about its own license
        itops_hub_add_col($db, 'itops_lm_installs', 'in_use_serial', "VARCHAR(40) NOT NULL DEFAULT ''");
        itops_hub_add_col($db, 'itops_lm_installs', 'client_state', "VARCHAR(20) NOT NULL DEFAULT ''");
        itops_hub_add_col($db, 'itops_lm_installs', 'last_problem', "VARCHAR(300) NOT NULL DEFAULT ''");
        // BT128: link codes not claimed yet (the first installation that checks in with one claims it)
        $db->exec("CREATE TABLE IF NOT EXISTS itops_lm_links (token_hash CHAR(64) NOT NULL PRIMARY KEY, label VARCHAR(200) NOT NULL DEFAULT '', created_at DATETIME NOT NULL,
            created_by INT UNSIGNED NULL, claimed_install VARCHAR(19) NOT NULL DEFAULT '', claimed_at DATETIME NULL, stopped TINYINT(1) NOT NULL DEFAULT 0)
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // BT129: release channels. A release is offered to Pilot installations at once and to Stable ones after the
        // waiting period (or when promoted); a paused or withdrawn release is offered to nobody.
        itops_hub_add_col($db, 'itops_lm_installs', 'channel', "VARCHAR(10) NOT NULL DEFAULT 'stable'");
        $t = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_lm_releases'")->fetchColumn();
        if ((int)$t) {
            itops_hub_add_col($db, 'itops_lm_releases', 'status', "VARCHAR(12) NOT NULL DEFAULT 'active'");
            itops_hub_add_col($db, 'itops_lm_releases', 'stable_at', 'DATETIME NULL');
        }
        // BT129: client profiles; a license belongs to a client
        $db->exec("CREATE TABLE IF NOT EXISTS itops_lm_clients (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, contact_name VARCHAR(200) NOT NULL DEFAULT '',
            email VARCHAR(200) NOT NULL DEFAULT '', phone VARCHAR(40) NOT NULL DEFAULT '', address VARCHAR(400) NOT NULL DEFAULT '', notes TEXT NULL,
            created_at DATETIME NOT NULL, updated_at DATETIME NULL, UNIQUE KEY uq_lmc_name (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $t2 = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itops_lm_licenses'")->fetchColumn();
        if ((int)$t2) itops_hub_add_col($db, 'itops_lm_licenses', 'client_id', 'INT UNSIGNED NULL');
        $done = true;
    }

    // ───────────── hub side: client profiles (BT129) ─────────────

    /** Checks a client profile. Returns the clean fields or throws InvalidArgumentException. */
    function itops_lm_client_clean(array $b): array {
        $t = function ($k, $max, $label) use ($b) { $v = $b[$k] ?? ''; if (!is_string($v)) throw new InvalidArgumentException($label . ' is text.');
            $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v)); if (mb_strlen($v) > $max) throw new InvalidArgumentException($label . ' is at most ' . $max . ' characters.'); return $v; };
        $c = ['name' => $t('name', 200, 'The organization'), 'contact_name' => $t('contact_name', 200, 'The contact'), 'email' => $t('email', 200, 'The email'),
              'phone' => $t('phone', 40, 'The phone'), 'address' => $t('address', 400, 'The address'), 'notes' => $t('notes', 2000, 'The notes')];
        if ($c['name'] === '') throw new InvalidArgumentException('The organization is required.');
        if ($c['email'] !== '' && !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('The email does not look right.');
        if ($c['phone'] !== '' && !preg_match('/^[0-9 +().-]{7,40}$/', $c['phone'])) throw new InvalidArgumentException('The phone has digits, spaces and + ( ) - . only.');
        return $c;
    }
    function itops_lm_clients(PDO $db): array {
        itops_hub_table($db);
        $rows = $db->query("SELECT c.*, (SELECT COUNT(*) FROM itops_lm_licenses l WHERE l.client_id=c.id AND l.status='issued') AS licenses_in_use,
            (SELECT COUNT(DISTINCT l.install_id) FROM itops_lm_licenses l WHERE l.client_id=c.id) AS installations FROM itops_lm_clients c ORDER BY c.name")->fetchAll(PDO::FETCH_ASSOC);
        $bal = []; try { require_once __DIR__ . '/Billing.php'; foreach (itops_bill_summary($db)['clients'] as $c) if ($c['client_id'] !== null) $bal[(int)$c['client_id']] = $c; } catch (Throwable $e) {}   // BT130
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['licenses_in_use'] = (int)$r['licenses_in_use']; $r['installations'] = (int)$r['installations']; $r['notes'] = (string)$r['notes'];
            $b = $bal[$r['id']] ?? null; $r['balance'] = $b ? $b['open'] : '0.00'; $r['overdue'] = $b ? $b['overdue'] : '0.00'; $r['days_late'] = $b ? (int)$b['days_late'] : 0; } unset($r);
        return $rows;
    }

    // ───────────── hub side: releases offered at check-in (BT129) ─────────────

    function itops_hub_pilot_days(PDO $db): int { $v = itops_lm_setting($db, 'lm_pilot_days'); return $v === '' ? 7 : max(0, min(60, (int)$v)); }
    /** When a release reaches Stable: promoted, else created + the waiting period. */
    function itops_hub_stable_at(PDO $db, array $r): string {
        if (!empty($r['stable_at'])) return (string)$r['stable_at'];
        return date('Y-m-d H:i:s', strtotime((string)$r['created_at']) + itops_hub_pilot_days($db) * 86400);
    }
    /** pilot | stable | paused | withdrawn */
    function itops_hub_release_state(PDO $db, array $r): string {
        $st = (string)($r['status'] ?? 'active'); if ($st === 'paused' || $st === 'withdrawn') return $st;
        return itops_hub_stable_at($db, $r) <= date('Y-m-d H:i:s') ? 'stable' : 'pilot';
    }
    /** The newest build an installation may have now (null when none is newer than the one it runs). */
    function itops_hub_offer(PDO $db, string $iid, string $build, string $channel): ?array {
        itops_hub_table($db);
        $q = $db->prepare("SELECT * FROM itops_lm_releases WHERE install_id IN ('', ?) AND status='active'"); $q->execute([$iid]);
        $best = null; $cur = itops_build_num($build);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = itops_build_num((string)$r['build']); if ($n <= $cur) continue;
            if ($channel !== 'pilot' && itops_hub_release_state($db, $r) !== 'stable') continue;
            if (!is_file(itops_upd_dir('releases') . '/' . basename((string)$r['file_name']))) continue;
            if (!$best || $n > itops_build_num((string)$best['build']) || ($n === itops_build_num((string)$best['build']) && $r['install_id'] !== '')) $best = $r;
        }
        if (!$best) return null;
        return ['build' => (string)$best['build'], 'notes' => (string)$best['notes'], 'size' => (int)$best['size'], 'created_at' => (string)$best['created_at'],
                'channel' => itops_hub_release_state($db, $best)];
    }
    /** The register of releases for the hub's page: state, when it reaches Stable, how many linked installations run it. */
    function itops_hub_release_rows(PDO $db, array $rels): array {
        itops_hub_table($db);
        $runs = []; foreach ($db->query("SELECT build, COUNT(*) n FROM itops_lm_installs WHERE token_hash<>'' GROUP BY build")->fetchAll(PDO::FETCH_ASSOC) as $x) $runs[$x['build']] = (int)$x['n'];
        $linked = (int)$db->query("SELECT COUNT(*) FROM itops_lm_installs WHERE token_hash<>''")->fetchColumn();
        $st = []; foreach ($db->query('SELECT id, status, stable_at, created_at FROM itops_lm_releases')->fetchAll(PDO::FETCH_ASSOC) as $x) $st[(int)$x['id']] = $x;
        foreach ($rels as &$r) { $x = $st[(int)$r['id']] ?? ['status' => 'active', 'stable_at' => null, 'created_at' => $r['created_at']];
            $r['state'] = itops_hub_release_state($db, $x); $r['stable_at'] = itops_hub_stable_at($db, $x); $r['runs'] = $runs[$r['build']] ?? 0; $r['linked'] = $linked; } unset($r);
        return $rels;
    }
    /** A release bundle an installation may download now (its offer). Returns the file path; throws InvalidArgumentException (token) or RuntimeException (not offered). */
    function itops_hub_release_file(PDO $db, string $token, string $iid, string $build, string $runs): string {
        itops_hub_table($db);
        $q = $db->prepare('SELECT token_hash, channel FROM itops_lm_installs WHERE install_id=?'); $q->execute([$iid]); $i = $q->fetch(PDO::FETCH_ASSOC);
        if (!$i || $i['token_hash'] === '' || !preg_match('/^ohc_[A-Za-z0-9_-]{40,60}$/', $token) || !hash_equals((string)$i['token_hash'], hash('sha256', $token))) throw new InvalidArgumentException('unknown installation or wrong token');
        $o = itops_hub_offer($db, $iid, $runs, (string)$i['channel']);
        if (!$o || $o['build'] !== $build) throw new RuntimeException('build ' . preg_replace('/[^0-9A-Za-z-]/', '', $build) . ' is not offered to this installation');
        $q = $db->prepare("SELECT file_name FROM itops_lm_releases WHERE build=? AND install_id IN ('', ?) AND status='active' ORDER BY install_id DESC, id DESC LIMIT 1"); $q->execute([$build, $iid]);
        $p = itops_upd_dir('releases') . '/' . basename((string)$q->fetchColumn());
        if (!is_file($p)) throw new RuntimeException('the release file is missing on the hub');
        return $p;
    }
    function itops_hub_public_url(PDO $db): string { return itops_hub_url_clean((string)itops_lm_setting($db, 'lm_public_url')); }
    function itops_hub_make_token(): string { return 'ohc_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }

    /** A new token for an installation (the old one stops working). Returns the token: shown once. */
    function itops_hub_new_token(PDO $db, string $iid, string $label): string {
        itops_hub_table($db); $tok = itops_hub_make_token();
        $db->prepare('INSERT INTO itops_lm_installs (install_id, label, token_hash, token_set_at, created_at) VALUES (?,?,?,NOW(),NOW())
                      ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash), token_set_at=NOW(), label=IF(VALUES(label)<>\'\', VALUES(label), label)')
           ->execute([$iid, mb_substr($label, 0, 200), hash('sha256', $tok)]);
        return $tok;
    }
    /** BT128: a link code's token for an installation whose ID is not known yet (for example one being installed). */
    function itops_hub_new_link(PDO $db, string $label, int $by): string {
        itops_hub_table($db); $tok = itops_hub_make_token();
        $db->prepare('INSERT INTO itops_lm_links (token_hash, label, created_at, created_by) VALUES (?,?,NOW(),?)')->execute([hash('sha256', $tok), mb_substr($label, 0, 200), $by ?: null]);
        return $tok;
    }

    /** The text a revocation notice signs. */
    function itops_hub_revocation_text(string $serial, string $iid, string $at): string { return 'itops-revocation|1|' . $serial . '|' . strtoupper($iid) . '|' . $at; }

    /** The license an installation should have now (its newest issued one) and signed notices for its revoked ones. */
    function itops_hub_current_license(PDO $db, string $iid): array {
        $q = $db->prepare("SELECT * FROM itops_lm_licenses WHERE install_id=? ORDER BY id DESC"); $q->execute([$iid]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC); $lic = null; $rev = [];
        foreach ($rows as $r) {
            if ($r['status'] === 'revoked') { $rev[] = $r; continue; }
            if ($r['status'] !== 'issued' || $lic !== null) continue;
            $l = itops_lm_row($db, (int)$r['id']);
            $lic = ['serial' => $l['serial'], 'agreement' => $l['agreement'], 'licensee' => $l['licensee'], 'install_id' => $l['install_id'], 'kind' => $l['kind'], 'plan' => $l['plan'],
                    'modules' => $l['modules'], 'users' => $l['users'], 'term_start' => $l['term_start'], 'term_end' => $l['term_end'], 'notes' => $l['notes'], 'issued_at' => $l['issued_on'],
                    'key_id' => $l['key_id'], 'signature' => $l['signature']];
        }
        $notices = [];
        if ($rev) {
            try { $sk = itops_lm_secret($db); $kid = itops_lm_key_info($db)['key_id'] ?? ''; } catch (Throwable $e) { $sk = ''; $kid = ''; }
            if ($sk !== '') foreach ($rev as $r) {
                $at = (string)($r['revoked_at'] ?? ''); if ($at === '') $at = '0000-00-00 00:00:00';
                $notices[] = ['serial' => (string)$r['serial'], 'install_id' => strtoupper($iid), 'revoked_at' => $at, 'key_id' => $kid,
                              'signature' => base64_encode(sodium_crypto_sign_detached(itops_hub_revocation_text((string)$r['serial'], $iid, $at), $sk))];
            }
        }
        return ['license' => $lic, 'revocations' => $notices];
    }

    /** One check-in. Throws InvalidArgumentException (refused) with a short reason. */
    function itops_hub_checkin(PDO $db, string $token, array $b, string $ip): array {
        itops_hub_table($db);
        $iid = strtoupper(trim((string)($b['install_id'] ?? '')));
        if (!preg_match('/^[A-F0-9]{4}(-[A-F0-9]{4}){3}$/', $iid)) throw new InvalidArgumentException('installation ID missing or malformed');
        if ($token === '' || !preg_match('/^ohc_[A-Za-z0-9_-]{40,60}$/', $token)) throw new InvalidArgumentException('unknown installation or wrong token');
        $th = hash('sha256', $token);
        $q = $db->prepare('SELECT token_hash FROM itops_lm_installs WHERE install_id=?'); $q->execute([$iid]); $h = (string)$q->fetchColumn();
        if ($h === '' || !hash_equals($h, $th)) {
            // BT128: a link code's token is claimed by the first installation that uses it (within 30 days of being made)
            $l = $db->prepare('SELECT * FROM itops_lm_links WHERE token_hash=?'); $l->execute([$th]); $l = $l->fetch(PDO::FETCH_ASSOC);
            if (!$l || $l['stopped'] || $l['claimed_install'] !== '' || strtotime((string)$l['created_at']) < time() - 30 * 86400) throw new InvalidArgumentException('unknown installation or wrong token');
            $c = $db->prepare("UPDATE itops_lm_links SET claimed_install=?, claimed_at=NOW() WHERE token_hash=? AND claimed_install=''"); $c->execute([$iid, $th]);
            if (!$c->rowCount()) throw new InvalidArgumentException('unknown installation or wrong token');
            $db->prepare('INSERT INTO itops_lm_installs (install_id, label, token_hash, token_set_at, created_at) VALUES (?,?,?,NOW(),NOW())
                          ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash), token_set_at=NOW(), label=IF(VALUES(label)<>\'\', VALUES(label), label)')
               ->execute([$iid, (string)$l['label'], $th]);
            if (function_exists('audit_log')) audit_log('hub_link_claimed', 'license', null, $iid . ($l['label'] !== '' ? ' · ' . $l['label'] : ''), $h !== '' ? ['Token' => 'replaced'] : null, ['Linked' => 'by its link code', 'Address' => $ip]);
        }
        $t = fn($k, $n) => mb_substr(preg_replace('/[^A-Za-z0-9 ._+:-]/', '', (string)($b[$k] ?? '')), 0, $n);
        $cur = itops_hub_current_license($db, $iid);
        $db->prepare('UPDATE itops_lm_installs SET build=?, php=?, dbv=?, users=?, last_update=?, last_seen=NOW(), last_ip=?, last_serial=?, in_use_serial=?, client_state=?, last_problem=? WHERE install_id=?')
           ->execute([$t('build', 40), $t('php', 20), $t('db', 60), is_numeric($b['users'] ?? null) ? max(0, min(1000000, (int)$b['users'])) : null, $t('last_update', 40),
                      mb_substr($ip, 0, 45), $cur['license'] ? (string)$cur['license']['serial'] : '', $t('serial', 40), $t('state', 20),
                      mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)($b['problem'] ?? '')), 0, 300), $iid]);
        $ch = $db->prepare('SELECT channel FROM itops_lm_installs WHERE install_id=?'); $ch->execute([$iid]);
        return ['ok' => true, 'license' => $cur['license'], 'revocations' => $cur['revocations'], 'checked_at' => date('Y-m-d H:i:s'),
                'update' => (function () use ($db, $iid, $t, $ch) { try { return itops_hub_offer($db, $iid, $t('build', 40), (string)$ch->fetchColumn() ?: 'stable'); } catch (Throwable $e) { return null; } })()];
    }

    /**
     * BT128: one row per installation the hub knows (from the register and from check-ins), with what needs attention.
     * Warnings: ends / ended, over the user limit, silent 7+ days, an older license in use than the one issued, a revoked
     * license still in use, a problem reported (for example a license it refused), not linked.
     */
    function itops_hub_overview(PDO $db): array {
        itops_hub_table($db);
        $ins = []; foreach ($db->query('SELECT * FROM itops_lm_installs')->fetchAll(PDO::FETCH_ASSOC) as $r) $ins[$r['install_id']] = $r;
        $lic = []; foreach ($db->query('SELECT l.id, l.serial, l.install_id, l.licensee, l.plan, l.modules, l.users, l.term_end, l.status, l.client_id, c.name AS client_name FROM itops_lm_licenses l LEFT JOIN itops_lm_clients c ON c.id=l.client_id ORDER BY l.id DESC')->fetchAll(PDO::FETCH_ASSOC) as $r) $lic[$r['install_id']][] = $r;
        $self = function_exists('itops_install_id') ? itops_install_id($db) : '';
        $od = []; try { require_once __DIR__ . '/Billing.php'; $od = itops_bill_overdue_clients($db); } catch (Throwable $e) {}   // BT130: payment overdue (a warning only)
        $ids = array_unique(array_merge(array_keys($ins), array_keys($lic), $self !== '' ? [$self] : [])); $today = date('Y-m-d'); $soon = date('Y-m-d', strtotime('+30 days'));
        $out = [];
        foreach ($ids as $iid) {
            $i = $ins[$iid] ?? null; $ls = $lic[$iid] ?? [];
            if ($iid === $self) {   // the hub itself never checks in: what it reports is read here
                $i = ['label' => (string)($i['label'] ?? ''), 'token_hash' => 'self', 'token_set_at' => null, 'build' => ITOPS_BUILD, 'php' => PHP_VERSION, 'users' => function_exists('itops_users_active') ? itops_users_active($db) : null,
                      'last_update' => itops_hubc_last_update($db), 'last_seen' => date('Y-m-d H:i:s'), 'in_use_serial' => itops_hubc_current_serial(), 'client_state' => '', 'last_problem' => ''];
            } $issued = null; foreach ($ls as $l) if ($l['status'] === 'issued') { $issued = $l; break; }
            $newest = $ls[0] ?? null; $w = [];
            $seen = $i && $i['last_seen'] ? (int)floor((time() - strtotime($i['last_seen'])) / 86400) : null;
            $linked = $i && $i['token_hash'] !== '';
            $inUse = $i ? (string)$i['in_use_serial'] : '';
            if ($issued && $issued['term_end']) { if ($issued['term_end'] < $today) $w[] = ['ended', 'License term ended ' . $issued['term_end']]; elseif ($issued['term_end'] <= $soon) $w[] = ['ends', 'License ends ' . $issued['term_end']]; }
            if (!$issued && $newest && $newest['status'] === 'revoked') $w[] = ['revoked', 'Its license is revoked'];
            if ($i && $i['users'] !== null && $issued && (int)$issued['users'] > 0 && (int)$i['users'] > (int)$issued['users']) $w[] = ['users', (int)$i['users'] . ' active users; the license allows ' . (int)$issued['users']];
            if ($linked && $seen !== null && $seen >= 7) $w[] = ['silent', 'Silent for ' . $seen . ' days'];
            if ($linked && $i['last_seen'] === null) $w[] = ['never', 'Linked but never checked in'];
            if ($i && $i['last_seen'] !== null && $issued && $inUse !== $issued['serial']) {
                $wasRevoked = false; foreach ($ls as $l) if ($l['serial'] === $inUse && $l['status'] === 'revoked') $wasRevoked = true;
                $w[] = $wasRevoked ? ['revoked_in_use', 'Still uses revoked license ' . $inUse] : ['old_license', $inUse === '' ? 'Has no license file in use (issued: ' . $issued['serial'] . ')' : 'Uses ' . $inUse . ', not the issued ' . $issued['serial']];
            }
            if ($i && $i['last_problem'] !== '') $w[] = ['problem', $i['last_problem']];
            if ($newest && $newest['client_id'] !== null && isset($od[(int)$newest['client_id']])) $w[] = ['overdue', 'Payment overdue: ' . $od[(int)$newest['client_id']]['overdue'] . ' USD, oldest ' . $od[(int)$newest['client_id']]['days_late'] . ' days late'];
            if (!$linked) $w[] = ['unlinked', $i && $i['last_seen'] ? 'Token stopped: it no longer checks in' : 'Not linked: its license is known only from the files sent'];
            $out[] = ['install_id' => $iid, 'label' => $i ? (string)$i['label'] : '', 'licensee' => $newest ? (string)$newest['licensee'] : '',
                      'client_id' => $newest && $newest['client_id'] !== null ? (int)$newest['client_id'] : null, 'client_name' => $newest ? (string)($newest['client_name'] ?? '') : '',
                      'issued_serial' => $issued ? $issued['serial'] : '', 'plan' => $issued ? (string)$issued['plan'] : '', 'modules' => $issued ? (json_decode((string)$issued['modules'], true) ?: []) : [],
                      'users_allowed' => $issued ? (int)$issued['users'] : null, 'term_end' => $issued ? (string)($issued['term_end'] ?? '') : '',
                      'license_status' => $newest ? (string)$newest['status'] : '', 'in_use_serial' => $inUse, 'client_state' => $i ? (string)$i['client_state'] : '',
                      'linked' => $linked && $iid !== $self, 'is_hub' => $iid === $self, 'channel' => (string)($i['channel'] ?? 'stable') ?: 'stable', 'token_set_at' => $i ? $i['token_set_at'] : null, 'build' => $i ? (string)$i['build'] : '', 'php' => $i ? (string)$i['php'] : '',
                      'users' => $i && $i['users'] !== null ? (int)$i['users'] : null, 'last_update' => $i ? (string)$i['last_update'] : '', 'last_seen' => $i ? $i['last_seen'] : null,
                      'silent_days' => $seen, 'warnings' => array_map(fn($x) => ['kind' => $x[0], 'text' => $x[1]], $w)];
        }
        usort($out, function ($a, $b) { $ka = count(array_filter($a['warnings'], fn($w) => $w['kind'] !== 'unlinked')); $kb = count(array_filter($b['warnings'], fn($w) => $w['kind'] !== 'unlinked'));
            return [$kb, (string)$b['last_seen']] <=> [$ka, (string)$a['last_seen']]; });
        $links = $db->query("SELECT token_hash, label, created_at, claimed_install, claimed_at, stopped FROM itops_lm_links ORDER BY created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        $pend = [];
        foreach ($links as $l) if ($l['claimed_install'] === '' && !$l['stopped']) $pend[] = ['ref' => substr($l['token_hash'], 0, 12), 'label' => $l['label'], 'created_at' => $l['created_at'],
                                                                                            'expired' => strtotime($l['created_at']) < time() - 30 * 86400];
        return ['installs' => $out, 'pending_links' => $pend];
    }

    // ───────────── client side ─────────────

    function itops_hubc_cfg(PDO $db): array {
        $v = $db->query("SELECT sval FROM itops_settings WHERE skey='hub_client'")->fetchColumn();
        $j = json_decode((string)$v, true); return (is_array($j) ? $j : []) + ['token_enc' => '', 'url' => '', 'off' => false, 'last' => null, 'problem' => '', 'offer' => null];
    }
    function itops_hubc_save(PDO $db, array $c): void { $db->prepare("INSERT INTO itops_settings (skey,sval) VALUES ('hub_client',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([json_encode($c)]); }
    function itops_hubc_public(PDO $db): array {
        $c = itops_hubc_cfg($db);
        return ['url' => itops_hub_url($db), 'url_from' => (string)$c['url'] !== '' ? 'link' : (itops_hub_url() !== '' ? 'build' : ''), 'token_saved' => $c['token_enc'] !== '', 'off' => (bool)$c['off'],
                'last' => $c['last'], 'problem' => (string)$c['problem'],
                'offer' => is_array($c['offer']) && itops_build_num((string)($c['offer']['build'] ?? '')) > itops_build_num(ITOPS_BUILD) ? $c['offer'] : null, 'license_key_in_build' => count(itops_license_pubkeys()) > 0,
                'sends' => 'This installation\'s ID, build, PHP and database versions, the number of active users, the last update\'s result and its license number and state. Nothing about people, tickets or settings.'];
    }
    /**
     * Saves a link code (address + token) or a token alone. Returns what changed (for the Audit Log); throws
     * InvalidArgumentException when it is not usable, RuntimeException when the token cannot be stored safely.
     */
    function itops_hubc_link(PDO $db, string $code): array {
        $code = trim($code); $c = itops_hubc_cfg($db); $chg = [];
        if (strncmp($code, 'ohl1.', 5) === 0) { $l = itops_hub_parse_link($code); $url = $l['url']; $tok = $l['token']; }
        else { if (!preg_match('/^ohc_[A-Za-z0-9_-]{40,60}$/', $code)) throw new InvalidArgumentException('That is not a License Hub link code (ohl1.…) or token (ohc_…).'); $url = (string)$c['url']; $tok = $code; }
        $w = itops_secret_wrap_checked($tok); if ($w === null) throw new RuntimeException('The token cannot be stored safely: the vault key is missing or wrong (see Settings → Security).');
        if ($url !== (string)$c['url']) $chg['License Hub address'] = $url;
        $c['url'] = $url; $c['token_enc'] = $w; $c['problem'] = ''; $chg['Token'] = 'saved (wrapped)';
        itops_hubc_save($db, $c);
        return $chg;
    }

    /** The current license file's serial ('' when there is none or it cannot be read). */
    function itops_hubc_current_serial(): string {
        $f = dirname(__DIR__, 2) . '/itops-license.php'; if (!is_file($f)) return '';
        try { $r = (static function (string $f) { return include $f; })($f); } catch (Throwable $e) { return ''; }
        return is_array($r) ? (string)($r['serial'] ?? '') : '';
    }

    /**
     * Writes a license that came from the hub, after checking it. Returns a sentence; throws RuntimeException when it is
     * refused (nothing is changed then).
     */
    function itops_hubc_install_license(PDO $db, array $lic): string {
        $raw = [];
        foreach (itops_license_fields() as $k) $raw[$k] = $lic[$k] ?? ($k === 'modules' ? [] : ($k === 'users' ? 0 : ''));
        $raw['key_id'] = (string)($lic['key_id'] ?? ''); $raw['signature'] = (string)($lic['signature'] ?? '');
        if (!preg_match('/^[A-Za-z0-9-]{1,40}$/', (string)$raw['serial']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$raw['issued_at'])) throw new RuntimeException('the license has an unexpected number or date');
        if (!itops_license_pubkeys()) throw new RuntimeException('this build carries no license key, so a license from the hub cannot be checked');
        $chk = itops_license_check($raw);
        if ($chk !== 'valid') throw new RuntimeException('the license from the hub did not pass the signature check (' . $chk . ')');
        if (function_exists('itops_install_id') && strtoupper((string)$raw['install_id']) !== strtoupper(itops_install_id($db))) throw new RuntimeException('the license from the hub is for another installation');
        $root = dirname(__DIR__, 2); $f = $root . '/itops-license.php';
        $body = itops_license_file($raw, $raw['key_id'], $raw['signature']);
        try { token_get_all($body, TOKEN_PARSE); } catch (ParseError $e) { throw new RuntimeException('the license file could not be made'); }
        if (is_file($f)) { $bk = $root . '/data/updates/licenses'; if (!is_dir($bk)) @mkdir($bk, 0700, true); @copy($f, $bk . '/itops-license-' . date('Ymd-His') . '.php'); }
        $tmp = $f . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $body) !== strlen($body) || !@rename($tmp, $f)) { @unlink($tmp); throw new RuntimeException('the license file could not be written (can the web server write the installation folder?)'); }
        if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true);
        return 'License ' . $raw['serial'] . ' received from the hub and installed.';
    }

    /**
     * BT128: a revocation is used only when it is signed with the license key in this build, names this installation and
     * names the license in use. Returns a sentence when the file was moved aside, '' when nothing applied; throws
     * RuntimeException when a notice for the license in use could not be verified.
     */
    function itops_hubc_apply_revocations(PDO $db, array $notices): string {
        $cur = itops_hubc_current_serial(); if ($cur === '') return '';
        $iid = strtoupper(itops_install_id($db)); $keys = itops_license_pubkeys(); $bad = '';
        foreach ($notices as $n) {
            if (!is_array($n) || (string)($n['serial'] ?? '') !== $cur) continue;
            $at = (string)($n['revoked_at'] ?? ''); $kid = (string)($n['key_id'] ?? '');
            $sig = base64_decode((string)($n['signature'] ?? ''), true); $pub = isset($keys[$kid]) ? base64_decode($keys[$kid], true) : false;
            $ok = strtoupper((string)($n['install_id'] ?? '')) === $iid && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $at)
                  && $sig !== false && strlen($sig) === SODIUM_CRYPTO_SIGN_BYTES && $pub !== false && strlen($pub) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                  && sodium_crypto_sign_verify_detached($sig, itops_hub_revocation_text($cur, $iid, $at), $pub);
            if (!$ok) { $bad = !$keys ? 'this build carries no license key, so a revocation cannot be checked' : 'a revocation of the license in use did not pass the signature check'; continue; }
            $root = dirname(__DIR__, 2); $bk = $root . '/data/updates/licenses'; if (!is_dir($bk)) @mkdir($bk, 0700, true);
            if (!@rename($root . '/itops-license.php', $bk . '/itops-license-revoked-' . date('Ymd-His') . '.php')) throw new RuntimeException('the revoked license file could not be moved aside');
            return 'License ' . $cur . ' was revoked by the owner and was taken out of use.';
        }
        if ($bad !== '') throw new RuntimeException($bad);
        return '';
    }

    function itops_hubc_last_update(PDO $db): string {
        try { $v = itops_upd_last($db); return $v ? trim((string)($v['status'] ?? '') . ' ' . (string)($v['to'] ?? '')) : ''; } catch (Throwable $e) { return ''; }
    }

    /** The check-in. Never throws; returns ['ok', 'note', 'changed'] and keeps it as the last result. */
    function itops_hubc_checkin(PDO $db, string $by = 'scheduler'): array {
        $c = itops_hubc_cfg($db); $url = itops_hub_url($db);
        $done = function (bool $ok, string $note, bool $changed = false, ?string $problem = null) use ($db, &$c, $by) {
            $c['last'] = ['at' => date('Y-m-d H:i:s'), 'ok' => $ok, 'note' => $note, 'by' => $by, 'serial' => itops_hubc_current_serial()];
            if ($problem !== null) $c['problem'] = $problem;
            itops_hubc_save($db, $c);
            return ['ok' => $ok, 'note' => $note, 'changed' => $changed];
        };
        if ($c['off']) return ['ok' => false, 'note' => 'Check-in is switched off on this installation.', 'changed' => false];
        if ($url === '') return ['ok' => false, 'note' => 'This installation is not linked to a License Hub yet.', 'changed' => false];
        if ($c['token_enc'] === '') return ['ok' => false, 'note' => 'No check-in token is saved yet.', 'changed' => false];
        try { $tok = itops_secret_unwrap($c['token_enc']); } catch (Throwable $e) { $tok = ''; }
        if ($tok === '' || !itops_secret_is_wrapped($c['token_enc'])) return $done(false, 'The saved token cannot be read (was the vault key changed?). Enter it again.');
        if (!itops_hub_url_ok($url)) return $done(false, 'The License Hub address is not HTTPS; nothing was sent.');
        $st = ['mode' => ''];
        try { require_once __DIR__ . '/Brand.php'; $st = itops_license_status($db, function_exists('itops_license_record') ? itops_license_record($db) : null); } catch (Throwable $e) {}
        $payload = ['install_id' => itops_install_id($db), 'build' => ITOPS_BUILD, 'php' => PHP_VERSION, 'db' => (string)$db->query('SELECT VERSION()')->fetchColumn(),
                    'users' => function_exists('itops_users_active') ? itops_users_active($db) : null, 'last_update' => itops_hubc_last_update($db),
                    'serial' => itops_hubc_current_serial(), 'state' => (string)($st['mode'] ?? '') . (($st['why'] ?? '') !== '' ? ':' . $st['why'] : ''), 'problem' => (string)$c['problem']];
        $ch = curl_init($url . '/api/hub/checkin'); $rh = [];
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => itops_hub_ua(), CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$rh) { $p = strpos($line, ':'); if ($p !== false) $rh[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1)); return strlen($line); },
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['X-ITOps-Hub-Token: ' . $tok, 'Authorization: Bearer ' . $tok, 'Content-Type: application/json', 'Accept: application/json']]);
        $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch);
        if ($raw === false) return $done(false, 'The License Hub did not answer (' . $err . '). Nothing changed.');
        $j = json_decode((string)$raw, true);
        // BT141: say who refused and why. The License Hub answers JSON with its reason; anything else (an HTML page, an
        // empty answer) came from something in front of it (the server's firewall or security filter).
        $why = is_array($j) ? trim(preg_replace('/^Check-in refused:\s*/', '', (string)($j['error']['message'] ?? ''))) : '';
        $why = mb_substr(rtrim(preg_replace('/[\x00-\x1F\x7F<>]+/u', ' ', $why), '.'), 0, 160);
        if (($code === 401 || $code === 403) && $why !== '') return $done(false, 'The License Hub refused this installation\'s token (' . $why . '). Ask the owner for a new link code. Nothing changed.');
        if ($code === 401 || $code === 403) return $done(false, 'The check-in was blocked before it reached the License Hub: HTTP ' . $code . ', ' . itops_hub_blocker($rh, (string)$raw) . '. That is the License Hub\'s hosting (its firewall or security filter), not the License Hub itself. Ask the owner to have this server\'s public IP address allowed for /api/hub/ there. Nothing changed.');
        if ($code === 429) return $done(false, 'The License Hub is refusing check-ins from this address for a few minutes (too many refused tries). Try again later. Nothing changed.');
        if ($code >= 400 && !is_array($j)) return $done(false, 'The License Hub address answered HTTP ' . $code . ', ' . itops_hub_blocker($rh, (string)$raw) . ' (not the License Hub itself). Nothing changed.');
        if ($code >= 400 || !is_array($j) || empty($j['ok'])) return $done(false, 'The License Hub answered HTTP ' . $code . '. Nothing changed.');
        $notes = []; $changed = false; $problem = '';
        if (is_array($j['license'] ?? null) && (string)($j['license']['serial'] ?? '') !== itops_hubc_current_serial()) {
            try { $was = itops_hubc_current_serial(); $notes[] = itops_hubc_install_license($db, $j['license']); $changed = true;
                  if (function_exists('audit_log')) audit_log('license_from_hub', 'license', null, (string)$j['license']['serial'], ['Serial' => $was ?: 'none'], ['Serial' => (string)$j['license']['serial'], 'By' => $by]); }
            catch (RuntimeException $e) { $notes[] = 'A new license was offered but not installed: ' . $e->getMessage() . '.'; $problem = 'Refused license ' . preg_replace('/[^A-Za-z0-9-]/', '', (string)($j['license']['serial'] ?? '?')) . ': ' . $e->getMessage();
                  if (function_exists('audit_log')) audit_log('license_refused', 'license', null, (string)($j['license']['serial'] ?? '?'), null, ['Why' => $e->getMessage()]); }
        }
        if (!$changed && is_array($j['revocations'] ?? null) && $j['revocations']) {
            try { $r = itops_hubc_apply_revocations($db, $j['revocations']);
                  if ($r !== '') { $notes[] = $r; $changed = true; if (function_exists('audit_log')) audit_log('license_revoked_by_hub', 'license', null, 'License', null, ['Result' => $r]); } }
            catch (RuntimeException $e) { $notes[] = 'A revocation was not applied: ' . $e->getMessage() . '.'; $problem = 'Refused revocation: ' . $e->getMessage();
                  if (function_exists('audit_log')) audit_log('license_refused', 'license', null, 'Revocation', null, ['Why' => $e->getMessage()]); }
        }
        // BT129: the newest build the hub offers this installation (kept for Settings → Updates and the maintenance window)
        $u = $j['update'] ?? null; $c['offer'] = null;
        if (is_array($u) && preg_match('/^[0-9A-Za-z._-]{3,40}$/', (string)($u['build'] ?? '')) && itops_build_num((string)$u['build']) > itops_build_num(ITOPS_BUILD))
            $c['offer'] = ['build' => (string)$u['build'], 'notes' => mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)($u['notes'] ?? '')), 0, 4000),
                           'size' => max(0, (int)($u['size'] ?? 0)), 'created_at' => preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)($u['created_at'] ?? '')) ? (string)$u['created_at'] : '',
                           'channel' => in_array($u['channel'] ?? '', ['pilot', 'stable'], true) ? $u['channel'] : '', 'seen_at' => date('Y-m-d H:i:s')];
        if ($c['offer']) $notes[] = 'Build ' . $c['offer']['build'] . ' is available (Settings → Updates).';
        // BT143: say what "current" is: the license number in use, or that the hub has none for this installation yet
        if (!$notes) {
            if (is_array($j['license'] ?? null)) $notes[] = 'Checked in. License ' . preg_replace('/[^A-Za-z0-9-]/', '', (string)$j['license']['serial']) . ' is current.';
            else {
                $tr = ''; try { $stx = itops_license_status($db, function_exists('itops_license_record') ? itops_license_record($db) : null); if (($stx['mode'] ?? '') === 'trial') $tr = ' It runs on the trial until ' . date('m/d/Y', strtotime((string)$stx['trial_ends'])) . '.'; } catch (Throwable $e) {}
                $notes[] = 'Checked in. No license has been issued for this installation on the License Hub yet.' . $tr . ' Ask the owner to issue one for installation ' . itops_install_id($db) . '.';
            }
        }
        return $done(true, implode(' ', $notes), $changed, $problem);
    }

    /**
     * BT129: downloads the build the hub offers into data/updates/staged and checks it like an upload (signature,
     * package, build, space, write access). Returns the staged record. Throws RuntimeException with a sentence.
     */
    function itops_hubc_fetch(PDO $db, int $by, string $byName): array {
        require_once __DIR__ . '/UpdateFlow.php';
        $c = itops_hubc_cfg($db); $url = itops_hub_url($db); $o = $c['offer'];
        if (!is_array($o) || itops_build_num((string)($o['build'] ?? '')) <= itops_build_num(ITOPS_BUILD)) throw new RuntimeException('The License Hub offers no newer build (check in first).');
        if ($c['off'] || $url === '' || $c['token_enc'] === '' || !itops_hub_url_ok($url)) throw new RuntimeException('This installation is not linked to a License Hub (or check-in is off).');
        try { $tok = itops_secret_unwrap($c['token_enc']); } catch (Throwable $e) { $tok = ''; }
        if ($tok === '') throw new RuntimeException('The saved token cannot be read (was the vault key changed?). Link again.');
        if (itops_upd_maintenance()) throw new RuntimeException('An update is under way.');
        $dest = itops_upd_dir('staged') . '/bundle-' . bin2hex(random_bytes(6)) . '.itops-update';
        $fh = @fopen($dest, 'wb'); if (!$fh) throw new RuntimeException('The bundle could not be saved in data/updates (can the web server write the installation folder?).');
        $got = 0;
        $ch = curl_init($url . '/api/hub/release');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['install_id' => itops_install_id($db), 'build' => $o['build'], 'runs' => ITOPS_BUILD]),
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 600, CURLOPT_USERAGENT => itops_hub_ua(),
            CURLOPT_HTTPHEADER => ['X-ITOps-Hub-Token: ' . $tok, 'Authorization: Bearer ' . $tok, 'Content-Type: application/json'],
            CURLOPT_WRITEFUNCTION => function ($ch, $d) use ($fh, &$got) { $got += strlen($d); if ($got > ITOPS_UPD_MAX) return 0; return fwrite($fh, $d); }]);
        $ok = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch); fclose($fh); @chmod($dest, 0600);
        if ($ok === false || $code !== 200) {
            $msg = $code >= 400 ? (json_decode((string)@file_get_contents($dest, false, null, 0, 2000), true)['error']['message'] ?? ('HTTP ' . $code)) : ($got > ITOPS_UPD_MAX ? 'the file is too large' : $err);
            @unlink($dest); throw new RuntimeException('The License Hub did not send build ' . $o['build'] . ' (' . $msg . ').');
        }
        try { $s = itops_upd_stage_bundle($db, $dest, $by, $byName, 'hub'); }
        catch (InvalidArgumentException $e) { throw new RuntimeException($e->getMessage()); }
        audit_log('update_downloaded', 'update', null, 'Build ' . $o['build'], null, ['From' => 'the License Hub', 'Size' => $got, 'By' => $byName]);
        return $s;
    }
}
