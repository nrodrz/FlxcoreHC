// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
// dev/check-app.mjs (local testing only): the starter app installs, signs in, shows only its modules, the removed APIs answer 404,
// every page opens without a script error or a server error
import { createRequire } from 'module'; const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
const path = require('path');
const { execFileSync, spawn } = require('child_process');
const HERE = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..'); const RUN = process.env.RUN || '/tmp/app-check-run'; const PORT = +(process.env.PORT || 8301); const DB = process.env.DB || 'app_check';
const KEEP = (process.env.KEEP || 'm365,onboarding,security_assessment,teams_app').split(',').sort(); const NAME = process.env.PRODUCT || 'NewApp'; const B = 'http://127.0.0.1:' + PORT + '/';
let P = 0, F = 0; const ok = (n, c, x) => { if (c) { P++; console.log('PASS', n); } else { F++; console.log('FAIL', n, x || ''); } };
execFileSync(path.join(HERE, 'dev', 'fresh-install.sh'), [RUN, String(PORT), DB], { stdio: 'inherit' }); await new Promise(r => setTimeout(r, 800));
const h = { 'X-OpsHub-CSRF': '1', 'Content-Type': 'application/json' }; let ck = '';
const api = async (m, u, b) => { const r = await fetch(B + 'api' + u, { method: m, headers: Object.assign({ Cookie: ck }, h), body: b ? JSON.stringify(b) : undefined }); let j = null; try { j = await r.json(); } catch (e) {} return [r.status, j]; };
const br = await chromium.launch();
try {
  const t = await (await fetch(B + 'api/terms/text')).json(); const r = await fetch(B + 'api/auth/login', { method: 'POST', headers: h, body: JSON.stringify({ email: 'admin@example.com', password: 'Adm1n!Harb0ur#7' }) });
  ck = r.headers.getSetCookie().map(c => c.split(';')[0]).join('; '); await api('POST', '/terms/accept', { version: t.version, for_org: true, org_name: 'Example Org' });
  const [, lk] = await api('GET', '/lookups');
  ok('only the product modules are on: ' + JSON.stringify(lk.modules.on), JSON.stringify(lk.modules.on.sort()) === JSON.stringify(KEEP));
  const [ab, about] = await api('GET', '/about'); ok('About & ownership: new product, owner Indisse-BTS', ab === 200 && about.product === NAME && /Indisse-BTS/.test(JSON.stringify(about)) && about.product === NAME && !/OpsHub/.test(about.product + about.product_line + about.developed_by), JSON.stringify(about).slice(0, 300));
  const gone = {}; for (const rt of ['tickets', 'kb', 'assets', 'guardian', 'server_perf', 'vtiger_users', 'budget', 'contracts', 'calendar', 'announcements', 'public_ticket']) gone[rt] = (await api('GET', '/' + rt))[0];
  ok('removed modules: their APIs answer 404', Object.values(gone).every(c => c === 404), JSON.stringify(gone));
  const kept = {}; for (const rt of ['m365hub/m365-domains', 'security_assessment', 'risk_users', 'onboarding/templates', 'settings', 'users', 'roles', 'audit']) kept[rt] = (await api('GET', '/' + rt))[0];
  ok('product and platform APIs answer (no 404 / 5xx): ' + JSON.stringify(kept), Object.values(kept).every(c => c !== 404 && c < 500), JSON.stringify(kept));
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
  for (const c of ['cron-sla.php', 'cron-mail.php', 'cron-audit.php', 'cron-uptime.php']) { let out = ''; try { out = execFileSync('php', [c], { cwd: RUN, timeout: 120000 }).toString(); } catch (e) { out = 'EXIT ' + e.status + ' ' + String(e.stdout || '') + String(e.stderr || ''); }
    ok('scheduled job ' + c + ' runs without a PHP error', !/Fatal|EXIT [1-9]|Uncaught/.test(out), out.slice(-300)); }
} catch (e) { ok('ran to the end', false, e.message.split('\n')[0]); }
await br.close(); try { for (const l of execFileSync('pgrep', ['-a', 'php']).toString().split('\n')) if (l.includes('127.0.0.1:' + PORT)) process.kill(+l.split(' ')[0]); } catch (e) {}
console.log('== ' + P + ' passed, ' + F + ' failed');
