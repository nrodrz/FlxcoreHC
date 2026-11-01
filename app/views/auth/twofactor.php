<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ $title = 'Two-factor verification'; $byEmail = !empty($byEmail); ?>
<div class="auth-card">
    <h1 style="margin-bottom:.2rem"><?= e(t('Two-step verification')) ?></h1>
    <?php if ($byEmail): ?>
      <p class="hint" style="margin-top:0"><?= e(t('We e-mailed a 6-digit code to {email}. Enter it below. It expires in 10 minutes.', ['email' => $emailHint ?? ''])) ?></p>
    <?php else: ?>
      <p class="hint" style="margin-top:0"><?= e(t('Open your authenticator app and enter the current 6-digit code for this account.')) ?></p>
    <?php endif; ?>

    <?php if ($__fm = flash()): ?><div class="<?= ($GLOBALS['flx_flash_last_type'] ?? 'ok') === 'error' ? 'error' : 'flash' ?>" role="status"><?= e(t($__fm)) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="error" role="alert" style="margin-bottom:1rem"><?= e(t($error)) ?></div><?php endif; ?>

    <form method="post" action="<?= BASE_PATH ?>/login/verify" class="stack">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <label for="code"><?= e(t($byEmail ? 'Code from the e-mail' : 'Authentication code')) ?></label>
      <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required
             placeholder="123456" style="font-size:1.4rem;letter-spacing:.35em;text-align:center;font-family:ui-monospace,monospace">
      <button class="btn" style="width:100%"><?= e(t('Verify and sign in')) ?></button>
    </form>

    <?php if ($byEmail): ?>
    <form method="post" action="<?= BASE_PATH ?>/login/verify" style="margin-top:.8rem">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="resend">
      <button class="btn line" style="width:100%"><?= e(t('Send a new code')) ?></button>
    </form>
    <?php endif; ?>

    <p class="hint" style="margin-top:1rem">
      <?= e(t($byEmail ? 'No e-mail? Check the spam folder, or enter one of your backup codes in the box above — each one works once.'
                       : 'Lost your phone? Enter one of your backup codes in the box above — each one works once.')) ?>
      <br><a href="<?= BASE_PATH ?>/logout"><?= e(t('Cancel and sign out')) ?></a>
    </p>
  <?php include __DIR__ . '/_lang.php'; ?>
</div>
