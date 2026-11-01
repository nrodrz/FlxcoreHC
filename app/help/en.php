<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * Help content — English. Each section: title, summary, and an ordered list of blocks:
 *   ['h', 'Heading'], ['p', 'Paragraph'], ['steps', ['Step one', 'Step two']], ['tip', 'Callout']
 * Keep it task-oriented: what it is, how to do it, what to expect.
 */
return [
  'getting-started' => ['title' => 'Getting started', 'summary' => 'From a blank organization to a working compliance calendar in an afternoon.', 'blocks' => [
    ['p', 'FlxCoreHC tracks every license, certification, training and document your providers must keep current, and turns their expiry dates into a calendar, reminders and reports. The fastest path for a new organization is the checklist on the Dashboard.'],
    ['steps', ['Invite your team (Team) and set each person\'s role and provider access.',
               'Add your Locations (practice sites) and Groups (client groups or departments).',
               'Import providers from a spreadsheet (Providers → Import CSV) — the template includes group and location columns so the roster lands with its structure.',
               'Import their credentials (Credentials → Import CSV). Expiry dates flow to the calendar automatically.',
               'Connect your own email (Organization → Email delivery) so invites and reminders come from your domain.',
               'Open the Calendar — the Agenda shows what is due, grouped by urgency.']],
    ['tip', 'You can dismiss the dashboard checklist once you are set up; the features remain in the navigation.'],
    ['p', 'Once running, see also: Document requests (providers upload directly in their portal), Sharing with insurers & agencies (password-protected links), Linking with other organizations (consent-based credential sharing), and Activity & audit for the daily picture.'],
  ]],
  'providers' => ['title' => 'Providers', 'summary' => 'The people and entities you credential: physicians, nurses, therapists, labs, clinics.', 'blocks' => [
    ['h', 'Adding providers'], ['p', 'Providers → Add provider creates one record. Choose the professional type (physician, NP, PA, dentist, therapist, …) — it drives which credentialing template applies. Enter the NPI to auto-fill from the national registry.'],
    ['h', 'Bulk import'], ['p', 'Providers → Import CSV. Download the template; each row is a provider. The group and location columns are matched by name or created for you. Preview shows every row with a reason for any error; fix the file and re-upload, or import the good rows.'],
    ['h', 'Provider page'], ['p', 'Each provider has a page with their credentials, documents, group memberships, practice locations, assigned staff, sharing clearance, and — for managers — a full History of every change with who made it.'],
    ['h', 'Profile packet'], ['p', 'The Profile packet button produces a print-ready dossier: identity and NPI, every credential with number, issuer and dates, documents with their verification status, exclusion-screening results, and required items not on file. Use Print / Save as PDF for payer enrollment or audits.'],
  ]],
  'credentials' => ['title' => 'Credentials & renewals', 'summary' => 'Licenses, certifications, trainings, insurance and identifiers — with expiry dates that drive everything.', 'blocks' => [
    ['h', 'Adding a credential'], ['p', 'Credentials → Add credential. Pick the provider and the document type. If the type renews on a cycle and you enter an issue date, the expiry is suggested automatically (issued + cycle). You can always override it.'],
    ['h', 'Bulk import'], ['p', 'Credentials → Import CSV. Identify the provider by NPI or by first + last name. The credential_type is a code from Organization → Document types (for example state_medical_license, dea, malpractice, cme). Leave expiry blank on a cycled type and it is calculated from the issue date.'],
    ['h', 'Status'], ['p', 'Active, Expiring (within the expiring window, 90 days by default), or Expired — derived from the expiry date. Credentials with no expiry never expire.'],
    ['h', 'Document types'], ['p', 'Organization → Document types lists built-in types and lets you add your own with a category, a renewal cycle (annual, every 2 years, …) and a jurisdiction. Custom types can be edited; the short code stays fixed so existing records keep pointing at it.'],
    ['h', 'Documents & verification'], ['p', 'Upload the actual file to a credential. Mark it Verified with the method used (primary source, copy, attestation), an optional note, and a re-verification date. FlxCoreHC records your organization\'s verification activity and evidence; it does not independently perform or certify primary-source verification. Verified documents show in the profile packet.'],
  ]],
  'templates' => ['title' => 'Requirement templates', 'summary' => 'What each kind of professional must have on file.', 'blocks' => [
    ['p', 'Templates list the credentials a professional type is expected to hold. FlxCoreHC ships bundles for physicians, nurse practitioners, physician assistants, nurses, dentists, pharmacists, therapists, behavioral health, chiropractors, technologists, labs, clinics and groups — prescribers carry DEA, non-prescribers do not.'],
    ['p', 'Apply a template to a provider from their page; the compliance engine then scores them on required items present, expiring or missing. Templates are editable — add or remove items to match your payers\' requirements.'],
  ]],
  'groups-locations' => ['title' => 'Groups & locations', 'summary' => 'Organize a large roster the way your business is structured.', 'blocks' => [
    ['h', 'Groups'], ['p', 'For a billing company these are the client groups you serve; for a hospital, departments. A provider can belong to several (a physician covering two groups, a nurse working for two departments); one can be marked primary. Manage them under Groups, or from each provider\'s page.'],
    ['h', 'Locations'], ['p', 'Practice sites with address and optional organizational NPI. Affiliate providers with the sites where they practice; one can be primary.'],
    ['h', 'Filtering'], ['p', 'The provider list, the calendar and the readiness report can all be narrowed by group or location, so "everything due this week for Grupo Médico A" is one click.'],
  ]],
  'calendar' => ['title' => 'Calendar', 'summary' => 'Every deadline, in a view that stays readable with thousands of items.', 'blocks' => [
    ['h', 'Agenda (default)'], ['p', 'Open items grouped by urgency — Overdue, Due this week, Due this month, Later — each with a count. Deadlines from credential expiries appear automatically; no manual sync needed.'],
    ['h', 'Filters'], ['p', 'Narrow by provider, group, location or event type. Filters persist as you switch views.'],
    ['h', 'Month & List'], ['p', 'The classic views. Busy days show "+N more" that opens that day\'s list. Drag an event to move it; mark items done; add manual events (meetings, site visits, calls).'],
    ['h', 'Subscribe'], ['p', 'Calendar → Subscribe gives a private iCalendar URL you can add to Google Calendar, Outlook or Apple Calendar so deadlines appear alongside your other appointments. Rotate the link if it is ever shared.'],
  ]],
  'notifications' => ['title' => 'Notifications', 'summary' => 'The bell tells you what needs attention.', 'blocks' => [
    ['p', 'Notifications are generated for credentials expiring within 30 days (with a second alert inside 7 days) and already expired, document verifications due for re-check, overdue calendar items, exclusion-screening flags, providers assigned to you, and completed imports.'],
    ['p', 'They respect need-to-know: if your provider access is "assigned only", you are notified only about your assigned providers. Open marks an item read and takes you to it; Mark all read clears the badge.'],
  ]],
  'reports' => ['title' => 'Reports & exports', 'summary' => 'Compliance readiness you can hand to a client or an auditor.', 'blocks' => [
    ['h', 'Compliance reports'], ['p', 'Readiness scores per provider (active = 1, expiring = ½, missing or expired = 0), risk ranking, and expiring windows.'],
    ['h', 'Export readiness (CSV)'], ['p', 'One file: the organization total, readiness by group and by location, then one row per provider.'],
    ['h', 'Credential report'], ['p', 'Every credential with number, dates, days left and status; filter and export to CSV.'],
    ['h', 'Your data'], ['p', 'Organization settings → Download all data: providers, credentials, a document index with checksums, groups, locations, memberships and team as CSV files you own. Nothing is locked in.'],
  ]],
  'screening' => ['title' => 'Exclusion screening', 'summary' => 'OIG LEIE, SAM.gov and state Medicaid exclusion checks.', 'blocks' => [
    ['p', 'Providers are checked against federal and state exclusion lists by NPI and name. Results are review signals stamped with the list date, never a determination. Flags create a notification and appear in the profile packet. Set up the daily screening cron for continuous checks.'],
  ]],
  'team' => ['title' => 'Team, roles & privacy', 'summary' => 'Who can see and do what.', 'blocks' => [
    ['h', 'Roles'], ['p', 'Master Admin (everything, incl. billing and approving platform support), Admin (everything but billing changes), Manager (credentialing operations incl. delete, sharing and audit), Regular User (data entry), Viewer (read-only), Provider (portal). Built-in roles are locked; create your own for anything custom.'],
    ['h', 'Provider access (need-to-know)'], ['p', 'Each user sees either all providers or only those assigned to them. Assignment is per provider from the provider page. Notifications, lists and the calendar all honour it.'],
    ['h', 'Sharing'], ['p', 'Verification links share a provider\'s status externally. Full-file shares require a recorded consent from the provider.'],
    ['h', 'Platform support'], ['p', 'If the platform team needs to look at your account, a Master Admin approves a 4-hour, logged support window that cannot see provider files.'],
  ]],
  'email' => ['title' => 'Email delivery', 'summary' => 'Send from your own mailbox.', 'blocks' => [
    ['p', 'Organization → Email delivery. Choose Own SMTP, click your provider (Google Workspace, Microsoft 365, cPanel) to pre-fill host and port, enter the From address, username and password (an App password for Google; authenticated SMTP must be enabled for Microsoft 365), save, then Send test. A Verified badge confirms delivery works. Leave it on Platform default to use the app\'s sender.'],
  ]],
  'language' => ['title' => 'Language & translations', 'summary' => 'Spanish for the whole interface, editable in-app.', 'blocks' => [
    ['p', 'Switch language from the user menu. The whole interface follows. Platform administrators can refine wording under Console → Translations: strings are grouped by screen; open a group, fill the Spanish column, and save that group. Any English text you encounter while browsing in Spanish is collected there automatically.'],
  ]],
  'documents-mobile' => ['title' => 'Documents: upload, photo & scan', 'summary' => 'Attach any number of documents to a credential — from a file, a phone photo, or a multi-page scan.', 'blocks' => [
    ['h', 'Multiple documents per credential'], ['p', 'A credential can hold as many documents as you need — the license itself, its renewal letter, a supporting affidavit. Each has a kind (Primary, Renewal, Supporting, Correspondence, Other), its own verification, and its own history. A new primary or renewal replaces the previous one; the old file is kept in the chain, never deleted.'],
    ['h', 'From a phone'], ['p', 'On the credential page tap Take photo to use the camera. The photo is sharpened and shrunk on the device before upload (an 8 MB photo becomes under 1 MB), iPhone HEIC is converted, and location data is stripped from the file for privacy.'],
    ['h', 'Multi-page scan'], ['p', 'Scan (multi-page) lets you photograph or choose several pages, enhance each for legibility, remove or reorder them, and Save as PDF — combined into one file in the browser and attached to the credential.'],
    ['tip', 'Documents are stored outside the web root and served only to your organization. Every download is logged.'],
  ]],
  'sensitive-data' => ['title' => 'Sensitive identity data & field-level permissions', 'summary' => 'Date of birth and SSN last-4 are encrypted, visible only to permitted users, and never leave the app.', 'blocks' => [
    ['h', 'What is stored'], ['p', 'Provider → Edit → Sensitive identity data holds the date of birth and the LAST FOUR DIGITS of the SSN. The full SSN is never stored — if a full number is pasted, only the last four are kept. Both are encrypted at rest with the same protection as NPI.'],
    ['h', 'Who can see it'], ['p', 'Only users whose role includes "View sensitive identity data" (`pii.view`) — Master Admin by default; grant it to a specific role if an enrollment specialist needs it. Everyone else sees a mask (••••-••-•• and •••-••-1234) and the panel is hidden entirely on the provider page. Every view by a permitted user is written to the access log.'],
    ['h', 'Where it never appears'], ['p', 'Profile packets, the data export, tenant reports, emails, audit detail, external shares and cross-organization shares. This is enforced in code and asserted by tests, not left to care at each call site.'],
    ['h', 'Per-field rules by role'], ['p', 'Organization → Roles → open a role → Sensitive fields: for each protected field choose Inherit (follow the permission above), Allow or Deny. Deny overrides the permission; Master Admin can never be denied. Works on built-in roles too — e.g. "Managers may see DOB but not SSN".'],
    ['h', 'Sensitive custom fields'], ['p', 'When defining a custom field, tick "Sensitive". It is then encrypted at rest, hidden from anyone without permission (and cannot be changed by them), never shown in packets, lists or exports, and can be allowed per role like the built-in fields.'],
    ['tip', 'This is field-level security: a field declares the permission it needs and the app masks it everywhere else. The same mechanism protects any future field.'],
  ]],
  'custom-fields-readiness' => ['title' => 'Custom fields & application readiness', 'summary' => 'Add your own fields without a developer, and see exactly what an application will ask for that is not on file yet.', 'blocks' => [
    ['h', 'Custom fields'], ['p', 'Organization → Custom fields: define extra fields for providers or for the organization — text, long text, number, date, choice list, yes/no, email, phone, web address — mark any as required, and choose whether they show in the profile packet and lists. They appear on the provider form (Additional fields) and Organization → Profile, and are included in packets and the data export. Retiring a field hides it without losing what was entered.'],
    ['h', 'Application data'], ['p', 'Provider → Edit → Application data: the identity and identifier fields that CAQH, OCS/SICRO, Medicaid PEP, Medicare and payer applications ask for — middle name, suffix, prior names, gender, languages, specialties, taxonomy code, CAQH ID, PTAN, Medicaid ID, hospital affiliations, accepting new patients. Education & training and work history (with gap explanations) are recorded on the provider page.'],
    ['h', 'Application readiness'], ['p', 'On each provider page: a checklist and score of everything an application will ask for, grouped as Identity, Identifiers, Licensure & standing, Insurance & practice, History and Enrollment status — each gap says where to fix it. Collect the gaps before you start an application; chasing missing data mid-process is the main cause of delays.'],
    ['tip', 'Date of birth and SSN are deliberately not collected yet. They carry real privacy weight and would need encryption and need-to-know like NPI; the checklist shows them as decision-pending so the gap is visible.'],
  ]],
  'addresses-contacts' => ['title' => 'Addresses & office contacts', 'summary' => 'Keep several addresses per organization and per provider, cite the right one on each credential, and add office staff who help without needing a login.', 'blocks' => [
    ['h', 'Addresses'], ['p', 'A provider or organization can have any number of addresses — mailing, billing, correspondence, service, legal, home — each optionally labelled and dated. These are distinct from practice locations (which carry an organizational NPI). Add them on the provider page (Addresses) or under Organization → Addresses. The first one becomes primary; change it any time.'],
    ['h', 'Address on a credential'], ['p', 'When adding or editing a credential, pick the address on file with that issuer or payer. This is what makes address-consistency checks possible later: the address a board has can differ from the one a payer has.'],
    ['h', 'Office contacts'], ['p', 'Providers often rely on an office manager, credentialing coordinator or billing contact to keep documents current. Add them on the provider page (Office contacts) with a role, email, phone and preferred language — no portal account is created. Flag whether each receives document requests and/or expiry reminders; requests are then sent to them (in their language) even if the provider has no email on file.'],
  ]],
  'document-requests' => ['title' => 'Document requests (provider portal)', 'summary' => 'Ask a provider or their office for a document; they upload it in their portal. They get an e-mail that only links to the portal — documents are never sent by e-mail.', 'blocks' => [
    ['p', 'From a provider\'s page → Document requests → Request a document. Choose what you need (license, malpractice certificate, CV, …), optionally the credential it belongs to, a note and a needed-by date. The provider sees the request in their portal and gets one email nudge that links to the portal — the email never carries documents, and they are told not to reply with attachments.'],
    ['steps', ['The provider (or their office) opens Document requests in their portal and uploads — camera and scan work there too.',
               'You see it as Submitted. Accept locks the document to your organization; Return sends it back with a note.',
               'After Accept, the provider keeps a read-only record of what they provided. They can never edit or delete it — only your organization can.']],
    ['tip', 'Until you accept, the provider may submit a replacement (for example, a clearer copy). Every step is recorded in the provider\'s permanent timeline.'],
  ]],
  'external-sharing' => ['title' => 'Sharing with insurers, agencies & others', 'summary' => 'A password-protected, time-limited, read-only profile link for people outside FlxCoreHC.', 'blocks' => [
    ['p', 'From a provider\'s page → External shares → Share externally. Pick the recipient type (payer, state agency, accreditation body, hospital, other) and name, which credentials to include, whether to include their documents, how many days the link stays active (up to 180 — share again if more time is needed), and a password (set your own or let FlxCoreHC generate a strong one).'],
    ['h', 'Two factors, delivered separately'], ['p', 'The recipient needs both the link and the password. The password is shown to you once and is never emailed with the link — give it to the recipient by phone or another message. The link alone shows only a password prompt with the recipient\'s name; it reveals nothing about the provider.'],
    ['h', 'Logged and revocable'], ['p', 'Every view, every document download, every wrong password and every attempt after expiry is logged with time and IP address — open Access log on any share. After five wrong passwords the link locks for 30 minutes. Revoke stops access instantly; expired links show a plain notice.'],
    ['tip', 'The recipient sees a branded read-only page: identity, NPI, the included credentials with status and dates, documents to download, and the screening summary — never internal notes or other providers.'],
  ]],
  'cross-organization' => ['title' => 'Linking with other organizations', 'summary' => 'When another organization on FlxCoreHC also manages one of your providers, records can be linked so credentials are shared — only with everyone\'s consent.', 'blocks' => [
    ['p', 'Each organization keeps and edits only its own record; nothing is ever merged and nobody can edit another organization\'s data. Linking lets one organization see specific credentials (with documents) from another — live, read-only, one credential at a time.'],
    ['h', 'Three consents before anything links'], ['steps', ['On the provider\'s page a Master Admin sees "also managed by another organization" (only a count — no names) and clicks Request to link records.',
               'The provider consents in their portal (Linked organizations). They see both organization names; only the real provider can approve.',
               'The other organization\'s Master Admin approves under Organization → Linked providers.',
               'Only then can the owning organization share credentials with you, one by one, from the provider\'s page.']],
    ['h', 'What you see'], ['p', 'Shared credentials appear under Shared with you on the provider\'s page, tagged with the owning organization, read-only and live — when they renew, you see the new expiry with no re-entry. Your own private credentials stay alongside.'],
    ['h', 'Revoking'], ['p', 'The owner can withdraw any share; either Master Admin can revoke the link; the provider can withdraw all consent. Each takes effect immediately. Every request, consent, share, view and download is logged in both organizations.'],
  ]],
  'activity-audit' => ['title' => 'Activity, audit log & digests', 'summary' => 'See what is happening every day, drill into who did what, and get it by email.', 'blocks' => [
    ['h', 'Activity dashboard'], ['p', 'Organization → Activity: today / 7 days / 30 days at a glance — total actions, people active, records touched, a "what happened" breakdown (providers added, credentials updated, documents verified, …), the most active people, and a 30-day trend. Each card links into the filtered audit log.'],
    ['h', 'Master audit log'], ['p', 'Organization → Audit: every action in your organization, filterable by date range, person, record type, action and free text, paginated over the full history and exportable to CSV. Provider, credential and document pages also show their own history in place.'],
    ['h', 'Compliance timeline'], ['p', 'On each provider\'s page, a permanent, year-by-year record of everything that happened to their credentials and documents — added, renewed (with before → after), expired, verified, replaced, shared — with who and when. Export full history gives the complete CSV.'],
    ['h', 'Email digest'], ['p', 'Profile → Activity digest email: a daily or weekly (Monday) summary of your organization\'s activity, sent only on days with activity. Owners, managers and admins can opt in.'],
  ]],
  'verification' => ['title' => 'Verification records', 'summary' => 'Record the verification checks your organization performs — with a public-registry lookup where an API exists, and a note of the official page where it does not.', 'blocks' => [
    ['h', 'What this is (and is not)'], ['p', 'FlxCoreHC records your organization\'s verification activity and evidence. It does not independently perform or certify primary-source verification, and a recorded status is not a credentialing decision.'],
    ['h', 'Registry lookups (an API exists)'], ['p', 'On a provider page → Verification records: Verify NPI (NPPES) re-pulls the live CMS NPI record and compares the name, taxonomy and state to what is on file, flagging any mismatch. Check SAM.gov runs a real-time federal exclusion check (needs a free SAM.gov API key, set once in Console → System). A SAM match is also recorded as an exclusion screening, so it hard-stops the compliance level.'],
    ['h', 'Recorded from the official page (no API)'], ['p', 'ORCPS Good Standing, OCS/SICRO, Medicaid PEP and Medicare PECOS have no public API. Use "Record a verification done at the official page": it deep-links you to the official site, you confirm the status yourself, and record the result with a screenshot as evidence — a named person, the source checked and the date are kept with the record. Whether a payer or auditor accepts it is their decision.'],
    ['h', 'Re-verification'], ['p', 'Every verification can carry a re-verify date; when it passes, the record is marked due so checks recur on a cadence.'],
  ]],
  'payers-enrollment' => ['title' => 'Payers, Medicaid/Medicare & compliance levels', 'summary' => 'Track where each provider stands with every payer and government program, and see a status level — Licensed → Billable → All tracked requirements current — with hard stops.', 'blocks' => [
    ['h', 'Compliance level'], ['p', 'On each provider page: 1 Licensed, 2 Credentialed (template items on file), 3 Payer-credentialed, 4 Billable (NPI + Medicaid/Medicare or an effective payer contract), 5 All tracked requirements current (OCS attested, nothing lapsing — a status of what FlxCoreHC tracks, not a legal compliance determination). Hard stops: an expired or missing PR license, or an unresolved exclusion flag, sets level 0 regardless of anything else. The panel says exactly what is needed for the next level.'],
    ['h', 'Payer credentialing'], ['p', 'Provider page → Payers & enrollment: one row per payer (Triple-S, MCS, First Medical, Menonita, MMM, MAPFRE, Humana, Plan Vital, Medicare are pre-loaded; add others under Organization → Payers) with status (application pending, under review, credentialed, participating, denied, …), effective and recredentialing dates, and the payer\'s provider ID. Recredentialing dates appear on the calendar.'],
    ['h', 'Medicaid (PEP) and Medicare (PECOS)'], ['p', 'Enrollment status, ID (Medicaid provider ID / PTAN), effective and revalidation dates. Medicaid revalidates every 5 years (medical) or 3 years (non-medical enrolled since 2023); Medicare every 5 years. For Medicaid, confirm all service locations are registered in PEP — since May 15, 2024 claims are denied otherwise.'],
    ['h', 'OCS / SICRO'], ['p', 'The centralized credentialing portal of the Office of the Commissioner of Insurance (Ley 73-2023) — registration is compulsory. Track status, profile ID, last attestation and expiry, delegate/CVO, and the notarized affidavit for institutions (Phase II). Attestation expiry appears on the calendar.'],
    ['h', 'Telehealth'], ['p', 'A simple flag. Under Ley 8-2025 (April 2025) a current PR license is sufficient; the separate board certification is no longer required.'],
    ['h', 'Regulations'], ['p', 'Organization → Regulations lists the law or circular letter behind each requirement, with effective dates and last review. The platform team maintains it under Console → Regulatory.'],
  ]],
  'console' => ['title' => 'Platform console (operators)', 'summary' => 'For the SaaS team running FlxCoreHC.', 'blocks' => [
    ['p', 'Console → Tenants creates client organizations (billing company, medical group, hospital, solo practice) and invites their Master Admin; each starts with the full template, regulator and calendar-type library. Users lists everyone by organization and can add a user to any tenant as Viewer. Roles manages global roles (built-ins are locked). System runs database updates after an upgrade. Email, Translations and Errors complete the console.'],
    ['tip', 'After uploading a new version, always run Console → System → Update database.'],
  ]],
];
