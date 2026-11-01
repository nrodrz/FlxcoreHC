<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */
$title = 'Platform console — Platform data';
$adminTab = 'platform';
require FLXCOREHC_APP . '/views/admin/_tabs.php';
?>
<div class="panel">
  <div class="topline"><h2>Regulatory content</h2></div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
    <div>
      <label>Verified ruleset version</label>
      <p style="margin:.2rem 0 0"><strong class="mono"><?= e(FlxCoreHC\CatalogService::RULESET_VERSION) ?></strong></p>
      <p class="hint" style="margin-top:.3rem">All regulatory content (NPPES/NPI, DEA, CLIA, state medical &amp; nursing boards,
        and Puerto Rico 20 LPRA sources) is verified against authoritative sources before it ships, and versioned here.
        Shown in tenant footers.</p>
    </div>
    <div>
      <label>Credential catalog</label>
      <p class="hint" style="margin:.2rem 0 0">Tenants are seeded with the verified healthcare credential catalog
        (NPI, state medical/nursing license, DEA, CDS, board certification, CME, malpractice, CAQH, CLIA, plus
        Puerto Rico rows) and can add their own custom types.</p>
    </div>
  </div>
</div>

<div class="panel">
  <div class="topline"><h2>Plans &amp; billing</h2></div>
  <div style="display:flex;gap:.6rem;flex-wrap:wrap">
    <a class="btn sm line" href="<?= BASE_PATH ?>/admin/plans">Manage plans</a><a class="btn sm line" href="<?= BASE_PATH ?>/admin/roles">Global roles</a>
    <a class="btn sm line" href="<?= BASE_PATH ?>/admin/billing">Billing overview</a>
  </div>
  <p class="hint" style="margin:.6rem 0 0"><?= count($plans) ?> plan tier(s) defined. Assign a plan from any organization's page.</p>
</div>
