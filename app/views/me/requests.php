<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */ /** @var array $row */ /** @var array $requests */
$title = 'Document requests';
$open = array_filter($requests, fn($r) => in_array($r['status'], ['open','returned'], true));
$done = array_filter($requests, fn($r) => !in_array($r['status'], ['open','returned'], true));
$stChip = ['open'=>'warn','submitted'=>'','returned'=>'danger','accepted'=>'active','cancelled'=>''];
?>
<div class="wrap">
  <div class="crumb"><a href="<?= BASE_PATH ?>/me">← <?= e(t('My credentials')) ?></a></div>
  <div class="topline"><h1><?= e(t('Document requests')) ?></h1><?php if ($open): ?><span class="chip warn"><?= count($open) ?> <?= e(t('waiting for you')) ?></span><?php endif; ?></div>
  <p class="hint"><?= e(t('Your organization asks for documents here. Upload them directly — photos from your phone work. Once accepted, the organization manages the document; you keep a read-only record of what you provided.')) ?></p>

  <div class="panel">
    <h2 style="margin-top:0;font-size:1rem"><?= e(t('Waiting for you')) ?> (<?= count($open) ?>)</h2>
    <?php if (!$open): ?><p class="hint"><?= e(t('Nothing pending — you are all caught up.')) ?></p>
    <?php else: foreach ($open as $r): ?>
      <div style="border:1px solid var(--line,#e3e1da);border-radius:8px;padding:.7rem .8rem;margin-bottom:.6rem">
        <div style="display:flex;justify-content:space-between;gap:.5rem;flex-wrap:wrap;align-items:center">
          <div><strong><?= e($r['requested_kind']) ?></strong><?php if ($r['credential_name']): ?> <span class="hint">→ <?= e($r['credential_name']) ?></span><?php endif; ?>
            <div class="hint" style="font-size:.75rem"><?= e(t('Requested')) ?> <?= e(fmt_dt($r['requested_at'], 'm/d/Y')) ?><?php if ($r['due_at']): ?> · <?= e(t('needed by')) ?> <?= e($r['due_at']) ?><?php endif; ?></div></div>
          <span class="chip <?= $stChip[$r['status']] ?>"><?= e($r['status']==='returned' ? t('Returned — please resubmit') : t('Open')) ?></span>
        </div>
        <?php if ($r['message']): ?><div style="margin:.4rem 0;font-size:.85rem">💬 <?= e($r['message']) ?></div><?php endif; ?>
        <?php if ($r['status']==='returned' && $r['returned_note']): ?><div class="hint" style="margin:.3rem 0;font-size:.82rem;color:var(--danger,#be4b42)"><?= e($r['returned_note']) ?></div><?php endif; ?>
        <form method="post" action="<?= BASE_PATH ?>/me/requests/<?= e($r['id']) ?>/submit" enctype="multipart/form-data" style="margin-top:.5rem" data-doc-upload>
          <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
          <div style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center">
            <label class="btn sm line" style="cursor:pointer;margin:0"><?= e(t('Choose file')) ?><input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.heic,.heif,.docx,image/*,application/pdf" hidden data-doc-file></label>
            <label class="btn sm line" style="cursor:pointer;margin:0"><?= e(t('Take photo')) ?><input type="file" accept="image/*" capture="environment" hidden data-doc-camera></label>
            <button type="button" class="btn sm line" data-doc-scan><?= e(t('Scan (multi-page)')) ?></button>
            <button class="btn sm gold" data-doc-submit disabled><?= e(t('Upload')) ?></button>
          </div>
          <div class="hint" data-doc-status style="margin-top:.3rem;font-size:.75rem"><?= e(t('PDF, JPG, PNG or HEIC up to 10 MB.')) ?></div>
          <div data-doc-preview style="display:none;margin-top:.4rem"></div>
        </form>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <?php if ($done): ?>
  <div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1rem"><?= e(t('Submitted')) ?></h2>
    <table style="font-size:.82rem">
      <tr><th><?= e(t('Document')) ?></th><th><?= e(t('Submitted')) ?></th><th><?= e(t('Status')) ?></th></tr>
      <?php foreach ($done as $r): ?>
      <tr>
        <td data-label="<?= e(t('Document')) ?>"><?= e($r['requested_kind']) ?><?php if ($r['document_name']): ?><div class="hint" style="font-size:.72rem">📎 <?= e($r['document_name']) ?></div><?php endif; ?></td>
        <td class="hint" style="white-space:nowrap" data-label="<?= e(t('Submitted')) ?>"><?= $r['submitted_at'] ? e(fmt_dt($r['submitted_at'], 'm/d/Y')) : '—' ?></td>
        <td data-label="<?= e(t('Status')) ?>"><span class="chip <?= $stChip[$r['status']] ?? '' ?>"><?= e($r['status']==='accepted' ? t('Accepted — read only') : ($r['status']==='submitted' ? t('Awaiting review') : ucfirst((string)$r['status']))) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>
</div>
<script <?= nonce_attr() ?> src="<?= BASE_PATH ?>/assets/docscan.js?v=<?= e(FLXCOREHC_VERSION) ?>"></script>
<script <?= nonce_attr() ?> src="<?= BASE_PATH ?>/assets/docscanner.js?v=<?= e(FLXCOREHC_VERSION) ?>"></script>
