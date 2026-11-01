<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var array|null $import */ /** @var int $total */
$title = 'LEIE screening';
?>
<div class="crumb"><a href="<?= BASE_PATH ?>/admin">Platform admin</a></div>
<div class="topline"><h1><?= e(t('Exclusion screening')) ?></h1></div>

<p class="hint" style="max-width:80ch">
  The OIG List of Excluded Individuals/Entities is published monthly as a downloadable CSV (there is no
  OIG API). Load the current file here and it becomes the list every tenant's automated screening matches
  against. A match is a <strong>review signal</strong>, never a verdict — identity must be confirmed at
  <span class="mono">oig.hhs.gov</span> before any action.
</p>

<div class="panel" style="margin-bottom:1rem">
  <h2 style="margin-top:0"><?= e(t('Current list')) ?></h2>
  <?php if ($import): ?>
    <div class="kpis">
      <div class="kpi ok"><span class="n"><?= e($import['file_month']) ?></span><span class="l"><?= e(t('Vintage')) ?></span></div>
      <div class="kpi"><span class="n"><?= number_format((int)$total) ?></span><span class="l"><?= e(t('Exclusion rows')) ?></span></div>
      <div class="kpi"><span class="n"><?= e(fmt_dt((string)$import['imported_at'], 'm/d/Y')) ?></span><span class="l"><?= e(t('Imported')) ?></span></div>
    </div>
    <p class="hint"><?= e((string)($import['source_note'] ?? '')) ?> · Screening results are stamped with this vintage.</p>
    <form method="post" action="<?= BASE_PATH ?>/admin" style="margin-top:.6rem">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="action" value="sweep_leie">
      <button class="btn sm">Screen all providers against this list now</button>
      <span class="hint" style="margin-left:.4rem">Screens each active provider once per vintage; owners are emailed on potential matches.</span>
    </form>
    <p class="hint" style="margin-top:.5rem">To run continuously without clicking, schedule the cron entry (daily is fine — it only works when a new list is loaded):
      <br><span class="mono">/usr/local/bin/php <?= e(defined('FLXCOREHC_CRON_HINT_PATH') ? FLXCOREHC_CRON_HINT_PATH : '/home/USER/flxcred') ?>/cron/screen_leie.php</span></p>
  <?php else: ?>
    <p class="hint">No LEIE list is loaded yet. Until one is imported, automated screening reports that the list is unavailable.</p>
  <?php endif; ?>
</div>

<div class="panel">
  <h2 style="margin-top:0"><?= e(t('Load / update the list')) ?></h2>
  <form method="post" action="<?= BASE_PATH ?>/admin" class="stack" style="max-width:720px">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <input type="hidden" name="action" value="import_leie">

    <div><label>File month (vintage, YYYY-MM)</label>
      <input name="file_month" type="text" placeholder="<?= e(date('Y-m')) ?>" value="<?= e(date('Y-m')) ?>" style="max-width:160px">
      <div class="hint">The month the file represents. Re-importing the same month replaces it.</div></div>

    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin:.4rem 0">
      <button class="btn gold" name="mode" value="download" type="submit">Download current file from OIG</button>
      <span class="hint" style="align-self:center">Fetches <span class="mono">UPDATED.csv</span> directly (needs outbound HTTPS on the server).</span>
    </div>

    <div><label>…or paste the LEIE CSV</label>
      <textarea name="csv" rows="6" placeholder="LASTNAME,FIRSTNAME,MIDNAME,BUSNAME,GENERAL,SPECIALTY,UPIN,NPI,DOB,ADDRESS,CITY,STATE,ZIP,EXCLTYPE,EXCLDATE,REINDATE,WAIVERDATE,WVRSTATE&#10;…"></textarea>
      <div class="hint">Download the file from <span class="mono">oig.hhs.gov/exclusions/exclusions_list.asp</span>, open it, and paste its contents here if the server can't reach OIG directly.</div></div>

    <div><button class="btn" name="mode" value="paste" type="submit">Load pasted CSV</button></div>
  </form>
</div>

<div class="panel" style="margin-top:1.2rem">
  <h2 style="margin-top:0">SAM.gov exclusions <span class="hint" style="font-weight:normal">— government-wide debarment (name-based matching)</span></h2>
  <?php if (!empty($samImport)): ?>
    <div class="kpis">
      <div class="kpi ok"><span class="n"><?= e($samImport['file_month']) ?></span><span class="l"><?= e(t('Vintage')) ?></span></div>
      <div class="kpi"><span class="n"><?= number_format((int)($samTotal ?? 0)) ?></span><span class="l"><?= e(t('Exclusion rows')) ?></span></div>
      <div class="kpi"><span class="n"><?= e(fmt_dt((string)$samImport['imported_at'], 'm/d/Y')) ?></span><span class="l"><?= e(t('Imported')) ?></span></div>
    </div>
  <?php else: ?>
    <p class="hint">No SAM.gov list loaded. SAM records carry no NPI, so provider matching is by name only — always a review signal.</p>
  <?php endif; ?>
  <form method="post" action="<?= BASE_PATH ?>/admin" class="stack" style="max-width:720px;margin-top:.4rem">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <input type="hidden" name="action" value="import_sam">
    <div><label>File month (vintage, YYYY-MM)</label>
      <input name="file_month" type="text" placeholder="<?= e(date('Y-m')) ?>" value="<?= e(date('Y-m')) ?>" style="max-width:160px"></div>
    <div><label>Paste the SAM.gov exclusions CSV</label>
      <textarea name="csv" rows="6" placeholder="Classification,Name,First,Last,Exclusion Type,Exclusion Program,Excluding Agency,Activation Date,UEI,State&#10;…"></textarea>
      <div class="hint">Export from the SAM.gov exclusions search (CSV), or the exclusions public extract, and paste it here. The public extract/API needs a free SAM.gov API key; pasting does not. Header names are matched flexibly.</div></div>
    <div><button class="btn" type="submit">Load SAM.gov CSV</button></div>
  </form>
</div>

<div class="panel" style="margin-top:1.2rem">
  <h2 style="margin-top:0">State / territory Medicaid <span class="hint" style="font-weight:normal">— starting with Puerto Rico</span></h2>
  <?php if (!empty($medicaid)): ?>
    <table style="margin-bottom:.6rem">
      <tr><th>Jurisdiction</th><th>Vintage</th><th>Imported</th></tr>
      <?php foreach ($medicaid as $mi): ?>
      <tr><td><?= e(FlxCoreHC\MedicaidExclusionService::JURISDICTIONS[$mi['jurisdiction']] ?? $mi['jurisdiction']) ?></td>
          <td class="mono"><?= e($mi['file_month']) ?></td>
          <td class="mono"><?= e(fmt_dt((string)$mi['imported_at'], 'm/d/Y')) ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php else: ?>
    <p class="hint">No Medicaid list loaded yet. Puerto Rico Medicaid publishes its own exclusion list separate from the federal lists.</p>
  <?php endif; ?>
  <form method="post" action="<?= BASE_PATH ?>/admin" class="stack" style="max-width:720px;margin-top:.4rem">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <input type="hidden" name="action" value="import_medicaid">
    <div style="display:flex;gap:.6rem;flex-wrap:wrap">
      <div><label>Jurisdiction</label>
        <select name="jurisdiction" style="max-width:220px">
          <?php foreach (FlxCoreHC\MedicaidExclusionService::JURISDICTIONS as $code => $name): ?>
            <option value="<?= e($code) ?>"<?= $code === 'PR' ? ' selected' : '' ?>><?= e($name) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label>File month (YYYY-MM)</label>
        <input name="file_month" type="text" placeholder="<?= e(date('Y-m')) ?>" value="<?= e(date('Y-m')) ?>" style="max-width:140px"></div>
    </div>
    <div><label>Paste the Medicaid exclusion CSV</label>
      <textarea name="csv" rows="6" placeholder="last_name,first_name,business_name,npi,provider_type,reason,date&#10;(Spanish headers like apellido, nombre, entidad, motivo, fecha are also recognized)"></textarea>
      <div class="hint">Column headers are matched flexibly, in English or Spanish. NPI is used for exact matching when present; otherwise matching is by name.</div></div>
    <div><button class="btn" type="submit">Load Medicaid CSV</button></div>
  </form>
</div>
