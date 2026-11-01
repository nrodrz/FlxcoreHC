<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** Public Terms of Use page (reached from the sign-in page). */
$title = 'Terms of Use';
?>
<div class="auth-card" style="max-width:760px">
  <div class="brand"><span class="mark"><?= e(mb_strtoupper(mb_substr(FlxCoreHC\Ownership::PRODUCT, 0, 2))) ?></span> <?= e(FlxCoreHC\Ownership::PRODUCT) ?></div>
  <h1 style="font-size:1.25rem;margin:.6rem 0"><?= e(t('Terms of Use')) ?></h1>
  <?php require __DIR__ . '/_body.php'; ?>
  <p class="hint"><?= e(FlxCoreHC\Ownership::SIGNIN_STATEMENT) ?> <?= e(FlxCoreHC\Ownership::copyright()) ?></p>
  <div class="auth-alt"><a href="<?= BASE_PATH ?>/login"><?= e(t('Back to sign in')) ?></a></div>
</div>
