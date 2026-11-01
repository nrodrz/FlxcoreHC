<?php
/** @var FlxCoreHC\TenantContext $ctx */ /** @var array $row */ /** @var array $pending */ /** @var array $links */ /** @var bool $hasNpi */
$title = 'Linked organizations';
$stLabel = ['pending_provider'=>'Waiting for you','pending_owner'=>'Waiting for the other organization','active'=>'Active','declined'=>'Declined','revoked'=>'Revoked'];
$stChip = ['pending_provider'=>'warn','pending_owner'=>'','active'=>'active','declined'=>'','revoked'=>'danger'];
$active = array_filter($links, fn($l) => in_array($l['status'], ['active','pending_owner'], true));
?>
<div class="wrap">
  <div class="crumb"><a href="<?= BASE_PATH ?>/me">← <?= e(t('My credentials')) ?></a></div>
  <div class="topline"><h1><?= e(t('Linked organizations')) ?></h1></div>
  <p class="hint" style="max-width:760px"><?= e(t('Several organizations may manage your credentials. Linking their records means a renewal entered by one can be shared with the others — only the credentials each organization chooses, read-only, and only after you approve. You are the anchor: nothing links without your consent, and you can withdraw it at any time.')) ?></p>
  <?php if (!$hasNpi): ?><div class="panel"><p class="hint"><?= e(t('Your record has no NPI on file yet, so linking is not available.')) ?></p></div><?php else: ?>

  <div class="panel" style="<?= $pending ? 'border-left:3px solid var(--warn,#c47f1a)' : '' ?>">
    <h2 style="margin-top:0;font-size:1rem"><?= e(t('Waiting for your consent')) ?> (<?= count($pending) ?>)</h2>
    <?php if (!$pending): ?><p class="hint"><?= e(t('No pending requests.')) ?></p>
    <?php else: foreach ($pending as $l): ?>
      <div style="border:1px solid var(--line,#e3e1da);border-radius:8px;padding:.7rem .8rem;margin-bottom:.6rem">
        <div><strong><?= e($l['requester_name']) ?></strong> <?= e(t('asks to link your records with')) ?> <strong><?= e($l['owner_name']) ?></strong>.</div>
        <div class="hint" style="font-size:.78rem;margin:.2rem 0 .5rem"><?= e(t('If you approve, {owner} will be asked to confirm, and then may share specific credentials with {req} — read-only, one at a time. You can withdraw later.', ['owner' => $l['owner_name'], 'req' => $l['requester_name']])) ?></div>
        <form method="post" action="<?= BASE_PATH ?>/me/links/<?= e($l['id']) ?>/approve" style="display:inline"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm gold"><?= e(t('I consent')) ?></button></form>
        <form method="post" action="<?= BASE_PATH ?>/me/links/<?= e($l['id']) ?>/decline" style="display:inline"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm line"><?= e(t('Decline')) ?></button></form>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="panel" style="margin-top:1rem">
    <div class="topline"><h2 style="margin:0;font-size:1rem"><?= e(t('Your links')) ?></h2>
      <?php if ($active): ?>
      <form method="post" action="<?= BASE_PATH ?>/me/links/withdraw-all" style="margin:0"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
        <button class="btn sm line" data-confirm="<?= e(t('Withdraw consent from ALL links? Every organization stops seeing anything shared. This cannot be undone (they would have to ask again).')) ?>"><?= e(t('Withdraw all consent')) ?></button></form>
      <?php endif; ?></div>
    <?php if (!$links): ?><p class="hint"><?= e(t('No links yet.')) ?></p>
    <?php else: ?>
    <table style="font-size:.84rem">
      <tr><th><?= e(t('Between')) ?></th><th><?= e(t('Status')) ?></th><th><?= e(t('Requested')) ?></th></tr>
      <?php foreach ($links as $l): ?>
      <tr>
        <td data-label="<?= e(t('Between')) ?>"><?= e($l['requester_name']) ?> ↔ <?= e($l['owner_name']) ?></td>
        <td data-label="<?= e(t('Status')) ?>"><span class="chip <?= $stChip[$l['status']] ?? '' ?>"><?= e(t($stLabel[$l['status']] ?? $l['status'])) ?></span></td>
        <td class="hint" style="white-space:nowrap" data-label="<?= e(t('Requested')) ?>"><?= e(fmt_dt($l['requested_at'], 'm/d/Y')) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
