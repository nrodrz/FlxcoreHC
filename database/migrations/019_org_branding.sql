-- Per-tenant branding + organization profile.
ALTER TABLE tenants
  ADD COLUMN brand_name    VARCHAR(120) NULL,
  ADD COLUMN brand_accent  VARCHAR(9)   NULL,
  ADD COLUMN logo_path     VARCHAR(255) NULL,
  ADD COLUMN contact_email VARCHAR(190) NULL,
  ADD COLUMN contact_phone VARCHAR(40)  NULL,
  ADD COLUMN website       VARCHAR(190) NULL,
  ADD COLUMN address       VARCHAR(400) NULL;
