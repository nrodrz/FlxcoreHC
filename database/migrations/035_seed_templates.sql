-- Seed the starter templates into every existing organization. New organizations get
-- the same set at registration (see AuthService::seedTemplates). Agencies may edit,
-- rename, or delete any of them — these are a starting point, not a cage.
-- Idempotent: re-running changes nothing (guarded by NOT EXISTS on name).

INSERT INTO requirement_templates (id, tenant_id, name, description, applies_to, line, license_code, is_seed, sort, created_at)
SELECT UUID(), t.id, s.name, s.descr, s.applies_to, s.line, s.license_code, 1, s.sort, UTC_TIMESTAMP()
FROM tenants t
CROSS JOIN (
  SELECT 'FL Medicare Agent (2-15)' AS name,
         'Health & life licensed agent selling Medicare Advantage: AHIP, carrier certification, Ready-to-Sell, CMS marketing and FWA training.' AS descr,
         'agent' AS applies_to, 'medicare' AS line, 'fl_2_15' AS license_code, 10 AS sort
  UNION ALL SELECT 'FL Medicare Agent (2-40 Health only)',
         'Health-only licensed agent selling Medicare Advantage.',
         'agent', 'medicare', 'fl_2_40', 20
  UNION ALL SELECT 'FL Commercial Producer (2-20 General Lines)',
         'Property & casualty general lines producer: 24 CE hours biennially, E&O, carrier appointments.',
         'agent', 'commercial', 'fl_2_20', 30
  UNION ALL SELECT 'FL Personal Lines Producer (20-44)',
         'Personal auto and homeowners producer.',
         'agent', 'commercial', 'fl_20_44', 40
  UNION ALL SELECT 'FL Customer Representative (4-40)',
         'Service staff working under a supervising 2-20 agent. No independent selling authority.',
         'agent', 'commercial', 'fl_4_40', 50
  UNION ALL SELECT 'Dual-line Producer (Medicare + P&C)',
         'Producer writing both Medicare Advantage and commercial P&C business.',
         'agent', 'all', 'fl_2_20', 60
  UNION ALL SELECT 'Flood (NFIP) add-on',
         'Add-on for producers who write NFIP flood policies (FEMA Section 207 training).',
         'agent', 'commercial', NULL, 70
  UNION ALL SELECT 'Agency / Entity',
         'The agency itself: entity licence, E&O, appointments and corporate compliance training.',
         'agency', 'all', 'agency_lic', 80
  UNION ALL SELECT 'FMO / Upline',
         'Upline organization: carrier contracts, entity licence, E&O and background checks.',
         'fmo', 'all', 'agency_lic', 90
) s
WHERE NOT EXISTS (
  SELECT 1 FROM requirement_templates rt WHERE rt.tenant_id = t.id AND rt.name = s.name
);

-- Items for each seeded template. Tokens are plain catalog codes; carrier-scoped
-- tokens (code@carrierId) are added by the agency, since carriers are tenant data.
INSERT INTO requirement_template_items (id, tenant_id, template_id, token, created_at)
SELECT UUID(), rt.tenant_id, rt.id, x.token, UTC_TIMESTAMP()
FROM requirement_templates rt
JOIN (
  -- FL Medicare Agent (2-15)
  SELECT 'FL Medicare Agent (2-15)' AS tname, 'fl_2_15' AS token UNION ALL
  SELECT 'FL Medicare Agent (2-15)', 'ahip'          UNION ALL
  SELECT 'FL Medicare Agent (2-15)', 'carrier_cert'  UNION ALL
  SELECT 'FL Medicare Agent (2-15)', 'rts'           UNION ALL
  SELECT 'FL Medicare Agent (2-15)', 'fwa'           UNION ALL
  SELECT 'FL Medicare Agent (2-15)', 'cms_marketing' UNION ALL
  SELECT 'FL Medicare Agent (2-15)', 'call_recording'UNION ALL
  SELECT 'FL Medicare Agent (2-15)', 'carrier_appt'  UNION ALL
  SELECT 'FL Medicare Agent (2-15)', 'eo'            UNION ALL
  SELECT 'FL Medicare Agent (2-15)', 'npn'           UNION ALL
  SELECT 'FL Medicare Agent (2-15)', 'background'    UNION ALL
  SELECT 'FL Medicare Agent (2-15)', 'fl_ce'         UNION ALL

  -- FL Medicare Agent (2-40 Health only)
  SELECT 'FL Medicare Agent (2-40 Health only)', 'fl_2_40'       UNION ALL
  SELECT 'FL Medicare Agent (2-40 Health only)', 'ahip'          UNION ALL
  SELECT 'FL Medicare Agent (2-40 Health only)', 'carrier_cert'  UNION ALL
  SELECT 'FL Medicare Agent (2-40 Health only)', 'rts'           UNION ALL
  SELECT 'FL Medicare Agent (2-40 Health only)', 'fwa'           UNION ALL
  SELECT 'FL Medicare Agent (2-40 Health only)', 'cms_marketing' UNION ALL
  SELECT 'FL Medicare Agent (2-40 Health only)', 'carrier_appt'  UNION ALL
  SELECT 'FL Medicare Agent (2-40 Health only)', 'eo'            UNION ALL
  SELECT 'FL Medicare Agent (2-40 Health only)', 'npn'           UNION ALL
  SELECT 'FL Medicare Agent (2-40 Health only)', 'background'    UNION ALL
  SELECT 'FL Medicare Agent (2-40 Health only)', 'fl_ce'         UNION ALL

  -- FL Commercial Producer (2-20)
  SELECT 'FL Commercial Producer (2-20 General Lines)', 'fl_2_20'      UNION ALL
  SELECT 'FL Commercial Producer (2-20 General Lines)', 'carrier_appt' UNION ALL
  SELECT 'FL Commercial Producer (2-20 General Lines)', 'eo'           UNION ALL
  SELECT 'FL Commercial Producer (2-20 General Lines)', 'npn'          UNION ALL
  SELECT 'FL Commercial Producer (2-20 General Lines)', 'background'   UNION ALL
  SELECT 'FL Commercial Producer (2-20 General Lines)', 'fl_ce'        UNION ALL

  -- FL Personal Lines (20-44)
  SELECT 'FL Personal Lines Producer (20-44)', 'fl_20_44'     UNION ALL
  SELECT 'FL Personal Lines Producer (20-44)', 'carrier_appt' UNION ALL
  SELECT 'FL Personal Lines Producer (20-44)', 'eo'           UNION ALL
  SELECT 'FL Personal Lines Producer (20-44)', 'npn'          UNION ALL
  SELECT 'FL Personal Lines Producer (20-44)', 'background'   UNION ALL
  SELECT 'FL Personal Lines Producer (20-44)', 'fl_ce'        UNION ALL

  -- FL Customer Representative (4-40)
  SELECT 'FL Customer Representative (4-40)', 'fl_4_40'    UNION ALL
  SELECT 'FL Customer Representative (4-40)', 'background' UNION ALL
  SELECT 'FL Customer Representative (4-40)', 'fl_ce'      UNION ALL

  -- Dual-line
  SELECT 'Dual-line Producer (Medicare + P&C)', 'fl_2_15'       UNION ALL
  SELECT 'Dual-line Producer (Medicare + P&C)', 'fl_2_20'       UNION ALL
  SELECT 'Dual-line Producer (Medicare + P&C)', 'ahip'          UNION ALL
  SELECT 'Dual-line Producer (Medicare + P&C)', 'carrier_cert'  UNION ALL
  SELECT 'Dual-line Producer (Medicare + P&C)', 'rts'           UNION ALL
  SELECT 'Dual-line Producer (Medicare + P&C)', 'fwa'           UNION ALL
  SELECT 'Dual-line Producer (Medicare + P&C)', 'cms_marketing' UNION ALL
  SELECT 'Dual-line Producer (Medicare + P&C)', 'carrier_appt'  UNION ALL
  SELECT 'Dual-line Producer (Medicare + P&C)', 'eo'            UNION ALL
  SELECT 'Dual-line Producer (Medicare + P&C)', 'npn'           UNION ALL
  SELECT 'Dual-line Producer (Medicare + P&C)', 'background'    UNION ALL
  SELECT 'Dual-line Producer (Medicare + P&C)', 'fl_ce'         UNION ALL

  -- Flood add-on
  SELECT 'Flood (NFIP) add-on', 'nfip_flood' UNION ALL

  -- Agency / Entity
  SELECT 'Agency / Entity', 'agency_lic'    UNION ALL
  SELECT 'Agency / Entity', 'eo'            UNION ALL
  SELECT 'Agency / Entity', 'npn'           UNION ALL
  SELECT 'Agency / Entity', 'background'    UNION ALL
  SELECT 'Agency / Entity', 'carrier_appt'  UNION ALL
  SELECT 'Agency / Entity', 'fwa'           UNION ALL
  SELECT 'Agency / Entity', 'cms_marketing' UNION ALL

  -- FMO / Upline
  SELECT 'FMO / Upline', 'fmo_contract' UNION ALL
  SELECT 'FMO / Upline', 'agency_lic'   UNION ALL
  SELECT 'FMO / Upline', 'eo'           UNION ALL
  SELECT 'FMO / Upline', 'background'   UNION ALL
  SELECT 'FMO / Upline', 'surety_bond'
) x ON x.tname = rt.name
WHERE rt.is_seed = 1
  AND NOT EXISTS (
    SELECT 1 FROM requirement_template_items i
     WHERE i.template_id = rt.id AND i.token = x.token
  );
