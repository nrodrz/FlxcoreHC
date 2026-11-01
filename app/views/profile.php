<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var FlxCoreHC\TenantContext $ctx */
$title='Your profile';
$planFeat = FlxCoreHC\PlanService::forTenant($ctx->tenantId);
?>
<div class="crumb"><?= e($ctx->tenantName) ?></div>
<div class="topline"><h1>Your profile</h1></div>

<div class="cols-2">
<div>

<div class="panel">
  <div class="topline"><h2>Details</h2></div>
  <form class="stack" method="post" action="<?= BASE_PATH ?>/profile">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="profile">
    <div><label>Full name</label><input name="full_name" value="<?= e($ctx->userName) ?>" required></div>
    <div><label>Role</label><input value="<?= e(FlxCoreHC\TeamService::ROLES[$ctx->role] ?? $ctx->role) ?>" disabled></div>
    <div><label>Organization</label><input value="<?= e($ctx->tenantName) ?>" disabled></div>
    <?php if (in_array($ctx->role, ['tenant_owner','manager','admin'], true)): $digest = $digest ?? 'off'; ?>
    <div><label><?= e(t('Activity digest email')) ?></label>
      <select name="activity_digest">
        <option value="off" <?= $digest==='off'?'selected':'' ?>><?= e(t('Off')) ?></option>
        <option value="daily" <?= $digest==='daily'?'selected':'' ?>><?= e(t('Daily summary')) ?></option>
        <option value="weekly" <?= $digest==='weekly'?'selected':'' ?>><?= e(t('Weekly summary (Mondays)')) ?></option>
      </select>
      <div class="hint"><?= e(t('A short email of your organization\'s activity — providers added, credentials updated, documents verified, and who was active. Sent only on days with activity.')) ?></div></div>
    <?php endif; ?>
    <div><button class="btn">Save profile</button></div>
  </form>
</div>

<div class="panel">
  <div class="topline"><h2>Change password</h2></div>
  <form class="stack" method="post" action="<?= BASE_PATH ?>/profile">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="password">
    <div><label>Current password</label><input type="password" name="current" required></div>
    <div><label>New password</label><input type="password" name="new" required minlength="10" placeholder="At least 10 characters"></div>
    <div><button class="btn">Update password</button></div>
  </form>
</div>


<?php // ---- Two-factor authentication ------------------------------------------------
$twoOn = !empty($ctx->twoFactor) || !empty($backupCodes);   // backup codes are only shown right after a successful enrolment
$mfaMethod    = FlxCoreHC\Auth\AuthService::mfaMethodFor($ctx->userId);                 // 'totp' | 'email' | null (off)
$emailAllowed = FlxCoreHC\Auth\AuthService::emailCodesAllowed();
$emailReady   = $emailAllowed && FlxCoreHC\MailService::canSendFor($ctx->tenantId);
$maskedEmail  = FlxCoreHC\Auth\AuthService::maskEmail((string) ($ctx->userEmail ?? ''));
$errBox = static fn(string $m): string => '<div class="error" role="alert" style="margin-bottom:.8rem">' . e(t($m)) . '</div>';
?>
</div>

<div>
<div class="panel" id="two-factor" style="border-left:3px solid <?= $twoOn ? 'var(--ok)' : 'var(--accent)' ?>">
  <div class="topline"><h2><?= e(t('Two-factor authentication')) ?></h2>
    <span class="chip <?= $twoOn ? 'active' : '' ?>"><?= $twoOn ? e(t('On')) : e(t('Off')) ?></span></div>

  <?php if (!empty($backupCodes)): ?>
    <div class="alert" style="background:var(--ok-bg,#e8f6ee);border-left:3px solid var(--ok)">
      <strong><?= e(t('Two-factor is on. Save these backup codes now — they will not be shown again.')) ?></strong>
      <p class="hint" style="margin:.4rem 0"><?= e(t('Each code signs you in once if you lose your phone or cannot read your e-mail. Print them or store them in a password manager.')) ?></p>
      <div class="mono" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:.4rem;margin-top:.6rem">
        <?php foreach ($backupCodes as $bc): ?><span style="padding:.35rem .5rem;background:var(--surface-2);border-radius:5px;text-align:center;letter-spacing:.05em"><?= e($bc) ?></span><?php endforeach; ?>
      </div>
    </div>

  <?php elseif (!empty($enrol)): /* ---- authenticator app: scan the QR code, or type the setup key ---- */ ?>
    <?php if (!empty($error)) echo $errBox($error); ?>
    <p class="hint" style="margin-top:0"><?= e(t('Add this account to an authenticator app (Google Authenticator, Microsoft Authenticator, Authy, or 1Password), then enter the 6-digit code it shows.')) ?></p>
    <div style="display:flex;gap:1.4rem;flex-wrap:wrap;align-items:flex-start">
      <div style="text-align:center">
        <?php $__qr = FlxCoreHC\QrCode::svg($enrol['uri'], 200, t('QR code to add this account to an authenticator app')); ?>
        <?php if ($__qr !== ''): ?>
          <div data-qr="totp" style="display:inline-block;padding:6px;background:#fff;border:1px solid var(--line,#e3e1da);border-radius:8px;line-height:0"><?= $__qr ?></div>
          <div class="hint" style="margin-top:.35rem"><strong><?= e(t('1. Scan this code with your app')) ?></strong></div>
        <?php endif; ?>
      </div>
      <div style="flex:1;min-width:240px">
        <label class="hint" style="font-weight:600"><?= e(t('Cannot scan? Setup key — type this into your app')) ?></label>
        <div class="mono" style="font-size:1.05rem;letter-spacing:.12em;padding:.7rem .9rem;background:var(--surface-2);border-radius:7px;word-break:break-all;margin:.3rem 0 .5rem">
          <?= e(trim(chunk_split($enrol['secret'], 4, ' '))) ?>
        </div>
        <p class="hint" style="margin:0 0 .8rem"><?= e(t('On a phone?')) ?> <a href="<?= e($enrol['uri']) ?>"><?= e(t('Tap here to add it automatically ↗')) ?></a></p>
        <form class="stack" method="post" action="<?= BASE_PATH ?>/profile" style="max-width:260px">
          <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="2fa_confirm">
          <label><strong><?= e(t('2. Code from your app')) ?></strong></label>
          <input name="code" inputmode="numeric" autocomplete="one-time-code" required placeholder="123456"
                 style="font-size:1.2rem;letter-spacing:.3em;text-align:center;font-family:ui-monospace,monospace">
          <button class="btn"><?= e(t('Turn on two-factor')) ?></button>
        </form>
      </div>
    </div>

    <div style="margin-top:1rem" data-help="authenticators">
      <div class="hint" style="font-weight:600;margin-bottom:.3rem"><?= e(t('Step by step for your app')) ?></div>
      <details style="margin:.25rem 0"><summary style="cursor:pointer"><strong>Google Authenticator</strong></summary>
        <ol class="hint" style="margin:.4rem 0 .2rem 1.1rem;line-height:1.55">
          <li><?= e(t('Open Google Authenticator and tap the + button.')) ?></li>
          <li><?= e(t('Choose “Scan a QR code” and point the camera at the code on this page.')) ?></li>
          <li><?= e(t('No camera? Choose “Enter a setup key”, type a name for the account, type the setup key shown here, and leave “Time based” selected.')) ?></li>
          <li><?= e(t('Type the 6-digit code the app now shows into “Code from your app”.')) ?></li>
        </ol></details>
      <details style="margin:.25rem 0"><summary style="cursor:pointer"><strong>Microsoft Authenticator</strong></summary>
        <ol class="hint" style="margin:.4rem 0 .2rem 1.1rem;line-height:1.55">
          <li><?= e(t('Open Microsoft Authenticator and tap + (Add account).')) ?></li>
          <li><?= e(t('Choose “Other account (Google, Facebook, etc.)”.')) ?></li>
          <li><?= e(t('Scan the code on this page. No camera? Tap “Or enter code manually”, type a name for the account and the setup key.')) ?></li>
          <li><?= e(t('Type the 6-digit code the app now shows into “Code from your app”.')) ?></li>
        </ol></details>
      <details style="margin:.25rem 0"><summary style="cursor:pointer"><strong>Authy</strong></summary>
        <ol class="hint" style="margin:.4rem 0 .2rem 1.1rem;line-height:1.55">
          <li><?= e(t('Open Authy on your phone or tablet and tap + (Add account).')) ?></li>
          <li><?= e(t('Tap “Scan QR Code” and scan the code on this page, or tap “Enter key manually” and type the setup key.')) ?></li>
          <li><?= e(t('Give the account a name and save it.')) ?></li>
          <li><?= e(t('Type the 6-digit code the app now shows into “Code from your app”.')) ?></li>
        </ol></details>
      <details style="margin:.25rem 0"><summary style="cursor:pointer"><strong>1Password</strong></summary>
        <ol class="hint" style="margin:.4rem 0 .2rem 1.1rem;line-height:1.55">
          <li><?= e(t('Open (or create) the login item for this site in 1Password and choose Edit.')) ?></li>
          <li><?= e(t('Add a “One-Time Password” field to the item.')) ?></li>
          <li><?= e(t('Scan the code on this page with the scan button in that field, or paste the setup key into it.')) ?></li>
          <li><?= e(t('Save the item, then type the 6-digit code it shows into “Code from your app”.')) ?></li>
        </ol></details>
      <p class="hint" style="margin:.5rem 0 0"><?= e(t('The code changes every 30 seconds. If a correct-looking code is refused, set your phone clock to automatic and try the next code. Any other app that supports standard time-based codes (TOTP) works the same way.')) ?></p>
    </div>

  <?php elseif (!empty($emailEnrol)): /* ---- e-mail codes: prove the mailbox first ---- */ ?>
    <?php if (!empty($error)) echo $errBox($error); ?>
    <p class="hint" style="margin-top:0"><?= e(t('We sent a 6-digit code to {email}. Enter it here to turn on two-factor with e-mailed codes. The code expires in 10 minutes.', ['email' => $maskedEmail])) ?></p>
    <form class="stack" method="post" action="<?= BASE_PATH ?>/profile" style="max-width:260px">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="2fa_email_confirm">
      <label><?= e(t('Code from the e-mail')) ?></label>
      <input name="code" inputmode="numeric" autocomplete="one-time-code" required placeholder="123456"
             style="font-size:1.2rem;letter-spacing:.3em;text-align:center;font-family:ui-monospace,monospace">
      <button class="btn"><?= e(t('Turn on two-factor')) ?></button>
    </form>
    <form method="post" action="<?= BASE_PATH ?>/profile" style="margin:.6rem 0 0">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="2fa_email_start">
      <button class="btn line sm"><?= e(t('Send a new code')) ?></button>
    </form>

  <?php elseif ($twoOn && $mfaMethod === 'email'): ?>
    <p class="hint" style="margin-top:0"><?= e(t('Each time you sign in, we e-mail a 6-digit code to {email}. To turn two-factor off, ask for a code and enter it, or use a backup code.', ['email' => $maskedEmail])) ?></p>
    <form method="post" action="<?= BASE_PATH ?>/profile" style="margin:0 0 .7rem">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="2fa_email_code">
      <button class="btn line sm"><?= e(t('E-mail me a code')) ?></button>
    </form>
    <form class="stack" method="post" action="<?= BASE_PATH ?>/profile" style="max-width:320px">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="2fa_disable">
      <div><label><?= e(t('Code from the e-mail, or a backup code')) ?></label><input name="code" inputmode="numeric" required placeholder="123456"></div>
      <div><button class="btn line" style="color:var(--danger)"><?= e(t('Turn off two-factor')) ?></button></div>
    </form>
    <p class="hint" style="margin-bottom:0"><?= e(t('An authenticator app is stronger than e-mail codes: to switch, turn two-factor off and set it up again with an app.')) ?></p>

  <?php elseif ($twoOn): ?>
    <p class="hint" style="margin-top:0"><?= e(t('Your account asks for a code from your authenticator app each time you sign in. To turn it off, confirm with a current code or a backup code.')) ?></p>
    <form class="stack" method="post" action="<?= BASE_PATH ?>/profile" style="max-width:320px">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="2fa_disable">
      <div><label><?= e(t('Current code')) ?></label><input name="code" inputmode="numeric" required placeholder="123456"></div>
      <div><button class="btn line" style="color:var(--danger)"><?= e(t('Turn off two-factor')) ?></button></div>
    </form>

  <?php else: ?>
    <p class="hint" style="margin-top:0"><?= e(t('Add a second step at sign-in, so a stolen password alone cannot open your organization\'s compliance records. Strongly recommended for owners and managers.')) ?></p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.8rem">
      <div style="border:1px solid var(--line,#e3e1da);border-radius:9px;padding:.8rem">
        <strong><?= e(t('Authenticator app')) ?></strong> <span class="chip active" style="margin-left:.3rem"><?= e(t('Recommended')) ?></span>
        <p class="hint" style="margin:.4rem 0 .7rem"><?= e(t('A free app on your phone shows a new code every 30 seconds. Works without signal or e-mail. Google Authenticator, Microsoft Authenticator, Authy and 1Password are supported, with step-by-step instructions.')) ?></p>
        <form method="post" action="<?= BASE_PATH ?>/profile" style="margin:0">
          <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="2fa_start">
          <button class="btn"><?= e(t('Set up with an app')) ?></button>
        </form>
      </div>
      <?php if ($emailAllowed): ?>
      <div style="border:1px solid var(--line,#e3e1da);border-radius:9px;padding:.8rem">
        <strong><?= e(t('E-mail codes')) ?></strong>
        <p class="hint" style="margin:.4rem 0 .7rem"><?= e(t('We e-mail a 6-digit code to {email} each time you sign in. Simpler, but only as safe as your mailbox.', ['email' => $maskedEmail])) ?></p>
        <?php if ($emailReady): ?>
        <form method="post" action="<?= BASE_PATH ?>/profile" style="margin:0">
          <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="2fa_email_start">
          <button class="btn line"><?= e(t('Use e-mail codes')) ?></button>
        </form>
        <?php else: ?>
        <p class="hint" style="margin:0"><em><?= e(t('Not available yet: outbound e-mail is not set up for this organization.')) ?></em></p>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($planFeat): ?>
<div class="panel">
  <div class="topline"><h2>Your plan</h2></div>
  <p><strong><?= e($planFeat['name']) ?></strong><?= $planFeat['price_monthly']!==null ? e(' · $'.number_format((float)$planFeat['price_monthly'],2).t('/mo')) : '' ?>
     · <?= e(t('{seats} seats ({n} in use)', ['seats' => $planFeat['max_users']===null ? t('unlimited') : (int)$planFeat['max_users'], 'n' => FlxCoreHC\PlanService::seatCount($ctx->tenantId)])) ?></p>
  <p class="hint">Included: <?php $f=json_decode((string)$planFeat['features'],true)?:[]; echo $f?implode(', ', array_map(fn($c)=>FlxCoreHC\PlanService::FEATURES[$c]??$c,$f)):'core features only'; ?></p>
</div>
<?php endif; ?>
</div>
</div>
