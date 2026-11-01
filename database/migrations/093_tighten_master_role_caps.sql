-- 093_tighten_master_role_caps.sql
-- Least-privilege hardening of the built-in global master roles, and the safety decision that these
-- built-in masters are immutable (enforced in code: superadmin may create NEW global roles but cannot
-- alter the baseline every tenant relies on). Here we correct the seeded capabilities:
--   • Manager becomes an operations role — loses account administration (team.manage) and billing.view.
--   • Viewer becomes strictly read-only — loses audit.view (audit oversight is reserved for Manager+).
-- Idempotent: deleting rows that may already be gone is a no-op.

DELETE FROM role_capabilities
 WHERE tenant_id IS NULL
   AND role_id IN (SELECT id FROM roles WHERE tenant_id IS NULL AND slug = 'manager')
   AND capability IN ('team.manage', 'billing.view');

DELETE FROM role_capabilities
 WHERE tenant_id IS NULL
   AND role_id IN (SELECT id FROM roles WHERE tenant_id IS NULL AND slug = 'viewer')
   AND capability IN ('audit.view');
