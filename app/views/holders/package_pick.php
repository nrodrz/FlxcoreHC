<?php
/** Curated packet builder. Expects $row, $picks, $templates. */
$title = FlxCoreHC\Repository\HolderRepository::displayName($row);
require FLXCOREHC_APP . '/views/holders/_provider_header.php';
$statusChip = ['active'=>'active','expiring'=>'expiring','expired'=>'expired'];
?>
<div class="topline"><h2><?= e(t('Generate credential packet')) ?></h2>
  <a class="btn line sm" href="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>"><?= e(t('Back to provider')) ?></a></div>
<p class="hint" style="max-width:70ch"><?= e(t('Choose exactly which credentials and their documents to include, then generate a single PDF packet — for a payer, a hospital, or an audit.')) ?></p>

<div class="panel">
  <form method="get" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/package" data-novalidate id="pkgform">
    <div class="grid2" style="margin-bottom:.8rem">
      <div><label><?= e(t('Packet purpose / template')) ?></label>
        <select name="regulator">
          <option value=""><?= e(t('All applicable regulators')) ?></option>
          <?php foreach (($templates ?? []) as $tpl): ?><option value=""><?= e($tpl['name']) ?></option><?php endforeach; ?>
        </select>
        <div class="hint" style="font-size:.7rem"><?= e(t('Leave on "all" to include everything, or pick a scope.')) ?></div>
      </div>
      <div style="display:flex;align-items:flex-end"><label style="font-weight:normal;font-size:.82rem"><input type="checkbox" id="pk_all" checked data-check-all=".pk-ck"> <?= e(t('Select all')) ?></label></div>
    </div>
    <?php if ($picks === []): ?>
      <div class="empty"><?= e(t('No credentials on file to include yet.')) ?></div>
    <?php else: ?>
    <table style="font-size:.85rem">
      <tr><th style="width:2rem"></th><th><?= e(t('Credential')) ?></th><th><?= e(t('Status')) ?></th><th><?= e(t('Expires')) ?></th><th><?= e(t('Documents')) ?></th></tr>
      <?php foreach ($picks as $p): ?>
      <tr>
        <td><input type="checkbox" class="pk-ck" name="ids[]" value="<?= e($p['id']) ?>" checked style="width:auto"></td>
        <td><strong><?= e($p['name']) ?></strong></td>
        <td><span class="chip <?= $statusChip[$p['status']] ?? '' ?>" style="font-size:.6rem"><?= e(t(ucfirst($p['status']))) ?></span></td>
        <td class="mono hint"><?= e(fmt_day((string)$p['expiry'])) ?></td>
        <td class="hint"><?= (int)$p['docs'] ?> <?= e(t('file(s)')) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <div style="margin-top:1rem;display:flex;gap:.6rem;align-items:center">
      <button class="btn gold" type="submit"><?= e(t('Generate packet (PDF)')) ?></button>
      <span class="hint"><?= e(t('Only checked items are included.')) ?></span>
    </div>
    <?php endif; ?>
  </form>
</div>
<script <?= nonce_attr() ?>>
// Serialize checked ids into a single ?ids= param the package route reads, and drop the array field.
document.getElementById('pkgform').addEventListener('submit', function(e){
  var ids = Array.from(document.querySelectorAll('.pk-ck:checked')).map(c=>c.value);
  document.querySelectorAll('.pk-ck').forEach(c=>c.removeAttribute('name'));
  var h=document.createElement('input'); h.type='hidden'; h.name='ids'; h.value=ids.join(','); this.appendChild(h);
});
</script>
