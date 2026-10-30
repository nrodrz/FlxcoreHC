<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * BT27 — Device inventory.
 *
 * One table (itops_device_inventory) with every device that signs in with a work account, built from what
 * Microsoft already records: the Entra device list and the Entra sign-in log. Nothing is installed on any PC
 * and nothing here changes a device: it is read-only.
 *
 * Each row carries a `source` so later sources (Teams tab, desktop Companion) can feed the same table.
 *   entra:<deviceId>            a device object that exists in Entra (joined, hybrid or registered)
 *   seen:<sha1(user|os family)> sign-ins from a device that is NOT registered; one row per person and OS family
 *                               (source 'signin' when first found in the sign-in log, 'app' when first reported by the app itself — BT29)
 *
 * The v1.0 sign-in log only lists INTERACTIVE sign-ins (password / MFA prompts). An app that stays signed in keeps
 * working on refresh tokens without adding entries, so for unregistered rows `last_seen` can be months old while the
 * computer is used every day. `owner_last_active` (the person's newest interactive OR background sign-in, from
 * users.signInActivity) is what keeps those rows from being flagged "not seen" or dropped while the person is active.
 */

const ITOPS_DINV_STALE_DAYS   = 90;      // "not seen for 90 days"
const ITOPS_DINV_KEEP_DAYS    = 180;     // unregistered / removed rows older than this are dropped
const ITOPS_DINV_LOOKBACK     = 30;      // days of sign-in log read on the first sync (Microsoft keeps 30)
const ITOPS_DINV_GRACE_DAYS   = 30;      // bulk clean-up: a device disabled from here is proposed for removal after this many days
const ITOPS_DINV_BULK_MAX     = 10;      // bulk clean-up: devices handled per request (each one is read live before it is changed)
const ITOPS_DINV_WIN_MIN      = 22000;   // Windows build below this = Windows 10 or older (out of support)
const ITOPS_DINV_MAC_MIN      = 14;      // macOS major version below this = out of support

/** Classify an operating system string. $version is only trusted when it comes from the Entra device object. */
function itops_dinv_os(string $os, string $version = ''): array
{
    $k = strtolower(str_replace([' ', '_', '-'], '', $os));
    $family = 'other'; $kind = 'other'; $label = trim($os); $eol = null;
    if ($k === '') return ['family' => 'other', 'kind' => 'other', 'label' => '', 'eol' => null];
    if (strpos($k, 'windowsphone') === 0) { $family = 'other'; $kind = 'phone'; $label = 'Windows Phone'; $eol = true; }
    elseif (strpos($k, 'windows') === 0) {
        $family = 'windows'; $kind = 'computer'; $label = 'Windows';
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $m)) {
            $major = (int)$m[1]; $build = (int)$m[3];
            if ($major >= 10) { $label = $build >= ITOPS_DINV_WIN_MIN ? 'Windows 11' : 'Windows 10'; $eol = $build < ITOPS_DINV_WIN_MIN; }
            else { $label = 'Windows (older than 10)'; $eol = true; }
        }
    }
    elseif (strpos($k, 'mac') === 0 || strpos($k, 'osx') === 0) {
        $family = 'macos'; $kind = 'computer'; $label = 'macOS';
        if (preg_match('/^(\d+)(?:\.(\d+))?/', $version, $m)) { $major = (int)$m[1]; $label = 'macOS ' . $major; $eol = $major < ITOPS_DINV_MAC_MIN; }
    }
    elseif (preg_match('/^(ios|iphone|ipad)/', $k)) {
        $family = 'ios'; $kind = 'phone'; $label = strpos($k, 'ipad') === 0 ? 'iPadOS' : 'iOS';
        if (preg_match('/^(\d+)/', $version, $m)) $label .= ' ' . $m[1];
    }
    elseif (strpos($k, 'android') === 0) {
        $family = 'android'; $kind = 'phone'; $label = 'Android';
        if (preg_match('/^(\d+)/', $version, $m)) $label .= ' ' . $m[1];
    }
    elseif (preg_match('/linux|ubuntu|mint|debian|fedora|rhel|centos|suse/', $k)) {
        $family = 'linux'; $kind = 'computer'; $label = 'Linux';
        if ($version !== '' && strlen($version) <= 20) $label .= ' ' . $version;
    }
    elseif (strpos($k, 'chrome') === 0) { $family = 'chromeos'; $kind = 'computer'; $label = 'ChromeOS'; }
    return ['family' => $family, 'kind' => $kind, 'label' => $label, 'eol' => $eol];
}

/** Entra join type → AzureAd (joined) | ServerAd (hybrid) | Workplace (registered) | ''. Accepts the sign-in log spellings too. */
function itops_dinv_trust(string $t): string
{
    $k = strtolower(str_replace(' ', '', $t));
    if ($k === '') return '';
    if (strpos($k, 'hybrid') !== false || strpos($k, 'serverad') !== false || strpos($k, 'domain') !== false) return 'ServerAd';
    if (strpos($k, 'registered') !== false || strpos($k, 'workplace') !== false) return 'Workplace';
    if (strpos($k, 'joined') !== false || strpos($k, 'azuread') !== false || strpos($k, 'entra') !== false) return 'AzureAd';
    return '';
}

/** company | personal | unknown, from the join type and (when Intune set it) the ownership flag. */
function itops_dinv_ownership(string $trust, string $ownership): string
{
    $o = strtolower($ownership);
    if ($o === 'company') return 'company';
    if ($trust === 'AzureAd' || $trust === 'ServerAd') return 'company';
    if ($o === 'personal' || $trust === 'Workplace') return 'personal';
    return 'unknown';
}

function itops_dinv_dt($iso): ?string
{
    if ($iso === null || $iso === '') return null;
    $t = strtotime((string)$iso);
    return ($t === false || $t <= 0) ? null : gmdate('Y-m-d H:i:s', $t);
}
function itops_dinv_iso(?string $dt): ?string { return $dt ? str_replace(' ', 'T', $dt) . 'Z' : null; }
function itops_dinv_cut(string $s, int $n): string { return mb_substr($s, 0, $n); }

/**
 * BT29.1 — reading Microsoft can take a minute, and the database connection is idle all that time. Shared hosts close
 * idle connections after as little as 30 seconds ("MySQL server has gone away"). Two defences: ask for a long idle
 * allowance on this connection, and touch the connection between Microsoft pages.
 */
function itops_dinv_hold(PDO $db): void { try { $db->exec('SET SESSION wait_timeout=1800'); } catch (Throwable $e) {} }
function itops_dinv_ping(PDO $db): void { try { $db->query('SELECT 1')->fetchColumn(); } catch (Throwable $e) {} }

function itops_dinv_ready(PDO $db): bool
{
    static $ok = null;
    if ($ok === null) { try { $db->query('SELECT 1 FROM itops_device_inventory LIMIT 1'); $ok = true; } catch (Throwable $e) { $ok = false; } }
    return $ok;
}

function itops_dinv_state(PDO $db): array
{
    try { $s = $db->prepare("SELECT sval FROM itops_settings WHERE skey='device_inventory'"); $s->execute(); $j = json_decode((string)$s->fetchColumn(), true); }
    catch (Throwable $e) { $j = null; }
    return is_array($j) ? $j : [];
}
function itops_dinv_state_save(PDO $db, array $st): void
{
    $db->prepare("INSERT INTO itops_settings (skey,sval) VALUES ('device_inventory',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([json_encode($st)]);
}

function itops_dinv_is_perm(string $m): bool
{
    return stripos($m, 'authoriz') !== false || stripos($m, 'forbidden') !== false || str_contains($m, '403')
        || stripos($m, 'permission') !== false || stripos($m, 'privilege') !== false;
}
function itops_dinv_is_license(string $m): bool
{
    return stripos($m, 'premium') !== false || stripos($m, 'license') !== false || stripos($m, 'B2C') !== false;
}

/** Empty row with every column the table has. */
function itops_dinv_blank(string $key): array
{
    return ['dkey' => $key, 'source' => 'signin', 'entra_id' => null, 'device_id' => null, 'name' => '', 'os' => '', 'os_version' => '',
        'os_label' => '', 'os_family' => 'other', 'kind' => 'other', 'ownership' => 'unknown', 'trust_type' => '', 'model' => '',
        'is_managed' => 0, 'is_compliant' => null, 'enabled' => 1, 'os_eol' => null, 'user_upn' => '', 'user_name' => '', 'owner_enabled' => null, 'owner_last_active' => null,
        'users_json' => '[]', 'registered_at' => null, 'last_seen' => null, 'last_ip' => '', 'last_location' => '', 'last_app' => '',
        'last_client' => '', 'seen_count' => 0, 'in_directory' => 1, 'first_seen' => gmdate('Y-m-d H:i:s')];
}

/**
 * Read Entra + the sign-in log and bring the table up to date.
 * $opt: svc (ItopsM365), max_pages (sign-in pages of 1,000; default 8).
 * Returns the saved state: last_sync, needs (permissions missing), errors, counts, notes.
 */
function itops_dinv_sync(PDO $db, array $opt = []): array
{
    if (!itops_dinv_ready($db)) return ['error' => 'The device inventory table is missing — run the database update (Settings → Maintenance).'];
    $lock = false;
    try { $lock = (int)$db->query("SELECT GET_LOCK('itops_dinv_sync', 0)")->fetchColumn() === 1; } catch (Throwable $e) { $lock = true; }
    if (!$lock) { $st = itops_dinv_state($db); $st['busy'] = true; return $st; }
    try {
        @set_time_limit(240);
        itops_dinv_hold($db);
        $ping = function () use ($db) { itops_dinv_ping($db); };
        $svc = $opt['svc'] ?? ItopsM365::fromConfig();
        $maxPages = max(1, (int)($opt['max_pages'] ?? 8));
        $prev = itops_dinv_state($db);
        $nowTs = time(); $now = gmdate('Y-m-d H:i:s', $nowTs);
        $st = ['last_sync' => gmdate('Y-m-d\TH:i:s\Z', $nowTs), 'cursor' => $prev['cursor'] ?? null, 'needs' => [], 'errors' => [], 'notes' => [], 'counts' => []];

        // Existing rows, by key.
        $rows = []; $dirty = [];
        foreach ($db->query('SELECT * FROM itops_device_inventory')->fetchAll(PDO::FETCH_ASSOC) as $r) { unset($r['id'], $r['updated_at']); $rows[$r['dkey']] = $r; }
        $addUser = function (array &$r, string $upn, string $name = '') {
            if ($upn === '') return;
            $l = json_decode((string)($r['users_json'] ?? '[]'), true) ?: [];
            foreach ($l as $u) if (strcasecmp((string)($u['upn'] ?? ''), $upn) === 0) return;
            if (count($l) >= 12) return;
            $l[] = ['upn' => $upn, 'name' => $name];
            $r['users_json'] = json_encode($l);
        };

        // ── 1. Entra devices ────────────────────────────────────────────────
        $devOk = false;
        try {
            $devs = $svc->entraDevices($ping); $devOk = true; $seenKeys = [];
            foreach ($devs as $d) {
                $did = $d['device_id'] !== '' ? $d['device_id'] : strtolower($d['id']);
                if ($did === '') continue;
                $key = 'entra:' . $did; $seenKeys[$key] = true;
                $r = $rows[$key] ?? itops_dinv_blank($key);
                $osi = itops_dinv_os($d['os'], $d['os_version']);
                $trust = itops_dinv_trust($d['trust_type']);
                $r['source'] = 'entra'; $r['entra_id'] = $d['id']; $r['device_id'] = $did;
                $r['name'] = itops_dinv_cut($d['name'], 190); $r['os'] = itops_dinv_cut($d['os'], 60); $r['os_version'] = itops_dinv_cut($d['os_version'], 60);
                $r['os_label'] = itops_dinv_cut($osi['label'], 60); $r['os_family'] = $osi['family']; $r['kind'] = $osi['kind'];
                $r['os_eol'] = $osi['eol'] === null ? null : (int)$osi['eol'];
                $r['trust_type'] = $trust; $r['ownership'] = itops_dinv_ownership($trust, $d['ownership']);
                $r['model'] = itops_dinv_cut($d['model'], 190);
                $r['is_managed'] = (int)$d['managed']; $r['is_compliant'] = $d['compliant'] === null ? null : (int)$d['compliant']; $r['enabled'] = (int)$d['enabled'];
                $r['registered_at'] = itops_dinv_dt($d['registered']); $r['in_directory'] = 1;
                $ls = itops_dinv_dt($d['last_seen']);
                if ($ls !== null && ($r['last_seen'] === null || $ls > $r['last_seen'])) $r['last_seen'] = $ls;
                if ($d['owners']) { $r['user_upn'] = itops_dinv_cut($d['owners'][0]['upn'], 190); $r['user_name'] = itops_dinv_cut($d['owners'][0]['name'], 190); }
                foreach ($d['owners'] as $o) $addUser($r, $o['upn'], $o['name']);
                $rows[$key] = $r; $dirty[$key] = true;
            }
            foreach ($rows as $key => $r) {
                if ($r['source'] === 'entra' && !isset($seenKeys[$key]) && (int)$r['in_directory'] === 1) { $rows[$key]['in_directory'] = 0; $dirty[$key] = true; }
            }
            $st['counts']['entra'] = count($devs);
        } catch (Throwable $e) {
            $m = $e->getMessage();
            if (itops_dinv_is_perm($m)) $st['needs']['devices'] = 'Device.Read.All'; else $st['errors']['devices'] = mb_substr($m, 0, 300);
        }

        // ── 2. Sign-in log (successful interactive sign-ins since the last sync) ─────────
        $floor = $nowTs - ITOPS_DINV_LOOKBACK * 86400;
        $cur = isset($prev['cursor']) ? strtotime((string)$prev['cursor']) : false;
        if ($cur === false || $cur < $floor) $cur = $floor;
        try {
            $capped = false;
            $ping();
            $sis = $svc->signInsSince(gmdate('Y-m-d\TH:i:s\Z', $cur), $maxPages, $capped, $ping);
            $newest = $cur; $used = 0;
            foreach ($sis as $sx) {
                $t = strtotime($sx['time']); if ($t !== false && $t > $newest) $newest = $t;
                $osi = itops_dinv_os($sx['os']);
                if ($sx['upn'] === '' || ($osi['family'] === 'other' && $sx['device_id'] === '')) continue;   // nothing to tell devices apart by
                $registered = $sx['device_id'] !== '';
                $key = $registered ? 'entra:' . $sx['device_id'] : 'seen:' . sha1(strtolower($sx['upn']) . '|' . $osi['family']);
                $isNew = !isset($rows[$key]);
                $r = $rows[$key] ?? itops_dinv_blank($key);
                if ($isNew) {
                    $r['os'] = itops_dinv_cut($sx['os'], 60); $r['os_label'] = itops_dinv_cut($osi['label'], 60); $r['os_family'] = $osi['family']; $r['kind'] = $osi['kind'];
                    if ($registered) {
                        $trust = itops_dinv_trust($sx['trust_type']);
                        $r['device_id'] = $sx['device_id']; $r['name'] = itops_dinv_cut($sx['device_name'], 190); $r['trust_type'] = $trust;
                        $r['ownership'] = itops_dinv_ownership($trust, ''); $r['is_managed'] = (int)$sx['managed']; $r['is_compliant'] = (int)$sx['compliant'];
                    } else { $r['ownership'] = 'unregistered'; }
                }
                $ts = itops_dinv_dt($sx['time']);
                if ($ts !== null && ($r['last_seen'] === null || $ts >= $r['last_seen'])) {
                    $r['last_seen'] = $ts; $r['last_ip'] = itops_dinv_cut($sx['ip'], 64); $r['last_location'] = itops_dinv_cut($sx['location'], 190);
                    $r['last_app'] = itops_dinv_cut($sx['app'], 120); $r['last_client'] = itops_dinv_cut($sx['client'], 120);
                    if (!$registered || $r['user_upn'] === '' || $r['source'] === 'signin') { $r['user_upn'] = itops_dinv_cut($sx['upn'], 190); $r['user_name'] = itops_dinv_cut($sx['user_name'], 190); }
                }
                $addUser($r, $sx['upn'], $sx['user_name']);
                $r['seen_count'] = (int)$r['seen_count'] + 1;
                $rows[$key] = $r; $dirty[$key] = true; $used++;
            }
            $st['cursor'] = gmdate('Y-m-d\TH:i:s\Z', $newest);
            $st['counts']['signins'] = $used;
            if ($capped) $st['notes'][] = 'The sign-in log was long: ' . number_format($maxPages * 1000) . ' sign-ins were read this time; the next run continues from there.';
        } catch (Throwable $e) {
            $m = $e->getMessage();
            if (itops_dinv_is_license($m)) $st['needs']['signins_license'] = 'Microsoft Entra ID P1';
            elseif (itops_dinv_is_perm($m)) $st['needs']['signins'] = 'AuditLog.Read.All';
            else $st['errors']['signins'] = mb_substr($m, 0, 300);
        }

        // ── 3. Is the owner's account still enabled? (best effort) ────────────────────
        try {
            $ping();
            $dir = $svc->directoryForRecon(); $en = []; $act = [];
            foreach ($dir['users'] as $u) {
                $k = strtolower($u['upn']); $en[$k] = $u['enabled'] ? 1 : 0; $act[$k] = itops_dinv_dt($u['last_activity'] ?? null);
                if ($u['mail'] !== '') { $k2 = strtolower($u['mail']); $en[$k2] ??= $en[$k]; $act[$k2] ??= $act[$k]; }
            }
            $hasAct = !empty($dir['has_sign_in']);
            foreach ($rows as $key => $r) {
                $uk = strtolower((string)$r['user_upn']);
                $v = $uk === '' ? null : ($en[$uk] ?? null);
                $cur0 = $r['owner_enabled'] === null ? null : (int)$r['owner_enabled'];
                if ($v !== $cur0) { $rows[$key]['owner_enabled'] = $v; $dirty[$key] = true; }
                // the person's own last activity (only moves forward; kept when sign-in activity can't be read this time)
                $a = ($hasAct && $uk !== '') ? ($act[$uk] ?? null) : null;
                if ($a !== null && ($r['owner_last_active'] === null || $a > $r['owner_last_active'])) { $rows[$key]['owner_last_active'] = $a; $dirty[$key] = true; }
            }
        } catch (Throwable $e) { /* the inventory still works without it */ }

        // ── 4. Write ─────────────────────────────────────────────────────────
        $ping();
        $cols = array_keys(itops_dinv_blank('x'));
        $upd = array_values(array_filter($cols, fn($c) => $c !== 'dkey' && $c !== 'first_seen'));
        $sql = 'INSERT INTO itops_device_inventory (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ') ON DUPLICATE KEY UPDATE '
             . implode(',', array_map(fn($c) => $c === 'last_seen'
                 ? 'last_seen=IF(VALUES(last_seen) IS NULL OR (last_seen IS NOT NULL AND last_seen > VALUES(last_seen)), last_seen, VALUES(last_seen))'   // BT29: never backwards (the app may report during a sync)
                 : "$c=VALUES($c)", $upd));
        $ins = $db->prepare($sql);
        foreach (array_keys($dirty) as $key) { $r = $rows[$key]; $ins->execute(array_map(fn($c) => $r[$c] ?? null, $cols)); }

        // Old rows that no longer mean anything: unregistered or removed devices not seen for 180 days.
        $old = gmdate('Y-m-d H:i:s', $nowTs - ITOPS_DINV_KEEP_DAYS * 86400);
        // An unregistered row is kept while its person is still active (see the note at the top of this file).
        $del = $db->prepare("DELETE FROM itops_device_inventory WHERE (source IN ('signin','app') OR in_directory=0) AND COALESCE(last_seen, first_seen) < ?
            AND (ownership <> 'unregistered' OR owner_last_active IS NULL OR owner_last_active < ?)");
        $del->execute([$old, $old]);

        $st['counts']['changed'] = count($dirty); $st['counts']['dropped'] = $del->rowCount();
        $st['counts']['total'] = (int)$db->query('SELECT COUNT(*) FROM itops_device_inventory')->fetchColumn();
        $st['devices_ok'] = $devOk;
        itops_dinv_state_save($db, $st);
        return $st;
    } finally {
        try { $db->query("SELECT RELEASE_LOCK('itops_dinv_sync')"); } catch (Throwable $e) {}
    }
}

// ───────────────────────────── BT29: what the app itself sees ─────────────────────────────

function itops_dinv_has_app(PDO $db): bool
{
    static $ok = null;
    if ($ok === null) { try { $db->query('SELECT app_at FROM itops_device_inventory LIMIT 1'); $ok = true; } catch (Throwable $e) { $ok = false; } }
    return $ok;
}
/**
 * Operating system as reported by the browser the person opened the app with.
 * $f: platform + platform_version (User-Agent Client Hints, Chromium only), touch. $ua: the User-Agent header.
 * Returns family, kind, label, eol (null when the version is not known).
 */
function itops_dinv_client_os(array $f, string $ua): array
{
    $plat = strtolower(trim((string)($f['platform'] ?? ''))); $pv = trim((string)($f['platform_version'] ?? ''));
    $major = preg_match('/^(\d+)/', $pv, $m) ? (int)$m[1] : null;
    $family = 'other';
    if ($plat !== '') { $family = ['windows' => 'windows', 'macos' => 'macos', 'linux' => 'linux', 'android' => 'android', 'ios' => 'ios', 'chrome os' => 'chromeos', 'chromium os' => 'chromeos'][$plat] ?? 'other'; }
    if ($family === 'other') {
        if (preg_match('/Windows NT/i', $ua)) $family = 'windows';
        elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) $family = 'ios';
        elseif (preg_match('/Android/i', $ua)) $family = 'android';
        elseif (preg_match('/CrOS/i', $ua)) $family = 'chromeos';
        elseif (preg_match('/Macintosh|Mac OS X/i', $ua)) $family = 'macos';
        elseif (preg_match('/Linux|X11/i', $ua)) $family = 'linux';
    }
    if ($family === 'macos' && (int)($f['touch'] ?? 0) > 1) $family = 'ios';   // iPad Safari calls itself a Mac
    $kind = in_array($family, ['android', 'ios'], true) ? 'phone' : ($family === 'other' ? 'other' : 'computer');
    $label = ['windows' => 'Windows', 'macos' => 'macOS', 'linux' => 'Linux', 'android' => 'Android', 'ios' => 'iOS', 'chromeos' => 'ChromeOS', 'other' => ''][$family];
    $eol = null;
    if ($family === 'windows') {
        if ($major !== null && $plat !== '') { if ($major >= 13) { $label = 'Windows 11'; $eol = false; } elseif ($major >= 1) { $label = 'Windows 10'; $eol = true; } else { $label = 'Windows (older than 10)'; $eol = true; } }
        elseif (preg_match('/Windows NT (\d+)\.(\d+)/', $ua, $w) && (int)$w[1] < 10) { $label = 'Windows (older than 10)'; $eol = true; }
    } elseif ($family === 'macos' && $major !== null && $plat !== '') { $label = 'macOS ' . $major; $eol = $major < ITOPS_DINV_MAC_MIN; }
    elseif ($family === 'android' && $major !== null) $label = 'Android ' . $major;
    return ['family' => $family, 'kind' => $kind, 'label' => $label, 'eol' => $eol];
}
/** "Teams desktop", "Edge 129", "Firefox 131"… */
function itops_dinv_client_name(array $f, string $ua): string
{
    if (($f['host'] ?? '') === 'teams') {
        $t = strtolower(preg_replace('/[^a-z]/i', '', (string)($f['client_type'] ?? '')));
        return 'Teams ' . (in_array($t, ['desktop', 'web', 'android', 'ios', 'ipados', 'macos', 'visionos'], true) ? $t : 'app');
    }
    $b = trim((string)($f['browser'] ?? ''));
    if ($b !== '' && preg_match('/^[A-Za-z][A-Za-z .]{1,30} \d{1,4}$/', $b)) return str_replace(['Microsoft Edge', 'Google Chrome'], ['Edge', 'Chrome'], $b);
    foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome'] as $k => $n) if (preg_match('#' . $k . '/(\d+)#', $ua, $m)) return $n . ' ' . $m[1];
    if (preg_match('#Version/(\d+).*Safari#', $ua, $m)) return 'Safari ' . $m[1];
    return 'Browser';
}
/**
 * Record that this person opened the app on a device (called after sign-in, in Teams or a browser).
 * A web page cannot tell which physical device it runs on, so the report is attached to the person's only registered
 * device of that operating system when there is exactly one; otherwise to their "not registered" row for that system.
 * The facts come from the browser, so they are an indication, not proof.
 */
function itops_dinv_client_report(PDO $db, array $user, array $f, string $ua, string $ip): array
{
    if (!itops_dinv_ready($db) || !itops_dinv_has_app($db)) return ['ok' => false, 'reason' => 'no_table'];
    $upn = strtolower(trim((string)($user['email'] ?? '')));
    if ($upn === '' || empty($user['id'])) return ['ok' => false, 'reason' => 'no_user'];
    $os = itops_dinv_client_os($f, $ua);
    if ($os['family'] === 'other') return ['ok' => false, 'reason' => 'unknown_os'];
    $host = ($f['host'] ?? '') === 'teams' ? 'teams' : 'browser';
    $client = itops_dinv_cut(itops_dinv_client_name($f, $ua), 60);
    $now = gmdate('Y-m-d H:i:s');
    $st = $db->prepare("SELECT id, dkey, source, os_label FROM itops_device_inventory WHERE user_upn=? AND os_family=? AND in_directory=1");
    $st->execute([$upn, $os['family']]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $entra = array_values(array_filter($rows, fn($r) => $r['source'] === 'entra'));
    if (count($entra) > 1) $entra = array_values(array_filter($entra, fn($r) => $r['os_label'] === $os['label']));   // several registered devices: only a version match can single one out
    $regCount = count(array_filter($rows, fn($r) => $r['source'] === 'entra'));
    if (count($entra) === 1) {
        $db->prepare("UPDATE itops_device_inventory SET app_at=?, app_host=?, app_client=?, app_os=?, last_seen=IF(last_seen IS NULL OR last_seen < ?, ?, last_seen) WHERE id=?")
           ->execute([$now, $host, $client, $os['label'], $now, $now, (int)$entra[0]['id']]);
        return ['ok' => true, 'attached' => 'registered'];
    }
    if ($regCount > 1) return ['ok' => true, 'attached' => 'ambiguous'];   // it is one of their registered devices; which one cannot be known
    $key = 'seen:' . sha1($upn . '|' . $os['family']);
    $cur = null; foreach ($rows as $r) if ($r['dkey'] === $key) $cur = $r;
    if (!$cur) { $s2 = $db->prepare('SELECT id, dkey, source, os_label FROM itops_device_inventory WHERE dkey=?'); $s2->execute([$key]); $cur = $s2->fetch(PDO::FETCH_ASSOC) ?: null; }
    $specific = $os['eol'] !== null;   // the browser told us the exact version
    if ($cur) {
        $sql = "UPDATE itops_device_inventory SET app_at=?, app_host=?, app_client=?, app_os=?, last_seen=?, in_directory=1" . ($specific ? ', os_label=?, os_eol=?' : '') . ' WHERE id=?';
        $args = [$now, $host, $client, $os['label'], $now];
        if ($specific) { $args[] = $os['label']; $args[] = (int)$os['eol']; }
        $args[] = (int)$cur['id'];
        $db->prepare($sql)->execute($args);
        return ['ok' => true, 'attached' => 'unregistered'];
    }
    $r = itops_dinv_blank($key);
    $r['source'] = 'app'; $r['ownership'] = 'unregistered'; $r['os'] = itops_dinv_cut($os['label'], 60); $r['os_label'] = itops_dinv_cut($os['label'], 60);
    $r['os_family'] = $os['family']; $r['kind'] = $os['kind']; $r['os_eol'] = $os['eol'] === null ? null : (int)$os['eol'];
    $r['user_upn'] = itops_dinv_cut($upn, 190); $r['user_name'] = itops_dinv_cut((string)($user['display_name'] ?? ''), 190); $r['owner_enabled'] = 1;
    $r['users_json'] = json_encode([['upn' => $upn, 'name' => (string)($user['display_name'] ?? '')]]);
    $r['last_seen'] = $now; $r['last_ip'] = itops_dinv_cut($ip, 64); $r['last_client'] = $client; $r['first_seen'] = $now;
    $cols = array_keys($r);
    $db->prepare('INSERT IGNORE INTO itops_device_inventory (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($r));
    $db->prepare("UPDATE itops_device_inventory SET app_at=?, app_host=?, app_client=?, app_os=? WHERE dkey=?")->execute([$now, $host, $client, $os['label'], $key]);
    return ['ok' => true, 'attached' => 'new'];
}


// ───────────────────────── BT31: acting on a device (roadmap phase 3a) ─────────────────────────

/** One inventory row by its id, or null. */
function itops_dinv_row(PDO $db, int $id): ?array
{
    if ($id <= 0 || !itops_dinv_ready($db)) return null;
    $q = $db->prepare('SELECT * FROM itops_device_inventory WHERE id=?'); $q->execute([$id]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/**
 * What may be done with a device, and — in words — why not. $row = the inventory row, $live = ItopsM365::entraDevice()
 * read just now (null = Entra no longer has it). The rules, all deliberate:
 *   · only devices registered in Entra; rows that come from the sign-in log are never acted on
 *   · hybrid devices (synced from on-premises AD) are managed on-premises, never here
 *   · enable / disable: Windows only — Microsoft lets an application change nothing but extension attributes on other systems
 *   · remove: never while Intune manages it or Autopilot holds it; a Windows device must be disabled first (Microsoft's
 *     disable → wait → delete advice); other systems cannot be disabled by an application, so they must be stale
 *     (not seen for ITOPS_DINV_STALE_DAYS) or belong to a disabled account
 *   · recovery keys: Windows; local admin password (LAPS): Windows joined devices
 */
function itops_dinv_actions(array $row, ?array $live): array
{
    $no = ['disable' => false, 'enable' => false, 'delete' => false, 'keys' => false, 'laps' => false];
    if ((string)($row['device_id'] ?? '') === '')
        return ['can' => $no, 'why' => ['all' => 'This row comes from the sign-in log: the device is not registered in Entra, so there is nothing to disable or remove.'], 'registered' => false];
    if ($live === null)
        return ['can' => $no, 'why' => ['all' => 'Entra no longer has this device. It will leave the list after the next sync.'], 'registered' => false, 'gone' => true];
    $fam     = itops_dinv_os((string)$live['os'], (string)$live['os_version'])['family'];
    $win     = $fam === 'windows';
    $hybrid  = strcasecmp((string)$live['trust_type'], 'ServerAd') === 0;
    $joined  = in_array(strtolower((string)$live['trust_type']), ['azuread', 'serverad'], true);
    $enabled = (bool)$live['enabled'];
    $ref = null;
    foreach ([$live['last_seen'] ?? null, $live['registered'] ?? null, $row['last_seen'] ?? null, $row['app_at'] ?? null, $row['registered_at'] ?? null] as $t) { $ts = $t ? strtotime((string)$t . (strpos((string)$t, 'T') === false ? ' UTC' : '')) : false; if ($ts && ($ref === null || $ts > $ref)) $ref = $ts; }
    $stale = $ref === null ? true : $ref < time() - ITOPS_DINV_STALE_DAYS * 86400;
    $ownerOff = ($row['owner_enabled'] ?? null) !== null && (int)$row['owner_enabled'] === 0;
    $can = $no; $why = [];
    // enable / disable
    if ($hybrid) $why['toggle'] = 'This computer is synced from your on-premises Active Directory. Disable it there; a change made here would be undone by the next sync.';
    elseif (!$win) $why['toggle'] = 'Microsoft only lets an application disable or enable Windows devices. For this one, use the Entra admin center (Devices → All devices).';
    else { $can['disable'] = $enabled; $can['enable'] = !$enabled; }
    // remove
    if ($hybrid) $why['delete'] = 'Synced from your on-premises Active Directory: delete it there, otherwise the next sync brings it back.';
    elseif (!empty($live['autopilot'])) $why['delete'] = 'Registered with Windows Autopilot. Remove it from Autopilot first; Microsoft keeps the Entra device while Autopilot holds it.';
    elseif (!empty($live['managed'])) $why['delete'] = 'Intune still manages this device. Retire or delete it in Intune first (Security → Devices), then remove it here.';
    elseif ($win && $enabled) $why['delete'] = 'Disable it first. Microsoft\'s advice is to disable a device, wait to see that nobody misses it, and only then remove it.';
    elseif (!$win && $enabled && !$stale && !$ownerOff) $why['delete'] = 'It was seen in the last ' . ITOPS_DINV_STALE_DAYS . ' days and its owner\'s account is active. A device like this can be removed here once it has not been seen for ' . ITOPS_DINV_STALE_DAYS . ' days or its owner\'s account is disabled.';
    else $can['delete'] = true;
    // secrets
    if ($win) $can['keys'] = true; else $why['keys'] = 'BitLocker recovery keys exist only for Windows computers.';
    if ($win && $joined) $can['laps'] = true; else $why['laps'] = $win ? 'Windows LAPS backs up passwords only for computers joined to the organization, not for personal (registered) ones.' : 'Local administrator passwords (Windows LAPS) exist only for Windows computers.';
    return ['can' => $can, 'why' => $why, 'registered' => true, 'windows' => $win, 'stale' => $stale, 'owner_disabled' => $ownerOff];
}

/** Write what an action changed back to the inventory row, so the list is right without waiting for the next sync. */
/**
 * BT34 — bulk clean-up: what the inventory proposes to disable (step 1) and to remove (step 2).
 * Reads ITOps's own table and audit log only; no Microsoft call. Every device is read live again when the action runs
 * (itops_dinv_bulk_check), so a row that changed since the last sync is skipped there, never acted on blindly.
 *
 *   Step 1, disable : registered Windows devices, enabled, not seen for ITOPS_DINV_STALE_DAYS.
 *   Step 2, remove  : not seen for ITOPS_DINV_STALE_DAYS (or the owner's account is disabled) and, for Windows, already disabled.
 *                     Phones, tablets and Macs cannot be disabled by an application, so they go straight to step 2.
 *   Never           : Intune-managed, hybrid-joined (on-premises) and Autopilot devices, and rows that only come from the sign-in log.
 */
function itops_dinv_cleanup(PDO $db): array
{
    $out = ['disable' => [], 'remove' => [], 'excluded' => ['managed' => 0, 'hybrid' => 0, 'unregistered' => 0],
            'stale_days' => ITOPS_DINV_STALE_DAYS, 'grace_days' => ITOPS_DINV_GRACE_DAYS, 'max' => ITOPS_DINV_BULK_MAX, 'state' => itops_dinv_state($db)];
    if (!itops_dinv_ready($db)) return $out + ['missing_table' => true];
    // When, and by whom, each device was disabled from ITOps (the audit log is the only record: Entra keeps no "disabled on" date).
    $dis = [];
    try {
        $q = $db->query("SELECT user_name, new_value, DATE_FORMAT(created_at,'%Y-%m-%d') AS d, TIMESTAMPDIFF(DAY, created_at, NOW()) AS days
                         FROM itops_audit_log WHERE action='m365_device_disable' AND entity_type='m365' ORDER BY id DESC LIMIT 3000");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $v = json_decode((string)$a['new_value'], true) ?: [];
            if (($v['result'] ?? '') !== 'success') continue;
            $did = strtolower((string)($v['detail']['device_id'] ?? ''));
            if ($did !== '' && !isset($dis[$did])) $dis[$did] = ['at' => (string)$a['d'], 'by' => (string)$a['user_name'], 'days' => (int)$a['days']];
        }
    } catch (Throwable $e) {}
    $cut = time() - ITOPS_DINV_STALE_DAYS * 86400;
    foreach ($db->query('SELECT * FROM itops_device_inventory WHERE in_directory=1 ORDER BY last_seen, name')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ref = null;
        foreach ([$r['last_seen'] ?? null, $r['app_at'] ?? null, $r['registered_at'] ?? null] as $t) { $ts = $t ? strtotime((string)$t . ' UTC') : false; if ($ts && ($ref === null || $ts > $ref)) $ref = $ts; }
        $stale    = $ref === null || $ref < $cut;
        $ownerOff = $r['owner_enabled'] !== null && (int)$r['owner_enabled'] === 0;
        if (!$stale && !$ownerOff) continue;
        if ((string)($r['device_id'] ?? '') === '') { if ($stale) $out['excluded']['unregistered']++; continue; }
        if (strcasecmp((string)$r['trust_type'], 'ServerAd') === 0) { $out['excluded']['hybrid']++; continue; }
        if ((int)$r['is_managed']) { $out['excluded']['managed']++; continue; }
        $win = $r['os_family'] === 'windows'; $enabled = (bool)(int)$r['enabled'];
        $row = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'os' => $r['os_label'] !== '' ? (string)$r['os_label'] : (string)$r['os'], 'kind' => (string)$r['kind'],
                'user' => (string)$r['user_upn'], 'user_name' => (string)$r['user_name'], 'ownership' => (string)$r['ownership'], 'trust_type' => (string)$r['trust_type'],
                'last_seen' => $ref ? gmdate('Y-m-d\TH:i:s\Z', $ref) : null, 'days' => $ref ? (int)floor((time() - $ref) / 86400) : null,
                'stale' => $stale, 'owner_disabled' => $ownerOff];
        if ($win && $enabled) { if ($stale) $out['disable'][] = $row; continue; }
        $d = $dis[strtolower((string)$r['device_id'])] ?? null;
        // Removing a computer that is joined to the organization also deletes its BitLocker recovery keys and LAPS password from Entra.
        $row += ['windows' => $win, 'enabled' => $enabled, 'keys' => $win && strcasecmp((string)$r['trust_type'], 'AzureAd') === 0,
                 'disabled_at' => $d['at'] ?? null, 'disabled_by' => $d['by'] ?? null, 'disabled_days' => $d['days'] ?? null,
                 // Proposed (pre-ticked) only when the waiting time is known to be over; everything else is listed but left unticked.
                 'ready' => $win ? ($d !== null && $d['days'] >= ITOPS_DINV_GRACE_DAYS) : $stale];
        $out['remove'][] = $row;
    }
    return $out;
}

/**
 * BT34 — the bulk rule for ONE device, on top of itops_dinv_actions() and with the device as Entra has it right now.
 * Returns '' when the bulk action may run, otherwise the reason it is skipped.
 */
function itops_dinv_bulk_check(string $op, array $row, ?array $live): string
{
    $st = itops_dinv_actions($row, $live);
    if (!empty($st['why']['all'])) return (string)$st['why']['all'];
    if ($op === 'disable') {
        if (empty($st['can']['disable'])) return (string)($st['why']['toggle'] ?? 'It is already disabled.');
        if (!empty($live['managed']))   return 'Intune manages this device. Retire it in Intune (Security → Devices) instead.';
        if (!empty($live['autopilot'])) return 'Registered with Windows Autopilot: it is left alone by the bulk clean-up.';
        if (empty($st['stale']))        return 'It has been seen in the last ' . ITOPS_DINV_STALE_DAYS . ' days, so it is no longer stale.';
        return '';
    }
    if (empty($st['can']['delete'])) return (string)($st['why']['delete'] ?? 'It cannot be removed.');
    if (empty($st['stale']) && empty($st['owner_disabled'])) return 'It has been seen in the last ' . ITOPS_DINV_STALE_DAYS . ' days. Remove it from its own row if you are sure.';
    return '';
}

function itops_dinv_mark(PDO $db, int $id, array $set): void
{
    $allowed = ['enabled', 'in_directory']; $sql = []; $args = [];
    foreach ($set as $k => $v) if (in_array($k, $allowed, true)) { $sql[] = "$k=?"; $args[] = (int)$v; }
    if (!$sql) return;
    $args[] = $id;
    $db->prepare('UPDATE itops_device_inventory SET ' . implode(',', $sql) . ' WHERE id=?')->execute($args);
}

/** The inventory as the screen shows it: rows with their flags, a summary and the sync state. */
function itops_dinv_list(PDO $db): array
{
    $st = itops_dinv_state($db);
    if (!itops_dinv_ready($db)) return ['devices' => [], 'summary' => [], 'total' => 0, 'state' => $st, 'missing_table' => true];
    $stale = gmdate('Y-m-d H:i:s', time() - ITOPS_DINV_STALE_DAYS * 86400);
    $out = []; $sum = ['total' => 0, 'computers' => 0, 'phones' => 0, 'company' => 0, 'personal' => 0, 'unregistered' => 0, 'unknown' => 0,
        'unmanaged' => 0, 'eol' => 0, 'stale' => 0, 'owner_disabled' => 0, 'removed' => 0, 'people' => 0, 'byod_computers' => 0, 'app' => 0];
    $people = [];
    foreach ($db->query('SELECT * FROM itops_device_inventory ORDER BY last_seen DESC, name')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ref = $r['last_seen'] ?? $r['registered_at'] ?? $r['first_seen'];
        // Unregistered rows only have interactive sign-ins: the person's own activity also counts as "seen".
        if ($r['ownership'] === 'unregistered' && $r['owner_last_active'] !== null && ($ref === null || $r['owner_last_active'] > $ref)) $ref = $r['owner_last_active'];
        $flags = [];
        if (!(int)$r['in_directory']) $flags[] = 'removed';
        if ($r['kind'] === 'computer' && !(int)$r['is_managed']) $flags[] = 'unmanaged';
        if ($r['os_eol'] !== null && (int)$r['os_eol'] === 1) $flags[] = 'eol';
        if ($ref !== null && $ref < $stale) $flags[] = 'stale';
        if ($r['owner_enabled'] !== null && (int)$r['owner_enabled'] === 0) $flags[] = 'owner_disabled';
        if (!(int)$r['enabled']) $flags[] = 'disabled';
        $own = in_array($r['ownership'], ['company', 'personal', 'unregistered'], true) ? $r['ownership'] : 'unknown';
        $out[] = [
            'id' => (int)$r['id'], 'source' => $r['source'], 'object_id' => (int)$r['in_directory'] ? (string)($r['entra_id'] ?? '') : '', 'name' => $r['name'], 'os' => $r['os_label'] !== '' ? $r['os_label'] : $r['os'],
            'os_version' => $r['os_version'], 'os_family' => $r['os_family'], 'kind' => $r['kind'], 'ownership' => $own, 'trust_type' => $r['trust_type'],
            'model' => $r['model'], 'managed' => (bool)$r['is_managed'], 'compliant' => $r['is_compliant'] === null ? null : (bool)$r['is_compliant'],
            'enabled' => (bool)$r['enabled'], 'user' => $r['user_upn'], 'user_name' => $r['user_name'],
            'users' => json_decode((string)$r['users_json'], true) ?: [],
            'registered' => itops_dinv_iso($r['registered_at']), 'last_seen' => itops_dinv_iso($r['last_seen']), 'first_seen' => itops_dinv_iso($r['first_seen']),
            'person_active' => itops_dinv_iso($r['owner_last_active']),
            'opened' => !empty($r['app_at']) ? ['at' => itops_dinv_iso($r['app_at']), 'host' => (string)$r['app_host'], 'client' => (string)$r['app_client'], 'os' => (string)$r['app_os']] : null,
            'ip' => $r['last_ip'], 'location' => $r['last_location'], 'app' => $r['last_app'], 'client' => $r['last_client'],
            'seen_count' => (int)$r['seen_count'], 'flags' => $flags,
        ];
        $sum['total']++; $sum[$own]++;
        if ($r['kind'] === 'computer') { $sum['computers']++; if ($own === 'personal' || $own === 'unregistered') $sum['byod_computers']++; }
        elseif ($r['kind'] === 'phone') $sum['phones']++;
        foreach (['unmanaged', 'eol', 'stale', 'owner_disabled', 'removed'] as $f) if (in_array($f, $flags, true)) $sum[$f]++;
        if ($r['user_upn'] !== '') $people[strtolower($r['user_upn'])] = true;
        if (!empty($r['app_at'])) $sum['app']++;
    }
    $sum['people'] = count($people);
    return ['devices' => $out, 'summary' => $sum, 'total' => count($out), 'state' => $st,
        'needs_permission' => ($st['needs'] ?? []) ?: null, 'stale_days' => ITOPS_DINV_STALE_DAYS];
}
