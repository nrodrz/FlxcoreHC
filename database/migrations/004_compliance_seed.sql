-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- FlxCoreHC — global compliance checklist template items (tenant_id NULL).
-- Seeded once. Tenants get their own status rows on first interaction.
-- applies_to gates by vertical: 'all', 'healthcare', or 'insurance'.

INSERT INTO compliance_items (id, tenant_id, applies_to, category, code, title, description, severity, sort_order, active, created_at) VALUES
-- Access control
(UUID(), NULL, 'all', 'access', 'mfa_admins', 'Enable MFA for admin accounts', 'Require multi-factor authentication for all tenant_owner and manager logins.', 'required', 10, 1, UTC_TIMESTAMP()),
(UUID(), NULL, 'all', 'access', 'unique_logins', 'One login per person (no shared accounts)', 'Each user has their own credentials; shared logins break the audit trail.', 'required', 20, 1, UTC_TIMESTAMP()),
(UUID(), NULL, 'all', 'access', 'review_access', 'Quarterly access review', 'Review who has access and remove users who have left or changed roles.', 'recommended', 30, 1, UTC_TIMESTAMP()),
(UUID(), NULL, 'all', 'access', 'session_timeout', 'Confirm idle session timeout is active', 'Sessions should expire after inactivity to limit unattended-screen risk.', 'recommended', 40, 1, UTC_TIMESTAMP()),
-- Encryption
(UUID(), NULL, 'all', 'encryption', 'tls_enforced', 'Force HTTPS / TLS for all access', 'Confirm the site redirects HTTP to HTTPS and HSTS is enabled.', 'required', 50, 1, UTC_TIMESTAMP()),
(UUID(), NULL, 'healthcare', 'encryption', 'phi_at_rest', 'Confirm PHI encryption at rest is enabled', 'Sensitive fields (NPI, credential numbers) and documents are encrypted in the database.', 'required', 60, 1, UTC_TIMESTAMP()),
-- Audit
(UUID(), NULL, 'all', 'audit', 'review_audit', 'Review audit & access logs monthly', 'Periodically review who created/changed/viewed records to catch anomalies.', 'recommended', 70, 1, UTC_TIMESTAMP()),
(UUID(), NULL, 'healthcare', 'audit', 'access_log_on', 'Confirm PHI access logging is active', 'Views and downloads of PHI are recorded with user, time, and IP.', 'required', 80, 1, UTC_TIMESTAMP()),
-- Retention
(UUID(), NULL, 'all', 'retention', 'retention_policy', 'Define a record retention policy', 'Document how long credential records and documents are kept before disposal.', 'recommended', 90, 1, UTC_TIMESTAMP()),
(UUID(), NULL, 'healthcare', 'retention', 'phi_retention', 'Set PHI retention period (often 6 years)', 'HIPAA requires defined retention and proper disposal of PHI records.', 'required', 100, 1, UTC_TIMESTAMP()),
-- Training / policy
(UUID(), NULL, 'all', 'training', 'security_training', 'Annual security awareness training', 'Staff complete basic security/privacy training each year.', 'recommended', 110, 1, UTC_TIMESTAMP()),
(UUID(), NULL, 'healthcare', 'policy', 'hipaa_policies', 'Adopt written HIPAA policies & procedures', 'Maintain documented privacy and security policies covering PHI handling.', 'required', 120, 1, UTC_TIMESTAMP()),
(UUID(), NULL, 'healthcare', 'policy', 'breach_plan', 'Have a breach notification plan', 'Documented steps for detecting, reporting, and notifying on a data breach.', 'required', 130, 1, UTC_TIMESTAMP()),
(UUID(), NULL, 'insurance', 'policy', 'cms_marketing', 'CMS marketing compliance review', 'Confirm agent marketing materials and conduct meet CMS rules before AEP.', 'recommended', 140, 1, UTC_TIMESTAMP()),
(UUID(), NULL, 'insurance', 'policy', 'soa_retention', 'Retain Scope of Appointment records', 'SOA forms retained per CMS (commonly 10 years).', 'recommended', 150, 1, UTC_TIMESTAMP());
