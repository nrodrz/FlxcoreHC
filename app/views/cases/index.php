<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var array $cases @var array $queue @var array $types */
$title = 'Credentialing cases';
$statusChip = ['open'=>'','in_progress'=>'warn','ready_for_submission'=>'active','submitted'=>'active','completed'=>'ok','closed'=>'muted','cancelled'=>'muted'];
?>
<div class="crumb"><?= e($ctx->tenantName) ?></div>
<div class="topline"><h1><?= e(t('Credentialing cases')) ?></h1>
  <a class="btn gold" href="<?= BASE_PATH ?>/cases?new=1#new"><?= e(t('New case')) ?></a></div>
<?php if (!empty($queue['query_failures'])): ?>
  <div class="flash" style="border-color:var(--danger)"><?= e(t('Some work-queue figures could not be computed (logged). Numbers below may be incomplete — this is not a compliance result.')) ?></div>
<?php endif; ?>

<div class="kpis">
  <a class="kpi" href="<?= BASE_PATH ?>/cases"><span class="n"><?= (int)($queue['unassigned'] ?? 0) ?></span><span class="l"><?= e(t('Unassigned')) ?></span></a>
  <div class="kpi <?= ($queue['overdue_reqs'] ?? 0) ? 'danger' : '' ?>"><span class="n"><?= (int)($queue['overdue_reqs'] ?? 0) ?></span><span class="l"><?= e(t('Overdue requirements')) ?></span></div>
  <div class="kpi <?= ($queue['ready'] ?? 0) ? 'warn' : '' ?>"><span class="n"><?= (int)($queue['ready'] ?? 0) ?></span><span class="l"><?= e(t('Ready to submit')) ?></span></div>
  <div class="kpi <?= ($queue['hard_stops'] ?? 0) ? 'danger' : '' ?>"><span class="n"><?= (int)($queue['hard_stops'] ?? 0) ?></span><span class="l"><?= e(t('Hard stops')) ?></span></div>
  <div class="kpi <?= ($queue['needs_attention'] ?? 0) ? 'warn' : '' ?>"><span class="n"><?= (int)($queue['needs_attention'] ?? 0) ?></span><span class="l"><?= e(t('Needs attention')) ?></span></div>
  <div class="kpi"><span class="n"><?= (int)($queue['submitted'] ?? 0) ?></span><span class="l"><?= e(t('Submitted')) ?></span></div>
  <div class="kpi"><span class="n"><?= (int)($queue['approaching'] ?? 0) ?></span><span class="l"><?= e(t('Target ≤14 days')) ?></span></div>
</div>

<?php if (!empty($_GET['new'])): ?>
<div class="panel" id="new">
  <h2><?= e(t('New case')) ?></h2>
  <form method="post" action="<?= BASE_PATH ?>/cases" class="stack"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <input type="hidden" name="action" value="create">
    <?php $__pre = (string)($_GET['holder'] ?? ''); ?>
    <label><?= e(t('Provider')) ?> <select name="holder_id" required><option value=""><?= e(t('Choose a provider…')) ?></option>
      <?php foreach (($pickHolders ?? []) as $__h): $__n = trim(($__h['last_name'] ?? '') . ', ' . ($__h['first_name'] ?? ''), ', ') ?: (string)($__h['org_name'] ?? ''); ?>
        <option value="<?= e($__h['id']) ?>" <?= $__pre === $__h['id'] ? 'selected' : '' ?>><?= e($__n) ?></option>
      <?php endforeach; ?></select></label>
    <label><?= e(t('Case type')) ?>
      <select name="case_type"><?php foreach ($types as $k=>$v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
    <label><?= e(t('Client group')) ?> <span class="hint"><?= e(t('(optional)')) ?></span> <select name="client_group_id"><option value="">—</option>
      <?php foreach (($pickGroups ?? []) as $__g): ?><option value="<?= e($__g['id']) ?>"><?= e($__g['name'] ?? '') ?></option><?php endforeach; ?></select></label>
    <label><?= e(t('Payer')) ?> <span class="hint"><?= e(t('(optional)')) ?></span> <select name="payer_id"><option value="">—</option>
      <?php foreach (($pickPayers ?? []) as $__p): ?><option value="<?= e($__p['id']) ?>"><?= e($__p['name'] ?? '') ?></option><?php endforeach; ?></select></label>
    <label><?= e(t('Target date')) ?> <input type="date" name="target_date"></label>
    <label><?= e(t('Notes')) ?> <textarea name="notes" rows="2"></textarea></label>
    <button class="btn gold"><?= e(t('Open case')) ?></button>
  </form>
</div>
<?php endif; ?>

<div class="panel">
  <h2><?= e(t('All cases')) ?></h2>
  <?php if ($cases === []): ?><div class="empty"><?= e(t('No cases yet.')) ?></div>
  <?php else: ?>
  <table>
    <tr><th><?= e(t('Provider')) ?></th><th><?= e(t('Type')) ?></th><th><?= e(t('Status')) ?></th><th><?= e(t('Target')) ?></th><th></th></tr>
    <?php $__names = []; foreach (($pickHolders ?? []) as $__ph) { $__names[$__ph['id']] = trim(($__ph['last_name'] ?? '') . ', ' . ($__ph['first_name'] ?? ''), ', ') ?: (string)($__ph['org_name'] ?? ''); } ?>
    <?php foreach ($cases as $c): ?>
    <tr>
      <td><?= e($__names[$c['holder_id']] ?? t('Provider')) ?></td>
      <td><?= e($types[$c['case_type']] ?? $c['case_type']) ?></td>
      <td><span class="chip <?= $statusChip[$c['status']] ?? '' ?>"><?= e(t(ucfirst(str_replace('_',' ',$c['status'])))) ?></span></td>
      <td class="mono hint"><?= e(fmt_day((string)($c['target_date'] ?? ''))) ?></td>
      <td class="right"><a class="btn sm line" href="<?= BASE_PATH ?>/cases/<?= e($c['id']) ?>"><?= e(t('Open')) ?> →</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
