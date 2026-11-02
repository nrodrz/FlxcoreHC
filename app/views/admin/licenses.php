<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */ /** @var array $rows */ /** @var string $selected */ /** @var array $history */ /** @var array $plans */ /** @var bool $sodium */
$title = 'Platform console — Licenses';
$adminTab = 'licenses';
require FLXCOREHC_APP . '/views/admin/_tabs.php';
$chip = ['licensed' => 'active', 'grace' => 'warn', 'trial' => 'warn', 'core' => 'expired', 'internal' => 'muted'];
$label = ['licensed' => 'Licensed', 'grace' => 'Grace period', 'trial' => 'Trial', 'core' => 'Core only', 'internal' => 'Internal'];
$mods = FlxCoreHC\LicenseService::MODULES;
$today = today_local();
?>
<div class="topline"><h1>Licenses</h1></div>
<p class="hint" style="max-width:82ch">What each organization may use. A license is a signed record: if it is changed outside this screen it stops counting. <strong>Term ended</strong> → 14 days of grace.
  <strong>No license</strong> → a 30-day trial with every module from the day the organization was created, then the <strong>core only</strong> (no optional module). Nothing is ever deleted.
  Revoking a license leaves the organization with no license. Your own organization and demo organizations are internal and always have everything.</p>
<?php if (!$sodium): ?><p class="error">libsodium is not available on this server, so licenses cannot be signed or checked and every organization is treated as having no license.</p><?php endif; ?>
<div class="panel">
  <div class="table-wrap"><table>
    <tr><th>Organization</th><th>State</th><th>Plan / kind</th><th>Modules</th><th>Users</th><th>Term</th><th>License</th><th></th></tr>
    <?php foreach ($rows as $r): $e = $r['entitlement']; ?>
    <tr>
      <td><strong><?= e($r['name']) ?></strong><div class="hint mono" style="font-size:.7rem"><?= e((string) $r['workspace_id']) ?></div></td>
      <td><span class="chip <?= e($chip[$e['state']] ?? '') ?>"><?= e($label[$e['state']] ?? $e['state']) ?></span><?= $e['tampered'] ? ' <span class="chip expired" title="A license record failed its signature check and is ignored">altered record</span>' : '' ?></td>
      <td><?= e((string) ($e['plan'] ?? '—')) ?> <span class="hint"><?= e($e['kind'] ? (FlxCoreHC\LicenseService::KINDS[$e['kind']] ?? $e['kind']) : '') ?></span></td>
      <td class="hint"><?= $e['modules'] ? e(implode(', ', $e['modules'])) : '—' ?></td>
      <td><?= (int) $r['users_in_use'] ?> / <?= in_array($e['state'], ['licensed', 'grace'], true) ? ($e['users'] === null ? '∞' : (int) $e['users']) : '—' ?></td>
      <td class="hint"><?= $e['term_end'] ? e(fmt_day($e['term_end'])) . ($e['days_left'] !== null ? ' (' . (int) $e['days_left'] . ' d)' : '') : '—' ?></td>
      <td class="mono" style="font-size:.75rem"><?= e((string) ($e['serial'] ?? '')) ?></td>
      <td style="text-align:right;white-space:nowrap">
        <a class="btn sm line" href="<?= BASE_PATH ?>/admin/licenses?tenant=<?= e($r['id']) ?>#issue">Issue / history</a>
        <?php if ($e['serial']): ?>
        <form method="post" action="<?= BASE_PATH ?>/admin/licenses" style="display:inline" data-confirm="Revoke license <?= e($e['serial']) ?>? The organization is left with no license (a trial until its date, then the core only).">
          <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="serial" value="<?= e($e['serial']) ?>">
          <button class="btn sm line">Revoke</button></form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>
<div class="panel" id="issue">
  <div class="topline"><h2>Issue or renew a license</h2></div>
  <form method="post" action="<?= BASE_PATH ?>/admin/licenses">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <div class="grid2">
      <div><label>Organization</label><select name="tenant_id" required>
        <option value="">— choose —</option>
        <?php foreach ($rows as $r): ?><option value="<?= e($r['id']) ?>" <?= $selected === $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
      <div><label>Kind</label><select name="kind"><?php foreach (FlxCoreHC\LicenseService::KINDS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label>Plan (name shown on the license)</label><select name="plan"><option value="">—</option><?php foreach ($plans as $p): ?><option value="<?= e($p['name']) ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
      <div><label>User limit <span class="hint" style="font-weight:400">(empty = unlimited)</span></label><input type="number" name="users" min="1" step="1"></div>
      <div><label>Term starts</label><input type="date" name="term_start" value="<?= e($today) ?>" required></div>
      <div><label>Term ends <span class="hint" style="font-weight:400">(empty = no end)</span></label><input type="date" name="term_end" value="<?= e((new DateTimeImmutable($today))->modify('+1 year')->format('Y-m-d')) ?>"></div>
      <div><label>Agreement number</label><input name="agreement" maxlength="80"></div>
      <div><label>Notes</label><input name="notes" maxlength="500"></div>
    </div>
    <label style="margin-top:.6rem">Modules switched on <span class="hint" style="font-weight:400">(the core is always on)</span></label>
    <div style="display:flex;gap:1.2rem;flex-wrap:wrap"><?php foreach ($mods as $code => $lbl): ?><label class="chk" style="font-weight:400"><input type="checkbox" name="modules[]" value="<?= e($code) ?>" checked> <?= e($lbl) ?></label><?php endforeach; ?></div>
    <button class="btn gold" type="submit" style="margin-top:.8rem" <?= $sodium ? '' : 'disabled' ?>>Issue license</button>
    <p class="hint">Issuing a new license for an organization replaces the one in force (the newest valid record wins; older ones stay in the history).</p>
  </form>
</div>
<?php if ($selected !== ''): ?>
<div class="panel">
  <div class="topline"><h2>History</h2></div>
  <?php if (!$history): ?><p class="hint">No license has been issued to this organization.</p><?php else: ?><div class="table-wrap"><table>
    <tr><th>Serial</th><th>Issued</th><th>Kind / plan</th><th>Modules</th><th>Users</th><th>Term</th><th>Signature</th><th>Status</th></tr>
    <?php foreach ($history as $h): ?><tr>
      <td class="mono"><?= e($h['serial']) ?></td><td><?= e(fmt_dt((string) $h['issued_at'])) ?></td>
      <td><?= e((FlxCoreHC\LicenseService::KINDS[$h['kind']] ?? $h['kind']) . ($h['plan'] ? ' / ' . $h['plan'] : '')) ?></td>
      <td class="hint"><?= e(str_replace(',', ', ', (string) $h['modules']) ?: '—') ?></td><td><?= $h['users'] === null ? '∞' : (int) $h['users'] ?></td>
      <td class="hint"><?= e(fmt_day($h['term_start']) . ' → ' . ($h['term_end'] ? fmt_day($h['term_end']) : 'no end')) ?></td>
      <td><?= $h['verified'] ? '<span class="chip active">valid</span>' : '<span class="chip expired">does not verify</span>' ?></td>
      <td><?= $h['revoked_at'] ? 'revoked ' . e(fmt_day($h['revoked_at'])) : 'active' ?></td></tr><?php endforeach; ?>
  </table></div><?php endif; ?>
</div>
<?php endif; ?>
