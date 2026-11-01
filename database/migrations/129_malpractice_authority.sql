-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 129_malpractice_authority.sql — B-8: malpractice / professional-liability coverage is issued by an insurer, not a federal authority
-- (the audit package printed "federal" for it).
UPDATE credential_catalog SET authority = 'insurer'
WHERE tenant_id IS NULL AND code IN ('malpractice', 'group_malpractice') AND authority = 'federal';
