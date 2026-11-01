-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 125_healthcare_cleanup.sql — v1.170.8 follow-up to 124.
-- Insurance-era compliance checklist items seeded by 004 (applies_to='insurance') were the last insurance
-- seed rows surviving a fresh install (hidden only by the vertical filter). Remove them — child status rows
-- first, because fk_tcomp_item has no ON DELETE CASCADE.
DELETE tc FROM tenant_compliance tc JOIN compliance_items ci ON ci.id = tc.item_id WHERE ci.applies_to = 'insurance';
DELETE FROM compliance_items WHERE applies_to = 'insurance';
-- A custom role created earlier with a reserved slug resolved to every capability (see RoleService).
UPDATE users u JOIN roles r ON r.tenant_id = u.tenant_id AND r.slug = u.role AND r.is_system = 0 AND r.slug IN ('master_admin', 'tenant_owner')
   SET u.role = CONCAT(r.slug, '_role_', LEFT(r.id, 8));
-- The suffix is derived from the role id so the new slug is unique even if the tenant already has a role
-- called e.g. 'master_admin_role' (uq_role_tenant_slug would otherwise abort this migration).
UPDATE roles SET slug = CONCAT(slug, '_role_', LEFT(id, 8)) WHERE tenant_id IS NOT NULL AND is_system = 0 AND slug IN ('master_admin', 'tenant_owner');
-- Audit action names renamed in v1.170.8 — keep history readable under the new names.
UPDATE audit_logs SET action = 'operating_jurisdictions_set' WHERE action = 'lines_of_business_set';
UPDATE audit_logs SET action = 'provider_user_linked'  WHERE action = 'producer_user_linked';
UPDATE audit_logs SET action = 'provider_user_invited' WHERE action = 'producer_user_invited';
