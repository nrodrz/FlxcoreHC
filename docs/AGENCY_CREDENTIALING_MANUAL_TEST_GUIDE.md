# Agency Credentialing — Manual Testing Guide (click-by-click)
**Applies to:** FlxCoreHC v1.168.0 · A person following these steps in a browser after the app is
installed and running. (This is the human walkthrough. The automated PHP tests are separate —
see `docs/architecture/AGENCY_CREDENTIALING_TEST_GUIDELINES.md`.)

> Record each step as **PASS** (behaves as the Expected column says) or **FAIL** (anything else — note
> exactly what you saw). A FAIL is still useful information; write down the URL and any on-screen message.

---

## 0. Before you start
1. Install/deploy FlxCoreHC v1.168.0 to a test environment (NOT production).
2. In the platform console, **Console → System → Build healthcare demo** to get demo data
   (idempotent — safe to rebuild any time; removes and recreates the same dataset).
3. Log in as an admin who has the **records.write** capability. Demo login:
   - **Email:** `demo-admin@coastalcred.example`
   - **Password:** `FlxDemo2026!`
4. **Navigation note:** there is not yet a sidebar link for cases — reach the case list by typing the URL
   **`/cases`** (e.g. `https://YOURHOST/cases`). If your install runs in a subfolder, prefix it with the
   base path. (Add a nav link later; for testing, the URL is enough.)

---

## Suite A — Case list & work queue

| # | Step | Expected |
|---|---|---|
| A1 | Go to `/cases` | The **Credentialing cases** page loads with KPI tiles (Unassigned, Overdue requirements, Hard stops, Needs attention, Ready to submit, Submitted, Target ≤14 days) and an "All cases" table. No PHP errors. |
| A2 | Read the KPI tiles | Each shows a number. On a fresh demo they may be 0 — that is fine; you will make them move in later steps. |
| A3 | Click **New case** (top right) | A "New case" form appears (provider id, case type, optional client group / payer, target date, notes). |

---

## Suite B — Create a case (with template population)

| # | Step | Expected |
|---|---|---|
| B1 | You need a provider id. Open a provider first: go to a provider's page, or copy a `holder_id` from the demo. (Tip: from a provider page URL `/holders/{id}`, the id is that `{id}`.) | You have a valid provider id to paste. |
| B2 | On `/cases` → **New case**, paste the provider id, choose **Initial credentialing**, set a **Target date** a week out, click **Open case** | You are redirected to the new case page (`/cases/{id}`). A flash says the case opened. |
| B3 | Look at the **Case requirements** section | It is **already populated** with requirements pulled from the provider's profession template (e.g. state license, DEA, malpractice, CME…). This is the automatic template population. |
| B4 | Look at the **progress summary** near the top | Shows "0 / N required satisfied", a progress bar, and any "needs evidence" / "hard stop(s)" counts. |
| B5 | If B3 showed **no** requirements | The provider likely has no profession set. You should see a **"Populate from profession template"** button — click it and confirm requirements appear (or a clear message if there is no template). |

---

## Suite C — Requirements

| # | Step | Expected |
|---|---|---|
| C1 | In **Case requirements**, open the **+ Add requirement** panel, pick a catalog item, tick **Hard stop**, set a due date, click **Add** | The requirement is added to the list and marked as a hard stop (⛔). |
| C2 | Try adding the **same** catalog item again | It is not duplicated (the list still shows it once) — duplicate prevention. |
| C3 | On any requirement, change its **status** dropdown to **Satisfied** | The row updates to Satisfied; the progress summary count and % increase. |
| C4 | Change a requirement to **Waived** | Treated as resolved (progress increases); it no longer blocks. |

---

## Suite D — Evidence (reuse + integrity)

| # | Step | Expected |
|---|---|---|
| D1 | Under **Evidence**, expand **Link evidence** on a requirement whose code matches a credential the provider already holds | You see the provider's **existing matching credentials** listed as radio options, each marked **current** or **expired — not valid**. |
| D2 | Select a **current** credential and click **Link** | The evidence appears under that requirement as a link. Note: linking does **not** auto-mark the requirement satisfied — you still set status yourself (human decision). |
| D3 | Try to link an **expired** credential | It is shown but flagged "expired — not valid" so you know it is not adequate evidence. |
| D4 | Paste a **random / made-up** id into one of the "other evidence type / by id" boxes and click **Link** | Rejected with a "not found in this tenant" / "different provider" style message. No link is created. |
| D5 | Click **Unlink** on a linked evidence row | The evidence is removed from that requirement. |

---

## Suite E — Case documents

| # | Step | Expected |
|---|---|---|
| E1 | Under **Case documents**, choose a small PDF, set a type (e.g. `cv`), click **Upload** | The document appears in the list with its name and date. |
| E2 | Click the document name | It downloads (attachment). |
| E3 | Try uploading a disallowed file (e.g. a `.exe` or a >10 MB file) | Rejected with a clear message; nothing is stored. |
| E4 | Click **Remove** on the document | It is removed from the list. |

---

## Suite F — Lifecycle & hard stops (the important one)

| # | Step | Expected |
|---|---|---|
| F1 | On the case, find the **Lifecycle** buttons. Click **Move to in progress** | Status changes to In progress; buttons update to the next valid steps. |
| F2 | With at least one **unresolved hard-stop** requirement, click **Move to ready for submission** | **Blocked.** A flash / panel indicates hard stops are unresolved and the status does **not** change. The **Hard stops blocking submission** panel lists them. |
| F3 | Resolve every hard-stop requirement (set them to Satisfied or Waived), then click **Move to ready for submission** again | Now **allowed** — status becomes Ready for submission. |
| F4 | Click **Move to submitted**, then **Move to completed** | Status advances; a completed case is retained (not deleted). |
| F5 | Try to jump an illegal step (e.g. from Open straight to Completed) — you generally won't see the button, but if you craft the request | The transition is rejected by the state machine; status unchanged. |

---

## Suite G — Assignment

| # | Step | Expected |
|---|---|---|
| G1 | Assign the case to a valid user in your org | Assignment saves; the case shows as assigned; it appears under "My cases"/assigned counts in the queue. |
| G2 | Unassign (clear the assignee) | Assignment clears; the case returns to Unassigned. |
| G3 | (If you can craft it) attempt to assign a user id from **another** organization | Rejected — the case is not updated. |

---

## Suite H — Provider integration

| # | Step | Expected |
|---|---|---|
| H1 | Open the provider's page (`/holders/{id}`), go to the **Payers & enrollment** tab | A **Credentialing cases** panel lists that provider's cases, with a **New case** button. |
| H2 | Click **New case** there | The case create form opens with the provider **pre-filled**. |

---

## Suite I — Applications & history

| # | Step | Expected |
|---|---|---|
| I1 | On a case, view the **Applications** section | Lists payer/enrollment submissions linked to the case (may be empty on demo — that is fine). |
| I2 | View the **Case history** section | Shows a timeline of events you generated (case created, requirements populated/changed, status changes, evidence linked/unlinked, documents added/removed) with timestamps and who did it. |

---

## Suite J — Work queue reflects your work

| # | Step | Expected |
|---|---|---|
| J1 | Go back to `/cases` after the steps above | The KPI tiles now reflect your activity (e.g. Hard stops / Needs attention / Ready / Submitted counts changed as you moved the case). |
| J2 | Confirm the "All cases" table shows your case with its current status | Row present, correct status chip, target date. |

---

## Suite K — Tenant isolation (spot check)

| # | Step | Expected |
|---|---|---|
| K1 | Note a case URL `/cases/{id}` from this org. Log out, log in as a user in a **different** demo org, and open that same URL | **Not found** — the other org cannot see this org's case. |
| K2 | (Automated equivalent) the PHP suite proves this and cross-case protections rigorously — see the test guidelines. | — |

---

## What a good result looks like
- Every Expected outcome matches, especially **F2/F3** (hard stops actually block, then allow), **D2/D3**
  (evidence reuse shows validity and never auto-satisfies), **D4** (bad evidence rejected), and **K1**
  (another org can't see the case).
- No PHP warnings/errors on any page.
- The work queue and history reflect exactly what you did.

## If something fails
Write down: the step number, the URL, what you clicked, and the exact on-screen message (or a screenshot).
That is enough to reproduce and fix it. Do not change any settings or data to "make it pass."

## Note on scope (honest)
- There is **no sidebar nav link** to `/cases` yet — you reach it by URL. That is a UI convenience to add,
  not a functional defect.
- Some sections (Applications on a case, verification-to-case links) are **wired but lightly populated** in
  the demo; they will show real rows once you create those associations in a live workflow. The automated
  test guidelines list these as partial-coverage areas.
