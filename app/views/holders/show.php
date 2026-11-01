<?php $title = FlxCoreHC\Repository\HolderRepository::displayName($row);
require FLXCOREHC_APP . '/views/holders/_provider_header.php'; ?>
<?php $manage = true; require FLXCOREHC_APP . '/views/holders/_onboarding.php'; ?>

<?php // ---- Portal access: every producer should have an app login -----------------
if (($row['holder_type'] ?? '') === 'individual'):
  $pu = $portalUser ?? null;
  $canManageUsers = FlxCoreHC\Authz::can($ctx, 'team.manage');
?>

<?php // ---- Tabbed sections: the page was one long scroll of unrelated concerns ---- ?>
<div data-tabs>
<div class="tabbar" role="tablist">
  <button type="button" role="tab" data-tab="overview">Overview</button>
  <button type="button" role="tab" data-tab="checklist">Credentials<?php $__ck = $checklist ?? null; if ($__ck && $__ck['total']): ?> <span class="tb-count"><?= (int)$__ck['done'] ?>/<?= (int)$__ck['total'] ?></span><?php endif; ?></button>
  <button type="button" role="tab" data-tab="profile">Profile &amp; history</button>
  <button type="button" role="tab" data-tab="payers">Payers &amp; enrollment</button>
  <button type="button" role="tab" data-tab="share">Sharing</button>
  <button type="button" role="tab" data-tab="ce">CME / CE</button>
  <button type="button" role="tab" data-tab="activity">Activity</button>
</div>

<section class="tabpane" data-pane="overview">
<div class="panel" style="<?= $pu ? '' : 'border-left:4px solid var(--warn)' ?>">
  <div class="topline"><h2>Portal access</h2>
    <?php if ($pu): ?>
      <span class="chip <?= $pu['status']==='active'?'active':'expiring' ?>"><?= $pu['status']==='invited'?'invite pending':e($pu['status']) ?></span>
    <?php else: ?><span class="chip expired">no login</span><?php endif; ?>
  </div>
  <?php if ($pu): ?>
    <p class="hint" style="margin:.1rem 0 0">This provider signs in as <strong><?= e($pu['email']) ?></strong>
      to see their own compliance checklist and upload documents.
      <?php if ($pu['status'] === 'invited' && $canManageUsers): ?>
        The invite hasn't been accepted yet — <a href="<?= BASE_PATH ?>/team">resend it from Team</a>.
      <?php endif; ?>
    </p>
  <?php elseif ($canManageUsers): ?>
    <p class="hint" style="margin:.1rem 0 .6rem">Every provider should have a portal login so they can keep their own records current.
      Send an invite to <?= $row['email'] ? '<strong>'.e($row['email']).'</strong>' : 'their email' ?>.</p>
    <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/invite-portal"
          style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin:0">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <?php if (!$row['email']): ?><input type="email" name="email" placeholder="provider@email.com" required style="min-width:220px"><?php endif; ?>
      <button class="btn">Invite to portal</button>
    </form>
  <?php else: ?>
    <p class="hint" style="margin:.1rem 0 0">This provider has no portal login yet. An administrator can invite them.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php // ---- CMS-verified identity (NPPES) — the fields CMS owns, refreshed only by re-verification ----
if (($row['holder_type'] ?? '') === 'individual' || !empty($row['npi'])):
  $verified = !empty($row['npi_verified_at']);
  $canVerify = FlxCoreHC\Authz::can($ctx, 'records.write') && FlxCoreHC\Authz::can($ctx, 'npi.lookup');
?>
<div class="panel" id="npi" style="<?= $verified ? 'border-left:4px solid var(--ok,#15803d)' : '' ?>">
  <div class="topline"><h2>CMS-verified identity</h2>
    <?php if ($verified): ?>
      <span class="chip active" title="Confirmed against the CMS NPPES registry">✓ NPI verified</span>
    <?php else: ?><span class="chip" style="opacity:.6">not verified</span><?php endif; ?>
  </div>

  <?php if ($verified): ?>
    <p class="hint" style="margin:.1rem 0 .7rem">These fields are owned by the CMS registry and are read-only.
      They were last confirmed <strong><?= e(fmt_dt($row['npi_verified_at'])) ?> ET</strong>. Re-verify to refresh
      them from CMS — your organization's details below are never affected.</p>
    <div class="table-wrap"><table class="kv">
      <tr><th style="width:180px">NPI</th><td class="mono"><?= e($row['npi']) ?></td></tr>
      <tr><th>Legal name</th><td><?= e($row['npi_legal_name'] ?? '') ?: '—' ?></td></tr>
      <tr><th>Entity type</th><td><?= e(ucfirst((string)($row['npi_entity_type'] ?? ''))) ?: '—' ?></td></tr>
      <?php if (!empty($row['npi_credential'])): ?><tr><th>Credential</th><td><?= e($row['npi_credential']) ?></td></tr><?php endif; ?>
      <tr><th>Taxonomy / specialty</th><td><?= e($row['npi_taxonomy'] ?? '') ?: '—' ?></td></tr>
      <tr><th>Practice location</th><td><?= e(trim(($row['npi_city'] ?? '') . ', ' . ($row['npi_state'] ?? ''), ', ')) ?: '—' ?></td></tr>
    </table></div>
    <?php if ($canVerify): ?>
    <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/verify-npi" style="margin-top:.7rem" id="reverifyForm">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <button class="btn line sm" id="reverifyBtn">Re-verify from CMS</button>
      <span class="hint" id="reverifyMsg" style="margin-left:.5rem"></span>
    </form>
    <?php endif; ?>
  <?php elseif ($canVerify): ?>
    <p class="hint" style="margin:.1rem 0 .7rem">Confirm this provider's identity against the CMS NPPES registry.
      Once verified, the CMS-owned fields (legal name, specialty, practice location) are locked to the registry
      and shown read-only here — while your editable details stay separate.</p>
    <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/verify-npi"
          style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin:0" id="verifyForm">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="text" name="npi" inputmode="numeric" maxlength="10" placeholder="10-digit NPI"
             value="<?= e($row['npi'] ?? '') ?>" style="max-width:180px">
      <button class="btn" id="verifyBtn">Verify NPI</button>
      <span class="hint" id="verifyMsg"></span>
    </form>
  <?php else: ?>
    <p class="hint" style="margin:.1rem 0 0">This provider's NPI is not yet verified against CMS.</p>
  <?php endif; ?>

  <?php /* NPPES confirms identity (NPI), not state licensure. Say so plainly so nobody reads an NPI
           check as a licence verification. */ ?>
  <p class="hint" style="margin:.9rem 0 0;padding-top:.7rem;border-top:1px solid var(--line);max-width:74ch">
    <strong>NPI verification is not licence verification.</strong> The check above confirms identity against
    the CMS NPPES registry. Verify each state licence, DEA registration and board certification with the
    issuing board (primary source) and record it on that credential.
  </p>
</div>
<script <?= nonce_attr() ?>>
(function () {
  // Show a spinner while a verify/re-verify POST is in flight.
  ['verifyForm', 'reverifyForm'].forEach(function (fid) {
    var f = document.getElementById(fid);
    if (!f) return;
    f.addEventListener('submit', function () {
      var b = f.querySelector('button');
      if (b) { b.disabled = true; b.dataset.label = b.textContent; b.textContent = 'Checking CMS…'; }
      var m = f.querySelector('.hint'); if (m) m.textContent = '⏳ contacting the registry…';
    });
  });
})();
</script>
<?php endif; ?>

<div class="panel">
  <table>
    <tr><th>Type</th><th>NPI</th><th>Email</th><th>Role</th></tr>
    <tr>
      <td><?= $row['holder_type'] === 'organization' ? 'Organization' : 'Individual' ?></td>
      <td class="mono"><?= e(($row['npi'] ?? '') ?: '—') ?></td>
      <td><?= e(($row['email'] ?? '') ?: '—') ?></td>
      <td><?= e(($row['role_title'] ?? '') ?: '—') ?></td>
    </tr>
  </table>
</div>

<?php // ---- Provider readiness (six named sub-scores) ----
$legacyReadiness = $legacyReadiness ?? null;
if ($legacyReadiness):
  $barColor = function(?int $s) { if ($s === null) return 'var(--line)'; return $s >= 90 ? 'var(--ok)' : ($s >= 60 ? 'var(--warn)' : 'var(--danger)'); };
  $ov = $legacyReadiness['overall']; ?>
<div class="panel">
  <div class="topline"><h2>Readiness</h2>
    <?php if ($ov !== null): ?><span class="chip <?= $ov>=90?'active':($ov>=60?'warn':'expired') ?>" style="font-size:.82rem"><?= (int)$ov ?>% overall</span><?php endif; ?>
  </div>
  <p class="hint" style="margin:-.2rem 0 .8rem"><?= e(fmt_iso_text((string)$legacyReadiness['summary'])) ?></p>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:.7rem 1.1rem">
    <?php foreach ($legacyReadiness['scores'] as $s): ?>
      <div>
        <div style="display:flex;justify-content:space-between;font-size:.82rem;font-weight:600">
          <span><?= e($s['label']) ?></span>
          <span style="color:<?= $barColor($s['score']) ?>"><?= $s['score'] === null ? 'N/A' : (int)$s['score'].'%' ?></span>
        </div>
        <div class="bar" style="margin:.25rem 0 .2rem"><i style="width:<?= $s['score'] === null ? 0 : (int)$s['score'] ?>%;background:<?= $barColor($s['score']) ?>"></i></div>
        <span class="hint" style="font-size:.74rem"><?= e(fmt_iso_text((string)$s['detail'])) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="hint" style="margin:.7rem 0 0;font-size:.72rem">Management-oriented readiness — what to work on next, not a legal-compliance determination.</p>
</div>
<?php endif; ?>

<?php // ---- Applications / enrollments for this provider ----
$applications = $applications ?? [];
$aS = FlxCoreHC\Repository\ApplicationRepository::STATUSES;
$aChip = function(string $s) { return match($s) {
  'approved' => 'active', 'denied' => 'expired', 'under_review','submitted' => 'gold',
  'withdrawn' => '', default => 'warn' }; }; ?>
<div class="panel">
  <div class="topline"><h2>Applications &amp; enrollments</h2>
    <a class="btn sm line" href="<?= BASE_PATH ?>/applications/new?holder=<?= e($row['id']) ?>">+ New</a></div>
  <?php if ($applications === []): ?>
    <p class="hint" style="margin:0">No enrollment or credentialing applications tracked for this provider yet.</p>
  <?php else: ?>
    <table class="tbl"><tr><th>Application</th><th>Status</th><th>Submitted</th></tr>
      <?php foreach ($applications as $a): ?>
        <tr><td><a href="<?= BASE_PATH ?>/applications/<?= e($a['id']) ?>"><?= e($a['title']) ?></a></td>
          <td><span class="chip <?= $aChip($a['status']) ?>"><?= e($aS[$a['status']] ?? $a['status']) ?></span></td>
          <td class="mono"><?= $a['submitted_on'] ? e(fmt_day((string)$a['submitted_on'])) : '—' ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
</section>
<section class="tabpane" data-pane="checklist" hidden>
<div class="panel">
  <div class="topline"><h2>Requirements &amp; documents</h2>
    <div style="display:flex;gap:.5rem;align-items:center">
    <a class="btn sm line" href="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/cv" title="Download a professional CV / credential summary assembled from the data on file">CV (PDF)</a>
    <a class="btn sm line" href="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/package" title="Download a PDF credential package for this provider — licenses, certifications, malpractice, and evidence index">Credential package (PDF)</a>
    </div>
  </div>
  <?php require FLXCOREHC_APP . '/views/holders/_checklist.php'; ?>
</div>
<div class="panel">



  <?php // ---- Regulators this provider answers to ----
  $assignedRegulators = $assignedRegulators ?? [];
  $regsByCat = $regulatorsByCategory ?? [];
  $catLabels = FlxCoreHC\Repository\RegulatorRepository::CATEGORIES; ?>
  <div style="background:var(--surface-2);border-radius:9px;padding:.85rem 1rem;margin:.2rem 0 1rem">
    <div class="topline"><h3 style="font-size:.92rem;margin:0">Regulators this provider answers to</h3>
      <a class="hint" style="color:var(--accent)" href="<?= BASE_PATH ?>/regulators">Manage regulators &rarr;</a></div>
    <p class="hint" style="margin:.2rem 0 .6rem">Tick each payer, agency, framework, accreditor or board that applies. Their required credentials and documents are added to this provider's checklist automatically and shown grouped by regulator in <a href="<?= BASE_PATH ?>/compliance">Compliance</a>.</p>
    <?php if (($regulatorList ?? []) === []): ?>
      <p class="hint">No regulators defined yet — <a href="<?= BASE_PATH ?>/regulators">add the payers, agencies and boards</a> this organization works with.</p>
    <?php else: ?>
    <form method="post" action="<?= BASE_PATH ?>/compliance/assign-regulators">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="holder_id" value="<?= e($row['id']) ?>">
      <?php foreach ($catLabels as $cCode => $cLabel): $rows = $regsByCat[$cCode] ?? []; if ($rows === []) continue; ?>
        <div style="margin-bottom:.5rem"><strong style="font-size:.76rem;color:var(--mut)"><?= e($cLabel) ?></strong>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.3rem .8rem;margin-top:.25rem">
          <?php foreach ($rows as $rg): ?>
            <label style="font-weight:400;display:flex;gap:.4rem;align-items:center;font-size:.85rem">
              <input type="checkbox" name="regulator_ids[]" value="<?= e($rg['id']) ?>" style="width:auto" <?= in_array($rg['id'], $assignedRegulators, true) ? 'checked' : '' ?>>
              <?= e($rg['name']) ?><?php if (!empty($rg['jurisdiction'])): ?> <span class="chip" style="font-size:.6rem"><?= e($rg['jurisdiction']) ?></span><?php endif; ?></label>
          <?php endforeach; ?>
        </div></div>
      <?php endforeach; ?>
      <div style="margin-top:.6rem"><button class="btn sm">Save regulators</button></div>
    </form>
    <?php endif; ?>
  </div>

  <?php // ---- Provider attestations ----
  $attestations = $attestations ?? [];
  $attMethods = FlxCoreHC\Repository\AttestationRepository::METHODS; ?>
  <div style="background:var(--surface-2);border-radius:9px;padding:.85rem 1rem;margin:.2rem 0 1rem">
    <div class="topline"><h3 style="font-size:.92rem;margin:0">Attestations</h3></div>
    <p class="hint" style="margin:.2rem 0 .6rem">A signed statement that the provider's information and documents are accurate and current — the record a payer or auditor expects behind delegated credentialing. Providers can also attest themselves from their portal.</p>
    <?php if ($attestations !== []): ?>
    <table class="tbl" style="margin-bottom:.6rem"><tr><th>Date</th><th>Statement</th><th>Method</th><th>By</th></tr>
      <?php foreach ($attestations as $a): ?>
        <tr><td class="mono"><?= e(substr((string)$a['attested_on'],0,10)) ?></td>
          <td><?= e($a['statement']) ?></td>
          <td><?= e($attMethods[$a['method']] ?? $a['method']) ?></td>
          <td><?= e($a['attested_by'] ?: '—') ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?><p class="hint">No attestations on file yet.</p><?php endif; ?>
    <form method="post" action="<?= BASE_PATH ?>/compliance/attest">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="holder_id" value="<?= e($row['id']) ?>">
      <div><label>Statement</label><input type="text" name="statement" maxlength="600" value="<?= e(FlxCoreHC\Repository\AttestationRepository::DEFAULT_STATEMENT) ?>"></div>
      <div class="grid2" style="margin-top:.4rem">
        <div><label>Method</label><select name="method"><?php foreach ($attMethods as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div><label>Attested by <span class="hint" style="display:inline">(defaults to you)</span></label><input type="text" name="attested_by" placeholder="<?= e($ctx->userName ?? '') ?>"></div>
      </div>
      <div style="margin-top:.5rem"><button class="btn sm">Record attestation</button></div>
    </form>
  </div>

  <?php // ---- Group membership (client groups / departments) ----
  $holderGroups = $holderGroups ?? []; $allGroups = $allGroups ?? []; $groupLabel = $groupLabel ?? 'Group'; ?>
  <div class="panel" id="groups" style="margin-top:1rem">
    <div class="topline"><h3 style="font-size:.92rem;margin:0"><?= e(t($groupLabel . ' membership')) ?></h3>
      <a class="hint" href="<?= BASE_PATH ?>/groups" style="font-size:.75rem"><?= e(t('Manage')) ?></a></div>
    <?php if ($holderGroups !== []): ?>
      <table style="margin-bottom:.6rem">
        <?php foreach ($holderGroups as $hg): ?>
        <tr>
          <td><strong><?= e($hg['name']) ?></strong>
            <?php if ($hg['is_primary']): ?><span class="chip gold"><?= e(t('Primary')) ?></span><?php endif; ?>
            <?php if (!empty($hg['role_in_group'])): ?><span class="sub"><?= e($hg['role_in_group']) ?></span><?php endif; ?>
            <?php if (($hg['status'] ?? '') !== 'active'): ?><span class="chip"><?= e(t('Inactive')) ?></span><?php endif; ?></td>
          <td class="right">
            <?php if (!$hg['is_primary']): ?>
            <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/primary-group" style="display:inline">
              <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="group_id" value="<?= e($hg['group_id']) ?>">
              <button class="btn sm line"><?= e(t('Make primary')) ?></button></form>
            <?php endif; ?>
            <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/leave-group" style="display:inline">
              <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="group_id" value="<?= e($hg['group_id']) ?>">
              <button class="btn sm line"><?= e(t('Remove')) ?></button></form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php else: ?><p class="hint"><?= e(t('Not in any ' . strtolower($groupLabel) . ' yet.')) ?></p><?php endif; ?>
    <?php
      $memberIds = array_column($holderGroups, 'group_id');
      $availableG = array_filter($allGroups, fn($g) => !in_array($g['id'], $memberIds, true));
    ?>
    <?php if ($availableG !== []): ?>
      <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/join-group" style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center">
        <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
        <select name="group_id" required style="max-width:220px">
          <option value=""><?= e(t('Choose…')) ?></option>
          <?php foreach ($availableG as $g): ?><option value="<?= e($g['id']) ?>"><?= e($g['name']) ?></option><?php endforeach; ?>
        </select>
        <input name="role_in_group" placeholder="<?= e(t('Role (optional)')) ?>" style="max-width:170px">
        <label style="font-weight:normal;font-size:.8rem"><input type="checkbox" name="is_primary" value="1"> <?= e(t('Primary')) ?></label>
        <button class="btn sm gold"><?= e(t('Add')) ?></button>
      </form>
    <?php elseif ($allGroups === []): ?>
      <p class="hint"><?= e(t('None defined yet.')) ?> <a href="<?= BASE_PATH ?>/groups"><?= e(t('Add one')) ?></a>.</p>
    <?php endif; ?>
  </div>

  <?php // ---- Practice locations (sites the provider practices at) ----
  $holderLocations = $holderLocations ?? []; $allLocations = $allLocations ?? []; ?>
  <div class="panel" id="locations" style="margin-top:1rem">
    <div class="topline"><h3 style="font-size:.92rem;margin:0"><?= e(t('Practice locations')) ?></h3>
      <a class="hint" href="<?= BASE_PATH ?>/locations" style="font-size:.75rem"><?= e(t('Manage locations')) ?></a></div>
    <?php if ($holderLocations !== []): ?>
      <table style="margin-bottom:.6rem">
        <?php foreach ($holderLocations as $hl): ?>
        <tr>
          <td><strong><?= e($hl['name']) ?></strong>
            <?php if ($hl['is_primary']): ?><span class="chip gold"><?= e(t('Primary')) ?></span><?php endif; ?>
            <?php if (!empty($hl['role_at_site'])): ?><span class="sub"><?= e($hl['role_at_site']) ?></span><?php endif; ?>
            <?php if (($hl['status'] ?? '') !== 'active'): ?><span class="chip"><?= e(t('Inactive')) ?></span><?php endif; ?></td>
          <td class="sub"><?= e(trim(($hl['city'] ?? '') . ' ' . ($hl['state'] ?? ''))) ?></td>
          <td class="right">
            <?php if (!$hl['is_primary']): ?>
            <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/primary-location" style="display:inline">
              <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="location_id" value="<?= e($hl['location_id']) ?>">
              <button class="btn sm line"><?= e(t('Make primary')) ?></button></form>
            <?php endif; ?>
            <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/unaffiliate" style="display:inline">
              <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="location_id" value="<?= e($hl['location_id']) ?>">
              <button class="btn sm line"><?= e(t('Remove')) ?></button></form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php else: ?><p class="hint"><?= e(t('Not affiliated with any location yet.')) ?></p><?php endif; ?>
    <?php
      $affiliatedIds = array_column($holderLocations, 'location_id');
      $available = array_filter($allLocations, fn($l) => !in_array($l['id'], $affiliatedIds, true));
    ?>
    <?php if ($available !== []): ?>
      <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/affiliate" style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center">
        <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
        <select name="location_id" required style="max-width:220px">
          <option value=""><?= e(t('Choose a location…')) ?></option>
          <?php foreach ($available as $l): ?><option value="<?= e($l['id']) ?>"><?= e($l['name']) ?></option><?php endforeach; ?>
        </select>
        <input name="role_at_site" placeholder="<?= e(t('Role at site (optional)')) ?>" style="max-width:180px">
        <label style="font-weight:normal;font-size:.8rem"><input type="checkbox" name="is_primary" value="1"> <?= e(t('Primary')) ?></label>
        <button class="btn sm gold"><?= e(t('Affiliate')) ?></button>
      </form>
    <?php elseif ($allLocations === []): ?>
      <p class="hint"><?= e(t('No locations defined yet.')) ?> <a href="<?= BASE_PATH ?>/locations"><?= e(t('Add one')) ?></a>.</p>
    <?php endif; ?>
  </div>

  <?php // ---- Assigned staff (need-to-know access) ----
  $assignees = $assignees ?? []; $teamMembers = $teamMembers ?? []; ?>
  <div class="panel" style="margin-top:1rem">
    <div class="topline"><h3 style="font-size:.92rem;margin:0"><?= e(t('Assigned staff')) ?></h3></div>
    <p class="hint" style="margin:.2rem 0 .6rem"><?= e(t('Team members responsible for this provider. Users restricted to assigned providers can only see the providers listed against them.')) ?></p>
    <?php if ($assignees !== []): ?>
      <table style="margin-bottom:.6rem">
        <?php foreach ($assignees as $a): ?>
        <tr><td><strong><?= e($a['full_name'] ?: $a['email']) ?></strong>
            <?php if (!empty($a['note'])): ?><span class="sub"><?= e($a['note']) ?></span><?php endif; ?></td>
          <td class="mono"><?= e(fmt_dt((string)$a['assigned_at'], 'm/d/Y')) ?></td>
          <td class="right"><?php if ($teamMembers !== []): ?>
            <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/unassign" style="display:inline">
              <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="user_id" value="<?= e($a['user_id']) ?>">
              <button class="btn sm line"><?= e(t('Remove')) ?></button>
            </form><?php endif; ?></td></tr>
        <?php endforeach; ?>
      </table>
    <?php else: ?><p class="hint"><?= e(t('No staff assigned yet.')) ?></p><?php endif; ?>
    <?php if ($teamMembers !== []): ?>
      <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/assign" style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center">
        <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
        <select name="user_id" style="max-width:240px">
          <?php foreach ($teamMembers as $m): if (($m['status'] ?? '') !== 'active') continue; ?>
            <option value="<?= e($m['id']) ?>"><?= e($m['full_name'] ?: $m['email']) ?><?= ($m['provider_access'] ?? 'all') === 'assigned' ? ' · ' . e(t('assigned-only')) : '' ?></option>
          <?php endforeach; ?>
        </select>
        <input name="note" placeholder="<?= e(t('Note (optional)')) ?>" style="max-width:200px">
        <button class="btn sm gold"><?= e(t('Assign')) ?></button>
      </form>
    <?php endif; ?>
  </div>

  <?php // ---- Exclusion / sanctions screening ----
  $screenings = $screenings ?? [];
  $scrSrc = FlxCoreHC\Repository\ScreeningRepository::SOURCES;
  $scrRes = FlxCoreHC\Repository\ScreeningRepository::RESULTS;
  $latestScr = $screenings[0] ?? null;
  $scrChip = fn($r) => $r === 'clear' ? 'active' : ($r === 'excluded' ? 'expired' : 'warn'); ?>
  <div style="background:var(--surface-2);border-radius:9px;padding:.85rem 1rem;margin:.2rem 0 1rem<?= $latestScr && $latestScr['result']==='excluded' ? ';border-left:4px solid var(--danger)' : '' ?>">
    <div class="topline"><h3 style="font-size:.92rem;margin:0">Exclusion &amp; sanctions screening</h3>
      <?php if ($latestScr): ?><span class="chip <?= $scrChip($latestScr['result']) ?>"><?= e($scrRes[$latestScr['result']] ?? $latestScr['result']) ?></span><?php endif; ?></div>
    <p class="hint" style="margin:.2rem 0 .6rem">Screen the provider against federal and state exclusion lists (OIG LEIE, SAM.gov, state/PR Medicaid). An excluded provider is a hard stop before billing. Official lists remain the source of truth; this records each check and its result.</p>
    <?php if ($screenings !== []): ?>
    <table class="tbl" style="margin-bottom:.6rem"><tr><th>Date</th><th>Source</th><th>Result</th><th>By</th></tr>
      <?php foreach ($screenings as $s): ?>
        <tr><td class="mono"><?= e(fmt_day($s['screened_on'])) ?></td>
          <td><?= e($scrSrc[$s['source']] ?? $s['source']) ?><?php if (!empty($s['reference'])): ?><span class="sub mono"><?= e($s['reference']) ?></span><?php endif; ?></td>
          <td><span class="chip <?= $scrChip($s['result']) ?>"><?= e($scrRes[$s['result']] ?? $s['result']) ?></span></td>
          <td><?= e($s['screened_by'] ?: '—') ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?><p class="hint">No screenings recorded yet.</p><?php endif; ?>
    <form method="post" action="<?= BASE_PATH ?>/compliance/screen-leie" style="margin-bottom:.6rem">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="holder_id" value="<?= e($row['id']) ?>">
      <button class="btn sm gold" title="Match this provider against the loaded monthly OIG LEIE list">Screen against exclusion lists now</button>
      <span class="hint" style="margin-left:.4rem">Automated · checks OIG LEIE + SAM.gov, records a review signal per list.</span>
    </form>
    <form method="post" action="<?= BASE_PATH ?>/compliance/screen">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="holder_id" value="<?= e($row['id']) ?>">
      <div class="grid2">
        <div><label>Source</label><select name="source"><?php foreach ($scrSrc as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div><label>Result</label><select name="result"><?php foreach ($scrRes as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div><label>Screened on</label><input type="date" name="screened_on" value="<?= e(today_local()) ?>"></div>
        <div><label>Reference <span class="hint" style="display:inline">(optional)</span></label><input type="text" name="reference" maxlength="120"></div>
      </div>
      <div style="margin-top:.4rem"><button class="btn sm">Record screening</button></div>
    </form>
  </div>
  <?php $curTpl = $tplCurrent ?? null; $dr = $drift ?? null;   // v1.170.9: this block had lost its opening tag (stray code text + undefined variables)
  $driftCount = $dr ? (count($dr['extra']) + count($dr['missing'])) : 0; ?>
  <div style="background:var(--surface-2);border-radius:9px;padding:.85rem 1rem;margin:.2rem 0 1rem">
    <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/apply-template" style="margin:0;display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <div style="flex:1;min-width:260px">
        <label style="font-weight:600;font-size:.82rem">Compliance template</label>
        <select name="template_id" style="width:100%">
          <option value="">— custom (no template) —</option>
          <?php foreach (($tplList ?? []) as $t): ?>
            <option value="<?= e($t['id']) ?>" <?= $curTpl && $curTpl['id'] === $t['id'] ? 'selected' : '' ?>>
              <?= e($t['name']) ?> (<?= (int)$t['item_count'] ?> requirements)</option>
          <?php endforeach; ?>
        </select>
        <span class="hint">
          <?php if ($curTpl): ?>
            On <strong><?= e($curTpl['name']) ?></strong>.
            <?php if ($driftCount > 0): ?>
              <span style="color:var(--warn)"><?= $driftCount ?> requirement<?= $driftCount === 1 ? '' : 's' ?> differ<?= $driftCount === 1 ? 's' : '' ?> from the template</span>
              — re-apply to reset, or leave as a deliberate exception.
            <?php else: ?>Matches the template exactly.<?php endif; ?>
          <?php else: ?>
            Pick the template for the licence this provider holds — it assigns the whole requirement set at once.
          <?php endif; ?>
        </span>
      </div>
      <div><label style="font-size:.82rem">Mode</label>
        <select name="mode">
          <option value="replace">Replace requirements</option>
          <option value="merge">Add to existing</option>
        </select></div>
      <button class="btn sm">Apply</button>
      <a class="btn sm line" href="<?= BASE_PATH ?>/templates">Manage templates</a>
    </form>
  </div>

  
</div>


<?php // ---- Carrier verification share links ----
if (FlxCoreHC\TeamService::canManage($ctx)):
  $shareLinks = $shareLinks ?? []; $baseUrl = $baseUrl ?? '';
  $statusChip = ['active' => 'active', 'expired' => 'muted', 'revoked' => 'danger'];
?>
</section>
<section class="tabpane" data-pane="share" hidden>
<?php // ---- Provider clearance (consent) ledger — the authority behind disclosures ----
$consents = $consents ?? []; ?>
<div class="panel" style="margin-bottom:1rem">
  <div class="topline"><h3 style="font-size:.92rem;margin:0"><?= e(t('Sharing clearance (provider consent)')) ?></h3></div>
  <p class="hint" style="margin:.2rem 0 .6rem"><?= e(t('Nothing is ever shared between organizations automatically. Sharing this provider\'s full file requires their recorded authorization; every clearance here is time-limited, revocable, and audit-logged.')) ?></p>
  <?php if ($consents !== []): ?>
    <table style="margin-bottom:.6rem">
      <tr><th><?= e(t('Granted to')) ?></th><th><?= e(t('Scope')) ?></th><th><?= e(t('Method')) ?></th><th><?= e(t('Granted')) ?></th><th><?= e(t('Expires')) ?></th><th></th></tr>
      <?php foreach ($consents as $c):
        $activeC = $c['revoked_at'] === null && ($c['expires_at'] === null || $c['expires_at'] > utc_now()); ?>
      <tr>
        <td><strong><?= e($c['granted_to']) ?></strong><?php if (!empty($c['note'])): ?><span class="sub"><?= e($c['note']) ?></span><?php endif; ?></td>
        <td><span class="chip"><?= e(FlxCoreHC\ConsentService::SCOPES[$c['scope']] ?? $c['scope']) ?></span></td>
        <td class="sub"><?= e(FlxCoreHC\ConsentService::METHODS[$c['method']] ?? $c['method']) ?></td>
        <td class="mono"><?= e(fmt_dt((string)$c['granted_at'], 'm/d/Y')) ?></td>
        <td class="mono"><?= e($c['expires_at'] ? fmt_dt((string)$c['expires_at'], 'm/d/Y') : '—') ?></td>
        <td class="right"><?php if ($activeC): ?>
          <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/consent-revoke" style="display:inline">
            <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="consent_id" value="<?= e($c['id']) ?>">
            <button class="btn sm line"><?= e(t('Revoke')) ?></button>
          </form>
        <?php else: ?><span class="chip expired"><?= e($c['revoked_at'] ? t('Revoked') : t('Expired')) ?></span><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php else: ?><p class="hint"><?= e(t('No clearances recorded.')) ?></p><?php endif; ?>
  <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/consent" style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:end">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <div><label style="font-size:.72rem"><?= e(t('Granted to')) ?></label><input name="granted_to" placeholder="<?= e(t('Organization / person')) ?>" style="max-width:200px" required></div>
    <div><label style="font-size:.72rem"><?= e(t('Scope')) ?></label><select name="scope"><?php foreach (FlxCoreHC\ConsentService::SCOPES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label style="font-size:.72rem"><?= e(t('Method')) ?></label><select name="method"><?php foreach (FlxCoreHC\ConsentService::METHODS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label style="font-size:.72rem"><?= e(t('Expires')) ?></label><input name="expires_at" type="date" style="max-width:150px"></div>
    <button class="btn sm gold"><?= e(t('Record clearance')) ?></button>
  </form>
</div>

<div class="panel" id="share">
  <div class="topline"><h2>Verification links</h2></div>
  <p class="hint" style="margin-top:-.2rem;max-width:66ch">
    Share a read-only view of this provider's credential status with a payer, hospital or credentialing committee — no login required.
    The link expires on its own and shows nothing else in your organization. Revoke it any time.
  </p>

  <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/share"
        style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end;margin:.4rem 0 1rem">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <div style="flex:1;min-width:200px"><label>Label <span class="hint" style="display:inline">(who it's for)</span></label>
      <input name="label" placeholder="e.g. Humana contracting" style="width:100%"></div>
    <div><label>Shows</label>
      <select name="scope">
        <?php foreach (FlxCoreHC\ShareLinkService::SCOPES as $k => $v): ?>
          <option value="<?= e($k) ?>"><?= e($v) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label>Expires in</label>
      <select name="days">
        <?php foreach (FlxCoreHC\ShareLinkService::DAY_OPTIONS as $k => $v): ?>
          <option value="<?= e($k) ?>" <?= $k === 30 ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select></div>
    <button class="btn sm">Create link</button>
  </form>

  <?php if (!empty($shareLinks)): ?>
  <div class="table-wrap"><table>
    <tr><th>Label</th><th>Link</th><th>Shows</th><th>Expires</th><th>Views</th><th>Status</th><th></th></tr>
    <?php foreach ($shareLinks as $L): $st = FlxCoreHC\ShareLinkService::status($L);
      $url = $baseUrl . '/verify/' . $L['token']; ?>
    <tr>
      <td><?= e($L['label'] ?? '—') ?></td>
      <td><?php if ($st === 'active'): ?>
            <input readonly value="<?= e($url) ?>" data-action="select-all"
                   style="width:230px;font-family:monospace;font-size:.72rem;padding:.3rem .4rem">
          <?php else: ?><span class="hint">—</span><?php endif; ?></td>
      <td><span class="hint"><?= e(FlxCoreHC\ShareLinkService::SCOPES[$L['scope']] ?? $L['scope']) ?></span></td>
      <td class="mono" style="font-size:.8rem"><?= e(fmt_dt((string)$L['expires_at'], 'm/d/Y')) ?></td>
      <td class="mono"><?= (int)$L['view_count'] ?></td>
      <td><span class="chip <?= e($statusChip[$st] ?? 'muted') ?>"><?= e(ucfirst($st)) ?></span></td>
      <td style="text-align:right">
        <?php if ($st === 'active'): ?>
        <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/share-revoke"
              data-confirm="Revoke this link? Anyone holding it loses access immediately." data-confirm-danger style="margin:0">
          <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
          <input type="hidden" name="link_id" value="<?= e($L['id']) ?>">
          <button class="btn sm line" style="color:var(--danger)">Revoke</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>

</section>
<section class="tabpane" data-pane="ce" hidden>
<?php require FLXCOREHC_APP . '/views/holders/_ce.php'; ?>

</section>

<section class="tabpane" data-pane="overview" hidden>
<?php // ---- Compliance level + payer credentialing + program enrollment + OCS/SICRO + telehealth ----
$enroll = $enroll ?? ['payers'=>[], 'rows'=>[], 'programs'=>[], 'ocs'=>null]; $level = $level ?? null;
$canW = FlxCoreHC\Authz::can($ctx, 'records.write');
$lvColor = [0=>'danger',1=>'',2=>'',3=>'warn',4=>'active',5=>'active'];
if ($level): ?>
<div class="panel" id="level" style="margin-top:1rem;border-left:4px solid <?= $level['level']===0?'var(--danger,#be4b42)':($level['level']>=4?'var(--ok,#2f7d5b)':'var(--warn,#c47f1a)') ?>">
  <div class="topline"><h3 style="font-size:.92rem;margin:0"><?= e(t('Compliance level')) ?></h3>
    <span class="chip <?= $lvColor[$level['level']] ?>" style="font-size:.8rem"><?= (int)$level['level'] ?> · <?= e(t($level['label'])) ?></span></div>
  <div style="display:flex;gap:.25rem;margin:.4rem 0;flex-wrap:wrap">
    <?php foreach (FlxCoreHC\ComplianceLevelService::LABELS as $n => $lbl): if ($n===0) continue; ?>
      <span class="chip <?= $n <= $level['level'] ? 'active' : '' ?>" style="font-size:.66rem;opacity:<?= $n <= $level['level'] ? '1' : '.45' ?>"><?= $n ?> <?= e(t($lbl)) ?></span>
    <?php endforeach; ?>
  </div>
  <?php if ($level['hard_stop']): ?><div style="color:var(--danger,#be4b42);font-weight:600;font-size:.85rem">⛔ <?= e(t('Hard stop')) ?>: <?= e(t($level['hard_stop'])) ?></div>
  <?php elseif ($level['reasons']): ?><div class="hint" style="font-size:.8rem"><?= e(t('To reach the next level')) ?>: <?= e(implode(' · ', array_map(fn($r)=>t($r), $level['reasons']))) ?></div>
  <?php else: ?><div class="hint" style="font-size:.8rem">✓ <?= e(t('Every federal, Puerto Rico, payer and organizational requirement on file is satisfied.')) ?></div><?php endif; ?>
</div>
<?php endif; ?>

<?php // ---- Sensitive identity data (field-level access control) ----
$__canPii = FlxCoreHC\Authz::can($ctx, 'pii.view');
$__dob = $row['dob'] ?? null; $__ssn = $row['ssn_last4'] ?? null;
if ($__dob || $__ssn):
  if ($__canPii) FlxCoreHC\SensitiveFields::logView($ctx, 'dob|ssn_last4', (string)$row['id']); ?>
<div class="panel" style="margin-top:1rem">
  <div class="topline"><h3 style="font-size:.92rem;margin:0"><?= e(t('Sensitive identity data')) ?></h3>
    <span class="chip <?= $__canPii ? 'warn' : '' ?>" style="font-size:.6rem"><?= e($__canPii ? t('visible to you') : t('masked')) ?></span></div>
  <table style="font-size:.84rem" data-no-stack>
    <?php if ($__dob): ?><tr><th style="width:10rem"><?= e(t('Date of birth')) ?></th><td class="mono"><?= e(FlxCoreHC\SensitiveFields::display($ctx, 'dob', $__dob)) ?></td></tr><?php endif; ?>
    <?php if ($__ssn): ?><tr><th><?= e(t('SSN (last 4)')) ?></th><td class="mono"><?= e(FlxCoreHC\SensitiveFields::display($ctx, 'ssn_last4', $__ssn)) ?></td></tr><?php endif; ?>
  </table>
  <div class="hint" style="font-size:.72rem;margin-top:.3rem"><?= e(t('Encrypted at rest. Never included in packets, exports, reports or emails. Views are logged.')) ?></div>
</div>
<?php endif; ?>

</section>
<section class="tabpane" data-pane="profile" hidden>
<?php // ---- Application readiness (data audit) + Education & work history ----
$readiness = $readiness ?? null; $education = $education ?? []; $workHistory = $workHistory ?? [];
if ($readiness): $rs = (int)$readiness['score']; $rcol = $rs >= 90 ? 'var(--ok,#2f7d5b)' : ($rs >= 60 ? 'var(--warn,#c47f1a)' : 'var(--danger,#be4b42)'); ?>
<div class="panel" id="readiness" style="margin-top:1rem;border-left:4px solid <?= $rcol ?>">
  <div class="topline"><h3 style="font-size:.92rem;margin:0"><?= e(t('Application readiness')) ?></h3>
    <span class="chip <?= $rs >= 90 ? 'active' : ($rs >= 60 ? 'warn' : 'danger') ?>" style="font-size:.8rem"><?= $rs ?>% · <?= (int)$readiness['ok'] ?>/<?= (int)$readiness['total'] ?></span></div>
  <p class="hint" style="margin:.2rem 0 .5rem"><?= e(t('What CAQH, OCS/SICRO, Medicaid PEP, Medicare and payer applications ask for, against what is on file. Collect the gaps before starting an application.')) ?></p>
  <details <?= $rs < 90 ? 'open' : '' ?>><summary class="hint" style="cursor:pointer"><?= e(t('Show checklist')) ?></summary>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:.6rem 1.2rem;margin-top:.5rem">
      <?php foreach ($readiness['groups'] as $g => $items): ?>
      <div><div style="font-weight:600;font-size:.8rem;margin-bottom:.25rem"><?= e(t($g)) ?></div>
        <?php foreach ($items as $it): ?>
          <div style="display:flex;gap:.4rem;align-items:flex-start;font-size:.8rem;margin:.15rem 0">
            <span style="width:1.1rem;flex:none"><?= $it['how'] === null ? '<span class="hint">◌</span>' : ($it['ok'] ? '<span style="color:var(--ok,#2f7d5b)">✓</span>' : '<span style="color:var(--danger,#be4b42)">✕</span>') ?></span>
            <span><?= e(t($it['label'])) ?><?php if ($it['value']): ?> <span class="hint">— <?= e($it['value']) ?></span><?php endif; ?>
              <?php if (!$it['ok'] && $it['how']): ?><span class="hint" style="font-size:.7rem"> · <?= e(t($it['how'])) ?></span><?php endif; ?>
              <?php if ($it['note']): ?><div class="hint" style="font-size:.68rem"><?= e(t($it['note'])) ?></div><?php endif; ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </details>
</div>
<?php endif; ?>

<?php $canHist = FlxCoreHC\Authz::can($ctx, 'records.write'); $ekinds = ['undergraduate'=>'Undergraduate','professional'=>'Professional school','internship'=>'Internship','residency'=>'Residency','fellowship'=>'Fellowship','other'=>'Other']; ?>
<div class="panel" id="history" style="margin-top:1rem">
  <div class="topline"><h3 style="font-size:.92rem;margin:0"><?= e(t('Education & work history')) ?></h3>
    <span class="hint"><?= e(t('Applications ask for training and 5 years of employment with gaps explained')) ?></span></div>
  <div style="font-weight:600;font-size:.85rem;margin:.4rem 0 .2rem"><?= e(t('Education & training')) ?></div>
  <?php if ($education === []): ?><p class="hint"><?= e(t('None recorded.')) ?></p><?php else: ?>
  <table style="font-size:.8rem"><tr><th><?= e(t('Type')) ?></th><th><?= e(t('Institution')) ?></th><th><?= e(t('Program')) ?></th><th><?= e(t('Dates')) ?></th><th></th></tr>
    <?php foreach ($education as $e): ?>
    <tr><td data-label="<?= e(t('Type')) ?>"><?= e(t($ekinds[$e['kind']] ?? $e['kind'])) ?></td><td data-label="<?= e(t('Institution')) ?>"><strong><?= e($e['institution']) ?></strong><?php if ($e['city']): ?> <span class="hint"><?= e(trim($e['city'] . ', ' . ($e['state'] ?? ''), ', ')) ?></span><?php endif; ?></td>
      <td class="hint" data-label="<?= e(t('Program')) ?>"><?= e($e['program'] ?: '—') ?></td><td class="hint" style="white-space:nowrap" data-label="<?= e(t('Dates')) ?>"><?= e(($e['start_date'] ?: '?') . ' → ' . ($e['end_date'] ?: ($e['completed'] ? '?' : t('in progress')))) ?></td>
      <td class="right"><?php if ($canHist): ?><form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/education/<?= e($e['id']) ?>/delete" style="display:inline" data-confirm="<?= e(t('Remove this entry?')) ?>"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm line">✕</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></table><?php endif; ?>
  <?php if ($canHist): ?>
  <details style="margin:.3rem 0 .8rem"><summary class="btn sm line" style="display:inline-block;cursor:pointer"><?= e(t('Add education / training')) ?></summary>
    <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/education" class="stack" style="margin-top:.5rem;max-width:640px"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <div class="grid2"><div><label><?= e(t('Type')) ?></label><select name="kind"><?php foreach ($ekinds as $k=>$l): ?><option value="<?= $k ?>"><?= e(t($l)) ?></option><?php endforeach; ?></select></div>
        <div><label><?= e(t('Institution')) ?></label><input name="institution" required></div></div>
      <div class="grid2"><div><label><?= e(t('Program / degree / specialty')) ?></label><input name="program"></div>
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:.4rem"><div><label><?= e(t('City')) ?></label><input name="city"></div><div><label><?= e(t('State')) ?></label><input name="state" maxlength="8"></div><div><label><?= e(t('Country')) ?></label><input name="country" maxlength="2" value="US"></div></div></div>
      <div class="grid2"><div><label><?= e(t('Start')) ?></label><input type="date" name="start_date"></div><div><label><?= e(t('End')) ?></label><input type="date" name="end_date"></div></div>
      <div><label style="font-weight:normal"><input type="checkbox" name="completed" value="1" checked> <?= e(t('Completed')) ?></label></div>
      <div><button class="btn sm gold"><?= e(t('Add')) ?></button></div>
    </form></details>
  <?php endif; ?>

  <div style="font-weight:600;font-size:.85rem;margin:.6rem 0 .2rem"><?= e(t('Work history')) ?></div>
  <?php if ($workHistory === []): ?><p class="hint"><?= e(t('None recorded.')) ?></p><?php else: ?>
  <table style="font-size:.8rem"><tr><th><?= e(t('Employer')) ?></th><th><?= e(t('Position')) ?></th><th><?= e(t('Dates')) ?></th><th><?= e(t('Gap before')) ?></th><th></th></tr>
    <?php foreach ($workHistory as $w): ?>
    <tr><td data-label="<?= e(t('Employer')) ?>"><strong><?= e($w['employer']) ?></strong><?php if ($w['city']): ?> <span class="hint"><?= e(trim($w['city'] . ', ' . ($w['state'] ?? ''), ', ')) ?></span><?php endif; ?></td>
      <td class="hint" data-label="<?= e(t('Position')) ?>"><?= e($w['position'] ?: '—') ?></td><td class="hint" style="white-space:nowrap" data-label="<?= e(t('Dates')) ?>"><?= e(($w['start_date'] ?: '?') . ' → ' . ($w['end_date'] ?: t('current'))) ?></td>
      <td class="hint" data-label="<?= e(t('Gap before')) ?>"><?= e($w['gap_explanation'] ?: '—') ?></td>
      <td class="right"><?php if ($canHist): ?><form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/work/<?= e($w['id']) ?>/delete" style="display:inline" data-confirm="<?= e(t('Remove this entry?')) ?>"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm line">✕</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></table><?php endif; ?>
  <?php if ($canHist): ?>
  <details style="margin-top:.3rem"><summary class="btn sm line" style="display:inline-block;cursor:pointer"><?= e(t('Add employment')) ?></summary>
    <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/work" class="stack" style="margin-top:.5rem;max-width:640px"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <div class="grid2"><div><label><?= e(t('Employer')) ?></label><input name="employer" required></div><div><label><?= e(t('Position')) ?></label><input name="position"></div></div>
      <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:.4rem"><div><label><?= e(t('City')) ?></label><input name="city"></div><div><label><?= e(t('State')) ?></label><input name="state" maxlength="8"></div><div><label><?= e(t('Start')) ?></label><input type="date" name="start_date"></div><div><label><?= e(t('End')) ?> <span class="hint">(<?= e(t('blank = current')) ?>)</span></label><input type="date" name="end_date"></div></div>
      <div class="grid2"><div><label><?= e(t('Reason for leaving')) ?></label><input name="reason_left"></div><div><label><?= e(t('Explain any gap before this role (> 6 months)')) ?></label><input name="gap_explanation"></div></div>
      <div><button class="btn sm gold"><?= e(t('Add')) ?></button></div>
    </form></details>
  <?php endif; ?>
</div>

<?php // ---- Addresses & office contacts ----
$addresses = $addresses ?? []; $contacts = $contacts ?? []; $canEditRec = FlxCoreHC\Authz::can($ctx, 'records.write'); ?>
<div class="panel" id="addresses" style="margin-top:1rem">
  <div class="topline"><h3 style="font-size:.92rem;margin:0"><?= e(t('Addresses')) ?></h3>
    <span class="hint"><?= e(t('Mailing, billing, home… distinct from practice locations; credentials can cite the one on file')) ?></span></div>
  <?php $base = BASE_PATH . '/holders/' . $row['id'] . '/addresses'; $canEdit = $canEditRec; require FLXCOREHC_APP . '/views/_address_list.php'; ?>
  <?php if ($canEditRec): ?>
  <details style="margin-top:.5rem"><summary class="btn sm line" style="display:inline-block;cursor:pointer"><?= e(t('Add address')) ?></summary>
    <div style="margin-top:.5rem"><?php $action = $base; $a = null; require FLXCOREHC_APP . '/views/_address_form.php'; ?></div></details>
  <?php endif; ?>
</div>

<div class="panel" id="contacts" style="margin-top:1rem">
  <div class="topline"><h3 style="font-size:.92rem;margin:0"><?= e(t('Office contacts')) ?></h3>
    <span class="hint"><?= e(t('People at the provider\'s office who help keep the record current — no portal account needed')) ?></span></div>
  <?php if ($contacts === []): ?><p class="hint"><?= e(t('No office contacts yet.')) ?></p>
  <?php else: ?>
  <table style="font-size:.82rem">
    <tr><th><?= e(t('Name')) ?></th><th><?= e(t('Role')) ?></th><th><?= e(t('Email')) ?></th><th><?= e(t('Phone')) ?></th><th><?= e(t('Receives')) ?></th><th></th></tr>
    <?php foreach ($contacts as $c): ?>
    <tr>
      <td data-label="<?= e(t('Name')) ?>"><strong><?= e($c['full_name']) ?></strong><?php if (!empty($c['is_primary'])): ?> <span class="chip active" style="font-size:.58rem"><?= e(t('primary')) ?></span><?php endif; ?></td>
      <td class="hint" data-label="<?= e(t('Role')) ?>"><?= e($c['role_title'] ?: '—') ?></td>
      <td data-label="<?= e(t('Email')) ?>"><?= e($c['email'] ?: '—') ?><?php if ($c['preferred_language']): ?> <span class="hint">(<?= e(strtoupper($c['preferred_language'])) ?>)</span><?php endif; ?></td>
      <td class="hint" data-label="<?= e(t('Phone')) ?>"><?= e($c['phone'] ?: '—') ?></td>
      <td data-label="<?= e(t('Receives')) ?>"><?= !empty($c['gets_document_requests']) ? '<span class="chip" style="font-size:.6rem">' . e(t('requests')) . '</span> ' : '' ?><?= !empty($c['gets_reminders']) ? '<span class="chip" style="font-size:.6rem">' . e(t('reminders')) . '</span>' : '' ?></td>
      <td class="right" style="white-space:nowrap">
        <?php if ($canEditRec): ?>
        <details style="display:inline-block"><summary class="btn sm line" style="display:inline-block;cursor:pointer"><?= e(t('Edit')) ?></summary>
          <div style="margin:.5rem 0"><?php $cform = $c; $caction = BASE_PATH . '/holders/' . $row['id'] . '/contacts/' . $c['id']; require FLXCOREHC_APP . '/views/_contact_form.php'; ?></div></details>
        <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/contacts/<?= e($c['id']) ?>/delete" style="display:inline" data-confirm="<?= e(t('Remove this contact?')) ?>"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm line">✕</button></form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
  <?php if ($canEditRec): ?>
  <details style="margin-top:.5rem"><summary class="btn sm line" style="display:inline-block;cursor:pointer"><?= e(t('Add contact')) ?></summary>
    <div style="margin-top:.5rem"><?php $cform = null; $caction = BASE_PATH . '/holders/' . $row['id'] . '/contacts'; require FLXCOREHC_APP . '/views/_contact_form.php'; ?></div></details>
  <?php endif; ?>
</div>

</section>
<section class="tabpane" data-pane="checklist" hidden>
<?php // ---- Verification records (Tier A = public-registry lookup / Tier B = organization-recorded from the official page) ----
$verifs = $verifs ?? []; $samConfigured = $samConfigured ?? false;
$canVerify = FlxCoreHC\Authz::can($ctx, 'documents.verify');
$ocolor = ['match'=>'active','clear'=>'active','recorded'=>'active','mismatch'=>'warn','flagged'=>'danger','not_found'=>'warn','error'=>'','recorded'=>'active'];
$srcs = FlxCoreHC\VerificationService::SOURCES;   // full list: labels of already-recorded verifications
$__offered = array_keys(FlxCoreHC\VerificationService::sourcesFor((array) ($tenantStates ?? [])));   // jurisdiction-aware list offered for new entries
if ($canVerify || $verifs): ?>
<div class="panel" id="verify" style="margin-top:1rem">
  <div class="topline"><h3 style="font-size:.92rem;margin:0"><?= e(t('Verification records')) ?></h3>
    <span class="hint"><?= e(t('FlxCoreHC records your organization\'s verification activity and evidence. It does not independently perform or certify primary-source verification.')) ?></span></div>

  <?php if ($canVerify): ?>
  <div style="display:flex;gap:.4rem;flex-wrap:wrap;margin:.4rem 0 .6rem">
    <?php if (!empty($row['npi'])): ?>
    <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/verify-source" style="margin:0"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="action" value="nppes"><input type="hidden" name="reverify_on" value="<?= e(today_local('+1 year')) ?>">
      <button class="btn sm gold" title="<?= e(t('Re-pull the live NPI record and compare')) ?>">✓ <?= e(t('Verify NPI (NPPES)')) ?></button></form>
    <?php endif; ?>
    <?php if ($samConfigured): ?>
    <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/verify-source" style="margin:0"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="action" value="sam"><input type="hidden" name="reverify_on" value="<?= e(today_local('+30 days')) ?>">
      <button class="btn sm line" title="<?= e(t('Real-time SAM.gov exclusion check')) ?>">✓ <?= e(t('Check SAM.gov')) ?></button></form>
    <?php else: ?><span class="hint" style="align-self:center;font-size:.72rem"><?= e(t('SAM.gov check needs a key (Console → System)')) ?></span><?php endif; ?>
  </div>
  <details style="margin:0 0 .6rem"><summary class="btn sm line" style="display:inline-block;cursor:pointer"><?= e(t('Record a verification done at the official page')) ?></summary>
    <div class="hint" style="font-size:.78rem;margin:.4rem 0"><?= e(t(in_array('orcps', $__offered, true) ? 'ORCPS, OCS/SICRO, Medicaid PEP and Medicare PECOS have no public API — open the official page, confirm the status, and record it here with a screenshot as evidence.' : 'Medicaid and Medicare enrollment have no public API — open the official page, confirm the status, and record it here with a screenshot as evidence.')) ?></div>
    <div style="display:flex;gap:.3rem;flex-wrap:wrap;margin-bottom:.5rem">
      <?php foreach (array_intersect(['orcps','ocs','medicaid','medicare'], $__offered) as $sk): ?>
        <a class="btn sm line" href="<?= e($srcs[$sk][2]) ?>" target="_blank" rel="noopener"><?= e($srcs[$sk][0]) ?> ↗</a>
      <?php endforeach; ?>
    </div>
    <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/verify-source" class="stack" style="max-width:560px"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="action" value="manual">
      <div class="grid2">
        <div><label><?= e(t('Source')) ?></label><select name="source"><?php foreach (array_merge(array_intersect(['orcps','ocs','medicaid','medicare'], $__offered), ['other']) as $sk): ?><option value="<?= $sk ?>"><?= e($srcs[$sk][0] ?? 'Other') ?></option><?php endforeach; ?></select></div>
        <div><label><?= e(t('Outcome')) ?></label><select name="outcome"><option value="clear"><?= e(t('Clear / good standing')) ?></option><option value="flagged"><?= e(t('Flagged / issue')) ?></option><option value="recorded"><?= e(t('Recorded')) ?></option></select></div>
      </div>
      <div class="grid2">
        <div><label><?= e(t('Confirmation / reference')) ?></label><input name="reference" placeholder="<?= e(t('confirmation #, or screenshot filename')) ?>"></div>
        <div><label><?= e(t('Re-verify on')) ?></label><input type="date" name="reverify_on"></div>
      </div>
      <div><label><?= e(t('Note')) ?></label><input name="note" maxlength="400"></div>
      <div><button class="btn sm gold"><?= e(t('Record verification')) ?></button></div>
    </form>
  </details>
  <?php endif; ?>

  <?php if ($verifs === []): ?><p class="hint"><?= e(t('No verifications recorded yet.')) ?></p>
  <?php else: ?>
  <table style="font-size:.8rem">
    <tr><th><?= e(t('When')) ?></th><th><?= e(t('Source')) ?></th><th><?= e(t('Outcome')) ?></th><th><?= e(t('By')) ?></th><th><?= e(t('Re-verify')) ?></th></tr>
    <?php foreach ($verifs as $v): $det = $v['detail'] ? json_decode((string)$v['detail'], true) : null; ?>
    <tr>
      <td class="mono hint" style="white-space:nowrap" data-label="<?= e(t('When')) ?>"><?= e(fmt_day($v['verified_on'])) ?></td>
      <td data-label="<?= e(t('Source')) ?>"><?= e($srcs[$v['source']][0] ?? $v['source']) ?> <?php if ($v['method']==='api'): ?><span class="chip" style="font-size:.58rem"><?= e(t('automated')) ?></span><?php endif; ?></td>
      <td data-label="<?= e(t('Outcome')) ?>"><span class="chip <?= $ocolor[$v['outcome']] ?? '' ?>"><?= e(t(ucfirst($v['outcome']))) ?></span>
        <?php if (!empty($det['differences'])): ?><div class="hint" style="font-size:.68rem"><?php foreach ($det['differences'] as $f=>$d): ?><?= e($f) ?>: <?= e($d['on_file'] ?? '') ?> ≠ <?= e($d['nppes'] ?? '') ?><?php endforeach; ?></div><?php endif; ?></td>
      <td class="hint" style="white-space:nowrap" data-label="<?= e(t('By')) ?>"><?= e($v['verified_by_name'] ?? 'System') ?></td>
      <td class="hint" style="white-space:nowrap" data-label="<?= e(t('Re-verify')) ?>"><?= $v['reverify_on'] ? e(fmt_day($v['reverify_on'])) . (($v['reverify_on'] < today_local()) ? ' <span class="chip warn" style="font-size:.58rem">'.e(t('due')).'</span>' : '') : '—' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>

</section>
<section class="tabpane" data-pane="payers" hidden>
<div class="panel">
  <div class="topline"><h2 style="margin:0"><?= e(t('Credentialing cases')) ?></h2>
    <a class="btn sm gold" href="<?= BASE_PATH ?>/cases?new=1&holder=<?= e($row['id']) ?>#new"><?= e(t('New case')) ?></a></div>
  <?php $agencyCases = $agencyCases ?? []; if ($agencyCases): ?>
  <table style="font-size:.84rem">
    <tr><th><?= e(t('Type')) ?></th><th><?= e(t('Status')) ?></th><th><?= e(t('Target')) ?></th><th></th></tr>
    <?php foreach ($agencyCases as $ac): ?>
    <tr><td><?= e((string)$ac['case_type']) ?></td><td><span class="chip"><?= e(t(ucfirst(str_replace('_',' ',$ac['status'])))) ?></span></td>
      <td class="mono hint"><?= e(fmt_day((string)($ac['target_date'] ?? ''))) ?></td>
      <td class="right"><a class="btn sm line" href="<?= BASE_PATH ?>/cases/<?= e($ac['id']) ?>"><?= e(t('Open')) ?> →</a></td></tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?><div class="hint" style="font-size:.82rem"><?= e(t('No credentialing cases for this provider yet.')) ?></div><?php endif; ?>
</div>

<div class="panel" id="enrollment" style="margin-top:1rem">
  <div class="topline"><h3 style="font-size:.92rem;margin:0"><?= e(t('Payers & enrollment')) ?></h3>
    <span class="hint"><?= e(t('Where this provider stands with each payer, Medicaid / Medicare, and OCS')) ?></span></div>

  <?php $psl = FlxCoreHC\EnrollmentService::PAYER_STATUSES; $pchip = ['credentialed'=>'active','contracted'=>'active','participating'=>'active','recredentialing'=>'warn','application_pending'=>'warn','under_review'=>'warn','denied'=>'danger','suspended'=>'danger','terminated'=>'danger','expired'=>'danger']; ?>
  <div style="font-weight:600;font-size:.85rem;margin:.5rem 0 .2rem"><?= e(t('Payer credentialing')) ?></div>
  <?php if ($enroll['rows'] === []): ?><p class="hint"><?= e(t('No payer records yet.')) ?></p>
  <?php else: ?>
  <table style="font-size:.8rem">
    <tr><th><?= e(t('Payer')) ?></th><th><?= e(t('Status')) ?></th><th><?= e(t('Effective')) ?></th><th><?= e(t('Recredentialing')) ?></th><th><?= e(t('ID / contract')) ?></th><th></th></tr>
    <?php foreach ($enroll['rows'] as $r): ?>
    <tr>
      <td data-label="<?= e(t('Payer')) ?>"><strong><?= e($r['payer_name']) ?></strong><?php if ($r['network']): ?> <span class="hint"><?= e($r['network']) ?></span><?php endif; ?></td>
      <td data-label="<?= e(t('Status')) ?>"><span class="chip <?= $pchip[$r['status']] ?? '' ?>"><?= e(t($psl[$r['status']] ?? $r['status'])) ?></span></td>
      <td class="hint" data-label="<?= e(t('Effective')) ?>"><?= (!empty($r['effective_date']) ? e(fmt_day($r['effective_date'])) : '—') ?></td>
      <td class="hint" data-label="<?= e(t('Recredentialing')) ?>"><?= (!empty($r['recredentialing_date']) ? e(fmt_day((string)$r['recredentialing_date'])) : '—') ?></td>
      <td class="mono hint" data-label="<?= e(t('ID / contract')) ?>"><?= e($r['payer_provider_id'] ?: ($r['contract_number'] ?: '—')) ?></td>
      <td class="right"><?php if ($canW): ?><form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/payer/<?= e($r['id']) ?>/delete" style="display:inline"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm line">✕</button></form><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
  <?php if ($canW): ?>
  <details style="margin:.4rem 0 .8rem"><summary class="btn sm line" style="display:inline-block;cursor:pointer"><?= e(t('Add / update payer status')) ?></summary>
    <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/payer" class="stack" style="margin-top:.5rem;max-width:620px"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <div class="grid2">
        <div><label><?= e(t('Payer')) ?></label><select name="payer_id" required><?php foreach ($enroll['payers'] as $p): ?><option value="<?= e($p['id']) ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select>
          <div class="hint"><?= e(t('Missing a payer?')) ?> <a href="<?= BASE_PATH ?>/organization/payers"><?= e(t('Add it under Organization → Payers')) ?></a></div></div>
        <div><label><?= e(t('Plan / network')) ?> <span class="hint">(<?= e(t('optional')) ?>)</span></label><input name="network" placeholder="Vital, Advantage, Commercial…"></div>
      </div>
      <div class="grid2">
        <div><label><?= e(t('Status')) ?></label><select name="status"><?php foreach ($psl as $k=>$l): ?><option value="<?= e($k) ?>"><?= e(t($l)) ?></option><?php endforeach; ?></select></div>
        <div><label><?= e(t('Payer provider ID / contract #')) ?></label><input name="payer_provider_id"></div>
      </div>
      <div class="grid3" style="display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem">
        <div><label><?= e(t('Application')) ?></label><input type="date" name="application_date"></div>
        <div><label><?= e(t('Effective')) ?></label><input type="date" name="effective_date"></div>
        <div><label><?= e(t('Recredentialing due')) ?></label><input type="date" name="recredentialing_date"></div>
      </div>
      <div><label><?= e(t('Notes')) ?></label><input name="notes" maxlength="500"></div>
      <div><button class="btn sm gold"><?= e(t('Save payer status')) ?></button></div>
    </form>
  </details>
  <?php endif; ?>

  <?php $prg = $enroll['programs']; $pst = FlxCoreHC\EnrollmentService::PROGRAM_STATUSES; $gchip = ['enrolled'=>'active','revalidation_due'=>'warn','revalidating'=>'warn','pending'=>'warn','terminated'=>'danger','opted_out'=>'']; ?>
  <div style="font-weight:600;font-size:.85rem;margin:.6rem 0 .2rem"><?= e(t('Government programs')) ?></div>
  <div class="grid2" style="gap:.6rem">
    <?php foreach (FlxCoreHC\EnrollmentService::PROGRAMS as $pk => $plabel): $pr = $prg[$pk] ?? null; ?>
    <div style="border:1px solid var(--line,#e3e1da);border-radius:8px;padding:.5rem .7rem">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:.4rem"><strong style="font-size:.85rem"><?= e(t($plabel)) ?></strong>
        <span class="chip <?= $gchip[$pr['status'] ?? ''] ?? '' ?>"><?= e(t($pst[$pr['status'] ?? 'not_enrolled'] ?? 'Not enrolled')) ?></span></div>
      <?php if ($pr): ?>
        <div class="hint" style="font-size:.76rem;margin-top:.2rem">
          <?= $pr['enrollment_id'] ? e(t('ID')) . ' <span class="mono">' . e($pr['enrollment_id']) . '</span> · ' : '' ?>
          <?= $pr['effective_date'] ? e(t('since')) . ' ' . e(fmt_day($pr['effective_date'])) . ' · ' : '' ?>
          <?= $pr['revalidation_date'] ? e(t('revalidate by')) . ' <strong>' . e(fmt_day((string)$pr['revalidation_date'])) . '</strong>' : '' ?>
          <?php if ($pk === 'medicaid'): ?> · <?= e(t('locations in PEP')) ?>: <?= $pr['locations_registered'] ? '✓' : '<span style="color:var(--danger,#be4b42)">✕ ' . e(t('claims deny')) . '</span>' ?><?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if ($canW): ?>
      <details style="margin-top:.35rem"><summary class="hint" style="cursor:pointer;font-size:.75rem"><?= e(t('Update')) ?></summary>
        <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/program" class="stack" style="margin-top:.4rem"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
          <input type="hidden" name="program" value="<?= e($pk) ?>">
          <div class="grid2">
            <div><label><?= e(t('Status')) ?></label><select name="status"><?php foreach ($pst as $k=>$l): ?><option value="<?= e($k) ?>" <?= ($pr['status'] ?? '')===$k?'selected':'' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></div>
            <div><label><?= e($pk==='medicaid' ? t('Medicaid provider ID') : t('PTAN')) ?></label><input name="enrollment_id" value="<?= e($pr['enrollment_id'] ?? '') ?>"></div>
          </div>
          <div class="grid2">
            <div><label><?= e(t('Effective')) ?></label><input type="date" name="effective_date" value="<?= e($pr['effective_date'] ?? '') ?>"></div>
            <div><label><?= e(t('Revalidation due')) ?></label><input type="date" name="revalidation_date" value="<?= e($pr['revalidation_date'] ?? '') ?>">
              <div class="hint" style="font-size:.68rem"><?= e($pk==='medicaid' ? t('PEP: every 5 yrs (medical) / 3 yrs (non-medical since 2023)') : t('PECOS: every 5 years')) ?></div></div>
          </div>
          <div class="grid2">
            <div><label><?= e(t('Enrollment type')) ?></label><select name="enrollment_type"><option value="">—</option><?php foreach (['individual'=>'Individual','group'=>'Group','ordering_referring'=>'Ordering / referring'] as $k=>$l): ?><option value="<?= $k ?>" <?= ($pr['enrollment_type'] ?? '')===$k?'selected':'' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></div>
            <div><label><?= e($pk==='medicaid' ? t('Group affiliation') : t('MAC / region')) ?></label><input name="<?= $pk==='medicaid' ? 'group_affiliation' : 'mac_or_region' ?>" value="<?= e($pk==='medicaid' ? ($pr['group_affiliation'] ?? '') : ($pr['mac_or_region'] ?? '')) ?>"></div>
          </div>
          <?php if ($pk === 'medicaid'): ?><div><label style="font-weight:normal"><input type="checkbox" name="locations_registered" value="1" <?= !empty($pr['locations_registered'])?'checked':'' ?>> <?= e(t('All service locations registered in PEP (required since 2024-05-15 or claims deny)')) ?></label></div><?php endif; ?>
          <div><button class="btn sm"><?= e(t('Save')) ?></button></div>
        </form>
      </details>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if (in_array('PR', array_map('strtoupper', (array) ($tenantStates ?? [])), true)): ?>
  <?php $ocs = $enroll['ocs']; $ost = FlxCoreHC\EnrollmentService::OCS_STATUSES; $ochip = ['attested'=>'active','registered'=>'warn','pending'=>'warn','expired'=>'danger','not_registered'=>'danger']; ?>
  <div style="font-weight:600;font-size:.85rem;margin:.8rem 0 .2rem"><?= e(t('OCS / SICRO centralized credentialing')) ?> <span class="hint" style="font-weight:400">(<?= e(t('Ley 73-2023 — compulsory')) ?>)</span></div>
  <div style="border:1px solid var(--line,#e3e1da);border-radius:8px;padding:.5rem .7rem;max-width:620px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:.4rem">
      <span class="chip <?= $ochip[$ocs['sicro_status'] ?? 'not_registered'] ?>"><?= e(t($ost[$ocs['sicro_status'] ?? 'not_registered'])) ?></span>
      <?php if ($ocs): ?><span class="hint" style="font-size:.76rem"><?= $ocs['last_attestation'] ? e(t('attested')) . ' ' . e(fmt_day((string)$ocs['last_attestation'])) : '' ?><?= $ocs['attestation_expires'] ? ' · ' . e(t('expires')) . ' <strong>' . e(fmt_day((string)$ocs['attestation_expires'])) . '</strong>' : '' ?><?= $ocs['delegate_cvo'] ? ' · ' . e(t('delegate')) . ' ' . e($ocs['delegate_cvo']) : '' ?></span><?php endif; ?>
    </div>
    <?php if ($canW): ?>
    <details style="margin-top:.35rem"><summary class="hint" style="cursor:pointer;font-size:.75rem"><?= e(t('Update')) ?></summary>
      <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/ocs" class="stack" style="margin-top:.4rem"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
        <div class="grid2">
          <div><label><?= e(t('SICRO status')) ?></label><select name="sicro_status"><?php foreach ($ost as $k=>$l): ?><option value="<?= e($k) ?>" <?= ($ocs['sicro_status'] ?? '')===$k?'selected':'' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></div>
          <div><label><?= e(t('OCS profile ID')) ?></label><input name="profile_id" value="<?= e($ocs['profile_id'] ?? '') ?>"></div>
        </div>
        <div class="grid2">
          <div><label><?= e(t('Last attestation')) ?></label><input type="date" name="last_attestation" value="<?= e($ocs['last_attestation'] ?? '') ?>"></div>
          <div><label><?= e(t('Attestation expires')) ?></label><input type="date" name="attestation_expires" value="<?= e($ocs['attestation_expires'] ?? '') ?>"></div>
        </div>
        <div class="grid2">
          <div><label><?= e(t('Delegate / CVO')) ?></label><input name="delegate_cvo" value="<?= e($ocs['delegate_cvo'] ?? '') ?>"></div>
          <div><label style="font-weight:normal;margin-top:1.4rem"><input type="checkbox" name="affidavit_on_file" value="1" <?= !empty($ocs['affidavit_on_file'])?'checked':'' ?>> <?= e(t('Notarized affidavit on file (institutions, Phase II)')) ?></label></div>
        </div>
        <div><button class="btn sm"><?= e(t('Save')) ?></button></div>
      </form>
    </details>
    <?php endif; ?>
  </div>
  <?php endif; /* OCS/SICRO is a Puerto Rico program: hidden for tenants that do not operate in PR (unless data already exists) */ ?>

  <div style="margin:.8rem 0 0;font-size:.82rem">
    <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/telehealth" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <label style="font-weight:normal;margin:0"><input type="checkbox" name="enabled" value="1" <?= !empty($row['telehealth_enabled'])?'checked':'' ?> <?= $canW?'':'disabled' ?>> <?= e(t('Provides telemedicine / telehealth')) ?></label>
      <?php if ($canW): ?><button class="btn sm line"><?= e(t('Save')) ?></button><?php endif; ?>
      <span class="hint" style="font-size:.72rem"><?= e(t('No separate certification is required — a current PR license suffices (Ley 8-2025).')) ?></span>
    </form>
  </div>
</div>

<?php // ---- Cross-organization linking & sharing (consent model) ----
$crossOthers = (int)($crossOthers ?? 0); $crossLinks = $crossLinks ?? []; $crossSharesOut = $crossSharesOut ?? [];
$crossSharesIn = $crossSharesIn ?? []; $crossPending = $crossPending ?? []; $holderCreds = $holderCreds ?? [];
$isMA = $ctx->role === 'tenant_owner'; $canShare = FlxCoreHC\Authz::can($ctx, 'share.manage');
if ($crossOthers > 0 || $crossLinks || $crossSharesIn || $crossPending): ?>
<div class="panel" id="crossorg" style="margin-top:1rem">
  <div class="topline"><h3 style="font-size:.92rem;margin:0"><?= e(t('Other organizations')) ?></h3>
    <span class="hint"><?= e(t('Linked records share credentials one at a time, read-only, only after everyone consents')) ?></span></div>

  <?php if ($crossOthers > 0 && !$crossLinks && !$crossPending): ?>
    <div style="border:1px solid var(--line,#e3e1da);border-left:3px solid var(--accent,#2563eb);border-radius:8px;padding:.6rem .8rem;margin:.4rem 0">
      <div><?= e(t('A provider with this NPI is also managed by {n} other organization(s) on FlxCoreHC.', ['n' => $crossOthers])) ?> <span class="hint"><?= e(t('Who they are is not shown until a link is approved.')) ?></span></div>
      <?php if ($isMA): ?>
        <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/link-request" style="margin-top:.4rem"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
          <button class="btn sm gold"><?= e(t('Request to link records')) ?></button>
          <span class="hint"><?= e(t('The provider consents first, then their Master Admin. Nothing is shared until both approve.')) ?></span></form>
      <?php else: ?><div class="hint" style="margin-top:.3rem"><?= e(t('Ask your Master Admin to request a link.')) ?></div><?php endif; ?>
    </div>
  <?php endif; ?>

  <?php foreach ($crossPending as $pl): ?>
    <div class="hint" style="margin:.3rem 0">⏳ <?= e($pl['status'] === 'pending_provider' ? t('Link requested — waiting for the provider to consent.') : t('Provider consented — waiting for the other organization\'s Master Admin.')) ?></div>
  <?php endforeach; ?>

  <?php if ($crossLinks): ?>
    <div style="margin:.5rem 0 .2rem;font-weight:600;font-size:.85rem"><?= e(t('Linked with')) ?>: <?= e(implode(', ', array_column($crossLinks, 'other_name'))) ?></div>
    <?php if ($canShare && $holderCreds): ?>
    <details style="margin:.3rem 0 .6rem"><summary class="btn sm line" style="display:inline-block;cursor:pointer"><?= e(t('Share credentials with a linked organization')) ?></summary>
      <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/cross-share" class="stack" style="margin-top:.6rem;max-width:560px"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
        <div><label><?= e(t('Share with')) ?></label><select name="link_id"><?php foreach ($crossLinks as $l): ?><option value="<?= e($l['link_id']) ?>"><?= e($l['other_name']) ?></option><?php endforeach; ?></select></div>
        <div><label><?= e(t('Credentials')) ?></label>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.2rem">
            <?php foreach ($holderCreds as $c): ?><label style="font-weight:normal"><input type="checkbox" name="credential_ids[]" value="<?= e($c['id']) ?>"> <?= e($c['name']) ?></label><?php endforeach; ?></div></div>
        <div><label style="font-weight:normal"><input type="checkbox" name="include_documents" value="1" checked> <?= e(t('Include the documents (they can view and download, never edit or delete)')) ?></label></div>
        <div><button class="btn sm gold"><?= e(t('Share (read-only, live)')) ?></button></div>
      </form>
    </details>
    <?php endif; ?>
    <?php if ($crossSharesOut): ?>
    <div class="hint" style="font-size:.78rem;margin:.2rem 0"><?= e(t('Shared by you')) ?>:</div>
    <table style="font-size:.8rem">
      <?php foreach ($crossSharesOut as $sh): ?>
      <tr>
        <td data-label="<?= e(t('Credential')) ?>"><?= e($sh['credential_name']) ?> → <strong><?= e($sh['recipient_name']) ?></strong><?= $sh['include_documents'] ? ' <span class="hint">📎</span>' : '' ?></td>
        <td class="hint" style="white-space:nowrap" data-label="<?= e(t('Status')) ?>"><?= $sh['revoked_at'] ? '<span class="chip danger">' . e(t('Withdrawn')) . '</span>' : '<span class="chip active">' . e(t('Active')) . '</span>' ?> · <?= (int)$sh['access_count'] ?> <?= e(t('access(es)')) ?></td>
        <td class="right" style="white-space:nowrap">
          <a class="btn sm line" href="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/cross-shares/<?= e($sh['id']) ?>/log"><?= e(t('Log')) ?></a>
          <?php if (!$sh['revoked_at'] && $canShare): ?><form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/cross-shares/<?= e($sh['id']) ?>/revoke" style="display:inline"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm line"><?= e(t('Withdraw')) ?></button></form><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($crossSharesIn): ?>
    <div style="margin:.8rem 0 .2rem;font-weight:600;font-size:.85rem"><?= e(t('Shared with you')) ?> <span class="hint" style="font-weight:400">— <?= e(t('live from the owning organization, read-only')) ?></span></div>
    <?php foreach ($crossSharesIn as $sc): $chip = ['active'=>'active','expiring'=>'warn','expired'=>'danger']; ?>
      <div style="border:1px dashed var(--line,#e3e1da);border-radius:8px;padding:.5rem .7rem;margin-bottom:.4rem;background:rgba(37,99,235,.03)">
        <div style="display:flex;justify-content:space-between;gap:.5rem;flex-wrap:wrap;align-items:center">
          <div><strong><?= e($sc['name']) ?></strong> <span class="hint"><?= e(t('from')) ?> <?= e($sc['owner_name']) ?></span></div>
          <span class="chip <?= $chip[$sc['status']] ?? '' ?>"><?= e(ucfirst((string)$sc['status'])) ?></span>
        </div>
        <div class="hint" style="font-size:.78rem"><?= e(t('Expires')) ?> <?= (!empty($sc['expiry_date']) ? e(fmt_day($sc['expiry_date'])) : '—') ?> · <?= e($sc['issuing_body'] ?: '') ?><?php if ($sc['last_verified_at']): ?> · <?= e(t('verified')) ?> <?= e(fmt_dt((string)$sc['last_verified_at'], 'm/d/Y')) ?><?php endif; ?></div>
        <?php foreach (($sc['documents'] ?? []) as $d): ?>
          <div style="margin-top:.25rem"><a class="btn sm line" href="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/shared-doc/<?= e($sc['share_id']) ?>/<?= e($d['id']) ?>" target="_blank">📄 <?= e($d['original_name']) ?></a>
            <?php if ($d['verified_at']): ?><span class="chip active" style="font-size:.6rem"><?= e(t('Verified')) ?></span><?php endif; ?></div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

</section>
<section class="tabpane" data-pane="activity" hidden>
<?php // ---- Document requests: ask the provider/office for a document (fulfilled in their portal) ----
$docRequests = $docRequests ?? []; $holderCreds = $holderCreds ?? [];
if (FlxCoreHC\Authz::can($ctx, 'documents.manage')): ?>
<div class="panel" id="requests" style="margin-top:1rem">
  <div class="topline"><h3 style="font-size:.92rem;margin:0"><?= e(t('Document requests')) ?></h3>
    <span class="hint"><?= e(t('The provider uploads in their portal — the e-mail only links to it and never carries documents')) ?></span></div>
  <details style="margin:.3rem 0 .6rem"><summary class="btn sm line" style="display:inline-block;cursor:pointer"><?= e(t('Request a document')) ?></summary>
    <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/request-document" class="stack" style="margin-top:.6rem;max-width:520px">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <div><label><?= e(t('What do you need?')) ?></label>
        <select name="kind"><?php foreach (FlxCoreHC\DocumentRequestService::KINDS as $k=>$lbl): ?><option value="<?= e($k) ?>"><?= e($lbl) ?></option><?php endforeach; ?></select></div>
      <div><label><?= e(t('Attach to credential')) ?> <span class="hint">(<?= e(t('optional')) ?>)</span></label>
        <select name="credential_id"><option value=""><?= e(t('— new / not tied to one —')) ?></option>
          <?php foreach ($holderCreds as $c): ?><option value="<?= e($c['id']) ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="grid2">
        <div><label><?= e(t('Note to the provider')) ?></label><input name="message" maxlength="500" placeholder="<?= e(t('e.g. Please send the 2027 renewal certificate')) ?>"></div>
        <div><label><?= e(t('Needed by')) ?></label><input type="date" name="due_at"></div>
      </div>
      <div><button class="btn sm"><?= e(t('Send request')) ?></button></div>
    </form>
  </details>
  <?php if ($docRequests === []): ?><p class="hint"><?= e(t('No requests yet.')) ?></p>
  <?php else: $stChip = ['open'=>'','submitted'=>'warn','returned'=>'danger','accepted'=>'active','cancelled'=>'']; ?>
  <table style="font-size:.8rem">
    <tr><th><?= e(t('Requested')) ?></th><th><?= e(t('Document')) ?></th><th><?= e(t('Status')) ?></th><th></th></tr>
    <?php foreach ($docRequests as $r): ?>
    <tr>
      <td class="mono hint" style="white-space:nowrap" data-label="<?= e(t('Requested')) ?>"><?= e(fmt_dt($r['requested_at'], 'm/d/Y')) ?><?php if ($r['due_at']): ?> · <?= e(t('by')) ?> <?= e(fmt_day((string)$r['due_at'])) ?><?php endif; ?></td>
      <td data-label="<?= e(t('Document')) ?>"><?= e($r['requested_kind']) ?><?php if ($r['credential_name']): ?> <span class="hint">→ <?= e($r['credential_name']) ?></span><?php endif; ?>
        <?php if ($r['document_name']): ?><div class="hint" style="font-size:.72rem">📎 <?= e($r['document_name']) ?><?php if ($r['submitted_by']): ?> · <?= e($r['submitted_by']) ?><?php endif; ?></div><?php endif; ?>
        <?php if ($r['returned_note'] && $r['status']==='returned'): ?><div class="hint" style="font-size:.72rem"><?= e(t('Returned')) ?>: <?= e($r['returned_note']) ?></div><?php endif; ?></td>
      <td data-label="<?= e(t('Status')) ?>"><span class="chip <?= $stChip[$r['status']] ?? '' ?>"><?= e(ucfirst((string)$r['status'])) ?></span></td>
      <td class="right" style="white-space:nowrap">
        <?php if ($r['status'] === 'submitted'): ?>
          <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/requests/<?= e($r['id']) ?>/accept" style="display:inline"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm gold"><?= e(t('Accept')) ?></button></form>
          <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/requests/<?= e($r['id']) ?>/return" style="display:inline"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="note" value="Please resubmit a clearer copy."><button class="btn sm line"><?= e(t('Return')) ?></button></form>
        <?php elseif (in_array($r['status'], ['open','returned'], true)): ?>
          <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/requests/<?= e($r['id']) ?>/cancel" style="display:inline"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm line"><?= e(t('Cancel')) ?></button></form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php // ---- External shares: password + expiry protected read-only profile for payers/agencies ----
$extShares = $extShares ?? []; $extReveal = $extReveal ?? null;
if (FlxCoreHC\Authz::can($ctx, 'share.manage')): ?>
<div class="panel" id="external" style="margin-top:1rem">
  <div class="topline"><h3 style="font-size:.92rem;margin:0"><?= e(t('External shares')) ?></h3>
    <span class="hint"><?= e(t('Payers, state agencies, hospitals — password + expiry, every access logged')) ?></span></div>
  <?php if ($extReveal): ?>
    <div class="panel" style="border-left:3px solid var(--ok,#2f7d5b);margin:.4rem 0 .8rem">
      <strong><?= e(t('Link created — copy both now. The password will not be shown again and is never emailed with the link.')) ?></strong>
      <div style="margin:.4rem 0"><label class="hint"><?= e(t('Link')) ?></label><input readonly value="<?= e($extReveal['url']) ?>" style="width:100%;font-family:monospace;font-size:.8rem"></div>
      <div><label class="hint"><?= e(t('Password')) ?> — <?= e(t('deliver separately (phone / other message)')) ?></label><input readonly value="<?= e($extReveal['password']) ?>" style="max-width:260px;font-family:monospace"></div>
      <div class="hint" style="margin-top:.3rem"><?= e(t('Expires')) ?> <?= e(fmt_dt($extReveal['expires_at'], 'm/d/Y')) ?></div>
    </div>
  <?php endif; ?>
  <details style="margin:.3rem 0 .6rem"><summary class="btn sm line" style="display:inline-block;cursor:pointer"><?= e(t('Share externally')) ?></summary>
    <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/share-external" class="stack" style="margin-top:.6rem;max-width:560px">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <div class="grid2">
        <div><label><?= e(t('Recipient type')) ?></label><select name="recipient_type"><?php foreach (FlxCoreHC\ExternalShareService::TYPES as $k=>$lbl): ?><option value="<?= e($k) ?>"><?= e($lbl) ?></option><?php endforeach; ?></select></div>
        <div><label><?= e(t('Recipient name')) ?></label><input name="recipient_name" required placeholder="Triple-S, PR Board of Medicine…"></div>
      </div>
      <div><label><?= e(t('Include credentials')) ?></label>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.2rem">
          <?php foreach ($holderCreds as $c): ?><label style="font-weight:normal"><input type="checkbox" name="credential_ids[]" value="<?= e($c['id']) ?>" checked> <?= e($c['name']) ?></label><?php endforeach; ?>
        </div></div>
      <div><label style="font-weight:normal"><input type="checkbox" name="include_documents" value="1" checked> <?= e(t('Include the documents on those credentials')) ?></label></div>
      <div class="grid2">
        <div><label><?= e(t('Active for (days)')) ?></label><input type="number" name="days" value="30" min="1" max="<?= (int)FlxCoreHC\ExternalShareService::MAX_DAYS ?>" required>
          <div class="hint"><?= e(t('Max')) ?> <?= (int)FlxCoreHC\ExternalShareService::MAX_DAYS ?> <?= e(t('days — share again if more time is needed.')) ?></div></div>
        <div><label><?= e(t('Password')) ?> <span class="hint">(<?= e(t('blank = generate a strong one')) ?>)</span></label><input name="password" minlength="8" autocomplete="off"></div>
      </div>
      <div><label><?= e(t('Max views')) ?> <span class="hint">(<?= e(t('optional')) ?>)</span></label><input type="number" name="max_views" min="1" style="max-width:120px"></div>
      <div><button class="btn sm gold"><?= e(t('Create share link')) ?></button></div>
    </form>
  </details>
  <?php if ($extShares === []): ?><p class="hint"><?= e(t('No external shares yet.')) ?></p>
  <?php else: $sc = ['active'=>'active','expired'=>'','revoked'=>'danger','exhausted'=>'warn']; ?>
  <table style="font-size:.8rem">
    <tr><th><?= e(t('Recipient')) ?></th><th><?= e(t('Created')) ?></th><th><?= e(t('Expires')) ?></th><th><?= e(t('Views')) ?></th><th><?= e(t('Status')) ?></th><th></th></tr>
    <?php foreach ($extShares as $s): ?>
    <tr>
      <td data-label="<?= e(t('Recipient')) ?>"><strong><?= e($s['recipient_name']) ?></strong> <span class="hint"><?= e(FlxCoreHC\ExternalShareService::TYPES[$s['recipient_type']] ?? $s['recipient_type']) ?></span></td>
      <td class="hint" style="white-space:nowrap" data-label="<?= e(t('Created')) ?>"><?= e(fmt_dt($s['created_at'], 'm/d/Y')) ?> · <?= e($s['created_by_name'] ?? '') ?></td>
      <td class="hint" style="white-space:nowrap" data-label="<?= e(t('Expires')) ?>"><?= e(fmt_dt($s['expires_at'], 'm/d/Y')) ?></td>
      <td class="mono" data-label="<?= e(t('Views')) ?>"><?= (int)$s['view_count'] ?><?= $s['max_views'] ? '/' . (int)$s['max_views'] : '' ?><?php if ($s['last_viewed_at']): ?> <span class="hint">· <?= e(fmt_dt($s['last_viewed_at'], 'm/d/Y g:i A')) ?></span><?php endif; ?></td>
      <td data-label="<?= e(t('Status')) ?>"><span class="chip <?= $sc[$s['state']] ?? '' ?>"><?= e(ucfirst($s['state'])) ?></span></td>
      <td class="right" style="white-space:nowrap">
        <a class="btn sm line" href="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/external-shares/<?= e($s['id']) ?>/log"><?= e(t('Access log')) ?></a>
        <?php if ($s['state'] === 'active'): ?>
          <form method="post" action="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/external-shares/<?= e($s['id']) ?>/revoke" style="display:inline"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm line" data-confirm="<?= e(t('Revoke this link? The recipient will lose access immediately.')) ?>"><?= e(t('Revoke')) ?></button></form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php // ---- Compliance timeline: the permanent, year-spanning record ----
$timeline = $timeline ?? null; if ($timeline !== null && $timeline !== []):
  $evIcon = ['credential_added'=>'＋','credential_renewed'=>'↻','credential_edited'=>'✎','credential_removed'=>'✕',
    'credential_expired'=>'⏰','document_uploaded'=>'📎','document_replaced'=>'↻','document_verified'=>'✔','requirement_met'=>'✔','status_changed'=>'•'];
  // group by year for a multi-year record
  $byYear = [];
  foreach ($timeline as $ev) { $byYear[substr((string)$ev['created_at'],0,4)][] = $ev; }
?>
<div class="panel" id="timeline" style="margin-top:1rem">
  <div class="topline"><h3 style="font-size:.92rem;margin:0"><?= e(t('Compliance timeline')) ?></h3>
    <a class="btn sm line" href="<?= BASE_PATH ?>/holders/<?= e($row['id']) ?>/history.csv"><?= e(t('Export full history')) ?></a></div>
  <p class="hint" style="margin:.1rem 0 .5rem"><?= e(t('Permanent record of everything that has happened to this provider\'s credentials and documents — kept across all years, never overwritten.')) ?></p>
  <?php foreach ($byYear as $yr => $rows): ?>
    <div style="font-weight:600;font-size:.8rem;margin:.5rem 0 .2rem;color:var(--mut,#666)"><?= e($yr) ?> · <?= count($rows) ?></div>
    <table style="font-size:.8rem">
      <?php foreach ($rows as $ev): ?>
      <tr>
        <td class="mono hint" style="white-space:nowrap;width:8.5rem" data-label="<?= e(t('When')) ?>"><?= e(fmt_dt($ev['created_at'], 'm/d/Y g:i A')) ?></td>
        <td style="width:1.4rem"><?= e($evIcon[$ev['event_type']] ?? '•') ?></td>
        <td data-label="<?= e(t('Event')) ?>"><?= e(fmt_iso_text((string)$ev['summary'])) ?>
          <?php if (!empty($ev['effective_on'])): ?><span class="hint">· <?= e(t('effective')) ?> <?= e(fmt_day($ev['effective_on'])) ?></span><?php endif; ?></td>
        <td class="hint" style="white-space:nowrap" data-label="<?= e(t('By')) ?>"><?= e($ev['actor_name'] ?? 'System') ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php // ---- History: everything that happened to this provider's file (audit.view) ----
$history = $history ?? null; if ($history !== null): ?>
<div class="panel" id="history" style="margin-top:1rem">
  <div class="topline"><h3 style="font-size:.92rem;margin:0"><?= e(t('History')) ?></h3>
    <span class="hint"><?= count($history) ?> <?= e(t('entries')) ?></span></div>
  <?php if ($history === []): ?><p class="hint"><?= e(t('No recorded activity yet.')) ?></p>
  <?php else: ?>
  <table style="font-size:.8rem">
    <?php foreach ($history as $h): $act = str_replace('_', ' ', (string)$h['action']); $ch = $h['changes'] ? json_decode((string)$h['changes'], true) : null; ?>
    <tr>
      <td class="mono hint" style="white-space:nowrap;width:9.5rem"><?= e(fmt_dt($h['created_at'], 'm/d/Y g:i A')) ?></td>
      <td><strong><?= e(ucfirst($act)) ?></strong>
        <?php if (is_array($ch) && $ch): ?><span class="hint"><?= e(fmt_iso_in_text(implode(' · ', array_map(fn($k, $v) => is_scalar($v) ? "$k: $v" : $k, array_keys($ch), $ch)))) ?></span><?php endif; ?></td>
      <td class="hint" style="white-space:nowrap"><?= e($h['user_name'] ?? ($h['user_id'] ? 'user' : 'system')) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>
</div>