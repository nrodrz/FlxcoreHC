-- 114_microsoft_oauth.sql
-- Microsoft 365 "modern auth": OAuth 2.0 authorization-code flow + Microsoft Graph sendMail, replacing
-- basic SMTP AUTH (which Microsoft is retiring). Works for the platform sender and for each tenant.
-- The operator registers ONE Azure app (client id + secret + redirect URI); the platform admin and
-- each tenant's Master Admin then "Connect Microsoft 365" with their own mailbox. Refresh tokens are
-- stored encrypted; access tokens are minted on demand and never persisted.

ALTER TABLE tenant_mail_settings
  ADD COLUMN oauth_provider   VARCHAR(16)   NULL AFTER transport,          -- 'microsoft'
  ADD COLUMN oauth_account    VARCHAR(190)  NULL AFTER oauth_provider,     -- the connected mailbox (UPN)
  ADD COLUMN oauth_refresh_enc TEXT         NULL AFTER oauth_account,      -- encrypted refresh token
  ADD COLUMN oauth_connected_at DATETIME    NULL AFTER oauth_refresh_enc;
