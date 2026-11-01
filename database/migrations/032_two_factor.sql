-- Two-factor authentication (TOTP). Optional per user; can be required for
-- owners/managers via the org setting below.
ALTER TABLE users
  ADD COLUMN totp_secret VARCHAR(64) NULL,
  ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN totp_backup_codes TEXT NULL,
  ADD COLUMN totp_confirmed_at DATETIME NULL;

-- Org-wide policy: when 1, owners and managers must enrol before using the app.
ALTER TABLE tenants
  ADD COLUMN require_2fa_admins TINYINT(1) NOT NULL DEFAULT 0;
