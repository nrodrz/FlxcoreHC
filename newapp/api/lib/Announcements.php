<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * BT28 — Announcements.
 *
 * One announcement, several channels: the app itself (bell + the Announcements page), email and — when a Teams
 * channel is connected — a card posted to that channel. No channel is the only one: the record, the list of
 * recipients and who has read it always live here.
 *
 *   opsh_announcements             the announcement (text, optional Spanish text, level, audience, channels, dates)
 *   opsh_announcement_audience     who it is for when the audience is "selected" (user | department | role)
 *   opsh_announcement_recipients   one row per person it was published to: email state, seen, acknowledged, reminded
 *
 * Recipients are fixed at the moment of publishing (people added later are not included).
 * All date-times in these tables are UTC.
 * Email is queued per recipient and sent in small batches (at publish, by cron-sla.php and by the in-app scheduler)
 * so publishing to a whole organisation never hangs a request.
 */

const OPSH_ANN_LEVELS      = ['info', 'important', 'urgent'];
const OPSH_ANN_AUTO_REMIND = 5;     // automatic reminders stop after this many
const OPSH_ANN_BODY_MAX    = 20000;

function opsh_ann_ready(PDO $db): bool
{
    static $ok = null;
    if ($ok === null) { try { $db->query('SELECT 1 FROM opsh_announcement_recipients LIMIT 1'); $ok = true; } catch (Throwable $e) { $ok = false; } }
    return $ok;
}
function opsh_ann_now(): string { return gmdate('Y-m-d H:i:s'); }
function opsh_ann_iso(?string $dt): ?string { return $dt ? str_replace(' ', 'T', $dt) . 'Z' : null; }
/** Any date-time the client sends (ISO with a zone) → UTC 'Y-m-d H:i:s', or null. */
function opsh_ann_dt($v): ?string
{
    if ($v === null || $v === '') return null;
    $t = strtotime((string)$v);
    return ($t === false || $t <= 0) ? null : gmdate('Y-m-d H:i:s', $t);
}
function opsh_ann_link(): string { return (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/#announcements'; }
function opsh_ann_cut(string $s, int $n): string { $s = trim(preg_replace('/\s+/', ' ', $s)); return mb_strlen($s) > $n ? rtrim(mb_substr($s, 0, $n - 1)) . '…' : $s; }
/** Markdown-lite → plain words (for the bell and for short summaries). */
function opsh_ann_plain(string $s): string
{
    $s = preg_replace('/\[([^\]]+)\]\(([^)\s]+)\)/', '$1', $s);
    $s = preg_replace('/\*\*(.+?)\*\*/s', '$1', $s);
    return trim(preg_replace('/^\s*[-•]\s+/m', '', $s));
}
/** Title / body in the reader's language (Spanish only when a Spanish version was written). */
function opsh_ann_text(array $a, ?string $lang): array
{
    $es = $lang === 'es' && trim((string)($a['title_es'] ?? '')) !== '' && trim((string)($a['body_es'] ?? '')) !== '';
    return $es ? [(string)$a['title_es'], (string)$a['body_es']] : [(string)$a['title'], (string)$a['body']];
}

// ───────────────────────────── Audience ─────────────────────────────

function opsh_ann_audience_get(PDO $db, int $id): array
{
    $out = ['users' => [], 'departments' => [], 'roles' => []];
    $st = $db->prepare('SELECT kind, ref_id FROM opsh_announcement_audience WHERE announcement_id=?'); $st->execute([$id]);
    foreach ($st->fetchAll() as $r) {
        if ($r['kind'] === 'user') $out['users'][] = (int)$r['ref_id'];
        elseif ($r['kind'] === 'department') $out['departments'][] = (int)$r['ref_id'];
        elseif ($r['kind'] === 'role') $out['roles'][] = (int)$r['ref_id'];
    }
    return $out;
}
function opsh_ann_audience_clean(array $body): array
{
    $ids = fn($k) => array_values(array_unique(array_filter(array_map('intval', (array)($body[$k] ?? [])))));
    return ['users' => $ids('audience_users'), 'departments' => $ids('audience_departments'), 'roles' => $ids('audience_roles')];
}
function opsh_ann_audience_save(PDO $db, int $id, string $audience, array $aud): void
{
    $db->prepare('DELETE FROM opsh_announcement_audience WHERE announcement_id=?')->execute([$id]);
    if ($audience !== 'selected') return;
    $ins = $db->prepare('INSERT IGNORE INTO opsh_announcement_audience (announcement_id, kind, ref_id) VALUES (?,?,?)');
    foreach (['users' => 'user', 'departments' => 'department', 'roles' => 'role'] as $k => $kind) foreach ($aud[$k] as $ref) $ins->execute([$id, $kind, $ref]);
}
/** The active people an audience resolves to: [id => {id, display_name, email, language}]. */
function opsh_ann_audience_users(PDO $db, string $audience, array $aud): array
{
    $out = [];
    $rows = $db->query("SELECT id, display_name, email, language, department_id, role_id FROM opsh_users WHERE status='active' ORDER BY display_name")->fetchAll();
    foreach ($rows as $u) {
        if ($audience === 'selected'
            && !in_array((int)$u['id'], $aud['users'], true)
            && !($u['department_id'] && in_array((int)$u['department_id'], $aud['departments'], true))
            && !($u['role_id'] && in_array((int)$u['role_id'], $aud['roles'], true))) continue;
        $out[(int)$u['id']] = ['id' => (int)$u['id'], 'display_name' => (string)$u['display_name'], 'email' => (string)$u['email'], 'language' => (string)($u['language'] ?? '')];
    }
    return $out;
}
/** "Everyone" or "2 departments, 1 role, 3 people" — for lists. */
function opsh_ann_audience_label(string $audience, array $aud, ?array $ext = null): string
{
    if ($audience !== 'selected') return 'Everyone';
    $p = [];
    foreach (['departments' => ['department', 'departments'], 'roles' => ['role', 'roles'], 'users' => ['person', 'people']] as $k => $w) {
        $n = count($aud[$k]); if ($n) $p[] = $n . ' ' . $w[$n === 1 ? 0 : 1];
    }
    if ($ext) {   // BT94
        $n = count($ext['groups']); if ($n) $p[] = $n . ' Microsoft 365 group' . ($n === 1 ? '' : 's');
        $n = count($ext['emails']); if ($n) $p[] = $n . ' email address' . ($n === 1 ? '' : 'es');
        $n = count($ext['people'] ?? []); if ($n) $p[] = $n . ' ' . ($n === 1 ? 'person' : 'people') . ' from Microsoft 365';
        if (!empty($ext['tenant'])) array_unshift($p, 'Everyone in Microsoft 365' . (!empty($ext['domains']) ? ' at ' . implode(', ', $ext['domains']) : '') . ($ext['tenant'] === 'licensed' ? ' (with a license)' : ''));
    }
    return $p ? implode(', ', $p) : 'Nobody selected';
}

// ───────────────────────────── BT94: people outside the app ─────────────────────────────
// An announcement for chosen people can also go to Microsoft 365 groups / distribution lists (their members are read
// from Microsoft 365 when it is published) and to typed email addresses. A person who has an account in the app is
// treated as a normal recipient (bell, the usual email, read in the app). Everyone else gets their own email with a
// personal link: opening it counts as opened, and "I have read this" confirms it — no account needed.

const OPSH_ANN_EXT_MAX = 2000;   // people outside the app per announcement

function opsh_ann_ext_ready(PDO $db): bool
{
    static $ok = null;
    if ($ok === null) { try { $db->query('SELECT 1 FROM opsh_announcement_external LIMIT 0'); $db->query('SELECT 1 FROM opsh_announcement_audience_ext LIMIT 0'); $ok = true; } catch (Throwable $e) { $ok = false; } }
    return $ok;
}
/** BT136: is anything outside the app chosen (a group, an address, a person found in Microsoft 365, everyone in it)? */
function opsh_ann_ext_any(array $x): bool { return !empty($x['groups']) || !empty($x['emails']) || !empty($x['people']) || !empty($x['tenant']); }
/** ['groups' => [['id','name']], 'emails' => ['a@b.org', …], 'people' => [['email','name']], 'tenant' => '' | 'licensed' | 'all'] */
function opsh_ann_ext_get(PDO $db, int $id): array
{
    $out = ['groups' => [], 'emails' => [], 'people' => [], 'tenant' => '', 'domains' => []];
    if (!opsh_ann_ext_ready($db)) return $out;
    $st = $db->prepare('SELECT kind, ref, label FROM opsh_announcement_audience_ext WHERE announcement_id=? ORDER BY id'); $st->execute([$id]);
    foreach ($st->fetchAll() as $r) {
        if ($r['kind'] === 'group') $out['groups'][] = ['id' => (string)$r['ref'], 'name' => (string)$r['label']];
        elseif ($r['kind'] === 'email') $out['emails'][] = (string)$r['ref'];
        elseif ($r['kind'] === 'person') $out['people'][] = ['email' => (string)$r['ref'], 'name' => (string)$r['label']];   // BT136
        elseif ($r['kind'] === 'tenant') $out['tenant'] = (string)$r['ref'] === 'all' ? 'all' : 'licensed';                // BT136
        elseif ($r['kind'] === 'domain') $out['domains'][] = (string)$r['ref'];                                              // BT136
    }
    return $out;
}
/** Email addresses typed or pasted (commas, semicolons, spaces or new lines; "Name <a@b.org>" is fine). Throws listing the bad ones. */
function opsh_ann_parse_emails($in, int $max = 500): array
{
    $txt = is_array($in) ? implode("\n", array_map('strval', $in)) : (string)$in;
    $txt = preg_replace('/"[^"]*"|\([^)]*\)/', ' ', $txt);
    $parts = preg_split('/[\s,;]+/', str_replace(['<', '>'], ' ', $txt), -1, PREG_SPLIT_NO_EMPTY);
    $ok = []; $bad = [];
    foreach ($parts as $p) {
        if (strpos($p, '@') === false) continue;   // a name word next to an address
        $e = strtolower(trim($p, " \t'\"."));
        if (filter_var($e, FILTER_VALIDATE_EMAIL) && strlen($e) <= 320) $ok[$e] = true; else $bad[] = $p;
    }
    if ($bad) throw new InvalidArgumentException('These are not email addresses: ' . implode(', ', array_slice($bad, 0, 5)) . (count($bad) > 5 ? ' …' : '') . '.');
    if (count($ok) > $max) throw new InvalidArgumentException('Up to ' . $max . ' typed addresses. For more people, use a Microsoft 365 group.');
    return array_keys($ok);
}
/** Clean what the editor sends: audience_groups [{id, name}] and audience_emails (text or list). */
function opsh_ann_ext_clean(array $body): array
{
    $groups = [];
    foreach ((array)($body['audience_groups'] ?? []) as $g) {
        $gid = strtolower(trim((string)(is_array($g) ? ($g['id'] ?? '') : $g)));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $gid)) throw new InvalidArgumentException('Choose the Microsoft 365 groups from the list.');
        $groups[$gid] = ['id' => $gid, 'name' => mb_substr(trim((string)(is_array($g) ? ($g['name'] ?? '') : '')), 0, 255)];
    }
    if (count($groups) > 20) throw new InvalidArgumentException('Up to 20 Microsoft 365 groups.');
    // BT136: people found in Microsoft 365 by name, and everyone in Microsoft 365
    $people = [];
    foreach ((array)($body['audience_people'] ?? []) as $pp) {
        $em = strtolower(trim((string)(is_array($pp) ? ($pp['email'] ?? '') : $pp)));
        if (!filter_var($em, FILTER_VALIDATE_EMAIL) || strlen($em) > 320) throw new InvalidArgumentException('Choose the people from the Microsoft 365 search.');
        $people[$em] = ['email' => $em, 'name' => mb_substr(trim(preg_replace('/[\x00-\x1F<>]/u', '', (string)(is_array($pp) ? ($pp['name'] ?? '') : ''))), 0, 255)];
    }
    if (count($people) > 500) throw new InvalidArgumentException('Up to 500 people picked one by one. For more, use a group or "Everyone in Microsoft 365".');
    $t = (string)($body['audience_tenant'] ?? '');
    if (!in_array($t, ['', 'licensed', 'all'], true)) throw new InvalidArgumentException('The choice for everyone in Microsoft 365 is not valid.');
    // which of the organization's domains (an organization can have several): people whose address is in one of them
    $domains = [];
    foreach ((array)($body['audience_domains'] ?? []) as $d) {
        $d = strtolower(trim((string)$d));
        if (!preg_match('/^(?=.{3,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $d)) throw new InvalidArgumentException('Choose the domains from the list.');
        $domains[$d] = true;
    }
    if (count($domains) > 30) throw new InvalidArgumentException('Up to 30 domains.');
    if ($t !== '' && !$domains) throw new InvalidArgumentException('Choose at least one domain for "Everyone in Microsoft 365".');
    return ['groups' => array_values($groups), 'emails' => opsh_ann_parse_emails($body['audience_emails'] ?? ''), 'people' => array_values($people), 'tenant' => $t, 'domains' => $t !== '' ? array_keys($domains) : []];
}
function opsh_ann_ext_save(PDO $db, int $id, string $audience, array $ext): void
{
    if (!opsh_ann_ext_ready($db)) return;
    $db->prepare('DELETE FROM opsh_announcement_audience_ext WHERE announcement_id=?')->execute([$id]);
    if ($audience !== 'selected') return;
    $ins = $db->prepare('INSERT IGNORE INTO opsh_announcement_audience_ext (announcement_id, kind, ref, label) VALUES (?,?,?,?)');
    foreach ($ext['groups'] as $g) $ins->execute([$id, 'group', $g['id'], $g['name']]);
    foreach ($ext['emails'] as $e) $ins->execute([$id, 'email', $e, '']);
    foreach ($ext['people'] ?? [] as $pp) $ins->execute([$id, 'person', $pp['email'], $pp['name']]);
    if (!empty($ext['tenant'])) { $ins->execute([$id, 'tenant', $ext['tenant'], 'Everyone in Microsoft 365']); foreach ($ext['domains'] ?? [] as $d) $ins->execute([$id, 'domain', $d, '']); }
}
/** BT136: the organization's verified domains in Microsoft 365 (for "Everyone in Microsoft 365"): [['name','default']]. */
function opsh_ann_domains(): array
{
    if (!function_exists('opsh_graph_get')) require_once __DIR__ . '/Graph.php';
    if (!opsh_graph_configured()) throw new RuntimeException('Microsoft 365 is not connected (Settings → Microsoft 365).');
    [$code, $data] = opsh_graph_http('GET', 'https://graph.microsoft.com/v1.0/domains?$select=id,isVerified,isDefault');
    if ($code >= 400) throw new RuntimeException((string)($data['error']['message'] ?? ('Microsoft 365 answered ' . $code)));
    $out = [];
    foreach (($data['value'] ?? []) as $d) {
        $n = strtolower((string)($d['id'] ?? '')); if ($n === '' || empty($d['isVerified'])) continue;
        $out[] = ['name' => $n, 'default' => !empty($d['isDefault']), 'initial' => (bool)preg_match('/\.onmicrosoft\.com$/', $n)];
    }
    usort($out, fn($a, $b) => [$b['default'], $a['initial'], $a['name']] <=> [$a['default'], $b['initial'], $b['name']]);
    return $out;
}
/** BT136: find people in Microsoft 365 by name or address (for the picker). Enabled accounts with a mailbox address. */
function opsh_ann_people_search(string $q): array
{
    if (!function_exists('opsh_graph_get')) require_once __DIR__ . '/Graph.php';
    if (!opsh_graph_configured()) throw new RuntimeException('Microsoft 365 is not connected (Settings → Microsoft 365).');
    $q = trim(mb_substr($q, 0, 60));
    if (mb_strlen($q) < 2) return [];
    $v = str_replace("'", "''", $q);
    $f = "startswith(displayName,'$v') or startswith(givenName,'$v') or startswith(surname,'$v') or startswith(mail,'$v') or startswith(userPrincipalName,'$v')";
    [$code, $data] = opsh_graph_http('GET', 'https://graph.microsoft.com/v1.0/users?$select=displayName,mail,userPrincipalName,jobTitle,department,accountEnabled&$top=25&$filter=' . rawurlencode($f));
    if ($code >= 400) throw new RuntimeException((string)($data['error']['message'] ?? ('Microsoft 365 answered ' . $code)));
    $out = [];
    foreach (($data['value'] ?? []) as $u) {
        if (isset($u['accountEnabled']) && $u['accountEnabled'] === false) continue;
        $m = strtolower(trim((string)($u['mail'] ?? ''))); if ($m === '' || !filter_var($m, FILTER_VALIDATE_EMAIL) || isset($out[$m])) continue;
        $out[$m] = ['email' => $m, 'name' => (string)($u['displayName'] ?? ''), 'title' => trim((string)($u['jobTitle'] ?? '') . ((string)($u['department'] ?? '') !== '' ? ' · ' . $u['department'] : ''))];
        if (count($out) >= 15) break;
    }
    return array_values($out);
}
/**
 * BT136: everyone in Microsoft 365: enabled member accounts (not guests) with a mailbox address; 'licensed' leaves out
 * accounts without a license (service and test accounts). Shared mailboxes and rooms are disabled accounts, so they are
 * left out too. Read for 10 minutes at most, so the editor's count does not ask Microsoft on every change.
 */
function opsh_ann_tenant_members(string $mode): array
{
    if (!function_exists('opsh_graph_paged')) require_once __DIR__ . '/Graph.php';
    $f = null;
    if (function_exists('opsh_cache_dir') || @include_once __DIR__ . '/SafeCache.php') {
        try { $f = opsh_cache_dir('ann') . '/tenant-' . ($mode === 'all' ? 'all' : 'licensed') . '.json'; if (is_file($f) && filemtime($f) > time() - 600) { $j = json_decode((string)file_get_contents($f), true); if (is_array($j)) return $j; } } catch (Throwable $e) { $f = null; }
    }
    $out = [];
    $GLOBALS['OPSH_GRAPH_TRUNCATED'] = false;
    foreach (opsh_graph_paged('users?$select=displayName,mail,accountEnabled,userType,assignedLicenses&$top=999', 30) as $u) {
        if (($u['accountEnabled'] ?? true) === false || strcasecmp((string)($u['userType'] ?? 'Member'), 'Member') !== 0) continue;
        if ($mode !== 'all' && empty($u['assignedLicenses'])) continue;
        $m = strtolower(trim((string)($u['mail'] ?? ''))); if ($m === '' || !filter_var($m, FILTER_VALIDATE_EMAIL)) continue;
        $out[$m] = (string)($u['displayName'] ?? '');
    }
    if ($f && empty($GLOBALS['OPSH_GRAPH_TRUNCATED'])) { try { file_put_contents($f, json_encode($out), LOCK_EX); } catch (Throwable $e) {} }
    return $out;
}
/** Find Microsoft 365 groups by name (for the picker). Needs GroupMember.Read.All or Group.Read.All. */
function opsh_ann_group_search(string $q): array
{
    if (!function_exists('opsh_graph_get')) require_once __DIR__ . '/Graph.php';
    if (!opsh_graph_configured()) throw new RuntimeException('Microsoft 365 is not connected (Settings → Microsoft 365).');
    $q = trim($q); $filter = '';
    if ($q !== '') $filter = '&$filter=' . rawurlencode("startswith(displayName,'" . str_replace("'", "''", mb_substr($q, 0, 60)) . "') or startswith(mail,'" . str_replace("'", "''", mb_substr($q, 0, 60)) . "')");
    [$code, $data] = opsh_graph_http('GET', 'https://graph.microsoft.com/v1.0/groups?$select=id,displayName,mail,groupTypes,mailEnabled,securityEnabled&$top=25' . $filter);
    if ($code >= 400) throw new RuntimeException((string)($data['error']['message'] ?? ('Microsoft 365 answered ' . $code)));
    $out = [];
    foreach (($data['value'] ?? []) as $g) {
        $kind = in_array('Unified', (array)($g['groupTypes'] ?? []), true) ? 'Microsoft 365 group' : (!empty($g['mailEnabled']) && empty($g['securityEnabled']) ? 'Distribution list' : (!empty($g['mailEnabled']) ? 'Mail-enabled security group' : 'Security group'));
        $out[] = ['id' => (string)$g['id'], 'name' => (string)($g['displayName'] ?? ''), 'mail' => (string)($g['mail'] ?? ''), 'kind' => $kind];
    }
    return $out;
}
/** The people in a group, nested groups included: [lower email => name]. People without an address and disabled accounts are left out. */
function opsh_ann_group_members(string $gid): array
{
    if (!function_exists('opsh_graph_paged')) require_once __DIR__ . '/Graph.php';
    $out = [];
    $GLOBALS['OPSH_GRAPH_TRUNCATED'] = false;
    foreach (opsh_graph_paged('groups/' . rawurlencode($gid) . '/transitiveMembers/microsoft.graph.user?$select=displayName,mail,userPrincipalName,accountEnabled&$top=999', 10) as $u) {
        if (isset($u['accountEnabled']) && $u['accountEnabled'] === false) continue;
        $m = strtolower(trim((string)($u['mail'] ?? ''))); if ($m === '' || !filter_var($m, FILTER_VALIDATE_EMAIL)) continue;
        $out[$m] = (string)($u['displayName'] ?? '');
    }
    try {   // distribution lists can hold outside contacts
        foreach (opsh_graph_paged('groups/' . rawurlencode($gid) . '/transitiveMembers/microsoft.graph.orgContact?$select=displayName,mail&$top=999', 5) as $c) {
            $m = strtolower(trim((string)($c['mail'] ?? ''))); if ($m !== '' && filter_var($m, FILTER_VALIDATE_EMAIL) && !isset($out[$m])) $out[$m] = (string)($c['displayName'] ?? '');
        }
    } catch (Throwable $e) {}
    return $out;
}
/** Everyone outside the app the announcement goes to: ['people' => [email => ['name','via']], 'errors' => [...], 'groups' => [name => n]]. */
function opsh_ann_ext_resolve(PDO $db, int $id): array { return opsh_ann_ext_resolve_list(opsh_ann_ext_get($db, $id)); }
function opsh_ann_ext_resolve_list(array $ext): array
{
    $out = ['people' => [], 'errors' => [], 'groups' => [], 'typed' => count($ext['emails']), 'picked' => count($ext['people'] ?? []), 'tenant' => null, 'cut' => false];
    if (!empty($ext['tenant'])) {   // BT136
        try { $mem = opsh_ann_tenant_members((string)$ext['tenant']);
              $doms = array_flip(array_map('strtolower', $ext['domains'] ?? []));   // only the domains chosen
              if ($doms) $mem = array_filter($mem, fn($n, $e) => isset($doms[substr((string)strrchr($e, '@'), 1)]), ARRAY_FILTER_USE_BOTH);
              $out['tenant'] = count($mem);
              if (!empty($GLOBALS['OPSH_GRAPH_TRUNCATED'])) $out['cut'] = true;
              foreach ($mem as $e => $n) $out['people'][$e] = ['name' => $n, 'via' => 'Everyone in Microsoft 365']; }
        catch (Throwable $e) { $out['errors'][] = 'Everyone in Microsoft 365: ' . $e->getMessage(); }
    }
    foreach ($ext['groups'] as $g) {
        try {
            $mem = opsh_ann_group_members($g['id']); $out['groups'][$g['name'] ?: $g['id']] = count($mem);
            if (!empty($GLOBALS['OPSH_GRAPH_TRUNCATED'])) $out['cut'] = true;
            foreach ($mem as $e => $n) if (!isset($out['people'][$e])) $out['people'][$e] = ['name' => $n, 'via' => 'Group: ' . ($g['name'] ?: $g['id'])];
        } catch (Throwable $e) { $out['errors'][] = ($g['name'] ?: $g['id']) . ': ' . $e->getMessage(); }
    }
    foreach ($ext['people'] ?? [] as $pp) if (!isset($out['people'][$pp['email']])) $out['people'][$pp['email']] = ['name' => $pp['name'], 'via' => 'Picked in Microsoft 365'];
    foreach ($ext['emails'] as $e) if (!isset($out['people'][$e])) $out['people'][$e] = ['name' => '', 'via' => 'Typed address'];
    return $out;
}
/** The personal link of one person outside the app: derived from the announcement, the address and the server's key. */
function opsh_ann_ext_token(int $annId, string $email): string
{
    $k = defined('VAULT_ENCRYPTION_KEY') && strpos((string)VAULT_ENCRYPTION_KEY, '%%') === false && VAULT_ENCRYPTION_KEY !== '' ? (string)VAULT_ENCRYPTION_KEY : (defined('JWT_SECRET') ? (string)JWT_SECRET : 'opsh');
    return substr(hash_hmac('sha256', 'announcement|' . $annId . '|' . strtolower($email), $k), 0, 48);
}
function opsh_ann_ext_link(string $token): string { return (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/read.html?t=' . $token; }
function opsh_ann_ext_mail_on(): bool
{
    if (!function_exists('opsh_tpl_setting')) require_once __DIR__ . '/EmailTemplates.php';
    return opsh_tpl_setting('mail_out_enabled', '0') === '1' && opsh_tpl_enabled('announcement_external');
}
/** Send the queued emails of people outside the app (new or reminder). */
function opsh_ann_deliver_ext(PDO $db, int $max = 40, int $budgetSeconds = 20, ?int $onlyId = null): array
{
    $out = ['sent' => 0, 'failed' => 0, 'left' => 0];
    if (!opsh_ann_ext_ready($db)) return $out;
    if (!function_exists('opsh_email')) require_once __DIR__ . '/EmailTemplates.php';
    $t0 = time();
    try { $db->prepare("UPDATE opsh_announcement_external SET email_state='pending' WHERE email_state='sending' AND email_at < ?")->execute([gmdate('Y-m-d H:i:s', time() - 600)]); } catch (Throwable $e) {}
    $db->prepare("UPDATE opsh_announcement_external x JOIN opsh_announcements a ON a.id=x.announcement_id SET x.email_state='skipped'
                  WHERE x.email_state IN ('pending','remind') AND (a.status<>'published' OR (a.expires_at IS NOT NULL AND a.expires_at < ?))")->execute([opsh_ann_now()]);
    $rows = $db->query("SELECT id, announcement_id, email, name, email_state FROM opsh_announcement_external WHERE email_state IN ('pending','remind')" . ($onlyId ? ' AND announcement_id=' . (int)$onlyId : '') . ' ORDER BY announcement_id, id LIMIT ' . max(1, min(500, $max)))->fetchAll();
    $claim = $db->prepare("UPDATE opsh_announcement_external SET email_state='sending', email_at=? WHERE id=? AND email_state=?");
    $done = $db->prepare('UPDATE opsh_announcement_external SET email_state=?, email_at=? WHERE id=?');
    $cache = [];
    foreach ($rows as $r) {
        if (time() - $t0 >= $budgetSeconds) break;
        $claim->execute([opsh_ann_now(), (int)$r['id'], $r['email_state']]); if ($claim->rowCount() !== 1) continue;
        $aid = (int)$r['announcement_id']; $a = $cache[$aid] ?? ($cache[$aid] = opsh_ann_get($db, $aid));
        if (!$a) { $done->execute(['skipped', opsh_ann_now(), (int)$r['id']]); continue; }
        $hasEs = trim((string)$a['title_es']) !== '' && trim((string)$a['body_es']) !== '';
        $due = !empty($a['ack_due']) ? opsh_tpl_d2((string)$a['ack_due']) : ['en' => '', 'es' => ''];
        $rem = $r['email_state'] === 'remind';
        $vars = [
            'title' => ['en' => (string)$a['title'], 'es' => $hasEs ? (string)$a['title_es'] : (string)$a['title']],
            'body_md' => ['en' => (string)$a['body'], 'es' => $hasEs ? (string)$a['body_es'] : (string)$a['body']],
            'level' => ['en' => ['info' => 'Announcement', 'important' => 'Important', 'urgent' => 'Urgent'][$a['level']] ?? 'Announcement', 'es' => ['info' => 'Anuncio', 'important' => 'Importante', 'urgent' => 'Urgente'][$a['level']] ?? 'Anuncio'],
            'sender_name' => (string)($a['published_by_name'] ?? ''),
            'reminder_line' => $rem ? ['en' => 'This is a reminder: you have not ' . (!empty($a['requires_ack']) ? 'confirmed this announcement' : 'opened this announcement') . ' yet.', 'es' => 'Este es un recordatorio: aún no ha ' . (!empty($a['requires_ack']) ? 'confirmado este anuncio' : 'abierto este anuncio') . '.'] : '',
            'ack_line' => empty($a['requires_ack']) ? '' : ['en' => 'Please open it and confirm that you have read it' . ($due['en'] !== '' ? ' by ' . $due['en'] : '') . '.', 'es' => 'Por favor ábralo y confirme que lo ha leído' . ($due['es'] !== '' ? ' antes del ' . $due['es'] : '') . '.'],
            'link_label' => !empty($a['requires_ack']) ? ['en' => 'Open it and confirm you have read it', 'es' => 'Ábralo y confirme que lo leyó'] : ['en' => 'Open it', 'es' => 'Abrirlo'],
            'recipient_name' => (string)$r['name'],
            'link' => opsh_ann_ext_link(opsh_ann_ext_token($aid, (string)$r['email'])),
        ];
        try { $res = opsh_email('announcement_external', (string)$r['email'], $vars); } catch (Throwable $e) { $res = ['ok' => false, 'error' => $e->getMessage()]; }
        $state = !empty($res['ok']) ? 'sent' : (!empty($res['skipped']) ? 'skipped' : 'failed');
        $done->execute([$state, opsh_ann_now(), (int)$r['id']]);
        if ($state === 'sent') $out['sent']++; elseif ($state === 'failed') $out['failed']++;
    }
    $out['left'] = (int)$db->query("SELECT COUNT(*) FROM opsh_announcement_external WHERE email_state IN ('pending','remind')" . ($onlyId ? ' AND announcement_id=' . (int)$onlyId : ''))->fetchColumn();
    return $out;
}

// ───────────────────────────── Teams channels ─────────────────────────────

/** Only Microsoft's own webhook hosts are accepted: Teams Workflows (Power Automate) and the older connector URLs. */
function opsh_ann_url_ok(string $url): bool
{
    $p = parse_url($url);
    if (!$p || strtolower((string)($p['scheme'] ?? '')) !== 'https' || empty($p['host'])) return false;
    if (isset($p['port']) && (int)$p['port'] !== 443) return false;
    if (isset($p['user']) || isset($p['pass'])) return false;
    $h = strtolower($p['host']);
    foreach (['.logic.azure.com', '.api.powerplatform.com', '.webhook.office.com'] as $suf) if (substr($h, -strlen($suf)) === $suf) return true;
    return false;
}
/** Stored channels: [{id, name, url}] — url unwrapped only when $withUrl. */
function opsh_ann_channels(PDO $db, bool $withUrl = false): array
{
    try { $s = $db->prepare("SELECT sval FROM opsh_settings WHERE skey='announce_teams'"); $s->execute(); $j = json_decode((string)$s->fetchColumn(), true); }
    catch (Throwable $e) { $j = null; }
    $out = [];
    foreach ((is_array($j) ? $j : []) as $c) {
        if (!is_array($c) || empty($c['id']) || empty($c['url'])) continue;
        $row = ['id' => (string)$c['id'], 'name' => (string)($c['name'] ?? 'Teams channel')];
        if ($withUrl) { require_once __DIR__ . '/Secrets.php'; try { $row['url'] = opsh_secret_unwrap((string)$c['url']); } catch (Throwable $e) { $row['url'] = ''; } }
        $out[] = $row;
    }
    return $out;
}
/** Save the list. An item without a url keeps the stored one (the UI never receives the URL back). */
function opsh_ann_channels_save(PDO $db, array $list): array
{
    require_once __DIR__ . '/Secrets.php';
    $old = []; foreach (opsh_ann_channels($db, true) as $c) $old[$c['id']] = $c;
    $out = []; $names = [];
    foreach ($list as $c) {
        if (!is_array($c)) continue;
        $name = trim((string)($c['name'] ?? '')); $url = trim((string)($c['url'] ?? '')); $id = (string)($c['id'] ?? '');
        if ($name === '') error_response('Every Teams channel needs a name.');
        if (mb_strlen($name) > 60) error_response('Channel names can be up to 60 characters.');
        if (isset($names[strtolower($name)])) error_response('Two channels are called "' . $name . '". Give each its own name.');
        $names[strtolower($name)] = true;
        if ($url === '') { if ($id === '' || !isset($old[$id])) error_response('Paste the workflow URL for "' . $name . '".'); $url = $old[$id]['url']; }
        elseif (!opsh_ann_url_ok($url)) error_response('"' . $name . '": that is not a Teams workflow URL. It must start with https:// and come from Microsoft (…logic.azure.com or …api.powerplatform.com).');
        if ($id === '' || !preg_match('/^[a-f0-9]{8}$/', $id)) $id = bin2hex(random_bytes(4));
        $out[] = ['id' => $id, 'name' => $name, 'url' => opsh_secret_wrap($url)];
    }
    if (count($out) > 12) error_response('Up to 12 Teams channels.');
    $db->prepare("INSERT INTO opsh_settings (skey,sval) VALUES ('announce_teams',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([json_encode($out)]);
    return opsh_ann_channels($db);
}

/** Announcement text → the markdown subset Adaptive Cards understand (blank line = new paragraph, \r between list items). */
function opsh_ann_card_text(string $s): string
{
    $s = str_replace(["\r\n", "\r"], "\n", trim($s));
    $paras = [];
    foreach (preg_split('/\n{2,}/', $s) as $para) {
        $lines = array_values(array_filter(array_map('rtrim', explode("\n", $para)), fn($l) => $l !== ''));
        if (!$lines) continue;
        $isList = count(array_filter($lines, fn($l) => preg_match('/^\s*[-•]\s+/', $l))) === count($lines);
        $paras[] = $isList ? implode("\r", array_map(fn($l) => '- ' . preg_replace('/^\s*[-•]\s+/', '', $l), $lines)) : implode("\n\n", $lines);
    }
    $t = implode("\n\n", $paras);
    return mb_strlen($t) > 3000 ? rtrim(mb_substr($t, 0, 3000)) . '… (open it to read the rest)' : $t;
}
/** The message a Teams "Post to a channel when a webhook request is received" workflow expects. */
function opsh_ann_teams_payload(array $a, string $byName): array
{
    $app = function_exists('opsh_app_name') ? opsh_app_name() : 'OpsHub';
    $lvl = ['info' => ['Announcement', 'Accent'], 'important' => ['Important', 'Warning'], 'urgent' => ['Urgent', 'Attention']][$a['level']] ?? ['Announcement', 'Accent'];
    $body = [
        ['type' => 'TextBlock', 'text' => strtoupper($lvl[0]) . ' · ' . $app, 'size' => 'Small', 'weight' => 'Bolder', 'color' => $lvl[1], 'wrap' => true],
        ['type' => 'TextBlock', 'text' => (string)$a['title'], 'size' => 'Large', 'weight' => 'Bolder', 'wrap' => true, 'spacing' => 'Small'],
        ['type' => 'TextBlock', 'text' => opsh_ann_card_text((string)$a['body']), 'wrap' => true],
    ];
    if (trim((string)($a['title_es'] ?? '')) !== '' && trim((string)($a['body_es'] ?? '')) !== '') {
        $body[] = ['type' => 'TextBlock', 'text' => (string)$a['title_es'], 'weight' => 'Bolder', 'wrap' => true, 'separator' => true, 'spacing' => 'Medium'];
        $body[] = ['type' => 'TextBlock', 'text' => opsh_ann_card_text((string)$a['body_es']), 'wrap' => true];
    }
    if (!empty($a['requires_ack'])) {
        $due = !empty($a['ack_due']) ? ' by ' . date('m/d/Y', strtotime((string)$a['ack_due'])) : '';
        $body[] = ['type' => 'TextBlock', 'text' => 'Please open it and confirm that you have read it' . $due . '.', 'wrap' => true, 'weight' => 'Bolder', 'spacing' => 'Medium'];
    }
    if ($byName !== '') $body[] = ['type' => 'TextBlock', 'text' => 'Posted by ' . $byName, 'isSubtle' => true, 'size' => 'Small', 'wrap' => true, 'spacing' => 'Medium'];
    $card = ['$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'type' => 'AdaptiveCard', 'version' => '1.4', 'msteams' => ['width' => 'Full'], 'body' => $body];
    $link = opsh_ann_link();
    // BT29: with the Teams app switched on, the button opens the Announcements page inside Teams (signed in automatically).
    try { if (!function_exists('opsh_teams_cfg')) require_once __DIR__ . '/TeamsApp.php'; $tc = opsh_teams_cfg(getDB()); if ($tc['enabled'] && $tc['app_id'] !== '') $link = opsh_teams_deeplink($tc['app_id'], 'announcements'); } catch (Throwable $e) {}
    if (preg_match('#^https://#i', $link)) $card['actions'] = [['type' => 'Action.OpenUrl', 'title' => !empty($a['requires_ack']) ? 'Open and confirm' : 'Open in ' . $app, 'url' => $link]];
    return ['type' => 'message', 'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive', 'contentUrl' => null, 'content' => $card]]];
}
if (!function_exists('opsh_ann_http_post')) {
    /** POST JSON to a webhook URL. Returns [ok, http status, error]. (Replaced by a stub in the test environment.) */
    function opsh_ann_http_post(string $url, string $json): array
    {
        if (!function_exists('curl_init')) return [false, 0, 'curl is not available on this server'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        $resp = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        $ok = $status >= 200 && $status < 300;
        return [$ok, $status, $ok ? '' : ($err ?: 'HTTP ' . $status . ' ' . mb_substr(trim(strip_tags((string)$resp)), 0, 160))];
    }
}
/** Post to one stored channel. Returns ['ok', 'channel', 'error']. Never throws. */
function opsh_ann_teams_send(PDO $db, string $channelId, array $payload): array
{
    $ch = null; foreach (opsh_ann_channels($db, true) as $c) if ($c['id'] === $channelId) $ch = $c;
    if (!$ch) return ['ok' => false, 'channel' => '', 'error' => 'The Teams channel was removed.'];
    if ($ch['url'] === '' || !opsh_ann_url_ok($ch['url'])) return ['ok' => false, 'channel' => $ch['name'], 'error' => 'The stored workflow URL is not valid. Paste it again.'];
    try { [$ok, $status, $err] = opsh_ann_http_post($ch['url'], json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); }
    catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
    return ['ok' => (bool)$ok, 'channel' => $ch['name'], 'error' => $ok ? '' : mb_substr((string)$err, 0, 200)];
}

// ───────────────────────────── Publish, email, remind ─────────────────────────────

function opsh_ann_get(PDO $db, int $id): ?array
{
    $st = $db->prepare('SELECT a.*, pu.display_name AS published_by_name, cu.display_name AS created_by_name FROM opsh_announcements a
                        LEFT JOIN opsh_users pu ON pu.id=a.published_by LEFT JOIN opsh_users cu ON cu.id=a.created_by WHERE a.id=?');
    $st->execute([$id]); $a = $st->fetch();
    return $a ?: null;
}
function opsh_ann_mail_on(): bool
{
    if (!function_exists('opsh_tpl_setting')) require_once __DIR__ . '/EmailTemplates.php';
    return opsh_tpl_setting('mail_out_enabled', '0') === '1' && opsh_tpl_enabled('announcement_new');
}

/**
 * Publish now: fix the recipients, ring the bell, post to Teams, queue the emails and send the first batch.
 * $by = the publishing user row (or ['id'=>null,'display_name'=>'Scheduled'] from cron). Returns the delivery summary.
 */
function opsh_ann_publish(PDO $db, int $id, array $by): array
{
    $a = opsh_ann_get($db, $id);
    if (!$a) return ['error' => 'Announcement not found.'];
    if (!in_array($a['status'], ['draft', 'scheduled'], true)) return ['error' => 'This announcement was already published.'];
    $users = opsh_ann_audience_users($db, (string)$a['audience'], opsh_ann_audience_get($db, $id));
    // BT94: Microsoft 365 groups and typed addresses — people with an account join the recipients, the others get a personal link
    $outside = []; $extInfo = null;
    if ((string)$a['audience'] === 'selected' && opsh_ann_ext_ready($db)) {
        $x = opsh_ann_ext_get($db, $id);
        if (opsh_ann_ext_any($x)) {
            $extInfo = opsh_ann_ext_resolve($db, $id);
            if (($x['groups'] || $x['tenant']) && count($extInfo['errors']) === count($x['groups']) + ($x['tenant'] ? 1 : 0) && !$x['emails'] && !$x['people'] && !$users)
                return ['error' => 'Microsoft 365 could not be read: ' . implode(' · ', $extInfo['errors'])];
            $acc = []; foreach ($db->query("SELECT id, display_name, email, language FROM opsh_users WHERE status='active' AND email<>''")->fetchAll() as $u) $acc[strtolower((string)$u['email'])] = $u;
            foreach ($extInfo['people'] as $em => $pp) {
                if (isset($acc[$em])) { $u = $acc[$em]; $users[(int)$u['id']] = ['id' => (int)$u['id'], 'display_name' => (string)$u['display_name'], 'email' => (string)$u['email'], 'language' => (string)($u['language'] ?? '')]; }
                else $outside[$em] = $pp;
            }
            if (count($outside) > OPSH_ANN_EXT_MAX) return ['error' => 'This would go to ' . count($outside) . ' people outside the app; the limit is ' . OPSH_ANN_EXT_MAX . ' per announcement.'];
        }
    }
    if (!$users && !$outside) return ['error' => 'Nobody matches the audience — the people, departments or roles you picked have no active users' . ($extInfo ? ', and the groups and addresses gave nobody to write to' : '') . '.'];
    // Claim it first so a second click or an overlapping scheduled run cannot publish twice.
    $now = opsh_ann_now();
    $byId = !empty($by['id']) ? (int)$by['id'] : ((int)($a['created_by'] ?? 0) ?: null);
    $cl = $db->prepare("UPDATE opsh_announcements SET status='published', published_at=?, published_by=? WHERE id=? AND status IN ('draft','scheduled')");
    $cl->execute([$now, $byId, $id]);
    if ($cl->rowCount() !== 1) return ['error' => 'This announcement was already published.'];

    $mailOn = !empty($a['ch_email']) && opsh_ann_mail_on();
    $insR = $db->prepare('INSERT IGNORE INTO opsh_announcement_recipients (announcement_id, user_id, email_state) VALUES (?,?,?)');
    $insN = $db->prepare('INSERT INTO opsh_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)');
    $pre = ['info' => '', 'important' => 'Important: ', 'urgent' => 'Urgent: '][$a['level']] ?? '';
    $queued = 0; $noEmail = 0;
    foreach ($users as $u) {
        $hasMail = (bool)filter_var($u['email'], FILTER_VALIDATE_EMAIL);
        $state = empty($a['ch_email']) ? 'none' : (!$mailOn ? 'skipped' : ($hasMail ? 'pending' : 'skipped'));
        if ($state === 'pending') $queued++; elseif (!empty($a['ch_email'])) $noEmail++;
        $insR->execute([$id, $u['id'], $state]);
        [$t, $b] = opsh_ann_text($a, $u['language']);
        try { $insN->execute([$u['id'], opsh_ann_cut($pre . $t, 250), opsh_ann_cut(opsh_ann_plain($b), 180) . (!empty($a['requires_ack']) ? ' — please confirm you have read it.' : ''), 'announcement', 'announcements/' . (int)$a['id']]);   /* review DESK-18: one bell per announcement */ } catch (Throwable $e) {}
    }
    // BT94: people outside the app — one row each, with a personal link (email only)
    $extQueued = 0;
    if ($outside) {
        $extOn = !empty($a['ch_email']) && opsh_ann_ext_mail_on();
        $insX = $db->prepare('INSERT IGNORE INTO opsh_announcement_external (announcement_id, email, name, via, token_hash, email_state) VALUES (?,?,?,?,?,?)');
        foreach ($outside as $em => $pp) { $insX->execute([$id, $em, mb_substr((string)$pp['name'], 0, 255), mb_substr((string)$pp['via'], 0, 255), hash('sha256', opsh_ann_ext_token($id, $em)), $extOn ? 'pending' : 'skipped']); if ($extOn) $extQueued++; }
    }
    $byName = (string)($by['display_name'] ?? ($a['created_by_name'] ?? ''));
    $del = ['recipients' => count($users) + count($outside), 'inapp' => count($users),
            'external' => $extInfo ? ['people' => count($outside), 'with_account' => count($extInfo['people']) - count($outside), 'groups' => $extInfo['groups'], 'typed' => $extInfo['typed'],
                                      'errors' => $extInfo['errors'], 'cut' => $extInfo['cut'], 'queued' => $extQueued] : null,
            'email' => empty($a['ch_email']) ? null : ['queued' => $queued, 'skipped' => $noEmail, 'off' => !$mailOn],
            'teams' => null];
    if ((string)$a['teams_channel'] !== '') {
        $del['teams'] = opsh_ann_teams_send($db, (string)$a['teams_channel'], opsh_ann_teams_payload($a, $byName));
        if (!$del['teams']['ok'] && function_exists('opsh_log')) { try { opsh_log('warning', 'announcements', 'Teams post failed: ' . $a['title'], ['error' => $del['teams']['error'], 'channel' => $del['teams']['channel']]); } catch (Throwable $e) {} }
    }
    // The first emails go out with the publish itself; the rest follow from the scheduled job within minutes.
    if ($queued || $extQueued) { $e = opsh_ann_deliver_emails($db, 20, 10, $id); if ($del['email']) { $del['email']['sent'] = $e['sent']; $del['email']['failed'] = $e['failed']; $del['email']['left'] = $e['left']; } }
    $db->prepare('UPDATE opsh_announcements SET delivery_json=? WHERE id=?')->execute([json_encode($del), $id]);
    if (function_exists('audit_log')) { try { audit_log('announcement_publish', 'announcement', $id, (string)$a['title'], null, ['recipients' => count($users), 'outside the app' => count($outside), 'email' => !empty($a['ch_email']), 'teams' => $del['teams']['channel'] ?? null]); } catch (Throwable $e) {} }
    return $del;
}

/**
 * Send queued announcement emails (new = 'pending', reminder = 'remind'). Each row is claimed before sending so
 * overlapping runs never send twice. Returns ['sent','failed','left'].
 */
function opsh_ann_deliver_emails(PDO $db, int $max = 40, int $budgetSeconds = 20, ?int $onlyId = null): array
{
    $out = ['sent' => 0, 'failed' => 0, 'left' => 0];
    if (!opsh_ann_ready($db)) return $out;
    if (!function_exists('opsh_email')) require_once __DIR__ . '/EmailTemplates.php';
    $t0 = time(); $now = opsh_ann_now();
    // A run that died mid-send leaves rows "sending": give them back after 10 minutes.
    try { $db->prepare("UPDATE opsh_announcement_recipients SET email_state='pending' WHERE email_state='sending' AND email_at < ?")->execute([gmdate('Y-m-d H:i:s', time() - 600)]); } catch (Throwable $e) {}
    // Nothing is emailed for an announcement that was archived or has run out.
    $db->prepare("UPDATE opsh_announcement_recipients r JOIN opsh_announcements a ON a.id=r.announcement_id SET r.email_state='skipped'
                  WHERE r.email_state IN ('pending','remind') AND (a.status<>'published' OR (a.expires_at IS NOT NULL AND a.expires_at < ?))")->execute([$now]);
    $sql = "SELECT r.announcement_id, r.user_id, r.email_state, u.email, u.display_name, u.status AS ustatus
            FROM opsh_announcement_recipients r JOIN opsh_users u ON u.id=r.user_id
            WHERE r.email_state IN ('pending','remind')" . ($onlyId ? ' AND r.announcement_id=' . (int)$onlyId : '') . " ORDER BY r.announcement_id, r.user_id LIMIT " . max(1, min(500, $max));
    $rows = $db->query($sql)->fetchAll();
    $claim = $db->prepare("UPDATE opsh_announcement_recipients SET email_state='sending', email_at=? WHERE announcement_id=? AND user_id=? AND email_state=?");
    $done  = $db->prepare('UPDATE opsh_announcement_recipients SET email_state=?, email_at=? WHERE announcement_id=? AND user_id=?');
    $cache = [];
    foreach ($rows as $r) {
        if (time() - $t0 >= $budgetSeconds) break;
        $aid = (int)$r['announcement_id']; $uid = (int)$r['user_id'];
        $claim->execute([opsh_ann_now(), $aid, $uid, $r['email_state']]);
        if ($claim->rowCount() !== 1) continue;
        $a = $cache[$aid] ?? ($cache[$aid] = opsh_ann_get($db, $aid));
        if (!$a || $r['ustatus'] !== 'active' || !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) { $done->execute(['skipped', opsh_ann_now(), $aid, $uid]); continue; }
        $hasEs = trim((string)$a['title_es']) !== '' && trim((string)$a['body_es']) !== '';
        $due = !empty($a['ack_due']) ? opsh_tpl_d2((string)$a['ack_due']) : ['en' => '', 'es' => ''];
        $vars = [
            'title'       => ['en' => (string)$a['title'], 'es' => $hasEs ? (string)$a['title_es'] : (string)$a['title']],
            'body_md'     => ['en' => (string)$a['body'],  'es' => $hasEs ? (string)$a['body_es']  : (string)$a['body']],
            'level'       => ['en' => ['info' => 'Announcement', 'important' => 'Important', 'urgent' => 'Urgent'][$a['level']] ?? 'Announcement',
                              'es' => ['info' => 'Anuncio', 'important' => 'Importante', 'urgent' => 'Urgente'][$a['level']] ?? 'Anuncio'],
            'sender_name' => (string)($a['published_by_name'] ?? ''),
            'ack_line'    => empty($a['requires_ack']) ? '' : [
                'en' => 'Please open it and confirm that you have read it' . ($due['en'] !== '' ? ' by ' . $due['en'] : '') . '.',
                'es' => 'Por favor ábralo y confirme que lo ha leído' . ($due['es'] !== '' ? ' antes del ' . $due['es'] : '') . '.'],
            'recipient_name' => (string)$r['display_name'],
            'link'        => opsh_ann_link(),
        ];
        try { $res = opsh_email($r['email_state'] === 'remind' ? 'announcement_reminder' : 'announcement_new', (string)$r['email'], $vars, ['user_id' => $uid]); }
        catch (Throwable $e) { $res = ['ok' => false, 'error' => $e->getMessage()]; }
        $state = !empty($res['ok']) ? 'sent' : (!empty($res['skipped']) ? 'skipped' : 'failed');
        $done->execute([$state, opsh_ann_now(), $aid, $uid]);
        if ($state === 'sent') $out['sent']++; elseif ($state === 'failed') $out['failed']++;
    }
    $out['left'] = (int)$db->query("SELECT COUNT(*) FROM opsh_announcement_recipients WHERE email_state IN ('pending','remind')" . ($onlyId ? ' AND announcement_id=' . (int)$onlyId : ''))->fetchColumn();
    // BT94: and the people outside the app, in what time is left
    $x = opsh_ann_deliver_ext($db, $max, max(1, $budgetSeconds - (time() - $t0)), $onlyId);
    $out['sent'] += $x['sent']; $out['failed'] += $x['failed']; $out['left'] += $x['left'];
    return $out;
}

/** People who still have to act: not acknowledged (when it asks for that) or not opened. */
function opsh_ann_outstanding(PDO $db, array $a): array
{
    $col = !empty($a['requires_ack']) ? 'r.ack_at' : 'r.seen_at';
    $st = $db->prepare("SELECT r.user_id, u.language, u.email FROM opsh_announcement_recipients r JOIN opsh_users u ON u.id=r.user_id AND u.status='active' WHERE r.announcement_id=? AND $col IS NULL");
    $st->execute([(int)$a['id']]);
    return $st->fetchAll();
}
/** Remind the people who have not read / acknowledged it: bell now, email through the queue. Returns ['reminded'=>n] or ['error'=>…]. */
function opsh_ann_remind(PDO $db, int $id, bool $auto = false): array
{
    $a = opsh_ann_get($db, $id);
    if (!$a || $a['status'] !== 'published') return ['error' => 'Only a published announcement can be reminded.'];
    if (!empty($a['expires_at']) && $a['expires_at'] < opsh_ann_now()) return ['error' => 'This announcement is no longer shown, so there is nothing to remind.'];
    $rows = opsh_ann_outstanding($db, $a);
    // BT94: people outside the app who have not opened (or confirmed) it get the email again with their link
    $extN = 0;
    if (opsh_ann_ext_ready($db)) {
        $xc = !empty($a['requires_ack']) ? 'ack_at' : 'seen_at';
        $xu = $db->prepare("UPDATE opsh_announcement_external SET reminded_at=?, email_state=IF(? AND email_state NOT IN ('pending','sending'), 'remind', email_state) WHERE announcement_id=? AND $xc IS NULL");
        $xu->execute([opsh_ann_now(), (!empty($a['ch_email']) && opsh_ann_ext_mail_on()) ? 1 : 0, $id]); $extN = $xu->rowCount();
    }
    if (!$rows && !$extN) return ['reminded' => 0];
    $mailOn = !empty($a['ch_email']) && opsh_ann_mail_on();
    $insN = $db->prepare('INSERT INTO opsh_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)');
    $upd = $db->prepare("UPDATE opsh_announcement_recipients SET reminded_at=?, email_state=IF(? AND email_state NOT IN ('pending','sending'), 'remind', email_state) WHERE announcement_id=? AND user_id=?");
    $now = opsh_ann_now();
    foreach ($rows as $r) {
        [$t] = opsh_ann_text($a, (string)($r['language'] ?? ''));
        try { $insN->execute([(int)$r['user_id'], opsh_ann_cut('Reminder: ' . $t, 250), !empty($a['requires_ack']) ? 'Please open it and confirm you have read it.' : 'You have not opened this announcement yet.', 'announcement', 'announcements/' . (int)$a['id']]); } catch (Throwable $e) {}
        $upd->execute([$now, ($mailOn && filter_var($r['email'], FILTER_VALIDATE_EMAIL)) ? 1 : 0, $id, (int)$r['user_id']]);
    }
    $db->prepare('UPDATE opsh_announcements SET last_reminder_at=?, reminders_sent=reminders_sent+1 WHERE id=?')->execute([$now, $id]);
    if (!$auto && function_exists('audit_log')) { try { audit_log('announcement_remind', 'announcement', $id, (string)$a['title'], null, ['reminded' => count($rows), 'outside the app' => $extN]); } catch (Throwable $e) {} }
    if ($mailOn || $extN) opsh_ann_deliver_emails($db, 20, 8, $id);
    return ['reminded' => count($rows) + $extN];
}

/** Scheduled work (cron-sla.php / in-app scheduler): publish what is due, send automatic reminders, drain the email queue. */
function opsh_ann_cron(PDO $db): array
{
    $out = ['published' => 0, 'reminded' => 0, 'emails' => 0, 'failed' => 0];
    if (!opsh_ann_ready($db)) return $out;
    $now = opsh_ann_now();
    $st = $db->prepare("SELECT id FROM opsh_announcements WHERE status='scheduled' AND publish_at IS NOT NULL AND publish_at <= ? ORDER BY publish_at LIMIT 10");
    $st->execute([$now]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $r = opsh_ann_publish($db, (int)$id, ['id' => null]);
        if (empty($r['error'])) $out['published']++;
        elseif (function_exists('opsh_log')) { try { opsh_log('warning', 'announcements', 'Scheduled announcement not published', ['id' => (int)$id, 'error' => $r['error']]); } catch (Throwable $e) {} $db->prepare("UPDATE opsh_announcements SET status='draft' WHERE id=? AND status='scheduled'")->execute([(int)$id]); }
    }
    $st = $db->prepare("SELECT id, remind_days, published_at, last_reminder_at FROM opsh_announcements
                        WHERE status='published' AND requires_ack=1 AND remind_days>0 AND reminders_sent < ? AND (expires_at IS NULL OR expires_at > ?)");
    $st->execute([OPSH_ANN_AUTO_REMIND, $now]);
    foreach ($st->fetchAll() as $a) {
        $from = strtotime(((string)($a['last_reminder_at'] ?: $a['published_at'])) . ' UTC');
        if ($from === false || time() - $from < (int)$a['remind_days'] * 86400) continue;
        $r = opsh_ann_remind($db, (int)$a['id'], true);
        if (!empty($r['reminded'])) $out['reminded'] += (int)$r['reminded'];
        else $db->prepare('UPDATE opsh_announcements SET last_reminder_at=? WHERE id=?')->execute([$now, (int)$a['id']]);   // everyone is done: stop checking until the next interval
    }
    $e = opsh_ann_deliver_emails($db, 60, 25);
    $out['emails'] = $e['sent']; $out['failed'] = $e['failed'];
    return $out;
}

// ───────────────────────────── Reading ─────────────────────────────

/** What one person sees: their current announcements (and recent past ones), in their language. */
function opsh_ann_for_user(PDO $db, array $user): array
{
    $uid = (int)($user['id'] ?? 0);
    $out = ['current' => [], 'past' => [], 'unread' => 0, 'to_ack' => 0];
    if (!$uid || !opsh_ann_ready($db)) return $out;
    $now = opsh_ann_now(); $lang = (string)($user['language'] ?? '');
    $st = $db->prepare("SELECT a.id, a.title, a.body, a.title_es, a.body_es, a.level, a.requires_ack, a.ack_due, a.status, a.published_at, a.expires_at,
                               pu.display_name AS by_name, r.seen_at, r.ack_at
                        FROM opsh_announcement_recipients r JOIN opsh_announcements a ON a.id=r.announcement_id
                        LEFT JOIN opsh_users pu ON pu.id=a.published_by
                        WHERE r.user_id=? AND a.status IN ('published','archived') ORDER BY a.published_at DESC LIMIT 120");
    $st->execute([$uid]);
    $rank = ['urgent' => 0, 'important' => 1, 'info' => 2];
    foreach ($st->fetchAll() as $a) {
        [$t, $b] = opsh_ann_text($a, $lang);
        $live = $a['status'] === 'published' && (empty($a['expires_at']) || $a['expires_at'] > $now);
        $row = ['id' => (int)$a['id'], 'title' => $t, 'body' => $b, 'level' => $a['level'], 'requires_ack' => (bool)$a['requires_ack'], 'ack_due' => $a['ack_due'],
                'published_at' => opsh_ann_iso($a['published_at']), 'by' => (string)($a['by_name'] ?? ''), 'seen' => $a['seen_at'] !== null,
                'acked_at' => opsh_ann_iso($a['ack_at']), 'live' => $live];
        if ($live) {
            $out['current'][] = $row;
            if ($a['seen_at'] === null) $out['unread']++;
            if ($a['requires_ack'] && $a['ack_at'] === null) $out['to_ack']++;
        } elseif (count($out['past']) < 40) $out['past'][] = $row;
    }
    // What still needs the person comes first, then by level, newest first.
    usort($out['current'], function ($x, $y) use ($rank) {
        $nx = ($x['requires_ack'] && !$x['acked_at']) || !$x['seen']; $ny = ($y['requires_ack'] && !$y['acked_at']) || !$y['seen'];
        if ($nx !== $ny) return $nx ? -1 : 1;
        if ($rank[$x['level']] !== $rank[$y['level']]) return $rank[$x['level']] <=> $rank[$y['level']];
        return strcmp((string)$y['published_at'], (string)$x['published_at']);
    });
    return $out;
}
/** Just the two numbers, for the menu badge and the strip at the top of every page. */
function opsh_ann_counts_for_user(PDO $db, int $uid): array
{
    if (!$uid || !opsh_ann_ready($db)) return ['unread' => 0, 'to_ack' => 0, 'urgent' => 0];
    $st = $db->prepare("SELECT SUM(r.seen_at IS NULL) AS unread, SUM(a.requires_ack=1 AND r.ack_at IS NULL) AS to_ack,
                               SUM(a.level='urgent' AND ((a.requires_ack=1 AND r.ack_at IS NULL) OR r.seen_at IS NULL)) AS urgent
                        FROM opsh_announcement_recipients r JOIN opsh_announcements a ON a.id=r.announcement_id
                        WHERE r.user_id=? AND a.status='published' AND (a.expires_at IS NULL OR a.expires_at > ?)");
    $st->execute([$uid, opsh_ann_now()]); $r = $st->fetch() ?: [];
    return ['unread' => (int)($r['unread'] ?? 0), 'to_ack' => (int)($r['to_ack'] ?? 0), 'urgent' => (int)($r['urgent'] ?? 0)];
}
