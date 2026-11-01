<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */ /** @var FlxCoreHC\TenantContext $ctx */ /** @var array $rows */ /** @var array $f */ /** @var array $typeOptions */ /** @var array $holderOptions */
$title = 'Credential report';
$hc = $ctx->vertical === 'healthcare';
$holderWord = vocab('holder');
$qs = http_build_query(array_filter($f, fn($v) => $v !== '' && $v !== null));

$ptLabels = ['physician'=>'Physician','nurse'=>'Nurse / APP','lab'=>'Laboratory','clinic'=>'Clinic','group'=>'Medical group'];
$data = [];
foreach ($rows as $r) {
    $who = $r['holder_type'] === 'organization' ? (string)$r['org_name']
         : trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
    $data[] = [
        'holder'   => $who,
        'holderId' => (string)$r['holder_id'],
        'htype'    => $r['holder_type'] === 'organization' ? 'Organization' : 'Individual',
        'ptype'    => $ptLabels[$r['provider_type'] ?? ''] ?? '',
        'cred'     => (string)$r['name'],
        'type'     => (string)($typeOptions[$r['credential_type']] ?? $r['credential_type']),
        'issuer'   => (string)($r['issuing_body'] ?? ''),
        'number'   => (string)($r['credential_number'] ?? ''),
        'issued'   => $r['issued_date'] ? (string)$r['issued_date'] : '',
        'expiry'   => $r['expiry_date'] ? (string)$r['expiry_date'] : '',
        'days'     => $r['days_left'] !== null ? (int)$r['days_left'] : null,
        'status'   => (string)$r['status'],
        'docs'     => (int)$r['doc_count'],
    ];
}
?>
<div class="crumb"><?= e($ctx->tenantName) ?></div>
<div class="topline"><h1>Credential report</h1>
  <a class="btn gold" href="<?= BASE_PATH ?>/reports/credentials/export<?= $qs ? '?' . e($qs) : '' ?>">Export CSV</a></div>
<div class="tabs" style="display:flex;gap:.5rem;margin-bottom:1rem">
  <a class="btn sm line" href="<?= BASE_PATH ?>/reports">Compliance</a>
  <a class="btn sm line" href="<?= BASE_PATH ?>/reports/production">Production</a>
  <a class="btn sm" href="<?= BASE_PATH ?>/reports/credentials">Credentials</a>
</div>

<div class="panel">
  <form method="get" action="<?= BASE_PATH ?>/reports/credentials" class="grid4" style="align-items:end;gap:.6rem">
    <div><label for="rf_status">Status</label>
      <select name="status" id="rf_status">
        <option value="">Any</option>
        <?php foreach (['active','expiring','expired'] as $st): ?>
          <option value="<?= $st ?>"<?= ($f['status'] ?? '') === $st ? ' selected' : '' ?>><?= ucfirst($st) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label for="rf_type">Document type</label>
      <select name="type" id="rf_type">
        <option value="">Any</option>
        <?php foreach ($typeOptions as $code => $nm): ?>
          <option value="<?= e($code) ?>"<?= ($f['type'] ?? '') === $code ? ' selected' : '' ?>><?= e($nm) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label for="rf_holder"><?= e($holderWord) ?></label>
      <select name="holder_id" id="rf_holder">
        <option value="">All</option>
        <?php foreach ($holderOptions as $hid => $hn): ?>
          <option value="<?= e($hid) ?>"<?= ($f['holder_id'] ?? '') === $hid ? ' selected' : '' ?>><?= e($hn) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label for="rf_window">Expiring within</label>
      <select name="window_days" id="rf_window">
        <option value="">Any time</option>
        <?php foreach ([30 => '30 days', 60 => '60 days', 90 => '90 days', 180 => '180 days'] as $d => $l): ?>
          <option value="<?= $d ?>"<?= (int)($f['window_days'] ?? 0) === $d ? ' selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label for="rf_docs">Evidence</label>
      <select name="has_docs" id="rf_docs">
        <option value="">Any</option>
        <option value="yes"<?= ($f['has_docs'] ?? '') === 'yes' ? ' selected' : '' ?>>Has documents</option>
        <option value="no"<?= ($f['has_docs'] ?? '') === 'no' ? ' selected' : '' ?>>Missing documents</option>
      </select></div>
    <?php if ($hc): ?>
    <div><label for="rf_pt">Provider type</label>
      <select name="provider_type" id="rf_pt">
        <option value="">Any</option>
        <?php foreach (['physician'=>'Physician','nurse'=>'Nurse / APP','lab'=>'Laboratory','clinic'=>'Clinic','group'=>'Medical group'] as $pv=>$pl): ?>
          <option value="<?= $pv ?>"<?= ($f['provider_type'] ?? '') === $pv ? ' selected' : '' ?>><?= $pl ?></option>
        <?php endforeach; ?>
      </select></div>
    <?php endif; ?>
    <div><button class="btn">Apply server filters</button>
      <?php if ($qs): ?><a class="btn line" href="<?= BASE_PATH ?>/reports/credentials">Clear</a><?php endif; ?></div>
  </form>
  <p class="hint" style="margin:.5rem 0 0">Server filters narrow the dataset and the CSV export. Below, search, sort, and per-column filters refine what's on screen instantly.</p>
</div>

<div class="panel" style="padding-top:.8rem">
  <div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;margin-bottom:.7rem">
    <input type="search" id="gridSearch" placeholder="Search all columns…" autocomplete="off"
           style="flex:1;min-width:220px;padding:.5rem .7rem;border:1px solid var(--line);border-radius:8px">
    <div style="position:relative">
      <button type="button" class="btn sm line" id="colBtn">Columns &#9662;</button>
      <div id="colMenu" style="display:none;position:absolute;right:0;top:110%;z-index:20;background:var(--panel,#fff);border:1px solid var(--line);border-radius:10px;padding:.5rem .6rem;box-shadow:0 8px 24px rgba(0,0,0,.12);min-width:180px"></div>
    </div>
    <select id="pageSize" style="padding:.45rem;border:1px solid var(--line);border-radius:8px">
      <option value="25">25 / page</option>
      <option value="50" selected>50 / page</option>
      <option value="100">100 / page</option>
      <option value="99999">All</option>
    </select>
    <span class="hint" id="gridCount" style="white-space:nowrap"></span>
  </div>

  <div style="overflow:auto;max-height:66vh;border:1px solid var(--line);border-radius:10px">
    <table id="grid" style="width:100%;border-collapse:separate;border-spacing:0;font-size:.86rem">
      <thead></thead>
      <tbody></tbody>
    </table>
  </div>
  <div id="gridEmpty" class="empty" style="display:none">Nothing matches your search and column filters.</div>
  <div id="pager" style="display:flex;gap:.4rem;align-items:center;justify-content:center;margin-top:.8rem;flex-wrap:wrap"></div>
</div>

<script <?= nonce_attr() ?>>
(function () {
  var BASE = <?= flx_json(BASE_PATH) ?>;
  var HC = <?= $hc ? 'true' : 'false' ?>;
  var HOLDER = <?= flx_json($holderWord) ?>;
  var ROWS = <?= flx_json($data, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

  var COLS = [
    { key:'holder', label:HOLDER, filter:true, link:true },
    { key:'htype',  label:'Kind', filter:true },
    { key:'ptype',  label:'Provider type', filter:true, hcOnly:true },
    { key:'cred',   label:'Credential', filter:true },
    { key:'type',   label:'Type', filter:true },
    { key:'issuer', label:'Issuing body', filter:true, hideDefault:true },
    { key:'number', label:'Number', filter:true, hideDefault:true },
    { key:'issued', label:'Issued', hideDefault:true },
    { key:'expiry', label:'Expires' },
    { key:'days',   label:'Days left', num:true },
    { key:'status', label:'Status', filter:true },
    { key:'docs',   label:'Docs', num:true }
  ].filter(function (c) { return !c.hcOnly || HC; });

  var state = { search:'', sortKey:'expiry', sortDir:1, colFilters:{}, hidden:{}, page:1, pageSize:50 };
  COLS.forEach(function (c) { if (c.hideDefault) state.hidden[c.key] = true; });

  var $ = function (id) { return document.getElementById(id); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (m) {
    return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' })[m]; }); };

  function statusChip(st, days) {
    var col = st === 'expired' ? 'var(--danger)' : (st === 'expiring' ? 'var(--warn)' : 'var(--ok)');
    var lbl = st.charAt(0).toUpperCase() + st.slice(1);
    if (days != null && st !== 'active') lbl += ' (' + days + 'd)';
    return '<span class="chip" style="background:' + col + '22;color:' + col + '">' + esc(lbl) + '</span>';
  }
  function cellValue(r, key) { var v = r[key]; return v == null ? '' : v; }

  function filtered() {
    var q = state.search.trim().toLowerCase(), cf = state.colFilters;
    var out = ROWS.filter(function (r) {
      if (q) {
        var hay = COLS.map(function (c) { return cellValue(r, c.key); }).join(' ').toLowerCase();
        if (hay.indexOf(q) === -1) return false;
      }
      for (var k in cf) {
        if (!cf[k]) continue;
        if (String(cellValue(r, k)).toLowerCase().indexOf(cf[k].toLowerCase()) === -1) return false;
      }
      return true;
    });
    var sk = state.sortKey, dir = state.sortDir;
    var col = COLS.filter(function (c) { return c.key === sk; })[0] || {};
    out.sort(function (a, b) {
      var x = cellValue(a, sk), y = cellValue(b, sk);
      if (col.num) { x = (x === '' ? -Infinity : +x); y = (y === '' ? -Infinity : +y); return (x - y) * dir; }
      x = String(x).toLowerCase(); y = String(y).toLowerCase();
      return (x < y ? -1 : x > y ? 1 : 0) * dir;
    });
    return out;
  }

  function render() {
    var rows = filtered(), total = rows.length, ps = state.pageSize;
    var pages = Math.max(1, Math.ceil(total / ps));
    if (state.page > pages) state.page = pages;
    var start = (state.page - 1) * ps, pageRows = rows.slice(start, start + ps);
    var visCols = COLS.filter(function (c) { return !state.hidden[c.key]; });
    var arrow = function (c) { return state.sortKey === c.key ? (state.sortDir === 1 ? ' \u25B2' : ' \u25BC') : ''; };

    var thead = $('grid').tHead || $('grid').createTHead();
    var h = '<tr>';
    visCols.forEach(function (c) {
      h += '<th data-key="' + c.key + '" style="position:sticky;top:0;background:var(--panel,#f7f8fa);cursor:pointer;text-align:'
        + (c.num ? 'right' : 'left') + ';padding:.5rem .6rem;border-bottom:2px solid var(--line);white-space:nowrap;user-select:none;z-index:2">'
        + esc(c.label) + arrow(c) + '</th>';
    });
    h += '</tr><tr>';
    visCols.forEach(function (c) {
      if (c.filter) {
        h += '<th style="position:sticky;top:34px;background:var(--panel,#f7f8fa);padding:.25rem .4rem;border-bottom:1px solid var(--line);z-index:2">'
          + '<input data-cf="' + c.key + '" value="' + esc(state.colFilters[c.key] || '') + '" placeholder="filter\u2026" '
          + 'style="width:100%;box-sizing:border-box;padding:.25rem .4rem;border:1px solid var(--line);border-radius:6px;font-size:.78rem"></th>';
      } else {
        h += '<th style="position:sticky;top:34px;background:var(--panel,#f7f8fa);border-bottom:1px solid var(--line);z-index:2"></th>';
      }
    });
    h += '</tr>';
    thead.innerHTML = h;

    var tb = $('grid').tBodies[0] || $('grid').appendChild(document.createElement('tbody'));
    if (pageRows.length === 0) {
      tb.innerHTML = '';
      $('gridEmpty').style.display = 'block';
    } else {
      $('gridEmpty').style.display = 'none';
      var body = '';
      pageRows.forEach(function (r, i) {
        body += '<tr style="background:' + (i % 2 ? 'transparent' : 'rgba(0,0,0,.02)') + '">';
        visCols.forEach(function (c) {
          var align = c.num ? 'right' : 'left', cls = c.num ? 'mono' : '', val;
          if (c.key === 'status') val = statusChip(r.status, r.days);
          else if (c.key === 'holder' && c.link) val = '<a href="' + BASE + '/holders/' + esc(r.holderId) + '"><strong>' + esc(r.holder) + '</strong></a>';
          else if (c.key === 'days') val = (r.days == null ? '\u2014' : r.days);
          else if (c.key === 'expiry') val = (r.expiry || '\u2014');
          else val = esc(cellValue(r, c.key)) || (c.num ? '0' : '\u2014');
          body += '<td class="' + cls + '" style="text-align:' + align + ';padding:.4rem .6rem;border-bottom:1px solid var(--line);white-space:nowrap">' + val + '</td>';
        });
        body += '</tr>';
      });
      tb.innerHTML = body;
    }

    $('gridCount').textContent = total + ' row' + (total === 1 ? '' : 's')
      + (total > ps ? ' \u00B7 showing ' + (total ? (start + 1) : 0) + '\u2013' + Math.min(start + ps, total) : '');

    var pg = $('pager');
    if (pages <= 1) { pg.innerHTML = ''; }
    else {
      var btn = function (label, page, disabled, active) {
        return '<button type="button" class="btn sm ' + (active ? '' : 'line') + '" ' + (disabled ? 'disabled' : '')
          + ' data-page="' + page + '" style="min-width:34px' + (disabled ? ';opacity:.4' : '') + '">' + label + '</button>';
      };
      var out = btn('\u2039', state.page - 1, state.page === 1, false);
      var win = 2, from = Math.max(1, state.page - win), to = Math.min(pages, state.page + win);
      if (from > 1) out += btn('1', 1, false, state.page === 1);
      if (from > 2) out += '<span class="hint">\u2026</span>';
      for (var p = from; p <= to; p++) out += btn(String(p), p, false, p === state.page);
      if (to < pages - 1) out += '<span class="hint">\u2026</span>';
      if (to < pages) out += btn(String(pages), pages, false, state.page === pages);
      out += btn('\u203A', state.page + 1, state.page === pages, false);
      pg.innerHTML = out;
    }
  }

  function buildColMenu() {
    $('colMenu').innerHTML = COLS.map(function (c) {
      return '<label style="display:flex;gap:.5rem;align-items:center;padding:.2rem 0;font-size:.82rem;cursor:pointer">'
        + '<input type="checkbox" data-col="' + c.key + '"' + (state.hidden[c.key] ? '' : ' checked') + '> ' + esc(c.label) + '</label>';
    }).join('');
  }

  $('gridSearch').addEventListener('input', function () { state.search = this.value; state.page = 1; render(); });
  $('pageSize').addEventListener('change', function () { state.pageSize = +this.value; state.page = 1; render(); });
  $('grid').addEventListener('click', function (e) {
    var th = e.target.closest('th[data-key]'); if (!th) return;
    var k = th.getAttribute('data-key');
    if (state.sortKey === k) state.sortDir *= -1; else { state.sortKey = k; state.sortDir = 1; }
    render();
  });
  $('grid').addEventListener('input', function (e) {
    var inp = e.target.closest('input[data-cf]'); if (!inp) return;
    var key = inp.getAttribute('data-cf');
    state.colFilters[key] = inp.value; state.page = 1;
    render();
    var again = document.querySelector('input[data-cf="' + key + '"]');
    if (again) { again.focus(); again.setSelectionRange(again.value.length, again.value.length); }
  });
  $('pager').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-page]'); if (!b || b.disabled) return;
    state.page = +b.getAttribute('data-page'); render();
  });
  var colBtn = $('colBtn'), colMenu = $('colMenu');
  colBtn.addEventListener('click', function (e) { e.stopPropagation(); colMenu.style.display = colMenu.style.display === 'none' ? 'block' : 'none'; });
  document.addEventListener('click', function (e) { if (!colMenu.contains(e.target) && e.target !== colBtn) colMenu.style.display = 'none'; });
  colMenu.addEventListener('change', function (e) {
    var cb = e.target.closest('input[data-col]'); if (!cb) return;
    state.hidden[cb.getAttribute('data-col')] = !cb.checked; render();
  });

  buildColMenu();
  render();
})();
</script>
