<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var array $case @var array $holder @var array $requirements @var array $blockers @var array $history @var array $catalog @var array $transitions */
$title = 'Case';
$T = FlxCoreHC\Repository\CredentialingCaseRepository::TYPES;
$reqStatuses = FlxCoreHC\Repository\CredentialingCaseRequirementRepository::STATUSES;
$next = [
  'open'=>['in_progress','cancelled'],'in_progress'=>['ready_for_submission','open','cancelled'],
  'ready_for_submission'=>['submitted','in_progress','cancelled'],'submitted'=>['completed','in_progress'],
  'completed'=>['closed'],'closed'=>[],'cancelled'=>[],
][$case['status']] ?? [];
$reqChip = ['pending'=>'','in_progress'=>'warn','satisfied'=>'ok','waived'=>'muted','failed'=>'danger'];
?>
<div class="crumb"><a href="<?= BASE_PATH ?>/cases"><?= e(t('Cases')) ?></a></div>
<div class="topline">
  <h1><?= e($T[$case['case_type']] ?? $case['case_type']) ?> — <?= e(trim(($holder['first_name'] ?? '').' '.($holder['last_name'] ?? '')) ?: ($holder['org_name'] ?? '—')) ?></h1>
  <span class="chip"><?= e(t(ucfirst(str_replace('_',' ',$case['status'])))) ?></span>
</div>
<?php $progress = $progress ?? ['total'=>0,'satisfied'=>0,'pct'=>0,'hard_stops'=>0,'all'=>0];
$needEvidence = 0; foreach (($requirements ?? []) as $__r) { if (empty(($evidence[$__r['id']] ?? [])) && !in_array($__r['status'],['satisfied','waived','not_applicable'],true)) $needEvidence++; } ?>
<div class="panel" style="display:flex;gap:1.4rem;align-items:center;flex-wrap:wrap">
  <div style="min-width:180px"><div style="font-size:1.4rem;font-weight:700"><?= (int)$progress['satisfied'] ?> / <?= (int)$progress['total'] ?></div><div class="hint"><?= e(t('required requirements satisfied')) ?></div></div>
  <div style="flex:1;min-width:200px;height:10px;background:var(--surface-3);border-radius:99px;overflow:hidden"><div style="height:100%;width:<?= (int)$progress['pct'] ?>%;background:<?= $progress['pct']>=100?'var(--ok,#2f7d5b)':($progress['pct']>=50?'var(--warn,#c47f1a)':'var(--danger,#be4b42)') ?>;border-radius:99px"></div></div>
  <div class="hint" style="font-size:.82rem"><?php $bits=[]; if($needEvidence)$bits[]="$needEvidence ".t('need evidence'); if($progress['hard_stops'])$bits[]='<span style="color:var(--danger)">'.$progress['hard_stops'].' '.t('hard stop(s)').'</span>'; echo implode(' · ',$bits) ?: e(t('on track')); ?></div>
</div>

<?php if ($blockers): ?>
<div class="panel" style="border-left:3px solid var(--danger)">
  <h2><?= e(t('Hard stops blocking submission')) ?></h2>
  <ul><?php foreach ($blockers as $b): ?><li><?= e($b['type']==='case_requirement' ? ($b['code'].' — '.$b['status']) : ($b['reason'] ?? 'provider compliance')) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="panel">
  <h2><?= e(t('Lifecycle')) ?></h2>
  <?php if ($next): ?>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <?php foreach ($next as $to): ?>
    <form method="post" action="<?= BASE_PATH ?>/cases/<?= e($case['id']) ?>" style="margin:0"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="action" value="transition"><input type="hidden" name="to" value="<?= e($to) ?>">
      <button class="btn sm <?= $to==='submitted'||$to==='ready_for_submission'?'gold':'line' ?>"><?= e(t('Move to')) ?> <?= e(t(str_replace('_',' ',$to))) ?></button>
    </form>
    <?php endforeach; ?>
  </div>
  <?php else: ?><p class="hint"><?= e(t('This case is in a terminal state.')) ?></p><?php endif; ?>
</div>

<div class="panel">
  <div class="topline"><h2 style="margin:0"><?= e(t('Case requirements')) ?></h2></div>
  <?php if ($requirements === []): ?><div class="empty" style="text-align:left"><?= e(t('No requirements yet.')) ?>
    <form method="post" action="<?= BASE_PATH ?>/cases/<?= e($case['id']) ?>" style="display:inline;margin-left:.5rem"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="populate_requirements"><button class="btn sm gold"><?= e(t('Populate from profession template')) ?></button></form>
    <span class="hint"><?= e(t('or add individually below.')) ?></span></div>
  <?php else: ?>
  <table style="font-size:.86rem">
    <tr><th><?= e(t('Requirement')) ?></th><th><?= e(t('Hard stop')) ?></th><th><?= e(t('Status')) ?></th><th><?= e(t('Due')) ?></th><th></th></tr>
    <?php foreach ($requirements as $r): ?>
    <tr>
      <td><?= e($r['label'] ?: $r['requirement_code']) ?> <span class="mono hint" style="font-size:.7rem"><?= e($r['requirement_code']) ?></span></td>
      <td><?= !empty($r['is_hard_stop']) ? '⛔' : '' ?></td>
      <td><span class="chip <?= $reqChip[$r['status']] ?? '' ?>"><?= e(t(ucfirst($r['status']))) ?></span></td>
      <td class="mono hint"><?= e((string)($r['due_date'] ?? '')) ?></td>
      <td class="right">
        <form method="post" action="<?= BASE_PATH ?>/cases/<?= e($case['id']) ?>" style="margin:0;display:inline-flex;gap:.3rem"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
          <input type="hidden" name="action" value="req_status"><input type="hidden" name="req_id" value="<?= e($r['id']) ?>">
          <select name="status" data-autosubmit aria-label="<?= e(t('Requirement status')) ?>"><?php foreach ($reqStatuses as $k=>$v): ?><option value="<?= e($k) ?>" <?= $r['status']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select>
          <button class="btn sm line"><?= e(t('Save')) ?></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
  <?php $evidence = $evidence ?? []; $eviTargets = FlxCoreHC\Repository\CaseRequirementEvidenceRepository::TARGETS; ?>
  <div style="margin-top:1rem">
    <h3 style="font-size:.9rem"><?= e(t('Evidence')) ?></h3>
    <?php foreach ($requirements as $r): $links = $evidence[$r['id']] ?? []; ?>
    <div class="panel" style="margin:.4rem 0;padding:.6rem .8rem">
      <strong style="font-size:.84rem"><?= e($r['label'] ?: $r['requirement_code']) ?></strong>
      <?php if ($links): ?>
        <ul style="margin:.3rem 0;font-size:.8rem">
        <?php foreach ($links as $lk): $tcol = null; foreach ($eviTargets as $col=>$_t) { if (!empty($lk[$col])) { $tcol=$col; break; } } ?>
          <li><span class="mono hint"><?= e($tcol ? str_replace('_id','',$tcol) : 'evidence') ?></span>: <span class="mono"><?= e($tcol ? substr((string)$lk[$tcol],0,8) : '') ?></span>
            <form method="post" action="<?= BASE_PATH ?>/cases/<?= e($case['id']) ?>" style="display:inline;margin:0"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
              <input type="hidden" name="action" value="unlink_evidence"><input type="hidden" name="evidence_id" value="<?= e($lk['id']) ?>">
              <button class="btn sm line" style="padding:0 .4rem"><?= e(t('Unlink')) ?></button>
            </form></li>
        <?php endforeach; ?>
        </ul>
      <?php else: ?><div class="hint" style="font-size:.75rem"><?= e(t('No evidence linked.')) ?></div><?php endif; ?>
      <details><summary class="hint" style="cursor:pointer;font-size:.75rem"><?= e(t('Link evidence')) ?></summary>
        <form method="post" action="<?= BASE_PATH ?>/cases/<?= e($case['id']) ?>" class="stack" style="margin-top:.4rem;max-width:460px"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
          <input type="hidden" name="action" value="link_evidence"><input type="hidden" name="req_id" value="<?= e($r['id']) ?>">
          <p class="hint" style="font-size:.72rem"><?= e(t('Select existing evidence, or paste one evidence id. Exactly one target.')) ?></p>
          <?php $reuse = ($reusable ?? [])[$r['requirement_code']] ?? []; if ($reuse): ?>
          <div style="font-size:.76rem;margin:.2rem 0">
            <?php foreach ($reuse as $rc): if (($rc['kind'] ?? '')!=='credential') continue; ?>
              <label style="display:block;font-weight:normal"><input type="radio" name="credential_id" value="<?= e($rc['id']) ?>" style="width:auto"> <?= e($rc['label']) ?>
                <?php if (!$rc['valid']): ?><span class="chip danger" style="font-size:.6rem"><?= e(t('expired — not valid')) ?></span><?php elseif (!empty($rc['expiry'])): ?><span class="hint"><?= e(t('exp')) ?> <?= e(fmt_day((string)$rc['expiry'])) ?></span><?php else: ?><span class="chip ok" style="font-size:.6rem"><?= e(t('current')) ?></span><?php endif; ?></label>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <details style="font-size:.74rem"><summary class="hint"><?= e(t('other evidence type / by id')) ?></summary>
          <?php foreach ($eviTargets as $col=>$_t): if ($col==='credential_id') continue; ?><label style="font-size:.78rem"><?= e(str_replace('_',' ',$col)) ?> <input name="<?= e($col) ?>" class="mono"></label><?php endforeach; ?>
          </details>
          <button class="btn sm gold"><?= e(t('Link')) ?></button>
        </form>
      </details>
    </div>
    <?php endforeach; ?>
  </div>

  <details style="margin-top:.8rem"><summary class="btn sm line" style="display:inline-block"><?= e(t('+ Add requirement')) ?></summary>
    <form method="post" action="<?= BASE_PATH ?>/cases/<?= e($case['id']) ?>" class="stack" style="margin-top:.6rem;max-width:520px"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
      <input type="hidden" name="action" value="add_requirement">
      <label><?= e(t('Requirement (catalog)')) ?>
        <select name="requirement_code" data-fill="label">
          <?php
            // Group the catalog by category so clinical and non-clinical types are easy to find.
            $__byCat = [];
            foreach ($catalog as $cat) { $__byCat[(string)($cat['category'] ?? 'other')][] = $cat; }
            ksort($__byCat);
            foreach ($__byCat as $__catName => $__items): ?>
            <optgroup label="<?= e(ucfirst((string)$__catName)) ?>">
              <?php foreach ($__items as $cat): ?><option value="<?= e($cat['code']) ?>" data-name="<?= e($cat['name']) ?>"><?= e($cat['name']) ?> (<?= e($cat['code']) ?>)</option><?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select></label>
      <input type="hidden" name="label" value="">
      <label style="font-weight:normal"><input type="checkbox" name="is_hard_stop" value="1" style="width:auto"> <?= e(t('Hard stop (blocks submission)')) ?></label>
      <label><?= e(t('Due date')) ?> <input type="date" name="due_date"></label>
      <button class="btn sm gold"><?= e(t('Add')) ?></button>
    </form>
  </details>
</div>

<div class="panel">
  <div class="topline"><h2 style="margin:0"><?= e(t('Applications')) ?></h2></div>
  <p class="hint" style="font-size:.75rem"><?= e(t('Payer/enrollment submissions linked to this case. A case may have several.')) ?></p>
  <?php $applications = $applications ?? []; if ($applications): ?>
  <table style="font-size:.82rem">
    <tr><th><?= e(t('Kind')) ?></th><th><?= e(t('Status')) ?></th><th><?= e(t('Submitted')) ?></th><th><?= e(t('Decision')) ?></th><th><?= e(t('Due')) ?></th></tr>
    <?php foreach ($applications as $a): ?>
    <tr><td><?= e((string)($a['kind'] ?? '')) ?></td><td><span class="chip"><?= e((string)($a['status'] ?? '')) ?></span></td>
      <td class="mono hint"><?= e((string)($a['submitted_on'] ?? '')) ?></td><td class="mono hint"><?= e((string)($a['decision_on'] ?? '')) ?></td><td class="mono hint"><?= e((string)($a['due_on'] ?? '')) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?><div class="hint" style="font-size:.8rem"><?= e(t('No applications linked yet.')) ?></div><?php endif; ?>
</div>

<div class="panel">
  <div class="topline"><h2 style="margin:0"><?= e(t('Case documents')) ?></h2></div>
  <p class="hint" style="font-size:.75rem"><?= e(t('Provider/case evidence that is not tied to a specific credential (CV, W-9, forms).')) ?></p>
  <?php $caseDocuments = $caseDocuments ?? []; if ($caseDocuments): ?>
  <table style="font-size:.82rem">
    <tr><th><?= e(t('Document')) ?></th><th><?= e(t('Type')) ?></th><th><?= e(t('Uploaded')) ?></th><th></th></tr>
    <?php foreach ($caseDocuments as $d): ?>
    <tr>
      <td><a href="<?= BASE_PATH ?>/cases/<?= e($case['id']) ?>/document/<?= e($d['id']) ?>"><?= e($d['original_name'] ?: 'document') ?></a></td>
      <td class="hint"><?= e((string)($d['doc_type'] ?? '')) ?></td>
      <td class="mono hint"><?= e(fmt_dt((string)$d['created_at'], 'm/d/Y')) ?></td>
      <td class="right"><form method="post" action="<?= BASE_PATH ?>/cases/<?= e($case['id']) ?>" style="margin:0" data-confirm="Remove this document?"><?= FlxCoreHC\Auth\CsrfGuard::field() ?><input type="hidden" name="action" value="delete_case_document"><input type="hidden" name="doc_id" value="<?= e($d['id']) ?>"><button class="btn sm line"><?= e(t('Remove')) ?></button></form></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?><div class="hint" style="font-size:.8rem"><?= e(t('No case documents yet.')) ?></div><?php endif; ?>
  <form method="post" action="<?= BASE_PATH ?>/cases/<?= e($case['id']) ?>" enctype="multipart/form-data" class="stack" style="margin-top:.6rem;max-width:460px"><?= FlxCoreHC\Auth\CsrfGuard::field() ?>
    <input type="hidden" name="action" value="upload_case_document">
    <label><?= e(t('Document type')) ?> <input name="doc_type" placeholder="cv | w9 | application | other"></label>
    <label><?= e(t('File')) ?> <input type="file" name="document" required></label>
    <button class="btn sm gold"><?= e(t('Upload')) ?></button>
  </form>
</div>

<div class="panel">
  <h2><?= e(t('Case history')) ?></h2>
  <?php if ($history === []): ?><p class="hint"><?= e(t('No events yet.')) ?></p>
  <?php else: ?><table style="font-size:.82rem"><?php foreach ($history as $h): ?>
    <tr><td class="mono hint" style="white-space:nowrap"><?= e(fmt_dt((string)$h['created_at'], 'm/d/Y g:i A')) ?></td><td><?= e($h['summary']) ?></td><td class="hint"><?= e((string)($h['actor_name'] ?? '')) ?></td></tr>
  <?php endforeach; ?></table><?php endif; ?>
</div>
