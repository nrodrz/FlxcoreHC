<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */
/** @var array $items */
/** @var array $statusMap */
/** @var array $score */
$title = 'Security & privacy';
$byCat = [];
foreach ($items as $it) { $byCat[$it['category']][] = $it; }
$catLabels = ['access'=>'Access control','encryption'=>'Encryption & transport','audit'=>'Audit & monitoring',
  'retention'=>'Retention & disposal','training'=>'Training','policy'=>'Policies & procedures'];
$statusLabels = ['not_started'=>'Not started','in_progress'=>'In progress','done'=>'Done','na'=>'N/A'];
$chipClass = ['not_started'=>'pending','in_progress'=>'in_progress','done'=>'done','na'=>'active'];
?>
<div class="crumb"><?= e($ctx->tenantName) ?></div>
<div class="topline">
  <h1>Security &amp; privacy checklist</h1>
</div>
<?php $tab='security'; require FLXCOREHC_APP.'/views/compliance/_tabs.php'; ?>

<div class="panel" style="display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap">
  <div>
    <div style="font-family:var(--font-mono,monospace);font-size:2rem;font-weight:800;line-height:1">
      <?= (int) $score['pct'] ?>%</div>
    <div class="hint"><?= e(t('Required items complete ({done}/{total})', ['done' => $score['required_done'], 'total' => $score['required_total']])) ?></div>
  </div>
  <div style="flex:1;min-width:160px">
    <div style="height:10px;background:var(--line,#e3e1da);border-radius:6px;overflow:hidden">
      <div style="height:100%;width:<?= (int)$score['pct'] ?>%;background:<?= $score['pct']>=100?'var(--ok,#2f7d5b)':($score['pct']>=50?'var(--warn,#c77b21)':'var(--danger,#be4b42)') ?>"></div>
    </div>
    <div class="hint" style="margin-top:.4rem"><?= e(t('All items: {done}/{total} addressed. Required items must be Done or N/A to reach 100%.', ['done' => $score['all_done'], 'total' => $score['all_total']])) ?></div>
  </div>
</div>

<?php foreach ($byCat as $cat => $list): ?>
<div class="panel">
  <h2><?= e($catLabels[$cat] ?? ucfirst($cat)) ?></h2>
  <table>
    <tr><th>Item</th><th>Severity</th><th>Status</th><th>Update</th></tr>
    <?php foreach ($list as $it):
      $st = $statusMap[$it['id']]['status'] ?? 'not_started'; ?>
    <tr>
      <td><strong><?= e($it['title']) ?></strong>
        <?php if ($it['description']): ?><span class="sub"><?= e($it['description']) ?></span><?php endif; ?>
        <?php if (!empty($statusMap[$it['id']]['note'])): ?><span class="sub" style="color:var(--ink-2)">Note: <?= e($statusMap[$it['id']]['note']) ?></span><?php endif; ?></td>
      <td><span class="chip <?= $it['severity']==='required'?'expired':'pending' ?>"><?= e($it['severity']) ?></span></td>
      <td><span class="chip <?= $chipClass[$st] ?>"><?= e($statusLabels[$st]) ?></span></td>
      <td>
        <form method="post" action="<?= BASE_PATH ?>/compliance/security/<?= e($it['id']) ?>" style="display:flex;gap:.3rem;flex-wrap:wrap">
          <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
          <select name="status" style="width:auto;padding:.3rem .5rem;font-size:.8rem">
            <?php foreach ($statusLabels as $k=>$lbl): ?>
              <option value="<?= $k ?>" <?= $st===$k?'selected':'' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn sm line">Save</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endforeach; ?>

<p class="hint"><?= str_replace(['{required}', '{recommended}'], ['<strong>' . e(t('required')) . '</strong>', '<strong>' . e(t('recommended')) . '</strong>'],
  e(t('This checklist adapts to your organization type ({type}). Items marked {required} are needed for your compliance posture; {recommended} items strengthen it. Status changes are recorded in your audit trail.', ['type' => t((string)$ctx->vertical)]))) ?></p>
