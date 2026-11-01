<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var array $rows */
$title = 'Deleted records';
?>
<div class="crumb"><a href="<?= BASE_PATH ?>/holders"><?= e(t('Providers')) ?></a></div>
<div class="topline"><h1><?= e(t('Deleted records')) ?></h1>
  <a class="btn line sm" href="<?= BASE_PATH ?>/holders"><?= e(t('Back to active records')) ?></a></div>
<p class="hint" style="max-width:80ch"><?= e(t('Soft-deleted providers and organizations. Restore any record to bring it back exactly as it was, or permanently delete it (irreversible). Permanent delete also removes that record\'s credentials, documents, requirements, verifications and links.')) ?></p>

<div class="panel">
  <?php if ($rows === []): ?><div class="empty"><?= e(t('Nothing in Deleted records.')) ?></div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th><?= e(t('Name')) ?></th><th><?= e(t('Type')) ?></th><th><?= e(t('Credentials')) ?></th><th><?= e(t('Deleted')) ?></th><th></th></tr>
    <?php foreach ($rows as $r):
      $name = ($r['holder_type'] ?? '') === 'organization' ? ($r['org_name'] ?: '—') : trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? '')); ?>
    <tr>
      <td><strong><?= e($name ?: '—') ?></strong></td>
      <td class="hint"><?= e((string)($r['holder_type'] ?? '')) ?><?= !empty($r['provider_type']) ? ' · '.e($r['provider_type']) : '' ?></td>
      <td class="hint"><?= (int)($r['credential_count'] ?? 0) ?></td>
      <td class="mono hint" style="white-space:nowrap"><?= e(fmt_dt((string)($r['deleted_at'] ?? ''), 'm/d/Y g:i A')) ?></td>
      <td class="right" style="white-space:nowrap">
        <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($r['id']) ?>/restore" style="display:inline;margin:0"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
          <button class="btn sm gold"><?= e(t('Restore')) ?></button></form>
        <details style="display:inline-block;margin-left:.3rem">
          <summary class="btn sm line" style="display:inline-block;color:var(--danger)"><?= e(t('Delete permanently')) ?></summary>
          <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($r['id']) ?>/purge" class="stack" style="margin-top:.4rem;max-width:360px;text-align:left" data-confirm="<?= e(t('This permanently deletes the record and all its data. This cannot be undone.')) ?>"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
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
