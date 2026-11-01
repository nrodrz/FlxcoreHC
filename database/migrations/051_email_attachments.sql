-- Optional single attachment per queued email (v1.57). Used to attach the invoice PDF to the
-- invoice email. Stored base64 in the outbox row so it survives until flush; kept small.
ALTER TABLE email_outbox ADD COLUMN IF NOT EXISTS attach_name VARCHAR(120) NULL AFTER body;
ALTER TABLE email_outbox ADD COLUMN IF NOT EXISTS attach_mime VARCHAR(80) NULL AFTER attach_name;
ALTER TABLE email_outbox ADD COLUMN IF NOT EXISTS attach_b64 MEDIUMTEXT NULL AFTER attach_mime;
