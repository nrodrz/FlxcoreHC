# Starter report — NewApp (0001-A001)

Made 10/30/2026 from `/home/claude/bt169` with `--keep m365,onboarding,security_assessment,teams_app`.

## What the script did

    1. Copy /home/claude/bt169 -> /home/claude/msapp/out
      - API-INTEGRATION-GUIDE.md -> docs/reference/
      - left out BT14-SECURITY-VALIDATION.md
      - left out BT16-INTEGRATION-AUDIT-PLAN.md
      - left out BT16-RELEASE-NOTES.md
      - left out DATA-DICTIONARY.md
      - INTUNE-DEFENDER-SETUP.md -> docs/reference/
      - left out OPSHUB-AUDIT.md
      - left out vtiger-bridge
    2. Product modules: m365, onboarding, security_assessment, teams_app   (removed: service_desk, cases, calendar, scorecard, standards, announcements, knowledge, documents, equipment, vault, contracts, vtiger, company_systems, servers, budget, integration_audit)
      - Modules.php: opsh_product_modules()
      - Modules.php: plans Complete and Essentials
      - Modules.php: licensed limited to the product modules
      - Modules.php: the screens list only the product modules
      - deleted api/routes/tickets.php (service_desk)
      - deleted api/routes/ticket_categories.php (service_desk)
      - deleted api/routes/forms.php (service_desk)
      - deleted api/routes/public_ticket.php (service_desk)
      - deleted api/routes/public_form.php (service_desk)
      - deleted api/routes/public_upload.php (service_desk)
      - deleted api/routes/public_csat.php (service_desk)
      - deleted api/routes/reports.php (service_desk)
      - deleted api/routes/improvements.php (service_desk)
      - deleted api/routes/cases.php (cases)
      - deleted api/routes/calendar.php (calendar)
      - deleted api/routes/scorecard.php (scorecard)
      - deleted api/routes/standards.php (standards)
      - deleted api/routes/standards_domains.php (standards)
      - deleted api/routes/announcements.php (announcements)
      - deleted api/routes/public_ann.php (announcements)
      - deleted api/routes/kb.php (knowledge)
      - deleted api/routes/kb_public.php (knowledge)
      - deleted api/routes/sops.php (documents)
      - deleted api/routes/job_descriptions.php (documents)
      - deleted api/routes/signatures.php (documents)
      - deleted api/routes/public_sign.php (documents)
      - deleted api/routes/documents.php (documents)
      - deleted api/routes/assets.php (equipment)
      - deleted api/routes/credentials.php (vault)
      - deleted api/routes/contracts.php (contracts)
      - deleted api/routes/vendors.php (contracts)
      - deleted api/routes/vendor_contacts.php (contracts)
      - deleted api/routes/invoices.php (contracts)
      - deleted api/routes/vtiger_users.php (vtiger)
      - deleted api/routes/vtiger_provision.php (vtiger)
      - deleted api/routes/access.php (company_systems)
      - deleted api/routes/guardian.php (servers)
      - deleted api/routes/guardian_action.php (servers)
      - deleted api/routes/uptime.php (servers)
      - deleted api/routes/backups.php (servers)
      - deleted api/routes/mon360.php (servers)
      - deleted api/routes/server_perf.php (servers)
      - deleted api/routes/budget.php (budget)
      - deleted api/routes/integration_audit.php (integration_audit)
      - deleted submit.html (service_desk)
      - deleted submit.js (service_desk)
      - deleted confirm.html (service_desk)
      - deleted confirm.js (service_desk)
      - deleted form.html (service_desk)
      - deleted form.js (service_desk)
      - deleted rate.html (service_desk)
      - deleted rate.js (service_desk)
      - deleted read.html (announcements)
      - deleted read.js (announcements)
      - deleted sign.html (documents)
      - deleted sign.js (documents)
    3. Identity: NewApp — Microsoft 365 Administration
      - Brand.php: OPSH_PRODUCT, OPSH_PRODUCT_LINE
      - index.html: product name
      - install/index.php: product name
      - build id 0001-A001 (Build.php and src/app.jsx)
      - CHANGELOG.txt started
    4. Own License Hub: reference key lines removed
      - api/lib/License.php: 1 key line(s) removed (add this app's own after installing its hub)
      - api/lib/Updates.php: 1 key line(s) removed (add this app's own after installing its hub)
    5. STARTER-REPORT.md

## Removed modules still present in the screens (src/app.jsx)

Each is already hidden and refused (the module is off for good); the code is dead weight to prune (NEW-APP-SPEC.md, step 2).

### service_desk — Service Desk
Pages: tickets, categories, forms, reports, improvements · routes deleted: tickets, ticket_categories, forms, public_ticket, public_form, public_upload, public_csat, reports, improvements · permissions: tickets, categories, forms, reports, sla_cfg
- line 14321 (`tickets`): `{pgAllowed&&pg==='tickets'&&<Tickets lookups={lookups} user={user} toast={toast} focus={focus} setFocus={setFocus}/>}`
- line 14332 (`categories`): `{pgAllowed&&pg==='categories'&&<CategoriesPage lookups={lookups} toast={toast} reloadLookups={loadLookups} user={user}/>}`
- line 14333 (`forms`): `{pgAllowed&&pg==='forms'&&<FormsPage toast={toast}/>}`
- line 14369 (`improvements`): `{pgAllowed&&pg==='improvements'&&<Improvements user={user} toast={toast} focus={focus} setFocus={setFocus}/>}`
- line 14370 (`reports`): `{pgAllowed&&pg==='reports'&&<Reports lookups={lookups} toast={toast} setPg={setPg} setFocus={setFocus}/>}`

### cases — Cases & Projects
Pages: cases, my_tasks · routes deleted: cases · permissions: cases
- line 14322 (`cases`): `{pgAllowed&&pg==='cases'&&<Cases lookups={lookups} user={user} toast={toast} focus={focus} setFocus={setFocus}/>}`
- line 14366 (`my_tasks`): `{pgAllowed&&pg==='my_tasks'&&<MyTasks user={user} toast={toast}/>}`

### calendar — Calendar
Pages: calendar · routes deleted: calendar · permissions: calendar
- line 14365 (`calendar`): `{pgAllowed&&pg==='calendar'&&<Calendar toast={toast} setPg={setPg} setFocus={setFocus} focus={focus} lookups={lookups} user={user}/>}`

### scorecard — Scorecard
Pages: scorecard · routes deleted: scorecard · permissions: scorecard
- line 14344 (`scorecard`): `{pgAllowed&&pg==='scorecard'&&<Scorecard toast={toast}/>}`

### standards — Standards & Audits
Pages: standards · routes deleted: standards, standards_domains · permissions: standards
- line 14334 (`standards`): `{pgAllowed&&pg==='standards'&&<StandardsHub lookups={lookups} toast={toast} user={user} canEdit={isAdm||can('standards','edit')}/>}`

### announcements — Announcements
Pages: announcements · routes deleted: announcements, public_ann · permissions: announcements
- line 14320 (`announcements`): `{pgAllowed&&pg==='announcements'&&<AnnouncementsPage toast={toast} lookups={lookups} onChanged={loadNotifs}/>}`

### knowledge — Knowledge Base
Pages: kb, kb_assist, kb_gaps · routes deleted: kb, kb_public · permissions: kb, kb_assist, kb_gaps
- line 14323 (`kb`): `{pgAllowed&&pg==='kb'&&<KB lookups={lookups} toast={toast}/>}`
- line 14346 (`kb_assist`): `{pgAllowed&&pg==='kb_assist'&&<KBAssistant toast={toast}/>}`
- line 14347 (`kb_gaps`): `{pgAllowed&&pg==='kb_gaps'&&<KBGaps toast={toast}/>}`

### documents — SOPs & Job Descriptions
Pages: sops, job_descriptions, my_signatures · routes deleted: sops, job_descriptions, signatures, public_sign, documents · permissions: sops, job_descriptions, my_signatures
- line 14324 (`sops`): `{pgAllowed&&pg==='sops'&&<CrudList res="sops" cfg={CRUD.sops} lookups={lookups} toast={toast} setPg={setPg} focus={focus} setFocus={setFocus`
- line 14325 (`job_descriptions`): `{pgAllowed&&pg==='job_descriptions'&&<CrudList res="job_descriptions" cfg={CRUD.job_descriptions} lookups={lookups} toast={toast} setPg={set`
- line 14339 (`my_signatures`): `{pgAllowed&&pg==='my_signatures'&&<MySignatures toast={toast}/>}`

### equipment — Equipment
Pages: assets, my_equipment · routes deleted: assets · permissions: assets, my_equipment
- line 14342 (`assets`): `{pgAllowed&&pg==='assets'&&<AssetsScreen toast={toast}/>}`
- line 14343 (`my_equipment`): `{pgAllowed&&pg==='my_equipment'&&<MyEquipment toast={toast}/>}`

### vault — Credentials
Pages: credentials · routes deleted: credentials · permissions: creds
- line 14326 (`credentials`): `{pgAllowed&&pg==='credentials'&&<CrudList res="credentials" cfg={CRUD.credentials} lookups={lookups} toast={toast} setPg={setPg} focus={focu`

### contracts — Contracts & Vendors
Pages: contracts, vendors, invoices · routes deleted: contracts, vendors, vendor_contacts, invoices · permissions: contracts, vendors, invoices
- line 14327 (`contracts`): `{pgAllowed&&pg==='contracts'&&<CrudList res="contracts" cfg={CRUD.contracts} lookups={lookups} toast={toast} setPg={setPg} focus={focus} set`
- line 14328 (`vendors`): `{pgAllowed&&pg==='vendors'&&<CrudList res="vendors" cfg={CRUD.vendors} lookups={lookups} toast={toast} setPg={setPg} focus={focus} setFocus=`
- line 14357 (`invoices`): `{pgAllowed&&pg==='invoices'&&<InvoicesScreen toast={toast} setPg={setPg}/>}`

### vtiger — vTiger
Pages: vtiger_users · routes deleted: vtiger_users, vtiger_provision · permissions: vtiger_users, vtiger_credentials, provision_onboard, provision_offboard
- line 14354 (`vtiger_users`): `{pgAllowed&&pg==='vtiger_users'&&<VtigerUsersScreen toast={toast}/>}`

### company_systems — Company systems
Pages: — · routes deleted: access · permissions: access
- (no page switch found)

### servers — Servers
Pages: guardian, server_perf, uptime, backups · routes deleted: guardian, guardian_action, uptime, backups, mon360, server_perf · permissions: guardian, uptime, backups
- line 14350 (`uptime`): `{pgAllowed&&pg==='uptime'&&<UptimePage toast={toast}/>}`
- line 14351 (`server_perf`): `{pgAllowed&&pg==='server_perf'&&<ServerPerfPage toast={toast}/>}`
- line 14352 (`backups`): `{pgAllowed&&pg==='backups'&&<BackupsScreen toast={toast}/>}`
- line 14355 (`guardian`): `{pgAllowed&&pg==='guardian'&&<GuardianScreen toast={toast}/>}`

### budget — IT Budget
Pages: budget · routes deleted: budget · permissions: budget
- line 14356 (`budget`): `{pgAllowed&&pg==='budget'&&<BudgetScreen toast={toast}/>}`

### integration_audit — Integration Audit
Pages: integration_audit · routes deleted: integration_audit · permissions: integration_audit
- line 14338 (`integration_audit`): `{pgAllowed&&pg==='integration_audit'&&<IntegrationAuditScreen toast={toast}/>}`

## Library files no remaining file loads

Candidates to delete after a check (some are loaded by a name built at run time):

- api/lib/BudgetDoc.php
- api/lib/CtmService.php
- api/lib/GuardianRemediate.php
- api/lib/IaCronSync.php
- api/lib/JustCallService.php
- api/lib/KbRolloutGuides.php
- api/lib/KbSeed.php
- api/lib/Redirects.php
- api/lib/ScorecardLink.php
- api/lib/SopAudience.php
- api/lib/SystemGuides.php
- api/lib/TicketRelated.php
- api/lib/Uploads.php
- api/lib/VTigerCheck.php
- api/lib/VTigerUsers.php

## Build and check

      - php -l: 0 file(s) with errors
      - src/build.js: ok
