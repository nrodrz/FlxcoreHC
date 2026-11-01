-- 104_tenant_default_locale.sql
-- Per-tenant default interface language. New users in the tenant adopt it until they choose their own,
-- and outbound tenant emails (reminders) are composed in it. Blank/en = English.
ALTER TABLE tenants ADD COLUMN default_locale VARCHAR(8) NOT NULL DEFAULT 'en' AFTER vertical;
