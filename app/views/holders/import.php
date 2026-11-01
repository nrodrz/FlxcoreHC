<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */ /** @var array|null $preview */ /** @var string $csv */
$title = 'Import providers';
$rows = $preview['rows'] ?? [];
$sum  = $preview['summary'] ?? [];
$chip = ['create' => 'ok', 'skip' => 'warn', 'error' => 'danger'];
?>
<div class="crumb"><a href="<?= BASE_PATH ?>/holders">Providers</a></div>
<div class="topline"><h1>Import providers from CSV</h1></div>

<?php if (!empty($preview) && empty($preview['ok'])): ?>
  <div class="panel danger" style="margin-bottom:1rem"><strong>Couldn't read that file.</strong> <?= e($preview['error']) ?></div>
<?php endif; ?>

<?php if (empty($rows)): ?>
  <div class="panel">
    <p class="hint" style="max-width:80ch">Upload a CSV to add many providers at once. The first row must name the columns.
      Recognized columns: <span class="mono">holder_type, provider_type, first_name, last_name, org_name, npi, email, group, location, role_title</span>.
      <span class="mono">group</span> (client group / department) and <span class="mono">location</span> (practice site) are matched by name or created for you, so a whole roster lands with its structure in place.
      <span class="mono">provider_type</span> accepts: <span class="mono"><?= e(implode(', ', array_keys(FlxCoreHC\Vocab::appliesToOptions('healthcare')))) ?></span>.
      Rows with an NPI already on file are skipped automatically.</p>
    <p><a class="btn sm line" href="<?= BASE_PATH ?>/holders/import/template">Download CSV template</a></p>
    <form method="post" action="<?= BASE_PATH ?>/holders/import" enctype="multipart/form-data" class="stack" style="max-width:720px;margin-top:.6rem">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <div><label>Upload a .csv file</label><input type="file" name="file" accept=".csv,text/csv"></div>
      <div style="text-align:center;color:var(--muted);margin:.2rem 0">— or —</div>
      <div><label>Paste CSV rows</label>
        <textarea name="csv" rows="7" placeholder="<?= e(FlxCoreHC\ProviderImportService::TEMPLATE_HEADER) ?>&#10;individual,physician,Jane,Smith,,1234567893,jane@example.com,,"><?= e($csv) ?></textarea></div>
      <div><button class="btn gold">Preview import</button></div>
    </form>
  </div>
<?php else: ?>
  <div class="kpis">
    <div class="kpi ok"><span class="n"><?= (int)($sum['create'] ?? 0) ?></span><span class="l">Will be created</span></div>
    <div class="kpi <?= ($sum['skip'] ?? 0) > 0 ? 'warn' : '' ?>"><span class="n"><?= (int)($sum['skip'] ?? 0) ?></span><span class="l">Skipped (duplicates)</span></div>
    <div class="kpi <?= ($sum['error'] ?? 0) > 0 ? 'danger' : '' ?>"><span class="n"><?= (int)($sum['error'] ?? 0) ?></span><span class="l">Errors (won't import)</span></div>
  </div>
  <div class="panel">
    <div class="topline"><h2 style="margin:0">Preview</h2><span class="hint">Only the "create" rows will be added.</span></div>
    <table>
      <tr><th>Line</th><th>Provider</th><th>Type</th><th>NPI</th><th>Status</th><th>Note</th></tr>
      <?php foreach ($rows as $r):
        $who = $r['org_name'] ?: trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: '—'; ?>
      <tr>
        <td class="mono"><?= (int)$r['line'] ?></td>
        <td><?= e($who) ?></td>
        <td class="mono"><?= e($r['provider_type'] ?? '—') ?></td>
        <td class="mono"><?= e($r['npi'] ?? '—') ?></td>
        <td><span class="chip <?= $chip[$r['status']] ?? '' ?>"><?= e(ucfirst($r['status'])) ?></span></td>
        <td class="hint"><?= e($r['reason'] ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <div style="display:flex;gap:.5rem;margin-top:.8rem;align-items:center">
    <?php if ((int)($sum['create'] ?? 0) > 0): ?>
      <form method="post" action="<?= BASE_PATH ?>/holders/import">
        <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
        <input type="hidden" name="step" value="commit">
        <input type="hidden" name="csv" value="<?= e($csv) ?>">
        <button class="btn gold">Import <?= (int)$sum['create'] ?> provider(s)</button>
      </form>
    <?php else: ?>
      <span class="hint">Nothing to import — fix the errors and try again.</span>
    <?php endif; ?>
    <a class="btn line" href="<?= BASE_PATH ?>/holders/import">Start over</a>
  </div>
<?php endif; ?>
