<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** The merged credential checklist. Expects $checklist (from ChecklistService), $row, $ctx. */
$ck = $checklist ?? ['rows'=>[],'done'=>0,'total'=>0,'pct'=>0,'missing'=>0,'expiring'=>0,'expired'=>0,'provider_type'=>null,'has_template'=>false];
$canWrite = FlxCoreHC\Authz::can($ctx, 'records.write');
$hid = $row['id'];
$pctColor = $ck['pct'] >= 90 ? 'var(--ok,#2f7d5b)' : ($ck['pct'] >= 60 ? 'var(--warn,#c47f1a)' : 'var(--danger,#be4b42)');
$statusChip = [
  'active'   => '<span class="chip active" style="font-size:.62rem">'.e(t('on file')).'</span>',
  'expiring' => '<span class="chip expiring" style="font-size:.62rem">'.e(t('expiring')).'</span>',
  'expired'  => '<span class="chip expired" style="font-size:.62rem">'.e(t('expired')).'</span>',
  'missing'  => '<span class="chip" style="font-size:.62rem;color:var(--danger)">'.e(t('needed')).'</span>',
];
?>
<div class="panel">
  <div class="topline">
    <h2 style="margin:0"><?= e(t('Credential checklist')) ?></h2>
    <span class="hint"><?= e(t('What this profession requires, and whether it is on file')) ?></span>
  </div>

  <?php if (!$ck['has_template'] && $ck['total'] === 0): ?>
    <div class="empty" style="text-align:left">
      <?php if (empty($ck['provider_type'])): ?>
        <?= e(t('Set this provider\'s profession to load the required credentials automatically.')) ?>
        <?php if ($canWrite): ?><a class="btn sm gold" href="<?= BASE_PATH ?>/holders/<?= e($hid) ?>/edit" style="margin-left:.5rem"><?= e(t('Set profession')) ?></a><?php endif; ?>
      <?php else: ?>
        <?= e(t('No template exists yet for this profession. Add requirements below, or create a template under Compliance templates.')) ?>
      <?php endif; ?>
    </div>
  <?php else: ?>

  <!-- progress bar -->
  <div style="display:flex;align-items:center;gap:.8rem;margin:.2rem 0 .9rem">
    <div style="flex:1;height:10px;background:var(--surface-3);border-radius:99px;overflow:hidden">
      <div style="height:100%;width:<?= $ck['pct'] ?>%;background:<?= $pctColor ?>;border-radius:99px"></div>
    </div>
    <strong style="font-size:.9rem;color:<?= $pctColor ?>"><?= $ck['pct'] ?>%</strong>
    <span class="hint" style="white-space:nowrap"><?= (int)$ck['done'] ?>/<?= (int)$ck['total'] ?> <?= e(t('complete')) ?></span>
  </div>
  <?php if ($ck['missing'] || $ck['expired'] || $ck['expiring']): ?>
  <p class="hint" style="margin:-.4rem 0 .8rem;font-size:.8rem">
    <?php $bits=[]; if($ck['expired'])$bits[]='<span style="color:var(--danger)">'.$ck['expired'].' '.e(t('expired')).'</span>'; if($ck['missing'])$bits[]=$ck['missing'].' '.e(t('needed')); if($ck['expiring'])$bits[]='<span style="color:var(--warn,#c47f1a)">'.$ck['expiring'].' '.e(t('expiring soon')).'</span>'; echo implode(' · ',$bits); ?>
  </p>
  <?php endif; ?>

  <!-- one row per requirement -->
  <div class="ck-list">
    <?php foreach ($ck['rows'] as $r):
      $satisfied = $r['status'] !== 'missing';
      $icon = match($r['status']){ 'active'=>'✓','expiring'=>'!','expired'=>'✕','missing'=>'○',default=>'○' };
      $iconColor = match($r['status']){ 'active'=>'var(--ok,#2f7d5b)','expiring'=>'var(--warn,#c47f1a)','expired'=>'var(--danger,#be4b42)',default=>'var(--ink-3)' };
    ?>
    <div class="ck-row<?= $r['status']==='missing'?' ck-need':'' ?>">
      <span class="ck-icon" style="color:<?= $iconColor ?>;border-color:<?= $iconColor ?>"><?= $icon ?></span>
      <div class="ck-main">
        <div class="ck-label"><?= e($r['label']) ?>
          <?php if ($r['is_extra']): ?><span class="hint" style="font-size:.66rem">· <?= e(t('extra')) ?></span><?php endif; ?>
        </div>
        <?php if ($r['meta']): ?><div class="ck-meta"><?= e($r['meta']) ?></div><?php endif; ?>
        <?php if (!empty($r['source'])): ?><div class="ck-why">&#9878; <?= e(t('Why?')) ?> <?= e($r['source']['authority']) ?><?php if($r['source']['url']): ?> · <a href="<?= e($r['source']['url']) ?>" target="_blank" rel="noopener"><?= e(t('source')) ?> ↗</a><?php endif; ?></div><?php endif; ?>
      </div>
      <div class="ck-right">
        <?= $statusChip[$r['status']] ?? '' ?>
        <?php if ($r['expiry']): ?><span class="ck-exp"><?= e(t('exp')) ?> <?= e(fmt_day((string)$r['expiry'])) ?></span><?php endif; ?>
        <?php if ($canWrite): ?>
          <?php if ($satisfied && $r['credential_id']): ?>
            <a class="btn sm line" href="<?= BASE_PATH ?>/credentials/<?= e($r['credential_id']) ?>/edit"><?= e($r['status']==='active'?t('View'):t('Renew')) ?></a>
            <?php if (FlxCoreHC\Authz::can($ctx, 'records.delete')): ?>
            <form method="post" action="<?= BASE_PATH ?>/credentials/<?= e($r['credential_id']) ?>/delete" style="display:inline;margin:0" data-confirm="<?= e(t('Remove this credential? It moves to Deleted records and can be restored.')) ?>"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
              <input type="hidden" name="return" value="holder"><button class="btn sm line" style="color:var(--danger)" title="<?= e(t('Remove a duplicate or credential that does not apply')) ?>">✕</button></form>
            <?php endif; ?>
          <?php else: ?>
            <a class="btn sm gold" href="<?= BASE_PATH ?>/credentials/new?holder=<?= e($hid) ?>&type=<?= e($r['add_type']) ?>&name=<?= rawurlencode($r['label']) ?>&return=holder"><?= e(t('Add document')) ?></a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($canWrite): ?>
  <div style="margin-top:.9rem;display:flex;gap:.5rem;flex-wrap:wrap">
    <a class="btn sm line" href="<?= BASE_PATH ?>/credentials/new?holder=<?= e($hid) ?>&return=holder"><?= e(t('+ Add another requirement')) ?></a>
    <a class="btn sm line" href="<?= BASE_PATH ?>/holders/<?= e($hid) ?>/package?pick=1"><?= e(t('Credential package (PDF)')) ?></a>
  </div>
  <p class="hint" style="margin:.5rem 0 0;font-size:.72rem"><?= e(t('Required credentials come from this profession\'s template. Adding a document with its expiry date checks the item off automatically.')) ?></p>
  <?php endif; ?>
  <?php endif; ?>
</div>
