<?php /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $rows */ /** @var array $filter */ /** @var array $tenants */ /** @var array $actions */
$adminTab = 'audit'; $title = 'Platform audit log';
include __DIR__ . '/_tabs.php';
$qs = function (array $extra) use ($filter) { return http_build_query(array_merge($filter, $extra)); };
$fmt = function ($j) {
    if (!$j) return '';
    $d = json_decode((string) $j, true);
    if (!is_array($d)) return (string) $j;
    $parts = []; foreach ($d as $k => $v) { if (is_scalar($v)) $parts[] = $k . ': ' . $v; }
    return implode(' · ', $parts);
};
?>
<div class="topline"><h1>Platform audit log</h1>
  <a class="btn sm line" href="<?= BASE_PATH ?>/admin/audit/export.csv?<?= e($qs([])) ?>">Export CSV</a></div>
<p class="hint" style="max-width:80ch">Actions across all organizations: who did what, where and when. Settings changes, tests, support access and sign-ins by <strong>platform administrators</strong> show their details. For ordinary users of customer organizations this screen shows only who, what kind of action and when, never the contents of their records.</p>

<form method="get" action="<?= BASE_PATH ?>/admin/audit" class="panel" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:end;margin-bottom:1rem">
  <div><label style="font-size:.72rem">From</label><input type="date" name="from" value="<?= e($filter['from'] ?? '') ?>"></div>
  <div><label style="font-size:.72rem">To</label><input type="date" name="to" value="<?= e($filter['to'] ?? '') ?>"></div>
  <div><label style="font-size:.72rem">Organization</label>
    <select name="tenant_id"><option value="">All</option>
      <?php foreach ($tenants as $t): ?><option value="<?= e($t['id']) ?>" <?= ($filter['tenant_id'] ?? '') === $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
    </select></div>
  <div><label style="font-size:.72rem">Action</label>
    <select name="action"><option value="">All</option>
      <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>" <?= ($filter['action'] ?? '') === $a ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $a)) ?></option><?php endforeach; ?>
    </select></div>
  <div style="flex:1;min-width:150px"><label style="font-size:.72rem">Search</label><input type="search" name="q" value="<?= e($filter['q'] ?? '') ?>" placeholder="action, person or organization" style="width:100%"></div>
  <label style="display:flex;gap:.35rem;align-items:center;font-size:.8rem"><input type="checkbox" name="staff" value="1" <?= !empty($filter['staff']) ? 'checked' : '' ?>> Platform administrators only</label>
  <div><button class="btn sm">Filter</button>
    <?php if ($filter): ?><a class="btn sm line" href="<?= BASE_PATH ?>/admin/audit">Clear</a><?php endif; ?></div>
</form>

<div class="panel">
  <div class="topline" style="margin-bottom:.4rem"><span class="hint"><?= (int) $total ?> entries<?= $filter ? ' (filtered)' : '' ?></span>
    <?php if ($pages > 1): ?><span class="hint">Page <?= (int) $page ?> / <?= (int) $pages ?></span><?php endif; ?></div>
  <?php if ($rows === []): ?><div class="empty">No matching activity.</div><?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>When</th><th>Organization</th><th>Who</th><th>Action</th><th>Record</th><th style="min-width:18rem">Details</th></tr>
    <?php foreach ($rows as $a): ?>
    <tr>
      <td class="mono hint" style="white-space:nowrap"><?= e(fmt_dt($a['created_at'], 'm/d/Y g:i A')) ?></td>
      <td><?= e($a['tenant_name']) ?></td>
      <td><?= e($a['user_name'] ?? 'System') ?><?= $a['is_staff'] ? ' <span class="chip">platform admin</span>' : '' ?><?php if (!empty($a['user_email'])): ?><span class="sub"><?= e($a['user_email']) ?></span><?php endif; ?></td>
      <td><strong><?= e(ucfirst(str_replace('_', ' ', (string) $a['action']))) ?></strong></td>
      <td class="hint"><?= e(ucfirst(str_replace('_', ' ', (string) $a['entity_type']))) ?></td>
      <td class="hint" style="font-size:.78rem;min-width:18rem"><?= e(fmt_iso_text($fmt($a["changes"]))) ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php if ($pages > 1): ?>
  <div style="display:flex;gap:.4rem;justify-content:center;margin-top:.8rem">
    <?php if ($page > 1): ?><a class="btn sm line" href="<?= BASE_PATH ?>/admin/audit?<?= e($qs(['page' => $page - 1])) ?>">← Previous</a><?php endif; ?>
    <?php if ($page < $pages): ?><a class="btn sm line" href="<?= BASE_PATH ?>/admin/audit?<?= e($qs(['page' => $page + 1])) ?>">Next →</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
