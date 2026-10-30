// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
// dev/check-app.mjs (local testing only): the starter app installs, signs in, shows only its modules, the removed APIs answer 404,
// every page opens without a script error or a server error
import { createRequire } from 'module'; const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
const path = require('path');
const { execFileSync, spawn } = require('child_process');
const HERE = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..'); const RUN = process.env.RUN || '/tmp/app-check-run'; const PORT = +(process.env.PORT || 8301); const DB = process.env.DB || 'app_check';
const KEEP = (process.env.KEEP || 'm365,onboarding,security_assessment,teams_app').split(',').sort(); const NAME = process.env.PRODUCT || 'ITOps'; const B = 'http://127.0.0.1:' + PORT + '/';
let P = 0, F = 0; const ok = (n, c, x) => { if (c) { P++; console.log('PASS', n); } else { F++; console.log('FAIL', n, x || ''); } };
execFileSync(path.join(HERE, 'dev', 'fresh-install.sh'), [RUN, String(PORT), DB], { stdio: 'inherit' }); await new Promise(r => setTimeout(r, 800));
const h = { 'X-ITOps-CSRF': '1', 'Content-Type': 'application/json' }; let ck = '';
const api = async (m, u, b) => { const r = await fetch(B + 'api' + u, { method: m, headers: Object.assign({ Cookie: ck }, h), body: b ? JSON.stringify(b) : undefined }); let j = null; try { j = await r.json(); } catch (e) {} return [r.status, j]; };
const br = await chromium.launch();
try {
  const t = await (await fetch(B + 'api/terms/text')).json(); const r = await fetch(B + 'api/auth/login', { method: 'POST', headers: h, body: JSON.stringify({ email: 'admin@example.com', password: 'Adm1n!Harb0ur#7' }) });
  ck = r.headers.getSetCookie().map(c => c.split(';')[0]).join('; '); await api('POST', '/terms/accept', { version: t.version, for_org: true, org_name: 'Example Org' });
  const [, lk] = await api('GET', '/lookups');
  ok('only the product modules are on: ' + JSON.stringify(lk.modules.on), JSON.stringify(lk.modules.on.sort()) === JSON.stringify(KEEP));
  const [ab, about] = await api('GET', '/about'); ok('About & ownership: new product, owner Indisse-BTS', ab === 200 && about.product === NAME && /Indisse-BTS/.test(JSON.stringify(about)) && about.product === NAME && !/OpsHub|NewApp/.test(about.product + about.product_line + about.developed_by), JSON.stringify(about).slice(0, 300));
  const gone = {}; for (const rt of ['tickets', 'kb', 'assets', 'guardian', 'server_perf', 'vtiger_users', 'budget', 'contracts', 'calendar', 'announcements', 'public_ticket']) gone[rt] = (await api('GET', '/' + rt))[0];
  ok('removed modules: their APIs answer 404', Object.values(gone).every(c => c === 404), JSON.stringify(gone));
  const kept = {}; for (const rt of ['m365hub/m365-domains', 'security_assessment', 'risk_users', 'onboarding/templates', 'settings', 'users', 'roles', 'audit']) kept[rt] = (await api('GET', '/' + rt))[0];
  ok('product and platform APIs answer (no 404 / 5xx): ' + JSON.stringify(kept), Object.values(kept).every(c => c !== 404 && c < 500), JSON.stringify(kept));
  const tbl = execFileSync('mysql', ['-uroot', '--socket=' + (process.env.DB_SOCKET || '/run/mysqld/mysqld.sock'), '-N', '-e', "SELECT table_name FROM information_schema.tables WHERE table_schema='" + DB + "'"]).toString().trim().split('\n');
  ok('database: every table (' + tbl.length + ') uses the itops_ prefix', tbl.length > 0 && tbl.every(t => t.startsWith('itops_')), tbl.filter(t => !t.startsWith('itops_')).join(' '));
  const mig = execFileSync('php', ['-r', 'require "config.php"; chdir("api"); require "lib/Migrations.php"; echo count(itops_migrations_list());'], { cwd: RUN }).toString().trim();
  const done = execFileSync('mysql', ['-uroot', '--socket=' + (process.env.DB_SOCKET || '/run/mysqld/mysqld.sock'), '-N', '-e', 'SELECT COUNT(*) FROM itops_migrations', DB]).toString().trim();
  ok('database: the installer applied every update (' + done + ' of ' + mig + ')', +mig > 0 && mig === done, done + ' of ' + mig);
  const [mc, mods] = await api('GET', '/modules'); ok('the Modules screen lists only the product modules and the two plans', mc === 200 && mods.modules.length === KEEP.length && mods.plans.map(p => p.key).join() === 'complete,essentials', JSON.stringify(mods && { m: mods.modules && mods.modules.map(x => x.key), p: mods.plans && mods.plans.map(p => p.key) }));
  const pg = await (await br.newContext({ viewport: { width: 1360, height: 900 } })).newPage(); const errs = [], s5 = [], known = new Set();
  pg.on('pageerror', e => errs.push(e.message)); pg.on('response', async rr => { if (rr.status() >= 500) { let t = ''; try { t = await rr.text(); } catch (e) {} if (!/Microsoft 365 is not connected/.test(t)) s5.push(rr.status() + ' ' + rr.url()); else known.add(rr.url().replace(/.*\/api\//, '')); } });
  await pg.goto(B); await pg.waitForSelector('[data-login-tou]'); await pg.fill('input[placeholder="Email"]', 'admin@example.com'); await pg.fill('input[placeholder="Password"]', 'Adm1n!Harb0ur#7'); await pg.click('[data-login-submit]');
  await pg.waitForSelector('aside.sb', { timeout: 20000 }); await pg.waitForTimeout(800);
  const nav = await pg.evaluate(() => [...document.querySelectorAll('aside.sb')].map(a => a.innerText).join(' ').replace(/\s+/g, ' '));
  ok('menu: no removed module (Tickets, Knowledge Base, Equipment, Server Guardian, IT Budget, vTiger)', !/Tickets|Knowledge Base|Equipment|Server Guardian|IT Budget|vTiger|Contracts|Calendar/.test(nav), nav.slice(0, 400));
  ok('menu: Microsoft 365 is there', /Microsoft 365/i.test(nav), nav.slice(0, 300));
  await pg.screenshot({ path: '/tmp/app-check-home.png' });
  const pages = await pg.evaluate(() => Object.keys(TITLES)); const bad = [];
  for (const p of pages) { const b = errs.length; await pg.evaluate(x => { location.hash = x; }, p); await pg.waitForTimeout(600); if (errs.length > b) bad.push(p + ': ' + errs.slice(b).join(' | ').slice(0, 160)); }
  ok('every page id opened (' + pages.length + '): no script error', bad.length === 0, bad.join(' || '));
  ok('no server error (5xx) while opening them, except the reference app\'s known "Microsoft 365 is not connected" answers', s5.length === 0, s5.slice(0, 8).join(' | ')); console.log('known (reference app answers 500 when Microsoft 365 is not connected):', [...known].join(', '));
  // 0001-A003: the screens hold only this product
  const goneTxt = /Tickets?\b|Knowledge Base|SOPs?\b|Credentials? vault|Contracts?\b|Vendors?\b|Equipment|vTiger|Announcements?\b|SLA\b|Company systems|Server Guardian|IT Budget|Scorecard|Integration Audit/;
  const shape = await pg.evaluate(() => ({ perms: PERM_GROUPS.map(g => g.label + ': ' + g.mods.map(m => m[0] + ' ' + m[1]).join(', ')).join(' | '),
    presets: JSON.stringify(PERM_PRESETS.map(p => [p.name, p.desc, Object.keys(p.perms)])) }));
  ok('Roles editor: only this product\'s permissions', !goneTxt.test(shape.perms) && !/\b(tickets|kb|sops|creds|contracts|vendors|assets|vtiger_users|categories|forms|sla_cfg|access|announcements|guardian)\b/.test(shape.perms), shape.perms.slice(0, 300));
  ok('Roles editor: the presets name only this product\'s permissions', !/"(tickets|kb|kb_assist|sops|creds|contracts|vendors|assets|calendar|reports|scorecard|standards|categories|forms|guardian|provision_onboard|vtiger_users|sla_cfg|integration_audit)"/.test(shape.presets), shape.presets.slice(0, 300));
  await pg.evaluate(() => { location.hash = 'dashboard'; }); await pg.waitForTimeout(900);
  const dash = await pg.evaluate(() => ({ areas: document.querySelectorAll('[data-dash-area]').length, text: (document.querySelector('[data-dashboard]') || document.body).innerText }));
  ok('dashboard: the Microsoft 365 areas (' + dash.areas + '), nothing of the removed modules', dash.areas >= 8 && !goneTxt.test(dash.text), dash.text.slice(0, 200));
  await pg.evaluate(() => { location.hash = 'settings'; }); await pg.waitForTimeout(900);
  const tabs = await pg.evaluate(() => [...document.querySelectorAll('[data-set-tab]')].map(t => [t.getAttribute('data-set-tab'), t.innerText]));
  const tabErr = [], tabText = {};
  for (const [id] of tabs) { const b = errs.length; await pg.click('[data-set-tab="' + id + '"]'); await pg.waitForTimeout(700); tabText[id] = await pg.evaluate(() => document.querySelector('.conn-grid').innerText); if (errs.length > b) tabErr.push(id + ': ' + errs.slice(b).join(' | ').slice(0, 160)); }
  ok('Settings: every tab opens (' + tabs.map(t => t[1]).join(', ') + ') with no script error', tabs.length >= 8 && tabErr.length === 0, tabErr.join(' || '));
  ok('Settings: a Microsoft 365 tab, no SLA & Hours or Company systems tab', tabs.some(t => t[0] === 'systems' && t[1] === 'Microsoft 365') && !tabs.some(t => /SLA|Company systems/.test(t[1])), JSON.stringify(tabs));
  ok('Settings → Microsoft 365: connection, permissions, sign-in and Teams app', /Connection/.test(tabText.systems || '') && /Permissions/.test(tabText.systems || '') && /Teams app/.test(tabText.systems || ''), (tabText.systems || '').slice(0, 200));
  const badTab = Object.entries(tabText).filter(([k, v]) => /Email-to-Ticket|help-desk mailbox|SLA clock|Priority targets|vTiger/.test(v)).map(([k]) => k);
  ok('Settings: no card of a removed module (ticket intake, SLA, vTiger)', badTab.length === 0, badTab.join(', '));
  ok('Settings → General: business hours', /Business hours/.test(tabText.general || ''), (tabText.general || '').slice(0, 120));
  await pg.fill('input.topsearch', 'Ada'); await pg.waitForTimeout(1200);
  const sr = await pg.evaluate(() => [...document.querySelectorAll('.searchdd > div')].map(d => d.innerText.replace(/\s+/g, ' ')));
  await pg.fill('input.topsearch', 'Settings'); await pg.waitForTimeout(1200);
  const sr2 = await pg.evaluate(() => [...document.querySelectorAll('.searchdd > div')].map(d => d.innerText.replace(/\s+/g, ' ')));
  await pg.fill('input.topsearch', '');
  ok('search: finds a user and a page', sr.some(x => /user/i.test(x) && /Ada Admin/.test(x)) && sr2.some(x => /page/i.test(x) && /Settings/.test(x)), JSON.stringify([sr, sr2]).slice(0, 300));
  { const b = errs.length; await pg.evaluate(() => { location.hash = 'users'; }); await pg.waitForTimeout(1200); await pg.click('table tbody tr'); await pg.waitForTimeout(900);
    const v = await pg.evaluate(() => document.body.innerText); ok('Users: a user opens with its details', errs.length === b && /admin@example\.com/.test(v), errs.slice(b).join(' | ').slice(0, 200)); }
  { const b = errs.length; const seen = {}; for (const h of ['tickets', 'kb', 'announcements', 'vtiger_users', 'access']) { await pg.evaluate(x => { location.hash = x; }, h); await pg.waitForTimeout(600); seen[h] = await pg.evaluate(() => (document.querySelector('.main') || document.body).innerText.replace(/\s+/g, ' ').slice(0, 80)); }
    ok('old links to removed pages (#tickets, #kb, …) open without a script error', errs.length === b, errs.slice(b).join(' | ').slice(0, 200) + ' ' + JSON.stringify(seen)); }
  { const g = await (await fetch(B + 'guide.js')).text(); const i = g.indexOf('const CONTENT ='); let t = g.slice(i + 15, g.indexOf('\n', i)).trim().replace(/;$/, ''); const C = JSON.parse(t);
    const cats = C.categories.map(c => c.cat); const titles = C.categories.flatMap(c => c.topics.map(x => x.title));
    ok('Help Center: only this product\'s categories (' + cats.join(', ') + ')', cats.length === 4 && !titles.some(x => /Tickets?\b|SLA|vTiger|Company systems|Knowledge Base|IT Budget|Announcements/.test(x)), titles.filter(x => /Tickets?\b|SLA|vTiger|Company systems|Knowledge Base|IT Budget|Announcements/.test(x)).join(' | ')); }
  { // 0001-A004 (R-03): on a fresh installation the in-app scheduler starts the main job (it used to need the Email settings saved first)
    const my = (q) => execFileSync('mysql', ['-uroot', '--socket=' + (process.env.DB_SOCKET || '/run/mysqld/mysqld.sock'), '-N', '-e', q, DB]).toString().trim();
    my("UPDATE itops_settings SET sval='0' WHERE skey='auto_tick_at'");
    const before = +my("SELECT COUNT(*) FROM itops_cron_runs WHERE job='sla'");
    await api('POST', '/tick', {}); let after = before;
    for (let i = 0; i < 30 && after === before; i++) { await new Promise(r => setTimeout(r, 1000)); after = +my("SELECT COUNT(*) FROM itops_cron_runs WHERE job='sla'"); }
    ok('in-app scheduler: runs the main job on a fresh installation (' + before + ' → ' + after + ' runs)', after > before, 'no main-scheduler run recorded'); }
  { // 0001-A004: no call to a function that was removed (the only ones allowed are web-server functions, each behind function_exists)
    const out = execFileSync('php', [path.join(HERE, 'dev', 'php-undefined.php')]).toString().split('\n').filter(l => l.includes('←'));
    const okNames = ['apache_request_headers', 'getallheaders', 'litespeed_finish_request', 'fastcgi_finish_request', 'apache_get_modules', 'itops_ai_http_stub'];
    const bad = out.filter(l => !okNames.includes(l.split(' ')[0]));
    ok('server code: every function it calls exists', bad.length === 0, bad.join(' | ').slice(0, 300)); }
  for (const c of ['cron-sla.php']) { let out = ''; try { out = execFileSync('php', [c], { cwd: RUN, timeout: 120000 }).toString(); } catch (e) { out = 'EXIT ' + e.status + ' ' + String(e.stdout || '') + String(e.stderr || ''); }
    ok('scheduled job ' + c + ' runs without a PHP error', !/Fatal|EXIT [1-9]|Uncaught/.test(out), out.slice(-300)); }
} catch (e) { ok('ran to the end', false, e.message.split('\n')[0]); }
await br.close(); try { for (const l of execFileSync('pgrep', ['-a', 'php']).toString().split('\n')) if (l.includes('127.0.0.1:' + PORT)) process.kill(+l.split(' ')[0]); } catch (e) {}
console.log('== ' + P + ' passed, ' + F + ' failed');
