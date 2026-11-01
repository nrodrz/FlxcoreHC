-- 134_tenant_timezone.sql — v1.172.16: each organization can have its own time zone.
--   tenants.timezone: an IANA zone name from the allowed list (America/Puerto_Rico, America/New_York, ...).
--   NULL means "use the platform time zone" (Admin > System), so every existing organization behaves exactly as before.
--   Timestamps stay stored in UTC; this column only decides which calendar day / clock time is shown and used for "today".
ALTER TABLE tenants ADD COLUMN timezone VARCHAR(40) NULL;
