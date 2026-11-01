<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** Expects $faction, $fd (row|null), $fscope ('provider'|'tenant'). */ $d = $fd ?? []; $types = FlxCoreHC\CustomFieldService::TYPES; ?>
<form method="post" action="<?= e($faction) ?>" class="stack" style="max-width:600px">
  <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
  <input type="hidden" name="applies_to" value="<?= e($fscope) ?>">
  <div class="grid2">
    <div><label><?= e(t('Label')) ?></label><input name="label" value="<?= e($d['label'] ?? '') ?>" required></div>
    <div><label><?= e(t('Key')) ?> <span class="hint">(<?= e(t('optional, auto from label')) ?>)</span></label><input name="code" value="<?= e($d['code'] ?? '') ?>" <?= !empty($d['id']) ? 'readonly' : '' ?> placeholder="e.g. medical_school"></div>
  </div>
  <div class="grid2">
    <div><label><?= e(t('Type')) ?></label><select name="field_type"><?php foreach ($types as $k=>$l): ?><option value="<?= $k ?>" <?= ($d['field_type'] ?? 'text')===$k?'selected':'' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></div>
    <div><label><?= e(t('Order')) ?></label><input type="number" name="sort_order" value="<?= (int)($d['sort_order'] ?? 0) ?>"></div>
  </div>
  <div><label><?= e(t('Help text')) ?></label><input name="help_text" value="<?= e($d['help_text'] ?? '') ?>" maxlength="240"></div>
  <div><label><?= e(t('Options (for choice lists — one per line)')) ?></label><textarea name="options" rows="3"><?= e(implode("\n", $d['options'] ?? [])) ?></textarea></div>
  <div style="display:flex;gap:1rem;flex-wrap:wrap">
    <label style="font-weight:normal"><input type="checkbox" name="is_required" value="1" <?= !empty($d['is_required'])?'checked':'' ?>> <?= e(t('Required')) ?></label>
    <label style="font-weight:normal"><input type="checkbox" name="show_in_packet" value="1" <?= ($d === [] || !empty($d['show_in_packet']))?'checked':'' ?>> <?= e(t('Show in profile packet')) ?></label>
    <label style="font-weight:normal"><input type="checkbox" name="show_in_list" value="1" <?= !empty($d['show_in_list'])?'checked':'' ?>> <?= e(t('Show in lists')) ?></label>
    <label style="font-weight:normal;color:var(--warn,#c47f1a)"><input type="checkbox" name="is_sensitive" value="1" <?= !empty($d['is_sensitive'])?'checked':'' ?>> <?= e(t('Sensitive — encrypt, mask unless permitted, never export')) ?></label>
  </div>
  <div><button class="btn sm gold"><?= e(!empty($d['id']) ? t('Save field') : t('Add field')) ?></button></div>
</form>
