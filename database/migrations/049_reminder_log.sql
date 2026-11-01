-- Compliance expiry reminders (v1.54).
--
-- The credential_reminders table already exists (base schema) with UNIQUE (credential_id,
-- days_before). That key has a flaw: once a threshold row exists it blocks that threshold
-- forever, so a RENEWED credential (new expiry date) never re-notifies. We add an
-- expiry_snapshot column and widen the unique key to include it, so each expiry date is its
-- own reminder cycle — a renewal starts fresh automatically.
ALTER TABLE credential_reminders
  ADD COLUMN IF NOT EXISTS expiry_snapshot DATE NULL AFTER days_before;

-- Backfill existing rows from the current credential expiry so the new key is populated.
UPDATE credential_reminders r
  JOIN credentials c ON c.id = r.credential_id
  SET r.expiry_snapshot = c.expiry_date
  WHERE r.expiry_snapshot IS NULL;

-- Swap the unique key to include the expiry snapshot.
ALTER TABLE credential_reminders DROP INDEX uq_reminder;
ALTER TABLE credential_reminders
  ADD UNIQUE KEY uq_reminder (credential_id, days_before, expiry_snapshot);
