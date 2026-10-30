# Intune & Defender for Business setup guide

**Purpose:** turn on the settings that actually protect company Windows PCs. ITOps's "Seed recommended baseline" only creates *compliance checks* (pass/fail reporting). The items below are the *configuration* that makes a PC pass those checks — and stops threats.

**From ITOps BT15 on, most of this is one button:** IT Operations → Microsoft 365 → Security → Device policies → **Seed device security** creates §3–§8 (antivirus, firewall, attack-surface rules in Audit, BitLocker, update ring, LAPS) for the device group you pick, and the same tab checks §0 for you (BT21+ menu; before BT21 this was Microsoft 365 → Devices). What stays manual is marked **MANUAL** below. The rest of this guide is the reference for what those profiles contain and how to verify them.

| § | Item | In ITOps BT15 |
|---|---|---|
| 0 | Stop personal PCs enrolling | **MANUAL** — ITOps checks and warns (Device policies tab) |
| 1 | Device groups | **MANUAL** (Entra) |
| 2 | Defender for Business onboarding | **MANUAL** (portal connector) — ITOps shows CONNECTED / NOT CONNECTED |
| 3–4 | Antivirus, firewall | Seed device security |
| 5 | Attack surface reduction | Seed device security (Audit) → "Switch to Block…" later |
| 6 | BitLocker | Seed device security |
| 7 | Update ring | Seed device security (feature-update pin stays **MANUAL**) |
| 8 | Windows LAPS | Seed device security + **MANUAL** Entra switch |
| 9 | Security baseline | **MANUAL**, optional |
| 10 | Defender risk in compliance | ITOps compliance editor → "Defender machine risk" |

**Scope:** company-owned Windows PCs joined to Entra / enrolled in Intune (tier A). Personal computers and phones are never touched (personal-device policy: voluntary).

**Licence:** everything here is included in Microsoft 365 Business Premium (Intune Plan 1 + Defender for Business). No add-ons.

**Ground rules**
- Do everything **pilot group first** (IT + 3–5 volunteers), wait one week, then move to all company PCs.
- Anything with a Block mode starts in **Audit**.
- Never assign these policies to "All users" — always to a **device group** of company PCs. Personal PCs must not be pulled in.
- Before you start: **Intune → Devices → All policies → Back up all** (or export from each policy) so you can roll back.

**Time:** about 3 hours spread over 3 weeks (most of it is waiting for pilot results).

---

## 0. Stop personal PCs from enrolling (do this FIRST)

A personal laptop reached Intune because Windows enrols any PC where someone adds a work account while automatic enrolment is open to all users. This contradicts the personal-device policy. Fix both settings:

| # | Where | Set | Why |
|---|---|---|---|
| 0.1 | **Intune admin center → Devices → Enrollment → Windows → Device platform restrictions** → *Default* (or create one) → Properties → Platform settings | Windows (MDM): **Personally owned = Block** | A PC that is not Entra-joined by IT is treated as personal and refused |
| 0.2 | **Entra admin center → Devices → Overview → Device settings** *(older path: Mobility (MDM and MAM) → Microsoft Intune)* | **MDM user scope = Some** → select the group **`Company – Company PC users`** (or the pilot group for now) | Only accounts in the group can auto-enrol; everyone else just gets Outlook/Teams with no enrolment |
| 0.3 | Same page | **MAM user scope = All** | App protection for personal phones keeps working without enrolment |

Check: on a personal Windows PC, Settings → Accounts → Access work or school → Connect with a work account. It should sign in for apps only, and **no** "Set up my device for work" step should appear.

## 1. Groups (5 minutes)

Create two groups in **Entra admin center → Groups**:

| Group | Type | Members | Used for |
|---|---|---|---|
| `Company – Company PCs (pilot)` | Security, assigned | The 3–5 pilot PCs (device objects) | Every policy below, week 1 |
| `Company – Company PCs` | Security, **dynamic device** | Rule: `(device.deviceOwnership -eq "Company") and (device.deviceOSType -eq "Windows")` | Every policy below, week 2+ |

From BT34 the pilot group can be created in ITOps instead: **Microsoft 365 → Entra ID → Groups → "+ New group"** (a cloud security group with assigned membership and at least one owner; needs the optional Graph permission `Group.ReadWrite.All`). The dynamic group still has to be created in the Entra admin center, because ITOps does not write membership rules.

Set ownership on each company PC: Intune → Devices → Windows → device → Properties → **Ownership = Corporate**. (Autopilot-registered devices are Corporate automatically.)

## 2. Defender for Business onboarding (10 minutes)

Without this, nothing reports to Defender and ITOps's Defender card stays empty.

1. **security.microsoft.com → Settings → Endpoints → Onboarding** → choose *Windows 10 and 11* → Deployment method **Microsoft Intune**. (First visit provisions the tenant — allow up to 30 minutes.)
2. **Intune → Endpoint security → Setup → Microsoft Defender for Endpoint** → set **Connect Windows devices to Microsoft Defender for Endpoint = On**. Save.
3. **Intune → Endpoint security → Endpoint detection and response → Create policy** → Windows → *Endpoint detection and response*:
   - Name: `Company – Defender onboarding`
   - Microsoft Defender for Endpoint client configuration package type: **Auto from connector**
   - Sample sharing: **All**
   - Assign → pilot group.
4. **Settings → Endpoints → Advanced features**: turn **On** — *Tamper protection*, *Automated investigation*, *Live response*, *Allow or block file*. Set **Automation level = Full – remediate threats automatically** (Settings → Endpoints → Device groups → default group).

Check (next day): security.microsoft.com → Assets → Devices lists the pilot PCs with status *Onboarded*.

## 3. Antivirus policy (15 minutes)

**Intune → Endpoint security → Antivirus → Create policy** → Windows → *Microsoft Defender Antivirus*. Name: `Company – Antivirus`.

| Setting | Value |
|---|---|
| Allow Realtime Monitoring | Allowed |
| Allow Cloud Protection | Allowed |
| Cloud Block Level | High |
| Cloud Extended Timeout | 50 seconds |
| Allow Behavior Monitoring | Allowed |
| Allow Email Scanning / Allow Scanning Network Files / Allow Archive Scanning | Allowed |
| Allow Full Scan Removable Drive Scanning | Allowed |
| Real Time Scan Direction | Monitor all files |
| PUA Protection | **Block** (potentially unwanted apps — toolbars, bundlers) |
| Enable Network Protection | **Audit** now → *Enabled* after pilot |
| Submit Samples Consent | Send safe samples automatically |
| Schedule Scan Day / Time | Every day, 12:00 (quick scan) |
| Signature Update Interval | 4 hours |
| Check For Signatures Before Running Scan | Enabled |
| Disable Local Admin Merge | Enabled |
| Allow User UI Access | Allowed (people can see the Defender app) |

Second policy — **Windows Security experience**: `Company – Windows Security app`: *Enable Tamper Protection = On*, *Hide Windows Security notifications = Off*. Assign both to the pilot group.

## 4. Firewall policy (5 minutes)

**Endpoint security → Firewall → Create policy** → Windows → *Windows Firewall*. Name: `Company – Firewall`.

For **each** of Domain / Private / Public profile: *Enable Firewall = True*, *Default Inbound Action = Block*, *Default Outbound Action = Allow*, *Allow Local Policy Merge = True* (so apps can still add their own rules). Assign → pilot group.

## 5. Attack surface reduction rules (15 minutes)

**Endpoint security → Attack surface reduction → Create policy** → Windows → *Attack Surface Reduction Rules*. Name: `Company – ASR rules`.

Set **all** of these to **Audit** for the pilot; after 2 clean weeks switch the "Block-ready" ones to **Block**.

> ITOps's seeder (Windows endpoint-protection profile) covers 12 of these: LSASS, email content, Office executable content, Office code injection, Office child processes, JavaScript/VBScript, obfuscated scripts, Win32 API from macros, untrusted USB, ransomware, PSExec/WMI (kept in Audit) and the prevalence rule (Warn), plus network protection. The other six — vulnerable signed drivers, WMI persistence, Adobe Reader, Office communication apps, Safe Mode reboot and copied system tools — are not in that profile type; add them in an Intune **Endpoint security → Attack surface reduction** policy if you want them (optional).

| Rule | Pilot | Later |
|---|---|---|
| Block credential stealing from LSASS | Audit | **Block** |
| Block executable content from email client and webmail | Audit | **Block** |
| Block Office applications from creating executable content | Audit | **Block** |
| Block Office applications from injecting code into other processes | Audit | **Block** |
| Block all Office applications from creating child processes | Audit | **Block** |
| Block JavaScript or VBScript from launching downloaded executable content | Audit | **Block** |
| Block execution of potentially obfuscated scripts | Audit | **Block** |
| Block Win32 API calls from Office macros | Audit | **Block** |
| Block untrusted and unsigned processes that run from USB | Audit | **Block** |
| Block abuse of exploited vulnerable signed drivers | Audit | **Block** |
| Block persistence through WMI event subscription | Audit | **Block** |
| Block Adobe Reader from creating child processes | Audit | **Block** |
| Block Office communication apps from creating child processes | Audit | **Block** |
| Use advanced protection against ransomware | Audit | **Block** |
| Block process creations from PSExec and WMI commands | Audit | Audit (breaks some IT tools) |
| Block executable files unless they meet prevalence/age/trusted list | Audit | **Warn** (users can override once) |
| Block rebooting machine in Safe Mode | Audit | **Block** |
| Block use of copied or impersonated system tools | Audit | **Block** |

Where to see what would have been blocked: security.microsoft.com → **Reports → Attack surface reduction rules** → *Detections* tab. Anything legitimate that shows up (a line-of-business app, vTiger sync tool, etc.) goes in the policy's **ASR Only Per Rule Exclusions** before switching to Block.

## 6. BitLocker — turn encryption on (10 minutes)

**Endpoint security → Disk encryption → Create policy** → Windows → *BitLocker*. Name: `Company – BitLocker`.

| Setting | Value |
|---|---|
| Require Device Encryption | Enabled |
| Allow Warning For Other Disk Encryption | **Disabled** (this is what makes encryption silent — no user prompt) |
| Allow Standard User Encryption | Enabled |
| Configure Recovery Password Rotation | Refresh on for Azure AD-joined devices |
| Encryption method (OS / fixed / removable) | XTS-AES 256 |
| Require additional authentication at startup | Enabled → TPM only (no PIN — people do not have to type anything extra) |
| **OS drive recovery** | Allow recovery password = Required; **Save BitLocker recovery information to Azure AD = Enabled**; **Do not enable BitLocker until recovery information is stored to AD DS = Enabled** |
| Fixed drive recovery | Same as OS drive |
| Removable drives: Deny write access to removable drives not protected by BitLocker | Disabled for now (turn on later if you want USB sticks encrypted) |

Requirements for silent encryption: TPM 2.0, UEFI, Secure Boot on, Windows Pro. Home PCs (tier B) cannot do this — upgrade to Pro or treat them as personal.

Check: Intune → Devices → Monitor → **Encryption report**. Recovery keys: Entra → Devices → device → **BitLocker keys**.

## 7. Windows Update ring (5 minutes)

**Devices → Windows → Update rings for Windows 10 and later → Create**. Name: `Company – Update ring`.

| Setting | Value |
|---|---|
| Microsoft product updates / Windows drivers | Allow |
| Quality update deferral | 3 days |
| Feature update deferral | 60 days |
| Upgrade Windows 10 devices to latest Windows 11 | Yes (only where hardware allows) |
| Automatic update behavior | Auto install and restart at maintenance time |
| Active hours | 7:00–19:00 |
| Deadline for quality updates | 7 days · Grace period 2 days · Auto reboot before deadline = Yes |
| Deadline for feature updates | 14 days |

Also: **Devices → Windows → Feature updates** → create one pinned to *Windows 11, version 24H2* (or current) so every PC lands on the same version.

## 8. Windows LAPS — local admin passwords (5 minutes)

Every PC has a built-in Administrator account; without LAPS they share whatever password was set at imaging.

**Entra → Devices → Device settings → Enable Microsoft Entra Local Administrator Password Solution (LAPS) = Yes.**
Then **Intune → Endpoint security → Account protection → Create policy** → Windows → *Local admin password solution (Windows LAPS)*. Name: `Company – LAPS`.

Backup Directory = **Azure AD only** · Password Age Days = 30 · Administrator Account Name = *(leave blank = built-in Administrator)* · Password Complexity = Large + small letters + numbers + special · Password Length = 20 · Post Authentication Actions = Reset password and log off · Post Authentication Reset Delay = 4 hours.

Retrieve a password: Intune → Devices → device → **Local admin password** → *Show*. Log the retrieval in ITOps Credentials when you use one.

## 9. Baseline hardening (Windows security baseline) — optional, week 3

**Endpoint security → Security baselines → Security Baseline for Windows 10 and later → Create profile**. Accept the defaults, then change only:

- *Interactive logon: Machine inactivity limit* → 900 seconds (15 min lock)
- *Allow Windows Ink Workspace / Cortana* → leave as baseline
- *Microsoft Defender → PUA* → already set in §3 (baseline agrees)
- *Local Policies Security Options → Accounts: Rename administrator account* → skip (LAPS handles it)

Baselines are strict; if the pilot shows breakage (printers, older apps), fix or remove the single setting rather than dropping the baseline.

## 10. Update the ITOps compliance checks (2 minutes)

After the above, the seeded compliance policy *ITOps baseline — Windows* stays valid (password, BitLocker, Defender, OS minimum). Two tweaks in **ITOps → IT Operations → Microsoft 365 → Security → Device policies → policy pencil** once everything is enforced:

- *Minimum OS version* → `10.0.22631` (Windows 11 23H2) or whatever your Feature update pins.
- *Grace* → switch from "never block" to **Block after 7 days** — this is rollout Phase 5, not before.

Also add in the same policy (Intune side, since ITOps's editor does not expose it yet): **Microsoft Defender for Endpoint → Require the device to be at or under the machine risk score = Medium**. That is what ties Defender alerts to Conditional Access: a PC with an active high-severity alert becomes non-compliant and loses access until the alert is resolved.

## 11. Timeline

| Week | Do | Check before moving on |
|---|---|---|
| 1 | §0 enrolment restrictions · §1 groups · §2 Defender onboarding · §3 AV · §4 firewall · §5 ASR (Audit) · §6 BitLocker · §7 updates · §8 LAPS — **pilot group** | Pilot PCs: Defender *Onboarded*, Encryption report *Encrypted*, no user complaints |
| 2 | Same policies → `Company – Company PCs` group (in waves of 10–20, matching rollout Phase 4) | Intune → Devices → Monitor → *Assignment failures* empty; ASR report reviewed |
| 3 | §9 baseline (pilot) · switch Network Protection to Enabled · switch ASR "Block-ready" rules to Block · §10 risk score | ASR detections reviewed and exclusions added; ITOps Security → Devices shows company PCs compliant |
| 4+ | §10 grace to "Block after 7 days" (rollout Phase 5) · monthly review adds: ASR detections, encryption report, Defender vulnerabilities (Secure Score) | — |

## 12. Roll-back

Every policy is independent: **Intune → policy → Assignments → remove the group**. PCs revert on the next check-in (within 8 hours; force it with ITOps → Microsoft 365 → Security → Devices → Sync). BitLocker stays encrypted after removal (keys remain in Entra). Defender onboarding: **Settings → Endpoints → Offboarding** package via Intune, only if you really mean to leave Defender.

## 13. What ITOps automates (BT15)

IT Operations → Microsoft 365 → Security → Device policies (and Devices for Sync / Lock):
- **Seed device security** → creates and assigns §3–§8 to one device group; re-run with another group to add it (nothing is duplicated).
- **Device security card** → per item: assigned groups and applied / error / conflict / pending counts; Defender connector state; **Switch to Block…** / **Back to Audit** for attack-surface rules.
- **Enrolment guard-rail card** → warns when §0 is not in place and lists personal Windows PCs already enrolled (never acts on them).
- **Compliance editor** → Defender machine risk (§10).

The Rollout tab (step 4, parts A–D) walks through this in order.

## 14. Seeing personal computers without enrolling them (BT27)

Personal computers stay out of Intune (§0), so Intune and Defender cannot report on them. To still know which ones touch company data, use **IT Operations → Microsoft 365 → Entra ID → Device inventory**:

- It lists every device that signs in with a work account — company, personal (registered) and unregistered — from the Entra device list and the Entra sign-in log. Nothing is installed on the computer, and reading the list changes nothing.
- Per device: person, operating system, company or personal, managed by Intune or not, last sign-in and the city it came from. Flags: not managed, operating system out of support, not seen for 90 days, owner account disabled.
- "By person" shows who works from a personal or unregistered computer.
- It cannot show disk encryption, firewall, antivirus or pending updates for a computer Intune does not manage. Those facts need the desktop Companion planned in the device visibility roadmap.

Needs: `Device.Read.All` (Entra device list) and `AuditLog.Read.All` (sign-in log; also Entra ID P1, included in Business Premium), both Application permissions with admin consent.

### Acting on a registered device (BT31)

Click a device that is registered in Entra. "Actions in Entra" offers, depending on the device:

- **Disable / Enable** (Windows only; not for hybrid computers). Use it for a computer that is lost, or before removing a stale one.
- **Remove from Entra…** — only after the device is disabled (other systems: not seen for 90 days, or owner disabled), never while Intune manages it or Autopilot holds it. Removing also deletes the recovery keys Entra holds for it.
- **Recovery key & admin password** — the BitLocker recovery key and the Windows LAPS local administrator password of a company computer, each view with a reason and an Audit Log entry. This is how the help desk unlocks a computer that asks for its BitLocker key.

Roles: "Microsoft 365 · Entra devices" and "Microsoft 365 · Recovery keys" (given to nobody automatically). Azure: `Device.ReadWrite.All`, `BitlockerKey.Read.All`, `DeviceLocalCredential.Read.All` — add only what you will use. If disabling is refused, give the app's enterprise application the Entra role "Cloud Device Administrator".

### Cleaning up stale devices in bulk (BT34)

**Device inventory → "Clean up stale devices…"** does the same for many devices, in the two steps Microsoft advises:

1. **Disable** — Windows devices that are enabled, not managed by Intune and not seen for 90 days. All are ticked; untick the ones to keep. Reversible from the device's row.
2. **Remove** (about 30 days later) — Windows devices that are disabled and not seen for 90 days, and phones, tablets and Macs not seen for 90 days or whose owner's account is disabled. A Windows device is ticked only once it has been disabled from ITOps for 30 days. Type `REMOVE <count>` to confirm. Computers joined to the organization are marked "Recovery keys go with it": look the key up first if the disk may still be needed.

Each device is read from Microsoft again just before it is changed; one that was used since the last sync, is managed by Intune, belongs to Autopilot or is hybrid-joined is skipped and the reason is shown. Same role and Graph permission as the single-device actions.

To put a pilot computer in the `Company – Company PCs (pilot)` group (§1), use **Entra ID → Groups → the group → "+ Add device"**. The main `Company – Company PCs` group is dynamic and fills itself.

## 15. Threats, vulnerabilities and antivirus state in ITOps (BT133)

- **Security → Threats & vulnerabilities** reads Defender advanced hunting (`DeviceTvmSoftwareVulnerabilities`,
  `DeviceTvmSoftwareVulnerabilitiesKB`) through Microsoft Graph `POST /security/runHuntingQuery`. Add the application
  permission **ThreatHunting.Read.All** and grant admin consent. It needs Defender vulnerability management (Defender for
  Endpoint Plan 2, or the vulnerability management add-on) and devices onboarded to Defender (section 2).
- Microsoft's threat write-ups and a vulnerability's details come from `/security/threatIntelligence`: permission
  **ThreatIntelligence.Read.All**. Microsoft's pages differ on the license (included with Defender XDR, or Defender Threat
  Intelligence); if Microsoft refuses, the page says so and the vulnerabilities still show.
- The **Threat analytics** reports of the Defender portal have no API (checked 10/25/2026). ITOps links to them.
- **Devices → Compliance → More** on a Windows device shows its Defender antivirus state from Intune
  (`managedDevices/{id}/windowsProtectionState`, DeviceManagementManagedDevices.Read.All, already needed).

## 16. Personal computers with Defender only, by choice (BT134)

ITOps → Microsoft 365 → Devices → **Personal computers**. For computers the organization does not own: they join
Microsoft Defender for Endpoint (not Intune), and Intune's endpoint security policies reach them through Defender's
**security settings management**.

1. **Defender portal** → Settings → Endpoints → Configuration management → **Enforcement scope**: Windows (and macOS) on;
   All devices, or On tagged devices (tag `MDE-Management`) for a pilot. No API: confirm it on the ITOps page.
2. **Intune** → Endpoint security → Microsoft Defender for Endpoint → **Allow Microsoft Defender for Endpoint to enforce
   Endpoint Security Configurations** = On. No API: confirm it on the ITOps page.
3. ITOps creates the dynamic device group `(device.managementType -eq "MicrosoftSense")` (Group.ReadWrite.All).
4. ITOps sends the **Antivirus** and **Windows Firewall** endpoint security policies, built from the tenant's own
   templates, assigned to that group. Change them in ITOps.
5. Upload the **Local script** onboarding (and offboarding) packages from the Defender portal. People get them on their
   own page after agreeing.

Microsoft supports Windows 10/11 **Pro, Enterprise and Education** and macOS. **Windows Home is not supported** by Defender
for Endpoint: ITOps gives those people a check of the built-in Defender and how to keep it on.
Assignments are to device groups only; assignment filters do not apply to these computers.

