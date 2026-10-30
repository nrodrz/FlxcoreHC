<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps BT46 — the reference shown on a company system's set-up page: where its API key is made at the vendor, what
 * the key must be allowed to do, and what ITOps does with the connection.
 *
 * The steps come from each vendor's own help pages, read on the date in 'checked'. Vendors move their menus: when a
 * step no longer matches, follow the link in 'sources' and correct the text here. Nothing in this file is a secret and
 * nothing here calls another system.
 *
 *   status  'available' = this build can connect to the system (a Connection box is on the same page)
 *           'planned'   = a connection is planned; the system is tracked by hand until then, and the steps are kept
 *                         here so the key can be prepared (or simply so it is known where it comes from)
 */
if (!function_exists('itops_sys_guide')) {

    function itops_sys_guides(): array {
        $app = 'this app';
        try { if (!function_exists('itops_app_name')) { $f = __DIR__ . '/Brand.php'; if (is_file($f)) require_once $f; } if (function_exists('itops_app_name')) $app = itops_app_name(); } catch (Throwable $e) {}
        return [
            'justcall' => [
                'status' => 'available',
                'state' => $app . ' can connect to JustCall in this build, read only: the users, the calls and activity of each person, and missed calls and voicemails as tickets. Paste the key and the secret in the "Connection" box on this page.',
                'who' => 'In JustCall only the Account Owner and Admins see the API credentials (not Team Members or custom roles). They are part of every JustCall plan.',
                'steps' => [
                    'Sign in to JustCall in a web browser (the mobile and desktop apps do not show the credentials).',
                    'Click the user icon at the top right and choose "APIs and Webhooks".',
                    'In the section "API Credentials", click the copy icon next to "API Key".',
                    'Click the eye icon to show the "API Secret", then its copy icon.',
                    'Paste both in the "Connection" box on this page and click "Save the key and test".',
                ],
                'mind' => [
                    'JustCall has one key and one secret per account. If another app of yours already uses it, both apps count against the same hourly limit.',
                    'Do not click "Reset Credentials" unless you mean it: the old pair stops working at once, in every app that uses it.',
                    'JustCall\'s API cannot add or remove a user. Those two stay by hand in JustCall; ' . $app . ' checks afterwards by reading.',
                ],
                'gives' => [
                    'Reads every JustCall user: name, email, role, group, extension, the numbers they own and share, last sign-in.',
                    'Matches each user to a person by the exact email, and flags a user whose person was blocked in Microsoft 365.',
                    'Onboarding: the step is done when JustCall lists the new person. Offboarding: the step is done when JustCall no longer lists them. "Verify offboarding" asks JustCall again.',
                    'Users who have not signed in for 90 days are flagged: a seat that may no longer be needed.',
                    'Calls and activity: for a period of up to 92 days, the calls by line, by person and by day (answered, missed, average talk time).',
                    'Missed calls → tickets: on the lines you choose, a missed call, a voicemail (with JustCall\'s transcription when there is one) or an abandoned call opens a ticket; the same caller again while it is open adds a note.',
                ],
                'later' => [],
                'checked' => '2026-10-07',
                'sources' => [['JustCall Help: How to access and copy API credentials', 'https://help.justcall.io/en/articles/12096339-how-to-access-and-copy-api-credentials-in-justcall']],
            ],
            'hubspot' => [
                'status' => 'available',
                'state' => $app . ' can connect to HubSpot in this build, read only: it reads the users, their roles, teams and seats. Paste the service key in the "Connection" box on this page. Creating and removing users is planned for a later step.',
                'who' => 'A HubSpot Super Admin, or a user with the "Developer tools access" permission.',
                'steps' => [
                    'In HubSpot open "Development" → "Keys" → "Service keys" (HubSpot also names the path Settings → Integrations → Service Keys).',
                    'Click "Create service key" and give it a name that says who uses it, for example "' . $app . ' user access".',
                    'Click "Add new scope" and tick the scopes listed under "The key must be allowed to". Click "Update".',
                    'Click "Create" and confirm.',
                    'Open the key, click "Show" and then "Copy". It starts with "pat-". Paste it in the "Connection" box on this page and click "Save the key and test".',
                ],
                'scopes' => [
                    ['settings.users.read', 'read the users and their roles (the HubSpot tab, and a person\'s HubSpot user under People)'],
                    ['settings.users.teams.read', 'read the teams, so a person\'s teams show by name and can be changed'],
                    ['settings.users.write', 'change a person\'s name, role and teams under People, and remove the user (People → Offboard, or the offboarding step)'],
                    ['crm.objects.owners.read', 'read who owns records, to count what a leaver still owns'],
                    ['crm.objects.users.read / crm.objects.users.write', 'later: named by HubSpot for its user calls'],
                    ['settings.billing.write', 'later, only if seats are assigned from here'],
                ],
                'mind' => [
                    'Use a service key, not a "private app": HubSpot stops the creation of new private apps on October 26, 2026 for existing accounts. Private apps that already exist keep working.',
                    'Give this key its own name and only the scopes above. Do not reuse a token another app uses: then either app can be cut off without touching the other.',
                    'To replace a key, use "Rotate" → "Rotate and expire later": the old key keeps working for seven days.',
                    'In HubSpot, removing a user cannot be undone and does not move their records. Reassign the records first.',
                ],
                'gives' => [
                    'A HubSpot tab with every user, Super Admin or role, team and seat, matched to people by email.',
                    'On each person (People → the account → Systems): their HubSpot user, found by email as soon as it exists; Edit HubSpot info (name, role, teams) and Remove from HubSpot. A HubSpot Super Admin is never removed from here.',
                    'Offboarding: "Remove HubSpot user" when offboarding a person, and "Remove from HubSpot now" on the offboarding step. Adding people to HubSpot stays in HubSpot.',
                    'Not yet: moving a leaver\'s records to a colleague before the removal (do it in HubSpot first).',
                ],
                'checked' => '2026-10-25',
                'sources' => [['HubSpot Developers: Account service keys', 'https://developers.hubspot.com/docs/apps/developer-platform/build-apps/authentication/account-service-keys'],
                              ['HubSpot API: update a user (settings/v3/users)', 'https://developers.hubspot.com/docs/api-reference/legacy/account/settings/user-provisioning/users/update-user'],
                              ['HubSpot API: delete a user', 'https://developers.hubspot.com/docs/api-reference/legacy/account/settings/user-provisioning/users/delete-user'],
                              ['HubSpot changelog: Legacy private app creation sunset', 'https://developers.hubspot.com/changelog/legacy-private-app-creation-sunset']],
            ],
            'efax' => [
                'status' => 'available',
                'state' => $app . ' can try to connect to eFax (Documo) in this build, read only. Paste the key in the "Connection" box on this page: the test confirms the key and says whether Documo lists the users for it (Documo documents only "who owns this key"). If it does not, eFax stays tracked by hand.',
                'who' => 'A Documo user on the Business plan or higher, with the API Add-On bought under billing. The key belongs to the user who creates it and can do what that user\'s role can do.',
                'steps' => [
                    'Sign in to Documo and open the "Toolbox" menu.',
                    'Click "API Keys", then "Create".',
                    'Choose the access level "admin" (a "base" key sees only its own user).',
                    'Copy the key at once, or have it emailed to you: Documo shows it only this one time. Paste it in the "Connection" box on this page.',
                ],
                'mind' => [
                    'Create the key under a user that will not leave (a service user), not under a person. Documo does not let a user with an active API key be deactivated, and deleting that user deletes the key.',
                    'If another app already sends faxes with a Documo key, find out which Documo user owns it before that person is ever offboarded.',
                    'Documo\'s published API documents "who owns this key" but not a list of users. The test tries the list; if Documo does not offer it, eFax stays tracked by hand.',
                ],
                'gives' => [
                    'When Documo lists the users for the key: who has a fax login and their fax caller number, matched to people; the offboarding step checks by reading.',
                ],
                'checked' => '2026-10-07',
                'sources' => [['Documo Help: How to Create API Keys', 'https://help.documo.com/hc/en-us/articles/7789630698011-How-to-Create-API-Keys']],
            ],
            'jotform' => [
                'status' => 'available',
                'state' => $app . ' can connect to Jotform in this build, read only: the account owner and the team members (sub-users), the forms with who owns each, and the new submissions of the forms you choose, which become tickets. Paste the key in the "Connection" box on this page; for an account on Jotform\'s EU or HIPAA server, type eu or hipaa in "Server".',
                'who' => 'Any Jotform user can create a key for their own account. Use the account that owns the company\'s forms (on Jotform Enterprise, an administrator).',
                'steps' => [
                    'Sign in to Jotform, open your account settings and go to "API" (the Account API page).',
                    'On the right, click "Create New Key".',
                    'Click the new key and type a label that says who uses it, for example "' . $app . '".',
                    'Under "Permissions", open the dropdown and choose "Read Access". That is enough to read the members, the forms and the submissions.',
                    'Copy the key, paste it in the "Connection" box on this page and click "Save the key and test". The test shows which fields Jotform gives for a member.',
                ],
                'mind' => [
                    'Jotform counts API calls per day by plan (Starter 1,000; Bronze 10,000; Silver 50,000; Gold 100,000; Enterprise unlimited). The count starts again at midnight, Eastern time.',
                    'An account on one of Jotform\'s regional servers is reached at a different address. Note which one the organization uses when the connection is built.',
                    'Not confirmed yet: whether Jotform\'s API can add or remove a team member. If not, those stay by hand and the connection checks afterwards.',
                    'If people sign in to Jotform with their Microsoft account, blocking the Microsoft 365 account already ends their access; the step is then about moving their forms.',
                ],
                'gives' => [
                    'Who is in the Jotform team, matched to people by email; the offboarding step checks by reading.',
                    'The forms of the account with who owns each; the offboarding step lists the forms a leaver owns, so they are moved to a colleague first.',
                    'Forms → tickets: for the forms you choose, each new submission opens a ticket with its answers (asked every ten minutes, one call per form; nothing is set up in Jotform).',
                ],
                'checked' => '2026-10-07',
                'sources' => [['Jotform Help: How to Create a Jotform API Key', 'https://www.jotform.com/help/253-how-to-create-a-jotform-api-key/']],
            ],
            'ctm' => [   // BT148
                'status' => 'available',
                'state' => $app . ' can connect to CallTrackingMetrics in this build, read only: the users (matched to people for onboarding and offboarding), the tracking numbers, a call report, and missed calls as tickets when you switch that on. Paste the access key and the secret key in the "Connection" box on this page.',
                'who' => 'An administrator of the CallTrackingMetrics account. API access is turned on per account (an agency can also make keys for several accounts).',
                'steps' => [
                    'Sign in to CallTrackingMetrics in a web browser.',
                    'Open "Settings" → "Account Settings" and go to "API Integration".',
                    'If API access is not on yet, turn it on. The page shows the "Access Key" and the "Secret Key".',
                    'Copy both and paste them in the "Connection" box on this page. Leave "Account ID" empty when the key belongs to one account; for an agency key, type the ID of the company\'s account (Account Settings shows it).',
                    'Click "Save the key and test". The test names the account it reached and the fields a user carries.',
                    'Set CallTrackingMetrics to "Connected" on this page. Its tab under Company systems then shows the users, the tracking numbers, the calls and the missed-call tickets.',
                ],
                'mind' => [
                    'The keys reach every call of the account. If they are replaced in CallTrackingMetrics, enter the new pair here; the old pair stops working there.',
                    'CallTrackingMetrics allows about 10 requests a second. ' . $app . ' reads the users every four hours, and the calls every five minutes only while missed-call tickets are on.',
                    'CallTrackingMetrics\' API reference does not say whether a user can be removed through it, so adding and removing users stays by hand there; ' . $app . ' checks afterwards by reading.',
                    'A call answered by a voicemail box counts as answered in CallTrackingMetrics, so it does not make a missed-call ticket.',
                ],
                'gives' => [
                    'Reads every user: name, email, role and groups, matched to people by the exact email; a user whose person was blocked in Microsoft 365 is flagged.',
                    'Onboarding: the step is done when CallTrackingMetrics lists the new person. Offboarding: the step is done when it no longer lists them. "Verify offboarding" asks again.',
                    'Tracking numbers: each number with its label, source and where it rings.',
                    'Calls: totals for a period by line, by source, by agent and by day (answered, missed, average talk time).',
                    'Missed calls → tickets: on the lines you choose, an unanswered call opens a ticket in the category you choose; the same caller again while it is open adds a note.',
                ],
                'checked' => '2026-10-27',
                'sources' => [['CallTrackingMetrics API (Postman)', 'https://postman.calltrackingmetrics.com/'], ['CallTrackingMetrics: Activity statuses', 'https://calltrackingmetrics.zendesk.com/hc/en-us/articles/7259164036493-Activity-Statuses'],
                              ['CallTrackingMetrics: Webhooks API integration', 'https://calltrackingmetrics.zendesk.com/hc/en-us/articles/6021057338125-Webhooks-API-Integration']],
            ],
            'pbx' => [   // BT151
                'status' => 'available',
                'state' => $app . ' can connect to a Grandstream UCM63xx (UCM6302) in this build: it reads the extensions, matches them to people, takes a leaver\'s extension out of service from the offboarding step (and puts it back if needed), creates extensions at onboarding, reads the call records for reports and missed-call tickets, and shows the PBX\'s set-up. It deletes an extension only after it has been out of service for the waiting period.',
                'who' => 'An administrator of the phone system\'s own web page, and whoever manages the office firewall. The API has its own user name and password, separate from the administrator\'s.',
                'steps' => [
                    'Sign in to the web page of the UCM as administrator.',
                    'Open "Integrations" → "API Configuration" → "HTTPS API Settings (New)". Tick "Enable", leave the port at 8089, and set a user name and a password for the API (not the administrator\'s).',
                    'Decide how this server reaches the PBX. First try its RemoteConnect address (UCM web page → RemoteConnect → Plan → My Plan, like https://xxxx.a.gdms.cloud): enter it here and test. If the API does not answer there, open one port-forward on the office firewall to the PBX\'s port 8089, allowed only from this server\'s address, and enter https://<the office\'s public address or name>:8089.',
                    'On the PBX, add this server\'s address to the allowlists, so a wrong password can never ban it: Maintenance → Login Settings (login allowlist) and System Settings → Security Settings → Fail2Ban (allowlist). If the web page is limited to some addresses (System Settings → HTTP Server → IP address whitelist), add it there too.',
                    'In the "Connection" box on this page, enter the address, the API user and the API password, and click "Save the key and test". The first test remembers the PBX\'s certificate; a different certificate later is refused until you test again here.',
                    'Set "Use" to "Connected". The "Phone system (PBX)" tab then lists the extensions; give each extension without an email to its person with "Assign…".',
                ],
                'mind' => [
                    'The phone system sits in the office. One port-forward allowed only from this server is enough; never open the PBX\'s web page to everybody.',
                    'Several wrong passwords in a row make the PBX refuse that address. ' . $app . ' stops at the first refused sign-in and never retries by itself.',
                    'An extension "out of service" cannot make or take calls; its voicemail and settings stay. ' . $app . ' also clears its call forwarding so calls are not sent on to the leaver\'s mobile. Nothing is deleted, and "Put back in service" undoes it.',
                    'The menu names are from Grandstream\'s documentation for the UCM63xx. An older firmware may show them in another place.',
                ],
                'gives' => [
                    'Every extension, matched to a person (by the email the PBX gives, or assigned once by hand), with its status. The offboarding step is done when the extension is out of service, and "Verify offboarding" reads the PBX again.',
                    '"Take the extension out of service now" on the offboarding step, and "Put back in service" on the PBX tab. Each change is in the Audit Log.',
                    'Status: the model, the firmware and how long the PBX has been up.',
                ],
                'later' => ['Missed calls as tickets from the PBX\'s own call records (CDR Real-Time Output), with the same rules as CallTrackingMetrics and JustCall.'],
                'checked' => '2026-10-27',
                'sources' => [['Grandstream: IPPBX HTTPS API', 'https://documentation.grandstream.com/knowledge-base/https-api/'], ['Grandstream: UCM RemoteConnect user guide', 'https://documentation.grandstream.com/knowledge-base/ucm-remoteconnect-user-guide/']],
            ],
            // ── BT57 ──
            'paylocity' => [
                'status' => 'planned',
                'state' => 'A connection to Paylocity is planned, read only: the employee roster, and hires and terminations arriving as draft onboardings and offboardings. Until then Paylocity is tracked by hand, and these steps are here so the access request can be started now: Paylocity approves it per company and it takes time.',
                'who' => 'A Paylocity-authorized contact of the company (usually the HR or payroll administrator). Paylocity charges for API access: ask for the cost first.',
                'steps' => [
                    'Ask your Paylocity account executive (or Paylocity support) for the "Web Services Access Request" form.',
                    'Fill in the client contact information. Under who receives the credentials, give the address of the person who will paste them here; under the renewal reminder, an address that is read (the secret has to be replaced every year).',
                    'Ask for the NextGen employee data, read only: employee information, position and status. Do not ask for pay, direct deposit, sensitive data or pay statements: ' . $app . ' never reads them.',
                    'For the events, ask for the webhooks "New Hire", "Termination" and "Employee Change" (Paylocity sets these up; they are not self-service).',
                    'Email the form to webservices@paylocity.com with the subject "API Access", asking for a Client ID and a Client Secret.',
                    'When they arrive, keep them in the Vault and note the Company ID (on the Paylocity home page) until the connection asks for them.',
                ],
                'mind' => [
                    'The Client Secret expires after 365 days. Paylocity emails a reminder before; a missed renewal stops the connection.',
                    'Future-dated hires and terminations send no event until Paylocity makes the record current (tied to payroll processing). Whether the API shows them earlier is not confirmed yet.',
                    'The New Hire event also carries home address, gender and marital status. ' . $app . ' will drop them on receipt and keep only work data.',
                    'A Paylocity event will only create a draft for a person to review. It never blocks an account by itself.',
                ],
                'gives' => [
                    'Planned: an HR roster (status, hire and termination dates, title, department, supervisor, work email) matched to Microsoft 365 accounts, and a list of differences.',
                    'Planned: a new hire becomes a draft onboarding; a termination becomes a draft offboarding dated to the last day.',
                ],
                'checked' => '2026-10-09',
                'sources' => [['Paylocity developer: Authentication', 'https://developer.paylocity.com/integrations/reference/authentication'],
                              ['Paylocity developer: Webhooks overview', 'https://developer.paylocity.com/integrations/reference/webhooks-overview']],
            ],
            'hosting' => [
                'status' => 'planned',
                'state' => 'The hosting provider is a company tool: nobody has an account per person, so it adds no onboarding or offboarding step. A read-only connection to the provider\'s customer portal is planned (servers, the provider\'s tickets and invoices next to Server Guardian). This build has no box for a provider key yet.',
                'who' => 'A user of the organization\'s account at the hosting provider who can manage its API keys (the account owner, or a contact with full access).',
                'steps' => [
                    'Sign in to the provider\'s customer portal.',
                    'Find its API or developer page and request a key. Ask the provider whether the key can be read-only.',
                    'Note the key (and its secret, if there is one). Keep it in the Vault until the connection asks for it.',
                    'If the portal offers to limit the key to addresses, allow only the address of the server ' . $app . ' runs on.',
                ],
                'mind' => [
                    'A portal key can often reach most of what the portal does (reboots, tickets, even paying invoices). ' . $app . ' will only read, but the key itself may be powerful: keep it in the Vault and replace it if it is ever exposed.',
                    'DNS zones that live on the cPanel servers are in WHM, not at the provider.',
                ],
                'gives' => [
                    'Planned: Servers (each server and VPS, matched to the WHM servers in Server Guardian), Tickets (read; later open and reply) and Invoices (date, amount, status, due date, a reminder before the due date).',
                ],
                'checked' => '2026-10-21',
                'sources' => [],
            ],
            'whm' => [
                'status' => 'available',
                'state' => 'WHM / cPanel is a company tool: nobody has an account per person, so it adds no onboarding or offboarding step. ' . $app . ' already reads it for Server Guardian (accounts, services, SSL, disk). Its address, user and API token are saved under Server Guardian → Configure server checks (Super Admin); the line above says whether they are there.',
                'who' => 'The root user of WHM (or a reseller with all initial privileges, which shows the API Tokens page).',
                'steps' => [
                    'Sign in to WHM (the server address on port 2087).',
                    'Open "Development" → "Manage API Tokens" and click "Generate Token".',
                    'Give it a name that says who uses it (letters, numbers, dashes and underscores; for example "' . preg_replace('/[^A-Za-z0-9_-]/', '-', $app) . '-guardian").',
                    'Expiration: the default is one year. Keep it, and put the date in the Calendar; an expired token stops the Server Guardian panels.',
                    'Under IPs, enter the address of the server ' . $app . ' runs on, so the token works from nowhere else.',
                    'Under Privileges, untick "Everything", "Change Password" and "Create User Session and Manage API Tokens". Keep only what the Server Guardian panels read; if a panel later says a call was refused, tick the privilege that call needs.',
                    'Click "Generate", copy the token at once (WHM shows it only this one time) and click "Yes, I saved my token".',
                    'Paste it, with the WHM host, port 2087 and user, under Server Guardian → Configure server checks.',
                ],
                'mind' => [
                    'WHM does not delete an expired token by itself: delete the old one after replacing it.',
                    'A token with "Everything" can do anything root can. Keep the privileges small and the address limit on.',
                    'The server actions of Server Guardian (restarts and the like) use the same token and stay Super Admin only.',
                ],
                'gives' => [
                    'Server Guardian: cPanel accounts and suspended ones, service status, load, SSL certificates, disk and inodes, mail queue, backups and updates.',
                ],
                'checked' => '2026-10-09',
                'sources' => [['cPanel & WHM: Manage API Tokens in WHM', 'https://docs.cpanel.net/whm/development/manage-api-tokens-in-whm/'],
                              ['cPanel API: API tokens in WHM', 'https://api.docs.cpanel.net/guides/guide-to-api-authentication/guide-to-api-authentication-api-tokens-in-whm/']],
            ],
            'codetwo' => [
                'status' => 'indirect',
                'state' => 'CodeTwo Email Signatures is a company tool: it adds no onboarding or offboarding step and needs no key here. It reads every account from Microsoft Entra ID by itself, so what is set on Microsoft 365 → Accounts (name, title, department, phones, address, the custom fields) reaches the signatures without anything else.',
                'who' => 'A CodeTwo administrator of the company\'s tenant (signed in with a Microsoft 365 global administrator when the tenant was registered).',
                'steps' => [
                    'Sign in at login.codetwo.com and open "Tenants", then the company\'s tenant.',
                    'Open "User Entra ID attributes" to see what CodeTwo has read for each person.',
                    'After changing a person on Microsoft 365 → Accounts, wait about 20 minutes, or click "Update cache manually now" there.',
                    'If signatures show old data for everyone, open "Tokens" in the left menu and refresh the token (it stops after a password or MFA change of the administrator who granted it).',
                ],
                'mind' => [
                    'CodeTwo keeps the attributes in a cache it refreshes about every 20 minutes. A change made here appears in the signature after that, not at once.',
                    'CodeTwo recommends Exchange Online custom attributes 1–15 for signatures. The custom fields on Microsoft 365 → Accounts are the account\'s extension attributes 1–15; check once in CodeTwo that a field filled here appears as the custom attribute you expect before building a signature on it.',
                    'A few attributes (initials, notes, web page) need CodeTwo\'s "Synchronize attributes" wizard after a change.',
                    'Offboarding needs nothing in CodeTwo: once the Microsoft 365 account is blocked and unlicensed, the person no longer sends mail.',
                ],
                'gives' => [
                    'Nothing to connect: the link to CodeTwo is Microsoft Entra ID. Use the link on this page to reach CodeTwo\'s admin panel.',
                ],
                'checked' => '2026-10-09',
                'sources' => [['CodeTwo user guide: User Entra ID attributes & Tokens', 'https://www.codetwo.com/userguide/email-signatures-for-office-365/custom-attributes.htm']],
            ],
        ];
    }

    function itops_sys_guide(string $key): ?array {
        static $all = null;
        if ($all === null) $all = itops_sys_guides();
        return $all[$key] ?? null;
    }
}
