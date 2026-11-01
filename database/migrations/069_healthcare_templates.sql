-- Provider-type templates for healthcare organizations (healthcare Phase 2).
--
-- The same requirement_templates machinery the insurance vertical uses, seeded with provider-type
-- bundles: applying "Physician" to a provider stamps their standard required set. Seeded ONLY into
-- healthcare tenants (WHERE t.vertical = 'healthcare'), so insurance organizations are untouched.
-- Editable like any template — a starting point, not a cage. Idempotent via NOT EXISTS on name.

INSERT INTO requirement_templates (id, tenant_id, name, description, applies_to, line, license_code, is_seed, sort, created_at)
SELECT UUID(), t.id, s.name, s.descr, s.applies_to, s.line, s.license_code, 1, s.sort, UTC_TIMESTAMP()
FROM tenants t
CROSS JOIN (
  SELECT 'Physician' AS name,
         'Individual prescriber: state license, DEA, board certification, malpractice, CAQH attestation, CME and identity documents.' AS descr,
         'physician' AS applies_to, 'all' AS line, 'state_medical_license' AS license_code, 10 AS sort
  UNION ALL SELECT 'Nurse / Advanced Practice',
         'Nurse or advanced-practice provider: state license, malpractice, CAQH, CME (and DEA where the role prescribes).',
         'nurse', 'all', 'state_medical_license', 20
  UNION ALL SELECT 'Laboratory',
         'Clinical laboratory facility: CLIA certificate, organizational NPI, EIN, state lab license where required, and director qualifications.',
         'lab', 'all', 'clia', 30
  UNION ALL SELECT 'Clinic / Facility',
         'Clinic or facility entity: organizational NPI, EIN, business license, group malpractice and Medicare PTAN.',
         'clinic', 'all', 'npi_type2', 40
  UNION ALL SELECT 'Medical Group',
         'Group practice entity: organizational NPI, EIN, group malpractice, Medicare PTAN and business license.',
         'group', 'all', 'npi_type2', 50
) s
WHERE t.vertical = 'healthcare'
  AND NOT EXISTS (
    SELECT 1 FROM requirement_templates rt WHERE rt.tenant_id = t.id AND rt.name = s.name
  );

-- Items (catalog codes) for each seeded healthcare template, healthcare tenants only.
INSERT INTO requirement_template_items (id, tenant_id, template_id, token, created_at)
SELECT UUID(), rt.tenant_id, rt.id, x.token, UTC_TIMESTAMP()
FROM requirement_templates rt
JOIN tenants t ON t.id = rt.tenant_id AND t.vertical = 'healthcare'
JOIN (
  -- Physician
  SELECT 'Physician' AS tname, 'state_medical_license' AS token UNION ALL
  SELECT 'Physician', 'npi'          UNION ALL
  SELECT 'Physician', 'dea'          UNION ALL
  SELECT 'Physician', 'board_cert'   UNION ALL
  SELECT 'Physician', 'malpractice'  UNION ALL
  SELECT 'Physician', 'caqh'         UNION ALL
  SELECT 'Physician', 'cme'          UNION ALL
  SELECT 'Physician', 'gov_id'       UNION ALL
  SELECT 'Physician', 'cv'           UNION ALL

  -- Nurse / Advanced Practice
  SELECT 'Nurse / Advanced Practice', 'state_medical_license' UNION ALL
  SELECT 'Nurse / Advanced Practice', 'npi'         UNION ALL
  SELECT 'Nurse / Advanced Practice', 'malpractice' UNION ALL
  SELECT 'Nurse / Advanced Practice', 'caqh'        UNION ALL
  SELECT 'Nurse / Advanced Practice', 'cme'         UNION ALL
  SELECT 'Nurse / Advanced Practice', 'gov_id'      UNION ALL
  SELECT 'Nurse / Advanced Practice', 'cv'          UNION ALL

  -- Laboratory
  SELECT 'Laboratory', 'clia'              UNION ALL
  SELECT 'Laboratory', 'npi_type2'         UNION ALL
  SELECT 'Laboratory', 'ein'               UNION ALL
  SELECT 'Laboratory', 'state_lab_license' UNION ALL
  SELECT 'Laboratory', 'lab_director_quals'UNION ALL
  SELECT 'Laboratory', 'group_malpractice' UNION ALL

  -- Clinic / Facility
  SELECT 'Clinic / Facility', 'npi_type2'         UNION ALL
  SELECT 'Clinic / Facility', 'ein'               UNION ALL
  SELECT 'Clinic / Facility', 'business_license'  UNION ALL
  SELECT 'Clinic / Facility', 'group_malpractice' UNION ALL
  SELECT 'Clinic / Facility', 'medicare_ptan'     UNION ALL

  -- Medical Group
  SELECT 'Medical Group', 'npi_type2'         UNION ALL
  SELECT 'Medical Group', 'ein'               UNION ALL
  SELECT 'Medical Group', 'group_malpractice' UNION ALL
  SELECT 'Medical Group', 'medicare_ptan'     UNION ALL
  SELECT 'Medical Group', 'business_license'
) x ON x.tname = rt.name
WHERE rt.is_seed = 1
  AND NOT EXISTS (
    SELECT 1 FROM requirement_template_items i WHERE i.template_id = rt.id AND i.token = x.token
  );
