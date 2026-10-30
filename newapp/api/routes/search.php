<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — Global Search
 * GET /api/search?q=term → combined matches across modules the user may VIEW (permission-filtered).
 */
$db = getDB();
if ($method !== 'GET') error_response('Method not allowed', 405);

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) { json_response(['data' => [], 'query' => $q]); }
$like = '%' . $q . '%';
$out = [];

$add = function($type, $id, $title, $sub, $page) use (&$out) {
    $out[] = ['type' => $type, 'id' => (int)$id, 'title' => $title, 'sub' => $sub, 'page' => $page];
};

if (itops_can($currentUser, 'tickets')) {
$scope = itops_can($currentUser,'tickets','all') ? '' : ' AND (assignee_id=' . (int)$currentUser['id'] . ' OR requester_id=' . (int)$currentUser['id'] . ' OR backup_id=' . (int)$currentUser['id'] . ')';
$st = $db->prepare("SELECT id,ticket_number,subject,status FROM itops_tickets WHERE (subject LIKE ? OR ticket_number LIKE ? OR description LIKE ?)$scope ORDER BY created_at DESC LIMIT 8");
$st->execute([$like, $like, $like]);
foreach ($st->fetchAll() as $r) $add('ticket', $r['id'], $r['ticket_number'] . ' · ' . $r['subject'], ucfirst(str_replace('_', ' ', $r['status'])), 'tickets');
}

if (itops_can($currentUser, 'kb')) {
$kbEd = itops_can($currentUser, 'kb', 'edit');   // same rule as the Knowledge Base list   // review CORE-10: readers find published articles only
$st = $db->prepare("SELECT id,title FROM itops_kb_articles WHERE (title LIKE ? OR content LIKE ?)" . ($kbEd ? '' : " AND status='published'") . " LIMIT 6");
$st->execute([$like, $like]);
foreach ($st->fetchAll() as $r) $add('kb', $r['id'], $r['title'], 'Knowledge Base', 'kb');
}

if (itops_can($currentUser, 'sops')) {
$sopEd = itops_can($currentUser, 'sops', 'edit') || itops_can($currentUser, 'sops', 'create');   // review CORE-10
$st = $db->prepare("SELECT id,title FROM itops_sops WHERE title LIKE ?" . ($sopEd ? '' : " AND status='published'") . " LIMIT 6");
$st->execute([$like]);
foreach ($st->fetchAll() as $r) $add('sop', $r['id'], $r['title'], 'SOP', 'sops');
}

if (itops_can($currentUser, 'vendors')) {
$st = $db->prepare("SELECT id,name FROM itops_vendors WHERE name LIKE ? OR category LIKE ? LIMIT 5");
$st->execute([$like, $like]);
foreach ($st->fetchAll() as $r) $add('vendor', $r['id'], $r['name'], 'Vendor', 'vendors');
}

if (itops_can($currentUser, 'contracts')) {
$st = $db->prepare("SELECT id,title FROM itops_contracts WHERE title LIKE ? OR contract_number LIKE ? LIMIT 5");
$st->execute([$like, $like]);
foreach ($st->fetchAll() as $r) $add('contract', $r['id'], $r['title'], 'Contract', 'contracts');
}

json_response(['data' => $out, 'query' => $q]);
