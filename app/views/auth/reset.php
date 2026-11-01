<?php $title = 'Choose a new password'; /** @var array $acct */ /** @var string $token */
  $__b = $GLOBALS['flx_brand'] ?? []; $__bn = (string) ($__b['name'] ?? 'FlxCoreHC'); ?>
<div class="auth-card">
  <div class="brand"><span class="mark"><?= e(mb_strtoupper(mb_substr($__bn, 0, 2))) ?></span> <?= e($__bn) ?></div>
  <h1 style="margin:.6rem 0 .2rem;font-size:1.25rem"><?= e(t('Choose a new password')) ?></h1>
  <p class="hint" style="margin-top:0"><?= e(t('Account: {email} at {org}.', ['email' => FlxCoreHC\Auth\AuthService::maskEmail((string) ($acct['email'] ?? '')), 'org' => (string) ($acct['tenant_name'] ?? '')])) ?></p>
  <?php if (!empty($error)): ?><div class="error" role="alert" style="margin-bottom:1rem"><?= e(t($error)) ?></div><?php endif; ?>
  <form method="post" action="<?= BASE_PATH ?>/reset/<?= e($token) ?>">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <div><label for="new"><?= e(t('New password')) ?></label>
      <input id="new" type="password" name="new" required minlength="10" autocomplete="new-password" autofocus placeholder="<?= e(t('At least 10 characters')) ?>"></div>
    <div><label for="confirm"><?= e(t('Confirm new password')) ?></label>
      <input id="confirm" type="password" name="confirm" required minlength="10" autocomplete="new-password"></div>
    <button class="btn gold"><?= e(t('Save new password')) ?></button>
  </form>
  <p class="hint" style="margin-top:1rem"><?= e(t('After saving you will sign in with the new password. Every other open session of this account is signed out.')) ?></p>
  <?php include __DIR__ . '/_lang.php'; ?>
</div>
