<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** Producer portal: their own compliance card, expirations, tasks, CE, training.
 *  Expects: $ctx, $row, $mine, $myTasks, $creds, $ce, $ceHours, $ceLaw, $cycle. */
$title = 'My compliance';
$name = $row['holder_type'] === 'individual'
    ? trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))
    : (string)($row['org_name'] ?? 'Provider');
$sc = $mine['score'] ?? null;
$stChip = function (string $st): string {
    $map = ['active' => 'active', 'expiring' => 'expiring', 'expired' => 'expired', 'missing' => 'expired'];
    return '<span class="chip ' . ($map[$st] ?? '') . '">' . e($st) . '</span>';
};
?>
<p class="crumb">MY WORKSPACE</p>
<div class="topline"><h1><?= e(t('Welcome, {name}', ['name' => $name])) ?></h1>
  <span style="display:flex;gap:.4rem">
    <a class="btn sm line" href="<?= BASE_PATH ?>/me/profile">My profile</a>
    <?php $__openReq = (int)($openRequests ?? 0); ?>
    <a class="btn sm <?= $__openReq ? 'gold' : 'line' ?>" href="<?= BASE_PATH ?>/me/requests"><?= e(t('Document requests')) ?><?= $__openReq ? ' (' . $__openReq . ')' : '' ?></a>
    <?php $__pendLinks = 0; try { if (!empty($row['npi'])) $__pendLinks = count(FlxCoreHC\CrossTenantService::pendingForProvider(FlxCoreHC\Crypto::blindIndex(preg_replace('/\D/','',(string)$row['npi'])))); } catch (\Throwable $e) {} ?>
    <a class="btn sm <?= $__pendLinks ? 'gold' : 'line' ?>" href="<?= BASE_PATH ?>/me/links"><?= e(t('Linked organizations')) ?><?= $__pendLinks ? ' (' . $__pendLinks . ')' : '' ?></a>
    <a class="btn sm line" href="<?= BASE_PATH ?>/me/cv" title="Download your credential CV / summary as a PDF">My CV (PDF)</a>
  </span></div>
<p class="hint" style="margin-top:-.4rem"><?= e(t('Your credentials, renewal deadlines, and CE hours — kept current by {org}.', ['org' => $ctx->tenantName])) ?></p>

<div class="kpis" style="margin:.9rem 0 1.1rem">
  <div class="kpi <?= $sc===null||$sc>=90?'ok':($sc>=60?'warn':'danger') ?>"><span class="n"><?= $sc===null?'—':$sc ?></span><span class="l">My compliance score / 100</span></div>
  <div class="kpi"><span class="n"><?= (int)($mine['active'] ?? 0) ?>/<?= (int)($mine['total'] ?? 0) ?></span><span class="l">Requirements active</span></div>
  <div class="kpi <?= count($myTasks) ? 'warn' : 'ok' ?>"><span class="n"><?= count($myTasks) ?></span><span class="l">Open tasks</span></div>
</div>

<?php $manage = false; require FLXCOREHC_APP . '/views/holders/_onboarding.php'; ?>

<?php
  // Split requirements into what NEEDS ACTION vs what is COMPLETE, so it's obvious what to do.
  $__need = []; $__done = [];
  foreach (($mine['rows'] ?? []) as $__r) {
      if (($__r['status'] ?? 'missing') === 'active') $__done[] = $__r; else $__need[] = $__r;
  }
  $__act = function(array $r): string {
      $what = $r['regulator'] ? $r['regulator'] . ' — ' . $r['name'] : $r['name'];
      return match ($r['status'] ?? 'missing') {
          'expired'  => 'Renew it and add the new certificate — your previous one has lapsed.',
          'expiring' => 'It expires ' . ($r['expiry'] ? fmt_day($r['expiry']) : 'soon') . ' — renew it and upload the new document.',
          default    => 'Not on file yet — add this credential and attach your certificate or license.',
      };
  };
?>
<div class="panel" style="<?= $__need ? 'border-left:4px solid var(--warn)' : '' ?>">
  <div class="topline"><h2><?= $__need ? '❗ Needs your action (' . count($__need) . ')' : '✓ ' . t('All your tracked requirements are current') ?></h2></div>
  <?php if (($mine['total'] ?? 0) === 0): ?>
    <p class="hint" style="margin:0">Nothing assigned yet — your administrator will set up your required credentials.</p>
  <?php elseif (!$__need): ?>
    <p class="hint" style="margin:0">Every requirement below is satisfied by a current credential. Keep an eye on expiry dates — renewal tasks will appear here automatically.</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>What's missing</th><th>Status</th><th>What to do</th><th></th></tr>
    <?php foreach ($__need as $r): ?>
    <?php
      // Pre-fill the add-credential form with the requirement's type + name (one-click resolution).
      $baseCode = explode('@', (string) $r['token'])[0];
      $addType  = str_starts_with($baseCode, 'cr:') ? '' : $baseCode;
      $addUrl   = BASE_PATH . '/credentials/new?holder=' . rawurlencode((string)($ctx->holderId ?? ''))
                . ($addType !== '' ? '&type=' . rawurlencode($addType) : '')
                . '&name=' . rawurlencode((string) $r['name']);
    ?>
    <tr>
      <td><strong><?= e($r['name']) ?></strong><?php if ($r['regulator']): ?><span class="sub"><?= e($r['regulator']) ?></span><?php endif; ?></td>
      <td><?= $stChip($r['status']) ?: '<span class="chip expired">missing</span>' ?></td>
      <td class="sub" style="max-width:38ch"><?= e($__act($r)) ?></td>
      <td class="right"><a class="btn sm" href="<?= $addUrl ?>"><?= ($r['status'] ?? '') === 'active' ? 'Update' : 'Add' ?></a></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>

<?php if ($__done): ?>
<div class="panel">
  <div class="topline"><h2>✓ Complete (<?= count($__done) ?>)</h2></div>
  <div class="table-wrap"><table>
    <tr><th>Requirement</th><th>Regulator</th><th>Satisfied by</th></tr>
    <?php foreach ($__done as $r): ?>
    <tr>
      <td><strong><?= e($r['name']) ?></strong></td>
      <td><?= $r['regulator'] ? e($r['regulator']) : '<span class="hint">General</span>' ?></td>
      <td><?= $r['credential_name'] ? e($r['credential_name']) . ($r['expiry'] ? ' <span class="hint">· expires ' . e(fmt_day($r['expiry'])) . '</span>' : '') : '<span class="hint">—</span>' ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php endif; ?>

<?php if ($myTasks !== []): ?>
<div class="panel">
  <div class="topline"><h2>What needs my attention</h2></div>
  <div class="table-wrap"><table>
    <tr><th>Task</th><th>Due</th><th>Priority</th></tr>
    <?php foreach ($myTasks as $t): ?>
    <tr><td><strong><?= e($t['title']) ?></strong></td>
      <td class="mono"><?= $t['due_date'] ? e(fmt_day($t['due_date'])) : '—' ?></td>
      <td><span class="chip <?= $t['priority'] === 'high' ? 'expired' : '' ?>"><?= e($t['priority']) ?></span></td></tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php endif; ?>

<?php require FLXCOREHC_APP . '/views/holders/_ce.php'; ?>

<div class="panel">
  <div class="topline"><h2>Training Center</h2></div>
  <p class="hint" style="margin:0 0 .6rem">Credentialing guides and licensing references — with links to the official sources.</p>
  <a class="btn sm line" href="<?= BASE_PATH ?>/training">Open Training Center →</a>
</div>

<?php $latestAttestation = $latestAttestation ?? null; ?>
<div class="panel">
  <div class="topline"><h2>Attestation</h2></div>
  <?php if ($latestAttestation): ?>
    <p class="hint" style="margin:0 0 .6rem">You last attested on
      <strong><?= e(substr((string)$latestAttestation['attested_on'],0,10)) ?></strong>.
      Re-attest whenever your information changes or a payer requests it.</p>
  <?php else: ?>
    <p class="hint" style="margin:0 0 .6rem">Confirm that your credentialing information and documents are accurate and current. Payers and auditors expect this on file.</p>
  <?php endif; ?>
  <p style="font-size:.86rem;margin:.2rem 0 .7rem"><em><?= e(FlxCoreHC\Repository\AttestationRepository::DEFAULT_STATEMENT) ?></em></p>
  <form method="post" action="<?= BASE_PATH ?>/me/attest" data-confirm="Submit this attestation? It will be recorded with today's date.">
    <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <button class="btn sm">I attest — record this</button>
  </form>
</div>
