# FlxCoreHC — Demo Accounts

*Generated for v1.158.0. Rebuild the demo any time from **Console → System → Build healthcare demo**;
it is idempotent (it tears down the old demo and recreates this exact dataset). Remove it with
**Remove demo organizations**.*

> These are **demo organizations only** — `is_demo = 1`, no real data. Anyone you give these to can
> explore freely. Do not reuse these passwords for anything real.

## Universal password

Every account below uses **`FlxDemo2026!`** except the five organization owners, which keep their own
memorable passwords (listed per organization).

---

## 1. Coastal Credentialing Partners — billing company *(the richest dataset)*

9 providers across 2 client groups and 2 locations, many professional types, mixed
active/expiring/expired credentials, verified documents, screenings.

| Role | What they can do | Email | Password |
|---|---|---|---|
| **Master Admin** | Everything, incl. billing, roles, cross-org approvals | `demo-admin@coastalcred.example` | `coastal-demo-2026` |
| Manager | All credentialing ops, sharing, audit, export | `manager@coastalcred.example` | `FlxDemo2026!` |
| Staff | Data entry + verify documents + request documents; can export the credentials report (limited to the providers they can see) but not organization-wide data (no `data.export`), no cross-org | `staff@coastalcred.example` | `FlxDemo2026!` |
| Coordinator | Data entry only — cannot verify, export, or share | `coordinator@coastalcred.example` | `FlxDemo2026!` |
| Read-only | View records, documents and reports; change nothing | `readonly@coastalcred.example` | `FlxDemo2026!` |
| **Provider portal** (Dr. Ana Rivera) | Own credentials, document requests, cross-org consent | `ana.rivera@coastalcred.example` | `FlxDemo2026!` |
| **Provider portal** (Dr. Luis Colón) | Own credentials (has expired items) | `luis.colon@coastalcred.example` | `FlxDemo2026!` |

## 2. Dr. Ramirez Family Practice — solo doctor's office

The owner is the office manager (the doctor's spouse); one physician + one nurse.

| Role | Email | Password |
|---|---|---|
| **Master Admin / office manager** | `office@ramirezfp.example` | `ramirez-demo-2026` |
| Front-desk assistant (Regular) | `frontdesk@ramirezfp.example` | `FlxDemo2026!` |

## 3. Bayamon Medical Group — self-managed group practice

6 providers. **Also manages Dr. Ana Rivera**, linked cross-organization with Coastal.

| Role | Email | Password |
|---|---|---|
| **Master Admin** | `admin@bayamonmg.example` | `bayamon-demo-2026` |
| **Provider portal** (Dr. Ana Rivera, this org's record) | `ana.rivera@bayamonmg.example` | `FlxDemo2026!` |

## 4. Isla IPA — multi-location IPA

12 providers (physicians, NPs, nurses, PA, therapist, behavioral, pharmacist) across 3 locations and
2 divisions; some affiliated to more than one site.

| Role | Email | Password |
|---|---|---|
| **Master Admin** | `admin@islaipa.example` | `isla-demo-2026` |

## 5. Anesthesia Associates PR — specialty group

5 anesthesiologists + 2 CRNAs in one department at one site.

| Role | Email | Password |
|---|---|---|
| **Master Admin** | `admin@anesthesiapr.example` | `anes-demo-2026` |

---

## What is pre-loaded to test

| Feature | Where to see it |
|---|---|
| **Document requests** | Coastal → Dr. Ana Rivera → *Document requests*: one **waiting** on the provider, one already **accepted**. Log in as `ana.rivera@coastalcred.example` → **Document requests** to upload against the open one (camera and scan work on a phone). |
| **Multi-document credentials** | Coastal → Dr. Luis Colón → a credential holding a *primary* + *renewal* + *supporting* document. |
| **External sharing** | Coastal → Dr. Ana Rivera → *External shares*: an active link for "Triple-S Salud". Open the link and use password **`DemoShare2026`**. Then check *Access log* to see your own view recorded. |
| **Cross-organization sharing** | Dr. Ana Rivera exists in **both** Coastal and Bayamón, fully consented and **active**, with one credential shared Coastal → Bayamón. See it from Bayamón (*Shared with you*) and from Coastal (*Shared by you* + access log). Provider view: `ana.rivera@bayamonmg.example` → *Linked organizations*. |
| **Notifications** | The bell is populated in Coastal and Bayamón (expiring/expired credentials, assignments). |
| **Activity & audit** | Organization → *Activity* and *Audit* in any org; provider pages show *Compliance timeline*. |
| **Compliance / reports** | Reports → readiness export; provider pages → *Profile packet*. |
| **Spanish** | Switch language in the user menu; the whole interface, flash messages and emails follow. |
| **Platform console** | Sign in as your own platform administrator → Console → *Tenants* (each demo org has an **Open demo (full access)** button — no approval needed), *Security*, *Database*, *Translations*. |

## Sensitive data (DOB / SSN last-4)

Only **Master Admins** see sensitive identity fields by default. To try the field-level rules, sign in
as `demo-admin@coastalcred.example`, open Organization → Roles → *Staff* → **Sensitive fields**, set
DOB to Allow, then sign in as `staff@coastalcred.example` and open Dr. Ana Rivera.

## Testing the roles quickly

Sign in as each Coastal account and try to: verify a document, export organization data, and share a
credential cross-organization.

- **Manager** — all three succeed.
- **Staff** — verify ✔, export ✖, cross-org ✖.
- **Coordinator** — verify ✖, export ✖, cross-org ✖ (upload still works).
- **Read-only** — can view and download only.

## Notes

- Demo organizations are flagged `is_demo`, so a platform admin can enter them directly with full
  access (no support approval) — real tenants still require the consent-gated flow.
- Document files in the demo are tiny placeholder PDFs, not real documents.
- Emails from the demo are queued in the outbox rather than delivered, unless you configure SMTP.
