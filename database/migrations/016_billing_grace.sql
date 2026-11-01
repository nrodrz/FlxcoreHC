-- Track when a subscription first went past_due, to time the grace period.
ALTER TABLE tenants ADD COLUMN past_due_since DATETIME NULL;
