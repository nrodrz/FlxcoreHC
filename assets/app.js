/* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/* FlxCoreHC UI runtime — theme, drawer, toasts, modal-confirm, custom selects, validation.
   Vanilla, no dependencies, no build step. */
(function () {
  'use strict';
  var d = document, root = d.documentElement;

  /* ---------- theme (light/dark), persisted in a cookie so PHP can pre-render ---------- */
  function setCookie(k, v) { try { d.cookie = k + '=' + v + ';path=/;max-age=31536000;samesite=lax'; } catch (e) {} }
  function applyTheme(t) { root.setAttribute('data-theme', t); setCookie('flx_theme', t); updateThemeIcons(t); }
  function updateThemeIcons(t) {
    d.querySelectorAll('[data-theme-label]').forEach(function (el) { el.textContent = t === 'dark' ? 'Light mode' : 'Dark mode'; });
  }
  window.flxToggleTheme = function () {
    applyTheme(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
  };

  /* ---------- toasts ---------- */
  var ICON = {
    ok: '<path d="M20 6L9 17l-5-5"/>', error: '<path d="M18 6L6 18M6 6l12 12"/>',
    warn: '<path d="M12 9v4M12 17h.01M10.3 3.9L2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L14 3.9a2 2 0 0 0-3.4 0z"/>',
    info: '<path d="M12 8h.01M11 12h1v4h1"/><circle cx="12" cy="12" r="9"/>'
  };
  function toastHost() { var h = d.getElementById('toasts'); if (!h) { h = d.createElement('div'); h.id = 'toasts'; d.body.appendChild(h); } return h; }
  window.flxToast = function (msg, type, ttl) {
    type = type || 'info';
    var t = d.createElement('div'); t.className = 'toast ' + type;
    t.innerHTML = '<svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
      (ICON[type] || ICON.info) + '</svg><div class="tx"></div><button class="tc" aria-label="Dismiss">&times;</button>';
    t.querySelector('.tx').textContent = msg;
    function close() { t.classList.add('hide'); setTimeout(function () { t.remove(); }, 220); }
    t.querySelector('.tc').addEventListener('click', close);
    toastHost().appendChild(t);
    setTimeout(close, ttl || 5000);
  };

  /* ---------- confirm modal (replaces window.confirm for data-confirm) ---------- */
  var pendingConfirm = null;
  function buildModal() {
    var s = d.createElement('div'); s.className = 'modal-scrim'; s.id = 'flx-confirm';
    s.innerHTML = '<div class="modal" role="dialog" aria-modal="true"><h3 id="flx-cf-title">Please confirm</h3>' +
      '<p id="flx-cf-msg"></p><div class="modal-actions">' +
      '<button class="btn line" data-cf="no">Cancel</button><button class="btn" data-cf="yes">Confirm</button></div></div>';
    d.body.appendChild(s);
    s.addEventListener('click', function (e) { if (e.target === s || e.target.getAttribute('data-cf') === 'no') closeConfirm(false); });
    s.querySelector('[data-cf="yes"]').addEventListener('click', function () { closeConfirm(true); });
    return s;
  }
  function closeConfirm(ok) {
    var s = d.getElementById('flx-confirm'); if (s) s.classList.remove('open');
    var cb = pendingConfirm; pendingConfirm = null;
    if (cb) cb(ok);
  }
  window.flxConfirm = function (msg, cb, opts) {
    opts = opts || {};
    var s = d.getElementById('flx-confirm') || buildModal();
    s.querySelector('#flx-cf-msg').textContent = msg;
    s.querySelector('#flx-cf-title').textContent = opts.title || 'Please confirm';
    var yes = s.querySelector('[data-cf="yes"]');
    yes.textContent = opts.confirmText || 'Confirm';
    yes.className = 'btn' + (opts.danger ? ' danger' : '');
    pendingConfirm = cb; s.classList.add('open');
  };

  /* ---------- form validation (styled, replaces native bubbles) ---------- */
  function clearErr(el) { el.classList.remove('invalid'); var n = el.parentNode.querySelector('.field-err'); if (n) n.remove(); }
  function showErr(el, msg) {
    el.classList.add('invalid');
    if (!el.parentNode.querySelector('.field-err')) {
      var s = d.createElement('span'); s.className = 'field-err'; s.textContent = msg; el.parentNode.appendChild(s);
    }
  }
  function validateField(el) {
    if (el.disabled || el.type === 'hidden') return true;
    var v = (el.value || '').trim();
    if (el.hasAttribute('required') && v === '') { showErr(el, 'This field is required.'); return false; }
    if (el.type === 'email' && v !== '' && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v)) { showErr(el, 'Enter a valid email address.'); return false; }
    if (el.hasAttribute('minlength') && v !== '' && v.length < +el.getAttribute('minlength')) { showErr(el, 'Use at least ' + el.getAttribute('minlength') + ' characters.'); return false; }
    clearErr(el); return true;
  }
  function wireValidation() {
    d.querySelectorAll('form').forEach(function (f) {
      if (f.hasAttribute('data-novalidate') || f.__flxv) return; f.__flxv = true;
      f.setAttribute('novalidate', 'novalidate');
      f.addEventListener('submit', function (e) {
        var ok = true, first = null;
        f.querySelectorAll('input,select,textarea').forEach(function (el) { if (!validateField(el)) { ok = false; first = first || el; } });
        if (!ok) { e.preventDefault(); if (first) first.focus(); window.flxToast('Please fix the highlighted fields.', 'warn'); }
      });
      f.querySelectorAll('input,select,textarea').forEach(function (el) {
        el.addEventListener('blur', function () { validateField(el); });
        el.addEventListener('input', function () { if (el.classList.contains('invalid')) validateField(el); });
      });
    });
  }

  /* Entrance animations retain a composited transform under fill-mode:both, which turns
     every animated panel into a containing block for position:fixed descendants (breaking
     dropdown popups). Once an entrance animation finishes, clear it — the element is
     already at its final state, so nothing changes visually. */
  d.addEventListener('animationend', function (e) {
    if (e.animationName === 'rise' || e.animationName === 'fadein') { e.target.style.animation = 'none'; }
  }, true);

  /* ---------- custom select (progressive enhancement of native <select>) ---------- */
  function enhanceSelect(sel) {
    if (sel.__x || sel.multiple || sel.dataset.noenhance !== undefined) return; sel.__x = true;
    var wrap = d.createElement('div'); wrap.className = 'xselect';
    sel.parentNode.insertBefore(wrap, sel); wrap.appendChild(sel);
    var btn = d.createElement('button'); btn.type = 'button'; btn.className = 'xs-btn';
    btn.innerHTML = '<span class="xs-txt"></span><svg class="xs-car" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
    var pop = d.createElement('div'); pop.className = 'xs-pop';
    wrap.appendChild(btn); wrap.appendChild(pop);
    function render() {
      pop.innerHTML = '';
      Array.prototype.forEach.call(sel.options, function (o, i) {
        var it = d.createElement('div'); it.className = 'xs-opt' + (i === sel.selectedIndex ? ' sel' : '');
        it.textContent = o.text; it.setAttribute('data-i', i);
        it.addEventListener('click', function () { sel.selectedIndex = i; sync(); close(); sel.dispatchEvent(new Event('change', { bubbles: true })); });
        pop.appendChild(it);
      });
    }
    function sync() { var o = sel.options[sel.selectedIndex]; btn.querySelector('.xs-txt').textContent = o ? o.text : ''; }
    function place() {
      // Fixed positioning from the button's rect: escapes table overflow clipping and
      // per-row stacking contexts (the cause of dropdowns rendering under later rows).
      var r = btn.getBoundingClientRect();
      pop.style.position = 'fixed';
      pop.style.left = r.left + 'px';
      pop.style.width = Math.max(r.width, 160) + 'px';
      pop.style.right = 'auto';
      var h = Math.min(pop.scrollHeight || 240, 260);
      if (r.bottom + h + 12 > window.innerHeight && r.top - h - 12 > 0) {
        pop.style.top = (r.top - h - 6) + 'px';          // flip upward near the bottom edge
      } else {
        pop.style.top = (r.bottom + 6) + 'px';
      }
    }
    var hi = -1;                                   // highlighted row while the list is open
    function highlight(i) {
      var rows = pop.querySelectorAll('.xs-opt');
      if (!rows.length) return;
      i = Math.max(0, Math.min(rows.length - 1, i)); hi = i;
      rows.forEach(function (r, k) { r.classList.toggle('hl', k === i); });
      var r = rows[i]; if (r && r.scrollIntoView) r.scrollIntoView({ block: 'nearest' });
    }
    function onScroll(e) {
      // Scrolling INSIDE the list must not close it; scrolling the page just re-anchors the popup.
      if (pop.contains(e.target)) return;
      place();
    }
    function open() {
      render(); wrap.classList.add('open'); place(); highlight(sel.selectedIndex);
      setTimeout(function () { d.addEventListener('click', outside); }, 0);
      window.addEventListener('scroll', onScroll, { passive: true, capture: true });
      window.addEventListener('resize', place, { passive: true });
    }
    function close() {
      wrap.classList.remove('open'); hi = -1;
      d.removeEventListener('click', outside);
      window.removeEventListener('scroll', onScroll, { capture: true });
      window.removeEventListener('resize', place);
    }
    function outside(e) { if (!wrap.contains(e.target)) close(); }
    function choose(i) {
      if (i < 0 || i >= sel.options.length) return;
      sel.selectedIndex = i; sync(); close(); sel.dispatchEvent(new Event('change', { bubbles: true }));
    }
    btn.addEventListener('click', function (e) { e.stopPropagation(); wrap.classList.contains('open') ? close() : open(); });
    btn.addEventListener('keydown', function (e) {
      var isOpen = wrap.classList.contains('open');
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!isOpen) { open(); return; }                       // arrows open the list first
        highlight((hi < 0 ? sel.selectedIndex : hi) + (e.key === 'ArrowDown' ? 1 : -1));
      } else if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        if (!isOpen) open(); else choose(hi < 0 ? sel.selectedIndex : hi);
      } else if (e.key === 'Escape') { close(); }
      else if (e.key === 'Home' && isOpen) { e.preventDefault(); highlight(0); }
      else if (e.key === 'End' && isOpen) { e.preventDefault(); highlight(sel.options.length - 1); }
    });
    // Hovering a row highlights it (mouse and keyboard share one cursor).
    pop.addEventListener('mousemove', function (e) {
      var r = e.target.closest && e.target.closest('.xs-opt'); if (!r) return;
      var i = +r.getAttribute('data-i'); if (i !== hi) highlight(i);
    });
    sel.addEventListener('change', sync);
    sync();
  }
  function wireSelects() { d.querySelectorAll('select').forEach(enhanceSelect); }

  /* ---------- dropdown menus (profile/theme) ---------- */
  function wireMenus() {
    d.querySelectorAll('.menu > [data-menu-btn]').forEach(function (btn) {
      if (btn.__m) return; btn.__m = true;
      btn.addEventListener('click', function (e) {
        e.stopPropagation(); var m = btn.closest('.menu');
        d.querySelectorAll('.menu.open').forEach(function (o) { if (o !== m) o.classList.remove('open'); });
        m.classList.toggle('open');
      });
    });
    d.addEventListener('click', function () { d.querySelectorAll('.menu.open').forEach(function (m) { m.classList.remove('open'); }); });
  }

  /* ---------- nav collapse + mobile drawer ---------- */
  function wireNav() {
    var shell = d.getElementById('shell'); if (!shell) return;
    try { if (localStorage.getItem('flx_nav') === 'collapsed') shell.classList.add('nav-collapsed'); } catch (e) {}
    var cb = d.getElementById('collapseBtn');
    cb && cb.addEventListener('click', function () {
      shell.classList.toggle('nav-collapsed');
      try { localStorage.setItem('flx_nav', shell.classList.contains('nav-collapsed') ? 'collapsed' : 'open'); } catch (e) {}
    });
    var burg = d.getElementById('hamburger');
    burg && burg.addEventListener('click', function () { shell.classList.toggle('nav-open'); });
    var scrim = shell.querySelector('.scrim');
    scrim && scrim.addEventListener('click', function () { shell.classList.remove('nav-open'); });
    shell.querySelectorAll('.side .nav-i').forEach(function (a) {
      a.addEventListener('click', function () { if (window.innerWidth <= 900) shell.classList.remove('nav-open'); });
    });
    wireAccordion(shell);
    wireNavTips(shell);
  }

  /* accordion (expanded) + flyout (collapsed): one group open at a time */
  function wireAccordion(shell) {
    var groups = shell.querySelectorAll('.nav-group[data-group]');
    var saved = null; try { saved = localStorage.getItem('flx_navgroup'); } catch (e) {}
    groups.forEach(function (g) { if (g.getAttribute('data-group') === saved) g.classList.add('open'); });

    var fly = document.getElementById('flx-navfly');
    if (!fly) { fly = document.createElement('div'); fly.id = 'flx-navfly'; fly.className = 'nav-flyout'; document.body.appendChild(fly); }

    function closeFlyout() {
      fly.classList.remove('show');
      groups.forEach(function (o) { o.classList.remove('flyout-open'); });
    }
    function openFlyout(g) {
      var btn = g.querySelector('[data-navtoggle]');
      var items = g.querySelector('.ng-items');
      if (!items) return;
      var label = (g.querySelector('.ng-label') || {}).textContent || '';
      // Rebuild flyout contents from this group's items (clone the links).
      fly.innerHTML = '<div class="ng-fly-head">' + label + '</div>';
      items.querySelectorAll('.nav-i').forEach(function (a) {
        var c = a.cloneNode(true);
        c.addEventListener('click', function () { closeFlyout(); if (window.innerWidth <= 900) shell.classList.remove('nav-open'); });
        fly.appendChild(c);
      });
      // Position beside the rail, aligned to the clicked icon, kept on-screen.
      var r = btn.getBoundingClientRect();
      // Sit flush against the sidebar's right edge so it reads as part of the menu, not a
      // floating card offset from the icon.
      var side = shell.querySelector('.side');
      var railRight = side ? side.getBoundingClientRect().right : r.right;
      fly.style.left = railRight + 'px';
      fly.classList.add('show');
      var fh = fly.getBoundingClientRect().height;
      var top = Math.min(r.top, window.innerHeight - fh - 12);
      fly.style.top = Math.max(12, top) + 'px';
      groups.forEach(function (o) { o.classList.remove('flyout-open'); });
      g.classList.add('flyout-open');
    }

    shell.querySelectorAll('[data-navtoggle]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        var g = btn.closest('.nav-group');
        var collapsed = shell.classList.contains('nav-collapsed') && window.innerWidth > 900;
        if (collapsed) {
          e.stopPropagation();
          if (g.classList.contains('flyout-open')) { closeFlyout(); }
          else { openFlyout(g); }
          return;
        }
        // expanded accordion
        var willOpen = !g.classList.contains('open');
        groups.forEach(function (o) { o.classList.remove('open'); });
        if (willOpen) g.classList.add('open');
        try { localStorage.setItem('flx_navgroup', willOpen ? g.getAttribute('data-group') : ''); } catch (e2) {}
      });
    });

    // Dismiss the flyout on outside click, Escape, scroll, or collapse toggle.
    document.addEventListener('click', function (e) {
      if (fly.classList.contains('show') && !fly.contains(e.target) && !e.target.closest('[data-navtoggle]')) closeFlyout();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeFlyout(); });
    window.addEventListener('scroll', closeFlyout, { passive: true, capture: true });
    var cb = document.getElementById('collapseBtn');
    cb && cb.addEventListener('click', closeFlyout);
  }

  /* collapsed-rail tooltips: fixed-position element so overflow never clips them */
  function wireNavTips(shell) {
    var tip = document.getElementById('flx-navtip');
    if (!tip) { tip = document.createElement('div'); tip.id = 'flx-navtip'; document.body.appendChild(tip); }
    function show(el) {
      if (!shell.classList.contains('nav-collapsed') || window.innerWidth <= 900) return;
      var label = el.getAttribute('data-tip'); if (!label) return;
      tip.textContent = label;
      var r = el.getBoundingClientRect();
      tip.style.top = (r.top + r.height / 2) + 'px';
      tip.style.left = (r.right + 12) + 'px';
      tip.style.transform = 'translateY(-50%)';
      tip.classList.add('show');
    }
    function hide() { tip.classList.remove('show'); }
    shell.querySelectorAll('.side [data-tip]').forEach(function (el) {
      el.addEventListener('mouseenter', function () { show(el); });
      el.addEventListener('mouseleave', hide);
    });
    var cb = document.getElementById('collapseBtn');
    cb && cb.addEventListener('click', hide);
  }

  /* ---------- delegate: data-confirm on forms & links ---------- */
  function wireConfirms() {
    d.addEventListener('submit', function (e) {
      var f = e.target, msg = f.getAttribute && f.getAttribute('data-confirm');
      if (!msg || f.__cfok) return;
      e.preventDefault();
      window.flxConfirm(msg, function (ok) { if (ok) { f.__cfok = true; f.submit(); } }, { danger: f.hasAttribute('data-confirm-danger'), confirmText: f.getAttribute('data-confirm-btn') || 'Confirm' });
    }, true);
  }

  /* ---------- delegated behaviours (v1.172.0) ----------
     The Content-Security-Policy forbids inline event handlers (onclick="…", onchange="…"): the browser ignores them.
     Views therefore declare behaviour with data-attributes, and this one script (served with the nonce) acts on them. */
  function wireDataBehaviours() {
    d.addEventListener('change', function (e) {
      var t = e.target; if (!t || !t.getAttribute) return;
      if (t.hasAttribute('data-autosubmit') && t.form) { if (t.form.requestSubmit) t.form.requestSubmit(); else t.form.submit(); }
      var fill = t.getAttribute('data-fill');
      if (fill && t.form && t.form.elements[fill]) {
        var o = t.options ? t.options[t.selectedIndex] : null;
        t.form.elements[fill].value = (o && o.getAttribute('data-name')) || '';
      }
    });
    d.addEventListener('click', function (e) {
      var t = e.target && e.target.closest ? e.target.closest('[data-check-all],[data-print],[data-select-on-click],[data-copy]') : null;
      if (!t) return;
      if (t.hasAttribute('data-check-all')) { d.querySelectorAll(t.getAttribute('data-check-all')).forEach(function (c) { c.checked = t.checked; }); }
      if (t.hasAttribute('data-print')) { window.print(); }
      if (t.hasAttribute('data-select-on-click') && t.select) { t.select(); }
      if (t.hasAttribute('data-copy')) {
        var txt = t.getAttribute('data-copy'), done = function () { t.textContent = t.getAttribute('data-copied') || 'Copied'; };
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(txt).then(done, function () {});
        else { var ta = d.createElement('textarea'); ta.value = txt; d.body.appendChild(ta); ta.select(); try { d.execCommand('copy'); done(); } catch (x) {} d.body.removeChild(ta); }
      }
    });
  }

  /* ---------- init ---------- */
  function init() {
    wireDataBehaviours();
    updateThemeIcons(root.getAttribute('data-theme') || 'light');
    // surface server flash messages as toasts
    d.querySelectorAll('[data-toast]').forEach(function (el) { window.flxToast(el.getAttribute('data-toast'), el.getAttribute('data-toast-type') || 'info'); });
    wireNav(); wireMenus(); wireConfirms(); wireValidation(); wireSelects();
  }
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', init); else init();
})();

/* ---- CSP-safe event delegation -------------------------------------------
   Inline on*="" handlers cannot run under a nonce-based Content-Security-Policy,
   so behaviour is declared with data-attributes and wired up here instead. */
(function () {
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-action]');
    if (!t) return;
    var a = t.getAttribute('data-action');
    if (a === 'theme-toggle') { if (window.flxToggleTheme) window.flxToggleTheme(); }
    else if (a === 'print') { window.print(); }
    else if (a === 'select-all') { t.select && t.select(); }
    else if (a === 'toggle') {
      var el = document.getElementById(t.getAttribute('data-target'));
      if (el) el.style.display = (el.style.display === 'none' ? '' : 'none');
    }
    else if (a === 'cal-new') { if (window.calNew) window.calNew(t.getAttribute('data-date') || undefined); }
    else if (a === 'cal-edit') { if (window.calEdit) window.calEdit(t.getAttribute('data-id')); }
    else if (a === 'cal-close') { if (window.calClose) window.calClose(); }
  });
  // Inputs marked data-action="select-all" should also select on focus/click.
  document.addEventListener('focusin', function (e) {
    if (e.target.matches && e.target.matches('[data-action="select-all"]')) e.target.select();
  });
  // <select data-autosubmit> replaces onchange="this.form.submit()".
  document.addEventListener('change', function (e) {
    if (e.target.matches && e.target.matches('[data-autosubmit]') && e.target.form) e.target.form.submit();
  });
})();

/* ---- Live theme preview ---------------------------------------------------
   Mirrors ThemeService (PHP) so the settings screen shows exactly what will be
   saved. Writes a single <style> block, so every token propagates app-wide —
   not just the handful of elements on the settings page. Nothing is persisted
   until the form is submitted. */
(function () {
  function rgb(h) { h = h.replace('#',''); return [0,2,4].map(function(i){ return parseInt(h.substr(i,2),16); }); }
  function lum(h) {
    return rgb(h).map(function(c){ c/=255; return c<=0.03928?c/12.92:Math.pow((c+0.055)/1.055,2.4); })
      .reduce(function(a,v,i){ return a + [0.2126,0.7152,0.0722][i]*v; }, 0);
  }
  function contrast(a,b){ var x=lum(a),y=lum(b),hi=Math.max(x,y),lo=Math.min(x,y); return (hi+0.05)/(lo+0.05); }
  function inkFor(bg){ return contrast(bg,'#ffffff') >= contrast(bg,'#111111') ? '#ffffff' : '#111111'; }
  // Lightness shift in HSL — keeps the hue instead of washing out toward grey.
  function shade(hex, delta) {
    var c = rgb(hex).map(function(v){ return v/255; });
    var max = Math.max.apply(null,c), min = Math.min.apply(null,c);
    var l = (max+min)/2, d = max-min;
    var s = d === 0 ? 0 : d/(1-Math.abs(2*l-1));
    var h = 0;
    if (d !== 0) {
      if (max === c[0]) h = 60*(((c[1]-c[2])/d)%6);
      else if (max === c[1]) h = 60*(((c[2]-c[0])/d)+2);
      else h = 60*(((c[0]-c[1])/d)+4);
    }
    if (h < 0) h += 360;
    l = Math.max(0, Math.min(1, l + delta/100));
    var cc = (1-Math.abs(2*l-1))*s, x = cc*(1-Math.abs((h/60)%2-1)), m = l-cc/2, r;
    if (h<60) r=[cc,x,0]; else if (h<120) r=[x,cc,0]; else if (h<180) r=[0,cc,x];
    else if (h<240) r=[0,x,cc]; else if (h<300) r=[x,0,cc]; else r=[cc,0,x];
    return '#' + r.map(function(v){ return ('0'+Math.round((v+m)*255).toString(16)).slice(-2); }).join('');
  }
  function toHsl(hex){
    var c = rgb(hex).map(function(v){ return v/255; });
    var max=Math.max.apply(null,c), min=Math.min.apply(null,c), l=(max+min)/2, d=max-min;
    var s = d===0?0:d/(1-Math.abs(2*l-1)), h=0;
    if (d!==0) {
      if (max===c[0]) h=60*(((c[1]-c[2])/d)%6);
      else if (max===c[1]) h=60*(((c[2]-c[0])/d)+2);
      else h=60*(((c[0]-c[1])/d)+4);
    }
    if (h<0) h+=360;
    return [h,s,l];
  }
  function fromHsl(h,s,l){
    var c=(1-Math.abs(2*l-1))*s, x=c*(1-Math.abs((h/60)%2-1)), m=l-c/2, r;
    if(h<60)r=[c,x,0]; else if(h<120)r=[x,c,0]; else if(h<180)r=[0,c,x];
    else if(h<240)r=[0,x,c]; else if(h<300)r=[x,0,c]; else r=[c,0,x];
    return '#'+r.map(function(v){ return ('0'+Math.round((v+m)*255).toString(16)).slice(-2); }).join('');
  }
  function tint(hex, lightness, satScale){
    var h=toHsl(hex);
    return fromHsl(h[0], Math.max(0,Math.min(1,h[1]*(satScale===undefined?1:satScale))), Math.max(0,Math.min(1,lightness)));
  }
  var RADII = { sharp:['6px','4px','10px'], soft:['16px','12px','22px'], round:['24px','18px','32px'] };

  /** Build the same CSS the server would generate for these choices. */
  function buildCss(o) {
    var root = [], dark = [];
    if (o.accent) {
      root.push('--accent:'+o.accent, '--accent-2:'+shade(o.accent,-8), '--accent-ink:'+inkFor(o.accent),
        '--accent-soft:color-mix(in srgb,'+o.accent+' 12%,#ffffff)',
        '--ring:0 0 0 3px color-mix(in srgb,'+o.accent+' 28%,transparent)');
      var lift = tint(o.accent, 0.62, 1.15);
      if (contrast(lift,'#0f1a2b') < 4.5) lift = tint(o.accent, 0.70, 1.15);
      dark.push('--accent:'+lift, '--accent-2:'+tint(o.accent,0.70,1.15), '--accent-ink:'+inkFor(lift),
        '--accent-soft:'+tint(o.accent,0.20,0.55),
        '--ring:0 0 0 3px color-mix(in srgb,'+lift+' 34%,transparent)');
    }
    // Surfaces carry the brand hue so the palette reaches the whole page, not just controls.
    var tintBase = o.nav || o.accent;
    if (tintBase) {
      dark.push('--bg:'+tint(tintBase,0.055,0.55), '--surface:'+tint(tintBase,0.105,0.42),
        '--surface-2:'+tint(tintBase,0.145,0.40), '--surface-3:'+tint(tintBase,0.195,0.38),
        '--panel:var(--surface)', '--panel-2:var(--surface-2)',
        '--line:'+tint(tintBase,0.245,0.34), '--line-2:'+tint(tintBase,0.185,0.32));
      root.push('--bg:'+tint(tintBase,0.925,0.30), '--surface-2:'+tint(tintBase,0.965,0.26),
        '--surface-3:'+tint(tintBase,0.925,0.24), '--line:'+tint(tintBase,0.865,0.22),
        '--line-2:'+tint(tintBase,0.925,0.20));
    }
    if (o.nav) {
      var navInk = inkFor(o.nav);
      root.push('--nav:'+o.nav, '--nav-grad-a:'+shade(o.nav,6), '--nav-grad-b:'+shade(o.nav,-4),
        '--nav-2:'+shade(o.nav,11), '--nav-3:'+shade(o.nav,18),
        '--nav-ink:'+(navInk==='#ffffff'?'rgba(255,255,255,.78)':'rgba(0,0,0,.72)'),
        '--nav-ink-2:'+(navInk==='#ffffff'?'rgba(255,255,255,.52)':'rgba(0,0,0,.5)'));
    }
    if (o.radius && RADII[o.radius]) {
      var v = RADII[o.radius];
      root.push('--r:'+v[0], '--r-sm:'+v[1], '--r-lg:'+v[2]);
    }
    var css = '';
    if (root.length) css += ':root{'+root.join(';')+'}';
    if (dark.length) css += '[data-theme="dark"]{'+dark.join(';')+'}';
    return css;
  }

  /** Apply a preview app-wide. Pass null values to fall back to the stock theme. */
  window.flxPreviewTheme = function (o) {
    var el = document.getElementById('flx-theme-preview');
    if (!el) {
      el = document.createElement('style');
      el.id = 'flx-theme-preview';
      // Carry the CSP nonce so the block is allowed even if style-src is tightened later.
      var src = document.querySelector('style[nonce], script[nonce]');
      if (src && src.nonce) el.nonce = src.nonce;
      document.head.appendChild(el);
    }
    el.textContent = buildCss(o || {});
  };
  window.flxThemeUtil = { inkFor: inkFor, contrast: contrast, shade: shade };
})();

/* ---- In-page tabs -------------------------------------------------------
   Declarative: a .tabbar with buttons carrying data-tab, and sibling
   .tabpane elements carrying data-pane. Deep links still work — a URL hash
   matching a pane (or an element inside one) opens that tab. */
(function () {
  function panesFor(bar) {
    var scope = bar.closest('[data-tabs]') || document;
    return scope.querySelectorAll('.tabpane');
  }
  function activate(bar, name, push) {
    var found = false;
    bar.querySelectorAll('[data-tab]').forEach(function (b) {
      var on = b.getAttribute('data-tab') === name;
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      if (on) found = true;
    });
    if (!found) return false;
    panesFor(bar).forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== name; });
    try {
      if (push) history.replaceState(null, '', '#' + name);
      sessionStorage.setItem('flx_tab_' + location.pathname, name);
    } catch (e) {}
    return true;
  }
  function init() {
    document.querySelectorAll('.tabbar').forEach(function (bar) {
      if (bar.__t) return; bar.__t = true;
      bar.addEventListener('click', function (e) {
        var b = e.target.closest('[data-tab]');
        if (!b) return;
        e.preventDefault();
        activate(bar, b.getAttribute('data-tab'), true);
      });
      var want = (location.hash || '').replace('#', '');
      // A hash may point at an element inside a pane rather than the pane itself.
      if (want) {
        var el = document.getElementById(want);
        var host = el && el.closest ? el.closest('.tabpane') : null;
        if (host) want = host.getAttribute('data-pane');
      }
      if (!want) { try { want = sessionStorage.getItem('flx_tab_' + location.pathname) || ''; } catch (e) {} }
      if (!want || !activate(bar, want, false)) {
        var first = bar.querySelector('[data-tab]');
        if (first) activate(bar, first.getAttribute('data-tab'), false);
      }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

/* ---------- responsive tables (mobile): wrap for scroll + stack into cards ---------- */
(function(){
  function prep(){
    document.querySelectorAll('main table').forEach(function(t){
      if (t.dataset.rtDone) return; t.dataset.rtDone = '1';
      if (!t.parentElement.classList.contains('table-wrap')) {
        var w = document.createElement('div'); w.className = 'table-wrap';
        t.parentNode.insertBefore(w, t); w.appendChild(t);
      }
      if (t.hasAttribute('data-no-stack')) return;
      // header labels: first row with <th>
      var hdr = t.querySelector('tr'); if (!hdr) return;
      var ths = hdr.querySelectorAll('th'); if (!ths.length) return;
      var labels = Array.prototype.map.call(ths, function(th){ return th.textContent.trim(); });
      // only stack if the table has real column headers (not layout tables / key-value tables)
      if (labels.filter(Boolean).length < 2) return;
      t.classList.add('stack');
      var rows = t.querySelectorAll('tr');
      for (var i = 0; i < rows.length; i++) {
        if (rows[i] === hdr) continue;
        var tds = rows[i].children;
        for (var j = 0; j < tds.length; j++) {
          if (tds[j].tagName === 'TD' && !tds[j].hasAttribute('data-label') && labels[j] !== undefined) tds[j].setAttribute('data-label', labels[j]);
        }
      }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', prep); else prep();
})();
