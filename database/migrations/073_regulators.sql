-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 073_regulators.sql
-- Generalise the payer ("carrier") concept into a first-class REGULATOR: any entity a provider or
-- facility must satisfy — a payer (Aetna, Medicare), a government agency (CMS, FDA, CDC), a
-- regulatory framework (HIPAA, OSHA), an accreditation body (The Joint Commission), or a state
-- board — across US and Puerto Rico jurisdictions. Payers become one category among five.
--
--   category:     payer | government | regulation | accreditation | state_board
--   jurisdiction: NULL = national/federal, else a state code ('FL', ...) or 'PR'
--
-- A regulator's requirement list may contain BOTH credential requirements (kind='credential',
-- linked to the catalog via catalog_code) and document requirements (kind='document', free-named:
-- HIPAA attestation, OSHA training certificate, COI, W-9...). Requirement tokens keep their
-- existing grammar (code@<id> and cr:<id>) so stored assignments keep working unchanged.

RENAME TABLE carriers TO regulators;
ALTER TABLE regulators
  ADD COLUMN category VARCHAR(30) NOT NULL DEFAULT 'payer' AFTER name,
  ADD COLUMN jurisdiction VARCHAR(10) NULL AFTER category;
ALTER TABLE regulators DROP COLUMN platform_carrier_id;

RENAME TABLE carrier_requirements TO regulator_requirements;
ALTER TABLE regulator_requirements
  CHANGE COLUMN carrier_id regulator_id CHAR(36) NOT NULL,
  ADD COLUMN kind VARCHAR(12) NOT NULL DEFAULT 'credential' AFTER regulator_id,
  ADD COLUMN catalog_code VARCHAR(60) NULL AFTER kind;

ALTER TABLE producer_requirements
  CHANGE COLUMN carrier_id regulator_id CHAR(36) NULL;

ALTER TABLE requirement_templates
  ADD COLUMN regulator_id CHAR(36) NULL AFTER line;

-- Kept healthcare tables that referenced the payer by column: renamed to match. Insurance-vestige
-- tables (policies, commissions, calendar_events, platform_carrier_*) keep their columns untouched
-- until the structural-pruning pass.
ALTER TABLE credentials CHANGE COLUMN carrier_id regulator_id CHAR(36) NULL;
ALTER TABLE training_resources CHANGE COLUMN carrier_id regulator_id CHAR(36) NULL;
ALTER TABLE share_links CHANGE COLUMN carrier_id regulator_id CHAR(36) NULL;
ALTER TABLE carrier_interactions CHANGE COLUMN carrier_id regulator_id CHAR(36) NULL;
