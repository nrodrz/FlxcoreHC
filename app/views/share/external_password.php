<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var string $token */ /** @var ?string $error */ /** @var string $recipient */ $title = 'Secure profile share'; ?>
<?php require __DIR__ . '/_lang.php'; ?>
<div class="auth-card" style="max-width:440px;margin:3rem auto">
  <h1 style="font-size:1.2rem;margin:0 0 .3rem"><?= e(t('Secure profile share')) ?></h1>
  <p class="hint" style="margin:0 0 1rem"><?= e(t('This link was prepared for')) ?> <strong><?= e($recipient) ?></strong>. <?= e(t('Enter the password you were given separately to view the profile.')) ?></p>
  <?php if (!empty($error)): ?><div class="error"><?= e(t((string)$error)) ?></div><?php endif; ?>
  <form method="post" action="<?= BASE_PATH ?>/s/<?= e($token) ?>" class="stack">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <div><label><?= e(t('Password')) ?></label><input type="password" name="password" required autofocus autocomplete="off"></div>
    <div><button class="btn"><?= e(t('View profile')) ?></button></div>
  </form>
  <p class="hint" style="font-size:.72rem;margin-top:1rem"><?= e(t('Access is logged. The link expires automatically and can be revoked by the organization that shared it.')) ?></p>
</div>
