# © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
import collections
import json, re, sys, http.cookiejar, urllib.request, urllib.parse
import os, subprocess
ids = json.load(open(os.environ['FLX_IDS']))
BASE=os.environ.get('FLX_BASE','http://127.0.0.1:8099')
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
def client():
    cj=http.cookiejar.CookieJar()
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj), NoRedirect)
def req(op, path, data=None):
    url=BASE+path
    body=urllib.parse.urlencode(data).encode() if data is not None else None
    try:
        r=op.open(urllib.request.Request(url, data=body), timeout=30)
        return r.status, r.read().decode('utf-8','replace'), r.headers
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8','replace'), e.headers
def csrf(html):
    m=re.search(r'name="_csrf" value="([^"]+)"', html); return m.group(1) if m else ''
def login(email):
    op=client(); s,h,_=req(op,'/login'); 
    s,h,hd=req(op,'/login',{'_csrf':csrf(h),'email':email,'password':'SmokePass12345'})
    return op, s, hd.get('Location')
BAD=re.compile(r'(Fatal error|Uncaught|Parse error|Warning:|Notice:|Deprecated:|Undefined (variable|array key|index|offset)|Stack trace)', re.I)
def hit(op, path, label=None):
    s,b,hd=req(op,path)
    bad=BAD.search(b)
    loc=hd.get('Location') if s in (301,302,303) else ''
    return s, (bad.group(0) if bad else ''), loc, len(b), b

BASEPATH=''
FAILS=[0]
# preflight: the harness itself must be talking to a configured app (index.php redirecting to install.php means FLX_CONFIG was ignored)
_pf=client(); _s,_b,_hd=req(_pf,'/login')
if _s!=200 or 'install.php' in (_hd.get('Location') or ''):
    print('FAIL — preflight: /login did not render (status %s, Location %s) — the app is not configured for this run'%(_s,_hd.get('Location'))); print('\nSOME FAILED'); sys.exit(1)
def dbq(sql):
    a=['mysql','-u'+os.environ.get('FLX_DBUSER','root'),'-h'+os.environ.get('FLX_DBHOST','127.0.0.1'),'-N','-e',sql,os.environ.get('FLX_DBNAME','flx_smoke')]
    if os.environ.get('FLX_DBPASS'): a.insert(2,'-p'+os.environ['FLX_DBPASS'])
    return subprocess.check_output(a).decode().strip()

def post(op, path, data):
    s,b,hd=req(op,'/login') if False else (None,None,None)
def page_token(op, path):
    s,b,hd=req(op,path); return csrf(b), s, b
out=[]
def rec(label, ok, extra=''):
    print(('PASS' if ok else 'FAIL'), '—', label, extra)
    if not ok: FAILS[0]+=1
# 1/2 verify permission
op,_,_=login('coord@smoke.test'); tok,s,b=page_token(op,'/')
s,b,hd=req(op,f'/documents/{ids["doc_P1"]}/verify',{'_csrf':tok,'method':'copy'}); rec('coordinator cannot verify a document (documents.verify)', s==403, f'status {s}')
op,_,_=login('staff@smoke.test'); tok,s,b=page_token(op,'/')
s,b,hd=req(op,f'/documents/{ids["doc_P1"]}/verify',{'_csrf':tok,'method':'copy'}); rec('staff can verify a document', s in (302,303), f'status {s}')
# credential edit page hides verify buttons for coordinator, shows for staff
op,_,_=login('coord@smoke.test'); s,b,hd=req(op,f'/credentials/{ids["cred_P1"]}/edit'); rec('coordinator credential page has no Verify/Mark verified controls', 'Mark verified' not in b and '/verify"' not in b, f'status {s}')
op,_,_=login('staff@smoke.test'); s,b,hd=req(op,f'/credentials/{ids["cred_P1"]}/edit'); rec('staff credential page offers verification', ('Mark verified' in b) or ('/verify' in b) or ('Clear' in b), f'status {s}')
# 4 restricted write to P2 credential
op,_,_=login('restricted@smoke.test'); tok,s,b=page_token(op,f'/credentials/{ids["cred_P1"]}/edit')
s,b,hd=req(op,f'/credentials/{ids["cred_P2"]}/edit',{'_csrf':tok,'name':'tampered','credential_type':'state_license'}); rec('restricted user cannot POST-edit P2 credential', s in (403,404), f'status {s}')
# 7 dashboard content
op,_,_=login('restricted@smoke.test'); s,rb,hd=req(op,'/')
op,_,_=login('owner@smoke.test'); s,ob,hd=req(op,'/')
rec('restricted dashboard never names P2 (Bravo)', 'Bravo' not in rb and 'B. Bravo' not in rb)
rec('owner dashboard shows both providers/expiries', 'Bravo' in ob or 'P2 License' in ob)
# lists
for path,name in [('/credentials','credentials'),('/holders','holders'),('/tasks','tasks'),('/calendar','calendar'),('/cases','cases')]:
    op,_,_=login('restricted@smoke.test'); s,b,hd=req(op,path)
    rec(f'restricted {name} list hides P2', 'Bravo' not in b and 'P2 License' not in b and 'Renew P2' not in b and 'P2 event' not in b, f'status {s}')
# 3 custom portal role cannot edit P2 credential or read P2 docs
op,_,_=login('customportal@smoke.test'); tok,s,b=page_token(op,'/me')
s,b,hd=req(op,f'/credentials/{ids["cred_P2"]}/edit',{'_csrf':tok,'name':'x','credential_type':'other'}); rec('custom portal role cannot edit another provider credential', s in (403,404), f'status {s}')
s,b,hd=req(op,f'/documents/{ids["doc_P2"]}'); rec('custom portal role cannot read another provider document', s==404, f'status {s}')
s,b,hd=req(op,'/'); rec('custom portal role is redirected from the dashboard to /me', s==302 and (hd.get('Location') or '').endswith('/me'), f'status {s} {hd.get("Location")}')
# 5 manager cannot grant owner
op,_,_=login('manager@smoke.test'); tok,s,b=page_token(op,'/team')
uid=dbq("select id from users where email='staff@smoke.test'")
s,b,hd=req(op,'/team',{'_csrf':tok,'action':'role','user_id':uid,'role':'tenant_owner'})
role=dbq("select role from users where email='staff@smoke.test'")
rec('manager cannot promote a user to tenant_owner', role!='tenant_owner', f'status {s}, role now {role}')

# ---- v1.171.0: session re-validation (S-2) ----
def sv_login(email): return login(email)[0]
op=sv_login('victim@smoke.test'); s,_,_=req(op,'/'); rec('S2 victim can use the app before suspension', s==200, f'status {s}')
dbq("update users set status='suspended' where email='victim@smoke.test'")
s,_,hd=req(op,'/'); rec('S2 suspending a user ends their live session on the very next request', s==302 and (hd.get('Location') or '').endswith('/login'), f'status {s} {hd.get("Location")}')
op=sv_login('victim2@smoke.test'); s,_,_=req(op,'/'); 
dbq("update users set password_hash='$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHRzb21l$aGFzaGhhc2hoYXNoaGFzaGhhc2hoYXNoaGFzaGhhc2g' where email='victim2@smoke.test'")
s,_,hd=req(op,'/'); rec('S2 a password reset ends the user\'s other sessions', s==302 and (hd.get('Location') or '').endswith('/login'), f'status {s}')
op=sv_login('promo@smoke.test'); s,_,_=req(op,'/team'); before=s
dbq("update users set role='read_only' where email='promo@smoke.test'")
s,_,_=req(op,'/team'); rec('S2 a role change applies immediately (manager -> read-only loses /team)', before==200 and s in (302,403), f'before {before} after {s}')
# ---- v1.171.0: S-1 role ceiling over HTTP ----
op=sv_login('manager@smoke.test'); tok,_,_=page_token(op,'/organization/roles/new')
s,b,hd=req(op,'/organization/roles',{'_csrf':tok,'action':'create','name':'HTTP Escalator','capabilities[]':'pii.view'})
n=dbq("select count(*) from roles where name='HTTP Escalator'"); rec('S1 manager POST creating a role with a capability they lack is refused', n=='0', f'status {s}, rows {n}')
# ---- v1.171.0: B-4 need-to-know over HTTP ----
op=sv_login('restricted@smoke.test')
s,_,_=req(op,'/audit'); rec('B4 restricted user gets 403 on the organization-wide /audit log', s in (403,302), f'status {s}')
s,b,_=req(op,'/audit/export.csv'); rec('B4 restricted user cannot export the audit log', s in (403,302) and 'Bob Bravo' not in b, f'status {s}')
s,b,_=req(op,'/notifications'); rec('B4 restricted /notifications shows P1 notice and hides P2 notice', 'NOTICE-P1' in b and 'NOTICE-P2' not in b and 'Bravo' not in b, f'status {s}')
# ---- v1.171.0: B-1 provider edit round-trip over HTTP ----
op=sv_login('owner@smoke.test'); tok,s,b=page_token(op,f'/holders/{ids["P2"]}/edit')
s,b,hd=req(op,f'/holders/{ids["P2"]}',{'_csrf':tok,'first_name':'Bob','last_name':'Bravo','provider_type':'pa','email':'bob@smoke.test','primary_specialty':'Cardiology','caqh_id':'99887766','medicare_ptan':'PTAN1'})
pt=dbq(f"select concat(ifnull(provider_type,'NULL'),'|',ifnull(primary_specialty,'NULL'),'|',ifnull(caqh_id,'NULL'),'|',ifnull(medicare_ptan,'NULL')) from credential_holders where id='{ids['P2']}'")
rec('B1 editing a provider as PA keeps type=pa and saves specialty / CAQH / PTAN', pt=='pa|Cardiology|99887766|PTAN1', f'status {s} row {pt}')
# ---- v1.172.0: the platform console's two-factor requirement is a setting, OFF on a new installation ----
def setting(k): return dbq("select ifnull((select sval from platform_settings where skey='%s'),'(unset)')"%k)
op=sv_login('padmin@smoke.test'); s,b,hd=req(op,'/admin/tenants')
rec('P1 new installation: a platform admin WITHOUT 2FA can open the console (set-up is not blocked)', s==200 and setting('platform_require_2fa')=='(unset)', f'status {s} setting={setting("platform_require_2fa")}')
rec('P1 the console reminds that two-factor is not required yet (notice, not a block)', 'data-notice="platform-2fa-optional"' in b and 'Sign-in policy' in b)
s,b,_=req(op,'/admin/security'); tokp=csrf(b)
rec('P2 Console → Security shows the sign-in policy panel with every platform administrator and their two-factor status', s==200 and 'id="signin-policy"' in b and 'Test Platform Admin' in b and 'Two-factor optional for platform administrators' in b and not BAD.search(b), f'status {s}')
req(op,'/admin',{'_csrf':tokp,'action':'signin_policy','platform_require_2fa':'1','mfa_email_allowed':'1','current_password':'WrongPass00000'})
s,b,_=req(op,'/admin/security')
rec('P3 changing the policy needs the current password (wrong password: nothing changes)', setting('platform_require_2fa')=='(unset)' and 'Your current password is incorrect.' in b, f'setting={setting("platform_require_2fa")}')
req(op,'/admin',{'_csrf':csrf(b),'action':'signin_policy','platform_require_2fa':'1','mfa_email_allowed':'1','current_password':'SmokePass12345'})
s,b,_=req(op,'/admin/security')
rec('P4 an administrator without two-factor cannot switch the requirement on (would lock themselves out of the console)', setting('platform_require_2fa')=='(unset)' and 'Turn on two-factor for your own account first' in b, f'setting={setting("platform_require_2fa")}')
ops=sv_login('staff@smoke.test'); tks,_,_=page_token(ops,'/profile'); s,_,_=req(ops,'/admin',{'_csrf':tks,'action':'signin_policy','platform_require_2fa':'1','current_password':'SmokePass12345'})
rec('P5 a user who is not a platform administrator cannot change the policy', s==403 and setting('platform_require_2fa')=='(unset)', f'status {s}')
# ---- v1.171.0: S-3 platform console requires 2FA — unchanged assertion; its precondition is now explicit: the requirement is ON ----
dbq("insert into platform_settings (skey,sval,updated_at) values ('platform_require_2fa','1',utc_timestamp()) on duplicate key update sval='1'")
op=sv_login('padmin@smoke.test'); s,_,hd=req(op,'/admin/tenants')
rec('S3 platform admin WITHOUT 2FA is sent to their profile, not the console', s==302 and (hd.get('Location') or '').endswith('/profile'), f'status {s} {hd.get("Location")}')
dbq("update users set totp_enabled=1 where email='padmin@smoke.test'")
s,b,_=req(op,'/admin/tenants'); rec('S3 once 2FA is on, the console opens', s==200, f'status {s}')
rec('P6 while the requirement is ON the "not required" notice is gone', 'data-notice="platform-2fa-optional"' not in b)
s,b,_=req(op,'/admin/security'); a0=dbq("select count(*) from audit_logs where action='signin_policy_set'")
req(op,'/admin',{'_csrf':csrf(b),'action':'signin_policy','mfa_email_allowed':'1','current_password':'SmokePass12345'})   # box unticked = turn the requirement OFF
s,b,_=req(op,'/admin/security')
rec('P7 a platform administrator can turn the requirement OFF with their password (audited)', setting('platform_require_2fa')=='0' and dbq("select count(*) from audit_logs where action='signin_policy_set'")==str(int(a0)+1) and 'Two-factor optional for platform administrators' in b, f'setting={setting("platform_require_2fa")}')
req(op,'/admin',{'_csrf':csrf(b),'action':'signin_policy','platform_require_2fa':'1','mfa_email_allowed':'1','current_password':'SmokePass12345'})
s,b,_=req(op,'/admin/security')
rec('P8 an administrator who uses two-factor can turn the requirement ON at any moment', setting('platform_require_2fa')=='1' and 'Two-factor required for platform administrators' in b, f'setting={setting("platform_require_2fa")}')
s,b,_=req(op,'/admin/system'); rec('S3/K System page renders with the encryption-key backup panel + reminder (no PHP warnings)', s==200 and 'Encryption keys' in b and not BAD.search(b), f'status {s}')
s,b,_=req(op,'/admin/tenants'); rec('K banner reminds the platform admin to back up the keys', s==200 and 'Back up your encryption keys' in b, f'status {s}')
tok,_,_=page_token(op,'/admin/system')
s,b,hd=req(op,'/admin',{'_csrf':tok,'action':'key_backup_download','key_passphrase':'correct horse battery','key_passphrase2':'correct horse battery','current_password':'wrong-password'})
rec('K key backup refuses a wrong current password', s in (302,303) and 'encryption-key-backup' not in b, f'status {s}')
s,b,hd=req(op,'/admin',{'_csrf':tok,'action':'key_backup_download','key_passphrase':'short','key_passphrase2':'short','current_password':'SmokePass12345'})
rec('K key backup refuses a weak passphrase', s in (302,303) and 'encryption-key-backup' not in b, f'status {s}')
s,b,hd=req(op,'/admin',{'_csrf':tok,'action':'key_backup_download','key_passphrase':'correct horse battery','key_passphrase2':'correct horse battery','current_password':'SmokePass12345'})
cfgkeys=json.loads(subprocess.check_output(['php','-r','$c=require (getenv("FLX_CONFIG") ?: "app/config.php"); echo json_encode([$c["crypto_data_key"],$c["crypto_index_key"]]);']).decode())
tmp=os.path.join(os.environ.get('TMPDIR','/tmp'),'smoke.flxkeys'); open(tmp,'w').write(b)
dec=subprocess.check_output(['php','-r','require "app/src/KeyBackup.php"; $k=FlxCoreHC\\KeyBackup::decrypt(file_get_contents($argv[1]),"correct horse battery"); echo json_encode($k ? [$k["crypto_data_key"],$k["crypto_index_key"]] : null);',tmp]).decode()
rec('K key backup downloads as an attachment, hides the keys, and decrypts back to the installed keys', s==200 and 'attachment' in (hd.get('Content-Disposition') or '') and cfgkeys[0] not in b and cfgkeys[1] not in b and json.loads(dec)==cfgkeys, f'status {s}')
s,_,_=req(op,'/admin',{'_csrf':tok,'action':'key_backup_confirm'})
s,b,_=req(op,'/admin/tenants'); rec('K after confirming, the reminder banner is gone', s==200 and 'Back up your encryption keys' not in b, f'status {s}')
dbq("delete from platform_settings where skey='keys_backup_confirmed_at'")
# ---- v1.171.3: sealed SQL dump ----
tok,_,_=page_token(op,'/admin/system')
s,b,hd=req(op,'/admin',{'_csrf':tok,'action':'db_backup_encrypted','dump_passphrase':'short','dump_passphrase2':'short','current_password':'SmokePass12345'})
rec('D6 sealed dump refuses a weak passphrase', s in (302,303) and 'FLXDUMP1' not in b, f'status {s}')
s,b,hd=req(op,'/admin',{'_csrf':tok,'action':'db_backup_encrypted','dump_passphrase':'correct horse battery','dump_passphrase2':'correct horse battery','current_password':'wrong-password'})
rec('D6 sealed dump refuses a wrong account password', s in (302,303) and 'FLXDUMP1' not in b, f'status {s}')
s,b,hd=req(op,'/admin',{'_csrf':tok,'action':'db_backup_encrypted','dump_passphrase':'correct horse battery','dump_passphrase2':'correct horse battery','current_password':'SmokePass12345'})
rec('D6 sealed dump downloads as an attachment with the FLXDUMP1 header', s==200 and 'attachment' in (hd.get('Content-Disposition') or '') and (hd.get('Content-Disposition') or '').endswith('.flxenc"') and b.startswith('FLXDUMP1'), f'status {s}')

# These console pages are DOCUMENTATION / error-log viewers whose prose legitimately contains the words "Uncaught",
# "Undefined array key" and "fatal error" (changelog entries, the test plan, the errors page intro). A 5xx is still a failure there;
# only the text-pattern scan is skipped for them.
DOCPAGES = ('/admin/errors', '/admin/changelog', '/admin/test-plan')
SKIP=re.compile(r'(logout|/delete|purge|/restore|switch|impersonat|/support|sign-out|signout|/export|\.csv|\.pdf|/cv\b|/pdf|/print|download|/ics|/calendar\.ics|mailto:|javascript:)', re.I)
def crawl(email, cap=220):
    op,s,loc=login(email)
    seen=set(); q=collections.deque(['/']); bad=[]; codes=collections.Counter()
    while q and len(seen)<cap:
        p=q.popleft()
        p=re.sub(r'#.*','',p)
        if p in seen: continue
        seen.add(p)
        s,b,hd=req(op,p)
        codes[s]+=1
        m=BAD.search(b) if (s!=302 and p.split('?')[0] not in DOCPAGES) else None
        if s>=500 or m: bad.append((p,s,b[m.start():m.start()+160].replace('\n',' ') if m else ''))
        if s==200:
            for h in re.findall(r'href="([^"]+)"', b):
                if h.startswith('http') or h.startswith('#') or SKIP.search(h): continue
                if h.startswith('/'): 
                    h=h.split('#')[0]
                    if h not in seen: q.append(h)
    return len(seen), dict(codes), bad

# ---- E: HTTP upload → encrypted on disk → download returns the original bytes -------------------------------
import uuid
op,_,_=login('owner@smoke.test')
cid=dbq("SELECT id FROM credentials WHERE deleted_at IS NULL LIMIT 1")
marker='HTTP-PHI-MARKER-'+uuid.uuid4().hex
pdf=('%PDF-1.4\n'+marker+'\n%%EOF').encode()
tok,_,_=page_token(op,'/credentials/'+cid+'/edit')
bd='----flx'+uuid.uuid4().hex
body=b''
for k,v in (('_csrf',tok),('kind','primary')):
    body+=('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n'%(bd,k,v)).encode()
body+=('--%s\r\nContent-Disposition: form-data; name="document"; filename="t.pdf"\r\nContent-Type: application/pdf\r\n\r\n'%bd).encode()+pdf+('\r\n--%s--\r\n'%bd).encode()
try:
    r=op.open(urllib.request.Request(BASE+'/credentials/'+cid+'/documents',data=body,headers={'Content-Type':'multipart/form-data; boundary='+bd}),timeout=30)
except urllib.error.HTTPError as e: r=e
row=dbq("SELECT CONCAT(id,'|',file_path) FROM credential_documents WHERE credential_id='%s' AND original_name='t.pdf' ORDER BY created_at DESC LIMIT 1"%cid)
did,fpath=row.split('|',1) if '|' in row else ('','')
raw=open(fpath,'rb').read() if fpath and os.path.isfile(fpath) else b''
rec('E HTTP upload stores the file sealed (header present, no plaintext on disk)', raw.startswith(b'FLXENC1\n') and marker.encode() not in raw)
s,b,hd=req(op,'/documents/'+did) if did else (0,'',{})
rec('E HTTP download returns the original bytes', s==200 and marker in b and hd.get('Content-Length')==str(len(pdf)), 'status=%s'%s)

# ---- D-5: CE window follows the tenant jurisdiction (PR = 36 months) -------------------------------------------
hid=dbq("SELECT id FROM credential_holders WHERE deleted_at IS NULL LIMIT 1")
tid=dbq("SELECT tenant_id FROM credential_holders WHERE id='%s'"%hid)
dbq("UPDATE tenants SET operating_states='PR' WHERE id='%s'"%tid)
s,b,hd=req(op,'/holders/'+hid)
rec('D-5 PR tenant: CE panel totals the last 36 months', s==200 and 'last 36 months' in b, 'status=%s'%s)
dbq("UPDATE tenants SET operating_states='FL' WHERE id='%s'"%tid)
s,b,hd=req(op,'/holders/'+hid)
rec('D-5 non-PR tenant: CE panel totals the last 24 months; PR-only readiness items absent', s==200 and 'last 24 months' in b and 'OCS / SICRO' not in b, 'status=%s 24m=%s ocs_ctx=%r'%(s,'last 24 months' in b,(b[max(0,b.find('OCS / SICRO')-80):b.find('OCS / SICRO')+60] if 'OCS / SICRO' in b else '')))
dbq("UPDATE tenants SET operating_states='' WHERE id='%s'"%tid)

# ---- B-8: the menu only offers pages the user can open (staff used to see Groups/Locations that returned 403) -----------
ops,_,_=login('staff@smoke.test'); s,b,_h=req(ops,'/'); b=b[b.find('<nav'):b.find('</nav>')] if '<nav' in b else b
rec('B-8 staff menu does not list Groups / Locations / Applications / Regulators', s==200 and not any(('href="'+BASEPATH+x+'"') in b for x in ('/groups','/locations','/applications','/regulators')), 'status=%s found=%s'%(s,[x for x in ('/groups','/locations','/applications','/regulators') if ('href="'+BASEPATH+x+'"') in b]))
opo,_,_=login('owner@smoke.test'); s,b,_h=req(opo,'/'); b=b[b.find('<nav'):b.find('</nav>')] if '<nav' in b else b
rec('B-8 owner menu still lists them', s==200 and all(('href="'+BASEPATH+x+'"') in b for x in ('/locations','/regulators')), 'status=%s'%s)

# ---- S7: org 2FA policy applies to every privileged user, before ANY route (billing/dashboard included) --------------
tid7=dbq("SELECT tenant_id FROM users WHERE email='manager@smoke.test'")
dbq("UPDATE tenants SET require_2fa_admins=1 WHERE id='%s'"%tid7)
opm,_,_=login('manager@smoke.test')
res={}
for path in ('/','/billing','/holders','/team'):
    s_,b_,h_=req(opm,path); res[path]=(s_, h_.get('Location'))
rec('S7 manager without 2FA is sent to /profile from / /billing /holders /team when the policy is on', all(v[0]==302 and (v[1] or '').endswith('/profile') for v in res.values()), str(res))
s_,b_,h_=req(opm,'/profile'); rec('S7 the profile page (where they enrol) stays reachable', s_==200, 'status=%s'%s_)
ops2,_,_=login('staff@smoke.test'); s_,b_,h_=req(ops2,'/'); rec('S7 staff (no privileged capability) is not gated', s_==200, 'status=%s'%s_)
dbq("UPDATE tenants SET require_2fa_admins=0 WHERE id='%s'"%tid7)
s_,b_,h_=req(opm,'/'); rec('S7 policy off again: manager reaches the dashboard', s_==200, 'status=%s'%s_)

# ---- v1.171.5 behavioural regressions over HTTP ------------------------------------------------------------------
# (B) forced sign-out: the user lands on /login WITH an explanation (password reset / suspension)
op=sv_login('victim3@smoke.test'); req(op,'/')
dbq("update users set status='suspended' where email='victim3@smoke.test'")
s,_,hd=req(op,'/'); loc=hd.get('Location') or ''
s2,b2,_=req(op,'/login')
rec('B3d-2 suspended user is sent to /login and SEES why they were signed out', s==302 and loc.endswith('/login') and 'signed out' in b2.lower(), f'status {s} {loc} flash_shown={"signed out" in b2.lower()}')
op=sv_login('victim4@smoke.test'); req(op,'/')
dbq("update users set password_hash='$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHRzb21l$aGFzaGhhc2hoYXNoaGFzaGhhc2hoYXNoaGFzaGhhc2g' where email='victim4@smoke.test'")
s,_,hd=req(op,'/'); s2,b2,_=req(op,'/login')
rec('B3d-2 after a password reset the /login page explains the sign-out', s==302 and 'signed out' in b2.lower(), f'status {s}')
s3,b3,_=req(op,'/login'); rec('B3d-2 the explanation is shown once (not on the next /login view)', 'signed out' not in b3.lower())
# (H) viewing /admin/email must not send queued mail
opa=sv_login('padmin@smoke.test'); s,_b,_=req(opa,'/admin/email')
st=dbq("select status from email_outbox where subject='SMOKE queued mail'"); att=dbq("select attempts from email_outbox where subject='SMOKE queued mail'")
rec('H GET /admin/email does not flush the outbox (mail stays queued, zero attempts)', s==200 and st=='queued' and att=='0', f'status {s} outbox={st} attempts={att}')
# (J) a refusal is styled as an error toast, a success as ok
opm=sv_login('manager@smoke.test'); tok,_,_=page_token(opm,'/team'); uid=dbq("select id from users where email='staff@smoke.test'")
req(opm,'/team',{'_csrf':tok,'action':'role','user_id':uid,'role':'tenant_owner'})
s,b,_=req(opm,'/team'); m=re.search(r'data-toast="([^"]*)" data-toast-type="([^"]*)"', b)
rec('J a refused action is shown with the error toast style', bool(m) and m.group(2)=='error', f'flash={m.groups() if m else None}')
opo=sv_login('owner@smoke.test'); tok,_,_=page_token(opo,'/profile')
req(opo,'/profile',{'_csrf':tok,'action':'password','current':'wrong-current-pw','new':'Another-Long-Pass-99'})
s,b,_=req(opo,'/profile'); m=re.search(r'data-toast="([^"]*)" data-toast-type="([^"]*)"', b)
rec('J a wrong-current-password refusal is shown with the error toast style', bool(m) and m.group(2)=='error', f'flash={m.groups() if m else None}')
# (K) a read-only user can enrol in their OWN 2FA (it is account security, not data)
opr=sv_login('readonly@smoke.test'); tok,_,_=page_token(opr,'/profile')
s,b,_=req(opr,'/profile',{'_csrf':tok,'action':'2fa_start'})
rec('K read-only user can start 2FA enrolment (secret shown, not refused as read-only)', s==200 and ('otpauth://' in b or 'secret' in b.lower()) and 'read-only' not in b.lower()[:400], f'status {s}')
tok,_,_=page_token(opr,'/profile'); s,b,_=req(opr,'/holders',{'_csrf':tok,'first_name':'x'})
rec('K read-only user is still refused on data writes', s in (403,302,404,405), f'status {s}')
# (D) FL-only tenant: the OCS/SICRO panel stays hidden even though an OCS record exists
hidP1=ids['P1']; tidx=dbq("SELECT tenant_id FROM credential_holders WHERE id='%s'"%hidP1)
dbq("UPDATE tenants SET operating_states='FL' WHERE id='%s'"%tidx)
s,b,_=req(opo,'/holders/'+hidP1); has=dbq("select count(*) from ocs_profiles where holder_id='%s'"%hidP1)
rec('D FL-only tenant with an existing OCS record still hides the OCS/SICRO panel', s==200 and has=='1' and 'OCS / SICRO' not in b, f'status {s} ocs_rows={has}')
dbq("UPDATE tenants SET operating_states='PR' WHERE id='%s'"%tidx); s,b,_=req(opo,'/holders/'+hidP1)
rec('D PR tenant shows the OCS/SICRO panel', s==200 and 'OCS / SICRO' in b, f'status {s}')
dbq("UPDATE tenants SET operating_states='' WHERE id='%s'"%tidx)
# (E) PR boards (category licensing) are listed on /regulators
dbq("UPDATE tenants SET operating_states='PR' WHERE id='%s'"%tidx)
dbq("INSERT INTO regulators (id,tenant_id,name,category,created_at) SELECT UUID(),'%s','Junta Examinadora de Enfermería','licensing',NOW() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM regulators WHERE tenant_id='%s' AND name='Junta Examinadora de Enfermería')"%(tidx,tidx))
s,b,_=req(opo,'/regulators'); rec('E a licensing-board regulator appears on /regulators', s==200 and 'Junta Examinadora de Enfermería' in b, f'status {s}')
dbq("UPDATE tenants SET operating_states='' WHERE id='%s'"%tidx)
# (O) every NPI in the CSV import template passes the real Luhn check
s,b,_=req(opo,'/holders/import/template'); import subprocess as _sp
npis=[c for line in b.splitlines()[1:] for c in line.split(',') if re.fullmatch(r'\d{10}', c)]
def _npi_ok(n):
    t=0
    for i,ch in enumerate(reversed('80840'+n)):
        x=int(ch)
        if i%2==1:
            x*=2; x = x-9 if x>9 else x
        t+=x
    return t%10==0
rec('O the provider-import CSV template only contains valid NPIs', s==200 and len(npis)>=1 and all(_npi_ok(n) for n in npis), f'npis={npis}')
# (M) Roles page: Master Admin listed once, no raw slug for a Manager, one audit row per role creation
s,b,_=req(opm,'/organization/roles'); rec('M the Roles page lists Master Admin exactly once', s==200 and b.count('<strong>Master Admin</strong>')==1, f'status {s} count={b.count("<strong>Master Admin</strong>")}')
s,b,_=req(opm,'/team'); rec('M a Manager never sees the raw slug tenant_owner on the Team page', s==200 and 'tenant_owner' not in b, f'status {s}')
tok,_,_=page_token(opm,'/organization/roles/new'); before=dbq("select count(*) from audit_logs where action='role_created'")
req(opm,'/organization/roles',{'_csrf':tok,'action':'create','name':'Audit Once Role','capabilities[]':'documents.view'})
after=dbq("select count(*) from audit_logs where action='role_created'")
rec('M creating one role writes exactly one "role_created" audit row', int(after)-int(before)==1, f'before {before} after {after}')
# (N) audit package: requirement names + evidence, no blank rows
opo=sv_login('owner@smoke.test'); s,b,_=req(opo,'/reports/audit/'+ids['P1'])
rows=re.findall(r'<h2>Requirement evidence</h2>(.*?)</table>', b, re.S)
tr=re.findall(r'<tr><td>(.*?)</td>\s*<td class="mono">(.*?)</td>\s*<td><span[^>]*>(.*?)</span></td>\s*<td>(.*?)</td></tr>', rows[0] if rows else '', re.S)
rec('N audit package evidence table has rows, each with a requirement name, a state and non-blank evidence/detail', s==200 and len(tr)>=1 and all(r[0].strip() and r[2].strip() and r[3].strip() for r in tr), f'status {s} rows={len(tr)}')
rec('N the Requirement column is not just the authority word (state/federal/regulator)', all(r[0].strip().lower() not in ('state','federal','regulator','payer','employer') for r in tr) and len(tr)>=1)


# ---- v1.171.6 behavioural regressions over HTTP (second independent runtime report) -------------------------------
import hmac, hashlib, base64, struct, time
def _totp(secret):
    key=base64.b32decode(secret.replace(' ','').upper()+'='*((8-len(secret.replace(' ',''))%8)%8))
    c=struct.pack('>Q', int(time.time())//30); h=hmac.new(key,c,hashlib.sha1).digest(); o=h[-1]&15
    return '%06d'%((struct.unpack('>I',h[o:o+4])[0]&0x7fffffff)%1000000)
def _totp_next(secret):
    # a code for the NEXT 30-second step: accepted by the ±1 window and never equal to a step already used at enrolment
    key=base64.b32decode(secret.replace(' ','').upper()+'='*((8-len(secret.replace(' ',''))%8)%8))
    c=struct.pack('>Q', int(time.time())//30+1); h=hmac.new(key,c,hashlib.sha1).digest(); o=h[-1]&15
    return '%06d'%((struct.unpack('>I',h[o:o+4])[0]&0x7fffffff)%1000000)
def _toast(b):
    m=re.search(r'data-toast="([^"]*)" data-toast-type="([^"]*)"', b); return m.groups() if m else None
opo=sv_login('owner@smoke.test'); P1=ids['P1']
# (data loss) unknown sub-path must not update/blank the provider; a partial POST to the provider URL must not blank other fields
before=dbq("select concat(first_name,'|',last_name) from credential_holders where id='%s'"%P1)
tok,_,_=page_token(opo,'/holders/'+P1)
s,b,_=req(opo,'/holders/'+P1+'/not-a-real-action',{'_csrf':tok,'telehealth_enabled':'1'})
after=dbq("select concat(first_name,'|',last_name) from credential_holders where id='%s'"%P1)
rec('DL POST to an unknown provider sub-path is not treated as an edit (record unchanged)', s in (404,403,405) and before==after, f'status {s} before={before} after={after}')
s,b,_=req(opo,'/holders/'+P1,{'_csrf':tok,'role_title':'Attending physician'})
after=dbq("select concat(first_name,'|',last_name,'|',ifnull(role_title,'')) from credential_holders where id='%s'"%P1)
rec('DL a partial POST to the provider URL changes only what it carries (name intact, role_title set)', before+'|Attending physician'==after, f'status {s} after={after}')
# (J) typed flashes on real actions: a validation refusal is error, a success is ok
tok,_,_=page_token(opo,'/holders/new'); req(opo,'/holders',{'_csrf':tok,'holder_type':'individual','first_name':'No','last_name':'Email','provider_type':'physician'})
s,b,_=req(opo,'/holders'); t=_toast(b)
rec('J "A valid email is required…" is shown as an error toast', bool(t) and t[1]=='error', f'flash={t}')
tok,_,_=page_token(opo,'/holders/new'); req(opo,'/holders',{'_csrf':tok,'holder_type':'individual','first_name':'Yes','last_name':'Email6','email':'yes6@smoke.test','provider_type':'physician'})
s,b,_=req(opo,'/holders'); t=_toast(b)
rec('J a successful provider add is NOT shown as an error toast (message mentions "required credentials")', bool(t) and t[1]=='ok', f'flash={t}')
# (O) template rows are unique and valid
s,b,_=req(opo,'/holders/import/template'); npis=[c for line in b.splitlines()[1:] for c in line.split(',') if re.fullmatch(r'\d{10}', c)]
rec('O the CSV template never repeats an NPI', s==200 and len(npis)==len(set(npis)) and len(npis)>=4, f'npis={npis}')
# (2FA) enrolling can add a factor but never replace an active one without turning it off first
opc=sv_login('twofa@smoke.test'); tok,_,_=page_token(opc,'/profile')
s,b,_=req(opc,'/profile',{'_csrf':tok,'action':'2fa_start'}); m=re.search(r'Setup key.*?<div class="mono"[^>]*>\s*([A-Z2-7 ]+?)\s*</div>', b, re.S)
sec=m.group(1) if m else ''
s,b,_=req(opc,'/profile',{'_csrf':tok,'action':'2fa_confirm','code':_totp(sec)}) if sec else (0,'',None)
on=dbq("select totp_enabled from users where email='twofa@smoke.test'"); h1=dbq("select md5(totp_secret) from users where email='twofa@smoke.test'")
rec('2FA a normal enrolment still works', bool(sec) and on=='1', f'status {s} enabled={on}')
tok,_,_=page_token(opc,'/profile'); s,b,_=req(opc,'/profile',{'_csrf':tok,'action':'2fa_start'})
m2=re.search(r'Setup key.*?<div class="mono"[^>]*>\s*([A-Z2-7 ]+?)\s*</div>', b, re.S)
sec2=m2.group(1) if m2 else ''
s,b,_=req(opc,'/profile',{'_csrf':tok,'action':'2fa_confirm','code':_totp(sec2)}) if sec2 else (0,'',None)
h2=dbq("select md5(totp_secret) from users where email='twofa@smoke.test'")
rec('2FA with 2FA on, a second enrolment cannot replace the secret without the current code', h1==h2 and not sec2, f'secret_changed={h1!=h2} second_secret_shown={bool(sec2)}')
s,b,_=req(opc,'/profile'); t=_toast(b)
rec('2FA the refusal is shown as an error toast', bool(t) and t[1]=='error' and 'already on' in t[0], f'flash={t}')


# ---- v1.171.6 round 2 (second independent run) ------------------------------------------------------------------
# (K2) import with only bad rows is NOT green; a partly successful import is a warning, never plain success
hdr='kind,provider_type,first_name,last_name,org_name,npi,email,group,location,role_title'
tok,_,_=page_token(opo,'/holders/import')
req(opo,'/holders/import',{'_csrf':tok,'step':'commit','csv':hdr+'\nindividual,physician,Bad,Npi,,1234567890,bad@smoke.test,,,'})
s,b,_=req(opo,'/holders'); t=_toast(b)
rec('K2 an import that created nothing is shown as an error, not success', bool(t) and t[1] in ('error','warn') and t[1]!='ok', f'flash={t}')
# (obs.1) editing a provider cannot erase the name or save a malformed e-mail
before=dbq("select concat(first_name,'|',last_name) from credential_holders where id='%s'"%ids['P1'])
tok,_,_=page_token(opo,'/holders/'+ids['P1'])
req(opo,'/holders/'+ids['P1'],{'_csrf':tok,'first_name':'','last_name':''})
s,b,_=req(opo,'/holders/'+ids['P1']+'/edit'); 
after=dbq("select concat(first_name,'|',last_name) from credential_holders where id='%s'"%ids['P1'])
rec('OBS1 an edit that blanks the name is refused and the record is unchanged', before==after, f'before={before} after={after}')
t=_toast(b)
rec('OBS1 the refusal is shown as an error toast', bool(t) and t[1]=='error' and 'name' in t[0].lower(), f'flash={t}')
# (obs.5) a refused 2fa_confirm while 2FA is on does not render an empty enrolment form
opc=sv_login('twofa@smoke.test'); tok,_,_=page_token(opc,'/profile')
s,b,_=req(opc,'/profile',{'_csrf':tok,'action':'2fa_confirm','code':'000000'})
s2,b2,_=req(opc,'/profile')
rec('OBS5 refused 2fa_confirm redirects (no empty setup-key/otpauth form is rendered)', s in (302,303) and 'otpauth://' not in b2 and 'secret=' not in b2, f'status {s}')
# (obs.4) role names that differ only by whitespace are refused over HTTP too
opm=sv_login('manager@smoke.test'); tok,_,_=page_token(opm,'/organization/roles/new')
req(opm,'/organization/roles',{'_csrf':tok,'action':'create','name':'Spacing Role','capabilities[]':'documents.view'})
tok,_,_=page_token(opm,'/organization/roles/new')
req(opm,'/organization/roles',{'_csrf':tok,'action':'create','name':'Spacing  Role','capabilities[]':'documents.view'})
req(opm,'/organization/roles',{'_csrf':tok,'action':'create','name':"Spacing\tRole",'capabilities[]':'documents.view'})
n=dbq("select count(*) from roles where lower(replace(replace(name,char(9),' '),'  ',' '))='spacing role'")
rec('K5 whitespace-variant role names are refused (one role)', n=='1', f'rows {n}')

# ---- v1.171.8 (third independent run) ---------------------------------------------------------------------------
# (R1) rename a role onto another role's name over HTTP
opm=sv_login('manager@smoke.test')
for nm_ in ('Rename Http A','Rename Http B'):
    tok,_,_=page_token(opm,'/organization/roles/new'); req(opm,'/organization/roles',{'_csrf':tok,'action':'create','name':nm_,'capabilities[]':'documents.view'})
rid=dbq("select id from roles where name='Rename Http B' limit 1")
tok,_,_=page_token(opm,'/organization/roles/'+rid); req(opm,'/organization/roles/'+rid,{'_csrf':tok,'action':'update','name':'rename  http a','capabilities[]':'documents.view'})
s,b,_=req(opm,'/organization/roles/'+rid); t=_toast(b)
rec('R1 renaming a role onto another role\'s name is refused over HTTP (name unchanged, error toast)', dbq("select name from roles where id='%s'"%rid)=='Rename Http B' and bool(t) and t[1]=='error' and 'already exists' in t[0], f'flash={t}')
# (R2d) editing an organization keeps its licence code; invisible-only names are refused
tid=dbq("select tenant_id from credential_holders where id='%s'"%ids['P1']); oid='11111111-2222-4333-8444-%012d'%int(time.time()%1e9)
dbq("insert into credential_holders (id,tenant_id,holder_type,org_name,license_code,vertical,status,created_at) values ('%s','%s','organization','Smoke Lab 8','clia','healthcare','active',utc_timestamp())"%(oid,tid))
s,b,_=req(opo,'/holders/'+oid+'/edit')
rec('R2d the organization edit form offers the stored licence code as selected', s==200 and 'value="clia" selected' in b, f'status {s}')
tok,_,_=page_token(opo,'/holders/'+oid+'/edit')
req(opo,'/holders/'+oid,{'_csrf':tok,'org_name':'Smoke Lab 8 Renamed','license_code':'clia','npi':''})
rec('R2d renaming an organization does not clear its licence code', dbq("select concat(org_name,'|',ifnull(license_code,'')) from credential_holders where id='%s'"%oid)=='Smoke Lab 8 Renamed|clia', dbq("select concat(org_name,'|',ifnull(license_code,'')) from credential_holders where id='%s'"%oid))
tok,_,_=page_token(opo,'/holders/'+oid+'/edit')
req(opo,'/holders/'+oid,{'_csrf':tok,'org_name':' ​'})
rec('R2 an organization name of only NBSP/zero-width characters is refused over HTTP', dbq("select org_name from credential_holders where id='%s'"%oid)=='Smoke Lab 8 Renamed')
# (R5) a pending secret left in a stale session cannot be confirmed once 2FA is on, and renders no enrolment form
opa=sv_login('stale2fa@smoke.test'); tok,_,_=page_token(opa,'/profile')
s,b,_=req(opa,'/profile',{'_csrf':tok,'action':'2fa_start'})   # session A holds a pending secret
opb=sv_login('stale2fa@smoke.test'); tokb,_,_=page_token(opb,'/profile')
s,b,_=req(opb,'/profile',{'_csrf':tokb,'action':'2fa_start'}); mb=re.search(r'Setup key.*?<div class="mono"[^>]*>\s*([A-Z2-7 ]+?)\s*</div>', b, re.S)
req(opb,'/profile',{'_csrf':tokb,'action':'2fa_confirm','code':_totp(mb.group(1))}) if mb else None   # session B turns 2FA on
h1=dbq("select md5(totp_secret) from users where email='stale2fa@smoke.test'")
s,b,_=req(opa,'/profile',{'_csrf':tok,'action':'2fa_confirm','code':'000000'})
s2,b2,_=req(opa,'/profile'); t=_toast(b2)
rec('R5 a stale pending secret in another session is refused with a redirect and error toast (no enrolment form)', s in (302,303) and 'otpauth://' not in b2 and 'Setup key' not in b2 and bool(t) and t[1]=='error' and 'already on' in t[0], f'status {s} flash={t}')
h2=dbq("select md5(totp_secret) from users where email='stale2fa@smoke.test'")
rec('R5 the stored secret is unchanged by the stale session', h1==h2 and bool(mb) and h1 not in ('','NULL'), f'enrolled_in_B={bool(mb)} secret_changed={h1!=h2}')

# ---- v1.171.9 (fifth run) — the whole CLASS of look-alike / invisible names, at every HTTP entry point -------------
INVIS=['', ' ', ' ', '​', '‌‍', '﻿', '⁠', '­', 'ㅤ', '⠀', '᠎']
def lookalikes(base):
    fw=''.join(chr(ord(c)-0x21+0xFF01) if c.isascii() and c.isalpha() else c for c in base)
    return [base.upper(), base.lower(), ' '+base+' ', base.replace(' ','  '), base.replace(' ','\t'), base.replace(' ',' '), base.replace(' ','　'),
            base.replace(' ',' ​'), '﻿'+base, base.replace('e','e­'), base.replace('e','e⁠'), base.replace('e','е'), base.replace('a','а'), fw]
opo=sv_login('owner@smoke.test'); opm=sv_login('manager@smoke.test'); opp=sv_login('padmin@smoke.test')
# (N1) provider / organization CREATE and ONBOARD with invisible names
before=dbq("select count(*) from credential_holders"); acc=[]
for v in INVIS:
    tok,_,_=page_token(opo,'/holders/new')
    req(opo,'/holders',{'_csrf':tok,'holder_type':'individual','first_name':v,'last_name':'Nine','email':'inv9@smoke.test','provider_type':'physician'})
    req(opo,'/holders',{'_csrf':tok,'holder_type':'individual','first_name':'Nine','last_name':v,'email':'inv9@smoke.test','provider_type':'physician'})
    req(opo,'/holders',{'_csrf':tok,'holder_type':'organization','org_name':v,'provider_type':'lab'})
    tok,_,_=page_token(opo,'/holders/onboard')
    req(opo,'/holders/onboard',{'_csrf':tok,'holder_type':'individual','first_name':v,'last_name':'Nine','email':'inv9@smoke.test','provider_type':'physician'})
    req(opo,'/holders/onboard',{'_csrf':tok,'holder_type':'organization','org_name':v,'org_provider_type':'lab'})
tok,_,_=page_token(opo,'/holders/new'); req(opo,'/holders',{'_csrf':tok,'holder_type':'individual','email':'inv9@smoke.test','provider_type':'physician'})   # names not sent at all
after=dbq("select count(*) from credential_holders"); s,b,_=req(opo,'/holders'); t=_toast(b)
rec('N1 provider/organization create and onboarding refuse empty, missing and invisible-only names (no row created)', before==after and bool(t) and t[1]=='error', f'rows {before}->{after} last flash={t}')
tok,_,_=page_token(opo,'/holders/new'); req(opo,'/holders',{'_csrf':tok,'holder_type':'organization','org_name':'­Nine​  Labs ','provider_type':'lab'})
rec('N1 a real organization name is stored cleaned (soft hyphen / zero-width removed, spacing collapsed) and the toast says Organization', dbq("select count(*) from credential_holders where org_name='Nine Labs'")=='1' and 'Organization added' in (_toast(req(opo,'/holders')[1]) or ('',''))[0], dbq("select org_name from credential_holders where org_name like '%Nine%Labs%'"))
# (N2) tenant roles: look-alikes on create and rename; a rename-only POST keeps capabilities
tok,_,_=page_token(opm,'/organization/roles/new'); r0=int(dbq("select count(*) from roles where tenant_id=(select tenant_id from users where email='manager@smoke.test')"))
req(opm,'/organization/roles',{'_csrf':tok,'action':'create','name':'Review Desk Lead','capabilities[]':'documents.view'})
req(opm,'/organization/roles',{'_csrf':tok,'action':'create','name':'Other Role Nine','capabilities[]':'documents.view'})
rid9=dbq("select id from roles where name='Other Role Nine' limit 1"); acc=[]
for v in lookalikes('Review Desk Lead')+['Manager','Ｍａｎａｇｅｒ','Mаnager','Regular User','Master Admin','Admin','Viewer']+INVIS+['x'+i for i in INVIS]:
    req(opm,'/organization/roles',{'_csrf':tok,'action':'create','name':v,'capabilities[]':'documents.view'})
    req(opm,'/organization/roles/'+rid9,{'_csrf':tok,'action':'update','name':v,'capabilities[]':'documents.view'})
n=dbq("select count(*) from roles where tenant_id=(select tenant_id from roles where id='%s')"%rid9); nm=dbq("select name from roles where id='%s'"%rid9)
rec('N2 tenant roles over HTTP: exactly two new rows (no look-alike / invisible / built-in name created) and the rename never applied', nm=='Other Role Nine' and int(n)==r0+2, f'name={nm} tenant roles {r0}->{n}')
caps0=dbq("select count(*) from role_capabilities where role_id='%s'"%rid9)
req(opm,'/organization/roles/'+rid9,{'_csrf':tok,'action':'update','name':'Other Role Nine R'})   # rename only, no capabilities, no form marker
caps1=dbq("select count(*) from role_capabilities where role_id='%s'"%rid9)
req(opm,'/organization/roles/'+rid9,{'_csrf':tok,'action':'update','name':'Other Role Nine R','caps_form':'1'})   # the form with every box unticked
caps2=dbq("select count(*) from role_capabilities where role_id='%s'"%rid9)
rec('N2 a rename-only POST keeps the capabilities; the form with every box unticked clears them', caps0=='1' and caps1=='1' and caps2=='0' and dbq("select name from roles where id='%s'"%rid9)=='Other Role Nine R', f'{caps0}/{caps1}/{caps2}')
# (N3) global roles (platform admin) follow the same rule
tokp,_,_=page_token(opp,'/admin/roles'); g0=dbq("select count(*) from roles where tenant_id is null")
req(opp,'/admin/roles',{'_csrf':tokp,'action':'create','name':'Global Nine Role','caps[]':'documents.view'})
req(opp,'/admin/roles',{'_csrf':tokp,'action':'create','name':'Global Nine Other','caps[]':'documents.view'})
gid=dbq("select id from roles where tenant_id is null and name='Global Nine Other' limit 1")
for v in lookalikes('Global Nine Role')+['Manager','manager','Master Admin','Admin','Viewer','Regular User','Ｍａｎａｇｅｒ']+INVIS:
    req(opp,'/admin/roles',{'_csrf':tokp,'action':'create','name':v,'caps[]':'documents.view'})
    req(opp,'/admin/roles',{'_csrf':tokp,'action':'update','role_id':gid,'name':v,'caps[]':'documents.view'})
g1=dbq("select count(*) from roles where tenant_id is null"); gn=dbq("select name from roles where id='%s'"%gid)
dup=dbq("select count(*) from (select lower(name) n, count(*) c from roles where tenant_id is null group by lower(name) having c>1) d")
rec('N3 global roles over HTTP: exactly two new rows, no duplicates of built-ins, rename refused', int(g1)==int(g0)+2 and gn=='Global Nine Other' and dup=='0', f'global {g0}->{g1} name={gn} dup_groups={dup}')
s,b,_=req(opp,'/admin/roles'); t=_toast(b)
rec('N3 the last refusal (a blank name) is an error toast', bool(t) and t[1]=='error' and ('already exists' in t[0] or 'Enter a role name' in t[0]), f'flash={t}')
s,b,_=req(opm,'/organization/roles')
rec('N3 a tenant sees global custom roles as View, not Edit', s==200 and len(re.findall(r'roles/%s"[^>]*>Edit'%re.escape(dbq("select id from roles where tenant_id is null and name='Global Nine Role'")), b))==0)

# ---- v1.171.10 closeout: tenant organization identity names over HTTP ------------------------------------------------
opo=sv_login('owner@smoke.test'); tid=dbq("select tenant_id from users where email='owner@smoke.test'")
name0=dbq("select name from tenants where id='%s'"%tid)
tok,_,_=page_token(opo,'/settings')
for v in INVIS:
    req(opo,'/settings',{'_csrf':tok,'name':v})
s,b,_=req(opo,'/settings'); t=_toast(b)
rec('O1 the organization name cannot be set to an empty / invisible-only value over HTTP (unchanged, error toast)', dbq("select name from tenants where id='%s'"%tid)==name0 and bool(t) and t[1]=='error', f'flash={t}')
req(opo,'/settings',{'_csrf':tok,'name':'﻿ Smoke­​  Health　Org ','brand_name':'⁠Smokeㅤ Brand⠀'})
got=dbq("select concat(name,'|',ifnull(brand_name,'')) from tenants where id='%s'"%tid)
rec('O1 organization name and brand name are stored cleaned (invisible characters removed, spacing collapsed)', got=='Smoke Health Org|Smoke Brand', got)
req(opo,'/settings',{'_csrf':tok,'name':name0,'brand_name':''})   # restore for the crawls

# ==== v1.173.0 — Ownership & White-Label Standard (steps 1-4) over HTTP ==============================================
import json as _json
opn=client(); sN,_,hN=req(opn,'/about')
rec('OW1 /about needs a sign-in (signed-out visitors are sent to the sign-in page)', sN==302 and (hN.get('Location') or '').endswith('/login'), f'status {sN}')
sT,bT,_=req(opn,'/terms'); rec('OW2 the Terms of Use page is public and labelled as a draft', sT==200 and 'Terms of Use' in bT and 'DRAFT' in bT, f'status {sT}')
sL,bL,_=req(opn,'/login')
rec('OW3 the sign-in page shows the Powered-by line, "licensed, not sold" and the Terms link', 'Powered by FlxCoreHC · © 2026 Indisse-BTS. All rights reserved.' in bL.replace('\u2060','') and 'Proprietary software, licensed, not sold.' in bL and 'href="/terms"' in bL, f'status {sL}')
OWN=r'^[0-9A-F]{4}(-[0-9A-F]{4}){3}$'
opx=login('owner@smoke.test')[0]
sF,bF,_=req(opx,'/about?format=json'); F=_json.loads(bF) if sF==200 else {}
rec('OW4 a user with license.view (owner) gets the full view: workspace ID format, eight terms, plan fields', F.get('view')=='full' and bool(re.match(OWN,F.get('workspace_id',''))) and len(F.get('license_terms',[]))==8 and 'users_in_use' in F and F.get('owner')=='Indisse-BTS', f'status {sF} keys={sorted(F)[:6]}')
sF2,bF2,_=req(opx,'/about?format=json'); F2=_json.loads(bF2)
rec('OW5 the workspace ID is made once and never changes; it matches the database', F2.get('workspace_id')==F.get('workspace_id')==dbq("select workspace_id from tenants where id='%s'"%tid), F.get('workspace_id'))
rec('OW6 the workspace ID is unique per workspace', dbq("select count(*) from tenants where workspace_id is not null")==dbq("select count(distinct workspace_id) from tenants where workspace_id is not null"), '')
for who in ('readonly','staff','coord'):
    opw=login(who+'@smoke.test')[0]; sW,bW,_=req(opw,'/about?format=json'); W=_json.loads(bW) if sW==200 else {}
    leaked=[k for k in ('workspace_id','license_terms','plan','user_limit','users_in_use','name_shown','owner','modules','license_record') if k in W]
    rec(f'OW7 {who}: short view only - the answer carries no full-view field', W.get('view')=='short' and W.get('product')=='FlxCoreHC' and not leaked and bool(W.get('independent_developer')), f'status {sW} leaked={leaked}')
sH,bH,_=req(opx,'/about'); rec('OW8 the About page (owner) shows "This workspace" and the eight license lines', sH==200 and 'This workspace' in bH and bH.count('<li>')>=8 and F.get('workspace_id','?') in bH, f'status {sH}')
opr=login('readonly@smoke.test')[0]; sR,bR,_=req(opr,'/about'); rec('OW9 the About page (read-only) shows the short view only', sR==200 and 'This workspace' not in bR and 'Workspace ID' not in bR and 'independent product' in bR, f'status {sR}')
sHp,bHp,_=req(opx,'/help'); rec('OW10 the Help Center has the About & ownership button and the copyright footer', sHp==200 and 'href="/about"' in bHp and '© 2026 Indisse-BTS. All rights reserved.' in bHp, f'status {sHp}')
# organization name: audited old -> new
tokN=page_token(opx,'/settings')[0]; old=dbq("select name from tenants where id='%s'"%tid)
a0=dbq("select count(*) from audit_logs where action='org_name_changed'")
req(opx,'/settings',{'_csrf':tokN,'name':'Smoke Org Renamed'})
a1=dbq("select count(*) from audit_logs where action='org_name_changed'")
det=dbq("select changes from audit_logs where action='org_name_changed' order by created_at desc limit 1")
rec('OW11 changing the organization name writes one audit row with old -> new', int(a1)==int(a0)+1 and old in det and 'Smoke Org Renamed' in det, det[:120])
req(opx,'/settings',{'_csrf':tokN,'name':old})
a2=dbq("select count(*) from audit_logs where action='org_name_changed'")
req(opx,'/settings',{'_csrf':tokN,'name':old})
rec('OW12 saving the form without changing the name writes no audit row', dbq("select count(*) from audit_logs where action='org_name_changed'")==a2, '')
# ---- v1.173.1 — license notice, third-party notices, PDFs
rec('OW13 the About page (owner) shows LICENSE.txt and the third-party notices in the full view', sH==200 and 'LICENSE NOTICE' in bH and 'THIRD-PARTY NOTICES' in bH and 'THIRD-PARTY COMPONENTS' in bH, f'status {sH}')
rec('OW14 the About page (read-only) shows neither', 'LICENSE NOTICE' not in bR and 'THIRD-PARTY NOTICES' not in bR, '')
sP,bP,hP=req(opx,'/holders/'+ids['P1']+'/package')
rec('OW15 the credential package PDF carries the product line and the copyright line', sP==200 and 'pdf' in (hP.get('Content-Type') or '') and 'All rights reserved' in bP and 'Healthcare Compliance Intelligence Platform' in bP, f'status {sP} {hP.get("Content-Type")}')

# ==== v1.172.0 — sign-in options and guided onboarding, over HTTP =====================================================
MAILBOX=os.environ.get('FLX_MAILBOX','')
def mails(to=None):
    out=[]
    try:
        for line in open(MAILBOX, encoding='utf-8'):
            line=line.strip()
            if not line: continue
            m=json.loads(line)
            if to is None or to in m['to']: out.append(m)
    except FileNotFoundError: pass
    return out
def last_code(to):
    ms=mails(to); c=re.search(r'\n\s+(\d{6})\s*\n', ms[-1]['body']) if ms else None
    return c.group(1) if c else ''
def pset(k,v): dbq("insert into platform_settings (skey,sval,updated_at) values ('%s','%s',utc_timestamp()) on duplicate key update sval='%s'"%(k,v,v))
def login_pw(email, pw):
    op=client(); s,h,_=req(op,'/login'); s,h,hd=req(op,'/login',{'_csrf':csrf(h),'email':email,'password':pw}); return op, s, (hd.get('Location') or '')

# ---- E: e-mailed codes as the second step -----------------------------------------------------------------------------
ope=sv_login('mfaemail@smoke.test'); s,b,_=req(ope,'/profile')
rec('E1 with outbound e-mail off, the e-mail option is shown as not available and the authenticator option is offered', s==200 and 'Not available yet' in b and 'value="2fa_email_start"' not in b and 'value="2fa_start"' in b, f'status {s}')
for k,v in {'mail_enabled':'1','mail_transport':'smtp','smtp_host':'127.0.0.1','smtp_port':os.environ.get('FLX_SMTP_PORT','8125'),'smtp_secure':'none','smtp_user':'','mail_from':'no-reply@smoke.test'}.items(): pset(k,v)
s,b,_=req(ope,'/profile'); tok=csrf(b)
rec('E2 once outbound e-mail works, "Use e-mail codes" is offered', 'value="2fa_email_start"' in b and 'Not available yet' not in b)
n0=len(mails('mfaemail@smoke.test')); s,b,_=req(ope,'/profile',{'_csrf':tok,'action':'2fa_email_start'})
code=last_code('mfaemail@smoke.test')
ob=dbq("select concat(status,'|',category,'|',body regexp '[0-9]{6}') from email_outbox where to_email='mfaemail@smoke.test' order by created_at desc limit 1")
rec('E3 asking for e-mail codes really sends ONE message with a 6-digit code to the account address', len(mails('mfaemail@smoke.test'))==n0+1 and bool(code) and 'value="2fa_email_confirm"' in b and 'm***@smoke.test' in b, f'mails={len(mails("mfaemail@smoke.test"))-n0} code_found={bool(code)}')
rec('E3 the outbox records the message as sent, with its content removed (no code readable in the database)', ob=='sent|security|0', ob)
wrong='000000' if code!='000000' else '111111'
s,b,_=req(ope,'/profile',{'_csrf':csrf(b),'action':'2fa_email_confirm','code':wrong})
rec('E4 a wrong code does not turn two-factor on', dbq("select totp_enabled from users where email='mfaemail@smoke.test'")=='0' and 'not valid' in b)
s,b,_=req(ope,'/profile',{'_csrf':csrf(b),'action':'2fa_email_confirm','code':code})
bk=re.findall(r'letter-spacing:\.05em">([^<]+)</span>', b)
rec('E4 the right code turns two-factor on with the e-mail method and shows 10 backup codes', dbq("select concat(totp_enabled,'|',mfa_method,'|',isnull(totp_secret)) from users where email='mfaemail@smoke.test'")=='1|email|1' and len(bk)==10, f'backup codes shown={len(bk)}')
n1=len(mails('mfaemail@smoke.test')); opl,s,loc=login_pw('mfaemail@smoke.test','SmokePass12345')
s2,b2,_=req(opl,'/login/verify'); s3,_,hd3=req(opl,'/holders')
rec('E5 sign-in with the right password stops at the verification step, a code is e-mailed, and no page opens without it', loc.endswith('/login/verify') and len(mails('mfaemail@smoke.test'))==n1+1 and s2==200 and 'm***@smoke.test' in b2 and s3==302 and (hd3.get('Location') or '').endswith('/login'), f'loc={loc} mails+{len(mails("mfaemail@smoke.test"))-n1} holders={s3}')
c1=last_code('mfaemail@smoke.test'); s,b,_=req(opl,'/login/verify',{'_csrf':csrf(b2),'action':'resend'}); s,b,_=req(opl,'/login/verify'); c2=last_code('mfaemail@smoke.test')
s,b3,hd=req(opl,'/login/verify',{'_csrf':csrf(b),'code':c1})
rec('E6 "Send a new code" sends another message, and the earlier code stops working', len(mails('mfaemail@smoke.test'))==n1+2 and (c1==c2 or s==200), f'status {s} same_code={c1==c2}')
s,_,hd=req(opl,'/login/verify',{'_csrf':csrf(b3) or csrf(b),'code':c2}); s4,_,_=req(opl,'/profile')
rec('E6 the current e-mailed code completes the sign-in', (c1==c2) or (s==302 and s4==200), f'status {s} profile={s4}')
opl2,_,loc2=login_pw('mfaemail@smoke.test','SmokePass12345'); s,b,_=req(opl2,'/login/verify'); s,_,hd=req(opl2,'/login/verify',{'_csrf':csrf(b),'code':bk[0] if bk else 'x'}); s5,_,_=req(opl2,'/profile')
rec('E7 a backup code signs in when the mailbox cannot be read', s==302 and s5==200, f'status {s} profile={s5}')
pset('mfa_email_allowed','0'); opx=sv_login('pwreset@smoke.test'); s,b,_=req(opx,'/profile')
s,bx,_=req(opx,'/profile',{'_csrf':csrf(b),'action':'2fa_email_start'}); s,bx,_=req(opx,'/profile')
rec('E8 when the platform does not allow e-mailed codes, the option is hidden and a direct request is refused', 'value="2fa_email_start"' not in b and 'not allowed on this installation' in bx and dbq("select count(*) from auth_email_codes c join users u on u.id=c.user_id where u.email='pwreset@smoke.test'")=='0')
pset('mfa_email_allowed','1')

# ---- A: authenticator enrolment page: QR code + instructions ----------------------------------------------------------
opq=sv_login('pwreset@smoke.test'); s,b,_=req(opq,'/profile'); s,b,_=req(opq,'/profile',{'_csrf':csrf(b),'action':'2fa_start'})
key=re.search(r'<div class="mono"[^>]*>\s*([A-Z2-7 ]+?)\s*</div>', b); uri=re.search(r'href="(otpauth://[^"]+)"', b)
rec('A1 the enrolment page shows an inline SVG QR code, the setup key and the tap-to-add link', s==200 and '<div data-qr="totp"' in b and '<svg xmlns="http://www.w3.org/2000/svg"' in b and bool(key) and bool(uri) and not BAD.search(b), f'status {s}')
rec('A1 the QR is drawn by the server: no image request, no external service anywhere on the page', not re.search(r'<img[^>]+(qr|chart|otpauth)', b, re.I) and not re.search(r'(chart\.googleapis|api\.qrserver|quickchart|chart\.apis\.google)', b, re.I))
rec('A2 step-by-step instructions are shown for Google Authenticator, Microsoft Authenticator, Authy and 1Password', all(x in b for x in ('data-help="authenticators"','<strong>Google Authenticator</strong>','<strong>Microsoft Authenticator</strong>','<strong>Authy</strong>','<strong>1Password</strong>')))
sec=(key.group(1) if key else '').replace(' ','')
s,b,_=req(opq,'/profile',{'_csrf':csrf(b),'action':'2fa_confirm','code':_totp(sec)}) if sec else (0,'',None)
rec('A3 the code from the secret shown on the page turns two-factor on (authenticator method)', dbq("select concat(totp_enabled,'|',mfa_method) from users where email='pwreset@smoke.test'")=='1|totp', dbq("select concat(totp_enabled,'|',mfa_method) from users where email='pwreset@smoke.test'"))

# ---- R: password reset by e-mail ---------------------------------------------------------------------------------------
opn=client(); s,b,_=req(opn,'/login')
rec('R1 the sign-in page links to "Forgot your password?"', s==200 and '/forgot"' in b and 'Forgot your password?' in b)
# an open session of the account (it uses an authenticator now, so it goes through the verification step)
opv,_,locv=login_pw('pwreset@smoke.test','SmokePass12345'); s,b,_=req(opv,'/login/verify')
s,_,_=req(opv,'/login/verify',{'_csrf':csrf(b),'code':_totp_next(sec)}); sA,_,_=req(opv,'/profile')
s,b,_=req(opn,'/forgot'); n2=len(mails())
s,_,hd=req(opn,'/forgot',{'_csrf':csrf(b),'email':'nobody-at-all@smoke.test'}); s,bU,_=req(opn,'/forgot')
msgU=re.search(r'role="status">([^<]+)<', bU)
rec('R2 an unknown address gets the standard answer and no e-mail is sent', bool(msgU) and 'If that address belongs to an active account' in msgU.group(1) and len(mails())==n2, f'mails+{len(mails())-n2}')
s,_,hd=req(opn,'/forgot',{'_csrf':csrf(bU),'email':'pwreset@smoke.test'}); s,bK,_=req(opn,'/forgot')
msgK=re.search(r'role="status">([^<]+)<', bK); ms=mails('pwreset@smoke.test')
link=re.search(r'/reset/([a-f0-9]{64})', ms[-1]['body']) if ms else None; tokR=link.group(1) if link else ''
rec('R2 a known address gets exactly the same answer (the form does not reveal who has an account)', bool(msgK) and bool(msgU) and msgK.group(1)==msgU.group(1))
rec('R3 the account receives one message with a single-use link; only a hash is stored and the outbox keeps no link', bool(tokR) and len(mails())==n2+1 and dbq("select count(*) from password_resets where token_hash=sha2('%s',256)"%tokR)=='1' and dbq("select count(*) from password_resets where token_hash='%s'"%tokR)=='0' and dbq("select body like '%%/reset/%%' from email_outbox where to_email='pwreset@smoke.test' order by created_at desc limit 1")=='0', f'token_found={bool(tokR)} mails+{len(mails())-n2}')
s,b,_=req(opn,'/reset/'+('a'*64)); 
rec('R4 a guessed link is refused', s==302)
s,b,hdR=req(opn,'/reset/'+tokR)
rec('R4 the real link opens the new-password form (address masked, no caching, no referrer)', s==200 and 'name="confirm"' in b and 'p***@smoke.test' in b and 'no-store' in (hdR.get('Cache-Control') or '') and (hdR.get('Referrer-Policy') or '')=='no-referrer', f'status {s}')
s,b2,_=req(opn,'/reset/'+tokR,{'_csrf':csrf(b),'new':'BrandNewPass-1','confirm':'Different-Pass-1'})
rec('R5 mismatched passwords are refused and the link stays usable', s==200 and 'do not match' in b2 and dbq("select count(*) from password_resets where token_hash=sha2('%s',256) and used_at is null"%tokR)=='1')
s,_,hd=req(opn,'/reset/'+tokR,{'_csrf':csrf(b2),'new':'BrandNewPass-1','confirm':'BrandNewPass-1'}); sB,_,hdB=req(opv,'/profile')
rec('R6 the new password is saved and the browser is sent to sign in (not signed in automatically)', s==302 and (hd.get('Location') or '').endswith('/login'), f'status {s} {hd.get("Location")}')
rec('R6 the session that was open before the reset is ended', sA==200 and sB==302 and (hdB.get('Location') or '').endswith('/login'), f'before={sA} after={sB}')
s,b,_=req(opn,'/reset/'+tokR); 
rec('R7 the link cannot be used a second time', s==302)
_,_,locOld=login_pw('pwreset@smoke.test','SmokePass12345'); opw,_,locNew=login_pw('pwreset@smoke.test','BrandNewPass-1'); sH,_,_=req(opw,'/holders')
rec('R8 the old password no longer works; the new one does — and two-factor is still asked (the reset does not bypass it)', not locOld.endswith('/login/verify') and locNew.endswith('/login/verify') and sH==302, f'old->{locOld or "(stays on login)"} new->{locNew}')
n3=len(mails('pwreset@smoke.test')); s,b,_=req(opn,'/forgot')
for i in range(4):
    req(opn,'/forgot',{'_csrf':csrf(b),'email':'pwreset@smoke.test'}); s,b,_=req(opn,'/forgot')
rec('R9 repeated requests for one address are limited to 3 per 15 minutes (the answer stays the same)', len(mails('pwreset@smoke.test'))-n3==2 and 'If that address belongs to an active account' in b, f'mails+{len(mails("pwreset@smoke.test"))-n3}')
dbq("update users set password_hash=(select password_hash from (select password_hash from users where email='owner@smoke.test') x), totp_enabled=0, totp_secret=null, mfa_method='totp' where email in ('pwreset@smoke.test','mfaemail@smoke.test')")
pset('mail_enabled','0')

# ---- O: guided onboarding — a person and an organization are two real paths ---------------------------------------------
opo=sv_login('owner@smoke.test')
s,bI,_=req(opo,'/holders/onboard')
rec('O1 the person path shows first/last name and the person types only', s==200 and 'data-ob-type="individual"' in bI and 'name="first_name"' in bI and 'name="org_name"' not in bI and 'value="physician"' in bI and 'value="lab"' not in bI and '4. Portal access' in bI and not BAD.search(bI), f'status {s}')
s,bO,_=req(opo,'/holders/onboard?type=organization')
rec('O2 the organization path shows the organization name and the organization types only (no first/last name, no "Physician")', s==200 and 'data-ob-type="organization"' in bO and 'name="org_name"' in bO and 'name="first_name"' not in bO and 'name="last_name"' not in bO and 'value="physician"' not in bO and all('value="%s"'%x in bO for x in ('lab','clinic','group')) and '4. Review &amp; finish' in bO and not BAD.search(bO), f'status {s}')
rec('O2 the choice is made with plain links — it does not depend on JavaScript', 'href="/holders/onboard?type=organization"' in bI and 'href="/holders/onboard?type=individual"' in bO and not re.search(r'\son[a-z]+="', bI+bO))
h0=dbq("select count(*) from credential_holders")
s,_,hd=req(opo,'/holders/onboard',{'_csrf':csrf(bO),'holder_type':'organization','org_name':'  Smoke­  Onboard Lab ','org_provider_type':'lab','first_name':'Should','last_name':'Vanish','provider_type':'physician','email':''})
oid=(re.search(r'/holders/([0-9a-f-]{36})/onboard/identifiers', hd.get('Location') or '') or [None,None])[1] if (hd.get('Location') or '') else None
row=dbq("select concat(holder_type,'|',org_name,'|',ifnull(first_name,'NULL'),'|',ifnull(last_name,'NULL'),'|',provider_type) from credential_holders where id='%s'"%oid) if oid else ''
rec('O3 creating an organization stores the cleaned organization name and type, and no person name', s==302 and row=='organization|Smoke Onboard Lab|NULL|NULL|lab', f'status {s} row={row}')
s,b,_=req(opo,'/holders/%s/onboard/identifiers'%oid)
rec('O4 an organization is asked for organization identifiers (no birth month, no CAQH)', s==200 and 'Organization NPI' in b and 'name="taxonomy_code"' in b and 'name="birth_month"' not in b and 'name="caqh_id"' not in b and not BAD.search(b), f'status {s}')
s,_,hd=req(opo,'/holders/%s/onboard/identifiers'%oid,{'_csrf':csrf(b),'npi':'1003000126','taxonomy_code':'291U00000X','medicare_ptan':'PTAN-ORG'})
rec('O4 the organization identifiers are saved', dbq("select concat(ifnull(taxonomy_code,''),'|',ifnull(medicare_ptan,'')) from credential_holders where id='%s'"%oid)=='291U00000X|PTAN-ORG' and (hd.get('Location') or '').endswith('/onboard/requirements'), dbq("select concat(ifnull(taxonomy_code,''),'|',ifnull(medicare_ptan,'')) from credential_holders where id='%s'"%oid))
s,b,_=req(opo,'/holders/%s/onboard/portal'%oid)
rec('O5 the last step of an organization is a review of what was captured, with no portal invite', s==200 and 'data-ob-summary="organization"' in b and 'Smoke Onboard Lab' in b and '291U00000X' in b and 'Send portal invite' not in b and not BAD.search(b), f'status {s}')
a1=dbq("select count(*) from audit_logs where action='holder_onboard_finished' and entity_id='%s'"%oid)
s,_,hd=req(opo,'/holders/%s/onboard/portal'%oid,{'_csrf':csrf(b)})
rec('O5 finishing opens the record and is audited', s==302 and (hd.get('Location') or '').endswith('/holders/'+str(oid)) and dbq("select count(*) from audit_logs where action='holder_onboard_finished' and entity_id='%s'"%oid)==str(int(a1)+1))
s,b,_=req(opo,'/holders/%s/onboard/who'%oid)
s2,_,hd=req(opo,'/holders/%s/onboard/who'%oid,{'_csrf':csrf(b),'holder_type':'organization','org_name':'Smoke Onboard Laboratory','org_provider_type':'clinic','email':''})
rec('O6 going back to step 1 of an existing record shows its data and saves a correction', s==200 and 'value="Smoke Onboard Lab"' in b and dbq("select concat(org_name,'|',provider_type) from credential_holders where id='%s'"%oid)=='Smoke Onboard Laboratory|clinic', f'status {s}/{s2}')
s,b,_=req(opo,'/holders/onboard?type=individual'); n4=dbq("select count(*) from credential_holders")
s,_,hd=req(opo,'/holders/onboard',{'_csrf':csrf(b),'holder_type':'individual','first_name':'Nina','last_name':'Noemail','provider_type':'np','email':''})
s,b,_=req(opo,(hd.get('Location') or '/holders/onboard'))
rec('O7 a person without an e-mail is refused, nothing is created, and the form comes back filled in', dbq("select count(*) from credential_holders")==n4 and 'value="Nina"' in b and re.search(r'value="np"\s+selected|value="np" selected', b) is not None and 'data-toast-type="error"' in b)
s,_,hd=req(opo,'/holders/onboard',{'_csrf':csrf(b),'holder_type':'individual','first_name':'Nina','last_name':'Newton','provider_type':'np','email':'nina.newton@smoke.test','org_name':'Should Vanish'})
pid=(re.search(r'/holders/([0-9a-f-]{36})/onboard/identifiers', hd.get('Location') or '') or [None,None])[1]
row=dbq("select concat(holder_type,'|',first_name,'|',last_name,'|',ifnull(org_name,'NULL'),'|',provider_type) from credential_holders where id='%s'"%pid) if pid else ''
s,b,_=req(opo,'/holders/%s/onboard/identifiers'%pid)
rec('O8 creating a person stores the person fields only, and asks for person identifiers (specialty, birth month, CAQH)', row=='individual|Nina|Newton|NULL|np' and 'name="birth_month"' in b and 'name="caqh_id"' in b and 'name="primary_specialty"' in b and 'Organization NPI' not in b, row)
# the first step adapts to who is using the app
tid=dbq("select tenant_id from users where email='owner@smoke.test'")
gid='22222222-3333-4444-8555-%012d'%int(time.time()%1e9); og='99999999-3333-4444-8555-%012d'%int(time.time()%1e9)
dbq("insert into provider_groups (id,tenant_id,name,status,created_at) values ('%s','%s','Client Alpha Clinic','active',utc_timestamp())"%(gid,tid))
dbq("insert into tenants (id,name,vertical,org_shape,status,created_at) values ('%s','Other Org O','healthcare','medical_group','active',utc_timestamp())"%og.replace('99999999','88888888'))
dbq("insert into provider_groups (id,tenant_id,name,status,created_at) values ('%s','%s','Foreign Group','active',utc_timestamp())"%(og,og.replace('99999999','88888888')))
dbq("update tenants set org_shape='billing_company' where id='%s'"%tid)
s,b,_=req(opo,'/holders/onboard')
rec('O9 a billing company is asked which client group the provider belongs to', 'Client group' in b and 'name="group_id"' in b and 'Client Alpha Clinic' in b and 'Foreign Group' not in b and 'client groups' in b)
s,_,hd=req(opo,'/holders/onboard',{'_csrf':csrf(b),'holder_type':'individual','first_name':'Gia','last_name':'Grouped','provider_type':'physician','email':'gia.grouped@smoke.test','group_id':gid})
g1=(re.search(r'/holders/([0-9a-f-]{36})/', hd.get('Location') or '') or [None,None])[1]
s,_,hd=req(opo,'/holders/onboard',{'_csrf':csrf(b),'holder_type':'individual','first_name':'Fay','last_name':'Foreign','provider_type':'physician','email':'fay.foreign@smoke.test','group_id':og})
g2=(re.search(r'/holders/([0-9a-f-]{36})/', hd.get('Location') or '') or [None,None])[1]
rec('O9 the chosen client group is attached; a group id from another organization is ignored', dbq("select count(*) from holder_groups where holder_id='%s' and group_id='%s'"%(g1,gid))=='1' and dbq("select count(*) from holder_groups where holder_id='%s'"%g2)=='0', f'own={dbq("select count(*) from holder_groups where holder_id=%r"%g1)} foreign={dbq("select count(*) from holder_groups where holder_id=%r"%g2)}')
dbq("update tenants set org_shape='solo_practice' where id='%s'"%tid)
s,b,_=req(opo,'/holders/onboard'); s,bm,_=req(opo,'/holders/onboard?type=individual&me=1')
rec('O10 a solo practice is not asked for a group and can add the doctor with "I am the doctor"', 'name="group_id"' not in b and 'I am the doctor' in b and 'value="Olivia"' in bm and 'value="Owner"' in bm and 'value="owner@smoke.test"' in bm)
dbq("update tenants set org_shape='medical_group' where id='%s'"%tid)
# capability: a role without records.write cannot use the wizard at all
opn2=sv_login('nowrite@smoke.test'); s,b,_=req(opn2,'/holders/onboard'); tkn,_,_=page_token(opn2,'/profile'); n5=dbq("select count(*) from credential_holders")
s2,_,_=req(opn2,'/holders/onboard',{'_csrf':tkn,'holder_type':'organization','org_name':'Sneaky Org','org_provider_type':'lab'})
rec('O11 a role without the "records.write" permission cannot open or submit the wizard', s==403 and s2==403 and dbq("select count(*) from credential_holders")==n5 and dbq("select count(*) from credential_holders where org_name='Sneaky Org'")=='0', f'get={s} post={s2}')
# CSP: behaviour that used inline handlers now has a server-side or data-attribute path
rq='33333333-4444-4555-8666-%012d'%int(time.time()%1e9)
dbq("insert into credentialing_case_requirements (id,tenant_id,case_id,requirement_code,label,status,is_required,created_at) values ('%s','%s','%s','state_medical_license','Smoke requirement','pending',1,utc_timestamp())"%(rq,tid,ids['case_P1']))
s,b,_=req(opo,'/cases/'+ids['case_P1'])
rec('O12 the case requirement status form has a real Save button (it used to depend on an inline handler the CSP blocks)', s==200 and 'data-autosubmit' in b and re.search(r'name="action" value="req_status".*?<button class="btn sm line">', b, re.S) is not None and not re.search(r'\son[a-z]+="', b), f'status {s}')
s,_,_=req(opo,'/cases/'+ids['case_P1'],{'_csrf':csrf(b),'action':'req_status','req_id':rq,'status':'in_progress'})
rec('O12 submitting that form changes the requirement status', dbq("select status from credentialing_case_requirements where id='%s'"%rq)=='in_progress', dbq("select status from credentialing_case_requirements where id='%s'"%rq))
s,js,_=req(opo,'/assets/app.js')
rec('O12 the shared script that replaces inline handlers is served', s==200 and 'wireDataBehaviours' in js and 'data-autosubmit' in js)

# "Add provider" (/holders/new) is two real forms too — a person and an organization — not one form with fields that do not apply
s,bi,_=req(opo,'/holders/new'); s2,bo,_=req(opo,'/holders/new?type=organization')
rec('O13 Add provider for a person asks for first/last name and professions, never an organization name or an organization type',
    s==200 and 'name="first_name"' in bi and 'name="last_name"' in bi and 'name="birth_month"' in bi and 'name="org_name"' not in bi and '<option value="physician"' in bi and '<option value="lab"' not in bi and '<option value="group"' not in bi and '<option value="clinic"' not in bi and 'value="individual"' in bi, f'status {s}')
rec('O14 Add provider for an organization asks for the organization name and organization types, never person fields or professions',
    s2==200 and 'name="org_name"' in bo and 'name="first_name"' not in bo and 'name="last_name"' not in bo and 'name="birth_month"' not in bo and 'name="role_title"' not in bo and '<option value="lab"' in bo and '<option value="group"' in bo and '<option value="clinic"' in bo and '<option value="physician"' not in bo and 'portal login' not in bo and 'value="organization"' in bo, f'status {s2}')
n6=dbq("select count(*) from credential_holders")
s,_,hd=req(opo,'/holders',{'_csrf':csrf(bo),'holder_type':'organization','org_name':'Harbor Lab O15','first_name':'Sneaky','last_name':'Person','birth_month':'5','role_title':'Director','provider_type':'physician','npi':''})
rowo=dbq("select concat_ws('|',coalesce(first_name,'-'),coalesce(last_name,'-'),coalesce(birth_month,'-'),coalesce(role_title,'-'),coalesce(provider_type,'-'),org_name) from credential_holders where org_name='Harbor Lab O15'")
rec('O15 an organization is saved with only organization fields (person fields in the request are dropped; a profession is not accepted as its type; no e-mail needed)', s in (302,303) and rowo=='-|-|-|-|-|Harbor Lab O15' and dbq("select count(*) from credential_holders")==str(int(n6)+1), f'status {s} row {rowo}')
s,_,hd=req(opo,'/holders',{'_csrf':csrf(bi),'holder_type':'individual','first_name':'Ines','last_name':'Person O16','email':'ines.o16@smoke.test','org_name':'Sneaky Org O16','provider_type':'lab'})
rowi=dbq("select concat_ws('|',first_name,last_name,coalesce(org_name,'-'),coalesce(provider_type,'-')) from credential_holders where last_name='Person O16'")
rec('O16 a person is saved with only person fields (an organization name or an organization type in the request is dropped)', s in (302,303) and rowi=='Ines|Person O16|-|-', f'status {s} row {rowi}')
oid=dbq("select id from credential_holders where org_name='Harbor Lab O15'")
s,be,_=req(opo,'/holders/%s/edit'%oid)
s2,_,_=req(opo,'/holders/%s'%oid,{'_csrf':csrf(be),'org_name':'Harbor Lab O15 renamed','first_name':'Sneaky','provider_type':'lab'})
rowe=dbq("select concat_ws('|',coalesce(first_name,'-'),org_name,coalesce(provider_type,'-')) from credential_holders where id='%s'"%oid)
rec('O17 editing an organization shows only organization fields and cannot add person fields',
    s==200 and 'name="org_name"' in be and 'name="first_name"' not in be and 'name="birth_month"' not in be and '<option value="physician"' not in be and s2 in (302,303) and rowe=='-|Harbor Lab O15 renamed|lab', f'edit {s} save {s2} row {rowe}')

for u in ['owner','manager','staff','coord','readonly','restricted','padmin']:
    n,c,bad=crawl(f'{u}@smoke.test')
    # v1.171.5: a crawl that reached almost nothing (everything redirecting to the installer or the login page) is a FAILURE, not a pass.
    ok200=c.get(200,0); vacuous = n < 8 or ok200 < max(5, n//3)
    print(f'{"PASS" if not bad and not vacuous else "FAIL"} — crawl as {u}: pages={n} codes={c}' + (' — VACUOUS (too few pages / too few 200s)' if vacuous else ''))
    for b in bad[:15]: print('   BAD', b)
    if bad or vacuous: FAILS[0]+=1
print('\nALL PASS' if FAILS[0]==0 else '\nSOME FAILED')
sys.exit(1 if FAILS[0] else 0)
