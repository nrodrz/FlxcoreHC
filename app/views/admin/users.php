<?php
/** @var FlxCoreHC\TenantContext $ctx */
$title = 'Platform console — Users';
$adminTab = 'users';
require FLXCOREHC_APP . '/views/admin/_tabs.php';
?>
<div class="panel">
  <div class="topline"><h2>Find a user across every organization</h2></div>
  <form method="get" action="<?= BASE_PATH ?>/admin/users"
        style="display:flex;gap:.6rem;align-items:flex-end;margin-bottom:1rem;flex-wrap:wrap">
    <div style="flex:1;min-width:260px"><label>Name or email</label>
      <input name="q" value="<?= e($q) ?>" placeholder="e.g. bob@ or Smith" style="width:100%" autofocus></div>
    <button class="btn sm">Search</button>
  </form>

  <?php if ($q === ''): ?>
    <p class="hint" style="margin:0">Search by any fragment of a name or email. Password resets and account actions live on the organization page.</p>
  <?php elseif (empty($rows)): ?>
    <p class="hint" style="margin:0">No users match "<?= e($q) ?>".</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>Name</th><th>Email</th><th>Organization</th><th>Role</th><th>Status</th><th></th></tr>
    <?php foreach ($rows as $u): ?>
    <tr>
      <td><strong><?= e($u['full_name']) ?></strong>
        <?php if ($u['platform_admin']): ?><span class="chip warn" style="font-size:.58rem;margin-left:.3rem">Platform admin</span><?php endif; ?></td>
      <td class="mono" style="font-size:.82rem"><?= e($u['email']) ?></td>
      <td><?= e($u['tenant_name']) ?>
        <?php if ($u['tenant_status'] !== 'active'): ?><span class="sub"><?= e($u['tenant_status']) ?></span><?php endif; ?></td>
      <td><span class="hint"><?= e(FlxCoreHC\TeamService::ROLES[$u['role']] ?? $u['role']) ?></span></td>
      <td><span class="chip <?= $u['status'] === 'active' ? 'active' : 'danger' ?>"><?= e(ucfirst($u['status'])) ?></span></td>
      <td style="text-align:right"><a class="btn sm line" href="<?= BASE_PATH ?>/admin/tenant/<?= e($u['tenant_id']) ?>">Open organization</a></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>

<?php $tenants = $tenants ?? []; $groups = $groups ?? []; ?>
<div class="panel" style="margin-top:1.2rem">
  <div class="topline"><h2>Add a user to an organization</h2></div>
  <p class="hint" style="margin:.2rem 0 .6rem">The user is added as <strong>Viewer</strong> and emailed an
    invite — each tenant's Master Admin then assigns the right role, so role assignment stays a tenant
    decision.</p>
  <form method="post" action="<?= BASE_PATH ?>/admin/users" class="stack" style="max-width:780px">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <input type="hidden" name="action" value="add_viewer">
    <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end">
      <div style="flex:1;min-width:200px"><label>Organization</label>
        <select name="tenant_id" required style="width:100%">
          <option value="">Choose…</option>
          <?php foreach ($tenants as $t): ?><option value="<?= e($t['id']) ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div style="flex:1;min-width:160px"><label>Full name</label><input name="full_name" required style="width:100%"></div>
      <div style="flex:1;min-width:180px"><label>Email</label><input name="email" type="email" required style="width:100%"></div>
    </div>
    <div><button class="btn sm">Add as Viewer &amp; send invite</button></div>
  </form>
</div>

<?php if ($q === '' && $groups !== []): $totalU = 0; foreach ($groups as $g) $totalU += count($g['users']); ?>
<div class="panel" style="margin-top:1.2rem">
  <div class="topline"><h2>All users by organization</h2>
    <span class="hint"><?= (int)$totalU ?> user(s) across <?= count($groups) ?> organization(s)</span></div>
  <?php foreach ($groups as $g): ?>
    <div style="margin:.6rem 0 1rem">
      <div class="topline" style="margin-bottom:.3rem">
        <strong><?= e($g['tenant']['name']) ?>
          <?php if ($g['tenant']['status'] !== 'active'): ?><span class="chip"><?= e($g['tenant']['status']) ?></span><?php endif; ?></strong>
        <a class="btn sm line" href="<?= BASE_PATH ?>/admin/tenant/<?= e($g['tenant']['id']) ?>">Open</a>
      </div>
      <div class="table-wrap"><table>
        <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th></tr>
        <?php foreach ($g['users'] as $u): ?>
        <tr>
          <td><?= e($u['full_name']) ?><?php if ($u['platform_admin']): ?> <span class="chip warn" style="font-size:.58rem">Platform</span><?php endif; ?></td>
          <td class="mono" style="font-size:.82rem"><?= e($u['email']) ?></td>
          <td><span class="hint"><?= e(FlxCoreHC\RoleService::GLOBAL_ROLES[$u['role']]['name'] ?? (FlxCoreHC\TeamService::ROLES[$u['role']] ?? $u['role'])) ?></span></td>
          <td><span class="chip <?= $u['status'] === 'active' ? 'active' : 'danger' ?>"><?= e(ucfirst($u['status'])) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </table></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
