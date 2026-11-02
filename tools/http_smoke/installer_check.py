#!/usr/bin/env python3
# © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
"""v1.171.5 — installer behaviour over HTTP (TEST ONLY; driven by installer_check.sh).
Runs the real web installer against an empty database with FLX_CONFIG pointing at a temp file, once over plain http and
once behind a TLS proxy (X-Forwarded-Proto: https), then checks what it wrote and that the front controller now serves the app."""
import os, re, sys, subprocess, urllib.request, urllib.parse, http.cookiejar
BASE = os.environ['FLX_BASE']; CFG = os.environ['FLX_CONFIG']; FAILS = [0]
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)
def req(op, path, data=None, headers=None):
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    rq = urllib.request.Request(BASE + path, data=body, headers=headers or {})
    try:
        r = op.open(rq, timeout=120); return r.status, r.read().decode('utf-8', 'replace'), r.headers
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace'), e.headers
def rec(label, ok, extra=''):
    print(('PASS' if ok else 'FAIL'), '—', label, extra)
    if not ok: FAILS[0] += 1
def run_install(tag, hdrs):
    if os.path.exists(CFG): os.unlink(CFG)
    op = client()
    s, b, hd = req(op, '/', headers=hdrs)
    rec(f'installer[{tag}] with no config, / hands off to the installer (FLX_CONFIG honoured)', s == 302 and 'install.php' in (hd.get('Location') or ''), f'status {s} {hd.get("Location")}')
    s, b, _ = req(op, '/install.php', headers=hdrs)
    m = re.search(r'name="_csrf" value="([^"]+)"', b)
    rec(f'installer[{tag}] form renders with a CSRF token', s == 200 and bool(m), f'status {s}')
    # v1.171.10: ext-intl is a hard requirement (listed, and OK on this host — the install below would be refused otherwise)
    mi = re.search(r'PHP extension: intl.*?<span class="pill (pass|fail|warn)">', b, re.S)
    rec(f'installer[{tag}] lists PHP extension intl as a requirement and it is satisfied here', bool(mi) and mi.group(1) == 'pass', f'row={bool(mi)} pill={mi.group(1) if mi else None}')
    data = {'_csrf': m.group(1) if m else '', 'db_host': os.environ['FLX_DBHOST'], 'db_name': os.environ['FLX_DBNAME'], 'db_user': os.environ['FLX_DBUSER'],
            'db_pass': os.environ['FLX_DBPASS'], 'app_env': 'dev', 'admin_name': 'Test Admin', 'admin_email': 'admin@install.test', 'admin_pass': 'Install-Pass-12345',
            'admin_pass2': 'Install-Pass-12345', 'org_name': 'Install Org', 'org_shape': 'platform', 'key_passphrase': 'correct horse battery', 'key_passphrase2': 'correct horse battery'}
    s, b, _ = req(op, '/install.php', data, hdrs)
    rec(f'installer[{tag}] completes', s == 200 and os.path.isfile(CFG), f'status {s}')
    mt = re.search(r'Installation complete — created (\d+) tables', b)
    rec(f'installer[{tag}] reports the tables it created (v1.175.0: 94, incl. auth_email_codes, password_resets, terms_acceptances, terms_versions and tenant_licenses)', bool(mt) and mt.group(1) == '94', f'tables={mt.group(1) if mt else None}')
    # v1.174.0: every table of the new installation carries the fchc_ prefix, and none is left without it
    def _q(sql):
        a = ['mysql', '-u' + os.environ['FLX_DBUSER'], '-h' + os.environ['FLX_DBHOST'], '-N', '-e', sql]
        if os.environ.get('FLX_DBPASS'): a.insert(2, '-p' + os.environ['FLX_DBPASS'])
        return subprocess.check_output(a).decode().strip()
    try:
        withp = _q("select count(*) from information_schema.tables where table_schema='%s' and table_name like 'fchc\\_%%'" % os.environ['FLX_DBNAME'])
        nop = _q("select count(*) from information_schema.tables where table_schema='%s' and table_name not like 'fchc\\_%%'" % os.environ['FLX_DBNAME'])
    except Exception as e:
        withp, nop = 'err', str(e)
    rec(f'installer[{tag}] creates every table with the fchc_ prefix (94) and none without it', withp == '94' and nop == '0', f'prefixed={withp} unprefixed={nop}')
    cfgp = subprocess.check_output(['php', '-r', '$c=require getenv("FLX_CONFIG"); echo $c["db_prefix"]??"";']).decode() if os.path.isfile(CFG) else ''
    rec(f'installer[{tag}] writes db_prefix = fchc_ to the configuration', cfgp == 'fchc_', f'db_prefix={cfgp}')
    cfg = subprocess.check_output(['php', '-r', '$c=require getenv("FLX_CONFIG"); echo json_encode([$c["app_url"]??null,$c["hsts"]??null,!empty($c["crypto_data_key"])]);']).decode() if os.path.isfile(CFG) else '[]'
    import json; url, hsts, keys = (json.loads(cfg) + [None, None, None])[:3]
    want_https = tag == 'https'
    rec(f'installer[{tag}] writes app_url with the scheme it was reached over', isinstance(url, str) and url.startswith('https://' if want_https else 'http://'), f'app_url={url}')
    rec(f'installer[{tag}] sets hsts only for https', hsts is want_https, f'hsts={hsts}')
    rec(f'installer[{tag}] generated encryption keys', keys is True)
    s, b, hd = req(client(), '/login', headers=hdrs)
    rec(f'installer[{tag}] afterwards the app serves the sign-in page (not the installer)', s == 200 and 'Sign in' in b, f'status {s}')
    s, b, hd = req(client(), '/', headers=hdrs)
    rec(f'installer[{tag}] afterwards / goes to login, not install.php', s == 302 and 'install.php' not in (hd.get('Location') or ''), f'status {s} {hd.get("Location")}')
    # v1.172.0 — the owner's first-installation path: the administrator created by the installer signs in and sets the
    # installation up in the platform console WITHOUT being stopped for two-factor (it is a setting, off on a new install).
    # (http scenario only: in the https scenario the session cookie is Secure, and this plain-http test client rightly does not send it back.)
    if tag != 'http': return
    op2 = client(); s, b, hd = req(op2, '/login', headers=hdrs)
    m2 = re.search(r'name="_csrf" value="([^"]+)"', b)
    s, b, hd = req(op2, '/login', {'_csrf': m2.group(1) if m2 else '', 'email': 'admin@install.test', 'password': 'Install-Pass-12345'}, hdrs)
    rec(f'installer[{tag}] the new administrator signs in with the password chosen in the installer (no second step asked)', s == 302 and 'verify' not in (hd.get('Location') or ''), f'status {s} {hd.get("Location")}')
    for path, needle in (('/admin/tenants', 'data-notice="platform-2fa-optional"'), ('/admin/system', 'Encryption keys'), ('/admin/email', 'Outbox'), ('/admin/security', 'id="signin-policy"')):
        s, b, hd = req(op2, path, headers=hdrs)
        rec(f'installer[{tag}] first set-up: {path} opens for the new administrator without two-factor', s == 200 and needle in b, f'status {s} {hd.get("Location")}')
    s, b, hd = req(op2, '/profile', headers=hdrs)
    rec(f'installer[{tag}] the profile offers two-factor set-up with an authenticator app', s == 200 and 'value="2fa_start"' in b, f'status {s}')
    s, b, hd = req(client(), '/forgot', headers=hdrs)
    rec(f'installer[{tag}] "Forgot your password?" page is served on a fresh install', s == 200 and 'name="email"' in b, f'status {s}')
scenario = sys.argv[1]
run_install(scenario, {'X-Forwarded-Proto': 'https'} if scenario == 'https' else {})
print('\nALL PASS' if not FAILS[0] else '\nSOME FAILED'); sys.exit(1 if FAILS[0] else 0)
