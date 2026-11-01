<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
$title = $row ? 'Edit credential' : 'Add credential';
// Insurance and healthcare are both catalog-driven now (global seed + this tenant's custom types).
// CatalogService::all() already merges global rows with the tenant's custom rows and excludes
// retired ones, so a single call covers both tiers. The hardcoded lists remain only as a fallback
// for a tenant whose catalog view is empty (pre-migration, or the generic vertical).
$types = null;
if (in_array($ctx->vertical, ['insurance', 'healthcare'], true) && FlxCoreHC\CatalogService::available()) {
    $__cat = FlxCoreHC\CatalogService::all(null, null, null, $ctx->tenantId);
    if ($__cat !== []) {
        $types = [];
        $__renewMap = [];
        foreach ($__cat as $cat) {
            $types[$cat['code']] = $cat['name'];
            if (!empty($cat['renewal_months'])) $__renewMap[$cat['code']] = (int)$cat['renewal_months'];
        }
        $types['other'] = 'Other';
    }
}
$types = $types ?? ($ctx->vertical === 'healthcare'
  ? ['state_medical_license' => 'State professional license', 'dea' => 'DEA registration', 'board_cert' => 'Board certification', 'malpractice' => 'Malpractice coverage', 'caqh' => 'CAQH attestation', 'cme' => 'CME / CE', 'clia' => 'CLIA certificate', 'other' => 'Other']
  : ['license' => 'License', 'certification' => 'Certification', 'training' => 'Training', 'other' => 'Other']);
if ($ctx->vertical === 'custom' && FlxCoreHC\CatalogService::available()) {
    // Generic vertical still appends the tenant's own custom types onto the base list.
    $__custom = [];
    foreach (FlxCoreHC\CatalogService::customFor($ctx->tenantId) as $cc) {
        if (empty($cc['retired_at'])) $__custom[$cc['code']] = $cc['name'];
    }
    if ($__custom !== []) { $other = $types['other'] ?? 'Other'; unset($types['other']); $types += $__custom; $types['other'] = $other; }
}
?>
<?php
  // Where Cancel / back returns to: the producer we came from (if any), else the credentials list.
  $__backHolder = $row['holder_id'] ?? ($_GET['holder'] ?? null);
  $__backUrl = $__backHolder ? BASE_PATH . '/holders/' . e($__backHolder) : BASE_PATH . '/credentials';
  $__backLbl = $__backHolder ? 'the provider' : 'credentials';
?>
<div class="crumb"><a href="<?= BASE_PATH ?>/credentials">Credentials</a>
  <?php if ($__backHolder): ?> · <a href="<?= $__backUrl ?>">provider</a><?php endif; ?> · <?= $row ? 'Edit' : 'New' ?></div>
<h1><?= e($title) ?></h1>
<p class="hint" style="max-width:82ch;margin-top:-.3rem">
  A credential is the <strong>proof</strong> a provider holds — the actual license, certification, or
  policy with its number, expiry date, and document attached. Pick the <strong>Type</strong> that matches
  the requirement it satisfies (e.g. choose "DEA Registration" to clear a DEA requirement) and the
  provider's compliance status updates automatically.
</p>
<?php if ($row): ?><div class="cols-2"><div><?php endif; ?>
<div class="panel">
<form class="stack" method="post" action="<?= BASE_PATH . ($row ? '/credentials/' . e($row['id']) : '/credentials') ?>">
  <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
  <?php if (($_GET['return'] ?? '') === 'holder'): ?><input type="hidden" name="return" value="holder"><?php endif; ?>
  <div><label for="holder_id">Holder</label>
    <?php if ($row): ?>
      <input type="hidden" name="holder_id" value="<?= e($row['holder_id']) ?>">
      <select disabled><option>
        <?php foreach ($holderList as $h) { if ($h['id'] === $row['holder_id']) echo e(FlxCoreHC\Repository\HolderRepository::displayName($h)); } ?>
      </option></select>
    <?php else: ?>
      <select id="holder_id" name="holder_id" required>
        <option value="">Choose a provider…</option>
        <?php foreach ($holderList as $h): ?>
          <option value="<?= e($h['id']) ?>" <?= ($preselect ?? '') === $h['id'] ? 'selected' : '' ?>>
            <?= e(FlxCoreHC\Repository\HolderRepository::displayName($h)) ?><?= $h['holder_type'] === 'organization' ? ' (org)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?></div>
  <div class="grid2">
    <div><label for="credential_type">Type</label>
      <select id="credential_type" name="credential_type">
        <?php foreach ($types as $k => $label): ?>
          <option value="<?= e($k) ?>" <?= ($row['credential_type'] ?? ($_GET['type'] ?? '')) === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
        <?php $carNames = []; foreach (($regulatorList ?? []) as $car) { $carNames[$car['id']] = $car['name']; } ?>
        <?php foreach (($customReqs ?? []) as $carId => $list): if (!isset($carNames[$carId])) continue; ?>
        <optgroup label="<?= e($carNames[$carId]) ?> — regulator requirements">
          <?php foreach ($list as $cr): $val='cr:'.$cr['id']; ?>
            <option value="<?= e($val) ?>" <?= ($row['credential_type'] ?? '') === $val ? 'selected' : '' ?>><?= e($cr['name']) ?></option>
          <?php endforeach; ?>
        </optgroup>
        <?php endforeach; ?>
      </select></div>
    <div><label for="name">Name</label>
      <input id="name" type="text" name="name" value="<?= e($row['name'] ?? ($_GET['name'] ?? '')) ?>" placeholder="FL Medical License, DEA Registration…" required></div>
  </div>
  <div class="grid2">
    <div><label for="issuing_body">Issuing body</label>
      <input id="issuing_body" type="text" name="issuing_body" value="<?= e($row['issuing_body'] ?? '') ?>" placeholder="FL Board of Medicine, DEA, ABIM…"></div>
    <?php $addressOptions = $addressOptions ?? []; ?>
    <div><label for="address_id"><?= e(t('Address on file with this issuer')) ?> <span class="hint">(<?= e(t('optional')) ?>)</span></label>
      <select id="address_id" name="address_id">
        <option value=""><?= e(t('— none —')) ?></option>
        <?php foreach ($addressOptions as $o): ?><option value="<?= e($o['id']) ?>" <?= ($row['address_id'] ?? '') === $o['id'] ? 'selected' : '' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
      </select>
      <?php if ($addressOptions === []): ?><div class="hint"><?= e(t('Add addresses on the provider page or under Organization → Addresses.')) ?></div><?php endif; ?></div>
    <div><label for="credential_number">Credential number</label>
      <input id="credential_number" type="text" name="credential_number" value="<?= e($row['credential_number'] ?? '') ?>"></div>
  </div>
  <div class="grid2">
    <div><label for="issued_date">Issued</label>
      <input id="issued_date" type="date" name="issued_date" value="<?= e($row['issued_date'] ?? '') ?>"></div>
    <div><label for="expiry_date">Expires</label>
      <input id="expiry_date" type="date" name="expiry_date" value="<?= e($row['expiry_date'] ?? '') ?>">
      <div class="hint">Leave empty for credentials that never expire. Status is set from this date.
        <span id="renew-hint"></span></div></div>
  </div>
  <div><label for="regulator_id">Regulator / payer <span class="hint" style="display:inline">(for payer-scoped credentials, e.g. a payer enrollment)</span></label>
    <select id="regulator_id" name="regulator_id">
      <option value="">Not payer-scoped</option>
      <?php foreach ($regulatorList ?? [] as $car): ?>
        <option value="<?= e($car['id']) ?>" <?= ($row['regulator_id'] ?? $row['carrier_id'] ?? '') === $car['id'] ? 'selected' : '' ?>><?= e($car['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="hint">Manage the list under <a href="<?= BASE_PATH ?>/regulators">Regulators</a>.</div></div>
  <div><label for="notes">Notes</label>
    <textarea id="notes" name="notes" rows="2"><?= e($row['notes'] ?? '') ?></textarea></div>
  <div><button class="btn gold"><?= $row ? 'Save changes' : 'Add credential' ?></button>
    <a class="btn line" href="<?= $__backUrl ?>">Cancel</a></div>
</form>
</div>

<?php if ($row): ?>
<?php if ($row): ?></div><div><?php endif; ?>
<div class="panel">
  <h2>Verification records</h2>
  <p class="hint" style="margin-top:-.3rem">Record who verified this credential, how, and against which source. FlxCoreHC records your organization's verification activity and evidence; it does not independently perform or certify primary-source verification. Last verified:
    <span class="mono"><?= e($row['last_verified_at'] ?? 'never') ?></span></p>
  <?php $verifications = $verifications ?? []; if (!FlxCoreHC\RoleService::isPortalContext($ctx) && FlxCoreHC\Authz::can($ctx, 'documents.verify')): ?>
  <form method="post" action="<?= BASE_PATH ?>/credentials/<?= e($row['id']) ?>/verify">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <div class="grid2">
      <div><label>Method</label><select name="method">
        <?php foreach (FlxCoreHC\Repository\VerificationRepository::METHODS as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
      </select></div>
      <div><label>Result</label><select name="result">
        <?php foreach (FlxCoreHC\Repository\VerificationRepository::RESULTS as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
      </select></div>
      <div><label>Source <span class="hint" style="display:inline">(registry, issuer, portal…)</span></label><input type="text" name="source" placeholder="e.g. NPPES, PR Board website"></div>
      <div><label>Verified on</label><input type="date" name="verified_on" value="<?= e(today_local()) ?>"></div>
      <div><label>Verified by <span class="hint" style="display:inline">(defaults to you)</span></label><input type="text" name="verified_by" placeholder="<?= e($ctx->userName ?? '') ?>"></div>
      <div><label>Notes</label><input type="text" name="notes" maxlength="400"></div>
    </div>
    <button class="btn line sm">Record verification</button>
  </form>
  <?php endif; ?>
  <?php if ($verifications !== []): ?>
  <table class="tbl" style="margin-top:.8rem"><tr><th>Date</th><th>Method</th><th>Source</th><th>Result</th><th>By</th></tr>
    <?php foreach ($verifications as $v):
      $rc = $v['result']==='verified' ? 'active' : ($v['result']==='discrepancy'||$v['result']==='expired' ? 'expired' : ''); ?>
      <tr><td class="mono"><?= e(fmt_day($v['verified_on'])) ?></td>
        <td><?= e(FlxCoreHC\Repository\VerificationRepository::METHODS[$v['method']] ?? $v['method']) ?></td>
        <td><?= e($v['source'] ?: '—') ?><?php if (!empty($v['notes'])): ?><span class="sub"><?= e($v['notes']) ?></span><?php endif; ?></td>
        <td><span class="chip <?= $rc ?>"><?= e(FlxCoreHC\Repository\VerificationRepository::RESULTS[$v['result']] ?? $v['result']) ?></span></td>
        <td><?= e($v['verified_by'] ?: '—') ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<div class="panel">
  <h2>Documents</h2>
  <?php if (!empty($docs)): ?>
  <table>
    <tr><th>File</th><th>Uploaded</th><th>Verification</th><th></th></tr>
    <?php foreach ($docs as $d): $isV = !empty($d['verified_at']); ?>
      <tr><td><a href="<?= BASE_PATH ?>/documents/<?= e($d['id']) ?>" target="_blank"><?= e($d['original_name']) ?></a>
            <span class="chip" style="font-size:.6rem;margin-left:.3rem"><?= e(t(ucfirst((string)($d['kind'] ?? 'primary')))) ?></span>
            <?php if (!empty($d['supersedes_id'])): ?><span class="hint" style="font-size:.68rem">↻ <?= e(t('replaces earlier')) ?></span><?php endif; ?></td>
          <td class="mono"><?= e(fmt_dt((string)$d['created_at'], 'm/d/Y')) ?></td>
          <td>
            <?php if ($isV): ?>
              <span class="chip active" title="<?= e(fmt_dt($d['verified_at'], 'm/d/Y g:i A')) ?>">Verified · <?= e(str_replace('_', ' ', (string)$d['verification_method'])) ?></span>
              <div class="hint" style="font-size:.72rem"><?= e(fmt_dt((string)$d['verified_at'], 'm/d/Y')) ?><?php if (!empty($d['verification_expires'])): ?> · re-verify by <?= e(fmt_day((string)$d['verification_expires'])) ?><?php endif; ?><?php if (!empty($d['verification_note'])): ?> · <?= e($d['verification_note']) ?><?php endif; ?></div>
            <?php else: ?><span class="chip">Not verified</span><?php endif; ?>
          </td>
          <td class="right" style="white-space:nowrap">
            <?php if (!FlxCoreHC\Authz::can($ctx, 'documents.verify')): /* v1.170.9: verification is its own privilege — no button for roles that would get a 403 */ ?>
            <?php elseif ($isV): ?>
              <form method="post" action="<?= BASE_PATH ?>/documents/<?= e($d['id']) ?>/unverify" style="display:inline"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm line">Clear</button></form>
            <?php else: ?>
              <form method="post" action="<?= BASE_PATH ?>/documents/<?= e($d['id']) ?>/verify" style="display:inline-flex;gap:.3rem;align-items:center;flex-wrap:wrap">
                <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
                <select name="method" style="max-width:150px">
                  <option value="primary_source">Primary source</option>
                  <option value="copy">Copy reviewed</option>
                  <option value="attestation">Provider attestation</option>
                </select>
                <input name="verification_expires" type="date" title="Re-verify by (optional)" style="max-width:150px">
                <input name="note" placeholder="Note (optional)" style="max-width:160px">
                <button class="btn sm gold">Mark verified</button>
              </form>
            <?php endif; ?>
          </td></tr>
      <?php $dh = ($docHistory ?? [])[$d['id']] ?? []; if ($dh): ?>
      <tr><td colspan="4" style="padding-top:0">
        <details style="margin:0"><summary class="hint" style="cursor:pointer;font-size:.72rem"><?= e(t('Document history')) ?> (<?= count($dh) ?>)</summary>
          <table style="font-size:.74rem;margin:.3rem 0 0">
            <?php foreach ($dh as $h): ?>
            <tr><td class="mono hint" style="white-space:nowrap;width:8.5rem"><?= e(fmt_dt($h['created_at'], 'm/d/Y g:i A')) ?></td>
                <td><?= e(str_replace('_',' ',(string)$h['action'])) ?></td>
                <td class="hint" style="white-space:nowrap"><?= e($h['user_name'] ?? 'System') ?></td></tr>
            <?php endforeach; ?>
          </table>
        </details>
      </td></tr>
      <?php endif; ?>
    <?php endforeach; ?>
  </table>
  <p class="hint" style="margin-top:.4rem"><strong>Primary source</strong> = confirmed directly with the issuing board/agency; <strong>Copy reviewed</strong> = document inspected; <strong>Attestation</strong> = provider's signed statement. Verification is logged with who and when.</p>
  <?php endif; ?>
  <form method="post" action="<?= BASE_PATH ?>/credentials/<?= e($row['id']) ?>/documents" enctype="multipart/form-data" style="margin-top:.8rem" data-doc-upload>
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <div style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center">
      <select name="kind" style="max-width:170px" title="<?= e(t('What kind of document is this?')) ?>">
        <option value="primary"><?= e(t('Primary document')) ?></option><option value="renewal"><?= e(t('Renewal')) ?></option>
        <option value="supporting"><?= e(t('Supporting')) ?></option><option value="correspondence"><?= e(t('Correspondence')) ?></option><option value="other"><?= e(t('Other')) ?></option>
      </select>
      <label class="btn sm line" style="cursor:pointer;margin:0"><?= e(t('Choose file')) ?>
        <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.heic,.heif,.docx,image/*,application/pdf" hidden data-doc-file></label>
      <label class="btn sm line" style="cursor:pointer;margin:0" data-doc-camera-wrap><?= e(t('Take photo')) ?>
        <input type="file" accept="image/*" capture="environment" hidden data-doc-camera></label>
      <button type="button" class="btn sm line" data-doc-scan><?= e(t('Scan (multi-page)')) ?></button>
      <button class="btn sm gold" data-doc-submit disabled><?= e(t('Attach document')) ?></button>
    </div>
    <div class="hint" data-doc-status style="margin-top:.35rem"><?= e(t('PDF, JPG, PNG, HEIC or DOCX up to 10 MB. Photos are sharpened and shrunk on your device before upload. Stored outside the web root, served only to your organization.')) ?></div>
    <div data-doc-preview style="display:none;margin-top:.5rem"></div>
  </form>
</div>
<form method="post" action="<?= BASE_PATH ?>/credentials/<?= e($row['id']) ?>/delete"
      data-confirm="Remove this credential and its reminders?" data-confirm-danger>
  <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
  <button class="btn danger sm">Remove credential</button>
</form>
</div></div>

<?php // ---- This credential's history: audit actions + compliance events, on the same page ----
$credHistory = $credHistory ?? null; $credEvents = $credEvents ?? [];
if ($credHistory !== null || $credEvents !== []):
  // merge both streams into one time-sorted list
  $merged = [];
  foreach (($credEvents ?? []) as $e) $merged[] = ['t'=>$e['created_at'],'label'=>$e['summary'],'who'=>$e['actor_name'] ?? 'System','kind'=>'event'];
  foreach (($credHistory ?? []) as $a) $merged[] = ['t'=>$a['created_at'],'label'=>str_replace('_',' ',(string)$a['action']),'who'=>$a['user_name'] ?? 'System','kind'=>'audit'];
  usort($merged, fn($x,$y)=>strcmp((string)$y['t'],(string)$x['t']));
?>
<div class="panel" id="cred-history" style="margin-top:1rem">
  <div class="topline"><h3 style="font-size:.92rem;margin:0"><?= e(t('Credential history')) ?></h3>
    <span class="hint"><?= count($merged) ?> <?= e(t('entries')) ?></span></div>
  <p class="hint" style="margin:.1rem 0 .5rem"><?= e(t('Everything that has happened to this credential and its documents — permanent and in one place.')) ?></p>
  <?php if ($merged === []): ?><p class="hint"><?= e(t('No recorded activity yet.')) ?></p>
  <?php else: ?>
  <table style="font-size:.8rem">
    <?php foreach ($merged as $m): ?>
    <tr>
      <td class="mono hint" style="white-space:nowrap;width:8.5rem" data-label="<?= e(t('When')) ?>"><?= e(fmt_dt($m['t'], 'm/d/Y g:i A')) ?></td>
      <td style="width:1.2rem"><?= $m['kind']==='event'?'●':'○' ?></td>
      <td data-label="<?= e(t('Event')) ?>"><?= e($m['label']) ?></td>
      <td class="hint" style="white-space:nowrap" data-label="<?= e(t('By')) ?>"><?= e($m['who']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<script <?= nonce_attr() ?>>
(function(){
  var renew = <?= flx_json($__renewMap ?? [], JSON_UNESCAPED_SLASHES) ?>;
  var type = document.getElementById('credential_type');
  var issued = document.getElementById('issued_date');
  var expiry = document.getElementById('expiry_date');
  var hint = document.getElementById('renew-hint');
  if (!type || !issued || !expiry) return;
  function addMonths(d, m){ var dt = new Date(d + 'T00:00:00'); var day = dt.getDate();
    dt.setMonth(dt.getMonth() + m); if (dt.getDate() < day) dt.setDate(0);
    return dt.toISOString().slice(0,10); }
  function suggest(force){
    var months = renew[type.value];
    if (!months || !issued.value) { if (hint) hint.textContent=''; return; }
    var calc = addMonths(issued.value, months);
    // only auto-fill when empty (or when the user just changed type/issued and expiry was our suggestion)
    if (force || !expiry.value) { expiry.value = calc; }
    if (hint) hint.textContent = ' This type renews every ' + months + ' months → suggested ' + calc.slice(5,7) + '/' + calc.slice(8,10) + '/' + calc.slice(0,4) + '.';
  }
  type.addEventListener('change', function(){ suggest(true); });
  issued.addEventListener('change', function(){ suggest(!expiry.value); });
  suggest(false);
})();
</script>
<script <?= nonce_attr() ?> src="<?= BASE_PATH ?>/assets/docscan.js?v=<?= e(FLXCOREHC_VERSION) ?>"></script>
<script <?= nonce_attr() ?> src="<?= BASE_PATH ?>/assets/docscanner.js?v=<?= e(FLXCOREHC_VERSION) ?>"></script>
