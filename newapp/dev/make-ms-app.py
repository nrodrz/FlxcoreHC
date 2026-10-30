#!/usr/bin/env python3
# © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
"""
make-ms-app.py — makes the starting code of a new app (Microsoft services only) from a full-install copy of the
reference app.

What it does, in order (every step is printed and written to STARTER-REPORT.md in the output folder):
  1. Copies the reference app's install folder (or unzips its Full-Install zip) to --out, leaving out the reference
     app's own documents, its vTiger bridge, any config.php / license file, and build history.
  2. Makes the product: only the modules in --keep exist for this product. Every other module is always off (its
     permissions refused for everyone, its routes 404, its menu pages hidden, its scheduled steps skipped), and its API
     route files and public pages are deleted. The plans become this product's plans.
  3. Sets the identity: product name and product line (Brand.php, page titles, installer defaults), build id
     (<prefix>-A001), a fresh CHANGELOG.txt. Owner, copyright and code identifiers stay as they are.
  4. Starts a new ecosystem: removes the reference app's License Hub public key lines (license and release keys), so
     this app is licensed and updated only by its own main installation (install/hub.php) once its own keys are built in.
  5. Writes STARTER-REPORT.md: what was removed, and where the screens still hold code for the removed modules
     (to prune in the next steps — see docs/NEW-APP-SPEC.md).
  6. Builds the screens (src/build.js) when Node and @babel/standalone are available, and checks every PHP file parses.

Usage:
  python3 make-ms-app.py --src <reference install folder or Full-Install zip> --out <new folder>
                         [--name "NewApp"] [--line "Microsoft 365 Administration"] [--prefix 0001]
                         [--keep m365,onboarding,security_assessment,teams_app] [--babel <node_modules folder>]
The script never touches --src. Run it again on a newer reference build to start over from that build.
"""
import argparse, json, os, re, shutil, subprocess, sys, tempfile, zipfile, datetime

DEFAULT_KEEP = ['m365', 'onboarding', 'security_assessment', 'teams_app']
# Files and folders of the reference app that never go into the new app
DROP_TOP = {'config.php', 'OPSHUB-AUDIT.md', 'BT14-SECURITY-VALIDATION.md', 'BT16-INTEGRATION-AUDIT-PLAN.md', 'BT16-RELEASE-NOTES.md',
            'DATA-DICTIONARY.md', 'vtiger-bridge', 'opsh-license.php', '.git'}
MOVE_TO_DOCS = {'INTUNE-DEFENDER-SETUP.md', 'API-INTEGRATION-GUIDE.md'}
# Public pages and the module each one serves (deleted when that module is not kept)
PUBLIC_PAGES = {'submit': 'service_desk', 'confirm': 'service_desk', 'form': 'service_desk', 'rate': 'service_desk',
                'read': 'announcements', 'sign': 'documents'}

log = []
def say(s):
    print(s); log.append(s)

def die(s):
    print('ERROR: ' + s, file=sys.stderr); sys.exit(1)

def rd(p): return open(p, encoding='utf-8').read()
def wr(p, s): open(p, 'w', encoding='utf-8').write(s)

def sub_once(path, old, new, what):
    s = rd(path)
    n = s.count(old)
    if n != 1: die(f'{what}: expected the text once in {path}, found it {n} times (the reference build changed; update the script)')
    wr(path, s.replace(old, new)); say(f'  - {what}')

def php_catalog(root):
    """The module catalog as the reference app defines it: {key: {label, routes, pages, perms, depends}} read by PHP."""
    code = ("require 'api/lib/Modules.php'; $o=[]; foreach (opsh_modules_catalog() as $k=>$m) "
            "$o[$k]=['label'=>$m[0],'depends'=>$m[2],'perms'=>$m[3],'routes'=>$m[4],'pages'=>$m[5]]; echo json_encode($o);")
    try:
        out = subprocess.run(['php', '-r', code], cwd=root, capture_output=True, text=True, timeout=60)
        return json.loads(out.stdout)
    except Exception as e:
        die('could not read the module catalog with PHP (php must be on the PATH): ' + str(e))

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--src', required=True); ap.add_argument('--out', required=True)
    ap.add_argument('--name', default='NewApp'); ap.add_argument('--line', default='Microsoft 365 Administration')
    ap.add_argument('--prefix', default='0001'); ap.add_argument('--keep', default=','.join(DEFAULT_KEEP))
    ap.add_argument('--babel', default=os.environ.get('NODE_PATH', ''))
    a = ap.parse_args()
    if not re.match(r"^[A-Za-z][A-Za-z0-9 .\-]{1,40}$", a.name): die('--name: letters, digits, spaces, dots and hyphens, 2–41 characters')
    if "'" in a.line or len(a.line) > 80: die('--line: at most 80 characters, no single quote')
    if os.path.exists(a.out) and os.listdir(a.out): die(f'{a.out} exists and is not empty')

    # 1. copy
    src = a.src; tmp = None
    if src.endswith('.zip'):
        tmp = tempfile.mkdtemp(); zipfile.ZipFile(src).extractall(tmp); src = tmp
    if not os.path.isfile(os.path.join(src, 'api', 'lib', 'Modules.php')): die('--src is not an install folder of the reference app')
    say(f'1. Copy {a.src} -> {a.out}')
    os.makedirs(a.out, exist_ok=True)
    for n in sorted(os.listdir(src)):
        if n in DROP_TOP: say(f'  - left out {n}'); continue
        s, d = os.path.join(src, n), os.path.join(a.out, n)
        if n in MOVE_TO_DOCS:
            os.makedirs(os.path.join(a.out, 'docs', 'reference'), exist_ok=True); shutil.copy2(s, os.path.join(a.out, 'docs', 'reference', n)); say(f'  - {n} -> docs/reference/'); continue
        (shutil.copytree if os.path.isdir(s) else shutil.copy2)(s, d)
    root = a.out

    # 2. the product's modules
    cat = php_catalog(root)
    keep = [k.strip() for k in a.keep.split(',') if k.strip()]
    bad = [k for k in keep if k not in cat]
    if bad: die('unknown module(s) in --keep: ' + ', '.join(bad) + '. Known: ' + ', '.join(cat))
    for k in list(keep):
        for d in cat[k]['depends']:
            if d not in keep: die(f'module {k} needs {d}: add it to --keep')
    gone = [k for k in cat if k not in keep]
    say('2. Product modules: ' + ', '.join(keep) + '   (removed: ' + ', '.join(gone) + ')')
    mp = os.path.join(root, 'api', 'lib', 'Modules.php')
    keep_php = ', '.join("'" + k + "'" for k in keep)
    sub_once(mp, "    /** The plans that can be sold. A license names one; it may add modules by key on top. */",
             "    /** NEW APP: the modules this product has. Every other module of the catalog is always off here (its permissions refused,\n"
             "     *  its routes 404, its pages hidden, its scheduled steps skipped); the screens do not list it. */\n"
             "    function opsh_product_modules(): array { return [" + keep_php + "]; }\n\n"
             "    /** The plans that can be sold. A license names one; it may add modules by key on top. */", 'Modules.php: opsh_product_modules()')
    # plans: this product's
    s = rd(mp)
    m = re.search(r"    function opsh_plans\(\): array \{.*?\n    \}\n", s, re.S)
    if not m: die('Modules.php: opsh_plans() not found')
    essentials = [k for k in keep if k not in ('security_assessment',)]
    plans = ("    function opsh_plans(): array {\n        $all = opsh_product_modules();\n        return [\n"
             "            'complete' => ['Complete', 'Every module of this product.', $all],\n"
             "            'essentials' => ['Essentials', 'The Microsoft 365 administration without the security assessment.', array_values(array_intersect([" +
             ', '.join("'" + k + "'" for k in essentials) + "], $all))],\n        ];\n    }\n")
    wr(mp, s[:m.start()] + plans + s[m.end():]); say('  - Modules.php: plans Complete and Essentials')
    sub_once(mp, "        // 2. switched off by a Super Admin\n",
             "        $licensed = array_values(array_intersect($licensed, opsh_product_modules()));   // NEW APP: only this product's modules\n        // 2. switched off by a Super Admin\n",
             'Modules.php: licensed limited to the product modules')
    sub_once(mp, "        foreach (opsh_modules_catalog() as $k => $m) {\n            $out[] = ['key' => $k,",
             "        foreach (opsh_modules_catalog() as $k => $m) {\n            if (!in_array($k, opsh_product_modules(), true)) continue;   // NEW APP: not part of this product\n            $out[] = ['key' => $k,",
             'Modules.php: the screens list only the product modules')
    # delete the API routes and public pages of removed modules (they would 404 anyway)
    keep_routes = set(r for k in keep for r in cat[k]['routes'])
    for k in gone:
        for r in cat[k]['routes']:
            if r in keep_routes: continue
            f = os.path.join(root, 'api', 'routes', r + '.php')
            if os.path.isfile(f): os.remove(f); say(f'  - deleted api/routes/{r}.php ({k})')
    for pg, k in PUBLIC_PAGES.items():
        if k in keep: continue
        for ext in ('html', 'js'):
            f = os.path.join(root, pg + '.' + ext)
            if os.path.isfile(f): os.remove(f); say(f'  - deleted {pg}.{ext} ({k})')

    # 3. identity
    say('3. Identity: ' + a.name + ' — ' + a.line)
    bp = os.path.join(root, 'api', 'lib', 'Brand.php')
    s = rd(bp)
    s2 = re.sub(r"define\('OPSH_PRODUCT', '[^']*'\);", "define('OPSH_PRODUCT', '" + a.name + "');", s, count=1)
    s2 = re.sub(r"define\('OPSH_PRODUCT_LINE', '[^']*'\);", "define('OPSH_PRODUCT_LINE', '" + a.line + "');", s2, count=1)
    if s2 == s: die('Brand.php: product constants not found')
    wr(bp, s2); say('  - Brand.php: OPSH_PRODUCT, OPSH_PRODUCT_LINE')
    for f in ['index.html', 'help.html', 'guide.html', 'pc.html', 'install/index.php', 'install/hub.php']:
        p = os.path.join(root, f)
        if not os.path.isfile(p): continue
        s = rd(p)
        s2 = re.sub(r'<title>OpsHub', '<title>' + a.name, s)
        s2 = s2.replace('apple-mobile-web-app-title" content="OpsHub"', 'apple-mobile-web-app-title" content="' + a.name + '"')
        s2 = s2.replace("$_POST['app_name'] ?? 'OpsHub'", "$_POST['app_name'] ?? '" + a.name + "'").replace("$i['app_name'] ?? 'OpsHub'", "$i['app_name'] ?? '" + a.name + "'")
        s2 = s2.replace('exit("OpsHub is already installed.', 'exit("' + a.name + ' is already installed.').replace("'OpsHub v1.0'", "'" + a.name + " v1.0'")
        if s2 != s: wr(p, s2); say('  - ' + f + ': product name')
    bid = a.prefix + '-A001'
    s = rd(os.path.join(root, 'api', 'lib', 'Build.php')); s2 = re.sub(r"define\('OPSH_BUILD', '[^']*'\)", "define('OPSH_BUILD', '" + bid + "')", s)
    if s2 == s: die('Build.php: OPSH_BUILD not found')
    wr(os.path.join(root, 'api', 'lib', 'Build.php'), s2)
    s = rd(os.path.join(root, 'src', 'app.jsx')); s2 = re.sub(r"const APP_BUILD='[^']*';", "const APP_BUILD='" + bid + "';", s, count=1)
    if s2 == s: die('app.jsx: APP_BUILD not found')
    wr(os.path.join(root, 'src', 'app.jsx'), s2); say('  - build id ' + bid + ' (Build.php and src/app.jsx)')
    today = datetime.date.today().strftime('%m/%d/%Y')
    wr(os.path.join(root, 'CHANGELOG.txt'), f"{a.name} — CHANGELOG\n{a.line}. Newest first.\nBuild IDs match the stamp shown under the app and in the browser console.\n\n"
       "========================================================================\n"
       f"{bid} — Starting code ({today}), made by make-ms-app.py from the reference app's install package\n"
       "            [Fresh installation only. Press Ctrl+F5 after uploading.]\n"
       f"  - Modules of this product: {', '.join(cat[k]['label'] for k in keep)}; the platform (sign-in, users, roles, settings,\n"
       "    Audit Log, Event Log, scheduled jobs, Help, About & ownership, Terms of Use, licensing, updates, data dictionary,\n"
       "    Deleted items, API keys) as in the reference app.\n"
       "  - See docs/NEW-APP-SPEC.md for what comes next.\n")
    say('  - CHANGELOG.txt started')

    # 4. own ecosystem: no public key lines of the reference app's License Hub
    say('4. Own License Hub: reference key lines removed')
    for f, pat in (('api/lib/License.php', r"\n[ \t]*'[0-9a-f]{16}' => '[A-Za-z0-9+/=]{40,}',[^\n]*"), ('api/lib/Updates.php', r"\n[ \t]*'[0-9a-f]{16}' => '[A-Za-z0-9+/=]{40,}',[^\n]*")):
        p = os.path.join(root, f); s = rd(p); s2, n = re.subn(pat, '', s)
        wr(p, s2); say(f'  - {f}: {n} key line(s) removed (add this app\'s own after installing its hub)')

    # 5. report: where the screens still hold the removed modules
    say('5. STARTER-REPORT.md')
    jsx = rd(os.path.join(root, 'src', 'app.jsx')).split('\n')
    rep = [f'# Starter report — {a.name} ({bid})', '', f'Made {today} from `{a.src}` with `--keep {",".join(keep)}`.', '',
           '## What the script did', ''] + ['    ' + x for x in log] + ['', '## Removed modules still present in the screens (src/app.jsx)', '',
           'Each is already hidden and refused (the module is off for good); the code is dead weight to prune (NEW-APP-SPEC.md, step 2).', '']
    for k in gone:
        pages = cat[k]['pages']; hits = []
        for i, line in enumerate(jsx, 1):
            for pg in pages:
                if re.search(r"pg===['\"]" + re.escape(pg) + r"['\"]", line): hits.append((i, pg, re.sub(r'\s+', ' ', line.strip())[:140]))
        rep.append(f"### {k} — {cat[k]['label']}")
        rep.append(f"Pages: {', '.join(pages) or '—'} · routes deleted: {', '.join(cat[k]['routes']) or '—'} · permissions: {', '.join(cat[k]['perms']) or '—'}")
        rep += [f'- line {i} (`{pg}`): `{t}`' for i, pg, t in hits] or ['- (no page switch found)']
        rep.append('')
    # library files only the deleted routes loaded
    routes_dir = os.path.join(root, 'api', 'routes'); lib_dir = os.path.join(root, 'api', 'lib')
    used = set()
    for base, _, files in os.walk(root):
        if os.sep + 'vendor' in base: continue
        for f in files:
            if f.endswith('.php'):
                for mm in re.finditer(r"lib/([A-Za-z0-9_]+\.php)|__DIR__ \. '/([A-Za-z0-9_]+\.php)'", rd(os.path.join(base, f))):
                    used.add(mm.group(1) or mm.group(2))
    orphan = sorted(f for f in os.listdir(lib_dir) if f.endswith('.php') and f not in used)
    rep += ['## Library files no remaining file loads', '', 'Candidates to delete after a check (some are loaded by a name built at run time):', '']
    rep += ['- api/lib/' + f for f in orphan] or ['- none']
    wr(os.path.join(root, 'STARTER-REPORT.md'), '\n'.join(rep) + '\n')

    # 6. build and check
    say('6. Build and check')
    bad = []
    for base, _, files in os.walk(root):
        if os.sep + 'vendor' in base: continue
        for f in files:
            if f.endswith('.php'):
                r = subprocess.run(['php', '-l', os.path.join(base, f)], capture_output=True, text=True)
                if r.returncode != 0: bad.append(os.path.join(base, f))
    say(f'  - php -l: {len(bad)} file(s) with errors' + (': ' + ', '.join(bad) if bad else ''))
    if a.babel and shutil.which('node'):
        r = subprocess.run(['node', 'src/build.js'], cwd=root, capture_output=True, text=True, env=dict(os.environ, NODE_PATH=a.babel))
        say('  - src/build.js: ' + ('ok' if r.returncode == 0 else 'FAILED: ' + (r.stderr or r.stdout)[-400:]))
    else:
        say('  - src/build.js not run (give --babel <folder with @babel/standalone 7.24.7>); app.js is the reference build until then')
    if tmp: shutil.rmtree(tmp, ignore_errors=True)
    with open(os.path.join(root, 'STARTER-REPORT.md'), 'a', encoding='utf-8') as fh: fh.write('\n## Build and check\n\n' + '\n'.join('    ' + x for x in log[-2:]) + '\n')
    say('Done: ' + root)
    if bad: sys.exit(2)

if __name__ == '__main__':
    main()
