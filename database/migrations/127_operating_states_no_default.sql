-- 127_operating_states_no_default.sql
-- No jurisdiction is assumed for a new tenant (the silent 'FL' default was a review finding, D-5).
-- Existing tenants keep whatever they saved.
ALTER TABLE tenants MODIFY operating_states VARCHAR(160) NOT NULL DEFAULT '';
