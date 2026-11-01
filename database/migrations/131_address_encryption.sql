-- v1.171.3 — residential / provider addresses are personal data: line1, line2 and phone are stored encrypted.
-- Existing rows keep working (plaintext is still read until you run: php database/encrypt_existing_addresses.php).
ALTER TABLE addresses
  MODIFY line1 VARCHAR(160) NULL,
  ADD COLUMN line1_enc VARBINARY(512) NULL AFTER line1,
  ADD COLUMN line2_enc VARBINARY(512) NULL AFTER line2,
  ADD COLUMN phone_enc VARBINARY(255) NULL AFTER phone;
