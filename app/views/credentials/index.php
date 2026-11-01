<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ $title = 'Credentials'; ?>
<div class="crumb"><?= e($ctx->tenantName) ?></div>
<div class="topline">
  <h1>Credentials</h1>
  <span style="display:flex;gap:.4rem"><a class="btn sm line" href="<?= BASE_PATH ?>/credentials/requirements">Requirements catalog</a><a class="btn sm line" href="<?= BASE_PATH ?>/credentials/import">Import CSV</a><?php if (FlxCoreHC\Authz::can($ctx, 'records.delete')): ?><a class="btn sm line" href="<?= BASE_PATH ?>/credentials/trash">Deleted</a><?php endif; ?><a class="btn gold" href="<?= BASE_PATH ?>/credentials/new">Add credential</a></span>
</div>
<p>
  <a class="btn sm <?= $filter === null ? '' : 'line' ?>" href="<?= BASE_PATH ?>/credentials">All</a>
  <a class="btn sm <?= $filter === 'active' ? '' : 'line' ?>" href="<?= BASE_PATH ?>/credentials?status=active"><?= e(t('Active ({n})', ['n' => $counts['active']])) ?></a>
  <a class="btn sm <?= $filter === 'expiring' ? '' : 'line' ?>" href="<?= BASE_PATH ?>/credentials?status=expiring"><?= e(t('Expiring ({n})', ['n' => $counts['expiring']])) ?></a>
  <a class="btn sm <?= $filter === 'expired' ? '' : 'line' ?>" href="<?= BASE_PATH ?>/credentials?status=expired"><?= e(t('Expired ({n})', ['n' => $counts['expired']])) ?></a>
</p>
<div class="panel">
<?php if ($rows === []): ?>
  <div class="empty">No credentials here yet. Every license, certification, and enrollment you add starts being monitored immediately.
    <br><a class="btn gold" href="<?= BASE_PATH ?>/credentials/new">Add a credential</a></div>
<?php else: ?>
  <table>
    <tr><th>Credential</th><th><?= e(vocab('holder')) ?></th><th>Number</th><th>Expires</th><th class="right">Days</th><th>Status</th><th></th></tr>
    <?php foreach ($rows as $c):
      $who = $c['holder_type'] === 'organization' ? $c['org_name'] : trim($c['first_name'] . ' ' . $c['last_name']); ?>
    <tr>
      <td><strong><?= e($c['name']) ?></strong><span class="sub"><?= e($c['issuing_body'] ?? '') ?></span></td>
      <td><?= e($who) ?></td>
      <td class="mono"><?= e($c['credential_number'] ?: '—') ?></td>
      <td class="mono"><?= $c['expiry_date'] ? e(fmt_day($c['expiry_date'])) : '—' ?></td>
      <td class="right mono"><?= $c['days_left'] !== null ? (int) $c['days_left'] : '—' ?></td>
      <td><span class="chip <?= e($c['status']) ?>"><?= e($c['status']) ?></span></td>
      <td class="right"><a class="btn sm line" href="<?= BASE_PATH ?>/credentials/<?= e($c['id']) ?>/edit">Open</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>
</div>
