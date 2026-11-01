<?php
/** @var FlxCoreHC\TenantContext $ctx */ /** @var array $rows */ /** @var bool $unreadOnly */
$title = 'Notifications';
$unread = count(array_filter($rows, fn($r) => $r['read_at'] === null));
$sevColor = ['danger' => 'var(--danger,#be4b42)', 'warn' => 'var(--warn,#c47f1a)', 'info' => 'var(--accent,#2563eb)'];
$kindLabel = ['expiring' => 'Expiring', 'expired' => 'Expired', 'reverify' => 'Re-verify', 'overdue' => 'Overdue',
              'assigned' => 'Assigned', 'import' => 'Import', 'screening' => 'Screening', 'info' => 'Info'];
$ago = function (string $ts): string {
    $d = time() - strtotime($ts . ' UTC');
    if ($d < 60) return t('just now'); if ($d < 3600) return t('{n} min ago', ['n' => intdiv($d, 60)]);
    if ($d < 86400) return t('{n} h ago', ['n' => intdiv($d, 3600)]); if ($d < 7 * 86400) return t('{n} d ago', ['n' => intdiv($d, 86400)]);
    return fmt_dt($ts, 'm/d/Y');
};
?>
<div class="wrap">
  <div class="topline"><h1><?= e(t('Notifications')) ?> <?php if ($unread): ?><span class="chip danger"><?= (int)$unread ?> <?= e(t('unread')) ?></span><?php endif; ?></h1>
    <div style="display:flex;gap:.4rem;flex-wrap:wrap">
      <a class="btn sm <?= $unreadOnly ? 'line' : '' ?>" href="<?= BASE_PATH ?>/notifications"><?= e(t('All')) ?></a>
      <a class="btn sm <?= $unreadOnly ? '' : 'line' ?>" href="<?= BASE_PATH ?>/notifications?unread=1"><?= e(t('Unread')) ?></a>
      <?php if ($unread): ?>
      <form method="post" action="<?= BASE_PATH ?>/notifications/read-all" style="margin:0"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
        <button class="btn sm line"><?= e(t('Mark all read')) ?></button></form>
      <?php endif; ?>
    </div></div>
  <p class="hint"><?= e(t('Expiring and expired credentials, re-verification due dates, overdue calendar items, screening flags, assignments and imports — only for the providers you can see.')) ?></p>

  <div class="panel">
    <?php if ($rows === []): ?>
      <div class="empty"><?= e($unreadOnly ? t('No unread notifications.') : t('Nothing to show yet. Notifications appear as credentials approach expiry, items become overdue, or work is assigned to you.')) ?></div>
    <?php else: ?>
    <table>
      <?php foreach ($rows as $n): $isUnread = $n['read_at'] === null; ?>
      <tr style="<?= $isUnread ? 'background:rgba(37,99,235,.05)' : 'opacity:.8' ?>">
        <td style="width:1rem"><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:<?= $sevColor[$n['severity']] ?? $sevColor['info'] ?>"></span></td>
        <td>
          <div><?= $isUnread ? '<strong>' : '' ?><?= e(FlxCoreHC\NotificationService::localize((string)$n['title'])) ?><?= $isUnread ? '</strong>' : '' ?>
            <span class="chip" style="font-size:.6rem;margin-left:.3rem"><?= e($kindLabel[$n['kind']] ?? ucfirst((string)$n['kind'])) ?></span></div>
          <?php if (!empty($n['body'])): ?><div class="hint" style="font-size:.78rem"><?= e(fmt_iso_text(FlxCoreHC\NotificationService::localize((string)$n['body']))) ?></div><?php endif; ?>
        </td>
        <td class="hint" style="white-space:nowrap;font-size:.75rem"><?= e($ago((string)$n['created_at'])) ?></td>
        <td class="right" style="white-space:nowrap">
          <?php if (!empty($n['link'])): ?>
            <form method="post" action="<?= BASE_PATH ?>/notifications/<?= e($n['id']) ?>/read" style="display:inline"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
              <input type="hidden" name="to" value="<?= e($n['link']) ?>"><button class="btn sm gold"><?= e(t('Open')) ?></button></form>
          <?php endif; ?>
          <?php if ($isUnread): ?>
            <form method="post" action="<?= BASE_PATH ?>/notifications/<?= e($n['id']) ?>/read" style="display:inline"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
              <button class="btn sm line"><?= e(t('Mark read')) ?></button></form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</div>
