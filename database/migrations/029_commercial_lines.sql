-- Commercial / P&C lines expansion. FlxCoreHC now serves Medicare AND commercial
-- insurance agencies. Adds a line-of-business dimension to the catalog, a tenant
-- setting for which lines the org operates in, FL commercial licensing items
-- (verified against FL DFS + FEMA sources), and a Training Center guide.

ALTER TABLE credential_catalog
  ADD COLUMN line VARCHAR(20) NOT NULL DEFAULT 'all' AFTER applies_to;

ALTER TABLE tenants
  ADD COLUMN business_lines VARCHAR(60) NOT NULL DEFAULT 'medicare';

-- Tag existing Medicare/health-specific items.
UPDATE credential_catalog SET line = 'medicare'
 WHERE code IN ('ahip','nabip','carrier_cert','rts','cms_marketing','call_recording','fwa','fl_2_15','fl_2_40');

-- New commercial-lines catalog items (Florida focus).
INSERT IGNORE INTO credential_catalog
  (code, name, category, authority, applies_to, line, renewal_months, is_required, state, notes, sort) VALUES
 ('fl_2_20', 'FL 2-20 General Lines (P&C) Agent License', 'license', 'state', 'agent', 'commercial', NULL, 1, 'FL',
  'Florida''s broadest property & casualty license: property, casualty, surety, marine, and health lines. License does not expire, but 24 CE hours are due biennially by the end of the licensee''s birth month (F.S. 626.2815), and carrier appointments renew on their own cycle.', 22),
 ('fl_20_44', 'FL 20-44 Personal Lines Agent License', 'license', 'state', 'agent', 'commercial', NULL, 0, 'FL',
  'Limited P&C license for personal-lines products (auto, home). Assign this OR the 2-20 depending on the producer''s role; a 2-20 covers everything a 20-44 does. With one year of experience a 20-44 can convert to a 2-20 via a 40-hour course.', 24),
 ('fl_4_40', 'FL 4-40 Customer Representative License', 'license', 'state', 'agent', 'commercial', NULL, 0, 'FL',
  'For salaried service staff working under a supervising 2-20 agent in one agency at a time. Does not authorize independent selling. Assign to CSRs whose license status you track.', 26),
 ('nfip_flood', 'NFIP Flood Insurance Training (FEMA §207)', 'training', 'federal', 'agent', 'commercial', NULL, 0, NULL,
  'Federal minimum training required before selling National Flood Insurance Program policies (Flood Insurance Reform Act of 2004, Section 207). Typically a 3-4 hour course; many states fold it into CE. Assign to any producer who writes flood.', 28),
 ('cyber_liability', 'Commercial E&O / Cyber Liability Review', 'insurance', 'carrier', 'agency,fmo', 'commercial', 12, 0, NULL,
  'Annual confirmation that the agency''s E&O policy covers the commercial lines being written (including cyber, if sold). Many carrier agency agreements set minimum E&O limits — verify against each contract.', 62);

-- Training Center guide for commercial licensing.
INSERT IGNORE INTO training_library (code, title, category, authority, summary, body, link, link_label, minutes, sort, updated_at) VALUES
 ('fl_commercial_licensing', 'Florida commercial (P&C) licensing', 'state', 'state',
  'The license structure for property & casualty producers: 2-20, 20-44, 4-40, CE, and flood.',
  'Commercial and personal-lines producers in Florida operate under a different license family than health/Medicare agents.

- The 2-20 General Lines license is the broadest P&C license, covering property, casualty, surety, marine, and health lines. It requires a 200-hour pre-licensing course (or a 40-hour conversion with prior experience), a state exam, and fingerprinting.
- The 20-44 Personal Lines license is limited to personal auto and homeowners-type products. A 20-44 holder with a year of experience can convert to a 2-20 via a 40-hour course.
- The 4-40 Customer Representative license is for salaried service staff working under a supervising 2-20 agent — it does not authorize independent selling.
- Continuing education is 24 hours every two years, due by the end of the licensee''s birth month.
- Selling NFIP flood insurance requires completing FEMA''s minimum flood training (Flood Insurance Reform Act of 2004, Section 207) on top of the P&C license.

Licenses themselves do not expire in Florida, but letting CE lapse or losing all appointments puts the license at risk. Track carrier appointments per carrier, exactly as with Medicare certifications.',
  NULL, NULL, 6, 75, NOW());

INSERT IGNORE INTO training_sources (article_code, label, url, authority, sort) VALUES
 ('fl_commercial_licensing', 'Florida DFS — Insurance Licensing', 'https://myfloridacfo.com/division/agents/licensing', 'state', 10),
 ('fl_commercial_licensing', 'FEMA/NFIP — state flood training requirements', 'https://agents.floodsmart.gov/topics/selling-flood-insurance/state-training-requirements', 'federal', 20);
