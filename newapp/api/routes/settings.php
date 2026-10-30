<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
// BT18: the Settings page is split into access areas. Each setting key belongs to ONE module:
//   branding  → app name / logo / favicon / navigation icons          (Settings → Branding)
//   email_cfg → outbound mail, intake mailbox, notifications, templates (Settings → Email, Email templates)
//   sla_cfg   → SLA alerts, digest, business hours                     (Settings → SLA & Hours)
//   settings  → everything else: connection, security, redirects…     (Settings → General)
// Anyone with View on at least one area may open the page; GET returns only the keys of the areas they can see,
// and PUT checks Edit on the area of EVERY key sent. Super Admin bypasses all of this (itops_can).
require_once __DIR__ . '/../lib/SettingsAreas.php';
$db = getDB();
$isSuperS = (($currentUser['role_slug'] ?? '') === 'super_admin');
$areas = ['settings', 'branding', 'email_cfg', 'sla_cfg'];
$canAny = false; foreach ($areas as $a) { if (itops_can($currentUser, $a, $method === 'GET' ? 'view' : 'edit')) { $canAny = true; break; } }
if (!$canAny) error_response('Insufficient permissions', 403);
// Keys that hold credentials / connection config. Only a Super Admin may change them through this
// generic endpoint (their own screens use dedicated routes); they are never returned in clear text.
$SENSITIVE = '/^(o365_|guardian_|vtiger_|ai_|mail_|graph_|jwt|vault|mfa_required_scope$|session_idle_min$|session_timeout|auto_tick|cases_people$|change_freezes$|login_|lockout|redirect_allowed_hosts$|public_form_redirect_url$|cron_|m365_|teams_|bridge_|backup_|kb_public|webhook_|access_|ticket_routing$|calendar_types$|budget_|device_inventory|ia_collectors)/i';
// BT86 (code audit H-6): what someone other than a Super Admin may change here — every other key is refused (and logged)
// unless it is sent unchanged. Keys the app keeps for itself (numbering, rate limits, reminders sent, timestamps) are never
// written through this endpoint, and the logo and icon are uploaded (POST), only removed here.
$OPEN_KEYS = ['app_name', 'app_tagline', 'signin_default', 'nav_emoji', 'accent_color', 'default_theme', 'app_logo_url', 'app_favicon_url', 'doc_org_name', 'doc_footer', 'doc_accent', 'doc_logo_url',
              'business_hours', 'sla_alerts_enabled', 'sla_response_warn_min', 'sla_resolution_warn_min', 'sla_digest_enabled', 'sla_urgent_shorter', 'sla_autoclose_days', 'sla_priority_targets', 'sla_priority_defs',
              'sla_incident_targets', 'sla_csat_enabled', 'alert_digest_enabled', 'alert_min_severity', 'scorecard_start', 'weekly_digest_enabled', 'weekly_digest_dow', 'announcement_text', 'announcement_level',
              'tpl_brand_color', 'tpl_show_logo', 'tpl_on_ticket_reply', 'support_email',
              'app_install_enabled', 'app_short_name', 'app_theme_color', 'app_wipe_signout'];   // BT147
$INTERNAL = '/(^lm_|^app_timezone$|^license_trial_from$|_seq_\d{4}$|^rl_|^rem_|^ll_|^installed_at$|_last$|^db_last_backup$|^cron_since$|^cron_watch|^forms_default_seeded$|^improvement_seq|^case_seq|^project_seq|^ticket_seq|^guardian_whm_pin$|^install_id$)/i';   // BT91: the WHM certificate is trusted on its own screen (it must match what WHM shows)
// BT131: the installation's time zone (GET for everyone who may open Settings; POST: Super Admin, CHANGE typed once the
// installation holds records, because times written as clock values are not rewritten)
if (($segments[1] ?? '') === 'timezone') {
    require_once __DIR__ . '/../lib/TimeZone.php';
    $tzNow = itops_tz_get($db); $fixed = defined('ITOPS_TIMEZONE');
    $since = (string)$db->query("SELECT sval FROM itops_settings WHERE skey='installed_at'")->fetchColumn();
    $records = (int)$db->query("SELECT COUNT(*) FROM itops_tickets")->fetchColumn() + (int)$db->query("SELECT COUNT(*) FROM itops_audit_log WHERE action NOT IN ('system_installed')")->fetchColumn();
    if ($method === 'GET') {
        $common = []; foreach (itops_tz_common() as $k => $v) $common[] = ['id' => $k, 'label' => $v];
        json_response(['timezone' => $tzNow, 'label' => itops_tz_label($tzNow), 'fixed_in_config' => $fixed, 'common' => $common, 'all' => DateTimeZone::listIdentifiers(),
                       'now' => date('Y-m-d H:i:s'), 'offset' => date('P'), 'has_records' => $records > 0, 'installed_at' => $since]);
    }
    if ($method !== 'POST') error_response('Not found', 404);
    if (!$isSuperS) { audit_log('settings_refused', 'settings', null, 'Time zone', null, ['Why' => 'Super Admin only']); error_response('Only a Super Admin changes the time zone.', 403); }
    if ($fixed) error_response('The time zone is set in config.php (ITOPS_TIMEZONE) on this server; change it there.', 409);
    $b = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    $tz = trim((string)($b['timezone'] ?? ''));
    if (!itops_tz_valid($tz)) error_response('Choose a time zone from the list (an IANA name such as America/Chicago).', 400);
    if ($tz === $tzNow) error_response('That is already the time zone.', 400);
    if ($records > 0 && ($b['confirm'] ?? '') !== 'CHANGE') error_response('This installation already holds records. Times the app wrote as clock values (due times, schedules, many logs) keep them and can look shifted by the difference. Type CHANGE to switch the zone anyway.', 409);
    $db->prepare("INSERT INTO itops_settings (skey,sval) VALUES ('app_timezone',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([$tz]);
    audit_log('timezone_changed', 'settings', null, 'Time zone', ['Time zone' => $tzNow], ['Time zone' => $tz]);
    json_response(['ok' => true, 'timezone' => $tz, 'label' => itops_tz_label($tz)]);
}

switch($method){
  case 'GET':
    $stmt = $db->query('SELECT skey, sval FROM itops_settings');
    $settings = [];
    while ($row = $stmt->fetch()) $settings[$row['skey']] = $row['sval'];
    // Never send stored secrets to the browser — mask them (PUT ignores the mask).
    unset($settings['vtiger_provision'], $settings['vtiger_db']);   // JSON blobs with secrets — served (masked) by /vtiger_provision
    unset($settings['access_conn'], $settings['access_systems']);   // BT45: company systems and their connections have their own routes (/access/…); access_conn holds wrapped keys
    foreach ($settings as $k => &$v) {
        if (preg_match('/(token|secret|_pass|password|api_key|_enc)$/i', $k)) $v = ($v === '' ? '' : '********');
    }
    unset($v);
    // BT18: only the areas this role may view
    foreach (array_keys($settings) as $k) { if (!itops_can($currentUser, itops_setting_area($k), 'view')) unset($settings[$k]); }
    // BT124 (code review CORE-1): settings that belong to a module with its own permission (security posture and its emergency
    // accounts, Microsoft 365, IT Budget, routing, people of cases, Teams, devices, uptime, jobs) and the app's own bookkeeping
    // (rate limits, timestamps, counters) are served by their own pages, not here — except to a Super Admin
    if (!$isSuperS) foreach (array_keys($settings) as $k)
        if (preg_match('/^(sa_|m365_|budget_|ca_|ms_billing$|cases_people$|ticket_routing$|teams_app$|device_inventory|uptime_|cron_|outlook_|guardian_|ia_|risk_)/', $k) || preg_match($INTERNAL, $k)) unset($settings[$k]);
    json_response($settings); break;
  case 'POST':
    itops_require($currentUser, 'branding', 'edit');   // BT18
    $target = $id; // 'logo', 'favicon' or 'doclogo' (BT60: the logo printed on PDF and Word documents)
    if (!in_array($target, ['logo','favicon','doclogo'])) error_response('Unknown upload target', 400);
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) error_response('No file uploaded', 400);
    $file = $_FILES['file'];
    if ($file['size'] > 5*1024*1024) error_response('Image too large (max 5MB)', 400);
    $detected = function_exists('finfo_open') ? finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']) : '';
    $map = ['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp','image/vnd.microsoft.icon'=>'ico','image/x-icon'=>'ico'];
    if (!isset($map[$detected])) error_response('Use PNG, JPG, ICO, GIF or WEBP (SVG is not allowed for security reasons)', 400);
    if ($target === 'doclogo' && !in_array($detected, ['image/png','image/jpeg','image/gif','image/webp'], true)) error_response('Use a PNG or JPG for the document logo (GIF and WEBP work too).', 400);
    $ext = $map[$detected];
    $dir = UPLOAD_DIR . 'logos/'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $stored = $target . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $stored)) error_response('Could not save file', 500);
    $url = rtrim(APP_URL, '/') . '/public/uploads/logos/' . $stored;
    $skey = $target === 'logo' ? 'app_logo_url' : ($target === 'doclogo' ? 'doc_logo_url' : 'app_favicon_url');
    $old = (string)($db->query("SELECT sval FROM itops_settings WHERE skey=" . $db->quote($skey))->fetchColumn() ?: '');
    $db->prepare('INSERT INTO itops_settings (skey,sval,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval),updated_by=VALUES(updated_by)')
       ->execute([$skey, $url, $currentUser['id']]);
    if (function_exists('audit_log')) audit_log('branding_uploaded', 'settings', null, $target, ['File' => $old !== '' ? basename($old) : '(none)'], ['File' => $stored]);
    json_response(['ok'=>true, 'url'=>$url]);
    break;
  case 'PUT':
    $body=json_decode(file_get_contents('php://input'),true);
    if (!is_array($body)) error_response('JSON object expected', 400);
    foreach (array_keys($body) as $k) {
      if (!is_string($k) || !preg_match('/^[a-z0-9_]{1,100}$/i', $k)) error_response('Invalid setting name', 400);
      if (preg_match($INTERNAL, $k)) { audit_log('settings_refused', 'settings', null, $k, null, ['reason' => 'kept by the app']); error_response('"' . $k . '" is kept by the app and is not changed here.', 400); }
      if ($k === 'vtiger_left_remind_days' && (!preg_match('/^\d{1,2}$/', (string)$body[$k]) || (int)$body[$k] > 90)) error_response('The reminder for vTiger records left for later is a number of days from 0 (off) to 90.', 400);   // BT93
      if ($k === 'scorecard_start' && (string)$body[$k] !== '' && !(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$body[$k]) && checkdate((int)substr((string)$body[$k], 5, 2), (int)substr((string)$body[$k], 8, 2), (int)substr((string)$body[$k], 0, 4)))) error_response('The Scorecard start is a date (YYYY-MM-DD).', 400);   // BT92
      // BT103: the line under the logo on the sign-in page, and which way of signing in it shows first
      if ($k === 'app_tagline') { if (!is_string($body[$k])) error_response('The sign-in tagline is text.', 400); $body[$k] = trim(preg_replace('/[\x00-\x1F\x7F<>]/u', '', $body[$k])); if (mb_strlen($body[$k]) > 80) error_response('The sign-in tagline is at most 80 characters.', 400); }
      if ($k === 'signin_default' && !in_array($body[$k], ['local', 'microsoft'], true)) error_response('The default sign-in is "local" or "microsoft".', 400);
      // BT147: installing the app (Settings → Branding → Install as an app)
      if (in_array($k, ['app_install_enabled', 'app_wipe_signout'], true)) { if (!in_array((string)$body[$k], ['0', '1'], true)) error_response('Use 1 (on) or 0 (off).', 400); $body[$k] = (string)$body[$k]; }
      if ($k === 'app_short_name') { if (!is_string($body[$k])) error_response('The short name is text.', 400); $body[$k] = trim(preg_replace('/[\x00-\x1F\x7F<>]/u', '', $body[$k])); if (mb_strlen($body[$k]) > 12) error_response('The short name is up to 12 characters (it shows under the icon).', 400); }
      if ($k === 'app_theme_color' && (string)$body[$k] !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', (string)$body[$k])) error_response('The theme color is a hex color such as #1D4D7A.', 400);
      if (in_array($k, ['app_logo_url', 'app_favicon_url'], true) && (string)$body[$k] !== '') error_response('Upload the logo and the icon with the Upload buttons; they can only be removed here.', 400);   // BT86 (audit H-12)
      if (!$isSuperS && (preg_match($SENSITIVE, $k) || !in_array($k, $OPEN_KEYS, true))) {
        $sq = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $sq->execute([$k]); $cv = $sq->fetchColumn();
        $nv = is_array($body[$k]) || is_object($body[$k]) ? json_encode($body[$k]) : (string)$body[$k];
        if ($cv !== false && (string)$cv === $nv || ($cv === false && $nv === '') || $nv === '********') { unset($body[$k]); continue; }   // sent unchanged
        audit_log('settings_refused', 'settings', null, $k, null, ['reason' => 'Super Admin only', 'asked_by' => (string)($currentUser['email'] ?? '')]);
        error_response('Only a Super Admin can change "' . $k . '"', 403);
      }
      if (!itops_can($currentUser, itops_setting_area($k), 'edit')) error_response('Your role cannot change "' . $k . '" (' . itops_setting_area_label(itops_setting_area($k)) . ')', 403);
      if (preg_match('/^(sla_|vtiger_|access_)/', strtolower((string)$k))) error_response('"' . $k . '" is not a setting of this product.', 400);   // 0001-A004: removed modules
    }
    // BT60: document branding — checked here, and logged with the values before and after
    $docOld = []; $docNew = [];
    foreach ($body as $k => $v) {
      if (strpos((string)$k, 'doc_') !== 0) continue;
      if (!in_array($k, ['doc_org_name', 'doc_footer', 'doc_accent', 'doc_logo_url'], true)) error_response('Unknown document setting "' . $k . '"', 400);
      if (!is_string($v)) error_response('Document settings are text.', 400);
      $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
      if ($k === 'doc_accent' && $v !== '' && !preg_match('/^#?[0-9A-Fa-f]{6}$/', $v)) error_response('The document colour is a hex colour such as #1D4D7A.', 400);
      if ($k === 'doc_logo_url' && $v !== '') error_response('Upload the document logo with the Upload button; it can only be removed here.', 400);
      if (mb_strlen($v) > ($k === 'doc_footer' ? 160 : 120)) error_response('That text is too long.', 400);
      $body[$k] = $v;
      $st = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $st->execute([$k]); $ov = (string)($st->fetchColumn() ?: '');
      $lab = ['doc_org_name' => 'Organization name', 'doc_footer' => 'Footer', 'doc_accent' => 'Colour', 'doc_logo_url' => 'Document logo'][$k];
      if ($ov !== $v) { $docOld[$lab] = $k === 'doc_logo_url' ? basename($ov) : $ov; $docNew[$lab] = $k === 'doc_logo_url' ? '(removed)' : $v; }
    }
    require_once __DIR__ . '/../lib/Secrets.php';
    // Review CORE-25: the Audit Log gets what changed (before and after); secret values are never written, only "changed".
    $__isSecret = fn($k) => in_array($k, itops_secret_setting_keys(), true) || preg_match('/secret|password|passwd|token|_key$|api_key|_enc$/i', (string)$k);
    $__old = []; foreach (array_keys($body) as $__k) { $st = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $st->execute([$__k]); $__old[$__k] = $st->fetchColumn(); }
    $put = function ($k, $v) use ($db, $currentUser) {
      $db->prepare('INSERT INTO itops_settings (skey,sval,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval),updated_by=VALUES(updated_by)')
         ->execute([$k,$v,$currentUser['id']]);
    };
    foreach($body as $k=>$v){
      if ($v === '********') continue;   // masked placeholder from GET — leave the stored secret untouched
      if (is_array($v) || is_object($v)) $v = json_encode($v);
      // BT14 — ONE Microsoft 365 app configuration. The Graph card (Integrations → System) and the sign-in card
      // (Connection → Microsoft 365 sign-in) share tenant, client id and ONE encrypted secret (o365_client_secret_enc).
      if ($k === 'o365_client_secret') {
        if ((string)$v === '') continue;
        $put('o365_client_secret_enc', vault_encrypt((string)$v));
        $db->prepare("DELETE FROM itops_settings WHERE skey IN ('o365_client_secret','o365_graph_secret_enc')")->execute();
        continue;
      }
      if ($k === 'o365_tenant_id' || $k === 'o365_tenant') { $put('o365_tenant_id', (string)$v); $put('o365_tenant', (string)$v); continue; }
      if (in_array($k, itops_secret_setting_keys(), true) && (string)$v !== '') $v = itops_secret_wrap((string)$v);   // encrypted at rest
      $put($k, $v);
    }
    $__b = []; $__a = [];
    foreach ($body as $__k => $__v) {
      if ($__v === '********') continue;
      $nv = is_array($__v) || is_object($__v) ? json_encode($__v) : (string)$__v; $ov = $__old[$__k] === false ? null : (string)$__old[$__k];
      if ($__isSecret($__k)) { if ($nv !== '') { $__b[$__k] = $ov === null || $ov === '' ? '(not set)' : '(set)'; $__a[$__k] = '(changed)'; } continue; }
      if ($ov !== $nv) { $__b[$__k] = $ov === null ? '(not set)' : mb_substr($ov, 0, 300); $__a[$__k] = mb_substr($nv, 0, 300); }
    }
    audit_log('settings_updated','settings',null,implode(',',array_keys($body)), $__b ?: null, $__a ?: null);
    if ($docNew) audit_log('doc_branding_save', 'settings', null, 'Document branding', $docOld, $docNew);
    json_response(['ok'=>true]); break;
}
