<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — file attachments for records (contracts, vendors, assets).
 *   GET    /attachments?type=contracts&id=N     list attachments for a record
 *   GET    /attachments/:id?download=1          stream a file (auth + path-guarded)
 *   POST   /attachments  (multipart: type,id,file)  upload
 *   DELETE /attachments/:id                     remove
 */
$db = getDB();
$PERM = ['contracts' => 'contracts', 'vendors' => 'vendors', 'assets' => 'assets', 'asset_loans' => 'assets', 'invoices' => 'invoices'];   // BT120: an invoice's PDF
$ALLOWED_EXT = ['pdf','doc','docx','xls','xlsx','ppt','pptx','png','jpg','jpeg','gif','webp','txt','csv','zip','msg','eml'];
$MAXB = 15 * 1024 * 1024;

$rowById = function ($aid) use ($db) {
    $st = $db->prepare("SELECT * FROM itops_attachments WHERE id=?"); $st->execute([(int)$aid]); return $st->fetch();
};

if ($method === 'GET' && $id && !empty($_GET['download'])) {
    $att = $rowById($id);
    if (!$att) error_response('Attachment not found', 404);
    $mod = $PERM[$att['entity_type']] ?? null;
    if (!$mod) error_response('Not found', 404);
    itops_require($currentUser, $mod, 'view');
    $full = UPLOAD_DIR . $att['file_path'];
    $baseReal = realpath(UPLOAD_DIR); $fileReal = realpath($full);
    if (!$fileReal || !$baseReal || strpos($fileReal, $baseReal) !== 0 || !is_file($fileReal)) error_response('File not found', 404);
    header('Content-Type: ' . ($att['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $att['file_name']) . '"');
    header('Content-Length: ' . filesize($fileReal));
    readfile($fileReal);
    exit;
}

if ($method === 'GET') {
    $type = $_GET['type'] ?? ''; $eid = (int)($_GET['id'] ?? 0);
    $mod = $PERM[$type] ?? null;
    if (!$mod || !$eid) error_response('type and id are required', 400);
    itops_require($currentUser, $mod, 'view');
    try {
        $st = $db->prepare("SELECT a.id, a.file_name, a.file_size, a.created_at, u.display_name AS uploaded_by_name
                            FROM itops_attachments a LEFT JOIN itops_users u ON u.id=a.uploaded_by
                            WHERE a.entity_type=? AND a.entity_id=? ORDER BY a.created_at DESC");
        $st->execute([$type, $eid]);
        json_response(['data' => $st->fetchAll()]);
    } catch (\Throwable $e) { json_response(['data' => [], 'needs_migration' => true]); }
    return;
}

if ($method === 'POST') {
    $type = $_POST['type'] ?? ''; $eid = (int)($_POST['id'] ?? 0);
    $mod = $PERM[$type] ?? null;
    if (!$mod || !$eid) error_response('type and id are required', 400);
    itops_require($currentUser, $mod, 'edit');
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) error_response('No file uploaded', 400);
    $f = $_FILES['file'];
    if ($f['size'] > $MAXB) error_response('File too large (max 15 MB).');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $ALLOWED_EXT, true)) error_response('That file type is not allowed.');
    $dir = UPLOAD_DIR . 'documents/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $rel = 'documents/' . bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . $rel)) error_response('Could not save file', 500);
    $mime = function_exists('mime_content_type') ? (mime_content_type(UPLOAD_DIR . $rel) ?: '') : '';
    try {
        $db->prepare("INSERT INTO itops_attachments (entity_type,entity_id,file_name,file_path,mime_type,file_size,uploaded_by) VALUES (?,?,?,?,?,?,?)")
           ->execute([$type, $eid, substr($f['name'], 0, 255), $rel, $mime, (int)$f['size'], (int)$currentUser['id']]);
        $__newId = (int)$db->lastInsertId();   // capture now — audit_log() inserts a row too
    } catch (\Throwable $e) { @unlink(UPLOAD_DIR . $rel); json_response(['ok' => false, 'needs_migration' => true]); return; }
    audit_log('attachment_added', $mod, $eid, $f['name']);
    json_response(['ok' => true, 'id' => $__newId], 201);
    return;
}

if ($method === 'DELETE' && $id) {
    $att = $rowById($id);
    if (!$att) error_response('Attachment not found', 404);
    $mod = $PERM[$att['entity_type']] ?? null;
    if (!$mod) error_response('Not found', 404);
    itops_require($currentUser, $mod, 'edit');
    require_once __DIR__ . '/../lib/RecycleBin.php'; itops_bin_put($db, 'file', (int)$id, $currentUser);   // BT141: to Deleted items; the file stays until deleted for good
    audit_log('attachment_removed', $mod, (int)$att['entity_id'], $att['file_name'], null, ['Kept in' => 'Deleted items']);
    json_response(['ok' => true]);
    return;
}

error_response('Not found', 404);
