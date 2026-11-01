<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * Guided onboarding wizard — step-by-step alternative to the quick "New" form.
 *
 * v1.172.0: one wizard, two real paths.
 *   • A PERSON (doctor, nurse…):      Who → Identifiers → Requirements → Portal access
 *   • An ORGANIZATION (lab, clinic…): Organization → Identifiers → Requirements → Review & finish
 * The path is chosen with plain links (no JavaScript), each path shows only its own fields and type list, and the
 * first step adapts to the kind of organization using the app (solo practice, medical group, hospital, billing company).
 * The record is created at step 1 and refined at each step, so abandoning midway still leaves a usable record that the
 * profile checklist will flag.
 *
 * Expects: $ctx, $step ('who'|'identifiers'|'requirements'|'portal'), $row (null on a new step 1), $type
 *          ('individual'|'organization'), $orgShape, $old (step 1 repopulation), $groups, $locations (step 1),
 *          $tplList (step 3), $hasPortal, $sumGroups, $sumLocations (step 4).
 */
$isOrg      = ($type ?? ($row['holder_type'] ?? 'individual')) === 'organization';
$orgShape   = (string) ($orgShape ?? '');
$old        = is_array($old ?? null) ? $old : [];
$groups     = $groups ?? []; $locations = $locations ?? [];
$typeLabels = FlxCoreHC\Vocab::appliesToOptions('healthcare');
$indTypes   = FlxCoreHC\HolderInput::individualTypes();
$orgTypes   = FlxCoreHC\HolderInput::orgTypes();
$groupWord  = FlxCoreHC\GroupService::label($orgShape);
$steps = $isOrg
    ? ['who' => '1. Organization', 'identifiers' => '2. Identifiers', 'requirements' => '3. Requirements', 'portal' => '4. Review & finish']
    : ['who' => '1. Who',          'identifiers' => '2. Identifiers', 'requirements' => '3. Requirements', 'portal' => '4. Portal access'];
$title = 'Guided onboarding';
$stepKeys = array_keys($steps);
$curIdx = array_search($step, $stepKeys, true);
$base = BASE_PATH . '/holders';
$who = $row
    ? ($row['holder_type'] === 'organization' ? (string)($row['org_name'] ?? '')
       : trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')))
    : '';
$val = static fn(string $k): string => (string) ($old[$k] ?? ($row[$k] ?? ''));
// What step 1 says depends on who is using the app.
$intro = match (true) {
    $isOrg && $orgShape === 'billing_company' => 'Add an organization you credential for a client: a group practice, a clinic or facility, or a laboratory. Its own licences and certificates (for example CLIA or a facility licence) are tracked on this record; its people are added as individual records.',
    $isOrg && $orgShape === 'solo_practice'   => 'Add the practice itself as a record when it holds its own licences or certificates (for example a CLIA certificate or a facility licence). The doctor is added as an individual record.',
    $isOrg                                    => 'Add an organization whose own licences and certificates you track: a group practice, a clinic or facility, or a laboratory. Its people are added as individual records.',
    $orgShape === 'billing_company'           => 'Add a provider you credential for one of your client groups. Choose the client group now so the provider appears under the right client.',
    $orgShape === 'hospital'                  => 'Add a provider on your medical staff. Choose the department so the provider appears in the right place.',
    $orgShape === 'solo_practice'             => 'Add the doctor of this practice. If that is you, use your own name and e-mail.',
    default                                   => 'Add a provider of your practice. Everything else can be added in the next steps — or later.',
};
?>
<p class="crumb"><?= e($ctx->tenantName) ?></p>
<div class="topline"><h1>Guided onboarding<?= $who !== '' ? ' — ' . e($who) : '' ?></h1>
  <a class="btn sm line" href="<?= $base ?>"<?= $row ? ' title="The record so far is kept — the profile checklist will show what\'s still missing"' : '' ?>>Exit</a></div>

<div style="display:flex;gap:.4rem;margin:.2rem 0 1.1rem;flex-wrap:wrap" data-ob-steps="<?= $isOrg ? 'organization' : 'individual' ?>">
  <?php foreach ($steps as $k => $label): $i = array_search($k, $stepKeys, true);
    // Completed steps are clickable (go back and edit); the record already exists so nothing is lost.
    $clickable = $row && $i < $curIdx; $style = $i < $curIdx ? 'background:var(--ok)22;color:var(--ok)' : ($i === $curIdx ? 'background:var(--accent)22;color:var(--accent);font-weight:600' : 'opacity:.5'); ?>
    <?php if ($clickable): ?><a class="chip" href="<?= $base ?>/<?= e($row['id']) ?>/onboard/<?= e($k) ?>" style="<?= $style ?>;text-decoration:none">✓ <?= e($label) ?></a>
    <?php else: ?><span class="chip" style="<?= $style ?>"><?= $i < $curIdx ? '✓ ' : '' ?><?= e($label) ?></span><?php endif; ?>
  <?php endforeach; ?>
</div>

<?php if ($step === 'who'): ?>
<div class="panel" style="max-width:680px">
  <?php if (!$row): /* choosing the path: plain links, so it works with or without JavaScript */ ?>
  <div style="margin-bottom:.9rem"><label>Record type</label>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap" role="tablist" data-ob-type="<?= $isOrg ? 'organization' : 'individual' ?>">
      <a role="tab" aria-selected="<?= $isOrg ? 'false' : 'true' ?>" class="btn sm <?= $isOrg ? 'line' : '' ?>" href="<?= $base ?>/onboard?type=individual">A person — doctor, nurse, therapist…</a>
      <a role="tab" aria-selected="<?= $isOrg ? 'true' : 'false' ?>" class="btn sm <?= $isOrg ? '' : 'line' ?>" href="<?= $base ?>/onboard?type=organization">An organization — group, clinic, laboratory</a>
    </div></div>
  <?php endif; ?>
  <p class="hint" style="margin-top:0"><?= e($intro) ?></p>
  <?php if (!$row && !$isOrg && $orgShape === 'solo_practice' && empty($old)): ?>
    <p style="margin:.2rem 0 .8rem"><a class="btn sm line" href="<?= $base ?>/onboard?type=individual&amp;me=1">I am the doctor — use my name and e-mail</a></p>
  <?php endif; ?>

  <form class="stack" method="post" action="<?= $row ? $base . '/' . e($row['id']) . '/onboard/who' : $base . '/onboard' ?>">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <input type="hidden" name="holder_type" value="<?= $isOrg ? 'organization' : 'individual' ?>">

    <?php if ($isOrg): ?>
      <div><label for="ob_on">Organization name</label>
        <input id="ob_on" type="text" name="org_name" maxlength="160" required value="<?= e($val('org_name')) ?>" placeholder="e.g. Bayview Laboratory LLC"></div>
      <div><label for="ob_opt">Organization type</label>
        <?php $curT = (string) ($old['org_provider_type'] ?? ($row['provider_type'] ?? 'group')); ?>
        <select id="ob_opt" name="org_provider_type">
          <?php foreach ($orgTypes as $code): ?><option value="<?= e($code) ?>"<?= $curT === $code ? ' selected' : '' ?>><?= e($typeLabels[$code] ?? $code) ?></option><?php endforeach; ?>
        </select>
        <div class="hint" style="font-size:.72rem;margin-top:.2rem">The type loads the requirements for that kind of organization (for example a CLIA certificate for a laboratory). You can adjust them afterwards.</div></div>
      <div><label for="ob_em">Contact e-mail <span class="hint" style="display:inline">(optional — organizations do not get a portal login)</span></label>
        <input id="ob_em" type="email" name="email" value="<?= e($val('email')) ?>"></div>
    <?php else: ?>
      <div class="grid2">
        <div><label for="ob_fn">First name</label><input id="ob_fn" type="text" name="first_name" maxlength="80" required value="<?= e($val('first_name')) ?>"></div>
        <div><label for="ob_ln">Last name</label><input id="ob_ln" type="text" name="last_name" maxlength="80" required value="<?= e($val('last_name')) ?>"></div>
      </div>
      <div><label for="ob_pt">Provider type</label>
        <?php $curT = (string) ($old['provider_type'] ?? ($row['provider_type'] ?? 'physician')); ?>
        <select id="ob_pt" name="provider_type">
          <?php foreach ($indTypes as $code): ?><option value="<?= e($code) ?>"<?= $curT === $code ? ' selected' : '' ?>><?= e($typeLabels[$code] ?? $code) ?></option><?php endforeach; ?>
        </select>
        <div class="hint" style="font-size:.72rem;margin-top:.2rem">Choosing a profession loads its required credentials automatically. You can adjust them afterwards.</div></div>
      <div><label for="ob_em">E-mail <span class="hint" style="display:inline">(required — it becomes their portal login)</span></label>
        <input id="ob_em" type="email" name="email" required value="<?= e($val('email')) ?>"></div>
    <?php endif; ?>

    <?php if (!$row && $orgShape !== 'solo_practice'): ?>
      <div><label for="ob_grp"><?= e($groupWord) ?> <span class="hint" style="display:inline">(<?= $orgShape === 'billing_company' ? e(t('the client this record belongs to')) : e(t('optional')) ?>)</span></label>
        <?php if ($groups): ?>
        <select id="ob_grp" name="group_id">
          <option value="">— <?= e($orgShape === 'billing_company' ? t('No client group yet') : t('None')) ?> —</option>
          <?php foreach ($groups as $g): ?><option value="<?= e($g['id']) ?>"<?= (string) ($old['group_id'] ?? '') === (string) $g['id'] ? ' selected' : '' ?>><?= e((string) $g['name']) ?></option><?php endforeach; ?>
        </select>
        <?php else: ?>
        <div class="hint">No <?= e(strtolower(FlxCoreHC\GroupService::label($orgShape, true))) ?> exist yet.<?php if (FlxCoreHC\Authz::can($ctx, 'org.manage')): ?> <a href="<?= BASE_PATH ?>/groups">Create one</a> first, or continue and assign it later from the record.<?php else: ?> It can be assigned later from the record.<?php endif; ?></div>
        <?php endif; ?></div>
    <?php endif; ?>
    <?php if (!$row && $locations): ?>
      <div><label for="ob_loc">Practice location <span class="hint" style="display:inline">(optional)</span></label>
        <select id="ob_loc" name="location_id">
          <option value="">— None —</option>
          <?php foreach ($locations as $l): ?><option value="<?= e($l['id']) ?>"<?= (string) ($old['location_id'] ?? '') === (string) $l['id'] ? ' selected' : '' ?>><?= e((string) $l['name']) ?></option><?php endforeach; ?>
        </select></div>
    <?php endif; ?>

    <div><button class="btn gold"><?= $row ? 'Save &amp; continue →' : 'Create &amp; continue →' ?></button></div>
  </form>
</div>

<?php elseif ($step === 'identifiers'): ?>
<div class="panel" style="max-width:680px">
  <?php if ($isOrg): ?>
  <p class="hint" style="margin-top:0">Identifiers of the organization itself. Leave blank anything you do not have at hand — the profile checklist keeps track.</p>
  <form class="stack" method="post" action="<?= $base ?>/<?= e($row['id']) ?>/onboard/identifiers">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <div class="grid2">
      <div><label for="obi_npi">Organization NPI <span class="hint" style="display:inline">(Type 2)</span></label>
        <input id="obi_npi" type="text" name="npi" inputmode="numeric" maxlength="10" placeholder="10 digits" value="<?= e((string)($row['npi'] ?? '')) ?>"></div>
      <div><label for="obi_tax">Taxonomy code</label>
        <input id="obi_tax" type="text" name="taxonomy_code" maxlength="12" placeholder="e.g. 291U00000X" value="<?= e((string)($row['taxonomy_code'] ?? '')) ?>"></div>
    </div>
    <div class="grid2">
      <div><label for="obi_ptan">Medicare PTAN</label>
        <input id="obi_ptan" type="text" name="medicare_ptan" maxlength="20" value="<?= e((string)($row['medicare_ptan'] ?? '')) ?>"></div>
      <div><label for="obi_mcd">Medicaid ID</label>
        <input id="obi_mcd" type="text" name="medicaid_id" maxlength="20" value="<?= e((string)($row['medicaid_id'] ?? '')) ?>"></div>
    </div>
    <p class="hint" style="margin:0">Licences and certificates of the organization (CLIA, facility licence, accreditation) are added as credentials in the next step and on the record.</p>
  <?php else: ?>
  <p class="hint" style="margin-top:0">Identifiers connect this provider to registries and payers, and drive renewal timing. Leave blank anything you do not have at hand — the profile checklist keeps track.</p>
  <form class="stack" method="post" action="<?= $base ?>/<?= e($row['id']) ?>/onboard/identifiers">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <div class="grid2">
      <div><label for="obi_npi">NPI <span class="hint" style="display:inline">(individual, Type 1)</span></label>
        <input id="obi_npi" type="text" name="npi" inputmode="numeric" maxlength="10" placeholder="10 digits" value="<?= e((string)($row['npi'] ?? '')) ?>"></div>
      <div><label for="obi_sp">Primary specialty</label>
        <input id="obi_sp" type="text" name="primary_specialty" maxlength="120" value="<?= e((string)($row['primary_specialty'] ?? '')) ?>" placeholder="e.g. Internal Medicine"></div>
    </div>
    <div class="grid2">
      <div><label for="obi_bm">Birth month <span class="hint" style="display:inline">(renewal timing where a board uses it)</span></label>
        <select id="obi_bm" name="birth_month">
          <option value="">—</option>
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>"<?= (int)($row['birth_month'] ?? 0) === $m ? ' selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
          <?php endfor; ?>
        </select></div>
      <div><label for="obi_rt">Role / title</label>
        <input id="obi_rt" type="text" name="role_title" maxlength="120" value="<?= e((string)($row['role_title'] ?? '')) ?>" placeholder="e.g. Attending physician"></div>
    </div>
    <div class="grid2">
      <div><label for="obi_caqh">CAQH ID</label>
        <input id="obi_caqh" type="text" name="caqh_id" maxlength="20" value="<?= e((string)($row['caqh_id'] ?? '')) ?>"></div>
      <div><label for="obi_tax">Taxonomy code</label>
        <input id="obi_tax" type="text" name="taxonomy_code" maxlength="12" placeholder="e.g. 207Q00000X" value="<?= e((string)($row['taxonomy_code'] ?? '')) ?>"></div>
    </div>
    <div class="grid2">
      <div><label for="obi_ptan">Medicare PTAN</label>
        <input id="obi_ptan" type="text" name="medicare_ptan" maxlength="20" value="<?= e((string)($row['medicare_ptan'] ?? '')) ?>"></div>
      <div><label for="obi_mcd">Medicaid ID</label>
        <input id="obi_mcd" type="text" name="medicaid_id" maxlength="20" value="<?= e((string)($row['medicaid_id'] ?? '')) ?>"></div>
    </div>
  <?php endif; ?>
    <div style="display:flex;gap:.6rem;flex-wrap:wrap">
      <a class="btn line" href="<?= $base ?>/<?= e($row['id']) ?>/onboard/who">← Back</a>
      <button class="btn gold">Save &amp; continue →</button>
      <a class="btn line" href="<?= $base ?>/<?= e($row['id']) ?>/onboard/requirements">Skip for now</a>
    </div>
  </form>
</div>

<?php elseif ($step === 'requirements'): ?>
<div class="panel" style="max-width:680px">
  <p class="hint" style="margin-top:0">A template assigns the full requirement set for this kind of <?= $isOrg ? 'organization' : 'provider' ?> in one step — best matches first. You can fine-tune afterwards.</p>
  <?php if (empty($tplList)): ?>
    <div class="empty">No templates exist yet for this type. You can create one under Compliance templates, or skip and assign requirements manually later.</div>
    <div style="margin-top:.8rem;display:flex;gap:.6rem"><a class="btn line" href="<?= $base ?>/<?= e($row['id']) ?>/onboard/identifiers">← Back</a><a class="btn gold" href="<?= $base ?>/<?= e($row['id']) ?>/onboard/portal">Continue →</a></div>
  <?php else: ?>
  <form class="stack" method="post" action="<?= $base ?>/<?= e($row['id']) ?>/onboard/requirements">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <div class="stack" style="gap:.45rem">
      <?php foreach ($tplList as $i => $t): ?>
        <label style="display:flex;gap:.6rem;align-items:flex-start;border:1px solid var(--line);border-radius:10px;padding:.6rem .8rem;cursor:pointer">
          <input type="radio" name="template_id" value="<?= e($t['id']) ?>"<?= $i === 0 ? ' checked' : '' ?> style="margin-top:.2rem">
          <span><strong><?= e($t['name']) ?></strong>
            <?php if (!empty($t['state'])): ?><span class="chip" style="margin-left:.35rem"><?= e($t['state']) ?></span><?php endif; ?>
            <?php if (!empty($t['description'])): ?><span class="sub"><?= e($t['description']) ?></span><?php endif; ?>
            <span class="sub"><?= (int)($t['item_count'] ?? 0) ?> requirements</span></span>
        </label>
      <?php endforeach; ?>
      <label style="display:flex;gap:.6rem;align-items:center;border:1px dashed var(--line);border-radius:10px;padding:.6rem .8rem;cursor:pointer">
        <input type="radio" name="template_id" value="">
        <span class="hint">No template — I'll assign requirements manually</span>
      </label>
    </div>
    <div style="display:flex;gap:.6rem">
      <a class="btn line" href="<?= $base ?>/<?= e($row['id']) ?>/onboard/identifiers">← Back</a>
      <button class="btn gold">Apply &amp; continue →</button>
    </div>
  </form>
  <?php endif; ?>
</div>

<?php elseif ($step === 'portal'): ?>
<div class="panel" style="max-width:680px">
  <?php if ($isOrg): /* an organization has no portal login: this step is a review of what was captured */
    $miss = static fn($v): string => ((string) $v) !== '' ? e((string) $v) : '<span class="hint">— not entered yet</span>';
    $gNames = implode(', ', array_map(fn($g) => (string) $g['name'], $sumGroups ?? []));
    $lNames = implode(', ', array_map(fn($l) => (string) $l['name'], $sumLocations ?? [])); ?>
    <p class="hint" style="margin-top:0">Review what was captured. Organizations do not get portal logins — their people do, as individual records.</p>
    <table style="font-size:.9rem" data-ob-summary="organization">
      <tr><td style="width:12rem"><strong>Organization</strong></td><td><?= $miss($row['org_name'] ?? '') ?></td></tr>
      <tr><td><strong>Type</strong></td><td><?= e($typeLabels[(string) ($row['provider_type'] ?? '')] ?? '—') ?></td></tr>
      <tr><td><strong>Organization NPI</strong></td><td><?= $miss($row['npi'] ?? '') ?></td></tr>
      <tr><td><strong>Taxonomy code</strong></td><td><?= $miss($row['taxonomy_code'] ?? '') ?></td></tr>
      <tr><td><strong>Medicare PTAN</strong></td><td><?= $miss($row['medicare_ptan'] ?? '') ?></td></tr>
      <tr><td><strong>Medicaid ID</strong></td><td><?= $miss($row['medicaid_id'] ?? '') ?></td></tr>
      <?php if ($orgShape !== 'solo_practice'): ?><tr><td><strong><?= e($groupWord) ?></strong></td><td><?= $miss($gNames) ?></td></tr><?php endif; ?>
      <tr><td><strong>Practice location</strong></td><td><?= $miss($lNames) ?></td></tr>
      <tr><td><strong>Contact e-mail</strong></td><td><?= $miss($row['email'] ?? '') ?></td></tr>
    </table>
    <p class="hint">Next on the record: enter each licence or certificate with its number and expiry date, and upload the documents.</p>
    <div style="display:flex;gap:.6rem;flex-wrap:wrap">
      <form method="post" action="<?= $base ?>/<?= e($row['id']) ?>/onboard/portal" style="margin:0">
        <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
        <button class="btn gold">Finish — open the record</button>
      </form>
      <a class="btn line" href="<?= $base ?>/<?= e($row['id']) ?>/onboard/requirements">← Back</a>
    </div>
  <?php elseif (!empty($hasPortal)): ?>
    <p class="hint" style="margin-top:0">This provider already has a portal login. All set.</p>
    <a class="btn gold" href="<?= $base ?>/<?= e($row['id']) ?>">Finish — open the record</a>
  <?php else: ?>
    <p class="hint" style="margin-top:0">The portal lets them see their own compliance, complete their profile, upload documents, and download their CV. The invite email goes to <strong><?= e((string)($row['email'] ?? '')) ?: '(no email on file)' ?></strong>.</p>
    <div style="display:flex;gap:.6rem;flex-wrap:wrap">
      <form method="post" action="<?= $base ?>/<?= e($row['id']) ?>/onboard/portal" style="margin:0">
        <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
        <button class="btn gold"<?= empty($row['email']) ? ' disabled title="Add an email in step 1 first"' : '' ?>>Send portal invite &amp; finish</button>
      </form>
      <a class="btn line" href="<?= $base ?>/<?= e($row['id']) ?>/onboard/requirements">← Back</a>
      <a class="btn line" href="<?= $base ?>/<?= e($row['id']) ?>">Finish without inviting</a>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>
