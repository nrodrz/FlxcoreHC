<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — /api/sent_emails   (BT62: Logs & Integrations → Sent emails — every email the app tried to send)
 *
 * GET /sent_emails?q=&result=sent|failed&kind=&from=&to=&page=     the list (50 a page), newest first, with counts   (Event Log · View)
 * GET /sent_emails/export?…same filters…&preview=1                  CSV of the filtered list (up to 10,000 rows);
 *                                                                    a preview is audited as a preview, a download as an export
 *
 * Only what was sent is kept (when, to whom, subject, which email, how, the result and the error) — never the body:
 * some emails carry a temporary password or a sign-in link.
 */
$db = getDB();
$what = (string)($segments[1] ?? '');
if ($method !== 'GET') error_response('Not found', 404);
itops_require($currentUser, 'event_log', 'view');
require_once __DIR__ . '/../lib/EmailTemplates.php';
$names = []; try { foreach (itops_tpl_catalog() as $k => $t) $names[$k] = (string)($t[1] ?? $k); } catch (Throwable $e) {}
$names += ['mail_ack' => 'Acknowledgement of a new email', 'mail_notify_reply' => 'Reply on a ticket', 'mail_notify_status' => 'Ticket status changed', 'mail_notify_assign' => 'Ticket assigned', 'test' => 'Test email'];
$kindName = fn(string $k) => $k === '' ? 'Other' : ($names[$k] ?? ucfirst(str_replace('_', ' ', $k)));

$where = []; $bind = [];
$q = trim((string)($_GET['q'] ?? '')); if ($q !== '') { $where[] = '(l.to_email LIKE ? OR l.subject LIKE ?)'; $bind[] = '%' . $q . '%'; $bind[] = '%' . $q . '%'; }
$res = (string)($_GET['result'] ?? ''); if ($res === 'sent') $where[] = 'l.ok=1'; elseif ($res === 'failed') $where[] = 'l.ok=0';
$kind = (string)($_GET['kind'] ?? ''); if ($kind !== '') { $where[] = 'l.kind=?'; $bind[] = $kind; }
$from = (string)($_GET['from'] ?? ''); if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'l.created_at >= ?'; $bind[] = $from . ' 00:00:00'; }
$to = (string)($_GET['to'] ?? ''); if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'l.created_at < ?'; $bind[] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'; }
$W = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$sel = 'SELECT l.id, l.created_at, l.to_email, l.subject, l.kind, l.method, l.ok, l.error, l.ticket_id, t.ticket_number, u.display_name AS sent_by_name
        FROM itops_mail_log l LEFT JOIN itops_users u ON u.id = l.sent_by LEFT JOIN itops_tickets t ON t.id = l.ticket_id' . $W . ' ORDER BY l.id DESC';
$row = fn(array $r) => ['id' => (int)$r['id'], 'at' => $r['created_at'], 'to' => (string)$r['to_email'], 'subject' => (string)$r['subject'], 'kind' => (string)$r['kind'], 'kind_name' => $kindName((string)$r['kind']),
    'method' => (string)$r['method'], 'ok' => (bool)$r['ok'], 'error' => (string)($r['error'] ?? ''), 'ticket_id' => $r['ticket_id'] ? (int)$r['ticket_id'] : null, 'ticket_number' => $r['ticket_number'], 'sent_by' => $r['sent_by_name']];

if ($what === 'export') {
    $s = $db->prepare($sel . ' LIMIT 10000'); $s->execute($bind); $rows = array_map($row, $s->fetchAll(PDO::FETCH_ASSOC));
    $cell = function ($v) { $v = (string)$v; if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) $v = "'" . $v; return '"' . str_replace('"', '""', $v) . '"'; };
    $out = "\xEF\xBB\xBF" . implode(',', array_map($cell, ['When (' . date_default_timezone_get() . ')', 'To', 'Subject', 'Email', 'Result', 'Error', 'Ticket', 'Sent by', 'How'])) . "\r\n";
    foreach ($rows as $r) $out .= implode(',', array_map($cell, [date('m/d/Y g:i A', strtotime($r['at'])), $r['to'], $r['subject'], $r['kind_name'], $r['ok'] ? 'Sent' : 'Failed', $r['error'], (string)$r['ticket_number'], (string)$r['sent_by'], $r['method']])) . "\r\n";
    $prev = !empty($_GET['preview']);
    $filt = array_filter(['Search' => $q, 'Result' => $res, 'Email' => $kind !== '' ? $kindName($kind) : '', 'From' => $from, 'To' => $to], fn($v) => $v !== '');
    audit_log($prev ? 'mail_log_preview' : 'mail_log_export', 'event_log', 0, 'Sent emails', null, $filt + ['Rows' => (string)count($rows)]);
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: ' . ($prev ? 'inline' : 'attachment') . '; filename="sent-emails-' . date('Y-m-d') . '.csv"'); header('Cache-Control: no-store');
    echo $out; exit;
}
if ($what !== '') error_response('Not found', 404);
$page = max(1, (int)($_GET['page'] ?? 1)); $per = 50;
$c = $db->prepare('SELECT COUNT(*) FROM itops_mail_log l' . $W); $c->execute($bind); $total = (int)$c->fetchColumn();
$s = $db->prepare($sel . ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per)); $s->execute($bind);
$cnt = function (string $w, array $b = []) use ($db) { $x = $db->prepare('SELECT COUNT(*) FROM itops_mail_log WHERE ' . $w); $x->execute($b); return (int)$x->fetchColumn(); };
$kinds = []; foreach ($db->query("SELECT kind, COUNT(*) n FROM itops_mail_log GROUP BY kind ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC) as $k) $kinds[] = [(string)$k['kind'], $kindName((string)$k['kind']) . ' (' . number_format((int)$k['n']) . ')'];
json_response(['emails' => array_map($row, $s->fetchAll(PDO::FETCH_ASSOC)), 'total' => $total, 'page' => $page, 'per_page' => $per, 'kinds' => $kinds,
    'counts' => ['today' => $cnt('created_at >= ?', [date('Y-m-d 00:00:00')]), 'failed_7d' => $cnt('ok=0 AND created_at >= ?', [date('Y-m-d H:i:s', time() - 7 * 86400)]),
                 'month' => $cnt('created_at >= ?', [date('Y-m-01 00:00:00')]), 'all' => $cnt('1=1')]]);
