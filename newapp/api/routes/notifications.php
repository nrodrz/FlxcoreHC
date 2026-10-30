<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — Notifications Route
 * GET  /api/notifications            → current user's notifications + unread count
 * PUT  /api/notifications/:id         → mark one read
 * POST /api/notifications/read-all    → mark all read
 * DELETE /api/notifications/:id       → remove one
 */
$db = getDB();
$uid = (int)$currentUser['id'];

switch ($method) {
    case 'GET':
        // BT101: notifications that open a module which is not part of this installation are not shown (they are kept)
        require_once __DIR__ . '/../lib/Modules.php';
        $st = $db->prepare("SELECT id,title,body,type,link,is_read,created_at
                            FROM itops_notifications WHERE user_id=?
                            ORDER BY is_read ASC, created_at DESC LIMIT 200");
        $st->execute([$uid]);
        $rows = array_slice(array_values(array_filter($st->fetchAll(), fn($r) => itops_link_enabled($r['link'] ?? null))), 0, 50);
        $uc = $db->prepare("SELECT link FROM itops_notifications WHERE user_id=? AND is_read=0");
        $uc->execute([$uid]);
        json_response(['data' => $rows, 'unread' => count(array_filter($uc->fetchAll(PDO::FETCH_COLUMN), fn($l) => itops_link_enabled($l)))]);
        break;

    case 'POST':
        if ($id === 'read-all') {
            $db->prepare("UPDATE itops_notifications SET is_read=1 WHERE user_id=?")->execute([$uid]);
            json_response(['ok' => true]);
            break;
        }
        // Send a notification to one or more users — 0001-A004: Super Admin (the reference app sent them from tickets)
        if (($currentUser['role_slug'] ?? '') !== 'super_admin') error_response('Insufficient permissions', 403);
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $ids = $body['user_ids'] ?? (isset($body['user_id']) ? [$body['user_id']] : []);
        if (!is_array($ids)) $ids = [$ids];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $title = mb_substr(trim((string)($body['title'] ?? '')), 0, 200);
        if (!$ids || $title === '') error_response('Recipients and title are required', 400);
        if (count($ids) > 50) error_response('Up to 50 people at once.', 400);
        $act = $db->query('SELECT id FROM itops_users WHERE status=\'active\' AND id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_COLUMN); $ids = array_values(array_map('intval', $act));
        if (!$ids) error_response('Choose active people.', 400);
        $bodytext = mb_substr(trim((string)($body['body'] ?? '')), 0, 2000);
        $link = trim((string)($body['link'] ?? '')); if (!preg_match('~^[a-z0-9_/-]{0,60}$~', $link)) $link = '';   // a page of the app
        $type = 'message';
        $emailWanted = !empty($body['email']);
        $ins = $db->prepare("INSERT INTO itops_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)");
        $sent = 0;
        foreach ($ids as $rid) {
            $ins->execute([$rid, $title, $bodytext, $type, $link]); $sent++;
            if ($emailWanted) {
                try {
                    require_once __DIR__ . '/../lib/Mail.php';
                    $u = $db->prepare("SELECT email, display_name FROM itops_users WHERE id=? AND status='active'"); $u->execute([$rid]); $ur = $u->fetch();
                    if ($ur && !empty($ur['email'])) itops_email('admin_message', $ur['email'], ['title' => $title, 'message' => $bodytext ?: $title,
                        'sender_name' => $currentUser['display_name'] ?? '', 'recipient_name' => $ur['display_name'] ?? ''], ['user_id' => (int)$rid]);
                } catch (\Throwable $e) {}
            }
        }
        audit_log('notification_sent', 'notification', null, $title.' -> users '.implode(',', $ids));
        json_response(['ok' => true, 'sent' => $sent], 201);
        break;

    case 'PUT':
        if ($id === 'read-all') {
            $db->prepare("UPDATE itops_notifications SET is_read=1 WHERE user_id=?")->execute([$uid]);
            json_response(['ok' => true]);
            break;
        }
        if (!$id) error_response('ID required');
        $db->prepare("UPDATE itops_notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([(int)$id, $uid]);
        json_response(['ok' => true]);
        break;

    case 'DELETE':
        if (!$id) error_response('ID required');
        $db->prepare("DELETE FROM itops_notifications WHERE id=? AND user_id=?")->execute([(int)$id, $uid]);
        json_response(['ok' => true]);
        break;

    default:
        error_response('Method not allowed', 405);
}
