#!/usr/bin/env python3
"""
v1.172.9 — browser checks (Playwright, needs the demo server + DB: see docs). Evidence class: Claude.ai sandbox only.
  E1 the New-event modal offers exactly the tenant's ACTIVE event types (system 'deadline' excluded; no invented 'Task')
  E2 an event saved with a CUSTOM type is stored under that type's code (before v1.172.9 "Task" silently saved as "Meeting")
  E3 editing it shows the saved type, even after that type is switched off
  S1 Spanish: pages that used to show English paragraphs/hints no longer do
  S2 Spanish: notification and calendar auto-generated texts are translated at display time
  P1 public share page: browser language, ?lang=, and the switch
  H1 the Document-requests hint no longer says nothing is e-mailed
Run:  python3 tests/ui/test_v1172_9_ui.py [BASE]      exit 0 = all pass
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
TENANT = sql("select tenant_id from %s.users where email='demo-admin@coastalcred.example'" % DB)

def login(pw_, lang='en'):
    b = pw_.chromium.launch(); cx = b.new_context(viewport={'width': 1440, 'height': 900}, timezone_id='America/New_York'); pg = cx.new_page()
    pg.goto(BASE + '/login'); pg.fill('input[name=email]', 'demo-admin@coastalcred.example'); pg.fill('input[name=password]', 'coastal-demo-2026')
    pg.click('button.btn.gold'); pg.wait_for_load_state('networkidle')
    pg.goto('%s/?setlang=%s' % (BASE, lang)); pg.wait_for_load_state('networkidle')
    return b, cx, pg
def text(pg, path):
    pg.goto(BASE + path); pg.wait_for_load_state('networkidle')
    return re.sub(r'\s+', ' ', pg.evaluate('document.body.innerText'))

with sync_playwright() as pw:
    # ---------------- E1-E3 ----------------
    b, cx, pg = login(pw)
    sql("update %s.calendar_event_types set is_active=1 where tenant_id='%s' and code='site_inspection'" % (DB, TENANT))
    want = sql("select code from %s.calendar_event_types where tenant_id='%s' and is_active=1 and code<>'deadline' order by sort, label" % (DB, TENANT)).split('\n')
    pg.goto(BASE + '/calendar?view=month'); pg.wait_for_load_state('networkidle')
    pg.locator('button[data-action=cal-new]').first.click(); time.sleep(.4)
    got = pg.evaluate("[...document.querySelectorAll('#cf_kind option')].map(o=>o.value)")
    check(sorted(got) == sorted(want) and 'site_inspection' in got, 'E1 modal offers exactly the active configured types (%s)' % ','.join(got))
    check('deadline' not in got and 'task' not in got, 'E1 no system "deadline" and no invented "task" option')
    title = 'E2 probe %d' % int(time.time())
    pg.fill('#cf_title', title)
    pg.evaluate("()=>{document.getElementById('cf_date').value='2026-12-04'; document.getElementById('cf_time').value='10:15'}")
    pg.evaluate("()=>{const s=document.getElementById('cf_kind'); s.value='site_inspection'; s.dispatchEvent(new Event('change',{bubbles:true}))}")
    pg.locator('#calForm button[type=submit]').click(); pg.wait_for_load_state('networkidle')
    kind = sql("select kind from %s.calendar_events where tenant_id='%s' and title='%s' and deleted_at is null" % (DB, TENANT, title))
    check(kind == 'site_inspection', 'E2 event saved with the chosen custom type (stored kind=%r)' % kind)
    # default type for a brand-new event is the first configured one, not a hard-coded value
    pg.goto(BASE + '/calendar?view=month'); pg.wait_for_load_state('networkidle')
    pg.locator('button[data-action=cal-new]').first.click(); time.sleep(.3)
    check(pg.evaluate("document.getElementById('cf_kind').value") == want[0], 'E1 new event defaults to the first configured type (%s)' % want[0])
    # E3: switch the type off, reopen the event: the saved type is still shown
    sql("update %s.calendar_event_types set is_active=0 where tenant_id='%s' and code='site_inspection'" % (DB, TENANT))
    pg.goto(BASE + '/calendar?view=month&m=2026-12'); pg.wait_for_load_state('networkidle')
    row = pg.locator('.cal-ev[data-action=cal-edit]', has_text=title).first
    if row.count():
        row.click(); time.sleep(.4)
        check(pg.evaluate("document.getElementById('cf_kind').value") == 'site_inspection', 'E3 editing keeps the saved type even when it is switched off')
    else:
        check(False, 'E3 could not find the created event in the month view')
    sql("update %s.calendar_event_types set is_active=1 where tenant_id='%s' and code='site_inspection'" % (DB, TENANT))
    sql("delete from %s.calendar_events where tenant_id='%s' and title='%s'" % (DB, TENANT, title))
    b.close()

    # ---------------- S1 / S2 / H1 (Spanish) ----------------
    b, cx, pg = login(pw, 'es')
    ENGLISH = {   # fingerprints of paragraphs that used to be shown in English in Spanish mode
        '/credentials/requirements': ['Lifetime 10-digit identifier', 'Add a credential for', 'Workers compensation coverage'],
        '/entity': ['Payers enrol the group', 'Naming a responsible person'],
        '/calendar/subscribe': ['Subscribe to a read-only feed', 'How to subscribe'],
        '/organization/documents-tracked': ['The built-in document types cover', 'A short code is derived'],
        '/templates': ['A template says what a provider'],
        '/compliance/security': ['Required items complete (', 'This checklist adapts'],
        '/reports': ['assembles each provider'],
        '/settings': ['live on the'],
        H: ['need attention', 'credentials have evidence attached', 'Every provider should have a portal login'],
    }
    for path, bad in ENGLISH.items():
        t = text(pg, path)
        check(not any(x in t for x in bad), 'S1 %s has none of: %s' % (path.split('/')[-1] or path, ' | '.join(bad)))
    t = text(pg, '/notifications')
    check(not re.search(r'expires in \d+ day\(s\)|Start the renewal now|has expired —|\d+ (min|h|d) ago', t), 'S2 notifications are translated at display time')
    check(re.search(r'vence en \d+ día\(s\)|venció', t) is not None, 'S2 …and the Spanish wording is actually present')
    for v in ('month', 'list', 'agenda'):
        t = text(pg, '/calendar?view=' + v)
        check(not re.search(r'[A-Za-z ]+ expires — |Credential renewal deadline|item\(?s?\)? overdue — highlighted', t), 'S2 calendar (%s): auto-generated titles are translated' % v)
    t = text(pg, '/')
    check('Not compliant' not in t and 'Professional license expired on' not in t and 'No professional license on file' not in t, 'S1 dashboard compliance reasons are translated')
    t = text(pg, '/groups')
    check('Añadir Client group' not in t and 'Client group' not in t.replace('Grupo cliente', ''), 'S1 group form uses the Spanish label ("Añadir Grupo cliente")')
    b.close()

    # ---------------- H1 (English) ----------------
    b, cx, pg = login(pw, 'en')
    pg.goto(BASE + H); pg.wait_for_load_state('networkidle'); body = pg.content()
    check('nothing is emailed' not in body, 'H1 provider page no longer claims that nothing is e-mailed')
    check('the e-mail only links to it and never carries documents' in body, 'H1 …and states what really happens')
    t = text(pg, '/help/document-requests')
    check('Nothing is emailed' not in t, 'H1 help article corrected')
    # ---------------- P1 public share ----------------
    pg.goto(BASE + H); pg.wait_for_load_state('networkidle'); pg.click('[data-tab=activity]'); time.sleep(.3)
    pg.locator('#external summary').click(); pg.select_option('#external select[name=recipient_type]', 'payer'); pg.fill('#external input[name=recipient_name]', 'P1 probe payer')
    pg.locator('#external form button.btn.gold').click(); pg.wait_for_load_state('networkidle'); time.sleep(.4)
    pg.click('[data-tab=activity]'); time.sleep(.3)
    url = pg.locator('#external input[readonly]').first.input_value(); pwd = pg.locator('#external input[readonly]').nth(1).input_value()
    b.close()
    b = pw.chromium.launch()
    def pub(headers, suffix=''):
        c = b.new_context(locale=headers.get('Accept-Language', 'en-US').split(',')[0], extra_http_headers=headers); p = c.new_page()
        p.goto(url + suffix); p.wait_for_load_state('networkidle')
        h1 = p.evaluate("document.querySelector('h1').innerText")
        p.fill('input[name=password]', pwd); p.click('button.btn'); p.wait_for_load_state('networkidle')
        body = re.sub(r'\s+', ' ', p.evaluate('document.body.innerText')); has_switch = p.locator('.share-lang a[lang=es]').count() == 1
        c.close(); return h1, body, has_switch
    h1, body, sw = pub({'Accept-Language': 'es-PR,es;q=0.9'})
    check(h1 == 'Perfil compartido de forma segura' and 'Preparado para' in body and 'Prepared for' not in body, 'P1 Spanish browser → Spanish password page and profile')
    h1, body, sw = pub({'Accept-Language': 'en-US,en;q=0.9'})
    check(h1 == 'Secure profile share' and 'Prepared for' in body, 'P1 English browser → English')
    check(sw, 'P1 language switch is present on the profile page')
    h1, body, sw = pub({'Accept-Language': 'en-US,en;q=0.9'}, '?lang=es')
    check(h1 == 'Perfil compartido de forma segura' and 'Preparado para' in body, 'P1 ?lang=es overrides an English browser')
    h1, body, sw = pub({'Accept-Language': 'es-PR,es;q=0.9'}, '?lang=en')
    check(h1 == 'Secure profile share', 'P1 ?lang=en overrides a Spanish browser')
    b.close()

print('\n%s' % ('ALL PASS' if not fails else 'FAILED: %d' % len(fails)))
sys.exit(1 if fails else 0)
