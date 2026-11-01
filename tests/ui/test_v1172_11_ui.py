#!/usr/bin/env python3
# © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
"""
v1.172.11 — browser/database checks for "today" and typed times (Playwright + demo server). Evidence class: Claude.ai sandbox only.
  A  database-side day maths uses the organization's day: a credential expiring on the org's TODAY shows 0 days left, yesterday's shows -1
     (runs in whichever of Pacific/Pago_Pago (UTC-11) / Pacific/Kiritimati (UTC+14) has a different calendar day than UTC right now)
  B  calendar agenda + overdue banner: an all-day item due the org's TODAY is not "Overdue", and the banner count equals the Overdue bucket
  C  a timed event typed 23:30 in Pacific/Honolulu is stored as 09:30 UTC next day, shows 11:30 PM in the list and 23:30 in the edit modal
  D  sign-in pages: the strings the validator found in English are Spanish
Run:  python3 tests/ui/test_v1172_11_ui.py [BASE]      exit 0 = all pass
"""
import sys, re, time, subprocess, datetime
from zoneinfo import ZoneInfo
from playwright.sync_api import sync_playwright
BASE = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8098')
DB = 'flx_guide'; T = 'f6cbba33-6a22-4a65-ae68-1632bae576e5'
fails = []
def check(ok, label):
    print(('PASS' if ok else 'FAIL') + ' — ' + label)
    if not ok: fails.append(label)
def sql(q):
    return subprocess.run(['mysql', '--default-character-set=utf8mb4', '-uroot', '-N', '-B', '-e', q], capture_output=True, text=True).stdout.strip()
def setzone(z):
    sql("delete from %s.platform_settings where skey='app_timezone'" % DB)
    if z: sql("insert into %s.platform_settings (skey, sval) values ('app_timezone','%s')" % (DB, z))
utc = datetime.datetime.now(datetime.timezone.utc)
zone = next(z for z in ('Pacific/Pago_Pago', 'Pacific/Kiritimati') if utc.astimezone(ZoneInfo(z)).date() != utc.date())
loc = utc.astimezone(ZoneInfo(zone)).date(); today = loc.isoformat(); yest = (loc - datetime.timedelta(days=1)).isoformat()
def login(b):
    cx = b.new_context(viewport={'width': 1440, 'height': 900}); pg = cx.new_page()
    pg.goto(BASE + '/login'); pg.fill('input[name=email]', 'demo-admin@coastalcred.example'); pg.fill('input[name=password]', 'coastal-demo-2026')
    pg.click('button.btn.gold'); pg.wait_for_load_state('networkidle'); pg.goto(BASE + '/?setlang=en'); pg.wait_for_load_state('networkidle'); return cx, pg
def cleanup():
    sql("delete from %s.credentials where name like 'QA11 %%'; delete from %s.calendar_events where title like 'QA11 %%'" % (DB, DB))
    setzone(None)

cleanup()
try:
    with sync_playwright() as pw:
        b = pw.chromium.launch()
        # ---------------- A ----------------
        setzone(zone)
        for nm, d in (('QA11 Today', today), ('QA11 Yesterday', yest)):
            sql("CREATE TEMPORARY TABLE %s.t AS SELECT * FROM %s.credentials WHERE tenant_id='%s' AND deleted_at IS NULL LIMIT 1; UPDATE %s.t SET id=UUID(), name='%s', expiry_date='%s', status='active'; INSERT INTO %s.credentials SELECT * FROM %s.t; DROP TEMPORARY TABLE %s.t;" % (DB, DB, T, DB, nm, d, DB, DB, DB))
        cx, pg = login(b)
        pg.goto(BASE + '/credentials?q=QA11'); pg.wait_for_load_state('networkidle')
        row = lambda n: next(iter(pg.evaluate("(n)=>[...document.querySelectorAll('tr')].filter(r=>r.innerText.includes(n)).map(r=>r.innerText.replace(/\\s+/g,' '))", n)), '')
        rt, ry = row('QA11 Today'), row('QA11 Yesterday')
        check(bool(rt) and bool(ry), 'A fixture credentials are listed (zone %s, org today %s, UTC day %s)' % (zone, today, utc.date()))
        check(re.search(r'\b0 active\b', rt) is not None and re.search(r'(?<![\w-])-1(?![\w-])', rt) is None, 'A expiring on the org\'s TODAY: 0 days left, not expired (%s)' % rt[-60:])
        check(re.search(r'-1\b', ry) is not None, 'A expired the org\'s YESTERDAY: -1 days left (%s)' % ry[-60:])
        cx.close()
        # ---------------- B ----------------
        sql("CREATE TEMPORARY TABLE %s.t AS SELECT * FROM %s.calendar_events WHERE tenant_id='%s' AND deleted_at IS NULL LIMIT 1; UPDATE %s.t SET id=UUID(), title='QA11 Today Item', source='manual', all_day=1, status='open', holder_id=NULL, due_at='%s 09:00:00', end_at=NULL, completed_at=NULL; INSERT INTO %s.calendar_events SELECT * FROM %s.t; DROP TEMPORARY TABLE %s.t;" % (DB, DB, T, DB, today, DB, DB, DB))
        cx, pg = login(b)
        pg.goto(BASE + '/calendar?view=agenda'); pg.wait_for_load_state('networkidle')
        buckets = pg.evaluate("[...document.querySelectorAll('table.cal-agenda')].map(t=>({head:((t.closest('.table-wrap')||t).previousElementSibling||{innerText:''}).innerText.trim(), body:t.innerText})).filter(x=>x.head)")
        mine = [x for x in buckets if 'QA11 Today Item' in x['body']]
        check(len(mine) == 1 and not mine[0]['head'].startswith('Overdue'), 'B an all-day item due the org\'s TODAY is not under "Overdue" (bucket: %s)' % (mine[0]['head'] if mine else 'not found'))
        banner = pg.evaluate("(()=>{const e=document.querySelector('.error');const m=e&&e.innerText.match(/(\\d+) items? overdue/);return m?+m[1]:0})()")
        ov = next((int(re.search(r'(\d+)$', x['head']).group(1)) for x in buckets if x['head'].startswith('Overdue')), 0)
        check(banner == ov, 'B the overdue banner (%d) agrees with the Overdue bucket (%d)' % (banner, ov))
        cx.close()
        # ---------------- C ----------------
        setzone('Pacific/Honolulu')
        cx, pg = login(b)
        day = (datetime.date.today() + datetime.timedelta(days=6)).isoformat(); nxt = (datetime.date.today() + datetime.timedelta(days=7)).isoformat()
        pg.goto(BASE + '/calendar?view=month&m=' + day[:7]); pg.wait_for_load_state('networkidle')
        pg.locator('button[data-action=cal-new]').first.click(); time.sleep(.4)
        pg.fill('#cf_title', 'QA11 Timed Meeting'); pg.fill('#cf_date', day); pg.fill('#cf_time', '23:30')
        pg.locator('#calForm button[type=submit]').click(); pg.wait_for_load_state('networkidle')
        due = sql("select due_at from %s.calendar_events where title='QA11 Timed Meeting' and deleted_at is null" % DB)
        check(due == nxt + ' 09:30:00', 'C 23:30 typed in Pacific/Honolulu is stored as 09:30 UTC the next day (%s)' % due)
        pg.goto(BASE + '/calendar?view=list&from=%s&to=%s' % (day, nxt)); pg.wait_for_load_state('networkidle')
        body = re.sub(r'\s+', ' ', pg.evaluate('document.body.innerText'))
        check(re.search(re.escape('%s/%s/%s 11:30 PM' % (day[5:7], day[8:10], day[:4])), body) is not None, 'C the list shows the time as typed (11:30 PM)')
        pg.goto(BASE + '/calendar?view=month&m=' + day[:7]); pg.wait_for_load_state('networkidle')
        ev = pg.evaluate("(()=>{const m=window.FLX_CAL||{};return Object.values(m).find(e=>e.title==='QA11 Timed Meeting')||null})()")
        check(ev is not None and ev['time'] == '23:30' and ev['date'] == day, 'C the edit modal starts from the typed wall-clock time (%s)' % (ev and (ev['date'] + ' ' + ev['time'])))
        # edit (modal) and move (drag-drop endpoint) keep the same rule
        eid = sql("select id from %s.calendar_events where title='QA11 Timed Meeting' and deleted_at is null" % DB)
        pg.goto(BASE + '/calendar?view=month&m=' + day[:7]); pg.wait_for_load_state('networkidle')
        pg.evaluate("(id)=>window.calEdit(id)", eid); time.sleep(.4)
        pg.fill('#cf_time', '22:00'); pg.locator('#calForm button[type=submit]').click(); pg.wait_for_load_state('networkidle')
        due2 = sql("select due_at from %s.calendar_events where id='%s'" % (DB, eid))
        check(due2 == nxt + ' 08:00:00', 'C2 editing the time to 22:00 (Honolulu) stores 08:00 UTC next day (%s)' % due2)
        day3 = (datetime.date.today() + datetime.timedelta(days=9)).isoformat(); nxt3 = (datetime.date.today() + datetime.timedelta(days=10)).isoformat()
        pg.goto(BASE + '/calendar?view=month&m=' + day[:7]); pg.wait_for_load_state('networkidle')
        pg.evaluate("async ([id,d])=>{const c=document.querySelector('#calCsrf input').value;await fetch('/calendar/'+id+'/move',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'_csrf='+encodeURIComponent(c)+'&date='+d,credentials:'same-origin'})}", [eid, day3])
        due3 = sql("select due_at from %s.calendar_events where id='%s'" % (DB, eid))
        check(due3 == nxt3 + ' 08:00:00', 'C3 moving it to another day keeps 22:00 Honolulu (%s)' % due3)
        cx.close()
        # ---------------- D ----------------
        c2 = b.new_context(locale='es-PR', extra_http_headers={'Accept-Language': 'es-PR,es;q=0.9'}); p2 = c2.new_page()
        p2.goto(BASE + '/invite/not-a-real-token'); t = p2.evaluate('document.body.innerText')
        check('Invitación no encontrada' in t and 'Invitation not found' not in t, 'D invitation page (invalid link) is Spanish')
        p2.goto(BASE + '/forgot'); t = p2.evaluate('document.body.innerText')
        check('(Equipo → Establecer contraseña)' in t and 'Team → Set password' not in t, 'D forgot page: "(Team → Set password)" is translated')
        b.close()
finally:
    cleanup()
print('\nALL PASS' if not fails else '\n%d FAILED' % len(fails)); sys.exit(1 if fails else 0)
