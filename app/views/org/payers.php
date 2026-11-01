<?php /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $payers */
$title = 'Payers'; $__path = '/organization/payers'; require FLXCOREHC_APP . '/views/org/_tabs.php';
$kinds = ['commercial'=>'Commercial','medicare_advantage'=>'Medicare Advantage plan','medicaid_mco'=>'Medicaid MCO','government'=>'Government','other'=>'Other']; ?>
<div class="topline"><h1><?= e(t('Payers')) ?></h1></div>
<p class="hint" style="max-width:760px"><?= e(t('Insurers and plans you credential providers with. Puerto Rico payers are pre-loaded; add any others your organization works with. Each provider\'s page tracks their status with each payer.')) ?></p>
<div class="grid2" style="gap:1rem">
  <div class="panel">
    <table style="font-size:.85rem"><tr><th><?= e(t('Payer')) ?></th><th><?= e(t('Type')) ?></th><th></th></tr>
      <?php foreach ($payers as $p): ?>
      <tr><td><strong><?= e($p['name']) ?></strong><?php if ($p['short_code']): ?> <span class="mono hint"><?= e($p['short_code']) ?></span><?php endif; ?></td>
          <td class="hint"><?= e(t($kinds[$p['kind']] ?? $p['kind'])) ?></td>
          <td class="hint"><?= $p['tenant_id'] ? e(t('yours')) : e(t('pre-loaded')) ?></td></tr>
      <?php endforeach; ?></table>
  </div>
  <div class="panel">
    <h2 style="margin-top:0;font-size:1rem"><?= e(t('Add a payer')) ?></h2>
    <form method="post" action="<?= BASE_PATH ?>/organization/payers" class="stack"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <div><label><?= e(t('Name')) ?></label><input name="name" required></div>
      <div><label><?= e(t('Type')) ?></label><select name="kind"><?php foreach ($kinds as $k=>$l): ?><option value="<?= $k ?>"><?= e(t($l)) ?></option><?php endforeach; ?></select></div>
      <div><button class="btn"><?= e(t('Add payer')) ?></button></div>
    </form>
  </div>
</div>
