-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Tenant-scoped seeding for states + entity requirements.
--
-- Split out of 036 because migrations run before any tenant exists at install time,
-- and because a brand-new organization must get the same treatment at registration
-- (AuthService::seedTemplatesFor runs this file too). Idempotent by design.

-- Mark the Florida starter templates as Florida.
UPDATE requirement_templates SET state = 'FL'
 WHERE state IS NULL
   AND (name LIKE 'FL %' OR name = 'Dual-line Producer (Medicare + P&C)');
-- 'Flood (NFIP) add-on' stays state-agnostic: NFIP is a federal programme.
-- 'Agency / Entity' and 'FMO / Upline' stay state-agnostic at the template level; the
-- state-specific duties inside them (agent in charge, breach plan) carry their own state.

-- Add them to the seeded entity templates.
INSERT INTO requirement_template_items (id, tenant_id, template_id, token, created_at)
SELECT UUID(), rt.tenant_id, rt.id, x.token, UTC_TIMESTAMP()
FROM requirement_templates rt
JOIN (
  SELECT 'Agency / Entity' AS tname, 'agent_in_charge' AS token UNION ALL
  SELECT 'Agency / Entity', 'branch_designation' UNION ALL
  SELECT 'Agency / Entity', 'fein'               UNION ALL
  SELECT 'Agency / Entity', 'data_breach_plan'   UNION ALL
  SELECT 'FMO / Upline',    'agent_in_charge'    UNION ALL
  SELECT 'FMO / Upline',    'fein'               UNION ALL
  SELECT 'FMO / Upline',    'data_breach_plan'
) x ON x.tname = rt.name
WHERE NOT EXISTS (
  SELECT 1 FROM requirement_template_items i WHERE i.template_id = rt.id AND i.token = x.token
);

-- Sales-conduct duties belong on every Medicare-selling producer and on the entity.
INSERT INTO requirement_template_items (id, tenant_id, template_id, token, created_at)
SELECT UUID(), rt.tenant_id, rt.id, x.token, UTC_TIMESTAMP()
FROM requirement_templates rt
JOIN (
  SELECT 'FL Medicare Agent (2-15)' AS tname, 'soa_process' AS token UNION ALL
  SELECT 'FL Medicare Agent (2-15)', 'ptc_process'       UNION ALL
  SELECT 'FL Medicare Agent (2-15)', 'tpmo_disclaimer'   UNION ALL
  SELECT 'FL Medicare Agent (2-15)', 'agent_annual_test' UNION ALL
  SELECT 'FL Medicare Agent (2-40 Health only)', 'soa_process'       UNION ALL
  SELECT 'FL Medicare Agent (2-40 Health only)', 'ptc_process'       UNION ALL
  SELECT 'FL Medicare Agent (2-40 Health only)', 'tpmo_disclaimer'   UNION ALL
  SELECT 'FL Medicare Agent (2-40 Health only)', 'agent_annual_test' UNION ALL
  SELECT 'Dual-line Producer (Medicare + P&C)', 'soa_process'        UNION ALL
  SELECT 'Dual-line Producer (Medicare + P&C)', 'ptc_process'        UNION ALL
  SELECT 'Dual-line Producer (Medicare + P&C)', 'tpmo_disclaimer'    UNION ALL
  SELECT 'Dual-line Producer (Medicare + P&C)', 'agent_annual_test'  UNION ALL
  SELECT 'Agency / Entity', 'soa_process'      UNION ALL
  SELECT 'Agency / Entity', 'tpmo_disclaimer'  UNION ALL
  SELECT 'FMO / Upline',    'tpmo_disclaimer'
) x ON x.tname = rt.name
WHERE NOT EXISTS (
  SELECT 1 FROM requirement_template_items i WHERE i.template_id = rt.id AND i.token = x.token
);

-- Training Center: the beneficiary-interaction record trail.
INSERT IGNORE INTO training_library (code, title, category, authority, summary, body, minutes, sort, updated_at) VALUES
 ('cms_sales_records', 'What you must have on file for every beneficiary', 'cms', 'cms',
  'Scope of Appointment, Permission to Contact, the TPMO disclaimer, call recordings, and how long to keep each.',
  'Being certified to sell is not the same as selling compliantly. This is the record trail a carrier audit or a CMS complaint investigation actually asks for.

**Scope of Appointment (42 CFR 422.2264(c)(3), 422.2274(b)(3))**
An SOA must be agreed and recorded BEFORE every personal marketing appointment — in person, on the phone, or virtual — regardless of whether you or the beneficiary asked for the meeting. For CY2027, CMS **eliminated the 48-hour waiting period**: you may now meet the same day the SOA is signed. It must be in writing for in-person appointments; an electronic signature is valid, and for non-in-person appointments an audio or audio-visual recording or an electronic record suffices. The SOA must capture the product types to be discussed, the appointment date, beneficiary and agent contact details, and state plainly that there is no obligation to enrol, that current or future Medicare enrolment is unaffected, and that automatic enrolment will not occur. A new SOA is needed to discuss a different product line. You may collect SOAs at educational and sales events — but not during plan-initiated activities in a health care setting.

**Permission to Contact**
Document PTC before reaching out. It expires after 12 months, or sooner once its purpose is served.

**TPMO disclaimer**
You are a TPMO. For CY2027 the disclaimer must be spoken **before any discussion of plan benefits** — not within the first minute, as previously required — and the reference to SHIPs is removed. It must also appear on marketing materials, your website, and emails. A passing mention that "most plans include dental" is not a benefits discussion; explaining a specific plan''s cost sharing is.

**Call recording**
Every call in the chain of enrolment must be recorded in its entirety — inbound and outbound, telephonic and virtual (Zoom and the like count). CY2027 shortened retention for marketing and sales call recordings to **6 years**. Enrolment records remain at **10 years**.

**Annual training and testing**
Agents representing MA organizations must be trained and tested annually and score **85% or higher**.

Record each appointment, call, and event in FlxCoreHC''s interaction register. It flags the missing artefact before the carrier does.',
  9, 30, NOW());

INSERT IGNORE INTO training_sources (article_code, label, url, authority, sort) VALUES
 ('cms_sales_records', '42 CFR 422.2264 — Beneficiary contact (SOA, events)', 'https://www.law.cornell.edu/cfr/text/42/422.2264', 'cms', 10),
 ('cms_sales_records', '42 CFR 422.2274 — Agent, broker and third-party requirements', 'https://www.ecfr.gov/current/title-42/chapter-IV/subchapter-B/part-422/subpart-V/section-422.2274', 'cms', 20),
 ('cms_sales_records', 'CMS FAQ — new Scope of Appointment rules (CY2027 final rule)', 'https://www.cms.gov/files/document/agent-broker-marketing-faqs-10-19-2022.pdf', 'cms', 30);
