<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps BT21 — Microsoft 365 area.
 *
 *   GET|POST /api/m365hub/<action>
 *
 * The action names, parameters, response shapes, validation messages and audit behaviour are
 * the earlier design's (api.php "Microsoft 365 console", license-report, recon-directory, provisioning and
 * onboarding-template blocks). Differences, all deliberate:
 *   · Capabilities map to ITOps permission modules (see $CAPS) — reads need
 *     "Microsoft 365" (view); each kind of change has its own module.
 *   · Onboarding templates live in the table itops_m365_templates (earlier: an app config file).
 *   · "Your own account" = your Microsoft sign-in id (o365_oid) or your email.
 *   · Offboard wizard steps run through ITOps's offboarding engine, so every departure keeps a
 *     record with verification, recertification and the ITOps extras (offboard-start / -step / -verify).
 *   · Changes that alter the directory clear the 10-minute cache that reports read from, so a
 *     removed license does not linger in the lists (an earlier quirk).
 */
require_once __DIR__ . '/../lib/M365Hub.php';
require_once __DIR__ . '/../lib/M365Guard.php';   // BT88 (code audit H-10, M-31): one admin-account guard
require_once __DIR__ . '/../lib/M365Profile.php';   // BT38: custom fields and company-wide values
require_once __DIR__ . '/../lib/EventLog.php';
require_once __DIR__ . '/../lib/Onboarding.php';   // itops_graph_retry()
require_once __DIR__ . '/../lib/M365Background.php';   // BT54: reads kept fresh in the background

$db     = getDB();
// BT29.1: Microsoft reads can take a while and the result is then written to the cache or the inventory. Ask for a long
// idle allowance so a host that drops idle connections quickly does not answer "MySQL server has gone away".
try { $db->exec('SET SESSION wait_timeout=1800'); } catch (Throwable $e) {}
$action = (string)($segments[1] ?? '');
// BT72: reads kept on the server and served at once (lib/ReadStore.php); a change drops every kept copy
require_once __DIR__ . '/../lib/ReadStore.php';
itops_rs_drop_on_change();
$body   = in_array($method, ['POST', 'PUT'], true) ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
// BT42: a request body is a JSON object. A bare text, number or true/false would make every "$body['…']" below an error
// page instead of a sentence.
if (!is_array($body)) error_response('The request is not in the expected form (a JSON object). Nothing was changed.', 400);

// ── Capability → ITOps permission module ────────────────────────────────────
$CAPS = [
    'm365.view'          => ['m365', 'view'],
    'm365.users'         => ['m365_users', 'edit'],
    'm365.credentials'   => ['m365_credentials', 'edit'],
    'm365.licenses'      => ['m365_licenses', 'edit'],
    'm365.groups'        => ['m365_groups', 'edit'],
    'm365.roles'         => ['m365_roles', 'edit'],
    'm365.devices'       => ['m365_devices', 'edit'],     // BT31: disable / enable / remove Entra devices
    'm365.keys'          => ['m365_keys', 'view'],        // BT31: see BitLocker recovery keys and LAPS passwords
    'm365.mail.view'     => ['m365_mail', 'view'],        // BT113: mailbox details, calendar access, message trace
    'm365.mail'          => ['m365_mail', 'edit'],        // BT113: change "send on behalf" delegates and calendar access
    'm365.config'        => ['m365_admin', 'edit'],       // BT114: restart / Defender scan a device, pause or resume an update ring
    'm365.ca'            => ['m365_ca', 'edit'],         // BT118: Conditional Access changes (create report-only, on / off, delete own, put back, security defaults)
    'm365.incidents'     => ['m365_incidents', 'edit'],   // BT119: assign, classify and resolve Defender incidents, comment, make a ticket
    'm365.wipe'          => ['m365_device_wipe', 'edit'], // BT114: ask for, and approve, retire / wipe / fresh start (never one's own request)
    'provision.onboard'  => ['provision_onboard', 'edit'],
    'provision.offboard' => ['provision_offboard', 'edit'],
    'admin.connections'  => ['onboarding', 'edit'],       // onboarding templates: save / delete
];
$can = function (string $c) use ($CAPS, $currentUser): bool {
    [$m, $a] = $CAPS[$c];
    return itops_can($currentUser, $m, $a);
};
$cap = function (string $c) use ($can, $CAPS, $currentUser) {
    if (!$can($c)) { itops_refusal_log($currentUser, $CAPS[$c][0], $CAPS[$c][1] ?? ''); error_response('You do not have permission for this (' . $CAPS[$c][0] . ').', 403); }   // BT124: audited
};

// ── "Is this my own Microsoft 365 account?" (lock-out guards) ────────────────
$guardT = function (string $id, string $what, bool $secret = false) use ($currentUser) { itops_m365_guard_target($currentUser, $id, $what, $secret); };
$isSelf = function (string $id) use ($currentUser): bool {
    static $seen = [];
    $id = strtolower(trim($id));
    if ($id === '') return false;
    $oid = strtolower(trim((string)($currentUser['o365_oid'] ?? '')));
    $em  = strtolower(trim((string)($currentUser['email'] ?? '')));
    if (($oid !== '' && $id === $oid) || ($em !== '' && $id === $em)) return true;
    if (!array_key_exists($id, $seen)) {
        try { $u = ItopsM365::fromConfig()->getUser($id); $seen[$id] = array_filter([strtolower((string)($u['id'] ?? '')), strtolower((string)($u['upn'] ?? '')), strtolower((string)($u['mail'] ?? ''))]); }
        catch (Throwable $e) { $seen[$id] = []; }
    }
    return ($oid !== '' && in_array($oid, $seen[$id], true)) || ($em !== '' && in_array($em, $seen[$id], true));
};

// ── 10-minute report cache ───────────────────────────────────────────────────
$cacheDir = itops_m365_cache_dir();   // BT54: one formula, shared with the background refresh
$cacheFile = fn(string $k) => $cacheDir . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $k) . '.json';
$cacheGet = function (string $k, int $ttl) use ($cacheFile) {
    $ttl = itops_m365_bg_ttl($k, $ttl);   // BT54: while the background refresh keeps this read fresh, until its next run
    $f = $cacheFile($k);
    if (!is_file($f)) return null;
    $j = json_decode((string)@file_get_contents($f), true);
    if (!is_array($j)) return null;
    $age = time() - (int)($j['ts'] ?? 0);
    if ($age > $ttl) {   // BT72: shown at once to a plain read while the page asks for a new one in the background
        if ($k !== 'm365-grid-new' && $age <= ITOPS_RS_KEEP && itops_rs_may_serve_stale()) { itops_rs_mark('stale', (int)$j['ts']); return $j; }
        return null;
    }
    return $j;
};
$cachePut = function (string $k, $payload) use ($cacheDir, $cacheFile): int {
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
    $ts = time();
    @file_put_contents($cacheFile($k), json_encode(['ts' => $ts, 'payload' => $payload]), LOCK_EX);
    @chmod($cacheFile($k), 0600);
    return $ts;
};
$cacheDrop = function (string ...$keys) use ($cacheFile, $cacheDir) {
    @touch($cacheDir . '/_dropped.stamp');   // BT124 (review M365A-2): a background read that started before this is not written
    foreach ($keys as $k) {
        if (substr($k, -1) === '*') { foreach (glob($cacheDir . '/' . substr($k, 0, -1) . '*.json') ?: [] as $f) @unlink($f); }
        else @unlink($cacheFile($k));
    }
};
// Anything that changes accounts or licenses: the directory-based reports must re-read.
$dirty = fn() => $cacheDrop('m365-directory', 'recon-computed', 'license-report-*', 'm365-guests', 'm365-grid', 'm365-grid-mgr');   // BT48: and the Accounts grid
// BT53: accounts created here a moment ago. Microsoft's account list can take a little while to include a new account;
// the Accounts grid shows these until a read from Microsoft has them (kept 15 minutes at most).
$newAccGet = function () use ($cacheGet) { $j = $cacheGet('m365-grid-new', 900); $l = is_array($j) ? ($j['payload'] ?? []) : []; return is_array($l) ? $l : []; };
$newAccPut = function (array $l) use ($cachePut, $cacheDrop) { $l = array_values(array_filter($l, fn($r) => (time() - (int)($r['_at'] ?? 0)) < 900)); if ($l) $cachePut('m365-grid-new', $l); else $cacheDrop('m365-grid-new'); };

// ── Audit (ITOps audit log + event log) ─────────────────────────────────────
$audit = function (array $e) use ($currentUser) {
    $act = str_replace('.', '_', (string)($e['action'] ?? 'm365'));
    $ok  = ($e['result'] ?? 'success') !== 'error';
    $rid = (string)($e['record_id'] ?? '');
    $det = $e['detail'] ?? null;
    $name = $rid . (!$ok ? ' — failed: ' . mb_substr((string)($e['error'] ?? ''), 0, 160) : '');
    audit_log($act, 'm365', null, mb_substr($name, 0, 250), null,
              ['result' => $ok ? 'success' : 'error', 'error' => $e['error'] ?? null, 'detail' => $det, 'linked' => $e['linked_record_id'] ?? null]);
    if (function_exists('itops_log')) {
        try { itops_log($ok ? 'info' : 'warning', 'm365', $act . ' ' . $rid . ($ok ? '' : ' (failed)'), ['by' => $currentUser['display_name'] ?? '', 'error' => $e['error'] ?? null]); } catch (Throwable $x) {}
    }
};

// BT38: a change of values, written so the Audit Log screen shows it field by field ("Job title: Staff → Director").
// $old and $new carry the same keys; a refused attempt is logged too, under its own action, with Microsoft's reason.
// Returns null when written (or nothing to write), otherwise why the entry could not be written — the caller tells the user.
$auditChange = function (string $action, string $name, array $old, array $new, bool $ok = true) use ($currentUser): ?string {
    if (!$old && !$new) return null;
    try { audit_log($action, 'm365', null, mb_substr($name, 0, 250), $old ?: ['(nothing)' => ''], $new ?: ['(nothing)' => '']); }
    catch (Throwable $x) {
        if (function_exists('itops_log')) { try { itops_log('error', 'm365', 'AUDIT LOG WRITE FAILED for ' . $action . ' ' . $name . ': ' . $x->getMessage(), ['by' => $currentUser['display_name'] ?? '', 'old' => $old, 'new' => $new]); } catch (Throwable $y) {} }
        return $x->getMessage();
    }
    if (function_exists('itops_log')) {
        try { itops_log($ok ? 'info' : 'warning', 'm365', $action . ' ' . $name . ' (' . implode(', ', array_keys($new)) . ')', ['by' => $currentUser['display_name'] ?? '']); } catch (Throwable $x) {}
    }
    return null;
};

// BT41 — one part of a save (main fields, phones, fax, custom fields, manager) is written to the Audit Log as soon as
// Microsoft has answered it, before the next part is sent: a request the host cuts short still leaves a record of
// everything that was sent. A part that was refused, whose outcome is not known, or that was not sent for lack of time
// is logged under m365_user_update_refused with the value that was wanted and the reason.
$auditPart = function (string $who, array $r, array $changes, string $mgrFrom, string $mgrTo) use ($auditChange): ?string {
    $o = []; $n = [];
    foreach ((array)($r['keys'] ?? []) as $k) {
        if ($k === 'manager') { $label = 'Manager'; $from = $mgrFrom; $to = $mgrTo; }
        elseif (isset($changes[$k])) { $label = $changes[$k]['label']; $from = $changes[$k]['from']; $to = $changes[$k]['to']; }
        else continue;
        $o[$label] = $from;
        $n[$label] = !empty($r['ok']) ? $to
            : (!empty($r['unsent']) ? 'not sent' : (!empty($r['uncertain']) ? 'outcome not known' : 'not changed')) . ((string)$to === '' ? ' (wanted: empty) - ' : ' (wanted "' . $to . '") - ') . (string)($r['error'] ?? 'refused');
    }
    return $auditChange(!empty($r['ok']) ? 'm365_user_update' : 'm365_user_update_refused', $who, $o, $n, !empty($r['ok']));
};

// BT41 — time allowed inside one request that sends several writes to Microsoft. Shared hosting ends a request at about
// 60 seconds, so: at most `timeout` seconds per call to Microsoft, no new account started after `start` seconds (bulk
// edit), no new part sent after `send` seconds. A host with another limit can store other numbers under the setting
// m365_write_budget, for example {"timeout":8,"start":15,"send":25}.
$writeBudget = function () use ($db): array {
    $b = ['timeout' => 12, 'start' => 25, 'send' => 40];
    try { $st = itops_m365_setting_get($db, 'm365_write_budget'); } catch (Throwable $e) { $st = null; }
    if (is_array($st)) foreach ($b as $k => $v) if (isset($st[$k]) && is_numeric($st[$k])) $b[$k] = max(1, min(55, (float)$st[$k]));
    return $b;
};
$reqStart = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
// An account is named by its directory id or by a plain sign-in name. Nothing that could cut the address of the request
// to Microsoft short ("#", "?", "/"), on every endpoint that puts it in one.
$accountRef = fn($v): string => is_string($v) || is_int($v) ? trim((string)$v) : '';
$isAccountRef = fn(string $id): bool => (bool)(preg_match('/^[A-Za-z0-9-]{1,64}$/', $id) || preg_match('/^[A-Za-z0-9._%+\'-]{1,64}@[A-Za-z0-9.-]{1,190}$/', $id));

if ($action === '') error_response('An action is required.', 400);
// BT72: lists kept on the server (lib/ReadStore.php) — only for someone who may read them (each needs Microsoft 365 · View);
// a copy is never served before that check
if ($method === 'GET' && in_array($action, ['m365-users', 'm365-skus', 'm365-groups', 'm365-roles', 'm365-user-roles', 'm365-user-signins'], true) && $can('m365.view')) itops_rs_begin('m365hub', $action);
// BT72: what the app reads ahead for this person as soon as they sign in (and every 15 minutes while it is open)
if ($action === 'm365-warm-list' && $method === 'GET') {
    $cap('m365.view');
    // only the reads the server has no current copy of (lib/ReadStore.php)
    json_response(['urls' => itops_rs_warm_list($currentUser), 'every' => 15, 'parallel' => 2]);
}

// ═════════════════════ Microsoft 365 console ═════════════════════
// BT47: the organization's active custom fields as fields a template or the Onboard form can carry (key ext<slot>)
$customUserFields = function () use ($db): array {
    $out = [];
    try {
        require_once __DIR__ . '/../lib/M365Profile.php';
        foreach (itops_m365_custom_fields($db) as $cf) if (!empty($cf['active'])) $out[] = ['key' => 'ext' . (int)$cf['slot'], 'label' => (string)$cf['label'], 'common' => false, 'custom' => true,
            'options' => ($cf['kind'] ?? '') === 'list' ? array_values((array)$cf['options']) : []];
    } catch (Throwable $e) {}
    return $out;
};
// ---- BT133: Security → Threats & vulnerabilities, and a device's Defender status (read only; lib/ThreatIntel.php) ----
if (in_array($action, ['sec-vulns', 'sec-vuln', 'sec-articles', 'sec-article', 'dev-defender'], true)) {
    $cap('m365.view');
    require_once __DIR__ . '/../lib/ThreatIntel.php';
    if (!itops_graph_configured()) json_response(['configured' => false]);
    try {
        if ($action === 'sec-vulns') {
            $refresh = !empty($_GET['refresh']);
            $cc = $refresh ? null : $cacheGet('sec-vulns', 1800);
            if ($cc) { $p = $cc['payload']; $p['cached'] = true; $p['cached_at'] = date('c', $cc['ts']); json_response($p); }
            $p = ['configured' => true] + itops_ti_vulns();
            if ($p['ok']) $cachePut('sec-vulns', $p);
            json_response($p + ['read_at' => date('c')]);
        }
        if ($action === 'sec-vuln') json_response(['configured' => true] + itops_ti_vuln((string)($_GET['cve'] ?? '')));
        if ($action === 'sec-articles') {
            $q = trim((string)($_GET['q'] ?? ''));
            $cc = ($q !== '' || !empty($_GET['refresh'])) ? null : $cacheGet('sec-articles', 3600);
            if ($cc) { $p = $cc['payload']; $p['cached'] = true; json_response($p); }
            $p = ['configured' => true] + itops_ti_articles($q);
            if ($p['ok'] && $q === '') $cachePut('sec-articles', $p);
            json_response($p);
        }
        if ($action === 'sec-article') {
            try { json_response(['configured' => true, 'ok' => true, 'article' => itops_ti_article((string)($_GET['id'] ?? ''))]); }
            catch (RuntimeException $e) { json_response(['configured' => true, 'ok' => false, 'needs_permission' => itops_ti_needs($e, 'ThreatIntelligence.Read.All'), 'error' => mb_substr($e->getMessage(), 0, 300)]); }
        }
        json_response(['configured' => true] + itops_ti_defender_state((string)($_GET['id'] ?? '')));
    } catch (InvalidArgumentException $e) { error_response($e->getMessage(), 400); }
}

if (strpos($action, 'm365-') === 0) {

    // BT41: every action on one account names it by its directory id or its sign-in name. Anything else — a word that is
    // an address of its own in Microsoft Graph, or text with "/", "?" or "#" — is refused here, before any of them runs.
    if (strpos($action, 'm365-user-') === 0 && $action !== 'm365-user-history') {
        $rid = $method === 'GET' ? ($_GET['id'] ?? null) : ($body['id'] ?? null);
        if ($rid !== null && $rid !== '' && (!(is_string($rid) || is_int($rid)) || !$isAccountRef(trim((string)$rid))))
            error_response('That account id is not valid. Use the id of the account or its sign-in name.', 400);
    }


    // ---- BT48: the Accounts grid. Every account, one row each, with the values a column can show. ----
    // The read of the directory is kept for five minutes (any account change made here drops it; ?refresh=1 reads again).
    // ?signin=1 adds the last sign-in (from the directory report, ten minutes), ?manager=1 the manager: each costs
    // Microsoft several requests, so the screen asks only when that column is shown.
    // ---- BT54: the background refresh — what it keeps, when it last ran, run it now, its settings ----
    if ($action === 'm365-bg-status' || $action === 'm365-bg-run' || $action === 'm365-bg-config') {
        $cap('m365.view');
        $canCfg = itops_can($currentUser, 'integrations', 'edit');
        $status = function () use ($db, $canCfg) {
            $cfg = itops_m365_bg_config($db); $st = itops_m365_bg_get($db, 'm365_bg_state'); $defs = itops_m365_bg_sets();
            $sets = []; foreach ($defs as $id => $d) $sets[] = ['id' => $id, 'label' => $d[0], 'what' => $d[1], 'on' => in_array($id, $cfg['sets'], true)];
            $failed = [];
            foreach (($st['items'] ?? []) as $k => $i) if (empty($i['ok'])) { $lbl = $k; foreach ($defs as $d) if (in_array($k, $d[2], true)) $lbl = $d[0]; $failed[] = ['key' => $k, 'set' => $lbl, 'error' => (string)($i['error'] ?? '')]; }
            $next = itops_m365_bg_next($db);
            return ['enabled' => $cfg['enabled'], 'every' => $cfg['every'], 'off' => $cfg['off'], 'sets' => $sets,
                    'business_now' => itops_m365_bg_business($db, time()), 'interval_now' => itops_m365_bg_interval($db),
                    'started' => isset($st['started']) ? date('c', (int)$st['started']) : null, 'finished' => !empty($st['finished']) ? date('c', (int)$st['finished']) : null,
                    'ok' => $st['ok'] ?? null, 'by' => $st['by'] ?? null, 'duration' => $st['duration'] ?? null, 'failed' => $failed,
                    'running' => itops_m365_bg_running($db), 'next' => $next ? date('c', $next) : null, 'can_config' => $canCfg,
                    'business_hours_set' => function_exists('itops_bh_config') && itops_bh_config($db) !== null];
        };
        if ($action === 'm365-bg-status') json_response($status());
        if ($method !== 'POST') error_response('Method not allowed', 405);
        if ($action === 'm365-bg-config') {
            if (!$canCfg) { audit_log('m365_bg_config_refused', 'settings', null, 'Microsoft 365 background refresh', null, ['reason' => 'needs Integrations edit']); error_response('Your role cannot change the background refresh (it needs Integrations with Edit).', 403); }
            $old = itops_m365_bg_config($db);
            $every = (int)($body['every'] ?? $old['every']); $off = (int)($body['off'] ?? $old['off']);
            if (!in_array($every, [10, 15, 30, 60], true)) error_response('Every: 10, 15, 30 or 60 minutes.', 400);
            if (!in_array($off, [0, 30, 60, 120, 240], true)) error_response('Outside business hours: 30, 60, 120 or 240 minutes, or not at all.', 400);
            $sets = array_values(array_unique(array_filter((array)($body['sets'] ?? $old['sets']), 'is_string')));
            $bad = array_diff($sets, array_keys(itops_m365_bg_sets())); if ($bad) error_response('Unknown: ' . implode(', ', $bad), 400);
            $new = ['enabled' => (bool)($body['enabled'] ?? $old['enabled']), 'every' => $every, 'off' => $off, 'sets' => $sets];
            itops_m365_bg_set($db, 'm365_bg', $new);
            audit_log('m365_bg_config', 'settings', null, 'Microsoft 365 background refresh', $old, $new);
            json_response(['ok' => true] + $status());
        }
        // m365-bg-run: read now. The answer comes at once; the reads go on after it (like the in-app scheduler).
        if (itops_m365_bg_running($db)) json_response(['ok' => true, 'started' => false, 'reason' => 'running'] + $status());
        $st = itops_m365_bg_get($db, 'm365_bg_state');
        if (!empty($st['finished']) && time() - (int)$st['finished'] < 60) json_response(['ok' => true, 'started' => false, 'reason' => 'just refreshed'] + $status());
        if (!itops_m365_bg_config($db)['sets']) error_response('Nothing is chosen to keep fresh. Choose what under Settings on this line.', 400);
        $by = (string)($currentUser['display_name'] ?? $currentUser['email'] ?? 'someone');
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        ignore_user_abort(true); @set_time_limit(300);
        $out = json_encode(array_merge($status(), ['ok' => true, 'started' => true, 'running' => true]));
        http_response_code(200); header('Content-Type: application/json'); header('Content-Length: ' . strlen($out)); header('Connection: close');
        echo $out;
        if (function_exists('litespeed_finish_request')) litespeed_finish_request();
        elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        else { while (ob_get_level() > 0) @ob_end_flush(); @flush(); }
        itops_m365_bg_run($db, $by);
        exit;
    }

    if ($action === 'm365-accounts-grid') {
        $cap('m365.view');
        $fresh = !empty($_GET['refresh']);
        if ($fresh) $cacheDrop('m365-grid', 'm365-grid-mgr');
        $svc = null; $j = $fresh ? null : $cacheGet('m365-grid', 300);
        if (!is_array($j) || !is_array($j['payload'] ?? null)) {
            try {
                $svc = ItopsM365::fromConfig();
                try { $db->exec('SET SESSION wait_timeout=1800'); } catch (Throwable $e) {}
                $skuMap = []; try { $skuMap = $svc->skus()['map'] ?? []; } catch (Throwable $e) {}   // without it a license shows by its id
                $rows = $svc->gridUsers($skuMap);
            } catch (Throwable $e) { error_response($e->getMessage(), 400); }
            $at = $cachePut('m365-grid', $rows);
        } else { $rows = $j['payload']; $at = (int)$j['ts']; }
        // BT53: accounts created here that Microsoft's list does not show yet are added (marked "just created"); once a
        // read from Microsoft has them they are forgotten.
        $pendingNew = 0; $nl = $newAccGet();
        if ($nl) {
            $have = []; foreach ($rows as $r) $have[strtolower((string)$r['id'])] = true;
            $keep = [];
            foreach ($nl as $r) {
                if (isset($have[strtolower((string)($r['id'] ?? ''))])) continue;
                $keep[] = $r; unset($r['_at']); $r['just_created'] = true; $rows[] = $r; $pendingNew++;
            }
            if (count($keep) !== count($nl)) $newAccPut($keep);
            if ($pendingNew) usort($rows, fn($x, $y) => strcasecmp($x['display_name'] ?: $x['upn'], $y['display_name'] ?: $y['upn']));
        }
        // Of Microsoft's 15 custom attributes only the ones the organization named and switched on leave the server (BT41).
        $customDefs = []; $named = [];
        foreach (itops_m365_custom_fields($db) as $cf) if (!empty($cf['active'])) {
            $named['ext' . (int)$cf['slot']] = true;
            $customDefs[] = ['key' => 'ext' . (int)$cf['slot'], 'label' => (string)$cf['label'], 'options' => (($cf['kind'] ?? '') === 'list') ? array_values((array)($cf['options'] ?? [])) : []];
        }
        $me = array_filter([strtolower(trim((string)($currentUser['o365_oid'] ?? ''))), strtolower(trim((string)($currentUser['email'] ?? '')))]);
        foreach ($rows as $i => $u) {
            foreach (array_keys($u['profile']) as $pk) if (preg_match('/^ext\d+$/', (string)$pk) && !isset($named[$pk])) unset($rows[$i]['profile'][$pk]);
            $rows[$i]['is_self'] = $me && (in_array(strtolower($u['id']), $me, true) || in_array(strtolower($u['upn']), $me, true) || in_array(strtolower($u['mail']), $me, true));
        }
        $out = ['users' => $rows, 'at' => $at, 'custom' => $customDefs,
                'signin' => null, 'manager' => null, 'pending_new' => $pendingNew];
        if (!empty($_GET['signin'])) {
            $dj = $fresh ? null : $cacheGet('m365-directory', 600); $dir = is_array($dj) ? ($dj['payload'] ?? null) : null;
            $err = null;
            if (!is_array($dir) || !isset($dir['users'])) {
                try { $dir = ($svc ?: ItopsM365::fromConfig())->directoryForRecon(); $cachePut('m365-directory', $dir); }
                catch (Throwable $e) { $dir = null; $err = $e->getMessage(); }
            }
            $by = [];
            if (is_array($dir)) foreach ((array)($dir['users'] ?? []) as $d) $by[(string)($d['id'] ?? '')] = $d['last_sign_in'] ?? null;
            foreach ($out['users'] as $i => $u) $out['users'][$i]['last_sign_in'] = $by[$u['id']] ?? null;
            // available = Microsoft gave sign-in dates at all (they need Entra ID P1 and AuditLog.Read.All)
            $out['signin'] = ['ok' => $err === null, 'error' => $err, 'available' => is_array($dir) ? !empty($dir['has_sign_in']) : false];
        }
        if (!empty($_GET['manager'])) {
            $mj = $fresh ? null : $cacheGet('m365-grid-mgr', 300); $mg = is_array($mj) ? ($mj['payload'] ?? null) : null; $err = null;
            if (!is_array($mg)) {
                try { $mg = ($svc ?: ItopsM365::fromConfig())->gridManagers(); $cachePut('m365-grid-mgr', $mg); }
                catch (Throwable $e) { $mg = null; $err = $e->getMessage(); }
            }
            if (is_array($mg)) foreach ($out['users'] as $i => $u) $out['users'][$i]['manager'] = $mg[$u['id']] ?? null;
            $out['manager'] = ['ok' => $err === null, 'error' => $err];
        }
        json_response($out);
    }

    // ---- reads (m365.view) ----
    if ($action === 'm365-users') {
        $cap('m365.view');
        try {
            $res = ItopsM365::fromConfig()->listUsers(
                (string)($_GET['q'] ?? ''),
                (int)($_GET['top'] ?? 50),
                (string)($_GET['skip_token'] ?? ''),
                (string)($_GET['domain'] ?? '')
            );
        } catch (Throwable $e) { error_response($e->getMessage(), 400); }
        json_response($res);
    }

    if ($action === 'm365-skus') {
        $cap('m365.view');
        try { json_response(ItopsM365::fromConfig()->skus()); }
        catch (Throwable $e) { error_response($e->getMessage(), 400); }
    }

    if ($action === 'm365-user') {
        $cap('m365.view');
        $id = $accountRef($_GET['id'] ?? '');
        if ($id === '') error_response('A user id is required.', 400);
        if (!$isAccountRef($id)) error_response('That account id is not valid. Use the id of the account or its sign-in name.', 400);   // BT41
        try {
            $svc = ItopsM365::fromConfig();
            $user = $svc->getUser($id, $svc->skus()['map'] ?? []);
        } catch (Throwable $e) { error_response($e->getMessage(), 400); }
        $user['is_self'] = $isSelf((string)($user['id'] ?? $id));   // ITOps: UI lock-out guards
        try { $user['manager'] = $svc->getManager((string)($user['id'] ?? $id)); $user['manager_known'] = true; }   // BT38
        catch (Throwable $e) { $user['manager'] = null; $user['manager_known'] = false; }
        // BT41: of Microsoft's 15 custom attributes, only the ones the organization named and switched on are sent to the
        // screen. The others may carry something another system put there, which a reader of Accounts has no reason to see.
        if (is_array($user['profile'] ?? null)) {
            $named = []; foreach (itops_m365_custom_fields($db) as $cf0) if (!empty($cf0['active'])) $named['ext' . (int)$cf0['slot']] = true;
            foreach (array_keys($user['profile']) as $pk) if (preg_match('/^ext\d+$/', (string)$pk) && !isset($named[$pk])) unset($user['profile'][$pk]);
        }
        $user['can_history'] = $can('m365.users') || itops_can($currentUser, 'audit', 'view');
        json_response(['user' => $user]);
    }

    // ---- account lifecycle (m365.users) ----
    if ($action === 'm365-user-set-enabled') {
        $cap('m365.users');
        $body = $body;
        $id = trim((string)($body['id'] ?? ''));
        $enabled = (bool)($body['enabled'] ?? false);
        if ($id === '') error_response('A user id is required.', 400);
        if (!$enabled && $isSelf($id)) {
            error_response('You cannot disable your own account.', 400);
        }
        if (!$enabled) $guardT($id, 'disable it');   // BT88
        $ok = true; $err = null;
        try { ItopsM365::fromConfig()->setAccountEnabled($id, $enabled); }
        catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
        $audit([
            'action' => $enabled ? 'm365.user.enable' : 'm365.user.disable',
            'system' => 'm365', 'object_type' => 'user', 'record_id' => $id,
            'result' => $ok ? 'success' : 'error', 'error' => $err,
        ]);
        if (!$ok) error_response($err, 400);
        $dirty();
        json_response(['ok' => true, 'enabled' => $enabled]);
    }

    // ---- BT38: contact information, manager and custom fields (m365.users) ----
    // The organization's custom fields, its company-wide values and the list of what can be edited. ?usage=1 adds how
    // the 15 custom attributes are used across the directory (kept 10 minutes; refresh=1 reads again).
    if ($action === 'm365-custom-fields') {
        $cap('m365.view');
        $fields = itops_m365_custom_fields($db);
        $out = ['fields' => $fields, 'defaults' => (object)itops_m365_org_defaults($db, $fields), 'default_keys' => itops_m365_org_default_keys(),
                'catalog' => ItopsM365::profileFields(), 'groups' => ItopsM365::PROFILE_GROUPS, 'can_manage' => $can('admin.connections'), 'can_edit' => $can('m365.users')];
        if (!empty($_GET['usage'])) {
            // BT41: counting again reads every account, so it is for the people who edit accounts or manage this page;
            // a reader gets the count that is kept (or one fresh count when none is kept).
            $mayRefresh = $can('m365.users') || $can('admin.connections');
            $c = empty($_GET['refresh']) || !$mayRefresh ? $cacheGet('m365-extattr-usage', 600) : null;
            if ($c) { $out['usage'] = $c['payload']; $out['usage_at'] = $c['ts']; }
            else {
                try { $u = ItopsM365::fromConfig()->customAttributeUsage(); $out['usage'] = $u; $out['usage_at'] = $cachePut('m365-extattr-usage', $u); }
                catch (Throwable $e) { $out['usage'] = null; $out['usage_error'] = $e->getMessage(); }
            }
            // BT41: the example values are what accounts really hold in attributes nobody named yet. Only the people who
            // manage this page need them (to see what an attribute already carries before naming it); others get the counts.
            if (is_array($out['usage'] ?? null) && !$can('admin.connections')) {
                foreach ($out['usage']['slots'] as $i => $sl) $out['usage']['slots'][$i]['examples'] = [];
                $out['usage']['examples_hidden'] = true;
            }
        }
        json_response($out);
    }

    if ($action === 'm365-custom-fields-save') {
        $cap('admin.connections');
        if ($method !== 'POST') error_response('Method not allowed', 405);
        $before = itops_m365_custom_fields($db); $beforeDefaults = itops_m365_org_defaults($db, $before);
        // The list of fields must be sent, as a list: a request without it must never read as "remove every field".
        // Company-wide values are optional; when they are not sent, the saved ones are kept.
        if (!is_array($body['fields'] ?? null) || (isset($body['defaults']) && !is_array($body['defaults']))) error_response('A list of fields is expected.', 400);
        try {
            $fields = itops_m365_custom_fields_clean($body['fields']);
            if (array_key_exists('defaults', $body)) $defaults = itops_m365_org_defaults_clean($body['defaults'], $fields);
            else { $defaults = $beforeDefaults; foreach (array_keys($defaults) as $dk) if (preg_match('/^ext(\d+)$/', $dk, $dm) && !array_filter($fields, fn($f) => $f['active'] && $f['slot'] === (int)$dm[1])) unset($defaults[$dk]); }
        } catch (InvalidArgumentException $e) { error_response($e->getMessage(), 400); }
        if (strlen(json_encode($fields, JSON_UNESCAPED_UNICODE)) > 48000) error_response('The fields and their choices are too long together. Shorten the lists of choices.', 400);
        $db->beginTransaction();
        try { itops_m365_setting_put($db, 'm365_custom_fields', $fields); itops_m365_setting_put($db, 'm365_org_defaults', $defaults); $db->commit(); }
        catch (Throwable $e) { $db->rollBack(); error_response('Could not save the custom fields: ' . $e->getMessage(), 500); }
        // Audit: what the fields and the company-wide values were, and what they are now.
        // BT41: the section and the help text are part of a field too (a change of either was not visible in the log), and
        // the text is cut well inside what the log column holds, so a long list of choices can never make the entry fail.
        $cut = fn(string $t): string => strlen($t) > 60000 ? mb_strcut($t, 0, 60000, 'UTF-8') . ' … (cut)' : $t;
        $secName = ['organization' => 'Organization', 'contact' => 'Contact information'];
        $name = fn($l) => $cut(implode('; ', array_map(fn($f) => 'attribute ' . $f['slot'] . ' = ' . $f['label'] . ' (under ' . ($secName[$f['section'] ?? ''] ?? (string)($f['section'] ?? '')) . ')'
            . ($f['kind'] === 'list' ? ' [' . implode(' | ', $f['options']) . ']' : '') . (($f['help'] ?? '') !== '' ? ' help: "' . $f['help'] . '"' : '') . ($f['active'] ? '' : ' (off)'), $l)) ?: '(none)');
        $lab = []; foreach (ItopsM365::profileFields() as $pf) $lab[$pf['key']] = $pf['label'];
        foreach (array_merge($before, $fields) as $f) $lab['ext' . $f['slot']] = $f['label'];
        $vals = function (array $d) use ($lab, $cut) { $o = []; foreach ($d as $k => $v) $o[] = ($lab[$k] ?? $k) . ' = ' . $v; return $o ? $cut(implode('; ', $o)) : '(none)'; };
        $auditErr = $auditChange('m365_custom_fields_save', 'Custom fields and company-wide values',
                     ['Custom fields' => $name($before), 'Company-wide values' => $vals($beforeDefaults)],
                     ['Custom fields' => $name($fields), 'Company-wide values' => $vals($defaults)]);
        json_response(['ok' => true, 'fields' => $fields, 'defaults' => (object)$defaults, 'audit_error' => $auditErr]);
    }

    // Change one account. Body: {id, changes: {jobTitle: "…", ext3: "…"}, manager_id?: "<id>" | "", manager_was?: "<id>"}.
    // Only values that differ from the account are sent, each kind in its own request, and every one reports by itself.
    if ($action === 'm365-user-update') {
        $cap('m365.users');
        if ($method !== 'POST') error_response('Method not allowed', 405);
        $id = $accountRef($body['id'] ?? '');
        if ($id === '') error_response('A user id is required.', 400);
        // The id goes into the address of the request to Microsoft: an object id, or a plain sign-in name. Nothing that
        // could cut the address short ("#", "?", "/"), or the account would be read without the values to compare with.
        if (!$isAccountRef($id)) error_response('That account id is not valid. Use the id of the account or its sign-in name.', 400);
        $in = $body['changes'] ?? [];
        if (!is_array($in)) error_response('A set of values is expected.', 400);
        // BT41: the manager is sent as text. Anything else (nothing, a list, yes/no) is a request that went wrong on its
        // way — it must never be read as "remove the manager".
        $hasMgr = array_key_exists('manager_id', $body);
        if ($hasMgr && !is_string($body['manager_id'])) error_response('The manager is sent as text: the id of the new manager, or an empty text to remove the manager. Nothing was changed.', 400);
        if (array_key_exists('manager_was', $body) && !is_string($body['manager_was'])) error_response('The manager to remove is sent as text. Nothing was changed.', 400);
        // BT41: fill_only names the fields the page filled from the company-wide values. Those are written only where the
        // account has nothing — the promise of "Fill empty fields": never replace a value an account already has, also when
        // somebody else filled the field in after the page was opened.
        $fillOnly = [];
        if (array_key_exists('fill_only', $body)) {
            if (!is_array($body['fill_only'])) error_response('The list of filled fields is not a list. Nothing was changed.', 400);
            // BT43: every entry is a field name. One that is not text used to be passed over, which made the request an
            // ordinary save ("no restriction") for that field.
            foreach ($body['fill_only'] as $fk) { if (!is_string($fk)) error_response('The list of filled fields holds something that is not a field name. Nothing was changed.', 400); $fillOnly[$fk] = true; }
        }
        $bud = $writeBudget(); ItopsGraphClient::setRequestTimeout((int)$bud['timeout'], false);
        try { $svc = ItopsM365::fromConfig(); $cur = $svc->getProfile($id); }
        catch (Throwable $e) { error_response($e->getMessage(), 400); }
        if ($cur['synced']) error_response('This account is kept in an on-premises directory and copied to Microsoft 365, so Microsoft does not accept changes to it here. Change it in that directory; the copy follows at the next synchronization.', 400);
        $custom = itops_m365_custom_fields($db);
        // BT43: only for fields that can be edited here (the built-in ones and the custom fields that are named and
        // switched on). Any other key stays in the request and is refused below as it always was: the answer must not say
        // whether a custom attribute nobody named holds a value ("kept" would have said so). "Has a value" is judged as
        // everywhere else on the server: spaces and invisible characters are not a value.
        $keptKeys = []; $lab0 = [];
        foreach (ItopsM365::profileFields() as $pf) $lab0[$pf['key']] = $pf['label'];
        foreach ($custom as $c0) if (!empty($c0['active'])) $lab0['ext' . (int)$c0['slot']] = (string)$c0['label'];
        $hasValue = function ($v): bool { try { return ItopsM365::tidyValue(is_scalar($v) ? (string)$v : '') !== ''; } catch (Throwable $e) { return true; } };
        foreach (array_keys($fillOnly) as $fk) { $fk = (string)$fk; if (isset($lab0[$fk]) && array_key_exists($fk, $in) && $hasValue($cur['profile'][$fk] ?? '')) { unset($in[$fk]); $keptKeys[] = $fk; } }
        try { $changes = ItopsM365::profileChanges($cur['profile'], $in, $custom, $cur['licensed']); }
        catch (InvalidArgumentException $e) { error_response($e->getMessage(), 400); }
        $kept = [];
        foreach ($keptKeys as $fk) $kept[] = $lab0[$fk];
        $who = $cur['upn'] ?: $cur['id'];
        // Manager: only when the request names one, and only when it differs from today's.
        $mgr = null; $mgrFrom = ''; $mgrTo = ''; $nowId = '';
        if ($hasMgr) {
            $want = trim($body['manager_id']);
            if ($want !== '' && !preg_match('/^[A-Za-z0-9-]{1,64}$/', $want)) error_response('That manager is not valid.', 400);
            if ($want !== '' && strcasecmp($want, $cur['id']) === 0) error_response('An account cannot be its own manager.', 400);
            // "Could not read the manager" is not "has no manager": stop before anything is changed.
            try { $now = $svc->getManager($cur['id']); }
            catch (Throwable $e) { error_response('Could not read the current manager from Microsoft 365, so nothing was changed. Try again. (' . $e->getMessage() . ')', 400); }
            $who2 = fn(?string $n, ?string $u) => trim((string)$n) !== '' ? trim((string)$n) . ((string)$u !== '' ? ' (' . $u . ')' : '') : (string)$u;
            $nowId = (string)($now['id'] ?? '');
            // BT41: a removal says WHICH manager it removes (manager_was = the manager the page showed). A page that never
            // saw the manager, or saw another one, cannot remove today's manager: that is how a manager was lost when a
            // page was filled once and the account was read again behind it.
            if ($want === '' && $nowId !== '') {
                $was = trim((string)($body['manager_was'] ?? ''));
                if ($was === '' || strcasecmp($was, $nowId) !== 0) {
                    $why = $was === '' ? 'the request does not say which manager to remove (the page is from an older build)' : 'the page showed another manager than the account has now';
                    $auditChange('m365_user_update_refused', $who, ['Manager' => $who2($now['name'], $now['upn'])], ['Manager' => 'not changed (wanted: empty) - ' . $why], false);
                    error_response('The manager was not removed and nothing else was changed: ' . $why . '. The manager is ' . $who2($now['name'], $now['upn']) . '. ' . ($was === '' ? 'Reload the page (Ctrl+F5), open the account again and remove the manager there.' : 'Look at the account as it is now and decide again.'), 409);
                }
            }
            if ($want !== $nowId) {
                $mgr = $want; $mgrFrom = $now ? $who2($now['name'], $now['upn']) : '';
                if ($want !== '') { try { $mp = $svc->getProfile($want); $mgr = $mp['id']; $mgrTo = $who2($mp['display_name'], $mp['upn']); } catch (Throwable $e) { error_response('That manager was not found in Microsoft 365.', 400); } }
            }
        }
        if (!$changes && $mgr === null) json_response(['ok' => true, 'changed' => 0, 'results' => [], 'kept' => $kept, 'message' => 'Nothing to change: the account already has these values.']);
        // Audit, always, part by part and as it happens: what changed (old → new) and what was refused, not known or not sent.
        $auditErr = null;
        $results = $svc->updateProfile($cur['id'], $changes, $mgr, function (array $r) use ($auditPart, $who, $changes, $mgrFrom, $mgrTo, &$auditErr) {
            $e = $auditPart($who, $r, $changes, $mgrFrom, $mgrTo); if ($e !== null) $auditErr = $e;
        }, $reqStart + $bud['send'], $mgr === '' ? $nowId : null);   // BT43: a removal is checked once more right before it is sent
        $okKeys = []; $failed = false; $touched = false;
        foreach ($results as $r) { if ($r['ok']) { $okKeys = array_merge($okKeys, $r['keys']); $touched = true; } else { $failed = true; if (!empty($r['uncertain'])) $touched = true; } }
        if ($touched) { $dirty(); $cacheDrop('m365-extattr-usage'); }
        json_response(['ok' => !$failed, 'changed' => count($okKeys), 'results' => $results, 'kept' => $kept, 'audit_error' => $auditErr]);
    }

    // ---- BT39: the same change on several accounts (m365.users) ----
    // Body: {op: "preview"|"apply", ids: [up to 10 per request], fields: {key: {mode: "set"|"fill"|"clear", value}},
    //        manager?: {mode: "set"|"clear", id}, confirm: "CHANGE <total>" (apply), batch, offset, total, v}.
    // The screen sends the selection in portions and adds the answers up. Every account is read, compared and logged
    // by itself, exactly as a single edit is; a portion also leaves one summary line in the Audit Log.
    // BT41: v = 2 says the screen understands `rest` (accounts this request did not start, for lack of time: send them again).
    if ($action === 'm365-users-bulk-update') {
        $cap('m365.users');
        if ($method !== 'POST') error_response('Method not allowed', 405);
        $op = is_string($body['op'] ?? null) ? $body['op'] : '';
        if ($op !== 'preview' && $op !== 'apply') error_response('Choose what to do: preview or apply.', 400);
        if (!is_array($body['ids'] ?? null)) error_response('Select at least one account.', 400);
        $ids = [];
        foreach ($body['ids'] as $v) { $one = $accountRef($v); if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $one)) error_response('That account id is not valid.', 400); if (!in_array($one, $ids, true)) $ids[] = $one; }
        if (!$ids) error_response('Select at least one account.', 400);
        if (count($ids) > 10) error_response('At most 10 accounts per request.', 400);
        $custom = itops_m365_custom_fields($db);
        $allowed = array_flip(ItopsM365::bulkKeys($custom));
        $fieldsIn = is_array($body['fields'] ?? null) ? $body['fields'] : [];
        $labelOf = function (string $k) use ($custom): string { foreach (ItopsM365::profileFields() as $pf) if ($pf['key'] === $k) return $pf['label']; foreach ($custom as $c) if ('ext' . $c['slot'] === $k) return (string)$c['label']; return $k; };
        $fields = []; $probe = [];
        foreach ($fieldsIn as $k => $f) {
            $k = (string)$k;
            if (!isset($allowed[$k])) error_response('"' . mb_substr($k, 0, 40) . '" cannot be changed for several accounts at once. It is different for each person, or it is not a field.', 400);
            $mode = is_array($f) && is_string($f['mode'] ?? null) ? $f['mode'] : '';
            if (!in_array($mode, ['set', 'fill', 'clear'], true)) error_response('Choose for every field: set, set only where empty, or clear.', 400);
            $val = '';
            if ($mode !== 'clear') {
                // BT41: a value is text or a number; and it is tidied here, so "a value" that is only invisible characters is no value.
                $raw = $f['value'] ?? '';
                if (!is_string($raw) && !is_int($raw) && !is_float($raw)) error_response($labelOf($k) . ': the value is sent as text.', 400);
                try { $val = ItopsM365::tidyValue($raw, $k === 'streetAddress', $labelOf($k)); } catch (InvalidArgumentException $e) { error_response($e->getMessage(), 400); }
                if ($val === '') error_response('A field that is set needs a value. To remove a value, choose "Clear".', 400);
                $probe[$k] = $val;
            }
            $fields[$k] = ['mode' => $mode, 'value' => $val];
        }
        // The value rules are the same for everybody: check them once, before any account is read.
        try { $norm = ItopsM365::profileChanges([], $probe, $custom, false); }
        catch (InvalidArgumentException $e) { error_response($e->getMessage(), 400); }
        foreach ($norm as $k => $c) $fields[$k]['value'] = $c['to'];
        $mgrMode = ''; $mgrId = ''; $mgrName = '';
        if (array_key_exists('manager', $body) && $body['manager'] !== null) {
            if (!is_array($body['manager'])) error_response('For the manager choose: set, or remove.', 400);
            $mgrMode = is_string($body['manager']['mode'] ?? null) ? $body['manager']['mode'] : '';
            if (!in_array($mgrMode, ['set', 'clear'], true)) error_response('For the manager choose: set, or remove.', 400);
            if ($mgrMode === 'set') {
                $mgrId = $accountRef($body['manager']['id'] ?? '');
                if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $mgrId)) error_response('Choose the manager.', 400);
            }
        }
        if (!$fields && $mgrMode === '') error_response('Choose at least one thing to change.', 400);
        $total = (int)($body['total'] ?? 0); $offset = (int)($body['offset'] ?? 0);
        if ($total < count($ids)) $total = count($ids);
        if ($offset < 0) $offset = 0;
        // BT41: the typed number is the number of accounts of the whole run, and a portion must lie inside the run.
        if ($op === 'apply') {
            if (!preg_match('/^CHANGE (\d{1,6})$/', trim(is_string($body['confirm'] ?? null) ? $body['confirm'] : ''), $cm) || (int)$cm[1] !== $total)
                error_response('Type CHANGE and the number of accounts to confirm, for example: CHANGE ' . $total, 400);
            if ($offset + count($ids) > $total) error_response('This portion does not fit the run that was confirmed (' . $total . ' accounts). Nothing was changed. Run the preview again.', 400);
        }
        $batch = preg_replace('/[^A-Za-z0-9-]/', '', is_string($body['batch'] ?? null) ? $body['batch'] : ''); if ($batch === '') $batch = date('Ymd-His');
        $batch = substr($batch, 0, 24);
        $canRest = (int)($body['v'] ?? 1) >= 2;
        $bud = $writeBudget(); ItopsGraphClient::setRequestTimeout((int)$bud['timeout'], false);
        try { $svc = ItopsM365::fromConfig(); } catch (Throwable $e) { error_response($e->getMessage(), 400); }
        $who2 = fn(?string $n, ?string $u) => trim((string)$n) !== '' ? trim((string)$n) . ((string)$u !== '' ? ' (' . $u . ')' : '') : (string)$u;
        if ($mgrMode === 'set') { try { $mp = $svc->getProfile($mgrId); $mgrId = $mp['id']; $mgrName = $who2($mp['display_name'], $mp['upn']); } catch (Throwable $e) { error_response('That manager was not found in Microsoft 365.', 400); } }

        $res = []; $n = ['done' => 0, 'skipped' => 0, 'failed' => 0, 'unknown' => 0, 'same' => 0, 'change' => 0, 'unread' => 0]; $stopped = null; $auditErr = null; $rest = [];
        foreach ($ids as $ix => $one) {
            $row = ['id' => $one, 'name' => '', 'upn' => '', 'status' => '', 'message' => '', 'changes' => []];
            if ($stopped !== null) { $row['status'] = 'skipped'; $row['message'] = 'Not attempted: Microsoft refused the account before it.'; $n['skipped']++; $res[] = $row; continue; }
            // BT41: the host ends a request at about a minute. Past the time allowed, no further account is started in this
            // request: its id goes back in `rest` and the screen sends it again. The first account is always done.
            if ($canRest && $ix > 0 && microtime(true) - $reqStart > $bud['start']) { $rest = array_slice($ids, $ix); break; }
            try { $cur = $svc->getProfile($one); }
            catch (Throwable $e) {
                // Not read is not "nothing to do": in a preview it makes the preview incomplete; in an apply nothing was changed.
                if ($op === 'apply') { $row['status'] = 'failed'; $row['message'] = 'Not changed: this account could not be read (' . $e->getMessage() . ').'; $n['failed']++; }
                else { $row['status'] = 'skipped'; $row['unread'] = true; $row['message'] = 'Could not read this account: ' . $e->getMessage(); $n['skipped']++; $n['unread']++; }
                $res[] = $row; continue;
            }
            $row['name'] = $cur['display_name'] ?: $cur['upn']; $row['upn'] = $cur['upn'];
            if ($cur['synced']) { $row['status'] = 'skipped'; $row['message'] = 'Kept in an on-premises directory: change it there.'; $n['skipped']++; $res[] = $row; continue; }
            $notes = [];
            // Per-account rules (for example: a usage location cannot be emptied) drop that one field, not the account.
            $input = ItopsM365::bulkInput($cur['profile'], $fields); $changes = [];
            foreach ($input as $k => $v) {
                try { $changes += ItopsM365::profileChanges($cur['profile'], [$k => $v], $custom, $cur['licensed']); }
                catch (InvalidArgumentException $e) { $notes[] = $e->getMessage(); }
            }
            $mgr = null; $mgrFrom = '';
            if ($mgrMode !== '') {
                if ($mgrMode === 'set' && strcasecmp($mgrId, $cur['id']) === 0) $notes[] = 'Manager left as it is: an account cannot be its own manager.';
                else {
                    try { $now = $svc->getManager($cur['id']); $want = $mgrMode === 'set' ? $mgrId : ''; if ($want !== (string)($now['id'] ?? '')) { $mgr = $want; $mgrFrom = $now ? $who2($now['name'], $now['upn']) : ''; } }
                    catch (Throwable $e) {
                        // BT41: the manager is part of what was asked. When it cannot be read, the account is not half done:
                        // the preview is incomplete, and an apply leaves the whole account as it is.
                        if ($op === 'apply') { $row['status'] = 'failed'; $row['message'] = 'Not changed: the current manager of this account could not be read, so nothing was sent for it.'; $n['failed']++; }
                        else { $row['status'] = 'skipped'; $row['unread'] = true; $row['message'] = 'The current manager of this account could not be read.'; $n['skipped']++; $n['unread']++; }
                        $res[] = $row; continue;
                    }
                }
            }
            $mgrTo = $mgr === null || $mgr === '' ? '' : $mgrName;
            foreach ($changes as $k => $c) $row['changes'][] = ['field' => $c['label'], 'from' => $c['from'], 'to' => $c['to']];
            if ($mgr !== null) $row['changes'][] = ['field' => 'Manager', 'from' => $mgrFrom, 'to' => $mgrTo];
            if (!$changes && $mgr === null) { $row['status'] = $op === 'apply' ? 'skipped' : 'same'; $row['message'] = trim('Nothing to change. ' . implode(' ', $notes)); $n[$row['status']]++; $res[] = $row; continue; }
            if ($op === 'preview') { $row['status'] = 'change'; $row['message'] = implode(' ', $notes); $n['change']++; $res[] = $row; continue; }

            $tag = ($cur['upn'] ?: $cur['id']) . ' (bulk edit ' . $batch . ')';
            $results = $svc->updateProfile($cur['id'], $changes, $mgr, function (array $r) use ($auditPart, $tag, $changes, $mgrFrom, $mgrTo, &$auditErr) {
                $e = $auditPart($tag, $r, $changes, $mgrFrom, $mgrTo); if ($e !== null) $auditErr = $e;
            }, $reqStart + $bud['send']);
            $saved = []; $refused = []; $unsure = []; $unsent = [];
            foreach ($results as $r) {
                if ($r['ok']) { $saved = array_merge($saved, $r['fields']); continue; }
                if (!empty($r['unsent'])) $unsent = array_merge($unsent, $r['fields']);
                elseif (!empty($r['uncertain'])) $unsure[] = $r['label'] . ' (' . implode(', ', $r['fields']) . '): ' . rtrim((string)$r['error'], '. ');
                else $refused[] = $r['label'] . ': ' . rtrim((string)$r['error'], '. ');
                if ($r['group'] === 'main' && in_array((int)($r['status'] ?? 0), [401, 403], true)) $stopped = 'Microsoft refused ' . ($cur['upn'] ?: $cur['id']) . ' (' . $r['error'] . '). The accounts after it were not attempted. If only this account is protected, take it out of the selection and run again.';
            }
            // done = every part saved; unknown = a part got no clear answer (it may or may not be saved); failed = a part
            // was refused or not sent, and nothing is in doubt.
            if (!$refused && !$unsure && !$unsent) { $row['status'] = 'done'; $row['message'] = trim(count($saved) . (count($saved) === 1 ? ' value changed. ' : ' values changed. ') . implode(' ', $notes)); }
            else {
                $row['status'] = $unsure ? 'unknown' : 'failed';
                $row['message'] = trim(($saved ? 'Saved: ' . implode(', ', $saved) . '. ' : '')
                    . ($unsure ? 'No clear answer, so it is not known whether this was saved: ' . implode(' | ', $unsure) . '. ' : '')
                    . ($refused ? 'Refused: ' . implode(' | ', $refused) . '. ' : '')
                    . ($unsent ? 'Not sent for lack of time, not changed: ' . implode(', ', $unsent) . '.' : ''));
            }
            $n[$row['status']]++; $res[] = $row;
        }
        if ($op === 'apply') {
            $what = [];
            foreach ($fields as $k => $f) $what[] = $labelOf($k) . ($f['mode'] === 'clear' ? ': clear' : ($f['mode'] === 'fill' ? ': "' . $f['value'] . '" where empty' : ': "' . $f['value'] . '"'));
            if ($mgrMode !== '') $what[] = 'Manager: ' . ($mgrMode === 'clear' ? 'remove' : '"' . $mgrName . '"');
            $sum = ['Instruction' => implode('; ', $what), 'Accounts' => implode(', ', array_map(fn($r) => $r['upn'] ?: $r['id'], $res)),
                    'Result' => $n['done'] . ' changed, ' . $n['skipped'] . ' skipped, ' . $n['failed'] . ' failed, ' . $n['unknown'] . ' not known'
                              . ($rest ? ', ' . count($rest) . ' not started in this request (sent again by the screen)' : '')];
            $old = ['Instruction' => '', 'Accounts' => '', 'Result' => ''];
            if ($stopped !== null) { $sum['Stopped'] = $stopped; $old['Stopped'] = ''; }
            $bad = $stopped !== null || $n['failed'] > 0 || $n['unknown'] > 0;
            $auditErr = $auditChange('m365_users_bulk_update', 'Bulk edit ' . $batch . ' - accounts ' . ($offset + 1) . ' to ' . ($offset + count($res)) . ' of ' . $total . ($stopped !== null ? ' - stopped' : ''),
                $old, $sum, !$bad) ?? $auditErr;
            if ($n['done'] > 0 || $n['failed'] > 0 || $n['unknown'] > 0) { $dirty(); $cacheDrop('m365-extattr-usage'); }
        }
        json_response(['ok' => true, 'op' => $op, 'batch' => $batch, 'results' => $res, 'stopped' => $stopped, 'rest' => $rest, 'audit_error' => $auditErr] + $n);
    }

    // What this app did to one account, newest first, from the Audit Log: who, when, which action and, for edits,
    // the old and the new value. Entries are found by the sign-in name or the object id, the two ways actions name an account.
    // BT41: this is a piece of the Audit Log (who did what, with old and new values), so it is for the people who change
    // accounts or who may read the Audit Log — no longer for everyone who can open Accounts.
    if ($action === 'm365-user-history') {
        // Review M365B-18: an Audit Log reader also needs Microsoft 365 · View (the entries are Microsoft 365 entries)
        if (!$can('m365.users') && !(itops_can($currentUser, 'audit', 'view') && itops_can($currentUser, 'm365', 'view'))) error_response('The change history is for people who edit accounts, or who can read the Audit Log and Microsoft 365.', 403);
        $uid = is_string($_GET['id'] ?? null) ? trim($_GET['id']) : ''; $upn = is_string($_GET['upn'] ?? null) ? trim($_GET['upn']) : '';
        // BT42: the history of an ACCOUNT. The id has the shape of a directory id and the sign-in name the shape of one;
        // anything else ("Bulk", "Custom fields…", a sentence) is left out, so the name of another kind of entry cannot be
        // asked for here. Entries about groups, devices, alerts, the custom-field settings and bulk runs are not an
        // account's history either, even when their name is an id.
        if (strlen($uid) < 3 || !preg_match('/^[A-Za-z0-9-]{3,64}$/', $uid)) $uid = '';
        if (!preg_match('/^[A-Za-z0-9._%+\'-]{1,64}@[A-Za-z0-9.-]{1,190}$/', $upn)) $upn = '';
        if ($uid === '' && $upn === '') error_response('A user id is required.', 400);
        $w = []; $bind = [];
        $esc = fn($v) => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);
        // The name is the account alone, or the account followed by a space and details ("bea@x.org (2h, one-time)").
        foreach (array_filter([$uid, $upn], 'strlen') as $v) { $w[] = '(entity_name = ? OR entity_name LIKE ?)'; $bind[] = $v; $bind[] = $esc($v) . ' %'; }
        $q = $db->prepare("SELECT id, user_name, action, entity_name, old_value, new_value, created_at FROM itops_audit_log
                           WHERE entity_type = 'm365'
                             AND action NOT LIKE 'm365\\_group\\_%' AND action NOT LIKE 'm365\\_device\\_%' AND action NOT LIKE 'm365\\_alert\\_%'
                             AND action NOT IN ('m365_users_bulk_update','m365_custom_fields_save','m365_diagnose')
                             AND (" . implode(' OR ', $w) . ") ORDER BY id DESC LIMIT 60");
        $q->execute($bind);
        $rows = [];
        foreach ($q->fetchAll() as $r) {
            $ov = json_decode((string)$r['old_value'], true); $nv = json_decode((string)$r['new_value'], true);
            $ch = [];
            if (is_array($ov) && is_array($nv) && strpos((string)$r['action'], 'm365_user_update') === 0)
                foreach ($nv as $k => $v) $ch[] = ['field' => (string)$k, 'from' => (string)($ov[$k] ?? ''), 'to' => is_scalar($v) ? (string)$v : ''];
            $failed = is_array($nv) && (($nv['result'] ?? '') === 'error') || $r['action'] === 'm365_user_update_refused';
            $rows[] = ['id' => (int)$r['id'], 'at' => $r['created_at'], 'by' => $r['user_name'], 'action' => $r['action'], 'ok' => !$failed,
                       'error' => is_array($nv) && !$ch ? (string)($nv['error'] ?? '') : '', 'changes' => $ch];
        }
        json_response(['history' => $rows]);
    }

    // ---- credentials (m365.credentials) ----
    if ($action === 'm365-user-reset-password') {
        $cap('m365.credentials');
        $body = $body;
        $id = trim((string)($body['id'] ?? ''));
        if ($id === '') error_response('A user id is required.', 400);
        if ($isSelf($id)) error_response('This is your own account: change your password in Microsoft 365.', 400);
        $guardT($id, 'reset its password', true);   // BT88
        $force = array_key_exists('force_change', $body) ? (bool)$body['force_change'] : true;
        if (!$force && ($currentUser['role_slug'] ?? '') !== 'super_admin') $force = true;   // BT88: only a Super Admin sets a password the person keeps
        $pwd = (string)($body['password'] ?? '');
        if ($pwd === '') $pwd = ItopsM365::generatePassword();
        $ok = true; $err = null;
        try { ItopsM365::fromConfig()->resetPassword($id, $pwd, $force); }
        catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
        $audit([
            'action' => 'm365.user.reset_password',
            'system' => 'm365', 'object_type' => 'user', 'record_id' => $id,
            'result' => $ok ? 'success' : 'error', 'error' => $err,
            'detail' => ['force_change' => $force], // never log the password itself
        ]);
        if (!$ok) error_response($err, 400);
        json_response(['ok' => true, 'password' => $pwd, 'force_change' => $force]);
    }

    if ($action === 'm365-user-reset-mfa') {
        $cap('m365.credentials');
        $body = $body;
        $id = trim((string)($body['id'] ?? ''));
        if ($id === '') error_response('A user id is required.', 400);
        if ($isSelf($id)) error_response('This is your own account — ask another administrator.', 400);
        if (strtoupper(trim((string)($body['confirm'] ?? ''))) !== 'RESET') { audit_log('m365_admin_target_refused', 'm365', null, $id, null, ['wanted' => 'reset MFA', 'reason' => 'not confirmed']); error_response('Type RESET to confirm the MFA reset.', 400); }   // review UI1-4
        $guardT($id, 'reset its MFA');   // BT88
        $ok = true; $err = null; $result = ['removed' => 0, 'errors' => []];
        try { $result = ItopsM365::fromConfig()->resetMfa($id); }
        catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
        $audit([
            'action' => 'm365.user.reset_mfa',
            'system' => 'm365', 'object_type' => 'user', 'record_id' => $id,
            'result' => $ok ? 'success' : 'error', 'error' => $err,
            'detail' => ['removed' => $result['removed'] ?? 0],
        ]);
        if (!$ok) error_response($err, 400);
        json_response(['ok' => true] + $result);
    }

    if ($action === 'm365-user-revoke-sessions') {
        $cap('m365.credentials');
        $body = $body;
        $id = trim((string)($body['id'] ?? ''));
        if ($id === '') error_response('A user id is required.', 400);
        $guardT($id, 'sign it out everywhere');   // BT88
        $ok = true; $err = null;
        try { ItopsM365::fromConfig()->revokeSessions($id); }
        catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
        $audit([
            'action' => 'm365.user.revoke_sessions',
            'system' => 'm365', 'object_type' => 'user', 'record_id' => $id,
            'result' => $ok ? 'success' : 'error', 'error' => $err,
        ]);
        if (!$ok) error_response($err, 400);
        json_response(['ok' => true]);
    }

    // ---- lifecycle: onboard / offboard (m365.users) ----
    if ($action === 'm365-domains') {
        $cap('m365.view');
        // BT167: the organization's domains from Microsoft, plus the domains the accounts actually use (from the account list
        // kept by the People page, up to a week old) — Microsoft's list needs Domain.Read.All; the accounts always tell.
        $ds = []; $err = null; try { $ds = ItopsM365::fromConfig()->domains(); } catch (Throwable $e) { $err = $e->getMessage(); }
        $seen = []; $j = $cacheGet('m365-grid', 7 * 86400);
        foreach ((array)($j['payload'] ?? []) as $u) { $upn = strtolower((string)($u['upn'] ?? '')); if ($upn === '' || strpos($upn, '#ext#') !== false || strpos($upn, '@') === false) continue;
            $dm = substr($upn, strrpos($upn, '@') + 1); $seen[$dm] = ($seen[$dm] ?? 0) + 1; }
        $have = []; foreach ($ds as &$d) { $d['n'] = $seen[strtolower($d['id'])] ?? null; $have[strtolower($d['id'])] = 1; } unset($d);
        arsort($seen); foreach ($seen as $dm => $n) if (!isset($have[$dm])) $ds[] = ['id' => $dm, 'isDefault' => false, 'n' => $n, 'from_accounts' => true];
        if (!$ds && $err !== null) error_response($err, 400);
        $od = ''; try { $q = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $q->execute(['m365_people_domain']); $od = (string)$q->fetchColumn(); } catch (Throwable $e) {}
        json_response(['domains' => $ds, 'org_default' => $od, 'note' => $err !== null ? 'Microsoft did not list the domains (' . mb_substr($err, 0, 140) . '); the list comes from the accounts.' : null, 'can_set' => $can('admin.connections')]);
    }
    // BT167: the domain the People list opens on, for everyone (Settings → Company systems → Microsoft 365); '' = Microsoft's default domain, '*' = all domains
    if ($method === 'POST' && $action === 'm365-default-domain') {
        $cap('admin.connections');
        $v = trim((string)($body['domain'] ?? ''));
        if ($v !== '' && $v !== '*' && !preg_match('/^[A-Za-z0-9.\-]{1,190}$/', $v)) error_response('Choose a domain from the list. Nothing was saved.', 400);
        $was = ''; try { $q = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $q->execute(['m365_people_domain']); $was = (string)$q->fetchColumn(); } catch (Throwable $e) {}
        $db->prepare('INSERT INTO itops_settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)')->execute(['m365_people_domain', strtolower($v)]);
        $lab = fn($x) => $x === '' ? 'Microsoft 365 default domain' : ($x === '*' ? 'All domains' : '@' . $x);
        audit_log('m365_default_domain', 'm365', null, 'People: the domain shown first', ['Domain' => $lab($was)], ['Domain' => $lab(strtolower($v))]);
        json_response(['ok' => true, 'org_default' => strtolower($v)]);
    }

    if ($action === 'm365-user-create') {
        $cap('m365.users');
        $body = $body;
        // BT47: where the first password comes from. 'template' = the default password saved on the onboarding template
        // (it never travels to the browser before this point); otherwise the one typed, or a generated one.
        $pwdFrom = 'generated'; $tplName = '';
        if (($body['password_from'] ?? '') === 'template') {
            $tq = null;
            try { $tq = $db->prepare('SELECT name, m365_json FROM itops_m365_templates WHERE id=?'); $tq->execute([(int)($body['template_id'] ?? 0)]); $tq = $tq->fetch(); } catch (Throwable $e) { $tq = null; }
            $tm = $tq ? (json_decode((string)$tq['m365_json'], true) ?: []) : [];
            if (!$tq || empty($tm['password_enc'])) error_response('That template has no default password any more. Choose another way to set the password. Nothing was created.', 409);
            require_once __DIR__ . '/../lib/Secrets.php';
            try { $pwd = itops_secret_unwrap((string)$tm['password_enc']); } catch (Throwable $e) { $pwd = ''; }
            if ($pwd === '') error_response('The default password of that template could not be read (the vault key of this installation changed?). Save it again on the template, or choose another way to set the password. Nothing was created.', 409);
            $pwdFrom = 'template'; $tplName = (string)$tq['name'];
            $body['forceChange'] = true;   // a password that several people start with is always changed at first sign-in
        } else {
            $pwd = is_string($body['password'] ?? null) ? $body['password'] : '';
            if ($pwd !== '') $pwdFrom = 'typed'; else $pwd = ItopsM365::generatePassword();
        }
        $body['password'] = $pwd;
        if (($currentUser['role_slug'] ?? '') !== 'super_admin') $body['forceChange'] = true;   // review M365B-17: only a Super Admin may skip "change at first sign-in"
        unset($body['password_from'], $body['template_id']);
        // BT47: custom fields sent as ext1 … ext15. Only the organization's active fields, each value checked like on the edit page.
        $body['custom'] = []; $customNamed = [];
        foreach ($customUserFields() as $cf) {
            $ck = $cf['key']; if (!array_key_exists($ck, $body)) continue;
            try { $cv = ItopsM365::tidyValue($body[$ck], false, $cf['label']); } catch (InvalidArgumentException $e) { error_response($e->getMessage() . ' Nothing was created.', 400); }
            if (mb_strlen($cv) > 1024) error_response($cf['label'] . ' is too long (1,024 characters at most). Nothing was created.', 400);
            if ($cv !== '' && $cf['options'] && !in_array($cv, $cf['options'], true)) error_response($cf['label'] . ': "' . mb_substr($cv, 0, 60) . '" is not one of its choices. Nothing was created.', 400);
            if ($cv !== '') { $body['custom'][(int)substr($ck, 3)] = $cv; $customNamed[$cf['label']] = $cv; }
        }
        foreach (array_keys($body) as $bk) if (is_string($bk) && preg_match('/^ext\d+$/', $bk)) unset($body[$bk]);
        $svc = ItopsM365::fromConfig();
        $lic0 = array_values(array_filter((array)($body['licenses'] ?? [])));
        try { $usage = ItopsM365::usageLocation($body['usageLocation'] ?? ''); } catch (Throwable $e) { error_response($e->getMessage() . ' Nothing was created.', 400); }
        if ($lic0 && $usage === '') error_response('A license was chosen but no usage location: Microsoft cannot license an account without one. Enter the two-letter country (for example US). Nothing was created.', 400);
        try {
            $created = $svc->createUser($body);
        } catch (Throwable $e) {
            $audit([
                'action' => 'm365.user.create', 'system' => 'm365', 'object_type' => 'user',
                'record_id' => (string)($body['userPrincipalName'] ?? ''), 
                'result' => 'error', 'error' => $e->getMessage(),
            ]);
            error_response($e->getMessage(), 400);
        }
        // Best-effort follow-ups (manager + licenses); report any that fail without losing the account.
        $warnings = [];
        $mgr = trim((string)($body['managerId'] ?? ''));
        if ($mgr !== '' && !preg_match('/^[A-Za-z0-9\-]{1,64}$/', $mgr)) error_response('That manager is not valid.', 400);   // review M365B-15
        if ($mgr !== '') {
            try { itops_graph_retry(fn() => $svc->setManager($created['id'], $mgr)); }   // ITOps: new accounts take a few seconds to replicate
            catch (Throwable $e) { $warnings[] = 'Manager not set: ' . $e->getMessage(); }
        }
        $lic = $lic0;
        if ($lic) {
            // BT52: a brand-new account can still look location-less to the licensing service for a few seconds after it
            // was created with one. Then set the usage location again and try once more, waiting a little each time.
            $tries = 0;
            while (true) {
                try { itops_graph_retry(fn() => $svc->assignLicense($created['id'], $lic, [])); break; }
                catch (Throwable $e) {
                    if (stripos($e->getMessage(), 'usage location') !== false && $tries < 3) {
                        $tries++;
                        try { $svc->setUsageLocation($created['id'], $usage); } catch (Throwable $e2) {}
                        sleep([2, 4, 8][$tries - 1]);
                        continue;
                    }
                    $warnings[] = 'License not assigned: ' . $e->getMessage() . ' The account was created; assign the license from Accounts once its usage location is set.';
                    break;
                }
            }
        }
        // BT53: the grid row of the new account, so the Accounts list shows it at once (see $newAccGet)
        $row = null;
        try {
            $skuMap = []; try { $skuMap = $svc->skus()['map'] ?? []; } catch (Throwable $e) {}
            $row = itops_graph_retry(fn() => $svc->gridUser($created['id'], $skuMap));
        } catch (Throwable $e) { $row = null; }
        if (!$row || ($row['id'] ?? '') === '')   // not readable yet: what is known from the creation
            $row = ItopsM365::gridRow(['id' => $created['id'], 'displayName' => $created['displayName'], 'userPrincipalName' => $created['userPrincipalName'],
                'accountEnabled' => true, 'userType' => 'Member', 'createdDateTime' => gmdate('c'), 'usageLocation' => $usage]);
        if ($row['id'] !== '') { $nl = array_values(array_filter($newAccGet(), fn($r) => ($r['id'] ?? '') !== $row['id'])); $row['_at'] = time(); $nl[] = $row; $newAccPut($nl); }
        $audit([
            'action' => 'm365.user.create', 'system' => 'm365', 'object_type' => 'user',
            'record_id' => $created['id'], 'result' => 'success',
            'detail' => ['upn' => $created['userPrincipalName'], 'licenses' => $lic, 'manager' => $mgr !== '',
                         'password' => $pwdFrom === 'template' ? 'default of the template "' . $tplName . '"' : $pwdFrom,   // where it came from, never the password
                         'custom_fields' => $customNamed],   // by the organization's own name for each, with the value set
        ]);
        $dirty();
        json_response([
            // Review M365B-17: never to an API key; the template's shared default only to someone who may edit onboarding templates
            'ok' => true, 'user' => $created, 'password' => (!empty($currentUser['is_api_key']) || ($pwdFrom === 'template' && !itops_can($currentUser, 'onboarding', 'edit'))) ? null : $pwd, 'password_from' => $pwdFrom,
            'force_change' => array_key_exists('forceChange', $body) ? (bool)$body['forceChange'] : true,
            'warnings' => $warnings,
        ]);
    }

    if ($action === 'm365-user-offboard') {
        $cap('m365.users');
        $body = $body;
        $id = trim((string)($body['id'] ?? ''));
        if ($id === '') error_response('A user id is required.', 400);
        if ($isSelf($id)) error_response('You cannot offboard your own account.', 400);
        $guardT($id, 'offboard it');   // BT88
        $opts = [
            'revokeSessions'  => (bool)($body['revokeSessions'] ?? true),
            'removeLicenses'  => (bool)($body['removeLicenses'] ?? true),
            'removeFromGroups' => (bool)($body['removeFromGroups'] ?? false),
        ];
        try { $result = ItopsM365::fromConfig()->offboard($id, $opts); }
        catch (Throwable $e) { error_response($e->getMessage(), 400); }
        $allOk = !array_filter($result['steps'], fn($s) => !$s['ok']);
        $result['all_ok'] = $allOk;   // BT88: a step that failed is said, not hidden
        $audit([
            'action' => 'm365.user.offboard', 'system' => 'm365', 'object_type' => 'user',
            'record_id' => $id, 
            'result' => $allOk ? 'success' : 'error',
            'detail' => ['steps' => $result['steps'], 'options' => $opts],
        ]);
        $dirty();
        json_response(['ok' => true] + $result);
    }

    // ---- groups: read with m365.view, change with m365.groups ----
    if ($action === 'm365-groups') {
        $cap('m365.view');
        try { json_response(['groups' => ItopsM365::fromConfig()->listGroups((string)($_GET['q'] ?? ''))]); }
        catch (Throwable $e) { error_response($e->getMessage(), 400); }
    }

    // The Entra user properties an onboarding template can pre-populate. Source
    // of truth for the template field picker; matches what ItopsM365::createUser sets.
    if ($action === 'm365-user-fields') {
        $cap('m365.view');
        json_response(['fields' => array_merge(ItopsM365::settableUserFields(), $customUserFields())]);
    }

    if ($action === 'm365-user-add-group' || $action === 'm365-user-remove-group') {
        $cap('m365.groups');
        $body = $body;
        $id = trim((string)($body['id'] ?? ''));
        $gid = trim((string)($body['group_id'] ?? ''));
        if ($id === '' || $gid === '') error_response('A user id and group id are required.', 400);
        $add = $action === 'm365-user-add-group';
        $ok = true; $err = null;
        try {   // BT88: a group that can hold admin roles is changed in the Entra admin center (a role in disguise)
            $gd = ItopsM365::fromConfig()->groupDetail($gid);
            if (!empty($gd['group']['role_assignable'])) { if (function_exists('audit_log')) audit_log('m365_admin_target_refused', 'm365', null, $gid, null, ['wanted' => $add ? 'add to a role-assignable group' : 'remove from a role-assignable group', 'user' => $id]); error_response('This group can hold Entra admin roles: manage it in the Entra admin center.', 403); }
            // BT124 (review M365B-8): the same rule as the Groups page — a dynamic, synced or distribution group is never changed here
            if (empty($gd['group']['manageable'])) { audit_log('m365_refused', 'm365', null, $gid, null, ['wanted' => $add ? 'add to a group' : 'remove from a group', 'reason' => (string)($gd['group']['locked'] ?? 'not changed from here')]); error_response((string)($gd['group']['locked'] ?? 'This group is not changed from here.'), 400); }
        } catch (InvalidArgumentException $e) { audit_log('m365_refused', 'm365', null, mb_substr($gid, 0, 100), null, ['reason' => $e->getMessage()]); error_response($e->getMessage(), 400); }
          catch (Throwable $e) { if ($e instanceof RuntimeException || $e instanceof ItopsGraphException) error_response('Could not read the group: ' . $e->getMessage(), 400); throw $e; }
        try {
            $svc = ItopsM365::fromConfig();
            $op = fn() => $add ? $svc->addToGroup($id, $gid) : $svc->removeFromGroup($id, $gid);
            !empty($body['fresh']) ? itops_graph_retry($op) : $op();   // ITOps: the Onboard wizard adds groups seconds after creating the account
        } catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
        $audit([
            'action' => $add ? 'm365.user.add_group' : 'm365.user.remove_group',
            'system' => 'm365', 'object_type' => 'user', 'record_id' => $id,
            'result' => $ok ? 'success' : 'error', 'error' => $err,
            'detail' => ['group_id' => $gid],
        ]);
        if (!$ok) error_response($err, 400);
        $cacheDrop('m365-groups-overview');
        json_response(['ok' => true]);
    }

    // ---- licenses: change with m365.licenses ----
    if ($action === 'm365-user-assign-license' || $action === 'm365-user-remove-license') {
        $cap('m365.licenses');
        $body = $body;
        $id = trim((string)($body['id'] ?? ''));
        $sku = trim((string)($body['sku_id'] ?? ''));
        if ($id === '' || $sku === '') error_response('A user id and SKU id are required.', 400);
        $add = $action === 'm365-user-assign-license';
        $ok = true; $err = null;
        try {
            $svc = ItopsM365::fromConfig();
            $add ? $svc->assignLicense($id, [$sku], []) : $svc->assignLicense($id, [], [$sku]);
        } catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
        $audit([
            'action' => $add ? 'm365.user.assign_license' : 'm365.user.remove_license',
            'system' => 'm365', 'object_type' => 'user', 'record_id' => $id,
            'result' => $ok ? 'success' : 'error', 'error' => $err,
            'detail' => ['sku_id' => $sku],
        ]);
        if (!$ok) error_response($err, 400);
        $dirty();
        json_response(['ok' => true]);
    }

    // ---- bulk license reclaim: remove a specific SKU, or ALL licenses, from many users ----
    if ($action === 'm365-bulk-remove-licenses') {
        $cap('m365.licenses');
        $body = $body;
        $ids = array_values(array_filter(array_map('strval', (array)($body['ids'] ?? [])), fn($x) => trim($x) !== ''));
        $sku = trim((string)($body['sku_id'] ?? '')); // empty => remove ALL licenses per user
        if (!$ids) error_response('Select at least one user.', 400);
        if (count($ids) > 500) error_response('Up to 500 people at a time.', 400);
        // BT124 (review M365B-7): every id is an account id (nothing else reaches the Graph path), the SKU is a GUID, and the
        // confirmation names how many accounts
        foreach ($ids as $x) if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $x)) { audit_log('m365_refused', 'm365', null, 'Bulk license removal', null, ['Why' => 'not an account id: ' . mb_substr($x, 0, 80)]); error_response('One of the selected entries is not an account id. Nothing was changed.', 400); }
        if ($sku !== '' && !preg_match('/^[0-9a-fA-F-]{36}$/', $sku)) error_response('That is not a license id.', 400);
        if (($body['confirm'] ?? '') !== 'REMOVE ' . count($ids)) { audit_log('m365_refused', 'm365', null, 'Bulk license removal', null, ['Why' => 'confirmation not typed', 'Accounts' => count($ids)]); error_response('Type REMOVE ' . count($ids) . ' to confirm removing licenses from ' . count($ids) . ' account' . (count($ids) === 1 ? '' : 's') . '.', 400); }
        @set_time_limit(600);
        $svc = ItopsM365::fromConfig();
        $results = []; $okCount = 0; $removedSeats = 0;
        foreach ($ids as $id) {
            $ok = true; $err = null; $n = 0;
            try {
                if ($sku !== '') { $svc->assignLicense($id, [], [$sku]); $n = 1; }
                else { $n = $svc->removeAllLicenses($id); }
            } catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
            if ($ok) { $okCount++; $removedSeats += $n; }
            $results[] = ['id' => $id, 'ok' => $ok, 'removed' => $n, 'error' => $err];
            $audit([
                'action' => 'm365.user.remove_license', 'system' => 'm365', 'object_type' => 'user',
                'record_id' => $id, 'result' => $ok ? 'success' : 'error',
                'error' => $err, 'detail' => ['sku_id' => $sku !== '' ? $sku : 'ALL', 'bulk' => true],
            ]);
        }
        $dirty();
        json_response(['ok' => true, 'users' => $okCount, 'removed_seats' => $removedSeats, 'results' => $results]);
    }

    // ---- users holding a given SKU (served from the shared cached directory) ----
    if ($action === 'm365-sku-users') {
        $cap('m365.view');
        $sku = trim((string)($_GET['sku_id'] ?? ''));
        if ($sku === '') error_response('A sku_id is required.', 400);
        $dir = null; $cached = false;
        $cc = $cacheGet('m365-directory', 600);
        if ($cc) { $dir = $cc['payload']; $cached = true; }
        if ($dir === null) {
            try { $dir = ItopsM365::fromConfig()->directoryForRecon(); $cachePut('m365-directory', $dir); }
            catch (Throwable $e) { error_response($e->getMessage(), 400); }
        }
        $users = [];
        foreach (($dir['users'] ?? []) as $u) {
            if (in_array($sku, $u['license_skus'] ?? [], true)) {
                $users[] = [
                    'id' => $u['id'], 'display_name' => $u['display_name'], 'upn' => $u['upn'],
                    'enabled' => $u['enabled'], 'last_sign_in' => $u['last_sign_in'], 'license_count' => $u['license_count'],
                ];
            }
        }
        usort($users, fn($a, $b) => strcasecmp(($a['display_name'] ?: $a['upn']), ($b['display_name'] ?: $b['upn'])));
        json_response(['sku_id' => $sku, 'users' => $users, 'count' => count($users), 'cached' => $cached]);
    }

    // ---- Intune managed devices + compliance summary (read-only) ----
    if ($action === 'm365-intune-devices') {
        $cap('m365.view');
        $refresh = !empty($_GET['refresh']);
        $cc = $refresh ? null : $cacheGet('m365-intune-devices', 600);
        if ($cc) { $p = $cc['payload']; $p['cached'] = true; $p['cached_at'] = date('c', $cc['ts']); json_response($p); }
        $payload = itops_m365_build('m365-intune-devices');   // BT54: the same read the background refresh makes (lib/M365Background.php)
        if (!empty($payload['_nocache'])) { unset($payload['_nocache']); json_response($payload); }
        $cachePut('m365-intune-devices', $payload);
        json_response($payload);
    }

    // ---- Defender: secure score + recent security alerts (read-only) ----
    if ($action === 'm365-defender') {
        $cap('m365.view');
        $refresh = !empty($_GET['refresh']);
        $cc = $refresh ? null : $cacheGet('m365-defender', 600);
        if ($cc) { $p = $cc['payload']; $p['cached'] = true; $p['cached_at'] = date('c', $cc['ts']); json_response($p); }
        $payload = itops_m365_build('m365-defender');   // BT54: the same read the background refresh makes (lib/M365Background.php)
        if (!empty($payload['_nocache'])) { unset($payload['_nocache']); json_response($payload); }
        $cachePut('m365-defender', $payload);
        json_response($payload);
    }

    // ---- MFA / auth-method registration (identity security) ----
    if ($action === 'm365-mfa') {
        $cap('m365.view');
        $refresh = !empty($_GET['refresh']);
        $cc = $refresh ? null : $cacheGet('m365-mfa', 600);
        if ($cc) { $p = $cc['payload']; $p['cached'] = true; $p['cached_at'] = date('c', $cc['ts']); json_response($p); }
        $payload = itops_m365_build('m365-mfa');   // BT54: the same read the background refresh makes (lib/M365Background.php)
        if (!empty($payload['_nocache'])) { unset($payload['_nocache']); json_response($payload); }
        $cachePut('m365-mfa', $payload);
        json_response($payload);
    }

    // ---- Service health + message center (read-only) ----
    if ($action === 'm365-service-health') {
        $cap('m365.view');
        $refresh = !empty($_GET['refresh']);
        $cc = $refresh ? null : $cacheGet('m365-service-health', 600);
        if ($cc) { $p = $cc['payload']; $p['cached'] = true; $p['cached_at'] = date('c', $cc['ts']); json_response($p); }
        $payload = itops_m365_build('m365-service-health');   // BT54: the same read the background refresh makes (lib/M365Background.php)
        if (!empty($payload['_nocache'])) { unset($payload['_nocache']); json_response($payload); }
        $cachePut('m365-service-health', $payload);
        json_response($payload);
    }

    // ---- Exchange mailbox usage (read-only) ----
    if ($action === 'm365-exchange') {
        $cap('m365.view');
        $refresh = !empty($_GET['refresh']);
        $cc = $refresh ? null : $cacheGet('m365-exchange', 600);
        if ($cc) { $p = $cc['payload']; $p['cached'] = true; $p['cached_at'] = date('c', $cc['ts']); json_response($p); }
        $payload = itops_m365_build('m365-exchange');   // BT54: the same read the background refresh makes (lib/M365Background.php)
        if (!empty($payload['_nocache'])) { unset($payload['_nocache']); json_response($payload); }
        $cachePut('m365-exchange', $payload);
        json_response($payload);
    }

    // ---- Teams activity (read-only) ----
    if ($action === 'm365-teams') {
        $cap('m365.view');
        $refresh = !empty($_GET['refresh']);
        $cc = $refresh ? null : $cacheGet('m365-teams', 600);
        if ($cc) { $p = $cc['payload']; $p['cached'] = true; $p['cached_at'] = date('c', $cc['ts']); json_response($p); }
        $payload = itops_m365_build('m365-teams');   // BT54: the same read the background refresh makes (lib/M365Background.php)
        if (!empty($payload['_nocache'])) { unset($payload['_nocache']); json_response($payload); }
        $cachePut('m365-teams', $payload);
        json_response($payload);
    }

    // ---- OneDrive + SharePoint storage (read-only) ----
    if ($action === 'm365-storage') {
        $cap('m365.view');
        $refresh = !empty($_GET['refresh']);
        $cc = $refresh ? null : $cacheGet('m365-storage', 600);
        if ($cc) { $p = $cc['payload']; $p['cached'] = true; $p['cached_at'] = date('c', $cc['ts']); json_response($p); }
        $payload = itops_m365_build('m365-storage');   // BT54: the same read the background refresh makes (lib/M365Background.php)
        if (!empty($payload['_nocache'])) { unset($payload['_nocache']); json_response($payload); }
        $cachePut('m365-storage', $payload);
        json_response($payload);
    }

    // ---- Microsoft 365 Copilot usage (read-only) ----
    if ($action === 'm365-copilot') {
        $cap('m365.view');
        $refresh = !empty($_GET['refresh']);
        $cc = $refresh ? null : $cacheGet('m365-copilot', 600);
        if ($cc) { $p = $cc['payload']; $p['cached'] = true; $p['cached_at'] = date('c', $cc['ts']); json_response($p); }
        $payload = itops_m365_build('m365-copilot');   // BT54: the same read the background refresh makes (lib/M365Background.php)
        if (!empty($payload['_nocache'])) { unset($payload['_nocache']); json_response($payload); }
        $cachePut('m365-copilot', $payload);
        json_response($payload);
    }

    // ---- Entra directory recommendations (read-only) ----
    if ($action === 'm365-recommendations') {
        $cap('m365.view');
        $refresh = !empty($_GET['refresh']);
        $cc = $refresh ? null : $cacheGet('m365-recommendations', 600);
        if ($cc) { $p = $cc['payload']; $p['cached'] = true; $p['cached_at'] = date('c', $cc['ts']); json_response($p); }
        $payload = itops_m365_build('m365-recommendations');   // BT54: the same read the background refresh makes (lib/M365Background.php)
        if (!empty($payload['_nocache'])) { unset($payload['_nocache']); json_response($payload); }
        $cachePut('m365-recommendations', $payload);
        json_response($payload);
    }

    // ---- App registrations: secret/cert expiry (read-only) ----
    if ($action === 'm365-app-secrets') {
        $cap('m365.view');
        $refresh = !empty($_GET['refresh']);
        $cc = $refresh ? null : $cacheGet('m365-app-secrets', 600);
        if ($cc) { $p = $cc['payload']; $p['cached'] = true; $p['cached_at'] = date('c', $cc['ts']); json_response($p); }
        $payload = itops_m365_build('m365-app-secrets');   // BT54: the same read the background refresh makes (lib/M365Background.php)
        if (!empty($payload['_nocache'])) { unset($payload['_nocache']); json_response($payload); }
        $cachePut('m365-app-secrets', $payload);
        json_response($payload);
    }

    // ---- directory roles: read with m365.view, change with m365.roles ----
    if ($action === 'm365-roles') {
        $cap('m365.view');
        try { json_response(['roles' => ItopsM365::fromConfig()->listRoleDefinitions()]); }
        catch (Throwable $e) { error_response($e->getMessage(), 400); }
    }

    if ($action === 'm365-user-roles') {
        $cap('m365.view');
        $id = trim((string)($_GET['id'] ?? ''));
        if ($id === '') error_response('A user id is required.', 400);
        try { json_response(['roles' => ItopsM365::fromConfig()->userRoles($id)]); }
        catch (Throwable $e) { error_response($e->getMessage(), 400); }
    }

    if ($action === 'm365-user-assign-role') {
        $cap('m365.roles');
        $body = $body;
        $id = trim((string)($body['id'] ?? ''));
        $roleId = trim((string)($body['role_id'] ?? ''));
        if ($roleId !== '' && !preg_match('/^[A-Za-z0-9\-]{1,64}$/', $roleId)) error_response('That role is not valid.', 400);   // review M365B-15
        if ($id === '' || $roleId === '') error_response('A user id and role id are required.', 400);
        if (strtoupper(trim((string)($body['confirm'] ?? ''))) !== 'GRANT') { audit_log('m365_admin_target_refused', 'm365', null, $id, null, ['wanted' => 'assign role ' . $roleId, 'reason' => 'not confirmed']); error_response('Type GRANT to confirm the role.', 400); }   // review UI1-4
        if (($currentUser['role_slug'] ?? '') !== 'super_admin') {   // BT88 (code audit H-10): the high-risk roles, and roles for yourself, are Super Admin only
            $rname = ''; try { foreach (ItopsM365::fromConfig()->listRoleDefinitions() as $rd) if (strcasecmp((string)($rd['id'] ?? ''), $roleId) === 0) $rname = (string)($rd['name'] ?? ($rd['displayName'] ?? '')); } catch (Throwable $e) {}
            $why = $isSelf($id) ? 'You cannot give yourself a Microsoft 365 role.' : (($rname === '' || itops_m365_role_is_high($roleId, $rname)) ? 'Only a Super Admin can assign ' . ($rname !== '' ? $rname : 'that role') . ' (it can take over accounts or the tenant).' : '');
            if ($why !== '') { audit_log('m365_admin_target_refused', 'm365', null, $id, null, ['wanted' => 'assign role ' . ($rname ?: $roleId), 'reason' => $why]); error_response($why, 403); }
        }
        $ok = true; $err = null;
        try { ItopsM365::fromConfig()->assignRole($id, $roleId); }
        catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
        $audit([
            'action' => 'm365.user.assign_role', 'system' => 'm365', 'object_type' => 'user',
            'record_id' => $id, 'result' => $ok ? 'success' : 'error',
            'error' => $err, 'detail' => ['role_id' => $roleId],
        ]);
        if (!$ok) error_response($err, 400);
        json_response(['ok' => true]);
    }

    if ($action === 'm365-user-remove-role') {
        $cap('m365.roles');
        $body = $body;
        $id = trim((string)($body['id'] ?? ''));
        $assignmentId = trim((string)($body['assignment_id'] ?? ''));
        if ($assignmentId === '') error_response('An assignment id is required.', 400);
        // Review M365B-14: the account is required, the assignment id has the shape Microsoft gives, and the assignment must
        // belong to that account (so the self-lockout guard cannot be skipped by leaving the account out)
        if ($id === '' || !preg_match('/^[A-Za-z0-9_\-]{1,128}$/', $assignmentId) || !preg_match('/^[A-Za-z0-9\-]{1,64}$/', $id)) error_response('The account and the role assignment are required.', 400);
        // Don't let an operator strip roles from their own account (lockout guard).
        if ($isSelf($id)) error_response("You can't remove roles from your own account.", 400);
        { $own = false; try { foreach (ItopsM365::fromConfig()->userRoles($id) as $ra) if (strcasecmp((string)($ra['assignment_id'] ?? ($ra['id'] ?? '')), $assignmentId) === 0) $own = true; } catch (Throwable $e) { error_response('The account\'s roles could not be read from Microsoft, so nothing was removed. Try again.', 502); }
          if (!$own) { audit_log('m365_admin_target_refused', 'm365', null, $id, null, ['wanted' => 'remove a role', 'reason' => 'the assignment is not this account\'s']); error_response('That role assignment does not belong to this account.', 400); } }
        // BT124 (review M365B-4): when Settings → Approvals asks a second person for admin role changes, they are made in
        // Security → Admin roles (where the request and the approval are), not here in one click
        require_once __DIR__ . '/../lib/Approvals.php';
        if (itops_appr_needed($db, 'admin_role')) {
            audit_log('m365_admin_target_refused', 'm365', null, $id, null, ['wanted' => 'remove a role', 'reason' => 'a second person approves admin role changes: Security → Admin roles']);
            error_response('A second person approves admin role changes (Settings → Approvals): remove it from Microsoft 365 → Security → Admin roles.', 409);
        }
        if (($currentUser['role_slug'] ?? '') !== 'super_admin') {   // BT88: removing a high-risk role is Super Admin only
            $hit = null; try { foreach (ItopsM365::fromConfig()->userRoles($id) as $ra) if (strcasecmp((string)($ra['assignment_id'] ?? ($ra['id'] ?? '')), $assignmentId) === 0) $hit = $ra; } catch (Throwable $e) {}
            $rn = $hit ? (string)($hit['name'] ?? ($hit['displayName'] ?? '')) : '';
            if (!$hit || itops_m365_role_is_high((string)($hit['role_id'] ?? ''), $rn)) { $why = 'Only a Super Admin can remove ' . ($rn !== '' ? $rn : 'that role') . '.'; audit_log('m365_admin_target_refused', 'm365', null, $id, null, ['wanted' => 'remove role ' . ($rn ?: $assignmentId), 'reason' => $why]); error_response($why, 403); }
        }
        $ok = true; $err = null;
        try { ItopsM365::fromConfig()->removeRoleAssignment($assignmentId); }
        catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
        $audit([
            'action' => 'm365.user.remove_role', 'system' => 'm365', 'object_type' => 'user',
            'record_id' => $id, 'result' => $ok ? 'success' : 'error',
            'error' => $err, 'detail' => ['assignment_id' => $assignmentId],
        ]);
        if (!$ok) error_response($err, 400);
        json_response(['ok' => true]);
    }

    // ---- sign-in activity (m365.view; needs Entra ID P1) ----
    if ($action === 'm365-user-signins') {
        $cap('m365.view');
        $id = trim((string)($_GET['id'] ?? ''));
        if ($id === '') error_response('A user id is required.', 400);
        try { json_response(['signins' => ItopsM365::fromConfig()->userSignIns($id)]); }
        catch (Throwable $e) { json_response(['signins' => [], 'unavailable' => $e->getMessage()]); }
    }
    // ---- BT27: device inventory — Entra devices + sign-in log, kept in ITOps's own table (read-only) ----
    if ($action === 'm365-device-inventory') {
        $cap('m365.view');
        require_once __DIR__ . '/../lib/DeviceInventory.php';
        if (!itops_dinv_ready($db)) json_response(['devices' => [], 'summary' => [], 'total' => 0, 'state' => [], 'missing_table' => true]);
        $st = itops_dinv_state($db);
        $last = isset($st['last_sync']) ? (int)strtotime((string)$st['last_sync']) : 0;
        // peek=1 (Overview card, Needs attention) only reads the table and never starts a sync.
        $want = empty($_GET['peek']) && (!$last || (!empty($_GET['refresh']) && (time() - $last) >= 60));
        $synced = false; $busyNow = false;
        if ($want) {
            try {
                $r = itops_dinv_sync($db, ['max_pages' => 8]);
                $busyNow = !empty($r['busy']);
                $synced = !$busyNow && empty($r['error']);
                if ($synced && $last && function_exists('itops_log')) {
                    try { itops_log('info', 'm365', 'Device inventory synced', ['by' => $currentUser['display_name'] ?? '', 'counts' => $r['counts'] ?? []]); } catch (Throwable $x) {}
                }
            } catch (Throwable $e) {
                $p = itops_dinv_list($db); $p['error'] = $e->getMessage(); json_response($p);
            }
        }
        $p = itops_dinv_list($db);
        $p['synced'] = $synced; $p['busy'] = $busyNow;
        json_response($p);
    }

    // ═════════════ BT31 — Entra management, phase 3a: device actions, recovery keys, groups ═════════════
    $authErr = fn(string $m): bool => (stripos($m, 'authoriz') !== false || stripos($m, 'forbidden') !== false || str_contains($m, '403')
        || stripos($m, 'permission') !== false || stripos($m, 'privilege') !== false);
    $noStore = function () { header('Cache-Control: no-store, max-age=0'); header('Pragma: no-cache'); };

    // ═════════════ BT34 — bulk clean-up of stale devices (two steps: disable, then remove) ═════════════
    // What the inventory proposes. Table and audit log only: opening the page calls nothing at Microsoft.
    if ($action === 'm365-devices-cleanup') {
        $cap('m365.view');
        require_once __DIR__ . '/../lib/DeviceInventory.php';
        $p = itops_dinv_cleanup($db);
        $p['may_change'] = $can('m365.devices');
        $p['write_ok'] = ItopsM365::tokenHas(['Device.ReadWrite.All']);   // true / false / null (unknown)
        json_response($p);
    }

    // Disable or remove up to ITOPS_DINV_BULK_MAX devices. Each one is read live from Entra first and re-checked against
    // the same rules as the single-device actions plus the bulk rules; a device that no longer qualifies is skipped, with the reason.
    if ($action === 'm365-devices-bulk') {
        $cap('m365.devices');
        require_once __DIR__ . '/../lib/DeviceInventory.php';
        $op = (string)($body['op'] ?? '');
        if ($op !== 'disable' && $op !== 'remove') error_response('Choose what to do: disable or remove.', 400);
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($body['ids'] ?? null) ? $body['ids'] : []), fn($v) => $v > 0)));
        if (!$ids) error_response('Select at least one device.', 400);
        if (count($ids) > ITOPS_DINV_BULK_MAX) error_response('At most ' . ITOPS_DINV_BULK_MAX . ' devices per request.', 400);
        if ($op === 'remove') {
            // The person typed "REMOVE <how many>" for the whole selection; this request carries part of it.
            if (!preg_match('/^REMOVE (\d+)$/', trim((string)($body['confirm'] ?? '')), $cm) || (int)$cm[1] < count($ids))
                error_response('Type REMOVE and the number of devices to confirm, for example: REMOVE ' . count($ids), 400);
        }
        $svc = ItopsM365::fromConfig();
        $res = []; $n = ['done' => 0, 'skipped' => 0, 'failed' => 0]; $stopped = null;
        foreach ($ids as $rid) {
            $row = itops_dinv_row($db, $rid);
            if (!$row) { $res[] = ['id' => $rid, 'name' => '', 'status' => 'skipped', 'message' => 'That device is no longer in the inventory.']; $n['skipped']++; continue; }
            $devId = (string)($row['device_id'] ?? ''); $name = (string)($row['name'] ?: 'device ' . $rid);
            if ($stopped !== null) { $res[] = ['id' => $rid, 'name' => $name, 'status' => 'skipped', 'message' => 'Not attempted: Microsoft refused the device before it.']; $n['skipped']++; continue; }
            $live = null;
            if ($devId !== '') {
                try { $live = $svc->entraDevice($devId); if ($live === null) itops_dinv_mark($db, $rid, ['in_directory' => 0]); }
                catch (Throwable $e) { $res[] = ['id' => $rid, 'name' => $name, 'status' => 'failed', 'message' => 'Could not read it from Entra: ' . $e->getMessage()]; $n['failed']++;
                    if ($authErr($e->getMessage())) $stopped = 'Microsoft refused to read the device. The ' . itops_app_name() . ' app needs the Graph application permission Device.Read.All with admin consent.'; continue; }
            }
            if ($live && ($live['name'] ?? '') !== '') $name = (string)$live['name'];
            $skip = itops_dinv_bulk_check($op, $row, $live);
            if ($skip !== '') { $res[] = ['id' => $rid, 'name' => $name, 'status' => 'skipped', 'message' => $skip]; $n['skipped']++; continue; }
            $ok = true; $err = null;
            try { $op === 'disable' ? $svc->setDeviceEnabled($devId, false) : $svc->deleteDevice($devId); } catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
            $audit(['action' => $op === 'disable' ? 'm365.device.disable' : 'm365.device.delete', 'system' => 'm365', 'object_type' => 'device', 'record_id' => $name,
                    'result' => $ok ? 'success' : 'error', 'error' => $err,
                    'detail' => ['device_id' => $devId, 'owner' => $row['user_upn'], 'os' => $live['os'] ?? '', 'last_seen' => $live['last_seen'] ?? null, 'was_enabled' => $live['enabled'] ?? null, 'bulk' => true]]);
            if (!$ok) {
                $res[] = ['id' => $rid, 'name' => $name, 'status' => 'failed', 'message' => (string)$err]; $n['failed']++;
                if ($authErr((string)$err)) $stopped = 'Microsoft refused: ' . $err . ' — the ' . itops_app_name() . ' app needs the Graph application permission Device.ReadWrite.All with admin consent.'
                    . ($op === 'disable' ? ' If it already has it, also give its enterprise application the Entra role "Cloud Device Administrator".' : '');
                continue;
            }
            itops_dinv_mark($db, $rid, $op === 'disable' ? ['enabled' => 0] : ['in_directory' => 0]);
            $res[] = ['id' => $rid, 'name' => $name, 'status' => 'done', 'message' => $op === 'disable' ? 'Disabled.' : 'Removed from Entra.']; $n['done']++;
        }
        json_response(['ok' => true, 'op' => $op, 'results' => $res, 'stopped' => $stopped] + $n);
    }

    if (strpos($action, 'm365-device-') === 0 && $action !== 'm365-device-inventory') {
        require_once __DIR__ . '/../lib/DeviceInventory.php';
        $cap('m365.view');
        $rid = (int)($method === 'GET' ? ($_GET['id'] ?? 0) : ($body['id'] ?? 0));
        $row = itops_dinv_row($db, $rid);
        if (!$row) error_response('That device is not in the inventory.', 404);
        $devId = (string)($row['device_id'] ?? '');
        $svc = ItopsM365::fromConfig();
        $live = null; $liveErr = null;
        if ($devId !== '') {
            try { $live = $svc->entraDevice($devId); if ($live === null) itops_dinv_mark($db, $rid, ['in_directory' => 0]); }
            catch (Throwable $e) { $liveErr = $e->getMessage(); }
        }
        if ($liveErr !== null) {
            if ($action === 'm365-device-actions') json_response(['error' => $liveErr, 'needs_permission' => $authErr($liveErr) ? 'Device.Read.All' : null, 'can' => new stdClass(), 'why' => new stdClass()]);
            error_response('Could not read the device from Entra: ' . $liveErr, 400);
        }
        $st = itops_dinv_actions($row, $live);
        $name = $live['name'] ?? ($row['name'] ?: 'device ' . $rid);
        $own = ['may_change' => $can('m365.devices'), 'may_see_keys' => $can('m365.keys')];

        // What can be done with this device right now (read-only).
        if ($action === 'm365-device-actions') {
            json_response(['device' => $live, 'can' => $st['can'], 'why' => (object)$st['why'], 'registered' => $st['registered'], 'gone' => !empty($st['gone']),
                           'stale' => $st['stale'] ?? null, 'stale_days' => ITOPS_DINV_STALE_DAYS] + $own);
        }

        if ($action === 'm365-device-set-enabled') {
            if ($method !== 'POST') error_response('Method not allowed', 405);   // BT124 (review M365B-5)
            $cap('m365.devices');
            $want = !empty($body['enabled']);
            $k = $want ? 'enable' : 'disable';
            if (empty($st['can'][$k])) error_response($st['why']['all'] ?? ($st['why']['toggle'] ?? ($want ? 'This device is already enabled.' : 'This device is already disabled.')), 400);
            $ok = true; $err = null;
            try { $svc->setDeviceEnabled($devId, $want); } catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
            $audit(['action' => $want ? 'm365.device.enable' : 'm365.device.disable', 'system' => 'm365', 'object_type' => 'device', 'record_id' => $name,
                    'result' => $ok ? 'success' : 'error', 'error' => $err, 'detail' => ['device_id' => $devId, 'owner' => $row['user_upn']]]);
            if (!$ok) error_response($authErr($err)
                ? 'Microsoft refused: ' . $err . ' — the ' . itops_app_name() . ' app needs the Graph application permission Device.ReadWrite.All with admin consent. If it already has it, also give its enterprise application the Entra role "Cloud Device Administrator".'
                : $err, 400);
            itops_dinv_mark($db, $rid, ['enabled' => $want ? 1 : 0]);
            json_response(['ok' => true, 'enabled' => $want]);
        }

        if ($action === 'm365-device-delete') {
            if ($method !== 'POST') error_response('Method not allowed', 405);
            $cap('m365.devices');
            if (empty($st['can']['delete'])) error_response($st['why']['all'] ?? ($st['why']['delete'] ?? 'This device cannot be removed.'), 400);
            $typed = trim((string)($body['confirm'] ?? ''));
            if ($typed === '' || strcasecmp($typed, (string)$name) !== 0) error_response('Type the device name exactly to confirm: ' . $name, 400);
            $ok = true; $err = null;
            try { $svc->deleteDevice($devId); } catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
            $audit(['action' => 'm365.device.delete', 'system' => 'm365', 'object_type' => 'device', 'record_id' => $name,
                    'result' => $ok ? 'success' : 'error', 'error' => $err,
                    'detail' => ['device_id' => $devId, 'owner' => $row['user_upn'], 'os' => $live['os'] ?? '', 'last_seen' => $live['last_seen'] ?? null, 'was_enabled' => $live['enabled'] ?? null]]);
            if (!$ok) error_response($authErr($err)
                ? 'Microsoft refused: ' . $err . ' — the ' . itops_app_name() . ' app needs the Graph application permission Device.ReadWrite.All with admin consent.'
                : $err, 400);
            itops_dinv_mark($db, $rid, ['in_directory' => 0]);
            json_response(['ok' => true]);
        }

        // BitLocker recovery keys stored for the device: ids only. The key itself needs m365-device-key and a reason.
        if ($action === 'm365-device-keys') {
            $cap('m365.keys');
            if (empty($st['can']['keys']) && empty($st['can']['laps'])) json_response(['keys' => [], 'laps' => false, 'note' => $st['why']['all'] ?? ($st['why']['keys'] ?? '')]);
            $keys = []; $needs = null; $err = null;
            if (!empty($st['can']['keys'])) {
                try { $keys = $svc->bitlockerKeys($devId); }
                catch (Throwable $e) { $err = $e->getMessage(); if ($authErr($err)) $needs = 'BitlockerKey.Read.All'; }
            }
            $noStore();
            json_response(['keys' => $keys, 'laps' => !empty($st['can']['laps']), 'laps_note' => $st['why']['laps'] ?? '', 'error' => $err, 'needs_permission' => $needs]);
        }

        if ($action === 'm365-device-key' || $action === 'm365-device-laps') {
            $cap('m365.keys');
            $laps = $action === 'm365-device-laps';
            if (empty($st['can'][$laps ? 'laps' : 'keys'])) error_response($st['why']['all'] ?? ($st['why'][$laps ? 'laps' : 'keys'] ?? 'Not available for this device.'), 400);
            $reason = trim((string)($body['reason'] ?? ''));
            if (mb_strlen($reason) < 3) error_response('Say why you need it (a ticket number or a few words). It is recorded in the Audit Log.', 400);
            $reason = mb_substr($reason, 0, 200);
            $ok = true; $err = null; $out = null; $keyId = '';
            try {
                if ($laps) {
                    $r = $svc->lapsCredentials($devId);
                    if ($r === null || !$r['credentials']) { $ok = false; $err = 'No local administrator password is stored for this computer. Windows LAPS is not set up for it, or it has not backed one up yet.'; }
                    else $out = ['account' => $r['credentials'][0]['account'], 'password' => $r['credentials'][0]['password'], 'backed_up' => $r['credentials'][0]['backed_up'], 'older' => max(0, count($r['credentials']) - 1)];
                } else {
                    $keyId = trim((string)($body['key_id'] ?? ''));
                    $k = $svc->bitlockerKey($keyId);
                    // The key must belong to THIS device: a key id from another device is refused.
                    if ($k['device_id'] === '' || $k['device_id'] !== strtolower($devId)) { $ok = false; $err = $k['device_id'] === '' ? 'Microsoft did not say which device this recovery key belongs to, so it is not shown.' : 'That recovery key belongs to another device.'; }   // BT88: fails closed
                    elseif ($k['key'] === '') { $ok = false; $err = 'Microsoft returned no key. The app needs BitlockerKey.Read.All (BitlockerKey.ReadBasic.All is not enough).'; }
                    else $out = ['key' => $k['key'], 'key_id' => $k['id']];
                }
            } catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
            // The secret itself is never written anywhere: the audit entry says who, which device, which key and why.
            $audit(['action' => $laps ? 'm365.device.laps_password_viewed' : 'm365.device.bitlocker_key_viewed', 'system' => 'm365', 'object_type' => 'device', 'record_id' => $name,
                    'result' => $ok ? 'success' : 'error', 'error' => $err,
                    'detail' => ['device_id' => $devId, 'owner' => $row['user_upn'], 'reason' => $reason] + ($laps ? [] : ['key_id' => $keyId])]);
            if (!$ok) error_response($authErr((string)$err)
                ? 'Microsoft refused: ' . $err . ' — the ' . itops_app_name() . ' app needs the Graph application permission ' . ($laps ? 'DeviceLocalCredential.Read.All' : 'BitlockerKey.Read.All') . ' with admin consent.'
                : (string)$err, 400);
            $noStore();
            json_response(['ok' => true] + $out);
        }
        error_response('Unknown Microsoft 365 action.', 404);
    }

    // ---- groups: read with m365.view, change members with m365.groups ----
    // BT34: owners and new groups need a broader Microsoft permission than members do. The token says whether it was granted
    // (true / false), or null when the token cannot be inspected: then the buttons are offered and Microsoft's answer is shown.
    $grpExtra = function () use ($can): array {
        $own = ItopsM365::tokenHas(ItopsM365::GROUP_OWNER_PERMISSIONS); $new = ItopsM365::tokenHas(ItopsM365::GROUP_CREATE_PERMISSIONS);
        return ['may_owners' => $can('m365.groups') && $own !== false, 'owners_need' => $own === false ? 'Group.ReadWrite.All' : null,
                'may_create' => $can('m365.groups') && $new !== false, 'create_need' => $new === false ? 'Group.ReadWrite.All' : null,
                // true / false / null (unknown), for the "What it needs" list on the screen
                'owners_ok' => $own, 'create_ok' => $new, 'members_ok' => ItopsM365::tokenHas(['GroupMember.ReadWrite.All', 'Group.ReadWrite.All', 'Directory.ReadWrite.All'])];
    };
    if ($action === 'm365-groups-overview') {
        $cap('m365.view');
        $refresh = !empty($_GET['refresh']);
        $cc = $refresh ? null : $cacheGet('m365-groups-overview', 600);
        if ($cc) { $p = $cc['payload']; $p['cached'] = true; $p['cached_at'] = date('c', $cc['ts']); $p['may_change'] = $can('m365.groups'); json_response($p + $grpExtra()); }
        $payload = itops_m365_build('m365-groups-overview');   // BT54: the same read the background refresh makes (lib/M365Background.php)
        if (!empty($payload['_nocache'])) { unset($payload['_nocache']); json_response($payload); }
        $cachePut('m365-groups-overview', $payload);
        json_response($payload + ['may_change' => $can('m365.groups')] + $grpExtra());
    }

    if ($action === 'm365-group') {
        $cap('m365.view');
        $gid = trim((string)($_GET['id'] ?? ''));
        if ($gid === '') error_response('A group id is required.', 400);
        try { $r = ItopsM365::fromConfig()->groupDetail($gid); }
        catch (Throwable $e) { error_response($e->getMessage(), 400); }
        $r['may_change'] = $can('m365.groups');
        json_response($r + $grpExtra());
    }

    // BT34: add or remove an owner. People only; never for synced groups, Exchange-managed lists or role-assignable groups.
    if ($action === 'm365-group-add-owner' || $action === 'm365-group-remove-owner') {
        $cap('m365.groups');
        $gid = trim((string)($body['group_id'] ?? '')); $oid = trim((string)($body['owner_id'] ?? ''));
        if ($gid === '' || $oid === '') error_response('A group id and a person are required.', 400);
        $add = $action === 'm365-group-add-owner';
        $ok = true; $err = null; $gname = $gid; $oname = ''; $alsoMember = false; $refused = false;
        try {
            $svc = ItopsM365::fromConfig();
            $d = $svc->groupDetail($gid);
            $gname = $d['group']['name'] ?: $gid;
            $owners = $d['group']['owners'] ?? [];
            $isOwner = false; foreach ($owners as $o) if (strcasecmp($o['id'], $oid) === 0) { $isOwner = true; $oname = $o['name'] ?: $o['upn']; }
            $refused = true;   // from here to the Graph call, a failure is our own rule, not Microsoft's answer
            if (!$d['group']['owners_manageable']) throw new RuntimeException($d['group']['owners_locked']);
            if ($add) {
                if ($isOwner) throw new RuntimeException($oname . ' already owns this group.');
                $u = $svc->userKind($oid);
                $oname = $u['name'] ?: $u['email'];
                if ($u['guest']) throw new RuntimeException('A guest cannot own a group. Choose someone from the organization.');
                if (!$u['enabled']) throw new RuntimeException($oname . ' is blocked from signing in, so they could not look after the group. Choose someone else.');
                $refused = false;
                $svc->addGroupOwner($gid, $u['id']);
                // In a Microsoft 365 group or team an owner who is not a member cannot work in it; add them when the members can be changed.
                if (!empty($body['also_member']) && $d['group']['type'] === 'm365' && $d['group']['manageable']) {
                    $isMember = false; foreach ($d['members'] as $mm) if (strcasecmp($mm['id'], $u['id']) === 0) $isMember = true;
                    if (!$isMember) { try { $svc->addToGroup($u['id'], $gid); $alsoMember = true; } catch (Throwable $x) {} }
                }
            } else {
                if (!$isOwner) throw new RuntimeException('That person is not an owner of this group.');
                if ($d['group']['type'] === 'm365' && count($owners) <= 1)
                    throw new RuntimeException('This is the last owner. Microsoft does not let a Microsoft 365 group or team be left without one: add another owner first, then remove this one.');
                $refused = false;
                $svc->removeGroupOwner($gid, $oid);
            }
        } catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
        $audit(['action' => $add ? 'm365.group.add_owner' : 'm365.group.remove_owner', 'system' => 'm365', 'object_type' => 'group', 'record_id' => $gname,
                'result' => $ok ? 'success' : 'error', 'error' => $err, 'detail' => ['group_id' => $gid, 'owner_id' => $oid, 'owner' => mb_substr($oname, 0, 120), 'also_member' => $alsoMember]]);
        if (!$ok) error_response(!$refused && $authErr((string)$err)
            ? 'Microsoft refused: ' . $err . ' — changing owners needs the Graph application permission Group.ReadWrite.All with admin consent on the ' . itops_app_name() . ' app (GroupMember.ReadWrite.All only covers members).'
            : (string)$err, 400);
        $cacheDrop('m365-groups-overview'); if ($alsoMember) $dirty();
        json_response(['ok' => true, 'also_member' => $alsoMember]);
    }

    // BT34: create a cloud security group (assigned membership, no mailbox, no team) with its owners.
    if ($action === 'm365-group-create') {
        $cap('m365.groups');
        $name = trim(preg_replace('/\s+/u', ' ', (string)($body['name'] ?? '')) ?? '');
        $desc = trim((string)($body['description'] ?? ''));
        $ownerIds = array_values(array_unique(array_filter(array_map(fn($v) => trim((string)$v), is_array($body['owner_ids'] ?? null) ? $body['owner_ids'] : []))));
        if (mb_strlen($name) < 3) error_response('Give the group a name of at least 3 characters.', 400);
        if (mb_strlen($name) > 120) error_response('The name is too long (120 characters at most).', 400);
        if (mb_strlen($desc) > 500) error_response('The description is too long (500 characters at most).', 400);
        if (!$ownerIds) error_response('Choose at least one owner. A group that nobody owns cannot be looked after.', 400);
        if (count($ownerIds) > 5) error_response('Name up to 5 owners here; more can be added afterwards.', 400);
        $ok = true; $err = null; $g = null; $names = []; $refused = true;
        try {
            $svc = ItopsM365::fromConfig();
            if ($svc->groupsNamed($name)) throw new RuntimeException('A group called "' . $name . '" already exists. Two groups with the same name are easy to mix up: choose another name.');
            foreach ($ownerIds as $oid) {
                $u = $svc->userKind($oid);
                if ($u['guest']) throw new RuntimeException(($u['name'] ?: $u['email']) . ' is a guest. Owners must be people from the organization.');
                if (!$u['enabled']) throw new RuntimeException(($u['name'] ?: $u['email']) . ' is blocked from signing in. Choose an owner who can sign in.');
                $names[] = $u['name'] ?: $u['email'];
            }
            $refused = false;
            $g = $svc->createSecurityGroup($name, $desc, $ownerIds);
        } catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
        $audit(['action' => 'm365.group.create', 'system' => 'm365', 'object_type' => 'group', 'record_id' => $name,
                'result' => $ok ? 'success' : 'error', 'error' => $err, 'detail' => ['group_id' => $g['id'] ?? null, 'type' => 'security', 'owners' => $names, 'description' => mb_substr($desc, 0, 200)]]);
        if (!$ok) error_response(!$refused && $authErr((string)$err)
            ? 'Microsoft refused: ' . $err . ' — creating groups needs the Graph application permission Group.ReadWrite.All with admin consent on the ' . itops_app_name() . ' app.'
            : (string)$err, 400);
        $cacheDrop('m365-groups-overview');
        json_response(['ok' => true, 'group' => $g]);
    }

    if ($action === 'm365-group-add-member' || $action === 'm365-group-remove-member') {
        $cap('m365.groups');
        $gid = trim((string)($body['group_id'] ?? '')); $mid = trim((string)($body['member_id'] ?? ''));
        if ($gid === '' || $mid === '') error_response('A group id and a member id are required.', 400);
        $add = $action === 'm365-group-add-member';
        $ok = true; $err = null; $gname = $gid; $mname = trim((string)($body['member_name'] ?? ''));
        try {
            $svc = ItopsM365::fromConfig();
            $d = $svc->groupDetail($gid);
            $gname = $d['group']['name'] ?: $gid;
            if (!$d['group']['manageable']) throw new RuntimeException($d['group']['locked']);
            if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $mid)) throw new RuntimeException('That member id is not valid.');
            if (!$add) { foreach ($d['members'] as $mm) if (strcasecmp($mm['id'], $mid) === 0) $mname = $mm['name'] ?: $mm['upn']; }
            $add ? $svc->addToGroup($mid, $gid) : $svc->removeFromGroup($mid, $gid);
        } catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
        $audit(['action' => $add ? 'm365.group.add_member' : 'm365.group.remove_member', 'system' => 'm365', 'object_type' => 'group', 'record_id' => $gname,
                'result' => $ok ? 'success' : 'error', 'error' => $err, 'detail' => ['group_id' => $gid, 'member_id' => $mid, 'member' => mb_substr($mname, 0, 120)]]);
        if (!$ok) error_response($authErr((string)$err) && stripos((string)$err, 'managed in') === false && stripos((string)$err, 'manage it in') === false
            ? 'Microsoft refused: ' . $err . ' — the ' . itops_app_name() . ' app needs the Graph application permission GroupMember.ReadWrite.All with admin consent.'
            : (string)$err, 400);
        $cacheDrop('m365-groups-overview'); $dirty();
        json_response(['ok' => true]);
    }

    // ═════════════ BT32 — Entra management, phase 3b: guests, enterprise apps, Entra audit log ═════════════
    if ($action === 'm365-guests') {
        $cap('m365.view');
        $refresh = !empty($_GET['refresh']);
        $cc = $refresh ? null : $cacheGet('m365-guests', 600);
        // BT34: what the token allows, for the "What it needs" list (true / false / null = unknown)
        $guestExtra = fn(): array => ['may_change' => $can('m365.users'),
            'write_ok'  => ItopsM365::tokenHas(['User.ReadWrite.All', 'Directory.ReadWrite.All']),
            'invite_ok' => ItopsM365::tokenHas(['User.Invite.All', 'User.ReadWrite.All', 'Directory.ReadWrite.All'])];
        if ($cc) { $p = $cc['payload']; $p['cached'] = true; $p['cached_at'] = date('c', $cc['ts']); json_response($p + $guestExtra()); }
        $payload = itops_m365_build('m365-guests');   // BT54: the same read the background refresh makes (lib/M365Background.php)
        if (!empty($payload['_nocache'])) { unset($payload['_nocache']); json_response($payload); }
        $cachePut('m365-guests', $payload);
        json_response($payload + $guestExtra());
    }

    // Remove a guest. Only guests: a member account is never deleted through this route (members are offboarded).
    if ($action === 'm365-guest-delete') {
        $cap('m365.users');
        $gid = trim((string)($body['id'] ?? ''));
        if ($gid === '') error_response('A guest id is required.', 400);
        $svc = ItopsM365::fromConfig();
        try { $u = $svc->userKind($gid); } catch (Throwable $e) { error_response($e->getMessage(), 400); }
        if (!$u['guest']) error_response('This is not a guest account. Staff accounts are offboarded from Accounts → Offboard, never deleted here.', 400);
        $typed = strtolower(trim((string)($body['confirm'] ?? '')));
        if ($typed === '' || $typed !== strtolower($u['email'])) error_response('Type the guest\'s email address exactly to confirm: ' . $u['email'], 400);
        $guardT($u['id'], 'delete it');   // BT124 (review M365B-10): a guest with an admin role is deleted by a Super Admin only
        $ok = true; $err = null;
        try { $svc->deleteUser($u['id']); } catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
        $audit(['action' => 'm365.guest.delete', 'system' => 'm365', 'object_type' => 'user', 'record_id' => $u['email'],
                'result' => $ok ? 'success' : 'error', 'error' => $err, 'detail' => ['id' => $u['id'], 'name' => $u['name'], 'was_enabled' => $u['enabled']]]);
        if (!$ok) error_response($authErr((string)$err) ? 'Microsoft refused: ' . $err . ' — the ' . itops_app_name() . ' app needs the Graph application permission User.ReadWrite.All with admin consent.' : (string)$err, 400);
        $dirty(); $cacheDrop('m365-groups-overview');
        json_response(['ok' => true]);
    }

    // BT34: where the invitation link lands once the guest accepts. Microsoft requires a URL; "My Apps" works for every guest.
    $inviteRedirect = function (): string {
        $t = (string)(itops_graph_cfg()['tenant'] ?? '');
        return 'https://myapplications.microsoft.com' . (preg_match('/^[A-Za-z0-9.-]{3,80}$/', $t) ? '/?tenantid=' . rawurlencode($t) : '');
    };
    // One guest, one action. Only accounts that are guests AND still waiting on their invitation qualify.
    // Returns ['status' => done|skipped|failed, 'message' => …, 'name' => …]; $authFail is set when Microsoft refused for lack of permission.
    $guestPending = function (ItopsM365 $svc, string $gid, string $op, ?string &$authFail) use ($audit, $authErr, $inviteRedirect, $currentUser): array {
        try { $u = $svc->userKind($gid); }
        catch (Throwable $e) { return ['status' => 'failed', 'name' => '', 'message' => $e->getMessage()]; }
        $label = $u['name'] ?: $u['email'];
        if (!$u['guest']) return ['status' => 'skipped', 'name' => $label, 'message' => 'This is not a guest account.'];
        if ($u['state'] !== 'PendingAcceptance') return ['status' => 'skipped', 'name' => $label, 'message' => 'This guest already accepted the invitation.'];
        if ($op === 'resend' && !$u['enabled']) return ['status' => 'skipped', 'name' => $label, 'message' => 'Sign-in is blocked for this guest. Restore sign-in first.'];
        if ($op !== 'resend' && ($currentUser['role_slug'] ?? '') !== 'super_admin') {   // BT124 (review M365B-10)
            require_once __DIR__ . '/../lib/M365Guard.php';
            try { $ar = itops_m365_admin_roles($u['id']); } catch (Throwable $e) { $ar = ['(could not be checked)']; }
            if ($ar) { audit_log('m365_admin_target_refused', 'm365', null, mb_substr($u['email'], 0, 200), null, ['wanted' => 'delete a guest', 'reason' => 'admin role: ' . implode(', ', array_slice($ar, 0, 3))]); return ['status' => 'skipped', 'name' => $label, 'message' => 'This guest holds an admin role: only a Super Admin removes it.']; }
        }
        $ok = true; $err = null; $same = true;
        try {
            if ($op === 'resend') { $r = $svc->resendInvitation($u['email'], $inviteRedirect()); $same = $r['id'] === '' || strcasecmp($r['id'], $u['id']) === 0; }
            else $svc->deleteUser($u['id']);
        } catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
        $audit(['action' => $op === 'resend' ? 'm365.guest.resend_invite' : 'm365.guest.delete', 'system' => 'm365', 'object_type' => 'user', 'record_id' => $u['email'],
                'result' => $ok ? 'success' : 'error', 'error' => $err,
                'detail' => ['id' => $u['id'], 'name' => $u['name'], 'invited' => $u['created'], 'pending' => true] + ($op === 'resend' ? ['same_account' => $same] : ['was_enabled' => $u['enabled']])]);
        if (!$ok) {
            if ($authErr((string)$err)) $authFail = 'Microsoft refused: ' . $err . ' — the ' . itops_app_name() . ' app needs the Graph application permission '
                . ($op === 'resend' ? 'User.Invite.All (or User.ReadWrite.All)' : 'User.ReadWrite.All') . ' with admin consent.'
                . ($op === 'resend' ? ' Guest invitations must also be allowed in Entra (External Identities → External collaboration settings).' : '');
            return ['status' => 'failed', 'name' => $label, 'message' => (string)$err];
        }
        if ($op === 'resend') return ['status' => 'done', 'name' => $label, 'same_account' => $same, 'message' => $same
            ? 'Invitation sent again to ' . $u['email'] . '.'
            : 'Invitation sent to ' . $u['email'] . ', but Microsoft created a new guest account for it instead of reusing this one. Refresh the list and remove the one that stays pending.'];
        return ['status' => 'done', 'name' => $label, 'message' => 'Removed.'];
    };

    // Send the invitation email again to one guest who has not accepted yet.
    if ($action === 'm365-guest-resend') {
        $cap('m365.users');
        $gid = trim((string)($body['id'] ?? ''));
        if ($gid === '') error_response('A guest id is required.', 400);
        $authFail = null;
        $r = $guestPending(ItopsM365::fromConfig(), $gid, 'resend', $authFail);
        if ($r['status'] !== 'done') error_response($authFail ?? $r['message'], 400);
        if (empty($r['same_account'])) $dirty();
        json_response(['ok' => true, 'message' => $r['message'], 'same_account' => !empty($r['same_account'])]);
    }

    // Resend to, or remove, several guests whose invitation is still pending (never an accepted guest, never a staff account).
    if ($action === 'm365-guests-bulk') {
        $cap('m365.users');
        $op = (string)($body['op'] ?? '');
        if ($op !== 'resend' && $op !== 'remove') error_response('Choose what to do: resend or remove.', 400);
        $ids = array_values(array_unique(array_filter(array_map(fn($v) => trim((string)$v), is_array($body['ids'] ?? null) ? $body['ids'] : []))));
        if (!$ids) error_response('Select at least one guest.', 400);
        if (count($ids) > 10) error_response('At most 10 guests per request.', 400);
        if ($op === 'remove' && (!preg_match('/^REMOVE (\d+)$/', trim((string)($body['confirm'] ?? '')), $cm) || (int)$cm[1] < count($ids)))
            error_response('Type REMOVE and the number of guests to confirm, for example: REMOVE ' . count($ids), 400);
        $svc = ItopsM365::fromConfig();
        $res = []; $n = ['done' => 0, 'skipped' => 0, 'failed' => 0]; $stopped = null;
        foreach ($ids as $gid) {
            if ($stopped !== null) { $res[] = ['id' => $gid, 'name' => '', 'status' => 'skipped', 'message' => 'Not attempted: Microsoft refused the guest before it.']; $n['skipped']++; continue; }
            $r = $guestPending($svc, $gid, $op, $stopped);
            $res[] = ['id' => $gid] + $r; $n[$r['status']]++;
        }
        if ($n['done'] > 0) { $dirty(); if ($op === 'remove') $cacheDrop('m365-groups-overview'); }
        json_response(['ok' => true, 'op' => $op, 'results' => $res, 'stopped' => $stopped] + $n);
    }

    if ($action === 'm365-enterprise-apps') {
        $cap('m365.view');
        $refresh = !empty($_GET['refresh']);
        $cc = $refresh ? null : $cacheGet('m365-enterprise-apps', 600);
        if ($cc) { $p = $cc['payload']; $p['cached'] = true; $p['cached_at'] = date('c', $cc['ts']); json_response($p); }
        $payload = itops_m365_build('m365-enterprise-apps');   // BT54: the same read the background refresh makes (lib/M365Background.php)
        if (!empty($payload['_nocache'])) { unset($payload['_nocache']); json_response($payload); }
        $cachePut('m365-enterprise-apps', $payload);
        json_response($payload);
    }

    if ($action === 'm365-directory-audit') {
        $cap('m365.view');
        $days = (int)($_GET['days'] ?? 1); if (!in_array($days, [1, 7, 30], true)) $days = 1;
        $cat = preg_replace('/[^A-Za-z]/', '', (string)($_GET['category'] ?? ''));
        $key = 'm365-directory-audit-' . $days . '-' . strtolower($cat);
        $refresh = !empty($_GET['refresh']);
        $cc = $refresh ? null : $cacheGet($key, 300);
        if ($cc) { $p = $cc['payload']; $p['cached'] = true; $p['cached_at'] = date('c', $cc['ts']); json_response($p); }
        $capped = false;
        try { $rows = ItopsM365::fromConfig()->directoryAudits(gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400), $cat, $days === 1 ? 2 : 4, $capped); }
        catch (Throwable $e) {
            $m = $e->getMessage();
            json_response(['error' => $m, 'needs_permission' => $authErr($m) ? 'AuditLog.Read.All' : null, 'events' => [], 'summary' => []]);
        }
        $cats = []; $fail = 0;
        foreach ($rows as $r) { if ($r['category'] !== '') $cats[$r['category']] = ($cats[$r['category']] ?? 0) + 1; if ($r['result'] !== '' && $r['result'] !== 'success') $fail++; }
        ksort($cats);
        $payload = ['events' => $rows, 'summary' => ['total' => count($rows), 'failures' => $fail, 'categories' => $cats], 'days' => $days, 'category' => $cat, 'capped' => $capped, 'cached' => false];
        $cachePut($key, $payload);
        json_response($payload);
    }
    error_response('Unknown Microsoft 365 action.', 404);
}

// ═════════════════════ Licensing → Reclaim ═════════════════════
if ($action === 'license-report') {
    $cap('m365.view');
    $days = max(1, min(365, (int)($_GET['days'] ?? 30)));
    $refresh = !empty($_GET['refresh']);
    $cache = $refresh ? null : $cacheGet('license-report-' . $days, 600);
    if ($cache) json_response(((array)$cache['payload']) + ['cached' => true, 'cached_at' => date('c', $cache['ts'])]);
    try { $rep = ItopsM365::fromConfig()->licenseReport($days); }
    catch (Throwable $e) { error_response($e->getMessage(), 400); }
    $ts = $cachePut('license-report-' . $days, $rep);
    json_response(((array)$rep) + ['cached' => false, 'cached_at' => date('c', $ts)]);
}

// ═════════════════════ Licensing → Reconcile ═════════════════════
// Microsoft 365 directory ↔ the people who can sign in to this app, matched by email (read-only).
// 0001-A004: the reference app also matched vTiger users; this product has Microsoft 365 only.
if ($action === 'recon-directory') {
    $cap('m365.view');
    $mode = (string)($_GET['mode'] ?? '');
    $section = preg_replace('/[^a-z_]/', '', (string)($_GET['section'] ?? ''));
    $refresh = !empty($_GET['refresh']);
    $payload = null;
    if (!$refresh) {
        $cc = $cacheGet('recon-computed', 600);
        if ($cc) { $payload = $cc['payload']; $payload['cached'] = true; $payload['cached_at'] = date('c', $cc['ts']); }
    }
    if ($payload === null) {
        $m365 = []; $hasSignIn = false; $m365Error = null; $cachedAt = null; $fromCache = false;
        $cache = $refresh ? null : $cacheGet('m365-directory', 600);
        if ($cache) {
            $m365 = $cache['payload']['users'] ?? []; $hasSignIn = (bool)($cache['payload']['has_sign_in'] ?? false);
            $cachedAt = date('c', $cache['ts']); $fromCache = true;
        } else {
            try {
                $d = ItopsM365::fromConfig()->directoryForRecon();
                $m365 = $d['users']; $hasSignIn = (bool)$d['has_sign_in'];
                $cachedAt = date('c', $cachePut('m365-directory', $d));
            } catch (Throwable $e) { $m365Error = $e->getMessage(); }
        }
        // the people who can sign in to this app (active users), to find the ones without an enabled Microsoft 365 account
        $appUsers = [];
        try { $appUsers = $db->query("SELECT id, display_name, LOWER(email) email, auth_provider, last_login_at FROM itops_users WHERE status='active' AND email<>'' ORDER BY display_name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
        $emailsOf = function (array $u): array { return array_values(array_filter(array_unique([strtolower(trim((string)($u['mail'] ?? ''))), strtolower(trim((string)($u['upn'] ?? '')))]))); };
        $enabled = [];
        foreach ($m365 as $u) if (!empty($u['enabled'])) foreach ($emailsOf($u) as $e) $enabled[$e] = true;
        $cats = ['licensed_disabled' => [], 'app_without_m365' => []];
        foreach ($m365 as $u) {
            if (!$u['enabled'] && $u['license_count'] > 0) {
                $e = $emailsOf($u)[0] ?? '';
                $cats['licensed_disabled'][] = ['id' => $u['id'] ?? '', 'email' => $e, 'display_name' => $u['display_name'], 'upn' => $u['upn'], 'licenses' => $u['license_count'], 'skus' => $u['license_skus'] ?? []];
            }
        }
        if ($m365Error === null) foreach ($appUsers as $a) {
            if (!isset($enabled[$a['email']])) $cats['app_without_m365'][] = ['id' => (int)$a['id'], 'display_name' => $a['display_name'], 'email' => $a['email'],
                'auth_provider' => $a['auth_provider'], 'last_login_at' => $a['last_login_at']];
        }
        $payload = [
            'generated_at' => date('c'), 'cached' => $fromCache, 'cached_at' => $cachedAt, 'has_sign_in' => $hasSignIn,
            'counts' => [
                'm365_total' => count($m365), 'app_total' => count($appUsers),
                'licensed_disabled' => count($cats['licensed_disabled']), 'app_without_m365' => count($cats['app_without_m365']),
            ],
            'categories' => $cats,
            'errors' => array_filter(['m365' => $m365Error]),
        ];
        $cachePut('recon-computed', $payload);
    }
    if ($mode === 'summary') { $s = $payload; unset($s['categories']); json_response($s); }
    if ($section !== '') json_response(['section' => $section, 'items' => $payload['categories'][$section] ?? [], 'cached' => $payload['cached'] ?? false, 'cached_at' => $payload['cached_at'] ?? null]);
    json_response($payload);
}

// ═════════════════════ Offboarding verification ═════════════════════
if ($action === 'provision-verify') {
    if (!$can('provision.offboard') && !$can('m365.users')) $cap('provision.offboard');
    $email = trim((string)($body['email'] ?? '')); $m365Id = trim((string)($body['m365_id'] ?? ''));
    if ($m365Id !== '' && !preg_match('/^[A-Za-z0-9\-]{1,64}$/', $m365Id)) error_response('That Microsoft 365 account id is not valid.', 400);   // review M365B-15
    if ($email === '' && $m365Id === '') error_response('An email or a Microsoft 365 id is required.', 400);
    $checks = []; $issues = [];
    $add = function (string $system, string $label, ?bool $ok, string $detail = '') use (&$checks, &$issues) {
        $checks[] = ['system' => $system, 'label' => $label, 'status' => $ok === null ? 'skip' : ($ok ? 'pass' : 'fail'), 'detail' => $detail];
        if ($ok === false) $issues[] = "$system: $label — $detail";
    };
    try {
        $svc = ItopsM365::fromConfig(); $id = $m365Id;
        if ($id === '' && $email !== '') {
            try {
                $found = $svc->listUsers($email, 5)['users'] ?? [];
                foreach ($found as $u) if (strcasecmp((string)($u['upn'] ?? ''), $email) === 0 || strcasecmp((string)($u['mail'] ?? ''), $email) === 0) { $id = (string)($u['id'] ?? ''); break; }
                if ($id === '' && count($found) === 1) $id = (string)($found[0]['id'] ?? '');
            } catch (Throwable $e) {}
        }
        if ($id === '') $add('M365', 'Account located', null, 'No Microsoft 365 account matched — nothing to verify.');
        else {
            $u = $svc->getUser($id);
            $disabled = !(bool)($u['enabled'] ?? false); $lics = is_array($u['licenses'] ?? null) ? $u['licenses'] : [];
            $add('M365', 'Sign-in blocked', $disabled, $disabled ? 'accountEnabled = false' : 'account is STILL enabled');
            $add('M365', 'Licenses removed', count($lics) === 0, count($lics) === 0 ? 'no licenses assigned' : (count($lics) . ' license(s) still assigned'));
        }
    } catch (Throwable $e) { $add('M365', 'Checked', null, 'Microsoft 365 not configured or unreachable: ' . $e->getMessage()); }
    $audit(['action' => 'provision.verify', 'system' => 'm365', 'record_id' => $email !== '' ? $email : $m365Id,
            'result' => $clean ? 'success' : 'error', 'detail' => ['clean' => $clean, 'issues' => $issues, 'checks' => $checks]]);
    json_response(['ok' => true, 'clean' => $clean, 'checks' => $checks, 'issues' => $issues, 'subject' => ['email' => $email, 'm365_id' => $m365Id]]);
}

// ═════════════════════ Onboarding templates ═════════════════════
$tplRow = function (array $r): array {
    $m = json_decode((string)$r['m365_json'], true) ?: [];
    // BT47: the default password never leaves the server through a template. A screen is told only that one is saved.
    $m['has_password'] = !empty($m['password_enc']); unset($m['password_enc']);
    return ['id' => (string)$r['id'], 'name' => (string)$r['name'], 'description' => (string)($r['description'] ?? ''), 'm365' => $m,
            'updated_at' => $r['updated_at'] ?? null];
};
$tplList = function () use ($db, $tplRow): array {
    try { return array_map($tplRow, $db->query("SELECT * FROM itops_m365_templates ORDER BY id")->fetchAll()); }
    catch (Throwable $e) { error_response('Run db-update.php once to install the onboarding templates table.', 409); }
};
if ($action === 'onboard-templates-get') {
    if (!$can('m365.view') && !itops_can($currentUser, 'onboarding', 'view')) $cap('m365.view');
    json_response(['templates' => $tplList()]);
}
if ($action === 'onboard-template-save' || $action === 'onboard-template-delete') {
    $cap('admin.connections');
    if ($action === 'onboard-template-delete') {
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) error_response('A template id is required.', 400);
        $n = $db->prepare("SELECT name FROM itops_m365_templates WHERE id=?"); $n->execute([$id]); $nm = (string)$n->fetchColumn();
        require_once __DIR__ . '/../lib/RecycleBin.php'; try { itops_bin_put($db, 'm365_template', (int)($id), $currentUser); } catch (ItopsBinRefused $__e) { error_response('Not deleted: ' . $__e->getMessage(), 409); }
        $db->prepare("DELETE FROM itops_m365_templates WHERE id=?")->execute([$id]);
        audit_log('onboard_template_delete', 'onboarding', $id, $nm, null, ['Kept in' => 'Deleted items']);
        json_response(['deleted' => true, 'templates' => $tplList()]);
    }
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') error_response('A template name is required.', 400);
    $m365In = is_array($body['m365'] ?? null) ? $body['m365'] : [];
    $licenses = array_values(array_filter(array_map('strval', (array)($m365In['licenses'] ?? [])), fn($s) => $s !== ''));
    $groups = [];
    foreach ((array)($m365In['groups'] ?? []) as $g) { $gid = trim((string)($g['id'] ?? '')); if ($gid !== '') $groups[] = ['id' => $gid, 'name' => trim((string)($g['name'] ?? $gid))]; }
    $usage = strtoupper(trim((string)($m365In['usage_location'] ?? '')));
    if ($usage !== '' && !preg_match('/^[A-Z]{2}$/', $usage)) error_response('Usage location must be a 2-letter country code.', 400);
    $allowed = array_merge(array_column(ItopsM365::settableUserFields(), 'key'), array_column($customUserFields(), 'key'));   // BT47: + the active custom fields
    $fieldsIn = is_array($m365In['fields'] ?? null) ? $m365In['fields'] : [];
    if (($m365In['department'] ?? '') !== '' && !isset($fieldsIn['department'])) $fieldsIn['department'] = $m365In['department'];
    if (($m365In['job_title'] ?? '') !== '' && !isset($fieldsIn['jobTitle'])) $fieldsIn['jobTitle'] = $m365In['job_title'];
    $fields = [];
    foreach ($fieldsIn as $k => $v) { $k = (string)$k; $v = trim((string)$v); if ($v !== '' && in_array($k, $allowed, true)) $fields[$k] = $v; }
    $mgrIn = is_array($m365In['manager'] ?? null) ? $m365In['manager'] : [];
    $manager = trim((string)($mgrIn['id'] ?? '')) !== ''
        ? ['id' => trim((string)$mgrIn['id']), 'upn' => trim((string)($mgrIn['upn'] ?? '')), 'name' => trim((string)($mgrIn['name'] ?? ''))] : null;
    $m365 = ['licenses' => $licenses, 'groups' => $groups, 'usage_location' => $usage, 'fields' => $fields, 'manager' => $manager,
             'department' => $fields['department'] ?? '', 'job_title' => $fields['jobTitle'] ?? ''];
    $desc = trim((string)($body['description'] ?? ''));
    $id = (int)($body['id'] ?? 0);
    // BT47: the default password. Sent only when it is set or replaced ('password'); 'password_clear' removes it; otherwise
    // the saved one is kept. Stored wrapped; never returned; the Audit Log says set / replaced / removed, never the value.
    $oldEnc = '';
    if ($id > 0) { try { $oq = $db->prepare('SELECT m365_json FROM itops_m365_templates WHERE id=?'); $oq->execute([$id]); $om = json_decode((string)$oq->fetchColumn(), true) ?: []; $oldEnc = (string)($om['password_enc'] ?? ''); } catch (Throwable $e) {} }
    $pwNote = null; $newEnc = $oldEnc;
    if (array_key_exists('password', $m365In) && !is_string($m365In['password'])) error_response('The default password is not in the expected form (text). Nothing was saved.', 400);
    $pwIn = (string)($m365In['password'] ?? '');
    if (!empty($m365In['password_clear'])) { if ($oldEnc !== '') { $newEnc = ''; $pwNote = ['saved', 'removed']; } }
    elseif ($pwIn !== '') {
        if ($pwIn !== trim($pwIn) || preg_match('/[\x00-\x1F\x7F]/', $pwIn)) error_response('The default password starts or ends with a space, or holds a character that cannot be typed at sign-in. Nothing was saved.', 400);
        $classes = (int)preg_match('/[a-z]/', $pwIn) + (int)preg_match('/[A-Z]/', $pwIn) + (int)preg_match('/\d/', $pwIn) + (int)preg_match('/[^A-Za-z0-9]/', $pwIn);
        if (mb_strlen($pwIn) < 8 || mb_strlen($pwIn) > 256 || $classes < 3) error_response('The default password needs at least 8 characters and three of these four: a lower-case letter, a capital letter, a digit, a symbol. Microsoft 365 refuses anything weaker. Nothing was saved.', 400);
        require_once __DIR__ . '/../lib/Secrets.php';
        if (!itops_vault_ok()) error_response('This installation cannot store secrets: VAULT_ENCRYPTION_KEY in config.php is missing or not valid. The template was not saved.', 409);
        $newEnc = (string)itops_secret_wrap_checked($pwIn);
        if ($newEnc === '') error_response('The default password could not be stored safely. Nothing was saved.', 500);
        $pwNote = [$oldEnc !== '' ? 'saved' : 'not saved', $oldEnc !== '' ? 'replaced' : 'saved'];
    }
    if ($newEnc !== '') $m365['password_enc'] = $newEnc;
    try {
        $exists = false;
        if ($id > 0) { $q = $db->prepare("SELECT 1 FROM itops_m365_templates WHERE id=?"); $q->execute([$id]); $exists = (bool)$q->fetchColumn(); }
        if ($exists) {
            $db->prepare("UPDATE itops_m365_templates SET name=?, description=?, m365_json=?, updated_by=?, updated_at=NOW() WHERE id=?")
               ->execute([$name, $desc, json_encode($m365), (int)$currentUser['id'], $id]);
        } else {
            $db->prepare("INSERT INTO itops_m365_templates (name, description, m365_json, created_by, created_at, updated_at) VALUES (?,?,?,?,NOW(),NOW())")
               ->execute([$name, $desc, json_encode($m365), (int)$currentUser['id']]);
            $id = (int)$db->lastInsertId();
        }
    } catch (Throwable $e) { error_response('Run db-update.php once to install the onboarding templates table.', 409); }
    if ($pwNote) audit_log('onboard_template_save', 'onboarding', $id, $name, ['Default password' => $pwNote[0]], ['Default password' => $pwNote[1]]);
    else audit_log('onboard_template_save', 'onboarding', $id, $name);
    $one = $db->prepare("SELECT * FROM itops_m365_templates WHERE id=?"); $one->execute([$id]);
    json_response(['saved' => true, 'template' => $tplRow($one->fetch()), 'templates' => $tplList()]);
}

// ═════════════════════ Offboard wizard ═════════════════════
// The wizard's steps run through ITOps's offboarding engine so each departure keeps a
// record (History), stored verification, monthly recertification and the extra steps.
$STEP_LABEL = [
    'm365_disable' => 'M365 · Block sign-in', 'm365_revoke_sessions' => 'M365 · Revoke sessions',
    'm365_autoreply' => 'M365 · Auto-reply (out of office)', 'm365_forward' => 'M365 · Forward new email', 'm365_delegate_onedrive' => 'M365 · Share OneDrive for handover',
    'm365_license_remove_all' => 'M365 · Remove licenses', 'm365_groups_remove_all' => 'M365 · Remove from groups',
];
$stepAllowed = function (string $type) use ($can, $currentUser): bool {
    return itops_ob_type_allowed($currentUser, $type);
};
$runRow = function (int $rid) use ($db, $currentUser) {
    require_once __DIR__ . '/../lib/ObTasks.php';
    $r = $db->prepare("SELECT * FROM itops_ob_runs WHERE id=? AND kind='offboard'"); $r->execute([$rid]); $run = $r->fetch();
    if (!$run || !itops_ob_run_visible($currentUser, $run)) error_response('That offboarding record was not found.', 404);   // BT89 (M-37)
    return $run;
};

if ($action === 'offboard-start') {
    $cap('m365.users');
    require_once __DIR__ . '/../lib/ObTasks.php';
    $mid = trim((string)($body['m365_id'] ?? '')); $upn = trim((string)($body['upn'] ?? ''));
    if ($mid !== '' && !preg_match('/^[A-Za-z0-9\-]{1,64}$/', $mid)) error_response('That Microsoft 365 account id is not valid.', 400);   // review M365B-15
    $name = trim((string)($body['display_name'] ?? '')) ?: $upn;
    if ($mid === '' || $upn === '') error_response('Pick the person who is leaving.', 400);
    if ($isSelf($mid)) error_response('You cannot offboard your own account.', 400);
    // BT124 (review M365B-3): the account acted on is the one picked (id and sign-in name are the same person), it is not
    // an emergency access account, and an account with an admin role is offboarded by a Super Admin only
    try { $who = itops_graph_get('users/' . rawurlencode($mid) . '?$select=id,userPrincipalName'); }
    catch (Throwable $e) { error_response('The person could not be read from Microsoft 365: ' . $e->getMessage(), 502); }
    if (strcasecmp((string)($who['userPrincipalName'] ?? ''), $upn) !== 0) {
        audit_log('m365_admin_target_refused', 'm365', null, mb_substr($upn, 0, 200), null, ['wanted' => 'offboard', 'reason' => 'the sign-in name sent does not belong to the account picked']);
        error_response('The sign-in name does not belong to the account picked. Reload the page and pick the person again.', 409);
    }
    $emL = array_map('strtolower', array_filter((array)json_decode((string)$db->query("SELECT sval FROM itops_settings WHERE skey='sa_emergency'")->fetchColumn(), true), 'is_string'));
    if (in_array(strtolower($upn), $emL, true)) { audit_log('m365_admin_target_refused', 'm365', null, mb_substr($upn, 0, 200), null, ['wanted' => 'offboard', 'reason' => 'emergency access account']); error_response('This is an emergency access account (Posture → Tenant profile): it is never offboarded from here.', 409); }
    $guardT($mid, 'offboard it');
    $o = is_array($body['options'] ?? null) ? $body['options'] : [];
    $email = trim((string)($body['mail'] ?? '')) ?: $upn;
    $tasks = [];
    $tasks[] = ['m365_disable', []];
    if (!empty($o['revokeSessions'])) $tasks[] = ['m365_revoke_sessions', []];
    if (!empty($o['autoreply']['on'])) $tasks[] = ['m365_autoreply', ['message' => mb_substr(trim((string)($o['autoreply']['message'] ?? '')), 0, 2000)]];
    if (!empty($o['forward']['on'])) {
        $to = trim((string)($o['forward']['to'] ?? '')); if (!filter_var($to, FILTER_VALIDATE_EMAIL)) error_response('Enter a valid address to forward new email to.', 400);
        $tasks[] = ['m365_forward', ['to' => $to]];
    }
    if (!empty($o['onedrive']['on'])) {
        $to = trim((string)($o['onedrive']['to'] ?? '')); if (!filter_var($to, FILTER_VALIDATE_EMAIL)) error_response('Enter a valid address to share the OneDrive with.', 400);
        $tasks[] = ['m365_delegate_onedrive', ['to' => $to]];
    }
    if (!empty($o['removeLicenses'])) $tasks[] = ['m365_license_remove_all', []];
    if (!empty($o['removeFromGroups'])) $tasks[] = ['m365_groups_remove_all', []];
    foreach ($tasks as [$t]) if (!$stepAllowed($t)) error_response('"' . ($STEP_LABEL[$t] ?? $t) . '" needs a permission you do not have.', 403);
    $at = trim((string)($body['schedule_at_utc'] ?? ''));
    $ts = false;   // BT89: a time without a zone is read as UTC
    if ($at !== '') { try { $ts = (new DateTime($at, new DateTimeZone('UTC')))->getTimestamp(); } catch (Throwable $e) { $ts = false; } }
    if ($at !== '' && ($ts === false || $ts < time() - 60)) error_response('Pick a time in the future.', 400);
    try {
        $db->prepare("INSERT INTO itops_ob_runs (template_id,kind,subject_name,subject_email,subject_upn,status,notes,created_by,created_at,m365_id)
                      VALUES (NULL,'offboard',?,?,?,'in_progress',?,?,NOW(),?)")
           ->execute([$name, $email, $upn, 'Started from Microsoft 365 → Accounts → Offboard', (int)$currentUser['id'], $mid]);
    } catch (Throwable $e) { error_response('Could not start the offboarding record: ' . $e->getMessage(), 500); }
    $runId = (int)$db->lastInsertId();
    $ins = $db->prepare("INSERT INTO itops_ob_run_tasks (run_id,sort_order,title,task_type,config,assignee_role,required,due_date,status) VALUES (?,?,?,?,?,NULL,1,NULL,'pending')");
    $i = 0;
    foreach ($tasks as [$t, $cfg]) {
        $ins->execute([$runId, ($i += 10), $STEP_LABEL[$t] ?? $t, $t, json_encode($cfg ?: new stdClass())]);
    }
    if ($ts) {
        $db->prepare("UPDATE itops_ob_runs SET auto_run_at=?, auto_run_status='scheduled', auto_run_by=? WHERE id=?")->execute([gmdate('Y-m-d H:i:s', $ts), (int)$currentUser['id'], $runId]);
        audit_log('offboarding_scheduled', 'onboarding', $runId, $name . ' at ' . gmdate('Y-m-d H:i:s', $ts) . ' UTC');
    }
    audit_log('offboarding_started', 'onboarding', $runId, $name . ' (Microsoft 365 → Offboard)');
    $tk = $db->prepare("SELECT id, task_type, title, status FROM itops_ob_run_tasks WHERE run_id=? ORDER BY sort_order, id"); $tk->execute([$runId]);
    json_response(['ok' => true, 'run_id' => $runId, 'tasks' => $tk->fetchAll(), 'scheduled' => $ts ? gmdate('Y-m-d H:i:s', $ts) : null]);
}

if ($action === 'offboard-step') {
    $cap('m365.users');
    require_once __DIR__ . '/../lib/ObTasks.php';
    $run = $runRow((int)($body['run_id'] ?? 0));
    $tq = $db->prepare("SELECT * FROM itops_ob_run_tasks WHERE id=? AND run_id=?"); $tq->execute([(int)($body['task_id'] ?? 0), (int)$run['id']]); $task = $tq->fetch();
    if (!$task) error_response('Step not found.', 404);
    if (!$stepAllowed($task['task_type'])) error_response('"' . $task['title'] . '" needs a permission you do not have.', 403);
    try {
        $why = trim((string)($body['override_reason'] ?? ''));
   // BT40: "it is the same person", for a vTiger user found by user name
        $r = itops_ob_task_action($db, (int)$run['id'], (int)$task['id'], ['action' => 'run'] + ($why !== '' ? ['override_reason' => mb_substr($why, 0, 500)] : []), $currentUser);
        $dirty();
        json_response(['ok' => true, 'step' => $task['title'], 'type' => $task['task_type'], 'detail' => (string)($r['result'] ?? '')]);
    } catch (ItopsObError $e) {
        json_response(['ok' => false, 'step' => $task['title'], 'type' => $task['task_type'], 'error' => $e->getMessage(), 'needs_override' => !empty($e->extra['needs_override'])]);
    } catch (Throwable $e) {
        try { $db->prepare("UPDATE itops_ob_run_tasks SET status='failed', result=? WHERE id=?")->execute([mb_substr($e->getMessage(), 0, 500), (int)$task['id']]); } catch (Throwable $x) {}
        json_response(['ok' => false, 'step' => $task['title'], 'type' => $task['task_type'], 'error' => $e->getMessage()]);
    }
}

if ($action === 'offboard-verify') {
    if (!$can('provision.offboard') && !$can('m365.users')) $cap('provision.offboard');
    require_once __DIR__ . '/../lib/ObTasks.php';
    $run = $runRow((int)($body['run_id'] ?? 0));
    $v = itops_ob_verify($db, $run, true, 'manual');
    json_response(['ok' => true, 'clean' => (bool)($v['clean'] ?? false), 'checks' => $v['checks'] ?? [], 'issues' => $v['issues'] ?? [], 'at' => $v['at'] ?? null]);
}

// Onboard wizard: keep a record of each onboarding in History (what was created, what failed).
if ($action === 'onboard-record') {
    $cap('m365.users');
    $name = trim((string)($body['display_name'] ?? '')); $upn = trim((string)($body['upn'] ?? ''));
    if ($name === '' || $upn === '') error_response('Nothing to record.', 400);
    try {
        $db->prepare("INSERT INTO itops_ob_runs (template_id,kind,subject_name,subject_email,subject_upn,status,notes,created_by,created_at,completed_at,m365_id,first_name,last_name,job_title,department)
                      VALUES (NULL,'onboard',?,?,?,'completed',?,?,NOW(),NOW(),?,?,?,?,?)")
           ->execute([$name, $upn, $upn, mb_substr('Onboarded from Microsoft 365 → Accounts → Onboard' . (($body['template'] ?? '') !== '' ? ' · template "' . $body['template'] . '"' : ''), 0, 1000),
                      (int)$currentUser['id'], (string)($body['m365_id'] ?? '') ?: null,
                      mb_substr((string)($body['first_name'] ?? ''), 0, 80) ?: null, mb_substr((string)($body['last_name'] ?? ''), 0, 80) ?: null,
                      mb_substr((string)($body['job_title'] ?? ''), 0, 120) ?: null, mb_substr((string)($body['department'] ?? ''), 0, 120) ?: null]);
    } catch (Throwable $e) { json_response(['ok' => false]); }
    $runId = (int)$db->lastInsertId();
    // BT44: these lines are the record of what the wizard already did ("recorded"). One that failed is kept for the reader
    $ins = $db->prepare("INSERT INTO itops_ob_run_tasks (run_id,sort_order,title,task_type,config,assignee_role,required,due_date,status,result,completed_by,completed_at) VALUES (?,?,?,?,'{\"recorded\":true}',NULL,1,NULL,?,?,?,NOW())");
    $i = 0;
    foreach ((array)($body['steps'] ?? []) as $s) {
        $ins->execute([$runId, ($i += 10), mb_substr((string)($s['step'] ?? ''), 0, 190), 'manual', !empty($s['ok']) ? 'done' : 'failed', mb_substr((string)($s['detail'] ?? ($s['error'] ?? '')), 0, 500), (int)$currentUser['id']]);
    }
    audit_log('onboarding_completed', 'onboarding', $runId, $name . ' (Microsoft 365 → Onboard)');
    json_response(['ok' => true, 'run_id' => $runId]);
}


// ═════════════ BT113 — Microsoft 365 redesign, phase 2: Email (Exchange Online Admin API preview, Graph message trace) ═════════════
if (in_array($action, ['exo-mailboxes', 'exo-mailbox', 'exo-delegates', 'exo-calendar', 'exo-dls', 'exo-dl-members', 'exo-trace'], true)) {
    require_once __DIR__ . '/../lib/ExchangeAdmin.php';
    // a refusal for a missing permission is written to the Audit Log too
    $capA = function (string $c, string $what) use ($can, $CAPS, $audit) {
        if ($can($c)) return;
        $audit(['action' => 'm365.mail.refused', 'system' => 'm365', 'object_type' => 'mailbox', 'record_id' => $what, 'result' => 'error', 'error' => 'no permission (' . $CAPS[$c][0] . ' ' . $CAPS[$c][1] . ')']);
        error_response('You do not have permission for this (' . $CAPS[$c][0] . ' · ' . $CAPS[$c][1] . ').', 403);
    };
    $exoErr = function (Throwable $e): array {
        $m = $e->getMessage();
        $need = stripos($m, 'ManageAsAppV2') !== false ? 'Exchange.ManageAsAppV2' : (stripos($m, 'Recipient Management') !== false ? 'Exchange role: Recipient Management' : (stripos($m, 'ExchangeMessageTrace') !== false || (stripos($m, 'messageTraces') !== false && str_contains($m, '403')) ? 'ExchangeMessageTrace.Read.All' : null));
        return ['error' => $m, 'needs_permission' => $need];
    };
    $mbx = function () use ($body, $audit, $action): string { $id = strtolower(trim((string)($_GET['id'] ?? $body['id'] ?? '')));
        if (!filter_var($id, FILTER_VALIDATE_EMAIL)) { $audit(['action' => 'm365.mail.refused', 'system' => 'm365', 'object_type' => 'mailbox', 'record_id' => mb_substr($id, 0, 120), 'result' => 'error', 'error' => $action . ': not a mailbox address']); error_response('Name the mailbox by its email address.', 400); }
        return $id; };

    if ($action === 'exo-mailboxes') {   // every mailbox (user, shared, room, equipment) with size and last activity from the usage report
        $cap('m365.view');
        $cc = !empty($_GET['refresh']) ? null : $cacheGet('exo-mailboxes', 600);
        if ($cc) { $p = $cc['payload']; $p['cached'] = true; $p['cached_at'] = date('c', $cc['ts']); json_response($p + ['may_change' => $can('m365.mail'), 'may_view' => $can('m365.mail.view')]); }
        try { $rows = itops_exo_mailboxes(); } catch (Throwable $e) { json_response($exoErr($e) + ['mailboxes' => [], 'preview' => true, 'may_change' => $can('m365.mail'), 'may_view' => $can('m365.mail.view')]); }
        $use = []; $uc = $cacheGet('m365-exchange', 86400);
        if (!$uc) { try { $uc = ['payload' => itops_m365_build('m365-exchange')]; } catch (Throwable $e) { $uc = null; } }
        foreach ((($uc['payload'] ?? [])['mailboxes'] ?? []) as $u) $use[strtolower((string)$u['upn'])] = $u;
        $sum = [];
        foreach ($rows as &$r) {
            $u = $use[$r['upn']] ?? $use[$r['address']] ?? null;
            $r['used_bytes'] = $u['used_bytes'] ?? null; $r['pct'] = $u['pct'] ?? null; $r['items'] = $u['items'] ?? null; $r['last_activity'] = $u['last_activity'] ?? null;
            $sum[$r['kind']] = ($sum[$r['kind']] ?? 0) + 1;
        }
        unset($r);
        $payload = ['mailboxes' => $rows, 'summary' => $sum, 'total' => count($rows), 'usage' => (bool)$use, 'preview' => true, 'cached' => false];
        $cachePut('exo-mailboxes', $payload);
        json_response($payload + ['may_change' => $can('m365.mail'), 'may_view' => $can('m365.mail.view')]);
    }
    if ($action === 'exo-mailbox') {     // one mailbox: delegates and calendar access
        $id = $mbx(); $capA('m365.mail.view', $id);
        try { json_response(itops_exo_mailbox($id) + ['roles' => itops_exo_cal_roles(), 'may_change' => $can('m365.mail')]); }
        catch (Throwable $e) { json_response($exoErr($e)); }
    }
    if ($action === 'exo-delegates' || $action === 'exo-calendar') {
        if ($method !== 'POST') error_response('Method not allowed', 405);
        $id = $mbx(); $capA('m365.mail', $id);
        // BT124 (code review M365A-3): mailbox access is guarded like account changes — nobody but a Super Admin gives
        // themselves access, changes the mailbox of an account with an admin role, or opens a calendar to everyone beyond
        // free/busy with subject; what there was before is kept in the Audit Log so it can be put back by hand
        $isSA = ($currentUser['role_slug'] ?? '') === 'super_admin'; $me365 = strtolower((string)($currentUser['email'] ?? ''));
        $mRefuse = function (string $why) use ($audit, $id) { $audit(['action' => 'm365.mail.refused', 'system' => 'm365', 'object_type' => 'mailbox', 'record_id' => $id, 'result' => 'error', 'error' => $why]); error_response($why, 403); };
        if (!$isSA) {
            $who = $action === 'exo-delegates' ? array_map('strtolower', array_filter((array)($body['add'] ?? []), 'is_string')) : [strtolower(trim((string)($body['user'] ?? '')))];
            if ($me365 !== '' && in_array($me365, $who, true) && ($action === 'exo-delegates' || ($body['op'] ?? '') !== 'remove')) $mRefuse('You cannot give yourself access to a mailbox: ask someone else.');
            if ($action === 'exo-calendar' && in_array(trim((string)($body['user'] ?? '')), ['Default', 'Anonymous'], true) && !in_array((string)($body['role'] ?? ''), ['None', 'AvailabilityOnly', 'LimitedDetails'], true) && ($body['op'] ?? '') !== 'remove')
                $mRefuse('Opening a calendar to everyone beyond free/busy, subject and location is done by a Super Admin.');
            require_once __DIR__ . '/../lib/M365Guard.php';
            try { $ar = itops_m365_admin_roles($id); } catch (Throwable $e) { $ar = []; }
            if ($ar) $mRefuse('This mailbox belongs to an account with an admin role (' . implode(', ', array_slice($ar, 0, 3)) . '): only a Super Admin changes its access.');
        }
        $before = null; try { $mb0 = itops_exo_mailbox($id); $before = ['send_on_behalf' => $mb0['delegates'] ?? null, 'calendar' => $mb0['calendar'] ?? null]; } catch (Throwable $e) {}
        $ok = true; $err = null;
        if ($action === 'exo-delegates') {
            $add = array_values(array_filter((array)($body['add'] ?? []), 'is_string')); $rem = array_values(array_filter((array)($body['remove'] ?? []), 'is_string'));
            try { itops_exo_set_delegates($id, $add, $rem); } catch (InvalidArgumentException $e) { $audit(['action' => 'm365.mail.refused', 'system' => 'm365', 'object_type' => 'mailbox', 'record_id' => $id, 'result' => 'error', 'error' => $e->getMessage()]); error_response($e->getMessage(), 400); }
            catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
            $audit(['action' => 'm365.mailbox.delegates', 'system' => 'm365', 'object_type' => 'mailbox', 'record_id' => $id, 'result' => $ok ? 'success' : 'error', 'error' => $err, 'detail' => ['send_on_behalf_added' => $add, 'send_on_behalf_removed' => $rem, 'before' => $before]]);
        } else {
            $user = trim((string)($body['user'] ?? '')); $role = (string)($body['role'] ?? ''); $op = (string)($body['op'] ?? '');
            try { itops_exo_set_calendar($id, $user, $role, $op); } catch (InvalidArgumentException $e) { $audit(['action' => 'm365.mail.refused', 'system' => 'm365', 'object_type' => 'mailbox', 'record_id' => $id, 'result' => 'error', 'error' => $e->getMessage()]); error_response($e->getMessage(), 400); }
            catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
            $audit(['action' => 'm365.mailbox.calendar', 'system' => 'm365', 'object_type' => 'mailbox', 'record_id' => $id, 'result' => $ok ? 'success' : 'error', 'error' => $err, 'detail' => ['before' => $before, 'change' => $op, 'person' => $user, 'access' => $op === 'remove' ? null : $role]]);
        }
        if (!$ok) error_response((string)$err, 400);
        $cacheDrop('exo-mailboxes');
        json_response(['ok' => true]);
    }
    if ($action === 'exo-dls') {         // distribution lists (Graph: mail-enabled, not security, not Microsoft 365 groups)
        $cap('m365.view');
        $cc = !empty($_GET['refresh']) ? null : $cacheGet('exo-dls', 600);
        if ($cc) { $p = $cc['payload']; $p['cached'] = true; json_response($p); }
        try {
            $rows = []; $j = itops_graph_get('groups?$filter=' . rawurlencode('mailEnabled eq true') . '&$select=id,displayName,mail,groupTypes,securityEnabled,description&$top=999');
            foreach (($j['value'] ?? []) as $g) { if (in_array('Unified', (array)($g['groupTypes'] ?? []), true)) continue;
                $rows[] = ['id' => (string)$g['id'], 'name' => (string)($g['displayName'] ?? ''), 'address' => strtolower((string)($g['mail'] ?? '')), 'security' => !empty($g['securityEnabled']), 'description' => (string)($g['description'] ?? '')]; }
            usort($rows, fn($a, $b) => strcasecmp($a['name'], $b['name']));
            $payload = ['lists' => $rows, 'total' => count($rows)]; $cachePut('exo-dls', $payload); json_response($payload);
        } catch (Throwable $e) { json_response(['error' => $e->getMessage(), 'lists' => []]); }
    }
    if ($action === 'exo-dl-members') {
        $id = $mbx(); $cap('m365.view');
        try { json_response(['members' => itops_exo_dl_members($id), 'preview' => true]); } catch (Throwable $e) { json_response($exoErr($e) + ['members' => []]); }
    }
    if ($action === 'exo-trace') {       // message trace: who sent what to whom, and whether it was delivered
        $capA('m365.mail.view', 'message trace');
        $q = fn($k) => trim((string)($_GET[$k] ?? ''));
        try { $r = itops_exo_trace($q('from'), $q('to'), $q('sender'), $q('recipient'), $q('subject')); }
        catch (InvalidArgumentException $e) { $audit(['action' => 'm365.mail.refused', 'system' => 'm365', 'object_type' => 'mailbox', 'record_id' => 'message trace', 'result' => 'error', 'error' => $e->getMessage()]); error_response($e->getMessage(), 400); }
        catch (Throwable $e) { json_response($exoErr($e) + ['rows' => []]); }
        $audit(['action' => 'm365.mail.trace', 'system' => 'm365', 'object_type' => 'mailbox', 'record_id' => 'message trace', 'result' => 'success', 'detail' => ['from' => $q('from'), 'to' => $q('to'), 'sender' => $q('sender'), 'recipient' => $q('recipient'), 'subject' => $q('subject'), 'found' => count($r['rows'])]]);
        json_response($r);
    }
}

// ═════════════ BT114 — Microsoft 365 redesign, phase 3: Devices (actions with a second person's approval, apps, configuration, update rings) ═════════════
if (in_array($action, ['approval-settings', 'dev-requests', 'dev-request', 'dev-request-decide', 'dev-request-cancel', 'dev-request-status', 'dev-action', 'intune-apps', 'intune-software', 'intune-software-devices', 'intune-config', 'ring-pause'], true)) {
    require_once __DIR__ . '/../lib/IntuneOps.php';
    require_once __DIR__ . '/../lib/Approvals.php';   // BT115: Settings → Approvals
    $A = itops_io_actions(); $me = (int)($currentUser['id'] ?? 0); $isSuper = ($currentUser['role_slug'] ?? '') === 'super_admin';
    $refuse = function (string $why, int $code, string $what, array $more = []) use ($audit) {
        $audit(['action' => 'm365.device.refused', 'system' => 'm365', 'object_type' => 'device', 'record_id' => mb_substr($what, 0, 200), 'result' => 'error', 'error' => $why, 'detail' => $more ?: null]);
        error_response($why, $code);
    };
    // BT124 (review M365B-2): an API key is never the asker or the second person of a guarded device action (every key
    // runs as user 0, so "not your own request" could not hold)
    if (!empty($currentUser['is_api_key']) && in_array($action, ['dev-request', 'dev-request-decide', 'dev-request-cancel', 'approval-settings'], true) && $method !== 'GET')
        $refuse('An API key cannot ask for, approve or cancel a guarded device action: a person does it in the app.', 403, 'API key');
    $capD = function (string $c, string $what) use ($can, $CAPS, $refuse) { if (!$can($c)) $refuse('You do not have permission for this (' . $CAPS[$c][0] . ' · ' . $CAPS[$c][1] . ').', 403, $what); };
    $gErr = function (Throwable $e, string $perm): array { $m = $e->getMessage(); return ['error' => $m, 'needs_permission' => (str_contains($m, '403') || stripos($m, 'denied') !== false || stripos($m, 'authoriz') !== false) ? $perm : null]; };
    // the people who may approve (Microsoft 365 · Retire and wipe devices, edit), active
    $wipers = function () use ($db): array {
        $out = [];
        foreach ($db->query("SELECT u.id, u.display_name, u.email, r.slug AS role_slug, r.permissions AS role_perms FROM itops_users u LEFT JOIN itops_roles r ON r.id=u.role_id WHERE u.status='active'")->fetchAll(PDO::FETCH_ASSOC) as $u)
            if (itops_can($u, 'm365_device_wipe', 'edit')) $out[(int)$u['id']] = $u;
        return $out;
    };
    $bell = function (int $uid, string $title, string $text) use ($db) { try { $db->prepare('INSERT INTO itops_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)')->execute([$uid, mb_substr($title, 0, 250), $text, 'warning', '#device_approvals']); } catch (Throwable $e) {} };
    $row = function (int $id) use ($db): ?array { $q = $db->prepare('SELECT r.*, a.display_name AS requested_by_name, b.display_name AS decided_by_name FROM itops_m365_dev_requests r LEFT JOIN itops_users a ON a.id=r.requested_by LEFT JOIN itops_users b ON b.id=r.decided_by WHERE r.id=?'); $q->execute([$id]); $r = $q->fetch(PDO::FETCH_ASSOC); return $r ?: null; };
    $expire = function () use ($db, $audit) {   // a request nobody answered within 24 hours lapses
        foreach ($db->query("SELECT id, device_name, action FROM itops_m365_dev_requests WHERE status='pending' AND expires_at < NOW()")->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $u = $db->prepare("UPDATE itops_m365_dev_requests SET status='expired', result='Nobody approved it within 24 hours.' WHERE id=? AND status='pending'"); $u->execute([(int)$x['id']]);
            if ($u->rowCount()) $audit(['action' => 'm365.device.request_expired', 'system' => 'm365', 'object_type' => 'device', 'record_id' => $x['device_name'], 'result' => 'success', 'detail' => ['request' => (int)$x['id'], 'action' => $x['action']]]);
        }
        // Review M365B-12: an approved request whose sending never finished (a stopped request) is marked, not left "approving"
        foreach ($db->query("SELECT id, device_name, action FROM itops_m365_dev_requests WHERE status='approving' AND COALESCE(decided_at, requested_at) < NOW() - INTERVAL 10 MINUTE")->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $u = $db->prepare("UPDATE itops_m365_dev_requests SET status='failed', result='The outcome is not known: the sending stopped. Check the device in Microsoft before asking again.' WHERE id=? AND status='approving'"); $u->execute([(int)$x['id']]);
            if ($u->rowCount()) $audit(['action' => 'm365.device.request_stuck', 'system' => 'm365', 'object_type' => 'device', 'record_id' => $x['device_name'], 'result' => 'failure', 'detail' => ['request' => (int)$x['id'], 'action' => $x['action']]]);
        }
    };
    $label = fn(string $a) => $A[$a][0] ?? $a;

    if ($action === 'approval-settings') {   // BT115: which changes need a second person (Super Admin changes it)
        if ($method === 'GET') {
            $cap('m365.view');
            $cat = []; foreach (itops_appr_catalog() as $k => $c) $cat[] = ['key' => $k, 'label' => $c[0], 'area' => $c[1], 'default' => $c[2], 'explains' => $c[3], 'in_use' => $c[4]];
            json_response(['settings' => itops_appr_cfg($db), 'catalog' => $cat, 'can_edit' => $isSuper, 'approvers' => count($wipers())]);
        }
        if ($method !== 'POST') error_response('Method not allowed', 405);
        if (!$isSuper) { $audit(['action' => 'm365.approvals.refused', 'system' => 'm365', 'object_type' => 'setting', 'record_id' => 'Second-person approval', 'result' => 'error', 'error' => 'only a Super Admin changes this']); error_response('Only a Super Admin changes which actions need a second person.', 403); }
        $want = (array)($body['settings'] ?? []);
        foreach ($want as $k => $v) if (!is_bool($v)) error_response('Each setting is true or false.', 400);
        [$old, $new] = itops_appr_save($db, $want);
        if ($new) {
            $L = itops_appr_catalog(); $nm = fn($k) => $L[$k][0] ?? $k;
            $audit(['action' => 'm365.approvals.changed', 'system' => 'm365', 'object_type' => 'setting', 'record_id' => 'Second-person approval', 'result' => 'success',
                    'detail' => ['changes' => array_map(fn($k) => $nm($k) . ': ' . ($old[$k] ? 'on' : 'off') . ' → ' . ($new[$k] ? 'on' : 'off'), array_keys($new))]]);
            $offs = array_keys(array_filter($new, fn($v) => !$v));
            if ($offs) foreach (array_keys(array_diff_key($wipers(), [$me => 1])) as $uid)
                try { $db->prepare('INSERT INTO itops_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)')->execute([(int)$uid, 'Second-person approval switched off',
                    ($currentUser['display_name'] ?? 'A Super Admin') . ' switched off the second person\'s approval for: ' . implode(', ', array_map($nm, $offs)) . '. One person can now do these alone (still recorded in the Audit Log).', 'warning', '#device_approvals']); } catch (Throwable $e) {}
        }
        json_response(['ok' => true, 'settings' => itops_appr_cfg($db), 'changed' => count($new)]);
    }
    if ($action === 'dev-requests') {
        $cap('m365.view'); $expire(); $mayW = $can('m365.wipe');
        $rows = $db->query("SELECT r.*, a.display_name AS requested_by_name, b.display_name AS decided_by_name FROM itops_m365_dev_requests r LEFT JOIN itops_users a ON a.id=r.requested_by LEFT JOIN itops_users b ON b.id=r.decided_by
            WHERE r.status='pending' OR r.requested_at >= NOW() - INTERVAL 90 DAY ORDER BY (r.status='pending') DESC, r.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $pend = $r['status'] === 'pending'; $mine = (int)$r['requested_by'] === $me;
            $out[] = ['id' => (int)$r['id'], 'device_id' => $r['device_id'], 'device' => $r['device_name'], 'user' => $r['device_user'], 'os' => $r['device_os'], 'owner' => $r['device_owner'], 'serial' => $r['device_serial'],
                'action' => $r['action'], 'label' => $label($r['action']), 'options' => json_decode((string)$r['options'], true) ?: (object)[], 'reason' => $r['reason'],
                'requested_by' => $r['requested_by_name'], 'requested_at' => $r['requested_at'], 'expires_at' => $r['expires_at'], 'status' => $r['status'],
                'decided_by' => $r['decided_by_name'], 'decided_at' => $r['decided_at'], 'note' => $r['decision_note'], 'sent_at' => $r['sent_at'], 'result' => $r['result'],
                'mine' => $mine, 'can_decide' => $pend && $mayW && !$mine, 'can_cancel' => $pend && ($mine || $isSuper),
                'alone' => in_array($r['status'], ['sent', 'failed'], true) && (int)$r['decided_by'] === (int)$r['requested_by']];   // BT115: approval was off
        }
        $w = $wipers();
        json_response(['requests' => $out, 'pending' => count(array_filter($out, fn($x) => $x['status'] === 'pending')), 'may_request' => $mayW, 'may_act' => $can('m365.config'), 'approval' => itops_appr_cfg($db),
                       'approvers' => count($w), 'actions' => array_map(fn($k, $a) => ['key' => $k, 'label' => $a[0], 'approval' => $a[2], 'personal' => $a[3], 'windows' => $a[4], 'explains' => $a[5], 'options' => itops_io_options($k)], array_keys($A), $A)]);
    }
    if ($action === 'dev-request') {
        if ($method !== 'POST') error_response('Method not allowed', 405);
        $did = trim((string)($body['device_id'] ?? '')); $act = (string)($body['action'] ?? ''); $what = trim((string)($body['device_name'] ?? $did));
        $capD('m365.wipe', $what);
        if (!isset($A[$act]) || !$A[$act][2]) $refuse('Choose retire, wipe or fresh start.', 400, $what);
        $reason = trim((string)($body['reason'] ?? '')); if (mb_strlen($reason) < 5) $refuse('Say why (a few words), so the person approving knows.', 400, $what, ['action' => $act]);
        try { $dev = itops_io_device($did); } catch (InvalidArgumentException $e) { $refuse($e->getMessage(), 400, $what); } catch (Throwable $e) { json_response($gErr($e, 'DeviceManagementManagedDevices.Read.All')); }
        if (!$dev) $refuse('Intune no longer has this device.', 400, $what, ['action' => $act]);
        $what = $dev['name'] ?: $did;
        if (mb_strtolower(trim((string)($body['confirm'] ?? ''))) !== mb_strtolower($dev['name'])) $refuse('Type the device name exactly to confirm.', 400, $what, ['action' => $act]);
        if ($why = itops_io_refuse($dev, $act)) $refuse($why, 400, $what, ['action' => $act, 'owner' => $dev['owner']]);
        $expire();
        $opts = []; foreach (itops_io_options($act) as $k => $def) $opts[$k] = array_key_exists($k, (array)($body['options'] ?? [])) ? !empty($body['options'][$k]) : $def;
        if (!itops_appr_needed($db, $act)) {   // BT115: second-person approval is off for this action (Settings → Approvals): sent now, on one person's word
            $note = 'Second-person approval is off for this action (Settings → Approvals).';
            $ins = function (string $st, string $res) use ($db, $dev, $act, $opts, $reason, $me, $note): int {
                $db->prepare("INSERT INTO itops_m365_dev_requests (device_id, device_name, device_user, device_os, device_owner, device_serial, action, options, reason, requested_by, requested_at, expires_at, status, decided_by, decided_at, decision_note, sent_at, result)
                              VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),?,?,NOW(),?,?,?)")
                   ->execute([$dev['id'], mb_substr($dev['name'], 0, 255), mb_substr($dev['user'], 0, 255), mb_substr(trim($dev['os'] . ' ' . $dev['os_version']), 0, 80), $dev['owner'], mb_substr($dev['serial'], 0, 120), $act, json_encode($opts), mb_substr($reason, 0, 1000), $me, $st, $me, $note, $st === 'sent' ? date('Y-m-d H:i:s') : null, mb_substr($res, 0, 1000)]);
                return (int)$db->lastInsertId();
            };
            try { itops_io_send($dev['id'], $act, $opts); }
            catch (Throwable $e) { $id = $ins('failed', 'Intune refused: ' . $e->getMessage());
                $audit(['action' => 'm365.device.' . $act, 'system' => 'm365', 'object_type' => 'device', 'record_id' => $what, 'result' => 'error', 'error' => $e->getMessage(), 'detail' => ['request' => $id, 'approval' => 'off: one person']]);
                error_response('Intune refused: ' . $e->getMessage(), 400); }
            $id = $ins('sent', 'Sent to Intune. The device acts on it the next time it checks in.');
            $audit(['action' => 'm365.device.' . $act, 'system' => 'm365', 'object_type' => 'device', 'record_id' => $what, 'result' => 'success',
                    'detail' => ['request' => $id, 'asked_by' => $currentUser['display_name'] ?? '', 'approved_by' => null, 'approval' => 'off: one person (Settings → Approvals)', 'reason' => $reason, 'options' => $opts, 'user' => $dev['user'], 'serial' => $dev['serial']]]);
            $cacheDrop('m365-intune-devices');
            json_response(['ok' => true, 'id' => $id, 'sent' => true, 'approvers' => 0]);
        }
        $q = $db->prepare("SELECT COUNT(*) FROM itops_m365_dev_requests WHERE device_id=? AND status='pending'"); $q->execute([$dev['id']]);
        if ((int)$q->fetchColumn()) $refuse('A request for this device is already waiting for approval.', 409, $what, ['action' => $act]);
        $others = array_diff_key($wipers(), [$me => 1]);
        if (!$others) $refuse('Nobody else can approve this: give a second person "Microsoft 365 · Retire and wipe devices" (Administration → Roles & Permissions), or a Super Admin can switch the second person off for this action (Settings → Approvals).', 409, $what, ['action' => $act]);
        $db->prepare("INSERT INTO itops_m365_dev_requests (device_id, device_name, device_user, device_os, device_owner, device_serial, action, options, reason, requested_by, requested_at, expires_at, status)
                      VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW() + INTERVAL 24 HOUR,'pending')")
           ->execute([$dev['id'], mb_substr($dev['name'], 0, 255), mb_substr($dev['user'], 0, 255), mb_substr(trim($dev['os'] . ' ' . $dev['os_version']), 0, 80), $dev['owner'], mb_substr($dev['serial'], 0, 120), $act, json_encode($opts), mb_substr($reason, 0, 1000), $me]);
        $id = (int)$db->lastInsertId();
        $audit(['action' => 'm365.device.requested', 'system' => 'm365', 'object_type' => 'device', 'record_id' => $what, 'result' => 'success', 'detail' => ['request' => $id, 'action' => $label($act), 'options' => $opts, 'reason' => $reason, 'user' => $dev['user'], 'serial' => $dev['serial']]]);
        foreach (array_keys($others) as $uid) $bell((int)$uid, 'Approval needed: ' . $label($act) . ' ' . $what, ($currentUser['display_name'] ?? 'Someone') . ' asks to ' . strtolower($label($act)) . ' ' . $what . ($dev['user'] ? ' (' . $dev['user'] . ')' : '') . '. Reason: ' . mb_substr($reason, 0, 200) . '. It lapses in 24 hours.');
        json_response(['ok' => true, 'id' => $id, 'approvers' => count($others)]);
    }
    if ($action === 'dev-request-decide' || $action === 'dev-request-cancel') {
        if ($method !== 'POST') error_response('Method not allowed', 405);
        $id = (int)($body['id'] ?? 0); $r = $id ? $row($id) : null; if (!$r) error_response('No such request.', 404);
        $what = $r['device_name'] ?: $r['device_id']; $note = trim((string)($body['note'] ?? ''));
        if ($action === 'dev-request-cancel') {
            if ((int)$r['requested_by'] !== $me && !$isSuper) $refuse('Only the person who asked can withdraw a request.', 403, $what, ['request' => $id]);
            $u = $db->prepare("UPDATE itops_m365_dev_requests SET status='cancelled', decided_by=?, decided_at=NOW(), decision_note=? WHERE id=? AND status='pending'"); $u->execute([$me, mb_substr($note, 0, 1000) ?: null, $id]);
            if (!$u->rowCount()) $refuse('This request is no longer waiting.', 409, $what, ['request' => $id, 'status' => $r['status']]);
            $audit(['action' => 'm365.device.request_cancelled', 'system' => 'm365', 'object_type' => 'device', 'record_id' => $what, 'result' => 'success', 'detail' => ['request' => $id, 'action' => $label($r['action']), 'note' => $note ?: null]]);
            json_response(['ok' => true]);
        }
        $capD('m365.wipe', $what);
        $dec = (string)($body['decision'] ?? ''); if (!in_array($dec, ['approve', 'deny'], true)) $refuse('Approve or deny.', 400, $what, ['request' => $id]);
        if ((int)$r['requested_by'] === $me) $refuse('Nobody approves or denies their own request: a second person must.', 403, $what, ['request' => $id]);
        $expire(); $r = $row($id);
        if ($r['status'] !== 'pending') $refuse($r['status'] === 'expired' ? 'This request lapsed (nobody approved it within 24 hours). Ask again if it is still needed.' : 'This request is no longer waiting.', 409, $what, ['request' => $id, 'status' => $r['status']]);
        if ($dec === 'deny') {
            if (mb_strlen($note) < 3) $refuse('Say why it is denied.', 400, $what, ['request' => $id]);
            $u = $db->prepare("UPDATE itops_m365_dev_requests SET status='denied', decided_by=?, decided_at=NOW(), decision_note=? WHERE id=? AND status='pending'"); $u->execute([$me, mb_substr($note, 0, 1000), $id]);
            if (!$u->rowCount()) $refuse('This request is no longer waiting.', 409, $what, ['request' => $id]);
            $audit(['action' => 'm365.device.request_denied', 'system' => 'm365', 'object_type' => 'device', 'record_id' => $what, 'result' => 'success', 'detail' => ['request' => $id, 'action' => $label($r['action']), 'note' => $note]]);
            $bell((int)$r['requested_by'], 'Denied: ' . $label($r['action']) . ' ' . $what, ($currentUser['display_name'] ?? 'Someone') . ' denied it: ' . mb_substr($note, 0, 200));
            json_response(['ok' => true, 'status' => 'denied']);
        }
        // Review M365B-13: the person who asked must still be active and still allowed to ask
        { $rq = $db->prepare("SELECT u.*, r.slug AS role_slug, r.permissions AS role_perms FROM itops_users u LEFT JOIN itops_roles r ON r.id=u.role_id WHERE u.id=?"); $rq->execute([(int)$r['requested_by']]); $ru = $rq->fetch(PDO::FETCH_ASSOC);
          if (!$ru || ($ru['status'] ?? '') !== 'active' || !itops_can($ru, 'm365_device_wipe', 'edit')) {
            $db->prepare("UPDATE itops_m365_dev_requests SET status='denied', decided_by=?, decided_at=NOW(), decision_note=? WHERE id=? AND status='pending'")->execute([$me, 'The person who asked is no longer active or no longer allowed to ask.', $id]);
            $refuse('The person who asked is no longer active or no longer has "Retire and wipe devices", so the request was closed.', 409, $what, ['request' => $id]);
          } }
        // approve: claim the request first, so two people approving at once cannot send it twice
        $u = $db->prepare("UPDATE itops_m365_dev_requests SET status='approving', decided_by=?, decided_at=NOW(), decision_note=? WHERE id=? AND status='pending'"); $u->execute([$me, mb_substr($note, 0, 1000) ?: null, $id]);
        if (!$u->rowCount()) $refuse('This request is no longer waiting.', 409, $what, ['request' => $id]);
        $fail = function (string $why) use ($db, $id, $audit, $what, $r, $label, $bell, $currentUser) {
            $db->prepare("UPDATE itops_m365_dev_requests SET status='failed', result=? WHERE id=?")->execute([mb_substr($why, 0, 1000), $id]);
            $audit(['action' => 'm365.device.' . $r['action'], 'system' => 'm365', 'object_type' => 'device', 'record_id' => $what, 'result' => 'error', 'error' => $why, 'detail' => ['request' => $id, 'approved_by' => $currentUser['display_name'] ?? '']]);
            $bell((int)$r['requested_by'], 'Not sent: ' . $label($r['action']) . ' ' . $what, $why);
            error_response($why, 400);
        };
        $opts = json_decode((string)$r['options'], true) ?: [];
        try { $dev = itops_io_device($r['device_id']); } catch (Throwable $e) { $fail('Could not read the device from Intune: ' . $e->getMessage()); }
        if (!$dev) $fail('Intune no longer has this device. Nothing was sent.');
        if (mb_strtolower($dev['name']) !== mb_strtolower((string)$r['device_name'])) $fail('The device is now called "' . $dev['name'] . '", not "' . $r['device_name'] . '". Nothing was sent: ask again for the right device.');
        if ($why = itops_io_refuse($dev, $r['action'])) $fail($why . ' Nothing was sent.');
        try { itops_io_send($dev['id'], $r['action'], $opts); } catch (Throwable $e) { $fail('Intune refused: ' . $e->getMessage()); }
        $db->prepare("UPDATE itops_m365_dev_requests SET status='sent', sent_at=NOW(), result=? WHERE id=?")->execute(['Sent to Intune. The device acts on it the next time it checks in.', $id]);
        $audit(['action' => 'm365.device.' . $r['action'], 'system' => 'm365', 'object_type' => 'device', 'record_id' => $what, 'result' => 'success',
                'detail' => ['request' => $id, 'asked_by' => $r['requested_by_name'], 'approved_by' => $currentUser['display_name'] ?? '', 'reason' => $r['reason'], 'options' => $opts, 'user' => $r['device_user'], 'serial' => $r['device_serial']]]);
        $bell((int)$r['requested_by'], 'Approved and sent: ' . $label($r['action']) . ' ' . $what, ($currentUser['display_name'] ?? 'Someone') . ' approved it. Intune sends it the next time the device checks in.');
        $cacheDrop('m365-intune-devices');
        json_response(['ok' => true, 'status' => 'sent']);
    }
    if ($action === 'dev-request-status') {
        $cap('m365.view'); $id = (int)($_GET['id'] ?? 0); $r = $id ? $row($id) : null; if (!$r) error_response('No such request.', 404);
        try { $dev = itops_io_device($r['device_id']); } catch (Throwable $e) { json_response($gErr($e, 'DeviceManagementManagedDevices.Read.All')); }
        if (!$dev) json_response(['gone' => true, 'state' => null, 'note' => 'Intune no longer lists the device (after a retire or wipe this is expected).']);
        json_response(['gone' => false, 'state' => itops_io_action_state($dev, $r['action']), 'last_sync' => $dev['last_sync']]);
    }
    if ($action === 'dev-action') {   // restart, Defender scan: one person, at once
        if ($method !== 'POST') error_response('Method not allowed', 405);
        $did = trim((string)($body['device_id'] ?? '')); $act = (string)($body['action'] ?? ''); $what = trim((string)($body['device_name'] ?? $did));
        $capD('m365.config', $what);
        if (!isset($A[$act]) || $A[$act][2]) $refuse('Retire, wipe and fresh start need a second person\'s approval.', 400, $what, ['action' => $act]);
        try { $dev = itops_io_device($did); } catch (InvalidArgumentException $e) { $refuse($e->getMessage(), 400, $what); } catch (Throwable $e) { json_response($gErr($e, 'DeviceManagementManagedDevices.Read.All')); }
        if (!$dev) $refuse('Intune no longer has this device.', 400, $what);
        $what = $dev['name'] ?: $did;
        if ($act === 'restart' && ($body['confirm'] ?? '') !== 'RESTART') $refuse('Type RESTART to confirm.', 400, $what, ['action' => $act]);
        if ($why = itops_io_refuse($dev, $act)) $refuse($why, 400, $what, ['action' => $act]);
        try { itops_io_send($dev['id'], $act); }
        catch (Throwable $e) { $audit(['action' => 'm365.device.' . $act, 'system' => 'm365', 'object_type' => 'device', 'record_id' => $what, 'result' => 'error', 'error' => $e->getMessage()]); json_response($gErr($e, 'DeviceManagementManagedDevices.PrivilegedOperations.All')); }
        $audit(['action' => 'm365.device.' . $act, 'system' => 'm365', 'object_type' => 'device', 'record_id' => $what, 'result' => 'success', 'detail' => ['user' => $dev['user']]]);
        json_response(['ok' => true, 'note' => $label($act) . ' sent. The device acts on it the next time it checks in.']);
    }
    if (in_array($action, ['intune-apps', 'intune-software', 'intune-config'], true)) {
        $cap('m365.view');
        $cc = !empty($_GET['refresh']) ? null : $cacheGet($action, 600);
        if ($cc) { $p = $cc['payload']; $p['cached'] = true; $p['cached_at'] = date('c', $cc['ts']); json_response($p + ['may_act' => $can('m365.config')]); }
        $payload = itops_m365_build($action);
        if (!empty($payload['_nocache'])) { unset($payload['_nocache']); json_response($payload + ['may_act' => $can('m365.config')]); }
        $cachePut($action, $payload); json_response($payload + ['may_act' => $can('m365.config')]);
    }
    if ($action === 'intune-software-devices') {
        $cap('m365.view');
        try { json_response(['devices' => itops_io_software_devices((string)($_GET['id'] ?? ''))]); }
        catch (InvalidArgumentException $e) { error_response($e->getMessage(), 400); }
        catch (Throwable $e) { json_response($gErr($e, 'DeviceManagementManagedDevices.Read.All') + ['devices' => []]); }
    }
    if ($action === 'ring-pause') {
        if ($method !== 'POST') error_response('Method not allowed', 405);
        $rid = trim((string)($body['id'] ?? '')); $which = (string)($body['which'] ?? ''); $paused = !empty($body['paused']); $what = trim((string)($body['name'] ?? $rid));
        $capD('m365.config', 'update ring ' . $what);
        try { itops_io_ring_pause($rid, $which, $paused); }
        catch (InvalidArgumentException $e) { $refuse($e->getMessage(), 400, 'update ring ' . $what); }
        catch (Throwable $e) { $audit(['action' => 'm365.ring.' . ($paused ? 'paused' : 'resumed'), 'system' => 'm365', 'object_type' => 'update_ring', 'record_id' => $what, 'result' => 'error', 'error' => $e->getMessage()]); json_response($gErr($e, 'DeviceManagementConfiguration.ReadWrite.All')); }
        $audit(['action' => 'm365.ring.' . ($paused ? 'paused' : 'resumed'), 'system' => 'm365', 'object_type' => 'update_ring', 'record_id' => $what, 'result' => 'success', 'detail' => ['updates' => $which]]);
        $cacheDrop('intune-config');
        json_response(['ok' => true]);
    }
}

// ═════════════ BT118 — security baselines 4c (part 1): Conditional Access changed from here, safely ═════════════
if (in_array($action, ['ca-overview', 'ca-impact', 'ca-change', 'ca-request-decide', 'ca-request-cancel', 'admin-roles', 'role-change'], true)) {   // BT119: and admin roles (same requests)
    require_once __DIR__ . '/../lib/CaOps.php';
    require_once __DIR__ . '/../lib/SecOps.php';
    require_once __DIR__ . '/../lib/Approvals.php';
    $me = (int)($currentUser['id'] ?? 0); $isSuper = ($currentUser['role_slug'] ?? '') === 'super_admin';
    if (!itops_ca_ready($db) || !itops_so_ready($db)) error_response('The database is not up to date: run the update (Settings → System → Database) first.', 503);
    $KINDS = ['create', 'state', 'delete', 'restore', 'secdef_swap'];
    $RKINDS = ['role_remove', 'role_eligible', 'role_restore'];   // BT119
    // what differs between a Conditional Access change and an admin role change (BT119)
    $Z = fn(string $k) => in_array($k, $RKINDS, true)
        ? ['pfx' => 'm365.role', 'cap' => 'm365.roles', 'perm' => 'm365_roles', 'pname' => 'Microsoft 365 · Directory roles', 'link' => '#admin_roles', 'what' => 'admin role change', 'appr' => 'admin_role', 'graph' => 'RoleManagement.ReadWrite.Directory', 'obj' => 'role']
        : ['pfx' => 'm365.ca', 'cap' => 'm365.ca', 'perm' => 'm365_ca', 'pname' => 'Microsoft 365 · Conditional Access', 'link' => '#ca_approvals', 'what' => 'Conditional Access change', 'appr' => 'ca_change', 'graph' => 'Policy.ReadWrite.ConditionalAccess', 'obj' => 'policy'];
    $refuse = function (string $why, int $code, string $what, array $more = [], string $pfx = 'm365.ca') use ($audit) {
        $audit(['action' => $pfx . '.refused', 'system' => 'm365', 'object_type' => $pfx === 'm365.ca' ? 'policy' : 'role', 'record_id' => mb_substr($what ?: 'Conditional Access', 0, 200), 'result' => 'error', 'error' => $why, 'detail' => $more ?: null]);
        error_response($why, $code);
    };
    $perm = fn(string $m, string $g = 'Policy.ReadWrite.ConditionalAccess') => (str_contains($m, '403') || stripos($m, 'denied') !== false) ? $g : null;
    // BT124 (review M365B-2): no API key asks for or decides a Conditional Access or admin role change
    if (!empty($currentUser['is_api_key']) && in_array($action, ['ca-change', 'ca-request-decide', 'ca-request-cancel', 'role-change'], true))
        $refuse('An API key cannot change Conditional Access or admin roles, nor approve such a change: a person does it in the app.', 403, 'API key', [], $action === 'role-change' ? 'm365.role' : 'm365.ca');
    $holders = function (string $perm = 'm365_ca') use ($db): array {   // who holds the permission (edit), active
        $out = [];
        foreach ($db->query("SELECT u.id, u.display_name, r.slug AS role_slug, r.permissions AS role_perms FROM itops_users u LEFT JOIN itops_roles r ON r.id=u.role_id WHERE u.status='active'")->fetchAll(PDO::FETCH_ASSOC) as $u)
            if (itops_can($u, $perm, 'edit')) $out[(int)$u['id']] = $u;
        return $out;
    };
    $bell = function (int $uid, string $title, string $text, string $link = '#ca_approvals') use ($db) { try { $db->prepare('INSERT INTO itops_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)')->execute([$uid, mb_substr($title, 0, 250), $text, 'warning', $link]); } catch (Throwable $e) {} };
    $expire = function () use ($db, $audit, $Z) {
        foreach ($db->query("SELECT id, kind, label FROM itops_sa_change_requests WHERE status='pending' AND expires_at < NOW()")->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $u = $db->prepare("UPDATE itops_sa_change_requests SET status='expired', result='Nobody approved it within 24 hours.' WHERE id=? AND status='pending'"); $u->execute([(int)$x['id']]);
            if ($u->rowCount()) $audit(['action' => $Z($x['kind'])['pfx'] . '.request_expired', 'system' => 'm365', 'object_type' => $Z($x['kind'])['obj'], 'record_id' => mb_substr($x['label'], 0, 200), 'result' => 'success', 'detail' => ['request' => (int)$x['id']]]);
        }
        foreach ($db->query("SELECT id, kind, label FROM itops_sa_change_requests WHERE status='approving' AND COALESCE(decided_at, requested_at) < NOW() - INTERVAL 10 MINUTE")->fetchAll(PDO::FETCH_ASSOC) as $x) {   // review M365B-12
            $u = $db->prepare("UPDATE itops_sa_change_requests SET status='failed', result='The outcome is not known: the sending stopped. Check it in Microsoft before asking again.' WHERE id=? AND status='approving'"); $u->execute([(int)$x['id']]);
            if ($u->rowCount()) $audit(['action' => $Z($x['kind'])['pfx'] . '.request_stuck', 'system' => 'm365', 'object_type' => $Z($x['kind'])['obj'], 'record_id' => mb_substr($x['label'], 0, 200), 'result' => 'failure', 'detail' => ['request' => (int)$x['id']]]);
        }
    };
    // run one change now and write the Audit Log (success or Microsoft's refusal); returns the result
    $run = function (string $kind, array $p, string $label, ?int $req, array $who) use ($db, $me, $audit, $Z, $RKINDS): array {
        $pfx = $Z($kind)['pfx']; $act = $pfx . '.' . preg_replace('/^role_/', '', $kind);   // m365.role.remove / .eligible / .restore
        try { $r = in_array($kind, $RKINDS, true) ? itops_so_role_exec($db, $kind, $p, $me, itops_ca_em_upns($db), $req) : itops_ca_exec($db, $kind, $p, $me, $req); }
        catch (InvalidArgumentException $e) { throw $e; }
        catch (Throwable $e) {
            $audit(['action' => $act, 'system' => 'm365', 'object_type' => $Z($kind)['obj'], 'record_id' => mb_substr($label, 0, 200), 'result' => 'error', 'error' => $e->getMessage(), 'detail' => $who + ['request' => $req]]);
            throw new RuntimeException($e->getMessage());
        }
        $audit(['action' => $act, 'system' => 'm365', 'object_type' => $Z($kind)['obj'], 'record_id' => mb_substr($r['name'] ?: $label, 0, 200), 'result' => 'success',
                'detail' => $who + ['change' => $r['summary'], 'request' => $req, 'change_id' => $r['change_id'], 'reason' => $p['_reason'] ?? null]]);
        return $r;
    };

    if ($action === 'ca-overview') {
        $cap('m365.view'); $expire();
        $out = ['may_edit' => $can('m365.ca'), 'approval' => itops_appr_needed($db, 'ca_change'), 'approvers' => count($holders()), 'me' => $me, 'errors' => []];
        $T = itops_ca_templates(); $created = itops_ca_created($db);
        $upns = itops_ca_em_upns($db);
        $out['emergency'] = []; $em = [];
        try { [$em, $miss] = $upns ? itops_ca_emergency_ids($upns) : [[], []]; foreach ($upns as $u) $out['emergency'][] = ['upn' => $u, 'found' => isset($em[strtolower($u)])]; }
        catch (Throwable $e) { $out['errors'][] = ['what' => 'Emergency access accounts', 'error' => $e->getMessage(), 'needs_permission' => str_contains($e->getMessage(), '403') ? 'User.Read.All' : null]; }
        $w = itops_ca_setting($db, 'ca_em_watch'); $out['watch_since'] = $w ?: null;
        try { $out['secdef'] = itops_ca_secdef_on(); } catch (Throwable $e) { $out['secdef'] = null; $out['errors'][] = ['what' => 'Security defaults', 'error' => $e->getMessage(), 'needs_permission' => str_contains($e->getMessage(), '403') ? 'Policy.Read.All' : null]; }
        $pol = null;
        try { $pol = itops_ca_list($em, $created); } catch (Throwable $e) { $out['errors'][] = ['what' => 'Conditional Access policies', 'error' => $e->getMessage(), 'needs_permission' => str_contains($e->getMessage(), '403') ? 'Policy.Read.All' : null]; }
        $out['policies'] = $pol; $names = []; foreach ((array)$pol as $x) $names[strtolower($x['name'])] = $x;
        $out['templates'] = [];
        foreach ($T as $k => $t) { $nm = itops_ca_prefix() . $t[0]; $ex = $names[strtolower($nm)] ?? null;
            $out['templates'][] = ['key' => $k, 'title' => $t[0], 'name' => $nm, 'rule' => $t[1], 'license' => $t[2], 'explains' => $t[3], 'policy_id' => $ex['id'] ?? null, 'state' => $ex['state'] ?? null]; }
        $out['countries'] = [];
        if ($loc = itops_ca_setting($db, 'ca_country_location')) { try { $out['countries'] = (array)(itops_graph_get('identity/conditionalAccess/namedLocations/' . $loc)['countriesAndRegions'] ?? []); } catch (Throwable $e) {} }
        $rq = $db->query("SELECT q.*, a.display_name AS by_name, b.display_name AS decided_name FROM itops_sa_change_requests q LEFT JOIN itops_users a ON a.id=q.requested_by LEFT JOIN itops_users b ON b.id=q.decided_by
            WHERE q.kind NOT LIKE 'role\\_%' AND (q.status='pending' OR q.requested_at >= NOW() - INTERVAL 30 DAY) ORDER BY (q.status='pending') DESC, q.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        $mayE = $out['may_edit'];
        $out['requests'] = array_map(fn($q) => ['id' => (int)$q['id'], 'kind' => $q['kind'], 'label' => $q['label'], 'reason' => $q['reason'], 'by' => $q['by_name'], 'at' => $q['requested_at'], 'expires_at' => $q['expires_at'],
            'status' => $q['status'], 'decided_by' => $q['decided_name'], 'decided_at' => $q['decided_at'], 'note' => $q['note'], 'result' => $q['result'], 'mine' => (int)$q['requested_by'] === $me,
            'can_decide' => $q['status'] === 'pending' && $mayE && (int)$q['requested_by'] !== $me, 'can_cancel' => $q['status'] === 'pending' && ((int)$q['requested_by'] === $me || $isSuper)], $rq);
        $ch = $db->query("SELECT c.*, a.display_name AS by_name, b.display_name AS undone_name FROM itops_sa_ca_changes c LEFT JOIN itops_users a ON a.id=c.by_user LEFT JOIN itops_users b ON b.id=c.undone_by ORDER BY c.id DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
        $out['changes'] = array_map(fn($c) => ['id' => (int)$c['id'], 'action' => $c['action'], 'policy' => $c['policy_name'], 'by' => $c['by_name'], 'at' => $c['at'], 'request' => $c['request_id'] ? (int)$c['request_id'] : null,
            'before' => json_decode((string)$c['before_json'], true), 'after' => json_decode((string)$c['after_json'], true), 'undone_at' => $c['undone_at'], 'undone_by' => $c['undone_name'],
            'can_restore' => $mayE && !$c['undone_at'] && $c['action'] !== 'restore' && !($c['action'] === 'location' && !$c['before_json'])], $ch);
        json_response($out);
    }
    if ($action === 'ca-impact') {
        $cap('m365.view');
        $id = (string)($_GET['id'] ?? ''); $days = max(1, min(30, (int)($_GET['days'] ?? 7)));
        unset($GLOBALS['ITOPS_GRAPH_TRUNCATED']);
        try { json_response(['ok' => true] + itops_ca_impact($id, $days)); }
        catch (InvalidArgumentException $e) { error_response($e->getMessage(), 400); }
        catch (Throwable $e) { json_response(['ok' => false, 'error' => $e->getMessage(), 'needs_permission' => str_contains($e->getMessage(), '403') ? 'AuditLog.Read.All' : null]); }
    }
    if ($action === 'ca-change') {
        if ($method !== 'POST') error_response('Method not allowed', 405);
        $kind = (string)($body['kind'] ?? ''); $p = (array)($body['params'] ?? []); $reason = trim((string)($body['reason'] ?? ''));
        if (!in_array($kind, $KINDS, true)) $refuse('Unknown change.', 400, $kind);
        // the names shown in the request and the Audit Log come from the tenant / the record, never from the browser
        if (in_array($kind, ['state', 'delete'], true)) { try { $pp = itops_ca_get((string)($p['id'] ?? '')); $p['name'] = (string)($pp['displayName'] ?? ''); } catch (InvalidArgumentException $e) { $refuse($e->getMessage(), 400, (string)($p['id'] ?? '')); } catch (Throwable $e) { $refuse('Could not read the policy from Microsoft: ' . $e->getMessage(), 400, (string)($p['id'] ?? '')); } }
        if ($kind === 'restore') { $q = $db->prepare('SELECT policy_name, action, undone_at FROM itops_sa_ca_changes WHERE id=?'); $q->execute([(int)($p['change_id'] ?? 0)]); $c = $q->fetch(PDO::FETCH_ASSOC);
            if (!$c) $refuse('No such change.', 404, 'change #' . (int)($p['change_id'] ?? 0)); if ($c['undone_at']) $refuse('That change was already put back.', 409, $c['policy_name']); $p['label'] = $c['policy_name']; }
        $label = itops_ca_label($kind, $p);
        if (!$can('m365.ca')) $refuse('You do not have permission for this (Microsoft 365 · Conditional Access · edit).', 403, $label);
        if ($kind === 'secdef_swap' && trim((string)($body['confirm'] ?? '')) !== 'SWITCH') $refuse('Type SWITCH to confirm.', 400, $label);
        if ($kind === 'delete' && !isset(itops_ca_created($db)[(string)($p['id'] ?? '')])) $refuse('Only policies created here can be deleted. Turn others off instead.', 400, $label);
        if ($kind === 'create' && !isset(itops_ca_templates()[(string)($p['template'] ?? '')])) $refuse('Unknown template.', 400, $label);
        if ($kind === 'state' && !in_array((string)($p['state'] ?? ''), ['enabled', 'enabledForReportingButNotEnforced', 'disabled'], true)) $refuse('Unknown state.', 400, $label);
        $clean = array_intersect_key($p, array_flip(['template', 'countries', 'id', 'state', 'change_id', 'name', 'label']));
        $expire();
        if (!itops_appr_needed($db, 'ca_change')) {
            try { $r = $run($kind, $clean + ['_reason' => $reason ?: null], $label, null, ['by' => $currentUser['display_name'] ?? '', 'approval' => 'off (Settings → Approvals)']); }
            catch (InvalidArgumentException $e) { $refuse($e->getMessage(), 400, $label); }
            catch (RuntimeException $e) { json_response(['ok' => false, 'error' => 'Microsoft refused: ' . $e->getMessage(), 'needs_permission' => $perm($e->getMessage())], 400); }
            json_response(['ok' => true, 'done' => true] + $r);
        }
        if (mb_strlen($reason) < 5) $refuse('Say why (a few words), so the person approving knows.', 400, $label);
        $q = $db->prepare("SELECT COUNT(*) FROM itops_sa_change_requests WHERE status='pending' AND label=?"); $q->execute([mb_substr($label, 0, 400)]);
        if ((int)$q->fetchColumn()) $refuse('The same change is already waiting for approval.', 409, $label);
        $others = array_diff_key($holders(), [$me => 1]);
        if (!$others) $refuse('Nobody else can approve this: give a second person "Microsoft 365 · Conditional Access" (Administration → Roles & Permissions), or a Super Admin can switch the second person off for this (Settings → Approvals).', 409, $label);
        $db->prepare("INSERT INTO itops_sa_change_requests (kind, params, label, reason, requested_by, requested_at, expires_at, status) VALUES (?,?,?,?,?,NOW(),NOW() + INTERVAL 24 HOUR,'pending')")
           ->execute([$kind, json_encode($clean, JSON_UNESCAPED_UNICODE), mb_substr($label, 0, 400), mb_substr($reason, 0, 1000), $me]);
        $id = (int)$db->lastInsertId();
        $audit(['action' => 'm365.ca.requested', 'system' => 'm365', 'object_type' => 'policy', 'record_id' => mb_substr($label, 0, 200), 'result' => 'success', 'detail' => ['request' => $id, 'reason' => $reason]]);
        foreach (array_keys($others) as $uid) $bell((int)$uid, 'Approval needed: Conditional Access', ($currentUser['display_name'] ?? 'Someone') . ' asks to: ' . $label . '. Reason: ' . mb_substr($reason, 0, 200) . '. It lapses in 24 hours.');
        json_response(['ok' => true, 'done' => false, 'id' => $id, 'approvers' => count($others)]);
    }
    if ($action === 'ca-request-decide' || $action === 'ca-request-cancel') {
        if ($method !== 'POST') error_response('Method not allowed', 405);
        $id = (int)($body['id'] ?? 0); $q = $db->prepare('SELECT q.*, a.display_name AS by_name FROM itops_sa_change_requests q LEFT JOIN itops_users a ON a.id=q.requested_by WHERE q.id=?'); $q->execute([$id]); $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) error_response('No such request.', 404);
        $what = $r['label']; $note = trim((string)($body['note'] ?? '')); $z = $Z($r['kind']);
        if ($action === 'ca-request-cancel') {
            if ((int)$r['requested_by'] !== $me && !$isSuper) $refuse('Only the person who asked can withdraw a request.', 403, $what, ['request' => $id], $z['pfx']);
            $u = $db->prepare("UPDATE itops_sa_change_requests SET status='cancelled', decided_by=?, decided_at=NOW(), note=? WHERE id=? AND status='pending'"); $u->execute([$me, mb_substr($note, 0, 1000) ?: null, $id]);
            if (!$u->rowCount()) $refuse('This request is no longer waiting.', 409, $what, ['request' => $id], $z['pfx']);
            $audit(['action' => $z['pfx'] . '.request_cancelled', 'system' => 'm365', 'object_type' => $z['obj'], 'record_id' => mb_substr($what, 0, 200), 'result' => 'success', 'detail' => ['request' => $id, 'note' => $note ?: null]]);
            json_response(['ok' => true]);
        }
        if (!$can($z['cap'])) $refuse('You do not have permission for this (' . $z['pname'] . ' · edit).', 403, $what, ['request' => $id], $z['pfx']);
        $dec = (string)($body['decision'] ?? ''); if (!in_array($dec, ['approve', 'deny'], true)) $refuse('Approve or deny.', 400, $what, ['request' => $id], $z['pfx']);
        if ((int)$r['requested_by'] === $me) $refuse('Nobody approves or denies their own request: a second person must.', 403, $what, ['request' => $id], $z['pfx']);
        $expire(); $q->execute([$id]); $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($r['status'] !== 'pending') $refuse($r['status'] === 'expired' ? 'This request lapsed (nobody approved it within 24 hours). Ask again if it is still needed.' : 'This request is no longer waiting.', 409, $what, ['request' => $id, 'status' => $r['status']], $z['pfx']);
        if ($dec === 'deny') {
            if (mb_strlen($note) < 3) $refuse('Say why it is denied.', 400, $what, ['request' => $id], $z['pfx']);
            $u = $db->prepare("UPDATE itops_sa_change_requests SET status='denied', decided_by=?, decided_at=NOW(), note=? WHERE id=? AND status='pending'"); $u->execute([$me, mb_substr($note, 0, 1000), $id]);
            if (!$u->rowCount()) $refuse('This request is no longer waiting.', 409, $what, ['request' => $id], $z['pfx']);
            $audit(['action' => $z['pfx'] . '.request_denied', 'system' => 'm365', 'object_type' => $z['obj'], 'record_id' => mb_substr($what, 0, 200), 'result' => 'success', 'detail' => ['request' => $id, 'note' => $note]]);
            $bell((int)$r['requested_by'], 'Denied: ' . $z['what'], ($currentUser['display_name'] ?? 'Someone') . ' denied "' . $what . '": ' . mb_substr($note, 0, 200), $z['link']);
            json_response(['ok' => true, 'status' => 'denied']);
        }
        { $rq = $db->prepare("SELECT u.*, r.slug AS role_slug, r.permissions AS role_perms FROM itops_users u LEFT JOIN itops_roles r ON r.id=u.role_id WHERE u.id=?"); $rq->execute([(int)$r['requested_by']]); $ru = $rq->fetch(PDO::FETCH_ASSOC);   // review M365B-13
          $needMod = strpos((string)$r['kind'], 'role_') === 0 ? 'm365_roles' : 'm365_ca';
          if (!$ru || ($ru['status'] ?? '') !== 'active' || !itops_can($ru, $needMod, 'edit')) {
            $db->prepare("UPDATE itops_sa_change_requests SET status='denied', decided_by=?, decided_at=NOW(), note=? WHERE id=? AND status='pending'")->execute([$me, 'The person who asked is no longer active or no longer allowed to ask.', $id]);
            $refuse('The person who asked is no longer active or no longer allowed to make this change, so the request was closed.', 409, $what, ['request' => $id], $z['pfx']);
          } }
        $u = $db->prepare("UPDATE itops_sa_change_requests SET status='approving', decided_by=?, decided_at=NOW(), note=? WHERE id=? AND status='pending'"); $u->execute([$me, mb_substr($note, 0, 1000) ?: null, $id]);
        if (!$u->rowCount()) $refuse('This request is no longer waiting.', 409, $what, ['request' => $id], $z['pfx']);
        $fin = function (string $st, string $res) use ($db, $id) { $db->prepare('UPDATE itops_sa_change_requests SET status=?, result=? WHERE id=?')->execute([$st, mb_substr($res, 0, 1000), $id]); };
        $who = ['asked_by' => $r['by_name'], 'approved_by' => $currentUser['display_name'] ?? '', 'reason' => $r['reason']];
        try { $res = $run($r['kind'], (json_decode((string)$r['params'], true) ?: []) + ['_reason' => $r['reason']], $what, $id, $who); }
        catch (InvalidArgumentException $e) { $fin('failed', $e->getMessage() . ' Nothing was changed.'); $bell((int)$r['requested_by'], 'Not done: ' . $z['what'], $e->getMessage(), $z['link']); $refuse($e->getMessage() . ' Nothing was changed.', 400, $what, ['request' => $id], $z['pfx']); }
        catch (RuntimeException $e) { $fin('failed', 'Microsoft refused: ' . $e->getMessage()); $bell((int)$r['requested_by'], 'Not done: ' . $z['what'], 'Microsoft refused: ' . $e->getMessage(), $z['link']);
            json_response(['ok' => false, 'error' => 'Microsoft refused: ' . $e->getMessage(), 'needs_permission' => $perm($e->getMessage(), $z['graph'])], 400); }
        $fin('done', $res['summary']);
        $bell((int)$r['requested_by'], 'Approved and done: ' . $z['what'], ($currentUser['display_name'] ?? 'Someone') . ' approved it. ' . $res['summary'], $z['link']);
        json_response(['ok' => true, 'status' => 'done'] + $res);
    }
    // ── BT119: admin roles ──
    if ($action === 'admin-roles') {
        $cap('m365.view'); $expire();
        $mayE = $can('m365.roles'); $em = itops_ca_em_upns($db);
        $out = ['may_edit' => $mayE, 'approval' => itops_appr_needed($db, 'admin_role'), 'approvers' => count($holders('m365_roles')), 'emergency' => $em];
        try { $out += itops_so_admins($em); $out['error'] = null; }
        catch (Throwable $e) { $out['people'] = null; $out['error'] = $e->getMessage(); $out['needs_permission'] = str_contains($e->getMessage(), '403') ? 'RoleManagement.Read.Directory' : null; }
        $rq = $db->query("SELECT q.*, a.display_name AS by_name, b.display_name AS decided_name FROM itops_sa_change_requests q LEFT JOIN itops_users a ON a.id=q.requested_by LEFT JOIN itops_users b ON b.id=q.decided_by
            WHERE q.kind LIKE 'role\\_%' AND (q.status='pending' OR q.requested_at >= NOW() - INTERVAL 30 DAY) ORDER BY (q.status='pending') DESC, q.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        $out['requests'] = array_map(fn($q) => ['id' => (int)$q['id'], 'kind' => $q['kind'], 'label' => $q['label'], 'reason' => $q['reason'], 'by' => $q['by_name'], 'at' => $q['requested_at'], 'expires_at' => $q['expires_at'],
            'status' => $q['status'], 'decided_by' => $q['decided_name'], 'note' => $q['note'], 'result' => $q['result'], 'mine' => (int)$q['requested_by'] === $me,
            'can_decide' => $q['status'] === 'pending' && $mayE && (int)$q['requested_by'] !== $me, 'can_cancel' => $q['status'] === 'pending' && ((int)$q['requested_by'] === $me || $isSuper)], $rq);
        $ch = $db->query("SELECT c.*, a.display_name AS by_name, b.display_name AS undone_name FROM itops_m365_role_changes c LEFT JOIN itops_users a ON a.id=c.by_user LEFT JOIN itops_users b ON b.id=c.undone_by ORDER BY c.id DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
        $out['changes'] = array_map(fn($c) => ['id' => (int)$c['id'], 'action' => $c['action'], 'who' => $c['principal_name'], 'role' => $c['role_name'], 'by' => $c['by_name'], 'at' => $c['at'],
            'request' => $c['request_id'] ? (int)$c['request_id'] : null, 'undone_at' => $c['undone_at'], 'undone_by' => $c['undone_name'],
            'can_restore' => $mayE && !$c['undone_at'] && $c['action'] !== 'role_restore'], $ch);
        json_response($out);
    }
    if ($action === 'role-change') {
        if ($method !== 'POST') error_response('Method not allowed', 405);
        $kind = (string)($body['kind'] ?? ''); $p = (array)($body['params'] ?? []); $reason = trim((string)($body['reason'] ?? '')); $R = 'm365.role';
        if (!in_array($kind, $RKINDS, true)) $refuse('Unknown change.', 400, $kind, [], $R);
        try { $d = itops_so_role_describe($db, $kind, $p); }   // who and which role, from Microsoft or the record, never the browser
        catch (InvalidArgumentException $e) { $refuse($e->getMessage(), 400, (string)($p['assignment_id'] ?? $p['change_id'] ?? ''), [], $R); }
        catch (Throwable $e) { $refuse('Could not read the role from Microsoft: ' . $e->getMessage(), 400, (string)($p['assignment_id'] ?? ''), [], $R); }
        $label = itops_so_role_label($kind, $d);
        if (!$can('m365.roles')) $refuse('You do not have permission for this (Microsoft 365 · Directory roles · edit).', 403, $label, [], $R);
        // BT124 (review M365B-4): the same rules as Microsoft 365 → People: a high-risk role (Global Administrator and the
        // like) is changed by a Super Admin only, and nobody changes the roles of their own account
        require_once __DIR__ . '/../lib/M365Guard.php';
        if (!$isSuper && itops_m365_role_is_high((string)($d['role_id'] ?? ''), (string)$d['role'])) $refuse('Only a Super Admin can change ' . $d['role'] . '.', 403, $label, [], $R);
        if (($d['principal_id'] ?? '') !== '' && $isSelf((string)$d['principal_id'])) $refuse('You cannot change the admin roles of your own account.', 400, $label, [], $R);
        if ($kind === 'role_remove' && strtolower(trim((string)($body['confirm'] ?? ''))) !== strtolower((string)$d['who'])) $refuse('Type the account\'s address exactly to confirm.', 400, $label, [], $R);
        $clean = array_intersect_key($d, array_flip(['assignment_id', 'change_id', 'who', 'role']));
        $expire();
        if (!itops_appr_needed($db, 'admin_role')) {
            try { $r = $run($kind, $clean + ['_reason' => $reason ?: null], $label, null, ['by' => $currentUser['display_name'] ?? '', 'approval' => 'off (Settings → Approvals)']); }
            catch (InvalidArgumentException $e) { $refuse($e->getMessage(), 400, $label, [], $R); }
            catch (RuntimeException $e) { json_response(['ok' => false, 'error' => 'Microsoft refused: ' . $e->getMessage(), 'needs_permission' => $perm($e->getMessage(), $kind === 'role_eligible' ? 'RoleEligibilitySchedule.ReadWrite.Directory' : 'RoleManagement.ReadWrite.Directory')], 400); }
            json_response(['ok' => true, 'done' => true] + $r);
        }
        if (mb_strlen($reason) < 5) $refuse('Say why (a few words), so the person approving knows.', 400, $label, [], $R);
        $q = $db->prepare("SELECT COUNT(*) FROM itops_sa_change_requests WHERE status='pending' AND label=?"); $q->execute([mb_substr($label, 0, 400)]);
        if ((int)$q->fetchColumn()) $refuse('The same change is already waiting for approval.', 409, $label, [], $R);
        $others = array_diff_key($holders('m365_roles'), [$me => 1]);
        if (!$others) $refuse('Nobody else can approve this: give a second person "Microsoft 365 · Directory roles" (Administration → Roles & Permissions), or a Super Admin can switch the second person off for this (Settings → Approvals).', 409, $label, [], $R);
        $db->prepare("INSERT INTO itops_sa_change_requests (kind, params, label, reason, requested_by, requested_at, expires_at, status) VALUES (?,?,?,?,?,NOW(),NOW() + INTERVAL 24 HOUR,'pending')")
           ->execute([$kind, json_encode($clean, JSON_UNESCAPED_UNICODE), mb_substr($label, 0, 400), mb_substr($reason, 0, 1000), $me]);
        $id = (int)$db->lastInsertId();
        $audit(['action' => 'm365.role.requested', 'system' => 'm365', 'object_type' => 'role', 'record_id' => mb_substr($label, 0, 200), 'result' => 'success', 'detail' => ['request' => $id, 'reason' => $reason]]);
        foreach (array_keys($others) as $uid) $bell((int)$uid, 'Approval needed: admin role', ($currentUser['display_name'] ?? 'Someone') . ' asks to: ' . $label . '. Reason: ' . mb_substr($reason, 0, 200) . '. It lapses in 24 hours.', '#admin_roles');
        json_response(['ok' => true, 'done' => false, 'id' => $id, 'approvers' => count($others)]);
    }
}

// ═════════════ BT119 — security baselines 4c (part 2): Defender incidents handled from here ═════════════
if (in_array($action, ['incident', 'incident-update', 'incident-comment'], true)) {
    require_once __DIR__ . '/../lib/SecOps.php';
    if (!itops_so_ready($db)) error_response('The database is not up to date: run the update (Settings → System → Database) first.', 503);
    $id = (string)($method === 'GET' ? ($_GET['id'] ?? '') : ($body['id'] ?? ''));
    $refuse = function (string $why, int $code, string $what, array $more = []) use ($audit) {
        $audit(['action' => 'm365.incident.refused', 'system' => 'm365', 'object_type' => 'incident', 'record_id' => mb_substr($what, 0, 200), 'result' => 'error', 'error' => $why, 'detail' => $more ?: null]);
        error_response($why, $code);
    };
    $read = function () use ($id, $refuse): array {
        try { return itops_so_incident($id); }
        catch (InvalidArgumentException $e) { $refuse($e->getMessage(), 400, 'incident ' . mb_substr($id, 0, 40)); }
        catch (Throwable $e) { json_response(['ok' => false, 'error' => $e->getMessage(), 'needs_permission' => (str_contains($e->getMessage(), '403') ? 'SecurityIncident.Read.All' : null)], str_contains($e->getMessage(), '404') ? 404 : 200); }
        return [];
    };
    if ($action === 'incident') {
        $cap('m365.view'); $inc = $read();
        $tk = null;   // 0001-A005: no Service Desk tickets in this product
        $cats = [];
        $people = [];
        foreach ($db->query("SELECT u.id, u.display_name, u.email, r.slug AS role_slug, r.permissions AS role_perms FROM itops_users u LEFT JOIN itops_roles r ON r.id=u.role_id WHERE u.status='active' ORDER BY u.display_name")->fetchAll(PDO::FETCH_ASSOC) as $u)
            if (itops_can($u, 'm365_incidents', 'edit')) $people[] = ['id' => (int)$u['id'], 'name' => $u['display_name'], 'email' => strtolower((string)$u['email'])];
        $C = []; foreach (itops_so_classes() as $k => $c) $C[] = ['key' => $k, 'label' => $c[0], 'determinations' => array_map(fn($a, $b) => ['key' => $a, 'label' => $b], array_keys($c[1]), $c[1])];
        json_response(['ok' => true, 'incident' => $inc, 'ticket' => $tk ? ['id' => (int)$tk['ticket_id'], 'number' => $tk['ticket_number'], 'status' => $tk['status']] : null,
                       'may_edit' => $can('m365.incidents'), 'me' => strtolower((string)($currentUser['email'] ?? '')), 'people' => $people, 'categories' => $cats, 'classes' => $C]);
    }
    if ($method !== 'POST') error_response('Method not allowed', 405);
    if (!$can('m365.incidents')) $refuse('You do not have permission for this (Microsoft 365 · Security incidents · edit).', 403, 'incident ' . mb_substr($id, 0, 40));
    $inc = $read(); $what = 'Incident ' . $inc['id'] . ': ' . $inc['name'];
    if ($action === 'incident-update') {
        try { [$patch, $say] = itops_so_inc_patch($inc, $body); } catch (InvalidArgumentException $e) { $refuse($e->getMessage(), 400, $what); }
        try { itops_so_inc_send($inc['id'], $patch); }
        catch (Throwable $e) {
            $audit(['action' => 'm365.incident.updated', 'system' => 'm365', 'object_type' => 'incident', 'record_id' => mb_substr($what, 0, 200), 'result' => 'error', 'error' => $e->getMessage(), 'detail' => ['wanted' => $say]]);
            json_response(['ok' => false, 'error' => 'Microsoft refused: ' . $e->getMessage(), 'needs_permission' => (str_contains($e->getMessage(), '403') ? 'SecurityIncident.ReadWrite.All' : null)], 400);
        }
        $audit(['action' => 'm365.incident.' . (($patch['status'] ?? '') === 'resolved' ? 'resolved' : 'updated'), 'system' => 'm365', 'object_type' => 'incident', 'record_id' => mb_substr($what, 0, 200), 'result' => 'success',
                'detail' => ['change' => $say, 'before' => ['status' => $inc['status'], 'assigned' => $inc['assigned'] ?: '(nobody)', 'classification' => $inc['classification']], 'comment' => $patch['resolvingComment'] ?? null]]);
        json_response(['ok' => true, 'summary' => ucfirst($say)]);
    }
    if ($action === 'incident-comment') {
        $text = (string)($body['comment'] ?? '');
        try { itops_so_inc_comment($inc['id'], $text); }
        catch (InvalidArgumentException $e) { $refuse($e->getMessage(), 400, $what); }
        catch (Throwable $e) { $audit(['action' => 'm365.incident.comment', 'system' => 'm365', 'object_type' => 'incident', 'record_id' => mb_substr($what, 0, 200), 'result' => 'error', 'error' => $e->getMessage()]);
            json_response(['ok' => false, 'error' => 'Microsoft refused: ' . $e->getMessage(), 'needs_permission' => (str_contains($e->getMessage(), '403') ? 'SecurityIncident.ReadWrite.All' : null)], 400); }
        $audit(['action' => 'm365.incident.comment', 'system' => 'm365', 'object_type' => 'incident', 'record_id' => mb_substr($what, 0, 200), 'result' => 'success', 'detail' => ['comment' => mb_substr(trim($text), 0, 1000)]]);
        json_response(['ok' => true]);
    }
}


error_response('Unknown action.', 404);
