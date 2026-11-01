<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */
/** @var array|null $settings */
/** @var array $presets */
$title = 'Email delivery';
$s = $settings ?? null;
$transport = $s['transport'] ?? 'platform';
?>
<?php $__path = '/organization/email'; require FLXCOREHC_APP . '/views/org/_tabs.php'; ?>
<div class="wrap">
  <div class="crumb"><a href="<?= BASE_PATH ?>/settings"><?= e(t('Organization')) ?></a></div>
  <div class="topline"><h1><?= e(t('Email delivery')) ?></h1>
    <?php if (!empty($s['verified_at'])): ?><span class="chip active" title="<?= e(fmt_dt($s['verified_at'], 'm/d/Y g:i A')) ?>"><?= e(t('Verified')) ?></span>
    <?php elseif ($transport === 'smtp'): ?><span class="chip"><?= e(t('Not verified yet')) ?></span><?php endif; ?></div>

  <p class="hint" style="max-width:720px"><?= e(t('Invites, reminders, and notifications for this organization can be sent through your own mailbox — a mailbox on your own domain (SMTP) or Microsoft 365 — so mail comes from your domain. If you keep the platform default, the app\'s own sender is used.')) ?></p>

  <?php if (!empty($mailTest)): ?>
  <div class="panel" style="margin-bottom:1rem;border-left:3px solid <?= !empty($mailTest['ok']) ? 'var(--ok,#16a34a)' : 'var(--danger,#dc2626)' ?>">
    <strong><?= e($mailTest['what'] ?? 'Test') ?>: <?= !empty($mailTest['ok']) ? e(t('worked')) : e(t('failed')) ?></strong>
    <?php if (empty($mailTest['ok']) && !empty($mailTest['error'])): ?><p style="margin:.4rem 0 0"><?= e($mailTest['error']) ?></p><?php endif; ?>
    <?php if (!empty($mailTest['log'])): ?><ul class="hint" style="margin:.5rem 0 0 1.1rem;font-size:.8rem"><?php foreach ($mailTest['log'] as $__l): ?><li><?= e($__l) ?></li><?php endforeach; ?></ul><?php endif; ?>
  </div>
  <?php endif; ?>
    <?php $__ms = $settings ?? []; $__msOn = (($__ms['transport'] ?? '') === 'graph') && !empty($__ms['oauth_account']); ?>
<div class="panel" style="margin-bottom:1rem;border-left:3px solid var(--accent,#2563eb)">
  <div class="topline"><h2 style="margin:0;font-size:1rem"><?= e(t('Microsoft 365 (recommended)')) ?></h2>
    <?= $__msOn ? '<span class="chip active">' . e(t('connected')) . ': ' . e($__ms['oauth_account']) . '</span>' : '<span class="chip">' . e(t('not connected')) . '</span>' ?></div>
  <p class="hint" style="max-width:780px"><?= e(t('If your organization uses Microsoft 365, connect it here. You sign in once with the mailbox that should send reminders and invitations; no password is stored and nothing uses the SMTP settings Microsoft is retiring.')) ?></p>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
    <?php if ($__msOn): ?>
      <form method="post" action="<?= BASE_PATH ?>/organization/email" style="margin:0"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="ms_disconnect"><button class="btn sm line"><?= e(t('Disconnect')) ?></button></form>
      <form method="post" action="<?= BASE_PATH ?>/organization/email" style="margin:0"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="test"><input type="hidden" name="test_to" value="<?= e($__ms['oauth_account']) ?>"><button class="btn sm"><?= e(t('Send test to myself')) ?></button></form>
    <?php elseif (!empty($msReady)): ?>
      <form method="post" action="<?= BASE_PATH ?>/organization/email" style="margin:0"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="ms_connect"><button class="btn sm gold"><?= e(t('Connect Microsoft 365')) ?></button></form>
    <?php else: ?>
      <span class="hint"><?= e(t('Microsoft 365 sign-in is not enabled on this platform yet — ask your platform administrator.')) ?></span>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($s['last_error']) && empty($mailTest)): ?>
<div class="panel" style="border-color:var(--danger);margin-bottom:1rem"><span class="hint"><?= e(t('Last test failed')) ?>: <?= e($s['last_error']) ?></span></div>
<?php endif; ?>

  <div class="panel" style="max-width:760px">
    <form method="post" action="<?= BASE_PATH ?>/organization/email" class="stack">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="action" value="save">
      <div><label><?= e(t('Transport')) ?></label>
        <select name="transport" id="mail-transport">
          <option value="platform" <?= $transport === 'platform' ? 'selected' : '' ?>><?= e(t('Platform default (app sender)')) ?></option>
          <option value="smtp" <?= $transport === 'smtp' ? 'selected' : '' ?>><?= e(t('Own SMTP (mailbox on your own domain)')) ?></option>
        </select></div>

      <div id="smtp-fields" style="<?= $transport === 'platform' ? 'display:none' : '' ?>"><?php /* Shown for
        Own SMTP; the script keeps this in sync with the Transport dropdown. */ ?>
        <div style="margin:.2rem 0 .6rem"><label style="display:block;margin-bottom:.3rem"><?= e(t('Quick setup — pick your provider')) ?></label>
          <div style="display:flex;gap:.4rem;flex-wrap:wrap">
            <?php foreach ($presets as $key => $p): ?>
              <button type="button" class="btn sm line" data-preset="<?= e($key) ?>"
                data-host="<?= e($p['host']) ?>" data-port="<?= e((string)$p['port']) ?>" data-secure="<?= e($p['secure']) ?>"
                title="<?= e($p['hint']) ?>"><?= e($p['label']) ?></button>
            <?php endforeach; ?>
          </div>
          <p class="hint" id="preset-hint" style="min-height:1.2em;margin:.35rem 0 0"></p>
        </div>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap">
          <div style="flex:1;min-width:180px"><label><?= e(t('From name')) ?></label>
            <input name="from_name" value="<?= e($s['from_name'] ?? $ctx->tenantName) ?>" style="width:100%"></div>
          <div style="flex:1;min-width:220px"><label><?= e(t('From email')) ?></label>
            <input name="from_email" type="email" value="<?= e($s['from_email'] ?? '') ?>" placeholder="credentials@yourdomain.com" style="width:100%"></div>
        </div>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap">
          <div style="flex:2;min-width:220px"><label><?= e(t('SMTP host')) ?></label>
            <input name="smtp_host" id="f-host" value="<?= e($s['smtp_host'] ?? '') ?>" placeholder="mail.yourdomain.com" style="width:100%"></div>
          <div style="flex:1;min-width:90px"><label><?= e(t('Port')) ?></label>
            <input name="smtp_port" id="f-port" value="<?= e((string)($s['smtp_port'] ?? 587)) ?>" style="width:100%"></div>
          <div style="flex:1;min-width:120px"><label><?= e(t('Security')) ?></label>
            <select name="smtp_secure" id="f-secure" style="width:100%">
              <option value="tls" <?= ($s['smtp_secure'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option>
              <option value="ssl" <?= ($s['smtp_secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL/TLS (465)</option>
              <option value="none" <?= ($s['smtp_secure'] ?? '') === 'none' ? 'selected' : '' ?>><?= e(t('None')) ?></option>
            </select></div>
        </div>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap">
          <div style="flex:1;min-width:220px"><label><?= e(t('Username')) ?></label>
            <input name="smtp_user" value="<?= e($s['smtp_user'] ?? '') ?>" placeholder="credentials@yourdomain.com" autocomplete="off" style="width:100%"></div>
          <div style="flex:1;min-width:220px"><label><?= e(t('Password / app password')) ?>
              <?php if (!empty($s['has_password'])): ?><span class="hint">(<?= e(t('saved — leave blank to keep')) ?>)</span><?php endif; ?></label>
            <input name="smtp_pass" type="password" value="" autocomplete="new-password"
              placeholder="<?= !empty($s['has_password']) ? '••••••••' : '' ?>" style="width:100%"></div>
        </div>
        <p class="hint"><?= e(t('The password is stored encrypted and never shown again. Use the full e-mail address as the username. For Microsoft 365 use Connect Microsoft 365 above instead of a password.')) ?></p>
      </div>
      <div><button class="btn"><?= e(t('Save settings')) ?></button></div>
    </form>
  </div>

  <?php if ($transport === 'smtp'): ?>
  <div class="panel" style="max-width:760px;margin-top:1rem">
    <h2 style="margin-top:0"><?= e(t('Send a test message')) ?></h2>
    <form method="post" action="<?= BASE_PATH ?>/organization/email" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="action" value="test">
      <input name="test_to" type="email" required placeholder="you@yourdomain.com" style="max-width:280px">
      <button class="btn sm gold"><?= e(t('Send test')) ?></button>
      <span class="hint"><?= e(t('Uses the saved settings; a success stamps the Verified badge.')) ?></span>
    </form>
    <form method="post" action="<?= BASE_PATH ?>/organization/email" style="margin-top:.6rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="probe">
      <button class="btn sm line"><?= e(t('Test connection only')) ?></button>
      <span class="hint"><?= e(t('Logs in to the mail server and sends nothing.')) ?></span>
    </form>
    <form method="post" action="<?= BASE_PATH ?>/organization/email" style="margin-top:.4rem">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="retry">
      <button class="btn sm line"><?= e(t('Retry failed messages')) ?></button>
    </form>
  </div>
  <?php endif; ?>
</div>
<script <?= nonce_attr() ?>>
(function(){
  var t=document.getElementById('mail-transport'), f=document.getElementById('smtp-fields');
  var hint=document.getElementById('preset-hint');
  // Fields render visible by default; hide them only when the platform default is selected.
  function sync(){ if(f) f.style.display = (t && t.value==='platform') ? 'none' : ''; }
  if(t) t.addEventListener('change', sync);
  document.querySelectorAll('[data-preset]').forEach(function(b){
    b.addEventListener('click',function(){
      if(t){ t.value='smtp'; }
      if(f){ f.style.display=''; }
      var host=document.getElementById('f-host'), port=document.getElementById('f-port'), sec=document.getElementById('f-secure');
      if(host) host.value=b.getAttribute('data-host');
      if(port) port.value=b.getAttribute('data-port');
      if(sec)  sec.value=b.getAttribute('data-secure');
      if(hint) hint.textContent=b.getAttribute('title');
      if(host) host.focus();
    });
  });
  sync();
})();
</script>
