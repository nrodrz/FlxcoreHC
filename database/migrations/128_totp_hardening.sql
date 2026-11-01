-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 128_totp_hardening.sql — S-6: encrypted TOTP secrets (longer column) + replay protection (last accepted time-step).
ALTER TABLE users MODIFY totp_secret VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN totp_last_counter BIGINT NULL;
