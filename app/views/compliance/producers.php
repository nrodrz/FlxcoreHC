<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var array $producers */ /** @var array $totals */ /** @var bool $available */
$title = 'Compliance — ' . vocab('holders');
$hname = fn($h)=> ($h['holder_type']??'')==='organization' ? ($h['org_name']??'') : trim((string)($h['first_name']??'').' '.(string)($h['last_name']??''));
$stChip = fn($st)=> match($st){
  'active'=>'<span class="chip active">active</span>','expiring'=>'<span class="chip expiring">expiring</span>',
  'expired'=>'<span class="chip expired">expired</span>', default=>'<span class="chip" style="opacity:.55">missing</span>' };
$evCell = function($r) {
  if ($r['status'] === 'missing') return '<span class="hint">—</span>';
  if (($r['evidence'] ?? 0) > 0) {
    return '<a class="chip active" style="text-decoration:none" href="'.BASE_PATH.'/credentials/'.e($r['credential_id']).'/edit" title="'.e($r['credential_name'] ?? '').'">'.(int)$r['evidence'].' doc'.((int)$r['evidence']===1?'':'s').'</a>';
  }
  if (!empty($r['credential_id'])) {
    return '<a class="chip" style="opacity:.7;text-decoration:none" href="'.BASE_PATH.'/credentials/'.e($r['credential_id']).'/edit" title="Attach the certificate/policy to be audit-ready">no evidence</a>';
  }
  return '<span class="chip" style="opacity:.6">no evidence</span>';
};
$authChip = fn($a)=>'<span class="chip" style="text-transform:uppercase;font-size:.66rem">'.e($a).'</span>';
?>
<div class="crumb"><?= e($ctx->tenantName) ?> · Compliance</div>
<div class="topline"><h1><?= e(vocab('holder')) ?> status</h1></div>
<?php $tab='producers'; require FLXCOREHC_APP.'/views/compliance/_tabs.php'; ?>

<?php if (!$available): ?>
  <div class="panel"><div class="empty">The credential catalog isn't installed yet — apply it from <a href="<?= BASE_PATH ?>/admin/system">System &amp; updates</a>.</div></div>
<?php elseif ($producers === []): ?>
  <div class="panel"><div class="empty">No providers yet.<br><a class="btn gold" href="<?= BASE_PATH ?>/holders/new">Add a provider</a></div></div>
<?php else: foreach ($producers as $p): $h = $p['holder']; $rows = $p['rows'];
  $gaps = $p['total'] - $p['active'];
  // group rows: general first, then by regulator
  $general = array_values(array_filter($rows, fn($r)=>empty($r['regulator'])));
  $byRegulator = [];
  foreach ($rows as $r) { if (!empty($r['regulator'])) $byRegulator[$r['regulator']][] = $r; }
?>
<div class="panel">
  <div class="topline">
    <h2><a href="<?= BASE_PATH ?>/holders/<?= e($h['id']) ?>"><?= e($hname($h)) ?></a>
      <span class="hint" style="font-weight:400">· <?= e(ucfirst($p['role'])) ?><?= $p['defaults'] ? ' · role defaults' : '' ?></span></h2>
    <span style="display:flex;align-items:center;gap:.8rem">
      <?php $sc = $p['score'] ?? null; ?>
      <span style="min-width:150px"><span style="display:block;height:8px;background:var(--line);border-radius:5px;overflow:hidden"><span style="display:block;height:100%;width:<?= (int)($sc ?? 0) ?>%;background:<?= ($sc??0)>=90?'var(--ok)':(($sc??0)>=60?'var(--warn)':'var(--danger)') ?>"></span></span>
        <span class="hint"><?= e(t('{a}/{t} active · score', ['a' => $p['active'], 't' => $p['total']])) ?> <strong><?= $sc===null?'—':$sc ?></strong>/100</span></span>
      <?php if ($p['total'] === 0): ?><span class="chip" style="opacity:.6">no requirements</span>
      <?php elseif ($gaps === 0): ?><span class="chip active">Compliant</span>
      <?php else: ?><span class="chip expired"><?= e($gaps === 1 ? t('1 gap') : t('{n} gaps', ['n' => $gaps])) ?></span><?php endif; ?>
      <?php
        // The reminder is credential-based, not requirement-based: offer it whenever this
        // holder actually has something expiring or expired on file (backend re-checks too).
        $__needsNudge = !empty($nudgeable[$h['id']] ?? null);
        if ($__needsNudge): ?>
        <form method="post" action="<?= BASE_PATH ?>/compliance/nudge/<?= e($h['id']) ?>" style="display:inline;margin:0">
          <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
          <button class="btn sm line" title="Email this <?= e(strtolower(vocab('holder'))) ?> a summary of everything expiring or expired">Send reminder</button>
        </form>
      <?php endif; ?>
    </span>
  </div>

  <?php if ($p['total'] === 0): ?>
    <p class="hint" style="margin:0">Nothing assigned. <a href="<?= BASE_PATH ?>/holders/<?= e($h['id']) ?>">Assign required credentials</a>.</p>
  <?php else: ?>
    <?php if ($general): ?>
    <div class="table-wrap"><table>
      <tr><th>General requirement</th><th>Authority</th><th>Status</th><th>Evidence</th></tr>
      <?php foreach ($general as $r): ?>
      <tr><td><strong><?= e($r['name']) ?></strong></td><td><?= $authChip($r['authority']) ?></td><td><?= $stChip($r['status']) ?></td><td><?= $evCell($r) ?></td></tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
    <?php foreach ($byRegulator as $carName => $list): ?>
    <div class="table-wrap" style="margin-top:.6rem"><table>
      <tr><th><?= e($carName) ?> requirements</th><th>Authority</th><th>Status</th><th>Evidence</th></tr>
      <?php foreach ($list as $r): ?>
      <tr><td><strong><?= e($r['name']) ?></strong></td><td><?= $authChip($r['authority']) ?></td><td><?= $stChip($r['status']) ?></td><td><?= $evCell($r) ?></td></tr>
      <?php endforeach; ?>
    </table></div>
    <?php endforeach; ?>
    <div style="margin-top:.7rem;display:flex;gap:.5rem">
      <a class="btn sm line" href="<?= BASE_PATH ?>/holders/<?= e($h['id']) ?>">Manage requirements</a>
      <a class="btn sm line" href="<?= BASE_PATH ?>/credentials/new?holder=<?= e($h['id']) ?>">Add credential</a>
    </div>
  <?php endif; ?>
</div>
<?php endforeach; endif; ?>
