-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 115_addresses_contacts.sql
-- Addresses and office contacts as first-class records.
--
-- addresses: any number per TENANT or per PROVIDER, each with a kind (mailing, billing,
--   correspondence, service, legal, home, other). Distinct from `locations` (practice sites that
--   carry an org NPI and affiliations). A credential can point at the address that applies to it
--   (the address on file with that board / payer) — the basis for the address-consistency work.
--
-- provider_contacts: people at a provider's office (office manager, credentialing coordinator,
--   billing contact…) who help keep the record current WITHOUT needing a portal login. Document
--   requests and reminders can be routed to them.

CREATE TABLE IF NOT EXISTS addresses (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id   CHAR(36)     NOT NULL,
  owner_type  VARCHAR(12)  NOT NULL,          -- tenant | provider
  owner_id    CHAR(36)     NOT NULL,          -- tenants.id or credential_holders.id
  kind        VARCHAR(16)  NOT NULL DEFAULT 'mailing',   -- mailing|billing|correspondence|service|legal|home|other
  label       VARCHAR(80)  NULL,              -- free text, e.g. "Bayamón office"
  line1       VARCHAR(160) NOT NULL,
  line2       VARCHAR(160) NULL,
  city        VARCHAR(80)  NOT NULL,
  state       VARCHAR(8)   NOT NULL,
  zip         VARCHAR(12)  NULL,
  country     VARCHAR(2)   NOT NULL DEFAULT 'US',
  phone       VARCHAR(40)  NULL,
  fax         VARCHAR(40)  NULL,
  is_primary  TINYINT(1)   NOT NULL DEFAULT 0,
  effective_from DATE      NULL,
  effective_to   DATE      NULL,
  notes       VARCHAR(300) NULL,
  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NULL,
  deleted_at  DATETIME     NULL,
  KEY idx_addr_owner (tenant_id, owner_type, owner_id, deleted_at),
  KEY idx_addr_primary (tenant_id, owner_type, owner_id, is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A credential may cite the address on file with its issuer/payer.
ALTER TABLE credentials ADD COLUMN address_id CHAR(36) NULL AFTER issuing_body;

CREATE TABLE IF NOT EXISTS provider_contacts (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id     CHAR(36)     NOT NULL,
  holder_id     CHAR(36)     NOT NULL,
  full_name     VARCHAR(160) NOT NULL,
  role_title    VARCHAR(80)  NULL,            -- Office manager, Credentialing coordinator, Billing…
  email_enc     VARBINARY(1024) NULL,         -- encrypted like provider email (binary, like credential_holders.email_enc)
  email_bidx    VARCHAR(64)  NULL,
  phone         VARCHAR(40)  NULL,
  preferred_language VARCHAR(8) NULL,
  gets_document_requests TINYINT(1) NOT NULL DEFAULT 1,   -- route document-request nudges here
  gets_reminders         TINYINT(1) NOT NULL DEFAULT 0,   -- copy expiry reminders here
  is_primary    TINYINT(1)   NOT NULL DEFAULT 0,
  notes         VARCHAR(300) NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NULL,
  deleted_at    DATETIME     NULL,
  KEY idx_pc_holder (tenant_id, holder_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
