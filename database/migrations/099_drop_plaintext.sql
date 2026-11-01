-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- FINAL step of PHI encryption: drop the now-empty plaintext columns.
-- Run ONLY after 098 has encrypted existing data AND the app is confirmed working.
-- After this, plaintext PHI no longer exists at rest in any column.
ALTER TABLE credential_holders
  DROP COLUMN npi,
  DROP COLUMN npn,
  DROP COLUMN email;
ALTER TABLE credentials
  DROP COLUMN credential_number;
