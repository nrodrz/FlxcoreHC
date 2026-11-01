-- 099_document_verification.sql
-- Verification evidence recorded on the DOCUMENT itself. A credential carries dates; the document
-- (license PDF, malpractice COI, board letter) is what a credentialing specialist actually verifies —
-- by whom, when, how (primary source vs. a copy vs. the provider's attestation), and until when the
-- verification is considered current. Adds support for recording customer-entered verification evidence and
-- verification method. FlxCoreHC does not independently perform or certify primary-source verification.

ALTER TABLE credential_documents ADD COLUMN verified_at DATETIME NULL;
ALTER TABLE credential_documents ADD COLUMN verified_by CHAR(36) NULL;
ALTER TABLE credential_documents ADD COLUMN verification_method VARCHAR(24) NULL;   -- primary_source | copy | attestation
ALTER TABLE credential_documents ADD COLUMN verification_note VARCHAR(255) NULL;
ALTER TABLE credential_documents ADD COLUMN verification_expires DATE NULL;         -- when the recorded verification should be redone (optional)
ALTER TABLE credential_documents ADD KEY idx_docs_verified (tenant_id, verified_at);

-- Onboarding checklist: when a tenant dismisses "Getting started" (it also auto-hides when complete).
ALTER TABLE tenants ADD COLUMN onboarding_dismissed_at DATETIME NULL;
