<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** Shared address list. Expects: $addresses, $base (post url base for {id}/delete|primary), $canEdit. */
$kinds = FlxCoreHC\Repository\AddressRepository::KINDS; ?>
<?php if ($addresses === []): ?><p class="hint"><?= e(t('No addresses yet.')) ?></p>
<?php else: ?>
<table style="font-size:.82rem">
  <tr><th><?= e(t('Type')) ?></th><th><?= e(t('Address')) ?></th><th><?= e(t('Phone')) ?></th><th></th></tr>
  <?php foreach ($addresses as $a): ?>
  <tr>
    <td data-label="<?= e(t('Type')) ?>"><strong><?= e(t($kinds[$a['kind']] ?? $a['kind'])) ?></strong><?php if ($a['label']): ?> <span class="hint"><?= e($a['label']) ?></span><?php endif; ?><?php if (!empty($a['is_primary'])): ?> <span class="chip active" style="font-size:.58rem"><?= e(t('primary')) ?></span><?php endif; ?></td>
    <td data-label="<?= e(t('Address')) ?>"><?= e(FlxCoreHC\Repository\AddressRepository::oneLine($a)) ?><?php if ($a['effective_to']): ?><div class="hint" style="font-size:.7rem"><?= e(t('until')) ?> <?= e($a['effective_to']) ?></div><?php endif; ?></td>
    <td class="hint" data-label="<?= e(t('Phone')) ?>"><?= e($a['phone'] ?: '—') ?></td>
    <td class="right" style="white-space:nowrap">
      <?php if ($canEdit): ?>
        <details style="display:inline-block"><summary class="btn sm line" style="display:inline-block;cursor:pointer"><?= e(t('Edit')) ?></summary>
          <div style="margin:.5rem 0"><?php $action = $base . '/' . $a['id']; $__a_saved = $a; require FLXCOREHC_APP . '/views/_address_form.php'; $a = $__a_saved; ?></div></details>
        <?php if (empty($a['is_primary'])): ?><form method="post" action="<?= e($base) ?>/<?= e($a['id']) ?>/primary" style="display:inline"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm line"><?= e(t('Make primary')) ?></button></form><?php endif; ?>
        <form method="post" action="<?= e($base) ?>/<?= e($a['id']) ?>/delete" style="display:inline" data-confirm="<?= e(t('Remove this address?')) ?>"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm line">✕</button></form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>
