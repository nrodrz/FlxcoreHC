<?php
/** @var FlxCoreHC\TenantContext $ctx */
$isNew = $tpl === null;
$title = $isNew ? 'New template' : 'Edit template';
$sel   = array_flip($tokens);

// Group the catalog the way a compliance officer thinks about it.
$catLabel = [
    'license'       => 'Licences',
    'certification' => 'Certifications',
    'training'      => 'Training',
    'insurance'     => 'Malpractice / liability insurance',
    'appointment'   => 'Payer enrollments',
    'background'    => 'Background & screening',
    'identifier'    => 'Identifiers',
    'contract'      => 'Contracts',
];
$grouped = [];
foreach ($catalog as $c) { $grouped[$c['category']][] = $c; }
$licenses = array_values(array_filter($catalog, fn($c) => $c['category'] === 'license'));
$val = fn(string $k, $d = '') => e($tpl[$k] ?? $d);
?>
<p class="crumb"><a href="<?= BASE_PATH ?>/templates">Compliance templates</a></p>
<div class="topline">
  <h1><?= $isNew ? 'New template' : e($tpl['name']) ?></h1>
  <?php if (!$isNew && $canManage): ?>
  <form method="post" action="<?= BASE_PATH ?>/templates/<?= e($tpl['id']) ?>/delete" data-confirm="Delete this template? Providers already on it keep their requirements." data-confirm-danger style="margin:0">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn sm line" style="color:var(--danger)">Delete</button>
  </form>
  <?php endif; ?>
</div>

<form method="post" action="<?= BASE_PATH ?>/templates/<?= $isNew ? 'new' : e($tpl['id']) ?>">
  <?= FlxCoreHC\Auth\CsrfGuard::field() ?>

  <div class="panel">
    <div class="topline"><h2>What this template is for</h2></div>
    <div class="form-grid">
      <div class="full"><label>Template name</label>
        <input name="name" required value="<?= $val('name') ?>" placeholder="e.g. PR Physician (MD)" <?= $canManage ? '' : 'disabled' ?>></div>
      <div class="full"><label>Description <span class="hint" style="display:inline">(optional)</span></label>
        <input name="description" value="<?= $val('description') ?>" placeholder="Who this applies to and why" <?= $canManage ? '' : 'disabled' ?>></div>
      <div><label>Applies to</label>
        <select name="applies_to" <?= $canManage ? '' : 'disabled' ?>>
          <?php $__applies = FlxCoreHC\Vocab::appliesToOptions($ctx->vertical); $__defApplies = array_key_first($__applies);
          foreach ($__applies as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= ($tpl['applies_to'] ?? $__defApplies) === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label>Regulator <span class="hint" style="display:inline">(optional — CMS, HIPAA, a payer, a board…)</span></label>
        <select name="regulator_id" <?= $canManage ? '' : 'disabled' ?>>
          <option value="">— General —</option>
          <?php foreach (($regulatorList ?? []) as $rg): ?>
            <option value="<?= e($rg['id']) ?>" <?= ($tpl['regulator_id'] ?? '') === $rg['id'] ? 'selected' : '' ?>><?= e($rg['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label><?= $ctx->vertical === 'healthcare' ? 'Care setting' : 'Lines of business' ?></label>
        <select name="line" <?= $canManage ? '' : 'disabled' ?>>
          <?php foreach (FlxCoreHC\Vocab::lineOptions($ctx->vertical) as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= ($tpl['line'] ?? 'all') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label>State</label>
        <select name="state" <?= $canManage ? '' : 'disabled' ?>>
          <option value="">Any state / federal</option>
          <?php foreach (FlxCoreHC\CatalogService::STATES as $ab => $nm): ?>
            <option value="<?= e($ab) ?>" <?= ($tpl['state'] ?? '') === $ab ? 'selected' : '' ?>><?= e($ab) ?> — <?= e($nm) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint"><?= $ctx->vertical === 'healthcare' ? 'State-specific templates only appear for organizations operating there.' : 'State-specific templates only appear for agencies operating there.' ?></span></div>
      <div><label>Anchoring <?= $ctx->vertical === 'healthcare' ? 'credential' : 'licence' ?> <span class="hint" style="display:inline">(what the <?= e(strtolower(vocab('holder'))) ?> holds)</span></label>
        <select name="license_code" <?= $canManage ? '' : 'disabled' ?>>
          <option value="">— none —</option>
          <?php foreach ($licenses as $l): ?>
            <option value="<?= e($l['code']) ?>" <?= ($tpl['license_code'] ?? '') === $l['code'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint">Used to suggest this template for <?= e(strtolower(vocab('holders'))) ?> holding that <?= $ctx->vertical === 'healthcare' ? 'credential' : 'licence' ?>.</span></div>
    </div>
  </div>

  <div class="panel">
    <div class="topline"><h2>Requirements in this template</h2>
      <input id="reqfilter" placeholder="Filter requirements…" style="max-width:240px;margin:0">
    </div>
    <p class="hint" style="margin-top:-.2rem">Tick everything a provider on this template must hold. Regulator-specific items are listed under each regulator.</p>

    <?php foreach ($catLabel as $cat => $label): if (empty($grouped[$cat])) continue; ?>
      <h3 style="font-size:.86rem;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3);margin:1rem 0 .5rem"><?= e($label) ?></h3>
      <div class="req-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:.4rem">
        <?php foreach ($grouped[$cat] as $c): ?>
        <label class="reqitem" data-search="<?= e(strtolower($c['name'] . ' ' . $c['code'] . ' ' . $label)) ?>"
               style="display:flex;gap:.55rem;align-items:flex-start;padding:.5rem .6rem;border:1px solid var(--line);border-radius:7px;cursor:pointer">
          <input type="checkbox" name="tokens[]" value="<?= e($c['code']) ?>" <?= isset($sel[$c['code']]) ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?> style="margin-top:.15rem">
          <span><strong style="font-size:.88rem"><?= e($c['name']) ?></strong>
            <span class="hint" style="display:block"><?= e(strtoupper($c['authority'])) ?><?= $c['is_required'] ? ' · typically required' : '' ?></span></span>
        </label>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <?php foreach ($regulatorList as $car): $cid = $car['id']; $custom = $customReqs[$cid] ?? []; ?>
      <h3 style="font-size:.86rem;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3);margin:1rem 0 .5rem"><?= e($car['name']) ?></h3>
      <div class="req-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:.4rem">
        <?php foreach ($catalog as $c): if (!in_array($c['code'], FlxCoreHC\CatalogService::CARRIER_SCOPED, true)) continue; $tok = $c['code'] . '@' . $cid; ?>
        <label class="reqitem" data-search="<?= e(strtolower($car['name'] . ' ' . $c['name'])) ?>"
               style="display:flex;gap:.55rem;align-items:flex-start;padding:.5rem .6rem;border:1px solid var(--line);border-radius:7px;cursor:pointer">
          <input type="checkbox" name="tokens[]" value="<?= e($tok) ?>" <?= isset($sel[$tok]) ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?> style="margin-top:.15rem">
          <span><strong style="font-size:.88rem"><?= e($c['name']) ?></strong>
            <span class="hint" style="display:block"><?= e($car['name']) ?></span></span>
        </label>
        <?php endforeach; ?>
        <?php foreach ($custom as $cr): $tok = 'cr:' . $cr['id']; ?>
        <label class="reqitem" data-search="<?= e(strtolower($car['name'] . ' ' . $cr['name'])) ?>"
               style="display:flex;gap:.55rem;align-items:flex-start;padding:.5rem .6rem;border:1px solid var(--line);border-radius:7px;cursor:pointer">
          <input type="checkbox" name="tokens[]" value="<?= e($tok) ?>" <?= isset($sel[$tok]) ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?> style="margin-top:.15rem">
          <span><strong style="font-size:.88rem"><?= e($cr['name']) ?></strong>
            <span class="hint" style="display:block"><?= e($car['name']) ?> · custom</span></span>
        </label>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($canManage): ?>
  <div style="display:flex;gap:.6rem;margin-bottom:2rem">
    <button class="btn">Save template</button>
    <a class="btn line" href="<?= BASE_PATH ?>/templates">Cancel</a>
  </div>
  <?php endif; ?>
</form>

<script <?= nonce_attr() ?>>
(function () {
  var box = document.getElementById('reqfilter');
  if (!box) return;
  box.addEventListener('input', function () {
    var q = box.value.trim().toLowerCase();
    document.querySelectorAll('.reqitem').forEach(function (el) {
      el.style.display = (!q || (el.dataset.search || '').indexOf(q) !== -1) ? '' : 'none';
    });
    document.querySelectorAll('.req-grid').forEach(function (g) {
      var any = Array.prototype.some.call(g.querySelectorAll('.reqitem'), function (i) { return i.style.display !== 'none'; });
      var h = g.previousElementSibling;
      g.style.display = any ? '' : 'none';
      if (h && h.tagName === 'H3') h.style.display = any ? '' : 'none';
    });
  });
})();
</script>
