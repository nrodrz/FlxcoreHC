#!/usr/bin/env python3
"""
v1.172.12 — checks for the gaps the independent v1.172.11 run found (Playwright + demo DB). Evidence class: Claude.ai sandbox only.
  K  the nightly cron sweep uses the organization's day: a credential expiring on the org's TODAY stays 'expiring', yesterday's becomes 'expired'
  M  month-end evenings: a timed event at 23:30 on the last day of a month is in THAT month's grid and list (Honolulu 12/31, New York 10/31)
  O  a timed event that already passed today is "Overdue" in the agenda too, and banner = agenda bucket = list chips
  S  Spanish: sign-in / registration leftovers
Run:  python3 tests/ui/test_v1172_12_ui.py [BASE]      exit 0 = all pass
"""
import sys, re, time, subprocess, datetime, os
from zoneinfo import ZoneInfo
from playwright.sync_api import sync_playwright
BASE = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8098')
DB = 'flx_guide'; T = 'f6cbba33-6a22-4a65-ae68-1632bae576e5'
ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
fails = []
def check(ok, label):
    print(('PASS' if ok else 'FAIL') + ' — ' + label)
    if not ok: fails.append(label)
def sql(q):
    return subprocess.run(['mysql', '--default-character-set=utf8mb4', '-uroot', '-N', '-B', '-e', q], capture_output=True, text=True).stdout.strip()
def setzone(z):
    sql("delete from %s.platform_settings where skey='app_timezone'" % DB)
    if z: sql("insert into %s.platform_settings (skey, sval) values ('app_timezone','%s')" % (DB, z))
def cred(nm, d, status):
    sql("CREATE TEMPORARY TABLE %s.t AS SELECT * FROM %s.credentials WHERE tenant_id='%s' AND deleted_at IS NULL LIMIT 1; UPDATE %s.t SET id=UUID(), name='%s', expiry_date='%s', status='%s'; INSERT INTO %s.credentials SELECT * FROM %s.t; DROP TEMPORARY TABLE %s.t;" % (DB, DB, T, DB, nm, d, status, DB, DB, DB))
def event(title, due, allday=0):
    sql("CREATE TEMPORARY TABLE %s.t AS SELECT * FROM %s.calendar_events WHERE tenant_id='%s' AND deleted_at IS NULL LIMIT 1; UPDATE %s.t SET id=UUID(), title='%s', source='manual', kind='meeting', all_day=%d, status='open', holder_id=NULL, due_at='%s', end_at=NULL, completed_at=NULL; INSERT INTO %s.calendar_events SELECT * FROM %s.t; DROP TEMPORARY TABLE %s.t;" % (DB, DB, T, DB, title, allday, due, DB, DB, DB))
def cleanup():
    sql("delete from %s.credentials where name like 'QA12 %%'; delete from %s.calendar_events where title like 'QA12 %%'" % (DB, DB)); setzone(None)
def login(b, tz='America/New_York'):
    cx = b.new_context(viewport={'width': 1440, 'height': 900}, timezone_id=tz); pg = cx.new_page()
    pg.goto(BASE + '/login'); pg.fill('input[name=email]', 'demo-admin@coastalcred.example'); pg.fill('input[name=password]', 'coastal-demo-2026')
    pg.click('button.btn.gold'); pg.wait_for_load_state('networkidle'); pg.goto(BASE + '/?setlang=en'); pg.wait_for_load_state('networkidle'); return cx, pg

utc = datetime.datetime.now(datetime.timezone.utc)
zone = next(z for z in ('Pacific/Pago_Pago', 'Pacific/Kiritimati') if utc.astimezone(ZoneInfo(z)).date() != utc.date())
loc = utc.astimezone(ZoneInfo(zone)).date(); today = loc.isoformat(); yest = (loc - datetime.timedelta(days=1)).isoformat()
cleanup()
try:
    # ---------------- K ----------------
    setzone(zone)
    cred('QA12 Today', today, 'expiring'); cred('QA12 Yesterday', yest, 'active')
    cfg = os.environ.get('FLX_CONFIG_CRON', '/tmp/guide/cfg.php')
    r = subprocess.run(['php', ROOT + '/cron/sweep_expiring.php'], capture_output=True, text=True, env={**os.environ, 'FLX_CONFIG': cfg}, cwd=ROOT)
    st = lambda n: sql("select status from %s.credentials where name='%s'" % (DB, n))
    check(r.returncode == 0, 'K the cron sweep runs (%s)' % r.stdout.strip()[:60])
    check(st('QA12 Today') == 'expiring', 'K expiring on the org\'s TODAY (%s; UTC day %s) stays "expiring" after the nightly sweep (is: %s)' % (today, utc.date(), st('QA12 Today')))
    check(st('QA12 Yesterday') == 'expired', 'K expired the org\'s YESTERDAY becomes "expired" (is: %s)' % st('QA12 Yesterday'))
    sql("delete from %s.credentials where name like 'QA12 %%'" % DB)
    with sync_playwright() as pw:
        b = pw.chromium.launch()
        # ---------------- M ----------------
        for z, local, utcdue, ym, nxtm, label in (('Pacific/Honolulu', '2026-12-31', '2027-01-01 09:30:00', '2026-12', '2027-01', 'Honolulu 12/31 23:30'),
                                                    ('America/New_York', '2026-10-31', '2026-11-01 03:30:00', '2026-10', '2026-11', 'New York 10/31 23:30')):
            setzone(z); sql("delete from %s.calendar_events where title like 'QA12 %%'" % DB); event('QA12 Month End', utcdue)
            cx, pg = login(b, z)
            pg.goto('%s/calendar?view=month&m=%s' % (BASE, ym)); pg.wait_for_load_state('networkidle')
            cell = pg.evaluate("(d)=>{const c=document.querySelector('.cal-cell[data-date=\"'+d+'\"]');return c?c.innerText:''}", local)
            check('QA12 Month End' in cell, 'M %s: the event is in the %s grid, on day %s' % (label, ym, local))
            pg.goto('%s/calendar?view=list&from=%s-01&to=%s' % (BASE, ym, local)); pg.wait_for_load_state('networkidle')
            lst0 = pg.evaluate("[...document.querySelectorAll('table')].filter(t=>/When\\s+Type\\s+What\\s+Status/i.test(t.innerText)).map(t=>t.innerText).join('\\n')")
            check('QA12 Month End' in lst0, 'M %s: the event is in the %s list table' % (label, ym))
            pg.goto('%s/calendar?view=list&from=%s-01&to=%s-28' % (BASE, nxtm, nxtm)); pg.wait_for_load_state('networkidle')
            lst = pg.evaluate("[...document.querySelectorAll('table')].filter(t=>/When\\s+Type\\s+What\\s+Status/i.test(t.innerText)).map(t=>t.innerText).join('\\n')")
            check('QA12 Month End' not in lst, 'M %s: and NOT in the following month\'s list table' % label)
            cx.close()
        # ---------------- O ----------------
        setzone('Pacific/Honolulu'); sql("delete from %s.calendar_events where title like 'QA12 %%'" % DB)
        event('QA12 Passed Today', (utc - datetime.timedelta(hours=1)).strftime('%Y-%m-%d %H:%M:%S'))
        cx, pg = login(b, 'Pacific/Honolulu')
        pg.goto(BASE + '/calendar?view=agenda'); pg.wait_for_load_state('networkidle')
        buckets = pg.evaluate("[...document.querySelectorAll('table.cal-agenda')].map(t=>({head:((t.closest('.table-wrap')||t).previousElementSibling||{innerText:''}).innerText.trim(), body:t.innerText})).filter(x=>x.head)")
        mine = [x for x in buckets if 'QA12 Passed Today' in x['body']]
        check(len(mine) == 1 and mine[0]['head'].startswith('Overdue'), 'O a timed event that passed an hour ago is "Overdue" in the agenda (bucket: %s)' % (mine[0]['head'] if mine else 'not found'))
        banner = pg.evaluate("(()=>{const e=document.querySelector('.error');const m=e&&e.innerText.match(/(\\d+) items? overdue/);return m?+m[1]:0})()")
        ov = next((int(re.search(r'(\d+)$', x['head']).group(1)) for x in buckets if x['head'].startswith('Overdue')), 0)
        check(banner == ov, 'O banner (%d) = agenda Overdue bucket (%d)' % (banner, ov))
        cx.close()
        # ---------------- S ----------------
        c2 = b.new_context(locale='es-PR', extra_http_headers={'Accept-Language': 'es-PR,es;q=0.9'}); p2 = c2.new_page()
        p2.goto(BASE + '/login'); t = p2.evaluate('document.body.innerText')
        check('Healthcare Compliance Intelligence Platform' not in t and 'Plataforma de inteligencia' in t, 'S sign-in tagline is Spanish')
        sql("delete from %s.login_attempts where identifier not like 'known|%%'" % DB)   # earlier runs from this address may have tripped the lock-out
        p2.fill('input[name=email]', 'nobody@example.test'); p2.fill('input[name=password]', 'wrong-password-123'); p2.click('button.btn.gold'); p2.wait_for_load_state('networkidle')
        t = p2.evaluate('document.body.innerText')
        check('Email or password is incorrect.' not in t and 'incorrectos' in t, 'S wrong-password message is Spanish')
        b.close()
finally:
    cleanup()
print('\nALL PASS' if not fails else '\n%d FAILED' % len(fails)); sys.exit(1 if fails else 0)
