-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Optional sample records for trying the app. Loaded only when the installer's "Add sample records" box is ticked.
-- Neutral names only. Owner id 1 is the first admin.

-- ── SAMPLE CREDENTIALS (inserted via PHP with encryption) ─────
-- These are template records; actual encrypted values set by installer
-- Category IDs reference itops_cred_categories auto-increment order

-- cat 1 = SaaS Platforms
INSERT INTO `itops_credentials` (`name`,`description`,`category_id`,`department_id`,`system_url`,`environment`,`rotation_days`,`last_rotated_at`,`next_rotation_date`,`status`,`owner_id`) VALUES
('CRM Admin','Primary admin account for the CRM',1,1,'https://crm.example.com','production',90,'2026-05-01','2026-08-01','current',1),
('Cloud Phone Admin','Admin for the cloud phone system',1,1,'https://phone.example.com','production',90,'2026-04-15','2026-07-15','current',1),
('Online Forms Admin','Enterprise account admin',1,1,'https://forms.example.com','production',90,'2026-03-10','2026-06-10','due_soon',1),
('E-signature Admin','Business plan admin account',1,1,'https://esign.example.com','production',90,'2026-02-20','2026-05-20','overdue',1),
('Call Analytics Admin','Call tracking and attribution admin',1,1,'https://calls.example.com','production',90,'2026-05-10','2026-08-10','current',1),
('eFax Admin','Cloud eFax admin',1,1,'https://fax.example.com','production',90,'2026-04-01','2026-07-01','current',1);

-- cat 2 = Cloud Admin
INSERT INTO `itops_credentials` (`name`,`description`,`category_id`,`department_id`,`system_url`,`environment`,`rotation_days`,`last_rotated_at`,`next_rotation_date`,`status`,`owner_id`) VALUES
('Microsoft 365 Global Admin','Azure AD Global Admin — MFA enforced via Authenticator',2,1,'https://admin.microsoft.com','production',30,'2026-05-15','2026-06-15','due_soon',1),
('Azure Portal Admin','Azure subscription admin for cloud resources',2,1,'https://portal.azure.com','production',90,'2026-04-20','2026-07-20','current',1),
('Legacy Mail Admin','Admin for legacy mailboxes',2,1,'https://mail-legacy.example.com','production',90,'2026-03-01','2026-06-01','due_soon',1);

-- cat 3 = Infrastructure
INSERT INTO `itops_credentials` (`name`,`description`,`category_id`,`department_id`,`system_url`,`environment`,`rotation_days`,`last_rotated_at`,`next_rotation_date`,`status`,`owner_id`) VALUES
('cPanel / WHM Root','Primary cPanel/WHM root access — hosting VPS',3,1,'https://server.example.com:2087','production',90,'2026-01-05','2026-04-05','overdue',1),
('Hosting Client Portal','Hosting provider billing and server management portal',3,1,'https://portal.example.com','production',180,'2026-02-15','2026-08-15','current',1),
('DNS Provider Admin','DNS management for all company domains',3,1,'https://dns.example.com','production',90,'2026-04-10','2026-07-10','current',1),
('SSL Certificate Manager','cPanel AutoSSL management',3,1,'https://server.example.com:2087','production',365,'2026-01-01','2026-12-31','current',1);

-- cat 4 = Network Equipment
INSERT INTO `itops_credentials` (`name`,`description`,`category_id`,`department_id`,`system_url`,`environment`,`rotation_days`,`last_rotated_at`,`next_rotation_date`,`status`,`owner_id`) VALUES
('Firewall — Main Office','Firewall admin — main office',4,1,'https://10.0.1.1','production',90,'2026-03-22','2026-06-22','due_soon',1),
('Firewall — Branch Office','Firewall admin — branch office',4,1,'https://10.0.2.1','production',90,'2026-03-22','2026-06-22','due_soon',1),
('Wireless Controller','Wireless controller — all access points and switches',4,1,'https://10.0.1.5:8443','production',90,'2026-04-15','2026-07-15','current',1),
('ISP Admin — Primary Internet','Primary internet provider portal — main office fiber',4,1,'https://isp1.example.com','production',180,'2026-01-15','2026-07-15','current',1),
('ISP Admin — Backup Internet','Backup internet provider portal — branch offices',4,1,'https://isp2.example.com','production',180,'2026-01-15','2026-07-15','current',1);

-- cat 5 = Database
INSERT INTO `itops_credentials` (`name`,`description`,`category_id`,`department_id`,`system_url`,`environment`,`rotation_days`,`last_rotated_at`,`next_rotation_date`,`status`,`owner_id`) VALUES
('MySQL Production — Operations App','Operations app production database',5,1,'mysql://db.example.com:3306','production',90,'2026-04-20','2026-07-20','current',1),
('MySQL Production — CRM Backend','CRM backend database',5,1,'mysql://db.example.com:3306','production',90,'2026-04-20','2026-07-20','current',1),
('MySQL Staging','Staging/test database for all applications',5,1,'mysql://db.example.com:3306','staging',180,'2026-03-01','2026-09-01','current',1);

-- cat 7 = API Keys
INSERT INTO `itops_credentials` (`name`,`description`,`category_id`,`department_id`,`system_url`,`environment`,`rotation_days`,`last_rotated_at`,`next_rotation_date`,`status`,`owner_id`) VALUES
('AI Provider API','API key for the AI features',7,1,'https://ai.example.com','production',180,'2026-03-01','2026-09-01','current',1),
('Maps API','Maps API key for location services',7,1,'https://maps.example.com','production',365,'2026-01-01','2026-12-31','current',1),
('SMTP Relay','Email delivery API key',7,1,'https://smtp.example.com','production',180,'2026-02-15','2026-08-15','current',1),
('Payments API (Live)','Live API keys for payment processing',7,1,'https://payments.example.com','production',365,'2026-01-01','2026-12-31','current',1);

-- ── SAMPLE CONTRACTS ──────────────────────────────────────────
INSERT INTO `itops_contracts` (`title`,`vendor_id`,`contract_type`,`department_id`,`status`,`start_date`,`end_date`,`auto_renew`,`renewal_notice_days`,`total_value`,`payment_frequency`,`payment_amount`,`owner_id`) VALUES
('Cloud Phone — Annual','1','subscription',1,'active','2026-01-01','2026-12-31',1,90,4800.00,'annual',4800.00,1),
('Online Forms — Annual','2','subscription',1,'active','2026-03-01','2027-02-28',1,60,1188.00,'annual',1188.00,1),
('E-signature — Business Plan','3','subscription',1,'expiring','2025-09-01','2026-08-31',0,90,2400.00,'annual',2400.00,1),
('Call Analytics — Annual','4','subscription',1,'active','2026-02-01','2027-01-31',1,60,3600.00,'annual',3600.00,1),
('eFax — Annual','5','subscription',1,'active','2026-04-01','2027-03-31',1,60,960.00,'annual',960.00,1),
('Microsoft 365 Business — Annual','6','license',1,'active','2026-01-01','2026-12-31',1,90,28800.00,'annual',28800.00,1),
('Hosting VPS — Annual','7','service',1,'expiring','2025-07-01','2026-06-30',0,90,6000.00,'annual',6000.00,1),
('Firewall Support — Annual','8','maintenance',1,'active','2026-01-15','2027-01-14',1,90,3200.00,'annual',3200.00,1),
('CRM — Professional','9','subscription',1,'active','2026-01-01','2026-12-31',1,90,10800.00,'annual',10800.00,1),
('Primary Internet Fiber — Main Office',10,'service',1,'active','2025-06-01','2027-05-31',0,120,7200.00,'monthly',600.00,1),
('Backup Internet — Branch Offices',11,'service',1,'active','2026-01-01','2027-12-31',1,90,14400.00,'monthly',1200.00,1);

-- ── SAMPLE KB ARTICLES ────────────────────────────────────────
INSERT INTO `itops_kb_articles` (`title`,`slug`,`content`,`category_id`,`department_id`,`author_id`,`status`,`views_count`,`helpful_yes`,`helpful_no`,`tags`) VALUES
('How to Reset MFA for a User','reset-mfa-user','<h2>Steps to Reset MFA</h2><p>1. Go to Microsoft 365 Admin Center → Active Users</p><p>2. Select the user → Manage multifactor authentication</p><p>3. Select the user → Click "Enable" to re-enroll them</p><p>4. User will be prompted to set up MFA on next login</p><p><strong>Note:</strong> User must have a backup method configured.</p>',1,1,1,'published',234,92,8,'["mfa","microsoft 365","reset","authentication"]'),
('Troubleshooting Cloud Phone Audio Issues','phone-audio-issues','<h2>Common Causes</h2><p>One-way audio, echo, or no sound on cloud phone calls usually stems from:</p><ul><li>Browser permissions not granting microphone access</li><li>Network QoS not prioritizing VoIP traffic</li><li>ISP bandwidth insufficient (need minimum 100kbps per call)</li></ul><h2>Resolution Steps</h2><p>1. Check browser microphone permissions</p><p>2. Test with the phone system''s built-in audio test</p><p>3. Check network bandwidth at the affected location</p><p>4. Verify QoS settings on the firewall</p>',2,1,1,'published',187,85,15,'["phone","audio","voip","troubleshooting"]'),
('New Office Internet Setup Checklist','new-office-internet-checklist','<h2>Pre-Setup (T-30 days)</h2><p>1. Evaluate available ISPs at location</p><p>2. Obtain minimum 2 competitive quotes</p><p>3. Submit quote comparison to Executive Director for approval</p><h2>Installation (T-5 days)</h2><p>4. Coordinate circuit installation with ISP</p><p>5. Pre-configure router, switch, firewall, and APs</p><p>6. Ship pre-configured equipment to site</p><h2>Go-Live (Day 0)</h2><p>7. Install and cable all equipment</p><p>8. Configure VPN tunnel to HQ</p><p>9. Test all services (internet, VoIP, VPN, printing)</p><p>10. Document network topology and credentials in the vault</p>',4,1,1,'published',56,49,1,'["network","new office","checklist","ISP"]'),
('Setting Up Secure Online Forms for Sensitive Information','secure-forms-setup','<h2>Requirements</h2><p>Forms that collect health or other sensitive personal information need the forms vendor''s compliance plan. Standard forms are not suitable.</p><h2>Configuration Steps</h2><p>1. Make sure the form is in the compliance workspace</p><p>2. Turn on encryption for every sensitive field</p><p>3. Use only integrations covered by the vendor''s data-protection agreement</p><p>4. Add a privacy notice to the form</p><p>5. Test with sample data (never real personal information)</p>',3,1,1,'published',143,90,10,'["privacy","forms"]'),
('VPN Configuration for Remote Workers','vpn-remote-config','<h2>VPN Client Setup</h2><p>1. Download the VPN client from the company portal</p><p>2. Install with default settings</p><p>3. Add new VPN connection:</p><ul><li>Name: Company VPN</li><li>Gateway: vpn.company.com</li><li>Port: 443</li></ul><p>4. Authenticate with your M365 credentials</p><p><strong>Requirements:</strong> Minimum 50 Mbps download, 10 Mbps upload recommended.</p>',4,1,1,'published',312,88,12,'["vpn","remote","network"]'),
('eFax — Sending Your First Fax','efax-first-fax','<h2>Sending a Fax</h2><p>1. Sign in to the eFax portal</p><p>2. Click "New Fax" in the top navigation</p><p>3. Enter the recipient fax number (include country code)</p><p>4. Upload or drag-and-drop your document (PDF, DOCX, or image)</p><p>5. Optional: Add a cover page</p><p>6. Click "Send"</p><p><strong>Email-to-Fax:</strong> You can also send faxes by emailing to the fax provider''s email-to-fax address from your company email.</p>',5,1,1,'published',98,94,6,'["efax","fax","howto"]'),
('Accounts Payable Quick Reference','ap-quick-reference','<h2>Invoice Submission Process</h2><p>All vendor invoices must be submitted through the Finance ticket system.</p><p>1. Create a ticket under Finance → Invoice Processing</p><p>2. Attach the invoice PDF</p><p>3. Include: vendor name, amount, PO number (if applicable), and department code</p><p>4. Finance processes within 5 business days for net-30 terms</p>',8,3,1,'published',89,91,9,'["finance","accounts payable","invoice","process"]');

-- ── SAMPLE TICKETS ────────────────────────────────────────────
INSERT INTO `itops_tickets` (`ticket_number`,`subject`,`description`,`category_id`,`department_id`,`priority`,`status`,`requester_id`,`assignee_id`,`created_at`) VALUES
('TK-2026-0001','New laptop for marketing hire starting 6/15','Marketing is hiring a new Marketing Coordinator starting June 15. Need a standard-tier laptop configured and ready by June 13.',2,1,'p3_medium','open',1,1,'2026-05-25 09:00:00'),
('TK-2026-0002','Cannot connect to VPN from home','I''m getting "connection timed out" error when trying to connect to the VPN from my home office. Was working fine yesterday. I''ve restarted my laptop and router.',15,1,'p2_high','in_progress',1,1,'2026-05-27 14:30:00'),
('TK-2026-0003','Online intake form not sending to CRM','The client intake form on the website stopped syncing submissions to the CRM about 3 days ago. We have 15+ submissions that didn''t create contacts.',23,1,'p2_high','in_progress',1,1,'2026-05-28 10:15:00'),
('TK-2026-0004','Request access to Financial Reports dashboard','I need read access to the monthly financial reports dashboard in the BI tool for the Q2 budget review.',36,1,'p4_low','open',1,1,'2026-05-28 11:00:00'),
('TK-2026-0005','Phishing email received — urgent','Received an email claiming to be from Microsoft asking to verify my account. The link goes to a suspicious domain. I did NOT click it. Forwarding the email to IT.',28,1,'p1_critical','resolved',1,1,'2026-05-29 08:00:00'),
('TK-2026-0006','PTO request — July 4th week','Requesting PTO for July 1-5, 2026. Manager has approved verbally.',42,2,'p4_low','open',1,NULL,'2026-05-29 09:30:00'),
('TK-2026-0007','Vendor invoice — firewall support renewal','Firewall support renewal invoice attached. Amount: $3,200. PO#: IT-2026-044. Net-30 terms.',48,3,'p3_medium','open',1,NULL,'2026-05-29 10:00:00');

-- ── SAMPLE CALENDAR EVENTS ────────────────────────────────────
INSERT INTO `itops_calendar_events` (`title`,`description`,`event_type`,`entity_type`,`entity_id`,`start_date`,`color`,`department_id`,`created_by`) VALUES
('Hosting Contract Expires','Hosting VPS annual service contract expires. Decision: renew or migrate.','contract_expiry','contract',7,'2026-06-30','#C44545',1,1),
('E-signature Contract Expires','E-signature Business plan expires. No auto-renew.','contract_expiry','contract',3,'2026-08-31','#C08A30',1,1),
('Online Forms Credential Rotation Due','Online forms admin password rotation due.','credential_rotation','credential',3,'2026-06-10','#C08A30',1,1),
('E-signature Credential Rotation Overdue','E-signature admin password is past rotation date.','credential_rotation','credential',4,'2026-05-20','#C44545',1,1),
('M365 Global Admin Rotation Due','Microsoft 365 Global Admin password rotation (30-day cycle).','credential_rotation','credential',7,'2026-06-15','#C08A30',1,1),
('Audit Window Opens','Expected audit window — make sure the evidence binder is current.','custom',NULL,NULL,'2026-10-01','#4A7FB5',6,1),
('Q3 SOP Review Cycle','Quarterly review of all published SOPs due for review.','sop_review','sop',NULL,'2026-07-01','#4A7FB5',NULL,1),
('New Hire Start — Marketing Coordinator','Marketing Coordinator starting. IT setup ticket TK-2026-0001.','task','ticket',1,'2026-06-15','#7B6AAF',4,1),
('Annual IT Budget Review','Annual IT budget review with Executive Director and Finance.','meeting',NULL,NULL,'2026-07-15','#3D9A6E',1,1),
('Hosting Renewal Decision Deadline','Last day to notify the hosting provider of non-renewal (90-day notice required).','contract_renewal','contract',7,'2026-04-01','#C44545',1,1);


