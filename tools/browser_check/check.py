#!/usr/bin/env python3
# © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
"""FlxCoreHC v1.172.0 — real-browser checks (see run.sh). Each check asserts what a person would see or get."""
import base64, hashlib, hmac, json, os, re, struct, subprocess, sys, time
from playwright.sync_api import sync_playwright

BASE = os.environ['FLX_BASE']; SHOTS = os.environ.get('FLX_SHOTS', '/tmp'); IDS = json.load(open(os.environ['FLX_IDS']))
FAILS = [0]
def rec(label, ok, extra=''):
    print(('PASS' if ok else 'FAIL'), '—', label, extra)
    if not ok: FAILS[0] += 1

def _tables():
    import re as _re
    src=open(os.path.join(os.path.dirname(os.path.abspath(__file__)),'..','..','database','tables.php'),encoding='utf-8').read()
    return _re.findall(r"^\s*'([a-z0-9_]+)',",src,_re.M)
_TBL=None
def px(sql):
    """v1.174.0: the tables carry the installation's prefix (FLX_DBPREFIX); the test SQL below names them logically. Strings in quotes are left alone."""
    global _TBL
    pre=os.environ.get('FLX_DBPREFIX','')
    if not pre: return sql
    import re as _re
    if _TBL is None: _TBL=_re.compile(r'(?<![\w.`])('+'|'.join(sorted(map(_re.escape,_tables()),key=len,reverse=True))+r')(?![\w])')
    parts=_re.split(r"('(?:[^'\\]|\\.|'')*')",sql)
    return ''.join(p if i%2 else _TBL.sub(lambda m: pre+m.group(1),p) for i,p in enumerate(parts))

def dbq(sql):
    sql = px(sql)
    a = ['mysql', '-u' + os.environ.get('FLX_DBUSER', 'root'), '-h' + os.environ.get('FLX_DBHOST', '127.0.0.1'), '-N', '-e', sql, os.environ.get('FLX_DBNAME', 'flx_browser')]
    if os.environ.get('FLX_DBPASS'): a.insert(2, '-p' + os.environ['FLX_DBPASS'])
    return subprocess.check_output(a).decode().strip()
def totp(secret, step=0):
    s = secret.replace(' ', '').upper(); key = base64.b32decode(s + '=' * ((8 - len(s) % 8) % 8))
    c = struct.pack('>Q', int(time.time()) // 30 + step); h = hmac.new(key, c, hashlib.sha1).digest(); o = h[-1] & 15
    return '%06d' % ((struct.unpack('>I', h[o:o + 4])[0] & 0x7fffffff) % 1000000)
def last_mail(to):
    out = [json.loads(l) for l in open(os.environ['FLX_MAILBOX'], encoding='utf-8') if l.strip()]
    out = [m for m in out if to in m['to']]
    return out[-1] if out else None
def pset(k, v): dbq("insert into platform_settings (skey,sval,updated_at) values ('%s','%s',utc_timestamp()) on duplicate key update sval='%s'" % (k, v, v))

violations = []   # Content-Security-Policy violations and page errors seen anywhere during the run
def watch(page):
    page.on('console', lambda m: violations.append(m.text) if ('Content Security Policy' in m.text or 'Refused to' in m.text) else None)
    page.on('pageerror', lambda e: violations.append('pageerror: ' + str(e)))
def login(ctx, email, pw='SmokePass12345'):
    p = ctx.new_page(); watch(p); p.goto(BASE + '/login')
    p.fill('input[name=email]', email); p.fill('input[name=password]', pw); p.click('button.btn.gold')
    p.wait_for_load_state('networkidle'); return p

with sync_playwright() as pw:
    br = pw.chromium.launch()
    # ---- B1: guided onboarding switches between a person and an organization --------------------------------------
    cx = br.new_context(); p = login(cx, 'owner@smoke.test'); p.goto(BASE + '/holders/onboard')
    rec('B1 the wizard opens on the person path (first name visible, no organization name)', p.is_visible('input[name=first_name]') and p.locator('input[name=org_name]').count() == 0)
    p.click('text=An organization — group, clinic, laboratory'); p.wait_for_load_state('networkidle')
    opts = p.locator('select[name=org_provider_type] option').all_inner_texts()
    rec('B1 choosing "An organization" shows the organization name and ONLY organization types', p.is_visible('input[name=org_name]') and p.locator('input[name=first_name]').count() == 0 and p.locator('select[name=provider_type]').count() == 0 and not any('Physician' in o for o in opts) and len(opts) == 3, f'types={opts}')
    p.screenshot(path=os.path.join(SHOTS, 'B1_onboard_organization.png'))
    p.fill('input[name=org_name]', 'Browser Check Laboratory'); p.select_option('select[name=org_provider_type]', 'lab'); p.click('button.btn.gold'); p.wait_for_load_state('networkidle')
    rec('B1 submitting it continues to the organization identifiers step', '/onboard/identifiers' in p.url and p.is_visible('text=Organization NPI') and p.locator('select[name=birth_month]').count() == 0, p.url)
    # ---- B2: case requirement status — the select submits by itself, without an inline handler --------------------
    tid = dbq("select tenant_id from users where email='owner@smoke.test'"); rq = '44444444-4444-4555-8666-%012d' % int(time.time() % 1e9)
    dbq("insert into credentialing_case_requirements (id,tenant_id,case_id,requirement_code,label,status,is_required,created_at) values ('%s','%s','%s','state_medical_license','Browser requirement','pending',1,utc_timestamp())" % (rq, tid, IDS['case_P1']))
    p.goto(BASE + '/cases/' + IDS['case_P1'])
    p.locator('form:has(input[name=req_id][value="%s"]) select[name=status]' % rq).select_option('in_progress'); p.wait_for_load_state('networkidle')
    rec('B2 changing a case requirement status in the list saves it straight away (script allowed by the CSP)', dbq("select status from credentialing_case_requirements where id='%s'" % rq) == 'in_progress', dbq("select status from credentialing_case_requirements where id='%s'" % rq))
    # ---- B3: two-factor with an authenticator app: the QR on the page really scans -------------------------------
    cx2 = br.new_context(); p2 = login(cx2, 'twofa@smoke.test'); p2.goto(BASE + '/profile')
    p2.click('button:has-text("Set up with an app")'); p2.wait_for_load_state('networkidle')
    box = p2.locator('[data-qr=totp] svg').bounding_box()
    key = re.sub(r'\s+', '', p2.locator('div.mono').first.inner_text())
    rec('B3 the enrolment page shows a QR code of usable size and the setup key', bool(box) and box['width'] >= 150 and re.fullmatch(r'[A-Z2-7]{16,64}', key) is not None, f'box={box and int(box["width"])}px key_len={len(key)}')
    shot = os.path.join(SHOTS, 'B3_qr.png'); p2.locator('[data-qr=totp]').screenshot(path=shot); p2.screenshot(path=os.path.join(SHOTS, 'B3_enrolment_page.png'), full_page=True)
    decoded = None
    try:
        import cv2
        # OpenCV's decoder is a known weak point on some dense symbols (the same symbol fails for the reference library's
        # identical matrix), so it is tried at several scales and with a quiet zone; the assertion below is unchanged.
        base = cv2.imread(shot); decoded = ''
        for fx in (3, 4, 5, 6, 2, 8):
            img = cv2.resize(base, None, fx=fx, fy=fx, interpolation=cv2.INTER_NEAREST)
            img = cv2.copyMakeBorder(img, 40, 40, 40, 40, cv2.BORDER_CONSTANT, value=(255, 255, 255))
            decoded, _, _ = cv2.QRCodeDetector().detectAndDecode(img)
            if decoded: break
    except ImportError:
        decoded = None
    if decoded is None:
        print('NOT RUN — B3 QR decoding (OpenCV for Python is not installed: pip install opencv-python-headless)')
    else:
        m = re.search(r'secret=([A-Z2-7]+)', decoded or '')
        rec('B3 a QR reader decodes the code on the page to an otpauth:// address carrying the SAME secret as the setup key', (decoded or '').startswith('otpauth://totp/') and bool(m) and m.group(1) == key, f'decoded={decoded[:38]}… secret_matches={bool(m) and m.group(1) == key}')
    p2.click('summary:has-text("Microsoft Authenticator")')
    rec('B3 the step-by-step instructions open for the chosen app', p2.is_visible('text=Choose “Other account (Google, Facebook, etc.)”.'))
    p2.fill('input[name=code]', totp(key)); p2.click('button:has-text("Turn on two-factor")'); p2.wait_for_load_state('networkidle')
    rec('B3 the code generated from that secret turns two-factor on and backup codes are shown', dbq("select concat(totp_enabled,'|',mfa_method) from users where email='twofa@smoke.test'") == '1|totp' and p2.is_visible('text=Save these backup codes now'))
    # ---- B4: e-mailed code at sign-in ---------------------------------------------------------------------------
    for k, v in {'mail_enabled': '1', 'mail_transport': 'smtp', 'smtp_host': '127.0.0.1', 'smtp_port': os.environ['FLX_SMTP_PORT'], 'smtp_secure': 'none', 'smtp_user': '', 'mail_from': 'no-reply@smoke.test'}.items(): pset(k, v)
    cx3 = br.new_context(); p3 = login(cx3, 'mfaemail@smoke.test'); p3.goto(BASE + '/profile'); p3.click('button:has-text("Use e-mail codes")'); p3.wait_for_load_state('networkidle')
    code = re.search(r'\n\s+(\d{6})\s*\n', last_mail('mfaemail@smoke.test')['body']).group(1)
    p3.fill('input[name=code]', code); p3.click('button:has-text("Turn on two-factor")'); p3.wait_for_load_state('networkidle')
    rec('B4 the e-mailed code turns two-factor on with the e-mail method', dbq("select concat(totp_enabled,'|',mfa_method) from users where email='mfaemail@smoke.test'") == '1|email', dbq("select concat(totp_enabled,'|',mfa_method) from users where email='mfaemail@smoke.test'") + ' ' + p3.url)
    p3.screenshot(path=os.path.join(SHOTS, 'B4_email_enrol.png'), full_page=True)
    cx4 = br.new_context(); p4 = login(cx4, 'mfaemail@smoke.test')
    onverify = '/login/verify' in p4.url and p4.is_visible('text=Send a new code')
    code2 = re.search(r'\n\s+(\d{6})\s*\n', last_mail('mfaemail@smoke.test')['body']).group(1)
    p4.fill('input[name=code]', code2); p4.click('button:has-text("Verify and sign in")'); p4.wait_for_load_state('networkidle')
    rec('B4 with e-mail codes on, sign-in asks for the e-mailed code and completes with it', onverify and '/login' not in p4.url, p4.url)
    p4.screenshot(path=os.path.join(SHOTS, 'B4_after_email_code.png'))
    # ---- B5: forgot password, end to end ---------------------------------------------------------------------------
    cx5 = br.new_context(); p5 = cx5.new_page(); watch(p5); p5.goto(BASE + '/login'); p5.click('text=Forgot your password?'); p5.wait_for_load_state('networkidle')
    p5.fill('input[name=email]', 'staff@smoke.test'); p5.click('button:has-text("Send the link")'); p5.wait_for_load_state('networkidle')
    link = re.search(r'(http\S+/reset/[a-f0-9]{64})', last_mail('staff@smoke.test')['body']).group(1)
    p5.goto(link); p5.fill('input[name=new]', 'BrowserNewPass-1'); p5.fill('input[name=confirm]', 'BrowserNewPass-1'); p5.click('button:has-text("Save new password")'); p5.wait_for_load_state('networkidle')
    atlogin = p5.url.endswith('/login')
    p5.fill('input[name=email]', 'staff@smoke.test'); p5.fill('input[name=password]', 'BrowserNewPass-1'); p5.click('button.btn.gold'); p5.wait_for_load_state('networkidle')
    rec('B5 "Forgot your password?" → e-mailed link → new password → sign-in with it works', atlogin and '/login' not in p5.url, p5.url)
    # ---- B6: platform console is reachable without two-factor on a new installation, with the notice ------------------
    cx6 = br.new_context(); p6 = login(cx6, 'padmin@smoke.test'); p6.goto(BASE + '/admin/security')
    rec('B6 a platform administrator without two-factor reaches the console and sees the sign-in policy panel', '/admin/security' in p6.url and p6.is_visible('#signin-policy') and p6.is_visible('[data-notice=platform-2fa-optional]'), p6.url)
    p6.screenshot(path=os.path.join(SHOTS, 'B6_signin_policy.png'), full_page=True)
    # ---- B8: the logo area of the side panel is light in the light theme and stays dark in the dark theme -------------
    def _lum(page):
        v = page.evaluate("(() => { const c = getComputedStyle(document.querySelector('.side-top')).backgroundColor.match(/[\\d.]+/g).map(Number); const n = getComputedStyle(document.querySelector('.side')).backgroundImage; return [c[0], c[1], c[2], c.length > 3 ? c[3] : 1]; })()")
        return v
    p6.goto(BASE + '/admin/security'); p6.evaluate("document.documentElement.setAttribute('data-theme','light')")
    lt = _lum(p6); p6.screenshot(path=os.path.join(SHOTS, 'B8_side_light.png'), clip={'x': 0, 'y': 0, 'width': 700, 'height': 120})
    p6.evaluate("document.documentElement.setAttribute('data-theme','dark')")
    dk = _lum(p6); p6.screenshot(path=os.path.join(SHOTS, 'B8_side_dark.png'), clip={'x': 0, 'y': 0, 'width': 700, 'height': 120})
    rec('B8 light theme: the logo area of the side panel is light; dark theme: it stays dark', min(lt[:3]) >= 235 and lt[3] > 0.9 and max(dk[:3]) <= 80, f'light={lt} dark={dk}')
    # ---- B7: nothing on the visited pages was blocked by the Content-Security-Policy -----------------------------------
    rec('B7 no Content-Security-Policy violation and no script error on any page visited', not violations, json.dumps(violations[:3]))
    br.close()
print('\nALL PASS' if FAILS[0] == 0 else '\nSOME FAILED')
sys.exit(1 if FAILS[0] else 0)
