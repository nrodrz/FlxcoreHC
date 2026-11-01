<?php
/** @var FlxCoreHC\TenantContext $ctx */ /** @var array $row */ /** @var array $creds */ /** @var array $docsByCred */
/** @var array $verifiers */ /** @var array $catalog */ /** @var array $coverageDetail */ /** @var array $assigned */
/** @var array $holderLocations */ /** @var array $holderGroups */ /** @var string $groupLabel */ /** @var array|null $tplCurrent */ /** @var array $org */
$name = FlxCoreHC\Repository\HolderRepository::displayName($row);
$title = 'Profile packet — ' . $name;
$catByCode = []; foreach ($catalog as $c) { $catByCode[$c['code']] = $c; }
$fmt = fn(?string $d) => $d ? fmt_day($d) : '—';
$statusWord = ['active' => 'Active', 'expiring' => 'Expiring', 'expired' => 'EXPIRED', 'suspended' => 'Suspended', 'pending' => 'Pending'];
$methodWord = ['primary_source' => 'Primary source', 'copy' => 'Copy on file', 'attestation' => 'Attestation'];
// required-but-missing from the applied template / assigned requirements
$have = []; foreach ($creds as $c) { $have[FlxCoreHC\CatalogService::codeFor((string)$c['credential_type'])] = true; }
$missing = [];
foreach ($assigned as $tok) { $code = preg_replace('/@.*$/', '', (string)$tok); if (!isset($have[$code]) && !str_starts_with($code, 'cr:')) $missing[] = $catByCode[$code]['name'] ?? $code; }
$generated = fmt_dt(utc_now(), 'm/d/Y g:i A');
?>
<style>
  .pk{max-width:860px;margin:0 auto;font-size:.88rem}
  .pk h1{font-size:1.4rem;margin:0}.pk h2{font-size:.95rem;margin:1.2rem 0 .4rem;border-bottom:1px solid var(--line,#ddd);padding-bottom:.2rem;text-transform:uppercase;letter-spacing:.04em}
  .pk table{width:100%;border-collapse:collapse}.pk th,.pk td{padding:.35rem .45rem;border-bottom:1px solid var(--line,#e5e5e5);vertical-align:top;text-align:left}
  .pk th{font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:var(--mut,#666)}
  .pk .meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.4rem 1.2rem;margin:.6rem 0}
  .pk .meta div{font-size:.85rem}.pk .meta b{display:block;font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;color:var(--mut,#666)}
  .pk .st-expired{color:#be4b42;font-weight:700}.pk .st-expiring{color:#b8741a;font-weight:600}.pk .st-active{color:#2f7d5b}
  .pk .doc{font-size:.78rem;color:var(--mut,#555)}.pk .foot{margin-top:1.6rem;font-size:.72rem;color:var(--mut,#666);border-top:1px solid var(--line,#ddd);padding-top:.5rem}
  .noprint{margin:0 0 1rem}
  @media print{ .noprint, nav, header, .sidebar, .topbar, footer, .flash{display:none !important} body{background:#fff} .pk{max-width:none} }
</style>
<div class="noprint" style="display:flex;gap:.5rem;align-items:center">
  <a class="btn sm line" href="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>">← Back to provider</a>
  <button class="btn sm" type="button" data-print>Print / Save as PDF</button>
  <span class="hint">Use your browser's print dialog and choose "Save as PDF" to produce the packet file.</span>
</div>
<div class="pk">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem">
    <div>
      <div class="hint" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.06em">Provider profile packet</div>
      <h1><?= e($name) ?></h1>
      <div class="hint"><?= e(FlxCoreHC\Vocab::appliesToOptions('healthcare')[$row['provider_type'] ?? ''] ?? ucfirst((string)($row['provider_type'] ?? 'Provider'))) ?>
        <?php if (!empty($row['role_title'])): ?> · <?= e($row['role_title']) ?><?php endif; ?></div>
    </div>
    <div style="text-align:right;font-size:.8rem">
      <strong><?= e($org['name'] ?? $ctx->tenantName) ?></strong><br>
      <?php if (!empty($org['contact_email'])): ?><?= e($org['contact_email']) ?><br><?php endif; ?>
      <?php if (!empty($org['contact_phone'])): ?><?= e($org['contact_phone']) ?><br><?php endif; ?>
      <span class="hint">Generated <?= e($generated) ?></span>
    </div>
  </div>

  <div class="meta">
    <div><b>NPI</b><?= e($row['npi'] ?? '—') ?></div>
    <div><b>Email</b><?= e($row['email'] ?? '—') ?></div>
    <div><b>Status</b><?= e(ucfirst((string)($row['status'] ?? ''))) ?></div>
    <?php if (!empty($row['npi_taxonomy'])): ?><div><b>Taxonomy</b><?= e($row['npi_taxonomy']) ?></div><?php endif; ?>
    <?php if (!empty($row['npi_city']) || !empty($row['npi_state'])): ?><div><b>NPPES location</b><?= e(trim(($row['npi_city'] ?? '') . ', ' . ($row['npi_state'] ?? ''), ', ')) ?></div><?php endif; ?>
    <?php if ($holderGroups): ?><div><b><?= e($groupLabel) ?><?= count($holderGroups) > 1 ? 's' : '' ?></b><?= e(implode(', ', array_map(fn($g) => $g['name'] . ($g['is_primary'] ? ' (primary)' : ''), $holderGroups))) ?></div><?php endif; ?>
    <?php if ($holderLocations): ?><div><b>Practice locations</b><?= e(implode(', ', array_map(fn($l) => $l['name'] . ($l['is_primary'] ? ' (primary)' : ''), $holderLocations))) ?></div><?php endif; ?>
    <?php if ($tplCurrent): ?><div><b>Compliance template</b><?= e($tplCurrent['name']) ?></div><?php endif; ?>
  </div>

  <?php $__cf = (new FlxCoreHC\CustomFieldService($ctx))->labelled('provider', (string)$row['id'], true); if ($__cf): ?>
  <h2>Additional information</h2>
  <div class="grid">
    <?php foreach ($__cf as $f): ?><div><b><?= e($f['label']) ?></b><?= e($f['value']) ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>
  <h2>Credentials (<?= count($creds) ?>)</h2>
  <?php if ($creds === []): ?><p class="hint">No credentials on file.</p><?php else: ?>
  <table data-no-stack>
    <tr><th>Credential</th><th>Number</th><th>Issuer</th><th>Issued</th><th>Expires</th><th>Status</th><th>Documents / verification</th></tr>
    <?php foreach ($creds as $c): $code = FlxCoreHC\CatalogService::codeFor((string)$c['credential_type']); $st = (string)($c['status'] ?? ''); ?>
    <tr>
      <td><strong><?= e($c['name'] ?: ($catByCode[$code]['name'] ?? $code)) ?></strong><div class="doc"><?= e($catByCode[$code]['name'] ?? '') ?></div></td>
      <td class="mono"><?= e($c['credential_number'] ?? '—') ?></td>
      <td><?= e($c['issuing_body'] ?? '—') ?></td>
      <td><?= $fmt($c['issued_date'] ?? null) ?></td>
      <td><?= $fmt($c['expiry_date'] ?? null) ?></td>
      <td class="st-<?= e($st) ?>"><?= e($statusWord[$st] ?? ucfirst($st)) ?></td>
      <td>
        <?php $ds = $docsByCred[$c['id']] ?? []; if ($ds === []): ?><span class="doc">No document attached</span>
        <?php else: foreach ($ds as $d): ?>
          <div class="doc"><?= e($d['original_name']) ?>
            <?php if (!empty($d['verified_at'])): ?> — <strong>Verified</strong> (<?= e($methodWord[$d['verification_method'] ?? ''] ?? 'verified') ?>)
              <?= $fmt(fmt_dt((string)$d['verified_at'], 'm/d/Y')) ?><?php if (!empty($d['verified_by']) && isset($verifiers[$d['verified_by']])): ?> by <?= e($verifiers[$d['verified_by']]) ?><?php endif; ?>
              <?php if (!empty($d['verification_expires'])): ?>; re-verify by <?= $fmt($d['verification_expires']) ?><?php endif; ?>
            <?php else: ?> — <em>not verified</em><?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <?php if ($missing !== []): ?>
  <h2>Required items not on file (<?= count($missing) ?>)</h2>
  <ul style="margin:.2rem 0 0 1rem"><?php foreach ($missing as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>

  <?php $screenings = $screenings ?? []; ?>
  <h2>Exclusion screening</h2>
  <?php if ($screenings === []): ?>
    <p class="hint">No exclusion screening recorded for this provider.</p>
  <?php else:
    $bySource = []; foreach ($screenings as $s) { $k = strtoupper((string)($s['source'] ?? '')); if (!isset($bySource[$k])) $bySource[$k] = $s; }
    $flagWords = ['match', 'excluded', 'hit', 'flag', 'flagged', 'possible_match'];
  ?>
  <table data-no-stack>
    <tr><th>Source</th><th>Last screened</th><th>Result</th><th>Reference</th></tr>
    <?php foreach ($bySource as $src => $s): $bad = in_array(strtolower((string)($s['result'] ?? '')), $flagWords, true); ?>
    <tr><td><?= e($src) ?></td><td><?= e($fmt($s['screened_on'] ?? null)) ?></td>
        <td><strong style="<?= $bad ? 'color:#be4b42' : '' ?>"><?= e($bad ? 'FLAGGED — review' : 'No match') ?></strong></td>
        <td class="mono"><?= e($s['reference'] ?? '—') ?></td></tr>
    <?php endforeach; ?>
  </table>
  <p class="hint" style="font-size:.72rem">Screening reflects the exclusion lists as of each "last screened" date (OIG LEIE, SAM.gov, state Medicaid where configured). It is a review signal, not a determination.</p>
  <?php endif; ?>

  <div class="foot">
    Prepared by <?= e($org['name'] ?? $ctx->tenantName) ?> from records maintained in FlxCoreHC. Credential numbers and dates are as recorded at the time of generation;
    "Verified" indicates a documented verification with the method shown. This packet contains protected personal information — share only with authorized parties.
  </div>
</div>
