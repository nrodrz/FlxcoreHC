<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ ?>
<div class="auth-card">
<?php
  // Signed-out pages carry the agency's own logo and name when we can identify them.
  $__b = $GLOBALS['flx_brand'] ?? [];
  $__bn = (string) ($__b['name'] ?? 'FlxCoreHC');
  $__bl = $__b['logo'] ?? null;
?>
  <div class="brand"><?php if ($__bl): ?><img class="brand-logo" src="<?= e($__bl) ?>" alt="<?= e($__bn) ?>" style="height:30px;width:auto;border-radius:8px">
    <?php else: ?><span class="mark"><?= e(mb_strtoupper(mb_substr($__bn, 0, 2))) ?></span><?php endif; ?> <?= e($__bn) ?></div>
  <div class="tag"><?= e(FLXCOREHC_TAGLINE) ?></div>
  <?php if ($__fm = flash()): ?><div class="<?= ($GLOBALS['flx_flash_last_type'] ?? 'ok') === 'error' ? 'error' : 'flash' ?>" role="status"><?= e(t($__fm)) ?></div><?php endif; ?>
  <?php if (!empty($error)): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= BASE_PATH ?>/login">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <div><label for="email">Email</label>
      <input id="email" type="email" name="email" value="<?= e($email ?? '') ?>" required autofocus></div>
    <div><label for="password">Password</label>
      <input id="password" type="password" name="password" required></div>
    <button class="btn gold">Sign in</button>
  </form>
  <div class="auth-alt"><a href="<?= BASE_PATH ?>/forgot"><?= e(t('Forgot your password?')) ?></a></div>
  <?php if (config('allow_open_registration', false)): ?><div class="auth-alt"><?= e(t('New here?')) ?> <a href="<?= BASE_PATH ?>/register"><?= e(t('Create your organization')) ?></a></div><?php endif; ?>
  <?php include __DIR__ . '/_lang.php'; ?>
  <div class="auth-alt auth-owner" style="margin-top:.9rem;font-size:.74rem;opacity:.8;text-align:center">
    <div><?= e(FlxCoreHC\Ownership::poweredBy()) ?></div>
    <div><?= e(t(FlxCoreHC\Ownership::SIGNIN_STATEMENT)) ?> · <a href="<?= BASE_PATH ?>/terms"><?= e(t('Terms of Use')) ?></a></div>
  </div>
</div>
