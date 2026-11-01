# FlxCoreHC — Cross-Organization Sharing: Process Flow & Security Model

*Version 1.141.0 · Training reference. Companion diagram: `docs/cross-tenant-flow.svg` / `.png`.*

---

## 1. The problem this solves

The same real-world provider (e.g. Dr. Ana Rivera) is often credentialed by **several separate
organizations** — a billing company, a medical group, a hospital, an IPA. Each is a separate FlxCoreHC
organization ("tenant") with its own record of her, its own credentials and its own documents.
When her license renews, every organization used to re-enter it and re-upload the document.

Cross-organization sharing lets one organization's up-to-date credential (and its documents) be
**seen live** by another organization — **without ever merging records, without anyone editing
anyone else's data, and without anything being shared until everyone involved has consented.**

## 2. Core principles (the non-negotiables)

| # | Principle | What it means in practice |
|---|-----------|---------------------------|
| 1 | **Every organization owns and edits only its own record.** | There is never a shared or merged record. Org A's record stays Org A's; Org B's stays Org B's. Nobody can write into another organization's data — the code has no path that does so. |
| 2 | **Three-party consent before anything links.** | A link becomes active only when (a) the requesting organization's **Master Admin** asks, (b) the **provider** consents, and (c) the owning organization's **Master Admin** approves. Any one of them can decline. |
| 3 | **The provider is the anchor.** | The provider's consent is what allows their data to cross between organizations. The provider can withdraw consent at any time, which collapses every link on their identity and every share on those links. |
| 4 | **Sharing is per credential and one-directional.** | Linking does not share anything by itself. The owner then chooses, credential by credential, what a linked organization may see. Un-shared credentials stay private. |
| 5 | **Shared = live, read-only, never a copy.** | The recipient sees the owner's real credential row and documents. When the owner renews, the recipient sees the new expiry immediately. The recipient can view and download — never edit or delete. |
| 6 | **Nothing is disclosed before consent.** | Detection tells a requester only that *"another organization"* manages this provider — a count, never a name. Names appear only after the link is fully approved. |
| 7 | **Everything is logged, in both organizations.** | Every request, consent, approval, decline, share, withdrawal, view and download is recorded — in the audit log, in the permanent compliance timeline of **both** organizations, and (for views/downloads) in a per-share access log the owner can inspect. |
| 8 | **Master Admin only for consent.** | Link requests, approvals and revocations are made only by each organization's Master Admin. Regular staff never see or act on them. |

## 3. Identity matching without disclosure — how "same provider" is detected safely

Provider NPIs are stored **encrypted**. To detect that two organizations manage the same provider
*without decrypting anyone's data*, FlxCoreHC stores a **blind index** — a keyed one-way hash of the
NPI. Matching compares blind indexes only:

- The requester's organization learns **how many** other organizations have a record with the same
  blind index — nothing else. No names, no data.
- The provider's own consent is validated against the **same blind index** of *their* record, so only
  the real provider (through their portal login) can approve a link for their identity. A consent
  attempt with a non-matching identity is refused.

## 4. The end-to-end flow

### Step 0 — Detection (automatic, blind)
Org B adds Dr. Rivera (NPI `1841293742`). Org A already manages her.
On Dr. Rivera's page in Org B, a notice appears:

> "A provider with this NPI is also managed by **1** other organization on FlxCoreHC. Who they are is
> not shown until a link is approved."

Org B proceeds normally — it creates and manages its **own** record. Nothing is shared. The app
works fully even if no link is ever made.

### Step 1 — Request (Org B Master Admin)
Only a Master Admin sees **Request to link records**. Clicking it creates one **pending link** per
other organization (status `pending_provider`) and sends the provider a **single email nudge**
("Org B asks to link your credential records — review in your portal"). The email carries no data.
Org B still cannot see who the other organization is.

### Step 2 — Provider consent (provider portal → Linked organizations)
The provider sees: *"Org B asks to link your records with Org A."* They understand both parties.
- **I consent** → status `pending_owner`; Org A's Master Admin(s) get an in-app notification.
- **Decline** → status `declined`; Org B is told the provider declined. Nothing shared.

### Step 3 — Owner approval (Org A Master Admin → Organization → Linked providers)
Org A's Master Admin sees *"Org B asks to link a provider you manage — the provider has already
consented."*
- **Approve link** → status `active`. Org B is notified. **Still nothing is shared.**
- **Decline** → `declined`. Org B is told the other organization declined.

### Step 4 — Share, one credential at a time (Org A, Manager+ with share permission)
On Dr. Rivera's page in Org A, under **Other organizations → Linked with: Org B**, staff pick which
credentials to share and whether to include documents, then **Share (read-only, live)**.
Each share is a separate, revocable grant to one recipient organization.

### Step 5 — Recipient sees it live (Org B)
On Dr. Rivera's page in Org B, a **Shared with you** section lists the shared credentials, tagged
*"from Org A"*, with expiry, issuer, verification date and document downloads. It is read-only and
live: when Org A renews the license, Org B sees the new expiry with no re-entry. Org B keeps its own
private credentials alongside.

### Step 6 — Oversight
- Org A: **Shared by you** lists each share with its recipient, status and access count, an
  **Access log** (who in Org B viewed/downloaded, when, from which IP) and **Withdraw**.
- Both Master Admins: **Organization → Linked providers** lists every link and can **Revoke**.
- Provider: **Linked organizations** in the portal lists every link and offers **Withdraw all
  consent**.

### Step 7 — Revocation (anyone, any time)
- **Owner withdraws a share** → that credential disappears from the recipient immediately.
- **Either Master Admin revokes the link** → all shares on that link are withdrawn immediately.
- **Provider withdraws consent** → every link on their identity and every share on those links is
  revoked immediately. The provider can be asked again later, but nothing re-links without a fresh
  three-party consent.

Revocation never deletes history: what was shared, viewed and downloaded remains in the logs of both
organizations.

## 5. Groups of three or more organizations

Links are pairs anchored on the provider: with Orgs A, B and C, there are up to three links
(A↔B, A↔C, B↔C), each needing its own three-party consent. This is deliberate:
- Each organization controls **exactly which other organizations** it links with.
- Shares travel only along consented pairs. A share from A to B is never visible to C.
- One organization revoking its links does not affect links between the others.
- The provider withdrawing consent collapses all of them.

## 6. Security measures — the complete list

**Data isolation**
- A single service class (`CrossTenantService`) is the only code permitted to read across
  organizations; the isolation checker enforces that every other query is tenant-scoped.
- Every recipient read (`readShared`, `sharedDocument`) re-verifies on **every call**: the share is
  not revoked, the link is `active`, and the caller is the named recipient organization.
- No code path writes to another organization's rows. Sharing inserts a share row in the sharing
  organization's ownership; the recipient's tables are untouched (verified: 0 credential rows created
  in the recipient).
- Documents are streamed through the application after verification — never exposed as direct file
  URLs.

**Consent & authorization**
- Request / approve / decline / revoke: **Master Admin only** (`tenant_owner`); enforced server-side.
- Share / withdraw: requires the `share.manage` capability (Manager and above).
- Provider consent: validated against the NPI blind index of the provider's own record; identity
  mismatch is refused.
- Recipients' own **need-to-know** rules still apply: a restricted user in the recipient organization
  sees shared credentials only for providers assigned to them.

**Disclosure minimization**
- Detection returns a count only. The requester sees "Another organization" until the link is active.
- Nothing is shared by linking alone; sharing is an explicit per-credential act.
- The provider's nudge email and all notifications contain no credential data — only a link into the
  app.

**Auditability (both organizations)**
- `audit_logs`: cross_link_requested / approved / declined / revoked, cross_share_created / revoked,
  provider consent/decline/withdraw — with user, IP, timestamp.
- `compliance_events` (permanent, never deleted): a plain-language entry in **each** organization's
  provider timeline for every step, and for the recipient when a credential is received.
- `cross_share_access`: every view and download by the recipient, with organization, user, IP and
  time — visible to the owner per share.
- The master audit log, activity dashboard and digests include all of the above.

**Platform hygiene**
- All actions are CSRF-protected forms; all output escaped; parameterized SQL throughout; CSP nonces;
  HttpOnly/SameSite cookies; rate-limited authentication.
- Encrypted-at-rest fields (NPI, email) are never decrypted for a foreign organization.

## 7. Where things live (for trainers)

| Actor | Screen | Actions |
|-------|--------|---------|
| Requesting org Master Admin | Provider page → *Other organizations* | Request to link records |
| Provider (portal) | *Linked organizations* | I consent / Decline / Withdraw all consent |
| Owning org Master Admin | Organization → *Linked providers* | Approve / Decline / Revoke |
| Owning org Manager+ | Provider page → *Linked with…* | Share credentials (± documents) / Withdraw / Access log |
| Recipient org staff | Provider page → *Shared with you* | View, download (read-only) |

## 8. Status reference

`pending_provider` → waiting for the provider · `pending_owner` → provider consented, waiting for the
owning Master Admin · `active` → linked (shares possible) · `declined` → refused by provider or
owner · `revoked` → withdrawn by requester, owner or provider (all shares withdrawn).
