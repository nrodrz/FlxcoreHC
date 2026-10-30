<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * BT94 — Related tickets: several tickets about the same situation (an outage, a broken printer, a bad update)
 * linked as one group, so every requester hears the same thing at the same time.
 *
 *   opsh_ticket_related_groups   id, title, created_by, created_at
 *   opsh_tickets.related_group_id
 *
 * From any ticket in the group, someone with Tickets · Edit can link more tickets, unlink one, send one public reply to
 * every linked ticket (each requester gets the usual reply email) and resolve every linked ticket with one note (each
 * requester gets the usual Resolved email). Replies and resolutions only touch the tickets the person works on: Tickets ·
 * All, or being the ticket's owner or backup. A major incident is never resolved from here (it has its own steps).
 * Different from a major incident: no banner, no lead role, any agent can use it, and it can link tickets of any priority.
 */
require_once __DIR__ . '/ServiceMgmt.php';

if (!function_exists('opsh_rel_ready')) {

    function opsh_rel_ready(PDO $db): bool {
        static $ok = null;
        if ($ok === null) { try { $db->query('SELECT related_group_id FROM opsh_tickets LIMIT 0'); $db->query('SELECT 1 FROM opsh_ticket_related_groups LIMIT 0'); $ok = true; } catch (Throwable $e) { $ok = false; } }
        return $ok;
    }

    /** The group of a ticket: ['id','title','created_by_name','created_at','tickets'=>[rows]] or null. */
    function opsh_rel_group(PDO $db, int $ticketId): ?array {
        $q = $db->prepare('SELECT related_group_id FROM opsh_tickets WHERE id=?'); $q->execute([$ticketId]); $gid = (int)$q->fetchColumn();
        if (!$gid) return null;
        $g = $db->prepare('SELECT g.*, u.display_name AS created_by_name FROM opsh_ticket_related_groups g LEFT JOIN opsh_users u ON u.id=g.created_by WHERE g.id=?'); $g->execute([$gid]); $gr = $g->fetch(PDO::FETCH_ASSOC);
        if (!$gr) return null;
        $t = $db->prepare('SELECT t.id, t.ticket_number, t.subject, t.status, t.priority, t.requester_id, t.assignee_id, t.backup_id, t.contact_email, t.contact_name, t.major, t.case_id,
                                  COALESCE(t.contact_name, rq.display_name) AS requester_name, a.display_name AS assignee_name
                           FROM opsh_tickets t LEFT JOIN opsh_users rq ON rq.id=t.requester_id LEFT JOIN opsh_users a ON a.id=t.assignee_id
                           WHERE t.related_group_id=? AND t.merged_into_id IS NULL ORDER BY t.id');
        $t->execute([$gid]);
        $gr['tickets'] = $t->fetchAll(PDO::FETCH_ASSOC);
        return $gr;
    }

    /**
     * Link $otherIds to $ticketId's group (made when the ticket has none). A ticket already in another group brings that
     * whole group along (the two groups become one). Returns the numbers newly linked.
     */
    function opsh_rel_link(PDO $db, array $tk, array $otherIds, string $title, array $by): array {
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT id, ticket_number, related_group_id FROM opsh_tickets WHERE id=? FOR UPDATE');
            $q->execute([(int)$tk['id']]); $me = $q->fetch(PDO::FETCH_ASSOC);
            $gid = (int)($me['related_group_id'] ?? 0);
            if (!$gid) {
                $db->prepare('INSERT INTO opsh_ticket_related_groups (title, created_by, created_at) VALUES (?,?,NOW())')->execute([mb_substr($title !== '' ? $title : (string)$tk['subject'], 0, 200), (int)$by['id']]);
                $gid = (int)$db->lastInsertId();
                $db->prepare('UPDATE opsh_tickets SET related_group_id=? WHERE id=?')->execute([$gid, (int)$tk['id']]);
            } elseif ($title !== '') {
                $db->prepare('UPDATE opsh_ticket_related_groups SET title=? WHERE id=?')->execute([mb_substr($title, 0, 200), $gid]);
            }
            $added = [];
            foreach ($otherIds as $oid) {
                $q->execute([(int)$oid]); $o = $q->fetch(PDO::FETCH_ASSOC);
                if (!$o || (int)$o['related_group_id'] === $gid) continue;
                $old = (int)$o['related_group_id'];
                if ($old) {   // two groups become one
                    // Review DESK-25: only when the person can open every ticket of the other group (Tickets · All, or theirs)
                    if (!opsh_can($by, 'tickets', 'all')) {
                        $vq = $db->prepare('SELECT COUNT(*) FROM opsh_tickets WHERE related_group_id=? AND NOT (assignee_id <=> ? OR requester_id <=> ? OR backup_id <=> ?)'); $u0 = (int)$by['id']; $vq->execute([$old, $u0, $u0, $u0]);
                        if ((int)$vq->fetchColumn() > 0) throw new InvalidArgumentException($o['ticket_number'] . ' is in a group with tickets you cannot open, so the two groups cannot be joined. Ask the help desk.');
                    }
                    $m = $db->prepare('SELECT ticket_number FROM opsh_tickets WHERE related_group_id=?'); $m->execute([$old]);
                    $added = array_merge($added, $m->fetchAll(PDO::FETCH_COLUMN));
                    $db->prepare('UPDATE opsh_tickets SET related_group_id=? WHERE related_group_id=?')->execute([$gid, $old]);
                    $db->prepare('DELETE FROM opsh_ticket_related_groups WHERE id=?')->execute([$old]);
                } else {
                    $db->prepare('UPDATE opsh_tickets SET related_group_id=? WHERE id=?')->execute([$gid, (int)$oid]);
                    $added[] = $o['ticket_number'];
                }
            }
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        $added = array_values(array_unique($added));
        if ($added) {
            $note = $db->prepare('INSERT INTO opsh_ticket_comments (ticket_id,user_id,content,is_internal) VALUES (?,?,?,1)');
            foreach (opsh_rel_group($db, (int)$tk['id'])['tickets'] ?? [] as $r)
                $note->execute([(int)$r['id'], (int)$by['id'], 'Related tickets: ' . implode(', ', $added) . ' linked to this group by ' . ($by['display_name'] ?? '') . '.']);
            if (function_exists('audit_log')) { try { audit_log('ticket_related_linked', 'ticket', (int)$tk['id'], (string)$tk['ticket_number'], null, ['linked' => implode(', ', $added), 'group' => $gid]); } catch (Throwable $e) {} }
        }
        return $added;
    }

    /** Take one ticket out of its group; a group left with one ticket is dissolved. */
    function opsh_rel_unlink(PDO $db, array $tk, int $otherId, array $by): ?string {
        $q = $db->prepare('SELECT id, ticket_number, related_group_id FROM opsh_tickets WHERE id=?'); $q->execute([$otherId]); $o = $q->fetch(PDO::FETCH_ASSOC);
        $gid = (int)($tk['related_group_id'] ?? 0);
        if (!$o || !$gid || (int)$o['related_group_id'] !== $gid) return null;
        $db->prepare('UPDATE opsh_tickets SET related_group_id=NULL WHERE id=?')->execute([$otherId]);
        $db->prepare('INSERT INTO opsh_ticket_comments (ticket_id,user_id,content,is_internal) VALUES (?,?,?,1)')->execute([$otherId, (int)$by['id'], 'Taken out of the related tickets by ' . ($by['display_name'] ?? '') . '.']);
        $left = $db->prepare('SELECT id FROM opsh_tickets WHERE related_group_id=?'); $left->execute([$gid]); $ids = $left->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) <= 1) {
            $db->prepare('UPDATE opsh_tickets SET related_group_id=NULL WHERE related_group_id=?')->execute([$gid]);
            $db->prepare('DELETE FROM opsh_ticket_related_groups WHERE id=?')->execute([$gid]);
        }
        if (function_exists('audit_log')) { try { audit_log('ticket_related_unlinked', 'ticket', (int)$tk['id'], (string)$tk['ticket_number'], ['ticket' => $o['ticket_number']], null); } catch (Throwable $e) {} }
        return (string)$o['ticket_number'];
    }

    /** One public reply on each ticket in $rows (open ones), with the usual reply email to each requester. */
    function opsh_rel_reply(PDO $db, array $tk, array $rows, string $text, array $by): array {
        $done = []; $sent = 0;
        foreach ($rows as $k) {
            $db->prepare('INSERT INTO opsh_ticket_comments (ticket_id,user_id,content,is_internal) VALUES (?,?,?,0)')->execute([(int)$k['id'], (int)$by['id'], $text]);
            [$to, $nm, $ru, $ext] = opsh_svc_requester($db, $k);
            $isReq = !$ext && (int)$ru === (int)$by['id'];
            if (!$isReq) $db->prepare('UPDATE opsh_tickets SET first_response_at=COALESCE(first_response_at,NOW()) WHERE id=?')->execute([(int)$k['id']]);
            if ($to !== '' && !$isReq && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                opsh_email('ticket_reply', $to, ['ticket_number' => $k['ticket_number'], 'subject' => $k['subject'], 'agent_name' => $by['display_name'] ?? '', 'comment' => $text, 'recipient_name' => $nm], ['user_id' => $ru, 'ticket_id' => (int)$k['id']]);
                $sent++;
            }
            if (!$ext && $ru && !$isReq) opsh_svc_bell($db, $ru, 'New comment', $k['ticket_number'] . ': ' . mb_substr($text, 0, 80));
            $done[] = $k['ticket_number'];
        }
        if (function_exists('audit_log')) { try { audit_log('ticket_related_reply', 'ticket', (int)$tk['id'], (string)$tk['ticket_number'], null, ['text' => mb_substr($text, 0, 300), 'tickets' => implode(', ', $done), 'emails' => $sent]); } catch (Throwable $e) {} }
        return ['tickets' => $done, 'emails' => $sent];
    }

    /** Resolve each ticket in $rows with the same note (Pending time kept), with the usual Resolved email. */
    function opsh_rel_resolve(PDO $db, array $tk, array $rows, string $note, array $by): array {
        $done = [];
        foreach ($rows as $k) {
            $pq = $db->prepare('SELECT * FROM opsh_tickets WHERE id=?'); $pq->execute([(int)$k['id']]); $pr = $pq->fetch(PDO::FETCH_ASSOC);
            if (!$pr || !in_array($pr['status'], ['open', 'in_progress', 'pending'], true)) continue;
            if (function_exists('opsh_tk_end_pause')) { opsh_tk_end_pause($db, (int)$k['id']); $pq->execute([(int)$k['id']]); $pr = $pq->fetch(PDO::FETCH_ASSOC); }   // the Pending time stays out of the clock
            $up = $db->prepare("UPDATE opsh_tickets SET status='resolved', resolved_at=NOW(), resolution_notes=?, pending_reason=NULL, first_response_at=COALESCE(first_response_at,NOW()), sla_paused_at=NULL WHERE id=? AND status IN ('open','in_progress','pending')");
            $up->execute([mb_substr($note, 0, 4000), (int)$k['id']]);
            if ($up->rowCount() !== 1) continue;
            if (function_exists('audit_log')) { try { audit_log('updated', 'ticket', (int)$k['id'], (string)$pr['ticket_number'], ['status' => $pr['status']], ['status' => 'resolved', 'with_related' => (string)$tk['ticket_number']]); } catch (Throwable $e) {} }
            [$to, $nm, $ru, $ext] = opsh_svc_requester($db, $pr);
            if ($to !== '' && ($ext || (int)$ru !== (int)$by['id']) && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $vars = ['ticket_number' => $pr['ticket_number'], 'subject' => $pr['subject'], 'status' => opsh_tpl_l10n('status', 'resolved'), 'agent_name' => $by['display_name'] ?? '', 'recipient_name' => $nm];
                if (function_exists('opsh_tk_resolved_vars')) $vars += opsh_tk_resolved_vars($db, $note, 'resolved', (int)$pr['id']);
                opsh_email('ticket_resolved', $to, $vars, ['user_id' => $ru, 'ticket_id' => (int)$pr['id']]);
            }
            if (!$ext && $ru && (int)$ru !== (int)$by['id']) opsh_svc_bell($db, $ru, 'Ticket resolved', $pr['ticket_number'] . ': ' . mb_substr($note, 0, 80));
            $done[] = $pr['ticket_number'];
        }
        if (function_exists('audit_log')) { try { audit_log('ticket_related_resolved', 'ticket', (int)$tk['id'], (string)$tk['ticket_number'], null, ['note' => mb_substr($note, 0, 300), 'tickets resolved' => implode(', ', $done)]); } catch (Throwable $e) {} }
        return $done;
    }
}
