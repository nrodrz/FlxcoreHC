<?php
/** @var FlxCoreHC\TenantContext $ctx */ /** @var array $row */ /** @var array $share */ /** @var array $log */
$name = FlxCoreHC\Repository\HolderRepository::displayName($row);
$title = 'Share access log — ' . $name;
$evLabel = ['view'=>'Viewed profile','download'=>'Downloaded document','password_failed'=>'Wrong password','locked'=>'Locked (too many attempts)','expired'=>'Attempted after expiry','blocked'=>'Attempted (blocked)'];
$evColor = ['view'=>'active','download'=>'active','password_failed'=>'warn','locked'=>'danger','expired'=>'','blocked'=>''];
?>
<div class="crumb"><a href="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>#external">← <?= e($name) ?></a></div>
<div class="topline"><h1><?= e(t('Share access log')) ?></h1></div>
<div class="panel" style="margin-bottom:1rem">
  <table data-no-stack style="font-size:.86rem">
    <tr><th style="width:10rem"><?= e(t('Recipient')) ?></th><td><?= e($share['recipient_name']) ?> <span class="hint"><?= e(FlxCoreHC\ExternalShareService::TYPES[$share['recipient_type']] ?? '') ?></span></td></tr>
    <tr><th><?= e(t('Created')) ?></th><td><?= e(fmt_dt($share['created_at'], 'm/d/Y g:i A')) ?></td></tr>
    <tr><th><?= e(t('Expires')) ?></th><td><?= e(fmt_dt($share['expires_at'], 'm/d/Y g:i A')) ?><?php if ($share['revoked_at']): ?> · <span class="chip danger"><?= e(t('Revoked')) ?> <?= e(fmt_dt($share['revoked_at'], 'm/d/Y')) ?></span><?php endif; ?></td></tr>
    <tr><th><?= e(t('Views')) ?></th><td><?= (int)$share['view_count'] ?><?= $share['max_views'] ? ' / ' . (int)$share['max_views'] : '' ?></td></tr>
  </table>
</div>
<div class="panel">
  <h3 style="margin-top:0;font-size:.92rem"><?= e(t('Every access')) ?> (<?= count($log) ?>)</h3>
  <?php if ($log === []): ?><p class="hint"><?= e(t('Not accessed yet.')) ?></p>
  <?php else: ?>
  <table style="font-size:.8rem">
    <tr><th><?= e(t('When')) ?></th><th><?= e(t('Event')) ?></th><th><?= e(t('IP address')) ?></th><th><?= e(t('Browser')) ?></th></tr>
    <?php foreach ($log as $l): ?>
    <tr>
      <td class="mono hint" style="white-space:nowrap" data-label="<?= e(t('When')) ?>"><?= e(fmt_dt($l['created_at'], 'm/d/Y g:i:s A')) ?></td>
      <td data-label="<?= e(t('Event')) ?>"><span class="chip <?= $evColor[$l['event']] ?? '' ?>"><?= e(t($evLabel[$l['event']] ?? $l['event'])) ?></span></td>
      <td class="mono" data-label="<?= e(t('IP address')) ?>"><?= e($l['ip_address'] ?? '—') ?></td>
      <td class="hint" style="font-size:.72rem;max-width:280px;overflow:hidden;text-overflow:ellipsis" data-label="<?= e(t('Browser')) ?>"><?= e($l['user_agent'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
