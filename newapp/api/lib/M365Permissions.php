<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT125: every permission the Microsoft 365 app registration needs, in one list, with what each one is for.
 * Shown in Settings → Company systems → Microsoft 365 → Permissions, with whether the tenant granted it (read from the
 * app's token). Kept current by the tests (perm125.php): every permission named anywhere in the code, the screens or the
 * help guide must be in this list — except the lists of risky permissions the app looks for on other apps (BT137).
 *
 * Each row: [permission (any one of "A|B" is enough), type, area, what it is for, need, note]
 *   type  Application (Microsoft Graph, application permission, admin consent) · Delegated (sign-in) ·
 *         Exchange (Office 365 Exchange Online API) · Entra role (a directory role given to the app) · Billing role (Azure)
 *   need  core (the Microsoft 365 area needs it) · feature (only that feature) · optional (nice to have)
 */
if (!function_exists('itops_m365_permission_catalog')) {
    function itops_m365_permission_catalog(): array {
        $A = 'Application';
        return [
            // ── Directory: people, groups, licenses
            ['User.Read.All|User.ReadWrite.All', $A, 'People', 'Read accounts: People → Accounts, onboarding checks, offboarding, the security rules; announcements to everyone in Microsoft 365 and to people found in it', 'core', ''],
            ['User.ReadWrite.All|Directory.ReadWrite.All', $A, 'People', 'Create accounts, edit contact information and manager, block and restore sign-in, assign and remove licenses', 'feature', 'Onboarding, People → Accounts'],
            ['User.EnableDisableAccount.All|User.ReadWrite.All|Directory.ReadWrite.All', 'Application', 'People', 'Block and restore sign-in', 'feature', 'People → Accounts, offboarding'],
            ['User.RevokeSessions.All|User.ReadWrite.All', $A, 'People', 'Sign a person out of every session', 'feature', 'People → Accounts, offboarding'],
            ['User-Phone.ReadWrite.All|User.ReadWrite.All', $A, 'People', 'Edit phone numbers', 'optional', ''],
            ['User.Invite.All|User.ReadWrite.All', $A, 'People', 'Resend guest invitations', 'optional', 'People → Guests'],
            ['UserAuthenticationMethod.ReadWrite.All', $A, 'People', 'Reset multifactor sign-in, Temporary Access Pass', 'feature', 'People → MFA'],
            ['LicenseAssignment.ReadWrite.All|User.ReadWrite.All', $A, 'Licenses', 'Assign and remove licenses', 'feature', 'People → Accounts, Licenses → Reclaim'],
            ['Organization.Read.All', $A, 'Licenses', 'Read the licenses and subscriptions the tenant owns', 'core', ''],
            ['Group.Read.All|Group.ReadWrite.All', $A, 'People', 'Read groups', 'core', 'People → Groups'],
            ['GroupMember.ReadWrite.All|Group.ReadWrite.All', $A, 'People', 'Add and remove group members', 'feature', 'People → Groups, onboarding, offboarding'],
            ['GroupMember.Read.All|Group.Read.All', $A, 'Announcements', 'Read the members of a group an announcement or a notice is sent to', 'feature', ''],
            ['Group.ReadWrite.All|Group.Create', $A, 'People', 'Create security groups, change group owners; the dynamic group of personal computers', 'optional', 'People → Groups; Devices → Personal computers'],
            ['Directory.Read.All', $A, 'Security', 'Enterprise apps and the consents given to them', 'feature', 'Security → Enterprise apps'],
            ['Domain.Read.All', $A, 'Email', 'Read the tenant\'s domains (email security, security rules)', 'core', ''],
            ['Application.Read.All', $A, 'Security', 'App registrations and their secrets\' end dates; Conditional Access templates; the side-by-side check of this app\'s own permissions (Permissions → Compare)', 'core', 'Security → App secrets'],
            ['DelegatedPermissionGrant.Read.All|Directory.Read.All', $A, 'Security', 'Whether the sign-in permissions have admin consent (Permissions → Compare)', 'optional', ''],
            // ── Reports and logs
            ['Reports.Read.All', $A, 'Reports', 'Usage reports (mailboxes, Teams, storage, Copilot) and the MFA registration report', 'core', ''],
            ['AuditLog.Read.All', $A, 'Reports', 'Last sign-in (idle accounts), the sign-in log, the Entra audit log', 'core', 'Needs Entra ID P1 for sign-in data'],
            ['ServiceHealth.Read.All', $A, 'Home', 'Microsoft 365 service health', 'optional', 'Home → Service health'],
            ['ServiceMessage.Read.All', $A, 'Home', 'Message center posts (changes Microsoft announces)', 'optional', ''],
            // ── Security and posture
            ['Policy.Read.All', $A, 'Security', 'Conditional Access, security defaults, tenant-wide settings, sign-in methods', 'core', ''],
            ['Policy.ReadWrite.ConditionalAccess', $A, 'Security', 'Create, switch and put back Conditional Access policies', 'feature', 'Security → Conditional Access'],
            ['Policy.ReadWrite.SecurityDefaults', $A, 'Security', 'Turn Security Defaults on or off (for example, off when Conditional Access takes over)', 'feature', 'Security → Conditional Access'],
            ['Policy.ReadWrite.AuthenticationMethod', $A, 'Security', 'Allow or stop a sign-in method tenant-wide', 'optional', ''],
            ['RoleManagement.Read.Directory', $A, 'Security', 'Read admin role assignments', 'core', 'Security → Admin roles'],
            ['RoleManagement.ReadWrite.Directory', $A, 'Security', 'Assign and remove admin roles', 'feature', 'Security → Admin roles'],
            ['RoleEligibilitySchedule.Read.Directory|RoleEligibilitySchedule.ReadWrite.Directory', $A, 'Security', 'Read roles held "when needed" (PIM)', 'feature', 'Needs Entra ID P2'],
            ['RoleEligibilitySchedule.ReadWrite.Directory', $A, 'Security', 'Make a role eligible instead of permanent', 'feature', 'Needs Entra ID P2'],
            ['SecurityEvents.Read.All', $A, 'Security', 'Secure Score and Microsoft\'s recommended actions', 'core', ''],
            ['SecurityAlert.Read.All|SecurityAlert.ReadWrite.All', $A, 'Security', 'Read Defender alerts', 'feature', 'Security → Alerts & actions'],
            ['SecurityAlert.ReadWrite.All', $A, 'Security', 'Resolve Defender alerts', 'feature', ''],
            ['SecurityIncident.Read.All|SecurityIncident.ReadWrite.All', $A, 'Security', 'Read Defender incidents', 'feature', ''],
            ['SecurityIncident.ReadWrite.All', $A, 'Security', 'Assign, comment on and resolve incidents', 'feature', ''],
            ['ThreatHunting.Read.All', $A, 'Security', 'Vulnerabilities and exposed devices; personal computers as Defender sees them (Defender advanced hunting, read only)', 'feature', 'Security → Threats & vulnerabilities; Devices → Personal computers'],
            ['ThreatIntelligence.Read.All', $A, 'Security', 'Microsoft\'s threat write-ups and the details of a vulnerability', 'optional', 'Security → Threats & vulnerabilities; Microsoft\'s pages differ on the license (Defender XDR, or Defender Threat Intelligence)'],
            ['IdentityRiskyUser.Read.All|IdentityRiskyUser.ReadWrite.All', $A, 'Security', 'Microsoft\'s risk for each person (risky people; Microsoft 365 → People → At risk → Microsoft\'s list)', 'optional', 'Needs Entra ID P2'],
            ['IdentityRiskyUser.ReadWrite.All', $A, 'Security', 'Dismiss a person\'s risk or confirm the account compromised (Microsoft\'s list)', 'optional', 'Needs Entra ID P2'],
            ['SharePointTenantSettings.Read.All', $A, 'Security', 'Sharing settings (security rules)', 'feature', ''],
            ['DirectoryRecommendations.Read.All', $A, 'Security', 'Entra recommendations', 'optional', ''],
            // ── Devices
            ['Device.Read.All|Device.ReadWrite.All', $A, 'Devices', 'Entra devices (device inventory)', 'core', ''],
            ['Device.ReadWrite.All', $A, 'Devices', 'Disable, enable and remove Entra devices', 'feature', ''],
            ['DeviceManagementManagedDevices.Read.All', $A, 'Devices', 'Intune devices and the software found on them', 'core', 'Needs Intune'],
            ['DeviceManagementManagedDevices.PrivilegedOperations.All', $A, 'Devices', 'Restart, Defender scan, retire, wipe, fresh start, remote lock', 'feature', 'Devices → Wipe & retire'],
            ['DeviceManagementConfiguration.Read.All', $A, 'Devices', 'Compliance policies, configuration profiles, update rings', 'core', ''],
            ['DeviceManagementConfiguration.ReadWrite.All', $A, 'Devices', 'Change policies and update rings; device security profiles', 'feature', ''],
            ['DeviceManagementServiceConfig.Read.All', $A, 'Devices', 'Enrollment restrictions', 'feature', ''],
            ['DeviceManagementApps.Read.All', $A, 'Devices', 'Intune apps, app protection policies, the Intune audit log (security drift)', 'feature', ''],
            ['DeviceManagementApps.ReadWrite.All', $A, 'Devices', 'Change app protection policies', 'optional', ''],
            ['BitlockerKey.Read.All|BitlockerKey.ReadBasic.All', $A, 'Devices', 'BitLocker recovery keys (shown for 2 minutes, audited)', 'feature', ''],
            ['DeviceLocalCredential.Read.All|DeviceLocalCredential.ReadBasic.All', $A, 'Devices', 'Local admin passwords (LAPS)', 'feature', ''],
            // ── Email, calendar, files
            ['Mail.ReadWrite', $A, 'Service desk', 'Read the help desk mailbox and mark messages read (email to ticket)', 'feature', 'Settings → Email (Microsoft 365 mailbox)'],
            ['Mail.Send', $A, 'Service desk', 'Send the app\'s email through Microsoft 365', 'feature', 'Settings → Email'],
            ['MailboxSettings.Read|MailboxSettings.ReadWrite', $A, 'Email', 'Read inbox rules that forward mail outside the company', 'feature', 'Email → Email security'],
            ['MailboxSettings.ReadWrite', $A, 'Onboarding', 'Automatic replies and forwarding when someone leaves; turn off a forwarding rule', 'feature', 'Offboarding; Email → Email security'],
            ['Calendars.ReadWrite', $A, 'Calendar', 'Two-way sync with people\'s Outlook calendars', 'feature', 'Calendar'],
            ['Files.ReadWrite.All|Sites.ReadWrite.All', $A, 'Onboarding', 'Hand over a leaver\'s OneDrive to their manager', 'feature', 'Offboarding'],
            ['ExchangeMessageTrace.Read.All', $A, 'Email', 'Message trace', 'feature', 'Email → Message trace'],
            ['Exchange.ManageAsAppV2', 'Exchange', 'Email', 'Mailboxes, send-on-behalf and calendar access (Exchange Admin API, preview)', 'feature', 'Also give the app the Exchange role "Recipient Management"'],
            ['Recipient Management', 'Exchange role', 'Email', 'Lets the app change mailbox delegates', 'feature', 'Exchange admin center → Roles'],
            ['User Administrator', 'Entra role', 'People', 'Lets the app reset passwords (Microsoft requires a directory role for that)', 'feature', 'Entra admin center → Roles → User Administrator → add the app'],
            // ── Teams
            ['TeamsActivity.Send', $A, 'Teams', 'Notifications in the Teams app', 'feature', 'Microsoft Teams app'],
            ['TeamsActivity.Send.User', 'Teams app', 'Teams', 'Notifications to one person (asked by the Teams app package itself; granted when the app is added in Teams)', 'feature', 'Nothing to add in Entra'],
            // ── Billing
            ['Billing profile reader|Invoice manager', 'Billing role', 'Invoices', 'Read Microsoft invoices (Microsoft Customer Agreement)', 'feature', 'Azure → Cost Management + Billing → the billing profile → Access control'],
            // ── Sign-in with Microsoft
            ['openid|profile|email|User.Read', 'Delegated', 'Sign-in', 'Sign in with Microsoft and in Teams', 'feature', 'Add the redirect address under Authentication'],
        ];
    }

    /**
     * The catalog with what the tenant granted. $roles = the application roles in the app's token (null = not known).
     * Rows of types other than Application cannot be read from the token: granted = null.
     */
    function itops_m365_permission_status(?array $roles): array {
        $have = $roles === null ? null : array_map('strtolower', $roles);
        $out = [];
        foreach (itops_m365_permission_catalog() as [$perm, $type, $area, $for, $need, $note]) {
            $any = explode('|', $perm); $granted = null;
            if ($type === 'Application' && $have !== null) { $granted = false; foreach ($any as $p) if (in_array(strtolower($p), $have, true)) { $granted = true; break; } }
            $out[] = ['permission' => $any[0], 'alternatives' => array_slice($any, 1), 'type' => $type, 'area' => $area, 'for' => $for, 'need' => $need, 'note' => $note, 'granted' => $granted];
        }
        return $out;
    }

    /* ── BT145: side by side — what the app needs (this list) against what Microsoft 365 has for this app registration ──
     * Read from Microsoft, not only from the token: the app's service principal's appRoleAssignments (application
     * permissions with admin consent), the app registration's requiredResourceAccess (added but maybe not consented),
     * oauth2PermissionGrants (admin consent for the sign-in permissions) and the directory roles given to the app. Each
     * needed row gets one state and the next step; whatever the app holds but does not use is listed apart (least privilege).
     * Reads need Application.Read.All (+ RoleManagement.Read.Directory, DelegatedPermissionGrant.Read.All); a read that is
     * refused leaves its part "unknown" and says why. */
    function itops_m365_resource_apps(): array {
        return ['00000003-0000-0000-c000-000000000000' => 'Microsoft Graph', '00000002-0000-0ff1-ce00-000000000000' => 'Office 365 Exchange Online'];
    }
    function itops_m365_permission_compare(?array $tokenRoles, string $clientId): array {
        if (!function_exists('itops_graph_get')) require_once __DIR__ . '/Graph.php';
        $why = []; $low = fn(array $a) => array_values(array_unique(array_map('strtolower', $a)));
        $tok = $tokenRoles === null ? null : $low($tokenRoles);
        $denied = fn(Throwable $e) => (bool)preg_match('/authoriz|privilege|forbidden|403|denied|permission/i', $e->getMessage());
        $q = fn(string $s) => rawurlencode($s);
        // the app's service principal and app registration
        $sp = null; $app = null;
        try { $sp = (itops_graph_get('servicePrincipals?$filter=' . $q("appId eq '" . $clientId . "'") . '&$select=id,appId,displayName')['value'] ?? [])[0] ?? null; }
        catch (Throwable $e) { $why['consent'] = $denied($e) ? 'Needs Application.Read.All to read what has admin consent.' : 'Could not read the app: ' . mb_substr($e->getMessage(), 0, 160); }
        try { $app = (itops_graph_get('applications?$filter=' . $q("appId eq '" . $clientId . "'") . '&$select=id,displayName,requiredResourceAccess')['value'] ?? [])[0] ?? null; }
        catch (Throwable $e) { $why['requested'] = $denied($e) ? 'Needs Application.Read.All to read the app registration.' : 'Could not read the app registration: ' . mb_substr($e->getMessage(), 0, 160); }
        // names of each resource's app roles and delegated scopes
        $res = [];
        foreach (itops_m365_resource_apps() as $rid => $rname) {
            try { $r = (itops_graph_get('servicePrincipals?$filter=' . $q("appId eq '" . $rid . "'") . '&$select=id,appId,displayName,appRoles,oauth2PermissionScopes')['value'] ?? [])[0] ?? null; }
            catch (Throwable $e) { $r = null; }
            if (!$r) continue;
            $roles = []; foreach (($r['appRoles'] ?? []) as $x) $roles[strtolower((string)($x['id'] ?? ''))] = (string)($x['value'] ?? '');
            $scopes = []; foreach (($r['oauth2PermissionScopes'] ?? []) as $x) $scopes[strtolower((string)($x['id'] ?? ''))] = (string)($x['value'] ?? '');
            $res[$rid] = ['id' => strtolower((string)$r['id']), 'name' => $rname, 'roles' => $roles, 'scopes' => $scopes];
        }
        $resBySp = []; foreach ($res as $rid => $r) $resBySp[$r['id']] = $rid;
        // consented application permissions
        $consented = null; $consentedRes = [];
        if ($sp) {
            try { $consented = [];
                foreach (itops_graph_paged('servicePrincipals/' . rawurlencode((string)$sp['id']) . '/appRoleAssignments?$top=999', 5) as $a) {
                    $rid = $resBySp[strtolower((string)($a['resourceId'] ?? ''))] ?? null; if (!$rid) continue;
                    $n = $res[$rid]['roles'][strtolower((string)($a['appRoleId'] ?? ''))] ?? ''; if ($n === '') continue;
                    $consented[] = strtolower($n); $consentedRes[strtolower($n)] = $res[$rid]['name'];
                } }
            catch (Throwable $e) { $consented = null; $why['consent'] = $denied($e) ? 'Needs Application.Read.All to read what has admin consent.' : 'Could not read the consented permissions: ' . mb_substr($e->getMessage(), 0, 160); }
        } elseif (!isset($why['consent'])) $why['consent'] = 'The app registration was not found in this tenant (check the client ID).';
        // requested (added under API permissions)
        $reqRoles = null; $reqScopes = null; $reqRes = [];
        if ($app) { $reqRoles = []; $reqScopes = [];
            foreach (($app['requiredResourceAccess'] ?? []) as $rr) { $rid = strtolower((string)($rr['resourceAppId'] ?? '')); if (!isset($res[$rid])) continue;
                foreach (($rr['resourceAccess'] ?? []) as $ra) { $id = strtolower((string)($ra['id'] ?? ''));
                    if (($ra['type'] ?? '') === 'Role') { $n = $res[$rid]['roles'][$id] ?? ''; if ($n !== '') { $reqRoles[] = strtolower($n); $reqRes[strtolower($n)] = $res[$rid]['name']; } }
                    else { $n = $res[$rid]['scopes'][$id] ?? ''; if ($n !== '') $reqScopes[] = strtolower($n); } } } }
        elseif (!isset($why['requested'])) $why['requested'] = 'The app registration was not found in this tenant (check the client ID).';
        // admin consent for delegated (sign-in) permissions
        $granted = null;
        if ($sp) { try { $granted = [];
                foreach (itops_graph_paged('oauth2PermissionGrants?$filter=' . $q("clientId eq '" . $sp['id'] . "'"), 2) as $g) if (($g['consentType'] ?? '') === 'AllPrincipals') foreach (preg_split('/\s+/', trim((string)($g['scope'] ?? ''))) as $sc) if ($sc !== '') $granted[] = strtolower($sc); }
            catch (Throwable $e) { $granted = null; $why['signin'] = $denied($e) ? 'Needs DelegatedPermissionGrant.Read.All to see the admin consent of the sign-in permissions.' : 'Could not read the sign-in consent.'; } }
        // directory roles given to the app
        $dirRoles = null;
        if ($sp) { try { $dirRoles = [];
                foreach ((itops_graph_get('roleManagement/directory/roleAssignments?$filter=' . $q("principalId eq '" . $sp['id'] . "'") . '&$expand=roleDefinition')['value'] ?? []) as $a)
                    if (strcasecmp((string)($a['principalId'] ?? ''), (string)$sp['id']) === 0 && !empty($a['roleDefinition']['displayName'])) $dirRoles[] = strtolower((string)$a['roleDefinition']['displayName']); }
            catch (Throwable $e) { $dirRoles = null; $why['roles'] = $denied($e) ? 'Needs RoleManagement.Read.Directory to see the directory roles given to the app.' : 'Could not read the directory roles.'; } }

        $rows = []; $used = [];
        $nextAdd = 'Add it: App registrations → the app → API permissions → Add a permission → Microsoft Graph → Application permissions; then Grant admin consent.';
        foreach (itops_m365_permission_catalog() as [$perm, $type, $area, $for, $need, $note]) {
            $any = explode('|', $perm); $anyL = array_map('strtolower', $any); foreach ($anyL as $x) $used[$x] = true;
            $state = 'manual'; $holds = ''; $next = $note;
            if ($type === 'Application' || $type === 'Exchange') {
                $inC = $consented === null ? null : array_values(array_intersect($anyL, $consented));
                $inT = $tok === null ? null : array_values(array_intersect($anyL, $tok));
                $inR = $reqRoles === null ? null : array_values(array_intersect($anyL, $reqRoles));
                if ($inC) { $holds = $inC[0]; if ($tok !== null && !$inT) { $state = 'refresh'; $next = 'Admin consent is given; the app\'s token does not have it yet.'; } else { $state = 'granted'; $next = ''; } }
                elseif ($consented === null && $inT) { $holds = $inT[0]; $state = 'granted'; $next = ''; }
                elseif ($inR) { $holds = $inR[0]; $state = 'consent'; $next = 'Added, without admin consent.'; }
                elseif ($consented === null && $reqRoles === null && $tok === null) { $state = 'unknown'; $next = ''; }
                else { $state = 'missing'; $next = $type === 'Exchange' ? 'Not added. Add it under APIs my organization uses → Office 365 Exchange Online → Application permissions.' . ($note ? ' ' . $note . '.' : '') : 'Not added under API permissions.'; }
            } elseif ($type === 'Delegated') {
                $missR = $reqScopes === null ? null : array_values(array_diff($anyL, $reqScopes));
                $missG = $granted === null ? null : array_values(array_diff($anyL, $granted));
                if ($missR === null && $missG === null) { $state = 'unknown'; $next = ''; }
                elseif ($missR) { $state = 'missing'; $next = 'Add the delegated permissions ' . implode(', ', array_map(fn($x) => $any[array_search($x, $anyL)], $missR)) . ': API permissions → Microsoft Graph → Delegated permissions; then Grant admin consent.'; }
                elseif ($missG) { $state = 'consent'; $next = 'Added; admin consent missing for ' . implode(', ', $missG) . ': Grant admin consent for the organization.'; }
                else { $state = $missG === null ? 'requested' : 'granted'; $next = $missG === null ? 'Added. Whether it has admin consent could not be read.' : ''; }
                $holds = implode(', ', $any);
            } elseif ($type === 'Entra role') {
                if ($dirRoles === null) { $state = 'unknown'; $next = $note; }
                elseif (array_intersect($anyL, $dirRoles)) { $state = 'granted'; $next = ''; $holds = $any[0]; }
                else { $state = 'missing'; $next = $note; }
            }
            $rows[] = ['permission' => $any[0], 'alternatives' => array_slice($any, 1), 'type' => $type, 'area' => $area, 'for' => $for, 'need' => $need, 'state' => $state, 'holds' => $holds, 'next' => $next];
        }
        // held (consented or requested) but not used by the app
        $hi = class_exists('ItopsM365') && defined('ItopsM365::HIGH_APP_PERMISSIONS') ? array_map('strtolower', ItopsM365::HIGH_APP_PERMISSIONS) : [];
        $extra = [];
        foreach (array_unique(array_merge($consented ?? [], $reqRoles ?? [])) as $n) {
            if (isset($used[$n])) continue;
            $proper = $n; foreach (array_merge(...array_map(fn($r) => array_values($r['roles']), array_values($res))) as $v) if (strtolower($v) === $n) { $proper = $v; break; }
            $extra[] = ['permission' => $proper, 'api' => $consentedRes[$n] ?? $reqRes[$n] ?? 'Microsoft Graph', 'consented' => in_array($n, $consented ?? [], true), 'high' => in_array($n, $hi, true)];
        }
        usort($extra, fn($a, $b) => ($b['high'] <=> $a['high']) ?: strcasecmp($a['permission'], $b['permission']));
        $cnt = []; foreach ($rows as $r) $cnt[$r['state']] = ($cnt[$r['state']] ?? 0) + 1;
        return ['app' => $sp ? ['name' => (string)($sp['displayName'] ?? ''), 'client_id' => $clientId] : null, 'rows' => $rows, 'extra' => $extra, 'counts' => $cnt, 'unread' => $why,
                'how' => ['missing' => $nextAdd, 'consent' => 'In the same API permissions page press Grant admin consent for the organization (a Global Administrator or Privileged Role Administrator can).', 'refresh' => 'Microsoft 365 → Home → Diagnose → Refresh token (or wait up to an hour).'],
                'portal' => 'https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationMenuBlade/~/CallAnAPI/appId/' . rawurlencode($clientId)];
    }

}
