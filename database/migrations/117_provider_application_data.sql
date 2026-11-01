-- 117_provider_application_data.sql
-- Provider data that credentialing / enrollment APPLICATIONS ask for (CAQH ProView, OCS/SICRO,
-- Medicaid PEP, Medicare 855, payer packets) but a tracking record did not hold. Non-sensitive
-- identity/professional fields are added here; DOB/SSN are deliberately NOT added (decision pending —
-- they carry real privacy weight and would need encryption + need-to-know like NPI).
-- Education, training and work history are repeating records, so they get their own tables.

ALTER TABLE credential_holders
  ADD COLUMN middle_name       VARCHAR(80)  NULL AFTER first_name,
  ADD COLUMN suffix            VARCHAR(12)  NULL AFTER last_name,        -- Jr., MD, etc.
  ADD COLUMN prior_names       VARCHAR(200) NULL AFTER suffix,           -- maiden/previous names
  ADD COLUMN gender            VARCHAR(12)  NULL AFTER prior_names,
  ADD COLUMN languages         VARCHAR(120) NULL AFTER gender,           -- "es,en"
  ADD COLUMN primary_specialty VARCHAR(120) NULL AFTER languages,
  ADD COLUMN secondary_specialty VARCHAR(120) NULL AFTER primary_specialty,
  ADD COLUMN taxonomy_code     VARCHAR(12)  NULL AFTER secondary_specialty,
  ADD COLUMN caqh_id           VARCHAR(20)  NULL AFTER taxonomy_code,
  ADD COLUMN medicare_ptan     VARCHAR(20)  NULL AFTER caqh_id,
  ADD COLUMN medicaid_id       VARCHAR(20)  NULL AFTER medicare_ptan,
  ADD COLUMN upin              VARCHAR(12)  NULL AFTER medicaid_id,
  ADD COLUMN accepting_new_patients TINYINT(1) NULL AFTER upin,
  ADD COLUMN hospital_affiliations VARCHAR(300) NULL AFTER accepting_new_patients;

-- Education & training (medical school, residency, fellowship, internship)
CREATE TABLE IF NOT EXISTS provider_education (
  id           CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id    CHAR(36)     NOT NULL,
  holder_id    CHAR(36)     NOT NULL,
  kind         VARCHAR(16)  NOT NULL,           -- undergraduate|professional|internship|residency|fellowship|other
  institution  VARCHAR(200) NOT NULL,
  program      VARCHAR(160) NULL,               -- degree / specialty
  city         VARCHAR(80)  NULL,
  state        VARCHAR(8)   NULL,
  country      VARCHAR(2)   NULL,
  start_date   DATE         NULL,
  end_date     DATE         NULL,
  completed    TINYINT(1)   NOT NULL DEFAULT 1,
  notes        VARCHAR(300) NULL,
  created_at   DATETIME     NOT NULL,
  updated_at   DATETIME     NULL,
  deleted_at   DATETIME     NULL,
  KEY idx_pe_holder (tenant_id, holder_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Work / employment history (applications ask for gaps to be explained)
CREATE TABLE IF NOT EXISTS provider_work_history (
  id           CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id    CHAR(36)     NOT NULL,
  holder_id    CHAR(36)     NOT NULL,
  employer     VARCHAR(200) NOT NULL,
  position     VARCHAR(160) NULL,
  city         VARCHAR(80)  NULL,
  state        VARCHAR(8)   NULL,
  start_date   DATE         NULL,
  end_date     DATE         NULL,               -- NULL = current
  reason_left  VARCHAR(300) NULL,
  gap_explanation VARCHAR(300) NULL,            -- explanation for a gap BEFORE this entry
  created_at   DATETIME     NOT NULL,
  updated_at   DATETIME     NULL,
  deleted_at   DATETIME     NULL,
  KEY idx_pw_holder (tenant_id, holder_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
