-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Platform (instance) administrator marker. The first account created by the
-- installer is flagged platform_admin = 1. Tenant isolation is unchanged; this
-- flag gates instance-level administration only.
ALTER TABLE users ADD COLUMN platform_admin TINYINT(1) NOT NULL DEFAULT 0;
