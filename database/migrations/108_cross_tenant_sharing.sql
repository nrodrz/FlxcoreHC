-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 108_cross_tenant_sharing.sql
-- Cross-tenant sharing on the CONSENT model. The same real-world provider (matched by the NPI blind
-- index — never by decrypting anyone's data) may be managed by several organizations. Each keeps its
-- OWN record and only ever edits its own. A LINK between two records exists only after three
-- parties consent: the requesting organization's Master Admin, the provider, and the owning
-- organization's Master Admin. Once linked, sharing is PER CREDENTIAL and DIRECTIONAL: the owner
-- chooses which credentials (with documents) a linked organization may see, live and read-only.
-- Anything can be revoked; the provider revoking collapses every link for their identity.

CREATE TABLE IF NOT EXISTS provider_links (
  id                 CHAR(36)     NOT NULL PRIMARY KEY,
  npi_bidx           VARCHAR(64)  NOT NULL,          -- identity anchor (blind index of the NPI)
  requester_tenant_id CHAR(36)    NOT NULL,          -- the org asking to link
  requester_holder_id CHAR(36)    NOT NULL,          -- its own record of the provider
  owner_tenant_id    CHAR(36)     NOT NULL,          -- the org already managing the provider
  owner_holder_id    CHAR(36)     NOT NULL,          -- its record of the provider
  status             VARCHAR(20)  NOT NULL DEFAULT 'pending_provider',
                     -- pending_provider → pending_owner → active | declined | revoked
  requested_by       CHAR(36)     NULL,              -- requester Master Admin user id
  requested_at       DATETIME     NOT NULL,
  provider_consent_at DATETIME    NULL,
  provider_consent_via CHAR(36)   NULL,              -- portal user id that consented
  owner_consent_at   DATETIME     NULL,
  owner_consent_by   CHAR(36)     NULL,              -- owner Master Admin user id
  declined_at        DATETIME     NULL,
  declined_by_party  VARCHAR(12)  NULL,              -- provider | owner
  revoked_at         DATETIME     NULL,
  revoked_by_party   VARCHAR(12)  NULL,              -- requester | owner | provider
  revoked_reason     VARCHAR(300) NULL,
  created_at         DATETIME     NOT NULL,
  UNIQUE KEY uq_pl_pair (requester_holder_id, owner_holder_id),
  KEY idx_pl_npi (npi_bidx, status),
  KEY idx_pl_req (requester_tenant_id, status),
  KEY idx_pl_own (owner_tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-credential, directional share along an ACTIVE link. The owner shares one of ITS credentials
-- with the linked recipient organization. The recipient gets a live read-only view (never a copy),
-- optionally including the documents. Revocable by the owner; dies with the link.
CREATE TABLE IF NOT EXISTS credential_shares (
  id                  CHAR(36)   NOT NULL PRIMARY KEY,
  link_id             CHAR(36)   NOT NULL,
  owner_tenant_id     CHAR(36)   NOT NULL,
  credential_id       CHAR(36)   NOT NULL,          -- the OWNER's credential row
  recipient_tenant_id CHAR(36)   NOT NULL,
  include_documents   TINYINT(1) NOT NULL DEFAULT 1,
  shared_by           CHAR(36)   NULL,
  shared_at           DATETIME   NOT NULL,
  revoked_at          DATETIME   NULL,
  revoked_by          CHAR(36)   NULL,
  UNIQUE KEY uq_cs (credential_id, recipient_tenant_id),
  KEY idx_cs_recipient (recipient_tenant_id, revoked_at),
  KEY idx_cs_link (link_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every time a recipient organization views a shared credential or downloads its document.
CREATE TABLE IF NOT EXISTS cross_share_access (
  id                  CHAR(36)    NOT NULL PRIMARY KEY,
  share_id            CHAR(36)    NOT NULL,
  owner_tenant_id     CHAR(36)    NOT NULL,
  recipient_tenant_id CHAR(36)    NOT NULL,
  user_id             CHAR(36)    NULL,
  event               VARCHAR(12) NOT NULL,          -- view | download
  document_id         CHAR(36)    NULL,
  ip_address          VARCHAR(45) NULL,
  created_at          DATETIME    NOT NULL,
  KEY idx_csa_share (share_id, created_at),
  KEY idx_csa_owner (owner_tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
