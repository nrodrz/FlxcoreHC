-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 084_prune_beneficiary_interactions.sql
-- The beneficiary-interaction register (CMS Medicare sales-conduct records: Scope of Appointment,
-- TPMO disclaimer, Permission to Contact, recording/enrolment clocks) was an insurance feature.
-- Its route, repository, rules class, and views were removed in this release; the backing table is
-- now unreferenced by any live code. Kept in the earlier prune only because it still had a live
-- route at that time — that route is now gone, so the table is safe to drop.
--
-- Idempotent: DROP ... IF EXISTS re-runs to a no-op.

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS beneficiary_interactions;
SET FOREIGN_KEY_CHECKS = 1;
