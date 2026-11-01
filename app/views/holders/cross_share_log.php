<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var array $row */ /** @var string $shareId */ /** @var array $log */
$name = FlxCoreHC\Repository\HolderRepository::displayName($row); $title = 'Share access log — ' . $name; ?>
<div class="crumb"><a href="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>#crossorg">← <?= e($name) ?></a></div>
<div class="topline"><h1><?= e(t('Cross-organization share — access log')) ?></h1></div>
<div class="panel">
  <?php if (!$log): ?><p class="hint"><?= e(t('Not accessed yet.')) ?></p><?php else: ?>
  <table style="font-size:.8rem">
    <tr><th><?= e(t('When')) ?></th><th><?= e(t('Organization')) ?></th><th><?= e(t('User')) ?></th><th><?= e(t('Event')) ?></th><th><?= e(t('IP address')) ?></th></tr>
    <?php foreach ($log as $l): ?>
    <tr><td class="mono hint" style="white-space:nowrap"><?= e(fmt_dt($l['created_at'], 'm/d/Y g:i:s A')) ?></td>
        <td><?= e($l['recipient_name']) ?></td><td><?= e($l['user_name'] ?? '—') ?></td>
        <td><span class="chip <?= $l['event']==='download'?'warn':'active' ?>"><?= e(t($l['event']==='download' ? 'Downloaded document' : 'Viewed')) ?></span></td>
        <td class="mono"><?= e($l['ip_address'] ?? '—') ?></td></tr>
    <?php endforeach; ?>
  </table><?php endif; ?>
</div>
