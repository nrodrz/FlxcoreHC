-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- ITOps Content Library — Knowledge Base articles + IT SOP library
-- Safe to import into an existing ITOps database (phpMyAdmin > Import, or mysql < this file).
-- Uses INSERT IGNORE + category lookups by name, so it will not clobber existing rows.
-- BT138: for an installation that ran the BT138 database update (categories in one table, itops_categories).

-- 1) Ensure itops_sops has a content column (idempotent: only adds if missing).
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='itops_sops' AND column_name='content');
SET @s := IF(@c=0, 'ALTER TABLE itops_sops ADD COLUMN content LONGTEXT AFTER slug', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2) Knowledge Base articles
INSERT IGNORE INTO itops_kb_articles (title, slug, content, category_id, author_id, status, views_count) SELECT 'Reset or recover your password','reset-your-password','<h2>Reset your work password</h2>
<p>If you are locked out or your password expired, you can reset it yourself in a couple of minutes.</p>
<h3>Self-service reset</h3>
<ol>
<li>Go to the sign-in page and choose <strong>Forgot password</strong>.</li>
<li>Enter your work email (the one ending in your company domain).</li>
<li>Approve the verification prompt on your phone, or enter the code emailed to your recovery address.</li>
<li>Choose a new password that meets the rules below, then sign in.</li>
</ol>
<h3>Password rules</h3>
<ul>
<li>At least 12 characters.</li>
<li>A mix of upper and lower case, a number, and a symbol.</li>
<li>Not reused from your last 5 passwords and not a common word.</li>
</ul>
<p><strong>Tip:</strong> a short passphrase like <code>Coffee-Rain-42!</code> is both strong and easy to remember.</p>
<h3>Still stuck?</h3>
<p>Reset links expire after 30 minutes. If you do not receive the email, check spam, wait two minutes, and try again. If it still fails, open a ticket under <em>Software &gt; Access / Login</em> and IT will reset it for you.</p>', (SELECT id FROM itops_categories WHERE kind='kb' AND name='Security' LIMIT 1), 1, 'published', 412 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM itops_kb_articles WHERE slug='reset-your-password') x);
INSERT IGNORE INTO itops_kb_articles (title, slug, content, category_id, author_id, status, views_count) SELECT 'Connect to the office VPN','connect-office-vpn','<h2>Working securely from outside the office</h2>
<p>The VPN gives you an encrypted connection back to company resources (file shares, internal apps) when you are working remotely or on public Wi-Fi.</p>
<h3>First-time setup</h3>
<ol>
<li>Install the <strong>GlobalProtect</strong> client from the company software portal (Windows, macOS, iOS and Android are all supported).</li>
<li>When prompted for the portal address, enter the value IT provided (for example <code>vpn.yourcompany.com</code>).</li>
<li>Sign in with your Microsoft 365 account and approve the MFA prompt.</li>
<li>Choose the gateway nearest to you for the best speed.</li>
</ol>
<h3>Everyday use</h3>
<ul>
<li>Connect the VPN <strong>before</strong> opening internal apps or shared drives.</li>
<li>You can leave it connected all day; it reconnects automatically if your network drops.</li>
<li>Disconnect when you are only browsing public websites to save bandwidth.</li>
</ul>
<h3>Troubleshooting</h3>
<ul>
<li><strong>Stuck on "Connecting":</strong> toggle Wi-Fi off and on, then retry.</li>
<li><strong>Authentication failed:</strong> confirm your password works on the webmail/portal first.</li>
<li><strong>Slow:</strong> switch to a closer gateway in the client settings.</li>
</ul>', (SELECT id FROM itops_categories WHERE kind='kb' AND name='Network' LIMIT 1), 1, 'published', 288 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM itops_kb_articles WHERE slug='connect-office-vpn') x);
INSERT IGNORE INTO itops_kb_articles (title, slug, content, category_id, author_id, status, views_count) SELECT 'Set up email and calendar on your phone','email-on-your-phone','<h2>Get your email on your phone</h2>
<p>We use Microsoft 365, so the easiest and most secure option is the <strong>Outlook mobile app</strong>.</p>
<h3>Steps</h3>
<ol>
<li>Install <strong>Microsoft Outlook</strong> from the App Store or Google Play.</li>
<li>Open it and add your work email address.</li>
<li>Sign in with your work password and approve the MFA prompt.</li>
<li>Outlook will sync your mail, calendar and contacts automatically.</li>
</ol>
<h3>Add Teams too</h3>
<p>Install <strong>Microsoft Teams</strong> and sign in with the same account to get chat, calls and meeting notifications on the go.</p>
<h3>Good to know</h3>
<ul>
<li>Your device may be asked to set a screen lock/PIN &mdash; this is a security requirement to protect company mail.</li>
<li>If you get a new phone, just install Outlook and sign in; nothing is stored only on the old device.</li>
<li>Lost your phone? Tell IT immediately so we can sign that device out remotely.</li>
</ul>', (SELECT id FROM itops_categories WHERE kind='kb' AND name='Microsoft 365' LIMIT 1), 1, 'published', 651 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM itops_kb_articles WHERE slug='email-on-your-phone') x);
INSERT IGNORE INTO itops_kb_articles (title, slug, content, category_id, author_id, status, views_count) SELECT 'Spot and report phishing emails','recognise-phishing','<h2>How to recognise a phishing email</h2>
<p>Phishing is the number-one way attackers get in. A few seconds of caution protects the whole organisation.</p>
<h3>Warning signs</h3>
<ul>
<li><strong>Urgency or threats</strong> &mdash; "your account will be closed in 24 hours".</li>
<li><strong>Unexpected attachments</strong> or links, especially invoices, shipping notices, or "voicemails".</li>
<li><strong>Mismatched sender</strong> &mdash; the display name looks right but the address is odd.</li>
<li><strong>Requests for credentials, MFA codes, or gift cards.</strong></li>
<li>Generic greetings and small spelling/grammar errors.</li>
</ul>
<h3>Before you click</h3>
<ol>
<li>Hover over links to preview the real destination &mdash; if it does not match, do not click.</li>
<li>Never enter your password on a page you reached from an email link; go to the site directly instead.</li>
<li>We will never ask for your password or MFA code by email.</li>
</ol>
<h3>Report it</h3>
<p>Use the <strong>Report Phish</strong> button in Outlook, or forward the message to the IT help desk and then delete it. When in doubt, report it &mdash; we would much rather check a safe email than miss a real one.</p>', (SELECT id FROM itops_categories WHERE kind='kb' AND name='Security' LIMIT 1), 1, 'published', 520 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM itops_kb_articles WHERE slug='recognise-phishing') x);
INSERT IGNORE INTO itops_kb_articles (title, slug, content, category_id, author_id, status, views_count) SELECT 'Request new hardware or accessories','request-new-hardware','<h2>Requesting a laptop, monitor or accessories</h2>
<p>Standard equipment can be requested through a ticket; your manager approves it and IT handles ordering and setup.</p>
<h3>How to request</h3>
<ol>
<li>Open a ticket under <strong>Hardware</strong>.</li>
<li>List what you need and why (for example, "second monitor for spreadsheet work").</li>
<li>Add your manager as a watcher so they can approve.</li>
</ol>
<h3>Standard kit</h3>
<table border="1" cellpadding="6" style="border-collapse:collapse">
<tr><th>Item</th><th>Standard</th><th>Lead time</th></tr>
<tr><td>Laptop</td><td>14" business laptop, 16&nbsp;GB RAM</td><td>3&ndash;5 business days</td></tr>
<tr><td>Monitor</td><td>24" or 27" 1080p/1440p</td><td>2&ndash;4 business days</td></tr>
<tr><td>Headset</td><td>USB noise-cancelling</td><td>In stock</td></tr>
<tr><td>Dock / adapters</td><td>USB-C dock</td><td>In stock</td></tr>
</table>
<p>Non-standard or higher-spec equipment needs a short business justification and budget-holder sign-off.</p>', (SELECT id FROM itops_categories WHERE kind='kb' AND name='General' LIMIT 1), 1, 'published', 134 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM itops_kb_articles WHERE slug='request-new-hardware') x);
INSERT IGNORE INTO itops_kb_articles (title, slug, content, category_id, author_id, status, views_count) SELECT 'Printer is not responding','printer-not-responding','<h2>Fix a printer that will not print</h2>
<p>Most printer problems are solved in under five minutes with these steps.</p>
<h3>Quick fixes</h3>
<ol>
<li><strong>Power-cycle</strong> the printer: turn it off, wait 15 seconds, turn it back on.</li>
<li>Check the panel for paper jams, low toner, or error lights.</li>
<li>Confirm the printer shows as <em>online</em> and is on the office network.</li>
<li>Cancel any stuck jobs in your print queue, then try again.</li>
</ol>
<h3>Re-add the printer</h3>
<ul>
<li><strong>Windows:</strong> Settings &gt; Bluetooth &amp; devices &gt; Printers &amp; scanners &gt; Add device.</li>
<li><strong>macOS:</strong> System Settings &gt; Printers &amp; Scanners &gt; Add Printer.</li>
<li>Choose the network printer for your floor (ask a neighbour for the name if unsure).</li>
</ul>
<p>Still stuck after that? Open a ticket under <em>Hardware &gt; Printer</em> with the printer name/location and a photo of any error.</p>', (SELECT id FROM itops_categories WHERE kind='kb' AND name='General' LIMIT 1), 1, 'published', 97 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM itops_kb_articles WHERE slug='printer-not-responding') x);
INSERT IGNORE INTO itops_kb_articles (title, slug, content, category_id, author_id, status, views_count) SELECT 'Microsoft 365 and Teams basics','m365-teams-basics','<h2>Getting the most from Microsoft 365</h2>
<p>Your account includes Outlook, Teams, Word, Excel, PowerPoint and 1&nbsp;TB of OneDrive storage.</p>
<h3>Save files to OneDrive or SharePoint, not your desktop</h3>
<ul>
<li>Files in OneDrive/SharePoint are backed up, versioned, and available on any device.</li>
<li>Anything saved only to your laptop is <strong>not</strong> backed up &mdash; if the laptop fails, it is gone.</li>
</ul>
<h3>Teams essentials</h3>
<ul>
<li><strong>Chat</strong> for quick messages; <strong>Channels</strong> for team/project discussion.</li>
<li>Use <strong>@mention</strong> to get someone''s attention.</li>
<li>Share files in a channel so the whole team can co-edit the same copy.</li>
<li>Set your status and quiet hours so notifications respect your focus time.</li>
</ul>
<h3>Co-author in real time</h3>
<p>Open a document from Teams or SharePoint and several people can edit at once &mdash; no more emailing versions back and forth.</p>', (SELECT id FROM itops_categories WHERE kind='kb' AND name='Microsoft 365' LIMIT 1), 1, 'published', 243 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM itops_kb_articles WHERE slug='m365-teams-basics') x);
INSERT IGNORE INTO itops_kb_articles (title, slug, content, category_id, author_id, status, views_count) SELECT 'Set up multi-factor authentication (MFA)','set-up-mfa','<h2>Set up multi-factor authentication</h2>
<p>MFA adds a second check beyond your password, so even a stolen password cannot get into your account. It is required for all staff.</p>
<h3>Recommended: Microsoft Authenticator app</h3>
<ol>
<li>Install <strong>Microsoft Authenticator</strong> on your phone.</li>
<li>On a computer, go to <code>https://aka.ms/mfasetup</code> and sign in.</li>
<li>Choose <strong>Add sign-in method &gt; Authenticator app</strong> and scan the QR code shown.</li>
<li>Approve the test prompt to confirm it works.</li>
</ol>
<h3>Add a backup method</h3>
<p>Also add a phone number for SMS/voice as a fallback in case you lose access to the app.</p>
<h3>Everyday use</h3>
<ul>
<li>When you sign in, approve the push notification (or enter the rotating code).</li>
<li>Only approve prompts you started. If you get a prompt you did not request, <strong>deny it and tell IT</strong> &mdash; someone may have your password.</li>
</ul>
<p>Getting a new phone? Set up Authenticator on the new device <em>before</em> wiping the old one.</p>', (SELECT id FROM itops_categories WHERE kind='kb' AND name='Security' LIMIT 1), 1, 'published', 376 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM itops_kb_articles WHERE slug='set-up-mfa') x);
INSERT IGNORE INTO itops_kb_articles (title, slug, content, category_id, author_id, status, views_count) SELECT 'Wi-Fi and guest network access','wifi-and-guest-access','<h2>Connecting to Wi-Fi</h2>
<h3>Staff Wi-Fi</h3>
<ol>
<li>Select the staff network for your office from the Wi-Fi list.</li>
<li>Sign in with your Microsoft 365 account (the same login as your email).</li>
<li>Accept the certificate prompt the first time you connect.</li>
</ol>
<p>Your device will reconnect automatically at any office once it has joined successfully.</p>
<h3>Guest Wi-Fi for visitors</h3>
<ul>
<li>Connect visitors to the <strong>Guest</strong> network &mdash; it has internet access but is isolated from internal systems.</li>
<li>The guest password is posted in meeting rooms and at reception, and rotates periodically.</li>
</ul>
<h3>Slow or dropping connection</h3>
<ul>
<li>Forget the network and rejoin to clear a stale connection.</li>
<li>Move closer to an access point; thick walls and microwaves cause interference.</li>
<li>If a whole area is affected, open a ticket under <em>Network</em> with the office and room.</li>
</ul>', (SELECT id FROM itops_categories WHERE kind='kb' AND name='Network' LIMIT 1), 1, 'published', 159 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM itops_kb_articles WHERE slug='wifi-and-guest-access') x);
INSERT IGNORE INTO itops_kb_articles (title, slug, content, category_id, author_id, status, views_count) SELECT 'Back up and recover your files','back-up-and-recover-files','<h2>Make sure your work is never lost</h2>
<p>The safest place for your files is <strong>OneDrive</strong> or a <strong>SharePoint/Teams</strong> library. Anything stored there is automatically backed up, versioned, and recoverable.</p>
<h3>Turn on OneDrive backup</h3>
<ol>
<li>Click the OneDrive cloud icon &gt; <strong>Settings</strong> &gt; <strong>Sync and back up</strong>.</li>
<li>Choose <strong>Manage backup</strong> and enable Desktop, Documents and Pictures.</li>
<li>Your files now sync to the cloud and to any device you sign in to.</li>
</ol>
<h3>Recover an earlier version</h3>
<ul>
<li>Right-click a file in OneDrive/SharePoint &gt; <strong>Version history</strong> to restore a previous copy.</li>
<li>Deleted something? Check the <strong>Recycle bin</strong> in OneDrive &mdash; items are kept for 30 days.</li>
</ul>
<h3>What is NOT backed up</h3>
<ul>
<li>Files saved only to your computer''s local <em>C:</em> drive or desktop (unless OneDrive backup is on).</li>
<li>Data on USB sticks and external drives.</li>
</ul>
<p>If you need to recover something older than 30 days or cannot find a file, open a ticket and IT will help.</p>', (SELECT id FROM itops_categories WHERE kind='kb' AND name='General' LIMIT 1), 1, 'published', 201 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM itops_kb_articles WHERE slug='back-up-and-recover-files') x);

-- 3) IT SOP library (published)
INSERT IGNORE INTO itops_sops (title, slug, content, category_id, department_id, status, current_version, review_frequency_days, next_review_date) SELECT 'New Employee IT Onboarding','sop-it-onboarding','<h2>Purpose</h2>
<p>Standardise how IT provisions accounts, equipment and access for a new hire so they are productive on day one and access is granted by least privilege.</p>
<h2>Scope</h2>
<p>All new employees, contractors and interns who require IT accounts or equipment.</p>
<h2>Trigger</h2>
<p>HR submits a new-hire request with start date, role, department and manager at least 5 business days in advance.</p>
<h2>Procedure</h2>
<h3>1. Before day one (T-5 to T-1)</h3>
<ol>
<li>Create the Microsoft 365 account; set display name, job title, department and manager.</li>
<li>Assign the correct licence and add to role-based security/distribution groups (least privilege).</li>
<li>Provision a mailbox; apply retention and signature policy.</li>
<li>Image and enrol the laptop in device management; install the standard software baseline.</li>
<li>Create accounts in line-of-business apps required for the role.</li>
<li>Prepare a welcome sheet with first-login steps, MFA setup, and help-desk details.</li>
</ol>
<h3>2. Day one</h3>
<ol>
<li>Walk the new hire through first sign-in and <strong>require MFA enrolment</strong>.</li>
<li>Confirm email, Teams, VPN and printing all work.</li>
<li>Point them to the Knowledge Base and the ticket form for future help.</li>
</ol>
<h3>3. Within the first week</h3>
<ol>
<li>Verify least-privilege access; remove anything not needed for the role.</li>
<li>Record the asset assignment (serial number) against the employee.</li>
<li>Close the onboarding ticket with a checklist of what was granted.</li>
</ol>
<h2>Records</h2>
<p>Onboarding ticket, asset assignment, and group membership changes are logged for audit.</p>', (SELECT id FROM itops_categories WHERE kind='sop' AND name='Systems' LIMIT 1), (SELECT id FROM itops_departments WHERE name='Information Technology' LIMIT 1), 'published', 2, 180, DATE_ADD(CURDATE(), INTERVAL 180 DAY) FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM itops_sops WHERE slug='sop-it-onboarding') x);
INSERT IGNORE INTO itops_sops (title, slug, content, category_id, department_id, status, current_version, review_frequency_days, next_review_date) SELECT 'Employee Offboarding and Deprovisioning','sop-it-offboarding','<h2>Purpose</h2>
<p>Ensure that when someone leaves, access is revoked promptly, data is preserved, and equipment is recovered &mdash; closing security gaps.</p>
<h2>Scope</h2>
<p>All departing employees, contractors and interns.</p>
<h2>Trigger</h2>
<p>HR submits an offboarding request with the employee, last working day, and exact deactivation time.</p>
<h2>Procedure</h2>
<h3>1. At the deactivation time (do not delay)</h3>
<ol>
<li><strong>Disable</strong> the user account and reset the password; revoke active sessions and tokens.</li>
<li>Remove MFA methods and block sign-in.</li>
<li>Revoke VPN, remote access, and privileged/admin roles.</li>
<li>Remove from all security and distribution groups.</li>
</ol>
<h3>2. Preserve data</h3>
<ol>
<li>Convert the mailbox to shared, or place on litigation/retention hold per policy.</li>
<li>Transfer OneDrive ownership to the manager; reassign shared files.</li>
<li>Reassign or export line-of-business app data as required.</li>
</ol>
<h3>3. Recover assets and clean up</h3>
<ol>
<li>Collect laptop, peripherals, access cards, tokens and mobile devices.</li>
<li>Wipe and re-image returned devices; update the asset register.</li>
<li>Cancel or reassign any licences and individual software subscriptions.</li>
<li>Rotate any shared credentials the person had access to.</li>
</ol>
<h2>Verification</h2>
<p>A second IT staff member confirms the account is disabled and access removed, then signs off the offboarding ticket.</p>', (SELECT id FROM itops_categories WHERE kind='sop' AND name='Systems' LIMIT 1), (SELECT id FROM itops_departments WHERE name='Information Technology' LIMIT 1), 'published', 2, 180, DATE_ADD(CURDATE(), INTERVAL 180 DAY) FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM itops_sops WHERE slug='sop-it-offboarding') x);
INSERT IGNORE INTO itops_sops (title, slug, content, category_id, department_id, status, current_version, review_frequency_days, next_review_date) SELECT 'Incident Response Runbook','sop-incident-response','<h2>Purpose</h2>
<p>Provide a repeatable process to detect, contain, eradicate and recover from security incidents (malware, account compromise, data exposure, ransomware) while preserving evidence.</p>
<h2>Severity levels</h2>
<table border="1" cellpadding="6" style="border-collapse:collapse">
<tr><th>Level</th><th>Example</th><th>Response time</th></tr>
<tr><td>SEV-1 Critical</td><td>Ransomware, active intrusion, major data breach</td><td>Immediate, all-hands</td></tr>
<tr><td>SEV-2 High</td><td>Single account compromise, targeted phishing success</td><td>Within 1 hour</td></tr>
<tr><td>SEV-3 Moderate</td><td>Isolated malware, suspicious activity</td><td>Within 4 hours</td></tr>
</table>
<h2>Procedure</h2>
<h3>1. Identify</h3>
<ol>
<li>Record what was observed, when, and by whom. Open a SEV-tagged ticket.</li>
<li>Notify the IT &amp; Operations Advisor; for SEV-1/2 notify leadership.</li>
</ol>
<h3>2. Contain</h3>
<ol>
<li>Isolate affected devices from the network (do not power off &mdash; preserve memory/evidence where feasible).</li>
<li>Disable compromised accounts and revoke sessions/tokens.</li>
<li>Block malicious senders, domains or IPs.</li>
</ol>
<h3>3. Eradicate</h3>
<ol>
<li>Remove malware, reset affected credentials, patch the exploited weakness.</li>
<li>Confirm no persistence (scheduled tasks, forwarding rules, rogue app consents).</li>
</ol>
<h3>4. Recover</h3>
<ol>
<li>Restore systems/data from known-good backups (see the Backup Restore SOP).</li>
<li>Monitor closely for re-infection before returning to normal operations.</li>
</ol>
<h3>5. Review</h3>
<p>Within 5 business days, hold a blameless post-incident review: timeline, root cause, and corrective actions. Update controls and this runbook.</p>', (SELECT id FROM itops_categories WHERE kind='sop' AND name='Systems' LIMIT 1), (SELECT id FROM itops_departments WHERE name='Information Technology' LIMIT 1), 'published', 2, 90, DATE_ADD(CURDATE(), INTERVAL 90 DAY) FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM itops_sops WHERE slug='sop-incident-response') x);
INSERT IGNORE INTO itops_sops (title, slug, content, category_id, department_id, status, current_version, review_frequency_days, next_review_date) SELECT 'Password and MFA Policy','sop-password-mfa-policy','<h2>Purpose</h2>
<p>Define authentication requirements that protect accounts without creating unnecessary friction.</p>
<h2>Password requirements</h2>
<ul>
<li>Minimum 12 characters; passphrases encouraged.</li>
<li>Screened against known-breached and common passwords.</li>
<li>No forced periodic expiry unless a compromise is suspected (per current industry guidance).</li>
<li>No reuse across work and personal accounts.</li>
</ul>
<h2>Multi-factor authentication</h2>
<ul>
<li>MFA is <strong>mandatory</strong> for all accounts, enforced via Conditional Access.</li>
<li>Preferred factor: authenticator app (push or TOTP). SMS only as a fallback.</li>
<li>Privileged/admin accounts require phishing-resistant MFA where available.</li>
</ul>
<h2>Privileged accounts</h2>
<ul>
<li>Admin work uses separate accounts, not daily-driver accounts.</li>
<li>Shared/service credentials are stored in the ITOps credential vault and rotated on schedule and on staff departure.</li>
</ul>
<h2>Enforcement</h2>
<p>Exceptions require documented approval from the IT &amp; Operations Advisor and a defined expiry date.</p>', (SELECT id FROM itops_categories WHERE kind='sop' AND name='Systems' LIMIT 1), (SELECT id FROM itops_departments WHERE name='Information Technology' LIMIT 1), 'published', 1, 180, DATE_ADD(CURDATE(), INTERVAL 180 DAY) FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM itops_sops WHERE slug='sop-password-mfa-policy') x);
INSERT IGNORE INTO itops_sops (title, slug, content, category_id, department_id, status, current_version, review_frequency_days, next_review_date) SELECT 'Patch and Update Management','sop-patch-management','<h2>Purpose</h2>
<p>Keep operating systems, applications and firmware current to reduce vulnerability exposure, while avoiding disruptive surprises.</p>
<h2>Cadence</h2>
<table border="1" cellpadding="6" style="border-collapse:collapse">
<tr><th>Class</th><th>Target</th></tr>
<tr><td>Critical / actively exploited</td><td>Within 48 hours</td></tr>
<tr><td>High severity</td><td>Within 7 days</td></tr>
<tr><td>Routine OS/app updates</td><td>Monthly maintenance window</td></tr>
<tr><td>Firmware / network devices</td><td>Quarterly, or as advised by vendor</td></tr>
</table>
<h2>Procedure</h2>
<ol>
<li>Review vendor advisories and the update queue weekly.</li>
<li>Test significant updates on a pilot group before broad rollout.</li>
<li>Schedule rollout in the maintenance window; notify affected users in advance.</li>
<li>Deploy via device management; track compliance and chase stragglers.</li>
<li>Record completion and any rollbacks in the change log.</li>
</ol>
<h2>Servers and hosting</h2>
<p>For the cPanel hosting environment, apply cPanel and OS updates per vendor guidance and confirm a current backup exists <strong>before</strong> applying major updates (see the cPanel Backup SOP).</p>', (SELECT id FROM itops_categories WHERE kind='sop' AND name='Operations' LIMIT 1), (SELECT id FROM itops_departments WHERE name='Information Technology' LIMIT 1), 'published', 1, 90, DATE_ADD(CURDATE(), INTERVAL 90 DAY) FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM itops_sops WHERE slug='sop-patch-management') x);
INSERT IGNORE INTO itops_sops (title, slug, content, category_id, department_id, status, current_version, review_frequency_days, next_review_date) SELECT 'Network Change Management','sop-network-change-management','<h2>Purpose</h2>
<p>Control changes to network and core infrastructure so they are planned, approved, reversible and communicated.</p>
<h2>What requires a change record</h2>
<ul>
<li>Firewall/router/switch configuration changes.</li>
<li>DNS, VPN, Wi-Fi and VLAN changes.</li>
<li>Changes to internet circuits or failover.</li>
</ul>
<h2>Procedure</h2>
<ol>
<li>Raise a change with purpose, scope, risk, rollback plan and proposed window.</li>
<li>Obtain approval from the IT &amp; Operations Advisor; high-risk changes get a peer review.</li>
<li>Take a configuration backup of the affected device(s) before changing anything.</li>
<li>Implement in the approved window; verify with a defined test.</li>
<li>If the test fails, execute the rollback plan.</li>
<li>Close the change with results and update network documentation.</li>
</ol>
<h2>Emergency changes</h2>
<p>For outages, implement the fix immediately, then document and review within 24 hours.</p>', (SELECT id FROM itops_categories WHERE kind='sop' AND name='Operations' LIMIT 1), (SELECT id FROM itops_departments WHERE name='Information Technology' LIMIT 1), 'published', 1, 180, DATE_ADD(CURDATE(), INTERVAL 180 DAY) FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM itops_sops WHERE slug='sop-network-change-management') x);
INSERT IGNORE INTO itops_sops (title, slug, content, category_id, department_id, status, current_version, review_frequency_days, next_review_date) SELECT 'cPanel Server Backup (Detailed Procedure)','sop-cpanel-server-backup','<h2>1. Purpose</h2>
<p>Define a complete, repeatable procedure to back up the company&rsquo;s cPanel hosting account(s) at the hosting provider &mdash; including all websites, application files, databases (notably the ITOps database <code>itops_db</code>), email, and DNS &mdash; so that any data loss, corruption, compromise or accidental deletion can be recovered within the recovery objectives below.</p>

<h2>2. Scope</h2>
<p>This SOP covers the cPanel environment at the hosting provider that serves company web properties and ITOps. It applies to both scenarios:</p>
<ul>
<li><strong>Shared/managed cPanel</strong> (no root/WHM access) &mdash; you back up at the <em>cPanel account</em> level.</li>
<li><strong>VPS / Dedicated with WHM root</strong> &mdash; you additionally back up at the <em>server (WHM)</em> level.</li>
</ul>
<p>Where a step is root-only, it is marked <strong>[WHM/root]</strong>.</p>

<h2>3. Recovery objectives</h2>
<table border="1" cellpadding="6" style="border-collapse:collapse">
<tr><th>Metric</th><th>Target</th></tr>
<tr><td>RPO (max acceptable data loss)</td><td>24 hours (daily backups)</td></tr>
<tr><td>RTO (max time to restore)</td><td>4 hours for a single site/DB; 8 hours for a full account</td></tr>
<tr><td>Restore test cadence</td><td>Monthly (see &sect;11)</td></tr>
</table>

<h2>4. Roles (RACI)</h2>
<table border="1" cellpadding="6" style="border-collapse:collapse">
<tr><th>Task</th><th>Responsible</th><th>Accountable</th></tr>
<tr><td>Run/verify backups</td><td>IT Operations</td><td>IT &amp; Operations Advisor</td></tr>
<tr><td>Offsite copy &amp; encryption</td><td>IT Operations</td><td>IT &amp; Operations Advisor</td></tr>
<tr><td>Monthly restore test</td><td>IT Operations</td><td>IT &amp; Operations Advisor</td></tr>
<tr><td>Credential storage (ITOps vault)</td><td>IT &amp; Operations Advisor</td><td>IT &amp; Operations Advisor</td></tr>
</table>

<h2>5. Backup schedule &amp; what is protected</h2>
<table border="1" cellpadding="6" style="border-collapse:collapse">
<tr><th>Data set</th><th>Frequency</th><th>Method</th><th>Destination</th><th>Retention</th></tr>
<tr><td>Full cPanel account</td><td>Weekly (Sun 02:00)</td><td>cPanel full backup / JetBackup</td><td>Remote SFTP + offsite object storage</td><td>4 weekly, 6 monthly</td></tr>
<tr><td>Databases (incl. <code>itops_db</code>)</td><td>Daily (02:00)</td><td><code>mysqldump</code> via cron</td><td>Home dir &rarr; offsite</td><td>14 daily</td></tr>
<tr><td>Home directory (web files)</td><td>Daily incremental</td><td><code>tar</code>/<code>rsync</code></td><td>Offsite SFTP/S3</td><td>14 daily</td></tr>
<tr><td>Email (mailboxes)</td><td>Weekly</td><td>Included in full account backup</td><td>As full backup</td><td>As full backup</td></tr>
<tr><td>DNS zone files</td><td>Weekly + on change</td><td>Export via cPanel/API</td><td>Offsite</td><td>8 weeks</td></tr>
</table>

<h2>6. What must be included</h2>
<ul>
<li><strong>Home directory</strong> (<code>/home/USERNAME</code>): all <code>public_html</code> web roots and application files (including ITOps).</li>
<li><strong>All MySQL databases</strong>, especially <code>itops_db</code>, plus DB users/grants.</li>
<li><strong>Email</strong>: mailboxes, filters, forwarders, autoresponders.</li>
<li><strong>DNS zone files</strong> and any custom records.</li>
<li><strong>cPanel metadata</strong>: subdomains, addon/parked domains, cron jobs, SSL certificates/keys, FTP accounts.</li>
</ul>

<h2>7. Method A &mdash; cPanel Backup Wizard (full account, no root needed)</h2>
<p>Use this for a complete, portable backup of the whole account.</p>
<ol>
<li>Log in to cPanel (the hosting provider&rsquo;s control panel &rarr; cPanel).</li>
<li>Open <strong>Files &gt; Backup</strong> (or <strong>Backup Wizard</strong>).</li>
<li>Choose <strong>Download a Full Account Backup</strong>.</li>
<li>Set <em>Backup Destination</em> to <strong>Remote FTP/SFTP</strong> (preferred) so the archive is written straight to offsite storage rather than filling the account&rsquo;s own disk. Enter the remote host, port, username and the directory.</li>
<li>Enter the notification email and start the backup. cPanel emails you when the archive (a <code>cpmove</code>/<code>backup-*.tar.gz</code>) is ready.</li>
<li>Record the completion in the backup log.</li>
</ol>
<p><strong>Note:</strong> a full account backup can be large. Always target a remote destination or a drive with sufficient free space.</p>

<h2>8. Method B &mdash; JetBackup (if enabled by the hosting provider)</h2>
<p>Many cPanel hosting plans include <strong>JetBackup</strong>, which manages scheduled, retained, offsite backups for you.</p>
<ol>
<li>In cPanel, open <strong>JetBackup</strong> (under the Files or Backups section).</li>
<li>Review <strong>Backups</strong> to confirm daily/weekly snapshots exist and are <em>recent</em>.</li>
<li>Use <strong>Generate/Download</strong> to pull a full account, a single database, a home-directory snapshot, or individual files/emails.</li>
<li>Where available, configure an additional <strong>Destination</strong> (offsite SFTP/S3) and a retention/GFS policy.</li>
<li>Verify the schedule and retention match &sect;5; confirm restores work in the monthly test.</li>
</ol>
<p>If JetBackup is present, it is the primary tool; Methods A, C and D are supplements/redundancy.</p>

<h2>9. Method C &mdash; WHM server-level backup <strong>[WHM/root]</strong></h2>
<p>Only on a VPS/dedicated server with root/WHM.</p>
<ol>
<li>In WHM, go to <strong>Backup &gt; Backup Configuration</strong>.</li>
<li>Enable backups; set type to <strong>Compressed</strong> (or Incremental for space).</li>
<li>Set schedule: Daily, Weekly and Monthly with retention (e.g. 7 daily, 4 weekly, 6 monthly).</li>
<li>Under <strong>Select Users</strong>, ensure the company account is included with its databases.</li>
<li>Under <strong>Additional Destinations</strong>, add a remote destination (SFTP, Amazon S3, Backblaze B2, Google Cloud, or a custom transport) and click <strong>Validate Destination</strong>.</li>
<li>Trigger a manual run from <strong>Backup &gt; Backup Restoration</strong> &rarr; verify a user backup appears at the destination.</li>
</ol>
<p>Equivalent CLI to run the configured backup on demand:</p>
<pre><code>/usr/local/cpanel/bin/backup --force</code></pre>

<h2>10. Method D &mdash; Automated CLI backups via cron (granular &amp; offsite)</h2>
<p>This protects the databases and web files daily, independent of the panel, and pushes an <strong>encrypted</strong> copy offsite (3-2-1 rule). Adjust paths/usernames to your account.</p>

<h3>10.1 Daily database dump</h3>
<pre><code># /home/USERNAME/scripts/db-backup.sh
#!/bin/bash
set -euo pipefail
STAMP=$(date +%F)
DEST="/home/USERNAME/backups/db"
mkdir -p "$DEST"

# Single most-critical DB (ITOps)
mysqldump --single-transaction --routines --triggers \
  itops_db | gzip > "$DEST/itops_db-$STAMP.sql.gz"

# OR: every database the cPanel user owns
for DB in $(mysql -N -e "SHOW DATABASES" | grep -E ''^USERNAME_''); do
  mysqldump --single-transaction --routines --triggers "$DB" \
    | gzip > "$DEST/$DB-$STAMP.sql.gz"
done

# Retain 14 days
find "$DEST" -name ''*.sql.gz'' -mtime +14 -delete</code></pre>
<p><em>Credentials:</em> store the DB user/password in <code>~/.my.cnf</code> (chmod 600) so they are not written in the script. Record those credentials in the ITOps <strong>Credentials</strong> vault, not in plain files.</p>

<h3>10.2 Daily web-files archive</h3>
<pre><code># /home/USERNAME/scripts/files-backup.sh
#!/bin/bash
set -euo pipefail
STAMP=$(date +%F)
DEST="/home/USERNAME/backups/files"
mkdir -p "$DEST"
tar -czf "$DEST/public_html-$STAMP.tar.gz" -C /home/USERNAME public_html
find "$DEST" -name ''*.tar.gz'' -mtime +14 -delete</code></pre>

<h3>10.3 Encrypt and push offsite</h3>
<p>Encrypt with GPG, then sync to object storage (Backblaze B2 / S3) using rclone, so the offsite copy is unreadable if intercepted.</p>
<pre><code># /home/USERNAME/scripts/offsite.sh
#!/bin/bash
set -euo pipefail
SRC="/home/USERNAME/backups"
# Encrypt each new archive to the company public key
for f in $(find "$SRC" -name ''*.gz'' -newermt ''-1 day''); do
  gpg --batch --yes --encrypt --recipient backups@yourcompany.com "$f"
done
# Sync only the encrypted .gpg files offsite (rclone remote configured separately)
rclone copy "$SRC" b2:yourcompany-itops-backups \
  --include ''*.gpg'' --transfers 4 --checksum</code></pre>

<h3>10.4 Schedule with cron (cPanel &gt; Cron Jobs)</h3>
<pre><code>10 2 * * *  /home/USERNAME/scripts/db-backup.sh    &gt;&gt; /home/USERNAME/backups/backup.log 2&gt;&amp;1
25 2 * * *  /home/USERNAME/scripts/files-backup.sh &gt;&gt; /home/USERNAME/backups/backup.log 2&gt;&amp;1
40 2 * * *  /home/USERNAME/scripts/offsite.sh      &gt;&gt; /home/USERNAME/backups/backup.log 2&gt;&amp;1</code></pre>

<h3>10.5 Export DNS zones (optional, via cPanel UAPI)</h3>
<pre><code>uapi --output=jsonpretty DNS dump_zone zone=''yourcompany.com'' \
  &gt; /home/USERNAME/backups/dns/yourcompany.com-$(date +%F).json</code></pre>

<h2>11. Verification &amp; monthly restore test</h2>
<p>A backup that has never been restored is only a hope. Each month:</p>
<ol>
<li>Pick one database and one site folder from the most recent backup.</li>
<li>Decrypt: <code>gpg --decrypt itops_db-DATE.sql.gz.gpg &gt; test.sql.gz</code>.</li>
<li>Restore the DB into a scratch database and confirm row counts and a few key records:
<pre><code>gunzip -c test.sql.gz | mysql USERNAME_restoretest</code></pre></li>
<li>Extract the files archive to a temp folder and spot-check critical files.</li>
<li>Record the test result (pass/fail, time taken) in the backup log and the ITOps ticket.</li>
<li>Drop the scratch database when finished.</li>
</ol>

<h2>12. Encryption &amp; retention (GFS)</h2>
<ul>
<li>All offsite copies are GPG-encrypted to the company backup key; the private key is held only by the IT &amp; Operations Advisor and stored securely.</li>
<li><strong>Grandfather-Father-Son</strong> retention: 14 daily, 4 weekly, 6 monthly. Adjust to compliance needs.</li>
<li>Object-storage bucket has versioning and (where available) object-lock to resist ransomware tampering.</li>
</ul>

<h2>13. Monitoring &amp; alerting</h2>
<ul>
<li>cPanel/JetBackup completion emails go to the IT shared mailbox; missing/failed notices are triaged same day.</li>
<li>The cron scripts append to <code>backup.log</code>; a daily check (or a dead-man&rsquo;s-switch ping such as Healthchecks.io) confirms the jobs ran.</li>
<li>Storage usage is reviewed monthly to avoid the account filling up.</li>
</ul>

<h2>14. Restore quick reference</h2>
<table border="1" cellpadding="6" style="border-collapse:collapse">
<tr><th>Need</th><th>Fastest path</th></tr>
<tr><td>One deleted file</td><td>JetBackup &gt; File Manager restore, or extract from latest <code>tar</code></td></tr>
<tr><td>One database</td><td>Import the dated <code>.sql.gz</code> via phpMyAdmin or <code>mysql</code></td></tr>
<tr><td>A whole site</td><td>Restore home-dir archive; re-point domain if needed</td></tr>
<tr><td>Entire account</td><td>cPanel/JetBackup full restore, or the hosting provider&rsquo;s support with the <code>cpmove</code> archive</td></tr>
</table>
<p>For a total-loss event, open a priority ticket with <strong>the hosting provider&rsquo;s support</strong> and provide the most recent full-account archive from offsite storage.</p>

<h2>15. Credential handling</h2>
<p>All backup-related secrets &mdash; remote SFTP/S3/B2 keys, the GPG passphrase reference, and DB users &mdash; are stored in the ITOps <strong>Credentials</strong> vault (encrypted), never in scripts or shared chats. Rotate them on schedule and whenever a staff member with access leaves.</p>

<h2>16. Revision history</h2>
<table border="1" cellpadding="6" style="border-collapse:collapse">
<tr><th>Version</th><th>Change</th></tr>
<tr><td>1</td><td>Initial cPanel backup procedure.</td></tr>
<tr><td>2</td><td>Added JetBackup, offsite encryption (GPG + rclone), GFS retention and monthly restore test.</td></tr>
</table>', (SELECT id FROM itops_categories WHERE kind='sop' AND name='Operations' LIMIT 1), (SELECT id FROM itops_departments WHERE name='Information Technology' LIMIT 1), 'published', 2, 90, DATE_ADD(CURDATE(), INTERVAL 90 DAY) FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM itops_sops WHERE slug='sop-cpanel-server-backup') x);
INSERT IGNORE INTO itops_sops (title, slug, content, category_id, department_id, status, current_version, review_frequency_days, next_review_date) SELECT 'Backup Restore and Disaster Recovery Test','sop-backup-restore-dr-test','<h2>Purpose</h2>
<p>Prove that backups are usable by restoring them on a schedule, and define how to recover after a major outage.</p>
<h2>Monthly restore test</h2>
<ol>
<li>Select a recent database and file backup at random.</li>
<li>Restore into an isolated scratch location (never over production).</li>
<li>Validate integrity: row counts, key records, and that files open correctly.</li>
<li>Record result, time taken, and any issues in the backup log and a ticket.</li>
</ol>
<h2>Disaster recovery (total loss)</h2>
<ol>
<li>Declare the incident and notify leadership; open a SEV-1 ticket.</li>
<li>Provision replacement hosting (the hosting provider&rsquo;s support / new cPanel account).</li>
<li>Restore the most recent <strong>full account</strong> backup from offsite storage.</li>
<li>Restore databases (including <code>itops_db</code>) and verify application login.</li>
<li>Repoint DNS; confirm SSL; test email flow.</li>
<li>Run smoke tests on each critical site/app before announcing recovery.</li>
</ol>
<h2>Targets</h2>
<p>RPO 24 hours; RTO 8 hours for full recovery. Review after every real or simulated DR event and update the cPanel Backup SOP accordingly.</p>', (SELECT id FROM itops_categories WHERE kind='sop' AND name='Operations' LIMIT 1), (SELECT id FROM itops_departments WHERE name='Information Technology' LIMIT 1), 'published', 1, 90, DATE_ADD(CURDATE(), INTERVAL 90 DAY) FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM itops_sops WHERE slug='sop-backup-restore-dr-test') x);

