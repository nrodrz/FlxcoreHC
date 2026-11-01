-- Per-type business timezone (availability is interpreted here; slots stored as UTC).
ALTER TABLE booking_types ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT 'UTC';
-- Reminder bookkeeping so the cron doesn't double-send.
ALTER TABLE bookings
  ADD COLUMN reminded_24h TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN reminded_1h  TINYINT(1) NOT NULL DEFAULT 0;
