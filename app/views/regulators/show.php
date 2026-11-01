<?php /** @var array $row */ /** @var array $reqs */ /** @var array $catalog */
$cats = FlxCoreHC\Repository\RegulatorRepository::CATEGORIES;
$title = $row['name']; ?>
<div class="topline"><h1><?= e($row['name']) ?></h1>
  <a class="btn sm line" href="<?= BASE_PATH ?>/regulators">All regulators</a></div>
<p class="hint" style="margin-top:-.4rem"><?= e($cats[$row['category']] ?? $row['category']) ?> · <?= e($row['jurisdiction'] ?: 'National') ?></p>

<div class="panel">
  <div class="topline"><h2>Details</h2></div>
  <form method="post" action="<?= BASE_PATH ?>/regulators">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= e($row['id']) ?>">
    <div class="grid2">
      <div><label>Name</label><input type="text" name="name" value="<?= e($row['name']) ?>" required></div>
      <div><label>Category</label><select name="category"><?php foreach ($cats as $c => $l): ?><option value="<?= e($c) ?>"<?= $row['category']===$c?' selected':'' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label>Jurisdiction</label>
        <select name="jurisdiction">
          <option value="">National (all)</option>
          <?php foreach (($states ?? []) as $code => $sname): ?><option value="<?= e($code) ?>" <?= ($row['jurisdiction'] ?? '') === $code ? 'selected' : '' ?>><?= e($code) ?> — <?= e($sname) ?></option><?php endforeach; ?>
        </select></div>
      <div><label>Status</label><select name="status"><option value="active"<?= $row['status']==='active'?' selected':'' ?>>Active</option><option value="inactive"<?= $row['status']==='inactive'?' selected':'' ?>>Inactive</option></select></div>
    </div>
    <button class="btn" type="submit">Save</button>
  </form>
</div>

<div class="panel">
  <div class="topline"><h2>Required credentials &amp; documents</h2></div>
  <?php if ($reqs === []): ?><p class="hint">Nothing defined yet. Add what this regulator requires below.</p>
  <?php else: ?>
    <table class="tbl"><tr><th>Requirement</th><th>Kind</th><th>Renewal</th><th></th></tr>
    <?php foreach ($reqs as $q): ?>
      <tr><td><strong><?= e($q['name']) ?></strong><?php if (!empty($q['catalog_code'])): ?><span class="sub mono"><?= e($q['catalog_code']) ?></span><?php endif; ?>
        <?php if (!empty($q['notes'])): ?><span class="sub"><?= e($q['notes']) ?></span><?php endif; ?></td>
      <td><?= e($q['kind'] === 'document' ? 'Document' : 'Credential') ?></td>
      <td><?= $q['renewal_months'] ? e($q['renewal_months']) . ' mo' : '—' ?></td>
      <td><form method="post" action="<?= BASE_PATH ?>/regulators/<?= e($row['id']) ?>" style="display:inline">
        <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="del_req"><input type="hidden" name="req_id" value="<?= e($q['id']) ?>">
        <button class="btn sm line" type="submit">Remove</button></form></td></tr>
    <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <h3 style="margin-top:1rem">Add a requirement</h3>
  <form method="post" action="<?= BASE_PATH ?>/regulators/<?= e($row['id']) ?>">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="add_req">
    <div class="grid2">
      <div><label>Kind</label><select name="kind"><option value="credential">Credential (from catalog)</option><option value="document">Document</option></select></div>
      <div><label>Catalog credential <span class="hint" style="display:inline">(for credential kind)</span></label>
        <select name="catalog_code"><option value="">—</option>
        <?php foreach ($catalog as $c): ?><option value="<?= e($c['code']) ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div><label>Name <span class="hint" style="display:inline">(required for documents; overrides catalog name)</span></label>
        <input type="text" name="req_name" placeholder="e.g. HIPAA attestation, OSHA training certificate, COI"></div>
      <div><label>Renewal (months, optional)</label><input type="number" name="renewal_months" min="1" max="120"></div>
    </div>
    <div><label>Notes (optional)</label><input type="text" name="notes" maxlength="400"></div>
    <button class="btn" type="submit">Add requirement</button>
  </form>
</div>

