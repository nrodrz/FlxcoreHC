<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ $title = vocab('holders');
$heading = 'Providers & organizations';
?>
<div class="crumb"><?= e($ctx->tenantName) ?></div>
<div class="topline">
  <h1><?= e(t($heading)) ?></h1>
  <span style="display:flex;gap:.5rem">
    <a class="btn line" href="<?= BASE_PATH ?>/holders/onboard" title="Step-by-step: who, identifiers, requirements, portal access"><?= e(t('Guided onboarding')) ?></a>
    <a class="btn line" href="<?= BASE_PATH ?>/holders/import" title="<?= e(t('Add many providers at once from a spreadsheet')) ?>"><?= e(t('Import CSV')) ?></a>
    <?php if (FlxCoreHC\Authz::can($ctx, 'records.delete')): ?><a class="btn line" href="<?= BASE_PATH ?>/holders/trash"><?= e(t('Deleted records')) ?></a><?php endif; ?>
    <a class="btn gold" href="<?= BASE_PATH ?>/holders/new"><?= e(t('Add')) ?> <?= e(strtolower(vocab('holder'))) ?></a>
  </span>
</div>
<div class="panel">
<?php
  $allGroups = $allGroups ?? []; $allLocations = $allLocations ?? [];
  $activeGroup = $activeGroup ?? null; $activeLocation = $activeLocation ?? null;
  $groupLabelPlural = $groupLabelPlural ?? 'Groups';
  $isFiltered = $activeGroup || $activeLocation;
  $showFilters = $allGroups !== [] || $allLocations !== [];
?>
<?php if ($showFilters): ?>
  <form method="get" action="<?= BASE_PATH ?>/holders" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.8rem">
    <?php if ($allGroups !== []): ?>
    <select name="group" data-autosubmit style="max-width:220px">
      <option value=""><?= e(t('All')) ?> <?= e(strtolower($groupLabelPlural)) ?></option>
      <?php foreach ($allGroups as $g): ?><option value="<?= e($g['id']) ?>" <?= ($activeGroup['id'] ?? '') === $g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php if ($allLocations !== []): ?>
    <select name="location" data-autosubmit style="max-width:220px">
      <option value=""><?= e(t('All locations')) ?></option>
      <?php foreach ($allLocations as $l): ?><option value="<?= e($l['id']) ?>" <?= ($activeLocation['id'] ?? '') === $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <noscript><button class="btn sm"><?= e(t('Filter')) ?></button></noscript>
    <?php if ($isFiltered): ?>
      <span class="hint"><?= count($rows) ?> <?= e(t('shown')) ?><?php
        if ($activeGroup): ?> · <?= e($activeGroup['name']) ?><?php endif;
        if ($activeLocation): ?> · <?= e($activeLocation['name']) ?><?php endif; ?></span>
      <a class="btn sm line" href="<?= BASE_PATH ?>/holders"><?= e(t('Clear')) ?></a>
    <?php endif; ?>
  </form>
<?php endif; ?>
<?php if ($rows === [] && $isFiltered): ?>
  <div class="empty"><?= e(t('No providers match this filter.')) ?>
    <a href="<?= BASE_PATH ?>/holders"><?= e(t('Clear filter')) ?></a></div>
<?php elseif ($rows === []): ?>
  <div class="empty">No <?= e(vocab('holders_lc')) ?> yet.
    Add the physicians, groups, labs, and clinics whose credentials you track.
    <br><a class="btn gold" href="<?= BASE_PATH ?>/holders/onboard"><?= e(t('Start guided onboarding')) ?></a>
    <a class="btn line" style="margin-left:.5rem" href="<?= BASE_PATH ?>/holders/new"><?= e(t('Quick add')) ?></a></div>
<?php else: ?>
  <table>
    <tr><th><?= e(t('Name')) ?></th><th><?= e(t('Type')) ?></th><th>NPI</th><th class="right"><?= e(t('Credentials')) ?></th><th><?= e(t('Next expiry')) ?></th><th></th></tr>
    <?php foreach ($rows as $h): ?>
    <tr>
      <td><a href="<?= BASE_PATH ?>/holders/<?= e($h['id']) ?>"><strong><?= e(FlxCoreHC\Repository\HolderRepository::displayName($h)) ?></strong></a>
        <?php if ($h['role_title']): ?><span class="sub"><?= e($h['role_title']) ?></span><?php endif; ?></td>
      <td><?= e(t($h['holder_type'] === 'organization' ? 'Organization' : 'Individual')) ?></td>
      <td class="mono"><?= e(($h['npi'] ?? '') ?: '—') ?><?php if (!empty($h['npi_verified_at'])): ?> <span class="chip active" style="font-size:.62rem" title="NPI verified against CMS">✓</span><?php endif; ?></td>
      <td class="right mono"><?= (int) $h['credential_count'] ?>
        <?php if ((int) $h['expired_count'] > 0): ?> <span class="chip expired"><?= (int)$h['expired_count'] ?> <?= e(t('expired')) ?></span>
        <?php elseif ((int) $h['expiring_count'] > 0): ?> <span class="chip expiring"><?= (int)$h['expiring_count'] ?> <?= e(t('expiring')) ?></span><?php endif; ?></td>
      <td class="mono"><?= e(!empty($h['next_expiry']) ? fmt_day($h['next_expiry']) : '—') ?></td>
      <td class="right"><a class="btn sm line" href="<?= BASE_PATH ?>/credentials/new?holder=<?= e($h['id']) ?>"><?= e(t('Add credential')) ?></a></td>
    </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>
</div>
