<?php
/** @var FlxCoreHC\TenantContext $ctx */
/** @var array $roles   global master roles with ['capabilities'] */
/** @var array $catalog Capabilities::CATALOG */
/** @var array $seeded  built-in global slugs (protected from delete) */
/** @var array|null $edit */
$title = 'Global roles';
?>
<div class="wrap">
  <div class="topline"><h1>Global master roles</h1>
    <a class="btn sm line" href="<?= BASE_PATH ?>/admin/tenants">Back to console</a></div>
  <p class="hint">These role definitions apply to <strong>every tenant</strong>. The six built-in master
    roles are a <strong>locked security baseline</strong> — they can't be edited or deleted, so every
    organization can trust what they mean. You can still create <em>new</em> global roles (applied to
    all tenants), and tenants create their own roles for anything custom. “Master Admin” always holds
    every capability and manages the full app, including approving platform support access.</p>

  <div class="panel">
    <table>
      <tr><th>Role</th><th>Slug</th><th>Capabilities</th><th></th></tr>
      <?php foreach ($roles as $r): ?>
      <tr>
        <td><strong><?= e($r['name']) ?></strong>
          <?php if ($r['portal_only']): ?><span class="chip">portal</span><?php endif; ?>
          <?php if (in_array($r['slug'], $seeded, true)): ?><span class="chip">built-in</span><?php endif; ?></td>
        <td class="mono"><?= e($r['slug']) ?></td>
        <td class="sub">
          <?= $r['slug'] === 'master_admin' ? 'All capabilities' : (count($r['capabilities']) ? e(implode(', ', $r['capabilities'])) : '<span class="hint">none</span>') ?>
        </td>
        <td class="right">
          <?php if (in_array($r['slug'], $seeded, true)): ?>
            <span class="chip" title="Built-in master roles are locked so every tenant has a stable baseline.">Locked</span>
          <?php else: ?>
            <a class="btn sm line" href="<?= BASE_PATH ?>/admin/roles/<?= e($r['id']) ?>">Edit</a>
            <form method="post" action="<?= BASE_PATH ?>/admin/roles" style="display:inline" data-confirm="Delete this global role for all tenants?">
              <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="role_id" value="<?= e($r['id']) ?>">
              <button class="btn sm line">Delete</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <?php
    $editing = $edit;
    // Built-in masters are locked; never render them as an editable form.
    if ($editing && in_array($editing['slug'], $seeded, true)) { $editing = null; }
    $editCaps = $editing['capabilities'] ?? [];
    $isMaster = ($editing['slug'] ?? '') === 'master_admin';
  ?>
  <div class="panel" style="margin-top:1.2rem">
    <h2 style="margin-top:0"><?= $editing ? 'Edit “' . e($editing['name']) . '”' : 'Create a global role' ?></h2>
    <?php if ($isMaster): ?>
      <p class="hint">Master Admin always holds every capability — you can rename it, but its capabilities are locked so a tenant can never be left unable to manage itself.</p>
    <?php endif; ?>
    <form method="post" action="<?= BASE_PATH ?>/admin/roles" class="stack" style="max-width:760px">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
      <?php if ($editing): ?><input type="hidden" name="role_id" value="<?= e($editing['id']) ?>"><?php endif; ?>
      <div><label>Role name</label><input name="name" value="<?= e($editing['name'] ?? '') ?>" required maxlength="80" style="max-width:320px"></div>
      <?php if (!$isMaster): ?>
      <div><label>Capabilities</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.4rem 1.2rem">
          <?php foreach ($catalog as $group): [$glabel, $caps] = $group; ?>
            <div><div class="sub" style="font-weight:600;margin:.3rem 0"><?= e($glabel) ?></div>
              <?php foreach ($caps as $slug => $desc): ?>
                <label style="display:block;font-weight:normal;font-size:.82rem">
                  <input type="checkbox" name="caps[]" value="<?= e($slug) ?>" <?= in_array($slug, $editCaps, true) ? 'checked' : '' ?>>
                  <?= e($slug) ?> <span class="hint">— <?= e($desc) ?></span></label>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php if (!$editing): ?>
      <div><label style="font-weight:normal"><input type="checkbox" name="portal_only" value="1"> Portal-only (provider self-service login)</label></div>
      <?php endif; ?>
      <?php endif; ?>
      <div><button class="btn"><?= $editing ? 'Save global role' : 'Create global role' ?></button>
        <?php if ($editing): ?><a class="btn line" href="<?= BASE_PATH ?>/admin/roles">Cancel</a><?php endif; ?></div>
    </form>
  </div>
</div>
