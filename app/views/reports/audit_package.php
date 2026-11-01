<?php
/** @var FlxCoreHC\TenantContext $ctx */ /** @var array $holder */ /** @var array $score */
/** @var array $credentials */ /** @var array $compliance */ /** @var array $auditLog */
/** @var array $accessLog */ /** @var int $planYear */ /** @var string $generatedAt */
use FlxCoreHC\RiskScoreService;
$name = $holder['holder_type']==='organization' ? ($holder['org_name'] ?: 'Organization') : trim(($holder['first_name']??'').' '.($holder['last_name']??''));
$stateLabel=['active'=>'Active','expiring'=>'Expiring','expired'=>'Expired','missing'=>'Missing'];
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Credentialing Audit-Readiness — <?= e($name) ?></title>
<style>
@media print { .noprint{display:none!important} body{margin:0} @page{margin:1.5cm} }
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#1a1a1a;max-width:820px;margin:24px auto;padding:0 20px;line-height:1.5}
h1{font-size:20px;margin:0 0 2px} h2{font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:#555;border-bottom:2px solid #eee;padding-bottom:4px;margin:22px 0 10px}
.meta{color:#666;font-size:12px;margin-bottom:16px}
.scorebox{display:flex;align-items:center;gap:16px;border:1px solid #e3e3e3;border-radius:10px;padding:14px 18px;margin:14px 0}
.scoren{font-size:38px;font-weight:800;line-height:1}
.band{font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px;color:#fff}
table{width:100%;border-collapse:collapse;font-size:12px;margin:6px 0}
th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#777;border-bottom:1px solid #ddd;padding:5px 6px}
td{padding:5px 6px;border-bottom:1px solid #f0f0f0;vertical-align:top}
.pill{font-size:10px;font-weight:700;padding:1px 7px;border-radius:999px}
.ok{background:#e7f4ee;color:#2f7d5b}.warn{background:#fbf0df;color:#c77b21}.bad{background:#fbe9e7;color:#be4b42}.mut{background:#eef0f3;color:#555}
.btn{display:inline-block;background:#2563eb;color:#fff;border:none;border-radius:7px;padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none}
.foot{margin-top:26px;border-top:1px solid #eee;padding-top:12px;font-size:11px;color:#777}
.mono{font-family:ui-monospace,monospace}
</style></head><body>
<div class="noprint" style="text-align:right;margin-bottom:10px">
  <button class="btn" data-action="print">Print / Save as PDF</button>
  <a class="btn" style="background:#eef0f3;color:#333" href="<?= BASE_PATH ?>/reports">Back</a>
</div>

<h1>Credentialing Audit-Readiness Package</h1>
<div class="meta"><strong><?= e($name) ?></strong><?= $holder['holder_type']==='organization'?' (Organization)':' (Provider)' ?>
  <?php if(!empty($holder['npi'])): ?> · NPI <?= e($holder['npi']) ?><?php endif; ?>
  · <?= e($ctx->tenantName) ?> · <?= e(t('Reporting year {year}', ['year' => (int)$planYear])) ?> · Generated <?= e(fmt_dt($generatedAt, 'm/d/Y g:i A')) ?></div>

<div class="scorebox">
  <div><div class="scoren" style="color:<?= RiskScoreService::bandColor($score['band']) ?>"><?= (int)$score['score'] ?></div><div style="font-size:11px;color:#777">/ 100</div></div>
  <div>
    <span class="band" style="background:<?= RiskScoreService::bandColor($score['band']) ?>"><?= e(RiskScoreService::bandLabel($score['band'])) ?></span>
    <div style="font-size:12px;color:#555;margin-top:6px">
      <?= (int)($score['tally']['active'] ?? 0) ?> active · <?= (int)($score['tally']['expiring'] ?? 0) ?> expiring · <?= (int)($score['tally']['expired'] ?? 0) ?> expired · <?= (int)($score['tally']['missing'] ?? 0) ?> missing
      (of <?= (int)$score['required'] ?> applicable)
    </div>
  </div>
</div>

<?php if($score['gaps']): ?>
<h2>Open items to resolve</h2>
<table><tr><th>Requirement</th><th>Authority</th><th>State</th><th>Detail</th></tr>
<?php foreach($score['gaps'] as $g): $cls=$g['state']==='expired'?'bad':($g['state']==='missing'?'mut':'warn'); ?>
<tr><td><strong><?= e($g['title']) ?></strong></td><td class="mono"><?= e($g['authority']) ?></td>
<td><span class="pill <?= $cls ?>"><?= e($stateLabel[$g['state']]??$g['state']) ?></span></td><td><?= e($g['detail']) ?></td></tr>
<?php endforeach; ?></table>
<?php endif; ?>

<h2>Requirement evidence</h2>
<table><tr><th>Requirement</th><th>Authority</th><th>State</th><th>Evidence / detail</th></tr>
<?php if(empty($requirementRows)): ?><tr><td colspan="4" style="color:#999">No requirements are assigned to this provider.</td></tr><?php endif; ?>
<?php foreach(($requirementRows ?? []) as $e): $st=$e['status']??'missing';
  $cls=['active'=>'ok','expiring'=>'warn','expired'=>'bad','missing'=>'mut'][$st]??'mut'; ?>
<tr><td><?= e($e['name'] ?? $e['label'] ?? $e['token'] ?? 'Requirement') ?></td>
<td class="mono"><?= e(trim(($e['regulator'] ?? '') !== '' && $e['regulator'] !== null ? $e['regulator'] : (string)($e['authority'] ?? ''))) ?></td>
<td><span class="pill <?= $cls ?>"><?= e($stateLabel[$st]??$st) ?></span></td>
<td><?= e($e['credential_name'] ?? '') !== '' ? e($e['credential_name']) : 'No credential on file' ?><?= !empty($e['expiry'])?' (exp '.e(substr((string)$e['expiry'],0,10)).')':'' ?><?= !empty($e['evidence']) ? ' · '.(int)$e['evidence'].' document(s)' : '' ?></td></tr>
<?php endforeach; ?></table>

<h2>Credentials on file</h2>
<table><tr><th>Type</th><th>Name</th><th>Number</th><th>Expires</th><th>Status</th></tr>
<?php if(!$credentials): ?><tr><td colspan="5" style="color:#999">None on file.</td></tr><?php endif; ?>
<?php foreach($credentials as $c):
  $exp=$c['expiry_date']; $st = !$exp?'ok':($exp<today_local()?'bad':($exp<=today_local('+60 days')?'warn':'ok'));
  $lbl = !$exp?'Active':($exp<today_local()?'Expired':($exp<=today_local('+60 days')?'Expiring':'Active')); ?>
<tr><td class="mono"><?= e($c['credential_type']) ?></td><td><?= e($c['name']) ?></td>
<td class="mono"><?= e($c['credential_number'] ?: '—') ?></td><td class="mono"><?= e($exp ?: '—') ?></td>
<td><span class="pill <?= $st ?>"><?= $lbl ?></span></td></tr>
<?php endforeach; ?></table>

<h2>Organization compliance posture</h2>
<table><tr><th>Item</th><th>Category</th><th>Status</th></tr>
<?php foreach($compliance as $ci): $st=$ci['status']??'not_started'; $cls=in_array($st,['done','na'])?'ok':($st==='in_progress'?'warn':'mut'); ?>
<tr><td><?= e($ci['title']) ?></td><td class="mono"><?= e($ci['category']) ?></td><td><span class="pill <?= $cls ?>"><?= e(str_replace('_',' ',$st)) ?></span></td></tr>
<?php endforeach; ?></table>

<h2>Activity trail (most recent)</h2>
<table><tr><th>When</th><th>Action</th><th>Record</th></tr>
<?php foreach(array_slice($auditLog,0,12) as $a): ?>
<tr><td class="mono"><?= e($a['created_at']) ?></td><td><?= e($a['action']) ?></td><td class="mono"><?= e($a['entity_type']) ?></td></tr>
<?php endforeach; ?>
<?php foreach(array_slice($accessLog,0,8) as $a): ?>
<tr><td class="mono"><?= e($a['created_at']) ?></td><td><?= e($a['action']) ?> (access)</td><td class="mono"><?= e($a['entity_type'] ?: '—') ?></td></tr>
<?php endforeach; ?></table>

<div class="foot">
  This package was generated by FlxCoreHC from the organization's credentialing records as a point-in-time
  compliance snapshot for audit preparation. It contains no protected health information.
  Credential numbers shown belong to the named provider or organization. Generated <?= e(fmt_dt($generatedAt, 'm/d/Y g:i A')) ?>.
</div>
</body></html>
