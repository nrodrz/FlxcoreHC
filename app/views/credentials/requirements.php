<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $catalog */ /** @var array $checklist */ /** @var bool $available */
$title = 'MA credential requirements';
$catLabel = ['certification'=>'Certification','license'=>'License','insurance'=>'Malpractice / liability','appointment'=>'Payer enrollment','training'=>'Training','background'=>'Background','contract'=>'Contract','identifier'=>'Identifier'];
$stChip = function($st){
  return match($st){ 'active'=>'<span class="chip active">active</span>', 'expiring'=>'<span class="chip expiring">expiring</span>',
    'expired'=>'<span class="chip expired">expired</span>', default=>'<span class="chip" style="opacity:.6">missing</span>' };
};
$hname = fn($h)=> ($h['holder_type']??'')==='organization' ? ($h['org_name']??'') : trim((string)($h['first_name']??'').' '.(string)($h['last_name']??''));
?>
<div class="crumb"><a href="<?= BASE_PATH ?>/credentials">Credentials</a> · Requirements</div>
<div class="topline"><h1>Credential requirements</h1>
  <a class="btn sm line" href="<?= BASE_PATH ?>/credentials">Back to credentials</a></div>
<p class="hint" style="margin-top:-.4rem">Tracks what providers and the organization must maintain to stay compliant with each regulator's rules. Coverage below reflects the credentials you've recorded for each provider.</p>

<?php if (!$available): ?>
  <div class="panel"><div class="empty">The requirements catalog isn't installed yet. A platform admin can apply it from
    <a href="<?= BASE_PATH ?>/admin/system">System &amp; updates → Update database</a>.</div></div>
<?php else: ?>

<?php if ($checklist === []): ?>
  <div class="panel"><div class="empty">Add a provider (physician, nurse, group or facility) to see their compliance checklist.
    <br><a class="btn gold" href="<?= BASE_PATH ?>/holders/new">Add provider</a></div></div>
<?php else: foreach ($checklist as $c): $h = $c['holder'];
  $req = $c['items']; $cov = $c['coverage'];
  $missing = 0; $exp = 0;
  foreach ($req as $r) { if (!(int)$r['is_required']) continue; $s = $cov[$r['code']] ?? null; if ($s===null) $missing++; elseif ($s==='expired'||$s==='expiring') $exp++; }
  $ok = $missing===0 && $exp===0;
?>
  <div class="panel">
    <div class="topline"><h2><?= e($hname($h)) ?> <span class="hint" style="font-weight:400">· <?= e(ucfirst($c['role'])) ?></span></h2>
      <span class="chip <?= $ok?'active':($missing?'expired':'expiring') ?>"><?= $ok ? 'Compliant' : ($exp ? t('{n} missing, {e} expiring', ['n' => $missing, 'e' => $exp]) : t('{n} missing', ['n' => $missing])) ?></span></div>
    <div class="table-wrap"><table>
      <tr><th>Required credential</th><th>Authority</th><th>Renewal</th><th>Status</th><th></th></tr>
      <?php foreach ($req as $r): $s = $cov[$r['code']] ?? null;
        $ctype = FlxCoreHC\CatalogService::typeForCode($r['code']);
        $addUrl = BASE_PATH . '/credentials/new?holder=' . e($h['id']) . '&type=' . e($ctype) . '&name=' . urlencode($r['name']);
        $needs = ($s === null || $s === 'expired' || $s === 'expiring');
      ?>
      <tr>
        <td><strong><?= e($r['name']) ?></strong><?php if(!(int)$r['is_required']): ?> <span class="hint">(optional)</span><?php endif; ?>
          <span class="sub"><?= e($r['notes']) ?></span></td>
        <td><span class="chip" style="text-transform:uppercase"><?= e($r['authority']) ?></span></td>
        <td class="mono"><?= $r['renewal_months'] ? (int)$r['renewal_months'].' mo' : '—' ?></td>
        <td><?= $stChip($s) ?></td>
        <td class="right" style="white-space:nowrap">
          <?php if ($needs): ?>
            <a class="btn sm" href="<?= $addUrl ?>"><?= $s === null ? 'Add' : 'Renew' ?></a>
          <?php else: ?>
            <a class="btn sm line" href="<?= $addUrl ?>">Update</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <div style="margin-top:.7rem"><a class="btn sm line" href="<?= BASE_PATH ?>/credentials/new?holder=<?= e($h['id']) ?>"><?= e(t('Add a credential for {name}', ['name' => $hname($h)])) ?></a></div>
  </div>
<?php endforeach; endif; ?>

<div class="panel">
  <div class="topline"><h2>Full catalog</h2><span class="hint"><?= e(t('{n} credential types tracked', ['n' => count($catalog)])) ?></span></div>
  <div class="table-wrap"><table>
    <tr><th>Credential</th><th>Category</th><th>Applies to</th><th>Authority</th><th>Renewal</th><th>Required</th></tr>
    <?php foreach ($catalog as $r): ?>
    <tr>
      <td><strong><?= e($r['name']) ?></strong><span class="sub"><?= e($r['notes']) ?></span></td>
      <td><?= e($catLabel[$r['category']] ?? $r['category']) ?></td>
      <td class="mono"><?= e(str_replace(',', ', ', $r['applies_to'])) ?></td>
      <td style="text-transform:uppercase"><?= e($r['authority']) ?></td>
      <td class="mono"><?= $r['renewal_months'] ? (int)$r['renewal_months'].' mo' : 'n/a' ?></td>
      <td><?= (int)$r['is_required'] ? '<span class="chip active">required</span>' : '<span class="hint">optional</span>' ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php endif; ?>
