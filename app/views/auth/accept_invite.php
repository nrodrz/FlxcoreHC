<?php /** @var array|null $inv */ /** @var string $token */ /** @var string|null $error */
$title = 'Accept invitation';
?>
<div class="auth-card" style="max-width:440px">
<?php
  // Signed-out pages carry the agency's own logo and name when we can identify them.
  $__b = $GLOBALS['flx_brand'] ?? [];
  $__bn = (string) ($__b['name'] ?? 'FlxCoreHC');
  $__bl = $__b['logo'] ?? null;
?>
  <div class="brand"><?php if ($__bl): ?><img class="brand-logo" src="<?= e($__bl) ?>" alt="<?= e($__bn) ?>" style="height:30px;width:auto;border-radius:8px">
    <?php else: ?><span class="mark"><?= e(mb_strtoupper(mb_substr($__bn, 0, 2))) ?></span><?php endif; ?> <?= e($__bn) ?></div>
  <?php if (!$inv): ?>
    <h1>Invitation not found</h1>
    <p class="hint">This invitation link is invalid or has expired. Ask your administrator to send a new one.</p>
    <a class="btn line" href="<?= BASE_PATH ?>/login">Go to sign in</a>
  <?php else: ?>
    <h1><?= e(t('Welcome, {name}', ['name' => $inv['full_name']])) ?></h1>
    <p class="hint"><?= str_replace('{org}', '<strong>' . e($inv['org_name']) . '</strong>', e(t("You've been invited to {org}. Set a password to activate your account ({email}).", ['org' => '{org}', 'email' => $inv['email']]))) ?></p>
    <?php if (!empty($error)): ?><div class="flash" style="background:#fbe9e7;color:#be4b42"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= BASE_PATH ?>/invite/<?= e($token) ?>" class="stack">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <div><label>Choose a password</label><input type="password" name="password" required minlength="10" placeholder="At least 10 characters"></div>
      <div><button class="btn gold">Activate account</button></div>
    </form>
  <?php endif; ?>
  <?php include __DIR__ . '/_lang.php'; ?>
</div>
