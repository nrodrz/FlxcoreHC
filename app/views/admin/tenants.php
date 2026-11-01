<?php
/** @var FlxCoreHC\TenantContext $ctx */
$title = 'Platform console — Tenants';
$adminTab = 'tenants';
require FLXCOREHC_APP . '/views/admin/_tabs.php';
$statusChip = ['pending' => 'warn', 'active' => 'active', 'suspended' => 'danger', 'rejected' => 'danger', 'closed' => 'muted'];
?>
<div class="kpis">
  <div class="kpi"><span class="n"><?= (int) ($metrics['tenants'] ?? count($tenants)) ?></span><span class="l">Organizations</span></div>
  <div class="kpi"><span class="n"><?= (int) ($metrics['users'] ?? 0) ?></span><span class="l">Users</span></div>
  <div class="kpi"><span class="n"><?= (int) ($metrics['holders'] ?? 0) ?></span><span class="l">Providers tracked</span></div>
  <div class="kpi <?= $pendingCount > 0 ? 'warn' : '' ?>"><span class="n"><?= (int) $pendingCount ?></span><span class="l">Awaiting approval</span></div>
</div>

<div class="panel" style="margin:1rem 0">
  <div class="topline"><h2 style="margin:0">Create an organization</h2>
    <span class="hint">Onboard a client. Its Master Admin gets an invite; the org starts with the healthcare role set, credential templates, regulators and calendar types.</span></div>
  <form class="form-grid" method="post" action="<?= BASE_PATH ?>/admin">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="create_org">
    <div><label>Organization name</label><input name="org_name" required></div>
    <div><label>Type</label><select name="org_shape">
      <?php foreach (edition_shapes() as $__k) { if ($__k === 'platform') continue;
        $__lbl = FlxCoreHC\Auth\AuthService::ORG_SHAPES[$__k][2] ?? ucfirst(str_replace('_', ' ', $__k)); ?>
      <option value="<?= e($__k) ?>"><?= e($__lbl) ?></option>
      <?php } ?>
    </select></div>
    <div><label>Master Admin name</label><input name="owner_name" required placeholder="First and last name"></div>
    <div><label>Master Admin email</label><input name="owner_email" type="email" required></div>
    <div class="full"><label>Temporary password <span class="hint" style="display:inline;font-size:.72rem">(optional, 10+ characters. Set one to sign in right away; leave blank to send an invite link by e-mail)</span></label>
      <input name="owner_password" type="password" minlength="10" autocomplete="new-password"></div>
    <div class="full"><button class="btn">Create organization</button></div>
  </form>
</div>

<div class="panel">
  <form method="get" action="<?= BASE_PATH ?>/admin/tenants"
        style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1rem">
    <div style="flex:1;min-width:220px"><label>Search</label>
      <input name="q" value="<?= e($q) ?>" placeholder="Organization name…" style="width:100%"></div>
    <div><label>Status</label>
      <select name="status">
        <option value="">All</option>
        <?php foreach (['pending','active','suspended','rejected'] as $st): ?>
          <option value="<?= $st ?>" <?= $statusF === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
        <?php endforeach; ?>
      </select></div>
    <button class="btn sm">Filter</button>
    <?php if ($q !== '' || $statusF !== ''): ?><a class="btn sm line" href="<?= BASE_PATH ?>/admin/tenants">Clear</a><?php endif; ?>
  </form>

  <?php if (empty($tenants)): ?>
    <p class="hint" style="margin:0">No organizations match.</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>Organization</th><th>Type</th><th>Plan</th><th>Users</th><th>Providers</th><th>Status</th><th>Last activity</th><th></th></tr>
    <?php foreach ($tenants as $t): ?>
    <tr>
      <td><strong><?= e($t['name']) ?></strong>
        <?php if (!empty($t['status_reason'])): ?><span class="sub"><?= e($t['status_reason']) ?></span><?php endif; ?></td>
      <td><span class="chip" style="font-size:.66rem"><?= e(ucfirst((string)($t['vertical'] ?? '—'))) ?></span>
        <span class="hint" style="margin-left:.3rem"><?= e($t['org_shape']) ?></span></td>
      <td><span class="hint"><?= e($t['plan_tier'] ?? '—') ?></span></td>
      <td class="mono"><?= (int) $t['users'] ?></td>
      <td class="mono"><?= (int) $t['holders'] ?></td>
      <td><span class="chip <?= e($statusChip[$t['status']] ?? 'muted') ?>"><?= e(ucfirst($t['status'])) ?></span></td>
      <td class="mono" style="font-size:.78rem"><?= $t['last_activity'] ? e(fmt_dt((string) $t['last_activity'], 'm/d/Y')) : '—' ?></td>
      <td style="text-align:right"><a class="btn sm line" href="<?= BASE_PATH ?>/admin/tenant/<?= e($t['id']) ?>">Open</a></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
