-- Tenant theming. The stylesheet is already built on CSS custom properties, so a tenant's
-- palette is expressed by overriding a handful of tokens rather than shipping a second
-- stylesheet. Each column is NULL by default = "use the product default", so an agency that
-- never touches theming is completely unaffected.
ALTER TABLE tenants
  ADD COLUMN IF NOT EXISTS brand_nav     VARCHAR(9)  NULL,   -- sidebar / top-nav base colour
  ADD COLUMN IF NOT EXISTS brand_theme   VARCHAR(10) NULL,   -- light | dark | auto (per-user override still wins)
  ADD COLUMN IF NOT EXISTS brand_radius  VARCHAR(10) NULL;   -- sharp | soft | round  (corner style)
