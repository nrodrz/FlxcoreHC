-- 097_healthcare_templates_by_profession.sql
-- Pre-built requirement templates for the professional types a billing company actually credentials,
-- beyond the original Physician / Nurse / Lab / Clinic / Group set. Each bundle reflects the
-- credentials that profession is normally asked for: prescribers carry DEA; non-prescribers don't;
-- everyone carries state licensure, NPI, malpractice, CAQH (where payers enroll them), identity and CV.
-- Seeded ONLY into healthcare tenants; idempotent (NOT EXISTS on name); editable like any template.

INSERT INTO requirement_templates (id, tenant_id, name, description, applies_to, line, license_code, is_seed, sort, created_at)
SELECT UUID(), t.id, s.name, s.descr, s.applies_to, s.line, s.license_code, 1, s.sort, UTC_TIMESTAMP()
FROM tenants t
CROSS JOIN (
  SELECT 'Nurse Practitioner' AS name,
         'Advanced-practice prescriber: state APRN license, DEA, NPI, malpractice, CAQH, CME, identity and CV.' AS descr,
         'np' AS applies_to, 'all' AS line, 'state_medical_license' AS license_code, 22 AS sort
  UNION ALL SELECT 'Physician Assistant',
         'PA: state PA license, DEA (where prescribing), NPI, malpractice, CAQH, CME, identity and CV.',
         'pa', 'all', 'state_medical_license', 24
  UNION ALL SELECT 'Dentist',
         'Dentist: state dental license, DEA, NPI, malpractice, CAQH, CE, identity and CV.',
         'dentist', 'all', 'state_medical_license', 26
  UNION ALL SELECT 'Pharmacist',
         'Pharmacist: state pharmacy license, NPI, malpractice, CE, identity and CV.',
         'pharmacist', 'all', 'state_medical_license', 28
  UNION ALL SELECT 'Therapist (PT / OT / SLP)',
         'Rehabilitation therapist: state license, NPI, malpractice, CAQH, CE, identity and CV. No DEA.',
         'therapist', 'all', 'state_medical_license', 30
  UNION ALL SELECT 'Behavioral Health',
         'Psychologist / LCSW / LMHC: state license, NPI, malpractice, CAQH, CE, identity and CV.',
         'behavioral', 'all', 'state_medical_license', 32
  UNION ALL SELECT 'Chiropractor',
         'Chiropractor: state license, NPI, malpractice, CAQH, CE, identity and CV.',
         'chiropractor', 'all', 'state_medical_license', 34
  UNION ALL SELECT 'Technologist / Technician',
         'Radiologic, lab or other technologist: state license or certification, identity and CV; NPI where billed.',
         'technologist', 'all', 'state_medical_license', 36
  UNION ALL SELECT 'Other Licensed Professional',
         'Any other licensed professional: state license, NPI (if billed), malpractice, identity and CV — adjust as needed.',
         'other_pro', 'all', 'state_medical_license', 38
) s
WHERE t.vertical = 'healthcare'
  AND NOT EXISTS (
    SELECT 1 FROM requirement_templates rt WHERE rt.tenant_id = t.id AND rt.name = s.name
  );

INSERT INTO requirement_template_items (id, tenant_id, template_id, token, created_at)
SELECT UUID(), rt.tenant_id, rt.id, x.token, UTC_TIMESTAMP()
FROM requirement_templates rt
JOIN tenants t ON t.id = rt.tenant_id AND t.vertical = 'healthcare'
JOIN (
  -- Nurse Practitioner (prescriber)
  SELECT 'Nurse Practitioner' AS tname, 'state_medical_license' AS token UNION ALL
  SELECT 'Nurse Practitioner', 'dea'         UNION ALL
  SELECT 'Nurse Practitioner', 'npi'         UNION ALL
  SELECT 'Nurse Practitioner', 'malpractice' UNION ALL
  SELECT 'Nurse Practitioner', 'caqh'        UNION ALL
  SELECT 'Nurse Practitioner', 'cme'         UNION ALL
  SELECT 'Nurse Practitioner', 'gov_id'      UNION ALL
  SELECT 'Nurse Practitioner', 'cv'          UNION ALL
  -- Physician Assistant (prescriber)
  SELECT 'Physician Assistant', 'state_medical_license' UNION ALL
  SELECT 'Physician Assistant', 'dea'         UNION ALL
  SELECT 'Physician Assistant', 'npi'         UNION ALL
  SELECT 'Physician Assistant', 'malpractice' UNION ALL
  SELECT 'Physician Assistant', 'caqh'        UNION ALL
  SELECT 'Physician Assistant', 'cme'         UNION ALL
  SELECT 'Physician Assistant', 'gov_id'      UNION ALL
  SELECT 'Physician Assistant', 'cv'          UNION ALL
  -- Dentist (prescriber)
  SELECT 'Dentist', 'state_medical_license' UNION ALL
  SELECT 'Dentist', 'dea'         UNION ALL
  SELECT 'Dentist', 'npi'         UNION ALL
  SELECT 'Dentist', 'malpractice' UNION ALL
  SELECT 'Dentist', 'caqh'        UNION ALL
  SELECT 'Dentist', 'cme'         UNION ALL
  SELECT 'Dentist', 'gov_id'      UNION ALL
  SELECT 'Dentist', 'cv'          UNION ALL
  -- Pharmacist (no DEA registrant as individual typically)
  SELECT 'Pharmacist', 'state_medical_license' UNION ALL
  SELECT 'Pharmacist', 'npi'         UNION ALL
  SELECT 'Pharmacist', 'malpractice' UNION ALL
  SELECT 'Pharmacist', 'cme'         UNION ALL
  SELECT 'Pharmacist', 'gov_id'      UNION ALL
  SELECT 'Pharmacist', 'cv'          UNION ALL
  -- Therapist (non-prescriber)
  SELECT 'Therapist (PT / OT / SLP)', 'state_medical_license' UNION ALL
  SELECT 'Therapist (PT / OT / SLP)', 'npi'         UNION ALL
  SELECT 'Therapist (PT / OT / SLP)', 'malpractice' UNION ALL
  SELECT 'Therapist (PT / OT / SLP)', 'caqh'        UNION ALL
  SELECT 'Therapist (PT / OT / SLP)', 'cme'         UNION ALL
  SELECT 'Therapist (PT / OT / SLP)', 'gov_id'      UNION ALL
  SELECT 'Therapist (PT / OT / SLP)', 'cv'          UNION ALL
  -- Behavioral health (non-prescriber)
  SELECT 'Behavioral Health', 'state_medical_license' UNION ALL
  SELECT 'Behavioral Health', 'npi'         UNION ALL
  SELECT 'Behavioral Health', 'malpractice' UNION ALL
  SELECT 'Behavioral Health', 'caqh'        UNION ALL
  SELECT 'Behavioral Health', 'cme'         UNION ALL
  SELECT 'Behavioral Health', 'gov_id'      UNION ALL
  SELECT 'Behavioral Health', 'cv'          UNION ALL
  -- Chiropractor (non-prescriber)
  SELECT 'Chiropractor', 'state_medical_license' UNION ALL
  SELECT 'Chiropractor', 'npi'         UNION ALL
  SELECT 'Chiropractor', 'malpractice' UNION ALL
  SELECT 'Chiropractor', 'caqh'        UNION ALL
  SELECT 'Chiropractor', 'cme'         UNION ALL
  SELECT 'Chiropractor', 'gov_id'      UNION ALL
  SELECT 'Chiropractor', 'cv'          UNION ALL
  -- Technologist / Technician
  SELECT 'Technologist / Technician', 'state_medical_license' UNION ALL
  SELECT 'Technologist / Technician', 'gov_id'      UNION ALL
  SELECT 'Technologist / Technician', 'cv'          UNION ALL
  -- Other licensed professional
  SELECT 'Other Licensed Professional', 'state_medical_license' UNION ALL
  SELECT 'Other Licensed Professional', 'npi'         UNION ALL
  SELECT 'Other Licensed Professional', 'malpractice' UNION ALL
  SELECT 'Other Licensed Professional', 'gov_id'      UNION ALL
  SELECT 'Other Licensed Professional', 'cv'
) x ON x.tname = rt.name
WHERE rt.is_seed = 1
  AND NOT EXISTS (
    SELECT 1 FROM requirement_template_items i WHERE i.template_id = rt.id AND i.token = x.token
  );
