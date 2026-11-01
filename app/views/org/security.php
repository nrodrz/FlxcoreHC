<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */
$title = 'Security';
include __DIR__ . '/_tabs.php';
$enabled = array_filter($users, fn($u) => !empty($u['totp_enabled']));
$active  = array_filter($users, fn($u) => ($u['status'] ?? '') === 'active');
?>
<div class="topline"><h1>Security</h1></div>
<p class="hint" style="margin-top:-.4rem;max-width:74ch">
  Account safety across your organization. Two-factor authentication protects each sign-in
  even if a password leaks — every user can enable it themselves under
  <strong>Account &rarr; Two-factor authentication</strong>.
</p>

<div class="kpis" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">
  <div class="kpi"><div class="n"><?= count($active) ?></div><div class="l">Active users</div></div>
  <div class="kpi"><div class="n" style="color:<?= count($enabled) === count($active) && $active !== [] ? 'var(--ok)' : 'var(--warn)' ?>"><?= count($enabled) ?>/<?= count($active) ?></div><div class="l">2FA enabled</div></div>
  <div class="kpi"><div class="n">10+</div><div class="l">Min password length</div></div>
</div>

<div class="panel">
  <div class="topline"><h2>Two-factor policy</h2></div>
  <form method="post" action="<?= BASE_PATH ?>/organization/security" class="stack" style="margin:0">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <?php /* Marker so an unchecked box is saved as "off" rather than read as "not on this form". */ ?>
    <input type="hidden" name="_security_form" value="1">
      <label style="display:flex;align-items:center;gap:.45rem;font-weight:500;cursor:pointer">
        <input type="checkbox" name="require_2fa_admins" value="1" <?= !empty($org['require_2fa_admins']) ? 'checked' : '' ?>>
        Require two-factor authentication for owners and managers</label>
      <span class="hint" style="margin-top:-.3rem">Admins will be asked to enrol on their next sign-in before they can reach any other page. Each person sets this up on their own profile.</span>

    <div><button class="btn">Save policy</button></div>
  </form>
</div>

<div class="panel">
  <div class="topline"><h2>Two-factor status</h2></div>
  <div class="table-wrap"><table>
    <tr><th>User</th><th>Role</th><th>Status</th><th>Two-factor</th></tr>
    <?php foreach ($users as $u): ?>
    <tr>
      <td><strong><?= e($u['full_name']) ?></strong> <span class="hint" style="font-size:.78rem"><?= e($u['email']) ?></span></td>
      <td><span class="hint"><?= e(FlxCoreHC\TeamService::roleLabel((string) $u['role'])) ?></span></td>
      <td><span class="chip <?= ($u['status'] ?? '') === 'active' ? 'active' : 'muted' ?>"><?= e($u['status']) ?></span></td>
      <td><?php if (!empty($u['totp_enabled'])): ?><span class="chip active">Enabled</span>
          <?php else: ?><span class="chip warn">Not enabled</span><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <p class="hint" style="margin:.8rem 0 0">
    Passwords are stored with Argon2id hashing and never in plain text. Sign-ins are
    rate-limited per account and per address. Every create, update, delete, and export is
    written to the audit trail with who did it and when.
  </p>
</div>
