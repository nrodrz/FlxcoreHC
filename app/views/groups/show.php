<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */ /** @var array $group */ /** @var array $members */ /** @var string $glabel */
$title = $group['name'];
$dn = function(array $h): string {
    if (($h['holder_type'] ?? '') === 'organization') return (string)($h['org_name'] ?? 'Organization');
    return trim(((string)($h['first_name'] ?? '')) . ' ' . ((string)($h['last_name'] ?? '')));
};
?>
<div class="wrap">
  <div class="crumb"><a href="<?= BASE_PATH ?>/groups"><?= e(t($glabel)) ?></a></div>
  <div class="topline"><h1><?= e($group['name']) ?>
    <?php if ($group['status'] !== 'active'): ?><span class="chip"><?= e(ucfirst($group['status'])) ?></span><?php endif; ?></h1>
    <a class="btn sm line" href="<?= BASE_PATH ?>/groups?edit=<?= e($group['id']) ?>"><?= e(t('Edit')) ?></a></div>
  <?php if (!empty($group['code']) || !empty($group['npi'])): ?>
  <div class="panel" style="margin-bottom:1rem"><div class="sub">
    <?php if (!empty($group['code'])): ?><?= e(t('Code')) ?>: <span class="mono"><?= e($group['code']) ?></span><?php endif; ?>
    <?php if (!empty($group['npi'])): ?> · NPI <span class="mono"><?= e($group['npi']) ?></span><?php endif; ?>
  </div></div>
  <?php endif; ?>
  <div class="panel">
    <div class="topline"><h2 style="margin-top:0"><?= e(t('Providers in this {what}', ['what' => strtolower(t($glabel))])) ?></h2><span class="hint"><?= count($members) ?></span></div>
    <?php if ($members === []): ?>
      <p class="hint"><?= e(t('No providers yet. Add them from each provider\'s page.')) ?></p>
    <?php else: ?>
    <table>
      <tr><th><?= e(t('Name')) ?></th><th><?= e(t('Type')) ?></th><th></th></tr>
      <?php foreach ($members as $h): ?>
      <tr><td><strong><a href="<?= BASE_PATH ?>/holders/<?= e($h['id']) ?>"><?= e($dn($h)) ?></a></strong></td>
        <td class="sub"><?= e(t($h['holder_type'] === 'organization' ? 'Organization' : 'Individual')) ?></td>
        <td class="right"><a class="btn sm line" href="<?= BASE_PATH ?>/holders/<?= e($h['id']) ?>"><?= e(t('Open')) ?></a></td></tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</div>
