-- 071_training_vertical.sql
-- Training content is vertical-specific. The existing library is Medicare-agent material (AHIP,
-- SOA, ready-to-sell, FWA…), which should not show to a healthcare tenant. This adds a `vertical`
-- column, tags every existing article 'insurance', and seeds a starter healthcare library covering
-- the credentialing lifecycle a billing company / medical group actually works with — NOT MA agent
-- training. Articles are national/reference-level; each links to the authoritative source.

ALTER TABLE training_library
  ADD COLUMN vertical VARCHAR(20) NOT NULL DEFAULT 'insurance' AFTER category;

-- Everything already in the library is insurance/MA content.
UPDATE training_library SET vertical = 'insurance' WHERE vertical IS NULL OR vertical = '';

-- ---- Healthcare training library (starter set) ------------------------------------------------
-- Idempotent per row: INSERT ... SELECT ... WHERE NOT EXISTS keyed on code, so re-runs are safe.

INSERT INTO training_library (code, title, category, vertical, authority, summary, body, link, link_label, minutes, sort, updated_at)
SELECT 'hc_credentialing_overview', 'Credentialing vs. enrollment: what we track', 'certification', 'healthcare', 'federal',
  'The difference between provider credentialing (verifying qualifications) and payer enrollment (getting paid), and which pieces this system tracks.',
  'Credentialing is the process of verifying a provider is who they say they are and holds the qualifications they claim — license, education, training, work history, malpractice coverage, and sanctions checks.\n\nEnrollment is the separate process of registering that provider with a payer (Medicare, Medicaid, or a commercial plan) so claims can be paid.\n\nThis platform tracks and reminds you about the credentials and their expiry dates. It does not submit applications to CAQH, PECOS, or any payer — that stays in your existing workflow. What it gives you is a single source of truth for what each provider holds, what is expiring, and what is missing.\n\n- Credentialing artifacts: state license, DEA, board certification, NPI, malpractice, CME.\n- Enrollment artifacts we also track as documents: CAQH attestation date, PECOS/Medicare PTAN, payer effective dates.',
  'https://www.cms.gov/medicare/enrollment-renewal/providers-suppliers', 'CMS provider enrollment', 6, 10, NOW()
WHERE NOT EXISTS (SELECT 1 FROM training_library WHERE code = 'hc_credentialing_overview');

INSERT INTO training_library (code, title, category, vertical, authority, summary, body, link, link_label, minutes, sort, updated_at)
SELECT 'hc_npi', 'NPI: Type 1 and Type 2', 'certification', 'healthcare', 'cms',
  'What the National Provider Identifier is, the difference between individual (Type 1) and organizational (Type 2) NPIs, and why both matter.',
  'The NPI is a 10-digit identifier issued through NPPES. It never expires, but the information behind it must be kept current.\n\n- Type 1 (individual): one per provider, for life. A physician, nurse practitioner, or other individual clinician has exactly one.\n- Type 2 (organizational): for the business entity — a group practice, laboratory, or clinic. An organization can have more than one Type 2 for distinct locations or subparts.\n\nKeep the NPPES record accurate: address, taxonomy, and authorized official. Payers reconcile claims against NPPES, and a stale record is a common denial cause.',
  'https://nppes.cms.hhs.gov/', 'NPPES registry', 4, 20, NOW()
WHERE NOT EXISTS (SELECT 1 FROM training_library WHERE code = 'hc_npi');

INSERT INTO training_library (code, title, category, vertical, authority, summary, body, link, link_label, minutes, sort, updated_at)
SELECT 'hc_state_license', 'State medical & professional licenses', 'state', 'healthcare', 'state',
  'License renewal cycles vary by state and profession. Track the expiry, the renewal window, and the CME tied to each.',
  'Every clinician who treats patients must hold an active license in the state where the patient is located. Renewal cycles differ by state and by profession (physician, NP, PA, RN), and most carry a continuing-education requirement that must be satisfied before renewal.\n\n- Track the license expiry date and the state board that issues it.\n- Track the CME/CE tied to that license separately, since the hours must be complete before the renewal is filed.\n- For providers licensed in multiple states, each license is its own record with its own cycle.\n\nPuerto Rico is a distinct jurisdiction: the PR Board of Medical Licensure and Discipline issues its own license, recertified triennially, and it is required to treat patients located in Puerto Rico regardless of any mainland license.',
  'https://www.fsmb.org/contact-a-state-medical-board/', 'Find a state medical board', 5, 30, NOW()
WHERE NOT EXISTS (SELECT 1 FROM training_library WHERE code = 'hc_state_license');

INSERT INTO training_library (code, title, category, vertical, authority, summary, body, link, link_label, minutes, sort, updated_at)
SELECT 'hc_dea', 'DEA registration & state CDS', 'certification', 'healthcare', 'federal',
  'Providers who prescribe controlled substances need a DEA registration (renewed every three years) and, in many states, a separate state CDS.',
  'A DEA registration authorizes a provider to prescribe controlled substances. It is renewed every three years and is specific to the state(s) where the provider practices.\n\n- Many states also require a separate Controlled Dangerous Substances (CDS) registration in addition to the federal DEA.\n- A lapsed DEA stops the provider from prescribing controlled substances — a hard compliance gap, not a soft one — so it belongs on the expiring-soon watch list well ahead of the date.\n- Track the registered address; a DEA is tied to a location and must be updated on a move.',
  'https://www.deadiversion.usdoj.gov/drugreg/index.html', 'DEA Diversion Control', 4, 40, NOW()
WHERE NOT EXISTS (SELECT 1 FROM training_library WHERE code = 'hc_dea');

INSERT INTO training_library (code, title, category, vertical, authority, summary, body, link, link_label, minutes, sort, updated_at)
SELECT 'hc_board_cert', 'Board certification & CME', 'certification', 'healthcare', 'industry',
  'Board certification through an ABMS member board, and the continuing medical education that keeps a license and certification current.',
  'Board certification is a voluntary credential (through an ABMS member board such as ABIM or ABFM) that many payers and facilities require for participation. It renews on the board''s MOC cycle.\n\nCME is the continuing education that keeps both the license and, often, the board certification current. Track the hours and the cycle end date. Where a state or board requires specific content (for example ethics, or disease-specific hours), track those sub-totals so you know a renewal will actually qualify — not just that the total hours are met.',
  'https://www.abms.org/board-certification/', 'ABMS board certification', 4, 50, NOW()
WHERE NOT EXISTS (SELECT 1 FROM training_library WHERE code = 'hc_board_cert');

INSERT INTO training_library (code, title, category, vertical, authority, summary, body, link, link_label, minutes, sort, updated_at)
SELECT 'hc_malpractice', 'Malpractice / professional liability', 'certification', 'healthcare', 'industry',
  'Proof of current professional liability coverage is required by nearly every payer and facility. Track the policy, limits, and expiry.',
  'Professional liability (malpractice) coverage is required by nearly every payer contract and facility privilege. What matters for tracking:\n\n- The policy expiry date, so a renewal certificate is collected before the gap.\n- The coverage limits, since some contracts specify minimums (commonly stated as per-occurrence / aggregate).\n- Whether the policy is claims-made or occurrence, which affects whether tail coverage is needed when a provider leaves.\n\nFor a group or facility, the entity policy is a separate record from each individual provider''s coverage.',
  'https://www.naic.org/consumer_medical_malpractice.htm', 'NAIC medical malpractice', 3, 60, NOW()
WHERE NOT EXISTS (SELECT 1 FROM training_library WHERE code = 'hc_malpractice');

INSERT INTO training_library (code, title, category, vertical, authority, summary, body, link, link_label, minutes, sort, updated_at)
SELECT 'hc_caqh', 'CAQH ProView attestation', 'certification', 'healthcare', 'industry',
  'CAQH ProView is the shared credentialing profile most commercial payers pull from. It must be re-attested every 120 days to stay current.',
  'CAQH ProView is a provider data service most commercial payers use as the source for credentialing. The provider maintains one profile, and payers with authorization pull from it.\n\nThe profile must be re-attested every 120 days. If attestation lapses, the profile is flagged stale and payers may treat the provider as non-current — a frequent, avoidable cause of enrollment delays. Track the attestation date and treat the 120-day mark as a recurring reminder. This platform tracks that date; the attestation itself is done in CAQH.',
  'https://proview.caqh.org/', 'CAQH ProView', 4, 70, NOW()
WHERE NOT EXISTS (SELECT 1 FROM training_library WHERE code = 'hc_caqh');

INSERT INTO training_library (code, title, category, vertical, authority, summary, body, link, link_label, minutes, sort, updated_at)
SELECT 'hc_clia', 'CLIA certification for laboratories', 'certification', 'healthcare', 'cms',
  'Any facility testing human specimens needs a CLIA certificate. Track the certificate type, expiry (every two years), and the lab director qualifications behind it.',
  'CLIA (Clinical Laboratory Improvement Amendments) governs any facility that tests human specimens for diagnosis or treatment. A CLIA certificate is required before testing and is renewed every two years.\n\n- Track the certificate type (waiver, PPM, compliance, or accreditation), since the requirements scale with test complexity.\n- Track the laboratory director qualifications, which CLIA ties to the certificate for moderate- and high-complexity labs.\n- The certificate is tied to a location; a lab operating multiple sites needs the right certificate for each.',
  'https://www.cms.gov/medicare/quality/clinical-laboratory-improvement-amendments', 'CMS CLIA program', 5, 80, NOW()
WHERE NOT EXISTS (SELECT 1 FROM training_library WHERE code = 'hc_clia');

INSERT INTO training_library (code, title, category, vertical, authority, summary, body, link, link_label, minutes, sort, updated_at)
SELECT 'hc_expirables', 'Building an expiry watch list', 'certification', 'healthcare', 'industry',
  'How to think about lead times: which credentials are hard stops if they lapse, and how far ahead to start chasing the renewal.',
  'Not every expiry is equal. Group what you track by how much damage a lapse does and how long the renewal takes:\n\n- Hard stops (start early): DEA, state license, CLIA. A lapse stops the provider or lab from working, and renewals can take weeks.\n- Payer-facing (avoid delays): CAQH attestation, board certification, malpractice certificate. A lapse doesn''t stop care but stalls enrollment and payment.\n- Administrative: NPI/NPPES accuracy, EIN documentation. Rarely expire, but keep them accurate.\n\nA practical rule: set the reminder to the renewal lead time plus a buffer. A license that takes six weeks to renew wants a 90-day heads-up, not 30.',
  'https://www.cms.gov/medicare/enrollment-renewal/providers-suppliers', 'CMS enrollment & renewal', 4, 90, NOW()
WHERE NOT EXISTS (SELECT 1 FROM training_library WHERE code = 'hc_expirables');
