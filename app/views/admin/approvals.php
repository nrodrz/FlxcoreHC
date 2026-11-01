<?php
/** @var FlxCoreHC\TenantContext $ctx */
$title = 'Platform console — Approvals';
$adminTab = 'approvals';
$pendingCount = count($rows);
require FLXCOREHC_APP . '/views/admin/_tabs.php';
?>
<div class="panel">
  <div class="topline"><h2>Organizations awaiting review</h2></div>
  <p class="hint" style="margin-top:-.2rem;max-width:70ch">
    New signups land here with read-only access. Approving unlocks their full role rights on their
    next request; rejecting blocks sign-in and shows them the reason.
  </p>

  <?php if (empty($rows)): ?>
    <p class="hint" style="margin:0">Nothing waiting. New organizations will appear here as they register.</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>Organization</th><th>Owner</th><th>Type</th><th>Requested</th><th style="width:44%"></th></tr>
    <?php foreach ($rows as $t): ?>
    <tr>
      <td><strong><?= e($t['name']) ?></strong></td>
      <td><?= e($t['owner_name'] ?? '—') ?><span class="sub"><?= e($t['owner_email'] ?? '') ?></span>
          <?php if (!empty($t['contact_phone']) || !empty($t['website'])): ?>
          <span class="sub"><?= e($t['contact_phone'] ?? '') ?><?= !empty($t['contact_phone']) && !empty($t['website']) ? ' · ' : '' ?><?= e($t['website'] ?? '') ?></span>
          <?php endif; ?></td>
      <td><span class="hint"><?= e($t['org_shape']) ?></span></td>
      <td class="mono" style="font-size:.8rem"><?= e(fmt_dt((string)$t['created_at'], 'm/d/Y')) ?></td>
      <td>
        <div style="display:flex;gap:.5rem;justify-content:flex-end;flex-wrap:wrap;align-items:center">
          <a class="btn sm line" href="<?= BASE_PATH ?>/admin/tenant/<?= e($t['id']) ?>">Review</a>
          <form method="post" action="<?= BASE_PATH ?>/admin" style="margin:0">
            <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
            <input type="hidden" name="action" value="approve_tenant">
            <input type="hidden" name="tenant_id" value="<?= e($t['id']) ?>">
            <button class="btn sm">Approve</button>
          </form>
          <form method="post" action="<?= BASE_PATH ?>/admin" style="margin:0;display:flex;gap:.4rem"
                data-confirm="Reject this organization? They will not be able to sign in." data-confirm-danger>
            <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
            <input type="hidden" name="action" value="reject_tenant">
            <input type="hidden" name="tenant_id" value="<?= e($t['id']) ?>">
            <input name="reason" placeholder="Reason (shown to them)" style="width:180px;font-size:.8rem">
            <button class="btn sm line" style="color:var(--danger)">Reject</button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
