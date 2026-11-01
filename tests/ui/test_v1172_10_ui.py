#!/usr/bin/env python3
"""
v1.172.10 — browser checks for the findings of the independent v1.172.7 validation (Playwright + demo server).
Evidence class: Claude.ai sandbox only.
  D1 an all-day deadline stays on its own day when the organization's time zone is Pacific/Honolulu (month grid, list, Next 30 days)
  D2 provider page / History tab show no raw ISO dates (the one fixed-date regulatory sentence is the only allowed exception)
  L1 sign-in page: browser language, ?lang=, the switch, and the choice surviving sign-out
  M1 Create organization refuses a 9-character temporary password and creates nothing
  O1 the Microsoft OAuth callback never shows text taken from the link
Run:  python3 tests/ui/test_v1172_10_ui.py [BASE]      exit 0 = all pass
"""
import sys, re, time, subprocess
from playwright.sync_api import sync_playwright
BASE = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8098')
H = '/holders/f554ff58-8691-47c3-a17d-16c0f6570177'
DB = 'flx_guide'
fails = []
def check(ok, label):
    print(('PASS' if ok else 'FAIL') + ' — ' + label)
    if not ok: fails.append(label)
def sql(q):
    return subprocess.run(['mysql', '--default-character-set=utf8mb4', '-uroot', '-N', '-B', '-e', q], capture_output=True, text=True).stdout.strip()
def login(b, email, pw, tz='America/New_York', **kw):
    cx = b.new_context(viewport={'width': 1440, 'height': 900}, timezone_id=tz, **kw); pg = cx.new_page()
    pg.goto(BASE + '/login'); pg.fill('input[name=email]', email); pg.fill('input[name=password]', pw)
    pg.click('button.btn.gold'); pg.wait_for_load_state('networkidle'); return cx, pg
ORG = ('demo-admin@coastalcred.example', 'coastal-demo-2026'); PLAT = ('platform@flxcorehc.example', 'PlatformDemo2026!')
ISO = re.compile(r'\b(19|20)\d{2}-\d{2}-\d{2}\b')
JS = """()=>{const o=[];const w=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT);let n;while(n=w.nextNode()){const p=n.parentElement;if(!p||['SCRIPT','STYLE'].includes(p.tagName))continue;const t=n.textContent.trim();if(t)o.push(t)}return o}"""

with sync_playwright() as pw:
    b = pw.chromium.launch()
    # ---------------- D1 ----------------
    row = sql("select id, due_at, title from %s.calendar_events where all_day=1 and source<>'manual' and deleted_at is null and status='open' and due_at>now() order by due_at limit 1" % DB).split('\t')
    eid, due, title = row[0], row[1], row[2]; day = due[:10]; ym = day[:7]
    had = sql("select count(*) from %s.platform_settings where skey='app_timezone'" % DB)
    sql("delete from %s.platform_settings where skey='app_timezone'" % DB)
    sql("insert into %s.platform_settings (skey, sval) values ('app_timezone','Pacific/Honolulu')" % DB)
    try:
        cx, pg = login(b, *ORG); pg.goto(BASE + '/?setlang=en'); pg.wait_for_load_state('networkidle')
        pg.goto('%s/calendar?view=month&m=%s' % (BASE, ym)); pg.wait_for_load_state('networkidle')
        cell = pg.evaluate("(d)=>{const c=document.querySelector('.cal-cell[data-date=\"'+d+'\"]');return c?c.innerText:null}", day)
        prev = pg.evaluate("(d)=>{const x=new Date(d+'T12:00:00Z');x.setUTCDate(x.getUTCDate()-1);const k=x.toISOString().slice(0,10);const c=document.querySelector('.cal-cell[data-date=\"'+k+'\"]');return c?c.innerText:''}", day)
        key = title.split(' expires')[0][:18]
        check(cell is not None and key in cell, 'D1 month grid: the deadline sits in its own day cell (%s) in Pacific/Honolulu' % day)
        check(key + ' expires' not in (prev or '') or title not in (prev or ''), 'D1 month grid: it is NOT in the previous day cell')
        pg.goto('%s/calendar?view=list&from=%s&to=%s' % (BASE, day, day)); pg.wait_for_load_state('networkidle')
        body = re.sub(r'\s+', ' ', pg.evaluate('document.body.innerText'))
        want = '%s/%s/%s' % (day[5:7], day[8:10], day[:4])
        check(want in body, 'D1 list view shows the stored day %s' % want)
        prevd = pg.evaluate("(d)=>{const x=new Date(d+'T12:00:00Z');x.setUTCDate(x.getUTCDate()-1);const k=x.toISOString().slice(0,10);return k.slice(5,7)+'/'+k.slice(8,10)+'/'+k.slice(0,4)}", day)
        check(not re.search(re.escape(prevd) + r' \d{1,2}:\d{2} [AP]M', body), 'D1 list view shows no shifted previous-day clock time')
        pg.goto('%s/calendar' % BASE); pg.wait_for_load_state('networkidle')
        nxt = pg.evaluate("""()=>{const h=[...document.querySelectorAll('h2')].find(x=>/Next 30 days/.test(x.textContent));return h?h.closest('.panel').innerText:''}""")
        check(not re.search(r'\d{2}/\d{2}/\d{4} \d{1,2}:\d{2} [AP]M\s+\S*[^\n]*\bexpires\b', nxt), 'D1 Next 30 days: deadlines carry a date, not a clock time')
        cx.close()
    finally:
        sql("delete from %s.platform_settings where skey='app_timezone'" % DB)
    # ---------------- D2 ----------------
    cx, pg = login(b, *ORG)
    pg.goto(BASE + H); pg.wait_for_load_state('networkidle'); seen = []
    def grab():
        for t in pg.evaluate(JS):
            if ISO.search(t): seen.append(t)
    grab()
    for t in pg.locator('[data-tab]').all():
        try: t.click(); time.sleep(.25); grab()
        except Exception: pass
    bad = [t for t in dict.fromkeys(seen) if '2024-05-15' not in t]
    check(not bad, 'D2 provider page + every tab: no raw ISO dates (%d found)%s' % (len(bad), (': ' + bad[0][:80]) if bad else ''))
    cx.close()
    # ---------------- L1 ----------------
    c1 = b.new_context(locale='es-PR', extra_http_headers={'Accept-Language': 'es-PR,es;q=0.9'}); p1 = c1.new_page()
    p1.goto(BASE + '/login'); p1.wait_for_load_state('networkidle')
    t1 = p1.evaluate('document.body.innerText')
    check('Iniciar sesión' in t1 and 'Contraseña' in t1 and p1.evaluate('document.documentElement.lang') == 'es', 'L1 sign-in page follows the browser language (Spanish)')
    check(p1.locator('.auth-lang a[lang=en]').count() == 1 and p1.locator('.auth-lang a[lang=es]').count() == 1, 'L1 English | Español switch is on the page')
    p1.click('.auth-lang a[lang=en]'); p1.wait_for_load_state('networkidle')
    check('Sign in' in p1.evaluate('document.body.innerText') and 'Forgot your password?' in p1.evaluate('document.body.innerText'), 'L1 the switch to English works even with a Spanish browser')
    p1.goto(BASE + '/login'); p1.wait_for_load_state('networkidle')
    check('Sign in' in p1.evaluate('document.body.innerText'), 'L1 the choice is remembered on the next visit')
    c1.close()
    c2 = b.new_context(locale='en-US'); p2 = c2.new_page()
    p2.goto(BASE + '/login'); check('Sign in' in p2.evaluate('document.body.innerText'), 'L1 English browser stays English')
    p2.goto(BASE + '/login?lang=es'); p2.wait_for_load_state('networkidle')
    check('Iniciar sesión' in p2.evaluate('document.body.innerText'), 'L1 ?lang=es switches the sign-in page')
    p2.goto(BASE + '/forgot'); check('Volver' in p2.evaluate('document.body.innerText') or '¿Olvidó' in p2.evaluate('document.body.innerText') or 'contraseña' in p2.evaluate('document.body.innerText').lower(), 'L1 the choice carries to the other signed-out pages')
    c2.close()
    # sign-out keeps the in-app language even when the visitor never touched the sign-in page's own switch (fresh browser, no cookie)
    c3 = b.new_context(locale='en-US'); p3 = c3.new_page()
    p3.goto(BASE + '/login'); p3.fill('input[name=email]', ORG[0]); p3.fill('input[name=password]', ORG[1]); p3.click('button.btn.gold'); p3.wait_for_load_state('networkidle')
    p3.goto(BASE + '/?setlang=es'); p3.wait_for_load_state('networkidle')
    p3.evaluate("document.querySelector('form[action$=\"/logout\"]').submit()"); p3.wait_for_url('**/login'); p3.wait_for_load_state('networkidle')
    check('Iniciar sesión' in p3.evaluate('document.body.innerText'), 'L1 signing out of a Spanish session leaves the sign-in page in Spanish')
    c3.close()
    # ---------------- M1 / O1 ----------------
    cx, pg = login(b, *PLAT); pg.goto(BASE + '/?setlang=en'); pg.wait_for_load_state('networkidle')
    pg.goto(BASE + '/admin/tenants'); pg.wait_for_load_state('networkidle')
    check(pg.get_attribute('input[name=owner_password]', 'minlength') == '10', 'M1 the temporary-password field requires 10+ characters')
    n0 = sql("select count(*) from %s.tenants" % DB)
    pg.evaluate("document.querySelector('input[name=owner_password]').removeAttribute('minlength')")
    f = pg.locator('form:has(input[name=org_name])')
    f.locator('input[name=org_name]').fill('QA 10 Temp Org'); f.locator('input[name=owner_name]').fill('Qa Owner'); f.locator('input[name=owner_email]').fill('qa10-owner@example.test'); f.locator('input[name=owner_password]').fill('abcdefghi')
    f.locator('button.btn').click(); pg.wait_for_load_state('networkidle')
    txt = pg.evaluate('document.body.innerText')
    check('at least 10 characters' in txt and sql("select count(*) from %s.tenants" % DB) == n0, 'M1 a 9-character temporary password is refused and no organization is created')
    pg.goto(BASE + '/oauth/microsoft/callback?error=access_denied&error_description=EVIL-PHISH-TEXT+call+555-0100'); pg.wait_for_load_state('networkidle')
    txt = pg.evaluate('document.body.innerText')
    check('EVIL-PHISH-TEXT' not in txt and 'cancelled or failed' in txt, 'O1 OAuth error text from the link is not shown')
    cx.close(); b.close()
print('\nALL PASS' if not fails else '\n%d FAILED' % len(fails)); sys.exit(1 if fails else 0)
