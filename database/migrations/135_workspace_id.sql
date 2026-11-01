-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 135_workspace_id.sql — v1.173.0: Ownership & White-Label Standard, element 8 — workspace ID.
--   tenants.workspace_id: XXXX-XXXX-XXXX-XXXX (uppercase hex), random, made once (on first need), never editable.
--   NULL until first needed; the application fills it with an UPDATE ... WHERE workspace_id IS NULL, so it can never change once set.
ALTER TABLE tenants ADD COLUMN workspace_id CHAR(19) NULL;
ALTER TABLE tenants ADD UNIQUE KEY uq_tenants_workspace_id (workspace_id);
