<?php
/** Shared address form. Expects: $action (post url), $a (existing row or null). */
$a = $a ?? []; $kinds = FlxCoreHC\Repository\AddressRepository::KINDS; ?>
<form method="post" action="<?= e($action) ?>" class="stack" style="max-width:640px">
  <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
  <div class="grid2">
    <div><label><?= e(t('Type')) ?></label><select name="kind"><?php foreach ($kinds as $k=>$l): ?><option value="<?= $k ?>" <?= ($a['kind'] ?? 'mailing')===$k?'selected':'' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></div>
    <div><label><?= e(t('Label')) ?> <span class="hint">(<?= e(t('optional')) ?>)</span></label><input name="label" value="<?= e($a['label'] ?? '') ?>" placeholder="<?= e(t('e.g. Bayamón office, PO Box')) ?>"></div>
  </div>
  <div><label><?= e(t('Street')) ?></label><input name="line1" value="<?= e($a['line1'] ?? '') ?>" required></div>
  <div><label><?= e(t('Suite / line 2')) ?></label><input name="line2" value="<?= e($a['line2'] ?? '') ?>"></div>
  <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:.5rem">
    <div><label><?= e(t('City')) ?></label><input name="city" value="<?= e($a['city'] ?? '') ?>" required></div>
    <div><label><?= e(t('State')) ?></label><input name="state" value="<?= e($a['state'] ?? 'PR') ?>" maxlength="8" required></div>
    <div><label><?= e(t('ZIP')) ?></label><input name="zip" value="<?= e($a['zip'] ?? '') ?>" maxlength="12"></div>
    <div><label><?= e(t('Country')) ?></label><input name="country" value="<?= e($a['country'] ?? 'US') ?>" maxlength="2"></div>
  </div>
  <div class="grid2">
    <div><label><?= e(t('Phone')) ?></label><input name="phone" value="<?= e($a['phone'] ?? '') ?>"></div>
    <div><label><?= e(t('Fax')) ?></label><input name="fax" value="<?= e($a['fax'] ?? '') ?>"></div>
  </div>
  <div class="grid2">
    <div><label><?= e(t('Effective from')) ?></label><input type="date" name="effective_from" value="<?= e($a['effective_from'] ?? '') ?>"></div>
    <div><label><?= e(t('Effective to')) ?></label><input type="date" name="effective_to" value="<?= e($a['effective_to'] ?? '') ?>"></div>
  </div>
  <div><label><?= e(t('Notes')) ?></label><input name="notes" value="<?= e($a['notes'] ?? '') ?>" maxlength="300"></div>
  <div><label style="font-weight:normal"><input type="checkbox" name="is_primary" value="1" <?= !empty($a['is_primary'])?'checked':'' ?>> <?= e(t('Primary address')) ?></label></div>
  <div><button class="btn sm gold"><?= e(!empty($a['id']) ? t('Save address') : t('Add address')) ?></button></div>
</form>
