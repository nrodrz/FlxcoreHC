<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */ /** @var array|null $preview */ /** @var string $csv */
$title = 'Import credentials';
$rows = $preview['rows'] ?? [];
$sum  = $preview['summary'] ?? [];
$chip = ['create' => 'ok', 'error' => 'danger'];
?>
<div class="crumb"><a href="<?= BASE_PATH ?>/credentials">Credentials</a></div>
<div class="topline"><h1>Import credentials from CSV</h1></div>

<?php if (empty($rows)): ?>
  <div class="panel">
    <p class="hint" style="margin-top:0">Attach licenses, certifications, trainings and other items to providers you've already added or imported — one row per credential.
      <a href="<?= BASE_PATH ?>/credentials/import/template">Download the CSV template</a> to see the columns.</p>
    <p class="hint">Recognized columns: <span class="mono">npi, first_name, last_name, credential_type, name, credential_number, issuing_body, issued_date, expiry_date, jurisdiction, notes</span>.
      Identify the provider by <span class="mono">npi</span> <em>or</em> by <span class="mono">first_name</span> + <span class="mono">last_name</span>.
      <span class="mono">credential_type</span> is a code from <a href="<?= BASE_PATH ?>/organization/documents-tracked">Organization → Document types</a>
      (e.g. <span class="mono">state_medical_license, dea, malpractice, caqh, cme, board_cert</span>). Dates are <span class="mono">YYYY-MM-DD</span>.
      Leave <span class="mono">expiry_date</span> blank on a type that renews on a cycle and it is calculated from the issue date.</p>
    <form method="post" action="<?= BASE_PATH ?>/credentials/import" enctype="multipart/form-data" class="stack">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <div><label>CSV file</label><input type="file" name="file" accept=".csv,text/csv"></div>
      <div><label>…or paste rows</label><textarea name="csv" rows="6" placeholder="<?= e(FlxCoreHC\CredentialImportService::TEMPLATE_HEADER) ?>&#10;1234567893,,,state_medical_license,FL Medical License,ME123456,Board of Medicine,2024-07-01,2026-06-30,FL," style="width:100%;font-family:monospace;font-size:.82rem"><?= e($csv) ?></textarea></div>
      <div><button class="btn">Preview import</button></div>
    </form>
  </div>
<?php else: ?>
  <div class="panel" style="margin-bottom:1rem">
    <div class="topline"><h2 style="margin:0">Preview</h2>
      <span class="hint"><?= (int)($sum['create'] ?? 0) ?> to create · <?= (int)($sum['error'] ?? 0) ?> with errors</span></div>
    <p class="hint">Rows with errors are skipped; fix them in your file and re-upload, or import the good rows now.</p>
    <div class="table-wrap"><table>
      <tr><th>Line</th><th>Provider</th><th>Type</th><th>Name / number</th><th>Issued</th><th>Expires</th><th>Result</th></tr>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td class="mono"><?= (int)$r['line'] ?></td>
        <td><?= e($r['provider_label'] ?? ($r['npi'] ?? trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')))) ?></td>
        <td><?= e($r['type_label'] ?? $r['credential_type'] ?? '—') ?></td>
        <td><?= e($r['name'] ?? '—') ?><?php if (!empty($r['credential_number'])): ?> <span class="mono hint"><?= e($r['credential_number']) ?></span><?php endif; ?></td>
        <td class="mono"><?= (!empty($r['issued_date']) ? e(fmt_day($r['issued_date'])) : '—') ?></td>
        <td class="mono"><?= (!empty($r['expiry_date']) ? e(fmt_day($r['expiry_date'])) : '—') ?><?php if (!empty($r['derived_expiry'])): ?> <span class="chip" style="font-size:.6rem" title="Calculated from the issue date and this type's renewal cycle">auto</span><?php endif; ?></td>
        <td><span class="chip <?= $chip[$r['status']] ?? '' ?>"><?= e($r['status']) ?></span>
          <?php if (!empty($r['reason'])): ?><div class="hint" style="font-size:.72rem"><?= e($r['reason']) ?></div><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
  </div>
  <form method="post" action="<?= BASE_PATH ?>/credentials/import" style="display:flex;gap:.5rem;align-items:center">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <input type="hidden" name="step" value="commit"><textarea name="csv" hidden><?= e($csv) ?></textarea>
    <button class="btn" <?= (int)($sum['create'] ?? 0) === 0 ? 'disabled' : '' ?>>Import <?= (int)($sum['create'] ?? 0) ?> credential(s)</button>
    <a class="btn line" href="<?= BASE_PATH ?>/credentials/import">Start over</a>
  </form>
<?php endif; ?>
