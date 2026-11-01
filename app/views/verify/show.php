<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** Standalone public page — no session, no nav. Shows one producer's credential status. */
$name = $holder['holder_type'] === 'individual'
    ? trim(($holder['first_name'] ?? '') . ' ' . ($holder['last_name'] ?? ''))
    : (string) ($holder['org_name'] ?? 'Provider');
$score = $producer['score'] ?? null;
$rows  = $producer['rows'] ?? [];
$full  = ($scope ?? 'summary') === 'full';

// group requirement rows by status for a clean at-a-glance verdict
$active = $expiring = $gaps = [];
foreach ($rows as $r) {
    $st = $r['status'] ?? 'missing';
    if ($st === 'active') $active[] = $r;
    elseif ($st === 'expiring') $expiring[] = $r;
    else $gaps[] = $r;
}
$total = count($rows);
$ready = $gaps === [] && $expiring === [] && $total > 0;
$scoreColor = $score === null ? '#6b7488' : ($score >= 90 ? '#1c6b4b' : ($score >= 70 ? '#b6862c' : '#c0492e'));
$expDate = fmt_day(fmt_dt((string)($link['expires_at'] ?? ''), 'm/d/Y'));
$statusLabel = ['active' => 'Active', 'expiring' => 'Expiring soon', 'expired' => 'Expired', 'missing' => 'Not on file'];
?><!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Credential verification — <?= e($name) ?></title>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/fonts/fonts.css">
<style>
  /* Fonts are served from this install (assets/fonts); no third-party request. */
  :root{--ink:#141b2e;--ink2:#3a4258;--mute:#6b7488;--line:#e2e5ec;--paper:#f6f5f1;--panel:#fff;
        --ok:#1c6b4b;--ok-soft:#e7f1ec;--warn:#b6862c;--warn-soft:#f6efdd;--bad:#c0492e;--bad-soft:#f7e9e5}
  *{margin:0;padding:0;box-sizing:border-box}
  body{background:#e9e7e0;color:var(--ink);font-family:'Space Grotesk',system-ui,sans-serif;
       -webkit-font-smoothing:antialiased;line-height:1.5;padding:28px 16px}
  .doc{max-width:680px;margin:0 auto;background:var(--panel);border-radius:16px;overflow:hidden;
       box-shadow:0 12px 40px rgba(20,27,46,.14)}
  .head{background:var(--ink);color:#fff;padding:26px 30px 24px;position:relative;overflow:hidden}
  .head::after{content:"";position:absolute;right:-50px;top:-50px;width:170px;height:170px;
       border:1px solid rgba(255,255,255,.07);border-radius:50%}
  .brand{display:flex;align-items:center;gap:10px;margin-bottom:20px}
  .logo{width:30px;height:30px;border-radius:7px;background:#fff;position:relative;flex:0 0 auto}
  .logo::before{content:"";position:absolute;inset:7px;border:2px solid var(--ink);border-radius:2px}
  .brand-t{font-family:'Fraunces',serif;font-weight:600;font-size:18px}
  .brand-s{font-family:'IBM Plex Mono',monospace;font-size:8.5px;letter-spacing:.14em;
       text-transform:uppercase;color:#9aa6c2}
  .verdict{display:flex;align-items:center;gap:16px;position:relative;z-index:1}
  .badge{width:64px;height:64px;border-radius:14px;display:grid;place-items:center;flex:0 0 auto;
       font-family:'Fraunces',serif;font-weight:900;font-size:22px}
  .badge svg{width:34px;height:34px;stroke-width:2.4;fill:none}
  .verdict h1{font-family:'Fraunces',serif;font-weight:900;font-size:26px;letter-spacing:-.02em;line-height:1.05}
  .verdict .sub{color:#c3cbdd;font-size:13px;margin-top:4px}
  .meta{display:flex;gap:26px;padding:16px 30px;background:var(--paper);border-bottom:1px solid var(--line);
       font-size:12px;flex-wrap:wrap}
  .meta .k{font-family:'IBM Plex Mono',monospace;font-size:8.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--mute)}
  .meta .v{font-weight:600;margin-top:2px}
  .body{padding:24px 30px 28px}
  .tag{font-family:'IBM Plex Mono',monospace;font-size:10px;letter-spacing:.16em;text-transform:uppercase;
       color:var(--mute);margin:0 0 12px;display:flex;align-items:center;gap:10px}
  .tag::after{content:"";flex:1;height:1px;background:var(--line)}
  .req{display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid var(--line)}
  .req:last-child{border-bottom:none}
  .dot{width:9px;height:9px;border-radius:50%;flex:0 0 auto}
  .req .nm{flex:1;font-size:13.5px;font-weight:500}
  .req .carrier{font-size:11px;color:var(--mute);font-weight:400}
  .req .st{font-family:'IBM Plex Mono',monospace;font-size:10px;letter-spacing:.04em;padding:3px 9px;border-radius:100px}
  .req .ev{font-family:'IBM Plex Mono',monospace;font-size:10px;color:var(--mute);margin-left:8px}
  .st-active{background:var(--ok-soft);color:var(--ok)} .dot-active{background:var(--ok)}
  .st-expiring{background:var(--warn-soft);color:var(--warn)} .dot-expiring{background:var(--warn)}
  .st-gap{background:var(--bad-soft);color:var(--bad)} .dot-gap{background:var(--bad)}
  .foot{padding:18px 30px 24px;border-top:1px solid var(--line);background:var(--paper)}
  .foot p{font-family:'IBM Plex Mono',monospace;font-size:9px;color:var(--mute);line-height:1.7;letter-spacing:.02em}
  .foot b{color:var(--ink)}
  .powered{text-align:center;margin-top:18px;font-family:'IBM Plex Mono',monospace;font-size:9px;
       letter-spacing:.1em;text-transform:uppercase;color:#a7a49a}
</style>
</head>
<body>
<div class="doc">
  <div class="head">
    <div class="brand">
      <div class="logo"></div>
      <div><div class="brand-t"><?= e($tenant['brand_name'] ?: $tenant['name']) ?></div>
        <div class="brand-s">Credential verification</div></div>
    </div>
    <div class="verdict">
      <?php if ($ready): ?>
        <div class="badge" style="background:var(--ok-soft);color:var(--ok)">
          <svg viewBox="0 0 24 24" stroke="currentColor"><path d="M20 6L9 17l-5-5"/></svg></div>
        <div><h1>Verified &amp; current</h1>
          <div class="sub"><?= count($active) ?> of <?= $total ?> requirements active — no gaps on file.</div></div>
      <?php elseif ($gaps === []): ?>
        <div class="badge" style="background:var(--warn-soft);color:var(--warn)">
          <svg viewBox="0 0 24 24" stroke="currentColor"><path d="M12 8v5M12 16v.5"/><circle cx="12" cy="12" r="9"/></svg></div>
        <div><h1>Current, action pending</h1>
          <div class="sub"><?= count($expiring) ?> requirement<?= count($expiring) === 1 ? '' : 's' ?> expiring soon.</div></div>
      <?php else: ?>
        <div class="badge" style="background:var(--bad-soft);color:var(--bad)">
          <svg viewBox="0 0 24 24" stroke="currentColor"><path d="M12 8v5M12 16v.5"/><circle cx="12" cy="12" r="9"/></svg></div>
        <div><h1>Gaps on file</h1>
          <div class="sub"><?= count($gaps) ?> requirement<?= count($gaps) === 1 ? '' : 's' ?> missing or expired.</div></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="meta">
    <div><div class="k">Provider</div><div class="v"><?= e($name) ?></div></div>
    <?php if (!empty($holder['npi'])): ?><div><div class="k">NPI</div><div class="v"><?= e($holder['npi']) ?><?= !empty($holder['npi_verified_at']) ? ' ✓' : '' ?></div></div><?php endif; ?>
    <?php $__spec = (string) ($holder['primary_specialty'] ?? '') ?: (string) ($holder['npi_taxonomy'] ?? ''); if ($__spec !== ''): ?><div><div class="k">Specialty</div><div class="v"><?= e($__spec) ?></div></div><?php endif; ?>
    <?php if ($score !== null): ?><div><div class="k">Compliance score</div><div class="v" style="color:<?= $scoreColor ?>"><?= (int)$score ?> / 100</div></div><?php endif; ?>
    <div><div class="k">Requirements</div><div class="v"><?= $total ?></div></div>
  </div>

  <div class="body">
    <?php if ($total === 0): ?>
      <p style="color:var(--mute);font-size:13px">No requirements are assigned to this provider yet.</p>
    <?php else: ?>
      <?php
      $render = function(array $list, string $cls, string $dot) use ($statusLabel, $full) {
          foreach ($list as $r):
            $st = $r['status'] ?? 'missing'; ?>
            <div class="req">
              <span class="dot dot-<?= $dot ?>"></span>
              <span class="nm"><?= e($r['name'] ?? '—') ?>
                <?php if (!empty($r['carrier'])): ?><span class="carrier">· <?= e($r['carrier']) ?></span><?php endif; ?></span>
              <?php if ($full && !empty($r['evidence'])): ?><span class="ev"><?= (int)$r['evidence'] ?> doc<?= (int)$r['evidence'] === 1 ? '' : 's' ?></span><?php endif; ?>
              <span class="st st-<?= $cls ?>"><?= e($statusLabel[$st] ?? $st) ?></span>
            </div>
      <?php endforeach; };
      ?>
      <?php if ($gaps !== []): ?><div class="tag">Needs attention</div><?php $render($gaps, 'gap', 'gap'); endif; ?>
      <?php if ($expiring !== []): ?><div class="tag">Expiring soon</div><?php $render($expiring, 'expiring', 'expiring'); endif; ?>
      <?php if ($active !== []): ?><div class="tag">Active</div><?php $render($active, 'active', 'active'); endif; ?>
    <?php endif; ?>
  </div>

  <div class="foot">
    <p>Shared by <b><?= e($tenant['name']) ?></b><?= !empty($link['label']) ? ' · ' . e($link['label']) : '' ?>.
       This link expires <b><?= e($expDate) ?></b> and reflects records as of <?= e(fmt_day(today_local())) ?>.
       Status is drawn from the organization's records in FlxCoreHC and is not itself a credentialing decision, primary-source verification, or legal attestation.
       Verified content set <?= e(FlxCoreHC\CatalogService::RULESET_VERSION) ?>.</p>
  </div>
</div>
<div class="powered">Powered by FlxCoreHC</div>
</body>
</html>
