-- One person, several organizations.
--
-- users.email was globally unique, so the same person could not belong to two
-- agencies (common for FMO owners, or a producer contracted to two agencies), and
-- login resolved an account by email alone. Email is now unique *within* a tenant,
-- and login disambiguates by asking which organization to enter.
ALTER TABLE users DROP INDEX uq_users_email;
ALTER TABLE users ADD UNIQUE KEY uq_users_tenant_email (tenant_id, email);
ALTER TABLE users ADD INDEX idx_users_email (email);
