<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */
/** @var array $loc */
/** @var array $providers */
$title = $loc['name'];
$displayName = function(array $h): string {
    if (($h['holder_type'] ?? '') === 'organization') return (string)($h['org_name'] ?? 'Organization');
    return trim(((string)($h['first_name'] ?? '')) . ' ' . ((string)($h['last_name'] ?? '')));
};
?>
<div class="wrap">
  <div class="crumb"><a href="<?= BASE_PATH ?>/locations"><?= e(t('Locations')) ?></a></div>
  <div class="topline"><h1><?= e($loc['name']) ?>
    <?php if ($loc['status'] !== 'active'): ?><span class="chip"><?= e(ucfirst($loc['status'])) ?></span><?php endif; ?></h1>
    <a class="btn sm line" href="<?= BASE_PATH ?>/locations?edit=<?= e($loc['id']) ?>"><?= e(t('Edit')) ?></a></div>

  <div class="panel" style="margin-bottom:1rem">
    <div class="sub">
      <?php $addr = array_filter([$loc['address1'] ?? '', $loc['address2'] ?? '', trim(($loc['city'] ?? '') . ' ' . ($loc['state'] ?? '') . ' ' . ($loc['zip'] ?? ''))]); ?>
      <?= $addr ? e(implode(' · ', $addr)) : '<span class="hint">' . e(t('No address on file')) . '</span>' ?>
      <?php if (!empty($loc['npi'])): ?> · NPI <span class="mono"><?= e($loc['npi']) ?></span><?php endif; ?>
      <?php if (!empty($loc['phone'])): ?> · <?= e($loc['phone']) ?><?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <div class="topline"><h2 style="margin-top:0"><?= e(t('Providers at this location')) ?></h2>
      <span class="hint"><?= count($providers) ?></span></div>
    <?php if ($providers === []): ?>
      <p class="hint"><?= e(t('No providers affiliated yet. Affiliate them from each provider\'s page.')) ?></p>
    <?php else: ?>
    <table>
      <tr><th><?= e(t('Name')) ?></th><th><?= e(t('Type')) ?></th><th></th></tr>
      <?php foreach ($providers as $h): ?>
      <tr>
        <td><strong><a href="<?= BASE_PATH ?>/holders/<?= e($h['id']) ?>"><?= e($displayName($h)) ?></a></strong></td>
        <td class="sub"><?= e(t($h['holder_type'] === 'organization' ? 'Organization' : 'Individual')) ?></td>
        <td class="right"><a class="btn sm line" href="<?= BASE_PATH ?>/holders/<?= e($h['id']) ?>"><?= e(t('Open')) ?></a></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</div>
