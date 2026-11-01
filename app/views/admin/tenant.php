<?php /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $d */
$t = $d['tenant']; $users = $d['users']; $title = 'Console — ' . $t['name'];
$adminTab = 'tenants';
require FLXCOREHC_APP . '/views/admin/_tabs.php';
$statusChip = ['pending' => 'warn', 'active' => 'active', 'suspended' => 'danger', 'rejected' => 'danger', 'closed' => 'muted'];
?>
<div class="tenant-head">
  <div>
    <h1 style="margin:0"><?= e($t['name']) ?></h1>
    <p class="hint" style="margin:.25rem 0 0"><?= e($t['org_shape']) ?> · <?= e($t['vertical']) ?> ·</p>
  </div>
  <div class="tenant-head-actions">
    <a class="btn sm line" href="<?= BASE_PATH ?>/admin/tenant/<?= e($t['id']) ?>/report.pdf" title="Full tenant report (PDF)">Report PDF</a>
    <a class="btn sm line" href="<?= BASE_PATH ?>/admin/tenant/<?= e($t['id']) ?>/report.zip" title="Full tenant report (CSV bundle)">Report CSV</a>
    <span class="chip <?= e($statusChip[$t['status']] ?? 'muted') ?>"><?= e(ucfirst($t['status'])) ?></span>
    <?php if ($t['status'] === 'active' && $t['id'] !== $ctx->tenantId): ?>
    <?php if (!empty($t['is_demo'])): ?>
      <form method="post" action="<?= BASE_PATH ?>/admin" style="margin:0">
        <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
        <input type="hidden" name="action" value="impersonate">
        <input type="hidden" name="tenant_id" value="<?= e($t['id']) ?>">
        <button class="btn sm gold">Open demo (full access)</button>
        <span class="hint">demo data — no approval needed</span>
      </form>
    <?php else:
          $__grant = FlxCoreHC\SupportAccess::activeGrant($t['id'], (string)$ctx->userId);
          $__pend  = FlxCoreHC\SupportAccess::pendingFor($t['id']); ?>
    <?php if ($__grant): ?>
      <form method="post" action="<?= BASE_PATH ?>/admin" style="margin:0">
        <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
        <input type="hidden" name="action" value="impersonate">
        <input type="hidden" name="tenant_id" value="<?= e($t['id']) ?>">
        <button class="btn sm gold">Enter support session</button>
        <span class="hint">approved until <?= e(fmt_dt($__grant['expires_at'], 'm/d/Y g:i A')) ?> · settings &amp; training only</span>
      </form>
    <?php elseif ($__pend): ?>
      <span class="chip">Support request pending Master Admin approval</span>
    <?php else: ?>
      <form method="post" action="<?= BASE_PATH ?>/admin" style="margin:0;display:flex;gap:.4rem;align-items:center">
        <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
        <input type="hidden" name="action" value="request_support">
        <input type="hidden" name="tenant_id" value="<?= e($t['id']) ?>">
        <input name="reason" placeholder="Reason (shown to the Master Admin)" required style="max-width:280px">
        <button class="btn sm">Request support access</button>
      </form>
      <div class="hint" style="margin-top:.3rem">Entry requires the tenant's approval — a 4-hour window, settings &amp; training only; provider/credential/document data stays private.</div>
    <?php endif; /* $__grant */ ?>
    <?php endif; /* is_demo */ ?>
    <?php endif; /* active && not self */ ?>
  
  </div>
</div>

<?php if ($t['status'] === 'pending'): ?>
<div class="panel" style="border-left:3px solid var(--warn,#b6862c)">
  <div class="topline"><h2>Awaiting approval</h2></div>
  <p class="hint" style="margin-top:-.2rem">This organization registered and has read-only access. Approve to unlock their roles, or reject with a reason they'll see at sign-in.</p>
  <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
    <form method="post" action="<?= BASE_PATH ?>/admin" style="margin:0">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="action" value="approve_tenant">
      <input type="hidden" name="tenant_id" value="<?= e($t['id']) ?>">
      <button class="btn">Approve organization</button>
    </form>
    <form method="post" action="<?= BASE_PATH ?>/admin" style="margin:0;display:flex;gap:.4rem"
          data-confirm="Reject this organization?" data-confirm-danger>
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="action" value="reject_tenant">
      <input type="hidden" name="tenant_id" value="<?= e($t['id']) ?>">
      <input name="reason" placeholder="Reason (shown at sign-in)" style="width:230px">
      <button class="btn line" style="color:var(--danger)">Reject</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php // ---- Usage & health: COUNTS ONLY. No tenant record contents are shown here. ----
$usage = $usage ?? []; if ($usage):
  $mb = fn(int $b) => $b >= 1048576 ? round($b/1048576,1) . ' MB' : round($b/1024) . ' KB';
  $ago = function(?string $ts): string { if(!$ts) return t('never'); $d=time()-strtotime($ts.' UTC'); if($d<3600) return t('{n}m ago', ['n' => max(1,intdiv($d,60))]); if($d<86400) return t('{n}h ago', ['n' => intdiv($d,3600)]); return t('{n}d ago', ['n' => intdiv($d,86400)]); };
  $wk = $usage['weekly'] ?? [];
  // Always render 12 week slots (oldest → newest) so the chart reads as a timeline even with sparse data.
  $slots = []; for ($w = 11; $w >= 0; $w--) { $key = date('oW', strtotime("-$w weeks")); $slots[$key] = (int)($wk[$key] ?? 0); }
  $wk = $slots; $wmax = max(1, max($wk));
?>
<div class="panel" style="margin-bottom:1rem">
  <div class="topline" style="margin-bottom:.5rem"><h2 style="margin:0;font-size:1rem">Usage &amp; health</h2>
    <span class="hint">counts only — no provider, credential or document contents</span></div>

  <div class="kpis" style="margin-bottom:.8rem">
    <div class="kpi"><span class="n"><?= (int)($usage['providers'] ?? 0) ?></span><span class="l">providers</span></div>
    <div class="kpi"><span class="n"><?= (int)($usage['credentials'] ?? 0) ?></span><span class="l">credentials</span></div>
    <div class="kpi"><span class="n"><?= (int)($usage['documents'] ?? 0) ?></span><span class="l">documents · <?= e($mb((int)($usage['storage_bytes'] ?? 0))) ?></span></div>
    <div class="kpi"><span class="n"><?= (int)($usage['users_active'] ?? 0) ?></span><span class="l">active users<?= ($usage['users_invited'] ?? 0) ? ' (+' . (int)$usage['users_invited'] . ' invited)' : '' ?></span></div>
  </div>

  <div class="grid2" style="gap:1rem">
    <div>
      <div style="font-weight:600;font-size:.85rem;margin-bottom:.3rem">Adoption</div>
      <table style="font-size:.82rem" data-no-stack>
        <tr><th>Team / portal users</th><td><?= (int)($usage['users'] ?? 0) ?> / <?= (int)($usage['portal_users'] ?? 0) ?></td></tr>
        <tr><th>Groups / locations</th><td><?= (int)($usage['groups'] ?? 0) ?> / <?= (int)($usage['locations'] ?? 0) ?></td></tr>
        <tr><th>Own email configured</th><td><?= ($usage['uses_email'] ?? 0) ? '<span class="chip active">verified</span>' : '<span class="chip">platform default</span>' ?></td></tr>
        <tr><th>Document requests used</th><td><?= (int)($usage['doc_requests'] ?? 0) ?></td></tr>
        <tr><th>External shares created</th><td><?= (int)($usage['external_shares'] ?? 0) ?></td></tr>
        <tr><th>Cross-org links active</th><td><?= (int)($usage['cross_links'] ?? 0) ?></td></tr>
        <tr><th>Emails sent (30d)</th><td><?= (int)($usage['emails_30d'] ?? 0) ?></td></tr>
      </table>
    </div>
    <div>
      <div style="font-weight:600;font-size:.85rem;margin-bottom:.3rem">Engagement &amp; health</div>
      <table style="font-size:.82rem" data-no-stack>
        <tr><th>Last login</th><td><?= e($ago($usage['last_login'] ?? null)) ?></td></tr>
        <tr><th>Last activity</th><td><?= e($ago($usage['last_activity'] ?? null)) ?></td></tr>
        <tr><th>Actions 7d / 30d</th><td><?= (int)($usage['actions_7d'] ?? 0) ?> / <?= (int)($usage['actions_30d'] ?? 0) ?></td></tr>
        <tr><th>People active (30d)</th><td><?= (int)($usage['active_users_30d'] ?? 0) ?> of <?= (int)($usage['users_active'] ?? 0) ?></td></tr>
        <tr><th>Credentials expiring</th><td><span class="chip warn"><?= (int)($usage['cred_expiring'] ?? 0) ?></span></td></tr>
        <tr><th>Credentials expired</th><td><span class="chip <?= ($usage['cred_expired'] ?? 0) ? 'danger' : '' ?>"><?= (int)($usage['cred_expired'] ?? 0) ?></span></td></tr>
        <tr><th>Documents verified</th><td><?= (int)($usage['docs_verified'] ?? 0) ?> of <?= (int)($usage['documents'] ?? 0) ?></td></tr>
      </table>
    </div>
  </div>

  <?php if ($wk): ?>
  <div style="margin-top:.8rem">
    <div style="font-weight:600;font-size:.85rem;margin-bottom:.3rem">Activity — last 12 weeks</div>
    <div style="display:flex;align-items:flex-end;gap:4px;height:56px">
      <?php foreach ($wk as $w => $n): $h = $n > 0 ? max(4,(int)round(52*$n/$wmax)) : 2; ?>
        <div title="week <?= e((string)$w) ?>: <?= (int)$n ?>" style="flex:0 0 calc((100% - 44px)/12);max-width:40px;background:var(--accent);opacity:.9;height:<?= $h ?>px;border-radius:2px 2px 0 0"></div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($usage['top_actions'])): ?>
  <div style="margin-top:.8rem">
    <div style="font-weight:600;font-size:.85rem;margin-bottom:.3rem">What they use most (30 days)</div>
    <div style="display:flex;gap:.3rem;flex-wrap:wrap">
      <?php foreach ($usage['top_actions'] as $a): ?>
        <span class="chip"><?= e(str_replace('_',' ',(string)$a['action'])) ?> · <?= (int)$a['n'] ?></span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($t['status'] === 'active' && $t['id'] !== $ctx->tenantId): ?>
<?php endif; ?>

<div class="tenant-cols">
  <div class="tenant-col">
  <div class="panel" style="margin:0 0 1rem">
    <div class="topline"><h2>Profile</h2></div>
    <form method="post" action="<?= BASE_PATH ?>/admin" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="action" value="update_tenant">
      <input type="hidden" name="tenant_id" value="<?= e($t['id']) ?>">
      <div style="flex:1;min-width:200px"><label>Organization name</label>
        <input name="name" value="<?= e($t['name']) ?>" required style="width:100%"></div>
      <button class="btn sm">Save</button>
    </form>
  </div>

  <div class="panel" style="margin:0 0 1rem">
    <div class="topline"><h2>Plan</h2></div>
    <form method="post" action="<?= BASE_PATH ?>/admin" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="action" value="assign_plan">
      <input type="hidden" name="tenant_id" value="<?= e($t['id']) ?>">
      <div style="flex:1;min-width:200px"><label>Plan</label>
        <select name="plan_id" style="width:100%">
          <option value="">— Default —</option>
          <?php foreach (($plans ?? []) as $pl): ?>
            <option value="<?= e($pl['id']) ?>" <?= ($t['plan_id'] ?? '') === $pl['id'] ? 'selected' : '' ?>><?= e($pl['name']) ?><?= $pl['max_users'] === null ? ' (∞ seats)' : ' (' . (int) $pl['max_users'] . ' seats)' ?></option>
          <?php endforeach; ?>
        </select></div>
      <button class="btn sm">Assign</button>
    </form>
    <form method="post" action="<?= BASE_PATH ?>/admin" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;margin-top:.7rem">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="action" value="set_seat_override">
      <input type="hidden" name="tenant_id" value="<?= e($t['id']) ?>">
      <div style="flex:1;min-width:200px"><label>Custom seat override <span class="hint">(blank = use plan limit)</span></label>
        <input name="seat_override" type="number" min="1" value="<?= e($t['seat_override'] ?? '') ?>" placeholder="e.g. 25" style="width:100%"></div>
      <button class="btn sm line">Set seats</button>
    </form>
    <p class="hint" style="margin:.5rem 0 0">A custom seat count for this specific customer, overriding their plan — a sales lever for bespoke deals. Effective limit now:
      <strong><?= ($lim = FlxCoreHC\PlanService::seatLimit($t['id'])) === null ? 'unlimited' : (int) $lim ?></strong>.</p>
</div>

<div class="panel">
  <div class="topline"><h2>Lifecycle</h2></div>
  <?php if ($t['status'] === 'active'): ?>
    <form method="post" action="<?= BASE_PATH ?>/admin" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center"
          data-confirm="Suspend this organization? Its users will be signed out and unable to sign in." data-confirm-danger>
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="action" value="suspend_tenant">
      <input type="hidden" name="tenant_id" value="<?= e($t['id']) ?>">
      <input name="reason" placeholder="Reason (kept on record)" style="width:260px">
      <button class="btn line" style="color:var(--danger)">Suspend organization</button>
      <span class="hint">Blocks all sign-ins. Data untouched; reversible.</span>
    </form>
  <?php elseif (in_array($t['status'], ['suspended', 'rejected'], true)): ?>
    <?php if (!empty($t['status_reason'])): ?><p class="hint" style="margin-top:0">On record: "<?= e($t['status_reason']) ?>"</p><?php endif; ?>
    <form method="post" action="<?= BASE_PATH ?>/admin" style="margin:0">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="action" value="activate_tenant">
      <input type="hidden" name="tenant_id" value="<?= e($t['id']) ?>">
      <button class="btn">Reactivate organization</button>
    </form>
  <?php endif; ?>
</div>


<div class="panel">
  <div class="topline"><h2>Domains</h2>
    <span class="hint">Branded sign-in for this organization</span></div>
  <p class="hint" style="margin:-.2rem 0 .7rem;max-width:74ch">
    Map a hostname to this organization and anyone arriving on it sees their logo, name, and colours on
    the sign-in page — before logging in. Point the domain at this server first (an A record or CNAME),
    then add it here. Both the apex and <code>www</code> forms resolve to the same entry.
    Domain matching affects branding only; it never grants access to any data.
  </p>

  <?php if (($domains ?? []) === []): ?>
    <div class="empty" style="margin-bottom:.7rem">No domains mapped. Visitors see the standard theme until they sign in once.</div>
  <?php else: ?>
    <div class="table-wrap"><table>
      <tr><th>Hostname</th><th>Added</th><th></th></tr>
      <?php foreach ($domains as $dm): ?>
      <tr>
        <td class="mono"><?= e($dm['host']) ?><?= !empty($dm['is_primary']) ? ' <span class="chip active">primary</span>' : '' ?></td>
        <td class="sub"><?= e(fmt_date($dm['created_at'])) ?></td>
        <td class="right">
          <form method="post" action="<?= BASE_PATH ?>/admin" style="margin:0" data-confirm="Remove <?= e($dm['host']) ?>?">
            <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
            <input type="hidden" name="action" value="remove_domain">
            <input type="hidden" name="tenant_id" value="<?= e($t['id']) ?>">
            <input type="hidden" name="domain_id" value="<?= e($dm['id']) ?>">
            <button class="btn line sm">Remove</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table></div>
  <?php endif; ?>

  <form method="post" action="<?= BASE_PATH ?>/admin" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;margin-top:.7rem">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <input type="hidden" name="action" value="add_domain">
    <input type="hidden" name="tenant_id" value="<?= e($t['id']) ?>">
    <div style="flex:1;min-width:240px"><label>Add a hostname</label>
      <input name="host" placeholder="credentialing.example.com" style="width:100%"></div>
    <label class="chk" style="white-space:nowrap"><input type="checkbox" name="is_primary" value="1"> Primary</label>
    <button class="btn sm">Add domain</button>
  </form>
</div>


  </div>
  <div class="tenant-col">
<div class="panel">
  <div class="topline"><h2>User accounts</h2><span class="hint"><?= count($users) ?></span></div>
  <div class="table-wrap"><table>
    <tr><th>Name</th><th>Email (sign-in)</th><th>Role</th><th>Status</th><th>Platform</th><th style="width:34%"></th></tr>
    <?php foreach ($users as $u): ?>
    <tr>
      <td><strong><?= e($u['full_name']) ?></strong></td>
      <td class="sub"><?= e($u['email']) ?></td>
      <td>
        <form method="post" action="<?= BASE_PATH ?>/admin" data-novalidate style="margin:0">
          <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
          <input type="hidden" name="action" value="set_user_role">
          <input type="hidden" name="tenant_id" value="<?= e($t['id']) ?>">
          <input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
          <select name="role" data-autosubmit style="min-width:150px">
            <?php foreach (FlxCoreHC\TeamService::ROLES as $rk => $rl): if ($rk === 'producer') continue; ?>
              <option value="<?= $rk ?>" <?= $u['role'] === $rk ? 'selected' : '' ?>><?= $rl ?></option>
            <?php endforeach; ?>
            <?php if ($u['role'] === 'producer'): ?><option value="producer" selected>Provider (self-service)</option><?php endif; ?>
          </select>
        </form>
      </td>
      <td><span class="chip <?= $u['status'] === 'active' ? 'active' : 'danger' ?>"><?= e($u['status']) ?></span></td>
      <td><?= (int) $u['platform_admin'] === 1 ? '<span class="chip warn">admin</span>' : '—' ?></td>
      <td>
        <div style="display:flex;gap:.4rem;justify-content:flex-end;flex-wrap:wrap;align-items:center">
          <form method="post" action="<?= BASE_PATH ?>/admin" style="margin:0;display:flex;gap:.3rem">
            <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="tenant_id" value="<?= e($t['id']) ?>">
            <input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
            <input name="new_password" type="text" autocomplete="off" placeholder="New password (10+)" minlength="10" style="width:150px;font-size:.78rem">
            <button class="btn sm line">Set</button>
          </form>
          <?php $F = fn($a, $lbl, $col = '') => '<form method="post" action="' . BASE_PATH . '/admin" style="display:inline;margin:0">' . FlxCoreHC\Auth\CsrfGuard::field() . '<input type="hidden" name="action" value="' . $a . '"><input type="hidden" name="tenant_id" value="' . e($t['id']) . '"><input type="hidden" name="user_id" value="' . e($u['id']) . '"><button class="btn sm line"' . ($col ? ' style="color:' . $col . '"' : '') . '>' . $lbl . '</button></form>'; ?>
          <?php if ($u['status'] === 'active'): ?><?= $F('disable_user', 'Disable', 'var(--danger,#be4b42)') ?><?php else: ?><?= $F('enable_user', 'Enable', 'var(--ok,#2f7d5b)') ?><?php endif; ?>
          <?php if ((int) $u['platform_admin'] === 1): ?><?= $F('revoke_admin', 'Revoke platform') ?><?php else: ?><?= $F('grant_admin', 'Grant platform') ?><?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <p class="hint">"Set" applies the typed password immediately — share it with the user securely. Platform grants give full console access; a revoked grant loses power on the user's next request.</p>
</div>


<div class="panel">
  <div class="topline"><h2>Add a user to <?= e($t['name']) ?></h2></div>
  <form class="form-grid" method="post" action="<?= BASE_PATH ?>/admin">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <input type="hidden" name="action" value="create_user">
    <input type="hidden" name="tenant_id" value="<?= e($t['id']) ?>">
    <div><label>Full name</label><input name="full_name" required></div>
    <div><label>Email (sign-in)</label><input name="email" type="email" required></div>
    <div><label>Role</label><select name="role">
      <?php foreach (FlxCoreHC\TeamService::ROLES as $rk => $rl): if ($rk === 'producer') continue; ?>
        <option value="<?= $rk ?>" <?= $rk === 'staff' ? 'selected' : '' ?>><?= $rl ?></option>
      <?php endforeach; ?>
    </select></div>
    <div><label>Temporary password <span class="hint" style="font-weight:400">(optional)</span></label>
      <input name="password" type="text" autocomplete="off" placeholder="10+ chars to set now" minlength="10"></div>
    <div class="full"><button class="btn">Add user</button>
      <span class="hint" style="margin-left:.6rem">Set a password for immediate access, or leave blank to generate an invite link.</span></div>
  </form>
</div>
  </div>
</div>


