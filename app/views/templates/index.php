<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */
$title = 'Compliance templates';
$byCode = [];
foreach ($catalog as $c) { $byCode[$c['code']] = $c; }
$regulatorNames = $regulatorNames ?? [];
// Labels + grouping come from the vertical's own vocabulary, so healthcare templates
// (physician/nurse/lab/clinic/group) group and display correctly instead of vanishing.
$roleLabel = FlxCoreHC\Vocab::appliesToOptions($ctx->vertical);
$lineLabel = FlxCoreHC\Vocab::lineOptions($ctx->vertical);
$lineHeading = $ctx->vertical === 'healthcare' ? 'Setting' : 'Lines';
$anchorHeading = $ctx->vertical === 'healthcare' ? 'Anchoring credential' : 'Anchoring licence';
$groups = [];
foreach ($templates as $t) { $groups[$t['applies_to']][] = $t; }
// Any applies_to value not in the vertical's known set still shows, appended at the end.
$__order = array_keys($roleLabel);
foreach (array_keys($groups) as $__g) { if (!in_array($__g, $__order, true)) $__order[] = $__g; }
?>
<p class="crumb"><?= e($ctx->tenantName) ?></p>
<div class="topline">
  <h1>Compliance templates</h1>
  <?php if ($canManage): ?><a class="btn" href="<?= BASE_PATH ?>/templates/new">New template</a><?php endif; ?>
</div>
<p class="hint" style="margin-top:-.4rem;max-width:70ch">
  A template says what a <?= e(strtolower(vocab('holder'))) ?> of a given type must have — the
  <?= 'licence, certifications, malpractice coverage, and training' ?>.
  Define it once, apply it to everyone of that type.
  These are starting points: rename, edit, or delete them to match how your organization actually operates.
</p>

<?php foreach ($__order as $role): if (empty($groups[$role])) continue; ?>
<div class="panel">
  <div class="topline"><h2><?= e($roleLabel[$role] ?? ucfirst($role)) ?></h2></div>
  <div class="table-wrap"><table>
    <tr><th>Template</th><th><?= e($anchorHeading) ?></th><th>State</th><th><?= e($lineHeading) ?></th><th>Requirements</th><th></th></tr>
    <?php foreach ($groups[$role] as $t): ?>
    <tr>
      <td><strong><?= e($t['name']) ?></strong>
        <?php if ($t['is_seed']): ?><span class="chip" style="font-size:.62rem;text-transform:uppercase;margin-left:.35rem">starter</span><?php endif; ?>
        <?php if (!empty($t['regulator_id']) && isset($regulatorNames[$t['regulator_id']])): ?><span class="chip" style="margin-left:.35rem"><?= e($regulatorNames[$t['regulator_id']]) ?></span><?php endif; ?>
        <?php if (!empty($t['description'])): ?><span class="sub"><?= e($t['description']) ?></span><?php endif; ?></td>
      <td><?= !empty($t['license_code']) && isset($byCode[$t['license_code']])
            ? '<span class="chip">' . e($byCode[$t['license_code']]['name']) . '</span>'
            : '<span class="hint">—</span>' ?></td>
      <td><?= !empty($t['state']) ? '<span class="chip">' . e($t['state']) . '</span>' : '<span class="hint">any</span>' ?></td>
      <td><span class="hint"><?= e($lineLabel[$t['line']] ?? $t['line']) ?></span></td>
      <td class="mono"><?= (int) $t['item_count'] ?></td>
      <td style="text-align:right">
        <a class="btn sm line" href="<?= BASE_PATH ?>/templates/<?= e($t['id']) ?>"><?= $canManage ? 'Edit' : 'View' ?></a>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php endforeach; ?>

<?php if ($templates === []): ?>
<div class="panel"><p class="hint" style="margin:0">No templates yet. <?= $canManage ? 'Create one to get started.' : '' ?></p></div>
<?php endif; ?>
