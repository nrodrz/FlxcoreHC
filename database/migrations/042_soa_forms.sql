-- Scope of Appointment forms — captured and e-signed inside FlxCoreHC.
--
-- Until now FlxCoreHC recorded THAT an SOA existed (on the beneficiary interaction). This lets
-- an agent capture the SOA itself: the CMS-required fields, the beneficiary's product
-- selections, the required non-obligation attestation, and an electronic signature. The
-- e-signature is valid in writing under the E-Sign Act of 2000, as CMS confirmed in the
-- CY2027 SOA FAQ. Required components (42 CFR 422.2264(c)(3); CMS SOA guidance):
--   * product type(s) the beneficiary agrees to discuss (beneficiary selects)
--   * date of the appointment
--   * beneficiary contact information
--   * agent name + contact information
--   * statement that signing does not obligate enrolment or affect current/future Medicare
--   * beneficiary signature + signature date
-- An SOA is valid 12 months from the signature date, for the agreed product scope only.
-- A new product, or a plan-year-specific SOA crossing into a new plan year, needs a new SOA.

CREATE TABLE IF NOT EXISTS soa_forms (
  id                 CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id          CHAR(36)     NOT NULL,
  holder_id          CHAR(36)     NOT NULL,          -- the agent
  interaction_id     CHAR(36)     NULL,              -- optional link to a logged interaction
  token              CHAR(32)     NULL,              -- for a public sign-by-link flow
  status             VARCHAR(20)  NOT NULL DEFAULT 'draft', -- draft | sent | signed | void
  channel            VARCHAR(20)  NOT NULL DEFAULT 'in_person', -- in_person | phone | virtual
  beneficiary_enc    VARBINARY(512) NULL,            -- name, encrypted
  beneficiary_bidx   VARCHAR(64)  NULL,
  beneficiary_phone  VARCHAR(40)  NULL,
  beneficiary_email  VARCHAR(160) NULL,
  product_lines      VARCHAR(200) NULL,              -- comma list of selected product codes
  plan_year          SMALLINT     NULL,              -- if the SOA references a specific plan year
  appointment_on     DATE         NULL,
  agent_name         VARCHAR(160) NULL,
  agent_npn          VARCHAR(40)  NULL,
  agent_phone        VARCHAR(40)  NULL,
  signed_name        VARCHAR(160) NULL,              -- typed name of signer
  signed_by          VARCHAR(30)  NULL,              -- beneficiary | representative
  signature_path     VARCHAR(300) NULL,             -- stored PNG of the drawn signature
  signed_at          DATETIME     NULL,
  signed_ip          VARCHAR(45)  NULL,
  not_before_reason  VARCHAR(200) NULL,              -- why SOA wasn't executed before the mtg (same-day)
  created_by         CHAR(36)     NULL,
  created_at         DATETIME     NOT NULL,
  updated_at         DATETIME     NULL,
  deleted_at         DATETIME     NULL,
  UNIQUE KEY uq_soa_token (token),
  INDEX idx_soa_tenant (tenant_id, holder_id),
  INDEX idx_soa_bidx (tenant_id, beneficiary_bidx),
  CONSTRAINT fk_soa_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
