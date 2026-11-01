<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** Computed onboarding checklist — no schema, derived from real state.
 *  Expects: $row (holder), $mine (compliance producer entry or null), $manage (bool: manager view). */
$steps = [];
// Producers act on their own record through the self-service profile; managers use the staff edit.
$editHref = ($manage ?? true) ? BASE_PATH . '/holders/' . e($row['id']) . '/edit' : BASE_PATH . '/me/profile';
$isHc = $ctx->vertical === 'healthcare';
$steps[] = ['done' => !empty($row['email']), 'label' => 'Contact email on file',
    'href' => $editHref];
$steps[] = ['done' => !empty($row['npi']), 'label' => 'NPI recorded',
    'href' => $editHref];
$steps[] = ['done' => !empty($row['birth_month']),
    'label' => $isHc ? 'Birth month set (renewal-cycle timing)' : 'Birth month set (anchors the CE cycle)',
    'href' => $editHref];
$total = $mine['total'] ?? 0;
$steps[] = ['done' => $total > 0 && empty($mine['defaults']), 'label' => 'Required credentials assigned',
    'href' => BASE_PATH . '/holders/' . e($row['id'])];
$gaps = $total > 0 ? count(array_filter($mine['rows'], fn($r) => $r['status'] === 'missing' || $r['status'] === 'expired')) : null;
$steps[] = ['done' => $total > 0 && $gaps === 0, 'label' => 'Every required credential recorded and current',
    'href' => BASE_PATH . '/credentials/new?holder=' . e($row['id'])];
$unevidenced = $total > 0 ? count(array_filter($mine['rows'], fn($r) => in_array($r['status'], ['active','expiring'], true) && ($r['evidence'] ?? 0) === 0)) : null;
$steps[] = ['done' => $total > 0 && $unevidenced === 0 && $gaps === 0, 'label' => 'Evidence attached to every satisfied requirement',
    'href' => BASE_PATH . '/compliance/producers'];
$doneCount = count(array_filter($steps, fn($s) => $s['done']));
if ($doneCount === count($steps)) return;   // fully onboarded — hide
?>
<div class="panel" style="border-left:3px solid var(--accent)">
  <div class="topline"><h2><?= ($manage ?? true) ? 'Onboarding checklist' : 'Getting started' ?></h2>
    <span class="chip <?= $doneCount >= 4 ? 'active' : 'expiring' ?>"><?= e(t('{n}/{m} done', ['n' => $doneCount, 'm' => count($steps)])) ?></span></div>
  <div style="display:grid;gap:.45rem">
    <?php foreach ($steps as $st): ?>
    <div style="display:flex;align-items:center;gap:.6rem">
      <span style="width:20px;height:20px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;flex:none;
        <?= $st['done'] ? 'background:var(--ok);color:#fff' : 'border:1.5px solid var(--line);color:var(--ink-3)' ?>"><?= $st['done'] ? '✓' : '' ?></span>
      <?php if ($st['done']): ?><span style="opacity:.75"><?= e(t($st['label'])) ?></span>
      <?php else: ?><a href="<?= $st['href'] ?>" style="font-weight:600"><?= e(t($st['label'])) ?> →</a><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
