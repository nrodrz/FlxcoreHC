# FlxCoreHC — what is next, and a review of how clients enter their providers

Written after v1.175.1. Findings in section 2 come from reading the code; none was run against a live installation yet. Section 2.4 lists the checks to run once the clean installation is up.

## 1. Plan: what is next

| # | Release | What | Why now | Needs from you |
|---|---|---|---|---|
| 1 | **1.175.2 – Provider intake fixes** | The changes in section 2 (CSV import first) | Every new client starts here; it is the first thing they judge the product on | Decisions in section 3 |
| 2 | **1.176.0 – Test findings** | Whatever you find while testing the clean installation | You are about to start; fix before building more | Your notes or screenshots |
| 3 | **1.177.0 – Roles and permissions** | Permission validator, role matrix (adoption plan step 17) | Wrong permissions are the worst bug class in a multi-tenant app | None |
| 4 | **1.178.0 – Data dictionary and deleted items** | Steps 15 and 16 | Needed for audits and for the "restore a deleted provider" promise | None |
| 5 | **1.179.0 – Billing and overdue rule** | Step 14: billing review, read-only when overdue | Needed before the first paying client | Billing approach; overdue days (default 30) |
| 6 | **1.180.0 – Spanish pass 2** | Training articles, the Terms minor-change notice | Most early clients are in Puerto Rico | Review of Spanish text by a native reader |
| 7 | **Later** | `requirement_template_items` merge (needs template seeding rework); rebrand script (step 18); hosted-only items (step 19) | Not blocking | — |

Outside the code, and yours to start: the real Terms of Use text, an attorney review of LICENSE.txt and the Spanish courtesy texts, and a subscription agreement and data processing agreement. These gate the first paying client.

## 2. Provider intake review

There are four ways a client gets providers in: the quick form (`/holders/new`), the guided wizard (`/holders/onboard`), CSV import (`/holders/import`), and a provider adding their own details through the portal invite. Credentials have a separate CSV import (`/credentials/import`).

### 2.1 What works well
- CSV import shows a preview before anything is created, validates NPI check digits, e-mail and profession, and skips duplicates inside the file.
- New groups and locations named in the CSV are created on the fly, and the profession's requirement checklist is applied automatically.
- Both the quick form and the wizard apply the checklist, audit the creation, and keep typed values when a form is refused.
- The credential import matches providers by NPI or name and derives expiry dates from the renewal cycle.

### 2.2 Gaps found (most important first)

| # | Where | Finding | Suggested change |
|---|---|---|---|
| A | CSV import | **Re-uploading the same file creates duplicates** for anyone without an NPI. Only the NPI is checked against existing records; the name check is inside the file only. | Check existing providers by NPI, then by e-mail, then by exact name; show "already exists" in the preview |
| B | CSV import | **No e-mail rule and no portal invite.** The form requires an e-mail for every individual because it becomes their portal login. The import accepts blank e-mails and sends no invites, so imported providers cannot sign in and the two methods behave differently. | Same e-mail rule as the form; after import, one button "Send portal invites to the N providers imported" |
| C | CSV import | **No row limit** on providers (credentials import caps at 2,000). A large file runs in one request and can time out halfway. | Cap at 2,000 rows with a clear message, as credentials already does |
| D | CSV import | **Not atomic.** Rows are created one by one; a failure or the plan limit part-way leaves a partial import. The limit is only found at commit time. | Check the plan limit in the preview ("12 of 40 would exceed your plan"); wrap the commit in a transaction |
| E | CSV parsing | **Excel in Spanish-language setups saves CSV with semicolons and often Windows-1252 encoding.** The parser only accepts commas and does not check the encoding, so accented names (Rodríguez, Peña) may be corrupted. Quoted cells containing a line break are also split. | Detect `;` and tab; detect and convert Windows-1252 to UTF-8; parse the whole file with a real CSV reader. Accept `.xlsx` later |
| F | CSV import | Preview does not show what will happen to **group and location** (new or existing), and a typo creates a duplicate group. | Show "new group: Grupo Medico B" in the preview, with a count of groups and locations that will be created |
| G | CSV import | **No way to look up an NPI during import.** The form has a "Look up" button (NPPES) that fills in the name; the import just trusts the file. | In the preview, flag rows where the NPI is real but the name differs from the registry (warning, not an error) |
| H | Wizard and form | Failures applying the checklist are swallowed silently (`catch (\Throwable) {}`), so a provider can be created with no requirements and nobody is told. | Show a warning "Provider added, but the checklist could not be applied. Apply it from the provider page" |
| I | All methods | No **summary after import** of what was created beyond a one-line message; no downloadable list of the rows that were skipped or had errors. | Result page with counts and a "download skipped/error rows" CSV |
| J | All methods | The CSV import has no **custom fields** and no **phone, state license, taxonomy** columns, so clients still have to open each provider afterwards. | Add the columns clients ask for first (phone, license number and state); keep the template short |
| K | Onboarding | The "get started" checklist should lead clients through the right order: providers, then credentials, then invites. Today they are separate pages. | One guided "Bring in your providers" page that shows the four methods, which to use for how many providers, and the next step after each |

### 2.3 Recommended order
1. A, B, C, D, H (data integrity and consistency) — one small release.
2. E, F, I (the experience for Spanish-language Excel users and large files).
3. G, J, K afterwards.

### 2.4 Checks to run on the clean installation
Please run these, or I will once you confirm the installation is up. They turn the code-read findings above into confirmed results.
1. Import the template file twice; confirm whether the second run creates duplicates for rows without NPI.
2. Import a row with no e-mail; confirm the provider is created and cannot sign in.
3. Save a spreadsheet from Excel as "CSV (semicolon-delimited)" and as "CSV (comma-delimited)" with the name `Rodríguez`; import it and check the stored name.
4. Import a file with 3,000 rows.
5. Import more providers than the trial plan allows; check what happens halfway.
6. Add one provider through the form and one through the wizard; compare the checklist each gets.

## 3. Decisions needed from you
1. Should an imported individual **require an e-mail** (same as the form)? Recommended: yes.
2. Should invites be sent **automatically** at import, or only when you press a button? Recommended: button, so a test import never e-mails real people.
3. Is a 2,000-row cap per file acceptable? Recommended: yes.
4. Should I accept `.xlsx` files directly now, or keep CSV only for this release? Recommended: CSV only now; `.xlsx` next.
