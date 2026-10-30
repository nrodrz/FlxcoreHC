/* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/* OpsHub — the app's dropdown for the pages that are plain HTML (public forms, the signing page, the installer and
 * the pop-out editor). The signed-in app (index.html) has the same dropdown as a React component (Sel / DSel); the look
 * is the same on purpose — if you change the rules below, change the .sel-* rules in index.html too.
 *
 * What it does: every <select> on the page is kept (so the page's own code, its value, its "required" check and its
 * change events work exactly as before) but hidden, and a button + list in the app's style is shown in its place.
 * Nothing to call: it runs when the page is ready and watches for selects and options added later
 * (unless window.OPSH_SELECT_MANUAL is set before this file loads — then only scan() does anything).
 *   window.opshSelect.scan(root)   enhance the selects inside root (default: the whole page)
 *   window.opshSelect.refresh(sel) re-read one select after its options or value were changed by code
 * Not enhanced: <select multiple>, <select size>1>, and any select with data-native.
 */
(function () {
  'use strict';
  if (window.opshSelect) return;

  var CSS = [
    '.sel{display:inline-block;position:relative;vertical-align:top;max-width:100%;box-sizing:border-box}',
    '.sel.sel-block{display:block;width:100%}',
    '.sel-native{position:absolute!important;opacity:0!important;pointer-events:none!important;width:1px!important;height:1px!important;min-width:0!important;padding:0!important;border:0!important;margin:-2px 0 0 12px!important;overflow:hidden!important}',
    '.sel-btn{width:100%;box-sizing:border-box;display:flex;align-items:center;gap:8px;padding:9px 12px;background:var(--s3,var(--bg2,#fff));border:1px solid var(--b1,#C7D2DE);border-radius:9px;color:var(--t1,#2E3A44);font-family:inherit;font-size:14px;font-weight:400;line-height:1.2;letter-spacing:normal;text-transform:none;cursor:pointer;text-align:left;margin:0;height:auto;min-height:0;box-shadow:none;justify-content:flex-start}',
    '.sel-btn:hover{border-color:var(--accent,#1D4D7A)}',
    '.sel-btn:focus-visible{outline:2px solid var(--accent,#1D4D7A);outline-offset:1px}',
    '.sel-open .sel-btn{border-color:var(--accent,#1D4D7A);box-shadow:0 0 0 3px var(--accentL,rgba(143,179,199,.25))}',
    '.sel-dis .sel-btn{opacity:.55;cursor:not-allowed}',
    '.sel-ph{color:var(--t4,#95A1AD)}',
    '.sel-txt{flex:1;min-width:0;display:flex;flex-direction:column}',
    '.sel-val{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
    '.sel-sz{height:0;overflow:hidden;visibility:hidden;white-space:nowrap}',
    '.sel-chev{flex:0 0 auto;opacity:.55;transition:transform .15s}',
    '.sel-open .sel-chev{transform:rotate(180deg)}',
    '.sel-menu{position:fixed;box-sizing:border-box;width:max-content;background:var(--bg2,var(--s1,#fff));border:1px solid var(--b1,#C7D2DE);border-radius:10px;box-shadow:var(--shadowLg,0 12px 32px rgba(15,35,60,.18));padding:5px;z-index:9600;overflow-y:auto;overscroll-behavior:contain;font-family:inherit;animation:selpop .14s ease}',
    '.sel-opt{padding:8px 11px;border-radius:7px;font-size:14px;line-height:1.3;color:var(--t1,#2E3A44);cursor:pointer;overflow-wrap:anywhere}',
    '.sel-opt.act{background:var(--accentL,rgba(143,179,199,.25))}',
    '.sel-opt.on{background:var(--accent,#1D4D7A);color:#fff;font-weight:600}',
    '.sel-opt.dis{opacity:.45;cursor:not-allowed}',
    '.sel-grp{padding:8px 11px 3px;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--t4,#95A1AD)}',
    '@keyframes selpop{from{opacity:0;transform:translateY(-5px)}to{opacity:1;transform:translateY(0)}}',
    '@media (prefers-reduced-motion:reduce){.sel-menu{animation:none}.sel-chev{transition:none}}',
    '@media (max-width:700px){.sel-opt{padding:11px 12px}}'
  ].join('');
  var CHEV = '<svg class="sel-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
  var seq = 0, openOne = null;

  function addCss(doc) {
    if (doc.getElementById('opsh-sel-css')) return;
    var st = doc.createElement('style'); st.id = 'opsh-sel-css'; st.textContent = CSS;
    (doc.head || doc.documentElement).appendChild(st);
  }

  /* Where the list goes: fixed, next to the button, inside the window; upward when there is more room above. */
  function place(btn, menu) {
    var w = btn.ownerDocument.defaultView || window, r = btn.getBoundingClientRect(), vw = w.innerWidth, vh = w.innerHeight, gap = 5, pad = 8;
    var keep = menu.scrollTop;
    menu.style.maxHeight = ''; menu.style.maxWidth = ''; menu.style.minWidth = ''; menu.style.top = ''; menu.style.bottom = '';
    var below = vh - r.bottom - gap - pad, above = r.top - gap - pad, want = Math.min(320, menu.scrollHeight + 2);
    var up = below < Math.min(want, 200) && above > below;
    var maxW = Math.min(480, vw - 2 * pad);
    menu.style.minWidth = Math.min(r.width, maxW) + 'px'; menu.style.maxWidth = maxW + 'px';
    menu.style.maxHeight = Math.max(110, Math.min(320, up ? above : below)) + 'px';
    var mw = Math.min(Math.max(menu.offsetWidth, r.width), maxW), left = r.left;
    if (left + mw > vw - pad) left = Math.max(pad, vw - pad - mw);
    menu.style.left = left + 'px';
    if (up) menu.style.bottom = (vh - r.top + gap) + 'px'; else menu.style.top = (r.bottom + gap) + 'px';
    menu.scrollTop = keep;
  }

  function enhance(sel) {
    if (!sel || sel.__opsh || sel.multiple || sel.size > 1 || sel.hasAttribute('data-native') || !sel.parentNode) return;
    sel.__opsh = {};
    var doc = sel.ownerDocument, win = doc.defaultView || window; addCss(doc);
    var id = 'osel' + (++seq), items = [], act = -1, menu = null, ta = { s: '', t: 0 }, skip = 0;

    /* Layout: a select that filled its line stays a block, an inline one stays inline. The button takes the look the
       page gave its select (background, border, corners, padding, text), so it matches the inputs next to it. */
    var cs = win.getComputedStyle(sel), par = sel.parentNode, block;
    if (sel.offsetParent === null) block = cs.display === 'block' || cs.width === '100%';
    else { var pcs = win.getComputedStyle(par); block = cs.display === 'block' || sel.getBoundingClientRect().width >= par.clientWidth - parseFloat(pcs.paddingLeft || 0) - parseFloat(pcs.paddingRight || 0) - 6; }
    var wrap = doc.createElement('span'); wrap.className = 'sel' + (block ? ' sel-block' : '');
    if (sel.style.width) wrap.style.width = sel.style.width;
    if (parseFloat(cs.marginTop)) wrap.style.marginTop = cs.marginTop;
    if (parseFloat(cs.marginBottom)) wrap.style.marginBottom = cs.marginBottom;
    if (cs.flexGrow && cs.flexGrow !== '0') wrap.style.flex = cs.flexGrow + ' ' + cs.flexShrink + ' ' + cs.flexBasis;
    var btn = doc.createElement('button'); btn.type = 'button'; btn.className = 'sel-btn';
    btn.setAttribute('role', 'combobox'); btn.setAttribute('aria-haspopup', 'listbox'); btn.setAttribute('aria-expanded', 'false');
    btn.innerHTML = '<span class="sel-txt"><span class="sel-val"></span><span class="sel-sz" aria-hidden="true"></span></span>' + CHEV;
    if (parseFloat(cs.paddingTop) >= 3) {   /* the page styled its selects: copy that look */
      btn.style.backgroundColor = cs.backgroundColor; btn.style.color = cs.color; btn.style.fontSize = cs.fontSize; btn.style.fontFamily = cs.fontFamily;
      if (cs.borderTopStyle !== 'none') btn.style.border = cs.borderTopWidth + ' ' + cs.borderTopStyle + ' ' + cs.borderTopColor;
      btn.style.borderRadius = cs.borderTopLeftRadius;
      btn.style.padding = cs.paddingTop + ' ' + cs.paddingRight + ' ' + cs.paddingBottom + ' ' + cs.paddingLeft;
    }
    var val = btn.querySelector('.sel-val'), sz = btn.querySelector('.sel-sz');
    wrap.appendChild(btn); par.insertBefore(wrap, sel);
    sel.classList.add('sel-native'); sel.tabIndex = -1; sel.setAttribute('aria-hidden', 'true');

    function read() {
      items = []; var longest = '';
      Array.prototype.forEach.call(sel.children, function (c) {
        if (c.tagName === 'OPTGROUP') { items.push({ group: c.label || '' }); Array.prototype.forEach.call(c.children, function (o) { if (o.tagName === 'OPTION') push(o, c.disabled); }); }
        else if (c.tagName === 'OPTION') push(c, false);
      });
      function push(o, dis) { var l = o.label || o.textContent || ''; if (l.length > longest.length) longest = l; items.push({ o: o, l: l, disabled: dis || o.disabled }); }
      var cur = sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex] : null, text = cur ? (cur.label || cur.textContent || '') : '';
      val.textContent = text || '\u00a0'; val.className = 'sel-val' + (cur && cur.value !== '' ? '' : ' sel-ph'); sz.textContent = block ? '' : longest;
      btn.disabled = sel.disabled; wrap.classList.toggle('sel-dis', sel.disabled); btn.setAttribute('data-value', cur ? cur.value : '');
      wrap.style.display = sel.style.display === 'none' || sel.hidden ? 'none' : '';
      var lab = sel.getAttribute('aria-label') || (sel.id && doc.querySelector('label[for="' + sel.id.replace(/"/g, '\\"') + '"]') || {}).textContent || '';
      if (lab) btn.setAttribute('aria-label', lab.trim() + ': ' + text); else btn.removeAttribute('aria-label');
      if (menu) draw();
    }
    function ok(i) { return !!(items[i] && !items[i].group && !items[i].disabled); }
    function step(from, d) { for (var i = from + d; i >= 0 && i < items.length; i += d) if (ok(i)) return i; return ok(from) ? from : -1; }
    function setAct(i) {
      act = i; if (!menu) return;
      Array.prototype.forEach.call(menu.children, function (el) { el.classList.toggle('act', el.getAttribute('data-i') === String(i)); });
      var el = menu.querySelector('[data-i="' + i + '"]');
      if (el) { btn.setAttribute('aria-activedescendant', el.id);
        if (el.offsetTop < menu.scrollTop) menu.scrollTop = el.offsetTop - 4;
        else if (el.offsetTop + el.offsetHeight > menu.scrollTop + menu.clientHeight) menu.scrollTop = el.offsetTop + el.offsetHeight - menu.clientHeight + 4; }
    }
    function draw() {
      menu.textContent = '';
      items.forEach(function (it, i) {
        var el = doc.createElement('div');
        if (it.group !== undefined) { el.className = 'sel-grp'; el.textContent = it.group; }
        else { el.className = 'sel-opt' + (it.o.selected ? ' on' : '') + (it.disabled ? ' dis' : ''); el.id = id + '-' + i; el.setAttribute('data-i', i); el.setAttribute('data-v', it.o.value); el.setAttribute('role', 'option');
          el.setAttribute('aria-selected', it.o.selected ? 'true' : 'false'); el.textContent = it.l || '\u00a0'; 
          if (it.o.style && it.o.style.fontFamily) el.style.fontFamily = it.o.style.fontFamily; }   /* an option shown in its own typeface (signature fonts) keeps it */
        menu.appendChild(el);
      });
    }
    function close(focus) {
      if (!menu) return;
      doc.removeEventListener('mousedown', onDown, true); doc.removeEventListener('keydown', onKey, true);
      win.removeEventListener('scroll', onScroll, true); win.removeEventListener('resize', onResize);
      if (raf) { win.cancelAnimationFrame(raf); raf = 0; }
      if (menu.parentNode) menu.parentNode.removeChild(menu); menu = null; openOne = null;
      wrap.classList.remove('sel-open'); btn.setAttribute('aria-expanded', 'false'); btn.removeAttribute('aria-activedescendant'); btn.removeAttribute('aria-controls');
      if (focus) { try { btn.focus(); } catch (e) {} }
    }
    function open() {
      if (menu || sel.disabled) return;
      if (openOne) openOne(false);
      read();
      menu = doc.createElement('div'); menu.className = 'sel-menu'; menu.id = id; menu.setAttribute('role', 'listbox'); menu.style.visibility = 'hidden'; menu.style.left = '0'; menu.style.top = '0';
      menu.addEventListener('mousedown', function (e) { e.preventDefault(); });
      menu.addEventListener('mousemove', function (e) { var el = e.target.closest ? e.target.closest('.sel-opt') : null; if (el) { var i = +el.getAttribute('data-i'); if (i !== act && ok(i)) setAct(i); } });
      menu.addEventListener('click', function (e) { var el = e.target.closest ? e.target.closest('.sel-opt') : null; if (el) choose(+el.getAttribute('data-i')); });
      draw(); doc.body.appendChild(menu); place(btn, menu); menu.style.visibility = '';
      wrap.classList.add('sel-open'); btn.setAttribute('aria-expanded', 'true'); btn.setAttribute('aria-controls', id);
      var curI = -1; items.forEach(function (it, i) { if (it.o && it.o.selected) curI = i; });
      setAct(ok(curI) ? curI : step(-1, 1));
      doc.addEventListener('mousedown', onDown, true); doc.addEventListener('keydown', onKey, true);
      win.addEventListener('scroll', onScroll, true); win.addEventListener('resize', onResize);
      openOne = close;
    }
    function choose(i) {
      if (!ok(i)) return;
      var o = items[i].o, changed = !o.selected; close(true);
      if (!changed) return;
      nativeIndex.call(sel, Array.prototype.indexOf.call(sel.options, o)); read();
      sel.dispatchEvent(new win.Event('input', { bubbles: true })); sel.dispatchEvent(new win.Event('change', { bubbles: true }));
    }
    function jump(ch) {
      var t = Date.now(); ta.s = (t - ta.t > 700 ? '' : ta.s) + ch.toLowerCase(); ta.t = t;
      for (var i = 0; i < items.length; i++) if (ok(i) && String(items[i].l).toLowerCase().replace(/^\s+/, '').indexOf(ta.s) === 0) { setAct(i); return; }
    }
    function onDown(e) { if (!(wrap.contains(e.target) || (menu && menu.contains(e.target)))) close(false); }
    /* the page moved under the open list: keep the list on its button; close only when the button is no longer in view */
    var raf = 0;
    function follow() {
      raf = 0; if (!menu) return;
      var r = btn.getBoundingClientRect();
      var top = doc.elementFromPoint(Math.min(Math.max(r.left + r.width / 2, 0), win.innerWidth - 1), Math.min(Math.max(r.top + r.height / 2, 0), win.innerHeight - 1));
      if (r.bottom < 0 || r.top > win.innerHeight || r.right < 0 || r.left > win.innerWidth || !top || !btn.contains(top)) { close(false); return; }
      place(btn, menu);
    }
    function onScroll(e) { if (menu && e.target && e.target.nodeType === 1 && menu.contains(e.target)) return; if (!raf) raf = win.requestAnimationFrame(follow); }
    function onResize() { if (!raf) raf = win.requestAnimationFrame(follow); }
    function onKey(e) {
      var k = e.key; if (e.ctrlKey || e.metaKey || e.altKey) return;
      if (k === 'Escape') { e.preventDefault(); e.stopPropagation(); close(true); }
      else if (k === 'Tab') close(false);
      else if (k === 'ArrowDown') { e.preventDefault(); setAct(step(act, 1)); }
      else if (k === 'ArrowUp') { e.preventDefault(); setAct(act < 0 ? step(items.length, -1) : step(act, -1)); }
      else if (k === 'Home' || k === 'PageUp') { e.preventDefault(); setAct(step(-1, 1)); }
      else if (k === 'End' || k === 'PageDown') { e.preventDefault(); setAct(step(items.length, -1)); }
      else if (k === 'Enter' || k === ' ') { e.preventDefault(); e.stopPropagation(); skip = Date.now(); if (act >= 0) choose(act); else close(true); }
      else if (k.length === 1) { e.preventDefault(); jump(k); }
    }
    btn.addEventListener('click', function () { if (Date.now() - skip < 350) return; if (menu) close(false); else open(); });
    btn.addEventListener('keydown', function (e) {
      if (menu || sel.disabled || e.ctrlKey || e.metaKey || e.altKey) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); open(); }
      else if (e.key.length === 1 && e.key !== ' ') { e.preventDefault(); open(); jump(e.key); }
    });
    /* a <label for> click or the browser's "please fill in this field" lands on the hidden select: pass it to the button */
    sel.addEventListener('focus', function () { try { btn.focus(); } catch (e) {} });
    sel.addEventListener('change', read);
    sel.addEventListener('invalid', function () { try { btn.focus(); } catch (e) {} });

    /* the page's own code sets sel.value / sel.selectedIndex without an event: keep the button in step */
    var proto = win.HTMLSelectElement.prototype, dV = Object.getOwnPropertyDescriptor(proto, 'value'), dI = Object.getOwnPropertyDescriptor(proto, 'selectedIndex');
    var nativeIndex = dI.set;
    try {
      Object.defineProperty(sel, 'value', { configurable: true, get: function () { return dV.get.call(sel); }, set: function (v) { dV.set.call(sel, v); read(); } });
      Object.defineProperty(sel, 'selectedIndex', { configurable: true, get: function () { return dI.get.call(sel); }, set: function (v) { dI.set.call(sel, v); read(); } });
    } catch (e) {}
    /* options added, removed or re-labelled, and disabled / hidden / style changes */
    if (win.MutationObserver) new win.MutationObserver(function () { read(); }).observe(sel, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['disabled', 'hidden', 'style', 'label', 'selected', 'aria-label'] });
    sel.__opsh = { refresh: read, open: open, close: close, button: btn, wrap: wrap };
    read();
  }

  function scan(root) {
    root = root || document;
    if (root.tagName === 'SELECT') { enhance(root); return; }
    if (root.querySelectorAll) Array.prototype.forEach.call(root.querySelectorAll('select'), enhance);
  }
  /* a select the page removed takes its button with it; one the page moved gets its button back in front of it */
  function follow(sel) {
    var o = sel.__opsh; if (!o || !o.wrap) return;
    if (!sel.isConnected) { if (o.wrap.parentNode) o.wrap.parentNode.removeChild(o.wrap); }
    else if (o.wrap.nextSibling !== sel) sel.parentNode.insertBefore(o.wrap, sel);
  }
  function each(n, fn) { if (n.nodeType !== 1) return; if (n.tagName === 'SELECT') fn(n); else if (n.querySelectorAll) Array.prototype.forEach.call(n.querySelectorAll('select'), fn); }
  function start() {
    scan(document);
    if (window.MutationObserver && document.body) new MutationObserver(function (muts) {
      muts.forEach(function (m) {
        Array.prototype.forEach.call(m.removedNodes, function (n) { each(n, follow); });
        Array.prototype.forEach.call(m.addedNodes, function (n) { if (n.nodeType === 1 && !(n.classList && (n.classList.contains('sel-menu') || n.classList.contains('sel')))) each(n, function (x) { if (x.__opsh) follow(x); else enhance(x); }); });
      });
    }).observe(document.body, { childList: true, subtree: true });
  }
  window.opshSelect = { scan: scan, refresh: function (sel) { if (sel && sel.__opsh) sel.__opsh.refresh(); else scan(sel); } };
  /* index.html sets OPSH_SELECT_MANUAL: the app draws its own dropdowns and only calls scan() for the pop-out editor */
  if (window.OPSH_SELECT_MANUAL) return;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
