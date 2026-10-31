-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- ═════════════════════════════════════════════════
-- ITOps — seed data (loaded after schema.sql)
-- ═════════════════════════════════════════════════

-- ── BOOTSTRAP ADMIN USER (id=1) ──────────────────────────────
-- A placeholder Super Admin so rows can reference user 1. The installer's finalize step UPDATEs this row with the real
-- admin name, email and password hash. role_id=1 = Super Admin.
INSERT INTO `itops_users`
  (`id`,`email`,`password_hash`,`display_name`,`first_name`,`last_name`,`role_id`,`department_id`,`auth_provider`,`status`)
VALUES
  (1,'admin@pending.local','!','Administrator','System','Admin',1,1,'local','active');

-- Sample records (two onboarding templates) are optional: sql/sample.sql, loaded only when "Add sample records" is
-- ticked in the installer. Settings → Maintenance → Sample records removes them again.
