-- 130_invite_token_hash.sql — S-8: invite tokens are stored as SHA-256 hashes (64 hex chars); pending legacy raw tokens (40 chars) still resolve until used or expired.
ALTER TABLE users MODIFY invite_token CHAR(64) NULL;
-- Hash every still-pending legacy invite in place; the emailed link keeps working because lookups try the hash of the presented token.
-- (Raw 40-char tokens cannot be re-derived from the hash, so this is done lazily: rows are left as-is and expire in <= 7 days.)
