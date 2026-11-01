<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var FlxCoreHC\TenantContext $ctx */ /** @var ?array $role */ /** @var array $catalog */
$isNew   = $role === null;
$isOwner = !$isNew && $role['slug'] === 'tenant_owner';
$isSystem = !$isNew && $role['is_system'];
$title   = $isNew ? 'New role' : ('Role — ' . $role['name']);
$held    = $isNew ? [] : array_flip($role['capabilities']);
$totalCaps = count(FlxCoreHC\Capabilities::all());
include __DIR__ . '/_tabs.php';
?>
<p class="crumb"><a href="<?= BASE_PATH ?>/organization/roles">Roles &amp; permissions</a></p>
<div class="topline"><h1><?= $isNew ? 'New role' : e($role['name']) ?></h1>
  <?php if ($isSystem): ?><span class="chip">Built-in</span><?php endif; ?>
</div>

<?php if ($isOwner): ?>
<div class="panel" style="border-left:3px solid var(--accent)">
  <p style="margin:0"><strong>The Owner role always has full access.</strong> <span class="hint">This can't be changed — it guarantees every organization keeps someone who can manage everything. To limit what a person can do, assign them a different role.</span></p>
</div>
<?php endif; ?>

<?php $lockedGlobal = $lockedGlobal ?? false; ?>
<?php if ($lockedGlobal): ?>
  <div class="panel" style="margin-bottom:1rem"><span class="hint">This is a <strong>built-in role</strong> —
    a stable baseline shared by every organization, so it can't be edited. To customize, create a
    <a href="<?= BASE_PATH ?>/organization/roles/new">new role</a> and assign that instead.</span></div>
<?php endif; ?>
<form method="post" action="<?= BASE_PATH ?>/organization/roles/<?= $isNew ? 'new' : e($role['id']) ?>">
<fieldset <?= $lockedGlobal ? 'disabled' : '' ?> style="border:0;padding:0;margin:0">
  <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
  <input type="hidden" name="action" value="<?= $isNew ? 'create' : 'update' ?>">
  <input type="hidden" name="caps_form" value="1">

  <div class="panel">
    <div class="form-grid">
      <div><label>Role name</label>
        <input name="name" value="<?= e($role['name'] ?? '') ?>" required maxlength="80"
               placeholder="e.g. Compliance Reviewer" <?= $isOwner ? 'readonly' : '' ?>></div>
      <?php if (!$isSystem): ?>
      <div>
        <label>Access scope</label>
        <label style="display:flex;gap:.55rem;align-items:center;padding:.55rem .7rem;border:1px solid var(--line);border-radius:9px;cursor:pointer;font-weight:400">
          <input type="checkbox" name="portal_only" value="1" <?= (!$isNew && $role['portal_only']) ? 'checked' : '' ?>>
          <span style="font-size:.85rem">Confine to the self-service portal (for providers who only manage their own credentials and documents)</span>
        </label>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <div class="topline"><h2>Permissions</h2>
      <?php if (!$isOwner): ?><span class="hint">Tick what this role can do</span><?php endif; ?>
    </div>
    <?php if ($isOwner): ?>
      <p class="hint" style="margin-top:-.2rem">Owner holds all <?= $totalCaps ?> permissions.</p>
    <?php endif; ?>

    <div style="display:grid;gap:1.1rem">
      <?php foreach ($catalog as $groupKey => [$groupLabel, $caps]): ?>
      <div>
        <div style="font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-3);font-weight:700;margin-bottom:.5rem;padding-bottom:.3rem;border-bottom:1px solid var(--line)"><?= e($groupLabel) ?></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.4rem">
          <?php foreach ($caps as $slug => $desc):
            $on = $isOwner || isset($held[$slug]); ?>
          <label style="display:flex;gap:.6rem;align-items:flex-start;padding:.5rem .65rem;border:1px solid var(--line);border-radius:9px;cursor:<?= $isOwner ? 'default' : 'pointer' ?>;<?= $on ? 'background:var(--accent-soft)' : '' ?>">
            <input type="checkbox" name="capabilities[]" value="<?= e($slug) ?>" <?= $on ? 'checked' : '' ?> <?= $isOwner ? 'disabled' : '' ?> style="margin-top:.15rem">
            <span style="font-size:.84rem;line-height:1.35"><?= e($desc) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (!$isOwner): ?>
  <div style="display:flex;gap:.6rem;margin-bottom:1.4rem">
</fieldset>

<?php if (!$isNew && !$isOwner): $fieldRules = $fieldRules ?? []; $protectable = $protectable ?? []; ?>
<?php if (!empty($lockedGlobal)): ?><form method="post" action="<?= BASE_PATH ?>/organization/roles/<?= e($role['id']) ?>" class="stack"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="field_rules"><?php endif; ?>
<fieldset style="margin-top:1rem">
  <legend><?= e(t('Sensitive fields — per-field rules')) ?></legend>
  <p class="hint" style="margin:.2rem 0 .6rem"><?= e(t('Fine-tune which sensitive fields this role can see. "Inherit" follows the "View sensitive identity data" permission above; "Allow" or "Deny" overrides it for that one field.')) ?></p>
  <input type="hidden" name="role_slug" value="<?= e($role['slug']) ?>">
  <table style="font-size:.84rem;max-width:640px">
    <tr><th><?= e(t('Field')) ?></th><th><?= e(t('Inherit')) ?></th><th><?= e(t('Allow')) ?></th><th><?= e(t('Deny')) ?></th></tr>
    <?php foreach ($protectable as $key => $label): $cur = array_key_exists($key, $fieldRules) ? ($fieldRules[$key] ? 'allow' : 'deny') : 'inherit'; ?>
    <tr>
      <td><?= e(t($label)) ?> <span class="mono hint" style="font-size:.68rem"><?= e($key) ?></span></td>
      <?php foreach (['inherit','allow','deny'] as $opt): ?>
      <td style="text-align:center"><input type="radio" name="field_rules[<?= e($key) ?>]" value="<?= $opt ?>" <?= $cur === $opt ? 'checked' : '' ?> style="width:auto"></td>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php if (!empty($lockedGlobal)): ?><div style="margin-top:.5rem"><button class="btn sm"><?= e(t('Save field rules')) ?></button> <span class="hint"><?= e(t('Built-in role: capabilities are fixed, but your organization can still set per-field rules.')) ?></span></div><?php endif; ?>
</fieldset>
<?php if (!empty($lockedGlobal)): ?></form><?php endif; ?>
<?php endif; ?>
    <?php if (!$lockedGlobal): ?><button class="btn"><?= $isNew ? 'Create role' : 'Save changes' ?></button><?php endif; ?>
    <a class="btn line" href="<?= BASE_PATH ?>/organization/roles">Cancel</a>
  </div>
  <?php if (!$isNew && !$isSystem && (int) ($role['user_count'] ?? 0) > 0): ?>
    <p class="hint" style="margin-top:-.6rem">This role is assigned to <?= (int) $role['user_count'] ?> <?= (int) $role['user_count'] === 1 ? 'person' : 'people' ?>. Reassign them on the Team tab before it can be deleted.</p>
  <?php endif; ?>
  <?php else: ?>
  <div style="margin-bottom:1.4rem"><a class="btn line" href="<?= BASE_PATH ?>/organization/roles">Back to roles</a></div>
  <?php endif; ?>
</form>
    <?php if (!$isNew && !$isSystem && (int) ($role['user_count'] ?? 0) === 0): ?>
    <form method="post" action="<?= BASE_PATH ?>/organization/roles/<?= e($role['id']) ?>" style="margin:.8rem 0 0"
          data-confirm="Delete this role?" data-confirm-danger>
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="delete">
      <button class="btn line" style="color:var(--danger)">Delete role</button>
    </form>
    <?php endif; ?>

