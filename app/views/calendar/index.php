<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $events */ /** @var array $upcoming */
/** @var string $monthStart */ /** @var int $overdue */ /** @var int $planYear */
/** @var string $viewMode */ /** @var string $from */ /** @var string $to */ /** @var string $statusF */
/** @var array $listEvents */ /** @var array $agents */
$title='Calendar';
// v1.172.9: show auto-generated deadline titles in the reader's language (stored text stays English).
$__loc = fn(array $r) => FlxCoreHC\Repository\CalendarRepository::localizeAuto($r);
$events = array_map($__loc, $events ?? []);
if (isset($listEvents)) $listEvents = array_map($__loc, $listEvents);
if (isset($upcoming)) $upcoming = array_map($__loc, $upcoming);
if (isset($agenda) && is_array($agenda)) { foreach (['overdue','week','month','later'] as $__b) { if (isset($agenda[$__b])) $agenda[$__b] = array_map($__loc, $agenda[$__b]); } }
$kindLabel = ($typeLabels ?? []) + ['deadline'=>'Compliance deadline'];
$priDot=['high'=>'var(--danger)','normal'=>'var(--accent)','low'=>'var(--ink-3)'];
$ms=new DateTimeImmutable($monthStart);
$__tz = app_tz();
$nowUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'); // instant, for overdue
$today  = (new DateTimeImmutable('now', $__tz))->format('Y-m-d');                         // LOCAL calendar day
// v1.172.10: an all-day item (every automatic deadline) is a calendar DAY, not an instant. Its stored time (09:00 UTC) is
// only a placeholder, so it must never be shifted into the viewer's zone (west of UTC-9 that moved it a day back).
$__ad   = fn(array $e): bool => (int)($e['all_day'] ?? 0) === 1;
$__when = fn(array $e): string => $__ad($e) ? fmt_day((string)$e['due_at']) : fmt_dt((string)$e['due_at']);
$__over = fn(array $e): bool => ($e['status'] ?? '') === 'open' && ($__ad($e) ? substr((string)$e['due_at'], 0, 10) < $today : (string)$e['due_at'] < $nowUtc);
$byDate=[]; foreach($events as $e){
  try { $__d = $__ad($e) ? substr((string)$e['due_at'], 0, 10) : (new DateTimeImmutable($e['due_at'], new DateTimeZone('UTC')))->setTimezone($__tz)->format('Y-m-d'); }
  catch (\Throwable $ex) { $__d = substr($e['due_at'],0,10); }
  $byDate[$__d][]=$e;
}
// JSON map for the editor
$emap=[];
foreach($events as $e){ $emap[$e['id']]=['id'=>$e['id'],'title'=>$e['title'],'kind'=>$e['kind'],'priority'=>$e['priority'],'source'=>$e['source'],'status'=>$e['status'],'ref'=>$e['source_ref'],'date'=>$__ad($e)?substr($e['due_at'],0,10):fmt_dt((string)$e['due_at'],'Y-m-d'),'time'=>$__ad($e)?substr($e['due_at'],11,5):fmt_dt((string)$e['due_at'],'H:i'),'detail'=>$e['detail'],'holder_id'=>$e['holder_id'],'editable'=>($e['source']==='manual')]; }
$qs = 'm=' . $ms->format('Y-m');
$filterQs = '';
foreach (($filter ?? []) as $k => $v) { if ($v) $filterQs .= '&' . $k . '=' . urlencode($v); }
?>
<div class="crumb"><?= e($ctx->tenantName) ?></div>
<div class="topline"><h1>Calendar</h1>
  <div style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">
    <form method="post" action="<?= BASE_PATH ?>/calendar/sync" data-novalidate style="display:inline"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <button class="btn sm line" title="Rebuild compliance deadlines from credential expiries">Sync deadlines</button></form>
    <a class="btn sm line" href="<?= BASE_PATH ?>/calendar/subscribe">Subscribe / sync</a>
    <?php if (FlxCoreHC\TeamService::canManage($ctx)): ?><a class="btn sm line" href="<?= BASE_PATH ?>/calendar/types">Event types</a><?php endif; ?>
    <button class="btn sm" type="button" data-action="cal-new">+ Add event</button>
  </div>
</div>
<?php if ($overdue>0): ?><div class="error"><?= e($overdue > 1 ? t('{n} items overdue — highlighted below.', ['n' => (int)$overdue]) : t('{n} item overdue — highlighted below.', ['n' => (int)$overdue])) ?></div><?php endif; ?>

<div class="cal-toolbar">
  <div class="mlabel-lg"><?= e(t($ms->format('F')) . ' ' . $ms->format('Y')) ?></div>
  <div class="cal-nav">
    <a class="btn sm line" href="<?= BASE_PATH ?>/calendar?m=<?= e($ms->modify('-1 month')->format('Y-m')) ?>&view=<?= $viewMode ?>">‹</a>
    <a class="btn sm line" href="<?= BASE_PATH ?>/calendar?m=<?= e(date('Y-m')) ?>&view=<?= $viewMode ?>">Today</a>
    <a class="btn sm line" href="<?= BASE_PATH ?>/calendar?m=<?= e($ms->modify('+1 month')->format('Y-m')) ?>&view=<?= $viewMode ?>">›</a>
  </div>
  <div class="spacer" style="flex:1"></div>
  <div class="seg">
    <a class="<?= $viewMode==='agenda'?'on':'' ?>" href="<?= BASE_PATH ?>/calendar?<?= $qs ?>&view=agenda<?= $filterQs ?>">Agenda</a>
    <a class="<?= $viewMode==='month'?'on':'' ?>" href="<?= BASE_PATH ?>/calendar?<?= $qs ?>&view=month<?= $filterQs ?>">Month</a>
    <a class="<?= $viewMode==='list'?'on':'' ?>" href="<?= BASE_PATH ?>/calendar?<?= $qs ?>&view=list<?= $filterQs ?>">List</a>
  </div>
</div>

<?php // ---- Filter bar (scale: narrow hundreds of providers to the few that matter) ----
  $allGroups = $allGroups ?? []; $allLocations = $allLocations ?? []; $filter = $filter ?? [];
  $types = $types ?? []; $groupLabelPlural = $groupLabelPlural ?? 'Groups';
  $anyFilter = !empty($filter);
?>
<?php if ($allGroups || $allLocations || $agents || $types): ?>
<form method="get" action="<?= BASE_PATH ?>/calendar" data-novalidate class="cal-filter" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin:0 0 1rem">
  <input type="hidden" name="view" value="<?= e($viewMode) ?>"><input type="hidden" name="m" value="<?= e($ms->format('Y-m')) ?>">
  <?php if ($agents): ?>
  <select name="holder_id" data-autosubmit style="max-width:200px"><option value="">All providers</option>
    <?php foreach ($agents as $a): $an=trim(($a['first_name']??'').' '.($a['last_name']??'')) ?: ($a['org_name']??''); ?>
      <option value="<?= e($a['id']) ?>" <?= ($filter['holder_id']??'')===$a['id']?'selected':'' ?>><?= e($an) ?></option>
    <?php endforeach; ?></select>
  <?php endif; ?>
  <?php if ($allGroups): ?>
  <select name="group_id" data-autosubmit style="max-width:190px"><option value="">All <?= e(strtolower($groupLabelPlural)) ?></option>
    <?php foreach ($allGroups as $g): ?><option value="<?= e($g['id']) ?>" <?= ($filter['group_id']??'')===$g['id']?'selected':'' ?>><?= e($g['name']) ?></option><?php endforeach; ?></select>
  <?php endif; ?>
  <?php if ($allLocations): ?>
  <select name="location_id" data-autosubmit style="max-width:190px"><option value="">All locations</option>
    <?php foreach ($allLocations as $l): ?><option value="<?= e($l['id']) ?>" <?= ($filter['location_id']??'')===$l['id']?'selected':'' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select>
  <?php endif; ?>
  <select name="kind" data-autosubmit style="max-width:170px"><option value="">All types</option>
    <?php foreach ($types as $t): ?><option value="<?= e($t['code']) ?>" <?= ($filter['kind']??'')===$t['code']?'selected':'' ?>><?= e($t['label']) ?></option><?php endforeach; ?>
    <option value="deadline" <?= ($filter['kind']??'')==='deadline'?'selected':'' ?>>Compliance deadline</option>
  </select>
  <noscript><button class="btn sm">Filter</button></noscript>
  <?php if ($anyFilter): ?><a class="btn sm line" href="<?= BASE_PATH ?>/calendar?<?= $qs ?>&view=<?= $viewMode ?>">Clear filters</a><?php endif; ?>
</form>
<?php endif; ?>

<?php if ($viewMode==='agenda'): /* ---- AGENDA: urgency buckets, the scale-friendly default ---- */
  $agenda = $agenda ?? ['overdue'=>[],'week'=>[],'month'=>[],'later'=>[],'counts'=>[]];
  $buckets = [
    'overdue' => ['Overdue', 'var(--danger)'],
    'week'    => ['Due this week', 'var(--danger)'],
    'month'   => ['Due this month', 'var(--accent)'],
    'later'   => ['Later (next 120 days)', 'var(--ink-3)'],
  ];
  $agTotal = array_sum($agenda['counts'] ?? []);
?>
<div class="panel">
  <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:.8rem">
    <?php foreach ($buckets as $bk => [$lbl,$col]): $n=$agenda['counts'][$bk]??0; ?>
      <div class="ag-chip" style="border-left:3px solid <?= $col ?>;padding:.35rem .7rem;border-radius:6px;background:var(--panel-2,rgba(0,0,0,.03))">
        <div style="font-size:1.3rem;font-weight:700"><?= (int)$n ?></div>
        <div class="hint" style="font-size:.72rem"><?= e(t($lbl)) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($agTotal===0): ?>
    <div class="empty">Nothing due in the next 120 days<?= $anyFilter?' for this filter':'' ?>. You're clear.</div>
  <?php else: foreach ($buckets as $bk => [$lbl,$col]): $rows=$agenda[$bk]??[]; if(!$rows) continue; ?>
    <div style="margin:.4rem 0 1rem">
      <div style="font-weight:600;font-size:.82rem;color:<?= $col ?>;margin-bottom:.3rem"><?= e(t($lbl)) ?> · <?= count($rows) ?></div>
      <table class="cal-agenda"><tbody>
      <?php foreach ($rows as $e): $ed=$__ad($e) ? substr((string)$e['due_at'],0,10) : fmt_dt((string)$e['due_at'], 'Y-m-d'); ?>
        <tr <?= $e['source']==='manual'?'data-id="'.e($e['id']).'" data-action="cal-edit" style="cursor:pointer"':'' ?>>
          <td class="mono" style="white-space:nowrap;width:6.5rem"><?= e(fmt_day($ed)) ?></td>
          <td><span class="pdot" style="background:<?= $priDot[$e['priority']]??'var(--accent)' ?>;display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:.4rem"></span><?= e($e['title']) ?>
            <?php if(($e['kind']??'')==='deadline'): ?><span class="chip" style="font-size:.6rem">deadline</span><?php endif; ?></td>
          <td class="right" style="white-space:nowrap">
            <?php if($e['holder_id']): ?><a class="btn sm line" href="<?= BASE_PATH ?>/holders/<?= e($e['holder_id']) ?>">Open provider</a><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody></table>
    </div>
  <?php endforeach; endif; ?>
</div>
<p class="hint"><?= e(t('Agenda groups open items by urgency so a large roster stays scannable. Use the filters above to narrow by {groups}, location, provider, or type. Switch to Month or List for the classic views.', ['groups' => strtolower($groupLabelPlural)])) ?></p>

<?php elseif ($viewMode==='month'): ?>
<div class="panel">
  <div class="cal-grid">
    <?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d) echo '<div class="cal-dow">'.$d.'</div>'; ?>
    <?php
      $startDow=(int)$ms->format('w');
      $gridStart=$ms->modify('-'.$startDow.' days');
      for($i=0;$i<42;$i++){
        $cur=$gridStart->modify('+'.$i.' days'); $ds=$cur->format('Y-m-d');
        $other=$cur->format('Y-m')!==$ms->format('Y-m');
        $items=$byDate[$ds]??[];
        $cls='cal-cell'.($other?' other':'').($ds===$today?' today':'');   // v1.172.14: overdue is marked on the item, not on the whole day
        echo '<div class="'.$cls.'" data-date="'.$ds.'" data-has-events="'.(!empty($items)?'1':'0').'">';
        echo '<div class="cal-daynum"><span>'.$cur->format('j').'</span><button class="cal-add" type="button" title="Add event" data-action="cal-new" data-date="'.$ds.'">+</button></div>';
        foreach(array_slice($items,0,4) as $it){
          $stat=$it['status']!=='open'?' st-'.$it['status']:''; if($__over($it)) $stat.=' ov';
          $drag=$it['source']==='manual'?'true':'false';
          echo '<div class="cal-ev k-'.e($it['kind']).$stat.'" draggable="'.$drag.'" data-id="'.e($it['id']).'" data-action="cal-edit" title="'.e($it['title']).($__over($it)?' — '.e(t('overdue')):'').'">'
             .'<span class="pdot" style="background:'.$priDot[$it['priority']].'"></span>'.e($it['title']).'</div>';
        }
        if(($dayCounts[$ds]??0)>4){ $extra=($dayCounts[$ds]??count($items))-4; echo '<a class="cal-more" href="'.BASE_PATH.'/calendar?view=list&from='.$ds.'&to='.$ds.$filterQs.'">+'.$extra.' more</a>'; }
        echo '</div>';
      }
    ?>
  </div>
</div>
<p class="hint">Drag an event to another day to move it. Click any event to edit, complete, or delete. Compliance deadlines appear automatically via <em>Sync deadlines</em>.</p>

<?php else: /* ---- LIST VIEW ---- */ ?>
<div class="panel">
  <form method="get" action="<?= BASE_PATH ?>/calendar" data-novalidate style="display:flex;gap:.6rem;align-items:end;flex-wrap:wrap;margin-bottom:1rem">
    <input type="hidden" name="view" value="list"><input type="hidden" name="m" value="<?= e($ms->format('Y-m')) ?>">
    <div><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
    <div><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
    <div><label>Status</label><select name="status">
      <option value="">All</option>
      <?php foreach(['open'=>'Open','done'=>'Done','cancelled'=>'Cancelled'] as $k=>$l): ?><option value="<?= $k ?>" <?= $statusF===$k?'selected':'' ?>><?= $l ?></option><?php endforeach; ?>
    </select></div>
    <button class="btn sm">Filter</button>
    <a class="btn sm line" href="<?= BASE_PATH ?>/calendar?view=list">Reset</a>
  </form>
  <?php if(!$listEvents): ?><div class="empty">No events in this range.</div><?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>When</th><th>Type</th><th>What</th><th>Status</th><th></th></tr>
    <?php foreach($listEvents as $e):
      $over=$__over($e); ?>
    <tr>
      <td class="mono"><?= e($__when($e)) ?><?= $over?' <span class="chip expired">overdue</span>':'' ?></td>
      <td><span class="pdot" style="display:inline-block;width:7px;height:7px;border-radius:50%;background:<?= $priDot[$e['priority']] ?>;margin-right:5px"></span><?= e($kindLabel[$e['kind']]??$e['kind']) ?><?php if($e['source']!=='manual'): ?><span class="cal-badge"><?= e($e['source']) ?></span><?php endif; ?></td>
      <td><strong><?= e($e['title']) ?></strong><?= !empty($e['client_name'])?'<span class="sub">'.e($e['client_name']).'</span>':'' ?></td>
      <td><span class="chip <?= $e['status']==='open'?'pending':($e['status']==='done'?'done':'expired') ?>"><?= e($e['status']) ?></span></td>
      <td class="right">
        <?php if($e['source']==='manual'): ?><button class="btn sm line" type="button" data-action="cal-edit" data-id="<?= e($e['id']) ?>">Edit</button><?php endif; ?>
        <?php if($e['status']==='open'): ?>
          <form method="post" action="<?= BASE_PATH ?>/calendar/<?= e($e['id']) ?>/done" data-novalidate style="display:inline"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="back" value="?view=list&from=<?= e($from) ?>&to=<?= e($to) ?>"><button class="btn sm line">Done</button></form>
        <?php endif; ?>
        <form method="post" action="<?= BASE_PATH ?>/calendar/<?= e($e['id']) ?>/delete" data-novalidate style="display:inline" data-confirm="Delete this event?" data-confirm-danger><?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="back" value="?view=list&from=<?= e($from) ?>&to=<?= e($to) ?>"><button class="btn sm danger">Delete</button></form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel">
  <div class="topline"><h2>Next 30 days</h2></div>
  <?php if(!$upcoming): ?><div class="empty">Nothing scheduled.</div><?php else: ?>
  <div class="table-wrap"><table>
    <?php foreach($upcoming as $e): ?>
    <tr>
      <td class="mono" style="width:150px"><?= e($__when($e)) ?></td>
      <td><span class="pdot" style="display:inline-block;width:7px;height:7px;border-radius:50%;background:<?= $priDot[$e['priority']] ?>;margin-right:5px"></span><strong><?= e($e['title']) ?></strong></td>
      <td class="right"><span class="chip pending"><?= e($kindLabel[$e['kind']]??$e['kind']) ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>

<!-- hidden csrf + agents for JS -->
<span id="calCsrf" hidden><?= FlxCoreHC\Auth\CsrfGuard::field() ?></span>
<script <?= nonce_attr() ?>>window.FLX_CAL=<?= flx_json((object)$emap, JSON_UNESCAPED_SLASHES) ?>;window.FLX_BASE=<?= flx_json(BASE_PATH) ?>;
window.FLX_KINDS=<?= flx_json((object)$kindLabel, JSON_UNESCAPED_SLASHES) ?>;
window.FLX_AGENTS=<?= flx_json(array_map(fn($a)=>['id'=>$a['id'],'name'=>trim(($a['first_name']??'').' '.($a['last_name']??''))],$agents), JSON_UNESCAPED_SLASHES) ?>;</script>

<!-- Calendar editor modal -->
<div class="modal-scrim cal-modal" id="calModal">
  <div class="modal" role="dialog" aria-modal="true">
    <h3 id="calTitle">New event</h3>
    <form id="calForm" method="post" data-novalidate>
      <span id="calFormCsrf"></span>
      <div style="display:grid;gap:.7rem">
        <div><label>Title</label><input name="title" id="cf_title" required></div>
        <div class="grid2">
          <div><label>Type</label><select name="kind" id="cf_kind"><?php foreach (($types ?? []) as $t): if (($t['code'] ?? '') === 'deadline') continue; ?><option value="<?= e($t['code']) ?>"><?= e($t['label']) ?></option><?php endforeach; ?></select></div>
          <div><label>Priority</label><select name="priority" id="cf_priority"><option value="normal">Normal</option><option value="high">High</option><option value="low">Low</option></select></div>
        </div>
        <div class="grid2">
          <div><label>Date</label><input type="date" name="_date" id="cf_date" required></div>
          <div><label>Time</label><input type="time" name="_time" id="cf_time" value="09:00"></div>
        </div>
        <input type="hidden" name="due_at" id="cf_due">
        <div><label><?= e(vocab('holder')) ?> (optional)</label><select name="holder_id" id="cf_holder"><option value="">—</option></select></div>
        <div><label>Notes</label><textarea name="detail" id="cf_detail" rows="2"></textarea></div>
      </div>
      <div class="modal-actions" style="margin-top:1.1rem">
        <button type="button" class="btn danger" id="cf_delete" style="margin-right:auto;display:none">Delete</button>
        <button type="button" class="btn line" data-action="cal-close">Cancel</button>
        <button type="submit" class="btn">Save</button>
      </div>
    </form>
    <div id="calRO" style="display:none">
      <div class="cal-ro"><b id="ro_kind">Item</b><span id="ro_title"></span><div class="sub mono" id="ro_when"></div></div>
      <p class="hint" id="ro_note">Drag this on the calendar to reschedule.</p>
      <div class="modal-actions" id="ro_actions"></div>
    </div>
  </div>
</div>

<script <?= nonce_attr() ?>>
(function(){
  var d=document, base=window.FLX_BASE||'', map=window.FLX_CAL||{}, agents=window.FLX_AGENTS||[];
  function csrf(){ var i=d.querySelector('#calCsrf input'); return i?i.value:''; }
  var modal=d.getElementById('calModal'), form=d.getElementById('calForm'), ro=d.getElementById('calRO');
  // populate agent select once
  var hsel=d.getElementById('cf_holder');
  agents.forEach(function(a){ var o=d.createElement('option'); o.value=a.id; o.textContent=a.name; hsel.appendChild(o); });
  var ksel=d.getElementById('cf_kind');
  function setKind(code){
    var has=false; for(var i=0;i<ksel.options.length;i++){ if(ksel.options[i].value===code){ has=true; break; } }
    if(!has && code){ var o=d.createElement('option'); o.value=code; o.textContent=(window.FLX_KINDS||{})[code]||code; ksel.appendChild(o); }
    ksel.value = code || (ksel.options.length ? ksel.options[0].value : '');
  }
  window.calClose=function(){ modal.classList.remove('open'); };
  function openModal(){ modal.classList.add('open'); }
  function showForm(){ form.style.display=''; ro.style.display='none'; d.getElementById('calFormCsrf').innerHTML='<input type="hidden" name="_csrf" value="'+csrf()+'">'; }
  window.calNew=function(date){
    showForm();
    d.getElementById('calTitle').textContent='New event';
    form.action=base+'/calendar';
    d.getElementById('cf_title').value='';
    setKind(''); d.getElementById('cf_priority').value='normal';
    d.getElementById('cf_date').value=date||new Date().toISOString().slice(0,10);
    d.getElementById('cf_time').value='09:00'; d.getElementById('cf_holder').value=''; d.getElementById('cf_detail').value='';
    d.getElementById('cf_delete').style.display='none';
    // resync any enhanced selects
    ['cf_kind','cf_priority','cf_holder'].forEach(function(id){ d.getElementById(id).dispatchEvent(new Event('change',{bubbles:true})); });
    openModal();
  };
  window.calEdit=function(id){
    var ev=map[id]; if(!ev){ return; }
    if(ev.editable){
      showForm();
      d.getElementById('calTitle').textContent='Edit event';
      form.action=base+'/calendar/'+id+'/update';
      d.getElementById('cf_title').value=ev.title||'';
      setKind(ev.kind||'');
      d.getElementById('cf_priority').value=ev.priority||'normal';
      d.getElementById('cf_date').value=ev.date; d.getElementById('cf_time').value=ev.time||'09:00';
      d.getElementById('cf_holder').value=ev.holder_id||''; d.getElementById('cf_detail').value=ev.detail||'';
      var del=d.getElementById('cf_delete'); del.style.display='';
      del.onclick=function(){ window.flxConfirm('Delete this event?', function(ok){ if(ok) postAction(base+'/calendar/'+id+'/delete'); }, {danger:true}); };
      ['cf_kind','cf_priority','cf_holder'].forEach(function(x){ d.getElementById(x).dispatchEvent(new Event('change',{bubbles:true})); });
    } else {
      // read-only (auto)
      form.style.display='none'; ro.style.display='';
      d.getElementById('calTitle').textContent = 'Compliance item';
      d.getElementById('ro_kind').textContent = (ev.source||'auto');
      d.getElementById('ro_title').textContent = ev.title;
      d.getElementById('ro_when').textContent = ev.date+' '+(ev.time||'');
      d.getElementById('ro_note').textContent = 'Generated from compliance data.';
      var acts=d.getElementById('ro_actions'); acts.innerHTML='';
      acts.appendChild(mkBtn('Close','btn line',window.calClose));
      if(ev.status==='open'){
        acts.appendChild(mkBtn('Mark done','btn',function(){ postAction(base+'/calendar/'+id+'/done'); }));
        acts.appendChild(mkBtn('Dismiss','btn danger',function(){
          window.flxConfirm('Remove this item?', function(ok){ if(ok) postAction(base+'/calendar/'+id+'/delete'); }, {danger:true});
        }));
      }
    }
    openModal();
  };
  function mkBtn(txt,cls,fn){ var b=d.createElement('button'); b.type='button'; b.className=cls; b.textContent=txt; b.onclick=fn; return b; }
  function postAction(action){
    var f=d.createElement('form'); f.method='post'; f.action=action; f.setAttribute('data-novalidate','');
    f.innerHTML='<input type="hidden" name="_csrf" value="'+csrf()+'">';
    d.body.appendChild(f); f.submit();
  }
  // compose due_at before submit
  form && form.addEventListener('submit', function(){
    var dt=d.getElementById('cf_date').value, tm=d.getElementById('cf_time').value||'09:00';
    d.getElementById('cf_due').value=dt+'T'+tm;
  });
  modal && modal.addEventListener('click', function(e){ if(e.target===modal) window.calClose(); });

  /* ---- drag & drop ---- */
  var dragId=null;
  d.querySelectorAll('.cal-ev[draggable="true"]').forEach(function(el){
    el.addEventListener('dragstart', function(e){ dragId=el.getAttribute('data-id'); el.classList.add('dragging'); e.dataTransfer.effectAllowed='move'; });
    el.addEventListener('dragend', function(){ el.classList.remove('dragging'); dragId=null; d.querySelectorAll('.cal-cell.drop').forEach(function(c){c.classList.remove('drop');}); });
  });
  d.querySelectorAll('.cal-cell').forEach(function(cell){
    cell.addEventListener('dragover', function(e){ if(dragId){ e.preventDefault(); cell.classList.add('drop'); } });
    cell.addEventListener('dragleave', function(){ cell.classList.remove('drop'); });
    cell.addEventListener('drop', function(e){
      e.preventDefault(); cell.classList.remove('drop');
      if(!dragId) return; var id=dragId, date=cell.getAttribute('data-date');
      var ev=map[id]; if(ev && ev.date===date) return;
      var body='_csrf='+encodeURIComponent(csrf())+'&date='+encodeURIComponent(date);
      fetch(base+'/calendar/'+id+'/move',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body,credentials:'same-origin'})
        .then(function(r){return r.json();}).then(function(res){
          if(res.ok){ window.flxToast('Moved to '+date, 'ok'); setTimeout(function(){location.reload();}, 400); }
          else { window.flxToast(res.error||'Could not move', 'error'); }
        }).catch(function(){ window.flxToast('Network error', 'error'); });
    });
  });
})();
</script>
