<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — Security assessment (BT102), step 1 of the roadmap: a read-only check catalog over the Microsoft 365 tenant.
 *
 * Every check reads, nothing changes the tenant. Each one says why it matters, what was found (with the affected
 * accounts, apps, domains or devices), the license and the Microsoft permission it needs, and how to fix it. A check
 * that cannot be read (permission not granted, license missing) says so and is left out of the score — it never counts
 * as passed.
 *
 * Data is read once per run through a fetcher: itops_sa_fetch_graph() in the app, a fixture in the tests. Results are
 * kept per run (itops_sa_runs, itops_sa_results) so the next steps (findings into work, exceptions, drift, history) build
 * on them.
 *
 * Statuses: pass · warn · fail · na (does not apply here) · unknown (could not be read).
 */
require_once __DIR__ . '/Brand.php';

if (!function_exists('itops_sa_catalog')) {

    if (!defined('ITOPS_SA_GA')) define('ITOPS_SA_GA', '62e90394-69f5-4237-9190-012177145e10');   // Global Administrator (role template id)

    /** The areas, in the order the page shows them. */
    function itops_sa_areas(): array {
        return ['identity' => 'Identity & sign-in', 'admins' => 'Admin accounts', 'tenant' => 'Tenant settings',
                'apps' => 'Applications', 'email' => 'Email', 'devices' => 'Devices', 'collab' => 'Sharing & collaboration', 'posture' => 'Overall posture'];
    }

    /**
     * id => [area, title, severity, license needed, permission needed, why it matters, fix steps, evaluator]
     * Severity weights the score: high 3, medium 2, low 1.
     */
    function itops_sa_catalog(): array {
        $P1 = 'Microsoft Entra ID P1 (included in Microsoft 365 Business Premium, E3 and E5)';
        $NONE = 'None (every Microsoft 365 plan)';
        $INT = 'Microsoft Intune (included in Microsoft 365 Business Premium, E3 and E5)';
        // BT116 (4a): the rule library grows from 24 to 57 rules
        $P2 = 'Microsoft Entra ID P2 (Microsoft 365 E5, or the Defender Suite add-on for Business Premium)';
        $MDB = 'Microsoft Defender for Business or Defender for Endpoint (Business Premium, E3 / E5)';
        $EXO = 'Exchange Online (every plan with email)';
        $SPO = 'SharePoint Online (every plan with SharePoint)';
        $APP = function_exists('itops_app_name') ? itops_app_name() : 'ITOps';   // review OPS-18: the app's own name in the steps
        return [
            // ── Identity & sign-in ──
            'mfa_registered' => ['identity', 'Every person has registered for multifactor sign-in', 'high', $P1, 'AuditLog.Read.All, User.Read.All',
                'An account that only has a password is taken over with one phished or reused password. Registration is the first step: a person who has not registered cannot be asked for a second factor.',
                ['Open Microsoft 365 → Entra ID → MFA in ' . $APP . ' to see who has not registered.', 'Ask each person to register at https://aka.ms/mfasetup (the Microsoft Authenticator app is recommended).', 'Turn on enforcement (see "Multifactor sign-in is required for everyone") so registration is not optional.'],
                'itops_sa_c_mfa_registered'],
            'mfa_required' => ['identity', 'Multifactor sign-in is required for everyone', 'high', $NONE . '; Conditional Access needs ' . $P1, 'Policy.Read.All',
                'Registration alone does not protect an account: sign-in must require the second factor. Either Security defaults or a Conditional Access policy for all users does that.',
                ['Small tenants without Entra ID P1: Entra admin center → Identity → Overview → Properties → Manage security defaults → Enabled.', 'With Entra ID P1: create a Conditional Access policy "Require MFA for all users" (all users, all cloud apps, grant: require multifactor authentication). Exclude only the two emergency accounts.', 'Start it in report-only for a few days, then switch it on.'],
                'itops_sa_c_mfa_required'],
            'legacy_auth' => ['identity', 'Old sign-in methods that skip multifactor are blocked', 'high', $NONE . '; Conditional Access needs ' . $P1, 'Policy.Read.All',
                'Old protocols (POP, IMAP, SMTP basic sign-in, older Office clients) cannot ask for a second factor, so attackers use them to try passwords around multifactor sign-in.',
                ['With Security defaults on, legacy sign-in is already blocked.', 'With Entra ID P1: Conditional Access policy "Block legacy authentication": all users, client apps "Exchange ActiveSync clients" and "Other clients", grant: block access.', 'Check first in the sign-in logs (filter by client app) who still uses them, and move those devices or apps to modern sign-in.'],
                'itops_sa_c_legacy_auth'],
            'admin_mfa_registered' => ['identity', 'Every admin has registered for multifactor sign-in', 'high', $P1, 'AuditLog.Read.All, RoleManagement.Read.Directory',
                'Admin accounts can change everything in the tenant. An admin without multifactor sign-in is the most valuable account an attacker can take over.',
                ['Have each listed admin register now at https://aka.ms/mfasetup, preferably with a passkey, a security key or the Authenticator app.', 'Until they have, consider removing the admin role and adding it back afterwards.'],
                'itops_sa_c_admin_mfa'],
            'admin_mfa_policy' => ['identity', 'Admins must use multifactor sign-in (policy)', 'high', $NONE . '; Conditional Access needs ' . $P1, 'Policy.Read.All',
                'Even where everyone else is covered later, admin roles should be protected first by a policy that always asks them for a second factor.',
                ['With Security defaults on, admins are always asked.', 'With Entra ID P1: Conditional Access policy "Require MFA for admins": include the directory roles Global Administrator, Privileged Role Administrator, Security, Exchange, SharePoint, User, Helpdesk, Conditional Access and Authentication Administrators; all cloud apps; grant: require multifactor authentication (or a phishing-resistant authentication strength).'],
                'itops_sa_c_admin_mfa_policy'],
            'ca_report_only' => ['identity', 'No Conditional Access policy is left in report-only', 'low', $P1, 'Policy.Read.All',
                'A policy in report-only mode only logs what it would do; it protects nothing. Policies are often left there after testing.',
                ['Open each listed policy in the Entra admin center → Protection → Conditional Access.', 'Review its "What if" and sign-in log results, then set "Enable policy" to On — or delete it if it is no longer wanted.'],
                'itops_sa_c_ca_report_only'],
            'weak_methods' => ['identity', 'Text message and voice call are not offered as sign-in methods', 'low', $NONE, 'Policy.Read.All',
                'Codes by text message or voice call can be intercepted (SIM swap, phone-number porting). Microsoft recommends the Authenticator app, passkeys or security keys instead.',
                ['First make sure everyone has another method registered (Entra ID → MFA in ' . $APP . ').', 'Entra admin center → Protection → Authentication methods → Policies → SMS and Voice call → Disable (or limit them to a small group that has no other option).'],
                'itops_sa_c_weak_methods'],
            'stale_users' => ['identity', 'No enabled account has gone unused for 90 days', 'medium', $P1, 'AuditLog.Read.All, User.Read.All',
                'Accounts nobody uses are not watched: a takeover goes unnoticed, and the license is paid for nothing. They often belong to people who left.',
                ['Check each listed account with its manager.', 'Offboard leavers (Microsoft 365 → Accounts → Offboard in ' . $APP . '); block sign-in for accounts kept for their mailbox or files.', 'Service and shared accounts that must stay: block sign-in where they do not need to sign in.'],
                'itops_sa_c_stale_users'],
            'stale_guests' => ['identity', 'Guests who no longer sign in are removed', 'low', $P1, 'AuditLog.Read.All, User.Read.All',
                'Guests keep access to teams, sites and files they were given. A guest who has not signed in for 90 days most likely no longer needs it.',
                ['Review the listed guests with the people who invited them (Entra ID → Guests in ' . $APP . ').', 'Remove those not needed; consider an access review that runs every quarter (Entra ID Governance).'],
                'itops_sa_c_stale_guests'],
            // ── Admin accounts ──
            'ga_count' => ['admins', 'Two to four Global Administrators', 'medium', $NONE, 'RoleManagement.Read.Directory',
                'With one Global Administrator, losing that account locks the organization out. With many, each extra one is another account that can take over the tenant.',
                ['Keep two to four Global Administrators, one of them an emergency ("break-glass") account kept offline.', 'Move people who only manage users, mail or devices to the narrower role they need (User, Exchange, Intune Administrator…).'],
                'itops_sa_c_ga_count'],
            'admin_separate' => ['admins', 'Admins use separate admin accounts', 'medium', $NONE, 'RoleManagement.Read.Directory, User.Read.All',
                'An admin role on the account someone uses every day for mail and browsing exposes the role to every phishing email they receive. A separate admin account without a mailbox is only used to administer.',
                ['Create a separate cloud-only admin account for each listed person (for example admin-name@yourtenant.onmicrosoft.com), without a mailbox license.', 'Give the admin role to that account and remove it from the daily account.'],
                'itops_sa_c_admin_separate'],
            // ── Tenant settings ──
            'user_consent' => ['tenant', 'People cannot give apps access to company data on their own', 'medium', $NONE, 'Policy.Read.All',
                'When anyone can consent, a malicious app ("consent phishing") gets read access to a person\'s mail and files with one click, and it keeps it after a password change.',
                ['Entra admin center → Identity → Applications → Enterprise applications → Consent and permissions → User consent settings → "Do not allow user consent" (or "Allow user consent for apps from verified publishers, for selected permissions").', 'Turn on the admin consent workflow so people can ask for an app instead.'],
                'itops_sa_c_user_consent'],
            'users_create_apps' => ['tenant', 'Only admins register applications', 'medium', $NONE, 'Policy.Read.All',
                'Every app registration can hold secrets and permissions. Registrations made by anyone are hard to track and are a common way to keep access to a tenant.',
                ['Entra admin center → Identity → Users → User settings → "Users can register applications" → No.', 'Give the Application Developer role to the people who really build apps.'],
                'itops_sa_c_users_create_apps'],
            'guest_invites' => ['tenant', 'Only chosen people invite guests', 'low', $NONE, 'Policy.Read.All',
                'If anyone (including guests) can invite guests, outside people can be brought in without anyone deciding it.',
                ['Entra admin center → Identity → External Identities → External collaboration settings → Guest invite settings → "Only users assigned to specific admin roles can invite guest users" (or member users and admins).'],
                'itops_sa_c_guest_invites'],
            'users_create_tenants' => ['tenant', 'People cannot create new Microsoft tenants', 'low', $NONE, 'Policy.Read.All',
                'A person who creates a tenant becomes its Global Administrator, outside the organization\'s control, sometimes with company data.',
                ['Entra admin center → Identity → Users → User settings → "Restrict non-admin users from creating tenants" → Yes.'],
                'itops_sa_c_users_create_tenants'],
            // ── Applications ──
            'app_credentials' => ['apps', 'No app secret or certificate has expired or is about to', 'medium', $NONE, 'Application.Read.All',
                'An expired secret stops the integration that uses it (mail, backups, sign-in to other systems). Expired secrets that are left on the app are clutter that hides the ones in use.',
                ['For each listed app: create a new secret or certificate, update the system that uses it, then delete the old one.', 'Delete credentials that have already expired and are no longer used.'],
                'itops_sa_c_app_credentials'],
            'app_long_secrets' => ['apps', 'App secrets do not last longer than two years', 'low', $NONE, 'Application.Read.All',
                'A long-lived secret that leaks keeps working for years. Shorter lifetimes (12 months or less) limit the damage and keep the rotation habit alive.',
                ['At the next rotation, create the new secret with a lifetime of 12 months or less.', 'Prefer certificates or managed identities where the system supports them.'],
                'itops_sa_c_app_long_secrets'],
            // ── Email ──
            'spf' => ['email', 'SPF is published for every sending domain', 'high', $NONE, 'Domain.Read.All (and public DNS)',
                'SPF tells receiving mail servers which servers may send for the domain. Without it, messages forged with the domain are more likely to be delivered, and real mail is more likely to land in spam.',
                ['Publish a TXT record at the domain: v=spf1 include:spf.protection.outlook.com -all (add the other services that send for you before -all).'],
                'itops_sa_c_spf'],
            'dkim' => ['email', 'DKIM signing is set up for every sending domain', 'medium', $NONE, 'Domain.Read.All (and public DNS)',
                'DKIM signs each message so receivers can tell it really came from the domain and was not changed. DMARC relies on it.',
                ['Defender portal → Email & collaboration → Policies → Email authentication settings → DKIM → the domain: publish the two CNAME records shown, then switch signing on.'],
                'itops_sa_c_dkim'],
            'dmarc' => ['email', 'DMARC is enforced for every sending domain', 'high', $NONE, 'Domain.Read.All (and public DNS)',
                'DMARC tells receivers what to do with mail that fails SPF and DKIM. Only "quarantine" or "reject" stops forged mail; "none" only reports.',
                ['Start with a TXT record at _dmarc.<domain>: v=DMARC1; p=none; rua=mailto:dmarc@<domain>.', 'After two to four weeks of clean reports, change to p=quarantine, then p=reject.'],
                'itops_sa_c_dmarc'],
            // ── Devices ──
            'devices_compliant' => ['devices', 'Every managed device is compliant', 'medium', $INT, 'DeviceManagementManagedDevices.Read.All',
                'A non-compliant device is missing something the organization requires (updates, encryption, a PIN, antivirus). It is the device most likely to be the way in.',
                ['Open Microsoft 365 → Security → Devices in ' . $APP . ' to see what each device is missing.', 'Fix it with the device\'s user, or remove access until it is fixed (a Conditional Access policy that requires a compliant device).'],
                'itops_sa_c_devices_compliant'],
            'devices_encrypted' => ['devices', 'Computers are encrypted', 'high', $INT, 'DeviceManagementManagedDevices.Read.All',
                'A lost or stolen computer that is not encrypted gives away every file on it, including copies of mail and cached passwords.',
                ['Windows: an Intune disk-encryption policy for BitLocker (Microsoft 365 → Security → Device policies in ' . $APP . ').', 'Mac: an Intune FileVault policy.', 'Make encryption part of the compliance policy so the device shows non-compliant until it is done.'],
                'itops_sa_c_devices_encrypted'],
            'devices_checkin' => ['devices', 'Managed devices have checked in within 30 days', 'low', $INT, 'DeviceManagementManagedDevices.Read.All',
                'A device that has not checked in gets no policies or updates. It is lost, retired or broken, and still counted as managed.',
                ['Check each listed device with its user.', 'Retire or wipe devices that are gone; set up Intune device clean-up rules (Devices → Device clean-up rules).'],
                'itops_sa_c_devices_checkin'],
            // ═════ BT116 (4a): new rules ═════
            // ── Identity & sign-in ──
            'break_glass' => ['admins', 'Two emergency access accounts are ready', 'high', $NONE, 'User.Read.All, RoleManagement.Read.Directory, Policy.Read.All',
                'If a Conditional Access policy goes wrong, or the only admins lose their phones, nobody can sign in to fix it. Two cloud-only Global Administrator accounts kept out of every policy are the way back in.',
                ['Create two cloud-only accounts on the onmicrosoft.com domain (for example emergency1@yourtenant.onmicrosoft.com), each with the Global Administrator role and a long random password kept in a safe; register a security key on each.', 'Exclude both from every Conditional Access policy.', 'Mark them in ' . $APP . ': Posture → Tenant profile → Emergency access accounts, so this check and later policies know them.', 'Test a sign-in with each every few months, and alert on any other sign-in.'],
                'itops_sa_c_break_glass'],
            'admin_phish_resistant' => ['identity', 'Admins must sign in with a phishing-resistant method', 'high', $P1, 'Policy.Read.All',
                'Codes and app approvals can be phished through a fake sign-in page that relays them. Passkeys, security keys and Windows Hello cannot, because they only answer the real Microsoft sign-in page.',
                ['Have every admin register a passkey or security key first (a Temporary Access Pass helps).', 'Create a Conditional Access policy: the admin directory roles, all resources, grant "Require authentication strength: Phishing-resistant MFA"; exclude the emergency accounts.', 'Report-only first, then on.'],
                'itops_sa_c_admin_phish_resistant'],
            'device_code_blocked' => ['identity', 'Sign-in with a device code is blocked', 'medium', $NONE . '; Conditional Access needs ' . $P1, 'Policy.Read.All',
                'Device code sign-in ("go to microsoft.com/devicelogin and enter this code") is a favorite phishing trick: the victim signs the attacker in. Few businesses need it.',
                ['With Security defaults on, it is blocked.', 'With Entra ID P1: Conditional Access policy, all users, all resources, conditions → authentication flows → device code flow, grant: block. Exclude the emergency accounts and any meeting-room devices that need it.'],
                'itops_sa_c_device_code_blocked'],
            'risky_signin_policy' => ['identity', 'Risky sign-ins are challenged', 'medium', $P2, 'Policy.Read.All',
                'Microsoft scores each sign-in (impossible travel, password spray, leaked token). A policy that asks for multifactor sign-in on a risky sign-in stops most takeovers in progress.',
                ['With Entra ID P2: turn on the Microsoft-managed policy "Multifactor authentication and reauthentication for risky sign-ins", or create one: all users, sign-in risk high and medium, grant: require multifactor authentication, session: sign-in frequency every time.'],
                'itops_sa_c_risky_signin_policy'],
            'risky_user_policy' => ['identity', 'High-risk users are blocked or must fix it', 'medium', $P2, 'Policy.Read.All',
                'When Microsoft believes an account is compromised (user risk high), it should not keep working as normal until someone looks.',
                ['With Entra ID P2: turn on the Microsoft-managed policy "Require remediation for high-risk users" (or "Block access for high-risk users").'],
                'itops_sa_c_risky_user_policy'],
            'compliant_device_admins' => ['identity', 'Admins sign in only from a compliant device', 'medium', $P1 . ' and Intune', 'Policy.Read.All',
                'An admin signing in from an unmanaged home computer brings any malware on it to the admin session. Requiring a compliant device keeps admin work on devices the organization controls.',
                ['Make sure the admins\' computers are enrolled in Intune and compliant.', 'Conditional Access policy: the admin directory roles, all resources, grant "Require device to be marked as compliant"; exclude the emergency accounts. Report-only first.'],
                'itops_sa_c_compliant_device_admins'],
            'compliant_device_all' => ['identity', 'Everyone signs in only from a compliant or protected device', 'medium', $P1 . ' and Intune', 'Policy.Read.All',
                'Company data then never lands on a device the organization cannot protect or wipe. It is the strongest device control, and the one that needs the most preparation.',
                ['Enroll every company computer and phone in Intune, and use app protection on personal phones first.', 'Conditional Access policy: all users, all resources, grant "Require device to be marked as compliant" or "Require app protection policy"; exclude the emergency accounts. Report-only for two weeks, then on.'],
                'itops_sa_c_compliant_device_all'],
            'passkey_enabled' => ['identity', 'Passkeys and security keys are allowed', 'low', $NONE, 'Policy.Read.All',
                'Passkeys are the phishing-resistant method Microsoft recommends. Admins need them before a phishing-resistant policy can be switched on.',
                ['Entra admin center → Protection → Authentication methods → Policies → Passkey (FIDO2) → Enable, for all users or an admins group first.'],
                'itops_sa_c_passkey_enabled'],
            // ── Tenant settings ──
            'guest_access_restricted' => ['tenant', 'Guests cannot see the whole directory', 'medium', $NONE, 'Policy.Read.All',
                'When guests have the same directory access as members, an outside person can list every employee, group and their membership, which is a ready-made target list.',
                ['Entra admin center → External Identities → External collaboration settings → Guest user access → "Guest users have limited access" or "restricted to their own directory objects".'],
                'itops_sa_c_guest_access_restricted'],
            'email_verified_join' => ['tenant', 'People cannot join the tenant just by verifying an email address', 'low', $NONE, 'Policy.Read.All',
                'When this is on, anyone with a company email address can create an account in the tenant through a self-service sign-up, outside the normal account process.',
                ['Microsoft Graph PowerShell: Update-MgPolicyAuthorizationPolicy -AllowEmailVerifiedUsersToJoinOrganization:$false.'],
                'itops_sa_c_email_verified_join'],
            'self_service_signup' => ['tenant', 'People cannot sign up for Microsoft subscriptions on their own', 'low', $NONE, 'Policy.Read.All',
                'Self-service sign-ups create subscriptions and trials nobody in IT knows about, sometimes holding company data.',
                ['Microsoft Graph PowerShell: Update-MgPolicyAuthorizationPolicy -AllowedToSignUpEmailBasedSubscriptions:$false.', 'Microsoft 365 admin center → Settings → Org settings → Self-service trials and purchases: turn off what is not wanted.'],
                'itops_sa_c_self_service_signup'],
            'admin_consent_workflow' => ['tenant', 'People can ask an admin to approve an app', 'low', $NONE, 'Policy.Read.All',
                'Once people cannot consent to apps themselves, they need a way to ask. Without the request workflow they ask by email, or find a way around.',
                ['Entra admin center → Enterprise applications → Admin consent settings → "Users can request admin consent to apps they are unable to consent to" → Yes; choose the reviewers.'],
                'itops_sa_c_admin_consent_workflow'],
            // ── Admin accounts ──
            'admins_cloud_only' => ['admins', 'Admin accounts are cloud-only', 'medium', $NONE, 'RoleManagement.Read.Directory, User.Read.All',
                'An admin account synchronized from the on-premises directory can be taken over from the on-premises network, then used to take over the tenant.',
                ['Create cloud-only admin accounts (on the onmicrosoft.com domain) for the listed people, move the roles to them, and remove the roles from the synchronized accounts.'],
                'itops_sa_c_admins_cloud_only'],
            'pim_used' => ['admins', 'Admin roles are assigned when needed, not held permanently', 'medium', $P2, 'RoleManagement.Read.Directory, RoleEligibilitySchedule.Read.Directory',
                'A role someone holds all the time is a role an attacker gets with their account. With Privileged Identity Management the role is requested for a few hours, with a reason and, if wanted, an approval.',
                ['With Entra ID P2: Entra admin center → Identity Governance → Privileged Identity Management → Microsoft Entra roles → make the admins eligible instead of active; keep only the emergency accounts permanently active.'],
                'itops_sa_c_pim_used'],
            // ── Applications ──
            'app_password_block' => ['apps', 'New password secrets on apps are blocked', 'low', $NONE, 'Policy.Read.All',
                'App secrets are passwords that skip multifactor sign-in. Certificates or managed identities are safer, and Microsoft\'s baseline security mode blocks new secrets.',
                ['Microsoft 365 admin center → Settings → Org settings → Security & privacy → Baseline security mode → "Block addition of new password credentials to apps" (check its impact report first).', 'Move the listed apps to certificates at their next rotation.'],
                'itops_sa_c_app_password_block'],
            // ── Email ──
            'mailbox_audit' => ['email', 'Mailbox auditing is on', 'medium', $EXO, 'Exchange.ManageAsAppV2 (Exchange Admin API, preview)',
                'Mailbox auditing records who opened, sent from or deleted mail in each mailbox. Without it, a mailbox takeover leaves no trace to investigate.',
                ['Exchange Online PowerShell: Set-OrganizationConfig -AuditDisabled $false.'],
                'itops_sa_c_mailbox_audit'],
            'exo_modern_auth' => ['email', 'Exchange uses modern sign-in', 'high', $EXO, 'Exchange.ManageAsAppV2 (Exchange Admin API, preview)',
                'Without modern authentication, Outlook falls back to basic sign-in, which cannot ask for multifactor sign-in.',
                ['Exchange Online PowerShell: Set-OrganizationConfig -OAuth2ClientProfileEnabled $true.'],
                'itops_sa_c_exo_modern_auth'],
            'exo_ews_off' => ['email', 'Exchange Web Services is off for the organization', 'low', $EXO, 'Exchange.ManageAsAppV2 (Exchange Admin API, preview)',
                'Exchange Web Services is an old access path to every mailbox, used by attackers after a takeover. Microsoft is retiring it; most businesses no longer need it.',
                ['Microsoft 365 admin center → Baseline security mode → "Disable organization-wide access to Exchange Web Services" (read its impact report: some add-ins and older Mac clients still use it).'],
                'itops_sa_c_exo_ews_off'],
            'dmarc_reject' => ['email', 'DMARC rejects forged mail on every sending domain', 'low', $NONE, 'Domain.Read.All (and public DNS)',
                '"Quarantine" sends forged mail to junk; "reject" stops it at the door. Reject is the end state once the reports show only your own services sending.',
                ['After a few weeks at p=quarantine with clean reports, change the record at _dmarc.<domain> to p=reject.'],
                'itops_sa_c_dmarc_reject'],
            // ── Devices ──
            'compliance_policy_platforms' => ['devices', 'Every platform in use has a compliance policy', 'high', $INT, 'DeviceManagementConfiguration.Read.All',
                'Compliance is what Conditional Access trusts. A platform without an assigned compliance policy has no definition of a healthy device.',
                ['Intune admin center → Devices → Compliance → Create policy, for each listed platform: require encryption, a minimum OS version, a PIN or password, and Defender (Windows). Assign it to all users or all devices.'],
                'itops_sa_c_compliance_policy_platforms'],
            'secure_by_default' => ['devices', 'Devices with no compliance policy count as not compliant', 'medium', $INT, 'DeviceManagementConfiguration.Read.All',
                'By default, Intune treats a device that no compliance policy covers as compliant, so a gap in assignments silently lets devices in.',
                ['Intune admin center → Devices → Compliance → Compliance settings → "Mark devices with no compliance policy assigned as" → Not compliant.'],
                'itops_sa_c_secure_by_default'],
            'laps_backed_up' => ['devices', 'Windows computers have a managed local admin password', 'high', $INT, 'DeviceLocalCredential.ReadBasic.All (or DeviceLocalCredential.Read.All)',
                'The same local administrator password on every computer lets an attacker on one computer move to all of them. Windows LAPS gives each one its own password, rotated and kept in Entra.',
                ['Intune admin center → Endpoint security → Account protection → Create policy → Local admin password solution (Windows LAPS): backup directory Entra ID, password age 30 days; assign it to all Windows devices.'],
                'itops_sa_c_laps_backed_up'],
            'bitlocker_escrow' => ['devices', 'Windows computers have their BitLocker key in Entra', 'medium', $INT, 'BitlockerKey.ReadBasic.All (or BitlockerKey.Read.All)',
                'An encrypted computer whose recovery key is not stored is a computer whose data is lost the first time BitLocker asks for the key.',
                ['Intune admin center → Endpoint security → Disk encryption → BitLocker policy with "Save BitLocker recovery information to Entra ID" required; for the listed computers, run manage-bde -protectors -adbackup or BackupToAAD-BitLockerKeyProtector.'],
                'itops_sa_c_bitlocker_escrow'],
            'baseline_assigned' => ['devices', 'A Windows security baseline is assigned', 'medium', $INT, 'DeviceManagementConfiguration.Read.All',
                'Microsoft\'s security baseline is several hundred recommended Windows settings in one profile, kept current by Microsoft. It is the quickest way to a hardened Windows.',
                ['Intune admin center → Endpoint security → Security baselines → Security Baseline for Windows → Create profile; assign it to a pilot group, then to all Windows devices.'],
                fn($D) => itops_sa_espol($D, ['baseline'], 'security baseline')],
            'asr_assigned' => ['devices', 'Attack surface reduction rules are assigned', 'medium', $MDB, 'DeviceManagementConfiguration.Read.All',
                'These rules stop the tricks most attacks start with: Office starting scripts, obfuscated macros, credential theft from memory, executables from email.',
                ['Intune admin center → Endpoint security → Attack surface reduction → Create policy → Attack Surface Reduction Rules: every rule in Audit mode for two weeks, then Block.'],
                fn($D) => itops_sa_espol($D, ['endpointSecurityAttackSurfaceReduction'], 'attack surface reduction policy')],
            'firewall_assigned' => ['devices', 'A Windows firewall policy is assigned', 'low', $INT, 'DeviceManagementConfiguration.Read.All',
                'A firewall set centrally cannot be switched off by a person or an installer, and blocks connections to the computer that nobody asked for.',
                ['Intune admin center → Endpoint security → Firewall → Create policy → Windows Firewall: on for the domain, private and public profiles; assign it to all Windows devices.'],
                fn($D) => itops_sa_espol($D, ['endpointSecurityFirewall'], 'firewall policy')],
            'antivirus_assigned' => ['devices', 'A Microsoft Defender Antivirus policy is assigned', 'medium', $MDB, 'DeviceManagementConfiguration.Read.All',
                'A central antivirus policy keeps real-time and cloud protection on and tamper protection locked, whatever happens on the computer.',
                ['Intune admin center → Endpoint security → Antivirus → Create policy → Microsoft Defender Antivirus: real-time protection, cloud protection high, tamper protection on; assign it to all Windows devices.'],
                fn($D) => itops_sa_espol($D, ['endpointSecurityAntivirus'], 'antivirus policy')],
            'edr_assigned' => ['devices', 'Devices are onboarded to Defender detection and response', 'medium', $MDB, 'DeviceManagementConfiguration.Read.All',
                'Detection and response records what happens on each device, so an attack is noticed and can be investigated and contained.',
                ['Defender portal → Settings → Endpoints → Advanced features → connect Intune; then Intune admin center → Endpoint security → Endpoint detection and response → Create policy (auto from connector); assign it to all devices.'],
                fn($D) => itops_sa_espol($D, ['endpointSecurityEndpointDetectionAndResponse'], 'detection and response policy')],
            'update_rings' => ['devices', 'Windows update rings are assigned', 'medium', $INT, 'DeviceManagementConfiguration.Read.All',
                'Without update rings, Windows updates arrive when each computer decides. Rings give a pilot group first and a deadline after which updates are no longer postponed.',
                ['Intune admin center → Devices → Windows updates → Update rings: a pilot ring (0 to 3 days) and a ring for everyone (7 days), each with a deadline of 2 to 3 days. Manage them in ' . $APP . ': Devices → Update rings.'],
                'itops_sa_c_update_rings'],
            'windows_supported' => ['devices', 'No computer runs Windows 10', 'high', $NONE, 'DeviceManagementManagedDevices.Read.All',
                'Windows 10 reached its end of support on 10/14/2025: it gets no more security updates unless extended security updates were bought.',
                ['Upgrade the listed computers to Windows 11 (Intune: a feature update policy), or replace those that cannot run it.', 'Until then, consider extended security updates for the ones that must stay.'],
                'itops_sa_c_windows_supported'],
            'app_protection' => ['devices', 'Phones have app protection for company data', 'medium', $INT, 'DeviceManagementApps.Read.All',
                'On a personal phone, app protection keeps company mail and files inside managed apps, requires a PIN, and lets IT remove only the company data.',
                ['Intune admin center → Apps → App protection policies → Create policy for iOS/iPadOS and for Android: target Outlook, Teams, OneDrive and the Office apps; require a PIN; block copying to unmanaged apps; assign to all users.'],
                'itops_sa_c_app_protection'],
            // ── Sharing & collaboration ──
            'spo_anyone_links' => ['collab', '"Anyone" links are off in SharePoint and OneDrive', 'medium', $SPO, 'SharePointTenantSettings.Read.All',
                'An "Anyone" link works for whoever gets it, without signing in. Forwarded once, the file is public, and nobody can tell who opened it.',
                ['SharePoint admin center → Policies → Sharing → External sharing: "New and existing guests" (or stricter) for SharePoint and OneDrive.'],
                'itops_sa_c_spo_anyone_links'],
            'spo_external_strict' => ['collab', 'External sharing is limited to people already invited', 'low', $SPO, 'SharePointTenantSettings.Read.All',
                'Limiting sharing to existing guests means every outside person is invited on purpose first, so nothing is shared with someone nobody approved.',
                ['SharePoint admin center → Policies → Sharing → External sharing → "Existing guests" (or "Only people in your organization"); open specific sites up where needed.'],
                'itops_sa_c_spo_external_strict'],
            // ── Overall posture ──
            'secure_score' => ['posture', 'Microsoft Secure Score is at least 70%', 'medium', $NONE, 'SecurityEvents.Read.All',
                'Secure Score is Microsoft\'s own measure of how many of its recommended protections are in place. It is a useful second opinion next to this assessment.',
                ['Open the Defender portal → Exposure management → Secure Score → Recommended actions, and work through the ones with the most points first.', 'The other checks here cover the most important of them.'],
                'itops_sa_c_secure_score'],
        ];
    }

    /* ─────────────────────────── reading the tenant ─────────────────────────── */

    /** A check that cannot be read: why (permission | license | error) and the sentence to show. */
    class ItopsSaUnavailable extends RuntimeException {
        public string $why;
        public function __construct(string $why, string $msg) { parent::__construct($msg); $this->why = $why; }
    }

    /** Classifies a Microsoft refusal. */
    function itops_sa_classify(Throwable $e): ItopsSaUnavailable {
        if ($e instanceof ItopsSaUnavailable) return $e;
        $m = $e->getMessage();
        if (preg_match('/premium|license|licence|\bP1\b|\bP2\b|not (?:onboarded|provisioned)|Tenant does not have|requires .*subscription/i', $m)) return new ItopsSaUnavailable('license', 'Microsoft says the tenant does not have the license this check needs. ' . mb_substr($m, 0, 300));
        if (preg_match('/HTTP 40[13]|access denied|Authorization_RequestDenied|Insufficient privileges|Forbidden/i', $m)) return new ItopsSaUnavailable('permission', 'The app is missing a Microsoft permission (or its admin consent). ' . mb_substr($m, 0, 300));
        return new ItopsSaUnavailable('error', 'Microsoft returned an error: ' . mb_substr($m, 0, 300));
    }

    /**
     * The Graph reads behind the checks, by key. Read-only. A key that cannot be read throws; the checks that need it
     * are marked "could not be read".
     */
    function itops_sa_fetch_graph(string $key) {
        if (!function_exists('itops_graph_get')) require_once __DIR__ . '/Graph.php';
        switch ($key) {
            case 'users':
                try { return ['rows' => itops_graph_paged('users?$top=999&$select=id,displayName,userPrincipalName,mail,accountEnabled,userType,assignedLicenses,createdDateTime,onPremisesSyncEnabled,signInActivity', 10), 'signin' => true]; }
                catch (Throwable $e) {   // signInActivity needs Entra ID P1 and AuditLog.Read.All: read the rest without it
                    return ['rows' => itops_graph_paged('users?$top=999&$select=id,displayName,userPrincipalName,mail,accountEnabled,userType,assignedLicenses,createdDateTime,onPremisesSyncEnabled', 10), 'signin' => false, 'signin_error' => $e->getMessage()];
                }
            case 'reg':     return itops_graph_paged('reports/authenticationMethods/userRegistrationDetails?$top=999', 10);
            case 'roles': {
                $out = [];
                foreach ((itops_graph_get('directoryRoles?$select=id,displayName,roleTemplateId')['value'] ?? []) as $r) {
                    $m = itops_graph_paged('directoryRoles/' . rawurlencode((string)$r['id']) . '/members?$select=id,displayName,userPrincipalName,accountEnabled', 3);
                    $out[] = ['template' => strtolower((string)($r['roleTemplateId'] ?? '')), 'name' => (string)($r['displayName'] ?? ''), 'members' => $m];
                }
                return $out;
            }
            case 'ca':      return itops_graph_get('identity/conditionalAccess/policies')['value'] ?? [];
            case 'secdef':  return itops_graph_get('policies/identitySecurityDefaultsEnforcementPolicy');
            case 'authz':   { $a = itops_graph_get('policies/authorizationPolicy'); return isset($a['value'][0]) ? $a['value'][0] : $a; }
            case 'authm':   return itops_graph_get('policies/authenticationMethodsPolicy');
            case 'apps':    return itops_graph_paged('applications?$top=999&$select=id,appId,displayName,passwordCredentials,keyCredentials', 5);
            case 'mail': {
                require_once __DIR__ . '/EmailSecurity.php';
                $out = [];
                foreach ((itops_graph_get('domains?$select=id,isVerified,supportedServices')['value'] ?? []) as $d) {
                    $name = strtolower((string)($d['id'] ?? ''));
                    if ($name === '' || empty($d['isVerified'])) continue;
                    if (itops_mailsec_skip_reason($name, isset($d['supportedServices']) ? (array)$d['supportedServices'] : null) !== '') continue;
                    $out[] = itops_mailsec_domain($name);
                }
                return $out;
            }
            case 'devices': return itops_graph_paged('deviceManagement/managedDevices?$top=999&$select=id,deviceName,userPrincipalName,operatingSystem,osVersion,complianceState,isEncrypted,lastSyncDateTime,azureADDeviceId,managedDeviceOwnerType', 10);
            // BT116 (4a)
            case 'acr':        return itops_graph_get('policies/adminConsentRequestPolicy');
            case 'appmgmt':    return itops_graph_get('policies/defaultAppManagementPolicy');
            case 'eligible':   return itops_graph_paged('roleManagement/directory/roleEligibilityScheduleInstances?$top=999', 5);
            case 'exo_org':    { require_once __DIR__ . '/ExchangeAdmin.php'; $r = itops_exo_call('OrganizationConfig', 'Get-OrganizationConfig', [], '', [], 1); return $r[0] ?? []; }
            case 'cpol':       return itops_sa_paged('deviceManagement/deviceCompliancePolicies?$expand=assignments&$top=100', 'v1.0', 5);
            case 'dmsettings': return itops_graph_get('deviceManagement?$select=settings');
            case 'laps':       return itops_sa_paged('directory/deviceLocalCredentials?$select=id,deviceName,lastBackupDateTime', 'v1.0', 20);
            case 'bitlocker':  return itops_sa_paged('informationProtection/bitlocker/recoveryKeys?$select=id,deviceId,createdDateTime', 'v1.0', 20);
            case 'espol':      return itops_sa_paged('deviceManagement/configurationPolicies?$select=id,name,templateReference&$expand=assignments&$top=100', 'beta', 10);
            case 'dconf':      return itops_sa_paged('deviceManagement/deviceConfigurations?$expand=assignments&$top=100', 'v1.0', 10);
            case 'mam':        return ['ios' => itops_sa_paged('deviceAppManagement/iosManagedAppProtections?$expand=assignments', 'v1.0', 3), 'android' => itops_sa_paged('deviceAppManagement/androidManagedAppProtections?$expand=assignments', 'v1.0', 3)];
            case 'spo':        return itops_graph_get('admin/sharepoint/settings');
            case 'skus':       return itops_graph_get('subscribedSkus')['value'] ?? [];
            case 'controls':   return itops_sa_paged('security/secureScoreControlProfiles?$top=200', 'v1.0', 5);
            case 'emergency':  return itops_sa_setting_json(getDB(), 'sa_emergency', []);
            case 'profile':    return itops_sa_setting_json(getDB(), 'sa_profile', []);
            case 'score':   return itops_graph_get('security/secureScores?$top=1')['value'][0] ?? null;
            // BT106: Identity Protection (Entra ID P2, IdentityRiskyUser.Read.All) and a person's manager (User.Read.All)
            case 'risky':   return itops_graph_paged('identityProtection/riskyUsers?$top=500', 5);
        }
        if (strpos($key, 'groupmembers:') === 0) return array_map(fn($m) => strtolower((string)($m['id'] ?? '')), itops_sa_paged('groups/' . rawurlencode(substr($key, 13)) . '/transitiveMembers?$select=id&$top=999', 'v1.0', 5));
        if (strpos($key, 'manager:') === 0) {
            try { $m = itops_graph_get('users/' . rawurlencode(substr($key, 8)) . '/manager?$select=displayName,mail,userPrincipalName'); }
            catch (Throwable $e) { if (preg_match('/HTTP 404/', $e->getMessage())) return null; throw $e; }   // no manager set
            return ['name' => (string)($m['displayName'] ?? ''), 'mail' => (string)(($m['mail'] ?? '') ?: ($m['userPrincipalName'] ?? ''))];
        }
        throw new ItopsSaUnavailable('error', 'Unknown data key ' . $key);
    }

    /* ─────────────────────────── the checks ─────────────────────────── */
    // Each evaluator: fn(callable $D): ['status' => …, 'detail' => '…', 'affected' => [[name, detail], …]]
    // $D('key') returns the data or throws ItopsSaUnavailable.

    function itops_sa_r(string $status, string $detail, array $affected = []): array { return ['status' => $status, 'detail' => $detail, 'affected' => $affected]; }
    function itops_sa_days_since(?string $ts): ?int { if (!$ts) return null; $t = strtotime($ts); return $t === false ? null : (int)floor((time() - $t) / 86400); }
    /** Enabled member accounts by id (the people the identity checks are about). */
    function itops_sa_members(callable $D): array {
        $out = []; foreach (($D('users')['rows'] ?? []) as $u) if (!empty($u['accountEnabled']) && ($u['userType'] ?? 'Member') !== 'Guest') $out[strtolower((string)$u['id'])] = $u;
        return $out;
    }
    /** Holders of the roles in $templates (null = every role): id => [user, [role names]]. Only user accounts. */
    function itops_sa_role_holders(callable $D, ?array $templates = null): array {
        $out = [];
        foreach ($D('roles') as $r) {
            if ($templates !== null && !in_array($r['template'], $templates, true)) continue;
            foreach ($r['members'] as $m) {
                if (($m['@odata.type'] ?? '#microsoft.graph.user') !== '#microsoft.graph.user' || empty($m['userPrincipalName'])) continue;
                $id = strtolower((string)$m['id']);
                if (!isset($out[$id])) $out[$id] = [$m, []];
                $out[$id][1][] = $r['name'];
            }
        }
        return $out;
    }
    function itops_sa_high_templates(): array { require_once __DIR__ . '/M365Guard.php'; return array_map('strtolower', array_keys(itops_m365_high_roles())); }
    /** Security defaults on? (null when it cannot be read) */
    function itops_sa_secdef(callable $D): ?bool { try { return !empty($D('secdef')['isEnabled']); } catch (ItopsSaUnavailable $e) { return null; } }
    function itops_sa_ca_on(callable $D): array {
        try { $all = $D('ca'); }
        catch (ItopsSaUnavailable $e) {
            // no Conditional Access in the tenant (no Entra ID P1) and Security defaults known to be off: nothing enforces it
            if ($e->why === 'license' && itops_sa_secdef($D) === false) return [];
            throw $e;
        }
        return array_values(array_filter($all, fn($p) => ($p['state'] ?? '') === 'enabled'));
    }
    function itops_sa_ca_requires_mfa(array $p): bool {
        $c = $p['grantControls']['builtInControls'] ?? [];
        return in_array('mfa', $c, true) || !empty($p['grantControls']['authenticationStrength']);
    }
    function itops_sa_ca_all_apps(array $p): bool { $a = $p['conditions']['applications']['includeApplications'] ?? []; return in_array('All', $a, true); }


    /**
     * BT124 (code review SEC-4): what makes a policy cover less than it seems. Empty = it covers what its rule needs.
     * $o: users_all (bool, people: everyone), admins (bool, people: the admin roles), accept (controls that satisfy the rule;
     * with OR any other control is a way around it), legacy (bool: the policy is meant for legacy clients only), risk (bool:
     * a risk condition is the point of the rule).
     */
    function itops_sa_ca_narrowing(callable $D, array $p, array $o): array {
        $why = []; $u = $p['conditions']['users'] ?? []; $c = $p['conditions'] ?? [];
        $emIds = [];
        try { $em = array_map('strtolower', (array)$D('emergency')); foreach (($D('users')['rows'] ?? []) as $x) if (in_array(strtolower((string)($x['userPrincipalName'] ?? '')), $em, true)) $emIds[] = strtolower((string)$x['id']); } catch (Throwable $e) {}
        $exU = array_values(array_diff(array_map('strtolower', (array)($u['excludeUsers'] ?? [])), $emIds, ['guestsorexternalusers']));
        if ($exU) $why[] = 'leaves out ' . count($exU) . ' account' . (count($exU) === 1 ? '' : 's') . ' that ' . (count($exU) === 1 ? 'is not an emergency access account' : 'are not emergency access accounts');
        if (!empty($u['excludeGroups'])) $why[] = 'leaves out ' . count($u['excludeGroups']) . ' group' . (count($u['excludeGroups']) === 1 ? '' : 's');
        if (!empty($u['excludeRoles'])) $why[] = 'leaves out ' . count($u['excludeRoles']) . ' admin role' . (count($u['excludeRoles']) === 1 ? '' : 's');
        if (!empty($o['admins']) && !in_array('All', (array)($u['includeUsers'] ?? []), true)) {
            // the admin roles Microsoft recommends such a policy covers: the same list the app's own Conditional Access
            // templates use, so a policy made here passes its own rule
            require_once __DIR__ . '/CaOps.php'; $need = array_map('strtolower', itops_ca_admin_roles());
            $inc = array_map('strtolower', (array)($u['includeRoles'] ?? [])); $miss = array_diff($need, $inc);
            if ($miss) $why[] = 'covers ' . count(array_intersect($need, $inc)) . ' of the ' . count($need) . ' admin roles Microsoft recommends';
        }
        if (!empty($c['applications']['excludeApplications'])) $why[] = 'leaves out ' . count($c['applications']['excludeApplications']) . ' app' . (count($c['applications']['excludeApplications']) === 1 ? '' : 's');
        if (empty($o['legacy'])) { $ct = (array)($c['clientAppTypes'] ?? []); if ($ct && !in_array('all', $ct, true)) $why[] = 'applies only to ' . implode(', ', $ct); }
        $pl = (array)($c['platforms'] ?? []); if ($pl && ((!empty($pl['includePlatforms']) && !in_array('all', (array)$pl['includePlatforms'], true)) || !empty($pl['excludePlatforms']))) $why[] = 'applies only to some device platforms';
        $lc = (array)($c['locations'] ?? []); if ($lc && ((!empty($lc['includeLocations']) && !in_array('All', (array)$lc['includeLocations'], true)) || !empty($lc['excludeLocations']))) $why[] = 'applies only in some locations';
        if (empty($o['risk']) && (!empty($c['signInRiskLevels']) || !empty($c['userRiskLevels']))) $why[] = 'applies only when Microsoft sees risk';
        if (!empty($o['accept'])) {
            $g = $p['grantControls'] ?? []; $ctl = (array)($g['builtInControls'] ?? []); if (!empty($g['authenticationStrength'])) $ctl[] = 'authenticationStrength';
            if (strtoupper((string)($g['operator'] ?? 'OR')) === 'OR' && count($ctl) > 1) { $other = array_diff($ctl, $o['accept']); if ($other) $why[] = 'can be met with ' . implode(' or ', $other) . ' instead'; }
        }
        return $why;
    }
    /** The first enabled policy that $match accepts and nothing narrows → pass; a narrowed one → warn (why); none → fail. */
    function itops_sa_ca_judge(callable $D, callable $match, array $o, string $passText, string $failText): array {
        $warn = null;
        foreach (itops_sa_ca_on($D) as $p) {
            if (!$match($p)) continue;
            $n = itops_sa_ca_narrowing($D, $p, $o); $nm = (string)($p['displayName'] ?? '');
            if (!$n) return itops_sa_r('pass', str_replace('{p}', $nm, $passText), [[$nm, 'Conditional Access policy']]);
            if ($warn === null) $warn = itops_sa_r('warn', 'Conditional Access policy "' . $nm . '" ' . str_replace('{p}', $nm, $o['does'] ?? 'is there') . ', but it ' . implode('; ', $n) . '.', [[$nm, implode('; ', $n)]]);
        }
        return $warn ?? itops_sa_r('fail', $failText);
    }
    function itops_sa_c_mfa_registered(callable $D): array {
        // BT124 (code review SEC-17): every enabled member is judged; one missing from Microsoft's registration report is not
        // counted as registered (the same as the admin rule)
        $members = itops_sa_members($D); $miss = []; $reg = [];
        foreach ($D('reg') as $r) $reg[strtolower((string)($r['id'] ?? ''))] = $r;
        foreach ($members as $id => $m) {
            $r = $reg[$id] ?? null;
            if (!$r) $miss[] = [(string)($m['displayName'] ?? ''), (string)($m['userPrincipalName'] ?? '') . ' · not in the registration report'];
            elseif (empty($r['isMfaRegistered'])) $miss[] = [(string)($r['userDisplayName'] ?? $m['displayName'] ?? ''), (string)($r['userPrincipalName'] ?? '')];
        }
        $n = count($members);
        if (!$n) return itops_sa_r('na', 'No enabled member accounts were found.');
        if (!$miss) return itops_sa_r('pass', 'All ' . $n . ' enabled accounts have registered a multifactor method.');
        return itops_sa_r('fail', count($miss) . ' of ' . $n . ' enabled accounts have not registered a multifactor method.', $miss);
    }
    function itops_sa_c_mfa_required(callable $D): array {
        if (itops_sa_secdef($D) === true) return itops_sa_r('pass', 'Security defaults are on: everyone is asked to register and to use multifactor sign-in.');
        return itops_sa_ca_judge($D, fn($p) => in_array('All', $p['conditions']['users']['includeUsers'] ?? [], true) && itops_sa_ca_all_apps($p) && itops_sa_ca_requires_mfa($p),
            ['accept' => ['mfa', 'authenticationStrength'], 'does' => 'requires multifactor sign-in for all users'],
            'Conditional Access policy "{p}" requires multifactor sign-in for all users and all apps.', 'Security defaults are off and no enabled Conditional Access policy requires multifactor sign-in for all users and all apps.');
    }
    function itops_sa_c_legacy_auth(callable $D): array {
        if (itops_sa_secdef($D) === true) return itops_sa_r('pass', 'Security defaults are on: legacy sign-in is blocked.');
        return itops_sa_ca_judge($D, function ($p) { $apps = $p['conditions']['clientAppTypes'] ?? []; return in_array('block', $p['grantControls']['builtInControls'] ?? [], true) && in_array('All', $p['conditions']['users']['includeUsers'] ?? [], true) && in_array('exchangeActiveSync', $apps, true) && in_array('other', $apps, true); },
            ['legacy' => true, 'does' => 'blocks legacy sign-in'], 'Conditional Access policy "{p}" blocks legacy sign-in for all users.', 'Security defaults are off and no enabled Conditional Access policy blocks legacy sign-in (Exchange ActiveSync and other clients) for all users.');
    }
    function itops_sa_c_admin_mfa(callable $D): array {
        $holders = itops_sa_role_holders($D, itops_sa_high_templates());
        if (!$holders) return itops_sa_r('na', 'No user account holds an admin role.');
        $reg = []; foreach ($D('reg') as $r) $reg[strtolower((string)($r['id'] ?? ''))] = !empty($r['isMfaRegistered']);
        $miss = [];
        foreach ($holders as $id => [$u, $roles]) if (empty($reg[$id])) $miss[] = [(string)($u['displayName'] ?? ''), (string)$u['userPrincipalName'] . ' · ' . implode(', ', $roles)];
        if (!$miss) return itops_sa_r('pass', 'All ' . count($holders) . ' admins have registered a multifactor method.');
        return itops_sa_r('fail', count($miss) . ' of ' . count($holders) . ' admins have not registered a multifactor method.', $miss);
    }
    function itops_sa_c_admin_mfa_policy(callable $D): array {
        if (itops_sa_secdef($D) === true) return itops_sa_r('pass', 'Security defaults are on: admins are asked for multifactor sign-in every time.');
        return itops_sa_ca_judge($D, fn($p) => itops_sa_ca_requires_mfa($p) && itops_sa_ca_all_apps($p) && itops_sa_ca_targets_admins($p),
            ['admins' => true, 'accept' => ['mfa', 'authenticationStrength'], 'does' => 'requires multifactor sign-in for admins'], 'Conditional Access policy "{p}" requires multifactor sign-in for the admin roles.', 'No enabled Conditional Access policy requires multifactor sign-in for Global Administrators (or for everyone), and Security defaults are off.');
    }
    function itops_sa_c_ca_report_only(callable $D): array {
        $all = $D('ca');
        if (!$all) return itops_sa_r('na', 'There are no Conditional Access policies.');
        $ro = array_values(array_filter($all, fn($p) => ($p['state'] ?? '') === 'enabledForReportingButNotEnforced'));
        if (!$ro) return itops_sa_r('pass', 'None of the ' . count($all) . ' Conditional Access policies is in report-only.');
        return itops_sa_r('warn', count($ro) . ' Conditional Access ' . (count($ro) === 1 ? 'policy is in report-only and protects' : 'policies are in report-only and protect') . ' nothing yet.', array_map(fn($p) => [(string)($p['displayName'] ?? ''), 'report-only'], $ro));
    }
    function itops_sa_c_weak_methods(callable $D): array {
        $on = [];
        foreach (($D('authm')['authenticationMethodConfigurations'] ?? []) as $c)
            if (in_array(strtolower((string)($c['id'] ?? '')), ['sms', 'voice'], true) && ($c['state'] ?? '') === 'enabled') $on[] = [strtolower($c['id']) === 'sms' ? 'Text message (SMS)' : 'Voice call', 'enabled'];
        if (!$on) return itops_sa_r('pass', 'Text message and voice call are not offered as sign-in methods.');
        return itops_sa_r('warn', implode(' and ', array_column($on, 0)) . (count($on) === 1 ? ' is' : ' are') . ' offered as a sign-in method.', $on);
    }
    function itops_sa_c_stale_users(callable $D): array {
        $u = $D('users');
        if (empty($u['signin'])) throw new ItopsSaUnavailable('license', 'The last sign-in of each account could not be read: it needs Microsoft Entra ID P1 and the AuditLog.Read.All permission.');
        $stale = [];
        $emS = []; try { $emS = array_map('strtolower', (array)$D('emergency')); } catch (Throwable $e) {}
        foreach (itops_sa_members($D) as $m) {
            if (in_array(strtolower((string)($m['userPrincipalName'] ?? '')), $emS, true)) continue;   // BT124 (review SEC-11): emergency accounts are left unused on purpose
            $last = $m['signInActivity']['lastSignInDateTime'] ?? null; $d = itops_sa_days_since($last);
            $age = itops_sa_days_since($m['createdDateTime'] ?? null);
            if ($d === null && ($age === null || $age > 30)) $stale[] = [(string)($m['displayName'] ?? ''), (string)($m['userPrincipalName'] ?? '') . ' · never signed in'];
            elseif ($d !== null && $d > 90) $stale[] = [(string)($m['displayName'] ?? ''), (string)($m['userPrincipalName'] ?? '') . ' · last sign-in ' . $d . ' days ago'];
        }
        if (!$stale) return itops_sa_r('pass', 'Every enabled account has signed in within the last 90 days.');
        return itops_sa_r('fail', count($stale) . ' enabled ' . (count($stale) === 1 ? 'account has' : 'accounts have') . ' not signed in for more than 90 days (or never).', $stale);
    }
    function itops_sa_c_stale_guests(callable $D): array {
        $u = $D('users');
        if (empty($u['signin'])) throw new ItopsSaUnavailable('license', 'The last sign-in of each guest could not be read: it needs Microsoft Entra ID P1 and the AuditLog.Read.All permission.');
        $g = array_values(array_filter($u['rows'] ?? [], fn($x) => ($x['userType'] ?? '') === 'Guest' && !empty($x['accountEnabled'])));
        if (!$g) return itops_sa_r('na', 'There are no guests.');
        $stale = [];
        foreach ($g as $x) {
            $d = itops_sa_days_since($x['signInActivity']['lastSignInDateTime'] ?? null); $age = itops_sa_days_since($x['createdDateTime'] ?? null);
            if (($d === null && ($age === null || $age > 30)) || ($d !== null && $d > 90)) $stale[] = [(string)($x['displayName'] ?? ''), ($d === null ? 'never signed in' : 'last sign-in ' . $d . ' days ago')];
        }
        if (!$stale) return itops_sa_r('pass', (count($g) === 1 ? 'The only guest has' : 'All ' . count($g) . ' guests have') . ' signed in within the last 90 days.');
        return itops_sa_r('warn', count($stale) . ' of ' . count($g) . ' guests have not signed in for more than 90 days (or never).', $stale);
    }
    function itops_sa_c_ga_count(callable $D): array {
        $ga = itops_sa_role_holders($D, [ITOPS_SA_GA]);
        // BT124 (review SEC-10): an account whose sign-in is blocked is no way back in; it is listed, not counted
        $off = array_filter($ga, fn($x) => array_key_exists('accountEnabled', $x[0]) && empty($x[0]['accountEnabled']));
        $ga = array_diff_key($ga, $off);
        $list = array_merge(array_map(fn($x) => [(string)($x[0]['displayName'] ?? ''), (string)$x[0]['userPrincipalName']], array_values($ga)),
                            array_map(fn($x) => [(string)($x[0]['displayName'] ?? ''), (string)$x[0]['userPrincipalName'] . ' · sign-in blocked (not counted)'], array_values($off)));
        $n = count($ga);
        if ($n >= 2 && $n <= 4) return itops_sa_r('pass', $n . ' accounts are Global Administrators.', $list);
        if ($n === 0) return itops_sa_r('unknown', 'No Global Administrator was found among the role members the app can read.');
        if ($n === 1) return itops_sa_r('fail', 'Only one account is Global Administrator: if it is lost or locked, nobody can administer the tenant.', $list);
        return itops_sa_r('fail', $n . ' accounts are Global Administrators; two to four is the recommendation.', $list);
    }
    function itops_sa_c_admin_separate(callable $D): array {
        $holders = itops_sa_role_holders($D, itops_sa_high_templates());
        if (!$holders) return itops_sa_r('na', 'No user account holds an admin role.');
        $byId = []; foreach (($D('users')['rows'] ?? []) as $u) $byId[strtolower((string)$u['id'])] = $u;
        $daily = [];
        foreach ($holders as $id => [$m, $roles]) {
            $lic = $byId[$id]['assignedLicenses'] ?? [];
            if ($lic) $daily[] = [(string)($m['displayName'] ?? ''), (string)$m['userPrincipalName'] . ' · ' . implode(', ', $roles) . ' · ' . count($lic) . ' license' . (count($lic) === 1 ? '' : 's')];
        }
        if (!$daily) return itops_sa_r('pass', 'None of the ' . count($holders) . ' admin accounts has a license: they look like separate admin accounts.');
        return itops_sa_r('warn', count($daily) . ' of ' . count($holders) . ' admin accounts also have licenses, so they are probably used every day for mail and browsing.', $daily);
    }
    function itops_sa_c_user_consent(callable $D): array {
        // BT124 (code review SEC-21): a missing setting is not read as "no consent"; a custom consent policy is a warning
        $dp = $D('authz')['defaultUserRolePermissions'] ?? null;
        if (!is_array($dp) || !array_key_exists('permissionGrantPoliciesAssigned', $dp)) return itops_sa_r('unknown', 'The user consent setting was not in Microsoft\'s answer.');
        $g = (array)$dp['permissionGrantPoliciesAssigned'];
        $legacy = array_values(array_filter($g, fn($x) => stripos((string)$x, 'microsoft-user-default-legacy') !== false));
        if ($legacy) return itops_sa_r('fail', 'Anyone can let an app read company data on their behalf (user consent for all apps is allowed).');
        $self = array_values(array_filter($g, fn($x) => stripos((string)$x, 'ManagePermissionGrantsForSelf') !== false));
        $low = array_values(array_filter($self, fn($x) => stripos((string)$x, 'microsoft-user-default-low') !== false));
        if ($self && count($low) === count($self)) return itops_sa_r('pass', 'People can consent only to apps from verified publishers, for low-risk permissions.');
        if ($self) return itops_sa_r('warn', 'People can consent to apps under a custom consent policy (' . implode(', ', array_map('strval', array_diff($self, $low))) . '): check what it allows.');
        return itops_sa_r('pass', 'People cannot consent to apps; an admin decides.');
    }
    function itops_sa_c_users_create_apps(callable $D): array {
        $v = $D('authz')['defaultUserRolePermissions']['allowedToCreateApps'] ?? null;
        if ($v === null) return itops_sa_r('unknown', 'The setting was not in Microsoft\'s answer.');
        return $v ? itops_sa_r('fail', 'Anyone in the organization can register applications.') : itops_sa_r('pass', 'Only admins (and people with the Application Developer role) can register applications.');
    }
    function itops_sa_c_guest_invites(callable $D): array {
        $v = (string)($D('authz')['allowInvitesFrom'] ?? '');
        $label = ['none' => 'nobody', 'adminsAndGuestInviters' => 'admins and Guest Inviters', 'adminsGuestInvitersAndAllMembers' => 'admins, Guest Inviters and every member', 'everyone' => 'everyone, guests included'][$v] ?? $v;
        if ($v === '') return itops_sa_r('unknown', 'The setting was not in Microsoft\'s answer.');
        if ($v === 'everyone') return itops_sa_r('fail', 'Guests can be invited by ' . $label . '.');
        if ($v === 'adminsGuestInvitersAndAllMembers') return itops_sa_r('warn', 'Guests can be invited by ' . $label . '.');
        return itops_sa_r('pass', 'Guests can be invited by ' . $label . '.');
    }
    function itops_sa_c_users_create_tenants(callable $D): array {
        $v = $D('authz')['defaultUserRolePermissions']['allowedToCreateTenants'] ?? null;
        if ($v === null) return itops_sa_r('unknown', 'The setting was not in Microsoft\'s answer.');
        return $v ? itops_sa_r('fail', 'Anyone in the organization can create a new Microsoft tenant.') : itops_sa_r('pass', 'Only admins can create new tenants.');
    }
    /** Every secret and certificate of every app: [app, kind, name, start, end, days left]. */
    function itops_sa_app_creds(callable $D): array {
        $out = [];
        foreach ($D('apps') as $a) foreach ([['passwordCredentials', 'secret'], ['keyCredentials', 'certificate']] as [$k, $kind])
            foreach (($a[$k] ?? []) as $c) {
                $end = $c['endDateTime'] ?? null; if (!$end) continue;
                $out[] = ['app' => (string)($a['displayName'] ?? $a['appId'] ?? ''), 'kind' => $kind, 'name' => (string)($c['displayName'] ?? ''),
                          'start' => $c['startDateTime'] ?? null, 'end' => $end, 'left' => (int)floor((strtotime($end) - time()) / 86400)];
            }
        return $out;
    }
    function itops_sa_c_app_credentials(callable $D): array {
        $creds = itops_sa_app_creds($D);
        if (!$creds) return itops_sa_r('na', 'No app registration has a secret or certificate.');
        // an app with a valid credential next to an expired one: the expired one is clutter (warn); an app whose only
        // credentials are expired or expiring: something is about to break (fail)
        $by = []; foreach ($creds as $c) $by[$c['app']][] = $c;
        $aff = []; $worst = 'pass';
        foreach ($by as $app => $cs) {
            $valid = array_filter($cs, fn($c) => $c['left'] > 30);
            foreach ($cs as $c) {
                if ($c['left'] > 30) continue;
                $state = $c['left'] < 0 ? 'expired ' . (-$c['left']) . ' days ago' : 'expires in ' . $c['left'] . ' days';
                $aff[] = [$app, ucfirst($c['kind']) . ($c['name'] !== '' ? ' "' . $c['name'] . '"' : '') . ' ' . $state . ($valid ? ' (another one is still valid)' : '')];
                $worst = $valid ? ($worst === 'fail' ? 'fail' : 'warn') : 'fail';
            }
        }
        if (!$aff) return itops_sa_r('pass', 'No secret or certificate has expired or expires in the next 30 days (' . count($creds) . ' checked).');
        return itops_sa_r($worst, count($aff) . ' secret' . (count($aff) === 1 ? '' : 's') . ' or certificate' . (count($aff) === 1 ? ' has' : 's have') . ' expired or expire within 30 days.', $aff);
    }
    function itops_sa_c_app_long_secrets(callable $D): array {
        $s = array_values(array_filter(itops_sa_app_creds($D), fn($c) => $c['kind'] === 'secret' && $c['left'] >= 0));
        if (!$s) return itops_sa_r('na', 'No app registration has a valid secret.');
        $long = [];
        foreach ($s as $c) {
            $st = $c['start'] ? strtotime($c['start']) : false; $life = $st ? (int)floor((strtotime($c['end']) - $st) / 86400) : null;
            if ($life !== null && $life > 731) $long[] = [$c['app'], 'Secret' . ($c['name'] !== '' ? ' "' . $c['name'] . '"' : '') . ' lasts ' . round($life / 365, 1) . ' years'];
        }
        if (!$long) return itops_sa_r('pass', 'Every valid app secret lasts two years or less (' . count($s) . ' checked).');
        return itops_sa_r('warn', count($long) . ' app secret' . (count($long) === 1 ? ' lasts' : 's last') . ' longer than two years.', $long);
    }
    function itops_sa_c_spf(callable $D): array {
        $m = $D('mail'); if (!$m) return itops_sa_r('na', 'No verified domain sends email.');
        $bad = []; foreach ($m as $d) if (empty($d['spf']['ok']) || ($d['spf']['all'] ?? '') === '+') $bad[] = [$d['domain'], $d['spf']['advice'] ?: 'SPF is incomplete.'];
        return $bad ? itops_sa_r('fail', count($bad) . ' of ' . count($m) . ' sending domains have no correct SPF record.', $bad) : itops_sa_r('pass', 'SPF is correct on all ' . count($m) . ' sending domains.', array_map(fn($d) => [$d['domain'], 'SPF ok'], $m));
    }
    function itops_sa_c_dkim(callable $D): array {
        $m = $D('mail'); if (!$m) return itops_sa_r('na', 'No verified domain sends email.');
        $bad = []; foreach ($m as $d) if (empty($d['dkim']['ok'])) $bad[] = [$d['domain'], 'DKIM selector records are missing or do not point to Microsoft 365.'];
        return $bad ? itops_sa_r('fail', count($bad) . ' of ' . count($m) . ' sending domains have no DKIM signing set up.', $bad) : itops_sa_r('pass', 'DKIM is set up on all ' . count($m) . ' sending domains.', array_map(fn($d) => [$d['domain'], 'DKIM ok'], $m));
    }
    function itops_sa_c_dmarc(callable $D): array {
        $m = $D('mail'); if (!$m) return itops_sa_r('na', 'No verified domain sends email.');
        $miss = []; $mon = [];
        foreach ($m as $d) { $g = $d['dmarc']['grade'] ?? 'missing'; if ($g === 'missing') $miss[] = [$d['domain'], 'No DMARC record']; elseif ($g === 'monitor') $mon[] = [$d['domain'], 'DMARC is monitor-only (p=none)']; }
        if ($miss) return itops_sa_r('fail', count($miss) . ' of ' . count($m) . ' sending domains have no DMARC record' . ($mon ? ', ' . count($mon) . ' only monitor' : '') . '.', array_merge($miss, $mon));
        if ($mon) return itops_sa_r('warn', count($mon) . ' of ' . count($m) . ' sending domains only monitor (p=none): forged mail is still delivered.', $mon);
        return itops_sa_r('pass', 'DMARC is enforced (quarantine or reject) on all ' . count($m) . ' sending domains.', array_map(fn($d) => [$d['domain'], 'p=' . ($d['dmarc']['policy'] ?? '')], $m));
    }
    function itops_sa_c_devices_compliant(callable $D): array {
        $dv = $D('devices'); if (!$dv) return itops_sa_r('na', 'No devices are enrolled in Intune.');
        $bad = []; foreach ($dv as $d) if (in_array(strtolower((string)($d['complianceState'] ?? '')), ['noncompliant', 'error', 'conflict'], true)) $bad[] = [(string)($d['deviceName'] ?? ''), ($d['operatingSystem'] ?? '') . ' · ' . ($d['userPrincipalName'] ?? '') . ' · ' . ($d['complianceState'] ?? '')];
        if ($bad) return itops_sa_r('fail', count($bad) . ' of ' . count($dv) . ' managed devices are not compliant.', $bad);
        // Review SEC-25: devices whose state Intune does not know yet are a warning, not a pass
        $unk = []; foreach ($dv as $d) if (in_array(strtolower((string)($d['complianceState'] ?? '')), ['unknown', ''], true)) $unk[] = [(string)($d['deviceName'] ?? ''), ($d['operatingSystem'] ?? '') . ' · state not known yet'];
        return $unk ? itops_sa_r('warn', count($unk) . ' of ' . count($dv) . ' managed devices have no compliance state yet.', $unk) : itops_sa_r('pass', 'All ' . count($dv) . ' managed devices are compliant (or in their grace period).');
    }
    function itops_sa_c_devices_encrypted(callable $D): array {
        $pcs = array_values(array_filter($D('devices'), fn($d) => in_array(strtolower((string)($d['operatingSystem'] ?? '')), ['windows', 'macos'], true)));
        if (!$pcs) return itops_sa_r('na', 'No Windows or Mac computer is enrolled in Intune.');
        $bad = []; foreach ($pcs as $d) if (empty($d['isEncrypted'])) $bad[] = [(string)($d['deviceName'] ?? ''), ($d['operatingSystem'] ?? '') . ' · ' . ($d['userPrincipalName'] ?? '')];
        return $bad ? itops_sa_r('fail', count($bad) . ' of ' . count($pcs) . ' computers are not encrypted.', $bad) : itops_sa_r('pass', 'All ' . count($pcs) . ' computers are encrypted.');
    }
    function itops_sa_c_devices_checkin(callable $D): array {
        $dv = $D('devices'); if (!$dv) return itops_sa_r('na', 'No devices are enrolled in Intune.');
        $old = []; foreach ($dv as $d) { $n = itops_sa_days_since($d['lastSyncDateTime'] ?? null); if ($n === null || $n > 30) $old[] = [(string)($d['deviceName'] ?? ''), ($d['userPrincipalName'] ?? '') . ' · ' . ($n === null ? 'never checked in' : 'last check-in ' . $n . ' days ago')]; }
        return $old ? itops_sa_r('warn', count($old) . ' of ' . count($dv) . ' managed devices have not checked in for more than 30 days.', $old) : itops_sa_r('pass', 'All ' . count($dv) . ' managed devices checked in within 30 days.');
    }
    function itops_sa_c_secure_score(callable $D): array {
        $s = $D('score'); if (!$s || empty($s['maxScore'])) return itops_sa_r('unknown', 'Microsoft has not calculated a Secure Score for the tenant yet.');
        $pct = (int)round((float)$s['currentScore'] / (float)$s['maxScore'] * 100);
        $txt = 'Secure Score is ' . $pct . '% (' . round((float)$s['currentScore']) . ' of ' . round((float)$s['maxScore']) . ' points).';
        return itops_sa_r($pct >= 70 ? 'pass' : ($pct >= 50 ? 'warn' : 'fail'), $txt);
    }


    /* ─────────────────────────── BT116 (4a): the new rules ─────────────────────────── */

    /** Every page of a Graph list on v1.0 or beta (only graph.microsoft.com next links are followed). */
    function itops_sa_paged(string $path, string $ver = 'v1.0', int $max = 5): array {
        if (!function_exists('itops_graph_get')) require_once __DIR__ . '/Graph.php';
        $out = []; $p = $path; $pre = 'https://graph.microsoft.com/' . $ver . '/';
        for ($i = 0; $i < $max && $p !== ''; $i++) {
            $j = itops_graph_get($p, $ver);
            foreach (($j['value'] ?? []) as $v) $out[] = $v;
            $n = (string)($j['@odata.nextLink'] ?? ''); $p = ($n !== '' && strpos($n, $pre) === 0) ? substr($n, strlen($pre)) : '';
        }
        return $out;
    }
    function itops_sa_setting_json(PDO $db, string $key, $default) {
        try { $st = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $st->execute([$key]); $j = json_decode((string)$st->fetchColumn(), true); return is_array($j) ? $j : $default; }
        catch (Throwable $e) { return $default; }
    }
    /** Policies that are on or in report-only (both count when we ask "is X left out of every policy"). */
    function itops_sa_ca_live(callable $D): array { return array_values(array_filter($D('ca'), fn($p) => in_array($p['state'] ?? '', ['enabled', 'enabledForReportingButNotEnforced'], true))); }
    function itops_sa_ca_targets_admins(array $p): bool {
        $u = $p['conditions']['users'] ?? [];
        return in_array('All', $u['includeUsers'] ?? [], true) || in_array(ITOPS_SA_GA, array_map('strtolower', $u['includeRoles'] ?? []), true);
    }
    function itops_sa_ca_phish_resistant(array $p): bool {
        $s = $p['grantControls']['authenticationStrength'] ?? null; if (!$s) return false;
        if (substr(strtolower((string)($s['id'] ?? '')), -12) === '000000000004') return true;   // the built-in "Phishing-resistant MFA" strength
        $c = (array)($s['allowedCombinations'] ?? []);
        return $c && !array_diff($c, ['fido2', 'windowsHelloForBusiness', 'x509CertificateMultiFactor']);
    }
    function itops_sa_windows(callable $D): array { return array_values(array_filter($D('devices'), fn($d) => strtolower((string)($d['operatingSystem'] ?? '')) === 'windows')); }

    function itops_sa_c_break_glass(callable $D): array {
        $em = array_values(array_unique(array_filter(array_map(fn($x) => strtolower(trim((string)$x)), (array)$D('emergency')))));
        if (!$em) return itops_sa_r('fail', 'No emergency access account is marked in ' . itops_app_name() . ' (Posture → Tenant profile → Emergency access accounts).');
        $byUpn = []; foreach (($D('users')['rows'] ?? []) as $u) $byUpn[strtolower((string)($u['userPrincipalName'] ?? ''))] = $u;
        $ga = itops_sa_role_holders($D, [ITOPS_SA_GA]);
        $pol = null; try { $pol = itops_sa_ca_live($D); } catch (ItopsSaUnavailable $e) { if ($e->why !== 'license') throw $e; }   // no Conditional Access: nothing to be left out of
        $aff = []; $bad = false;
        foreach ($em as $upn) {
            $u = $byUpn[$upn] ?? null; $why = [];
            if (!$u) { $aff[] = [$upn, 'not found in the tenant']; $bad = true; continue; }
            $id = strtolower((string)$u['id']);
            if (empty($u['accountEnabled'])) $why[] = 'sign-in blocked';
            if (!isset($ga[$id])) $why[] = 'not a Global Administrator';
            if (!empty($u['onPremisesSyncEnabled'])) $why[] = 'synchronized from on-premises';
            if (stripos($upn, '.onmicrosoft.com') === false) $why[] = 'not on the onmicrosoft.com domain';
            foreach ((array)$pol as $p) {
                $x = $p['conditions']['users'] ?? []; if (in_array($id, array_map('strtolower', $x['excludeUsers'] ?? []), true)) continue;
                $inGroup = false;
                foreach (($x['excludeGroups'] ?? []) as $g) { try { if (in_array($id, $D('groupmembers:' . $g), true)) { $inGroup = true; break; } } catch (ItopsSaUnavailable $e) {} }
                if (!$inGroup) $why[] = 'not left out of "' . ($p['displayName'] ?? '') . '"';
            }
            $real = array_diff($why, ['not on the onmicrosoft.com domain']);
            if ($real) $bad = true;
            $aff[] = [(string)($u['displayName'] ?? $upn), $upn . ' · ' . ($why ? implode('; ', $why) : 'ready')];
        }
        if ($bad) return itops_sa_r('fail', 'An emergency access account is not ready: it cannot be relied on to get back in.', $aff);
        if (count($em) < 2) return itops_sa_r('warn', 'One emergency access account is ready; two are recommended, so losing one still leaves a way in.', $aff);
        return itops_sa_r('pass', count($em) . ' emergency access accounts: Global Administrators, cloud-only, left out of every Conditional Access policy.', $aff);
    }
    function itops_sa_c_admin_phish_resistant(callable $D): array {
        return itops_sa_ca_judge($D, fn($p) => itops_sa_ca_all_apps($p) && itops_sa_ca_targets_admins($p) && itops_sa_ca_phish_resistant($p),
            ['admins' => true, 'accept' => ['authenticationStrength'], 'does' => 'requires phishing-resistant sign-in for admins'], 'Conditional Access policy "{p}" requires phishing-resistant sign-in for the admin roles.', 'No enabled Conditional Access policy requires phishing-resistant sign-in (passkey, security key, Windows Hello) for the admin roles.');
    }
    function itops_sa_c_device_code_blocked(callable $D): array {
        if (itops_sa_secdef($D) === true) return itops_sa_r('pass', 'Security defaults are on: device code sign-in is blocked.');
        return itops_sa_ca_judge($D, fn($p) => strpos(strtolower((string)($p['conditions']['authenticationFlows']['transferMethods'] ?? '')), 'devicecodeflow') !== false && in_array('block', $p['grantControls']['builtInControls'] ?? [], true) && in_array('All', $p['conditions']['users']['includeUsers'] ?? [], true),
            ['does' => 'blocks device code sign-in'], 'Conditional Access policy "{p}" blocks device code sign-in for all users.', 'Security defaults are off and no enabled Conditional Access policy blocks device code sign-in.');
    }
    function itops_sa_c_risky_signin_policy(callable $D): array {
        return itops_sa_ca_judge($D, function ($p) { $lv = $p['conditions']['signInRiskLevels'] ?? []; return (in_array('high', $lv, true) || in_array('medium', $lv, true)) && in_array('All', $p['conditions']['users']['includeUsers'] ?? [], true) && (itops_sa_ca_requires_mfa($p) || in_array('block', $p['grantControls']['builtInControls'] ?? [], true)); },
            ['risk' => true, 'accept' => ['mfa', 'authenticationStrength', 'block'], 'does' => 'acts on risky sign-ins'], 'Conditional Access policy "{p}" challenges risky sign-ins for all users.', 'No enabled Conditional Access policy acts on sign-in risk for all users.');
    }
    function itops_sa_c_risky_user_policy(callable $D): array {
        return itops_sa_ca_judge($D, fn($p) => in_array('high', $p['conditions']['userRiskLevels'] ?? [], true) && in_array('All', $p['conditions']['users']['includeUsers'] ?? [], true) && array_intersect(['block', 'passwordChange', 'riskRemediation'], $p['grantControls']['builtInControls'] ?? []),
            ['risk' => true, 'does' => 'acts on high user risk'], 'Conditional Access policy "{p}" acts on high user risk for all users.', 'No enabled Conditional Access policy blocks or remediates high-risk users (all users).');
    }
    function itops_sa_c_compliant_device_admins(callable $D): array {
        return itops_sa_ca_judge($D, fn($p) => itops_sa_ca_all_apps($p) && itops_sa_ca_targets_admins($p) && array_intersect(['compliantDevice', 'domainJoinedDevice'], $p['grantControls']['builtInControls'] ?? []),
            ['admins' => true, 'accept' => ['compliantDevice', 'domainJoinedDevice'], 'does' => 'requires a compliant device for admins'], 'Conditional Access policy "{p}" requires a compliant device for the admin roles.', 'No enabled Conditional Access policy requires a compliant device for the admin roles.');
    }
    function itops_sa_c_compliant_device_all(callable $D): array {
        $ok = ['compliantDevice', 'domainJoinedDevice', 'approvedApplication', 'compliantApplication'];
        return itops_sa_ca_judge($D, fn($p) => itops_sa_ca_all_apps($p) && in_array('All', $p['conditions']['users']['includeUsers'] ?? [], true) && array_intersect($ok, $p['grantControls']['builtInControls'] ?? []),
            ['accept' => $ok, 'does' => 'requires a compliant device or protected app'], 'Conditional Access policy "{p}" requires a compliant device or protected app for everyone.', 'No enabled Conditional Access policy requires a compliant device (or app protection) for all users.');
    }
    function itops_sa_c_passkey_enabled(callable $D): array {
        foreach (($D('authm')['authenticationMethodConfigurations'] ?? []) as $c)
            if (strtolower((string)($c['id'] ?? '')) === 'fido2') return ($c['state'] ?? '') === 'enabled' ? itops_sa_r('pass', 'Passkeys (FIDO2) are allowed as a sign-in method.') : itops_sa_r('fail', 'Passkeys (FIDO2) are not allowed as a sign-in method.');
        return itops_sa_r('fail', 'Passkeys (FIDO2) are not set up as a sign-in method.');
    }
    function itops_sa_c_guest_access_restricted(callable $D): array {
        $v = strtolower((string)($D('authz')['guestUserRoleId'] ?? ''));
        if ($v === '') return itops_sa_r('unknown', 'The setting was not in Microsoft\'s answer.');
        if ($v === 'a0b1b346-4d3e-4e8b-98f8-753987be4970') return itops_sa_r('fail', 'Guests have the same directory access as members.');
        if ($v === '2af84b1e-32c8-42b7-82bc-daa82404023b') return itops_sa_r('pass', 'Guests only see their own directory objects (the most restricted setting).');
        return itops_sa_r('pass', 'Guests have limited directory access.');
    }
    function itops_sa_c_email_verified_join(callable $D): array {
        $v = $D('authz')['allowEmailVerifiedUsersToJoinOrganization'] ?? null;
        if ($v === null) return itops_sa_r('unknown', 'The setting was not in Microsoft\'s answer.');
        return $v ? itops_sa_r('fail', 'Anyone with a company email address can join the tenant by verifying it.') : itops_sa_r('pass', 'Joining the tenant by email verification is off.');
    }
    function itops_sa_c_self_service_signup(callable $D): array {
        $v = $D('authz')['allowedToSignUpEmailBasedSubscriptions'] ?? null;
        if ($v === null) return itops_sa_r('unknown', 'The setting was not in Microsoft\'s answer.');
        return $v ? itops_sa_r('warn', 'People can sign up for Microsoft subscriptions with their work email on their own.') : itops_sa_r('pass', 'Self-service sign-up for subscriptions is off.');
    }
    function itops_sa_c_admin_consent_workflow(callable $D): array {
        return !empty($D('acr')['isEnabled']) ? itops_sa_r('pass', 'People can ask an admin to approve an app.') : itops_sa_r('fail', 'The admin consent request workflow is off.');
    }
    function itops_sa_c_admins_cloud_only(callable $D): array {
        $holders = itops_sa_role_holders($D, itops_sa_high_templates());
        if (!$holders) return itops_sa_r('na', 'No user account holds an admin role.');
        $byId = []; foreach (($D('users')['rows'] ?? []) as $u) $byId[strtolower((string)$u['id'])] = $u;
        $sync = [];
        foreach ($holders as $id => [$m, $roles]) if (!empty($byId[$id]['onPremisesSyncEnabled'])) $sync[] = [(string)($m['displayName'] ?? ''), (string)$m['userPrincipalName'] . ' · ' . implode(', ', $roles)];
        return $sync ? itops_sa_r('fail', count($sync) . ' of ' . count($holders) . ' admin accounts are synchronized from on-premises.', $sync) : itops_sa_r('pass', 'All ' . count($holders) . ' admin accounts are cloud-only.');
    }
    function itops_sa_c_pim_used(callable $D): array {
        $el = $D('eligible'); $high = itops_sa_high_templates();
        $n = count(array_filter($el, fn($e) => in_array(strtolower((string)($e['roleDefinitionId'] ?? '')), $high, true)));
        if ($n) return itops_sa_r('pass', $n . ' admin role assignment' . ($n === 1 ? ' is' : 's are') . ' eligible (requested when needed) instead of permanent.');
        return itops_sa_r('fail', 'Every admin role is held permanently; none is assigned for when it is needed.');
    }
    function itops_sa_c_app_password_block(callable $D): array {
        $p = $D('appmgmt');
        $on = !empty($p['isEnabled']) && array_filter((array)($p['applicationRestrictions']['passwordCredentials'] ?? []), fn($r) => ($r['restrictionType'] ?? '') === 'passwordAddition' && ($r['state'] ?? 'enabled') !== 'disabled');
        return $on ? itops_sa_r('pass', 'Adding new password secrets to apps is blocked.') : itops_sa_r('fail', 'New password secrets can still be added to apps.');
    }
    function itops_sa_c_mailbox_audit(callable $D): array {
        $v = $D('exo_org')['AuditDisabled'] ?? null;
        if ($v === null) return itops_sa_r('unknown', 'Exchange did not report the setting.');
        return $v ? itops_sa_r('fail', 'Mailbox auditing is switched off for the organization.') : itops_sa_r('pass', 'Mailbox auditing is on.');
    }
    function itops_sa_c_exo_modern_auth(callable $D): array {
        $v = $D('exo_org')['OAuth2ClientProfileEnabled'] ?? null;
        if ($v === null) return itops_sa_r('unknown', 'Exchange did not report the setting.');
        return $v ? itops_sa_r('pass', 'Modern authentication is on for Exchange Online.') : itops_sa_r('fail', 'Modern authentication is off for Exchange Online.');
    }
    function itops_sa_c_exo_ews_off(callable $D): array {
        $v = $D('exo_org')['EwsEnabled'] ?? null;
        if ($v === null) return itops_sa_r('unknown', 'Exchange did not report whether Exchange Web Services is on.');   // review SEC-25: not reported is not a warning
        return $v === false ? itops_sa_r('pass', 'Exchange Web Services is off for the organization.') : itops_sa_r('warn', 'Exchange Web Services is available to every mailbox.');
    }
    function itops_sa_c_dmarc_reject(callable $D): array {
        $m = $D('mail'); if (!$m) return itops_sa_r('na', 'No verified domain sends email.');
        $not = []; foreach ($m as $d) if (strtolower((string)($d['dmarc']['policy'] ?? '')) !== 'reject') $not[] = [$d['domain'], 'p=' . (($d['dmarc']['policy'] ?? '') ?: 'none')];
        return $not ? itops_sa_r('warn', count($not) . ' of ' . count($m) . ' sending domains do not reject forged mail yet.', $not) : itops_sa_r('pass', 'DMARC rejects forged mail on all ' . count($m) . ' sending domains.');
    }
    function itops_sa_c_compliance_policy_platforms(callable $D): array {
        $dv = $D('devices'); if (!$dv) return itops_sa_r('na', 'No devices are enrolled in Intune.');
        $map = ['windows' => 'windows10compliancepolicy', 'macos' => 'macoscompliancepolicy', 'ios' => 'ioscompliancepolicy', 'android' => 'android'];
        $used = []; foreach ($dv as $d) { $os = strtolower((string)($d['operatingSystem'] ?? '')); if (isset($map[$os])) $used[$os] = true; }
        $have = [];
        foreach ($D('cpol') as $p) { if (empty($p['assignments'])) continue; $t = strtolower(preg_replace('/^#microsoft\.graph\./', '', (string)($p['@odata.type'] ?? '')));
            foreach ($map as $os => $prefix) if (strpos($t, $prefix) === 0) $have[$os] = true; }
        $miss = []; $L = ['windows' => 'Windows', 'macos' => 'macOS', 'ios' => 'iOS / iPadOS', 'android' => 'Android'];
        foreach (array_keys($used) as $os) if (empty($have[$os])) $miss[] = [$L[$os], 'devices enrolled, no assigned compliance policy'];
        return $miss ? itops_sa_r('fail', count($miss) . ' platform' . (count($miss) === 1 ? '' : 's') . ' in use without an assigned compliance policy.', $miss) : itops_sa_r('pass', 'Every platform in use (' . implode(', ', array_map(fn($o) => $L[$o], array_keys($used))) . ') has an assigned compliance policy.');
    }
    function itops_sa_c_secure_by_default(callable $D): array {
        $v = $D('dmsettings')['settings']['secureByDefault'] ?? null;
        if ($v === null) return itops_sa_r('unknown', 'Intune did not report the setting.');
        return $v ? itops_sa_r('pass', 'Devices with no compliance policy count as not compliant.') : itops_sa_r('fail', 'Devices with no compliance policy count as compliant.');
    }
    function itops_sa_c_laps_backed_up(callable $D): array {
        $w = itops_sa_windows($D); if (!$w) return itops_sa_r('na', 'No Windows computer is enrolled in Intune.');
        $have = []; foreach ($D('laps') as $c) $have[strtolower((string)($c['deviceName'] ?? ''))] = true;
        $miss = []; foreach ($w as $d) if (empty($have[strtolower((string)($d['deviceName'] ?? ''))])) $miss[] = [(string)($d['deviceName'] ?? ''), (string)($d['userPrincipalName'] ?? '')];
        if (!$miss) return itops_sa_r('pass', 'All ' . count($w) . ' Windows computers have a managed local admin password in Entra.');
        return itops_sa_r(count($miss) === count($w) ? 'fail' : 'warn', count($miss) . ' of ' . count($w) . ' Windows computers have no managed local admin password in Entra.', $miss);
    }
    function itops_sa_c_bitlocker_escrow(callable $D): array {
        $w = itops_sa_windows($D); if (!$w) return itops_sa_r('na', 'No Windows computer is enrolled in Intune.');
        $have = []; foreach ($D('bitlocker') as $k) $have[strtolower((string)($k['deviceId'] ?? ''))] = true;
        $miss = []; foreach ($w as $d) if (empty($have[strtolower((string)($d['azureADDeviceId'] ?? ''))])) $miss[] = [(string)($d['deviceName'] ?? ''), (string)($d['userPrincipalName'] ?? '')];
        if (!$miss) return itops_sa_r('pass', 'All ' . count($w) . ' Windows computers have a BitLocker recovery key in Entra.');
        return itops_sa_r(count($miss) === count($w) ? 'fail' : 'warn', count($miss) . ' of ' . count($w) . ' Windows computers have no BitLocker recovery key in Entra.', $miss);
    }
    /** An endpoint security (settings catalog) policy of one of $families, assigned. Older template-based ones are not read: not found = warning. */
    function itops_sa_espol(callable $D, array $families, string $what): array {
        if (!itops_sa_windows($D)) return itops_sa_r('na', 'No Windows computer is enrolled in Intune.');
        $hit = [];
        foreach ($D('espol') as $p) if (in_array((string)($p['templateReference']['templateFamily'] ?? ''), $families, true) && !empty($p['assignments'])) $hit[] = [(string)($p['name'] ?? ''), 'assigned'];
        if ($hit) return itops_sa_r('pass', 'Assigned ' . $what . ': ' . implode(', ', array_column($hit, 0)) . '.', $hit);
        return itops_sa_r('warn', 'No assigned ' . $what . ' was found among the Intune endpoint security policies (older template-based policies are not read yet: check those by hand).');
    }
    function itops_sa_c_update_rings(callable $D): array {
        if (!itops_sa_windows($D)) return itops_sa_r('na', 'No Windows computer is enrolled in Intune.');
        $r = []; foreach ($D('dconf') as $p) if (stripos((string)($p['@odata.type'] ?? ''), 'windowsUpdateForBusinessConfiguration') !== false && !empty($p['assignments'])) $r[] = [(string)($p['displayName'] ?? ''), 'assigned'];
        return $r ? itops_sa_r('pass', count($r) . ' Windows update ring' . (count($r) === 1 ? ' is' : 's are') . ' assigned.', $r) : itops_sa_r('fail', 'No Windows update ring is assigned.');
    }
    function itops_sa_c_windows_supported(callable $D): array {
        $w = itops_sa_windows($D); if (!$w) return itops_sa_r('na', 'No Windows computer is enrolled in Intune.');
        $old = [];
        foreach ($w as $d) { $v = explode('.', (string)($d['osVersion'] ?? '')); $b = (int)($v[2] ?? 0); if ($b > 0 && $b < 22000) $old[] = [(string)($d['deviceName'] ?? ''), 'Windows 10 (' . ($d['osVersion'] ?? '') . ') · ' . ($d['userPrincipalName'] ?? '')]; }
        return $old ? itops_sa_r('fail', count($old) . ' of ' . count($w) . ' Windows computers still run Windows 10, which no longer gets security updates.', $old) : itops_sa_r('pass', 'All ' . count($w) . ' Windows computers run Windows 11.');
    }
    function itops_sa_c_app_protection(callable $D): array {
        $prof = (array)$D('profile');
        $personal = array_filter($D('devices'), fn($d) => strtolower((string)($d['managedDeviceOwnerType'] ?? '')) === 'personal' && in_array(strtolower((string)($d['operatingSystem'] ?? '')), ['ios', 'android'], true));
        if (empty($prof['byod']) && !$personal) return itops_sa_r('na', 'The tenant profile says personal phones are not used for company email, and none are enrolled.');
        $m = $D('mam'); $miss = [];
        foreach (['ios' => 'iOS / iPadOS', 'android' => 'Android'] as $k => $l) if (!array_filter((array)($m[$k] ?? []), fn($p) => !empty($p['assignments']))) $miss[] = [$l, 'no assigned app protection policy'];
        return $miss ? itops_sa_r('fail', 'App protection is missing for ' . implode(' and ', array_column($miss, 0)) . '.', $miss) : itops_sa_r('pass', 'iOS and Android both have an assigned app protection policy.');
    }
    function itops_sa_c_spo_anyone_links(callable $D): array {
        $v = (string)($D('spo')['sharingCapability'] ?? '');
        if ($v === '') return itops_sa_r('unknown', 'SharePoint did not report the sharing setting.');
        return $v === 'externalUserAndGuestSharing' ? itops_sa_r('fail', '"Anyone" links are allowed: files can be shared with whoever gets the link.') : itops_sa_r('pass', '"Anyone" links are off.');
    }
    function itops_sa_c_spo_external_strict(callable $D): array {
        $v = (string)($D('spo')['sharingCapability'] ?? '');
        if ($v === '') return itops_sa_r('unknown', 'SharePoint did not report the sharing setting.');
        $L = ['disabled' => 'only people in the organization', 'existingExternalUserSharingOnly' => 'existing guests only', 'externalUserSharingOnly' => 'new and existing guests', 'externalUserAndGuestSharing' => 'anyone, with links'];
        return in_array($v, ['disabled', 'existingExternalUserSharingOnly'], true) ? itops_sa_r('pass', 'External sharing: ' . ($L[$v] ?? $v) . '.') : itops_sa_r('warn', 'External sharing: ' . ($L[$v] ?? $v) . '.');
    }

    /* ─────────── BT116 (4a): baselines, rule details, license fit, Microsoft's recommended actions ─────────── */

    /** The baselines, smallest first. A rule belongs to its own level and every bigger one. */
    function itops_sa_baselines(): array {
        $b = ['essentials' => ['Essentials', 'Any tenant, any license: the protections every organization needs.'],
                'standard'   => ['Business standard', 'Business Premium tenants: Essentials plus device, admin and email protections the license already includes.'],
                'strict'     => ['Strict', 'Regulated or high-risk organizations: Business standard plus the strongest settings, some needing Entra ID P2.']];
        // BT123 (4e): a house baseline (a consultant's own rule set, exported from one client and imported at the next)
        if ($h = itops_sa_house()) $b['house'] = [$h['name'], 'Your house baseline: ' . $b[$h['base']][0] . ($h['add'] ? ' plus ' . count($h['add']) . ' rule' . (count($h['add']) === 1 ? '' : 's') : '') . ($h['remove'] ? ', without ' . count($h['remove']) . ' rule' . (count($h['remove']) === 1 ? '' : 's') : '') . '.'];
        return $b;
    }
    /** BT123: the house baseline, or null. ['name', 'base', 'add' => [ids], 'remove' => [ids], 'notes' => [id => text], 'from', 'at']. */
    function itops_sa_house(bool $fresh = false): ?array {
        static $h = false;
        if ($h !== false && !$fresh) return $h;
        $h = null;
        try { $v = getDB()->query("SELECT sval FROM itops_settings WHERE skey='sa_house'")->fetchColumn(); $j = json_decode((string)$v, true); } catch (Throwable $e) { $j = null; }
        if (is_array($j) && !empty($j['name']) && in_array($j['base'] ?? '', ['essentials', 'standard', 'strict'], true)) {
            $cat = itops_sa_catalog();
            $h = ['name' => mb_substr((string)$j['name'], 0, 60), 'base' => $j['base'], 'add' => array_values(array_filter((array)($j['add'] ?? []), fn($x) => isset($cat[$x]))),
                  'remove' => array_values(array_filter((array)($j['remove'] ?? []), fn($x) => isset($cat[$x]))), 'notes' => array_filter((array)($j['notes'] ?? []), fn($v, $k) => isset($cat[$k]) && is_string($v), ARRAY_FILTER_USE_BOTH),
                  'from' => (string)($j['from'] ?? ''), 'at' => (string)($j['at'] ?? '')];
        }
        return $h;
    }
    function itops_sa_baseline_cur(PDO $db): string { try { $v = $db->query("SELECT sval FROM itops_settings WHERE skey='sa_baseline'")->fetchColumn(); } catch (Throwable $e) { $v = false; } return isset(itops_sa_baselines()[$v]) ? $v : 'standard'; }
    function itops_sa_in_baseline(string $level, string $baseline, ?string $id = null): bool {
        $o = ['essentials' => 0, 'standard' => 1, 'strict' => 2];
        if ($baseline === 'house') {   // BT123: the house baseline's own additions and removals, on top of its base
            $h = itops_sa_house(); if (!$h) $baseline = 'standard';
            else { if ($id !== null && in_array($id, $h['remove'], true)) return false; if ($id !== null && in_array($id, $h['add'], true)) return true; $baseline = $h['base']; }
        }
        return ($o[$level] ?? 2) <= ($o[$baseline] ?? 1);
    }
    /** What each license key means, and the Microsoft service plans that provide it. */
    function itops_sa_licenses(): array {
        return ['none'   => ['Every Microsoft 365 plan', []],
                'p1'     => ['Entra ID P1', ['AAD_PREMIUM', 'AAD_PREMIUM_P2']],
                'p2'     => ['Entra ID P2', ['AAD_PREMIUM_P2']],
                'intune' => ['Intune', ['INTUNE_A', 'INTUNE_EDU', 'INTUNE_A_VL']],
                'mdb'    => ['Defender for Business or Defender for Endpoint', ['MDE_SMB', 'MDE_LITE', 'WINDEFATP']],
                'exo'    => ['Exchange Online', ['EXCHANGE_S_STANDARD', 'EXCHANGE_S_ENTERPRISE', 'EXCHANGE_S_DESKLESS']],
                'spo'    => ['SharePoint Online', ['SHAREPOINTSTANDARD', 'SHAREPOINTENTERPRISE', 'SHAREPOINTDESKLESS']]];
    }
    /** From the tenant's subscriptions: key => true (owned) | false (not owned). null when they could not be read. */
    function itops_sa_owned(array $skus): array {
        $plans = [];
        foreach ($skus as $s) {
            if (strtolower((string)($s['capabilityStatus'] ?? 'Enabled')) !== 'enabled' || (int)($s['prepaidUnits']['enabled'] ?? 0) < 1) continue;
            foreach (($s['servicePlans'] ?? []) as $p) if (strtolower((string)($p['provisioningStatus'] ?? 'Success')) !== 'disabled') $plans[strtoupper((string)($p['servicePlanName'] ?? ''))] = true;
        }
        $out = [];
        foreach (itops_sa_licenses() as $k => [$label, $names]) $out[$k] = $k === 'none' ? true : (bool)array_filter($names, fn($n) => isset($plans[$n]));
        return $out;
    }
    /** Per rule: [baseline level, license key, effort, user impact, do-first rule ids]. Levels: essentials | standard | strict. */
    function itops_sa_meta(): array {
        $e = 'essentials'; $s = 'standard'; $x = 'strict';
        return [
            'mfa_registered' => [$e, 'none', 'medium', 'medium', []], 'mfa_required' => [$e, 'none', 'low', 'medium', ['break_glass', 'mfa_registered']],
            'legacy_auth' => [$e, 'none', 'low', 'low', ['break_glass']], 'admin_mfa_registered' => [$e, 'none', 'low', 'low', []],
            'admin_mfa_policy' => [$e, 'none', 'low', 'low', ['break_glass', 'admin_mfa_registered']], 'ca_report_only' => [$s, 'p1', 'low', 'low', []],
            'weak_methods' => [$s, 'none', 'low', 'medium', ['mfa_registered']], 'stale_users' => [$e, 'none', 'low', 'low', []], 'stale_guests' => [$s, 'none', 'low', 'low', []],
            'ga_count' => [$e, 'none', 'low', 'low', ['break_glass']], 'admin_separate' => [$s, 'none', 'medium', 'low', []],
            'user_consent' => [$e, 'none', 'low', 'low', ['admin_consent_workflow']], 'users_create_apps' => [$e, 'none', 'low', 'low', []], 'guest_invites' => [$s, 'none', 'low', 'low', []],
            'users_create_tenants' => [$e, 'none', 'low', 'low', []], 'app_credentials' => [$e, 'none', 'low', 'low', []], 'app_long_secrets' => [$s, 'none', 'low', 'low', []],
            'spf' => [$e, 'none', 'low', 'low', []], 'dkim' => [$e, 'none', 'low', 'low', []], 'dmarc' => [$e, 'none', 'low', 'low', ['spf', 'dkim']],
            'devices_compliant' => [$s, 'intune', 'medium', 'medium', ['compliance_policy_platforms']], 'devices_encrypted' => [$s, 'intune', 'medium', 'low', []],
            'devices_checkin' => [$s, 'intune', 'low', 'low', []], 'secure_score' => [$s, 'none', 'high', 'medium', []],
            'break_glass' => [$e, 'none', 'low', 'low', []], 'admin_phish_resistant' => [$s, 'p1', 'medium', 'medium', ['break_glass', 'passkey_enabled', 'admin_mfa_registered']],
            'device_code_blocked' => [$e, 'none', 'low', 'low', ['break_glass']], 'risky_signin_policy' => [$x, 'p2', 'low', 'medium', ['break_glass', 'mfa_required']],
            'risky_user_policy' => [$x, 'p2', 'low', 'medium', ['break_glass']], 'compliant_device_admins' => [$s, 'p1', 'medium', 'medium', ['break_glass', 'devices_compliant']],
            'compliant_device_all' => [$x, 'p1', 'high', 'high', ['compliant_device_admins', 'app_protection']], 'passkey_enabled' => [$s, 'none', 'low', 'low', []],
            'guest_access_restricted' => [$e, 'none', 'low', 'low', []], 'email_verified_join' => [$e, 'none', 'low', 'low', []], 'self_service_signup' => [$s, 'none', 'low', 'low', []],
            'admin_consent_workflow' => [$e, 'none', 'low', 'low', []], 'admins_cloud_only' => [$s, 'none', 'medium', 'low', []], 'pim_used' => [$x, 'p2', 'medium', 'medium', ['admin_separate']],
            'app_password_block' => [$s, 'none', 'low', 'medium', ['app_credentials']], 'mailbox_audit' => [$e, 'exo', 'low', 'low', []], 'exo_modern_auth' => [$e, 'exo', 'low', 'low', []],
            'exo_ews_off' => [$x, 'exo', 'medium', 'medium', []], 'dmarc_reject' => [$x, 'none', 'medium', 'medium', ['dmarc']],
            'compliance_policy_platforms' => [$s, 'intune', 'medium', 'medium', []], 'secure_by_default' => [$s, 'intune', 'low', 'medium', ['compliance_policy_platforms']],
            'laps_backed_up' => [$s, 'intune', 'medium', 'low', []], 'bitlocker_escrow' => [$s, 'intune', 'low', 'low', ['devices_encrypted']],
            'baseline_assigned' => [$s, 'intune', 'medium', 'medium', []], 'asr_assigned' => [$s, 'mdb', 'medium', 'medium', ['antivirus_assigned']],
            'firewall_assigned' => [$s, 'intune', 'low', 'low', []], 'antivirus_assigned' => [$s, 'mdb', 'low', 'low', []], 'edr_assigned' => [$s, 'mdb', 'low', 'low', []],
            'update_rings' => [$s, 'intune', 'low', 'low', []], 'windows_supported' => [$e, 'none', 'high', 'medium', []], 'app_protection' => [$s, 'intune', 'medium', 'medium', []],
            'spo_anyone_links' => [$s, 'spo', 'low', 'medium', []], 'spo_external_strict' => [$x, 'spo', 'low', 'high', ['spo_anyone_links']],
        ];
    }
    /** Microsoft's recommended actions (Secure Score controls) with this tenant's state. Rule ids where one of ours checks the same thing. */
    function itops_sa_ms_actions(callable $D): array {
        $match = ['mfaregistrationv2' => 'mfa_registered', 'adminmfav2' => 'admin_mfa_policy', 'blocklegacyauthentication' => 'legacy_auth', 'integratedapps' => 'user_consent',
                  'oneadmin' => 'ga_count', 'signinriskpolicy' => 'risky_signin_policy', 'userriskpolicy' => 'risky_user_policy'];
        $scores = [];
        try { foreach ((array)(($D('score') ?? [])['controlScores'] ?? []) as $c) $scores[strtolower((string)($c['controlName'] ?? ''))] = (float)($c['score'] ?? 0); } catch (ItopsSaUnavailable $e) {}
        $out = [];
        foreach ($D('controls') as $c) {
            if (!empty($c['deprecated'])) continue;
            $id = strtolower((string)($c['id'] ?? '')); $max = (float)($c['maxScore'] ?? 0); $got = $scores[$id] ?? null;
            $out[] = ['id' => (string)($c['id'] ?? ''), 'title' => (string)($c['title'] ?? ''), 'category' => (string)($c['controlCategory'] ?? ''), 'max' => $max, 'score' => $got,
                      'done' => $got !== null && $max > 0 && $got >= $max, 'impact' => (string)($c['userImpact'] ?? ''), 'cost' => (string)($c['implementationCost'] ?? ''), 'tier' => (string)($c['tier'] ?? ''),
                      'service' => (string)($c['service'] ?? ''), 'url' => (string)($c['actionUrl'] ?? ''), 'remediation' => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)($c['remediation'] ?? '')))), 0, 500),
                      'rule' => $match[$id] ?? null];
        }
        usort($out, fn($a, $b) => ($a['done'] <=> $b['done']) ?: (($b['max'] - (float)$b['score']) <=> ($a['max'] - (float)$a['score'])));
        return $out;
    }

    /* ─────────────────────────── running and storing ─────────────────────────── */

    function itops_sa_ready(PDO $db): bool { try { $db->query('SELECT 1 FROM itops_sa_runs LIMIT 1'); return true; } catch (Throwable $e) { return false; } }

    /** Score: weighted share of passed checks among those that could be judged (warn counts half). null when none could. */
    /** BT116: only the rules of $baseline (null = every rule) count, and not those whose license the tenant does not own. */
    function itops_sa_score(array $results, ?string $baseline = null, ?array $owned = null): ?int {
        $w = ['high' => 3, 'medium' => 2, 'low' => 1]; $cat = itops_sa_catalog(); $meta = itops_sa_meta(); $got = 0.0; $max = 0;
        foreach ($results as $id => $r) {
            if (!in_array($r['status'], ['pass', 'warn', 'fail'], true) || !isset($cat[$id])) continue;
            if ($baseline !== null && !itops_sa_in_baseline($meta[$id][0] ?? 'strict', $baseline, $id)) continue;
            if ($owned !== null && ($owned[$meta[$id][1] ?? 'none'] ?? null) === false) continue;
            $k = $w[$cat[$id][2]] ?? 1; $max += $k; $got += $r['status'] === 'pass' ? $k : ($r['status'] === 'warn' ? $k / 2 : 0);
        }
        return $max ? (int)round($got / $max * 100) : null;
    }
    /**
     * BT124 (code review SEC-13): how much of the baseline the score stands on — the weight of the rules that could be
     * read ÷ the weight of every licensed rule of the baseline, in %. A score with low coverage is not a better tenant.
     */
    function itops_sa_coverage(array $results, ?string $baseline = null, ?array $owned = null): ?int {
        $w = ['high' => 3, 'medium' => 2, 'low' => 1]; $cat = itops_sa_catalog(); $meta = itops_sa_meta(); $read = 0; $all = 0;
        foreach ($results as $id => $r) {
            if (!isset($cat[$id]) || ($r['status'] ?? '') === 'na') continue;
            if ($baseline !== null && !itops_sa_in_baseline($meta[$id][0] ?? 'strict', $baseline, $id)) continue;
            if ($owned !== null && ($owned[$meta[$id][1] ?? 'none'] ?? null) === false) continue;
            $k = $w[$cat[$id][2]] ?? 1; $all += $k; if (in_array($r['status'], ['pass', 'warn', 'fail'], true)) $read += $k;
        }
        return $all ? (int)round($read / $all * 100) : null;
    }

    /** Evaluate every check with the data fetcher. Returns id => result (+ 'why' for unknown). */
    /** BT106: the reader every check (and the risky-people list) shares within one run: each Microsoft resource read once. */
    function itops_sa_reader(callable $fetch): callable {
        $cache = [];
        return function (string $k) use (&$cache, $fetch) {
            if (!array_key_exists($k, $cache)) { try { $cache[$k] = ['ok', $fetch($k)]; } catch (Throwable $e) { $cache[$k] = ['err', itops_sa_classify($e)]; } }
            if ($cache[$k][0] === 'err') throw $cache[$k][1];
            return $cache[$k][1];
        };
    }
    function itops_sa_evaluate(callable $fetch, ?callable $D = null): array {
        $D = $D ?: itops_sa_reader($fetch);
        $out = [];
        foreach (itops_sa_catalog() as $id => $c) {
            try { $r = ($c[7])($D); }
            catch (ItopsSaUnavailable $e) { $r = ['status' => 'unknown', 'detail' => $e->getMessage(), 'affected' => [], 'why' => $e->why]; }
            catch (Throwable $e) { $u = itops_sa_classify($e); $r = ['status' => 'unknown', 'detail' => $u->getMessage(), 'affected' => [], 'why' => $u->why]; }
            $r['why'] = $r['why'] ?? ($r['status'] === 'unknown' ? 'error' : '');
            $out[$id] = $r;
        }
        return $out;
    }

    /**
     * One assessment run, stored. $via: manual | schedule. $fetch: null = Microsoft Graph. Returns the run row.
     * Only one run at a time (a second one is refused while the first is running).
     */
    function itops_sa_run(PDO $db, string $via, ?int $userId, ?callable $fetch = null): array {
        if (!itops_sa_ready($db)) throw new RuntimeException('The security assessment needs a database update: run db-update.php once.');
        if ((int)$db->query("SELECT GET_LOCK('itops_sa_run', 0)")->fetchColumn() !== 1) throw new RuntimeException('An assessment is already running. Try again in a minute.');
        try {
            @set_time_limit(300);
            $started = date('Y-m-d H:i:s');
            $D = itops_sa_reader($fetch ?: 'itops_sa_fetch_graph');
            $res = itops_sa_evaluate($fetch ?: 'itops_sa_fetch_graph', $D);
            // BT116 (4a): the baseline chosen at the time, the licenses the tenant owns, Microsoft's recommended actions.
            // The counts kept with the run are those of the baseline (licensed rules), like the score.
            $baseline = itops_sa_baseline_cur($db); $owned = null; $ms = null; $msErr = null; $licErr = null;
            try { $owned = itops_sa_owned($D('skus')); } catch (Throwable $e) { $licErr = itops_sa_classify($e)->getMessage(); }
            try { $ms = itops_sa_ms_actions($D); } catch (Throwable $e) { $msErr = itops_sa_classify($e)->getMessage(); }
            $meta = itops_sa_meta();
            $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'na' => 0, 'unknown' => 0];
            foreach ($res as $id => $r) {
                if (!itops_sa_in_baseline($meta[$id][0] ?? 'strict', $baseline, $id) || ($owned !== null && ($owned[$meta[$id][1] ?? 'none'] ?? null) === false)) continue;
                $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
            }
            $score = itops_sa_score($res, $baseline, $owned);
            $extra = json_encode(['baseline' => $baseline, 'owned' => $owned, 'owned_error' => $licErr, 'ms' => $ms, 'ms_error' => $msErr], JSON_UNESCAPED_UNICODE);
            $db->beginTransaction();   // review SEC-24: a run and its results are stored together, or not at all
            try {
            if (itops_sa_has_extra($db)) $db->prepare('INSERT INTO itops_sa_runs (started_at, finished_at, via, started_by, score, counts, extra) VALUES (?,?,?,?,?,?,?)')
               ->execute([$started, date('Y-m-d H:i:s'), $via, $userId, $score, json_encode($counts), $extra]);
            else $db->prepare('INSERT INTO itops_sa_runs (started_at, finished_at, via, started_by, score, counts) VALUES (?,?,?,?,?,?)')
               ->execute([$started, date('Y-m-d H:i:s'), $via, $userId, $score, json_encode($counts)]);
            $rid = (int)$db->lastInsertId();
            $ins = $db->prepare('INSERT INTO itops_sa_results (run_id, check_id, status, why, affected_count, affected, detail) VALUES (?,?,?,?,?,?,?)');
            foreach ($res as $id => $r) {
                $aff = array_map(fn($a) => [mb_substr((string)($a[0] ?? ''), 0, 200), mb_substr((string)($a[1] ?? ''), 0, 300)], $r['affected']);
                $ins->execute([$rid, $id, $r['status'], (string)$r['why'], count($aff), json_encode(array_slice($aff, 0, 500), JSON_UNESCAPED_UNICODE), mb_substr((string)$r['detail'], 0, 1000)]);
            }
            $db->commit();
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            // keep the last 60 runs
            $keep = $db->query('SELECT id FROM itops_sa_runs ORDER BY id DESC LIMIT 1 OFFSET 59')->fetchColumn();
            if ($keep) { $db->prepare('DELETE FROM itops_sa_results WHERE run_id < ?')->execute([$keep]); $db->prepare('DELETE FROM itops_sa_runs WHERE id < ?')->execute([$keep]); }
            // BT106: the people at risk, from the same reads, and the follow-up (messages, reminders, escalation, proposals)
            $risk = null;
            try { require_once __DIR__ . '/RiskUsers.php'; $risk = itops_rk_store($db, $rid, itops_rk_compute($D)); if (itops_rf_cfg($db)['enabled']) $risk['followup'] = itops_rf_process($db, $rid, $D); }
            catch (Throwable $e) { $risk = ['error' => $e->getMessage()]; if (function_exists('itops_log')) { try { itops_log('error', 'm365', 'Risky people list or follow-up failed: ' . $e->getMessage()); } catch (Throwable $x) {} } }
            // BT123 (4e): drift — rules that passed in the scan before and fail now (bell, Audit Log)
            $drift = null;
            try { require_once __DIR__ . '/SaProof.php'; $drift = count(itops_pf_drift_detect($db, $rid)); }
            catch (Throwable $e) { if (function_exists('itops_log')) { try { itops_log('error', 'm365', 'Security drift check failed: ' . $e->getMessage()); } catch (Throwable $x) {} } }
            return ['id' => $rid, 'score' => $score, 'counts' => $counts, 'risk' => $risk, 'drift' => $drift];
        } finally {
            $db->query("SELECT RELEASE_LOCK('itops_sa_run')");
        }
    }

    function itops_sa_has_extra(PDO $db): bool { static $h = null; if ($h === null) { try { $db->query('SELECT extra FROM itops_sa_runs LIMIT 1'); $h = true; } catch (Throwable $e) { $h = false; } } return $h; }
    /** BT116: what a run kept besides the results (baseline then, licenses owned, Microsoft's recommended actions). */
    function itops_sa_run_extra(PDO $db, int $runId): array {
        if (!itops_sa_has_extra($db)) return [];
        $s = $db->prepare('SELECT extra FROM itops_sa_runs WHERE id=?'); $s->execute([$runId]); $j = json_decode((string)$s->fetchColumn(), true); return is_array($j) ? $j : [];
    }
    /** BT116: the score and counts of a run's checks for one baseline (the rules in it, licensed). */
    function itops_sa_view_summary(array $checks): array {
        $c = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'na' => 0, 'unknown' => 0, 'unlicensed' => 0, 'outside' => 0]; $res = [];
        foreach ($checks as $k) {
            if (!$k['in_baseline']) { $c['outside']++; continue; }
            if ($k['unlicensed']) { $c['unlicensed']++; continue; }
            $c[$k['status']] = ($c[$k['status']] ?? 0) + 1; $res[$k['id']] = ['status' => $k['status']];
        }
        return ['score' => itops_sa_score($res), 'counts' => $c, 'coverage' => itops_sa_coverage($res)];
    }
    /** The checks of a run, with their catalog text and the status they had in the run before. BT116: and the rule details for $baseline. */
    function itops_sa_results(PDO $db, int $runId, ?string $baseline = null): array {
        $baseline = $baseline ?? itops_sa_baseline_cur($db); $extra = itops_sa_run_extra($db, $runId); $owned = $extra['owned'] ?? null;
        $meta = itops_sa_meta(); $lic = itops_sa_licenses(); $bl = itops_sa_baselines(); $cat0 = itops_sa_catalog();
        $prev = $db->prepare('SELECT id FROM itops_sa_runs WHERE id < ? ORDER BY id DESC LIMIT 1'); $prev->execute([$runId]); $pid = (int)$prev->fetchColumn();
        $before = [];
        if ($pid) { $s = $db->prepare('SELECT check_id, status FROM itops_sa_results WHERE run_id=?'); $s->execute([$pid]); $before = $s->fetchAll(PDO::FETCH_KEY_PAIR); }
        $s = $db->prepare('SELECT check_id, status, why, affected_count, affected, detail FROM itops_sa_results WHERE run_id=?'); $s->execute([$runId]);
        $got = []; foreach ($s->fetchAll() as $r) $got[$r['check_id']] = $r;
        $areas = itops_sa_areas(); $out = [];
        foreach (itops_sa_catalog() as $id => $c) {
            $r = $got[$id] ?? null;
            $out[] = ['id' => $id, 'area' => $c[0], 'area_label' => $areas[$c[0]] ?? $c[0], 'title' => $c[1], 'severity' => $c[2], 'license' => $c[3], 'permission' => $c[4],
                      'why_it_matters' => $c[5], 'fix' => $c[6],
                      'status' => $r ? $r['status'] : 'unknown', 'why' => $r ? $r['why'] : 'error', 'detail' => $r ? $r['detail'] : 'This check was not part of that run (it was added later).',
                      'affected_count' => $r ? (int)$r['affected_count'] : 0, 'affected' => $r ? (json_decode((string)$r['affected'], true) ?: []) : [],
                      'before' => $pid ? ($before[$id] ?? null) : null]
                    + (function () use ($id, $meta, $lic, $bl, $baseline, $owned, $cat0) {
                        $m = $meta[$id] ?? ['strict', 'none', 'medium', 'medium', []]; $own = is_array($owned) ? ($owned[$m[1]] ?? null) : null;
                        return ['level' => $m[0], 'level_label' => $bl[$m[0]][0] ?? $m[0], 'in_baseline' => itops_sa_in_baseline($m[0], $baseline, $id),
                                'lic' => $m[1], 'lic_label' => $lic[$m[1]][0] ?? $m[1], 'owned' => $own, 'unlicensed' => $own === false,
                                'effort' => $m[2], 'impact' => $m[3], 'deps' => array_values(array_map(fn($d) => [$d, $cat0[$d][1] ?? $d], array_filter($m[4], fn($d) => isset($cat0[$d]))))];
                    })();
        }
        return $out;
    }

    /** Is a scheduled run due? Setting sa_schedule: off | weekly (default) | daily. */
    function itops_sa_due(PDO $db): bool {
        $sch = itops_sa_schedule($db);
        // BT106: while the risky-people follow-up is on, the assessment runs daily (reminders and deadlines are days)
        try { require_once __DIR__ . '/RiskUsers.php'; if (itops_rf_cfg($db)['enabled']) $sch = 'daily'; } catch (Throwable $e) {}
        if ($sch === 'off') return false;
        $last = $db->query("SELECT MAX(started_at) FROM itops_sa_runs")->fetchColumn();
        if (!$last) return true;
        return (time() - strtotime((string)$last)) >= ($sch === 'daily' ? 86400 : 7 * 86400) - 600;
    }
    function itops_sa_schedule(PDO $db): string {
        try { $v = $db->query("SELECT sval FROM itops_settings WHERE skey='sa_schedule'")->fetchColumn(); } catch (Throwable $e) { $v = false; }
        return in_array($v, ['off', 'weekly', 'daily'], true) ? $v : 'weekly';
    }
}
