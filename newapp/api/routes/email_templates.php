<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Editable system email templates (English + Spanish)
 *
 *   GET    /api/email_templates                  catalog + overrides + on/off + master switches + settings
 *   GET    /api/email_templates/:key             one template: defaults, overrides, placeholders
 *   PUT    /api/email_templates/:key             {lang, subject, body}  save an override (validated)
 *   DELETE /api/email_templates/:key?lang=en     reset one language to the built-in default
 *   PUT    /api/email_templates/:key/enabled     {enabled: true|false}
 *   POST   /api/email_templates/:key/preview     {lang: en|es|both, subject?, body?}  → rendered subject/html/text with sample data
 *   POST   /api/email_templates/:key/test        {lang, subject?, body?}  send the (unsaved) version to the current user
 *   GET    /api/email_templates/:key/history     change history (who / when / before / after)
 *   PUT    /api/email_templates/settings         {mail_default_lang, tpl_wrap_header_en, …, tpl_brand_color, tpl_show_logo}
 *
 * Defaults live in code (api/lib/EmailTemplates.php); only edits are stored (opsh_email_templates).
 * Permission: Connection (settings) — view to read, edit to change.
 */
require_once __DIR__ . '/../lib/MailIntake.php';
require_once __DIR__ . '/../lib/EmailTemplates.php';

// Reading and PREVIEWING (renders sample data, sends nothing) need view; everything else needs edit.
$isPreview = ($method === 'POST' && ($segments[2] ?? '') === 'preview');
opsh_require($currentUser, 'email_cfg', ($method === 'GET' || $isPreview) ? 'view' : 'edit');   // BT18: Settings · Email area
$db = getDB();
$cat = opsh_tpl_catalog();
require_once __DIR__ . '/../lib/Modules.php';   // BT101: emails of modules that are not part of this installation are left out (their wording is kept)
$cat = array_filter($cat, fn($k) => ($tm = opsh_tpl_module((string)$k)) === null || opsh_module_on($tm), ARRAY_FILTER_USE_KEY);
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$SETTINGS = ['mail_default_lang', 'tpl_wrap_header_en', 'tpl_wrap_footer_en', 'tpl_wrap_header_es', 'tpl_wrap_footer_es', 'tpl_brand_color', 'tpl_show_logo'];

$setting = function (string $k, $v) use ($db, $currentUser) {
    $db->prepare('INSERT INTO opsh_settings (skey,sval,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval),updated_by=VALUES(updated_by)')
       ->execute([$k, (string)$v, (int)$currentUser['id']]);
};
$rawSettings = function () use ($db): array {
    return $db->query("SELECT skey, sval FROM opsh_settings WHERE skey LIKE 'tpl\\_%' OR skey LIKE 'mail\\_%'")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
};
$overrides = function () use ($db): array {
    $o = [];
    try {
        foreach ($db->query('SELECT t.tpl_key, t.lang, t.subject, t.body, t.updated_at, u.display_name AS updated_by_name
                             FROM opsh_email_templates t LEFT JOIN opsh_users u ON u.id=t.updated_by')->fetchAll() as $r)
            $o[$r['tpl_key']][$r['lang']] = $r;
    } catch (Throwable $e) { error_response('Email templates need a database update — run db-update.php once.', 409); }
    return $o;
};
$masterLabels = ['mail_ack' => 'Acknowledge new email tickets', 'mail_notify_reply' => 'Email requester on agent reply',
                 'mail_notify_status' => 'Email requester on status change', 'mail_notify_assign' => 'Email agent on assignment'];
$vars = function (string $key) use ($cat): array {
    $v = [];
    foreach (opsh_tpl_common_vars() as $n => $d) $v[] = ['name' => $n, 'sample' => $d[0], 'help' => $d[1], 'common' => true];
    foreach ($cat[$key][5] as $n => $d) $v[] = ['name' => $n, 'sample' => $d[0], 'help' => $d[1], 'common' => false];
    return $v;
};
$sample = function (string $key) use ($cat, $currentUser): array {
    $s = ['recipient_name' => $currentUser['display_name'] ?? 'Alex Sample'];
    foreach ($cat[$key][5] as $n => $d) $s[$n] = $d[0];
    // localised samples so the Spanish preview reads naturally
    $es = ['status' => ['In progress' => 'En progreso', 'Resolved' => 'Resuelta'], 'priority' => ['High' => 'Alta'], 'sla_type' => ['Response' => 'respuesta', 'Resolution' => 'resolución']];
    foreach ($es as $n => $map) if (isset($s[$n]) && isset($map[$s[$n]])) $s[$n] = ['en' => $s[$n], 'es' => $map[$s[$n]]];
    foreach (['due', 'due_date', 'end_date', 'date'] as $n) if (isset($s[$n]) && ($t = strtotime(str_replace(',', '', $s[$n]))) !== false) $s[$n] = opsh_tpl_d2(date(strlen($s[$n]) > 12 ? 'Y-m-d H:i' : 'Y-m-d', $t));
    if (isset($s['report']) && strpos($key, 'digest_') === 0) {
        $s['report'] = ['en' => $s['report'], 'es' => strtr($s['report'], ['Tickets created' => 'Tickets creados', 'Tickets resolved' => 'Tickets resueltos',
            'Response SLA compliance' => 'Cumplimiento SLA de respuesta', 'Open tickets' => 'Tickets abiertos', 'SLA-breached' => 'SLA incumplido',
            'Equipment on loan' => 'Equipos en préstamo', 'overdue' => 'vencidos', 'since' => 'desde'])];
    }
    // sample "follow it online" box (real emails only show it to people with an OpsHub account)
    $s['portal_link'] = opsh_tpl_portal_link(opsh_tpl_ticket_url(142) ?: 'https://ops.example.com/#tickets/142', (string)($s['ticket_number'] ?? 'TK-2026-0142'));
    return $s;
};
$langOk = fn($l) => in_array($l, ['en', 'es'], true);

// ── Settings (header / footer / default language / brand) ──────────────────────
if ($id === 'settings') {
    if ($method !== 'PUT') error_response('Method not allowed', 405);
    $changed = [];
    foreach ($SETTINGS as $k) {
        if (!array_key_exists($k, $in)) continue;
        $v = (string)$in[$k];
        if ($k === 'mail_default_lang' && !in_array($v, ['en', 'es', 'both'], true)) error_response('Default language must be en, es or both', 400);
        if ($k === 'tpl_brand_color' && $v !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $v)) error_response('Brand colour must look like #1D4D7A', 400);
        if ($k === 'tpl_show_logo') $v = $v === '1' ? '1' : '0';
        if (strpos($k, 'tpl_wrap_') === 0) {
            if (mb_strlen($v) > 2000) error_response('Header / footer is too long (2,000 characters max)', 400);
            $allowed = array_keys(opsh_tpl_common_vars());
            $bad = array_diff(opsh_tpl_names($v), $allowed);
            if ($bad) error_response('Header / footer can only use the common placeholders. Unknown: {{' . implode('}}, {{', $bad) . '}}', 400);
        }
        $setting($k, $v); $changed[$k] = $v;
    }
    if ($changed) audit_log('email_template_settings', 'email_template', null, 'settings', null, $changed);
    json_response(['ok' => true]);
}

// ── List ────────────────────────────────────────────────────────────────────────
if (!$id) {
    if ($method !== 'GET') error_response('Method not allowed', 405);
    $ov = $overrides(); $st = $rawSettings(); $wd = opsh_tpl_wrapper_defaults();
    $list = [];
    foreach ($cat as $k => $c) {
        $master = $c[4];
        $masterOn = !$master || (($st[$master] ?? ($master === 'mail_notify_assign' ? '1' : '0')) === '1');
        $list[] = ['key' => $k, 'group' => $c[0], 'label' => $c[1], 'desc' => $c[2], 'recipients' => $c[3],
                   'enabled' => ($st['tpl_on_' . $k] ?? '1') === '1',
                   'master' => $master, 'master_label' => $master ? ($masterLabels[$master] ?? $master) : null, 'master_on' => $masterOn,
                   'edited' => ['en' => isset($ov[$k]['en']), 'es' => isset($ov[$k]['es'])],
                   'updated_at' => max($ov[$k]['en']['updated_at'] ?? '', $ov[$k]['es']['updated_at'] ?? '') ?: null];
    }
    $settings = [
        'mail_default_lang' => $st['mail_default_lang'] ?? 'en',
        'tpl_brand_color' => $st['tpl_brand_color'] ?? '#1D4D7A',
        'tpl_show_logo' => $st['tpl_show_logo'] ?? '0',
        'mail_out_enabled' => $st['mail_out_enabled'] ?? '0',
        'defaults' => $wd,
        'common_vars' => array_map(fn($n, $d) => ['name' => $n, 'sample' => $d[0], 'help' => $d[1]], array_keys(opsh_tpl_common_vars()), opsh_tpl_common_vars()),
    ];
    foreach (['en', 'es'] as $l) {
        $settings['tpl_wrap_header_' . $l] = $st['tpl_wrap_header_' . $l] ?? $wd[$l]['header'];
        $settings['tpl_wrap_footer_' . $l] = $st['tpl_wrap_footer_' . $l] ?? $wd[$l]['footer'];
    }
    json_response(['templates' => $list, 'settings' => $settings]);
}

if (!isset($cat[$id])) error_response('Unknown email template', 404);
$key = $id; $c = $cat[$key];

// ── Enable / disable ────────────────────────────────────────────────────────────
if ($sub === 'enabled') {
    if ($method !== 'PUT') error_response('Method not allowed', 405);
    $on = !empty($in['enabled']);
    $setting('tpl_on_' . $key, $on ? '1' : '0');
    audit_log($on ? 'email_template_enabled' : 'email_template_disabled', 'email_template', null, $key);
    json_response(['ok' => true, 'enabled' => $on]);
}

// ── History ─────────────────────────────────────────────────────────────────────
if ($sub === 'history') {
    $st = $db->prepare("SELECT id, user_name, action, entity_name, old_value, new_value, created_at FROM opsh_audit_log
                        WHERE entity_type='email_template' AND (entity_name=? OR entity_name LIKE ?) ORDER BY id DESC LIMIT 100");
    $st->execute([$key, $key . ':%']);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = ['id' => (int)$r['id'], 'at' => $r['created_at'], 'by' => $r['user_name'] ?: 'System', 'action' => $r['action'],
                  'lang' => strpos($r['entity_name'], ':') ? substr($r['entity_name'], strpos($r['entity_name'], ':') + 1) : null,
                  'before' => $r['old_value'] ? json_decode($r['old_value'], true) : null, 'after' => $r['new_value'] ? json_decode($r['new_value'], true) : null];
    }
    json_response($out);
}

// ── Preview / test (unsaved edits allowed) ──────────────────────────────────────
if ($sub === 'preview' || $sub === 'test') {
    if ($method !== 'POST') error_response('Method not allowed', 405);
    $lang = (string)($in['lang'] ?? 'en');
    if (!in_array($lang, ['en', 'es', 'both'], true)) error_response('Language must be en, es or both', 400);
    $override = null; $oLang = null;
    if (isset($in['subject']) || isset($in['body'])) {
        $subj = (string)($in['subject'] ?? ''); $body = (string)($in['body'] ?? '');
        $errs = opsh_tpl_validate($key, $subj, $body);
        if ($errs) error_response(implode(' ', $errs), 400);
        $override = ['subject' => $subj, 'body' => $body];
        $oLang = $lang === 'both' ? ($in['edit_lang'] ?? 'en') : $lang;
    }
    $r = opsh_tpl_render($key, $lang, $sample($key), $override, $oLang);
    if ($sub === 'preview') json_response(['subject' => $r['subject'], 'html' => $r['html'], 'text' => $r['text']]);
    $to = (string)($currentUser['email'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) error_response('Your account has no valid email address to send the test to', 400);
    $res = mail_send($to, '[TEST] ' . $r['subject'], $r['text'], ['kind' => 'template_test', 'html' => $r['html']]);
    if (empty($res['ok'])) error_response('Test email failed: ' . ($res['error'] ?? 'unknown error'), 502);
    json_response(['ok' => true, 'to' => $to]);
}

if ($sub) error_response('Not found', 404);

// ── One template ────────────────────────────────────────────────────────────────
switch ($method) {
    case 'GET':
        $ov = $overrides()[$key] ?? [];
        $st = $rawSettings();
        $out = ['key' => $key, 'group' => $c[0], 'label' => $c[1], 'desc' => $c[2], 'recipients' => $c[3],
                'enabled' => ($st['tpl_on_' . $key] ?? '1') === '1', 'master' => $c[4],
                'master_label' => $c[4] ? ($masterLabels[$c[4]] ?? $c[4]) : null,
                'master_on' => !$c[4] || (($st[$c[4]] ?? ($c[4] === 'mail_notify_assign' ? '1' : '0')) === '1'),
                'required_in_subject' => $c[6], 'vars' => $vars($key)];
        foreach (['en' => 7, 'es' => 8] as $l => $i) {
            $o = $ov[$l] ?? null;
            $out[$l] = ['subject' => $o['subject'] ?? $c[$i][0], 'body' => $o['body'] ?? $c[$i][1], 'edited' => (bool)$o,
                        'default_subject' => $c[$i][0], 'default_body' => $c[$i][1],
                        'updated_at' => $o['updated_at'] ?? null, 'updated_by' => $o['updated_by_name'] ?? null];
        }
        json_response($out);
        break;

    case 'PUT':
        $lang = (string)($in['lang'] ?? '');
        if (!$langOk($lang)) error_response('Language must be en or es', 400);
        $subj = trim((string)($in['subject'] ?? '')); $body = (string)($in['body'] ?? '');
        $errs = opsh_tpl_validate($key, $subj, $body);
        if ($errs) error_response(implode(' ', $errs), 400);
        $prev = $overrides()[$key][$lang] ?? null;
        $i = $lang === 'es' ? 8 : 7;
        $before = ['subject' => $prev['subject'] ?? $c[$i][0], 'body' => $prev['body'] ?? $c[$i][1]];
        if ($before['subject'] === $subj && $before['body'] === $body) json_response(['ok' => true, 'unchanged' => true]);
        $db->prepare('INSERT INTO opsh_email_templates (tpl_key, lang, subject, body, updated_by) VALUES (?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE subject=VALUES(subject), body=VALUES(body), updated_by=VALUES(updated_by), updated_at=NOW()')
           ->execute([$key, $lang, $subj, $body, (int)$currentUser['id']]);
        audit_log('email_template_updated', 'email_template', null, $key . ':' . $lang, $before, ['subject' => $subj, 'body' => $body]);
        json_response(['ok' => true]);
        break;

    case 'DELETE':
        $lang = (string)($_GET['lang'] ?? '');
        if (!$langOk($lang)) error_response('Language must be en or es', 400);
        $prev = $overrides()[$key][$lang] ?? null;
        if (!$prev) json_response(['ok' => true, 'unchanged' => true]);
        $db->prepare('DELETE FROM opsh_email_templates WHERE tpl_key=? AND lang=?')->execute([$key, $lang]);
        $i = $lang === 'es' ? 8 : 7;
        audit_log('email_template_reset', 'email_template', null, $key . ':' . $lang,
            ['subject' => $prev['subject'], 'body' => $prev['body']], ['subject' => $c[$i][0], 'body' => $c[$i][1]]);
        json_response(['ok' => true]);
        break;

    default:
        error_response('Method not allowed', 405);
}
