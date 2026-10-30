<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT17 ticket routing: an OWNER and a BACKUP for every new ticket, whatever channel it came from
 * (in-app, email-to-ticket, public ticket page, intake forms).
 *
 * Rules
 *   - Owner / backup come from the ticket's category; a category without its own inherits its GROUP's; then the
 *     default routing (Ticket Categories → Default routing). An explicit choice made on the ticket always wins.
 *   - Inactive users are skipped: an inactive owner is replaced by the backup (who then has no backup). BT52: so is
 *     anyone whose role cannot edit tickets; each skip is written to the Event Log and to the ticket_routed audit entry.
 *   - The backup receives everything the owner receives (assignment, requester replies, internal notes, SLA alerts)
 *     and can open the ticket even without "All records".
 *   - A ticket that still has no owner is announced to the "unassigned alert" list (default: every Super Admin),
 *     so a new ticket is never silent.
 * Everything here is best-effort: a notification failure never blocks ticket creation.
 */

if (is_file(__DIR__ . '/EventLog.php')) require_once __DIR__ . '/EventLog.php';   // BT52: routing problems are logged

if (!function_exists('itops_tr_settings')) {

    function itops_tr_settings(PDO $db): array {
        $v = null;
        try { $st = $db->prepare("SELECT sval FROM itops_settings WHERE skey='ticket_routing'"); $st->execute(); $v = $st->fetchColumn(); } catch (Throwable $e) {}
        $j = $v ? (json_decode((string)$v, true) ?: []) : [];
        return ['default_owner' => !empty($j['default_owner']) ? (int)$j['default_owner'] : null,
                'default_backup' => !empty($j['default_backup']) ? (int)$j['default_backup'] : null,
                'alert_ids' => array_values(array_unique(array_filter(array_map('intval', (array)($j['alert_ids'] ?? []))))),
                'alert_always' => !empty($j['alert_always'])];
    }

    function itops_tr_active(PDO $db, ?int $uid): bool {
        if (!$uid) return false;
        $st = $db->prepare("SELECT 1 FROM itops_users WHERE id=? AND status='active'"); $st->execute([$uid]);
        return (bool)$st->fetchColumn();
    }

    /**
     * BT52: whether a person can work a ticket — an active account whose role may edit tickets (Super Admin always).
     * Routing used to hand tickets to anyone active, so a ticket could go to someone who could not open, answer or
     * close it. Same rule as itops_can($user, 'tickets', 'edit'), written out here so the crons (email intake) can use it.
     */
    function itops_tr_can_work(PDO $db, ?int $uid): bool {
        if (!$uid) return false;
        try {
            $st = $db->prepare("SELECT r.slug, r.permissions FROM itops_users u LEFT JOIN itops_roles r ON r.id=u.role_id WHERE u.id=? AND u.status='active'");
            $st->execute([$uid]); $r = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return itops_tr_active($db, $uid); }
        if (!$r) return false;
        if (function_exists('itops_can')) return itops_can(['role_slug' => (string)$r['slug'], 'role_perms' => $r['permissions']], 'tickets', 'edit');
        if ($r['slug'] === 'super_admin') return true;
        $p = json_decode((string)$r['permissions'], true); if (!is_array($p)) return false;
        if (!empty($p['*'])) return true;
        $v = $p['tickets'] ?? null;
        if (is_array($v)) return in_array('edit', $v, true);
        return in_array($v, ['crud', 'create_edit', 'dept_manage', 'own', 'full', true], true);
    }

    /** Why someone cannot work tickets ('' when they can) — for the routing check and the refusals. */
    function itops_tr_why_not(PDO $db, ?int $uid): string {
        if (!$uid) return '';
        if (!itops_tr_active($db, $uid)) return 'not an active account';
        return itops_tr_can_work($db, $uid) ? '' : 'their role cannot edit tickets';
    }

    /** [owner, backup, source, skipped] for a category. Owner and backup are found separately: the category's own person
     *  first, then its group's, then the default routing. BT109: a person who is inactive or whose role cannot work
     *  tickets is skipped at that level and the next level is used (before, the ticket was left without one). */
    function itops_tr_route(PDO $db, ?int $categoryId): array {
        $owner = null; $backup = null; $src = ''; $skipped = [];
        $take = function ($uid, string $as, string $from) use ($db, &$skipped, &$owner, &$backup, &$src) {
            if (!$uid) return;
            $w = itops_tr_why_not($db, (int)$uid);
            if ($w !== '') { $skipped[] = ['user_id' => (int)$uid, 'as' => $as, 'why' => $w, 'level' => $from]; return; }
            if ($as === 'owner') $owner = (int)$uid; else $backup = (int)$uid;
            $src = $src ?: $from;
        };
        $cid = $categoryId; $hops = 0;
        while ($cid && $hops++ < 3) {
            try { $st = $db->prepare('SELECT id, name, parent_id, default_assignee_id, backup_assignee_id FROM itops_ticket_categories WHERE id=?'); $st->execute([$cid]); $c = $st->fetch(PDO::FETCH_ASSOC); }
            catch (Throwable $e) { $c = null; }
            if (!$c) break;
            $lvl = (empty($c['parent_id']) ? 'group ' : 'category ') . $c['name'];
            if (!$owner) $take($c['default_assignee_id'] ?? null, 'owner', $lvl);
            if (!$backup) $take($c['backup_assignee_id'] ?? null, 'backup', $lvl);
            if ($owner && $backup) break;
            $cid = !empty($c['parent_id']) ? (int)$c['parent_id'] : null;
        }
        $d = itops_tr_settings($db);
        if (!$owner) $take($d['default_owner'] ?? null, 'owner', 'default routing');
        if (!$backup) $take($d['default_backup'] ?? null, 'backup', 'default routing');
        if (!$owner && $backup) { $owner = $backup; $backup = null; }
        if ($backup === $owner) $backup = null;
        return [$owner, $backup, $src, $skipped];
    }

    function itops_tr_user(PDO $db, int $uid): ?array {
        $st = $db->prepare("SELECT id, email, display_name FROM itops_users WHERE id=? AND status='active'"); $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC); return $u ?: null;
    }

    function itops_tr_bell(PDO $db, int $uid, string $title, string $body, string $type): void {
        try { $db->prepare('INSERT INTO itops_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)')->execute([$uid, $title, mb_substr($body, 0, 250), $type, 'tickets']); } catch (Throwable $e) {}
    }

    function itops_tr_email(string $key, string $to, array $vars, array $opt): void {
        if ($to === '') return;
        try {
            if (!function_exists('itops_email')) require_once __DIR__ . '/EmailTemplates.php';
            if (!function_exists('itops_tpl_l10n') && is_file(__DIR__ . '/EmailTemplates.php')) require_once __DIR__ . '/EmailTemplates.php';
            itops_email($key, $to, $vars, $opt);
        } catch (Throwable $e) {}
    }

    /** Priority label for email templates — per-language array from itops_tpl_l10n(), or the raw value. */
    function itops_tr_prio(string $p) {
        if (!function_exists('itops_tpl_l10n')) { try { require_once __DIR__ . '/EmailTemplates.php'; } catch (Throwable $e) {} }
        return function_exists('itops_tpl_l10n') ? itops_tpl_l10n('priority', $p) : $p;
    }

    /** Tell someone they now own a ticket (bell + "ticket_assigned" email). */
    function itops_tr_notify_owner(PDO $db, array $t, int $uid, string $by): void {
        $u = itops_tr_user($db, $uid); if (!$u) return;
        itops_tr_bell($db, $uid, 'Ticket assigned to you', ($t['ticket_number'] ?? '') . ': ' . ($t['subject'] ?? ''), 'assignment');
        itops_tr_email('ticket_assigned', (string)$u['email'], ['ticket_number' => (string)$t['ticket_number'], 'subject' => (string)($t['subject'] ?? ''),
            'assigned_by' => $by, 'priority' => itops_tr_prio((string)($t['priority'] ?? 'p3_medium')), 'recipient_name' => (string)$u['display_name']],
            ['user_id' => $uid, 'ticket_id' => (int)$t['id']]);
    }

    /** Tell someone they are the backup on a ticket (bell + "ticket_backup" email). */
    function itops_tr_notify_backup(PDO $db, array $t, int $uid, string $by): void {
        $u = itops_tr_user($db, $uid); if (!$u) return;
        $owner = '';
        if (!empty($t['assignee_id'])) { $o = itops_tr_user($db, (int)$t['assignee_id']); $owner = $o ? (string)$o['display_name'] : ''; }
        itops_tr_bell($db, $uid, 'You are the backup on ' . ($t['ticket_number'] ?? ''), ($t['subject'] ?? '') . ($owner !== '' ? ' — owner: ' . $owner : ''), 'assignment');
        itops_tr_email('ticket_backup', (string)$u['email'], ['ticket_number' => (string)$t['ticket_number'], 'subject' => (string)($t['subject'] ?? ''),
            'assigned_by' => $by, 'owner_name' => $owner !== '' ? $owner : '—', 'priority' => itops_tr_prio((string)($t['priority'] ?? 'p3_medium')),
            'recipient_name' => (string)$u['display_name']], ['user_id' => $uid, 'ticket_id' => (int)$t['id']]);
    }

    /** Who is alerted about a new ticket that has no owner: the configured list, else every active Super Admin. */
    function itops_tr_alert_list(PDO $db): array {
        $s = itops_tr_settings($db); $ids = $s['alert_ids'];
        if (!$ids) { try { $ids = array_map('intval', $db->query("SELECT u.id FROM itops_users u JOIN itops_roles r ON r.id=u.role_id WHERE r.slug='super_admin' AND u.status='active'")->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) { $ids = []; } }
        return $ids;
    }

    /**
     * Run once for every NEW ticket, from every channel. Fills owner/backup from the routing rules when the ticket has
     * none, then notifies: owner, backup, and — when nobody owns it — the unassigned-alert list.
     * $actorId: the signed-in user who created it (never notified about their own action); null for email/public/forms.
     * Returns ['assignee_id'=>…, 'backup_id'=>…, 'routed'=>bool, 'alerted'=>n].
     */
    function itops_tr_on_created(PDO $db, int $ticketId, ?int $actorId, string $via): array {
        $out = ['assignee_id' => null, 'backup_id' => null, 'routed' => false, 'alerted' => 0];
        try {
            $st = $db->prepare('SELECT t.*, r.display_name AS requester_name, r.email AS requester_email FROM itops_tickets t LEFT JOIN itops_users r ON r.id=t.requester_id WHERE t.id=?');
            $st->execute([$ticketId]); $t = $st->fetch(PDO::FETCH_ASSOC); if (!$t) return $out;
            [$ro, $rb, $src, $skipped] = itops_tr_route($db, !empty($t['category_id']) ? (int)$t['category_id'] : null);
            if ($skipped && function_exists('itops_log')) {
                foreach ($skipped as $k) { try { itops_log('warning', 'tickets', 'Routing skipped user #' . $k['user_id'] . ' as ' . $k['as'] . ' of ' . $t['ticket_number'] . ': ' . $k['why'] . '. Fix the category routing (Tickets → Categories).', ['ticket_id' => $ticketId]); } catch (Throwable $e) {} }
            }
            $owner = !empty($t['assignee_id']) ? (int)$t['assignee_id'] : $ro;
            $backup = !empty($t['backup_id']) ? (int)$t['backup_id'] : $rb;
            if ($backup === $owner) $backup = ($rb && $rb !== $owner) ? $rb : null;
            $changed = ((int)($t['assignee_id'] ?? 0) !== (int)$owner) || ((int)($t['backup_id'] ?? 0) !== (int)$backup);
            if ($changed) {
                $db->prepare('UPDATE itops_tickets SET assignee_id=?, backup_id=? WHERE id=?')->execute([$owner, $backup, $ticketId]);
                $out['routed'] = true;
                if (function_exists('audit_log')) { try { audit_log('ticket_routed', 'ticket', $ticketId, (string)$t['ticket_number'], null, ['owner' => $owner, 'backup' => $backup, 'rule' => $src, 'via' => $via] + ($skipped ? ['skipped' => $skipped] : [])); } catch (Throwable $e) {} }
            }
            $t['assignee_id'] = $owner; $t['backup_id'] = $backup;
            $by = $actorId ? '' : 'automatic routing';
            if ($actorId) { $a = $db->prepare('SELECT display_name FROM itops_users WHERE id=?'); $a->execute([$actorId]); $by = (string)($a->fetchColumn() ?: ''); }
            if ($owner && $owner !== $actorId) itops_tr_notify_owner($db, $t, $owner, $by !== '' ? $by : 'automatic routing');
            if ($backup && $backup !== $actorId) itops_tr_notify_backup($db, $t, $backup, $by !== '' ? $by : 'automatic routing');
            $s = itops_tr_settings($db);
            if (!$owner || $s['alert_always']) {
                foreach (itops_tr_alert_list($db) as $uid) {
                    if ($uid === $actorId || $uid === $owner || $uid === $backup) continue;
                    $u = itops_tr_user($db, $uid); if (!$u) continue;
                    itops_tr_bell($db, $uid, $owner ? 'New ticket ' . $t['ticket_number'] : 'New ticket — nobody assigned', $t['ticket_number'] . ': ' . $t['subject'], $owner ? 'info' : 'warning');
                    itops_tr_email('ticket_new_unassigned', (string)$u['email'], ['ticket_number' => (string)$t['ticket_number'], 'subject' => (string)$t['subject'],
                        'requester' => (string)(!empty($t['contact_email']) ? trim(($t['contact_name'] ?? '') . ' <' . $t['contact_email'] . '>') : ($t['requester_name'] ?? '')), 'channel' => itops_tr_channel($via),
                        'priority' => itops_tr_prio((string)($t['priority'] ?? 'p3_medium')), 'owner_status' => $owner ? 'Assigned automatically' : 'Nobody is assigned yet',
                        'recipient_name' => (string)$u['display_name']], ['user_id' => $uid, 'ticket_id' => $ticketId]);
                    $out['alerted']++;
                }
            }
            $out['assignee_id'] = $owner; $out['backup_id'] = $backup;
        } catch (Throwable $e) {   // best effort — never block ticket creation, but say so (BT52: it used to be silent)
            $out['error'] = $e->getMessage();
            try {
                if (!function_exists('itops_log') && is_file(__DIR__ . '/EventLog.php')) require_once __DIR__ . '/EventLog.php';
                if (function_exists('itops_log')) itops_log('error', 'tickets', 'Routing failed for ticket #' . $ticketId . ' (' . $via . '): ' . $e->getMessage(), ['ticket_id' => $ticketId]);
            } catch (Throwable $e2) {}
        }
        return $out;
    }

    function itops_tr_channel(string $via): string {
        return ['app' => (function_exists('itops_app_name') ? itops_app_name() : 'ITOps'), 'email' => 'Email', 'public' => 'Public ticket page', 'form' => 'Intake form'][$via] ?? $via;
    }

    /** Active owner + backup ids of a ticket (for "everyone who works this ticket" notifications). */
    function itops_tr_workers(PDO $db, int $ticketId): array {
        try {
            $st = $db->prepare("SELECT t.assignee_id, t.backup_id FROM itops_tickets t WHERE t.id=?"); $st->execute([$ticketId]); $r = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $st = $db->prepare("SELECT t.assignee_id, NULL AS backup_id FROM itops_tickets t WHERE t.id=?"); $st->execute([$ticketId]); $r = $st->fetch(PDO::FETCH_ASSOC);
        }
        if (!$r) return [];
        return array_values(array_unique(array_filter([(int)$r['assignee_id'], (int)$r['backup_id']], fn($x) => $x > 0 && itops_tr_active($db, $x))));
    }
}
