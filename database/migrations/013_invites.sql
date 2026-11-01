-- User invitations: an invited user has status='invited', no usable password, and a
-- one-time token to set their password and activate. Used for both team invites and
-- platform-provisioned organization owners.
ALTER TABLE users
  ADD COLUMN invite_token   CHAR(40) NULL,
  ADD COLUMN invite_expires DATETIME NULL,
  ADD UNIQUE KEY uq_users_invite (invite_token);
