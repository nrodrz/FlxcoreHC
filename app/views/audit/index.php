<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** @var FlxCoreHC\TenantContext $ctx */ /** @var array $rows */ /** @var array $filter */
/** @var array $users */ /** @var array $actions */ /** @var int $total */ /** @var int $page */ /** @var int $pages */
$title = 'Audit log';
require FLXCOREHC_APP . '/views/org/_tabs.php';
$entityTypes = ['holders'=>'Providers','credentials'=>'Credentials','credential_documents'=>'Documents',
  'provider_groups'=>'Groups','locations'=>'Locations','tenants'=>'Organization','users'=>'Users',
  'roles'=>'Roles','audit_logs'=>'Audit'];
$qs = function(array $extra) use ($filter) { return http_build_query(array_merge($filter, $extra)); };
$fmtChanges = function($j) {
    if (!$j) return '';
    $d = json_decode((string)$j, true);
    if (!is_array($d)) return (string)$j;
    if (isset($d['changes']) && is_array($d['changes'])) {
        $parts = [];
        foreach ($d['changes'] as $k => $v) { $parts[] = $k . ': ' . (is_array($v) ? (($v['from'] ?? '∅') . ' → ' . ($v['to'] ?? '∅')) : $v); }
        return implode(' · ', $parts);
    }
    $parts = []; foreach ($d as $k => $v) { if (is_scalar($v)) $parts[] = $k . ': ' . $v; }
    return implode(' · ', $parts);
};
?>
<div class="wrap">
  <div class="topline"><h1><?= e(t('Audit log')) ?></h1>
    <a class="btn sm line" href="<?= BASE_PATH ?>/audit/pulse"><?= e(t('Activity dashboard')) ?></a>
    <a class="btn sm line" href="<?= BASE_PATH ?>/audit/export.csv?<?= e($qs([])) ?>"><?= e(t('Export CSV')) ?></a></div>
  <p class="hint"><?= e(t('Every action in this organization — who did what, and when. Filter by date, person, action or record; drill into a provider, credential or document from its own page.')) ?></p>

  <form method="get" action="<?= BASE_PATH ?>/audit" class="panel" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:end;margin-bottom:1rem">
    <div><label style="font-size:.72rem"><?= e(t('From')) ?></label><input type="date" name="from" value="<?= e($filter['from'] ?? '') ?>"></div>
    <div><label style="font-size:.72rem"><?= e(t('To')) ?></label><input type="date" name="to" value="<?= e($filter['to'] ?? '') ?>"></div>
    <div><label style="font-size:.72rem"><?= e(t('Who')) ?></label>
      <select name="user_id"><option value=""><?= e(t('Anyone')) ?></option>
        <?php foreach ($users as $u): ?><option value="<?= e($u['id']) ?>" <?= ($filter['user_id'] ?? '')===$u['id']?'selected':'' ?>><?= e($u['full_name']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label style="font-size:.72rem"><?= e(t('Record type')) ?></label>
      <select name="entity_type"><option value=""><?= e(t('All')) ?></option>
        <?php foreach ($entityTypes as $k=>$lbl): ?><option value="<?= e($k) ?>" <?= ($filter['entity_type'] ?? '')===$k?'selected':'' ?>><?= e($lbl) ?></option><?php endforeach; ?>
      </select></div>
    <div><label style="font-size:.72rem"><?= e(t('Action')) ?></label>
      <select name="action"><option value=""><?= e(t('All')) ?></option>
        <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>" <?= ($filter['action'] ?? '')===$a?'selected':'' ?>><?= e(str_replace('_',' ',$a)) ?></option><?php endforeach; ?>
      </select></div>
    <div style="flex:1;min-width:150px"><label style="font-size:.72rem"><?= e(t('Search')) ?></label><input type="search" name="q" value="<?= e($filter['q'] ?? '') ?>" placeholder="<?= e(t('record id or text')) ?>" style="width:100%"></div>
    <div><button class="btn sm"><?= e(t('Filter')) ?></button>
      <?php if ($filter): ?><a class="btn sm line" href="<?= BASE_PATH ?>/audit"><?= e(t('Clear')) ?></a><?php endif; ?></div>
  </form>

  <div class="panel">
    <div class="topline" style="margin-bottom:.4rem"><span class="hint"><?= (int)$total ?> <?= e(t('entries')) ?><?= $filter ? ' (' . e(t('filtered')) . ')' : '' ?></span>
      <?php if ($pages > 1): ?><span class="hint"><?= e(t('Page')) ?> <?= (int)$page ?> / <?= (int)$pages ?></span><?php endif; ?></div>
    <?php if ($rows === []): ?>
      <div class="empty"><?= e(t('No matching activity.')) ?></div>
    <?php else: ?>
    <table>
      <tr><th><?= e(t('When')) ?></th><th><?= e(t('Who')) ?></th><th><?= e(t('Action')) ?></th><th><?= e(t('Record')) ?></th><th><?= e(t('Changes')) ?></th></tr>
      <?php foreach ($rows as $a): ?>
      <tr>
        <td class="mono hint" style="white-space:nowrap" data-label="<?= e(t('When')) ?>"><?= e(fmt_dt($a['created_at'], 'm/d/Y g:i A')) ?></td>
        <td data-label="<?= e(t('Who')) ?>"><?= e($a['user_name'] ?? 'System') ?></td>
        <td data-label="<?= e(t('Action')) ?>"><strong><?= e(ucfirst(str_replace('_',' ',(string)$a['action']))) ?></strong></td>
        <td class="hint" data-label="<?= e(t('Record')) ?>"><span<?= !empty($a['entity_id']) ? ' title="ID ' . e((string)$a['entity_id']) . '"' : '' ?>><?= e($entityTypes[$a['entity_type']] ?? ucfirst(str_replace('_',' ',(string)$a['entity_type']))) ?></span></td>
        <td class="hint" style="font-size:.78rem" data-label="<?= e(t('Changes')) ?>"><?= e(fmt_iso_text($fmtChanges($a["changes"]))) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php if ($pages > 1): ?>
    <div style="display:flex;gap:.4rem;justify-content:center;margin-top:.8rem">
      <?php if ($page > 1): ?><a class="btn sm line" href="<?= BASE_PATH ?>/audit?<?= e($qs(['page'=>$page-1])) ?>">← <?= e(t('Previous')) ?></a><?php endif; ?>
      <?php if ($page < $pages): ?><a class="btn sm line" href="<?= BASE_PATH ?>/audit?<?= e($qs(['page'=>$page+1])) ?>"><?= e(t('Next')) ?> →</a><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
