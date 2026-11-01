<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var string $token */ $title='Subscribe / sync calendar';
$appUrl = rtrim((string) config('app_url', ''), '/');
if ($appUrl === '') { $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http'; $appUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain'); }
// The feed URL carries a secret token: never hand out a plain-http link on a production install.
if (config('app_env', 'production') !== 'dev' && str_starts_with($appUrl, 'http://')) $appUrl = 'https://' . substr($appUrl, 7);
$feedUrl = $token !== '' ? $appUrl . BASE_PATH . '/ical/' . $token . '.ics' : ''; ?>
<div class="crumb"><a href="<?= BASE_PATH ?>/calendar">Calendar</a></div>
<div class="topline"><h1>Subscribe / sync your calendar</h1></div>
<p class="hint" style="margin-top:-.4rem;max-width:80ch">Subscribe to a read-only feed of your FlxCoreHC calendar — your events and auto-generated compliance deadlines — from Google Calendar, Microsoft 365, Apple Calendar, or any app that supports calendar URLs. The feed refreshes automatically (external apps typically poll every few hours). This is a private link: anyone with the URL can see your calendar, so keep it secret.</p>

<div class="panel">
  <?php if ($feedUrl === ''): ?>
    <p class="hint">Generate your private feed link to get started.</p>
    <form method="post" action="<?= BASE_PATH ?>/calendar/subscribe"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><button class="btn">Generate feed link</button></form>
  <?php else: ?>
    <label>Your private feed URL</label>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
      <input type="text" readonly value="<?= e($feedUrl) ?>" data-select-on-click style="flex:1;min-width:280px;font-family:ui-monospace,monospace;font-size:.8rem">
      <button class="btn sm" type="button" data-copy="<?= e($feedUrl) ?>">Copy</button>
    </div>
    <div style="margin-top:1rem">
      <h3 style="font-size:.9rem">How to subscribe</h3>
      <ul class="hint" style="line-height:1.7">
        <li><strong>Google Calendar</strong>: Other calendars → + → From URL → paste the link.</li>
        <li><strong>Microsoft 365 / Outlook</strong>: Add calendar → Subscribe from web → paste the link.</li>
        <li><strong>Apple Calendar</strong>: File → New Calendar Subscription → paste the link.</li>
      </ul>
    </div>
    <form method="post" action="<?= BASE_PATH ?>/calendar/subscribe" style="margin-top:.6rem" data-confirm="Rotate the link? Existing subscriptions will stop updating until re-added." data-confirm-danger>
      <?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="revoke">
      <button class="btn sm line">Rotate / revoke link</button>
    </form>
  <?php endif; ?>
</div>
<p class="hint" style="font-size:.74rem">One-way sync: events flow from FlxCoreHC out to your calendar app. Changes made in the external app don't flow back.</p>
