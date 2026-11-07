<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */ /** @var array $res */ /** @var bool $canInvite */
$title = 'Import finished';
$rej = $res['rejected'] ?? [];
$chip = ['skip' => 'warn', 'limit' => 'warn', 'error' => 'danger'];
?>
<div class="crumb"><a href="<?= BASE_PATH ?>/holders">Providers</a></div>
<div class="topline"><h1>Import finished</h1></div>

<div class="kpis">
  <div class="kpi ok"><span class="n"><?= (int)($res['created'] ?? 0) ?></span><span class="l">Providers created</span></div>
  <div class="kpi <?= ($res['skipped'] ?? 0) > 0 ? 'warn' : '' ?>"><span class="n"><?= (int)($res['skipped'] ?? 0) ?></span><span class="l">Skipped (already there)</span></div>
  <div class="kpi <?= ($res['errors'] ?? 0) > 0 ? 'danger' : '' ?>"><span class="n"><?= (int)($res['errors'] ?? 0) ?></span><span class="l">Errors</span></div>
</div>

<?php if (empty($res['capacity'])): ?>
  <div class="panel warn" style="margin-bottom:1rem"><strong>Stopped at your plan limit.</strong> Some rows were not added. Upgrade your plan, then import the rows in the downloaded list.</div>
<?php endif; ?>
<?php if (!empty($res['no_checklist'])): ?>
  <div class="panel warn" style="margin-bottom:1rem"><strong><?= (int)$res['no_checklist'] ?> provider(s) arrived without a requirement checklist</strong> (no profession in the file, or no template for it).
    Open each from the Providers list and apply a template.</div>
<?php endif; ?>

<?php if (!empty($res['ids']) && $canInvite): ?>
  <div class="panel" style="margin-bottom:1rem">
    <h2 style="margin-top:0">Portal invites</h2>
    <p class="hint">Imported people have no portal login until they are invited. Nothing was e-mailed during the import.</p>
    <form method="post" action="<?= BASE_PATH ?>/holders/import/invite">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <button class="btn gold">Send portal invites to the <?= count($res['ids']) ?> new provider(s)</button>
    </form>
  </div>
<?php endif; ?>

<?php if ($rej): ?>
  <div class="panel">
    <div class="topline"><h2 style="margin:0">Rows that did not come in</h2>
      <a class="btn sm line" href="<?= BASE_PATH ?>/holders/import/rejected">Download this list (CSV)</a></div>
    <table>
      <tr><th>Line</th><th>Provider</th><th>Status</th><th>Reason</th></tr>
      <?php foreach (array_slice($rej, 0, 200) as $r):
        $who = $r['org_name'] ?: trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: '—'; ?>
      <tr><td class="mono"><?= (int)$r['line'] ?></td><td><?= e($who) ?></td>
        <td><span class="chip <?= $chip[$r['status']] ?? '' ?>"><?= e(ucfirst($r['status'])) ?></span></td><td class="hint"><?= e($r['reason'] ?? '') ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php if (count($rej) > 200): ?><p class="hint">Showing the first 200 of <?= count($rej) ?>; the download has them all.</p><?php endif; ?>
  </div>
<?php endif; ?>
<div style="margin-top:.8rem;display:flex;gap:.5rem"><a class="btn gold" href="<?= BASE_PATH ?>/holders">Go to providers</a><a class="btn line" href="<?= BASE_PATH ?>/credentials/import">Import their credentials next</a></div>
