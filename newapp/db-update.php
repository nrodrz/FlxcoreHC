<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Standalone database updater.
 * Visit this page in a browser while signed in to OpsHub as an administrator.
 * It applies any pending database updates (schema changes + content) safely and
 * idempotently. This is the "upgrade" page; it mirrors the in-app Database Updates card.
 */
$cfg = __DIR__ . '/config.php';
if (!is_file($cfg)) { http_response_code(503); exit('OpsHub is not installed yet. Run install/ first.'); }
require_once $cfg;
require_once __DIR__ . '/api/lib/BusinessHours.php'; try { opsh_db_clock_sync(getDB()); } catch (Throwable $e) {}   // BT52: the database clock follows PHP's
require_once __DIR__ . '/api/lib/Migrations.php';
require_once __DIR__ . '/api/lib/Brand.php';   // BT20
$APPN = htmlspecialchars(opsh_app_name(), ENT_QUOTES, 'UTF-8');

// Authenticate via the OpsHub session cookie; super_admin only.
$user = null;
$token = $_COOKIE['opsh_token'] ?? '';
if ($token && function_exists('jwt_decode')) {
    $payload = jwt_decode($token);
    // BT14: a real, live session only (not the half-way 2FA token; not a signed-out token).
    $live = false;
    if ($payload && !empty($payload['uid']) && empty($payload['mfa'])) {
        try { $sq = getDB()->prepare('SELECT 1 FROM opsh_sessions WHERE token_hash=? AND expires_at>=? LIMIT 1'); $sq->execute([hash('sha256', $token), date('Y-m-d H:i:s')]); $live = (bool)$sq->fetchColumn(); } catch (Throwable $e) { $live = false; }
    }
    if ($live) {
        $st = getDB()->prepare('SELECT u.id, u.display_name, r.slug AS role_slug
                                FROM opsh_users u LEFT JOIN opsh_roles r ON u.role_id = r.id
                                WHERE u.id = ? AND u.status = "active"');
        $st->execute([$payload['uid']]);
        $user = $st->fetch();
    }
}
$isAdmin = $user && ($user['role_slug'] ?? '') === 'super_admin';

$report = null;
$csrfTok = $token !== '' ? hash_hmac('sha256', 'db-update', $token) : '';
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($csrfTok, (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Invalid form token — reload the page and try again.'); }
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $report = opsh_run_migrations(getDB());
    if (function_exists('audit_log')) audit_log('db_update_page', 'system', 0, 'run');
}
$pending = $isAdmin ? opsh_pending_versions(getDB()) : [];
$appBase = rtrim(defined('APP_URL') ? APP_URL : '.', '/');
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>
<title><?= $APPN ?> — Database Update</title>
<style>
:root{--bg:#F5F3F0;--card:#FEFDFB;--t1:#2B2926;--t3:#6B6661;--t4:#9A938B;--b1:#DDD9D3;--accent:#4A7FB5;--accentH:#3B6A9E;--green:#3D9A6E;--red:#C44545;--amber:#C08A30;--font:'DM Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--t1);padding:24px;display:flex;justify-content:center}
.wrap{width:100%;max-width:640px}
.card{background:var(--card);border:1px solid var(--b1);border-radius:16px;padding:26px;box-shadow:0 6px 24px rgba(43,41,38,.08)}
.head{display:flex;align-items:center;gap:12px;margin-bottom:6px}
.mark{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,var(--accent),#7BA3CC);color:#fff;font-weight:800;font-size:13px;display:flex;align-items:center;justify-content:center}
h1{font-size:19px;font-weight:700}
.sub{font-size:13px;color:var(--t3);margin:2px 0 18px}
.btn{display:inline-block;padding:12px 18px;border:none;border-radius:10px;background:var(--accent);color:#fff;font-family:var(--font);font-size:14px;font-weight:700;cursor:pointer;text-decoration:none}
.btn:hover{background:var(--accentH)}
.note{padding:12px 14px;border-radius:10px;font-size:13px;line-height:1.5;margin:6px 0}
.note.info{background:#EEF4FA;border:1px solid #CFE0EF;color:#2f4d68}
.note.ok{background:#EAF6F0;border:1px solid #BFE3D2;color:#256b4c}
.note.warn{background:#FBF3E4;border:1px solid #ECD9B0;color:#7a5a1e}
.row{display:flex;align-items:flex-start;gap:8px;padding:9px 0;border-bottom:1px solid var(--b1);font-size:13px}
.row:last-child{border-bottom:0}
.tag{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;padding:3px 8px;border-radius:20px;white-space:nowrap}
.tag.applied{background:#EAF6F0;color:#256b4c}.tag.skipped{background:#EEF1F4;color:#5a6470}.tag.error{background:#FBEAEA;color:#9c2f2f}
code{font-family:ui-monospace,monospace;font-size:12px;color:var(--t3)}
.foot{font-size:11px;color:var(--t4);margin-top:16px;text-align:center}
a.link{color:var(--accent);text-decoration:none}
</style></head>
<body><div class="wrap"><div class="card">
  <div class="head"><div class="mark"><?= htmlspecialchars((function($n){ $w=preg_split('/\s+/',trim($n),-1,PREG_SPLIT_NO_EMPTY); if(count($w)>1) return strtoupper(mb_substr($w[0],0,1).mb_substr($w[1],0,1)); $c=preg_replace('/[^A-Z]/','',$n); return strtoupper(strlen($c)>=2?substr($c,0,2):mb_substr($n,0,2)); })(opsh_app_name()), ENT_QUOTES, 'UTF-8') ?></div><div><h1>Database Update</h1></div></div>
  <div class="sub">Applies pending schema changes and content safely. Running it more than once is harmless.</div>

<?php if (!$isAdmin): ?>
  <div class="note warn">Please sign in to <?= $APPN ?> as an administrator first, then reload this page.</div>
  <p style="margin-top:14px"><a class="btn" href="<?= htmlspecialchars($appBase) ?>/">Go to <?= $APPN ?> sign-in</a></p>
<?php else: ?>
  <?php if ($report !== null): ?>
    <?php $err = false; foreach ($report as $r) if ($r['status']==='error') $err=true; ?>
    <div class="note <?= $err ? 'warn' : 'ok' ?>"><?= $err ? 'Completed with an error — see below.' : 'Database is up to date. ✓' ?></div>
    <div style="margin-top:10px">
    <?php foreach ($report as $r): ?>
      <div class="row"><span class="tag <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span>
        <div><code><?= htmlspecialchars($r['version']) ?></code><br><?= htmlspecialchars($r['message']) ?></div></div>
    <?php endforeach; ?>
    </div>
    <p style="margin-top:18px"><a class="btn" href="<?= htmlspecialchars($appBase) ?>/">Back to <?= $APPN ?></a></p>
  <?php elseif (empty($pending)): ?>
    <div class="note ok">Your database is already up to date — nothing to apply. ✓</div>
    <p style="margin-top:18px"><a class="btn" href="<?= htmlspecialchars($appBase) ?>/">Back to <?= $APPN ?></a></p>
  <?php else: ?>
    <div class="note info"><strong><?= count($pending) ?></strong> update<?= count($pending)===1?'':'s' ?> available:
      <ul style="margin:8px 0 0 18px">
        <?php foreach ($pending as $p): ?><li><code><?= htmlspecialchars($p) ?></code></li><?php endforeach; ?>
      </ul>
    </div>
    <form method="post" style="margin-top:18px"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfTok, ENT_QUOTES) ?>"><button class="btn" type="submit">Apply updates now</button></form>
  <?php endif; ?>
  <div class="foot">Signed in as <?= htmlspecialchars($user['display_name'] ?? 'admin') ?> · this page does not modify config.php</div>
<?php endif; ?>
</div></div></body></html>
