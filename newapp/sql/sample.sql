-- ============================================================
-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Optional sample records for trying the app. Loaded only when the installer's "Add sample records" box is ticked.
-- Made-up values only. Settings → Maintenance → Sample records finds them again by name and description (api/lib/SampleData.php).
-- 0001-A005: two onboarding templates (the reference app's credentials, contracts, articles, tickets and calendar entry are gone).

INSERT INTO `itops_m365_templates` (`name`,`description`,`m365_json`,`created_by`) VALUES
('Sample · Sales associate','A sample template: a new person in Sales. Add licenses and groups of your tenant before using it.','{"licenses":[],"groups":[],"usage_location":"US","fields":{"jobTitle":"Sales associate","department":"Sales"},"manager":null,"department":"Sales","job_title":"Sales associate"}',1),
('Sample · Office staff','A sample template: a new person in the office. Add licenses and groups of your tenant before using it.','{"licenses":[],"groups":[],"usage_location":"US","fields":{"jobTitle":"Office assistant","department":"Operations"},"manager":null,"department":"Operations","job_title":"Office assistant"}',1);
