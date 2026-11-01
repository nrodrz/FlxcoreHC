-- 133_signin_options.sql — v1.172.0: sign-in options.
--   1. users.mfa_method: which second factor an account uses when two-factor is on (totp = authenticator app, email = e-mailed code).
--   2. auth_email_codes: one-time 6-digit codes sent by e-mail (stored as HMAC hashes, short-lived, attempt-limited).
--   3. password_resets: single-use forgot-password links (the token is stored as a SHA-256 hash, never in clear).
--   4. platform_require_2fa: the two-factor requirement of the platform console becomes a setting the platform administrator controls.
--      An installation that already has a platform administrator using two-factor keeps the requirement ON (it was mandatory
--      before this release). A new installation has no users when this runs, so it starts OFF and can be turned on at any time.
ALTER TABLE users ADD COLUMN mfa_method VARCHAR(10) NOT NULL DEFAULT 'totp';

CREATE TABLE IF NOT EXISTS auth_email_codes (
  id         CHAR(36)    NOT NULL PRIMARY KEY,
  user_id    CHAR(36)    NOT NULL,
  purpose    VARCHAR(12) NOT NULL,
  code_hash  CHAR(64)    NOT NULL,
  attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME    NOT NULL,
  used_at    DATETIME    NULL,
  created_at DATETIME    NOT NULL,
  KEY idx_aec_user (user_id, purpose)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id           CHAR(36)    NOT NULL PRIMARY KEY,
  user_id      CHAR(36)    NOT NULL,
  tenant_id    CHAR(36)    NOT NULL,
  token_hash   CHAR(64)    NOT NULL,
  expires_at   DATETIME    NOT NULL,
  used_at      DATETIME    NULL,
  requested_ip VARCHAR(45) NULL,
  created_at   DATETIME    NOT NULL,
  UNIQUE KEY uq_pr_token (token_hash),
  KEY idx_pr_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO platform_settings (skey, sval, updated_at)
SELECT 'platform_require_2fa', '1', UTC_TIMESTAMP() FROM DUAL
WHERE EXISTS (SELECT 1 FROM users WHERE platform_admin = 1 AND totp_enabled = 1)
ON DUPLICATE KEY UPDATE sval = sval;
