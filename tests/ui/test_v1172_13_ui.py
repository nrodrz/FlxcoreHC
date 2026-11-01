#!/usr/bin/env python3
# © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
"""
v1.172.13 — Spanish for the messages Claude Code found in English (Playwright + demo server). Evidence class: Claude.ai sandbox only.
  V1 /verify/<bad token>: heading AND body are Spanish
  R1 /register with public sign-up disabled: the refusal message is Spanish
Run:  python3 tests/ui/test_v1172_13_ui.py [BASE]      exit 0 = all pass
"""
import sys
from playwright.sync_api import sync_playwright
BASE = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8098')
fails = []
def check(ok, label):
    print(('PASS' if ok else 'FAIL') + ' — ' + label)
    if not ok: fails.append(label)
with sync_playwright() as pw:
    b = pw.chromium.launch()
    c = b.new_context(locale='es-PR', extra_http_headers={'Accept-Language': 'es-PR,es;q=0.9'}); p = c.new_page()
    p.goto(BASE + '/verify/not-a-real-token'); t = p.evaluate('document.body.innerText')
    check('Puede haber vencido' in t and 'It may have expired' not in t, 'V1 bad verification link: body text is Spanish')
    p.goto(BASE + '/register'); p.wait_for_load_state('networkidle'); t = p.evaluate('document.body.innerText')
    check('El registro público está desactivado' in t and 'Public sign-up is disabled' not in t, 'R1 public sign-up refusal is Spanish')
    c.close()
    b.close()
print('\nALL PASS' if not fails else '\n%d FAILED' % len(fails)); sys.exit(1 if fails else 0)
