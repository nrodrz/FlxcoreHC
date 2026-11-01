<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $org */
$isHc = $ctx->vertical === 'healthcare';
$title = $isHc ? 'Operating jurisdictions' : 'Lines of business';
?>
<?php include __DIR__ . '/../org/_tabs.php'; ?>
<div class="topline"><h1><?= $isHc ? 'Operating jurisdictions' : 'Lines of business' ?></h1></div>
<p class="hint" style="margin:-.4rem 0 1rem">Where your organization operates. This drives which state-specific credential requirements and templates appear across the workspace.</p>

<div class="panel">
  <form method="post" action="<?= BASE_PATH ?>/organization/lines" class="stack">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <?php /* Marks this form as covering lines + states, so an empty selection means
             "none selected" rather than "this form didn't render those fields". */ ?>
    <input type="hidden" name="_lines_form" value="1">

      <div class="topline" style="margin:.4rem 0 0"><h3 style="font-size:.95rem">Operating states</h3></div>
      <span class="hint" style="margin-top:-.3rem">Where your organization's providers practice. State-specific requirements outside these jurisdictions are hidden — a Florida-only group should never see Puerto Rico license rules.</span>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.3rem;max-height:210px;overflow-y:auto;border:1px solid var(--line);border-radius:8px;padding:.6rem">
        <?php $__sel = array_flip(FlxCoreHC\CatalogService::statesOf($org['operating_states'] ?? ''));
              foreach (FlxCoreHC\CatalogService::STATES as $__ab => $__nm): ?>
          <label style="display:flex;gap:.4rem;align-items:center;font-weight:400;font-size:.85rem;cursor:pointer">
            <input type="checkbox" name="operating_states[]" value="<?= e($__ab) ?>" <?= isset($__sel[$__ab]) ? 'checked' : '' ?>>
            <?= e($__ab) ?> <span class="hint" style="display:inline"><?= e($__nm) ?></span></label>
        <?php endforeach; ?>
      </div>
      <span class="hint" style="margin-top:-.2rem">FlxCoreHC ships verified Puerto Rico and Florida content today. Other states are accepted, and their providers still track federal items (NPI, DEA, CLIA) — state-specific rules for them are yours to add as templates.</span>

    <div><button class="btn">Save jurisdictions</button></div>
  </form>
</div>
