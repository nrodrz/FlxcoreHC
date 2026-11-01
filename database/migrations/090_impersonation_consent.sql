-- 090_impersonation_consent.sql
-- Consent-gated support access. A platform admin can no longer open a tenant at will: they request
-- access with a reason; the tenant's Master Admin approves (a 4-HOUR window — more time needs a new
-- approval), denies, or revokes mid-session. Every step (request, approval with the approved admin's
-- identity, start, expiration, entry, exit, revoke) is logged in BOTH the tenant audit trail and the
-- platform access log. Approved sessions run in SUPPORT MODE: tenant settings and training only —
-- providers, credentials, documents, and certifications are structurally invisible.

CREATE TABLE IF NOT EXISTS impersonation_requests (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id     CHAR(36)     NOT NULL,
  requested_by  CHAR(36)     NOT NULL,          -- platform admin user id (the ONLY user the grant covers)
  requester_name  VARCHAR(160) NULL,
  requester_email VARCHAR(190) NULL,
  reason        VARCHAR(300) NOT NULL,
  status        VARCHAR(12)  NOT NULL DEFAULT 'pending',  -- pending|approved|denied|revoked|expired
  requested_at  DATETIME     NOT NULL,
  decided_by    CHAR(36)     NULL,              -- tenant Master Admin who decided
  decided_at    DATETIME     NULL,
  expires_at    DATETIME     NULL,              -- decided_at + 4h when approved
  entered_at    DATETIME     NULL,
  ended_at      DATETIME     NULL,
  revoked_at    DATETIME     NULL,
  KEY idx_imp_tenant (tenant_id, status),
  KEY idx_imp_requester (requested_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
