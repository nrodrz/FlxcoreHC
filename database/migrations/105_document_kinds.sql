-- 105_document_kinds.sql
-- Every credential / managed item holds 0..n documents. `kind` labels why each one is there so a
-- license, its renewal letter and a supporting affidavit can coexist meaningfully. `sort_order` keeps
-- the list stable. Verification and history stay per document. Default 'primary' for existing rows.
ALTER TABLE credential_documents
  ADD COLUMN kind VARCHAR(24) NOT NULL DEFAULT 'primary' AFTER supersedes_id,
  ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER kind;
