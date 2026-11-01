-- 083_prune_insurance_requirement_defs.sql
-- Remove the 15 global insurance requirement definitions (vertical='insurance') seeded by
-- migration 006. In the healthcare edition, RequirementRepository::defs() only ever selects
-- WHERE vertical = 'healthcare', so these rows never load — they are inert seed data.
--
-- Safe: audited that no holder_requirements row references any vertical='insurance' def
-- (the FK fk_hreq_def would otherwise block this). Idempotent — re-runs to a no-op.

DELETE hr FROM holder_requirements hr
  JOIN requirement_defs d ON d.id = hr.requirement_id
 WHERE d.vertical = 'insurance';

DELETE FROM requirement_defs WHERE vertical = 'insurance';
