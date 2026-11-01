<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** Public Terms of Use page (reached from the sign-in page). */
$title = 'Terms of Use';
?>
<div class="auth-card" style="max-width:640px">
  <div class="brand"><span class="mark"><?= e(mb_strtoupper(mb_substr(FlxCoreHC\Ownership::PRODUCT, 0, 2))) ?></span> <?= e(FlxCoreHC\Ownership::PRODUCT) ?></div>
  <h1 style="font-size:1.25rem;margin:.6rem 0"><?= e(t('Terms of Use')) ?></h1>
  <p class="hint" style="margin:.2rem 0 .8rem"><?= e(t('DRAFT — the full Terms of Use are being finalised and will be published here.')) ?></p>
  <p><?= e(t('Until they are published, each organization uses {product} under its own written agreement with the owner.', ['product' => FlxCoreHC\Ownership::p()])) ?></p>
  <p><?= e(FlxCoreHC\Ownership::SIGNIN_STATEMENT) ?> <?= e(FlxCoreHC\Ownership::copyright()) ?></p>
  <div class="auth-alt"><a href="<?= BASE_PATH ?>/login"><?= e(t('Back to sign in')) ?></a></div>
</div>
