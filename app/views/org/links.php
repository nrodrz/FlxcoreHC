<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */ /** @var array $links */
$title = 'Linked providers';
$stChip = ['pending_owner'=>'warn','active'=>'active','pending_provider'=>'','declined'=>'','revoked'=>'danger'];
$stLabel = ['pending_owner'=>'Needs your approval','active'=>'Active','pending_provider'=>'Waiting for the provider','declined'=>'Declined','revoked'=>'Revoked'];
$byStatus = []; foreach ($links as $l) { $byStatus[$l['status']][] = $l; }
?>
<?php $__path = '/organization/links'; require FLXCOREHC_APP . '/views/org/_tabs.php'; ?>
<div class="topline"><h1><?= e(t('Linked providers')) ?></h1></div>
<p class="hint" style="max-width:820px"><?= e(t('When the same provider is managed by another organization on FlxCoreHC, records can be linked so credentials do not have to be entered twice. A link becomes active only after three consents — the requesting Master Admin, the provider, and you. Once linked, sharing is one credential at a time, read-only, and can be withdrawn by anyone at any time.')) ?></p>

<?php if (!empty($byStatus['pending_owner'])): ?>
<div class="panel" style="border-left:3px solid var(--warn,#c47f1a);margin-bottom:1rem">
  <h2 style="margin-top:0;font-size:1rem"><?= e(t('Needs your approval')) ?> (<?= count($byStatus['pending_owner']) ?>)</h2>
  <p class="hint"><?= e(t('The provider has already consented. Approving lets you share credentials with this organization, one by one — nothing is shared automatically.')) ?></p>
  <table style="font-size:.85rem">
    <?php foreach ($byStatus['pending_owner'] as $l): ?>
    <tr>
      <td data-label="<?= e(t('Organization')) ?>"><strong><?= e($l['requester_name']) ?></strong> <span class="hint"><?= e(t('asks to link a provider you manage')) ?></span>
        <div class="hint" style="font-size:.72rem"><?= e(t('Provider consented')) ?> <?= e(fmt_dt($l['provider_consent_at'], 'm/d/Y')) ?></div></td>
      <td class="right" style="white-space:nowrap">
        <a class="btn sm line" href="<?= BASE_PATH ?>/holders/<?= e($l['my_holder_id']) ?>"><?= e(t('View provider')) ?></a>
        <form method="post" action="<?= BASE_PATH ?>/organization/links/<?= e($l['id']) ?>/approve" style="display:inline"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm gold"><?= e(t('Approve link')) ?></button></form>
        <form method="post" action="<?= BASE_PATH ?>/organization/links/<?= e($l['id']) ?>/decline" style="display:inline"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm line"><?= e(t('Decline')) ?></button></form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<div class="panel">
  <h2 style="margin-top:0;font-size:1rem"><?= e(t('All links')) ?></h2>
  <?php if ($links === []): ?><p class="hint"><?= e(t('No links yet. A link starts from a provider\'s page when another organization also manages them.')) ?></p>
  <?php else: ?>
  <table style="font-size:.84rem">
    <tr><th><?= e(t('Other organization')) ?></th><th><?= e(t('Your role')) ?></th><th><?= e(t('Status')) ?></th><th><?= e(t('Since')) ?></th><th></th></tr>
    <?php foreach ($links as $l): $other = $l['my_role'] === 'owner' ? $l['requester_name'] : $l['owner_name']; ?>
    <tr>
      <td data-label="<?= e(t('Other organization')) ?>"><strong><?= e($other) ?></strong></td>
      <td class="hint" data-label="<?= e(t('Your role')) ?>"><?= e($l['my_role'] === 'owner' ? t('They asked to link') : t('You asked to link')) ?></td>
      <td data-label="<?= e(t('Status')) ?>"><span class="chip <?= $stChip[$l['status']] ?? '' ?>"><?= e(t($stLabel[$l['status']] ?? $l['status'])) ?></span></td>
      <td class="hint" style="white-space:nowrap" data-label="<?= e(t('Since')) ?>"><?= e(fmt_dt($l['requested_at'], 'm/d/Y')) ?></td>
      <td class="right" style="white-space:nowrap">
        <a class="btn sm line" href="<?= BASE_PATH ?>/holders/<?= e($l['my_holder_id']) ?>"><?= e(t('Provider')) ?></a>
        <?php if (in_array($l['status'], ['active','pending_provider','pending_owner'], true)): ?>
          <form method="post" action="<?= BASE_PATH ?>/organization/links/<?= e($l['id']) ?>/revoke" style="display:inline"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm line" data-confirm="<?= e(t('Revoke this link? All credentials shared on it stop being visible immediately.')) ?>"><?= e(t('Revoke')) ?></button></form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
