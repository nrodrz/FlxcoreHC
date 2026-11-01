<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $cfg */ /** @var array $outbox */
$adminTab = 'email';
$title = 'Email';
include __DIR__ . '/_tabs.php';
$statusChip = ['sent' => 'active', 'queued' => 'warn', 'failed' => 'muted'];
?>
<div class="topline"><h1>Email delivery</h1></div>
<?php if (!empty($mailTest)): ?>
<div class="panel" style="margin-bottom:1rem;border-left:3px solid <?= !empty($mailTest['ok']) ? 'var(--ok,#16a34a)' : 'var(--danger,#dc2626)' ?>">
  <strong><?= e($mailTest['what'] ?? 'Test') ?>: <?= !empty($mailTest['ok']) ? 'worked' : 'failed' ?></strong>
  <?php if (empty($mailTest['ok']) && !empty($mailTest['error'])): ?><p style="margin:.4rem 0 0"><?= e($mailTest['error']) ?></p><?php endif; ?>
  <?php if (!empty($mailTest['log'])): ?><ul class="hint" style="margin:.5rem 0 0 1.1rem;font-size:.8rem"><?php foreach ($mailTest['log'] as $__l): ?><li><?= e($__l) ?></li><?php endforeach; ?></ul><?php endif; ?>
</div>
<?php endif; ?>
<div class="panel" style="margin-bottom:1rem">
  <div class="topline"><h2 style="margin:0;font-size:1rem">Microsoft 365 — modern authentication</h2>
    <?= !empty($msAccount) ? '<span class="chip active">connected: ' . e($msAccount) . '</span>' : (!empty($msConfigured) ? '<span class="chip warn">app registered — not connected</span>' : '<span class="chip">not set up</span>') ?></div>
  <p class="hint" style="max-width:820px">Microsoft is retiring basic SMTP AUTH. Register <strong>one</strong> Azure app here; then the platform sender and every tenant can <em>Connect Microsoft 365</em> with their own mailbox — no passwords stored, messages sent through Microsoft Graph.</p>
  <details style="margin:.4rem 0 .8rem"><summary class="btn sm line" style="display:inline-block;cursor:pointer">App registration (one-time)</summary>
    <ol class="hint" style="font-size:.8rem;margin:.5rem 0 .6rem 1.2rem;line-height:1.6">
      <li>Entra admin center → <strong>App registrations → New</strong>. Supported account types: <em>Accounts in any organizational directory</em> (multitenant).</li>
      <li>Redirect URI (Web): <code><?= e($msRedirect ?? '') ?></code></li>
      <li>API permissions (Microsoft Graph, <em>delegated</em>): <code>Mail.Send</code>, <code>User.Read</code>, <code>offline_access</code>.</li>
      <li>Certificates &amp; secrets → new client secret. Paste the Application (client) ID and the secret value below.</li>
    </ol>
    <form method="post" action="<?= BASE_PATH ?>/admin" class="stack" style="max-width:560px"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="action" value="ms_app_save">
      <div><label>Application (client) ID</label><input name="ms_client_id" value="<?= e($msClientId ?? '') ?>" autocomplete="off"></div>
      <div><label>Client secret <span class="hint">(leave blank to keep the saved one)</span></label><input name="ms_client_secret" type="password" autocomplete="off"></div>
      <div><label>Client secret expires on <span class="hint">(the date Entra shows; the console warns you 60 days before)</span></label><input name="ms_secret_expires" type="date" value="<?= e($msSecretExpires ?? '') ?>"></div>
      <?php if (($msSecretDays ?? null) !== null): ?><p class="hint" style="margin:0"><?= $msSecretDays < 0 ? 'Expired ' . (int) -$msSecretDays . ' day(s) ago.' : 'Expires in ' . (int) $msSecretDays . ' day(s).' ?></p>
      <?php elseif (!empty($msClientId)): ?><p class="hint" style="margin:0">No expiry date saved: you will not get a warning. Enter the date shown in Entra.</p><?php endif; ?>
      <div><button class="btn sm">Save app registration</button></div>
    </form>
  </details>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
    <?php if (!empty($msAccount)): ?>
      <form method="post" action="<?= BASE_PATH ?>/admin" style="margin:0"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="ms_disconnect_platform"><button class="btn sm line">Disconnect</button></form>
      <span class="hint">Platform emails send as <strong><?= e($msAccount) ?></strong> via Graph.</span>
    <?php elseif (!empty($msConfigured)): ?>
      <form method="post" action="<?= BASE_PATH ?>/admin" style="margin:0"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="ms_connect_platform"><button class="btn sm gold">Connect Microsoft 365 (platform sender)</button></form>
      <span class="hint">Sign in with the mailbox platform emails should come from.</span>
    <?php endif; ?>
  </div>
</div>

<?php if (isset($emailReady) && !$emailReady): ?>
<div class="panel" style="border-left:3px solid var(--warn)">
  <p style="margin:0"><strong>Database update needed.</strong> The email outbox table isn't installed yet.
  Go to <a href="<?= BASE_PATH ?>/admin/system">System &amp; updates → Update database</a> to apply the
  latest migrations, then return here.</p>
</div>
<?php endif; ?>
<p class="hint" style="margin-top:-.4rem;max-width:78ch">
  Outbound email for approvals, invitations, and notices. Every message is queued to the outbox
  first, then delivered — so nothing is lost if a send fails, and you can see exactly what went
  out. On shared hosting, SMTP is far more reliable than the server's built-in mail; configure it
  below and send yourself a test.
</p>

<div class="panel">
  <div class="topline"><h2>Settings</h2>
    <span class="chip <?= $cfg['mail_enabled'] === '1' ? 'active' : 'muted' ?>"><?= $cfg['mail_enabled'] === '1' ? 'Email on' : 'Email off' ?></span>
  </div>
  <form method="post" action="<?= BASE_PATH ?>/admin">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="save_email_settings">
    <label style="display:flex;gap:.55rem;align-items:center;margin-bottom:1rem;font-weight:600">
      <input type="checkbox" name="mail_enabled" value="1" <?= $cfg['mail_enabled'] === '1' ? 'checked' : '' ?>>
      Send email (when off, messages still queue but aren't delivered)
    </label>
    <div class="form-grid">
      <div><label>From address</label><input name="mail_from" type="email" value="<?= e($cfg['mail_from']) ?>" placeholder="no-reply@yourpractice.com"></div>
      <div><label>From name</label><input name="mail_from_name" value="<?= e($cfg['mail_from_name']) ?>"></div>
      <div><label>Transport</label>
        <select name="mail_transport">
          <option value="smtp" <?= $cfg['mail_transport'] === 'smtp' ? 'selected' : '' ?>>SMTP (recommended)</option>
          <option value="mail" <?= $cfg['mail_transport'] === 'mail' ? 'selected' : '' ?>>Server mail (PHP mail(), no password; works on most cPanel hosts)</option>
        </select></div>
    </div>
    <div class="panel" style="background:var(--surface-2);margin-top:1rem">
      <p class="hint" style="margin-top:0"><strong>SMTP</strong> — used when transport is SMTP. Quick fill:
        <?php foreach ($presets as $__k => $__p): ?><button type="button" class="btn sm line" data-fill-host="<?= e($__p['host']) ?>" data-fill-port="<?= e((string) $__p['port']) ?>" data-fill-secure="<?= e($__p['secure']) ?>" title="<?= e($__p['hint']) ?>"><?= e($__p['label']) ?></button> <?php endforeach; ?>
      </p>
      <div class="form-grid">
        <div><label>Host</label><input name="smtp_host" value="<?= e($cfg['smtp_host']) ?>" placeholder="smtp.yourprovider.com"></div>
        <div><label>Port</label><input name="smtp_port" value="<?= e($cfg['smtp_port']) ?>" placeholder="587"></div>
        <div><label>Security</label>
          <select name="smtp_secure">
            <option value="tls" <?= $cfg['smtp_secure'] === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option>
            <option value="ssl" <?= $cfg['smtp_secure'] === 'ssl' ? 'selected' : '' ?>>SSL/TLS (465)</option>
            <option value="none" <?= $cfg['smtp_secure'] === 'none' ? 'selected' : '' ?>>None</option>
          </select></div>
        <div><label>Username</label><input name="smtp_user" value="<?= e($cfg['smtp_user']) ?>" autocomplete="off"></div>
        <div><label>Password</label><input name="smtp_pass" type="password" value="" autocomplete="new-password" placeholder="<?= $cfg['smtp_pass'] === '********' ? 'unchanged' : 'enter password' ?>"></div>
      </div>
      <label style="display:flex;gap:.5rem;align-items:center;margin-top:.7rem"><input type="checkbox" name="smtp_verify_tls" value="1" <?= ($cfg['smtp_verify_tls'] ?? '1') !== '0' ? 'checked' : '' ?>> Verify the server's security certificate (turn off only if the mail host's certificate names a different host, such as a shared cPanel server)</label>
      <p class="hint" style="margin-bottom:0">The password is stored encrypted. Leave it blank to keep the saved one. Username is usually the full e-mail address.</p>
    </div>
    <div style="margin-top:1rem"><button class="btn">Save settings</button></div>
  </form>
</div>

<div class="panel">
  <div class="topline"><h2>Send a test</h2></div>
  <form method="post" action="<?= BASE_PATH ?>/admin" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="send_test_email">
    <div><label>Send test to</label><input name="test_to" type="email" placeholder="you@yourpractice.com" style="width:260px"></div>
    <button class="btn line">Send test email</button>
  </form>
  <form method="post" action="<?= BASE_PATH ?>/admin" style="margin-top:.6rem"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="test_mail_connection">
    <button class="btn sm line">Test connection only (log in, send nothing)</button>
    <span class="hint">Both tests use the saved settings and work even while "Send email" is off.</span></form>
</div>

<div class="panel">
  <div class="topline"><h2>Outbox</h2>
    <form method="post" action="<?= BASE_PATH ?>/admin" style="margin:0">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="flush_outbox">
      <button class="btn sm line">Send queued now</button>
    </form>
    <form method="post" action="<?= BASE_PATH ?>/admin" style="margin:0">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="retry_failed_mail">
      <button class="btn sm line">Retry failed</button>
    </form>
  </div>
  <div class="table-wrap"><table>
    <tr><th>When</th><th>To</th><th>Subject</th><th>Type</th><th>Status</th></tr>
    <?php foreach ($outbox as $m): ?>
    <tr>
      <td class="mono" style="font-size:.78rem"><?= e(fmt_dt((string) $m['created_at'], 'm/d/Y g:i A')) ?></td>
      <td><?= e($m['to_email']) ?></td>
      <td><?= e($m['subject']) ?></td>
      <td><span class="hint" style="font-size:.74rem"><?= e($m['category']) ?></span></td>
      <td>
        <span class="chip <?= $statusChip[$m['status']] ?? 'muted' ?>"><?= e($m['status']) ?></span>
        <?php if ($m['status'] === 'failed' && !empty($m['last_error'])): ?>
          <div class="hint" style="font-size:.7rem"><?= e($m['last_error']) ?></div>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($outbox)): ?><tr><td colspan="5"><span class="hint">No messages yet.</span></td></tr><?php endif; ?>
  </table></div>
</div>
<script <?= nonce_attr() ?>>
document.querySelectorAll('[data-fill-host]').forEach(function(b){
  b.addEventListener('click',function(){
    var f=b.closest('form'); if(!f) return;
    f.elements['mail_transport'].value='smtp';
    f.elements['smtp_host'].value=b.getAttribute('data-fill-host');
    f.elements['smtp_port'].value=b.getAttribute('data-fill-port');
    f.elements['smtp_secure'].value=b.getAttribute('data-fill-secure');
  });
});
</script>
