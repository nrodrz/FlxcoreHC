-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Georgia regulatory content — the second statute-sourced state.
--
-- Until now every verified rule in the catalog was Florida's; Georgia agencies got the federal
-- and carrier items but nothing state-specific. Sources, all verified against the Georgia Office
-- of Commissioner of Insurance and Safety Fire (OCI) and the Georgia Administrative Code:
--
--   GA Rule 120-2-3-.07(1)   — a resident agent must obtain a certificate of authority from each
--                              insurer they represent.
--   GA Rule 120-2-3-.07(2)(b)— new applicants must submit electronic fingerprints through the
--                              Department's vendor for a criminal background check, at their cost.
--   GA Rule 120-2-3-.07(3)   — the major lines of authority are Life; Accident and Sickness;
--                              Property; and Casualty.
--   O.C.G.A. § 33-8-1        — a request for appointment must be submitted within 15 days of the
--                              agency contract execution date, or of the date the first insurance
--                              application is submitted, whichever applies.
--   OCI Continuing Education — resident licensees renew biennially, on or before the last day of
--                              their birth month. Less than 20 years of service: 24 hours, of
--                              which 3 must be ethics. From 2026, licensees with more than 20
--                              years receive an automatic 4-hour reduction (20 hours, 3 ethics).
--                              Carry-over may not exceed 50% of the biennial requirement, and
--                              excess ethics hours carry forward only as general credit.
--
-- Deliberately NOT encoded: prelicensing hour counts. Sources conflict between the older
-- 20-hours-per-major-line model and a revised 8-hour model, and prelicensing is a one-time
-- pre-appointment matter rather than an ongoing compliance obligation. Verify with OCI directly
-- if you need it.

INSERT INTO credential_catalog
  (code, name, category, authority, applies_to, line, state, is_required, renewal_months, notes, sort)
VALUES
 ('ga_las', 'GA Life, Accident & Sickness Agent License', 'license', 'state', 'agent', 'all', 'GA', 1, 24,
  'Georgia resident licence covering both Life and Accident & Sickness lines — the usual licence for Medicare Advantage and health sales. Renews biennially on or before the last day of the licensee''s birth month (OCI agent licensing; major lines per GA Rule 120-2-3-.07(3)).', 41),

 ('ga_as', 'GA Accident & Sickness Agent License', 'license', 'state', 'agent', 'all', 'GA', 0, 24,
  'Health-only Georgia resident licence, an alternative to the combined Life, Accident & Sickness licence. Biennial renewal on the last day of the birth month.', 46),

 ('ga_pc', 'GA Property & Casualty Agent License', 'license', 'state', 'agent', 'commercial', 'GA', 0, 24,
  'Georgia treats Property and Casualty as a paired authority. Biennial renewal on the last day of the birth month.', 47),

 ('ga_personal_lines', 'GA Personal Lines Agent License', 'license', 'state', 'agent', 'commercial', 'GA', 0, 24,
  'Limited Georgia authority for personal policies only (homeowners, personal auto); does not cover commercial lines.', 48),

 ('ga_ce', 'GA Continuing Education (CE)', 'training', 'state', 'agent', 'all', 'GA', 1, 24,
  'OCI: 24 hours per biennial cycle including 3 hours of ethics for resident licensees with under 20 years of service. From 2026, those with over 20 years get an automatic 4-hour reduction (20 hours, still 3 ethics). Due on or before the last day of the birth month. Carry-over is capped at 50% of the requirement, and surplus ethics hours carry forward only as general credit.', 51),

 ('ga_ltc', 'GA Long-Term Care Partnership Training', 'training', 'state', 'agent', 'all', 'GA', 0, 24,
  'Required before selling long-term care in Georgia: an 8-hour state-approved initial course, then a 4-hour ongoing course every 24 months. A producer who completed another state''s LTC Partnership training must add a 2-hour Georgia-specific Medicaid course.', 52),

 ('ga_annuity', 'GA Annuity Suitability Training', 'training', 'state', 'agent', 'all', 'GA', 0, NULL,
  'Four-hour initial annuity training required before soliciting annuity products in Georgia.', 53),

 ('ga_flood', 'GA Flood Insurance Training', 'training', 'state', 'agent', 'commercial', 'GA', 0, NULL,
  'One-time 3-hour flood course required before writing flood coverage in Georgia.', 54),

 ('ga_appointment_15d', 'GA Carrier Appointment Filed (15-day rule)', 'appointment', 'state', 'agent,agency', 'all', 'GA', 1, NULL,
  'O.C.G.A. § 33-8-1: the appointment request must be submitted within 15 days of the agency contract execution date, or of the date the first insurance application is submitted. Missing the window is the most common Georgia appointment finding — track the contract date, not just the appointment.', 211),

 ('ga_certificate_authority', 'GA Certificate of Authority per Insurer', 'appointment', 'state', 'agent', 'all', 'GA', 1, NULL,
  'GA Rule 120-2-3-.07(1): a resident agent must hold a certificate of authority from every insurer they represent. One per carrier, not a single blanket authorisation.', 212),

 ('ga_fingerprints', 'GA Electronic Fingerprint Background Check', 'background', 'state', 'agent', 'all', 'GA', 1, NULL,
  'GA Rule 120-2-3-.07(2)(b): new applicants must submit electronic fingerprints through the Department''s selected vendor for a criminal background check, at the applicant''s cost. Active licensees and reinstatements within 6 months of expiry are excluded.', 213)
ON DUPLICATE KEY UPDATE notes = VALUES(notes), name = VALUES(name), renewal_months = VALUES(renewal_months);
