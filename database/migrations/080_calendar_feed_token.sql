-- 080_calendar_feed_token.sql
-- Per-user secret token for the read-only iCalendar (ICS) subscription feed. Possession of the
-- token authorises a read-only calendar feed of that user's tenant events — the same model Google
-- and Outlook use for private iCal URLs. Null until the user generates one; regenerating rotates
-- it (revoking old subscriptions).

ALTER TABLE users ADD COLUMN ical_token CHAR(40) NULL AFTER password_hash;
