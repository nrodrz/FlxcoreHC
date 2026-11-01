-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Seed the reference library. Content is original summary written for FlxCoreHC; facts only.
INSERT IGNORE INTO training_library (code, title, category, authority, summary, body, link, link_label, minutes, sort, updated_at) VALUES

('ready_to_sell', 'Ready-to-Sell: the full sequence', 'rts', 'cms',
 'What "ready to sell" actually means and the order to complete it before AEP.',
 'Being appointed with a carrier is not the same as being ready to sell. For each plan year a producer must finish every step below, in order, before marketing or enrolling.

- Hold an active state license for the line (in Florida, 2-15 or 2-40).
- Complete annual Medicare training: AHIP or an accepted alternative (NABIP), including Fraud, Waste & Abuse.
- Complete each carrier''s own certification for that plan year.
- Secure an active appointment with each carrier you intend to write.
- Confirm ready-to-sell status inside each carrier''s portal — it is the carrier''s system, not yours, that grants it.

Do these early. Carrier certifications open through the summer, and a pending certification on October 16 means you cannot submit that carrier''s enrollments.',
 NULL, NULL, 6, 10, NOW()),

('ahip_2027', 'AHIP certification (PY2027)', 'certification', 'cms',
 'The industry-standard annual Medicare + FWA training most carriers require.',
 'AHIP''s annual Medicare course is the training most Medicare Advantage and Part D carriers require before a producer can sell that plan year''s products. It covers Medicare basics, eligibility and enrollment periods, plan types, and the marketing and compliance rules, plus Fraud, Waste & Abuse.

- The PY2027 course opened June 22, 2026.
- The final exam is 50 questions with a 2-hour limit; a 90% score passes.
- It is open-book — you may reference the modules during the exam.
- Completing AHIP is usually the prerequisite for a carrier''s own certification, so finish it first.

Record the completion as an AHIP credential on the producer''s profile so compliance tracks its plan-year status.',
 NULL, NULL, 5, 20, NOW()),

('nabip_2027', 'NABIP certification (the AHIP alternative)', 'certification', 'cms',
 'A CMS-approved alternative to AHIP — cheaper, but not accepted by every carrier.',
 'NABIP''s Medicare, Medicare Advantage & Compliance Requirements (MMACR) course is a CMS-approved alternative that satisfies the same annual training requirement as AHIP.

- The PY2027 course opened June 22, 2026, the same day as AHIP.
- It is accepted by 60+ carriers — but not universally. Confirm every carrier in the producer''s lineup accepts it before relying on it.
- It generally costs less than AHIP and has a lower passing threshold.

In FlxCoreHC, a NABIP credential automatically satisfies an assigned AHIP requirement, since both fulfil the annual training obligation. Still verify carrier acceptance for each carrier the producer writes.',
 NULL, NULL, 4, 30, NOW()),

('cy2027_marketing', 'CY2027 marketing rule changes', 'marketing', 'cms',
 'What the CY2027 Final Rule changed for agent/broker marketing, effective 10/01/2026.',
 'CMS issued the Contract Year 2027 Final Rule (CMS-4208-F3) on April 2, 2026. Most marketing and communication provisions apply from October 1, 2026 — ahead of the 2027 Annual Election Period. Key changes for producers:

- The 48-hour waiting period between collecting a Scope of Appointment and a personal marketing appointment is removed. An SOA is still required before the appointment; an electronic signature is valid.
- The TPMO disclaimer now needs to be given before any discussion of plan benefits, rather than within the first 60 seconds. The reference to the State Health Insurance Assistance Program (SHIP) is removed from the required disclaimer.
- Producers may again collect Scopes of Appointment at educational events, as long as no plan-specific marketing happens during the educational presentation.
- Restrictions on superlative language ("best", "top-rated") are relaxed; the ban on misleading claims still applies.
- Retention of marketing/sales call recordings drops to 6 years. Enrollment records and enrollment-call portions remain at 10 years.

Update call scripts, website disclaimers, and your data-retention policy before October 1. Do not delete older recordings retroactively without legal review.',
 'https://www.cms.gov/newsroom/fact-sheets/contract-year-2027-medicare-advantage-part-d-final-rule', 'CMS fact sheet', 8, 40, NOW()),

('tpmo_soa', 'TPMO disclaimer & Scope of Appointment', 'marketing', 'cms',
 'The disclaimer and SOA obligations every producer follows on each contact.',
 'Agents and brokers are third-party marketing organizations (TPMOs) and must follow TPMO rules on every beneficiary contact.

- Read the TPMO disclaimer before discussing any plan benefits, in person, by phone, and in electronic communication. If you represent fewer than all plans in the area, state how many organizations and products you represent.
- Complete a Scope of Appointment before any personal marketing appointment, regardless of who initiated it. "In writing" allows a valid electronic signature; for non-in-person appointments an audio, audio-visual, or electronic record can serve as the SOA.
- The same SOA can cover multiple contacts for the same product(s) and plan year, but you must honor a request to stop contact.

General statements like "most MA plans include dental" do not by themselves trigger the benefit-discussion threshold.',
 NULL, NULL, 6, 50, NOW()),

('carrier_cert_process', 'Carrier certifications & appointments', 'carrier', 'carrier',
 'How per-carrier certification and appointment fit on top of AHIP/NABIP.',
 'After AHIP or NABIP, each carrier requires its own annual product certification before you can discuss or submit that carrier''s plans, and an active appointment to be paid.

- Certifications run through each carrier''s portal and open on their own schedule. PY2027 examples: UnitedHealthcare in June, Aetna and Devoted around June 23, Humana July 8, Wellcare July 21.
- Some carriers add product-specific modules (for example D-SNP Model of Care training) on top of the base certification.
- Appointment and payment rules vary; some states and carriers appoint at first submission.

Add each carrier''s extra requirements under Carriers, then assign them to the producers who sell that carrier so compliance tracks each one separately.',
 NULL, NULL, 6, 60, NOW()),

('fl_licensing', 'Florida licensing & continuing education', 'state', 'state',
 'The state license lines and CE that underpin everything else.',
 'A valid Florida license is the foundation of ready-to-sell status.

- The 2-15 (Health, Life & Annuity) license covers Medicare Advantage and Part D; the 2-40 (Health) license is limited to health lines.
- Continuing education is required to renew the license on the state cycle; complete CE hours in the summer rather than during AEP.
- Keep the license active and in good standing — a lapsed license invalidates ready-to-sell status with every carrier.',
 NULL, NULL, 4, 70, NOW()),

('fwa_training', 'Fraud, Waste & Abuse (FWA)', 'fwa', 'cms',
 'The annual FWA and general-compliance training bundled with Medicare certification.',
 'CMS requires annual Fraud, Waste & Abuse and general-compliance training for anyone selling Medicare Advantage or Part D. It is included in the AHIP and NABIP courses.

- Complete it every plan year alongside Medicare certification.
- Agencies and FMOs should confirm every downline producer has current FWA training on file.
- Keep a record of completion; it is a common item in carrier and CMS oversight.',
 NULL, NULL, 3, 80, NOW());
