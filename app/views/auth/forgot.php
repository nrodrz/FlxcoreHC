<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ $title = 'Forgot password';
  $__b = $GLOBALS['flx_brand'] ?? []; $__bn = (string) ($__b['name'] ?? 'FlxCoreHC'); ?>
<div class="auth-card">
  <div class="brand"><span class="mark"><?= e(mb_strtoupper(mb_substr($__bn, 0, 2))) ?></span> <?= e($__bn) ?></div>
  <h1 style="margin:.6rem 0 .2rem;font-size:1.25rem"><?= e(t('Forgot your password?')) ?></h1>
  <p class="hint" style="margin-top:0"><?= e(t('Enter the e-mail address you sign in with. If it belongs to an active account, we will send a link to choose a new password.')) ?></p>
  <?php if ($__fm = flash()): ?><div class="<?= ($GLOBALS['flx_flash_last_type'] ?? 'ok') === 'error' ? 'error' : 'flash' ?>" role="status"><?= e(t($__fm)) ?></div><?php endif; ?>
  <form method="post" action="<?= BASE_PATH ?>/forgot">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <div><label for="email"><?= e(t('Email')) ?></label>
      <input id="email" type="email" name="email" required autofocus autocomplete="username"></div>
    <button class="btn gold"><?= e(t('Send the link')) ?></button>
  </form>
  <p class="hint" style="margin-top:1rem"><?= e(t('The link works once and expires in 60 minutes. It does not skip two-step verification. If no message arrives, check the spam folder, or ask your organization\'s administrator to set a new password for you (Team → Set password).')) ?></p>
  <div class="auth-alt"><a href="<?= BASE_PATH ?>/login"><?= e(t('Back to sign in')) ?></a></div>
  <?php include __DIR__ . '/_lang.php'; ?>
</div>
