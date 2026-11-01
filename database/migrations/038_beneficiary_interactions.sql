-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Beneficiary interaction register — the CMS sales-conduct audit trail.
--
-- Producers being *credentialed* is only half of Medicare Advantage compliance. The other
-- half is what an agent must have ON FILE for every interaction with a beneficiary. This is
-- what a carrier audit or a CMS complaint investigation actually asks for. Sources (CY2027
-- final rule CMS-4208-F3, effective for CY2027 marketing from 2026-10-01):
--
--  * Scope of Appointment — 42 CFR 422.2264(c)(3), 422.2274(b)(3). Required prior to EVERY
--    personal marketing appointment (in person, telephonic, virtual), regardless of who
--    initiated it. CY2027 ELIMINATED the 48-hour waiting period. Must be in writing for
--    in-person appointments; e-signature is valid (E-Sign Act); for non-in-person
--    appointments an audio/audio-visual recording or electronic record suffices. Must
--    capture product types to discuss, appointment date, beneficiary + agent contact
--    details, and state that there is no obligation to enrol, Medicare enrolment status is
--    unaffected, and no automatic enrolment occurs.
--  * Call recording — all calls in the chain of enrolment, inbound and outbound, telephonic
--    and virtual, recorded in their entirety. CY2027 shortened retention for marketing/sales
--    call recordings to 6 years; enrolment records remain at 10 years (42 CFR 422.504).
--  * TPMO disclaimer — CY2027 requires it BEFORE any discussion of plan benefits (no longer
--    within the first 60 seconds); the SHIP reference is removed.
--  * Permission to Contact — required before outreach; expires after 12 months.

CREATE TABLE IF NOT EXISTS beneficiary_interactions (
  id                CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id         CHAR(36)     NOT NULL,
  holder_id         CHAR(36)     NOT NULL,           -- the agent
  occurred_on       DATE         NOT NULL,
  kind              VARCHAR(30)  NOT NULL,           -- appointment|inbound_call|outbound_call|sales_event|educational_event
  channel           VARCHAR(20)  NOT NULL,           -- in_person|phone|virtual
  beneficiary_enc   VARBINARY(512) NULL,             -- name, encrypted at rest
  beneficiary_bidx  VARCHAR(64)  NULL,               -- blind index for lookup
  product_lines     VARCHAR(120) NULL,               -- what the SOA covers (MA, MAPD, PDP, Medigap...)

  -- The artefacts CMS expects on file:
  soa_required      TINYINT(1)   NOT NULL DEFAULT 1,
  soa_on_file       TINYINT(1)   NOT NULL DEFAULT 0,
  soa_method        VARCHAR(20)  NULL,               -- written|esign|recording|electronic
  soa_date          DATE         NULL,
  ptc_on_file       TINYINT(1)   NOT NULL DEFAULT 0, -- permission to contact (expires after 12 months)
  ptc_date          DATE         NULL,
  disclaimer_given  TINYINT(1)   NOT NULL DEFAULT 0, -- TPMO disclaimer before any benefits discussion
  recording_required TINYINT(1)  NOT NULL DEFAULT 0, -- chain-of-enrolment call?
  recording_on_file TINYINT(1)   NOT NULL DEFAULT 0,
  recording_ref     VARCHAR(160) NULL,               -- where the recording lives
  enrolled          TINYINT(1)   NOT NULL DEFAULT 0,
  enrollment_date   DATE         NULL,
  plan_name         VARCHAR(120) NULL,
  notes             VARCHAR(500) NULL,
  created_by        CHAR(36)     NULL,
  created_at        DATETIME     NOT NULL,
  deleted_at        DATETIME     NULL,
  INDEX idx_bi_tenant (tenant_id, occurred_on),
  INDEX idx_bi_holder (tenant_id, holder_id),
  INDEX idx_bi_bidx   (tenant_id, beneficiary_bidx),
  CONSTRAINT fk_bi_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sales-conduct requirements the agent must maintain (distinct from credentials).
INSERT INTO credential_catalog (code, name, category, authority, applies_to, line, state, is_required, renewal_months, notes, sort)
VALUES
 ('soa_process', 'Scope of Appointment records', 'training', 'cms', 'agent,agency,fmo', 'medicare', NULL, 1, NULL,
  '42 CFR 422.2264(c)(3): an SOA must be agreed and recorded BEFORE every personal marketing appointment - in person, telephonic or virtual, whoever requested it. CY2027 eliminated the 48-hour waiting period, so the appointment may be same-day. Written form required in person (e-signature valid); a recording or electronic record suffices otherwise. Retain 10 years.', 230),
 ('ptc_process', 'Permission to Contact records', 'training', 'cms', 'agent,agency,fmo', 'medicare', NULL, 1, NULL,
  'Permission to Contact must be documented before outreach to a beneficiary, and expires after 12 months or once its purpose has been served.', 235),
 ('tpmo_disclaimer', 'TPMO disclaimer in use', 'training', 'cms', 'agent,agency,fmo', 'medicare', NULL, 1, 12,
  'Agents and agencies are TPMOs. The standardised disclaimer must appear on marketing materials, websites and emails, and be spoken BEFORE any discussion of plan benefits (CY2027 changed this from within the first minute). The SHIP reference is removed for CY2027.', 240),
 ('agent_annual_test', 'Annual training + testing (85% pass)', 'training', 'cms', 'agent', 'medicare', NULL, 1, 12,
  '42 CFR 422.2274(b)(2): agents and brokers representing MA organizations must be trained and tested annually and score 85 percent or higher on all forms of testing.', 245)
ON DUPLICATE KEY UPDATE notes = VALUES(notes);
