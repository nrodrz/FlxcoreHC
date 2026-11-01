-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Allow standalone renewal tasks/to-dos (not tied to a specific credential),
-- with an optional holder link and a priority.
ALTER TABLE renewal_tasks
  MODIFY credential_id CHAR(36) NULL,
  ADD COLUMN holder_id CHAR(36) NULL,
  ADD COLUMN priority VARCHAR(10) NOT NULL DEFAULT 'normal';
