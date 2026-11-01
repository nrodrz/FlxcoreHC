<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $roles */ /** @var array $catalog */
$title = 'Roles & permissions';
include __DIR__ . '/_tabs.php';
$totalCaps = count(FlxCoreHC\Capabilities::all());
?>
<div class="topline">
  <h1>Roles &amp; permissions</h1>
  <a class="btn" href="<?= BASE_PATH ?>/organization/roles/new">New role</a>
</div>
<p class="hint" style="margin-top:-.4rem;max-width:76ch">
  Roles decide what each person can do. The built-in roles cover most organizations; create your own
  when you need a specific mix — a compliance reviewer who can't delete, a read-only auditor, a
  staff member confined to their assigned providers. Assign roles to people on the <a href="<?= BASE_PATH ?>/team">Team</a> tab.
</p>

<div class="panel">
  <div class="table-wrap"><table>
    <tr><th>Role</th><th>Type</th><th>People</th><th>Permissions</th><th style="text-align:right"></th></tr>
    <?php foreach ($roles as $r):
      $caps = $r['capabilities'];
      $isOwner = $r['slug'] === 'tenant_owner';
      $count = $isOwner ? $totalCaps : count($caps);
    ?>
    <tr>
      <td>
        <strong><?= e($r['name']) ?></strong>
        <?php if ($r['portal_only']): ?><span class="chip" style="font-size:.6rem;margin-left:.3rem">portal-only</span><?php endif; ?>
        <?php if (!empty($r['description'])): ?><div class="hint" style="font-size:.74rem"><?= e($r['description']) ?></div><?php endif; ?>
      </td>
      <td><span class="chip <?= $r['is_system'] ? '' : 'active' ?>" style="font-size:.62rem"><?= $r['is_system'] ? 'Built-in' : 'Custom' ?></span></td>
      <td><span class="hint"><?= (int) $r['user_count'] ?></span></td>
      <td>
        <?php if ($isOwner): ?>
          <span class="hint"><?= e(t('Full access (all {n} permissions)', ['n' => $totalCaps])) ?></span>
        <?php elseif ($count === 0): ?>
          <span class="hint">View only</span>
        <?php else: ?>
          <span class="hint"><?= e(t('{n} of {m} permissions', ['n' => $count, 'm' => $totalCaps])) ?></span>
        <?php endif; ?>
      </td>
      <td style="text-align:right;white-space:nowrap">
        <a class="btn sm line" href="<?= BASE_PATH ?>/organization/roles/<?= e($r['id']) ?>"><?= ($isOwner || !empty($r['is_system']) || empty($r['tenant_id'])) ? 'View' : 'Edit' ?></a>
        <?php if (!$r['is_system'] && (int) $r['user_count'] === 0): ?>
        <form method="post" action="<?= BASE_PATH ?>/organization/roles/<?= e($r['id']) ?>" style="display:inline;margin:0"
              data-confirm="Delete the “<?= e($r['name']) ?>” role?" data-confirm-danger>
          <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="delete">
          <button class="btn sm line" style="color:var(--danger)">Delete</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>
