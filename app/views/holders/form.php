<?php
$isOrg = $row ? (($row['holder_type'] ?? '') === 'organization') : (($type ?? 'individual') === 'organization');
$word  = $isOrg ? 'organization' : strtolower(vocab('holder'));
$title = $row ? ('Edit ' . $word) : ('Add ' . $word);
$__typeLabels = FlxCoreHC\Vocab::appliesToOptions('healthcare');
$__allowed = $isOrg ? FlxCoreHC\HolderInput::orgTypes() : FlxCoreHC\HolderInput::individualTypes();
?>
<div class="crumb"><a href="<?= BASE_PATH ?>/holders"><?= e(vocab('holders')) ?></a></div>
<h1><?= $row ? 'Edit ' . e($word) : 'Add ' . e($word) ?></h1>
<div class="panel">
<form class="stack" method="post" action="<?= BASE_PATH . ($row ? '/holders/' . e($row['id']) : '/holders') ?>" data-holder-form="<?= $isOrg ? 'organization' : 'individual' ?>">
  <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
  <?php if (!$row): /* choosing the kind of record: plain links, so each kind gets its own form (no JavaScript needed) */ ?>
  <input type="hidden" name="holder_type" value="<?= $isOrg ? 'organization' : 'individual' ?>">
  <div><label>Record type</label>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap" role="tablist" data-holder-type="<?= $isOrg ? 'organization' : 'individual' ?>">
      <a role="tab" aria-selected="<?= $isOrg ? 'false' : 'true' ?>" class="btn sm <?= $isOrg ? 'line' : '' ?>" href="<?= BASE_PATH ?>/holders/new?type=individual">A person — doctor, nurse, therapist…</a>
      <a role="tab" aria-selected="<?= $isOrg ? 'true' : 'false' ?>" class="btn sm <?= $isOrg ? '' : 'line' ?>" href="<?= BASE_PATH ?>/holders/new?type=organization">An organization — group, clinic, laboratory</a>
    </div>
    <div class="hint"><?= $isOrg
      ? 'An organization holds its own credentials: organizational NPI, EIN, CLIA, group malpractice. Its people are added as individual records.'
      : 'A person gets their own credential checklist and, if you wish, a portal login.' ?></div></div>
  <?php endif; ?>
  <?php if ($ctx->vertical === 'healthcare'): ?>
  <div><label for="provider_type"><?= $isOrg ? 'Organization type' : 'Profession' ?></label>
    <select name="provider_type" id="provider_type">
      <?php $__pt = $row['provider_type'] ?? '';
        // Only the types that fit this kind of record (HolderInput is the single source), plus a stored value that is not in the list so it round-trips.
        $__opts = ['' => 'Not specified'];
        foreach ($__allowed as $__v) $__opts[$__v] = $__typeLabels[$__v] ?? $__v;
        if ($__pt !== '' && !isset($__opts[$__pt])) $__opts[$__pt] = $__typeLabels[$__pt] ?? $__pt;
        foreach ($__opts as $__v => $__l): ?>
        <option value="<?= e($__v) ?>"<?= $__pt === $__v ? ' selected' : '' ?>><?= e($__l) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="hint"><?= $isOrg
      ? 'Drives which credentialing template applies — a laboratory tracks CLIA and the organizational NPI; a clinic tracks its facility licence.'
      : 'Drives which credentialing template applies — a physician tracks license, DEA and board certification.' ?></div></div>
  <?php endif; ?>
  <?php if ($isOrg): ?>
  <div><label for="org_name">Organization name</label>
    <input id="org_name" type="text" name="org_name" value="<?= e($row['org_name'] ?? '') ?>" required></div>
  <?php else: ?>
  <div class="grid2">
    <div><label for="first_name">First name</label><input id="first_name" type="text" name="first_name" value="<?= e($row['first_name'] ?? '') ?>" required></div>
    <div><label for="last_name">Last name</label><input id="last_name" type="text" name="last_name" value="<?= e($row['last_name'] ?? '') ?>" required></div>
  </div>
  <?php endif; ?>
  <div class="grid2">
    <div><label for="npi"><?= $isOrg ? 'Organizational NPI (NPI-2)' : 'NPI' ?> <span class="hint" style="display:inline">(<?= $isOrg ? 'optional' : 'healthcare' ?>)</span></label>
      <div style="display:flex;gap:.4rem;align-items:stretch">
        <input id="npi" type="text" name="npi" value="<?= e($row['npi'] ?? '') ?>" style="flex:1" autocomplete="off">
        <button type="button" id="npiLookup" class="btn line" style="white-space:nowrap">Look up</button>
      </div>
      <span class="hint" id="npiResult" style="display:none"></span></div>
    <div><label for="license_code"><?= $isOrg ? 'Main licence or certificate' : 'Licence held' ?> <span class="hint" style="display:inline">(optional — sorts matching templates)</span></label>
      <select id="license_code" name="license_code"><option value="">—</option>
      <?php foreach (($licenses ?? []) as $__c): if ($__c['category'] !== 'license') continue; ?>
        <option value="<?= e($__c['code']) ?>" <?= ($row['license_code'] ?? '') === $__c['code'] ? 'selected' : '' ?>><?= e($__c['name']) ?></option>
      <?php endforeach;
        // v1.171.8: a stored code that is not in the offered list (e.g. an organization's CLIA) must round-trip, not be cleared on save.
        $__lc = (string) ($row['license_code'] ?? '');
        $__offered = false;
        foreach (($licenses ?? []) as $__c) { if ($__c['category'] === 'license' && $__c['code'] === $__lc) { $__offered = true; break; } }
        if ($__lc !== '' && !$__offered): ?>
        <?php $__lcName = $__lc; foreach (($licenses ?? []) as $__c) { if ($__c['code'] === $__lc) { $__lcName = $__c['name']; break; } } ?>
        <option value="<?= e($__lc) ?>" selected><?= e($__lcName) ?></option>
      <?php endif; ?></select></div>
    <?php if (!$isOrg): ?>
    <div><label for="birth_month">Birth month <span class="hint" style="display:inline">(renewal-cycle timing where a board uses it)</span></label>
      <select id="birth_month" name="birth_month"><option value="">—</option>
      <?php foreach ([1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'] as $m => $mn): ?>
        <option value="<?= $m ?>" <?= (int)($row['birth_month'] ?? 0) === $m ? 'selected' : '' ?>><?= $mn ?></option>
      <?php endforeach; ?></select></div>
    <?php endif; ?>
  </div>
  <div class="grid2">
    <div><label for="email"><?= $isOrg ? 'Contact e-mail' : 'Email' ?> <span id="emailReq" class="hint"><?= $isOrg ? '(optional)' : '(required — creates their portal login)' ?></span></label><input id="email" type="email" name="email" value="<?= e($row['email'] ?? '') ?>"></div>
    <?php if (!$isOrg): ?>
    <div><label for="role_title">Role / title</label><input id="role_title" type="text" name="role_title" value="<?= e($row['role_title'] ?? '') ?>" placeholder="Internal Medicine, Medical Director…"></div>
    <?php endif; ?>
  <?php if ($row && (!FlxCoreHC\RoleService::isPortalContext($ctx))): ?>
  <details class="panel" style="margin:.6rem 0" <?= !empty($row['caqh_id']) || !empty($row['primary_specialty']) ? 'open' : '' ?>>
    <summary style="cursor:pointer;font-weight:600;font-size:.9rem"><?= e(t('Application data')) ?> <span class="hint" style="font-weight:400"><?= e(t('— what CAQH, OCS, Medicaid and payer applications ask for')) ?></span></summary>
    <div class="stack" style="margin-top:.6rem">
      <?php if (!$isOrg): ?>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem">
        <div><label><?= e(t('Middle name')) ?></label><input name="middle_name" value="<?= e($row['middle_name'] ?? '') ?>"></div>
        <div><label><?= e(t('Suffix')) ?></label><input name="suffix" value="<?= e($row['suffix'] ?? '') ?>" placeholder="MD, Jr."></div>
        <div><label><?= e(t('Gender')) ?></label><select name="gender"><option value="">—</option><?php foreach (['female'=>'Female','male'=>'Male','other'=>'Other / prefer not to say'] as $k=>$l): ?><option value="<?= $k ?>" <?= ($row['gender'] ?? '')===$k?'selected':'' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="grid2">
        <div><label><?= e(t('Prior / maiden names')) ?></label><input name="prior_names" value="<?= e($row['prior_names'] ?? '') ?>"></div>
        <div><label><?= e(t('Languages spoken')) ?></label><input name="languages" value="<?= e($row['languages'] ?? '') ?>" placeholder="Español, English"></div>
      </div>
      <?php endif; ?>
      <div class="grid2">
        <div><label><?= e(t('Primary specialty')) ?></label><input name="primary_specialty" value="<?= e($row['primary_specialty'] ?? '') ?>"></div>
        <div><label><?= e(t('Secondary specialty')) ?></label><input name="secondary_specialty" value="<?= e($row['secondary_specialty'] ?? '') ?>"></div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.5rem">
        <div><label><?= e(t('Taxonomy code')) ?></label><input name="taxonomy_code" value="<?= e($row['taxonomy_code'] ?? ($row['npi_taxonomy'] ?? '')) ?>" placeholder="207Q00000X"></div>
        <div><label>CAQH ID</label><input name="caqh_id" value="<?= e($row['caqh_id'] ?? '') ?>"></div>
        <div><label>Medicare PTAN</label><input name="medicare_ptan" value="<?= e($row['medicare_ptan'] ?? '') ?>"></div>
        <div><label>Medicaid ID</label><input name="medicaid_id" value="<?= e($row['medicaid_id'] ?? '') ?>"></div>
      </div>
      <div class="grid2">
        <div><label><?= e(t('Hospital affiliations')) ?></label><input name="hospital_affiliations" value="<?= e($row['hospital_affiliations'] ?? '') ?>" placeholder="<?= e(t('comma-separated')) ?>"></div>
        <?php if (!$isOrg): ?><div><label><?= e(t('Accepting new patients')) ?></label><select name="accepting_new_patients"><option value="">—</option><option value="1" <?= ($row['accepting_new_patients'] ?? null)==='1'||($row['accepting_new_patients'] ?? null)===1?'selected':'' ?>><?= e(t('Yes')) ?></option><option value="0" <?= ($row['accepting_new_patients'] ?? null)==='0'||($row['accepting_new_patients'] ?? null)===0?'selected':'' ?>><?= e(t('No')) ?></option></select></div><?php endif; ?>
      </div>
      <?php if (!$isOrg && FlxCoreHC\Authz::can($ctx, 'pii.view')): ?>
      <div style="border:1px solid var(--line);border-left:3px solid var(--warn,#c47f1a);border-radius:8px;padding:.6rem .8rem">
        <strong style="font-size:.85rem"><?= e(t('Sensitive identity data')) ?></strong>
        <div class="hint" style="font-size:.75rem;margin:.15rem 0 .5rem"><?= e(t('Encrypted at rest and visible only to users with the "View sensitive identity data" permission. Never included in packets, exports, reports or emails. Every view is logged.')) ?></div>
        <div class="grid2">
          <div><label><?= e(t('Date of birth')) ?></label><input type="date" name="dob" value="<?= e($row['dob'] ?? '') ?>"></div>
          <div><label><?= e(t('SSN — last 4 digits only')) ?></label><input name="ssn_last4" value="<?= e($row['ssn_last4'] ?? '') ?>" maxlength="11" inputmode="numeric" placeholder="1234" autocomplete="off">
            <div class="hint" style="font-size:.7rem"><?= e(t('The full SSN is never stored — if you paste one, only the last 4 are kept.')) ?></div></div>
        </div>
      </div>
      <?php endif; ?>
      <p class="hint" style="margin:0"><?= e(t('Education and work history live on the provider page.')) ?></p>
    </div>
  </details>
  <?php endif; ?>
  </div>
  <?php if (!empty($customFieldsHtml)): ?>
  <div class="panel" style="margin:.6rem 0"><h3 style="margin:0 0 .5rem;font-size:.9rem"><?= e(t('Additional fields')) ?></h3>
    <div class="stack"><?= $customFieldsHtml ?></div></div>
  <?php endif; ?>
  <div><button class="btn gold"><?= $row ? 'Save changes' : 'Add ' . e(strtolower(vocab('holder'))) ?></button>
    <a class="btn line" href="<?= BASE_PATH ?>/holders">Cancel</a></div>
</form>
</div>

<script <?= nonce_attr() ?>>
(function () {
  // NPI lookup against the CMS NPPES registry (server-side, cached). Fills name/org when found.
  var btn = document.getElementById('npiLookup');
  var npi = document.getElementById('npi');
  var out = document.getElementById('npiResult');
  if (!btn || !npi) return;
  btn.addEventListener('click', function () {
    var val = (npi.value || '').replace(/\D/g, '');
    if (val.length !== 10) { show('Enter a 10-digit NPI first.', true); return; }
    btn.disabled = true; btn.innerHTML = '<span class="spin"></span> Looking up…';
    fetch('<?= BASE_PATH ?>/holders/npi-lookup?npi=' + encodeURIComponent(val), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.found) { show(d && d.error ? d.error : 'No NPI record found.', true); return; }
        if (d.entity_type === 'organization') {
          var org = document.getElementById('org_name'); if (org && !org.value) org.value = d.name || '';
        } else {
          var parts = (d.name || '').split(' ');
          var fn = document.getElementById('first_name'), ln = document.getElementById('last_name');
          if (fn && !fn.value) fn.value = parts.shift() || '';
          if (ln && !ln.value) ln.value = parts.join(' ');
        }
        var bits = [d.name]; if (d.credential) bits.push(d.credential);
        if (d.taxonomy) bits.push(d.taxonomy); if (d.state) bits.push((d.city ? d.city + ', ' : '') + d.state);
        show('✓ ' + bits.filter(Boolean).join(' · ') + (d.cached ? ' (cached)' : ''), false);
      })
      .catch(function () { show('Lookup failed — try again shortly.', true); })
      .finally(function () { btn.disabled = false; btn.textContent = 'Look up'; });
  });
  function show(msg, warn) {
    out.style.display = 'block';
    out.textContent = msg;
    out.style.color = warn ? 'var(--danger)' : 'var(--ok)';
  }
})();
</script>
