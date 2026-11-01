<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */
$title = 'Entity compliance';
$catByCode = [];
foreach ($catalog as $c) { $catByCode[$c['code']] = $c; }
$userById = [];
foreach ($team as $u) { $userById[$u['id']] = $u; }
$score = $row['score'] ?? null;
$sc = fn($s) => $s === null ? 'var(--ink-3)' : ($s >= 90 ? 'var(--ok)' : ($s >= 70 ? 'var(--warn)' : 'var(--danger)'));
$statusChip = [
    'active'  => ['Active', 'active'],
    'expiring'=> ['Expiring', 'warn'],
    'expired' => ['Expired', 'danger'],
    'missing' => ['Missing', 'danger'],
];
?>
<p class="crumb"><?= e($ctx->tenantName) ?></p>
<div class="topline">
  <h1>Entity compliance</h1>
  <?php if ($score !== null): ?>
    <span class="chip" style="font-size:.95rem;color:<?= $sc($score) ?>;border-color:currentColor"><?= (int) $score ?> / 100</span>
  <?php endif; ?>
</div>
<p class="hint" style="margin-top:-.4rem;max-width:74ch">
  Your providers are not the only ones who must stay credentialed — <strong>the organization is too</strong>.
  Payers enrol the group under its own NPI and Medicaid/Medicare IDs, laboratories need a current CLIA
  certificate, facilities hold a state licence, and many contracts require accreditation and an exclusion
  check on the entity itself. This page tracks those entity-level duties and names who inside your
  organization owns each one.
</p>

<!-- Entity identity -->
<div class="panel">
  <div class="topline"><h2>Entity profile</h2></div>
  <form class="form-grid" method="post" action="<?= BASE_PATH ?>/entity">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <div><label>Legal name</label>
      <input name="legal_name" value="<?= e($tenant['legal_name'] ?? '') ?>" placeholder="As registered with the state" <?= $canManage ? '' : 'disabled' ?>></div>
    <div><label>FEIN</label>
      <input name="fein" value="<?= e($tenant['fein'] ?? '') ?>" placeholder="12-3456789" <?= $canManage ? '' : 'disabled' ?>>
      <span class="hint">Tax ID used on payer enrollment applications.</span></div>
    <?php $__ro = $canManage ? '' : 'disabled'; ?>
    <div><label>Group / organizational NPI <span class="hint" style="display:inline">(Type 2)</span></label>
      <input name="group_npi" inputmode="numeric" maxlength="10" value="<?= e($tenant['group_npi'] ?? '') ?>" placeholder="10 digits" <?= $__ro ?>></div>
    <div><label>Primary taxonomy code</label>
      <input name="taxonomy_code" maxlength="20" value="<?= e($tenant['taxonomy_code'] ?? '') ?>" placeholder="e.g. 207Q00000X" <?= $__ro ?>></div>
    <div><label>Medicare PTAN</label>
      <input name="medicare_ptan" maxlength="40" value="<?= e($tenant['medicare_ptan'] ?? '') ?>" <?= $__ro ?>></div>
    <div><label>Medicaid provider ID</label>
      <input name="medicaid_id" maxlength="40" value="<?= e($tenant['medicaid_id'] ?? '') ?>" <?= $__ro ?>></div>
    <div><label>CLIA number <span class="hint" style="display:inline">(if you run a lab)</span></label>
      <input name="clia_number" maxlength="20" value="<?= e($tenant['clia_number'] ?? '') ?>" placeholder="e.g. 40D1234567" <?= $__ro ?>></div>
    <div><label>State facility licence number</label>
      <input name="facility_license" maxlength="60" value="<?= e($tenant['facility_license'] ?? '') ?>" <?= $__ro ?>></div>
    <div class="full"><label>Accreditation <span class="hint" style="display:inline">(body and cycle)</span></label>
      <input name="accreditation" maxlength="120" value="<?= e($tenant['accreditation'] ?? '') ?>" placeholder="e.g. The Joint Commission — survey due 2027" <?= $__ro ?>></div>
    <?php if ($canManage): ?><div class="full"><button class="btn">Save entity profile</button></div><?php endif; ?>
  </form>
</div>

<!-- Template -->
<?php if ($canManage): ?>
<div class="panel">
  <div class="topline"><h2>Entity requirement template</h2></div>
  <form method="post" action="<?= BASE_PATH ?>/entity/apply-template" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end;margin:0">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <div style="flex:1;min-width:280px">
      <label>Template</label>
      <select name="template_id" style="width:100%">
        <?php foreach ($templates as $t): ?>
          <option value="<?= e($t['id']) ?>" <?= $tplCurrent && $tplCurrent['id'] === $t['id'] ? 'selected' : '' ?>>
            <?= e($t['name']) ?> (<?= (int) $t['item_count'] ?> requirements)</option>
        <?php endforeach; ?>
      </select>
      <span class="hint">
        <?php if ($tplCurrent): ?>Currently on <strong><?= e($tplCurrent['name']) ?></strong>.
        <?php else: ?>Apply an organization template (group practice, clinic, laboratory, facility) to load the entity's requirements.<?php endif; ?>
      </span>
    </div>
    <div><label>Mode</label>
      <select name="mode"><option value="replace">Replace</option><option value="merge">Add to existing</option></select></div>
    <button class="btn">Apply</button>
  </form>
</div>
<?php endif; ?>

<!-- Requirements with an accountable owner -->
<div class="panel">
  <div class="topline"><h2>Entity requirements</h2>
    <span class="hint"><?= count($assigned) ?> assigned</span></div>
  <?php if ($row === null || empty($row['rows'])): ?>
    <p class="hint" style="margin:0">No entity requirements yet. Apply an organization template above.</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>Requirement</th><th>Authority</th><th>Status</th><th>Evidence</th><th>Responsible person</th></tr>
    <?php foreach ($row['rows'] as $r):
      $tok  = $r['token'] ?? '';
      [$code, ] = FlxCoreHC\Repository\ProducerRequirementRepository::parse($tok);
      $cat  = $catByCode[$code] ?? null;
      $st   = $r['status'] ?? 'missing';
      [$lbl, $cls] = $statusChip[$st] ?? ['Missing', 'danger'];
      $owner = $assignees[$tok] ?? null;
    ?>
    <tr>
      <td><strong><?= e($r['name'] ?? $code) ?></strong>
        <?php if (!empty($cat['notes'])): ?>
          <span class="sub" style="max-width:52ch;display:block"><?= e($cat['notes']) ?></span>
        <?php endif; ?></td>
      <td><span class="chip" style="font-size:.66rem"><?= e(strtoupper((string)($r['authority'] ?? '—'))) ?></span>
        <?php if (!empty($cat['state'])): ?><span class="chip" style="font-size:.66rem"><?= e($cat['state']) ?></span><?php endif; ?></td>
      <td><span class="chip <?= e($cls) ?>"><?= e($lbl) ?></span></td>
      <td class="mono"><?= (int) ($r['evidence'] ?? 0) ?></td>
      <td>
        <?php if ($canManage): ?>
        <form method="post" action="<?= BASE_PATH ?>/entity/assign-owner" style="margin:0">
          <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
          <input type="hidden" name="token" value="<?= e($tok) ?>">
          <select name="user_id" data-autosubmit style="min-width:170px">
            <option value="">— unassigned —</option>
            <?php foreach ($team as $u): if (($u['role'] ?? '') === 'producer') continue; ?>
              <option value="<?= e($u['id']) ?>" <?= $owner === $u['id'] ? 'selected' : '' ?>><?= e($u['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php else: ?>
          <?= $owner && isset($userById[$owner]) ? e($userById[$owner]['full_name']) : '<span class="hint">—</span>' ?>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <p class="hint" style="margin-bottom:0">
    Naming a responsible person is how an obligation stops being "the organization's problem" and becomes someone's job.
    Renewal tasks for these requirements are assigned to whoever is named here.
  </p>
  <?php endif; ?>
</div>

<!-- Entity credentials -->
<div class="panel">
  <div class="topline"><h2>Entity credentials</h2>
    <a class="btn sm line" href="<?= BASE_PATH ?>/credentials/new?holder_id=<?= e($entity['id']) ?>">Add credential</a></div>
  <?php if (empty($creds)): ?>
    <p class="hint" style="margin:0">Nothing recorded yet. Add the facility licence, CLIA certificate, accreditation, and group malpractice coverage — with expiry dates, so renewals are tracked.</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>Credential</th><th>Number</th><th>Expires</th><th>Status</th><th></th></tr>
    <?php foreach ($creds as $c): ?>
    <tr>
      <td><?= e($c['name']) ?></td>
      <td class="mono"><?= e($c['credential_number'] ?? '—') ?></td>
      <td><?= (!empty($c['expiry_date']) ? e(fmt_day($c['expiry_date'])) : '—') ?></td>
      <td><span class="chip <?= $c['status'] === 'active' ? 'active' : ($c['status'] === 'expiring' ? 'warn' : 'danger') ?>"><?= e(ucfirst((string)$c['status'])) ?></span></td>
      <td style="text-align:right"><a class="btn sm line" href="<?= BASE_PATH ?>/credentials/<?= e($c['id']) ?>/edit">Open</a></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>

<p class="hint" style="margin-bottom:2rem">
  Operating states: <strong><?= e(implode(', ', $states)) ?></strong> — change these in
  <a href="<?= BASE_PATH ?>/settings">Settings</a>. Requirements and templates outside your operating states are hidden.
  References: NPPES (45 CFR 162.410), Medicare enrollment and revalidation (42 CFR 424.510–424.515),
  CLIA (42 CFR 493), OIG exclusions (42 U.S.C. 1320a-7).
  Verified content set <?= e(FlxCoreHC\CatalogService::RULESET_VERSION) ?>.
</p>
