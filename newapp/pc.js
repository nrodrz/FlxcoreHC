/* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/* ITOps BT134 — the script of pc.html: protect a personal computer with Microsoft Defender, by choice.
   A file of its own: the site's Content-Security-Policy allows no inline script. Everything shown is built with
   textContent, never as HTML from the server. */
(function () {
  var API = location.pathname.replace(/[^/]*$/, '') + 'api/public_pc';
  var T = (new URLSearchParams(location.search).get('t') || '').toLowerCase();
  var app = document.getElementById('app'); var D = null; var poll = null; var showMac = false;
  function el(tag, attrs, kids) {
    var e = document.createElement(tag); attrs = attrs || {};
    Object.keys(attrs).forEach(function (k) { if (k === 'text') e.textContent = attrs[k]; else if (k === 'on') Object.keys(attrs.on).forEach(function (ev) { e.addEventListener(ev, attrs.on[ev]); }); else e.setAttribute(k, attrs[k]); });
    (kids || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  function when(s) { if (!s) return ''; var d = new Date(String(s).replace(' ', 'T')); return isNaN(d) ? String(s) : d.toLocaleString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric', hour: 'numeric', minute: '2-digit' }); }
  function req(method, path, body) {
    return fetch(API + path + (path.indexOf('?') >= 0 ? '&' : '?') + 't=' + encodeURIComponent(T), { method: method, headers: body ? { 'Content-Type': 'application/json' } : {}, body: body ? JSON.stringify(body) : undefined })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) throw new Error((j.error && j.error.message) || 'Something went wrong.'); return j; }); });
  }
  function stateView(icon, title, text) { app.innerHTML = ''; app.appendChild(el('div', { class: 'card' }, [el('div', { class: 'state' }, [el('div', { class: 'big', text: icon }), el('h1', { text: title }), el('div', { class: 'sub', text: text })])])); }
  if (!/^[a-f0-9]{48}$/.test(T)) { stateView('⚠️', 'Link not complete', 'Open the link again from your email.'); return; }

  function load() { return req('GET', '').then(function (d) { D = d; render(); }).catch(function (e) { stateView('⚠️', 'Link not valid', e.message); }); }

  function render() {
    app.innerHTML = '';
    var org = D.org || 'your organization';
    app.appendChild(el('div', { class: 'card' }, [el('h1', { text: 'Protect the computer you use for work' }), el('div', { class: 'sub', text: (D.name ? D.name + ' · ' : '') + D.email + ' · offered by ' + org + ' · your choice' })]));
    if (D.state === 'withdrawn') { app.appendChild(withdrawnCard()); return; }
    app.appendChild(consentCard());
    if (D.state !== 'consented') return;
    app.appendChild(checkCard());
    if (D.report) app.appendChild(nextCard());
    app.appendChild(macCard());
    app.appendChild(withdrawCard());
  }

  function consentCard() {
    var c = el('div', { class: 'card', 'data-pc-consent': '1' }, [el('span', { class: 'step', text: 'Step 1' }), el('h2', { text: 'Read what it means' }), el('div', { class: 'doc', text: D.consent })]);
    if (D.agreed) { c.appendChild(el('p', { class: 'ok', 'data-pc-agreed': '1', text: '✓ You agreed on ' + when(D.agreed.at) + ' (typed: ' + D.agreed.name + ').' })); return c; }
    var ck = el('input', { type: 'checkbox', id: 'pcAgree' }); var nm = el('input', { type: 'text', id: 'pcName', placeholder: 'Your full name', maxlength: '120', autocomplete: 'name' });
    var err = el('div', { class: 'err' }); var btn = el('button', { class: 'btn', 'data-pc-agree': '1', text: 'I agree' });
    var upd = function () { btn.disabled = !(ck.checked && nm.value.trim().length >= 3); }; ck.addEventListener('change', upd); nm.addEventListener('input', upd); upd();
    btn.addEventListener('click', function () { btn.disabled = true; err.textContent = ''; req('POST', '', { action: 'consent', agree: true, name: nm.value.trim() }).then(function (d) { D = d; render(); }).catch(function (e) { err.textContent = e.message; upd(); }); });
    c.appendChild(el('label', { class: 'agree' }, [ck, el('span', { text: 'I have read this and I choose to protect my computer this way.' })]));
    c.appendChild(nm); c.appendChild(el('div', { class: 'row' }, [btn])); c.appendChild(err);
    c.appendChild(el('div', { class: 'note', text: 'Not now? Just close this page. Nothing happens unless you agree.' }));
    return c;
  }

  function checkCard() {
    var base = location.origin + location.pathname.replace(/[^/]*$/, '');
    var line = "irm '" + base + 'api/public_pc/script?t=' + T + "' | iex";
    var c = el('div', { class: 'card', 'data-pc-check': '1' }, [el('span', { class: 'step', text: 'Step 2 · Windows' }), el('h2', { text: D.report ? 'Your computer (check it again any time)' : 'Check your computer' })]);
    c.appendChild(el('ol', {}, [el('li', { text: 'Click Start, type PowerShell and open "Windows PowerShell" (not as administrator).' }), el('li', { text: 'Copy the line below, paste it in the blue window (right-click pastes) and press Enter.' }), el('li', { text: 'Come back here: the result appears below within a few seconds.' })]));
    var code = el('code', { class: 'cmd', 'data-pc-cmd': '1', text: line }); c.appendChild(code);
    var copy = el('button', { class: 'btn ghost', text: 'Copy the line' }); copy.addEventListener('click', function () { try { navigator.clipboard.writeText(line); copy.textContent = 'Copied'; } catch (e) { copy.textContent = 'Select it and copy'; } });
    var see = el('button', { class: 'btn ghost', 'data-pc-see': '1', text: 'See what it does' }); var pre = el('pre', { class: 'script', style: 'display:none' });
    see.addEventListener('click', function () { if (pre.style.display === 'none') { pre.style.display = 'block'; pre.textContent = 'Loading…'; fetch(API + '/script?t=' + T).then(function (r) { return r.text(); }).then(function (t) { pre.textContent = t; }); } else pre.style.display = 'none'; });
    c.appendChild(el('div', { class: 'row' }, [copy, see])); c.appendChild(pre);
    c.appendChild(el('div', { class: 'note', text: 'It only reads: your Windows edition, whether Microsoft Defender and its real-time protection are on, how old its definitions are, which antivirus Windows lists, and the computer\'s name. It changes nothing and reads no files.' }));
    if (D.report) c.appendChild(reportView(D.report, D.reported_at, D.problems));
    else { var w = el('p', { class: 'sub', 'data-pc-waiting': '1', text: 'Waiting for the check…' }); c.appendChild(w); startPoll(); }
    return c;
  }
  function startPoll() { if (poll) return; var n = 0; poll = setInterval(function () { n++; if (n > 150) { clearInterval(poll); poll = null; return; } req('GET', '').then(function (d) { if (d.reported_at && (!D.reported_at || d.reported_at !== D.reported_at)) { clearInterval(poll); poll = null; D = d; render(); } }).catch(function () {}); }, 4000); }

  function yes(v, on, off) { return v === true ? el('span', { class: 'ok', text: on }) : v === false ? el('span', { class: 'bad', text: off }) : el('span', { class: 'sub', text: 'unknown' }); }
  function reportView(r, at, problems) {
    var d = r.defender || {};
    var box = el('div', { 'data-pc-report': '1' }, [el('h2', { text: r.computer + ' · ' + (r.os || 'Windows') }), el('div', { class: 'sub', text: 'Checked ' + when(at) })]);
    box.appendChild(el('div', { class: 'facts' }, [
      el('div', {}, ['Microsoft Defender Antivirus: ', yes(d.antivirus, 'on', 'off')]), el('div', {}, ['Real-time protection: ', yes(d.realtime, 'on', 'off')]),
      el('div', {}, ['Tamper protection: ', yes(d.tamper, 'on', 'off')]), el('div', {}, ['Definitions: ', d.signature_age_days == null ? el('span', { class: 'sub', text: 'unknown' }) : (d.signature_age_days <= 3 ? el('span', { class: 'ok', text: 'current' }) : el('span', { class: 'bad', text: d.signature_age_days + ' days old' }))]),
      el('div', {}, ['Connected to your organization\'s Defender: ', yes(r.onboarded, 'yes', 'not yet')])]));
    if ((r.products || []).length) box.appendChild(el('div', { class: 'sub', text: 'Antivirus Windows lists: ' + r.products.map(function (p) { return p.name + (p.on === true ? ' (on)' : p.on === false ? ' (off)' : ''); }).join(', ') }));
    if ((problems || []).length) box.appendChild(el('p', { class: 'warn', 'data-pc-problems': String(problems.length), text: 'To fix: ' + problems.join(' · ') }));
    else box.appendChild(el('p', { class: 'ok', 'data-pc-problems': '0', text: 'Microsoft Defender is protecting this computer.' }));
    return box;
  }

  function nextCard() {
    var c = el('div', { class: 'card', 'data-pc-next': D.path || 'unknown' }, [el('span', { class: 'step', text: 'Step 3' })]);
    if (D.path === 'pro') {
      c.appendChild(el('h2', { text: D.report.onboarded ? 'Connected: you are done' : 'Connect your computer to Defender' }));
      if (D.report.onboarded) { c.appendChild(el('p', { text: 'Your computer is connected. ' + (D.org || 'Your organization') + ' keeps Defender\'s settings on it from now on. Nothing else to do.' })); return c; }
      if (!D.offer.win_on) { c.appendChild(el('p', { 'data-pc-nofile': '1', text: 'IT has not added the connection file yet. You will get an email when it is ready; this page will then show it here.' })); return c; }
      c.appendChild(el('ol', {}, [el('li', { text: 'Download the file below and open it (it is a .zip). Copy the file inside it to your Desktop.' }),
        el('li', { text: 'Right-click that file and choose "Run as administrator". Answer Yes, then type Y and press Enter when it asks.' }),
        el('li', { text: 'Wait a minute, then run the check again (Step 2). It should say "Connected".' })]));
      c.appendChild(el('div', { class: 'row' }, [el('a', { class: 'btn', 'data-pc-get': 'win_on', href: API + '/package?k=win_on&t=' + T, text: 'Download the connection file' })]));
      c.appendChild(el('div', { class: 'note', text: 'This file connects only this computer to your organization\'s Microsoft Defender. Do not share it.' }));
      return c;
    }
    if (D.path === 'home') {
      c.appendChild(el('h2', { text: 'Windows Home: keep the Defender already in Windows on' }));
      c.appendChild(el('p', { text: 'Windows Home cannot be connected to your organization\'s Defender (Microsoft allows it only on Windows Pro, Enterprise and Education). The Microsoft Defender that comes with Windows still protects you; make sure it is on:' }));
      c.appendChild(el('ol', {}, [el('li', { text: 'Click Start, type "Windows Security" and open it. Choose "Virus & threat protection".' }),
        el('li', { text: 'If it shows "Turn on" or a warning, click it. Under "Virus & threat protection settings", choose "Manage settings" and turn on Real-time protection, Cloud-delivered protection and Tamper Protection.' }),
        el('li', { text: 'If another antivirus is listed that you do not pay for any more (an expired trial), remove it: Settings → Apps → Installed apps → that antivirus → Uninstall. Windows turns Defender back on.' }),
        el('li', { text: 'Under "Virus & threat protection updates", click "Check for updates".' }),
        el('li', { text: 'Run the check again (Step 2) so IT can see it is done.' })]));
      c.appendChild(el('div', { class: 'note', text: 'For work, use Microsoft Edge and the Microsoft 365 apps. Upgrading to Windows Pro would allow full protection by your organization.' }));
      return c;
    }
    c.appendChild(el('h2', { text: 'We could not tell your Windows edition' })); c.appendChild(el('p', { text: 'Run the check again. If this stays, reply to the invitation email and IT will help.' }));
    return c;
  }

  function macCard() {
    var c = el('div', { class: 'card', 'data-pc-mac': '1' });
    var t = el('button', { class: 'btn ghost', text: showMac ? 'Hide the Mac steps' : 'I use a Mac' }); t.addEventListener('click', function () { showMac = !showMac; render(); });
    c.appendChild(t);
    if (!showMac) return c;
    c.appendChild(el('h2', { text: 'Mac', style: 'margin-top:12px' }));
    if (!D.offer.mac_on) { c.appendChild(el('p', { text: 'IT has not added the Mac connection file yet. Reply to the invitation email and IT will send you the steps.' })); return c; }
    c.appendChild(el('ol', {}, [el('li', { text: 'IT gives you the Microsoft Defender app for Mac first; install it.' }), el('li', { text: 'Download the connection file below, unzip it, and follow the instructions IT gave you to run it (it needs your Mac password).' }), el('li', { text: 'Open Microsoft Defender on the Mac: it should show that your organization manages it.' })]));
    c.appendChild(el('div', { class: 'row' }, [el('a', { class: 'btn', 'data-pc-get': 'mac_on', href: API + '/package?k=mac_on&t=' + T, text: 'Download the Mac connection file' })]));
    return c;
  }

  function withdrawCard() {
    var c = el('div', { class: 'card', 'data-pc-withdraw': '1' }, [el('h2', { text: 'Changed your mind?' }), el('p', { class: 'sub', text: 'You can withdraw at any time. IT then gives you the file that disconnects your computer from Defender.' })]);
    var note = el('textarea', { rows: '2', maxlength: '300', placeholder: 'Anything IT should know (optional)' }); var err = el('div', { class: 'err' });
    var b = el('button', { class: 'btn ghost', 'data-pc-withdraw-go': '1', text: 'Withdraw' });
    b.addEventListener('click', function () { if (b.getAttribute('data-sure') !== '1') { b.setAttribute('data-sure', '1'); b.textContent = 'Click again to withdraw'; return; }
      b.disabled = true; req('POST', '', { action: 'withdraw', note: note.value }).then(function (d) { D = d; render(); }).catch(function (e) { err.textContent = e.message; b.disabled = false; }); });
    c.appendChild(note); c.appendChild(el('div', { class: 'row' }, [b])); c.appendChild(err);
    return c;
  }
  function withdrawnCard() {
    var c = el('div', { class: 'card', 'data-pc-withdrawn': '1' }, [el('h2', { text: 'You withdrew' }), el('p', { text: 'Thank you for letting us know. Microsoft Defender Antivirus stays on your computer as part of Windows.' })]);
    if (D.offer.win_off) {
      c.appendChild(el('ol', {}, [el('li', { text: 'Download the file below and open it (a .zip). Copy the file inside to your Desktop.' }), el('li', { text: 'Right-click it, choose "Run as administrator", answer Yes and type Y when it asks.' }), el('li', { text: 'Your computer is no longer connected to your organization\'s Defender.' })]));
      c.appendChild(el('div', { class: 'row' }, [el('a', { class: 'btn', 'data-pc-get': 'win_off', href: API + '/package?k=win_off&t=' + T, text: 'Download the disconnect file' })]));
    } else c.appendChild(el('p', { text: 'IT will send you the file that disconnects your computer.' }));
    return c;
  }
  load();
})();
