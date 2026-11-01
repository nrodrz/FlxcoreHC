<?php /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $users */
use FlxCoreHC\TeamService;
$title = 'Team';
include __DIR__ . '/../org/_tabs.php';
$roles = $roleList ?? TeamService::ROLES;   // tenant's own roles (custom-role builder, v1.50)
$holderNames = [];
foreach ($holderList as $h) { $holderNames[$h['id']] = trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? '')); }
$activeOwners = count(array_filter($users, fn($u) => $u['role'] === 'tenant_owner' && ($u['status'] ?? '') === 'active'));
$iAmOwner = $ctx->role === 'tenant_owner';
$statusChip = ['active' => ['active', 'active'], 'suspended' => ['disabled', 'muted'], 'invited' => ['invited', 'warn']];
?>
<div class="topline"><h1>Team</h1><span class="hint"><?= count($users) ?> user<?= count($users) === 1 ? '' : 's' ?></span></div>
<p class="hint" style="margin-top:-.4rem;max-width:74ch">
  Everyone signs in with their own email and password. Roles control what each person can
  do; an organization always keeps at least one active owner.
</p>

<div class="panel">
  <div class="topline"><h2>Users</h2></div>
  <div class="table-wrap"><table>
    <tr><th>Name</th><th>Email (sign-in)</th><th style="min-width:170px">Role</th><th>Status</th><th style="text-align:right">Actions</th></tr>
    <?php foreach ($users as $u):
      $self       = $u['id'] === $ctx->userId;
      $isOwner    = $u['role'] === 'tenant_owner';
      $lastOwner  = $isOwner && ($u['status'] ?? '') === 'active' && $activeOwners <= 1;
      $mayTouch   = !$isOwner || $iAmOwner;          // managers never act on owners
      $invited    = ($u['status'] ?? '') === 'invited';
      [$stLbl, $stCls] = $statusChip[$u['status'] ?? 'active'] ?? [$u['status'], 'muted'];
    ?>
    <tr>
      <td>
        <strong><?= e($u['full_name']) ?></strong>
        <?php if ($self): ?><span class="chip" style="font-size:.6rem;margin-left:.3rem">you</span><?php endif; ?>
        <?php if ($u['role'] === 'producer' && !empty($u['holder_id'])): ?>
          <div class="hint" style="font-size:.74rem"><?= e(t('Provider record: {name}', ['name' => $holderNames[$u['holder_id']] ?? '—'])) ?></div>
        <?php endif; ?>
      </td>
      <td><span class="hint"><?= e($u['email']) ?></span></td>
      <td>
        <?php if ($mayTouch && !$lastOwner): ?>
        <form method="post" action="<?= BASE_PATH ?>/team" style="margin:0">
          <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
          <select name="role" data-autosubmit>
            <?php if (!isset($roles[$u['role']])): ?><option value="<?= e($u['role']) ?>" selected disabled><?= e(FlxCoreHC\TeamService::roleLabel((string) $u['role'], $roles)) ?></option><?php endif; ?>
            <?php foreach ($roles as $rk => $rl): ?><option value="<?= $rk ?>" <?= $u['role'] === $rk ? 'selected' : '' ?>><?= e($rl) ?></option><?php endforeach; ?>
          </select>
        </form>
        <?php if ($u['role'] !== 'tenant_owner'): ?>
        <form method="post" action="<?= BASE_PATH ?>/team" style="margin:.25rem 0 0" title="<?= e(t('Which providers this user can see')) ?>">
          <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="provider_access"><input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
          <select name="mode" data-autosubmit style="font-size:.75rem">
            <option value="all" <?= ($u['provider_access'] ?? 'all') === 'all' ? 'selected' : '' ?>><?= e(t('Sees all providers')) ?></option>
            <option value="assigned" <?= ($u['provider_access'] ?? 'all') === 'assigned' ? 'selected' : '' ?>><?= e(t('Assigned providers only')) ?></option>
          </select>
        </form>
        <?php endif; ?>
        <?php else: ?>
          <span class="chip"><?= e(FlxCoreHC\TeamService::roleLabel((string) $u['role'], $roles)) ?></span>
          <?php if ($lastOwner): ?><div class="hint" style="font-size:.72rem">Only active Master Admin — promote someone first to change this.</div><?php endif; ?>
        <?php endif; ?>
      </td>
      <td><span class="chip <?= e($stCls) ?>"><?= e($stLbl) ?></span></td>
      <td style="text-align:right;white-space:nowrap">
        <?php if ($mayTouch): ?>
          <button type="button" class="btn sm line" data-action="toggle" data-target="edit-<?= e($u['id']) ?>">Edit</button>
        <?php endif; ?>
        <?php if ($invited && $mayTouch): ?>
        <form method="post" action="<?= BASE_PATH ?>/team" style="display:inline;margin:0">
          <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="resend"><input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
          <button class="btn sm line">Resend invite</button>
        </form>
        <?php endif; ?>
        <?php if (!$self && $mayTouch && !$lastOwner): ?>
        <form method="post" action="<?= BASE_PATH ?>/team" style="display:inline;margin:0" <?= ($u['status'] ?? '') === 'active' ? 'data-confirm="Disable this account? They will not be able to sign in." data-confirm-danger' : '' ?>>
          <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="<?= ($u['status'] ?? '') === 'active' ? 'disable' : 'enable' ?>"><input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
          <button class="btn sm line" <?= ($u['status'] ?? '') === 'active' ? 'style="color:var(--danger)"' : '' ?>><?= ($u['status'] ?? '') === 'active' ? 'Disable' : 'Enable' ?></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php if ($mayTouch): ?>
    <tr id="edit-<?= e($u['id']) ?>" style="display:none">
      <td colspan="5" style="background:var(--surface-2);border-radius:10px">
        <div style="display:flex;gap:1.4rem;flex-wrap:wrap;align-items:flex-end;padding:.35rem .2rem">
          <form method="post" action="<?= BASE_PATH ?>/team" style="display:flex;gap:.7rem;align-items:flex-end;flex-wrap:wrap;margin:0">
            <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="update_user"><input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
            <div><label style="font-size:.72rem">Full name</label><input name="full_name" value="<?= e($u['full_name']) ?>" style="width:190px"></div>
            <div><label style="font-size:.72rem">Email (sign-in)</label><input name="email" value="<?= e($u['email']) ?>" style="width:220px"></div>
            <button class="btn sm">Save</button>
          </form>
          <form method="post" action="<?= BASE_PATH ?>/team" style="display:flex;gap:.7rem;align-items:flex-end;margin:0">
            <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
            <div><label style="font-size:.72rem">New password</label><input name="password" type="password" placeholder="10+ characters" style="width:170px"></div>
            <button class="btn sm line">Set password</button>
          </form>
        </div>
      </td>
    </tr>
    <?php endif; ?>
    <?php endforeach; ?>
  </table></div>
</div>

<div class="panel">
  <div class="topline"><h2>Add a user</h2></div>
  <form method="post" action="<?= BASE_PATH ?>/team">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="add">
    <div class="form-grid">
      <div><label>Full name</label><input name="full_name" required placeholder="First and last name"></div>
      <div><label>Email (sign-in)</label><input name="email" type="email" required></div>
      <div><label>Role</label>
        <select name="role">
          <?php foreach ($roles as $rk => $rl): if ($rk === 'tenant_owner' && !$iAmOwner) continue; ?>
            <option value="<?= $rk ?>" <?= $rk === 'staff' ? 'selected' : '' ?>><?= e($rl) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label>Provider record <span class="hint" style="display:inline;font-size:.72rem">(required for the Provider self-service role)</span></label>
        <select name="holder_id"><option value="">—</option>
          <?php foreach ($holderList as $h): ?>
            <option value="<?= e($h['id']) ?>"><?= e(trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? ''))) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="full"><label>Temporary password <span class="hint" style="display:inline;font-size:.72rem">(optional — leave blank to generate an invite link instead)</span></label>
        <input name="password" type="password" placeholder="10+ characters to set now" style="max-width:340px"></div>
    </div>
    <div style="margin-top:.9rem"><button class="btn">Add user</button></div>
  </form>
</div>
