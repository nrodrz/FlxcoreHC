-- Reference catalog of Medicare Advantage credentials (Florida market focus).
-- Global lookup data (not tenant-scoped); drives credential types + requirement checklists.
CREATE TABLE IF NOT EXISTS credential_catalog (
  code            VARCHAR(60)  NOT NULL PRIMARY KEY,
  name            VARCHAR(160) NOT NULL,
  category        VARCHAR(40)  NOT NULL,     -- certification|license|insurance|appointment|training|background|contract|identifier
  authority       VARCHAR(30)  NOT NULL,     -- cms|carrier|state|federal|ncqa
  applies_to      VARCHAR(80)  NOT NULL DEFAULT 'agent',  -- comma list of: agent,agency,fmo
  renewal_months  INT          NULL,         -- typical renewal cadence in months (NULL = non-expiring)
  is_required     TINYINT(1)   NOT NULL DEFAULT 1,
  state           VARCHAR(2)   NULL,         -- FL for state-specific, NULL = national
  notes           VARCHAR(400) NULL,
  sort            INT          NOT NULL DEFAULT 100
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO credential_catalog
  (code, name, category, authority, applies_to, renewal_months, is_required, state, notes, sort) VALUES
 ('ahip',        'AHIP Medicare Training',                 'certification','cms',    'agent',            12, 1, NULL, 'Annual America''s Health Insurance Plans Medicare cert; required before selling MA/PDP each plan year.', 10),
 ('carrier_cert','Carrier Certification',                  'certification','carrier','agent',            12, 1, NULL, 'Per-carrier annual certification (e.g., Humana, UnitedHealthcare, Aetna, Wellcare) for the plan year.', 20),
 ('fwa',         'Fraud, Waste & Abuse + General Compliance','training',   'cms',    'agent,agency,fmo', 12, 1, NULL, 'Annual CMS FWA and general compliance training.', 30),
 ('fl_2_15',     'FL 2-15 Health, Life & Annuity License',  'license',     'state',  'agent',            24, 1, 'FL', 'Florida resident license to sell health, life and annuities; renew every 24 months.', 40),
 ('fl_2_40',     'FL 2-40 Health Agent License',            'license',     'state',  'agent',            24, 0, 'FL', 'Florida health-only agent license (alternative to 2-15).', 45),
 ('fl_ce',       'FL Continuing Education (CE)',             'training',    'state',  'agent',            24, 1, 'FL', 'Florida CE hours required each license cycle (incl. 5-hr law & ethics).', 50),
 ('eo',          'Errors & Omissions (E&O) Insurance',      'insurance',   'carrier','agent,agency',     12, 1, NULL, 'Professional liability coverage; carriers typically require proof before appointment.', 60),
 ('npn',         'National Producer Number (NPN)',          'identifier',  'federal','agent,agency',     NULL,1, NULL, 'NIPR producer number; non-expiring identifier used across carrier appointments.', 70),
 ('carrier_appt','Carrier Appointment',                     'appointment', 'carrier','agent,agency',     NULL,1, NULL, 'Active appointment/authorization with a specific carrier to market its products.', 80),
 ('rts',         'Ready-to-Sell (RTS) Status',              'certification','carrier','agent',           12, 1, NULL, 'Carrier RTS = AHIP + carrier cert + appointment + valid license all complete for the plan year.', 90),
 ('background',  'Background Check',                         'background',  'carrier','agent,agency',     12, 1, NULL, 'Criminal background screening required by most MA carriers.', 100),
 ('aml',         'Anti-Money-Laundering (AML) Training',    'training',    'carrier','agent',            12, 0, NULL, 'AML training required by some carriers (e.g., for certain product lines).', 110),
 ('surety_bond', 'Surety Bond',                             'insurance',   'state',  'agency,fmo',       12, 0, 'FL', 'Bond some agencies/FMOs must carry per contract or state rule.', 120),
 ('agency_lic',  'FL Agency License',                       'license',     'state',  'agency,fmo',       24, 1, 'FL', 'Florida insurance agency license (entity license).', 130),
 ('fmo_contract','FMO/Upline Contract',                     'contract',    'carrier','agency,fmo',       NULL,1, NULL, 'Executed FMO/GA contract with carriers establishing hierarchy and commissions.', 140),
 ('cms_marketing','CMS Marketing Compliance Attestation',   'training',    'cms',    'agent,agency,fmo', 12, 1, NULL, 'Attestation to CMS marketing rules (CMS-4201-F): no cold calls, SOA retention, disclaimers.', 150);
