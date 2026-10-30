// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
// dev/prune-guide.js (local tool, never shipped; 0001-A003): removes the Help Center (guide.js) categories and topics of the modules
// this product does not have. Run once; it prints what it removed.
const fs = require('fs'), path = require('path');
const F = path.resolve(__dirname, '..', 'guide.js'); const s = fs.readFileSync(F, 'utf8');
const i = s.indexOf('const CONTENT ='); const j = s.indexOf('\n', i); let t = s.slice(i + 15, j).trim(); const semi = t.endsWith(';'); if (semi) t = t.slice(0, -1);
const C = JSON.parse(t);
const CATS = ['Tickets & support', 'Forms & intake', 'Knowledge & procedures', 'Vault, contracts & vendors', 'Calendar & reports', 'Equipment & loans', 'Monitoring & scorecard'];
const TOPICS = [/^Announcements/, /Ticket categories and SLA/, /^Priority targets$/, /^SLA alerts/, /Email-to-ticket/, /^Cases & Projects/, /^Standards & Audits$/, /^KB Content Gaps$/,
  /^Renewal and rotation reminders/, /Company systems/, /vTiger/, /^Equipment check-in/, /^HubSpot/, /JustCall/, /CallTrackingMetrics/, /^Jotform/, /office phone system/,
  /API key for each company system/, /^IT Budget/, /^Microsoft invoices/, /^Visual guides/, /^Help visuals/];
// the onboarding templates topic stays, without its vTiger parts
for (const c of C.categories) for (const tp of c.topics) if (/^Onboarding templates \(Microsoft 365 \+ vTiger defaults\)$/.test(tp.title)) {
  tp.title = 'Onboarding templates';
  for (const b of tp.body) {
    if (b.steps) b.steps = b.steps.filter(x => !/vTiger defaults/.test(x));
    if (b.bullets) b.bullets = b.bullets.map(x => x.replace(', other company systems and vTiger on the right', ' on the right'));
  }
}
let removed = [];
C.categories = C.categories.filter(c => { if (CATS.includes(c.cat)) { removed.push('category ' + c.cat + ' (' + c.topics.length + ' topics)'); return false; } return true; });
for (const c of C.categories) c.topics = c.topics.filter(tp => { if (TOPICS.some(r => r.test(tp.title))) { removed.push('topic ' + tp.title); return false; } return true; });
C.tagline = 'Microsoft 365 administration';
C.intro = C.app + ' is your IT team’s single place to run the organization’s Microsoft 365 tenant: people and their accounts, onboarding and offboarding, email, devices, licenses, security and the tenant’s security posture. This guide explains, in plain language, what each part does and how to use it.';
const out = s.slice(0, i) + 'const CONTENT = ' + JSON.stringify(C) + (semi ? ';' : '') + s.slice(j);
fs.writeFileSync(F, out);
removed.forEach(x => console.log('removed', x));
console.log(C.categories.map(c => c.cat + ': ' + c.topics.length).join(' · '));
