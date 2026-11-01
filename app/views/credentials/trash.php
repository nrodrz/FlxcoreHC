<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var array $rows */
$title = 'Deleted credentials';
?>
<div class="crumb"><a href="<?= BASE_PATH ?>/credentials"><?= e(t('Credentials')) ?></a></div>
<div class="topline"><h1><?= e(t('Deleted credentials')) ?></h1>
  <a class="btn line sm" href="<?= BASE_PATH ?>/credentials"><?= e(t('Back to active')) ?></a></div>
<p class="hint" style="max-width:80ch"><?= e(t('Soft-deleted credentials (duplicates, or ones that did not apply to the client). Restore to bring one back, or permanently delete it (also removes its documents, evidence links and verification records).')) ?></p>

<div class="panel">
  <?php if ($rows === []): ?><div class="empty"><?= e(t('Nothing in Deleted credentials.')) ?></div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th><?= e(t('Credential')) ?></th><th><?= e(t('Provider')) ?></th><th><?= e(t('Deleted')) ?></th><th></th></tr>
    <?php foreach ($rows as $r):
      $who = ($r['holder_type'] ?? '') === 'organization' ? ($r['org_name'] ?: '—') : trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? '')); ?>
    <tr>
      <td><strong><?= e($r['name'] ?: $r['credential_type']) ?></strong> <span class="mono hint" style="font-size:.7rem"><?= e((string)$r['credential_type']) ?></span></td>
      <td class="hint"><?= e($who ?: '—') ?></td>
      <td class="mono hint" style="white-space:nowrap"><?= e(fmt_dt((string)($r['deleted_at'] ?? ''), 'm/d/Y g:i A')) ?></td>
      <td class="right" style="white-space:nowrap">
        <form method="post" action="<?= BASE_PATH ?>/credentials/<?= e($r['id']) ?>/restore" style="display:inline;margin:0"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
          <button class="btn sm gold"><?= e(t('Restore')) ?></button></form>
        <details style="display:inline-block;margin-left:.3rem">
          <summary class="btn sm line" style="display:inline-block;color:var(--danger)"><?= e(t('Delete permanently')) ?></summary>
          <form method="post" action="<?= BASE_PATH ?>/credentials/<?= e($r['id']) ?>/purge" class="stack" style="margin-top:.4rem;max-width:360px;text-align:left" data-confirm="<?= e(t('Permanently delete this credential and its documents/evidence? Cannot be undone.')) ?>"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
            <label style="font-size:.78rem"><?= e(t('Type PERMANENTLY DELETE to confirm')) ?>
              <input name="confirm" autocomplete="off" placeholder="PERMANENTLY DELETE"></label>
            <button class="btn sm danger"><?= e(t('Permanently delete')) ?></button>
          </form>
        </details>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
