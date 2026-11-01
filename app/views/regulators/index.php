<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var array $byCategory */
$cats = FlxCoreHC\Repository\RegulatorRepository::CATEGORIES;
$title = 'Regulators'; ?>
<div class="topline"><h1>Regulators</h1></div>
<p class="hint" style="margin-top:-.4rem;max-width:78ch">Every entity your providers and organization must satisfy —
payers and insurance companies, government agencies (CMS, FDA, CDC), regulatory frameworks (HIPAA, OSHA),
accreditation bodies (The Joint Commission), and state boards across the US and Puerto Rico. Define each one and the
credentials and documents it requires; templates and provider requirements can then be scoped to it.</p>

<?php // Any stored category that is not (yet) a known one is still listed, never hidden.
$__extra = array_diff_key($byCategory ?? [], $cats); $__all = $cats; if ($__extra) { $__all['__other'] = 'Other'; $byCategory['__other'] = array_merge(...array_values($__extra)); }
foreach ($__all as $code => $label): $rows = $byCategory[$code] ?? []; ?>
<div class="panel">
  <div class="topline"><h2><?= e($label) ?></h2></div>
  <?php if ($rows === []): ?>
    <p class="hint" style="margin:0">None yet.</p>
  <?php else: ?>
    <table class="tbl"><tr><th>Name</th><th>Jurisdiction</th><th>Status</th></tr>
    <?php foreach ($rows as $r): ?>
      <tr><td><a href="<?= BASE_PATH ?>/regulators/<?= e($r['id']) ?>"><strong><?= e($r['name']) ?></strong></a>
        <?php if (!empty($r['short_name'])): ?><span class="sub"><?= e($r['short_name']) ?></span><?php endif; ?></td>
      <td><?= e($r['jurisdiction'] ?: 'National') ?></td>
      <td><span class="chip <?= $r['status']==='active'?'active':'' ?>"><?= e($r['status']) ?></span></td></tr>
    <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<div class="panel">
  <div class="topline"><h2>Add a regulator</h2></div>
  <form method="post" action="<?= BASE_PATH ?>/regulators">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="save">
    <div class="grid2">
      <div><label>Name</label><input type="text" name="name" required placeholder="e.g. Aetna, CMS, HIPAA, The Joint Commission"></div>
      <div><label>Category</label><select name="category"><?php foreach ($cats as $c => $l): ?><option value="<?= e($c) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label>Jurisdiction</label>
        <select name="jurisdiction">
          <option value="">National (all)</option>
          <?php foreach (($states ?? []) as $code => $sname): ?><option value="<?= e($code) ?>"><?= e($code) ?> — <?= e($sname) ?></option><?php endforeach; ?>
        </select></div>
      <div><label>Short name (optional)</label><input type="text" name="short_name" maxlength="20"></div>
    </div>
    <button class="btn" type="submit">Add regulator</button>
  </form>
</div>

