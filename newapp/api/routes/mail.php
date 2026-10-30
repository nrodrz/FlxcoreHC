<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — outgoing email settings (Settings → Email)
 * GET  /api/mail                    → current settings (passwords masked), the in-app scheduler switch, the last sends
 * PUT  /api/mail                    → save settings (SMTP password encrypted via vault)
 * POST /api/mail/sendtest           → send a test email to oneself
 * POST /api/mail/rotate_cron_token  → a new token for the web call of cron-sla.php (Super Admin)
 * 0001-A004: the email-to-ticket intake (mailbox, IMAP test, poll, ticket emails) is gone with the Service Desk.
 */
itops_require($currentUser, 'email_cfg', $method === 'GET' ? 'view' : 'edit');   // BT18: Settings · Email area
require_once __DIR__ . '/../lib/Mail.php';
$db = getDB();

if (!function_exists('mail_set')) {
    function mail_set($db, $k, $v, $uid) {
        $db->prepare('INSERT INTO itops_settings (skey,sval,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval),updated_by=VALUES(updated_by)')
           ->execute([$k, $v, $uid]);
    }
}

switch ($method) {
    case 'GET':
        $cfg = mail_cfg();
        json_response([
            // BT14: the cron token runs privileged scheduled jobs (incl. scheduled offboarding) — Super Admin only.
            'mail_cron_token'         => (($currentUser['role_slug'] ?? '') === 'super_admin') ? ($cfg['mail_cron_token'] ?? '') : '',
            'mail_cron_token_set'     => !empty($cfg['mail_cron_token']) ? 1 : 0,
            'mail_out_enabled'        => $cfg['mail_out_enabled'] ?? '0',
            'mail_out_method'         => $cfg['mail_out_method'] ?? 'mailfn',
            'mail_from_email'         => $cfg['mail_from_email'] ?? '',
            'mail_from_name'          => $cfg['mail_from_name'] ?? itops_app_name(),
            'mail_smtp_host'          => $cfg['mail_smtp_host'] ?? '',
            'mail_smtp_port'          => $cfg['mail_smtp_port'] ?? '587',
            'mail_smtp_security'      => $cfg['mail_smtp_security'] ?? 'tls',
            'mail_smtp_user'          => $cfg['mail_smtp_user'] ?? '',
            'mail_smtp_pass_set'      => !empty($cfg['mail_smtp_pass_enc']) ? 1 : 0,
            'mail_auto_tick'          => $cfg['mail_auto_tick'] ?? '1',
            'mail_log'                => (function () { try { return getDB()->query("SELECT l.id,l.to_email,l.subject,l.method,l.kind,l.ticket_id,l.ok,l.error,l.created_at,u.display_name AS sent_by_name FROM itops_mail_log l LEFT JOIN itops_users u ON u.id=l.sent_by ORDER BY l.id DESC LIMIT 40")->fetchAll(); } catch (Throwable $e) { return []; } })(),
            'auto_tick_last'          => (function () { try { $v = getDB()->query("SELECT sval FROM itops_settings WHERE skey='auto_tick_last'")->fetchColumn(); return $v ? json_decode($v, true) : null; } catch (Throwable $e) { return null; } })(),
        ]);
        break;

    case 'PUT':
        $b = json_decode(file_get_contents('php://input'), true) ?: [];
        if (!empty($b['mail_smtp_pass']) && ($currentUser['role_slug'] ?? '') !== 'super_admin') error_response('Only a Super Admin can change the SMTP password', 403);
        // BT87 (code audit H-5): the saved passwords follow their server — changing the server or account needs the password again
        { $stored = mail_cfg(); $isSA = ($currentUser['role_slug'] ?? '') === 'super_admin';
          foreach ([['mail_smtp_pass', 'mail_smtp_pass_enc', ['mail_smtp_host', 'mail_smtp_port', 'mail_smtp_security', 'mail_smtp_user'], 'SMTP']] as [$pk, $enc, $keys, $what]) {
            if (empty($stored[$enc]) || !empty($b[$pk])) continue;
            $moved = []; foreach ($keys as $k) if (array_key_exists($k, $b) && strtolower(trim((string)$b[$k])) !== strtolower(trim((string)($stored[$k] ?? '')))) $moved[] = $k;
            if (!$moved) continue;
            audit_log('mail_settings_refused', 'settings', null, 'mail', null, ['changed' => implode(', ', $moved), 'reason' => 'the ' . $what . ' password must be typed again']);
            error_response($isSA ? 'You changed the ' . $what . ' server or account: type its password too (the saved password is only used with the server it was saved for).'
                                 : 'Changing the ' . $what . ' server or account needs its password, which only a Super Admin enters.', $isSA ? 400 : 403);
          } }
        // BT124 (code review CORE-2): where mail goes and who it comes from — the servers, methods, accounts, sender address and
        // the scheduler switch — are changed by a Super Admin only, whether a password is saved or not
        if (($currentUser['role_slug'] ?? '') !== 'super_admin') {
            $cur0 = mail_cfg(); $hit = [];
            foreach (array_keys($b) as $k) if (preg_match('/^mail_(out_method|smtp_host|smtp_port|smtp_user|smtp_security|from_email|from_name|auto_tick)$/', $k) && strtolower(trim((string)$b[$k])) !== strtolower(trim((string)($cur0[$k] ?? '')))) $hit[] = $k;
            if ($hit) { audit_log('mail_settings_refused', 'settings', null, 'mail', null, ['changed' => implode(', ', $hit), 'reason' => 'Super Admin only']); error_response('Only a Super Admin changes the mail servers, accounts, sender address or the scheduler switch.', 403); }
        }
        $mOld = []; $mNew = []; $before = mail_cfg();   // BT92 (code audit Low): the Audit Log says what changed, before and after (never a password)
        foreach (['mail_out_enabled','mail_out_method','mail_from_email','mail_from_name','mail_smtp_host','mail_smtp_port','mail_smtp_security','mail_smtp_user','mail_auto_tick'] as $k) {
            if (array_key_exists($k, $b)) { if ((string)($before[$k] ?? '') !== (string)$b[$k]) { $mOld[$k] = (string)($before[$k] ?? ''); $mNew[$k] = (string)$b[$k]; } mail_set($db, $k, (string)$b[$k], $currentUser['id']); }
        }
        if (!empty($b['mail_smtp_pass'])) mail_set($db, 'mail_smtp_pass_enc', vault_encrypt($b['mail_smtp_pass']), $currentUser['id']);
        if (!empty($b['mail_smtp_pass'])) { $mOld['SMTP password'] = '(saved)'; $mNew['SMTP password'] = 'changed (not logged)'; }
        audit_log('mail_settings_updated', 'settings', null, 'mail', $mOld ?: null, $mNew ?: null);
        json_response(['ok' => true]);
        break;

    case 'POST':
        if ($id === 'rotate_cron_token') {   // BT14: issue a new cron token (old cron lines stop working)
            if (($currentUser['role_slug'] ?? '') !== 'super_admin') error_response('Only a Super Admin can rotate the cron token', 403);
            mail_set($db, 'mail_cron_token', bin2hex(random_bytes(24)), $currentUser['id']);
            audit_log('cron_token_rotated', 'settings', null, 'value not logged');
            json_response(['ok' => true]);
        }
        if ($id === 'sendtest') {
            $to = $currentUser['email'] ?? '';
            if (!$to) error_response('Your account has no email address', 400);
            $r = mail_send($to, itops_app_name() . ' test email', 'This is a test message from ' . itops_app_name() . ". If you received it, outbound email is working.");   // review CORE-23
            $r['to'] = $to;
            json_response($r);
        } else {
            error_response('Unknown action', 404);
        }
        break;

    default:
        error_response('Method not allowed', 405);
}
