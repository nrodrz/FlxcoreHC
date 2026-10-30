<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Operational event log reader.
 * Route: /api/event_log  (GET). Admins / auditors only. Read-only.
 * Mirrors the existing audit.php reader (pagination + filters).
 */
opsh_require($currentUser, 'event_log', 'view');
$db = getDB();

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = min(200, max(1, (int)($_GET['limit'] ?? 100)));
$offset = ($page - 1) * $limit;

$where = '1=1';
$bind  = [];
if (!empty($_GET['level']))   { $where .= ' AND level = ?';   $bind[] = $_GET['level']; }
if (!empty($_GET['channel'])) { $where .= ' AND channel = ?'; $bind[] = $_GET['channel']; }
if (!empty($_GET['search']))  { $where .= ' AND message LIKE ?'; $bind[] = '%'.$_GET['search'].'%'; }

$c = $db->prepare("SELECT COUNT(*) FROM opsh_event_log WHERE $where");
$c->execute($bind);
$total = (int)$c->fetchColumn();

$s = $db->prepare("SELECT * FROM opsh_event_log WHERE $where ORDER BY ts DESC LIMIT $limit OFFSET $offset");
$s->execute($bind);

// Distinct channels for the filter dropdown.
$chans = $db->query("SELECT DISTINCT channel FROM opsh_event_log ORDER BY channel")->fetchAll(PDO::FETCH_COLUMN);

json_response([
    'data'     => $s->fetchAll(),
    'channels' => $chans,
    'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int)ceil($total / $limit)],
]);
