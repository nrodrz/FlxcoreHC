<?php
/** @var array $share */ /** @var array $profile */ /** @var string $token */
$h = $profile['holder']; $t = $profile['tenant']; $creds = $profile['credentials']; $docs = $profile['docs'];
$name = ($h['holder_type'] ?? '') === 'organization' ? (string)($h['org_name'] ?? '') : trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? ''));
$title = 'Profile — ' . $name;
$fmt = fn(?string $d) => $d ? fmt_day($d) : '—';
$chip = ['active'=>'active','expiring'=>'warn','expired'=>'danger','suspended'=>'danger'];
$expiresIn = max(0, (int)floor((strtotime($share['expires_at'] . ' UTC') - time()) / 86400));
?>
<?php require __DIR__ . '/_lang.php'; ?>
<div style="max-width:860px;margin:1.5rem auto;padding:0 1rem">
  <div class="panel">
    <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start">
      <div>
        <div class="hint" style="text-transform:uppercase;letter-spacing:.06em;font-size:.68rem"><?= e(t('Provider profile')) ?> · <?= e(t('shared by')) ?> <?= e($t['brand_name'] ?: $t['name']) ?></div>
        <h1 style="margin:.15rem 0 .1rem;font-size:1.4rem"><?= e($name) ?></h1>
        <div class="hint"><?= e(t('NPI')) ?>: <span class="mono"><?= e($h['npi'] ?? '—') ?></span></div>
      </div>
      <div class="hint" style="text-align:right;font-size:.75rem">
        <?= e(t('Prepared for')) ?> <strong><?= e($share['recipient_name']) ?></strong><br>
        <?= e(t('Link expires in')) ?> <?= (int)$expiresIn ?> <?= e(t('day(s)')) ?> · <?= e(t('read-only')) ?>
      </div>
    </div>
  </div>

  <div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1rem"><?= e(t('Credentials')) ?> (<?= count($creds) ?>)</h2>
    <?php if (!$creds): ?><p class="hint"><?= e(t('No credentials were included in this share.')) ?></p>
    <?php else: foreach ($creds as $c): ?>
      <div style="border:1px solid var(--line,#e3e1da);border-radius:8px;padding:.6rem .8rem;margin-bottom:.6rem">
        <div style="display:flex;justify-content:space-between;gap:.6rem;flex-wrap:wrap;align-items:center">
          <strong><?= e($c['name']) ?></strong>
          <span class="chip <?= $chip[$c['status']] ?? '' ?>"><?= e(ucfirst((string)$c['status'])) ?></span>
        </div>
        <table style="font-size:.84rem;margin-top:.3rem" data-no-stack>
          <tr><th style="width:8rem"><?= e(t('Issuer')) ?></th><td><?= e($c['issuing_body'] ?? '—') ?></td>
              <th style="width:8rem"><?= e(t('Issued')) ?></th><td><?= e($fmt($c['issued_date'] ?? null)) ?></td></tr>
          <tr><th><?= e(t('Expires')) ?></th><td><?= e($fmt($c['expiry_date'] ?? null)) ?></td>
              <th><?= e(t('Last verified')) ?></th><td><?= e($fmt($c['last_verified_at'] ?? null)) ?></td></tr>
        </table>
        <?php $dl = $docs[$c['id']] ?? []; if ($dl): ?>
          <div style="margin-top:.4rem;font-size:.82rem">
            <div class="hint" style="margin-bottom:.15rem"><?= e(t('Documents')) ?></div>
            <?php foreach ($dl as $d): ?>
              <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin:.15rem 0">
                <a class="btn sm line" href="<?= BASE_PATH ?>/s/<?= e($token) ?>/doc/<?= e($d['id']) ?>" target="_blank" rel="noopener">📄 <?= e($d['original_name']) ?></a>
                <?php if (!empty($d['verified_at'])): ?><span class="chip active"><?= e(t('Verified')) ?> · <?= e(str_replace('_',' ',(string)$d['verification_method'])) ?></span><?php endif; ?>
                <span class="hint"><?= e(ucfirst((string)($d['kind'] ?? 'primary'))) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <?php if ($profile['screenings']): ?>
  <div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1rem"><?= e(t('Exclusion screening')) ?></h2>
    <table style="font-size:.84rem">
      <tr><th><?= e(t('Source')) ?></th><th><?= e(t('Screened')) ?></th><th><?= e(t('Result')) ?></th></tr>
      <?php foreach ($profile['screenings'] as $s): $bad = in_array(strtolower((string)$s['result']), ['match','excluded','hit','flag','flagged','possible_match'], true); ?>
      <tr><td><?= e(strtoupper((string)$s['source'])) ?></td><td><?= e($fmt($s['screened_on'])) ?></td>
          <td><strong style="<?= $bad ? 'color:#be4b42' : '' ?>"><?= e($bad ? t('Flagged — review') : t('No match')) ?></strong></td></tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

  <p class="hint" style="font-size:.72rem;margin-top:1rem"><?= e(t('Provided by')) ?> <?= e($t['brand_name'] ?: $t['name']) ?> <?= e(t('via FlxCoreHC. Confidential — for the named recipient only. Every view and download is logged.')) ?></p>
</div>
