-- Demo dataset support (v1.52). A single flag on the tenant marks the whole "Sunrise Senior
-- Advisors" demonstration organization. Because every record is tenant-scoped, flagging the
-- tenant is enough to identify and remove the entire demo in one operation — no per-row flag
-- needed across a dozen tables.
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS is_demo TINYINT(1) NOT NULL DEFAULT 0;
