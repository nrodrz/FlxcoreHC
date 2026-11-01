<?php /** Office contact form. Expects: $caction, $cform (row or null). */ $c = $cform ?? []; ?>
<form method="post" action="<?= e($caction) ?>" class="stack" style="max-width:640px">
  <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
  <div class="grid2">
    <div><label><?= e(t('Full name')) ?></label><input name="full_name" value="<?= e($c['full_name'] ?? '') ?>" required></div>
    <div><label><?= e(t('Role / title')) ?></label><input name="role_title" value="<?= e($c['role_title'] ?? '') ?>" placeholder="<?= e(t('Office manager, Credentialing coordinator, Billing…')) ?>"></div>
  </div>
  <div class="grid2">
    <div><label><?= e(t('Email')) ?></label><input type="email" name="email" value="<?= e($c['email'] ?? '') ?>"></div>
    <div><label><?= e(t('Phone')) ?></label><input name="phone" value="<?= e($c['phone'] ?? '') ?>"></div>
  </div>
  <div class="grid2">
    <div><label><?= e(t('Preferred language')) ?></label><select name="preferred_language"><option value="">—</option><option value="en" <?= ($c['preferred_language'] ?? '')==='en'?'selected':'' ?>>English</option><option value="es" <?= ($c['preferred_language'] ?? '')==='es'?'selected':'' ?>>Español</option></select></div>
    <div><label><?= e(t('Notes')) ?></label><input name="notes" value="<?= e($c['notes'] ?? '') ?>" maxlength="300"></div>
  </div>
  <div style="display:flex;gap:1rem;flex-wrap:wrap">
    <label style="font-weight:normal"><input type="checkbox" name="gets_document_requests" value="1" <?= ($c === [] || !empty($c['gets_document_requests']))?'checked':'' ?>> <?= e(t('Receives document requests')) ?></label>
    <label style="font-weight:normal"><input type="checkbox" name="gets_reminders" value="1" <?= !empty($c['gets_reminders'])?'checked':'' ?>> <?= e(t('Receives expiry reminders')) ?></label>
    <label style="font-weight:normal"><input type="checkbox" name="is_primary" value="1" <?= !empty($c['is_primary'])?'checked':'' ?>> <?= e(t('Primary contact')) ?></label>
  </div>
  <div><button class="btn sm gold"><?= e(!empty($c['id']) ? t('Save contact') : t('Add contact')) ?></button></div>
</form>
