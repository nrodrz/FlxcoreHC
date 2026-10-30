<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Notifications Route
 * GET  /api/notifications            → current user's notifications + unread count
 * PUT  /api/notifications/:id         → mark one read
 * POST /api/notifications/read-all    → mark all read
 * DELETE /api/notifications/:id       → remove one
 */
$db = getDB();
$uid = (int)$currentUser['id'];

switch ($method) {
    case 'GET':
        if ($id === 'groups') {   // BT94: Microsoft 365 groups for a ticket notice (the help desk)
            if (($currentUser['role_slug'] ?? '') !== 'super_admin' && !opsh_can($currentUser, 'tickets', 'all')) error_response('Writing to a Microsoft 365 group is for the help desk (Tickets · All).', 403);
            require_once __DIR__ . '/../lib/Announcements.php';
            try { json_response(['groups' => opsh_ann_group_search((string)($_GET['q'] ?? ''))]); }
            catch (Throwable $e) { json_response(['groups' => [], 'error' => $e->getMessage(), 'needs' => 'Microsoft permission GroupMember.Read.All (or Group.Read.All) on the app registration, with admin consent.']); }
        }
        // BT101: notifications that open a module which is not part of this installation are not shown (they are kept)
        require_once __DIR__ . '/../lib/Modules.php';
        $st = $db->prepare("SELECT id,title,body,type,link,is_read,created_at
                            FROM opsh_notifications WHERE user_id=?
                            ORDER BY is_read ASC, created_at DESC LIMIT 200");
        $st->execute([$uid]);
        $rows = array_slice(array_values(array_filter($st->fetchAll(), fn($r) => opsh_link_enabled($r['link'] ?? null))), 0, 50);
        $uc = $db->prepare("SELECT link FROM opsh_notifications WHERE user_id=? AND is_read=0");
        $uc->execute([$uid]);
        json_response(['data' => $rows, 'unread' => count(array_filter($uc->fetchAll(PDO::FETCH_COLUMN), fn($l) => opsh_link_enabled($l)))]);
        break;

    case 'POST':
        if ($id === 'read-all') {
            $db->prepare("UPDATE opsh_notifications SET is_read=1 WHERE user_id=?")->execute([$uid]);
            json_response(['ok' => true]);
            break;
        }
        // Send a notification to one or more users (agents/admins)
        if (!opsh_can($currentUser, 'tickets', 'edit') && (($currentUser['role_slug'] ?? '') !== 'super_admin')) error_response('Insufficient permissions', 403);
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $ids = $body['user_ids'] ?? (isset($body['user_id']) ? [$body['user_id']] : []);
        if (!is_array($ids)) $ids = [$ids];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $title = mb_substr(trim((string)($body['title'] ?? '')), 0, 200);
        // BT94: from a ticket, the help desk can also write to a Microsoft 365 group or to email addresses — a one-off email
        // about the ticket (template "Notice about a ticket"); it gives no access to the ticket.
        $extG = (array)($body['groups'] ?? []); $extE = $body['emails'] ?? '';
        $hasExt = $extG || trim(is_array($extE) ? implode('', array_map('strval', $extE)) : (string)$extE) !== '';
        $extOut = null;
        if ($hasExt) {
            require_once __DIR__ . '/../lib/Announcements.php';
            $tid = (int)($body['ticket_id'] ?? 0);
            $tq = $db->prepare('SELECT id, ticket_number, subject FROM opsh_tickets WHERE id=?'); $tq->execute([$tid]); $tk = $tq->fetch(PDO::FETCH_ASSOC);
            $xRefuse = function (string $why) use ($title, $tk) { audit_log('notification_refused', 'notification', $tk ? (int)$tk['id'] : null, mb_substr($title, 0, 200), null, ['to' => 'Microsoft 365 group / email addresses', 'reason' => $why]); error_response($why, 403); };
            if (!$tk) error_response('A notice to a group or to email addresses is sent from a ticket.', 400);
            if (($currentUser['role_slug'] ?? '') !== 'super_admin' && !opsh_can($currentUser, 'tickets', 'all')) $xRefuse('Writing to a Microsoft 365 group or to email addresses is for the help desk (Tickets · All).');
            if ($title === '') error_response('Recipients and title are required', 400);
            $msg = mb_substr(trim((string)($body['body'] ?? '')), 0, 4000); if ($msg === '') error_response('Write the message: the email is all they will get.', 400);
            try { $x = opsh_ann_ext_clean(['audience_groups' => $extG, 'audience_emails' => $extE]); } catch (InvalidArgumentException $e) { error_response($e->getMessage(), 400); }
            $res = opsh_ann_ext_resolve_list($x);
            if (!$res['people']) error_response($res['errors'] ? 'The group could not be read: ' . implode(' · ', $res['errors']) : 'Nobody to write to: the group has no members with an email address.', 400);
            if (count($res['people']) > 300) error_response('That is ' . count($res['people']) . ' people; a notice goes to up to 300. Use an announcement for more.', 400);
            if (!function_exists('opsh_email')) require_once __DIR__ . '/../lib/EmailTemplates.php';
            if (opsh_tpl_setting('mail_out_enabled', '0') !== '1' || !opsh_tpl_enabled('ticket_notice')) error_response('Outbound email is off, or the email "Notice about a ticket" is switched off (Settings → Email).', 409);
            $isSA2 = ($currentUser['role_slug'] ?? '') === 'super_admin';
            if (!$isSA2) { $rq = $db->prepare("SELECT COUNT(*) FROM opsh_audit_log WHERE user_id=? AND action IN ('notification_sent','ticket_notice_sent') AND created_at > NOW() - INTERVAL 1 HOUR"); $rq->execute([$uid]); if ((int)$rq->fetchColumn() >= 20) error_response('You sent 20 notifications in the last hour. Try again later.', 429); }
            $sentX = 0; $failX = 0;
            foreach ($res['people'] as $em => $pp) {
                try { $r = opsh_email('ticket_notice', $em, ['title' => $title, 'message' => $msg, 'ticket_number' => $tk['ticket_number'], 'subject' => $tk['subject'],
                                                           'sender_name' => $currentUser['display_name'] ?? '', 'recipient_name' => (string)$pp['name']]); }
                catch (Throwable $e) { $r = ['ok' => false]; }
                !empty($r['ok']) ? $sentX++ : $failX++;
            }
            $gn = implode(', ', array_map(fn($g) => $g['name'] ?: $g['id'], $x['groups']));
            $db->prepare('INSERT INTO opsh_ticket_comments (ticket_id,user_id,content,is_internal) VALUES (?,?,?,1)')
               ->execute([(int)$tk['id'], $uid, 'Notice emailed to ' . count($res['people']) . ' ' . (count($res['people']) === 1 ? 'person' : 'people') . ($gn !== '' ? ' (groups: ' . $gn . ')' : '') . ($x['emails'] ? ' (' . count($x['emails']) . ' typed address' . (count($x['emails']) === 1 ? '' : 'es') . ')' : '') . ': ' . $title . "\n\n" . $msg]);
            audit_log('ticket_notice_sent', 'ticket', (int)$tk['id'], (string)$tk['ticket_number'], null, ['Title' => $title, 'Microsoft 365 groups' => $gn, 'Typed addresses' => implode(', ', $x['emails']), 'People' => count($res['people']), 'Sent' => $sentX, 'Not sent' => $failX] + ($res['errors'] ? ['Group errors' => implode(' · ', $res['errors'])] : []));
            $extOut = ['people' => count($res['people']), 'sent' => $sentX, 'failed' => $failX, 'errors' => $res['errors']];
            if (!$ids) json_response(['ok' => true, 'sent' => 0, 'external' => $extOut], 201);
        }
        if (!$ids || $title === '') error_response('Recipients and title are required', 400);
        // BT86 (code audit M-19): the help desk (Tickets · All) and Super Admins message anyone; others only the people on a
        // ticket they can open (its requester, owner and backup). Up to 50 people at once, 20 messages an hour.
        $isSA = ($currentUser['role_slug'] ?? '') === 'super_admin';
        if (count($ids) > 50) error_response('Up to 50 people at once.', 400);
        $nRefuse = function (string $why) use ($title, $ids) { audit_log('notification_refused', 'notification', null, mb_substr($title, 0, 200), null, ['to' => implode(',', $ids), 'reason' => $why]); error_response($why, 403); };
        if (!$isSA && !opsh_can($currentUser, 'tickets', 'all')) {
            $tid = (int)($body['ticket_id'] ?? 0);
            $tq = $db->prepare('SELECT requester_id, assignee_id, backup_id FROM opsh_tickets WHERE id=? AND (assignee_id=? OR requester_id=? OR backup_id=?)'); $tq->execute([$tid, $uid, $uid, $uid]); $tr = $tq->fetch(PDO::FETCH_ASSOC);
            if (!$tr) $nRefuse('You send a notification from a ticket you work on, to the people on it. The help desk (Tickets · All) messages anyone.');
            $okIds = array_map('intval', array_filter([$tr['requester_id'], $tr['assignee_id'], $tr['backup_id']]));
            if (array_diff($ids, $okIds)) $nRefuse('From this ticket you notify its requester, owner and backup only.');
        }
        if (!$isSA) { $rq = $db->prepare("SELECT COUNT(*) FROM opsh_audit_log WHERE user_id=? AND action='notification_sent' AND created_at > NOW() - INTERVAL 1 HOUR"); $rq->execute([$uid]); if ((int)$rq->fetchColumn() >= 20) error_response('You sent 20 notifications in the last hour. Try again later.', 429); }
        $act = $db->query('SELECT id FROM opsh_users WHERE status=\'active\' AND id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_COLUMN); $ids = array_values(array_map('intval', $act));
        if (!$ids) error_response('Choose active people.', 400);
        $bodytext = mb_substr(trim((string)($body['body'] ?? '')), 0, 2000);
        $link = trim((string)($body['link'] ?? '')); if (!preg_match('~^[a-z0-9_/-]{0,60}$~', $link)) $link = '';   // a page of the app
        $type = 'message';
        $emailWanted = !empty($body['email']);
        $ins = $db->prepare("INSERT INTO opsh_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)");
        $sent = 0;
        foreach ($ids as $rid) {
            $ins->execute([$rid, $title, $bodytext, $type, $link]); $sent++;
            if ($emailWanted) {
                try {
                    require_once __DIR__ . '/../lib/MailIntake.php';
                    $u = $db->prepare("SELECT email, display_name FROM opsh_users WHERE id=? AND status='active'"); $u->execute([$rid]); $ur = $u->fetch();
                    if ($ur && !empty($ur['email'])) opsh_email('admin_message', $ur['email'], ['title' => $title, 'message' => $bodytext ?: $title,
                        'sender_name' => $currentUser['display_name'] ?? '', 'recipient_name' => $ur['display_name'] ?? ''], ['user_id' => (int)$rid]);
                } catch (\Throwable $e) {}
            }
        }
        audit_log('notification_sent', 'notification', null, $title.' -> users '.implode(',', $ids));
        json_response(['ok' => true, 'sent' => $sent, 'external' => $extOut], 201);
        break;

    case 'PUT':
        if ($id === 'read-all') {
            $db->prepare("UPDATE opsh_notifications SET is_read=1 WHERE user_id=?")->execute([$uid]);
            json_response(['ok' => true]);
            break;
        }
        if (!$id) error_response('ID required');
        $db->prepare("UPDATE opsh_notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([(int)$id, $uid]);
        json_response(['ok' => true]);
        break;

    case 'DELETE':
        if (!$id) error_response('ID required');
        $db->prepare("DELETE FROM opsh_notifications WHERE id=? AND user_id=?")->execute([(int)$id, $uid]);
        json_response(['ok' => true]);
        break;

    default:
        error_response('Method not allowed', 405);
}
