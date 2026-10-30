<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — /api/links   (generic cross-links between records)
 *
 * GET    /links?type=sops&id=5   — items linked to (type,id), with resolved titles
 * POST   /links                  — create a link { a_type,a_id,b_type,b_id }  (deduped)
 * DELETE /links/:id              — remove a link
 *
 * Links are bidirectional (stored once, matched in either direction).
 * BT91 (code audit M-26): the list shows only the linked records this person may see (the module, and for a ticket the
 * ticket itself); making or removing a link needs Edit on the record it is made from and the right to see the other,
 * and Standards & Audits · Edit when one side is a requirement (a link there counts as evidence). Both records must exist.
 * Every link and unlink is in the Audit Log, and so is every refusal.
 */

$db  = getDB();
$p1  = $segments[1] ?? '';
$id  = ctype_digit((string)$p1) ? (int)$p1 : null;

// linkable type -> [table, display column, label]
$TYPES = [
    'sops'             => ['opsh_sops',             'title',   'SOP'],
    'job_descriptions' => ['opsh_job_descriptions', 'title',   'Job Description'],
    'credentials'      => ['opsh_credentials',      'name',    'Credential'],
    'contracts'        => ['opsh_contracts',        'title',   'Contract'],
    'vendors'          => ['opsh_vendors',          'name',    'Vendor'],
    'kb'               => ['opsh_kb_articles',      'title',   'Knowledge Base'],
    'tickets'          => ['opsh_tickets',          'subject', 'Ticket'],
    'std_req'         => ['opsh_std_requirements', 'title',   'Requirement'],
];

// type -> permission module (most map to themselves; evidence uses the 'standards' module)
$PERMMOD = ['std_req' => 'standards', 'credentials' => 'creds'];   // BT91: the vault's permission is "creds"
$permOf = function ($type) use ($PERMMOD) { return $PERMMOD[$type] ?? $type; };

$needMig = function (\Throwable $e) {
    $c = $e->getCode();
    if ($c === '42S02' || $c === 1146 || strpos($e->getMessage(), 'Base table') !== false) {
        json_response(['data' => [], 'needs_migration' => true]);
    }
    throw $e;
};

// BT91: may this person see that record? (the module; a ticket also needs to be theirs without Tickets · All records)
$canSee = function (string $type, int $rid) use ($db, $currentUser, $permOf): bool {
    if (!opsh_can($currentUser, $permOf($type), 'view')) return false;
    if ($type === 'tickets' && !opsh_can($currentUser, 'tickets', 'all')) {
        $q = $db->prepare('SELECT 1 FROM opsh_tickets WHERE id=? AND (assignee_id=? OR requester_id=? OR backup_id=?)'); $u = (int)$currentUser['id'];
        $q->execute([$rid, $u, $u, $u]); return (bool)$q->fetchColumn();
    }
    return true;
};
$lRefuse = function (string $wanted, string $why, string $msg, int $code = 403) {
    audit_log('link_refused', 'link', null, mb_substr($wanted, 0, 250), null, ['Wanted' => $wanted, 'Why' => $why, 'Result' => 'Nothing was changed']);
    error_response($msg, $code);
};
$mayLink = function (string $from, int $fid, string $other, int $oid) use ($currentUser, $permOf, $canSee): ?string {
    if (!opsh_can($currentUser, $permOf($from), 'edit')) return 'needs Edit on ' . $from;
    if (!$canSee($from, $fid) || !$canSee($other, $oid)) return 'cannot see one of the records';
    if (($from === 'std_req' || $other === 'std_req') && !opsh_can($currentUser, 'standards', 'edit')) return 'needs Standards & Audits · Edit (evidence)';
    return null;
};

// resolve a record's display name (columns/tables come from the fixed whitelist above)
$resolve = function ($type, $rid) use ($db, $TYPES) {
    if (!isset($TYPES[$type])) return null;
    [$tbl, $col] = $TYPES[$type];
    try {
        $st = $db->prepare("SELECT {$col} AS nm FROM {$tbl} WHERE id = ?");
        $st->execute([(int)$rid]);
        $r = $st->fetch();
        return $r ? (string)($r['nm'] ?? '') : null;
    } catch (\Throwable $e) { return null; }
};

// ── LIST ──────────────────────────────────────────────
if ($method === 'GET' && $id === null) {
    $type = $_GET['type'] ?? '';
    $rid  = (int)($_GET['id'] ?? 0);
    if (!isset($TYPES[$type]) || !$rid) error_response('type and id are required');
    opsh_require($currentUser, $permOf($type), 'view');
    if (!$canSee($type, $rid)) error_response('You cannot open this record.', 403);   // review CORE-21: the source record too
    try {
        $st = $db->prepare("SELECT * FROM opsh_links WHERE (a_type=? AND a_id=?) OR (b_type=? AND b_id=?) ORDER BY id DESC");
        $st->execute([$type, $rid, $type, $rid]);
        $rows = $st->fetchAll();
    } catch (\Throwable $e) { $needMig($e); }
    $out = [];
    foreach ($rows as $r) {
        $isA = ($r['a_type'] === $type && (int)$r['a_id'] === $rid);
        $oT  = $isA ? $r['b_type'] : $r['a_type'];
        $oI  = $isA ? (int)$r['b_id'] : (int)$r['a_id'];
        if (!isset($TYPES[$oT]) || !$canSee($oT, $oI)) continue;   // BT91: what this person may not see is not listed (not even its title)
        $nm  = $resolve($oT, $oI);
        if ($nm === null) continue;   // target was deleted — skip
        $out[] = ['link_id' => (int)$r['id'], 'type' => $oT, 'label' => $TYPES[$oT][2] ?? $oT, 'id' => $oI, 'title' => ($nm !== '' ? $nm : ('#' . $oI))];
    }
    json_response(['data' => $out]);
}

// ── CREATE ────────────────────────────────────────────
if ($method === 'POST' && $id === null) {
    $b  = json_decode(file_get_contents('php://input'), true) ?: [];
    $at = $b['a_type'] ?? ''; $ai = (int)($b['a_id'] ?? 0);
    $bt = $b['b_type'] ?? ''; $bi = (int)($b['b_id'] ?? 0);
    if (!isset($TYPES[$at]) || !isset($TYPES[$bt]) || !$ai || !$bi) error_response('Invalid link');
    if ($at === $bt && $ai === $bi) error_response('Cannot link a record to itself');
    if ($resolve($at, $ai) === null || $resolve($bt, $bi) === null) error_response('One of the records does not exist (any more).', 404);
    if ($why = $mayLink($at, $ai, $bt, $bi)) $lRefuse('Link ' . $at . ' #' . $ai . ' to ' . $bt . ' #' . $bi, $why, 'Linking needs Edit on this record and access to the other one' . (strpos($why, 'Standards & Audits') !== false ? ', and Standards & Audits · Edit for evidence' : '') . '.');
    try {
        $dup = $db->prepare("SELECT id FROM opsh_links WHERE (a_type=? AND a_id=? AND b_type=? AND b_id=?) OR (a_type=? AND a_id=? AND b_type=? AND b_id=?) LIMIT 1");
        $dup->execute([$at, $ai, $bt, $bi, $bt, $bi, $at, $ai]);
        if ($dup->fetch()) json_response(['ok' => true, 'duplicate' => true]);
        $db->prepare("INSERT INTO opsh_links (a_type,a_id,b_type,b_id,created_by,created_at) VALUES (?,?,?,?,?,NOW())")
           ->execute([$at, $ai, $bt, $bi, (int)$currentUser['id']]);
        $__newId = (int)$db->lastInsertId();   // capture now — audit_log() inserts a row too
    } catch (\Throwable $e) { $needMig($e); }
    audit_log('linked', $at, $ai, ($TYPES[$bt][2] ?? $bt) . ' #' . $bi);
    json_response(['ok' => true, 'id' => $__newId]);
}

// ── DELETE ────────────────────────────────────────────
if ($method === 'DELETE' && $id !== null) {
    $st = $db->prepare("SELECT * FROM opsh_links WHERE id = ?");
    $st->execute([$id]);
    $lk = $st->fetch();
    if ($lk) {
        // BT91: removing needs the same as making it, from either side
        $w1 = $mayLink((string)$lk['a_type'], (int)$lk['a_id'], (string)$lk['b_type'], (int)$lk['b_id']);
        $w2 = $w1 ? $mayLink((string)$lk['b_type'], (int)$lk['b_id'], (string)$lk['a_type'], (int)$lk['a_id']) : null;
        if ($w1 && $w2) $lRefuse('Unlink ' . $lk['a_type'] . ' #' . $lk['a_id'] . ' and ' . $lk['b_type'] . ' #' . $lk['b_id'], $w1, 'Removing this link needs Edit on one of the records and access to both' . (strpos($w1 . $w2, 'Standards & Audits') !== false ? ', and Standards & Audits · Edit for evidence' : '') . '.');
        $db->prepare("DELETE FROM opsh_links WHERE id = ?")->execute([$id]);
        audit_log('unlinked', (string)$lk['a_type'], (int)$lk['a_id'], ($TYPES[$lk['b_type']][2] ?? $lk['b_type']) . ' #' . (int)$lk['b_id']);
    }
    json_response(['ok' => true]);
}

error_response('Not found', 404);
