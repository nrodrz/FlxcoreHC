<?php /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $custom */ /** @var array $categories */
$title = 'Tracked documents';
require FLXCOREHC_APP . '/views/org/_tabs.php';
?>

<div class="topline"><h1>Tracked documents</h1></div>
<p class="hint" style="margin:-.4rem 0 1rem;max-width:82ch">
  The built-in document types cover the standard requirements for your line of work. Anything else your
  organization tracks — a niche certification, an internal attestation, a jurisdiction-specific permit —
  can be added here and immediately becomes selectable when recording a credential. Your custom types are
  visible only to your organization.
</p>

<?php
  $edit = $edit ?? null; $states = $states ?? [];
  $RENEW_CYCLES = ['' => 'Does not renew', 6 => 'Every 6 months', 12 => 'Annual (12 mo)', 24 => 'Every 2 years',
                   36 => 'Every 3 years', 48 => 'Every 4 years', 60 => 'Every 5 years', 120 => 'Every 10 years'];
  $evRenew = (string)($edit['renewal_months'] ?? '');
?>
<div class="panel" id="type-form">
  <div class="topline"><h2><?= $edit ? 'Edit document type' : 'Add a document type' ?></h2>
    <?php if ($edit): ?><a class="btn sm line" href="<?= BASE_PATH ?>/organization/documents-tracked">Cancel</a><?php endif; ?></div>
  <?php if ($edit): ?><p class="hint" style="margin:-.3rem 0 .5rem">Editing <strong><?= e($edit['name']) ?></strong>
    (code <span class="mono"><?= e($edit['code']) ?></span> — the code stays fixed so existing records keep pointing at it).</p><?php endif; ?>
  <form method="post" action="<?= BASE_PATH ?>/organization/documents-tracked"
        style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <input type="hidden" name="action" value="<?= $edit ? 'update' : 'add' ?>">
    <?php if ($edit): ?><input type="hidden" name="type_id" value="<?= e($edit['id']) ?>"><?php endif; ?>
    <div style="flex:2;min-width:220px"><label for="ctName">Name</label>
      <input name="name" id="ctName" required maxlength="160" value="<?= e($edit['name'] ?? '') ?>" placeholder="e.g. X-ray Supervisor Certification" style="width:100%"></div>
    <div style="min-width:150px"><label for="ctCat">Category</label>
      <select name="category" id="ctCat">
        <?php foreach ($categories as $c): ?>
          <option value="<?= e($c) ?>" <?= ($edit['category'] ?? '') === $c ? 'selected' : '' ?>><?= e(FlxCoreHC\CatalogService::categoryLabel($c)) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div style="min-width:150px"><label for="ctRenew">Renews every</label>
      <select name="renewal_months" id="ctRenew">
        <?php foreach ($RENEW_CYCLES as $months => $lbl): ?>
          <option value="<?= e((string)$months) ?>" <?= $evRenew === (string)$months ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div style="min-width:170px"><label for="ctState">Jurisdiction</label>
      <select name="state" id="ctState">
        <option value="">National (all)</option>
        <?php foreach ($states as $code => $sname): ?>
          <option value="<?= e($code) ?>" <?= ($edit['state'] ?? '') === $code ? 'selected' : '' ?>><?= e($code) ?> — <?= e($sname) ?></option>
        <?php endforeach; ?>
      </select></div>
    <label class="chk" style="white-space:nowrap"><input type="checkbox" name="is_required" value="1" <?= !empty($edit['is_required']) ? 'checked' : '' ?>> Required</label>
    <button class="btn sm"><?= $edit ? 'Save changes' : 'Add type' ?></button>
  </form>
  <p class="hint" style="margin:.6rem 0 0;max-width:80ch">
    A short code is derived from the name automatically. Renewal cadence drives expiration reminders;
    leave it blank for documents that don't expire. Jurisdiction limits the type to organizations
    operating in that state or territory; leave blank for national.
  </p>
</div>

<div class="panel">
  <div class="topline"><h2>Your custom types</h2>
    <span class="hint"><?= count($custom) ?> defined</span></div>

  <?php if ($custom === []): ?>
    <div class="empty">No custom document types yet. The built-in set covers the standards; add your own above when you track something it doesn't.</div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>Name</th><th>Code</th><th>Category</th><th>Renews</th><th>Jurisdiction</th><th>Status</th><th></th></tr>
    <?php foreach ($custom as $t): ?>
    <tr<?= $t['retired_at'] ? ' style="opacity:.55"' : '' ?>>
      <td><strong><?= e($t['name']) ?></strong>
        <?php if (!empty($t['is_required'])): ?><span class="chip warn" style="font-size:.62rem">required</span><?php endif; ?></td>
      <td class="mono" style="font-size:.78rem"><?= e($t['code']) ?></td>
      <td><?= e(FlxCoreHC\CatalogService::categoryLabel((string) $t['category'])) ?></td>
      <td class="sub"><?= $t['renewal_months'] ? (int) $t['renewal_months'] . ' mo' : '—' ?></td>
      <td class="sub"><?= $t['state'] ? e($t['state']) : 'National' ?></td>
      <td><?= $t['retired_at'] ? '<span class="chip">retired</span>' : '<span class="chip active">active</span>' ?></td>
      <td class="right">
        <a class="btn line sm" href="<?= BASE_PATH ?>/organization/documents-tracked?edit=<?= e($t['id']) ?>#type-form">Edit</a>
        <form method="post" action="<?= BASE_PATH ?>/organization/documents-tracked" style="margin:0;display:inline"
              <?= $t['retired_at'] ? '' : 'data-confirm="Retire this type? Existing records keep it; it can no longer be newly assigned."' ?>>
          <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
          <input type="hidden" name="action" value="<?= $t['retired_at'] ? 'restore' : 'retire' ?>">
          <input type="hidden" name="type_id" value="<?= e($t['id']) ?>">
          <button class="btn line sm"><?= $t['retired_at'] ? 'Restore' : 'Retire' ?></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
