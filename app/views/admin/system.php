<?php /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $status */ /** @var array $diag */
$title='System & updates';
$adminTab = 'system';
require FLXCOREHC_APP . '/views/admin/_tabs.php';
$pending = $status['pending']; $applied = $status['applied'];
$yn = function($v){ if ($v===null) return '<span class="chip pending">unknown</span>'; return $v ? '<span class="chip active">yes</span>' : '<span class="chip expired">no</span>'; };
?>

<div class="topline"><h1>System &amp; updates</h1></div>

<div class="panel" style="border-left:3px solid var(--accent)">
  <div class="topline"><h2><?= e(t('Your organization\'s own compliance')) ?></h2></div>
  <p class="hint" style="max-width:80ch"><?= e(t('Track your own company\'s credentials and compliance the same way your tenants track theirs — business license, liability insurance, W-9, organizational NPI, HIPAA/OSHA training, staff certifications. Add your organization as an Organization-type record, then apply a template or add requirements. Everything uses the same catalog (now including non-clinical types) and the same case workflow.')) ?></p>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a class="btn sm gold" href="<?= BASE_PATH ?>/holders/new"><?= e(t('Add your organization')) ?></a>
    <a class="btn sm line" href="<?= BASE_PATH ?>/holders"><?= e(t('Your records')) ?></a>
    <a class="btn sm line" href="<?= BASE_PATH ?>/cases"><?= e(t('Credentialing cases')) ?></a>
    <a class="btn sm line" href="<?= BASE_PATH ?>/organization/documents-tracked"><?= e(t('Manage credential types')) ?></a>
  </div>
</div>

<div class="panel" style="border-left:3px solid var(--accent)">
  <div class="topline"><h2>Regulatory content</h2>
    <span class="chip active">Ruleset <?= e(FlxCoreHC\CatalogService::RULESET_VERSION) ?></span>
    <span class="chip" title="Application version — quote this when reporting an issue">FlxCoreHC v<?= e(defined('FLXCOREHC_VERSION') ? FLXCOREHC_VERSION : '—') ?></span></div>
  <p style="margin:.2rem 0 .4rem"><strong><?= e(FlxCoreHC\CatalogService::RULESET_LABEL) ?></strong></p>
  <p class="hint" style="margin:0"><?= e(FlxCoreHC\CatalogService::RULESET_NOTES) ?>
    <br>Catalog last updated <?= e(fmt_day(FlxCoreHC\CatalogService::RULESET_UPDATED)) ?>. When new regulator rules are released, a new migration bumps this version — run <strong>Update database</strong> below to apply it.</p>
</div>

<div class="cols-2">
  <div class="panel">
    <div class="topline"><h2>Database updates</h2>
      <span class="chip <?= $pending ? 'expiring' : 'active' ?>"><?= $pending ? count($pending).' pending' : 'up to date' ?></span></div>
    <p class="hint" style="margin-top:0">Applies any new schema changes shipped with a release. It's safe to run repeatedly — already-applied changes are detected and skipped.</p>
    <?php if ($pending): ?>
      <div class="table-wrap"><table>
        <tr><th>Pending migration</th></tr>
        <?php foreach($pending as $f): ?><tr><td class="mono"><?= e($f) ?></td></tr><?php endforeach; ?>
      </table></div>
    <?php else: ?>
      <div class="empty" style="padding:1.2rem">Everything is applied — no pending database changes.</div>
    <?php endif; ?>
    <form method="post" action="<?= BASE_PATH ?>/admin" data-novalidate style="margin-top:1rem;display:flex;gap:.5rem;flex-wrap:wrap">
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="action" value="run_migrations">
      <button class="btn" <?= $pending ? '' : 'disabled' ?>>Update database</button>
      <a class="btn line" href="<?= BASE_PATH ?>/admin/system">Refresh</a>
    </form>
    <details style="margin-top:1rem">
      <summary class="hint" style="cursor:pointer">Applied migrations (<?= count($applied) ?>)</summary>
      <div class="table-wrap" style="margin-top:.5rem"><table>
        <?php foreach($applied as $f): ?><tr><td class="mono" style="color:var(--ink-2)"><?= e($f) ?></td></tr><?php endforeach; ?>
        <?php if(!$applied): ?><tr><td class="hint">None recorded yet. If this database predates update tracking, use “Baseline”.</td></tr><?php endif; ?>
      </table></div>
      <form method="post" action="<?= BASE_PATH ?>/admin" data-novalidate style="margin-top:.6rem" data-confirm="Mark all current migrations as applied without running them? Use only if the schema is already up to date.">
        <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="baseline_migrations">
        <button class="btn sm line">Baseline (mark all as applied)</button>
      </form>
    </details>
  </div>

  <?php if (FLXCOREHC_EDITION !== 'insurance'): ?>
  <div class="panel">
    <div class="topline"><h2>Demonstration data<?= FLXCOREHC_EDITION === 'both' ? ' — healthcare' : '' ?></h2></div>
    <p class="hint" style="margin-top:-.3rem;max-width:74ch">
      Creates five populated example organizations (billing company, solo office, group practice, IPA, anesthesia group) — <strong>Coastal Credentialing Partners</strong>,
      operating in Florida and Puerto Rico — with five providers of different types (a physician, a
      Puerto Rico physician, a nurse practitioner, a clinical laboratory, and a medical group), each
      with a realistic credential set across active, expiring, and expired states, plus two working
      provider portal logins and a roster verification link. Its own tenant, so removing it is clean.
    </p>
    <?php if (!empty($demoHcTenantId)): ?>
      <div style="display:flex;gap:.7rem;align-items:center;flex-wrap:wrap">
        <span class="chip active">Demo billing company is live</span>
        <span class="hint">Owner <span class="mono">demo-admin@coastalcred.example</span> / <span class="mono">coastal-demo-2026</span> · all other demo logins use <span class="mono"><?= e(FlxCoreHC\DemoHealthcareService::DEMO_PASSWORD) ?></span></span>
      </div>
      <div style="display:flex;gap:.6rem;margin-top:.9rem">
        <form method="post" action="<?= BASE_PATH ?>/admin" style="margin:0" data-confirm="Rebuild the healthcare demo from scratch? The current demo data will be replaced.">
          <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="build_demo_hc">
          <button class="btn line">Rebuild demo</button>
        </form>
        <a class="btn sm line" href="<?= BASE_PATH ?>/admin/demo-accounts">Demo accounts &amp; passwords</a>
        <a class="btn sm line" href="<?= BASE_PATH ?>/admin/test-plan">Test plan</a>
        <a class="btn sm line" href="<?= BASE_PATH ?>/admin/capabilities">Capabilities</a>
        <a class="btn sm line" href="<?= BASE_PATH ?>/admin/changelog">Changelog</a>
        <form method="post" action="<?= BASE_PATH ?>/admin" style="margin:0" data-confirm="Remove the demo billing company and all its data? This cannot be undone." data-confirm-danger>
          <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="remove_demo_hc">
          <button class="btn line" style="color:var(--danger)">Remove demo</button>
        </form>
      </div>
    <?php else: ?>
      <div style="display:flex;gap:.6rem;align-items:center;margin-top:.4rem;flex-wrap:wrap">
        <form method="post" action="<?= BASE_PATH ?>/admin" style="margin:0">
          <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="build_demo_hc">
          <button class="btn">Create the five demo organizations</button>
        </form>
        <a class="btn sm line" href="<?= BASE_PATH ?>/admin/demo-accounts">Demo accounts &amp; passwords</a>
        <a class="btn sm line" href="<?= BASE_PATH ?>/admin/test-plan">Test plan</a>
        <a class="btn sm line" href="<?= BASE_PATH ?>/admin/capabilities">Capabilities</a>
        <a class="btn sm line" href="<?= BASE_PATH ?>/admin/changelog">Changelog</a>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="panel">
    <div class="panel">
      <div class="topline"><h2>Templates &amp; regulators</h2></div>
      <p class="hint">Every healthcare organization is seeded with the 14 provider-type compliance templates, the standard regulators and calendar types when it is created. If an organization is missing them (e.g. created before this was fixed), sync them here. Safe to run repeatedly — it never duplicates or overwrites edited templates.</p>
      <form method="post" action="<?= BASE_PATH ?>/admin" style="margin:0"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
        <input type="hidden" name="action" value="sync_templates">
        <button class="btn sm">Sync templates &amp; regulators</button>
      </form>
    </div>

    <?php $__kbAt = FlxCoreHC\SettingsService::get('keys_backup_confirmed_at'); $__kbHas = (string) config('crypto_data_key', '') !== ''; ?>
    <div class="panel" id="keys">
      <div class="topline"><h2>Encryption keys — backup</h2><?= $__kbAt ? '<span class="chip active">backup confirmed ' . e(substr((string)$__kbAt, 0, 10)) . '</span>' : '<span class="chip" style="background:#fbf0df;color:#7a4a08">not confirmed</span>' ?></div>
      <p class="hint">Provider and patient data is encrypted with two keys stored in <code>app/config.php</code>. If that file is lost, the data cannot be recovered. Download a backup locked with a passphrase you choose (it is <strong>never e-mailed</strong> and the keys are never shown in clear), then store the file and the passphrase in a vault or password manager. To restore: <code>php database/restore_keys.php &lt;file&gt;</code>.</p>
      <?php if ($__kbHas): ?>
      <form method="post" action="<?= BASE_PATH ?>/admin" class="stack" style="max-width:520px"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
        <input type="hidden" name="action" value="key_backup_download">
        <div class="grid2">
          <div><label>Backup passphrase <span class="hint" style="display:inline">(min 12 characters)</span></label><input type="password" name="key_passphrase" autocomplete="new-password" required></div>
          <div><label>Confirm passphrase</label><input type="password" name="key_passphrase2" autocomplete="new-password" required></div>
        </div>
        <div><label>Your current password <span class="hint" style="display:inline">(re-confirms it is you)</span></label><input type="password" name="current_password" autocomplete="current-password" required></div>
        <div><button class="btn sm">Download key backup</button></div>
      </form>
      <?php if (!$__kbAt): ?>
      <form method="post" action="<?= BASE_PATH ?>/admin" style="margin-top:.6rem"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
        <input type="hidden" name="action" value="key_backup_confirm">
        <button class="btn sm line">I have stored the backup and its passphrase</button>
      </form>
      <?php endif; ?>
      <?php else: ?><p class="hint">No encryption keys are configured (development mode) — nothing to back up.</p><?php endif; ?>
    </div>

    <div class="panel">
      <div class="topline"><h2>Verification — SAM.gov API key</h2><?= !empty($samConfigured) ? '<span class="chip active">configured</span>' : '<span class="chip">not set</span>' ?></div>
      <p class="hint">A free key from <a href="https://sam.gov/content/entity-registration" target="_blank" rel="noopener">sam.gov → Account Details</a> lets FlxCoreHC run <strong>real-time exclusion checks</strong> against SAM.gov from a provider's page. NPPES (NPI) verification needs no key; ORCPS, OCS, Medicaid and Medicare have no public API and are verified at their official pages.</p>
      <form method="post" action="<?= BASE_PATH ?>/admin" class="stack" style="max-width:520px"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
        <input type="hidden" name="action" value="set_sam_key">
        <div><label>SAM.gov public API key</label><input name="sam_api_key" placeholder="<?= !empty($samConfigured) ? 'configured — leave blank to keep, or paste a new key' : 'paste your SAM.gov API key' ?>" autocomplete="off"></div>
        <div><button class="btn sm">Save key</button><?php if (!empty($samConfigured)): ?> <span class="hint">stored encrypted</span><?php endif; ?></div>
      </form>
    </div>

    <div class="topline"><h2>Backups</h2>
      <?php if (isset($lastBackupDays)): ?>
        <?php if ($lastBackupDays === null): ?><span class="chip warn">Never backed up</span>
        <?php elseif ($lastBackupDays >= 7): ?><span class="chip warn"><?= (int) $lastBackupDays ?> days ago</span>
        <?php else: ?><span class="chip active"><?= $lastBackupDays === 0 ? 'Today' : ((int) $lastBackupDays . 'd ago') ?></span><?php endif; ?>
      <?php endif; ?>
    </div>
    <p class="hint" style="margin-top:-.3rem;max-width:74ch">
      Download a full copy of your data. Backups are generated on demand in server memory and sent to
      your browser — nothing is stored on the server. Very large databases may exceed the PHP memory limit on
      shared hosting; if a download fails, ask your host to raise <code>memory_limit</code> or run <code>mysqldump</code>.
      Keep backups somewhere safe and off-site.</p>
    <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1rem">
      <a class="btn" href="<?= BASE_PATH ?>/admin/backup/database">Download database</a>
      <a class="btn line" href="<?= BASE_PATH ?>/admin/backup/documents">Download documents</a>
    </div>
    <form method="post" action="<?= BASE_PATH ?>/admin" class="stack" style="max-width:520px;margin-bottom:1rem"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="action" value="db_backup_encrypted">
      <div class="hint" style="max-width:74ch">Or download the database <strong>sealed with a passphrase</strong> (recommended for off-site copies). To open it: <code>php database/decrypt_dump.php &lt;file&gt; &lt;out.sql.gz&gt;</code>.</div>
      <div class="grid2">
        <div><label>Passphrase <span class="hint" style="display:inline">(min 12 characters)</span></label><input type="password" name="dump_passphrase" autocomplete="new-password" required></div>
        <div><label>Confirm passphrase</label><input type="password" name="dump_passphrase2" autocomplete="new-password" required></div>
      </div>
      <div><label>Your current password</label><input type="password" name="current_password" autocomplete="current-password" required></div>
      <div><button class="btn sm line">Download sealed database</button></div>
    </form>
    <div class="panel" style="background:var(--surface-2);margin:0;border-left:3px solid var(--warn)">
      <p class="hint" style="margin:0"><strong>Also back up <span class="mono">config.php</span> separately.</strong>
      Encrypted fields (NPIs, dates of birth, SSN last four, email) are stored as ciphertext. They can only be
      restored with the encryption keys in <span class="mono">config.php</span>, which are <em>not</em>
      in the database backup. Store that file securely and apart from your data backups — anyone with
      both can read the encrypted data.</p>
    </div>
  </div>

  <div>
    <div class="panel">
      <div class="topline"><h2>Environment</h2></div>
      <div class="table-wrap"><table>
        <tr><td>PHP version</td><td class="right mono"><?= e($diag['php']) ?></td></tr>
        <tr><td>Database</td><td class="right mono"><?= e($diag['db']) ?></td></tr>
        <tr><td>Base path</td><td class="right mono"><?= e($diag['base_path']) ?></td></tr>
        <tr><td>Platform time zone <span class="hint" style="font-weight:400">(default for organizations that have not chosen their own)</span></td><td class="right">
          <form method="post" action="<?= BASE_PATH ?>/admin" style="margin:0;display:inline-flex;gap:.4rem;align-items:center"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
            <input type="hidden" name="action" value="set_timezone">
            <select name="app_timezone" style="min-width:190px">
              <?php $__cur = $diag['tz'] ?? 'America/New_York'; foreach (flx_timezones() as $z=>$lbl): ?>
                <option value="<?= $z ?>" <?= $__cur===$z?'selected':'' ?>><?= e($lbl) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn sm">Set</button>
          </form></td></tr>
        <tr><td>URL rewriting (mod_rewrite)</td><td class="right"><?= $yn($diag['rewrite']) ?></td></tr>
        <tr><td>PDO MySQL</td><td class="right"><?= $yn($diag['ext_pdo']) ?></td></tr>
        <tr><td>mbstring</td><td class="right"><?= $yn($diag['ext_mbstr']) ?></td></tr>
        <tr><td>cURL (Stripe/webhooks)</td><td class="right"><?= $yn($diag['ext_curl']) ?></td></tr>
        <tr><td>libsodium (encryption)</td><td class="right"><?= $yn($diag['ext_sodium']) ?></td></tr>
        <tr><td>uploads/ writable (logos)</td><td class="right"><?= $yn($diag['uploads_w']) ?></td></tr>
        <tr><td>Email configured</td><td class="right"><?= $yn($diag['mail']) ?></td></tr>
        <tr><td>Billing (Stripe) configured</td><td class="right"><?= $yn($diag['stripe']) ?></td></tr>
      </table></div>
    </div>
    <div class="panel">
      <div class="topline"><h2>Routing help</h2></div>
      <p class="hint" style="margin-top:0">If clean links like <span class="mono">/holders/new</span> return a server “Not Found” while the dashboard works, your host is likely intercepting extensionless paths. Ensure the shipped <span class="mono">.htaccess</span> is uploaded and that <span class="mono">mod_rewrite</span> + <span class="mono">Options -MultiViews</span> are honored (this release sets that). As a fallback, <span class="mono">/index.php/holders/new</span> also works.</p>
    </div>
  </div>
</div>
