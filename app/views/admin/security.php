<?php
/** @var FlxCoreHC\TenantContext $ctx */ /** @var string $report */ /** @var array $checks */
$title = 'Platform console — Security';
$adminTab = 'security';
require FLXCOREHC_APP . '/views/admin/_tabs.php';
$byGroup = []; foreach ($checks as $c) { $byGroup[$c['group']][] = $c; }
$counts = ['ok'=>0,'warn'=>0,'bad'=>0]; foreach ($checks as $c) { $counts[$c['state']]++; }
$icon = ['ok'=>'✓','warn'=>'!','bad'=>'✕']; $chip = ['ok'=>'active','warn'=>'warn','bad'=>'danger'];
?>
<div class="topline"><h1>Security &amp; code audit</h1>
  <span style="display:flex;gap:.4rem">
    <span class="chip active"><?= (int)$counts['ok'] ?> passing</span>
    <?php if ($counts['warn']): ?><span class="chip warn"><?= (int)$counts['warn'] ?> to review</span><?php endif; ?>
    <?php if ($counts['bad']): ?><span class="chip danger"><?= (int)$counts['bad'] ?> action needed</span><?php endif; ?>
  </span></div>
<p class="hint" style="max-width:840px">Live posture of <strong>this installation</strong> plus the code-audit report shipped with this version. No tenant data is read here.</p>

<?php // ---- v1.172.0: sign-in policy for the platform (two-factor requirement for platform administrators; e-mailed codes) ----
$__req2fa = FlxCoreHC\Auth\AuthService::platformRequires2fa(); $__emlOk = FlxCoreHC\Auth\AuthService::emailCodesAllowed();
$__pas = $platformAdmins ?? []; $__without = array_values(array_filter($__pas, fn($a) => empty($a['totp_enabled']) && ($a['status'] ?? '') === 'active')); ?>
<div class="panel" id="signin-policy" style="margin-bottom:.8rem;border-left:3px solid <?= $__req2fa ? 'var(--ok)' : 'var(--warn,#b7791f)' ?>">
  <div class="topline" style="margin-bottom:.3rem"><h2 style="margin:0;font-size:.95rem">Sign-in policy</h2>
    <span class="chip <?= $__req2fa ? 'active' : 'warn' ?>"><?= $__req2fa ? 'Two-factor required for platform administrators' : 'Two-factor optional for platform administrators' ?></span></div>
  <p class="hint" style="max-width:840px;margin-top:0">The platform console can reach every organization. Requiring a second step for platform administrators is strongly recommended once the installation is set up — and before the first customer organization is added. While it is not required, each administrator can still turn two-factor on for their own account in <a href="<?= BASE_PATH ?>/profile#two-factor">Your profile</a>.</p>
  <form method="post" action="<?= BASE_PATH ?>/admin" class="stack" style="max-width:640px" data-novalidate>
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="signin_policy">
    <label style="display:flex;gap:.5rem;align-items:flex-start;font-weight:500">
      <input type="checkbox" name="platform_require_2fa" value="1" <?= $__req2fa ? 'checked' : '' ?> style="margin-top:.2rem">
      <span>Require two-factor authentication to use the platform console
        <span class="hint" style="display:block;font-weight:400">When on, a platform administrator without a second step is sent to their profile to set it up before the console opens, and only users who already use two-factor can be made platform administrators. You need two-factor on your own account to turn this on.</span></span></label>
    <label style="display:flex;gap:.5rem;align-items:flex-start;font-weight:500">
      <input type="checkbox" name="mfa_email_allowed" value="1" <?= $__emlOk ? 'checked' : '' ?> style="margin-top:.2rem">
      <span>Allow e-mailed codes as a second step (all organizations)
        <span class="hint" style="display:block;font-weight:400">When off, new enrolments must use an authenticator app. Accounts that already use e-mailed codes keep working until they change method. E-mailed codes need working outbound e-mail and are only as safe as the person's mailbox.</span></span></label>
    <div style="max-width:320px"><label>Your current password <span class="hint" style="font-weight:400">(to confirm the change)</span></label>
      <input type="password" name="current_password" required autocomplete="current-password"></div>
    <div><button class="btn">Save sign-in policy</button></div>
  </form>
  <?php if ($__pas): ?>
  <table style="font-size:.85rem;margin-top:.8rem;max-width:640px">
    <tr><th style="text-align:left">Platform administrator</th><th style="text-align:left">Two-factor</th></tr>
    <?php foreach ($__pas as $__a): $__on = !empty($__a['totp_enabled']); ?>
    <tr><td><?= e((string) $__a['full_name']) ?> <span class="hint"><?= e(FlxCoreHC\Auth\AuthService::maskEmail((string) $__a['email'])) ?></span><?= ($__a['status'] ?? '') !== 'active' ? ' <span class="chip muted">' . e((string) $__a['status']) . '</span>' : '' ?></td>
        <td><span class="chip <?= $__on ? 'active' : 'warn' ?>"><?= $__on ? (($__a['mfa_method'] ?? 'totp') === 'email' ? 'On — e-mailed codes' : 'On — authenticator app') : 'Off' ?></span></td></tr>
    <?php endforeach; ?>
  </table>
  <?php if ($__req2fa && $__without): ?><p class="hint" style="margin-bottom:0"><?= count($__without) ?> platform administrator(s) without two-factor will be asked to set it up at their next visit to the console.</p><?php endif; ?>
  <?php endif; ?>
</div>

<?php foreach ($byGroup as $g => $rows): ?>
<div class="panel" style="margin-bottom:.8rem">
  <h2 style="margin-top:0;font-size:.95rem"><?= e($g) ?></h2>
  <table style="font-size:.85rem">
    <?php foreach ($rows as $r): ?>
    <tr>
      <td style="width:2rem"><span class="chip <?= $chip[$r['state']] ?>" style="padding:0 .35rem"><?= $icon[$r['state']] ?></span></td>
      <td style="width:16rem"><strong><?= e($r['label']) ?></strong></td>
      <td class="hint"><?= e($r['detail']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endforeach; ?>

<div class="panel">
  <div class="topline" style="margin-bottom:.4rem"><h2 style="margin:0;font-size:.95rem">Code audit report — v<?= e(FLXCOREHC_VERSION) ?></h2>
    <span class="hint">docs/CODE_AUDIT_REPORT.md</span></div>
  <?php if ($report === ''): ?>
    <p class="hint">The report file was not found in this deployment (docs/CODE_AUDIT_REPORT.md).</p>
  <?php else: ?>
    <div style="max-height:520px;overflow:auto;border:1px solid var(--line,#e3e1da);border-radius:8px;padding:.8rem;background:var(--panel-2,#fafafa)">
      <pre style="white-space:pre-wrap;font-size:.78rem;line-height:1.5;margin:0"><?= e($report) ?></pre>
    </div>
  <?php endif; ?>
</div>
