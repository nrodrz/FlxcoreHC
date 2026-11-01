-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 091_plan_storage_quota.sql
-- Per-plan document storage quota (MB), joining the existing max_users and max_holders limits.
-- credential_documents gains size_bytes so usage can be summed; legacy rows stay NULL (counted as 0)
-- and new uploads record their size. NULL quota = unlimited.
ALTER TABLE plans ADD COLUMN max_storage_mb INT NULL DEFAULT NULL AFTER max_holders;
ALTER TABLE credential_documents ADD COLUMN size_bytes BIGINT NULL DEFAULT NULL AFTER mime_type;
