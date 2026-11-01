<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** Self-service intake: the producer/provider completes their own record — identifiers, license,
 *  and CE anchor — feeding straight into the same holder row staff manage. Staff-owned fields
 *  (name, email/login, status) are shown read-only; identity changes go through the office.
 *  Expects: $ctx, $row (own holder). */
$title = 'My profile';
$isHc = $ctx->vertical === 'healthcare';
$name = $row['holder_type'] === 'individual'
    ? trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))
    : (string)($row['org_name'] ?? '');
?>
<p class="crumb">MY WORKSPACE</p>
<div class="topline"><h1>My profile</h1><a class="btn sm line" href="<?= BASE_PATH ?>/me">Back to my compliance</a></div>
<p class="hint" style="margin-top:-.4rem">Complete your details once — your credentialing team sees them immediately. Name and login changes go through your administrator.</p>
<div class="panel" style="max-width:660px">
  <form class="stack" method="post" action="<?= BASE_PATH ?>/me/profile">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <div class="grid2">
      <div><label>Name</label><input type="text" value="<?= e($name) ?>" disabled></div>
      <div><label>Login email</label><input type="text" value="<?= e((string)($row['email'] ?? '')) ?>" disabled></div>
    </div>
    <div class="grid2">
      <div><label for="p_npi">NPI<?= $isHc ? '' : ' (if any)' ?></label>
        <input id="p_npi" type="text" name="npi" inputmode="numeric" maxlength="10" placeholder="10 digits"
               value="<?= e((string)($row['npi'] ?? '')) ?>">
        <div class="hint">Your individual (Type 1) National Provider Identifier.</div></div>
      <?php if (!empty($row['holder_type']) && $row['holder_type'] === 'individual'): ?>
      <div><label for="p_pt">Provider type</label>
        <select id="p_pt" name="provider_type">
          <?php $__pt = (string)($row['provider_type'] ?? ''); $__opts = FlxCoreHC\Vocab::appliesToOptions('healthcare'); foreach (['' => 'Not specified'] + array_intersect_key($__opts, array_flip(FlxCoreHC\HolderInput::individualTypes())) as $__v => $__l): ?>
            <option value="<?= e($__v) ?>"<?= $__pt === $__v ? ' selected' : '' ?>><?= e($__l) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="hint">Drives which credential checklist applies to you.</div></div>
      <?php endif; ?>
    </div>
    <div class="grid2">
      <div><label for="p_bm">Birth month</label>
        <select id="p_bm" name="birth_month">
          <option value="">—</option>
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>"<?= (int)($row['birth_month'] ?? 0) === $m ? ' selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
          <?php endfor; ?>
        </select>
        <div class="hint"><?= $isHc ? 'Used for renewal-cycle timing where applicable.' : 'Anchors your Florida CE compliance cycle.' ?></div></div>
      <div><label for="p_rt">Role / title</label>
        <input id="p_rt" type="text" name="role_title" maxlength="120" placeholder="e.g. Internal Medicine, Attending"
               value="<?= e((string)($row['role_title'] ?? '')) ?>"></div>
    </div>
    <div><button class="btn gold">Save my profile</button></div>
  </form>
</div>
