-- 118_pii_fields.sql
-- Sensitive identity fields for credentialing applications, with FIELD-LEVEL access control.
--   dob_enc      — date of birth, encrypted at rest (PII, but routine: it is on every insurance card)
--   ssn_last4_enc— LAST FOUR DIGITS ONLY, encrypted. The full SSN is deliberately NOT stored:
--                  most credentialing/enrollment forms accept last-4, and storing the full number
--                  takes on breach liability we do not need.
-- Both are gated by the new `pii.view` capability: without it a user sees a masked value and the
-- server never decrypts. They are excluded from packets, exports, reports, emails and audit detail.
ALTER TABLE credential_holders
  ADD COLUMN dob_enc       VARBINARY(512) NULL AFTER birth_month,
  ADD COLUMN ssn_last4_enc VARBINARY(512) NULL AFTER dob_enc;
