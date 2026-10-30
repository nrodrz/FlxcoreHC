<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Email Intake admin API (super_admin only)
 * GET  /api/mail            → current mail settings (password masked)
 * PUT  /api/mail             → save settings (password encrypted via vault)
 * POST /api/mail/test        → test the IMAP connection
 * POST /api/mail/poll        → run the poller now
 */
opsh_require($currentUser, 'email_cfg', $method === 'GET' ? 'view' : 'edit');   // BT18: Settings · Email area
require_once __DIR__ . '/../lib/MailIntake.php';
$db = getDB();

if (!function_exists('mail_set')) {
    function mail_set($db, $k, $v, $uid) {
        $db->prepare('INSERT INTO opsh_settings (skey,sval,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval),updated_by=VALUES(updated_by)')
           ->execute([$k, $v, $uid]);
    }
}

switch ($method) {
    case 'GET':
        $cfg = mail_cfg();
        json_response([
            'mail_enabled'            => $cfg['mail_enabled'] ?? '0',
            'mail_in_method'          => $cfg['mail_in_method'] ?? 'imap',
            'mail_host'               => $cfg['mail_host'] ?? '',
            'mail_port'               => $cfg['mail_port'] ?? '993',
            'mail_encryption'         => $cfg['mail_encryption'] ?? 'ssl',
            'mail_username'           => $cfg['mail_username'] ?? '',
            'mail_password_set'       => !empty($cfg['mail_password_enc']) ? 1 : 0,
            'mail_folder'             => $cfg['mail_folder'] ?? 'INBOX',
            'mail_default_category'   => $cfg['mail_default_category'] ?? '',
            'mail_default_department' => $cfg['mail_default_department'] ?? '',
            'mail_default_priority'   => $cfg['mail_default_priority'] ?? 'p3_medium',
            'mail_reply_mode'         => $cfg['mail_reply_mode'] ?? 'thread',
            // BT14: the cron token runs privileged scheduled jobs (incl. scheduled offboarding) — Super Admin only.
            'mail_cron_token'         => (($currentUser['role_slug'] ?? '') === 'super_admin') ? ($cfg['mail_cron_token'] ?? '') : '',
            'mail_cron_token_set'     => !empty($cfg['mail_cron_token']) ? 1 : 0,
            // BT96: this installation's own cron line (its folder and PHP), never a fixed path — Super Admin only.
            'cron_mail_cmd'           => (($currentUser['role_slug'] ?? '') === 'super_admin') ? (function () { require_once __DIR__ . '/../lib/CronMonitor.php'; return '*/5 * * * * ' . opsh_cron_cmd('cron-mail.php'); })() : '',
            'imap_available'          => function_exists('imap_open') ? 1 : 0,
            'mail_out_enabled'        => $cfg['mail_out_enabled'] ?? '0',
            'mail_out_method'         => $cfg['mail_out_method'] ?? 'mailfn',
            'mail_from_email'         => $cfg['mail_from_email'] ?? '',
            'mail_from_name'          => $cfg['mail_from_name'] ?? 'IT Help Desk',
            'mail_smtp_host'          => $cfg['mail_smtp_host'] ?? '',
            'mail_smtp_port'          => $cfg['mail_smtp_port'] ?? '587',
            'mail_smtp_security'      => $cfg['mail_smtp_security'] ?? 'tls',
            'mail_smtp_user'          => $cfg['mail_smtp_user'] ?? '',
            'mail_smtp_pass_set'      => !empty($cfg['mail_smtp_pass_enc']) ? 1 : 0,
            'mail_ack'                => $cfg['mail_ack'] ?? '0',
            'mail_notify_reply'       => $cfg['mail_notify_reply'] ?? '0',
            'mail_notify_status'      => $cfg['mail_notify_status'] ?? '0',
            'mail_auto_tick'          => $cfg['mail_auto_tick'] ?? '1',
            'mail_notify_assign'      => $cfg['mail_notify_assign'] ?? '1',
            'mail_log'                => (function () { try { return getDB()->query("SELECT l.id,l.to_email,l.subject,l.method,l.kind,l.ticket_id,l.ok,l.error,l.created_at,u.display_name AS sent_by_name FROM opsh_mail_log l LEFT JOIN opsh_users u ON u.id=l.sent_by ORDER BY l.id DESC LIMIT 40")->fetchAll(); } catch (Throwable $e) { return []; } })(),
            'auto_tick_last'          => (function () { try { $v = getDB()->query("SELECT sval FROM opsh_settings WHERE skey='auto_tick_last'")->fetchColumn(); return $v ? json_decode($v, true) : null; } catch (Throwable $e) { return null; } })(),
        ]);
        break;

    case 'PUT':
        $b = json_decode(file_get_contents('php://input'), true) ?: [];
        if ((!empty($b['mail_password']) || !empty($b['mail_smtp_pass'])) && ($currentUser['role_slug'] ?? '') !== 'super_admin') error_response('Only a Super Admin can change the mailbox or SMTP password', 403);
        // BT87 (code audit H-5): the saved passwords follow their server — changing the server or account needs the password again
        { $stored = mail_cfg(); $isSA = ($currentUser['role_slug'] ?? '') === 'super_admin';
          foreach ([['mail_password', 'mail_password_enc', ['mail_host', 'mail_port', 'mail_encryption', 'mail_username'], 'mailbox'], ['mail_smtp_pass', 'mail_smtp_pass_enc', ['mail_smtp_host', 'mail_smtp_port', 'mail_smtp_security', 'mail_smtp_user'], 'SMTP']] as [$pk, $enc, $keys, $what]) {
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
            foreach (array_keys($b) as $k) if (preg_match('/^mail_(in_method|out_method|host|port|encryption|username|folder|smtp_host|smtp_port|smtp_user|smtp_secure|smtp_encryption|from_email|from_name|auto_tick)$/', $k) && strtolower(trim((string)$b[$k])) !== strtolower(trim((string)($cur0[$k] ?? '')))) $hit[] = $k;
            if ($hit) { audit_log('mail_settings_refused', 'settings', null, 'mail', null, ['changed' => implode(', ', $hit), 'reason' => 'Super Admin only']); error_response('Only a Super Admin changes the mail servers, accounts, sender address or the scheduler switch.', 403); }
        }
        $mOld = []; $mNew = []; $before = mail_cfg();   // BT92 (code audit Low): the Audit Log says what changed, before and after (never a password)
        foreach (['mail_enabled','mail_in_method','mail_host','mail_port','mail_encryption','mail_username','mail_folder','mail_default_category','mail_default_department','mail_default_priority','mail_reply_mode','mail_out_enabled','mail_out_method','mail_from_email','mail_from_name','mail_smtp_host','mail_smtp_port','mail_smtp_security','mail_smtp_user','mail_ack','mail_notify_reply','mail_notify_status','mail_notify_assign','mail_auto_tick'] as $k) {
            if (array_key_exists($k, $b)) { if ((string)($before[$k] ?? '') !== (string)$b[$k]) { $mOld[$k] = (string)($before[$k] ?? ''); $mNew[$k] = (string)$b[$k]; } mail_set($db, $k, (string)$b[$k], $currentUser['id']); }
        }
        if (!empty($b['mail_password'])) mail_set($db, 'mail_password_enc', vault_encrypt($b['mail_password']), $currentUser['id']);
        if (!empty($b['mail_smtp_pass'])) mail_set($db, 'mail_smtp_pass_enc', vault_encrypt($b['mail_smtp_pass']), $currentUser['id']);
        $cfg = mail_cfg();
        if (empty($cfg['mail_cron_token'])) mail_set($db, 'mail_cron_token', bin2hex(random_bytes(16)), $currentUser['id']);
        if (!empty($b['mail_password'])) { $mOld['mailbox password'] = '(saved)'; $mNew['mailbox password'] = 'changed (not logged)'; }
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
        if ($id === 'test') {
            $cfg = mail_cfg();
            $b = json_decode(file_get_contents('php://input'), true) ?: [];
            foreach (['mail_in_method','mail_host','mail_port','mail_encryption','mail_username','mail_folder'] as $k) {
                if (!empty($b[$k])) $cfg[$k] = $b[$k];
            }
            if (!empty($b['mail_password'])) $cfg['_password_plain'] = $b['mail_password'];
            // BT87 (code audit H-5): the stored password only goes to the server it was saved for — another server needs it typed again
            $stored = mail_cfg(); $moved = [];
            foreach (['mail_host', 'mail_port', 'mail_encryption', 'mail_username'] as $k) if (!empty($b[$k]) && strtolower(trim((string)$b[$k])) !== strtolower(trim((string)($stored[$k] ?? '')))) $moved[] = $k;
            if ($moved && empty($b['mail_password']) && ($cfg['mail_in_method'] ?? 'imap') !== 'graph') {
                audit_log('mail_test_refused', 'settings', null, 'mail', null, ['changed' => implode(', ', $moved), 'reason' => 'the saved password is not sent to another server']);
                error_response('To test a different server or account, type its password: the saved password is only used with the server it was saved for.', 400);
            }
            json_response(mail_intake_test($cfg));
        } elseif ($id === 'poll') {
            json_response(mail_intake_poll());
        } elseif ($id === 'sendtest') {
            $to = $currentUser['email'] ?? '';
            if (!$to) error_response('Your account has no email address', 400);
            $r = mail_send($to, opsh_app_name() . ' test email', 'This is a test message from ' . opsh_app_name() . ". If you received it, outbound email is working.\n\n— IT Help Desk");   // review CORE-23
            $r['to'] = $to;
            json_response($r);
        } else {
            error_response('Unknown action', 404);
        }
        break;

    default:
        error_response('Method not allowed', 405);
}
