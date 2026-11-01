<?php
/** @var FlxCoreHC\TenantContext $ctx */
/** @var array $rows locations with provider_count */
/** @var array|null $edit */
$title = 'Locations';
$e = $edit ?? null;
?>
<div class="wrap">
  <div class="topline"><h1><?= e(t('Locations')) ?></h1></div>
  <p class="hint"><?= e(t('Practice sites this organization manages. Affiliate providers with the locations where they practice; one can be marked their primary site.')) ?></p>

  <div class="panel">
    <?php if ($rows === []): ?>
      <p class="hint"><?= e(t('No locations yet. Add your first practice site below.')) ?></p>
    <?php else: ?>
    <table>
      <tr><th><?= e(t('Name')) ?></th><th><?= e(t('City')) ?></th><th>NPI</th><th class="right"><?= e(t('Providers')) ?></th><th><?= e(t('Status')) ?></th><th></th></tr>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><strong><a href="<?= BASE_PATH ?>/locations/<?= e($r['id']) ?>"><?= e($r['name']) ?></a></strong>
          <?php if (!empty($r['note'])): ?><span class="sub"><?= e($r['note']) ?></span><?php endif; ?></td>
        <td><?= e(trim(($r['city'] ?? '') . (($r['city'] ?? '') && ($r['state'] ?? '') ? ', ' : '') . ($r['state'] ?? ''))) ?: '—' ?></td>
        <td class="mono"><?= e($r['npi'] ?: '—') ?></td>
        <td class="right"><?= (int)$r['provider_count'] ?></td>
        <td><span class="chip <?= $r['status'] === 'active' ? 'active' : '' ?>"><?= e(ucfirst($r['status'])) ?></span></td>
        <td class="right"><a class="btn sm line" href="<?= BASE_PATH ?>/locations?edit=<?= e($r['id']) ?>"><?= e(t('Edit')) ?></a>
          <form method="post" action="<?= BASE_PATH ?>/locations" style="display:inline" data-confirm="Remove this location?">
            <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e($r['id']) ?>">
            <button class="btn sm line"><?= e(t('Delete')) ?></button></form></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <div class="panel" style="margin-top:1.2rem">
    <h2 style="margin-top:0"><?= $e ? e(t('Edit location')) : e(t('Add a location')) ?></h2>
    <form method="post" action="<?= BASE_PATH ?>/locations" class="stack" style="max-width:760px">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="action" value="<?= $e ? 'update' : 'create' ?>">
      <?php if ($e): ?><input type="hidden" name="id" value="<?= e($e['id']) ?>"><?php endif; ?>
      <div style="display:flex;gap:.6rem;flex-wrap:wrap">
        <div style="flex:2;min-width:220px"><label><?= e(t('Name')) ?></label><input name="name" required maxlength="160" value="<?= e($e['name'] ?? '') ?>" style="width:100%"></div>
        <div style="flex:1;min-width:120px"><label>NPI <span class="hint">(<?= e(t('optional')) ?>)</span></label><input name="npi" maxlength="10" value="<?= e($e['npi'] ?? '') ?>" style="width:100%"></div>
      </div>
      <div style="display:flex;gap:.6rem;flex-wrap:wrap">
        <div style="flex:2;min-width:220px"><label><?= e(t('Address')) ?></label><input name="address1" value="<?= e($e['address1'] ?? '') ?>" placeholder="Street" style="width:100%"></div>
        <div style="flex:1;min-width:120px"><label>&nbsp;</label><input name="address2" value="<?= e($e['address2'] ?? '') ?>" placeholder="Suite / unit" style="width:100%"></div>
      </div>
      <div style="display:flex;gap:.6rem;flex-wrap:wrap">
        <div style="flex:2;min-width:160px"><label><?= e(t('City')) ?></label><input name="city" value="<?= e($e['city'] ?? '') ?>" style="width:100%"></div>
        <div style="flex:1;min-width:70px"><label><?= e(t('State')) ?></label><input name="state" maxlength="2" value="<?= e($e['state'] ?? '') ?>" placeholder="PR" style="width:100%"></div>
        <div style="flex:1;min-width:100px"><label>ZIP</label><input name="zip" maxlength="10" value="<?= e($e['zip'] ?? '') ?>" style="width:100%"></div>
        <div style="flex:1;min-width:130px"><label><?= e(t('Phone')) ?></label><input name="phone" value="<?= e($e['phone'] ?? '') ?>" style="width:100%"></div>
      </div>
      <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:end">
        <div><label><?= e(t('Status')) ?></label>
          <select name="status">
            <option value="active" <?= ($e['status'] ?? 'active') === 'active' ? 'selected' : '' ?>><?= e(t('Active')) ?></option>
            <option value="inactive" <?= ($e['status'] ?? '') === 'inactive' ? 'selected' : '' ?>><?= e(t('Inactive')) ?></option>
          </select></div>
        <div style="flex:1;min-width:200px"><label><?= e(t('Note')) ?></label><input name="note" maxlength="255" value="<?= e($e['note'] ?? '') ?>" style="width:100%"></div>
      </div>
      <div><button class="btn"><?= $e ? e(t('Save location')) : e(t('Add location')) ?></button>
        <?php if ($e): ?><a class="btn line" href="<?= BASE_PATH ?>/locations"><?= e(t('Cancel')) ?></a><?php endif; ?></div>
    </form>
  </div>
</div>
