<?php /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $provider */ /** @var array $tenant */
$title = 'Custom fields'; $__path = '/organization/custom-fields'; require FLXCOREHC_APP . '/views/org/_tabs.php';
$types = FlxCoreHC\CustomFieldService::TYPES;
$list = function (array $defs, string $scope) use ($types) { ?>
  <?php if ($defs === []): ?><p class="hint"><?= e(t('No fields yet.')) ?></p><?php else: ?>
  <table style="font-size:.82rem">
    <tr><th><?= e(t('Label')) ?></th><th><?= e(t('Type')) ?></th><th><?= e(t('Required')) ?></th><th><?= e(t('Shown in')) ?></th><th></th></tr>
    <?php foreach ($defs as $d): ?>
    <tr style="<?= $d['active'] ? '' : 'opacity:.55' ?>">
      <td data-label="<?= e(t('Label')) ?>"><strong><?= e($d['label']) ?></strong> <span class="mono hint"><?= e($d['code']) ?></span><?php if (!$d['active']): ?> <span class="chip" style="font-size:.58rem"><?= e(t('retired')) ?></span><?php endif; ?><?php if (!empty($d['is_sensitive'])): ?> <span class="chip warn" style="font-size:.58rem"><?= e(t('sensitive')) ?></span><?php endif; ?>
        <?php if ($d['help_text']): ?><div class="hint" style="font-size:.7rem"><?= e($d['help_text']) ?></div><?php endif; ?></td>
      <td class="hint" data-label="<?= e(t('Type')) ?>"><?= e(t($types[$d['field_type']] ?? $d['field_type'])) ?><?= $d['field_type']==='select' ? ' <span class="hint">(' . count($d['options']) . ')</span>' : '' ?></td>
      <td data-label="<?= e(t('Required')) ?>"><?= $d['is_required'] ? '✓' : '—' ?></td>
      <td class="hint" data-label="<?= e(t('Shown in')) ?>"><?= implode(', ', array_filter([$d['show_in_packet'] ? t('packet') : '', $d['show_in_list'] ? t('list') : ''])) ?: '—' ?></td>
      <td class="right" style="white-space:nowrap">
        <details style="display:inline-block"><summary class="btn sm line" style="display:inline-block;cursor:pointer"><?= e(t('Edit')) ?></summary>
          <div style="margin:.5rem 0;text-align:left"><?php $fd = $d; $faction = BASE_PATH . '/organization/custom-fields/' . $d['id']; $fscope = $scope; require FLXCOREHC_APP . '/views/org/_custom_field_form.php'; ?></div></details>
        <?php if ($d['active']): ?><form method="post" action="<?= BASE_PATH ?>/organization/custom-fields/<?= e($d['id']) ?>/retire" style="display:inline" data-confirm="<?= e(t('Retire this field? Existing values are kept but it no longer appears on forms.')) ?>"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm line"><?= e(t('Retire')) ?></button></form><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
<?php }; ?>
<div class="topline"><h1><?= e(t('Custom fields')) ?></h1></div>
<p class="hint" style="max-width:820px"><?= e(t('Add your own fields to provider records or to the organization — no developer needed. Fields appear on the forms, in profile packets and exports. Retiring a field hides it without losing what was entered.')) ?></p>

<div class="panel">
  <h2 style="margin-top:0;font-size:1rem"><?= e(t('Provider fields')) ?></h2>
  <?php $list($provider, 'provider'); ?>
  <details style="margin-top:.6rem"><summary class="btn sm gold" style="display:inline-block;cursor:pointer"><?= e(t('Add provider field')) ?></summary>
    <div style="margin-top:.5rem"><?php $fd = null; $faction = BASE_PATH . '/organization/custom-fields'; $fscope = 'provider'; require FLXCOREHC_APP . '/views/org/_custom_field_form.php'; ?></div></details>
</div>
<div class="panel" style="margin-top:1rem">
  <h2 style="margin-top:0;font-size:1rem"><?= e(t('Organization fields')) ?></h2>
  <?php $list($tenant, 'tenant'); ?>
  <details style="margin-top:.6rem"><summary class="btn sm gold" style="display:inline-block;cursor:pointer"><?= e(t('Add organization field')) ?></summary>
    <div style="margin-top:.5rem"><?php $fd = null; $faction = BASE_PATH . '/organization/custom-fields'; $fscope = 'tenant'; require FLXCOREHC_APP . '/views/org/_custom_field_form.php'; ?></div></details>
</div>
