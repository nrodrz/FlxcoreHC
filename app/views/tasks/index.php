<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $rows */ /** @var array $holderList */ /** @var array $credList */
$title = 'Renewal tasks';
$hname = function($h){ return ($h['holder_type'] ?? '') === 'organization' ? ($h['org_name'] ?? '') : trim((string)($h['first_name'] ?? '').' '.(string)($h['last_name'] ?? '')); };
$priColor = ['high'=>'var(--danger)','normal'=>'var(--accent)','low'=>'var(--ink-3)'];
?>
<div class="crumb"><?= e($ctx->tenantName) ?></div>
<div class="topline"><h1>Renewal tasks &amp; to-dos</h1></div>

<div class="panel">
  <div class="topline"><h2>Add a task or renewal item</h2></div>
  <form class="form-grid" method="post" action="<?= BASE_PATH ?>/tasks">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <div class="full"><label>Title</label><input name="title" placeholder="e.g. Renew DEA registration, Follow up on malpractice policy" required></div>
    <div><label>Due date</label><input type="date" name="due_date"></div>
    <div><label>Priority</label><select name="priority"><option value="normal">Normal</option><option value="high">High</option><option value="low">Low</option></select></div>
    <div><label><?= e(vocab('holder')) ?> <span class="hint" style="font-weight:400">(optional)</span></label>
      <select name="holder_id"><option value="">—</option>
        <?php foreach($holderList as $h): ?><option value="<?= e($h['id']) ?>"><?= e($hname($h)) ?></option><?php endforeach; ?>
      </select></div>
    <div><label>Link to credential <span class="hint" style="font-weight:400">(optional — fills due date)</span></label>
      <select name="credential_id"><option value="">—</option>
        <?php foreach($credList as $c): ?><option value="<?= e($c['id']) ?>"><?= e(($c['name'] ?? $c['credential_type'] ?? 'Credential').' · '.$hname($c)) ?></option><?php endforeach; ?>
      </select></div>
    <div class="full"><button class="btn">Add task</button></div>
  </form>
</div>

<div class="panel">
  <div class="topline"><h2>Open items</h2></div>
<?php if ($rows === []): ?>
  <div class="empty">No tasks yet. Add one above, or create a renewal task from a credential when an expiry approaches.</div>
<?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>Task</th><th><?= e(vocab('holder')) ?></th><th>Due</th><th>Priority</th><th>Status</th><th></th></tr>
    <?php foreach ($rows as $t): $who = $hname($t); ?>
    <tr>
      <td><strong><?= e($t['title']) ?></strong><?php if (!empty($t['source_key'])): ?> <span class="chip" style="font-size:.64rem;text-transform:uppercase" title="Created automatically from a compliance gap; closes itself when the gap resolves">auto</span><?php endif; ?><?= !empty($t['credential_name']) ? '<span class="sub">'.e($t['credential_name']).'</span>' : '' ?></td>
      <td><?= $who !== '' ? e($who) : '<span class="hint">—</span>' ?></td>
      <td class="mono"><?= $t['due_date'] ? e(fmt_day($t['due_date'])) : '—' ?></td>
      <td><span class="pdot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $priColor[$t['priority'] ?? 'normal'] ?? 'var(--accent)' ?>;margin-right:5px"></span><?= e(ucfirst($t['priority'] ?? 'normal')) ?></td>
      <td><span class="chip <?= e($t['status']) ?>"><?= e(str_replace('_', ' ', $t['status'])) ?></span></td>
      <td class="right">
        <?php if ($t['status'] !== 'done'): ?>
        <form method="post" action="<?= BASE_PATH ?>/tasks/<?= e($t['id']) ?>/status" data-novalidate style="display:inline">
          <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
          <?php if ($t['status'] === 'pending'): ?>
            <input type="hidden" name="status" value="in_progress"><button class="btn sm line">Start</button>
          <?php else: ?>
            <input type="hidden" name="status" value="done"><button class="btn sm">Mark done</button>
          <?php endif; ?>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
<?php endif; ?>
</div>
