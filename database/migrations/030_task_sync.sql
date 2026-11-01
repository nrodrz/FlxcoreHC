-- Auto renewal tasks from compliance gaps: a stable source key lets the sync
-- engine create one task per gap, keep it deduplicated, and auto-close it
-- when the gap resolves.
ALTER TABLE renewal_tasks
  ADD COLUMN source_key VARCHAR(160) NULL,
  ADD INDEX idx_renewal_source (tenant_id, source_key);
