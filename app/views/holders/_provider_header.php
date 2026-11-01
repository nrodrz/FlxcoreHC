<?php
/** Persistent provider header band. Expects $row, $ctx, and (optional) $level, $checklist. */
$hid = $row['id'];
$isOrg = ($row['holder_type'] ?? '') === 'organization';
$name = $isOrg ? ($row['org_name'] ?: '—') : trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ? $row['middle_name'][0] . '. ' : '') . ($row['last_name'] ?? '') . ($row['suffix'] ? ', ' . $row['suffix'] : ''));
$initials = strtoupper(mb_substr($row['first_name'] ?? ($row['org_name'] ?? '?'), 0, 1) . mb_substr($row['last_name'] ?? '', 0, 1));
$ptypeLabels = ['physician'=>'Physician','np'=>'Nurse Practitioner','pa'=>'Physician Assistant','nurse'=>'Nurse','pharmacist'=>'Pharmacist','dentist'=>'Dentist','therapist'=>'Therapist','behavioral'=>'Behavioral Health','chiropractor'=>'Chiropractor','technologist'=>'Technologist','other_pro'=>'Licensed Professional','group'=>'Medical Group','clinic'=>'Clinic','lab'=>'Laboratory'];
$ptype = $ptypeLabels[$row['provider_type'] ?? ''] ?? ($row['role_title'] ?? '');
$dob = FlxCoreHC\SensitiveFields::display($ctx, 'dob', $row['dob'] ?? null);
$ssn = FlxCoreHC\SensitiveFields::display($ctx, 'ssn_last4', $row['ssn_last4'] ?? null);
$tenant = $tenant ?? [];
$statusChip = ['active'=>'active','inactive'=>'muted','pending'=>'warn'];
$lvl = $level['level'] ?? null;
?>
<div class="crumb"><a href="<?= BASE_PATH ?>/holders"><?= e(t('Providers')) ?></a></div>
<div class="phdr">
  <div class="phdr-top">
    <div class="phdr-id">
      <div class="phdr-avatar"><?= e($initials) ?></div>
      <div>
        <h1 class="phdr-name"><?= e($name) ?>
          <span class="chip <?= $statusChip[$row['status']] ?? '' ?>" style="font-size:.6rem;vertical-align:middle"><?= e(ucfirst($row['status'] ?? '')) ?></span>
        </h1>
        <div class="phdr-sub">
          <?php if ($ptype): ?><span><?= e(t('Provider type')) ?>: <strong><?= e($ptype) ?></strong></span><?php endif; ?>
          <?php if (!empty($row['primary_specialty'])): ?><span><?= e(t('Specialty')) ?>: <strong><?= e($row['primary_specialty']) ?></strong><?php if (!empty($row['taxonomy_code'])): ?> <span class="hint">(<?= e($row['taxonomy_code']) ?>)</span><?php endif; ?></span><?php endif; ?>
          <?php if ($lvl !== null): ?><span class="chip <?= $lvl===0?'danger':($lvl>=4?'active':'warn') ?>" style="font-size:.6rem"><?= e(t('Level')) ?> <?= (int)$lvl ?></span><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="phdr-actions">
      <a class="btn line sm" href="<?= BASE_PATH ?>/holders/<?= e($hid) ?>/packet"><?= e(t('Profile packet')) ?></a>
      <a class="btn line sm" href="<?= BASE_PATH ?>/holders/<?= e($hid) ?>/edit"><?= e(t('Edit')) ?></a>
      <a class="btn gold sm" href="<?= BASE_PATH ?>/credentials/new?holder=<?= e($hid) ?>&return=holder"><?= e(t('Add credential')) ?></a>
      <?php if (FlxCoreHC\Authz::can($ctx, 'records.delete')): ?>
      <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($hid) ?>/delete" style="display:inline;margin:0" data-confirm="<?= e(t('Move this record to Deleted records? You can restore it later.')) ?>"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
        <button class="btn line sm" style="color:var(--danger)"><?= e(t('Delete')) ?></button></form>
      <?php endif; ?>
    </div>
  </div>
  <div class="phdr-info">
    <div class="phdr-col">
      <div class="phdr-col-h"><?= e(t('Provider information')) ?></div>
      <?php
        $prov = [];
        if ($dob) $prov[t('Date of birth')] = $dob;
        $prov[t('NPI')] = $row['npi'] ?: '—';
        if (!empty($row['npi_verified_at'])) $prov[t('NPI')] .= '  ✓';
        if ($ssn) $prov[t('SSN')] = $ssn;
        $prov[t('Email')] = $row['email'] ?: '—';
        if (!empty($row['caqh_id'])) $prov[t('CAQH ID')] = $row['caqh_id'];
        if (!empty($row['languages'])) $prov[t('Languages')] = $row['languages'];
        foreach ($prov as $k => $v): ?>
          <div class="phdr-kv"><span class="phdr-k"><?= e($k) ?></span><span class="phdr-v"><?= e($v) ?></span></div>
        <?php endforeach; ?>
    </div>
    <div class="phdr-col">
      <div class="phdr-col-h"><?= e(t('Organization')) ?></div>
      <div class="phdr-kv"><span class="phdr-k"><?= e(t('Organization')) ?></span><span class="phdr-v"><?= e($tenant['name'] ?? $ctx->tenantName) ?></span></div>
      <?php if (!empty($tenant['fein'])): ?><div class="phdr-kv"><span class="phdr-k"><?= e(t('Tax ID')) ?></span><span class="phdr-v"><?= e($tenant['fein']) ?></span></div><?php endif; ?>
      <?php if (!empty($row['medicaid_id'])): ?><div class="phdr-kv"><span class="phdr-k"><?= e(t('Medicaid ID')) ?></span><span class="phdr-v"><?= e($row['medicaid_id']) ?></span></div><?php endif; ?>
      <?php if (!empty($row['medicare_ptan'])): ?><div class="phdr-kv"><span class="phdr-k"><?= e(t('Medicare PTAN')) ?></span><span class="phdr-v"><?= e($row['medicare_ptan']) ?></span></div><?php endif; ?>
      <?php if (!empty($checklist['total'])): ?><div class="phdr-kv"><span class="phdr-k"><?= e(t('Compliance')) ?></span><span class="phdr-v"><?= (int)$checklist['pct'] ?>% · <?= (int)$checklist['done'] ?>/<?= (int)$checklist['total'] ?></span></div><?php endif; ?>
    </div>
  </div>
</div>
