<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $metrics */ /** @var array $rev */ /** @var array $tenants */
$title='Dashboard';
$m=$metrics; $money=fn($n)=>'$'.number_format((float)$n,0);
?>
<div class="crumb"><?= e($ctx->tenantName) ?> · Platform</div>
<div class="topline"><h1>Welcome back, <?= e(explode(' ', (string)$ctx->userName)[0]) ?></h1>
  <a class="btn sm" href="<?= BASE_PATH ?>/admin">Open admin console</a>
</div>

<div class="kpis">
  <a class="kpi" href="<?= BASE_PATH ?>/admin"><span class="n"><?= (int)$m['tenants'] ?></span><span class="l">Organizations</span></a>
  <div class="kpi ok"><span class="n"><?= (int)$m['tenants_active'] ?></span><span class="l">Active</span></div>
  <a class="kpi" href="<?= BASE_PATH ?>/admin/billing"><span class="n"><?= $money($rev['mrr']) ?></span><span class="l">MRR</span></a>
  <div class="kpi<?= ($rev['status']['past_due']??0)>0?' warn':'' ?>"><span class="n"><?= (int)($rev['status']['trialing']??0) ?></span><span class="l">On trial</span></div>
  <div class="kpi<?= ($rev['locked']??0)>0?' danger':'' ?>"><span class="n"><?= (int)($rev['locked']??0) ?></span><span class="l">Read-only</span></div>
</div>

<div class="cols-2">
  <div class="panel">
    <div class="topline"><h2>Recent organizations</h2><a class="btn sm line" href="<?= BASE_PATH ?>/admin">View all</a></div>
    <?php if(!$tenants): ?><div class="empty">No organizations yet. Create one from the admin console.</div><?php else: ?>
    <div class="table-wrap"><table>
      <tr><th>Organization</th><th>Type</th><th>Users</th><th>Status</th></tr>
      <?php foreach($tenants as $t): ?>
      <tr>
        <td><a href="<?= BASE_PATH ?>/admin/tenant/<?= e($t['id']) ?>"><strong><?= e($t['name']) ?></strong></a></td>
        <td class="sub"><?= e($t['vertical']) ?></td>
        <td class="mono"><?= (int)$t['users'] ?></td>
        <td><span class="chip <?= $t['status']==='active'?'active':'suspended' ?>"><?= e($t['status']) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </div>

  <div>
    <div class="panel">
      <div class="topline"><h2>Platform at a glance</h2></div>
      <div style="display:grid;gap:.55rem;font-size:.9rem">
        <div style="display:flex;justify-content:space-between"><span class="hint">Users</span><strong><?= (int)$m['users'] ?></strong></div>
        <div style="display:flex;justify-content:space-between"><span class="hint">SaaS admins</span><strong><?= (int)$m['admins'] ?></strong></div>
        <div style="display:flex;justify-content:space-between"><span class="hint">New orgs (30d)</span><strong><?= (int)$m['new_30d'] ?></strong></div>
        <div style="display:flex;justify-content:space-between"><span class="hint">ARR (run-rate)</span><strong><?= $money($rev['arr']) ?></strong></div>
      </div>
    </div>
    <div class="panel">
      <div class="topline"><h2>Quick actions</h2></div>
      <div style="display:grid;gap:.5rem">
        <a class="btn line" href="<?= BASE_PATH ?>/admin">Manage organizations</a>
        <a class="btn line" href="<?= BASE_PATH ?>/admin/billing">Revenue overview</a>
        <a class="btn line" href="<?= BASE_PATH ?>/admin/plans">Manage plans</a>
        <a class="btn line" href="<?= BASE_PATH ?>/admin/system">System &amp; updates</a>
        <a class="btn line" href="<?= BASE_PATH ?>/settings">Branding &amp; settings</a>
      </div>
    </div>
  </div>
</div>
