-- Multi-state templates + entity (agency/FMO) compliance.
--
-- Two gaps this closes:
--  1. Templates were implicitly Florida. A Georgia agency needs Georgia bundles.
--  2. The AGENCY ITSELF is a licensed entity with its own statutory duties, and nobody
--     inside the agency was accountable for them. Sources encoded below:
--       F.S. 626.0428(4) — every agency location must be in the active full-time charge
--         of a designated, licensed "agent in charge", filed with DFS; a change must be
--         reported within 30 days. If the agent in charge leaves and no replacement is
--         designated within 30 days, and that failure continues 90 days, the AGENCY
--         LICENCE AUTOMATICALLY EXPIRES on the 91st day.
--       F.S. 626.112(7) — agency licence required; failing to file carries an
--         administrative penalty of up to $10,000. A branch is exempt from its own
--         licence only if it shares the agency's name and FEIN, has a designated agent
--         in charge, and files its address/phone within 30 days of transacting.
--       F.S. 501.171 (FIPA) — a business holding personal information must notify
--         affected individuals within 30 days of determining a breach; penalties reach
--         $500,000. Agencies hold SSNs, MBIs and financial data.

ALTER TABLE tenants
  ADD COLUMN operating_states VARCHAR(160) NOT NULL DEFAULT 'FL',
  ADD COLUMN legal_name     VARCHAR(160) NULL,
  ADD COLUMN fein           VARCHAR(20)  NULL,
  ADD COLUMN entity_npn     VARCHAR(20)  NULL,
  ADD COLUMN agency_license VARCHAR(40)  NULL;

-- Templates become state-aware. NULL state = federal / applies anywhere.
ALTER TABLE requirement_templates ADD COLUMN state CHAR(2) NULL AFTER line;

-- Every requirement can name the person accountable for it.
ALTER TABLE producer_requirements ADD COLUMN assigned_user_id CHAR(36) NULL;
ALTER TABLE producer_requirements ADD INDEX idx_pr_assignee (tenant_id, assigned_user_id);

-- Entity-level catalog items (state-scoped where the duty is a state duty).
INSERT INTO credential_catalog (code, name, category, authority, applies_to, line, state, is_required, renewal_months, notes, sort)
VALUES
 ('agent_in_charge', 'Designated agent in charge (DFS)', 'appointment', 'state', 'agency,fmo', 'all', 'FL', 1, NULL,
  'F.S. 626.0428(4): each agency location must be in the active full-time charge of a licensed, appointed agent whose name and licence number are filed with DFS. Report changes within 30 days. If the designation lapses and no replacement is filed within 30 days, and that failure continues 90 days, the agency licence automatically expires on the 91st day.', 210),
 ('branch_designation', 'Branch locations filed with DFS', 'appointment', 'state', 'agency,fmo', 'all', 'FL', 0, NULL,
  'F.S. 626.112(7): a branch avoids needing its own licence only if it uses the same name and FEIN, has a designated agent in charge, and its address and phone are filed with DFS within 30 days of transacting business.', 215),
 ('fein', 'Federal Employer Identification Number', 'identifier', 'federal', 'agency,fmo', 'all', NULL, 1, NULL,
  'Required to share an agency licence across branch locations under F.S. 626.112(7)(b).', 220),
 ('data_breach_plan', 'Data breach response plan (FIPA)', 'training', 'state', 'agency,fmo', 'all', 'FL', 1, 12,
  'F.S. 501.171: a business holding personal information must notify affected individuals within 30 days of determining a breach (and the Department when 500+ Floridians are affected). Penalties reach $500,000 per breach. Review the plan annually.', 225)
ON DUPLICATE KEY UPDATE notes = VALUES(notes);

